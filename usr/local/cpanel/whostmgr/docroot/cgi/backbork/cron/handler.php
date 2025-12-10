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

// Prevent concurrent execution - skip if already running
BackBorkConfig::debugLog('Checking if processor is running...');
if ($processor->isRunning()) {
    BackBorkConfig::debugLog('Queue processor already running, skipping');
    exit(0);
}
BackBorkConfig::debugLog('Processor not running, continuing...');

// Handle special 'cleanup' command for maintenance tasks
if (isset($argv[1]) && $argv[1] === 'cleanup') {
    runCleanup($processor);
    exit(0);
}

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
