<?php
/**
 * BackBork KISS - Cron Handler
 * Processes scheduled backups and queue items
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

// CLI only
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

// Load version constant
require_once(__DIR__ . '/../version.php');

// Load Bootstrap in CLI mode
require_once(__DIR__ . '/../app/Bootstrap.php');

// Initialize for CLI
BackBorkBootstrap::initCLI();

// Define paths
define('CRON_LAST_RUN_FILE', '/usr/local/cpanel/3rdparty/backbork/cron_last_run');
define('CRON_HEALTH_CHECK_INTERVAL', 1800); // 30 minutes in seconds

// Create processor instance
$processor = new BackBorkQueueProcessor();

// Log start
error_log('[BackBork] Cron handler started at ' . date('Y-m-d H:i:s'));
echo '[BackBork] Cron handler started at ' . date('Y-m-d H:i:s') . "\n";

// Update last run timestamp
file_put_contents(CRON_LAST_RUN_FILE, time());

// Check if already running
BackBorkConfig::debugLog('Checking if processor is running...');
if ($processor->isRunning()) {
    BackBorkConfig::debugLog('Queue processor already running, skipping');
    exit(0);
}
BackBorkConfig::debugLog('Processor not running, continuing...');

// Handle cleanup command
if (isset($argv[1]) && $argv[1] === 'cleanup') {
    runCleanup($processor);
    exit(0);
}

// Perform cron health self-check and notify if issues
performHealthCheck();

// Process schedules first (adds to queue)
$scheduleResults = $processor->processSchedules();
if (!empty($scheduleResults['scheduled'])) {
    error_log('[BackBork] Scheduled items queued: ' . count($scheduleResults['scheduled']));
}

// Process queue
$queueResults = $processor->processQueue();
error_log('[BackBork] Queue processing complete: ' . $queueResults['message']);

// Log via BackBorkLog if available - include actual accounts processed
if (class_exists('BackBorkLog')) {
    $processed = $queueResults['processed'] ?? 0;
    $failed = $queueResults['failed'] ?? 0;
    $accounts = $queueResults['accounts'] ?? [];
    $logMessage = $queueResults['message'];
    
    // Only log if something happened or there was an error
    if ($processed > 0 || $failed > 0 || !$queueResults['success']) {
        BackBorkLog::logEvent('root', 'queue_cron_process', $accounts, $queueResults['success'], $logMessage, 'cron');
    }
}

// Get stats
$stats = $processor->getStats();
error_log('[BackBork] Queue stats - Total: ' . $stats['total'] . 
          ', Queued: ' . $stats['queued'] . 
          ', Failed: ' . $stats['failed']);

// Cleanup old temp files (older than 24 hours)
$retrieval = new BackBorkRetrieval();
$cleaned = $retrieval->cleanupTempFiles(24);
if ($cleaned > 0) {
    error_log('[BackBork] Cleaned up ' . $cleaned . ' old temp files');
}

error_log('[BackBork] Cron handler finished at ' . date('Y-m-d H:i:s'));
echo '[BackBork] Cron handler finished at ' . date('Y-m-d H:i:s') . "\n";

/**
 * Run cleanup tasks
 */
function runCleanup($processor) {
    error_log('[BackBork] Running cleanup tasks...');
    
    // Clean completed jobs older than 30 days
    $processor->cleanupCompletedJobs(30);
    
    // Rotate logs
    $logDir = '/usr/local/cpanel/3rdparty/backbork/logs';
    $maxLogAge = 30 * 24 * 60 * 60; // 30 days
    
    foreach (glob($logDir . '/*.log') as $logFile) {
        if (filemtime($logFile) < (time() - $maxLogAge)) {
            unlink($logFile);
            error_log('[BackBork] Removed old log: ' . basename($logFile));
        }
    }
    
    // Clean orphaned temp files
    $retrieval = new BackBorkRetrieval();
    $cleaned = $retrieval->cleanupTempFiles(24);
    error_log('[BackBork] Cleanup complete. Removed ' . $cleaned . ' temp files.');
}

/**
 * Perform cron health self-check
 * Sends notification if cron hasn't run in expected interval
 */
function performHealthCheck() {
    $healthFile = '/usr/local/cpanel/3rdparty/backbork/cron_health_notified';
    
    // Check if cron file exists
    if (!file_exists('/etc/cron.d/backbork')) {
        sendHealthAlert('Cron file /etc/cron.d/backbork is missing', $healthFile);
        return;
    }
    
    // Check last run time (for detecting stuck cron)
    if (file_exists(CRON_LAST_RUN_FILE)) {
        $lastRun = (int)file_get_contents(CRON_LAST_RUN_FILE);
        $timeSinceLastRun = time() - $lastRun;
        
        // If longer than our expected interval, something may be wrong
        if ($timeSinceLastRun > CRON_HEALTH_CHECK_INTERVAL) {
            // Friendly, human-readable delta
            if ($timeSinceLastRun < 3600) {
                $delta = round($timeSinceLastRun / 60) . ' minutes';
            } else {
                $delta = round($timeSinceLastRun / 3600, 1) . ' hours';
            }
            sendHealthAlert(
                'Cron has not run in over ' . $delta,
                $healthFile,
                $lastRun
            );
            return;
        }
    }
    
    // Clear any previous health notification flag since we're healthy now
    if (file_exists($healthFile)) {
        unlink($healthFile);
    }
}

/**
 * Send health alert via email and Slack
 */
function sendHealthAlert($issue, $healthFile, $lastRun = null) {
    // Don't spam - only send once per issue
    if (file_exists($healthFile)) {
        $lastNotified = (int)file_get_contents($healthFile);
        // Don't re-notify within 24 hours
        if ((time() - $lastNotified) < 86400) {
            return;
        }
    }
    
    error_log('[BackBork] HEALTH ALERT: ' . $issue);
    
    // Get root config for notification settings
    $config = new BackBorkConfig();
    $rootConfig = $config->getUserConfig('root');
    
    $hostname = gethostname() ?: 'unknown';
    $timestamp = date('Y-m-d H:i:s T');
    $lastRunStr = $lastRun ? date('Y-m-d H:i:s T', $lastRun) : 'Never';
    
    // Send email if configured
    if (!empty($rootConfig['notify_email'])) {
        $subject = "⚠️ [BackBork] Cron Health Check Failed on {$hostname}";
        $body = "BackBork Cron Health Alert\n";
        $body .= "══════════════════════════\n\n";
        $body .= "Server: {$hostname}\n";
        $body .= "Time: {$timestamp}\n";
        $body .= "Status: FAILED\n\n";
        $body .= "Issue: {$issue}\n\n";
        $body .= "Last successful run: {$lastRunStr}\n";
        $body .= "Expected interval: 5 minutes\n\n";
        $body .= "Action Required:\n";
        $body .= "1. Check if crond service is running\n";
        $body .= "2. Verify /etc/cron.d/backbork exists\n";
        $body .= "3. Check cron logs: /var/log/cron\n\n";
        $body .= "══════════════════════════\n";
        $body .= "This is an automated alert from BackBork KISS\n";
        
        mail($rootConfig['notify_email'], $subject, $body, "From: backbork@{$hostname}");
        error_log('[BackBork] Health alert email sent to ' . $rootConfig['notify_email']);
    }
    
    // Send Slack if configured
    if (!empty($rootConfig['slack_webhook'])) {
        $slackPayload = [
            'text' => "⚠️ BackBork Cron Health Check Failed on {$hostname}",
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => '⚠️ Cron Health Alert',
                        'emoji' => true
                    ]
                ],
                [
                    'type' => 'section',
                    'fields' => [
                        ['type' => 'mrkdwn', 'text' => "*Server:*\n{$hostname}"],
                        ['type' => 'mrkdwn', 'text' => "*Status:*\n🔴 FAILED"],
                        ['type' => 'mrkdwn', 'text' => "*Issue:*\n{$issue}"],
                        ['type' => 'mrkdwn', 'text' => "*Last Run:*\n{$lastRunStr}"]
                    ]
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "*Action Required:*\n1. Check crond service\n2. Verify /etc/cron.d/backbork\n3. Check /var/log/cron"
                    ]
                ]
            ]
        ];
        
        $ch = curl_init($rootConfig['slack_webhook']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($slackPayload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10
        ]);
        curl_exec($ch);
        curl_close($ch);
        error_log('[BackBork] Health alert sent to Slack');
    }
    
    // Mark that we've notified
    file_put_contents($healthFile, time());
}
