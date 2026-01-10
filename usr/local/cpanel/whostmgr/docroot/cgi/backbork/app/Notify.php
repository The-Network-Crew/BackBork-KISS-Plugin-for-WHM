<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/
 *
 *  THIS FILE:
 *   Notification system using text templates for email and Slack.
 *   Sends alerts for backup/restore/system events with configurable channels.
 *
 *  This program is free software: you can redistribute it and/or modify
 *   it under the terms of the GNU Affero General Public License as published by
 *   the Free Software Foundation, either version 3 of the License, or
 *   (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *   but WITHOUT ANY WARRANTY; without even the implied warranty of
 *   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *   GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *   along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 *  @package BackBork
 *  @version See version.php (constant: BACKBORK_VERSION)
 *  @author The Network Crew Pty Ltd & Velocity Host Pty Ltd
 */

class BackBorkNotify {
    
    /** Directory containing notification templates */
    const TEMPLATES_DIR = '/usr/local/cpanel/whostmgr/docroot/cgi/backbork/templates/notifications';
    
    /**
     * Event configurations: maps event names to display properties
     * Easy to add new events - just add an entry here
     */
    private static $events = [
        // Backup events (use job.txt template)
        'backup_start'   => ['type' => 'Backup',  'status' => 'Started',   'emoji' => '🔄', 'color' => '#3498db'],
        'backup_success' => ['type' => 'Backup',  'status' => 'Completed', 'emoji' => '✅', 'color' => '#059669'],
        'backup_failure' => ['type' => 'Backup',  'status' => 'Failed',    'emoji' => '❌', 'color' => '#dc2626'],
        
        // Restore events (use job.txt template)
        'restore_start'   => ['type' => 'Restore', 'status' => 'Started',   'emoji' => '🔄', 'color' => '#2563eb'],
        'restore_success' => ['type' => 'Restore', 'status' => 'Completed', 'emoji' => '✅', 'color' => '#059669'],
        'restore_failure' => ['type' => 'Restore', 'status' => 'Failed',    'emoji' => '❌', 'color' => '#dc2626'],
        
        // System events (use system.txt template)
        'cron_health'     => ['title' => 'Cron Health Alert',       'emoji' => '🚨', 'color' => '#dc2626'],
        'queue_failure'   => ['title' => 'Queue Processing Failed', 'emoji' => '⚠️', 'color' => '#f97316'],
        'pruning'         => ['title' => 'Backup Pruning Complete', 'emoji' => '🗑️', 'color' => '#8b5cf6'],
        'update_success'  => ['title' => 'Plugin Update Complete',  'emoji' => '🚀', 'color' => '#059669'],
        'update_failure'  => ['title' => 'Plugin Update Failed',    'emoji' => '❌', 'color' => '#dc2626'],
        'test'            => ['title' => 'Test Notification',       'emoji' => '🧪', 'color' => '#6366f1'],
    ];
    
    // ========================================================================
    // PUBLIC API
    // ========================================================================
    
    /**
     * Send notification for an event
     * 
     * @param string $event Event type (backup_start, restore_success, etc.)
     * @param array $data Event data (accounts, destination, errors, etc.)
     * @param array $config User config with notify_email and slack_webhook
     * @return array Results for each channel
     */
    public function sendNotification($event, $data, $config) {
        $results = [];
        
        // Debug log the attempt
        BackBorkConfig::debugLog("Notify: Sending {$event} notification");
        
        // Build the message
        $message = $this->buildMessage($event, $data);
        
        // Send email if configured
        if (!empty($config['notify_email'])) {
            BackBorkConfig::debugLog("Notify: Sending email to {$config['notify_email']}");
            $results['email'] = $this->sendEmail($config['notify_email'], $message['subject'], $message['body']);
            BackBorkConfig::debugLog("Notify: Email result - " . ($results['email']['success'] ? 'success' : 'failed'));
        } else {
            BackBorkConfig::debugLog("Notify: No email configured");
        }
        
        // Send Slack if configured
        if (!empty($config['slack_webhook'])) {
            BackBorkConfig::debugLog("Notify: Sending Slack notification");
            $results['slack'] = $this->sendSlack($config['slack_webhook'], $message);
            BackBorkConfig::debugLog("Notify: Slack result - " . ($results['slack']['success'] ? 'success' : 'failed'));
        }
        
        return $results;
    }
    
    /**
     * Send a test notification
     * 
     * @param string $type 'email' or 'slack'
     * @param array $config User config
     * @return array Result
     */
    public function testNotification($type, $config) {
        $message = $this->buildMessage('test', ['message' => 'If you received this, your notifications are configured correctly!']);
        
        if ($type === 'email') {
            if (empty($config['notify_email'])) {
                return ['success' => false, 'message' => 'No email address configured'];
            }
            return $this->sendEmail($config['notify_email'], $message['subject'], $message['body']);
        }
        
        if ($type === 'slack') {
            if (empty($config['slack_webhook'])) {
                return ['success' => false, 'message' => 'No Slack webhook configured'];
            }
            return $this->sendSlack($config['slack_webhook'], $message);
        }
        
        return ['success' => false, 'message' => 'Invalid notification type'];
    }
    
    // ========================================================================
    // MESSAGE BUILDING
    // ========================================================================
    
    /**
     * Build notification message from template
     * 
     * @param string $event Event type
     * @param array $data Event data
     * @return array Message with subject, body, color, emoji, slack_action, slack_body
     */
    private function buildMessage($event, $data) {
        $hostname = gethostname();
        $timestamp = date('Y-m-d H:i:s');
        
        // Get event config (with fallback for unknown events)
        $eventConfig = self::$events[$event] ?? ['title' => ucfirst(str_replace('_', ' ', $event)), 'emoji' => 'ℹ️', 'color' => '#5a5f66'];
        
        // Determine if this is a job event (backup/restore) or system event
        $isJobEvent = isset($eventConfig['type']);
        
        // Load appropriate template
        $templateFile = self::TEMPLATES_DIR . '/' . ($isJobEvent ? 'job.txt' : 'system.txt');
        $template = file_exists($templateFile) ? file_get_contents($templateFile) : $this->getDefaultTemplate($isJobEvent);
        
        // Build subject line (for email)
        if ($isJobEvent) {
            $subject = "[BackBork KISS] {$eventConfig['type']} {$eventConfig['status']} :: {$hostname}";
        } else {
            $subject = "[BackBork KISS] {$eventConfig['title']} :: {$hostname}";
        }
        
        // Build Slack action line (e.g., "Backup Completed" or "Cron Health Alert")
        $slackAction = $isJobEvent 
            ? "{$eventConfig['type']} {$eventConfig['status']}" 
            : ($eventConfig['title'] ?? 'Notification');
        
        // Build details section based on data provided
        $details = $this->buildDetails($data);
        
        // Build errors section if present
        $errors = '';
        if (!empty($data['errors'])) {
            $errorList = is_array($data['errors']) ? implode("\n", $data['errors']) : $data['errors'];
            $errors = "\nErrors:\n{$errorList}";
        } elseif (!empty($data['error'])) {
            $errors = "\nError: {$data['error']}";
        }
        
        // Build replacements
        $replacements = [
            '{{EMOJI}}'     => $eventConfig['emoji'],
            '{{TYPE}}'      => $eventConfig['type'] ?? '',
            '{{STATUS}}'    => $eventConfig['status'] ?? '',
            '{{TITLE}}'     => $eventConfig['title'] ?? '',
            '{{HOSTNAME}}'  => $hostname,
            '{{TIMESTAMP}}' => $timestamp,
            '{{USER}}'      => $data['user'] ?? 'system',
            '{{DETAILS}}'   => $details,
            '{{ERRORS}}'    => $errors,
            '{{MESSAGE}}'   => $data['message'] ?? '',
            '{{VERSION}}'   => defined('BACKBORK_VERSION') ? BACKBORK_VERSION : 'unknown',
        ];
        
        // Apply replacements
        $body = str_replace(array_keys($replacements), array_values($replacements), $template);
        
        // Clean up any extra blank lines
        $body = preg_replace("/\n{3,}/", "\n\n", trim($body));
        
        // Build Slack-specific body (no redundant hostname/action, just the details)
        $slackBody = $this->buildSlackBody($data, $timestamp, $errors);
        
        return [
            'subject'      => $subject,
            'body'         => $body,
            'color'        => $eventConfig['color'],
            'emoji'        => $eventConfig['emoji'],
            'slack_action' => $slackAction,
            'slack_body'   => $slackBody
        ];
    }
    
    /**
     * Build Slack-specific message body
     * Compact format with only relevant info (no repeated hostname/status)
     */
    private function buildSlackBody($data, $timestamp, $errors) {
        $lines = [];
        
        // User/Accounts
        if (!empty($data['accounts'])) {
            $accounts = is_array($data['accounts']) ? implode(', ', $data['accounts']) : $data['accounts'];
            $lines[] = "*Accounts:* {$accounts}";
        } elseif (!empty($data['account'])) {
            $lines[] = "*Account:* {$data['account']}";
        }
        
        // Destination
        if (!empty($data['destination'])) {
            $lines[] = "*Destination:* {$data['destination']}";
        }

        // Trigger info first (most relevant for knowing who/what initiated)
        if (!empty($data['requestor'])) {
            $lines[] = "*Trigger:* {$data['requestor']}";
        }
        
        // Backup file (for restores)
        if (!empty($data['backup_file'])) {
            $lines[] = "*Backup:* " . basename($data['backup_file']);
        }
        
        // Timestamp
        $lines[] = "*Time:* {$timestamp}";
        
        // User who initiated (at the bottom)
        if (!empty($data['user'])) {
            $lines[] = "*WHM User:* {$data['user']}";
        }
        
        // System event specific fields
        if (!empty($data['issue'])) {
            $lines[] = "*Issue:* {$data['issue']}";
        }
        if (!empty($data['last_run'])) {
            $lines[] = "*Last Run:* {$data['last_run']}";
        }
        if (isset($data['pruned_count'])) {
            $lines[] = "*Removed:* {$data['pruned_count']} backups";
        }
        if (!empty($data['message'])) {
            $lines[] = "\n{$data['message']}";
        }
        
        // Errors at the end
        if (!empty($errors)) {
            $lines[] = $errors;
        }
        
        return implode("\n", $lines);
    }
    
    /**
     * Build details section from data array
     * Automatically includes any relevant fields that are present
     */
    private function buildDetails($data) {
        $lines = [];
        
        // Accounts (plural - for backups)
        if (!empty($data['accounts'])) {
            $accounts = is_array($data['accounts']) ? implode(', ', $data['accounts']) : $data['accounts'];
            $lines[] = "Accounts: {$accounts}";
        }
        
        // Account (singular - for restores)
        if (!empty($data['account'])) {
            $lines[] = "Account: {$data['account']}";
        }
        
        // Destination
        if (!empty($data['destination'])) {
            $lines[] = "Destination: {$data['destination']}";
        }
        
        // Backup file (for restores)
        if (!empty($data['backup_file'])) {
            $lines[] = "Backup File: {$data['backup_file']}";
        }
        
        // Requestor (manual vs cron vs api)
        if (!empty($data['requestor'])) {
            $lines[] = "Triggered by: {$data['requestor']}";
        }
        
        // Cron health specific
        if (!empty($data['issue'])) {
            $lines[] = "Issue: {$data['issue']}";
        }
        if (!empty($data['last_run'])) {
            $lines[] = "Last Run: {$data['last_run']}";
        }
        
        // Pruning specific
        if (isset($data['pruned_count'])) {
            $lines[] = "Backups Removed: {$data['pruned_count']}";
        }
        if (!empty($data['details'])) {
            $detailsList = is_array($data['details']) ? implode("\n  ", $data['details']) : $data['details'];
            $lines[] = "Details:\n  {$detailsList}";
        }
        
        return empty($lines) ? '' : implode("\n", $lines);
    }
    
    /**
     * Default template fallback if file not found
     */
    private function getDefaultTemplate($isJobEvent) {
        if ($isJobEvent) {
            return "{{EMOJI}} {{TYPE}} {{STATUS}}!\n\nServer: {{HOSTNAME}}\nTime: {{TIMESTAMP}}\nUser: {{USER}}\n\n{{DETAILS}}\n{{ERRORS}}\n---\nBackBork KISS • v{{VERSION}}";
        }
        return "{{EMOJI}} {{TITLE}}\n\nServer: {{HOSTNAME}}\nTime: {{TIMESTAMP}}\n\n{{MESSAGE}}\n{{DETAILS}}\n---\nBackBork KISS • v{{VERSION}}";
    }
    
    // ========================================================================
    // EMAIL
    // ========================================================================
    
    /**
     * Send email notification via PHP mail()
     */
    public function sendEmail($to, $subject, $body) {
        // Validate email
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            BackBorkConfig::debugLog("Notify: Invalid email address: {$to}");
            return ['success' => false, 'message' => 'Invalid email address'];
        }
        
        // Build headers
        $headers = [
            'From: backbork@' . gethostname(),
            'X-Mailer: BackBork KISS',
            'Content-Type: text/plain; charset=UTF-8'
        ];
        
        // Send
        $result = @mail($to, $subject, $body, implode("\r\n", $headers));
        
        return [
            'success' => $result,
            'message' => $result ? 'Email sent' : 'Failed to send email'
        ];
    }
    
    // ========================================================================
    // SLACK
    // ========================================================================
    
    /**
     * Send Slack webhook notification
     * 
     * Format optimised for mobile: emoji [hostname] Action Status
     * Body contains only info not already visible (no repeated hostname/action)
     */
    public function sendSlack($webhookUrl, $message) {
        // Validate URL
        if (!preg_match('/^https:\/\/hooks\.slack\.com\//', $webhookUrl)) {
            return ['success' => false, 'message' => 'Invalid Slack webhook URL'];
        }
        
        $hostname = gethostname();
        
        // Build clean header: emoji [hostname] Action Status
        // e.g., "✅ server.example.com Backup Completed"
        $slackHeader = ($message['emoji'] ?? 'ℹ️') . ' [' . $hostname . '] ' . ($message['slack_action'] ?? 'Notification');
        
        // Get version for footer
        $version = defined('BACKBORK_VERSION') ? BACKBORK_VERSION : 'unknown';
        
        // Build payload - clean and mobile-friendly
        $payload = [
            'username' => 'BackBork KISS',
            'icon_emoji' => ':shield:',
            'attachments' => [[
                'fallback' => $slackHeader,
                'color'    => $message['color'] ?? '#7f22b2',
                'pretext'  => $slackHeader,
                'text'     => $message['slack_body'] ?? $message['body'],
                'footer'   => 'v' . $version . ' • https://backbork.com',
                'ts'       => time()
            ]]
        ];
        
        // Send via cURL
        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode === 200 && $response === 'ok') {
            return ['success' => true, 'message' => 'Slack notification sent'];
        }
        
        return [
            'success' => false,
            'message' => 'Slack failed: ' . ($error ?: $response)
        ];
    }
}
