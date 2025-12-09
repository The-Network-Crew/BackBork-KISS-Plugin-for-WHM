<?php
/**
 * BackBork KISS - Logging helper
 * Centralized log writer for operations and events
 */

if (!defined('BACKBORK_VERSION')) {
    // The bootstrap defines BACKBORK_VERSION; if not present, include version for default
    require_once(__DIR__ . '/../version.php');
}

class BackBorkLog {
    const LOG_DIR = '/usr/local/cpanel/3rdparty/backbork/logs';
    const LOG_FILE = '/usr/local/cpanel/3rdparty/backbork/logs/operations.log';

    /**
     * Get the requestor identifier (IP address, 'cron', or 'local')
     * 
     * @return string
     */
    public static function getRequestor() {
        // Check for forwarded IP (behind proxy/load balancer)
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        
        // Direct remote IP
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            return $_SERVER['REMOTE_ADDR'];
        }
        
        // CLI mode (cron job)
        if (php_sapi_name() === 'cli' || !isset($_SERVER['REQUEST_METHOD'])) {
            return 'cron';
        }
        
        return 'local';
    }

    public static function logEvent($user, $type, $items = [], $success = true, $message = '', $requestor = '') {
        if (!is_dir(self::LOG_DIR)) {
            mkdir(self::LOG_DIR, 0750, true);
        }

        // If no requestor was provided, auto-detect it
        if (empty($requestor)) {
            $requestor = self::getRequestor();
        }

        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'user'      => $user,
            'type'      => $type,
            'items'     => is_array($items) ? $items : [$items],
            'success'   => (bool)$success,
            'message'   => $message,
            'requestor' => $requestor
        ];

        $line = json_encode($entry) . "\n";
        $written = @file_put_contents(self::LOG_FILE, $line, FILE_APPEND | LOCK_EX);
        if ($written === false) {
            // Failed to write to the operations log; fallback to PHP error log for troubleshooting
            error_log('[BackBork] Failed to write operations log. Fallback: ' . json_encode($entry));
        } else {
            // Attempt to set permissive read for readability (keep write restricted to owner)
            @chmod(self::LOG_FILE, 0644);
        }
    }

    /**
     * Read the operations log and return structured results with pagination
     *
     * @param string $user User requesting logs
     * @param bool $isRoot Whether user is root
     * @param int $page Page number
     * @param int $limit Items per page
     * @param string $filter Filter type
     * @return array
     */
    public static function getLogs($user, $isRoot, $page = 1, $limit = 50, $filter = 'all') {
        $logFile = self::LOG_FILE;
        $logs = [];

        if (!file_exists($logFile)) {
            return ['logs' => [], 'total_pages' => 0, 'current_page' => 1];
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_reverse($lines);

        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (!$entry) continue;

            // Filter by user if not root
            if (!$isRoot && isset($entry['user']) && $entry['user'] !== $user) {
                continue;
            }

            // Determine status and type from current log format
            $status = (isset($entry['success']) && $entry['success']) ? 'success' : 'error';
            $type = $entry['type'] ?? 'event';

            if ($filter !== 'all') {
                if ($filter === 'error' && $status !== 'error') continue;
                if ($filter === 'backup' && $type !== 'backup') continue;
                if ($filter === 'restore' && $type !== 'restore') continue;
            }

            $items = $entry['items'] ?? [];
            $account = is_array($items) ? implode(', ', $items) : $items;

            $logs[] = [
                'timestamp' => $entry['timestamp'] ?? '',
                'type' => $type,
                'account' => $account,
                'user' => $entry['user'] ?? '',
                'requestor' => $entry['requestor'] ?? 'N/A',
                'status' => $status,
                'message' => isset($entry['message']) ? substr($entry['message'], 0, 200) : ''
            ];
        }

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
