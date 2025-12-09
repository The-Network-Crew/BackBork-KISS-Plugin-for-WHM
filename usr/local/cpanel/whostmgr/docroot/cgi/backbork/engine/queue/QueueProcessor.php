<?php
/**
 * BackBork KISS - Queue Processor
 * Processes backup queue items (used by cron)
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
    
    const LOCK_FILE = '/tmp/backbork_queue.lock';
    const MAX_CONCURRENT = 1; // One backup at a time to prevent overload
    
    /** @var BackBorkQueue */
    private $queue;
    
    /** @var BackBorkBackupManager */
    private $backupManager;
    
    /** @var BackBorkConfig */
    private $config;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->queue = new BackBorkQueue();
        $this->backupManager = new BackBorkBackupManager();
        $this->config = new BackBorkConfig();
    }
    
    /**
     * Process all pending queue items
     * 
     * @return array Processing results
     */
    public function processQueue() {
        // Acquire lock to prevent concurrent processing
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
            
            // Get pending items - use root access for processing (cron runs as root)
            $queueData = $this->queue->getQueue('root', true);
            $queuedJobs = isset($queueData['queued']) ? $queueData['queued'] : [];
            
            BackBorkConfig::debugLog('processQueue: Found ' . count($queuedJobs) . ' queued jobs');
            
            if (empty($queuedJobs)) {
                $this->releaseLock();
                return [
                    'success' => true,
                    'message' => 'No items in queue',
                    'processed' => 0,
                    'accounts' => []
                ];
            }
            
            foreach ($queuedJobs as $item) {
                $id = $item['id'];
                $itemAccounts = $item['accounts'] ?? [];
                $itemStatus = $item['status'] ?? 'MISSING';
                
                BackBorkConfig::debugLog('processQueue: Processing job ' . $id . ' with status: ' . $itemStatus);
                
                // Skip non-queued items (status is 'queued' when created)
                if (!isset($item['status']) || $item['status'] !== 'queued') {
                    BackBorkConfig::debugLog('processQueue: Skipping job ' . $id . ' - status not queued');
                    continue;
                }
                
                BackBorkConfig::debugLog('processQueue: Moving job ' . $id . ' to running');
                
                // Move to running directory and mark as processing
                $moveResult = $this->queue->moveJob($id, BackBorkQueue::getQueueDir(), BackBorkQueue::getRunningDir(), [
                    'status' => 'processing',
                    'started_at' => date('Y-m-d H:i:s')
                ]);
                
                if (!$moveResult) {
                    BackBorkConfig::debugLog('processQueue: Failed to move job ' . $id . ' to running');
                    continue;
                }
                
                BackBorkConfig::debugLog('processQueue: Executing job ' . $id);
                
                // Process the item
                $result = $this->processItem($id, $item);
                $results[$id] = $result;
                
                BackBorkConfig::debugLog('processQueue: Job ' . $id . ' result: ' . ($result['success'] ? 'success' : 'failed') . ' - ' . ($result['message'] ?? 'no message'));
                
                if ($result['success']) {
                    // Move to completed
                    $this->queue->moveJob($id, BackBorkQueue::getRunningDir(), BackBorkQueue::getCompletedDir(), [
                        'status' => 'completed',
                        'completed_at' => date('Y-m-d H:i:s'),
                        'result' => $result['message'] ?? 'Success'
                    ]);
                    $processed++;
                    $processedAccounts = array_merge($processedAccounts, $itemAccounts);
                } else {
                    // Move to completed with failed status (or leave in running for retry)
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
            
            // Build summary of accounts
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
            $this->releaseLock();
            return [
                'success' => false,
                'message' => 'Queue processing error: ' . $e->getMessage(),
                'processed' => 0
            ];
        }
    }
    
    /**
     * Process a single queue item
     * 
     * @param string $id Queue item ID
     * @param array $item Queue item data
     * @return array Result
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
     * @param array $item Queue item data
     * @return array Result
     */
    private function processBackupItem($item) {
        $accounts = $item['accounts'] ?? [];
        $destination = $item['destination'] ?? 'local';
        $user = $item['user'] ?? 'root';
        
        if (empty($accounts)) {
            return ['success' => false, 'message' => 'No accounts specified'];
        }
        
        return $this->backupManager->createBackup($accounts, $destination, $user);
    }
    
    /**
     * Process a restore queue item
     * 
     * @param array $item Queue item data
     * @return array Result
     */
    private function processRestoreItem($item) {
        $backupFile = $item['backup_file'] ?? '';
        $destination = $item['destination'] ?? 'local';
        $options = $item['options'] ?? [];
        $user = $item['user'] ?? 'root';
        
        if (empty($backupFile)) {
            return ['success' => false, 'message' => 'No backup file specified'];
        }
        
        $restoreManager = new BackBorkRestoreManager();
        return $restoreManager->restoreAccount($backupFile, $destination, $options, $user);
    }
    
    /**
     * Process scheduled backups
     * 
     * @return array Results
     */
    public function processSchedules() {
        $schedulesDir = BackBorkQueue::SCHEDULES_DIR;
        $scheduleFiles = glob($schedulesDir . '/*.json');

        if (empty($scheduleFiles)) {
            return ['success' => true, 'message' => 'No schedules configured'];
        }

        $results = [];
        $currentTime = time();
        foreach ($scheduleFiles as $file) {
            $scheduleId = basename($file, '.json');
            $schedule = json_decode(file_get_contents($file), true);
            if (!$schedule) {
                continue;
            }
            
            // Skip if explicitly disabled
            if (isset($schedule['enabled']) && $schedule['enabled'] === false) {
                continue;
            }

            // Get schedule type and preferred hour (using correct field names from Queue.php)
            $scheduleType = $schedule['schedule'] ?? $schedule['frequency'] ?? 'daily';
            $preferredHour = $schedule['preferred_time'] ?? $schedule['hour'] ?? 2;

            // Ensure next_run is set; if not, calculate it
            if (empty($schedule['next_run'])) {
                $schedule['next_run'] = $this->queue->calculateNextRun($scheduleType, $preferredHour);
            }

            // Skip until next_run is reached
            if (strtotime($schedule['next_run']) > $currentTime) {
                continue;
            }

            // Add to queue
            $accounts = $schedule['accounts'] ?? [];
            $destination = $schedule['destination'] ?? 'local';
            $user = $schedule['user'] ?? 'root';
            $options = ['schedule_id' => $scheduleId];
            $this->queue->addToQueue($accounts, $destination, 'once', $user, $options);

            // Update schedule metadata: last_run, last_status, next_run
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
     * Check if a schedule should run at current time
     * 
     * @param array $schedule Schedule configuration
     * @param int $hour Current hour (0-23)
     * @param int $day Current day of month (1-31)
     * @param int $weekday Current weekday (1-7)
     * @return bool
     */
    private function shouldRunSchedule($schedule, $hour, $day, $weekday) {
        // Support both field naming conventions (schedule/frequency, preferred_time/hour)
        $frequency = $schedule['schedule'] ?? $schedule['frequency'] ?? 'daily';
        $scheduleHour = $schedule['preferred_time'] ?? $schedule['hour'] ?? 2; // Default 2 AM
        
        // For hourly schedules, hour doesn't matter
        if ($frequency === 'hourly') {
            return true;
        }
        
        // Check hour matches for non-hourly schedules
        if ($hour !== (int)$scheduleHour) {
            return false;
        }
        
        switch ($frequency) {
            case 'daily':
                return true;
                
            case 'weekly':
                $scheduledDay = $schedule['day_of_week'] ?? 1; // Default Monday
                return $weekday === (int)$scheduledDay;
                
            case 'monthly':
                $scheduledDate = $schedule['day_of_month'] ?? 1;
                return $day === (int)$scheduledDate;
        }
        
        return false;
    }
    
    /**
     * Get queue statistics
     * 
     * @return array
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
        
        // Count queued jobs
        $stats['queued'] = count($queueData['queued'] ?? []);
        
        // Count running jobs
        $stats['processing'] = count($queueData['running'] ?? []);
        
        // Count completed jobs (from completed directory)
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
        
        $stats['total'] = $stats['queued'] + $stats['processing'] + $stats['completed'] + $stats['failed'];
        
        return $stats;
    }
    
    /**
     * Clear completed items from completed directory
     * 
     * @return int Number of items cleared
     */
    public function clearCompleted() {
        $completedDir = BackBorkQueue::getCompletedDir();
        $cleared = 0;
        
        $files = glob($completedDir . '/*.json');
        foreach ($files as $file) {
            $job = json_decode(file_get_contents($file), true);
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
                // Reset status and move back to queue
                $job['status'] = 'queued';
                $job['retried_at'] = date('Y-m-d H:i:s');
                unset($job['error']);
                unset($job['completed_at']);
                
                file_put_contents($queueDir . '/' . $jobId . '.json', json_encode($job, JSON_PRETTY_PRINT));
                chmod($queueDir . '/' . $jobId . '.json', 0600);
                unlink($file);
                $retried++;
            }
        }
        
        return $retried;
    }
    
    /**
     * Acquire processing lock
     * 
     * @return bool
     */
    private function acquireLock() {
        // Check if lock file exists
        if (file_exists(self::LOCK_FILE)) {
            $lockTime = filemtime(self::LOCK_FILE);
            $lockAge = time() - $lockTime;
            $pid = (int)file_get_contents(self::LOCK_FILE);
            
            BackBorkConfig::debugLog('acquireLock: Lock file exists, age=' . $lockAge . 's, pid=' . $pid);
            
            // Check if lock is stale (older than 1 hour)
            if ($lockAge > 3600) {
                BackBorkConfig::debugLog('acquireLock: Removing stale lock (age > 1 hour)');
                unlink(self::LOCK_FILE);
            }
            // Check if the process that created the lock is still running
            elseif ($pid > 0 && !$this->isProcessRunning($pid)) {
                BackBorkConfig::debugLog('acquireLock: Removing orphaned lock (pid ' . $pid . ' not running)');
                unlink(self::LOCK_FILE);
            }
            else {
                BackBorkConfig::debugLog('acquireLock: Lock is valid, cannot acquire');
                return false;
            }
        }
        
        // Create lock
        $result = file_put_contents(self::LOCK_FILE, getmypid()) !== false;
        BackBorkConfig::debugLog('acquireLock: Created lock file, result=' . ($result ? 'success' : 'failed'));
        return $result;
    }
    
    /**
     * Check if a process is running
     * 
     * @param int $pid Process ID
     * @return bool
     */
    private function isProcessRunning($pid) {
        if ($pid <= 0) {
            return false;
        }
        
        // Linux: check /proc filesystem
        if (file_exists('/proc/' . $pid)) {
            return true;
        }
        
        // Fallback: use posix_kill with signal 0 (doesn't actually kill, just checks)
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }
        
        // Last resort: use shell command
        exec('ps -p ' . (int)$pid . ' > /dev/null 2>&1', $output, $returnCode);
        return $returnCode === 0;
    }
    
    /**
     * Release processing lock
     */
    private function releaseLock() {
        if (file_exists(self::LOCK_FILE)) {
            unlink(self::LOCK_FILE);
        }
    }
    
    /**
     * Check if processor is currently running
     * 
     * @return bool
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
        
        // Check if lock is stale (older than 1 hour)
        if ($lockAge > 3600) {
            BackBorkConfig::debugLog('isRunning: Lock is stale (age > 1 hour), cleaning up');
            unlink(self::LOCK_FILE);
            return false;
        }
        
        // Check if the process is still running
        if ($pid > 0 && $this->isProcessRunning($pid)) {
            BackBorkConfig::debugLog('isRunning: Process ' . $pid . ' is still running');
            return true;
        }
        
        // Process is not running, clean up the orphaned lock
        BackBorkConfig::debugLog('isRunning: Process ' . $pid . ' not running, cleaning up orphaned lock');
        unlink(self::LOCK_FILE);
        return false;
    }
    
    /**
     * Cleanup completed jobs older than specified days
     * 
     * @param int $days Number of days to keep completed jobs
     * @return int Number of jobs cleaned up
     */
    public function cleanupCompletedJobs($days = 30) {
        $completedDir = BackBorkQueue::getCompletedDir();
        $cleaned = 0;
        $cutoffTime = time() - ($days * 86400);
        
        $files = glob($completedDir . '/*.json');
        foreach ($files as $file) {
            $mtime = filemtime($file);
            if ($mtime < $cutoffTime) {
                unlink($file);
                $cleaned++;
            }
        }
        
        return $cleaned;
    }
}
