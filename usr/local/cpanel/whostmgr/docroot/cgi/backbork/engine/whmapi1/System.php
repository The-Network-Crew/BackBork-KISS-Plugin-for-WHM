<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-Plugin-for-WHM/
 *
 *  THIS FILE:
 *   WHM API wrapper for system-level information.
 *   Provides database detection, version info, and system utilities.
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

/**
 * WHM API wrapper for system-level information.
 * Provides database detection, version info, and system utilities.
 */
class BackBorkWhmApiSystem {
    
    // Path to WHM API command-line tool
    const WHMAPI_BIN = '/usr/local/cpanel/bin/whmapi1';
    
    // Paths to database backup tools
    const MARIADB_BACKUP_BIN = '/usr/bin/mariadb-backup';
    const MYSQL_BACKUP_BIN = '/usr/bin/mysqlbackup';
    
    /**
     * Detect MySQL/MariaDB server type and version.
     * Also checks for availability of database backup tools.
     * 
     * @return array Server info with type, version, and tool availability
     */
    public function detectDatabaseServer() {
        $result = [
            'type' => 'unknown',
            'version' => '',
            'full_version' => '',
            'mariadb_backup_available' => false,
            'mysqlbackup_available' => false
        ];
        
        // Get version string from mysql command
        $output = [];
        exec('mysql --version 2>/dev/null', $output);
        
        if (!empty($output[0])) {
            $versionStr = $output[0];
            $result['full_version'] = $versionStr;
            
            // Check if MariaDB (contains "mariadb" in version string)
            if (stripos($versionStr, 'mariadb') !== false) {
                $result['type'] = 'MariaDB';
                // Extract version number (e.g., "10.6.12")
                if (preg_match('/(\d+\.\d+\.\d+)-MariaDB/i', $versionStr, $matches)) {
                    $result['version'] = $matches[1];
                }
            } else {
                $result['type'] = 'MySQL';
                // Extract MySQL version from Distrib string
                if (preg_match('/Distrib\s+(\d+\.\d+\.\d+)/i', $versionStr, $matches)) {
                    $result['version'] = $matches[1];
                }
            }
        }
        
        // Check for mariadb-backup tool availability
        $result['mariadb_backup_available'] = file_exists(self::MARIADB_BACKUP_BIN) || 
            !empty(shell_exec('which mariadb-backup 2>/dev/null'));
        
        // Check for mysqlbackup tool availability
        $result['mysqlbackup_available'] = file_exists(self::MYSQL_BACKUP_BIN) || 
            !empty(shell_exec('which mysqlbackup 2>/dev/null'));
        
        // Also check for mariabackup (older MariaDB tool name)
        if (!$result['mariadb_backup_available']) {
            $result['mariadb_backup_available'] = !empty(shell_exec('which mariabackup 2>/dev/null'));
        }
        
        return $result;
    }
    
    /**
     * Get cPanel/WHM version information.
     * 
     * @return array Version info array with 'version' key
     */
    public function getCpanelVersion() {
        $output = shell_exec(self::WHMAPI_BIN . ' version --output=json');
        $data = json_decode($output, true);
        
        return [
            'version' => $data['data']['version'] ?? 'unknown'
        ];
    }
    
    /**
     * Check if a binary/tool exists and is executable.
     * 
     * @param string $path Absolute path to binary
     * @return bool True if file exists and is executable
     */
    public function binaryExists($path) {
        return file_exists($path) && is_executable($path);
    }
    
    /**
     * Get server hostname.
     * 
     * @return string Server hostname or 'unknown'
     */
    public function getHostname() {
        return gethostname() ?: 'unknown';
    }
    
    /**
     * Check disk space at a specific path.
     * Returns free, total, used space and percentage.
     * 
     * @param string $path Path to check disk space for
     * @return array Disk space info with bytes and percentage
     */
    public function checkDiskSpace($path) {
        $free = disk_free_space($path);
        $total = disk_total_space($path);
        
        return [
            'path' => $path,
            'free' => $free,
            'total' => $total,
            'used' => $total - $free,
            'percent_used' => $total > 0 ? round((($total - $free) / $total) * 100, 2) : 0
        ];
    }
    
    /**
     * Get list of reseller accounts on the server.
     * Uses whmapi1 listresellers command.
     * 
     * @return array Array with 'resellers' (list) and 'count' (int)
     */
    public function getResellers() {
        $output = shell_exec(self::WHMAPI_BIN . ' listresellers --output=json 2>/dev/null');
        $data = json_decode($output, true);
        
        $resellers = [];
        if (isset($data['data']['reseller']) && is_array($data['data']['reseller'])) {
            $resellers = $data['data']['reseller'];
        }
        
        return [
            'resellers' => $resellers,
            'count' => count($resellers)
        ];
    }
}
