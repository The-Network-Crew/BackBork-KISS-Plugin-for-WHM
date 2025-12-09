<?php
/**
 * BackBork KISS - API Router
 * Routes API requests to controllers
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

// Ensure Bootstrap is loaded (in case accessed directly)
if (!defined('BACKBORK_VERSION')) {
    require_once(__DIR__ . '/../version.php');
}
if (!class_exists('BackBorkBootstrap')) {
    require_once(__DIR__ . '/../app/Bootstrap.php');
}

// Initialize if not already done
if (!BackBorkBootstrap::init()) {
    header('Content-Type: application/json');
    // Attempt to log the denied attempt for auditing purposes
    if (class_exists('BackBorkLog')) {
        $requestor = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'unknown');
        BackBorkLog::logEvent('unknown', 'api_init_denied', [], false, 'API init failed (ACL or auth)', $requestor);
    }
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Always return JSON from this router (if headers not yet sent)
if (!headers_sent()) {
    header('Content-Type: application/json');
}

// Get ACL and user info
$acl = BackBorkBootstrap::getACL();
$currentUser = $acl->getCurrentUser();
$isRoot = $acl->isRoot();

// Get requestor for logging
$requestor = BackBorkLog::getRequestor();

// Get action
$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

// Route to appropriate handler
switch ($action) {
    case 'get_accounts':
        echo json_encode($acl->getAccessibleAccounts());
        break;
        
    case 'get_config':
        $config = new BackBorkConfig();
        echo json_encode($config->getUserConfig($currentUser));
        break;
        
    case 'get_db_info':
        $system = new BackBorkWhmApiSystem();
        echo json_encode($system->detectDatabaseServer());
        break;
        
    case 'save_config':
        $config = new BackBorkConfig();
        $data = json_decode(file_get_contents('php://input'), true);
        $result = $config->saveUserConfig($currentUser, $data);
        echo json_encode($result);
        break;
        
    case 'create_backup':
        $data = json_decode(file_get_contents('php://input'), true);
        $accounts = isset($data['accounts']) ? $data['accounts'] : [];
        $destinationId = isset($data['destination']) ? $data['destination'] : '';
        
        // Validate user can access these accounts
        $accessibleAccounts = $acl->getAccessibleAccounts();
        $validAccounts = array_intersect($accounts, array_column($accessibleAccounts, 'user'));
        
        if (empty($validAccounts)) {
            echo json_encode(['success' => false, 'message' => 'No valid accounts selected']);
            break;
        }
        
        $backupManager = new BackBorkBackupManager();
        $result = $backupManager->createBackup($validAccounts, $destinationId, $currentUser);
        echo json_encode($result);
        break;
        
    case 'queue_backup':
        $data = json_decode(file_get_contents('php://input'), true);
        $accounts = isset($data['accounts']) ? $data['accounts'] : [];
        $destinationId = isset($data['destination']) ? $data['destination'] : '';
        $schedule = isset($data['schedule']) ? $data['schedule'] : 'daily';
        
        // Validate user can access these accounts
        $accessibleAccounts = $acl->getAccessibleAccounts();
        $validAccounts = array_intersect($accounts, array_column($accessibleAccounts, 'user'));
        
        if (empty($validAccounts)) {
            echo json_encode(['success' => false, 'message' => 'No valid accounts selected']);
            break;
        }
        
        $queue = new BackBorkQueue();
        $options = [];
        if (isset($data['retention'])) $options['retention'] = (int)$data['retention'];
        if (isset($data['preferred_time'])) $options['preferred_time'] = (int)$data['preferred_time'];
        
        // Properly call addToQueue with typed params: accounts, destinationId, schedule, user, options
        $result = $queue->addToQueue($validAccounts, $destinationId, $schedule, $currentUser, $options);
        echo json_encode($result);
        break;
    
    case 'create_schedule':
        $data = json_decode(file_get_contents('php://input'), true);
        $accounts = isset($data['accounts']) ? $data['accounts'] : [];
        $destinationId = isset($data['destination']) ? $data['destination'] : '';
        $schedule = isset($data['schedule']) ? $data['schedule'] : 'daily';
        
        // Validate user can access these accounts
        $accessibleAccounts = $acl->getAccessibleAccounts();
        $validAccounts = array_intersect($accounts, array_column($accessibleAccounts, 'user'));
        
        if (empty($validAccounts)) {
            echo json_encode(['success' => false, 'message' => 'No valid accounts selected']);
            break;
        }

        $queue = new BackBorkQueue();
        $options = [];
        if (isset($data['retention'])) $options['retention'] = (int)$data['retention'];
        if (isset($data['preferred_time'])) $options['preferred_time'] = (int)$data['preferred_time'];

        $result = $queue->addToQueue($validAccounts, $destinationId, $schedule, $currentUser, $options);
        echo json_encode($result);
        break;
        
    case 'get_queue':
        $queue = new BackBorkQueue();
        echo json_encode($queue->getQueue($currentUser, $isRoot));
        break;
        
    case 'remove_from_queue':
        $data = json_decode(file_get_contents('php://input'), true);
        $jobId = isset($data['job_id']) ? $data['job_id'] : '';
        
        $queue = new BackBorkQueue();
        echo json_encode($queue->removeFromQueue($jobId, $currentUser, $isRoot));
        break;

    case 'delete_schedule':
        $data = json_decode(file_get_contents('php://input'), true);
        $jobId = isset($data['job_id']) ? $data['job_id'] : '';
        
        $queue = new BackBorkQueue();
        // Reuse removeFromQueue which handles schedules as well
        echo json_encode($queue->removeFromQueue($jobId, $currentUser, $isRoot));
        break;
        
    case 'get_backups':
        $account = isset($_GET['account']) ? $_GET['account'] : '';
        
        // Validate access
        if (!$acl->canAccessAccount($account)) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            break;
        }
        
        $backupManager = new BackBorkBackupManager();
        echo json_encode($backupManager->listBackups($account, $currentUser));
        break;
        
    case 'get_remote_backups':
        $destinationId = isset($_GET['destination']) ? $_GET['destination'] : '';
        $account = isset($_GET['account']) ? $_GET['account'] : '';
        
        $backupManager = new BackBorkBackupManager();
        echo json_encode($backupManager->listRemoteBackups($destinationId, $currentUser, $account));
        break;
        
    case 'restore_backup':
        $data = json_decode(file_get_contents('php://input'), true);
        $backupFile = isset($data['backup_file']) ? $data['backup_file'] : '';
        $account = isset($data['account']) ? $data['account'] : '';
        $restoreOptions = isset($data['options']) ? $data['options'] : [];
        $destinationId = isset($data['destination']) ? $data['destination'] : '';
        
        // Validate access
        if (!$acl->canAccessAccount($account)) {
            echo json_encode(['success' => false, 'message' => 'Access denied']);
            break;
        }
        
        $restoreManager = new BackBorkRestoreManager();
        $result = $restoreManager->restoreAccount($backupFile, $destinationId, $restoreOptions, $currentUser);
        echo json_encode($result);
        break;
        
    case 'get_destinations':
        $parser = new BackBorkDestinationsParser();
        echo json_encode($parser->getAvailableDestinations());
        break;
        
    case 'test_notification':
        $data = json_decode(file_get_contents('php://input'), true);
        $type = isset($data['type']) ? $data['type'] : 'email';
        
        $config = new BackBorkConfig();
        $notify = new BackBorkNotify();
        echo json_encode($notify->testNotification($type, $config->getUserConfig($currentUser)));
        break;
        
    case 'get_logs':
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
        $filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
        
        // Use centralized logger for retrieving logs
        if (class_exists('BackBorkLog')) {
            echo json_encode(BackBorkLog::getLogs($currentUser, $isRoot, $page, $limit, $filter));
        } else {
            $backupManager = new BackBorkBackupManager();
            echo json_encode($backupManager->getLogs($currentUser, $isRoot, $page, $limit, $filter));
        }
        break;
        
    case 'get_status':
        $queue = new BackBorkQueue();
        echo json_encode($queue->getRunningJobs($currentUser, $isRoot));
        break;
    
    case 'process_queue':
        // Manual trigger to process schedules and queue (protected via ACL)
        // Only allow root to manually trigger processing
        if (!$isRoot) {
            if (class_exists('BackBorkLog')) {
                BackBorkLog::logEvent($currentUser, 'queue_process_denied', [], false, 'Non-root user attempted to trigger process_queue', $requestor);
            }
            echo json_encode(['success' => false, 'message' => 'Access denied: manual processing requires root']);
            break;
        }

        $processor = new BackBorkQueueProcessor();
        try {
            $scheduled = $processor->processSchedules();
            $processed = $processor->processQueue();
            
            if (class_exists('BackBorkLog')) {
                BackBorkLog::logEvent($currentUser, 'queue_process', [], true, 'Manual queue process completed', $requestor);
            }
            echo json_encode(['success' => true, 'scheduled' => $scheduled, 'processed' => $processed]);
        } catch (Exception $e) {
            if (class_exists('BackBorkLog')) {
                BackBorkLog::logEvent($currentUser, 'queue_process', [], false, 'Manual queue process failed: ' . $e->getMessage(), $requestor);
            }
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        break;
        
    case 'check_cron':
        $cronStatus = ['installed' => false, 'path' => '', 'schedule' => '', 'command' => '', 'message' => ''];
        
        // Check /etc/cron.d/backbork
        $cronFile = '/etc/cron.d/backbork';
        if (file_exists($cronFile)) {
            $cronStatus['installed'] = true;
            $cronStatus['path'] = $cronFile;
            $content = file_get_contents($cronFile);
            // Extract schedule and command from cron file
            if (preg_match('/^([0-9*\/,\-]+\s+[0-9*\/,\-]+\s+[0-9*\/,\-]+\s+[0-9*\/,\-]+\s+[0-9*\/,\-]+)\s+(.+)$/m', $content, $matches)) {
                $cronStatus['schedule'] = trim($matches[1]);
                $cronStatus['command'] = trim($matches[2]);
            } else {
                $cronStatus['command'] = trim($content);
            }
        } else {
            // Check root crontab
            $crontab = shell_exec('crontab -l 2>/dev/null');
            if ($crontab && strpos($crontab, 'backbork') !== false) {
                $cronStatus['installed'] = true;
                $cronStatus['path'] = 'root crontab';
                // Find the line with backbork
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
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}