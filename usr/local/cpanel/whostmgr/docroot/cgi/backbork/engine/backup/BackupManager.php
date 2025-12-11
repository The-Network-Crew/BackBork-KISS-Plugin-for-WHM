<?php
/**
 * BackBork KISS - Backup Manager
 * High-level backup orchestration
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
 * High-level backup orchestration manager.
 * Coordinates backup operations using pkgacct, handles transport to destinations,
 * sends notifications, and manages operation logging.
 */
class BackBorkBackupManager {
    
    // Default directory for operation logs
    const LOG_DIR = '/usr/local/cpanel/3rdparty/backbork/logs';
    
    // Temporary directory for staging backup archives before transport
    const TEMP_DIR = '/home/backbork_tmp';
    
    /** @var BackBorkConfig User/global configuration handler */
    private $config;
    
    /** @var BackBorkNotify Email/Slack notification service */
    private $notify;
    
    /** @var BackBorkDestinationsParser Parses WHM transport destinations */
    private $destinations;
    
    /** @var BackBorkPkgacct cPanel pkgacct wrapper for creating account archives */
    private $pkgacct;
    
    /** @var BackBorkSQLBackup Hot database backup handler */
    private $dbBackup;
    
    /**
     * Constructor - Initialize all dependencies.
     * Sets up configuration, notification, destination parsing, and pkgacct services.
     * Creates required directories if they don't exist.
     */
    public function __construct() {
        // Initialize helper services
        $this->config = new BackBorkConfig();
        $this->notify = new BackBorkNotify();
        $this->destinations = new BackBorkDestinationsParser();
        $this->pkgacct = new BackBorkPkgacct();
        $this->dbBackup = new BackBorkSQLBackup();
        
        // Ensure temp directory exists for staging backups (secure permissions)
        if (!is_dir(self::TEMP_DIR)) {
            mkdir(self::TEMP_DIR, 0700, true);
        }
        
        // Ensure log directory exists for operation history
        if (!is_dir(self::LOG_DIR)) {
            mkdir(self::LOG_DIR, 0700, true);
        }
    }
    
    /**
     * Create backup for multiple accounts.
     * Orchestrates the full backup workflow: validation, per-account backup,
     * transport to destination, notifications, and logging.
     * 
     * @param array $accounts List of account usernames to backup
     * @param string $destinationId Destination ID from WHM transport config
     * @param string $user User initiating the backup (for logging/permissions)
     * @return array Result with success status, messages, per-account results, and errors
     */
    public function createBackup($accounts, $destinationId, $user) {
        // Load user-specific configuration (temp dir, notification prefs, etc.)
        $userConfig = $this->config->getUserConfig($user);
        
        // Look up the destination configuration by ID
        $destination = $this->destinations->getDestinationById($destinationId);
        
        // Validate destination exists
        if (!$destination) {
            return ['success' => false, 'message' => 'Invalid destination'];
        }
        
        // Track results and errors for each account
        $results = [];
        $errors = [];
        $logMessages = [];
        
        // Send start notification if user has enabled it (check new key, fallback to legacy)
        $notifyStart = !empty($userConfig['notify_backup_start']) || (!isset($userConfig['notify_backup_start']) && !empty($userConfig['notify_start']));
        if ($notifyStart) {
            $this->notify->sendNotification(
                'backup_start',
                [
                    'accounts' => $accounts,
                    'destination' => $destination['name'],
                    'user' => $user,
                    'requestor' => $this->getRequestor()
                ],
                $userConfig
            );
        }
        
        // Process each account sequentially
        foreach ($accounts as $account) {
            // Backup single account (pkgacct + transport)
            $result = $this->backupSingleAccount($account, $destination, $userConfig, $user);
            $results[$account] = $result;
            
            // Track failures for summary
            if (!$result['success']) {
                $errors[] = $account . ': ' . $result['message'];
            }
            
            // Build log message for this account
            $logMessages[] = "[{$account}] " . ($result['success'] ? 'SUCCESS' : 'FAILED') . ': ' . $result['message'];
        }
        
        // Overall success only if no errors occurred
        $success = empty($errors);
        
        // Log the complete operation with all account results
        $this->logOperation($user, 'backup', $accounts, $success, implode("\n", $logMessages));
        
        // Check notification preferences (new keys with legacy fallback)
        $notifySuccess = !empty($userConfig['notify_backup_success']) || (!isset($userConfig['notify_backup_success']) && !empty($userConfig['notify_success']));
        $notifyFailure = !empty($userConfig['notify_backup_failure']) || (!isset($userConfig['notify_backup_failure']) && !empty($userConfig['notify_failure']));
        
        // Send success notification if all backups succeeded and notifications enabled
        if ($success && $notifySuccess) {
            $this->notify->sendNotification(
                'backup_success',
                [
                    'accounts' => $accounts,
                    'destination' => $destination['name'],
                    'user' => $user,
                    'requestor' => BackBorkBootstrap::getRequestor(),
                    'results' => $results
                ],
                $userConfig
            );
        // Send failure notification if any backup failed and notifications enabled
        } elseif (!$success && $notifyFailure) {
            $this->notify->sendNotification(
                'backup_failure',
                [
                    'accounts' => $accounts,
                    'destination' => $destination['name'],
                    'user' => $user,
                    'requestor' => BackBorkBootstrap::getRequestor(),
                    'errors' => $errors
                ],
                $userConfig
            );
        }
        
        // Return comprehensive result for API response
        return [
            'success' => $success,
            'message' => $success ? 'All backups completed successfully' : 'Some backups failed',
            'results' => $results,
            'errors' => $errors,
            'log' => implode("\n", $logMessages)
        ];
    }
    
    /**
     * Backup a single cPanel account.
     * Executes pkgacct (with schema-only if using hot DB backup), optionally runs
     * mariadb-backup/mysqlbackup, and transports all files to destination.
     * 
     * @param string $account Account username to backup
     * @param array $destination Destination configuration (type, path, credentials, etc.)
     * @param array $userConfig User configuration (temp directory, options)
     * @param string $user User initiating backup (for logging)
     * @return array Result with success status and message
     */
    private function backupSingleAccount($account, $destination, $userConfig, $user) {
        // Generate timestamp for unique backup filename
        $timestamp = date('Y-m-d_H-i-s');
        
        // Use user-configured temp directory or default
        $tempDir = isset($userConfig['temp_directory']) ? $userConfig['temp_directory'] : self::TEMP_DIR;
        
        // Ensure temp directory exists with secure permissions
        if (!is_dir($tempDir)) {
            if (!mkdir($tempDir, 0700, true)) {
                return [
                    'success' => false,
                    'message' => "Failed to create temp directory: {$tempDir}"
                ];
            }
        }
        
        // Track files to upload and cleanup
        $filesToUpload = [];
        $filesToCleanup = [];
        
        // ====================================================================
        // STEP 1: Execute pkgacct
        // If using mariadb-backup/mysqlbackup, pkgacct uses --dbbackup=schema
        // ====================================================================
        $pkgResult = $this->pkgacct->execute($account, $tempDir, $userConfig);
        
        if (!$pkgResult['success']) {
            return $pkgResult;
        }
        
        // Rename archive with timestamp
        $createdFile = $pkgResult['path'];
        $backupFile = "cpmove-{$account}_{$timestamp}.tar.gz";
        $finalFile = $tempDir . '/' . $backupFile;
        
        if ($createdFile !== $finalFile) {
            if (!rename($createdFile, $finalFile)) {
                return [
                    'success' => false,
                    'message' => "Failed to rename backup file"
                ];
            }
        }
        
        $filesToUpload[] = ['local' => $finalFile, 'remote' => $account . '/' . $backupFile];
        $filesToCleanup[] = $finalFile;
        
        // ====================================================================
        // STEP 2: Hot database backup (if configured)
        // Creates separate DB archive with data (schema already in pkgacct)
        // ====================================================================
        $dbMethod = $userConfig['db_backup_method'] ?? 'pkgacct';
        
        if (in_array($dbMethod, ['mariadb-backup', 'mysqlbackup'], true)) {
            BackBorkConfig::debugLog("Running {$dbMethod} for account: {$account}");
            
            $dbResult = $this->dbBackup->backupDatabases($account, $tempDir, $userConfig);
            
            if (!$dbResult['success'] && empty($dbResult['skipped'])) {
                // DB backup failed - clean up and report
                foreach ($filesToCleanup as $file) {
                    if (file_exists($file)) unlink($file);
                }
                return [
                    'success' => false,
                    'message' => "Database backup failed: " . ($dbResult['message'] ?? 'Unknown error')
                ];
            }
            
            // Add DB archive to upload list if created
            if (!empty($dbResult['archive']) && file_exists($dbResult['archive'])) {
                $dbArchiveName = basename($dbResult['archive']);
                $filesToUpload[] = ['local' => $dbResult['archive'], 'remote' => $account . '/' . $dbArchiveName];
                $filesToCleanup[] = $dbResult['archive'];
            }
        }
        
        // ====================================================================
        // STEP 3: Upload all files to destination
        // ====================================================================
        $validator = new BackBorkDestinationsValidator();
        $transport = $validator->getTransportForDestination($destination);
        
        $allSuccess = true;
        $messages = [];
        
        foreach ($filesToUpload as $file) {
            $result = $transport->upload($file['local'], $file['remote'], $destination);
            if (!$result['success']) {
                $allSuccess = false;
                $messages[] = basename($file['remote']) . ': ' . ($result['message'] ?? 'Upload failed');
            }
        }
        
        // ====================================================================
        // STEP 4: Cleanup temp files
        // ====================================================================
        if ($allSuccess) {
            foreach ($filesToCleanup as $file) {
                if (file_exists($file)) unlink($file);
            }
        }
        
        return [
            'success' => $allSuccess,
            'message' => $allSuccess ? 'Backup completed successfully' : implode('; ', $messages)
        ];
    }
    
    /**
     * List backups for an account from local storage.
     * Searches the local /backup directory for existing backup archives.
     * 
     * @param string $account Account username to search for
     * @param string $user User requesting the list (for permission checks)
     * @return array Result with success status and list of backup files
     */
    public function listBackups($account, $user) {
        // Use local transport handler to search /backup directory
        $localTransport = new BackBorkTransportLocal();
        $localDest = ['type' => 'Local', 'path' => '/backup'];
        
        // Find all backup archives for this account
        $backups = $localTransport->findAccountBackups($account, $localDest);
        
        // Add human-readable size formatting to each backup entry
        foreach ($backups as &$backup) {
            $backup['size_formatted'] = $this->formatSize($backup['size']);
        }
        
        return ['success' => true, 'backups' => $backups];
    }
    
    /**
     * List backups from a remote destination.
     * Queries the specified destination for available backup files.
     * Optionally filters by account name substring.
     * 
     * @param string $destinationId Destination ID from WHM transport config
     * @param string $user User requesting (for permission filtering)
     * @param string $account Optional account filter (partial match)
     * @return array Result with success status and list of backup files
     */
    public function listRemoteBackups($destinationId, $user, $account = '') {
        // Look up destination configuration by ID
        $destination = $this->destinations->getDestinationById($destinationId);
        
        // Validate destination exists
        if (!$destination) {
            return ['success' => false, 'backups' => [], 'message' => 'Invalid destination'];
        }
        
        // Get appropriate transport handler for this destination type
        $validator = new BackBorkDestinationsValidator();
        $transport = $validator->getTransportForDestination($destination);
        
        // List all files at destination root
        $files = $transport->listFiles('', $destination);

        // Security: Get list of accounts this user can access (for filtering)
        $acl = BackBorkBootstrap::getACL();
        $isRoot = $acl->isRoot();
        $accessibleAccounts = [];
        
        // Non-root users can only see backups for accounts they own
        if (!$isRoot) {
            $accessible = $acl->getAccessibleAccounts();
            foreach ($accessible as $acc) {
                $accessibleAccounts[] = strtolower($acc['user']);
            }
        }

        // If account filter provided, filter files by partial match on filename
        if (!empty($account)) {
            $accountLower = mb_strtolower($account);
            $filtered = [];
            foreach ($files as $fileInfo) {
                $filenameLower = mb_strtolower($fileInfo['file'] ?? '');
                // Include file if account substring found in filename
                if (strpos($filenameLower, $accountLower) !== false) {
                    $filtered[] = $fileInfo;
                }
            }
            $files = $filtered;
        }
        
        // Format results for display with human-readable sizes
        // Also filter by user access (resellers only see their accounts' backups)
        $backups = [];
        foreach ($files as $file) {
            $filename = $file['file'] ?? '';
            
            // Extract account name from backup filename (cpmove-USERNAME_...)
            // Account names can contain letters, numbers, underscores but stop before timestamp (_YYYY-)
            $backupAccount = null;
            if (preg_match('/cpmove-([a-z0-9_]+?)(?:_\d{4}-\d{2}-\d{2}|\.tar|$)/i', $filename, $matches)) {
                $backupAccount = strtolower($matches[1]);
            }
            
            // Security: Skip backups for accounts the user doesn't own (resellers only)
            if (!$isRoot && $backupAccount !== null) {
                if (!in_array($backupAccount, $accessibleAccounts)) {
                    continue;  // Skip this backup - user doesn't own this account
                }
            }
            
            // For Native (SFTP/FTP) transport, files are flat in manual_backup/
            // For Local transport, files may be in account subdirectories
            // Use just the filename for remote paths (Native transport)
            $destType = strtolower($destination['type'] ?? 'local');
            $filePath = ($destType === 'sftp' || $destType === 'ftp') ? $filename : ($backupAccount ? $backupAccount . '/' . $filename : $filename);
            
            $backups[] = [
                'file' => $filePath,
                'display_name' => $filename,  // Just filename for display
                'size' => $this->formatSize($file['size'] ?? 0),
                'date' => $file['date'] ?? 'Unknown',
                'location' => 'remote',
                'account' => $backupAccount
            ];
        }
        
        return ['success' => true, 'backups' => $backups];
    }
    
    /**
     * Log a backup/restore operation to the centralized log system.
     * Delegates to BackBorkLog for consistent logging format.
     * 
     * @param string $user User who performed the operation
     * @param string $type Operation type ('backup' or 'restore')
     * @param array $accounts Affected accounts (array of usernames)
     * @param bool $success Whether operation succeeded
     * @param string $message Details/error message for the log entry
     */
    public function logOperation($user, $type, $accounts, $success, $message) {
        // Only log if BackBorkLog class is available
        if (class_exists('BackBorkLog')) {
            // Determine requestor IP address or 'cron' for CLI execution
            $requestor = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : (BackBorkBootstrap::isCLI() ? 'cron' : 'local'));
            
            // Log event through centralized logging
            BackBorkLog::logEvent($user, $type === 'backup' ? 'backup' : $type, $accounts, $success, $message, $requestor);
        }
    }
    
    /**
     * Get operation logs with pagination and filtering.
     * Delegates to BackBorkLog for consistent log retrieval.
     * Falls back to direct file reading if BackBorkLog unavailable.
     * 
     * @param string $user User requesting logs
     * @param bool $isRoot Whether user is root (sees all logs)
     * @param int $page Page number for pagination (1-indexed)
     * @param int $limit Items per page
     * @param string $filter Filter type: 'all', 'error', 'backup', 'restore'
     * @return array Paginated log entries with metadata
     */
    public function getLogs($user, $isRoot, $page = 1, $limit = 50, $filter = 'all') {
        // Delegate to centralized logger for consistency
        if (class_exists('BackBorkLog')) {
            return BackBorkLog::getLogs($user, $isRoot, $page, $limit, $filter);
        }

        // Fallback behaviour (should rarely be used - only if BackBorkLog not loaded)
        $logFile = self::LOG_DIR . '/operations.log';
        $logs = [];
        
        // Return empty if log file doesn't exist
        if (!file_exists($logFile)) {
            return ['logs' => [], 'total_pages' => 0, 'current_page' => 1];
        }
        
        // Read all log lines and reverse for most recent first
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_reverse($lines);
        
        // Process each log entry
        foreach ($lines as $line) {
            // Parse JSON log entry
            $entry = json_decode($line, true);
            if (!$entry) continue;
            
            // Non-root users can only see their own logs
            if (!$isRoot && isset($entry['user']) && $entry['user'] !== $user) {
                continue;
            }
            
            // Apply type filter if not 'all'
            if ($filter !== 'all') {
                if ($filter === 'error' && $entry['status'] !== 'error') continue;
                if ($filter === 'backup' && $entry['type'] !== 'backup') continue;
                if ($filter === 'restore' && $entry['type'] !== 'restore') continue;
            }
            
            // Format entry for display
            $logs[] = [
                'timestamp' => $entry['timestamp'],
                'type' => $entry['type'],
                'account' => is_array($entry['accounts']) ? implode(', ', $entry['accounts']) : $entry['accounts'],
                'user' => $entry['user'],
                'status' => $entry['status'],
                'message' => $entry['message']
            ];
        }
        
        // Calculate pagination
        $totalPages = ceil(count($logs) / $limit);
        $offset = ($page - 1) * $limit;
        $pagedLogs = array_slice($logs, $offset, $limit);
        
        return [
            'logs' => $pagedLogs,
            'total_pages' => $totalPages,
            'current_page' => $page
        ];
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
