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
     * Also handles accompanying DB backup files (from mariadb-backup/mysqlbackup).
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
            // Extract account from filename for logging even on failure
            $account = $this->extractAccountFromFilename(basename($backupFile));
            $this->logOperation($user, 'restore', [$account], false, 'Retrieval failed: ' . ($retrieveResult['message'] ?? 'Unknown error'));
            return $retrieveResult;
        }
        
        $localPath = $retrieveResult['local_path'];
        $filesToCleanup = [$localPath];
        
        // Extract account name from backup filename for logging/notifications
        $account = $this->extractAccountFromFilename(basename($backupFile));
        
        // Verify backup file integrity and format
        $verification = $this->retrieval->verifyBackupFile($localPath);
        if (!$verification['valid']) {
            $this->logOperation($user, 'restore', [$account], false, 'Invalid backup file: ' . $verification['message']);
            return ['success' => false, 'message' => 'Invalid backup file: ' . $verification['message']];
        }
        
        // Check for accompanying DB backup file (from mariadb-backup/mysqlbackup)
        $dbBackupFile = $this->findDbBackupFile($backupFile, $destinationId);
        $dbLocalPath = null;
        
        if ($dbBackupFile) {
            BackBorkConfig::debugLog("Found DB backup file: {$dbBackupFile}");
            $dbRetrieveResult = $this->retrieval->retrieveBackup($destinationId, $dbBackupFile);
            if ($dbRetrieveResult['success']) {
                $dbLocalPath = $dbRetrieveResult['local_path'];
                $filesToCleanup[] = $dbLocalPath;
            }
        }
        
        // Send start notification if user has enabled it (new key with legacy fallback)
        $notifyStart = !empty($userConfig['notify_restore_start']) || (!isset($userConfig['notify_restore_start']) && !empty($userConfig['notify_start']));
        if ($notifyStart) {
            $this->notify->sendNotification(
                'restore_start',
                [
                    'account' => $account,
                    'backup_file' => $backupFile,
                    'destination' => $destinationId,
                    'user' => $user
                ],
                $userConfig
            );
        }
        
        // ====================================================================
        // STEP 1: Restore main backup (includes schema if hot DB was used)
        // ====================================================================
        $result = $this->executeRestore($localPath, $options);
        
        if (!$result['success']) {
            // Clean up temp files on failure
            $this->cleanupFiles($filesToCleanup);
            $this->logOperation($user, 'restore', [$account], false, $result['message']);
            return $result;
        }
        
        // ====================================================================
        // STEP 2: Restore DB data if hot backup file exists
        // ====================================================================
        if ($dbLocalPath && file_exists($dbLocalPath)) {
            BackBorkConfig::debugLog("Restoring database data from: {$dbLocalPath}");
            
            $sqlRestore = new BackBorkSQLRestore();
            $dbResult = $sqlRestore->restoreDatabases($account, $dbLocalPath, $userConfig);
            
            if (!$dbResult['success']) {
                // DB restore failed but main restore succeeded - partial success
                $result['message'] .= ' (Warning: DB data restore failed: ' . ($dbResult['message'] ?? 'Unknown') . ')';
                $result['db_restore_failed'] = true;
            } else {
                $result['message'] .= ' (DB data restored)';
            }
        }
        
        // ====================================================================
        // STEP 3: Cleanup temp files
        // ====================================================================
        $this->cleanupFiles($filesToCleanup);
        
        // Log the operation to centralized log
        $this->logOperation($user, 'restore', [$account], $result['success'], $result['message']);
        
        // Check notification preferences (new keys with legacy fallback)
        $notifySuccess = !empty($userConfig['notify_restore_success']) || (!isset($userConfig['notify_restore_success']) && !empty($userConfig['notify_success']));
        $notifyFailure = !empty($userConfig['notify_restore_failure']) || (!isset($userConfig['notify_restore_failure']) && !empty($userConfig['notify_failure']));
        
        // Send success notification if restore succeeded and notifications enabled
        if ($result['success'] && $notifySuccess) {
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
        } elseif (!$result['success'] && $notifyFailure) {
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
     * Find accompanying DB backup file for a cpmove backup.
     * Looks for db-backup-{account}_{timestamp}.tar.gz matching the main backup.
     * 
     * @param string $backupFile Main backup filename (cpmove-account_timestamp.tar.gz)
     * @param string $destinationId Destination to search
     * @return string|null DB backup filename if found, null otherwise
     */
    private function findDbBackupFile($backupFile, $destinationId) {
        // Extract account and timestamp from main backup filename
        // Format: cpmove-{account}_{timestamp}.tar.gz
        if (!preg_match('/cpmove-([^_]+)_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.tar\.gz$/', basename($backupFile), $matches)) {
            return null;
        }
        
        $account = $matches[1];
        $timestamp = $matches[2];
        $dbBackupName = "db-backup-{$account}_{$timestamp}.tar.gz";
        
        // Check if DB backup exists in same directory
        $dir = dirname($backupFile);
        $dbBackupPath = ($dir === '.' || $dir === '') ? $dbBackupName : $dir . '/' . $dbBackupName;
        
        // Verify file exists at destination
        $destination = (new BackBorkDestinationsParser())->getDestinationById($destinationId);
        if (!$destination) {
            return null;
        }
        
        $validator = new BackBorkDestinationsValidator();
        $transport = $validator->getTransportForDestination($destination);
        
        if ($transport->fileExists($dbBackupPath, $destination)) {
            return $dbBackupPath;
        }
        
        return null;
    }
    
    /**
     * Clean up temporary files.
     * 
     * @param array $files List of file paths to delete
     */
    private function cleanupFiles($files) {
        foreach ($files as $file) {
            if ($file && strpos($file, '/home/backbork_tmp') === 0 && file_exists($file)) {
                unlink($file);
                BackBorkConfig::debugLog("Cleaned up temp file: {$file}");
            }
        }
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
        // Always use restorepkg for direct file restoration
        // backup_restore_manager is queue-based and designed for restore points,
        // not direct file restoration. restorepkg supports --disable=Module for
        // granular control and is the documented approach for file-based restores.
        return $this->restoreViaRestorepkg($backupPath, $options);
    }
    
    /**
     * Restore via restorepkg (traditional method).
     * Works on all WHM versions with granular --disable=Module control.
     * 
     * Note: backup_restore_manager is queue-based and designed for restore points
     * (e.g., selective restoration to existing live accounts). For direct file
     * restoration, restorepkg is the documented and recommended approach.
     * 
     * @param string $backupPath Absolute path to backup file
     * @param array $options Restore options (force, newuser, homedir, mysql, mail, etc.)
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
        
        // Build list of modules to disable based on unchecked options
        // restorepkg uses --disable=Module1,Module2 format
        $disableModules = [];
        
        if (isset($options['homedir']) && $options['homedir'] === false) {
            $disableModules[] = 'Homedir';
        }
        if (isset($options['mysql']) && $options['mysql'] === false) {
            $disableModules[] = 'Mysql';
        }
        if (isset($options['mail']) && $options['mail'] === false) {
            $disableModules[] = 'Mail';
            $disableModules[] = 'MailRouting';
        }
        if (isset($options['ssl']) && $options['ssl'] === false) {
            $disableModules[] = 'SSL';
        }
        if (isset($options['cron']) && $options['cron'] === false) {
            $disableModules[] = 'Cron';
        }
        if (isset($options['dns']) && $options['dns'] === false) {
            $disableModules[] = 'ZoneFile';
        }
        if (isset($options['subdomains']) && $options['subdomains'] === false) {
            // Domains module handles subdomains, parked domains, and addon domains together
            // We'll only disable if both subdomains AND addon_domains are false
            if (isset($options['addon_domains']) && $options['addon_domains'] === false) {
                $disableModules[] = 'Domains';
            }
        }
        
        // Add disable flag if any modules should be skipped
        if (!empty($disableModules)) {
            $command .= ' --disable=' . escapeshellarg(implode(',', $disableModules));
        }
        
        // Add backup file path
        $command .= ' ' . escapeshellarg($backupPath);
        
        // Generate unique restore ID for log tracking
        $restoreId = 'restore_' . time() . '_' . substr(md5($backupPath), 0, 8);
        $logFile = self::LOG_DIR . '/' . $restoreId . '.log';
        
        // Log the command being executed (sanitized)
        BackBorkConfig::debugLog("Executing restore: restorepkg " . basename($backupPath) . 
            (!empty($disableModules) ? " --disable=" . implode(',', $disableModules) : ''));
        
        // Write initial status to log
        file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Starting restore...\n");
        file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Backup file: " . basename($backupPath) . "\n", FILE_APPEND);
        if (!empty($disableModules)) {
            file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] Disabled modules: " . implode(', ', $disableModules) . "\n", FILE_APPEND);
        }
        file_put_contents($logFile, str_repeat('-', 60) . "\n", FILE_APPEND);
        
        // Execute command with real-time output capture using proc_open
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];
        
        $process = proc_open($command, $descriptors, $pipes);
        
        if (!is_resource($process)) {
            file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] ERROR: Failed to start restore process\n", FILE_APPEND);
            return [
                'success' => false,
                'message' => 'Failed to start restore process',
                'restore_id' => $restoreId,
                'log_file' => $logFile
            ];
        }
        
        // Close stdin - we don't need to write to it
        fclose($pipes[0]);
        
        // Set streams to non-blocking for real-time reading
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        
        $output = [];
        $allOutput = '';
        
        // Read output in real-time and write to log file
        while (true) {
            $stdout = fgets($pipes[1]);
            $stderr = fgets($pipes[2]);
            
            if ($stdout !== false) {
                $line = trim($stdout);
                if ($line !== '') {
                    $output[] = $line;
                    $allOutput .= $line . "\n";
                    // Write to log with timestamp for important lines
                    if (preg_match('/^(Restoring|Creating|Extracting|Installing|Updating|Running|Completed|Error|Warning|Failed)/i', $line)) {
                        file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] " . $line . "\n", FILE_APPEND);
                    } else {
                        file_put_contents($logFile, $line . "\n", FILE_APPEND);
                    }
                }
            }
            
            if ($stderr !== false) {
                $line = trim($stderr);
                if ($line !== '') {
                    $output[] = "[STDERR] " . $line;
                    $allOutput .= "[STDERR] " . $line . "\n";
                    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] [STDERR] " . $line . "\n", FILE_APPEND);
                }
            }
            
            // Check if process has finished
            $status = proc_get_status($process);
            if (!$status['running']) {
                // Read any remaining output
                while (($line = fgets($pipes[1])) !== false) {
                    $output[] = trim($line);
                    file_put_contents($logFile, trim($line) . "\n", FILE_APPEND);
                }
                while (($line = fgets($pipes[2])) !== false) {
                    $output[] = "[STDERR] " . trim($line);
                    file_put_contents($logFile, "[STDERR] " . trim($line) . "\n", FILE_APPEND);
                }
                break;
            }
            
            // Small delay to prevent CPU spinning
            usleep(50000); // 50ms
        }
        
        // Close pipes
        fclose($pipes[1]);
        fclose($pipes[2]);
        
        // Get exit code
        $returnCode = proc_close($process);
        
        // Write final status
        file_put_contents($logFile, str_repeat('-', 60) . "\n", FILE_APPEND);
        if ($returnCode !== 0) {
            file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] RESTORE FAILED (exit code: {$returnCode})\n", FILE_APPEND);
            return [
                'success' => false,
                'message' => 'Restore failed (exit code ' . $returnCode . ')',
                'restore_id' => $restoreId,
                'log_file' => $logFile,
                'output' => $output,
                'log' => $allOutput,
                'return_code' => $returnCode
            ];
        }
        
        file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] RESTORE COMPLETED SUCCESSFULLY\n", FILE_APPEND);
        
        return [
            'success' => true,
            'message' => 'Restore completed successfully',
            'restore_id' => $restoreId,
            'log_file' => $logFile,
            'output' => $output,
            'log' => $allOutput
        ];
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
        // Match cpmove-<account> pattern, stop before timestamp (_YYYY-)
        // Account names can contain letters, numbers, underscores
        if (preg_match('/cpmove-([a-z0-9_]+?)(?:_\d{4}-\d{2}-\d{2}|\.tar|$)/i', $filename, $matches)) {
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
