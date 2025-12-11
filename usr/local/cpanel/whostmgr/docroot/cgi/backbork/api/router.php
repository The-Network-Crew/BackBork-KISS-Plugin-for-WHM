<?php
/**
 * BackBork KISS - API Router
 * 
 * Central API endpoint that handles all AJAX requests from the frontend.
 * Routes requests to appropriate handlers based on the 'action' parameter.
 * 
 * All responses are JSON formatted. Access control is enforced via ACL.
 * 
 * Available Actions:
 * - get_accounts: List accounts user can access
 * - get_config/save_config: User configuration management
 * - get_global_config/save_global_config: Global settings (root only)
 * - create_backup: Immediate backup execution
 * - queue_backup: Add backup to queue for later processing
 * - create_schedule: Create recurring backup schedule
 * - delete_schedule: Remove a schedule
 * - get_queue: Get queue and schedule status
 * - restore_backup: Restore from backup file
 * - get_destinations: List available backup destinations
 * - get_logs: Retrieve operation logs
 * - process_queue: Manually trigger queue processing (root only)
 * - check_cron: Check cron job status
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

// ============================================================================
// INITIALIZATION
// ============================================================================

// Ensure Bootstrap is loaded (in case accessed directly via URL)
if (!defined('BACKBORK_VERSION')) {
    require_once(__DIR__ . '/../version.php');
}
if (!class_exists('BackBorkBootstrap')) {
    require_once(__DIR__ . '/../app/Bootstrap.php');
}

// Initialize application and verify user has access
if (!BackBorkBootstrap::init()) {
    // Access denied - return JSON error and log the attempt
    header('Content-Type: application/json');
    
    // Log failed access attempt for security auditing
    if (class_exists('BackBorkLog')) {
        $requestor = isset($_SERVER['HTTP_X_FORWARDED_FOR']) 
            ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] 
            : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown');
        BackBorkLog::logEvent('unknown', 'api_init_denied', [], false, 'API init failed (ACL or auth)', $requestor);
    }
    
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Set JSON content type for all API responses
if (!headers_sent()) {
    header('Content-Type: application/json');
}

// ============================================================================
// GET CURRENT USER CONTEXT
// ============================================================================

// Get ACL instance for permission checks
$acl = BackBorkBootstrap::getACL();

// Current authenticated user (from WHM session)
$currentUser = $acl->getCurrentUser();

// Is this user root? (has full access)
$isRoot = $acl->isRoot();

// Get requestor IP for audit logging
$requestor = BackBorkLog::getRequestor();

// ============================================================================
// ROUTE REQUEST TO HANDLER
// ============================================================================

// Get requested action from POST or GET parameters
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

// Route to appropriate handler based on action
switch ($action) {

    // ========================================================================
    // ACCOUNT MANAGEMENT
    // ========================================================================
    
    /**
     * Get list of accounts the current user can access
     * Root sees all accounts, resellers see only their owned accounts
     */
    case 'get_accounts':
        echo json_encode($acl->getAccessibleAccounts());
        break;
    
    // ========================================================================
    // CONFIGURATION MANAGEMENT
    // ========================================================================
    
    /**
     * Get current user's configuration settings
     * Also includes global config info for root users
     */
    case 'get_config':
        $config = new BackBorkConfig();
        $userConfig = $config->getUserConfig($currentUser);
        
        // Root gets additional global config information
        if ($isRoot) {
            $userConfig['_global'] = $config->getGlobalConfig();
            $userConfig['_resellers'] = $acl->getResellers();
            $userConfig['_users_with_schedules'] = $acl->getUsersWithSchedules();
        } else {
            // Non-root users only get schedule lock status
            $userConfig['_schedules_locked'] = BackBorkConfig::areSchedulesLocked();
        }
        echo json_encode($userConfig);
        break;
    
    /**
     * Get global configuration (root only)
     * Returns server-wide settings like schedule locks
     */
    case 'get_global_config':
        // Security: Only root can access global config
        if (!$isRoot) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            break;
        }
        $config = new BackBorkConfig();
        echo json_encode($config->getGlobalConfig());
        break;
    
    /**
     * Save global configuration (root only)
     * Updates server-wide settings
     */
    case 'save_global_config':
        // Security: Only root can modify global config
        if (!$isRoot) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            break;
        }
        $config = new BackBorkConfig();
        $data = json_decode(file_get_contents('php://input'), true);
        $result = $config->saveGlobalConfig($data, $currentUser);
        echo json_encode($result);
        break;
    
    /**
     * Toggle schedule lock status (root only)
     * When locked, resellers cannot create/modify/delete schedules
     */
    case 'set_schedules_lock':
        // Security: Only root can lock schedules
        if (!$isRoot) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            break;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $locked = isset($data['locked']) ? (bool)$data['locked'] : false;
        $config = new BackBorkConfig();
        $result = $config->setSchedulesLocked($locked, $currentUser);
        echo json_encode($result);
        break;
    
    /**
     * Save user's configuration settings
     * Each user has their own notification and backup settings
     */
    case 'save_config':
        $config = new BackBorkConfig();
        $data = json_decode(file_get_contents('php://input'), true);
        
        // Root-only: handle batched global settings (single save, single log entry)
        if ($isRoot && isset($data['_global_settings']) && is_array($data['_global_settings'])) {
            $globalUpdates = [];
            foreach ($data['_global_settings'] as $key => $value) {
                if ($value !== null) {
                    $globalUpdates[$key] = (bool)$value;
                }
            }
            if (!empty($globalUpdates)) {
                $config->saveGlobalConfig($globalUpdates, $currentUser);
            }
            unset($data['_global_settings']);
        }
        
        $result = $config->saveUserConfig($currentUser, $data);
        echo json_encode($result);
        break;
    
    // ========================================================================
    // DATABASE INFO
    // ========================================================================
    
    /**
     * Get database server information
     * Returns MySQL/MariaDB version and available backup tools
     */
    case 'get_db_info':
        $system = new BackBorkWhmApiSystem();
        echo json_encode($system->detectDatabaseServer());
        break;
    
    // ========================================================================
    // BACKUP OPERATIONS
    // ========================================================================
    
    /**
     * Create an immediate backup
     * Runs backup synchronously and returns result
     */
    case 'create_backup':
        $data = json_decode(file_get_contents('php://input'), true);
        $accounts = isset($data['accounts']) ? $data['accounts'] : [];
        $destinationId = isset($data['destination']) ? $data['destination'] : '';
        
        // Security: Validate user can access requested accounts
        $accessibleAccounts = $acl->getAccessibleAccounts();
        $validAccounts = array_intersect($accounts, array_column($accessibleAccounts, 'user'));
        
        if (empty($validAccounts)) {
            echo json_encode(['success' => false, 'message' => 'No valid accounts selected']);
            break;
        }
        
        // Execute backup
        $backupManager = new BackBorkBackupManager();
        $result = $backupManager->createBackup($validAccounts, $destinationId, $currentUser);
        echo json_encode($result);
        break;
    
    /**
     * Add backup job to queue
     * Job will be processed by cron handler
     */
    case 'queue_backup':
        $data = json_decode(file_get_contents('php://input'), true);
        $accounts = isset($data['accounts']) ? $data['accounts'] : [];
        $destinationId = isset($data['destination']) ? $data['destination'] : '';
        $schedule = isset($data['schedule']) ? $data['schedule'] : 'daily';
        
        // Security: Validate user can access requested accounts
        $accessibleAccounts = $acl->getAccessibleAccounts();
        $validAccounts = array_intersect($accounts, array_column($accessibleAccounts, 'user'));
        
        if (empty($validAccounts)) {
            echo json_encode(['success' => false, 'message' => 'No valid accounts selected']);
            break;
        }
        
        // Add to queue
        $queue = new BackBorkQueue();
        $options = [];
        if (isset($data['retention'])) $options['retention'] = (int)$data['retention'];
        if (isset($data['preferred_time'])) $options['preferred_time'] = (int)$data['preferred_time'];
        
        $result = $queue->addToQueue($validAccounts, $destinationId, $schedule, $currentUser, $options);
        echo json_encode($result);
        break;
    
    // ========================================================================
    // SCHEDULE MANAGEMENT
    // ========================================================================
    
    /**
     * Create a recurring backup schedule
     * Supports "all accounts" mode for dynamic account resolution
     */
    case 'create_schedule':
        $data = json_decode(file_get_contents('php://input'), true);
        $accounts = isset($data['accounts']) ? $data['accounts'] : [];
        $destinationId = isset($data['destination']) ? $data['destination'] : '';
        $schedule = isset($data['schedule']) ? $data['schedule'] : 'daily';
        $allAccounts = isset($data['all_accounts']) ? (bool)$data['all_accounts'] : false;
        
        // Security: Check if schedules are locked for resellers
        if (!$isRoot && BackBorkConfig::areSchedulesLocked()) {
            echo json_encode(['success' => false, 'message' => 'Schedules are locked by administrator']);
            break;
        }
        
        // Handle "all accounts" mode - store wildcard for runtime resolution
        if ($allAccounts || (is_array($accounts) && in_array('*', $accounts))) {
            // Store ['*'] as placeholder - resolved to actual accounts at execution time
            $validAccounts = ['*'];
        } else {
            // Validate user can access specific requested accounts
            $accessibleAccounts = $acl->getAccessibleAccounts();
            $validAccounts = array_intersect($accounts, array_column($accessibleAccounts, 'user'));
            
            if (empty($validAccounts)) {
                echo json_encode(['success' => false, 'message' => 'No valid accounts selected']);
                break;
            }
        }

        // Create the schedule
        $queue = new BackBorkQueue();
        $options = [];
        if (isset($data['retention'])) $options['retention'] = (int)$data['retention'];
        if (isset($data['preferred_time'])) $options['preferred_time'] = (int)$data['preferred_time'];
        if ($allAccounts) $options['all_accounts'] = true;

        $result = $queue->addToQueue($validAccounts, $destinationId, $schedule, $currentUser, $options);
        echo json_encode($result);
        break;
    
    /**
     * Delete a schedule
     * Users can only delete their own schedules unless root
     */
    case 'delete_schedule':
        $data = json_decode(file_get_contents('php://input'), true);
        $jobId = isset($data['job_id']) ? $data['job_id'] : '';
        
        // Security: Check if schedules are locked for resellers
        if (!$isRoot && BackBorkConfig::areSchedulesLocked()) {
            echo json_encode(['success' => false, 'message' => 'Schedules are locked by administrator']);
            break;
        }
        
        // Delete the schedule
        $queue = new BackBorkQueue();
        echo json_encode($queue->removeFromQueue($jobId, $currentUser, $isRoot));
        break;
    
    // ========================================================================
    // QUEUE MANAGEMENT
    // ========================================================================
    
    /**
     * Get queue status including pending jobs, running jobs, and schedules
     * Root can filter by specific user with view_user parameter
     */
    case 'get_queue':
        $queue = new BackBorkQueue();
        // Optional: Root can view as specific user
        $viewAsUser = isset($_GET['view_user']) ? $_GET['view_user'] : null;
        echo json_encode($queue->getQueue($currentUser, $isRoot, $viewAsUser));
        break;
    
    /**
     * Remove a job from the queue
     * Users can only remove their own jobs unless root
     */
    case 'remove_from_queue':
        $data = json_decode(file_get_contents('php://input'), true);
        $jobId = isset($data['job_id']) ? $data['job_id'] : '';
        
        $queue = new BackBorkQueue();
        echo json_encode($queue->removeFromQueue($jobId, $currentUser, $isRoot));
        break;
    
    /**
     * Manually trigger queue processing (root only)
     * Processes schedules and runs pending queue jobs
     */
    case 'process_queue':
        // Security: Only root can manually trigger processing
        if (!$isRoot) {
            if (class_exists('BackBorkLog')) {
                BackBorkLog::logEvent($currentUser, 'queue_process_denied', [], false, 
                    'Non-root user attempted to trigger process_queue', $requestor);
            }
            echo json_encode(['success' => false, 'message' => 'Access denied: manual processing requires root']);
            break;
        }

        // Process schedules and queue
        $processor = new BackBorkQueueProcessor();
        try {
            $scheduled = $processor->processSchedules();
            $processed = $processor->processQueue();
            
            // Log successful processing
            if (class_exists('BackBorkLog')) {
                BackBorkLog::logEvent($currentUser, 'queue_process', [], true, 
                    'Manual queue process completed', $requestor);
            }
            echo json_encode(['success' => true, 'scheduled' => $scheduled, 'processed' => $processed]);
        } catch (Exception $e) {
            // Log failed processing
            if (class_exists('BackBorkLog')) {
                BackBorkLog::logEvent($currentUser, 'queue_process', [], false, 
                    'Manual queue process failed: ' . $e->getMessage(), $requestor);
            }
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;
    
    /**
     * Get running job status
     */
    case 'get_status':
        $queue = new BackBorkQueue();
        echo json_encode($queue->getRunningJobs($currentUser, $isRoot));
        break;
    
    // ========================================================================
    // RESTORE OPERATIONS
    // ========================================================================
    
    /**
     * Get list of local backups for an account
     */
    case 'get_backups':
        $account = isset($_GET['account']) ? $_GET['account'] : '';
        
        // Security: Validate user can access this account
        if (!$acl->canAccessAccount($account)) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            break;
        }
        
        $backupManager = new BackBorkBackupManager();
        echo json_encode($backupManager->listBackups($account, $currentUser));
        break;
    
    /**
     * Get list of remote backups from a destination
     */
    case 'get_remote_backups':
        $destinationId = isset($_GET['destination']) ? $_GET['destination'] : '';
        $account = isset($_GET['account']) ? $_GET['account'] : '';
        
        $backupManager = new BackBorkBackupManager();
        echo json_encode($backupManager->listRemoteBackups($destinationId, $currentUser, $account));
        break;
    
    /**
     * Restore account from backup
     */
    case 'restore_backup':
        $data = json_decode(file_get_contents('php://input'), true);
        $backupFile = isset($data['backup_file']) ? $data['backup_file'] : '';
        $account = isset($data['account']) ? $data['account'] : '';
        $restoreOptions = isset($data['options']) ? $data['options'] : [];
        $destinationId = isset($data['destination']) ? $data['destination'] : '';
        
        // Security: Validate user can access this account
        if (!$acl->canAccessAccount($account)) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            break;
        }
        
        // Execute restore
        $restoreManager = new BackBorkRestoreManager();
        $result = $restoreManager->restoreAccount($backupFile, $destinationId, $restoreOptions, $currentUser);
        echo json_encode($result);
        break;
    
    /**
     * Delete a backup file from a destination
     * Only works for Local destinations (remote deletion not supported)
     */
    case 'delete_backup':
        $data = json_decode(file_get_contents('php://input'), true);
        $destinationId = isset($data['destination']) ? $data['destination'] : '';
        // Accept 'path' (full), 'filename', or 'backup_file'
        $backupPath = isset($data['path']) ? $data['path'] : '';
        $backupFile = isset($data['filename']) ? $data['filename'] : 
                     (isset($data['backup_file']) ? $data['backup_file'] : '');
        
        if (empty($destinationId) || (empty($backupFile) && empty($backupPath))) {
            echo json_encode(['success' => false, 'message' => 'Destination and backup file are required']);
            break;
        }
        
        // Extract account name from backup filename for permission check
        $account = isset($data['account']) ? $data['account'] : null;
        $fileToCheck = $backupPath ?: $backupFile;
        if (!$account && preg_match('/cpmove-([a-z0-9_]+?)(?:_\d{4}-\d{2}-\d{2}|\.tar|$)/i', basename($fileToCheck), $matches)) {
            $account = $matches[1];
        }
        
        // Security: Validate user can access this account's backups
        if ($account && !$acl->canAccessAccount($account)) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            break;
        }
        
        // Get destination info
        $parser = new BackBorkDestinationsParser();
        $destination = $parser->getDestinationById($destinationId);
        
        if (!$destination) {
            echo json_encode(['success' => false, 'message' => 'Destination not found']);
            break;
        }
        
        // Only allow deletion from Local destinations
        if (strtolower($destination['type']) !== 'local') {
            echo json_encode(['success' => false, 'message' => 'Deletion only supported for local destinations']);
            break;
        }
        
        // If full path provided, use it directly; otherwise build from filename
        $basePath = $destination['path'] ?? '/backup';
        if ($backupPath && strpos($backupPath, $basePath) === 0) {
            // Full path provided and it's within the destination - use directly
            $fullPath = $backupPath;
        } else {
            // Build path: check account subdir first, then root
            $accountDir = rtrim($basePath, '/') . '/' . $account . '/' . $backupFile;
            $rootDir = rtrim($basePath, '/') . '/' . $backupFile;
            $fullPath = file_exists($accountDir) ? $accountDir : $rootDir;
        }
        
        // Verify file exists and delete
        if (!file_exists($fullPath)) {
            echo json_encode(['success' => false, 'message' => 'Backup file not found']);
            break;
        }
        
        if (unlink($fullPath)) {
            BackBorkLog::logEvent($currentUser, 'delete', [$account ?? basename($fullPath)], true, 
                "Deleted backup: " . basename($fullPath), BackBorkBootstrap::getRequestor());
            echo json_encode(['success' => true, 'message' => 'Backup deleted successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete backup file']);
        }
        break;
    
    /**
     * Get list of accounts that have backups at a destination
     * Lists account directories in the backup path
     */
    case 'get_backup_accounts':
        $data = json_decode(file_get_contents('php://input'), true);
        $destinationId = isset($data['destination']) ? $data['destination'] : 
                        (isset($_GET['destination']) ? $_GET['destination'] : '');
        
        if (empty($destinationId)) {
            echo json_encode(['success' => false, 'message' => 'Destination is required']);
            break;
        }
        
        // Get destination info
        $parser = new BackBorkDestinationsParser();
        $destination = $parser->getDestinationById($destinationId);
        
        if (!$destination) {
            echo json_encode(['success' => false, 'message' => 'Destination not found']);
            break;
        }
        
        // Only works for Local destinations
        if (strtolower($destination['type']) !== 'local') {
            echo json_encode(['success' => false, 'message' => 'Account listing only supported for local destinations']);
            break;
        }
        
        $backupDir = isset($destination['path']) ? $destination['path'] : '';
        if (empty($backupDir) || !is_dir($backupDir)) {
            echo json_encode(['success' => false, 'message' => 'Backup directory not found']);
            break;
        }
        
        // List subdirectories (account folders) and filter by ACL
        $accounts = [];
        $dirs = glob($backupDir . '/*', GLOB_ONLYDIR);
        foreach ($dirs as $dir) {
            $account = basename($dir);
            if ($acl->canAccessAccount($account)) {
                $accounts[] = $account;
            }
        }
        
        sort($accounts);
        echo json_encode(['success' => true, 'accounts' => $accounts]);
        break;
    
    /**
     * List backups for a specific account at a destination
     */
    case 'list_backups':
        $data = json_decode(file_get_contents('php://input'), true);
        $destinationId = isset($data['destination']) ? $data['destination'] : 
                        (isset($_GET['destination']) ? $_GET['destination'] : '');
        $account = isset($data['account']) ? $data['account'] : 
                  (isset($_GET['account']) ? $_GET['account'] : '');
        
        if (empty($destinationId) || empty($account)) {
            echo json_encode(['success' => false, 'message' => 'Destination and account are required']);
            break;
        }
        
        // Security: Validate user can access this account
        if (!$acl->canAccessAccount($account)) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            break;
        }
        
        // Get destination info
        $parser = new BackBorkDestinationsParser();
        $destination = $parser->getDestinationById($destinationId);
        
        if (!$destination) {
            echo json_encode(['success' => false, 'message' => 'Destination not found']);
            break;
        }
        
        // Only works for Local destinations
        if (strtolower($destination['type']) !== 'local') {
            echo json_encode(['success' => false, 'message' => 'Backup listing only supported for local destinations']);
            break;
        }
        
        $backupDir = isset($destination['path']) ? $destination['path'] : '';
        if (empty($backupDir) || !is_dir($backupDir)) {
            echo json_encode(['success' => false, 'message' => 'Backup directory not found']);
            break;
        }
        
        // Find all backups in the account's directory
        $backups = [];
        $accountDir = rtrim($backupDir, '/') . '/' . $account;
        
        if (is_dir($accountDir)) {
            $files = glob($accountDir . '/cpmove-*.tar.gz');
            foreach ($files as $file) {
                $backups[] = [
                    'file' => basename($file),
                    'path' => $file,
                    'size' => filesize($file),
                    'modified' => filemtime($file)
                ];
            }
        }
        
        // Sort by modified date (oldest first)
        usort($backups, function($a, $b) {
            return $a['modified'] - $b['modified'];
        });
        
        echo json_encode(['success' => true, 'backups' => $backups]);
        break;
    
    // ========================================================================
    // DESTINATION MANAGEMENT
    // ========================================================================
    
    /**
     * Get list of available backup destinations
     * Reads from WHM's backup configuration
     * Filters out root-only destinations for resellers
     */
    case 'get_destinations':
        $parser = new BackBorkDestinationsParser();
        echo json_encode($parser->getAvailableDestinations($isRoot));
        break;
    
    /**
     * Get destination visibility settings (root only)
     * Returns which destinations are marked as root-only
     */
    case 'get_destination_visibility':
        if (!$isRoot) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            break;
        }
        $rootOnlyDests = BackBorkConfig::getRootOnlyDestinations();
        echo json_encode(['success' => true, 'root_only_destinations' => $rootOnlyDests]);
        break;
    
    /**
     * Set destination visibility (root only)
     * Mark specific destinations as root-only (hidden from resellers)
     */
    case 'set_destination_visibility':
        if (!$isRoot) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            break;
        }
        $data = json_decode(file_get_contents('php://input'), true);
        $destId = isset($data['destination_id']) ? $data['destination_id'] : '';
        $rootOnly = isset($data['root_only']) ? (bool)$data['root_only'] : false;
        
        if (empty($destId)) {
            echo json_encode(['success' => false, 'message' => 'Destination ID required']);
            break;
        }
        
        $result = BackBorkConfig::setDestinationRootOnly($destId, $rootOnly);
        if ($result) {
            BackBorkLog::logEvent($currentUser, 'destination_visibility_changed', [
                'destination' => $destId,
                'root_only' => $rootOnly
            ], true, "Destination '$destId' visibility set to " . ($rootOnly ? 'root-only' : 'all users'), $requestor);
        }
        echo json_encode(['success' => $result]);
        break;
    
    // ========================================================================
    // NOTIFICATIONS
    // ========================================================================
    
    /**
     * Send a test notification (email or Slack)
     */
    case 'test_notification':
        $data = json_decode(file_get_contents('php://input'), true);
        $type = isset($data['type']) ? $data['type'] : 'email';
        
        $config = new BackBorkConfig();
        $notify = new BackBorkNotify();
        echo json_encode($notify->testNotification($type, $config->getUserConfig($currentUser)));
        break;
    
    // ========================================================================
    // LOGS
    // ========================================================================
    
    /**
     * Get operation logs
     * Supports pagination and filtering
     */
    case 'get_logs':
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
        
        // Use centralized logger
        if (class_exists('BackBorkLog')) {
            echo json_encode(BackBorkLog::getLogs($currentUser, $isRoot, $page, $limit, $filter));
        } else {
            // Fallback to backup manager's log method
            $backupManager = new BackBorkBackupManager();
            echo json_encode($backupManager->getLogs($currentUser, $isRoot, $page, $limit, $filter));
        }
        break;
    
    /**
     * Get restore log content for real-time progress viewing
     * Used for tailing restore output during long operations
     */
    case 'get_restore_log':
        $restoreId = isset($_GET['restore_id']) ? $_GET['restore_id'] : '';
        $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
        
        // Validate restore_id format (security)
        if (!preg_match('/^restore_[0-9]+_[a-f0-9]+$/', $restoreId)) {
            echo json_encode(['success' => false, 'message' => 'Invalid restore ID']);
            break;
        }
        
        $logFile = '/usr/local/cpanel/3rdparty/backbork/logs/' . $restoreId . '.log';
        
        if (!file_exists($logFile)) {
            echo json_encode(['success' => false, 'message' => 'Log file not found', 'content' => '', 'offset' => 0, 'complete' => false]);
            break;
        }
        
        // Read log content from offset
        $content = '';
        $fileSize = filesize($logFile);
        
        if ($offset < $fileSize) {
            $handle = fopen($logFile, 'r');
            fseek($handle, $offset);
            $content = fread($handle, $fileSize - $offset);
            fclose($handle);
        }
        
        // Check if restore is complete (look for completion markers)
        $isComplete = (strpos(file_get_contents($logFile), 'RESTORE COMPLETED SUCCESSFULLY') !== false) ||
                      (strpos(file_get_contents($logFile), 'RESTORE FAILED') !== false);
        
        echo json_encode([
            'success' => true,
            'content' => $content,
            'offset' => $fileSize,
            'complete' => $isComplete
        ]);
        break;
    
    // ========================================================================
    // CRON STATUS
    // ========================================================================
    
    /**
     * Check if cron job is properly configured
     * Returns cron file path, schedule, and command
     */
    case 'check_cron':
        $cronStatus = [
            'installed' => false, 
            'path' => '', 
            'schedule' => '', 
            'command' => '', 
            'message' => ''
        ];
        
        // Check /etc/cron.d/backbork first (preferred location)
        $cronFile = '/etc/cron.d/backbork';
        if (file_exists($cronFile)) {
            $cronStatus['installed'] = true;
            $cronStatus['path'] = $cronFile;
            $content = file_get_contents($cronFile);
            
            // Parse cron schedule and command from file
            if (preg_match('/^([0-9*\/,\-]+\s+[0-9*\/,\-]+\s+[0-9*\/,\-]+\s+[0-9*\/,\-]+\s+[0-9*\/,\-]+)\s+(.+)$/m', $content, $matches)) {
                $cronStatus['schedule'] = trim($matches[1]);
                $cronStatus['command'] = trim($matches[2]);
            } else {
                $cronStatus['command'] = trim($content);
            }
        } else {
            // Fallback: Check root's crontab
            $crontab = shell_exec('crontab -l 2>/dev/null');
            if ($crontab && strpos($crontab, 'backbork') !== false) {
                $cronStatus['installed'] = true;
                $cronStatus['path'] = 'root crontab';
                
                // Parse backbork line from crontab
                if (preg_match('/^([0-9*\/,\-]+\s+[0-9*\/,\-]+\s+[0-9*\/,\-]+\s+[0-9*\/,\-]+\s+[0-9*\/,\-]+)\s+(.*backbork.*)$/m', $crontab, $matches)) {
                    $cronStatus['schedule'] = trim($matches[1]);
                    $cronStatus['command'] = trim($matches[2]);
                }
            } else {
                $cronStatus['message'] = 'No cron job found. Run install.sh to configure.';
            }
        }
        
        echo json_encode($cronStatus);
        break;
    
    // ========================================================================
    // DEFAULT (INVALID ACTION)
    // ========================================================================
    
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}