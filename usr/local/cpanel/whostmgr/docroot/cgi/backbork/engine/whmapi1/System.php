<?php
/**
 * BackBork KISS - WHM API System Handler
 * Wrapper for WHM API system-related functions
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

class BackBorkWhmApiSystem {
    
    const WHMAPI_BIN = '/usr/local/cpanel/bin/whmapi1';
    const MARIADB_BACKUP_BIN = '/usr/bin/mariadb-backup';
    const MYSQL_BACKUP_BIN = '/usr/bin/mysqlbackup';
    
    /**
     * Detect MySQL/MariaDB server type and version
     * 
     * @return array Server info with type and version
     */
    public function detectDatabaseServer() {
        $result = [
            'type' => 'unknown',
            'version' => '',
            'full_version' => '',
            'mariadb_backup_available' => false,
            'mysqlbackup_available' => false
        ];
        
        // Try to get version from mysql command
        $output = [];
        exec('mysql --version 2>/dev/null', $output);
        
        if (!empty($output[0])) {
            $versionStr = $output[0];
            $result['full_version'] = $versionStr;
            
            // Check if MariaDB
            if (stripos($versionStr, 'mariadb') !== false) {
                $result['type'] = 'MariaDB';
                if (preg_match('/(\d+\.\d+\.\d+)-MariaDB/i', $versionStr, $matches)) {
                    $result['version'] = $matches[1];
                }
            } else {
                $result['type'] = 'MySQL';
                if (preg_match('/Distrib\s+(\d+\.\d+\.\d+)/i', $versionStr, $matches)) {
                    $result['version'] = $matches[1];
                }
            }
        }
        
        // Check for backup tools availability
        $result['mariadb_backup_available'] = file_exists(self::MARIADB_BACKUP_BIN) || 
            !empty(shell_exec('which mariadb-backup 2>/dev/null'));
        $result['mysqlbackup_available'] = file_exists(self::MYSQL_BACKUP_BIN) || 
            !empty(shell_exec('which mysqlbackup 2>/dev/null'));
        
        // Also check for mariabackup (older name)
        if (!$result['mariadb_backup_available']) {
            $result['mariadb_backup_available'] = !empty(shell_exec('which mariabackup 2>/dev/null'));
        }
        
        return $result;
    }
    
    /**
     * Get WHM version information
     * 
     * @return array
     */
    public function getCpanelVersion() {
        $output = shell_exec(self::WHMAPI_BIN . ' version --output=json');
        $data = json_decode($output, true);
        
        return [
            'version' => $data['data']['version'] ?? 'unknown'
        ];
    }
    
    /**
     * Check if a binary/tool exists and is executable
     * 
     * @param string $path Path to binary
     * @return bool
     */
    public function binaryExists($path) {
        return file_exists($path) && is_executable($path);
    }
    
    /**
     * Get server hostname
     * 
     * @return string
     */
    public function getHostname() {
        return gethostname() ?: 'unknown';
    }
    
    /**
     * Check disk space at a path
     * 
     * @param string $path Path to check
     * @return array
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
}
