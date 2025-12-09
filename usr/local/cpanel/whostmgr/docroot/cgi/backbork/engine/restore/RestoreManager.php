<?php
/**
 * BackBork KISS - Restore Manager
 * High-level restore orchestration using backup_restore_manager/restorepkg
 *
 * BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 * Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 * https://github.com/The-Network-Crew/BackBork-KISS-Plugin-for-WHM/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @package BackBork
 * @version See version.php (constant: BACKBORK_VERSION)
 * @author The Network Crew Pty Ltd & Velocity Host Pty Ltd
 */

class BackBorkRestoreManager {
    
    const RESTOREPKG_BIN = '/scripts/restorepkg';
    const BACKUP_RESTORE_MANAGER = '/usr/local/cpanel/bin/backup_restore_manager';
    const LOG_DIR = '/usr/local/cpanel/3rdparty/backbork/logs';
    
    /** @var BackBorkConfig */
    private $config;
    
    /** @var BackBorkNotify */
    private $notify;
    
    /** @var BackBorkRetrieval */
    private $retrieval;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->config = new BackBorkConfig();
        $this->notify = new BackBorkNotify();
        $this->retrieval = new BackBorkRetrieval();
        
        // Ensure log directory exists
        if (!is_dir(self::LOG_DIR)) {
            mkdir(self::LOG_DIR, 0700, true);
        }
    }
    
    /**
     * Restore an account from backup
     * 
     * @param string $backupFile Path to backup file or remote path
     * @param string $destinationId Destination where backup is stored
     * @param array $options Restore options
     * @param string $user User initiating restore
     * @return array Result
     */
    public function restoreAccount($backupFile, $destinationId, $options, $user) {
        $userConfig = $this->config->getUserConfig($user);
        
        // Retrieve backup file if needed
        $retrieveResult = $this->retrieval->retrieveBackup($destinationId, $backupFile);
        
        if (!$retrieveResult['success']) {
            return $retrieveResult;
        }
        
        $localPath = $retrieveResult['local_path'];
        
        // Verify backup file
        $verification = $this->retrieval->verifyBackupFile($localPath);
        if (!$verification['valid']) {
            return ['success' => false, 'message' => 'Invalid backup file: ' . $verification['message']];
        }
        
        // Extract account name from backup filename
        $account = $this->extractAccountFromFilename(basename($backupFile));
        
        // Send start notification if enabled
        if (!empty($userConfig['notify_start'])) {
            $this->notify->sendNotification(
                'restore_start',
                [
                    'account' => $account,
                    'backup_file' => $backupFile,
                    'user' => $user
                ],
                $userConfig
            );
        }
        
        // Execute restore
        $result = $this->executeRestore($localPath, $options);
        
        // Log operation
        $this->logOperation($user, 'restore', [$account], $result['success'], $result['message']);
        
        // Send notification
        if ($result['success'] && !empty($userConfig['notify_success'])) {
            $this->notify->sendNotification(
                'restore_success',
                [
                    'account' => $account,
                    'backup_file' => $backupFile,
                    'user' => $user
                ],
                $userConfig
            );
        } elseif (!$result['success'] && !empty($userConfig['notify_failure'])) {
            $this->notify->sendNotification(
                'restore_failure',
                [
                    'account' => $account,
                    'backup_file' => $backupFile,
                    'user' => $user,
                    'error' => $result['message']
                ],
                $userConfig
            );
        }
        
        return $result;
    }
    
    /**
     * Execute restore using restorepkg or backup_restore_manager
     * 
     * @param string $backupPath Path to backup file
     * @param array $options Restore options
     * @return array Result
     */
    private function executeRestore($backupPath, $options = []) {
        // Determine which restore method to use
        $useManager = $this->preferRestoreManager();
        
        if ($useManager && file_exists(self::BACKUP_RESTORE_MANAGER)) {
            return $this->restoreViaManager($backupPath, $options);
        }
        
        // Fall back to restorepkg
        return $this->restoreViaRestorepkg($backupPath, $options);
    }
    
    /**
     * Restore via backup_restore_manager (newer WHM versions)
     * 
     * @param string $backupPath Path to backup file
     * @param array $options Restore options
     * @return array Result
     */
    private function restoreViaManager($backupPath, $options = []) {
        $command = self::BACKUP_RESTORE_MANAGER;
        $command .= ' --restore';
        $command .= ' --file=' . escapeshellarg($backupPath);
        
        if (!empty($options['newuser'])) {
            $command .= ' --newuser=' . escapeshellarg($options['newuser']);
        }
        
        if (!empty($options['ip'])) {
            $command .= ' --ip=' . escapeshellarg($options['ip']);
        }
        
        // Execute
        $output = [];
        $returnCode = 0;
        exec($command . ' 2>&1', $output, $returnCode);
        
        $outputStr = implode("\n", $output);
        
        if ($returnCode !== 0) {
            return [
                'success' => false,
                'message' => 'Restore failed (exit code ' . $returnCode . '): ' . $outputStr,
                'command' => $command,
                'output' => $output,
                'return_code' => $returnCode
            ];
        }
        
        return [
            'success' => true,
            'message' => 'Restore completed successfully',
            'output' => $output
        ];
    }
    
    /**
     * Restore via restorepkg (traditional method)
     * 
     * @param string $backupPath Path to backup file
     * @param array $options Restore options
     * @return array Result
     */
    private function restoreViaRestorepkg($backupPath, $options = []) {
        $command = self::RESTOREPKG_BIN;
        
        // Options
        if (!empty($options['force'])) {
            $command .= ' --force';
        }
        
        if (!empty($options['newuser'])) {
            $command .= ' --newuser=' . escapeshellarg($options['newuser']);
        }
        
        $command .= ' ' . escapeshellarg($backupPath);
        
        // Execute
        $output = [];
        $returnCode = 0;
        exec($command . ' 2>&1', $output, $returnCode);
        
        $outputStr = implode("\n", $output);
        
        if ($returnCode !== 0) {
            return [
                'success' => false,
                'message' => 'Restore failed (exit code ' . $returnCode . '): ' . $outputStr,
                'command' => $command,
                'output' => $output,
                'return_code' => $returnCode
            ];
        }
        
        return [
            'success' => true,
            'message' => 'Restore completed successfully',
            'output' => $output
        ];
    }
    
    /**
     * Check if we should prefer backup_restore_manager
     * 
     * @return bool
     */
    private function preferRestoreManager() {
        // Check WHM version - backup_restore_manager is preferred in newer versions
        $version = @file_get_contents('/usr/local/cpanel/version');
        if (!$version) return false;
        
        // Version 11.110+ prefers backup_restore_manager
        $version = trim($version);
        $parts = explode('.', $version);
        
        if (count($parts) >= 2) {
            $major = (int)$parts[0];
            $minor = (int)$parts[1];
            
            if ($major >= 11 && $minor >= 110) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get restore options based on backup analysis
     * 
     * @param string $backupPath Path to backup file
     * @return array Available restore options
     */
    public function getRestoreOptions($backupPath) {
        $options = [
            'force' => [
                'label' => 'Force restore (overwrite existing)',
                'type' => 'boolean',
                'default' => false
            ],
            'newuser' => [
                'label' => 'Restore as different username',
                'type' => 'string',
                'default' => ''
            ],
            'ip' => [
                'label' => 'Assign to specific IP',
                'type' => 'string',
                'default' => ''
            ]
        ];
        
        return $options;
    }
    
    /**
     * Preview backup contents without restoring
     * 
     * @param string $backupPath Path to backup file
     * @return array Backup contents summary
     */
    public function previewBackup($backupPath) {
        // List archive contents
        $output = [];
        exec('tar -tzf ' . escapeshellarg($backupPath) . ' 2>/dev/null', $output);
        
        $preview = [
            'total_files' => count($output),
            'has_homedir' => false,
            'has_mysql' => false,
            'has_pgsql' => false,
            'has_email' => false,
            'has_ssl' => false,
            'has_dnszones' => false,
            'account' => null,
            'sample_files' => array_slice($output, 0, 50)
        ];
        
        foreach ($output as $line) {
            if (preg_match('/cpmove-([a-z0-9_]+)/', $line, $matches)) {
                $preview['account'] = $matches[1];
            }
            if (strpos($line, 'homedir') !== false) $preview['has_homedir'] = true;
            if (strpos($line, 'mysql') !== false) $preview['has_mysql'] = true;
            if (strpos($line, 'pgsql') !== false || strpos($line, 'postgres') !== false) $preview['has_pgsql'] = true;
            if (strpos($line, 'mail') !== false || strpos($line, '/et/') !== false) $preview['has_email'] = true;
            if (strpos($line, 'ssl') !== false || strpos($line, 'sslkeys') !== false) $preview['has_ssl'] = true;
            if (strpos($line, 'dnszones') !== false) $preview['has_dnszones'] = true;
        }
        
        // Get file size
        $preview['size'] = filesize($backupPath);
        $preview['size_formatted'] = $this->formatSize($preview['size']);
        
        return $preview;
    }
    
    /**
     * Check if account exists (to warn about overwrite)
     * 
     * @param string $account Account username
     * @return bool
     */
    public function accountExists($account) {
        // Check passwd file
        $passwd = @file_get_contents('/etc/passwd');
        if ($passwd && preg_match('/^' . preg_quote($account, '/') . ':/m', $passwd)) {
            return true;
        }
        
        // Check home directory
        if (is_dir('/home/' . $account)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Extract account name from backup filename
     * 
     * @param string $filename Backup filename
     * @return string|null Account name
     */
    private function extractAccountFromFilename($filename) {
        if (preg_match('/cpmove-([a-z0-9_]+)/i', $filename, $matches)) {
            return $matches[1];
        }
        return null;
    }
    
    /**
     * Log a restore operation
     * 
     * @param string $user User who performed the operation
     * @param string $type Operation type
     * @param array $accounts Affected accounts
     * @param bool $success Whether operation succeeded
     * @param string $message Details/error message
     */
    private function logOperation($user, $type, $accounts, $success, $message) {
        if (class_exists('BackBorkLog')) {
            $requestor = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : (BackBorkBootstrap::isCLI() ? 'cron' : 'local'));
            BackBorkLog::logEvent($user, $type === 'restore' ? 'restore' : $type, $accounts, $success, $message, $requestor);
        }
    }
    
    /**
     * Format file size
     * 
     * @param int $bytes Size in bytes
     * @return string Formatted size
     */
    private function formatSize($bytes) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
