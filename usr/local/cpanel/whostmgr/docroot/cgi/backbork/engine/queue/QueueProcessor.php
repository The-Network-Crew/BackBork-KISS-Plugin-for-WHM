<?php
/**
 * BackBork KISS - Queue Processor
 * 
 * Cron-triggered processor for backup queue items and recurring schedules.
 * This is the workhorse that actually executes backup and restore jobs.
 * 
 * Processing Flow:
 * 1. Acquire exclusive lock (prevent concurrent runs)
 * 2. Process schedules - add due schedules to queue
 * 3. Process queue - execute pending backup/restore jobs
 * 4. Move completed jobs to completed directory
 * 5. Release lock
 * 
 * Key Features:
 * - Single-threaded processing (one job at a time)
 * - Lock file to prevent concurrent execution
 * - Schedule management (hourly, daily, weekly, monthly)
 * - "All Accounts" dynamic resolution for schedules
 * - Job state tracking (queued → running → completed/failed)
 * - Automatic stale lock cleanup
 * - Completed job retention management
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

class BackBorkQueueProcessor {
    
    // ========================================================================
    // CONSTANTS
    // ========================================================================
    
    /** Lock file path to prevent concurrent processing */
    const LOCK_FILE = '/tmp/backbork_queue.lock';
    
    /** Maximum concurrent backups (keep at 1 to prevent server overload) */
    const MAX_CONCURRENT = 1;
    
    // ========================================================================
    // PROPERTIES
    // ========================================================================
    
    /** @var BackBorkQueue Queue data manager */
    private $queue;
    
    /** @var BackBorkBackupManager Backup execution engine */
    private $backupManager;
    
    /** @var BackBorkConfig Configuration manager */
    private $config;
    
    // ========================================================================
    // CONSTRUCTOR
    // ========================================================================
    
    /**
     * Initialize the Queue Processor
     * Creates instances of required managers
     */
    public function __construct() {
        $this->queue = new BackBorkQueue();
        $this->backupManager = new BackBorkBackupManager();
        $this->config = new BackBorkConfig();
    }
    
    // ========================================================================
    // MAIN QUEUE PROCESSING
    // ========================================================================
    
    /**
     * Process all pending queue items
     * 
     * Main entry point called by cron. Processes all queued backup/restore
     * jobs one at a time, tracking success/failure for each.
     * 
     * @return array Processing results with counts and account lists
     */
    public function processQueue() {
        // Try to acquire exclusive lock
        if (!$this->acquireLock()) {
            BackBorkConfig::debugLog('processQueue: Failed to acquire lock');
            return [
                'success' => true,
                'message' => 'Queue processor already running, skipped',
                'processed' => 0,
                'skipped' => true
            ];
        }
        
        BackBorkConfig::debugLog('processQueue: Lock acquired, starting processing');
        
        try {
            $results = [];
            $processed = 0;
            $failed = 0;
            $processedAccounts = [];
            $failedAccounts = [];
            
            // Get all pending queue items (running as root since cron runs as root)
            $queueData = $this->queue->getQueue('root', true);
            $queuedJobs = isset($queueData['queued']) ? $queueData['queued'] : [];
            
            BackBorkConfig::debugLog('processQueue: Found ' . count($queuedJobs) . ' queued jobs');
            
            // Nothing to process
            if (empty($queuedJobs)) {
                $this->releaseLock();
                return [
                    'success' => true,
                    'message' => 'No items in queue',
                    'processed' => 0,
                    'accounts' => []
                ];
            }
            
            // Process each queued job sequentially
            foreach ($queuedJobs as $item) {
                $id = $item['id'];
                $itemAccounts = $item['accounts'] ?? [];
                $itemStatus = $item['status'] ?? 'MISSING';
                
                BackBorkConfig::debugLog('processQueue: Processing job ' . $id . ' with status: ' . $itemStatus);
                
                // Only process jobs with 'queued' status
                if (!isset($item['status']) || $item['status'] !== 'queued') {
                    BackBorkConfig::debugLog('processQueue: Skipping job ' . $id . ' - status not queued');
                    continue;
                }
                
                BackBorkConfig::debugLog('processQueue: Moving job ' . $id . ' to running');
                
                // Move job to running directory and update status
                $moveResult = $this->queue->moveJob($id, BackBorkQueue::getQueueDir(), BackBorkQueue::getRunningDir(), [
                    'status' => 'processing',
                    'started_at' => date('Y-m-d H:i:s')
                ]);
                
                if (!$moveResult) {
                    BackBorkConfig::debugLog('processQueue: Failed to move job ' . $id . ' to running');
                    continue;
                }
                
                BackBorkConfig::debugLog('processQueue: Executing job ' . $id);
                
                // Execute the job (backup or restore)
                $result = $this->processItem($id, $item);
                $results[$id] = $result;
                
                BackBorkConfig::debugLog('processQueue: Job ' . $id . ' result: ' . ($result['success'] ? 'success' : 'failed') . ' - ' . ($result['message'] ?? 'no message'));
                
                // Handle job completion
                if ($result['success']) {
                    // Success: move to completed directory
                    $this->queue->moveJob($id, BackBorkQueue::getRunningDir(), BackBorkQueue::getCompletedDir(), [
                        'status' => 'completed',
                        'completed_at' => date('Y-m-d H:i:s'),
                        'result' => $result['message'] ?? 'Success'
                    ]);
                    $processed++;
                    $processedAccounts = array_merge($processedAccounts, $itemAccounts);
                } else {
                    // Failure: move to completed with failed status
                    $this->queue->moveJob($id, BackBorkQueue::getRunningDir(), BackBorkQueue::getCompletedDir(), [
                        'status' => 'failed',
                        'completed_at' => date('Y-m-d H:i:s'),
                        'error' => $result['message'] ?? 'Unknown error'
                    ]);
                    $failed++;
                    $failedAccounts = array_merge($failedAccounts, $itemAccounts);
                }
            }
            
            $this->releaseLock();
            
            // Build comprehensive result summary
            $allAccounts = array_merge($processedAccounts, $failedAccounts);
            
            return [
                'success' => true,
                'message' => "Processed {$processed} items, {$failed} failed",
                'processed' => $processed,
                'failed' => $failed,
                'accounts' => $allAccounts,
                'processed_accounts' => $processedAccounts,
                'failed_accounts' => $failedAccounts,
                'results' => $results
            ];
            
        } catch (Exception $e) {
            // Ensure lock is released on error
            $this->releaseLock();
            return [
                'success' => false,
                'message' => 'Queue processing error: ' . $e->getMessage(),
                'processed' => 0
            ];
        }
    }
    
    // ========================================================================
    // ITEM PROCESSING
    // ========================================================================
    
    /**
     * Process a single queue item (backup or restore)
     * 
     * Routes to appropriate handler based on item type.
     * 
     * @param string $id Queue item ID
     * @param array $item Queue item data
     * @return array Result with success status and message
     */
    private function processItem($id, $item) {
        $type = $item['type'] ?? 'backup';
        
        if ($type === 'backup') {
            return $this->processBackupItem($item);
        } elseif ($type === 'restore') {
            return $this->processRestoreItem($item);
        }
        
        return [
            'success' => false,
            'message' => 'Unknown queue item type: ' . $type
        ];
    }
    
    /**
     * Process a backup queue item
     * 
     * Delegates actual backup execution to BackupManager.
     * 
     * @param array $item Queue item data with accounts, destination, user
     * @return array Result from BackupManager
     */
    private function processBackupItem($item) {
        $accounts = $item['accounts'] ?? [];
        $destination = $item['destination'] ?? 'local';
        $user = $item['user'] ?? 'root';
        
        if (empty($accounts)) {
            return ['success' => false, 'message' => 'No accounts specified'];
        }
        
        // Execute backup via BackupManager
        return $this->backupManager->createBackup($accounts, $destination, $user);
    }
    
    /**
     * Process a restore queue item
     * 
     * Delegates actual restore execution to RestoreManager.
     * 
     * @param array $item Queue item data with backup_file, destination, options
     * @return array Result from RestoreManager
     */
    private function processRestoreItem($item) {
        $backupFile = $item['backup_file'] ?? '';
        $destination = $item['destination'] ?? 'local';
        $options = $item['options'] ?? [];
        $user = $item['user'] ?? 'root';
        
        if (empty($backupFile)) {
            return ['success' => false, 'message' => 'No backup file specified'];
        }
        
        // Execute restore via RestoreManager
        $restoreManager = new BackBorkRestoreManager();
        return $restoreManager->restoreAccount($backupFile, $destination, $options, $user);
    }
    
    // ========================================================================
    // SCHEDULE PROCESSING
    // ========================================================================
    
    /**
     * Process recurring backup schedules
     * 
     * Checks all schedules for due items and adds them to the queue.
     * Supports "all accounts" mode which dynamically resolves accounts
     * based on the schedule owner's accessible accounts.
     * 
     * @return array Results with scheduled counts
     */
    public function processSchedules() {
        $schedulesDir = BackBorkQueue::SCHEDULES_DIR;
        $scheduleFiles = glob($schedulesDir . '/*.json');

        if (empty($scheduleFiles)) {
            return ['success' => true, 'message' => 'No schedules configured'];
        }

        $results = [];
        $currentTime = time();
        
        // Check each schedule
        foreach ($scheduleFiles as $file) {
            $scheduleId = basename($file, '.json');
            $schedule = json_decode(file_get_contents($file), true);
            if (!$schedule) {
                continue;
            }
            
            // Skip explicitly disabled schedules
            if (isset($schedule['enabled']) && $schedule['enabled'] === false) {
                continue;
            }

            // Get schedule configuration (support both naming conventions)
            $scheduleType = $schedule['schedule'] ?? $schedule['frequency'] ?? 'daily';
            $preferredHour = $schedule['preferred_time'] ?? $schedule['hour'] ?? 2;

            // Ensure next_run is calculated if missing
            if (empty($schedule['next_run'])) {
                $schedule['next_run'] = $this->queue->calculateNextRun($scheduleType, $preferredHour);
            }

            // Skip if not yet due
            if (strtotime($schedule['next_run']) > $currentTime) {
                continue;
            }

            // Get schedule parameters
            $accounts = $schedule['accounts'] ?? [];
            $destination = $schedule['destination'] ?? 'local';
            $user = $schedule['user'] ?? 'root';
            
            // === HANDLE "ALL ACCOUNTS" MODE ===
            // Dynamically resolve accounts at runtime based on schedule owner
            if (!empty($schedule['all_accounts']) || (is_array($accounts) && in_array('*', $accounts))) {
                // Resolve '*' to actual accounts for this user
                $acl = new BackBorkACL();
                $accountsEngine = new BackBorkWhmApiAccounts();
                $isScheduleOwnerRoot = ($user === 'root');
                
                // Get accounts accessible by the schedule owner
                $accessibleAccounts = $accountsEngine->getAccessibleAccounts($user, $isScheduleOwnerRoot);
                $accounts = array_column($accessibleAccounts, 'user');
                
                BackBorkConfig::debugLog('processSchedules: Resolved all_accounts for ' . $user . ' to ' . count($accounts) . ' accounts');
            }
            
            // Add schedule's backup job to the queue
            $options = ['schedule_id' => $scheduleId];
            $this->queue->addToQueue($accounts, $destination, 'once', $user, $options);

            // Update schedule metadata for next run
            $schedule['last_run'] = date('Y-m-d H:i:s', $currentTime);
            $schedule['last_status'] = 'queued';
            $schedule['next_run'] = $this->queue->calculateNextRun($scheduleType, $preferredHour);
            file_put_contents($file, json_encode($schedule, JSON_PRETTY_PRINT));
            
            $results[$scheduleId] = 'Queued';
        }
        
        return [
            'success' => true,
            'scheduled' => $results,
            'message' => count($results) > 0 ? count($results) . ' schedule(s) queued' : 'No schedules due'
        ];
    }
    
    /**
     * Check if a schedule should run at the current time
     * 
     * Legacy method for time-based schedule matching.
     * 
     * @param array $schedule Schedule configuration
     * @param int $hour Current hour (0-23)
     * @param int $day Current day of month (1-31)
     * @param int $weekday Current weekday (1-7, Mon=1)
     * @return bool True if schedule should run
     */
    private function shouldRunSchedule($schedule, $hour, $day, $weekday) {
        // Support both naming conventions
        $frequency = $schedule['schedule'] ?? $schedule['frequency'] ?? 'daily';
        $scheduleHour = $schedule['preferred_time'] ?? $schedule['hour'] ?? 2;
        
        // Hourly schedules run every hour
        if ($frequency === 'hourly') {
            return true;
        }
        
        // Non-hourly schedules must match the preferred hour
        if ($hour !== (int)$scheduleHour) {
            return false;
        }
        
        switch ($frequency) {
            case 'daily':
                return true;  // Daily runs every day at the hour
                
            case 'weekly':
                $scheduledDay = $schedule['day_of_week'] ?? 1;  // Default Monday
                return $weekday === (int)$scheduledDay;
                
            case 'monthly':
                $scheduledDate = $schedule['day_of_month'] ?? 1;  // Default 1st
                return $day === (int)$scheduledDate;
        }
        
        return false;
    }
    
    // ========================================================================
    // STATISTICS & MANAGEMENT
    // ========================================================================
    
    /**
     * Get queue statistics
     * 
     * Returns counts of jobs in each state.
     * 
     * @return array Statistics with counts by status
     */
    public function getStats() {
        $queueData = $this->queue->getQueue('root', true);
        
        $stats = [
            'total' => 0,
            'queued' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0
        ];
        
        // Count pending queued jobs
        $stats['queued'] = count($queueData['queued'] ?? []);
        
        // Count currently running jobs
        $stats['processing'] = count($queueData['running'] ?? []);
        
        // Count completed and failed jobs from completed directory
        $completedFiles = glob(BackBorkQueue::getCompletedDir() . '/*.json');
        foreach ($completedFiles as $file) {
            $job = json_decode(file_get_contents($file), true);
            if ($job) {
                if (isset($job['status']) && $job['status'] === 'failed') {
                    $stats['failed']++;
                } else {
                    $stats['completed']++;
                }
            }
        }
        
        // Total across all states
        $stats['total'] = $stats['queued'] + $stats['processing'] + $stats['completed'] + $stats['failed'];
        
        return $stats;
    }
    
    /**
     * Clear successfully completed items from completed directory
     * 
     * @return int Number of items cleared
     */
    public function clearCompleted() {
        $completedDir = BackBorkQueue::getCompletedDir();
        $cleared = 0;
        
        $files = glob($completedDir . '/*.json');
        foreach ($files as $file) {
            $job = json_decode(file_get_contents($file), true);
            // Only clear successfully completed jobs
            if ($job && isset($job['status']) && $job['status'] === 'completed') {
                unlink($file);
                $cleared++;
            }
        }
        
        return $cleared;
    }
    
    /**
     * Clear failed items from completed directory
     * 
     * @return int Number of items cleared
     */
    public function clearFailed() {
        $completedDir = BackBorkQueue::getCompletedDir();
        $cleared = 0;
        
        $files = glob($completedDir . '/*.json');
        foreach ($files as $file) {
            $job = json_decode(file_get_contents($file), true);
            // Only clear failed jobs
            if ($job && isset($job['status']) && $job['status'] === 'failed') {
                unlink($file);
                $cleared++;
            }
        }
        
        return $cleared;
    }
    
    /**
     * Retry failed items by moving back to queue
     * 
     * Resets failed jobs and moves them back to the queue for reprocessing.
     * 
     * @return int Number of items retried
     */
    public function retryFailed() {
        $completedDir = BackBorkQueue::getCompletedDir();
        $queueDir = BackBorkQueue::getQueueDir();
        $retried = 0;
        
        $files = glob($completedDir . '/*.json');
        foreach ($files as $file) {
            $job = json_decode(file_get_contents($file), true);
            if ($job && isset($job['status']) && $job['status'] === 'failed') {
                $jobId = $job['id'];
                
                // Reset job state for retry
                $job['status'] = 'queued';
                $job['retried_at'] = date('Y-m-d H:i:s');
                unset($job['error']);         // Remove error from previous attempt
                unset($job['completed_at']);  // Remove completion timestamp
                
                // Move back to queue
                file_put_contents($queueDir . '/' . $jobId . '.json', json_encode($job, JSON_PRETTY_PRINT));
                chmod($queueDir . '/' . $jobId . '.json', 0600);
                unlink($file);
                $retried++;
            }
        }
        
        return $retried;
    }
    
    // ========================================================================
    // LOCKING MECHANISMS
    // ========================================================================
    
    /**
     * Acquire exclusive processing lock
     * 
     * Prevents concurrent queue processing. Handles stale locks
     * (older than 1 hour) and orphaned locks (process no longer running).
     * 
     * @return bool True if lock acquired, false if already locked
     */
    private function acquireLock() {
        // Check if lock file exists
        if (file_exists(self::LOCK_FILE)) {
            $lockTime = filemtime(self::LOCK_FILE);
            $lockAge = time() - $lockTime;
            $pid = (int)file_get_contents(self::LOCK_FILE);
            
            BackBorkConfig::debugLog('acquireLock: Lock file exists, age=' . $lockAge . 's, pid=' . $pid);
            
            // Remove stale locks (older than 1 hour = likely hung process)
            if ($lockAge > 3600) {
                BackBorkConfig::debugLog('acquireLock: Removing stale lock (age > 1 hour)');
                unlink(self::LOCK_FILE);
            }
            // Check if the locking process is still running
            elseif ($pid > 0 && !$this->isProcessRunning($pid)) {
                BackBorkConfig::debugLog('acquireLock: Removing orphaned lock (pid ' . $pid . ' not running)');
                unlink(self::LOCK_FILE);
            }
            else {
                // Lock is valid - another process is running
                BackBorkConfig::debugLog('acquireLock: Lock is valid, cannot acquire');
                return false;
            }
        }
        
        // Create lock file with our PID
        $result = file_put_contents(self::LOCK_FILE, getmypid()) !== false;
        BackBorkConfig::debugLog('acquireLock: Created lock file, result=' . ($result ? 'success' : 'failed'));
        return $result;
    }
    
    /**
     * Check if a process is currently running
     * 
     * Uses multiple methods for compatibility across systems.
     * 
     * @param int $pid Process ID to check
     * @return bool True if process is running
     */
    private function isProcessRunning($pid) {
        if ($pid <= 0) {
            return false;
        }
        
        // Linux: Check /proc filesystem (fastest)
        if (file_exists('/proc/' . $pid)) {
            return true;
        }
        
        // POSIX: Use posix_kill with signal 0 (checks without killing)
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }
        
        // Fallback: Use shell ps command
        exec('ps -p ' . (int)$pid . ' > /dev/null 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }
    
    /**
     * Release the processing lock
     * 
     * Called after queue processing completes (success or error).
     */
    private function releaseLock() {
        if (file_exists(self::LOCK_FILE)) {
            unlink(self::LOCK_FILE);
        }
    }
    
    /**
     * Check if the queue processor is currently running
     * 
     * Public method for status checking. Also cleans up stale/orphaned locks.
     * 
     * @return bool True if processor is running
     */
    public function isRunning() {
        if (!file_exists(self::LOCK_FILE)) {
            BackBorkConfig::debugLog('isRunning: No lock file, not running');
            return false;
        }
        
        $lockTime = filemtime(self::LOCK_FILE);
        $lockAge = time() - $lockTime;
        $pid = (int)file_get_contents(self::LOCK_FILE);
        
        BackBorkConfig::debugLog('isRunning: Lock file exists, age=' . $lockAge . 's, pid=' . $pid);
        
        // Check for stale lock
        if ($lockAge > 3600) {
            BackBorkConfig::debugLog('isRunning: Lock is stale (age > 1 hour), cleaning up');
            unlink(self::LOCK_FILE);
            return false;
        }
        
        // Check if process is actually running
        if ($pid > 0 && $this->isProcessRunning($pid)) {
            BackBorkConfig::debugLog('isRunning: Process ' . $pid . ' is still running');
            return true;
        }
        
        // Process not running - clean up orphaned lock
        BackBorkConfig::debugLog('isRunning: Process ' . $pid . ' not running, cleaning up orphaned lock');
        unlink(self::LOCK_FILE);
        return false;
    }
    
    // ========================================================================
    // CLEANUP
    // ========================================================================
    
    /**
     * Cleanup completed jobs older than specified retention period
     * 
     * Removes old completed job records to prevent directory bloat.
     * 
     * @param int $days Number of days to keep completed jobs (default: 30)
     * @return int Number of jobs cleaned up
     */
    public function cleanupCompletedJobs($days = 30) {
        $completedDir = BackBorkQueue::getCompletedDir();
        $cleaned = 0;
        $cutoffTime = time() - ($days * 86400);  // Convert days to seconds
        
        $files = glob($completedDir . '/*.json');
        foreach ($files as $file) {
            $mtime = filemtime($file);
            // Delete if older than cutoff
            if ($mtime < $cutoffTime) {
                unlink($file);
                $cleaned++;
            }
        }
        
        return $cleaned;
    }
}
