<?php
/**
 * BackBork KISS - Queue Manager
 * Manages backup/restore job queue and scheduling (data layer)
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

class BackBorkQueue {
    
    const QUEUE_DIR = '/usr/local/cpanel/3rdparty/backbork/queue';
    const SCHEDULES_DIR = '/usr/local/cpanel/3rdparty/backbork/schedules';
    const RUNNING_DIR = '/usr/local/cpanel/3rdparty/backbork/running';
    const RESTORES_DIR = '/usr/local/cpanel/3rdparty/backbork/restores';
    const COMPLETED_DIR = '/usr/local/cpanel/3rdparty/backbork/completed';
    const LOCK_FILE = '/usr/local/cpanel/3rdparty/backbork/queue.lock';
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->ensureDirectories();
    }
    
    /**
     * Ensure required directories exist
     */
    private function ensureDirectories() {
        $dirs = [self::QUEUE_DIR, self::SCHEDULES_DIR, self::RUNNING_DIR, self::RESTORES_DIR, self::COMPLETED_DIR];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
        }
    }
    
    /**
     * Add jobs to queue
     * 
     * @param array $accounts Account list
     * @param string $destinationId Destination ID
     * @param string $schedule Schedule type (once, hourly, daily, weekly, monthly)
     * @param string $user User creating the job
     * @param array $options Additional options
     * @return array Result
     */
    public function addToQueue($accounts, $destinationId, $schedule = 'once', $user = 'root', $options = []) {
        $jobId = $this->generateJobId();
        
        $job = [
            'id' => $jobId,
            'type' => 'backup',
            'accounts' => $accounts,
            'destination' => $destinationId,
            'schedule' => $schedule,
            'user' => $user,
            'created_at' => date('Y-m-d H:i:s'),
            'status' => 'queued',
            'retention' => isset($options['retention']) ? (int)$options['retention'] : 30,
            'preferred_time' => isset($options['preferred_time']) ? (int)$options['preferred_time'] : 2
        ];
        
        if ($schedule === 'once') {
            // Add to immediate queue
            $queueFile = self::QUEUE_DIR . '/' . $jobId . '.json';
            file_put_contents($queueFile, json_encode($job, JSON_PRETTY_PRINT));
            chmod($queueFile, 0600);
            
            // Log queue addition
            if (class_exists('BackBorkLog')) {
                $requestor = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : (BackBorkBootstrap::isCLI() ? 'cron' : 'local'));
                BackBorkLog::logEvent($user, 'queue_add', $accounts, true, 'Job added to queue', $requestor);
            }

            return [
                'success' => true,
                'message' => 'Job added to queue',
                'job_id' => $jobId
            ];
        } else {
            // Add to schedules
            $job['next_run'] = $this->calculateNextRun($schedule, $job['preferred_time']);
            $scheduleFile = self::SCHEDULES_DIR . '/' . $jobId . '.json';
            file_put_contents($scheduleFile, json_encode($job, JSON_PRETTY_PRINT));
            chmod($scheduleFile, 0600);
            
            // Log schedule creation
            if (class_exists('BackBorkLog')) {
                $requestor = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : (BackBorkBootstrap::isCLI() ? 'cron' : 'local'));
                BackBorkLog::logEvent($user, 'schedule_create', $accounts, true, 'Schedule created', $requestor);
            }

            return [
                'success' => true,
                'message' => 'Schedule created',
                'job_id' => $jobId
            ];
        }
    }
    
    /**
     * Calculate next run time for a schedule
     * 
     * @param string $schedule Schedule type
     * @param int $preferredHour Preferred hour (0-23)
     * @return string DateTime string
     */
    public function calculateNextRun($schedule, $preferredHour = 2) {
        $now = new DateTime();
        $next = new DateTime();
        $next->setTime($preferredHour, 0, 0);
        
        switch ($schedule) {
            case 'hourly':
                $next = new DateTime();
                $next->modify('+1 hour');
                $next->setTime((int)$next->format('H'), 0, 0);
                break;
                
            case 'daily':
                if ($now >= $next) {
                    $next->modify('+1 day');
                }
                break;
                
            case 'weekly':
                $next->modify('next sunday');
                $next->setTime($preferredHour, 0, 0);
                break;
                
            case 'monthly':
                $next->modify('first day of next month');
                $next->setTime($preferredHour, 0, 0);
                break;
        }
        
        return $next->format('Y-m-d H:i:s');
    }
    
    /**
     * Get queue and schedule information
     * 
     * @param string $user User requesting
     * @param bool $isRoot Whether user is root
     * @return array
     */
    public function getQueue($user, $isRoot) {
        $result = [
            'queued' => [],
            'running' => [],
            'schedules' => [],
            'restores' => []
        ];
        
        // Get queued jobs
        $queueFiles = glob(self::QUEUE_DIR . '/*.json');
        foreach ($queueFiles as $file) {
            $job = json_decode(file_get_contents($file), true);
            if ($job && ($isRoot || $job['user'] === $user)) {
                $result['queued'][] = $job;
            }
        }
        
        // Get running jobs
        $runningFiles = glob(self::RUNNING_DIR . '/*.json');
        foreach ($runningFiles as $file) {
            $job = json_decode(file_get_contents($file), true);
            if ($job && ($isRoot || $job['user'] === $user)) {
                $result['running'][] = $job;
            }
        }
        
        // Get schedules
        $scheduleFiles = glob(self::SCHEDULES_DIR . '/*.json');
        foreach ($scheduleFiles as $file) {
            $schedule = json_decode(file_get_contents($file), true);
            if ($schedule && ($isRoot || $schedule['user'] === $user)) {
                $result['schedules'][] = $schedule;
            }
        }
        
        // Get active restores
        $restoreFiles = glob(self::RESTORES_DIR . '/*.json');
        foreach ($restoreFiles as $file) {
            $restore = json_decode(file_get_contents($file), true);
            if ($restore && ($isRoot || $restore['user'] === $user)) {
                $result['restores'][] = $restore;
            }
        }
        
        // Sort by created date
        usort($result['queued'], function($a, $b) {
            return strtotime($a['created_at']) - strtotime($b['created_at']);
        });
        
        return $result;
    }
    
    /**
     * Remove job from queue or delete schedule
     * 
     * @param string $jobId Job ID
     * @param string $user User requesting removal
     * @param bool $isRoot Whether user is root
     * @return array Result
     */
    public function removeFromQueue($jobId, $user, $isRoot) {
        // Check queue
        $queueFile = self::QUEUE_DIR . '/' . $jobId . '.json';
        if (file_exists($queueFile)) {
            $job = json_decode(file_get_contents($queueFile), true);
            if (!$isRoot && $job['user'] !== $user) {
                return ['success' => false, 'message' => 'Access denied'];
            }
            unlink($queueFile);
            if (class_exists('BackBorkLog')) {
                $requestor = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : (BackBorkBootstrap::isCLI() ? 'cron' : 'local'));
                BackBorkLog::logEvent($user, 'queue_remove', [$jobId], true, 'Job removed from queue', $requestor);
            }
            return ['success' => true, 'message' => 'Job removed from queue'];
        }
        
        // Check schedules
        $scheduleFile = self::SCHEDULES_DIR . '/' . $jobId . '.json';
        if (file_exists($scheduleFile)) {
            $schedule = json_decode(file_get_contents($scheduleFile), true);
            if (!$isRoot && $schedule['user'] !== $user) {
                return ['success' => false, 'message' => 'Access denied'];
            }
            unlink($scheduleFile);
            if (class_exists('BackBorkLog')) {
                $requestor = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : (BackBorkBootstrap::isCLI() ? 'cron' : 'local'));
                BackBorkLog::logEvent($user, 'schedule_delete', [$jobId], true, 'Schedule removed', $requestor);
            }
            return ['success' => true, 'message' => 'Schedule removed'];
        }
        
        return ['success' => false, 'message' => 'Job not found'];
    }
    
    /**
     * Get running jobs
     * 
     * @param string $user User
     * @param bool $isRoot Is root user
     * @return array
     */
    public function getRunningJobs($user, $isRoot) {
        $running = [];
        
        $files = glob(self::RUNNING_DIR . '/*.json');
        foreach ($files as $file) {
            $job = json_decode(file_get_contents($file), true);
            if ($job && ($isRoot || $job['user'] === $user)) {
                $running[] = $job;
            }
        }
        
        return ['running' => $running];
    }
    
    /**
     * Get a job by ID from any location
     * 
     * @param string $jobId Job ID
     * @return array|null
     */
    public function getJob($jobId) {
        $locations = [
            self::QUEUE_DIR,
            self::RUNNING_DIR,
            self::SCHEDULES_DIR,
            self::COMPLETED_DIR
        ];
        
        foreach ($locations as $dir) {
            $file = $dir . '/' . $jobId . '.json';
            if (file_exists($file)) {
                return json_decode(file_get_contents($file), true);
            }
        }
        
        return null;
    }
    
    /**
     * Update a job's data
     * 
     * @param string $jobId Job ID
     * @param array $data Data to merge
     * @param string $location Directory location
     * @return bool
     */
    public function updateJob($jobId, $data, $location = null) {
        if (!$location) {
            $locations = [self::QUEUE_DIR, self::RUNNING_DIR, self::SCHEDULES_DIR];
            foreach ($locations as $dir) {
                if (file_exists($dir . '/' . $jobId . '.json')) {
                    $location = $dir;
                    break;
                }
            }
        }
        
        if (!$location) {
            return false;
        }
        
        $file = $location . '/' . $jobId . '.json';
        if (!file_exists($file)) {
            return false;
        }
        
        $job = json_decode(file_get_contents($file), true);
        $job = array_merge($job, $data);
        
        return file_put_contents($file, json_encode($job, JSON_PRETTY_PRINT)) !== false;
    }
    
    /**
     * Move job between directories
     * 
     * @param string $jobId Job ID
     * @param string $from Source directory
     * @param string $to Destination directory
     * @param array $additionalData Additional data to add
     * @return bool
     */
    public function moveJob($jobId, $from, $to, $additionalData = []) {
        $sourceFile = $from . '/' . $jobId . '.json';
        if (!file_exists($sourceFile)) {
            return false;
        }
        
        $job = json_decode(file_get_contents($sourceFile), true);
        $job = array_merge($job, $additionalData);
        
        $destFile = $to . '/' . $jobId . '.json';
        if (file_put_contents($destFile, json_encode($job, JSON_PRETTY_PRINT)) === false) {
            return false;
        }
        chmod($destFile, 0600);
        
        unlink($sourceFile);
        return true;
    }
    
    /**
     * Generate unique job ID
     * 
     * @return string
     */
    public function generateJobId() {
        return 'bb_' . date('Ymd_His') . '_' . substr(md5(uniqid(mt_rand(), true)), 0, 8);
    }
    
    /**
     * Acquire queue processing lock
     * 
     * @return resource|false Lock file handle or false
     */
    public function acquireLock() {
        $lock = fopen(self::LOCK_FILE, 'w');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return false;
        }
        return $lock;
    }
    
    /**
     * Release queue processing lock
     * 
     * @param resource $lock Lock file handle
     */
    public function releaseLock($lock) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    
    /**
     * Get schedules directory
     * 
     * @return string
     */
    public static function getSchedulesDir() {
        return self::SCHEDULES_DIR;
    }
    
    /**
     * Get queue directory
     * 
     * @return string
     */
    public static function getQueueDir() {
        return self::QUEUE_DIR;
    }
    
    /**
     * Get running directory
     * 
     * @return string
     */
    public static function getRunningDir() {
        return self::RUNNING_DIR;
    }
    
    /**
     * Get completed directory
     * 
     * @return string
     */
    public static function getCompletedDir() {
        return self::COMPLETED_DIR;
    }
}
