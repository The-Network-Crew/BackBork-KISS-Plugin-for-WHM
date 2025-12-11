<?php
/**
 * BackBork KISS - Notification Handler
 * 
 * Handles email and Slack webhook notifications for backup/restore events.
 * Supports templated messages with variable substitution.
 * 
 * Notification Types:
 * - backup_start: Backup job initiated
 * - backup_success: Backup completed successfully
 * - backup_failure: Backup failed with errors
 * - restore_start: Restore job initiated
 * - restore_success: Restore completed successfully
 * - restore_failure: Restore failed with errors
 * - cron_health: Cron health check detected an issue (root only)
 * - queue_failure: Queue processing encountered failures (root only)
 * - pruning: Backup retention pruning completed (root only)
 * - test: Test notification to verify configuration
 * 
 * Template Variables:
 * - {{hostname}}: Server hostname
 * - {{timestamp}}: Current date/time
 * - {{accounts}}: Comma-separated account list
 * - {{user}}: User who triggered the job
 * - {{destination}}: Backup destination name
 * - {{errors}}: Error messages (for failures)
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

class BackBorkNotify {
    
    // ========================================================================
    // CONSTANTS
    // ========================================================================
    
    /** Directory containing notification templates (JSON files) */
    const TEMPLATES_DIR = '/usr/local/cpanel/whostmgr/docroot/cgi/backbork/templates/notifications';
    
    // ========================================================================
    // PUBLIC NOTIFICATION METHODS
    // ========================================================================
    
    /**
     * Send notification for an event
     * 
     * Sends to all configured channels (email and/or Slack).
     * 
     * @param string $event Event type (backup_start, backup_success, etc.)
     * @param array $data Event data (accounts, destination, user, errors, etc.)
     * @param array $config User configuration with notify_email and slack_webhook
     * @return array Results for each notification channel
     */
    public function sendNotification($event, $data, $config) {
        $results = [];
        
        // Build message from template or fallback
        $message = $this->buildMessage($event, $data);
        
        // Send email notification if configured
        if (!empty($config['notify_email'])) {
            $results['email'] = $this->sendEmail($config['notify_email'], $message['subject'], $message['body']);
        }
        
        // Send Slack notification if configured
        if (!empty($config['slack_webhook'])) {
            $results['slack'] = $this->sendSlack($config['slack_webhook'], $message);
        }
        
        return $results;
    }
    
    // ========================================================================
    // MESSAGE BUILDING
    // ========================================================================
    
    /**
     * Build notification message from template or inline fallback
     * 
     * Tries to load JSON template first for customization.
     * Falls back to hardcoded messages if template not found.
     * 
     * @param string $event Event type
     * @param array $data Event data for variable substitution
     * @return array Message with subject, body, color, emoji
     */
    private function buildMessage($event, $data) {
        $hostname = gethostname();
        $timestamp = date('Y-m-d H:i:s');
        
        // Try to load custom template from templates directory
        $templateFile = self::TEMPLATES_DIR . '/' . $event . '.json';
        if (file_exists($templateFile)) {
            $template = json_decode(file_get_contents($templateFile), true);
            if ($template) {
                return $this->applyTemplate($template, $data, $hostname, $timestamp);
            }
        }
        
        // Fallback to inline message building
        return $this->buildInlineMessage($event, $data, $hostname, $timestamp);
    }
    
    /**
     * Apply template with variable substitution
     * 
     * Replaces {{variable}} placeholders with actual values.
     * 
     * @param array $template Template data with subject, body, color, emoji
     * @param array $data Event data
     * @param string $hostname Server hostname
     * @param string $timestamp Current timestamp
     * @return array Processed message
     */
    private function applyTemplate($template, $data, $hostname, $timestamp) {
        // Merge all available variables
        $vars = array_merge($data, [
            'hostname' => $hostname,
            'timestamp' => $timestamp,
            // Convert arrays to strings for display
            'accounts' => is_array($data['accounts'] ?? null) ? implode(', ', $data['accounts']) : ($data['accounts'] ?? ''),
            'errors' => is_array($data['errors'] ?? null) ? implode("\n", $data['errors']) : ($data['errors'] ?? '')
        ]);
        
        $subject = $template['subject'] ?? '';
        $body = $template['body'] ?? '';
        
        // Replace all {{variable}} placeholders
        foreach ($vars as $key => $value) {
            if (is_string($value)) {
                $subject = str_replace('{{' . $key . '}}', $value, $subject);
                $body = str_replace('{{' . $key . '}}', $value, $body);
            }
        }
        
        return [
            'subject' => $subject,
            'body' => $body,
            'color' => $template['color'] ?? '#3498db',    // Default blue
            'emoji' => $template['emoji'] ?? 'ℹ️'          // Default info emoji
        ];
    }
    
    /**
     * Build inline message (fallback when template not found)
     * 
     * Contains hardcoded messages for each event type.
     * 
     * @param string $event Event type
     * @param array $data Event data
     * @param string $hostname Server hostname
     * @param string $timestamp Current timestamp
     * @return array Message with subject, body, color, emoji
     */
    private function buildInlineMessage($event, $data, $hostname, $timestamp) {
        switch ($event) {
            // ================================================================
            // BACKUP EVENTS
            // ================================================================
            
            case 'backup_start':
                $accounts = is_array($data['accounts']) ? implode(', ', $data['accounts']) : $data['accounts'];
                $requestor = $data['requestor'] ?? 'N/A';
                return [
                    'subject' => "[BackBork KISS] Backup Started - {$hostname}",
                    'body' => "BackBork KISS - Backup Started\n\n" .
                              "Server: {$hostname}\n" .
                              "Time: {$timestamp}\n" .
                              "User: {$data['user']} ({$requestor})\n" .
                              "Accounts: {$accounts}\n" .
                              "Destination: {$data['destination']}",
                    'color' => '#3498db',  // Blue - in progress
                    'emoji' => '🔄'
                ];
                
            case 'backup_success':
                $accounts = is_array($data['accounts']) ? implode(', ', $data['accounts']) : $data['accounts'];
                $requestor = $data['requestor'] ?? 'N/A';
                return [
                    'subject' => "[BackBork KISS] Backup Completed - {$hostname}",
                    'body' => "BackBork KISS - Backup Completed\n\n" .
                              "Server: {$hostname}\n" .
                              "Time: {$timestamp}\n" .
                              "User: {$data['user']} ({$requestor})\n" .
                              "Accounts: {$accounts}\n" .
                              "Destination: {$data['destination']}",
                    'color' => '#059669',  // Green - success
                    'emoji' => '✅'
                ];
                
            case 'backup_failure':
                $accounts = is_array($data['accounts']) ? implode(', ', $data['accounts']) : $data['accounts'];
                $errors = is_array($data['errors']) ? implode("\n", $data['errors']) : $data['errors'];
                $requestor = $data['requestor'] ?? 'N/A';
                return [
                    'subject' => "[BackBork KISS] Backup FAILED - {$hostname}",
                    'body' => "BackBork KISS - Backup FAILED\n\n" .
                              "Server: {$hostname}\n" .
                              "Time: {$timestamp}\n" .
                              "User: {$data['user']} ({$requestor})\n" .
                              "Accounts: {$accounts}\n" .
                              "Destination: {$data['destination']}\n\n" .
                              "Errors:\n{$errors}",
                    'color' => '#e74c3c',  // Red - failure
                    'emoji' => '❌'
                ];
                
            // ================================================================
            // RESTORE EVENTS
            // ================================================================
                
            case 'restore_start':
                $requestor = $data['requestor'] ?? 'N/A';
                return [
                    'subject' => "[BackBork KISS] Restore Started - {$hostname}",
                    'body' => "BackBork KISS - Restore Started\n\n" .
                              "Server: {$hostname}\n" .
                              "Time: {$timestamp}\n" .
                              "User: {$data['user']} ({$requestor})\n" .
                              "Account: {$data['account']}\n" .
                              "Backup File: {$data['backup_file']}\n" .
                              "Source: {$data['destination']}",
                    'color' => '#2563eb',  // Blue - in progress
                    'emoji' => '🔄'
                ];
                
            case 'restore_success':
                $requestor = $data['requestor'] ?? 'N/A';
                return [
                    'subject' => "[BackBork KISS] Restore Completed - {$hostname}",
                    'body' => "BackBork KISS - Restore Completed\n\n" .
                              "Server: {$hostname}\n" .
                              "Time: {$timestamp}\n" .
                              "User: {$data['user']} ({$requestor})\n" .
                              "Account: {$data['account']}\n" .
                              "Backup File: {$data['backup_file']}",
                    'color' => '#059669',  // Green - success
                    'emoji' => '✅'
                ];
                
            case 'restore_failure':
                $requestor = $data['requestor'] ?? 'N/A';
                return [
                    'subject' => "[BackBork KISS] Restore FAILED - {$hostname}",
                    'body' => "BackBork KISS - Restore FAILED\n\n" .
                              "Server: {$hostname}\n" .
                              "Time: {$timestamp}\n" .
                              "User: {$data['user']} ({$requestor})\n" .
                              "Account: {$data['account']}\n" .
                              "Backup File: {$data['backup_file']}\n\n" .
                              "Error: {$data['error']}",
                    'color' => '#e74c3c',  // Red - failure
                    'emoji' => '❌'
                ];
                
            // ================================================================
            // SYSTEM/CRON EVENTS (Root Only)
            // ================================================================
            
            case 'cron_health':
                $issue = $data['issue'] ?? 'Unknown issue';
                $lastRun = $data['last_run'] ?? 'Unknown';
                return [
                    'subject' => "[BackBork KISS] CRON HEALTH ALERT - {$hostname}",
                    'body' => "BackBork KISS - Cron Health Alert\n\n" .
                              "Server: {$hostname}\n" .
                              "Time: {$timestamp}\n" .
                              "Issue: {$issue}\n" .
                              "Last Successful Run: {$lastRun}\n\n" .
                              "Please check your cron configuration and BackBork logs.",
                    'color' => '#dc2626',  // Red - health alert
                    'emoji' => '🚨'
                ];
                
            // ================================================================
            // QUEUE EVENTS
            // ================================================================
                
            case 'queue_failure':
                $accounts = is_array($data['accounts']) ? implode(', ', $data['accounts']) : $data['accounts'];
                $errors = is_array($data['errors']) ? implode("\n", $data['errors']) : $data['errors'];
                return [
                    'subject' => "[BackBork KISS] Queue Processing FAILED - {$hostname}",
                    'body' => "BackBork KISS - Queue Processing Failed\n\n" .
                              "Server: {$hostname}\n" .
                              "Time: {$timestamp}\n" .
                              "Affected Accounts: {$accounts}\n\n" .
                              "Errors:\n{$errors}",
                    'color' => '#f97316',  // Orange - queue failure
                    'emoji' => '⚠️'
                ];
                
            case 'pruning':
                $count = $data['pruned_count'] ?? 0;
                $details = is_array($data['details']) ? implode("\n", $data['details']) : ($data['details'] ?? '');
                return [
                    'subject' => "[BackBork KISS] Backup Pruning Complete - {$hostname}",
                    'body' => "BackBork KISS - Backup Pruning Complete\n\n" .
                              "Server: {$hostname}\n" .
                              "Time: {$timestamp}\n" .
                              "Backups Removed: {$count}\n\n" .
                              ($details ? "Details:\n{$details}" : ""),
                    'color' => '#8b5cf6',  // Purple - pruning
                    'emoji' => '🗑️'
                ];
                
            // ================================================================
            // TEST & DEFAULT
            // ================================================================
                
            case 'test':
                // Test notification to verify settings are correct
                return [
                    'subject' => "[BackBork KISS] Test Notification - {$hostname}",
                    'body' => "BackBork KISS - Test Notification\n\n" .
                              "Server: {$hostname}\n" .
                              "Time: {$timestamp}\n\n" .
                              "If you received this, your notifications are configured correctly!",
                    'color' => '#6366f1',  // Purple - test
                    'emoji' => '🧪'
                ];
                
            default:
                // Generic fallback for unknown event types
                return [
                    'subject' => "[BackBork KISS] Notification - {$hostname}",
                    'body' => "BackBork KISS - Notification\n\n" .
                              "Server: {$hostname}\n" .
                              "Time: {$timestamp}\n" .
                              "Event: {$event}\n" .
                              "Data: " . json_encode($data),
                    'color' => '#5a5f66',  // Gray - generic
                    'emoji' => 'ℹ️'
                ];
        }
    }
    
    // ========================================================================
    // EMAIL NOTIFICATIONS
    // ========================================================================
    
    /**
     * Send email notification
     * 
     * Uses PHP's mail() function which integrates with WHM's sendmail.
     * 
     * @param string $to Recipient email address
     * @param string $subject Email subject line
     * @param string $body Plain text email body
     * @return array Result with success status and message
     */
    public function sendEmail($to, $subject, $body) {
        // Validate email address format
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid email address'];
        }
        
        // Build email headers
        $headers = [
            'From: backbork@' . gethostname(),  // From address using server hostname
            'X-Mailer: BackBork KISS',          // Identify the mailer
            'Content-Type: text/plain; charset=UTF-8'  // Plain text UTF-8
        ];
        
        // Send via PHP mail() - uses server's MTA (sendmail/postfix)
        $result = mail($to, $subject, $body, implode("\r\n", $headers));
        
        return [
            'success' => $result,
            'message' => $result ? 'Email sent successfully' : 'Failed to send email'
        ];
    }
    
    // ========================================================================
    // SLACK NOTIFICATIONS
    // ========================================================================
    
    /**
     * Send Slack webhook notification
     * 
     * Posts a formatted message to Slack using incoming webhooks.
     * Includes color-coded attachment for visual distinction.
     * 
     * @param string $webhookUrl Slack incoming webhook URL
     * @param array $message Message data with subject, body, color, emoji
     * @return array Result with success status and message
     */
    public function sendSlack($webhookUrl, $message) {
        // Validate webhook URL format (must be Slack hooks domain)
        if (!preg_match('/^https:\/\/hooks\.slack\.com\//', $webhookUrl)) {
            return ['success' => false, 'message' => 'Invalid Slack webhook URL'];
        }
        
        $hostname = gethostname();
        
        // Build Slack message payload with attachment for formatting
        $payload = [
            'username' => 'BackBork KISS',       // Bot display name
            'icon_emoji' => ':shield:',          // Bot icon
            'attachments' => [
                [
                    'fallback' => $message['subject'],  // Plain text fallback
                    'color' => isset($message['color']) ? $message['color'] : '#3498db',  // Side bar color
                    'pretext' => (isset($message['emoji']) ? $message['emoji'] . ' ' : '') . $message['subject'],  // Pre-attachment text
                    'text' => $message['body'],         // Main message body
                    'footer' => 'BackBork KISS | ' . $hostname,  // Footer text
                    'ts' => time()                      // Timestamp
                ]
            ]
        ];
        
        // Send via cURL
        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,                                    // POST request
            CURLOPT_POSTFIELDS => json_encode($payload),             // JSON payload
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], // JSON content type
            CURLOPT_RETURNTRANSFER => true,                          // Return response
            CURLOPT_TIMEOUT => 10,                                   // 10 second timeout
            CURLOPT_SSL_VERIFYPEER => true                           // Verify SSL (security)
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        // Slack returns "ok" on success with HTTP 200
        if ($httpCode === 200 && $response === 'ok') {
            return ['success' => true, 'message' => 'Slack notification sent'];
        }
        
        // Return error details for troubleshooting
        return [
            'success' => false,
            'message' => 'Failed to send Slack notification: ' . ($error ?: $response)
        ];
    }
    
    // ========================================================================
    // TEST NOTIFICATIONS
    // ========================================================================
    
    /**
     * Send a test notification to verify configuration
     * 
     * Used by the settings page to test email/Slack setup.
     * 
     * @param string $type Notification type: 'email' or 'slack'
     * @param array $config User configuration with notification settings
     * @return array Result with success status and message
     */
    public function testNotification($type, $config) {
        // Build test message
        $message = $this->buildMessage('test', []);
        
        // Route based on notification type
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
}
