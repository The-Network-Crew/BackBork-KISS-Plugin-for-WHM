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

class BackBorkBackupManager {
    
    const LOG_DIR = '/usr/local/cpanel/3rdparty/backbork/logs';
    const TEMP_DIR = '/home/backbork_tmp';
    
    /** @var BackBorkConfig */
    private $config;
    
    /** @var BackBorkNotify */
    private $notify;
    
    /** @var BackBorkDestinationsParser */
    private $destinations;
    
    /** @var BackBorkPkgacct */
    private $pkgacct;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->config = new BackBorkConfig();
        $this->notify = new BackBorkNotify();
        $this->destinations = new BackBorkDestinationsParser();
        $this->pkgacct = new BackBorkPkgacct();
        
        // Ensure directories exist
        if (!is_dir(self::TEMP_DIR)) {
            mkdir(self::TEMP_DIR, 0700, true);
        }
        if (!is_dir(self::LOG_DIR)) {
            mkdir(self::LOG_DIR, 0700, true);
        }
    }
    
    /**
     * Create backup for accounts
     * 
     * @param array $accounts List of account usernames
     * @param string $destinationId Destination ID
     * @param string $user User initiating the backup
     * @return array Result
     */
    public function createBackup($accounts, $destinationId, $user) {
        $userConfig = $this->config->getUserConfig($user);
        $destination = $this->destinations->getDestinationById($destinationId);
        
        if (!$destination) {
            return ['success' => false, 'message' => 'Invalid destination'];
        }
        
        $results = [];
        $errors = [];
        $logMessages = [];
        
        // Send start notification if enabled
        if (!empty($userConfig['notify_start'])) {
            $this->notify->sendNotification(
                'backup_start',
                [
                    'accounts' => $accounts,
                    'destination' => $destination['name'],
                    'user' => $user
                ],
                $userConfig
            );
        }
        
        foreach ($accounts as $account) {
            $result = $this->backupSingleAccount($account, $destination, $userConfig, $user);
            $results[$account] = $result;
            
            if (!$result['success']) {
                $errors[] = $account . ': ' . $result['message'];
            }
            
            $logMessages[] = "[{$account}] " . ($result['success'] ? 'SUCCESS' : 'FAILED') . ': ' . $result['message'];
        }
        
        $success = empty($errors);
        
        // Log the operation
        $this->logOperation($user, 'backup', $accounts, $success, implode("\n", $logMessages));
        
        // Send completion notification
        if ($success && !empty($userConfig['notify_success'])) {
            $this->notify->sendNotification(
                'backup_success',
                [
                    'accounts' => $accounts,
                    'destination' => $destination['name'],
                    'user' => $user,
                    'results' => $results
                ],
                $userConfig
            );
        } elseif (!$success && !empty($userConfig['notify_failure'])) {
            $this->notify->sendNotification(
                'backup_failure',
                [
                    'accounts' => $accounts,
                    'destination' => $destination['name'],
                    'user' => $user,
                    'errors' => $errors
                ],
                $userConfig
            );
        }
        
        return [
            'success' => $success,
            'message' => $success ? 'All backups completed successfully' : 'Some backups failed',
            'results' => $results,
            'errors' => $errors,
            'log' => implode("\n", $logMessages)
        ];
    }
    
    /**
     * Backup a single account
     * 
     * @param string $account Account username
     * @param array $destination Destination configuration
     * @param array $userConfig User configuration
     * @param string $user User initiating backup
     * @return array Result
     */
    private function backupSingleAccount($account, $destination, $userConfig, $user) {
        $timestamp = date('Y-m-d_H-i-s');
        $tempDir = isset($userConfig['temp_directory']) ? $userConfig['temp_directory'] : self::TEMP_DIR;
        
        // Ensure temp directory exists
        if (!is_dir($tempDir)) {
            if (!mkdir($tempDir, 0700, true)) {
                return [
                    'success' => false,
                    'message' => "Failed to create temp directory: {$tempDir}"
                ];
            }
        }
        
        // Execute pkgacct
        $pkgResult = $this->pkgacct->execute($account, $tempDir, $userConfig);
        
        if (!$pkgResult['success']) {
            return $pkgResult;
        }
        
        $createdFile = $pkgResult['path'];
        
        // Rename with timestamp
        $backupFile = "cpmove-{$account}_{$timestamp}.tar.gz";
        $finalFile = $tempDir . '/' . $backupFile;
        
        if ($createdFile !== $finalFile) {
            if (!rename($createdFile, $finalFile)) {
                return [
                    'success' => false,
                    'message' => "Failed to rename backup file from {$createdFile} to {$finalFile}"
                ];
            }
        }
        
        // Get appropriate transport for destination
        $validator = new BackBorkDestinationsValidator();
        $transport = $validator->getTransportForDestination($destination);
        
        // Transport to destination
        $transportResult = $transport->upload($finalFile, $account . '/' . $backupFile, $destination);
        
        // Clean up local file after successful transport
        if ($transportResult['success'] && file_exists($finalFile)) {
            unlink($finalFile);
        }
        
        return $transportResult;
    }
    
    /**
     * List backups for an account from local storage
     * 
     * @param string $account Account username
     * @param string $user User requesting the list
     * @return array
     */
    public function listBackups($account, $user) {
        $localTransport = new BackBorkTransportLocal();
        $localDest = ['type' => 'Local', 'path' => '/backup'];
        
        $backups = $localTransport->findAccountBackups($account, $localDest);
        
        // Format sizes
        foreach ($backups as &$backup) {
            $backup['size_formatted'] = $this->formatSize($backup['size']);
        }
        
        return ['success' => true, 'backups' => $backups];
    }
    
    /**
     * List backups from remote destination
     * 
     * @param string $destinationId Destination ID
     * @param string $user User requesting
     * @return array
     */
    public function listRemoteBackups($destinationId, $user, $account = '') {
        $destination = $this->destinations->getDestinationById($destinationId);
        
        if (!$destination) {
            return ['success' => false, 'backups' => [], 'message' => 'Invalid destination'];
        }
        
        $validator = new BackBorkDestinationsValidator();
        $transport = $validator->getTransportForDestination($destination);
        
        $files = $transport->listFiles('', $destination);

        // If an account filter is provided, only return files that contain the substring in the filename
        if (!empty($account)) {
            $accountLower = mb_strtolower($account);
            $filtered = [];
            foreach ($files as $fileInfo) {
                $filenameLower = mb_strtolower($fileInfo['file'] ?? '');
                if (strpos($filenameLower, $accountLower) !== false) {
                    $filtered[] = $fileInfo;
                }
            }
            $files = $filtered;
        }
        
        // Format for display
        $backups = [];
        foreach ($files as $file) {
            $backups[] = [
                'file' => $file['file'],
                'size' => $this->formatSize($file['size'] ?? 0),
                'date' => $file['date'] ?? 'Unknown',
                'location' => 'remote'
            ];
        }
        
        return ['success' => true, 'backups' => $backups];
    }
    
    /**
     * Log a backup operation
     * 
     * @param string $user User who performed the operation
     * @param string $type Operation type (backup/restore)
     * @param array $accounts Affected accounts
     * @param bool $success Whether operation succeeded
     * @param string $message Details/error message
     */
    public function logOperation($user, $type, $accounts, $success, $message) {
        if (class_exists('BackBorkLog')) {
            $requestor = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : (BackBorkBootstrap::isCLI() ? 'cron' : 'local'));
            BackBorkLog::logEvent($user, $type === 'backup' ? 'backup' : $type, $accounts, $success, $message, $requestor);
        }
    }
    
    /**
     * Get operation logs
     * 
     * @param string $user User requesting logs
     * @param bool $isRoot Whether user is root
     * @param int $page Page number
     * @param int $limit Items per page
     * @param string $filter Filter type
     * @return array
     */
    public function getLogs($user, $isRoot, $page = 1, $limit = 50, $filter = 'all') {
        // Delegate to centralized logger for consistency
        if (class_exists('BackBorkLog')) {
            return BackBorkLog::getLogs($user, $isRoot, $page, $limit, $filter);
        }

        // Fallback behaviour (should rarely be used)
        $logFile = self::LOG_DIR . '/operations.log';
        $logs = [];
        
        if (!file_exists($logFile)) {
            return ['logs' => [], 'total_pages' => 0, 'current_page' => 1];
        }
        
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_reverse($lines); // Most recent first
        
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (!$entry) continue;
            
            // Filter by user if not root
            if (!$isRoot && isset($entry['user']) && $entry['user'] !== $user) {
                continue;
            }
            
            // Apply filter
            if ($filter !== 'all') {
                if ($filter === 'error' && $entry['status'] !== 'error') continue;
                if ($filter === 'backup' && $entry['type'] !== 'backup') continue;
                if ($filter === 'restore' && $entry['type'] !== 'restore') continue;
            }
            
            $logs[] = [
                'timestamp' => $entry['timestamp'],
                'type' => $entry['type'],
                'account' => is_array($entry['accounts']) ? implode(', ', $entry['accounts']) : $entry['accounts'],
                'user' => $entry['user'],
                'status' => $entry['status'],
                'message' => substr($entry['message'], 0, 200)
            ];
        }
        
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
