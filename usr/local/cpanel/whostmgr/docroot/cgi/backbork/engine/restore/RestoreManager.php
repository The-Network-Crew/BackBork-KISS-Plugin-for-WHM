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

/**
 * High-level restore orchestration manager.
 * Coordinates account restoration using WHM's backup_restore_manager or restorepkg.
 * Handles file retrieval, verification, execution, notifications, and logging.
 */
class BackBorkRestoreManager {
    
    // Path to cPanel's restorepkg script (traditional restore method)
    const RESTOREPKG_BIN = '/scripts/restorepkg';
    
    // Path to backup_restore_manager (newer restore method for WHM 11.110+)
    const BACKUP_RESTORE_MANAGER = '/usr/local/cpanel/bin/backup_restore_manager';
    
    // Directory for operation logs
    const LOG_DIR = '/usr/local/cpanel/3rdparty/backbork/logs';
    
    /** @var BackBorkConfig User/global configuration handler */
    private $config;
    
    /** @var BackBorkNotify Email/Slack notification service */
    private $notify;
    
    /** @var BackBorkRetrieval Backup file retrieval service */
    private $retrieval;
    
    /**
     * Constructor - Initialize all dependencies.
     * Sets up configuration, notification, and retrieval services.
     */
    public function __construct() {
        // Initialize helper services
        $this->config = new BackBorkConfig();
        $this->notify = new BackBorkNotify();
        $this->retrieval = new BackBorkRetrieval();
        
        // Ensure log directory exists for operation history
        if (!is_dir(self::LOG_DIR)) {
            mkdir(self::LOG_DIR, 0700, true);
        }
    }
    
    /**
     * Restore an account from a backup file.
     * Complete restore workflow: retrieve file, verify, restore, notify, log.
     * 
     * @param string $backupFile Path to backup file or remote path
     * @param string $destinationId Destination ID where backup is stored
     * @param array $options Restore options (force, newuser, ip)
     * @param string $user User initiating restore (for logging/permissions)
     * @return array Result with success status and details
     */
    public function restoreAccount($backupFile, $destinationId, $options, $user) {
        // Load user-specific configuration for notifications
        $userConfig = $this->config->getUserConfig($user);
        
        // Retrieve backup file from destination (downloads if remote)
        $retrieveResult = $this->retrieval->retrieveBackup($destinationId, $backupFile);
        
        // Check retrieval success
        if (!$retrieveResult['success']) {
            return $retrieveResult;
        }
        
        $localPath = $retrieveResult['local_path'];
        
        // Verify backup file integrity and format
        $verification = $this->retrieval->verifyBackupFile($localPath);
        if (!$verification['valid']) {
            return ['success' => false, 'message' => 'Invalid backup file: ' . $verification['message']];
        }
        
        // Extract account name from backup filename for logging/notifications
        $account = $this->extractAccountFromFilename(basename($backupFile));
        
        // Send start notification if user has enabled it
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
        
        // Execute the actual restore operation
        $result = $this->executeRestore($localPath, $options);
        
        // Clean up downloaded temp file after restore (success or failure)
        // Only delete if it's in our temp directory (not a local destination file)
        if (strpos($localPath, '/home/backbork_tmp') === 0 && file_exists($localPath)) {
            unlink($localPath);
            BackBorkConfig::debugLog("Cleaned up temp restore file: {$localPath}");
        }
        
        // Log the operation to centralized log
        $this->logOperation($user, 'restore', [$account], $result['success'], $result['message']);
        
        // Send success notification if restore succeeded and notifications enabled
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
        // Send failure notification if restore failed and notifications enabled
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
     * Execute restore using appropriate WHM tool.
     * Automatically selects backup_restore_manager or restorepkg based on WHM version.
     * 
     * @param string $backupPath Absolute path to backup file
     * @param array $options Restore options (force, newuser, ip)
     * @return array Result with success status and details
     */
    private function executeRestore($backupPath, $options = []) {
        // Determine which restore method to use based on WHM version
        $useManager = $this->preferRestoreManager();
        
        // Use backup_restore_manager for WHM 11.110+ if available
        if ($useManager && file_exists(self::BACKUP_RESTORE_MANAGER)) {
            return $this->restoreViaManager($backupPath, $options);
        }
        
        // Fall back to traditional restorepkg script
        return $this->restoreViaRestorepkg($backupPath, $options);
    }
    
    /**
     * Restore via backup_restore_manager (newer WHM versions 11.110+).
     * Provides better handling and more options than restorepkg.
     * 
     * @param string $backupPath Absolute path to backup file
     * @param array $options Restore options (newuser, ip)
     * @return array Result with success status and details
     */
    private function restoreViaManager($backupPath, $options = []) {
        // Build backup_restore_manager command
        $command = self::BACKUP_RESTORE_MANAGER;
        $command .= ' --restore';
        $command .= ' --file=' . escapeshellarg($backupPath);
        
        // Add optional new username if restoring to different account
        if (!empty($options['newuser'])) {
            $command .= ' --newuser=' . escapeshellarg($options['newuser']);
        }
        
        // Add optional specific IP assignment
        if (!empty($options['ip'])) {
            $command .= ' --ip=' . escapeshellarg($options['ip']);
        }
        
        // Execute command and capture output
        $output = [];
        $returnCode = 0;
        exec($command . ' 2>&1', $output, $returnCode);
        
        $outputStr = implode("\n", $output);
        
        // Check for execution failure
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
     * Restore via restorepkg (traditional method).
     * Works on all WHM versions but has fewer options.
     * 
     * @param string $backupPath Absolute path to backup file
     * @param array $options Restore options (force, newuser)
     * @return array Result with success status and details
     */
    private function restoreViaRestorepkg($backupPath, $options = []) {
        // Build restorepkg command
        $command = self::RESTOREPKG_BIN;
        
        // Add force option to overwrite existing account
        if (!empty($options['force'])) {
            $command .= ' --force';
        }
        
        // Add optional new username if restoring to different account
        if (!empty($options['newuser'])) {
            $command .= ' --newuser=' . escapeshellarg($options['newuser']);
        }
        
        // Add backup file path
        $command .= ' ' . escapeshellarg($backupPath);
        
        // Execute command and capture output
        $output = [];
        $returnCode = 0;
        exec($command . ' 2>&1', $output, $returnCode);
        
        $outputStr = implode("\n", $output);
        
        // Check for execution failure
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
     * Check if we should prefer backup_restore_manager over restorepkg.
     * backup_restore_manager is recommended for WHM 11.110 and later.
     * 
     * @return bool True if backup_restore_manager should be used
     */
    private function preferRestoreManager() {
        // Read cPanel/WHM version
        $version = @file_get_contents('/usr/local/cpanel/version');
        if (!$version) return false;
        
        // Parse version string (e.g., "11.110.0.18")
        $version = trim($version);
        $parts = explode('.', $version);
        
        // Check for version 11.110 or higher
        if (count($parts) >= 2) {
            $major = (int)$parts[0];
            $minor = (int)$parts[1];
            
            // backup_restore_manager preferred in WHM 11.110+
            if ($major >= 11 && $minor >= 110) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get available restore options for UI display.
     * Returns configuration schema for restore options form.
     * 
     * @param string $backupPath Path to backup file (for potential analysis)
     * @return array Available restore options with labels and types
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
     * Preview backup contents without restoring.
     * Analyzes archive to show what data is included.
     * 
     * @param string $backupPath Absolute path to backup file
     * @return array Backup contents summary with flags for each data type
     */
    public function previewBackup($backupPath) {
        // List archive contents using tar
        $output = [];
        exec('tar -tzf ' . escapeshellarg($backupPath) . ' 2>/dev/null', $output);
        
        // Initialize preview data with content flags
        $preview = [
            'total_files' => count($output),
            'has_homedir' => false,     // Home directory present
            'has_mysql' => false,       // MySQL databases present
            'has_pgsql' => false,       // PostgreSQL databases present
            'has_email' => false,       // Email data present
            'has_ssl' => false,         // SSL certificates present
            'has_dnszones' => false,    // DNS zones present
            'account' => null,          // Extracted account name
            'sample_files' => array_slice($output, 0, 50)  // First 50 files for preview
        ];
        
        // Analyze file listing to detect content types
        foreach ($output as $line) {
            // Extract account name from cpmove directory
            if (preg_match('/cpmove-([a-z0-9_]+)/', $line, $matches)) {
                $preview['account'] = $matches[1];
            }
            // Check for various content types by path patterns
            if (strpos($line, 'homedir') !== false) $preview['has_homedir'] = true;
            if (strpos($line, 'mysql') !== false) $preview['has_mysql'] = true;
            if (strpos($line, 'pgsql') !== false || strpos($line, 'postgres') !== false) $preview['has_pgsql'] = true;
            if (strpos($line, 'mail') !== false || strpos($line, '/et/') !== false) $preview['has_email'] = true;
            if (strpos($line, 'ssl') !== false || strpos($line, 'sslkeys') !== false) $preview['has_ssl'] = true;
            if (strpos($line, 'dnszones') !== false) $preview['has_dnszones'] = true;
        }
        
        // Add file size information
        $preview['size'] = filesize($backupPath);
        $preview['size_formatted'] = $this->formatSize($preview['size']);
        
        return $preview;
    }
    
    /**
     * Check if account already exists on server.
     * Used to warn about potential overwrites before restore.
     * 
     * @param string $account Account username to check
     * @return bool True if account exists
     */
    public function accountExists($account) {
        // Check passwd file for user entry
        $passwd = @file_get_contents('/etc/passwd');
        if ($passwd && preg_match('/^' . preg_quote($account, '/') . ':/m', $passwd)) {
            return true;
        }
        
        // Check for home directory existence
        if (is_dir('/home/' . $account)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Extract account name from backup filename.
     * Parses cpmove-style naming convention.
     * 
     * @param string $filename Backup filename (e.g., "cpmove-username_2024-01-15.tar.gz")
     * @return string|null Account name or null if not parseable
     */
    private function extractAccountFromFilename($filename) {
        // Match cpmove-<account> pattern
        if (preg_match('/cpmove-([a-z0-9_]+)/i', $filename, $matches)) {
            return $matches[1];
        }
        return null;
    }
    
    /**
     * Log a restore operation to centralized log system.
     * Delegates to BackBorkLog for consistent logging format.
     * 
     * @param string $user User who performed the operation
     * @param string $type Operation type ('restore')
     * @param array $accounts Affected accounts (array of usernames)
     * @param bool $success Whether operation succeeded
     * @param string $message Details/error message
     */
    private function logOperation($user, $type, $accounts, $success, $message) {
        // Only log if BackBorkLog class is available
        if (class_exists('BackBorkLog')) {
            // Determine requestor IP address or 'cron' for CLI execution
            $requestor = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : (BackBorkBootstrap::isCLI() ? 'cron' : 'local'));
            
            // Log event through centralized logging
            BackBorkLog::logEvent($user, $type === 'restore' ? 'restore' : $type, $accounts, $success, $message, $requestor);
        }
    }
    
    /**
     * Format file size in human-readable units.
     * Converts bytes to appropriate unit (B, KB, MB, GB, TB).
     * 
     * @param int $bytes Size in bytes
     * @return string Formatted size with unit (e.g., "15.3 MB")
     */
    private function formatSize($bytes) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);  // Ensure non-negative
        
        // Calculate appropriate unit power (base 1024)
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);  // Cap at TB
        
        // Convert to selected unit
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
