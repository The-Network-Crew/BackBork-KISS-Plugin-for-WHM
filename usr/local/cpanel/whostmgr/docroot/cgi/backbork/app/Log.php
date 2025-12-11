<?php
/**
 * BackBork KISS - Logging Helper
 * 
 * Centralized logging system for all BackBork operations and events.
 * Provides structured JSON logging with support for:
 * - Operation tracking (backups, restores, queue actions)
 * - User activity auditing
 * - Requestor (IP address) tracking
 * - Paginated log retrieval with filtering
 * 
 * Log Format (JSON per line):
 * {
 *   "timestamp": "2024-01-15 14:30:00",
 *   "user": "root",
 *   "type": "backup",
 *   "items": ["account1", "account2"],
 *   "success": true,
 *   "message": "Backup completed",
 *   "requestor": "192.168.1.100"
 * }
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

// Ensure version constant is available
if (!defined('BACKBORK_VERSION')) {
    require_once(__DIR__ . '/../version.php');
}

/**
 * Class BackBorkLog
 * 
 * Static logging class providing centralized operation logging.
 * All methods are static for easy access throughout the application.
 */
class BackBorkLog {
    
    // ========================================================================
    // CONSTANTS
    // ========================================================================
    
    /** Directory for log storage */
    const LOG_DIR = '/usr/local/cpanel/3rdparty/backbork/logs';
    
    /** Main operations log file (JSON lines format) */
    const LOG_FILE = '/usr/local/cpanel/3rdparty/backbork/logs/operations.log';
    
    // ========================================================================
    // REQUESTOR DETECTION
    // ========================================================================

    /**
     * Get the requestor identifier (source of the request)
     * 
     * Determines who/what initiated the request:
     * - IP address for web requests (handles proxies)
     * - 'cron' for CLI/scheduled execution
     * - 'local' as fallback
     * 
     * @return string Requestor identifier
     */
    public static function getRequestor() {
        // Check for forwarded IP (request behind proxy/load balancer)
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // X-Forwarded-For can contain multiple IPs; use the first (client)
            return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        
        // Direct remote IP address
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            return $_SERVER['REMOTE_ADDR'];
        }
        
        // CLI mode indicates cron job execution
        if (php_sapi_name() === 'cli' || !isset($_SERVER['REQUEST_METHOD'])) {
            return 'cron';
        }
        
        // Fallback for unknown source
        return 'local';
    }
    
    // ========================================================================
    // LOG WRITING
    // ========================================================================

    /**
     * Log an operation event
     * 
     * Writes a structured JSON log entry for auditing and troubleshooting.
     * Each entry is appended as a single line (JSON Lines format).
     * 
     * @param string $user Username performing the action
     * @param string $type Event type (backup, restore, queue_add, schedule_create, etc.)
     * @param array|mixed $items Affected items (accounts, job IDs, etc.)
     * @param bool $success Whether the operation succeeded
     * @param string $message Human-readable description
     * @param string $requestor Source IP/identifier (auto-detected if empty)
     */
    public static function logEvent($user, $type, $items = [], $success = true, $message = '', $requestor = '') {
        // Ensure log directory exists
        if (!is_dir(self::LOG_DIR)) {
            mkdir(self::LOG_DIR, 0750, true);
        }

        // Auto-detect requestor if not provided
        if (empty($requestor)) {
            $requestor = self::getRequestor();
        }

        // Build structured log entry
        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),           // When the event occurred
            'user'      => $user,                          // Who performed the action
            'type'      => $type,                          // Event type for filtering
            'items'     => is_array($items) ? $items : [$items],  // Affected items
            'success'   => (bool)$success,                 // Success/failure status
            'message'   => $message,                       // Human-readable message
            'requestor' => $requestor                      // Source IP or 'cron'
        ];

        // Write log entry as JSON line with file locking
        $line = json_encode($entry) . "\n";
        $written = @file_put_contents(self::LOG_FILE, $line, FILE_APPEND | LOCK_EX);
        
        if ($written === false) {
            // Log write failed - use debug log as fallback (only if debug enabled)
            BackBorkConfig::debugLog('Failed to write operations log. Fallback: ' . json_encode($entry));
        } else {
            // Set readable permissions (owner write, world read)
            @chmod(self::LOG_FILE, 0644);
        }
    }
    
    // ========================================================================
    // LOG RETRIEVAL
    // ========================================================================

    /**
     * Retrieve operation logs with pagination and filtering
     * 
     * Reads the operations log and returns structured results.
     * Non-root users only see their own log entries.
     * 
     * @param string $user Username requesting logs
     * @param bool $isRoot Whether user has root privileges
     * @param int $page Page number (1-indexed)
     * @param int $limit Items per page
     * @param string $filter Filter type: 'all', 'error', 'backup', 'restore'
     * @return array Result with 'logs', 'total_pages', 'current_page'
     */
    public static function getLogs($user, $isRoot, $page = 1, $limit = 50, $filter = 'all') {
        $logFile = self::LOG_FILE;
        $logs = [];

        // No log file yet
        if (!file_exists($logFile)) {
            return ['logs' => [], 'total_pages' => 0, 'current_page' => 1];
        }

        // Read all log lines and reverse for newest-first display
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_reverse($lines);

        // Process each log entry
        foreach ($lines as $line) {
            // Parse JSON entry
            $entry = json_decode($line, true);
            if (!$entry) continue;

            // Security: Non-root users can only see their own logs
            if (!$isRoot && isset($entry['user']) && $entry['user'] !== $user) {
                continue;
            }

            // Extract status and type for filtering
            $status = (isset($entry['success']) && $entry['success']) ? 'success' : 'error';
            $type = $entry['type'] ?? 'event';

            // Apply filter
            if ($filter !== 'all') {
                if ($filter === 'error' && $status !== 'error') continue;
                if ($filter === 'backup' && $type !== 'backup') continue;
                if ($filter === 'restore' && $type !== 'restore') continue;
            }

            // Format items list for display
            $items = $entry['items'] ?? [];
            $account = is_array($items) ? implode(', ', $items) : $items;

            // Build display-friendly log entry
            $logs[] = [
                'timestamp' => $entry['timestamp'] ?? '',
                'type' => $type,
                'account' => $account,
                'user' => $entry['user'] ?? '',
                'requestor' => $entry['requestor'] ?? 'N/A',
                'status' => $status,
                'message' => $entry['message'] ?? ''
            ];
        }

        // Calculate pagination
        $totalPages = ceil(count($logs) / $limit);
        $offset = ($page - 1) * $limit;
        $pagedLogs = array_slice($logs, $offset, $limit);

        return [
            'logs' => $pagedLogs,
            'total_pages' => $totalPages,
            'current_page' => $page
        ];
    }
}
