<?php
/**
 * BackBork KISS - Notification Handler
 * Email and Slack webhook notifications
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
    
    const TEMPLATES_DIR = '/usr/local/cpanel/whostmgr/docroot/cgi/backbork/templates/notifications';
    
    /**
     * Send notification based on event type
     * 
     * @param string $event Event type
     * @param array $data Event data
     * @param array $config User configuration
     * @return array Results
     */
    public function sendNotification($event, $data, $config) {
        $results = [];
        
        $message = $this->buildMessage($event, $data);
        
        // Send email if configured
        if (!empty($config['notify_email'])) {
            $results['email'] = $this->sendEmail($config['notify_email'], $message['subject'], $message['body']);
        }
        
        // Send Slack if configured
        if (!empty($config['slack_webhook'])) {
            $results['slack'] = $this->sendSlack($config['slack_webhook'], $message);
        }
        
        return $results;
    }
    
    /**
     * Build notification message
     * Tries to load from template file first, falls back to inline
     * 
     * @param string $event Event type
     * @param array $data Event data
     * @return array Message with subject and body
     */
    private function buildMessage($event, $data) {
        $hostname = gethostname();
        $timestamp = date('Y-m-d H:i:s');
        
        // Try to load from template
        $templateFile = self::TEMPLATES_DIR . '/' . $event . '.json';
        if (file_exists($templateFile)) {
            $template = json_decode(file_get_contents($templateFile), true);
            if ($template) {
                return $this->applyTemplate($template, $data, $hostname, $timestamp);
            }
        }
        
        // Fallback to inline messages
        return $this->buildInlineMessage($event, $data, $hostname, $timestamp);
    }
    
    /**
     * Apply template with variable substitution
     * 
     * @param array $template Template data
     * @param array $data Event data
     * @param string $hostname Server hostname
     * @param string $timestamp Current timestamp
     * @return array
     */
    private function applyTemplate($template, $data, $hostname, $timestamp) {
        $vars = array_merge($data, [
            'hostname' => $hostname,
            'timestamp' => $timestamp,
            'accounts' => is_array($data['accounts'] ?? null) ? implode(', ', $data['accounts']) : ($data['accounts'] ?? ''),
            'errors' => is_array($data['errors'] ?? null) ? implode("\n", $data['errors']) : ($data['errors'] ?? '')
        ]);
        
        $subject = $template['subject'] ?? '';
        $body = $template['body'] ?? '';
        
        foreach ($vars as $key => $value) {
            if (is_string($value)) {
                $subject = str_replace('{{' . $key . '}}', $value, $subject);
                $body = str_replace('{{' . $key . '}}', $value, $body);
            }
        }
        
        return [
            'subject' => $subject,
            'body' => $body,
            'color' => $template['color'] ?? '#3498db',
            'emoji' => $template['emoji'] ?? 'ℹ️'
        ];
    }
    
    /**
     * Build inline message (fallback)
     * 
     * @param string $event Event type
     * @param array $data Event data
     * @param string $hostname Server hostname
     * @param string $timestamp Current timestamp
     * @return array
     */
    private function buildInlineMessage($event, $data, $hostname, $timestamp) {
        switch ($event) {
            case 'backup_start':
                $accounts = is_array($data['accounts']) ? implode(', ', $data['accounts']) : $data['accounts'];
                return [
                    'subject' => "[BackBork] Backup Started - {$hostname}",
                    'body' => "Backup job started.\n\n" .
                              "Server: {$hostname}\n" .
                              "Time: {$timestamp}\n" .
                              "User: {$data['user']}\n" .
                              "Accounts: {$accounts}\n" .
                              "Destination: {$data['destination']}",
                    'color' => '#3498db',
                    'emoji' => '🔄'
                ];
                
            case 'backup_success':
                $accounts = is_array($data['accounts']) ? implode(', ', $data['accounts']) : $data['accounts'];
                return [
                    'subject' => "[BackBork] Backup Completed - {$hostname}",
                    'body' => "Backup job completed successfully.\n\n" .
                              "Server: {$hostname}\n" .
                              "Time: {$timestamp}\n" .
                              "User: {$data['user']}\n" .
                              "Accounts: {$accounts}\n" .
                              "Destination: {$data['destination']}",
                    'color' => '#059669',
                    'emoji' => '✅'
                ];
                
            case 'backup_failure':
                $accounts = is_array($data['accounts']) ? implode(', ', $data['accounts']) : $data['accounts'];
                $errors = is_array($data['errors']) ? implode("\n", $data['errors']) : $data['errors'];
                return [
                    'subject' => "[BackBork] Backup FAILED - {$hostname}",
                    'body' => "Backup job failed!\n\n" .
                              "Server: {$hostname}\n" .
                              "Time: {$timestamp}\n" .
                              "User: {$data['user']}\n" .
                              "Accounts: {$accounts}\n" .
                              "Destination: {$data['destination']}\n\n" .
                              "Errors:\n{$errors}",
                    'color' => '#e74c3c',
                    'emoji' => '❌'
                ];
                
            case 'restore_start':
                return [
                    'subject' => "[BackBork] Restore Started - {$hostname}",
                    'body' => "Restore job started.\n\n" .
                              "Server: {$hostname}\n" .
                              "Time: {$timestamp}\n" .
                              "User: {$data['user']}\n" .
                              "Account: {$data['account']}\n" .
                              "Backup File: {$data['backup_file']}\n" .
                              "Source: {$data['destination']}",
                    'color' => '#2563eb',
                    'emoji' => '🔄'
                ];
                
            case 'restore_success':
                return [
                    'subject' => "[BackBork] Restore Completed - {$hostname}",
                    'body' => "Restore job completed successfully.\n\n" .
                              "Server: {$hostname}\n" .
                              "Time: {$timestamp}\n" .
                              "User: {$data['user']}\n" .
                              "Account: {$data['account']}\n" .
                              "Backup File: {$data['backup_file']}",
                    'color' => '#059669',
                    'emoji' => '✅'
                ];
                
            case 'restore_failure':
                return [
                    'subject' => "[BackBork] Restore FAILED - {$hostname}",
                    'body' => "Restore job failed!\n\n" .
                              "Server: {$hostname}\n" .
                              "Time: {$timestamp}\n" .
                              "User: {$data['user']}\n" .
                              "Account: {$data['account']}\n" .
                              "Backup File: {$data['backup_file']}\n\n" .
                              "Error: {$data['error']}",
                    'color' => '#e74c3c',
                    'emoji' => '❌'
                ];
                
            case 'test':
                return [
                    'subject' => "[BackBork] Test Notification - {$hostname}",
                    'body' => "This is a test notification from BackBork KISS.\n\n" .
                              "Server: {$hostname}\n" .
                              "Time: {$timestamp}\n\n" .
                              "If you received this, your notifications are configured correctly!",
                    'color' => '#6366f1',
                    'emoji' => '🧪'
                ];
                
            default:
                return [
                    'subject' => "[BackBork] Notification - {$hostname}",
                    'body' => "BackBork notification.\n\n" .
                              "Server: {$hostname}\n" .
                              "Time: {$timestamp}\n" .
                              "Event: {$event}\n" .
                              "Data: " . json_encode($data),
                    'color' => '#5a5f66',
                    'emoji' => 'ℹ️'
                ];
        }
    }
    
    /**
     * Send email notification
     * 
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $body Email body
     * @return array Result
     */
    public function sendEmail($to, $subject, $body) {
        // Validate email
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Invalid email address'];
        }
        
        // Use WHM's sendmail
        $headers = [
            'From: backbork@' . gethostname(),
            'X-Mailer: BackBork KISS',
            'Content-Type: text/plain; charset=UTF-8'
        ];
        
        $result = mail($to, $subject, $body, implode("\r\n", $headers));
        
        return [
            'success' => $result,
            'message' => $result ? 'Email sent successfully' : 'Failed to send email'
        ];
    }
    
    /**
     * Send Slack webhook notification
     * 
     * @param string $webhookUrl Slack webhook URL
     * @param array $message Message data
     * @return array Result
     */
    public function sendSlack($webhookUrl, $message) {
        // Validate webhook URL
        if (!preg_match('/^https:\/\/hooks\.slack\.com\//', $webhookUrl)) {
            return ['success' => false, 'message' => 'Invalid Slack webhook URL'];
        }
        
        $hostname = gethostname();
        
        // Build Slack message with attachment for formatting
        $payload = [
            'username' => 'BackBork KISS',
            'icon_emoji' => ':shield:',
            'attachments' => [
                [
                    'fallback' => $message['subject'],
                    'color' => isset($message['color']) ? $message['color'] : '#3498db',
                    'pretext' => (isset($message['emoji']) ? $message['emoji'] . ' ' : '') . $message['subject'],
                    'text' => $message['body'],
                    'footer' => 'BackBork KISS | ' . $hostname,
                    'ts' => time()
                ]
            ]
        ];
        
        // Send via curl
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
            'message' => 'Failed to send Slack notification: ' . ($error ?: $response)
        ];
    }
    
    /**
     * Test notification
     * 
     * @param string $type Notification type (email or slack)
     * @param array $config User configuration
     * @return array Result
     */
    public function testNotification($type, $config) {
        $message = $this->buildMessage('test', []);
        
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
