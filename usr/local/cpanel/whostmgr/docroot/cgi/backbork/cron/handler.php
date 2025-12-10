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

// ============================================================================
// CLI ONLY - This script must only be run from command line via cron
// ============================================================================
if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

// Load version constant for logging
require_once(__DIR__ . '/../version.php');

// Load Bootstrap in CLI mode (initializes all classes and dependencies)
require_once(__DIR__ . '/../app/Bootstrap.php');

// Initialize application for CLI environment (no session handling)
BackBorkBootstrap::initCLI();

// ============================================================================
// CONSTANTS
// ============================================================================

// File to track last successful cron execution time
define('CRON_LAST_RUN_FILE', '/usr/local/cpanel/3rdparty/backbork/cron_last_run');

// Health check interval (30 minutes) - alert if cron hasn't run in this time
define('CRON_HEALTH_CHECK_INTERVAL', 1800);

// ============================================================================
// MAIN EXECUTION
// ============================================================================

// Create queue processor instance for handling backup jobs
$processor = new BackBorkQueueProcessor();

// Log start of cron run
error_log('[BackBork] Cron handler started at ' . date('Y-m-d H:i:s'));
echo '[BackBork] Cron handler started at ' . date('Y-m-d H:i:s') . "\n";

// Update last run timestamp (for health check monitoring)
file_put_contents(CRON_LAST_RUN_FILE, time());

// ============================================================================
// SPECIAL COMMANDS - Handle these BEFORE the lock check (they don't need it)
// ============================================================================

// Handle special 'cleanup' command for maintenance tasks
if (isset($argv[1]) && $argv[1] === 'cleanup') {
    runCleanup($processor);
    exit(0);
}

// Handle special 'summary' command for daily summary notifications
if (isset($argv[1]) && $argv[1] === 'summary') {
    sendDailySummary();
    exit(0);
}

// ============================================================================
// QUEUE PROCESSING - Requires exclusive lock to prevent concurrent execution
// ============================================================================

// Prevent concurrent execution - skip if already running
BackBorkConfig::debugLog('Checking if processor is running...');
if ($processor->isRunning()) {
    BackBorkConfig::debugLog('Queue processor already running, skipping');
    exit(0);
}
BackBorkConfig::debugLog('Processor not running, continuing...');

// Check cron health and send alerts if issues detected
performHealthCheck();

// ============================================================================
// SCHEDULE PROCESSING - Check for due scheduled backups and queue them
// ============================================================================
$scheduleResults = $processor->processSchedules();
if (!empty($scheduleResults['scheduled'])) {
    error_log('[BackBork] Scheduled items queued: ' . count($scheduleResults['scheduled']));
}

// ============================================================================
// QUEUE PROCESSING - Execute queued backup/restore jobs
// ============================================================================
$queueResults = $processor->processQueue();
error_log('[BackBork] Queue processing complete: ' . $queueResults['message']);

// Log significant events via BackBorkLog for history tracking
if (class_exists('BackBorkLog')) {
    $processed = $queueResults['processed'] ?? 0;
    $failed = $queueResults['failed'] ?? 0;
    $accounts = $queueResults['accounts'] ?? [];
    $logMessage = $queueResults['message'];
    
    // Only log if something was processed or there were errors
    if ($processed > 0 || $failed > 0 || !$queueResults['success']) {
        BackBorkLog::logEvent('root', 'queue_cron_process', $accounts, $queueResults['success'], $logMessage, 'cron');
    }
}

// Log current queue statistics
$stats = $processor->getStats();
error_log('[BackBork] Queue stats - Total: ' . $stats['total'] . 
          ', Queued: ' . $stats['queued'] . 
          ', Failed: ' . $stats['failed']);

// ============================================================================
// RETENTION PRUNING - Delete backups older than schedule retention settings
// ============================================================================
// Runs hourly to honour retention policies even with frequent schedules
$pruneResults = $processor->pruneOldBackups();
if ($pruneResults['pruned'] > 0) {
    error_log('[BackBork] Retention pruning completed: ' . $pruneResults['message']);
}

// ============================================================================
// CLEANUP - Remove old temporary files from retrieval operations
// ============================================================================
$retrieval = new BackBorkRetrieval();
$cleaned = $retrieval->cleanupTempFiles(24);  // Delete files older than 24 hours
if ($cleaned > 0) {
    error_log('[BackBork] Cleaned up ' . $cleaned . ' old temp files');
}

// Log completion
error_log('[BackBork] Cron handler finished at ' . date('Y-m-d H:i:s'));
echo '[BackBork] Cron handler finished at ' . date('Y-m-d H:i:s') . "\n";

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Run maintenance cleanup tasks.
 * Called with 'cleanup' argument: php handler.php cleanup
 * 
 * @param BackBorkQueueProcessor $processor Queue processor instance
 */
function runCleanup($processor) {
    error_log('[BackBork] Running cleanup tasks...');
    
    // Remove completed queue jobs older than 30 days
    $processor->cleanupCompletedJobs(30);
    
    // Rotate old log files (delete logs older than 30 days)
    $logDir = '/usr/local/cpanel/3rdparty/backbork/logs';
    $maxLogAge = 30 * 24 * 60 * 60; // 30 days in seconds
    
    foreach (glob($logDir . '/*.log') as $logFile) {
        if (filemtime($logFile) < (time() - $maxLogAge)) {
            unlink($logFile);
            error_log('[BackBork] Removed old log: ' . basename($logFile));
        }
    }
    
    // Clean orphaned temp files from failed/interrupted operations
    $retrieval = new BackBorkRetrieval();
    $cleaned = $retrieval->cleanupTempFiles(24);
    error_log('[BackBork] Cleanup complete. Removed ' . $cleaned . ' temp files.');
}

/**
 * Perform cron health self-check.
 * Verifies cron file exists and last run was recent.
 * Sends alerts if issues detected.
 */
function performHealthCheck() {
    // File to track when we last sent a health alert (prevent spam)
    $healthFile = '/usr/local/cpanel/3rdparty/backbork/cron_health_notified';
    
    // Check if cron configuration file exists
    if (!file_exists('/etc/cron.d/backbork')) {
        sendHealthAlert('Cron file /etc/cron.d/backbork is missing', $healthFile);
        return;
    }
    
    // Check last run time to detect stuck/failed cron
    if (file_exists(CRON_LAST_RUN_FILE)) {
        $lastRun = (int)file_get_contents(CRON_LAST_RUN_FILE);
        $timeSinceLastRun = time() - $lastRun;
        
        // Alert if gap exceeds expected interval
        if ($timeSinceLastRun > CRON_HEALTH_CHECK_INTERVAL) {
            // Format human-readable time delta
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
    
    // Clear previous health notification flag - we're healthy now
    if (file_exists($healthFile)) {
        unlink($healthFile);
    }
}

/**
 * Send health alert notifications via email and Slack.
 * Rate-limited to prevent notification spam (max once per 24 hours).
 * 
 * @param string $issue Description of the health issue
 * @param string $healthFile Path to notification tracking file
 * @param int|null $lastRun Timestamp of last successful run (optional)
 */
function sendHealthAlert($issue, $healthFile, $lastRun = null) {
    // Rate limiting: Don't re-notify within 24 hours
    if (file_exists($healthFile)) {
        $lastNotified = (int)file_get_contents($healthFile);
        if ((time() - $lastNotified) < 86400) {
            return;
        }
    }
    
    error_log('[BackBork] HEALTH ALERT: ' . $issue);
    
    // Load root user's notification configuration
    $config = new BackBorkConfig();
    $rootConfig = $config->getUserConfig('root');
    
    // Gather alert context
    $hostname = gethostname() ?: 'unknown';
    $timestamp = date('Y-m-d H:i:s T');
    $lastRunStr = $lastRun ? date('Y-m-d H:i:s T', $lastRun) : 'Never';
    
    // ========================================================================
    // EMAIL NOTIFICATION - Send formatted alert email to configured address
    // ========================================================================
    if (!empty($rootConfig['notify_email'])) {
        // Compose alert subject with warning emoji and hostname
        $subject = "⚠️ [BackBork] Cron Health Check Failed on {$hostname}";
        
        // Build plain text email body with troubleshooting steps
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
        
        // Send email using PHP mail() function
        mail($rootConfig['notify_email'], $subject, $body, "From: backbork@{$hostname}");
        error_log('[BackBork] Health alert email sent to ' . $rootConfig['notify_email']);
    }
    
    // ========================================================================
    // SLACK NOTIFICATION - Send Block Kit formatted message to webhook
    // ========================================================================
    if (!empty($rootConfig['slack_webhook'])) {
        // Build Slack Block Kit payload with rich formatting
        $slackPayload = [
            // Fallback text for notifications
            'text' => "⚠️ BackBork Cron Health Check Failed on {$hostname}",
            // Rich Block Kit blocks for full display
            'blocks' => [
                // Header block with warning icon
                [
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => '⚠️ Cron Health Alert',
                        'emoji' => true
                    ]
                ],
                // Status fields in 2-column layout
                [
                    'type' => 'section',
                    'fields' => [
                        ['type' => 'mrkdwn', 'text' => "*Server:*\n{$hostname}"],
                        ['type' => 'mrkdwn', 'text' => "*Status:*\n🔴 FAILED"],
                        ['type' => 'mrkdwn', 'text' => "*Issue:*\n{$issue}"],
                        ['type' => 'mrkdwn', 'text' => "*Last Run:*\n{$lastRunStr}"]
                    ]
                ],
                // Troubleshooting section with action items
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "*Action Required:*\n1. Check crond service\n2. Verify /etc/cron.d/backbork\n3. Check /var/log/cron"
                    ]
                ]
            ]
        ];
        
        // Send POST request to Slack webhook URL
        $ch = curl_init($rootConfig['slack_webhook']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,                                  // HTTP POST method
            CURLOPT_POSTFIELDS => json_encode($slackPayload),      // JSON encoded payload
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,                        // Return response string
            CURLOPT_TIMEOUT => 10                                  // 10 second timeout
        ]);
        curl_exec($ch);
        curl_close($ch);
        error_log('[BackBork] Health alert sent to Slack');
    }
    
    // Record notification timestamp to prevent re-alerting too soon
    file_put_contents($healthFile, time());
}

/**
 * Send daily summary notification via email and Slack.
 * Called with 'summary' argument: php handler.php summary
 * 
 * Iterates through all users who have daily summary enabled and sends
 * them a digest of activity from the past 24 hours.
 */
function sendDailySummary() {
    error_log('[BackBork] Generating daily summaries...');
    
    $config = new BackBorkConfig();
    $hostname = gethostname() ?: 'unknown';
    $date = date('Y-m-d');
    $generatedAt = date('Y-m-d H:i:s T');
    
    // Gather statistics once (shared across all users)
    $stats = gatherDailyStats();
    
    // Determine overall status
    $hasFailures = ($stats['backup_failures'] > 0 || $stats['restore_failures'] > 0);
    $hasActivity = ($stats['total_events'] > 0);
    $statusEmoji = $hasFailures ? '⚠️' : ($hasActivity ? '✅' : 'ℹ️');
    $statusText = $hasFailures ? 'Issues Detected' : ($hasActivity ? 'All Good' : 'No Activity');
    
    // Find all users with daily summary enabled
    $usersToNotify = [];
    
    // Check root user
    $rootConfig = $config->getUserConfig('root');
    if (!empty($rootConfig['notify_daily_summary'])) {
        $usersToNotify['root'] = $rootConfig;
    }
    
    // Check reseller users (scan config directory)
    $configDir = '/usr/local/cpanel/3rdparty/backbork/config';
    if (is_dir($configDir)) {
        foreach (glob($configDir . '/*.json') as $configFile) {
            $username = basename($configFile, '.json');
            if ($username === 'root' || $username === 'global') continue;
            
            $userConfig = $config->getUserConfig($username);
            if (!empty($userConfig['notify_daily_summary'])) {
                $usersToNotify[$username] = $userConfig;
            }
        }
    }
    
    if (empty($usersToNotify)) {
        error_log('[BackBork] Daily summary skipped - no users have it enabled');
        return;
    }
    
    error_log('[BackBork] Sending daily summary to ' . count($usersToNotify) . ' user(s)');
    
    // Send summary to each user
    foreach ($usersToNotify as $username => $userConfig) {
        // Skip if no notification channels configured
        if (empty($userConfig['notify_email']) && empty($userConfig['slack_webhook'])) {
            continue;
        }
        
        sendSummaryToUser($username, $userConfig, $stats, $hostname, $date, $generatedAt, $statusEmoji, $statusText);
    }
    
    error_log('[BackBork] Daily summary complete');
}

/**
 * Send daily summary to a specific user.
 * 
 * @param string $username User receiving the summary
 * @param array $userConfig User's notification configuration
 * @param array $stats Gathered statistics
 * @param string $hostname Server hostname
 * @param string $date Current date
 * @param string $generatedAt Generation timestamp
 * @param string $statusEmoji Status indicator emoji
 * @param string $statusText Status description
 */
function sendSummaryToUser($username, $userConfig, $stats, $hostname, $date, $generatedAt, $statusEmoji, $statusText) {
    // ========================================================================
    // EMAIL NOTIFICATION
    // ========================================================================
    if (!empty($userConfig['notify_email'])) {
        $subject = "{$statusEmoji} [BackBork] Daily Summary for {$hostname} - {$date}";
        
        $body = "BackBork Daily Summary\n";
        $body .= "══════════════════════════\n\n";
        $body .= "Server: {$hostname}\n";
        $body .= "Date: {$date}\n";
        $body .= "Status: {$statusText}\n";
        $body .= "Generated: {$generatedAt}\n\n";
        
        $body .= "📊 Activity Summary (Last 24 Hours)\n";
        $body .= "──────────────────────────\n";
        $body .= "Backups Completed:  {$stats['backup_successes']}\n";
        $body .= "Backups Failed:     {$stats['backup_failures']}\n";
        $body .= "Restores Completed: {$stats['restore_successes']}\n";
        $body .= "Restores Failed:    {$stats['restore_failures']}\n";
        $body .= "Schedules Run:      {$stats['schedules_run']}\n";
        $body .= "Backups Pruned:     {$stats['backups_pruned']}\n";
        $body .= "Total Events:       {$stats['total_events']}\n\n";
        
        // Queue status
        $body .= "📋 Current Queue Status\n";
        $body .= "──────────────────────────\n";
        $body .= "Pending Jobs:    {$stats['queue_pending']}\n";
        $body .= "Completed Jobs:  {$stats['queue_completed']}\n";
        $body .= "Failed Jobs:     {$stats['queue_failed']}\n\n";
        
        // Recent errors (if any)
        if (!empty($stats['recent_errors'])) {
            $body .= "❌ Recent Errors\n";
            $body .= "──────────────────────────\n";
            foreach (array_slice($stats['recent_errors'], 0, 5) as $error) {
                $body .= "• [{$error['timestamp']}] {$error['type']}: {$error['message']}\n";
            }
            $body .= "\n";
        }
        
        $body .= "══════════════════════════\n";
        $body .= "BackBork KISS v" . BACKBORK_VERSION . " | Open-source Disaster Recovery\n";
        
        mail($userConfig['notify_email'], $subject, $body, "From: backbork@{$hostname}");
        error_log('[BackBork] Daily summary email sent to ' . $userConfig['notify_email']);
    }
    
    // ========================================================================
    // SLACK NOTIFICATION
    // ========================================================================
    if (!empty($userConfig['slack_webhook'])) {
        $slackPayload = [
            'text' => "{$statusEmoji} BackBork Daily Summary for {$hostname}",
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => "{$statusEmoji} Daily Summary - {$date}",
                        'emoji' => true
                    ]
                ],
                [
                    'type' => 'section',
                    'fields' => [
                        ['type' => 'mrkdwn', 'text' => "*Server:*\n{$hostname}"],
                        ['type' => 'mrkdwn', 'text' => "*Status:*\n{$statusText}"]
                    ]
                ],
                [
                    'type' => 'divider'
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "*📊 Activity (24h)*"
                    ]
                ],
                [
                    'type' => 'section',
                    'fields' => [
                        ['type' => 'mrkdwn', 'text' => "*Backups:*\n✅ {$stats['backup_successes']} | ❌ {$stats['backup_failures']}"],
                        ['type' => 'mrkdwn', 'text' => "*Restores:*\n✅ {$stats['restore_successes']} | ❌ {$stats['restore_failures']}"],
                        ['type' => 'mrkdwn', 'text' => "*Schedules Run:*\n{$stats['schedules_run']}"],
                        ['type' => 'mrkdwn', 'text' => "*Pruned:*\n{$stats['backups_pruned']}"]
                    ]
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "*📋 Queue:* {$stats['queue_pending']} pending | {$stats['queue_completed']} completed | {$stats['queue_failed']} failed"
                    ]
                ]
            ]
        ];
        
        // Add errors section if any
        if (!empty($stats['recent_errors'])) {
            $errorText = "*❌ Recent Errors:*\n";
            foreach (array_slice($stats['recent_errors'], 0, 3) as $error) {
                $errorText .= "• {$error['type']}: " . substr($error['message'], 0, 50) . "...\n";
            }
            $slackPayload['blocks'][] = [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => $errorText]
            ];
        }
        
        // Footer
        $slackPayload['blocks'][] = [
            'type' => 'context',
            'elements' => [
                ['type' => 'mrkdwn', 'text' => "BackBork KISS v" . BACKBORK_VERSION . " | Generated {$generatedAt}"]
            ]
        ];
        
        $ch = curl_init($userConfig['slack_webhook']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($slackPayload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10
        ]);
        curl_exec($ch);
        curl_close($ch);
        error_log('[BackBork] Daily summary sent to Slack');
    }
}

/**
 * Gather statistics from logs and queue for the past 24 hours.
 * 
 * @return array Statistics array with counts and recent errors
 */
function gatherDailyStats() {
    $stats = [
        'backup_successes' => 0,
        'backup_failures' => 0,
        'restore_successes' => 0,
        'restore_failures' => 0,
        'schedules_run' => 0,
        'backups_pruned' => 0,
        'total_events' => 0,
        'queue_pending' => 0,
        'queue_completed' => 0,
        'queue_failed' => 0,
        'recent_errors' => []
    ];
    
    $cutoffTime = strtotime('-24 hours');
    
    // Parse operations log for last 24 hours
    $logFile = BackBorkLog::LOG_FILE;
    if (file_exists($logFile)) {
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (!$entry) continue;
            
            // Check if within 24 hour window
            $timestamp = strtotime($entry['timestamp'] ?? '');
            if ($timestamp < $cutoffTime) continue;
            
            $stats['total_events']++;
            $type = $entry['type'] ?? '';
            $success = $entry['success'] ?? true;
            
            // Count by type
            switch ($type) {
                case 'backup':
                    if ($success) {
                        $stats['backup_successes']++;
                    } else {
                        $stats['backup_failures']++;
                        $stats['recent_errors'][] = [
                            'timestamp' => $entry['timestamp'],
                            'type' => 'Backup',
                            'message' => $entry['message'] ?? 'Unknown error'
                        ];
                    }
                    break;
                    
                case 'restore':
                    if ($success) {
                        $stats['restore_successes']++;
                    } else {
                        $stats['restore_failures']++;
                        $stats['recent_errors'][] = [
                            'timestamp' => $entry['timestamp'],
                            'type' => 'Restore',
                            'message' => $entry['message'] ?? 'Unknown error'
                        ];
                    }
                    break;
                    
                case 'schedule_run':
                case 'queue_cron_process':
                    $stats['schedules_run']++;
                    break;
            }
            
            // Check message for pruning info
            $message = $entry['message'] ?? '';
            if (stripos($message, 'pruned') !== false || stripos($message, 'retention') !== false) {
                // Try to extract count from message
                if (preg_match('/(\d+)\s*(old\s+)?backup/i', $message, $matches)) {
                    $stats['backups_pruned'] += (int)$matches[1];
                }
            }
        }
    }
    
    // Get current queue status
    $queue = new BackBorkQueue();
    $queueData = $queue->getQueue('root', true);
    $stats['queue_pending'] = count($queueData['queued'] ?? []);
    
    // Count completed/failed from completed directory
    $completedDir = BackBorkQueue::getCompletedDir();
    if (is_dir($completedDir)) {
        $completedFiles = glob($completedDir . '/*.json');
        foreach ($completedFiles as $file) {
            $job = json_decode(file_get_contents($file), true);
            if ($job) {
                if (($job['status'] ?? '') === 'failed') {
                    $stats['queue_failed']++;
                } else {
                    $stats['queue_completed']++;
                }
            }
        }
    }
    
    // Sort errors by most recent first
    usort($stats['recent_errors'], function($a, $b) {
        return strtotime($b['timestamp']) - strtotime($a['timestamp']);
    });
    
    return $stats;
}