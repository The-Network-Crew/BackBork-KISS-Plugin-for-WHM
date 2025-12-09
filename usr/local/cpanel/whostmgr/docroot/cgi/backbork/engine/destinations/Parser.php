<?php
/**
 * BackBork KISS - Destinations Parser
 * Reads SFTP destinations from WHM Backup Configuration
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

class BackBorkDestinationsParser {
    
    // WHM stores backup destinations in these locations
    const TRANSPORT_CONFIG_DIR = '/var/cpanel/backups/destinations';
    const BACKUP_CONFIG_FILE = '/var/cpanel/backups/config';
    
    /**
     * Get available backup destinations from WHM configuration
     * 
     * @return array
     */
    public function getAvailableDestinations() {
        $destinations = [];
        
        // Read destinations from the WHM backup destinations directory
        if (is_dir(self::TRANSPORT_CONFIG_DIR)) {
            $files = glob(self::TRANSPORT_CONFIG_DIR . '/*');
            
            foreach ($files as $file) {
                if (is_file($file)) {
                    $dest = $this->parseDestinationFile($file);
                    if ($dest) {
                        $destinations[] = $dest;
                    }
                }
            }
        }
        
        // Also check for legacy configuration format
        $legacyDestinations = $this->parseLegacyConfig();
        $destinations = array_merge($destinations, $legacyDestinations);
        
        // Always include local option
        $hasLocal = false;
        foreach ($destinations as $dest) {
            if ($dest['id'] === 'local' || $dest['type'] === 'Local') {
                $hasLocal = true;
                break;
            }
        }
        
        if (!$hasLocal) {
            array_unshift($destinations, [
                'id' => 'local',
                'name' => 'Local Storage',
                'type' => 'Local',
                'path' => '/backup',
                'enabled' => true
            ]);
        }
        
        return ['destinations' => $destinations];
    }
    
    /**
     * Parse a destination configuration file
     * 
     * @param string $file File path
     * @return array|null
     */
    public function parseDestinationFile($file) {
        $content = file_get_contents($file);
        $config = [];
        
        // Parse YAML-like or key=value format
        $lines = explode("\n", $content);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) continue;
            
            // Handle YAML format
            if (preg_match('/^(\w+):\s*(.*)$/', $line, $matches)) {
                $config[strtolower($matches[1])] = trim($matches[2], '"\'');
            }
            // Handle key=value format
            elseif (preg_match('/^(\w+)=(.*)$/', $line, $matches)) {
                $config[strtolower($matches[1])] = trim($matches[2], '"\'');
            }
        }
        
        if (empty($config)) {
            return null;
        }
        
        $name = basename($file);
        $type = isset($config['type']) ? $config['type'] : 'Unknown';
        
        return [
            'id' => $name,
            'name' => isset($config['name']) ? $config['name'] : $name,
            'type' => $type,
            'host' => isset($config['host']) ? $config['host'] : '',
            'port' => isset($config['port']) ? (int)$config['port'] : ($type === 'SFTP' ? 22 : 21),
            'username' => isset($config['username']) ? $config['username'] : '',
            'path' => isset($config['path']) ? $config['path'] : (isset($config['directory']) ? $config['directory'] : '/'),
            'enabled' => isset($config['disabled']) ? !$config['disabled'] : true,
            'timeout' => isset($config['timeout']) ? (int)$config['timeout'] : 30
        ];
    }
    
    /**
     * Parse legacy backup configuration
     * 
     * @return array
     */
    private function parseLegacyConfig() {
        $destinations = [];
        
        if (!file_exists(self::BACKUP_CONFIG_FILE)) {
            return $destinations;
        }
        
        $content = file_get_contents(self::BACKUP_CONFIG_FILE);
        $config = [];
        
        // Parse the config file
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) continue;
            
            if (preg_match('/^(\w+):\s*(.*)$/', $line, $matches)) {
                $config[strtolower($matches[1])] = trim($matches[2], '"\'');
            }
        }
        
        // Check for FTP/SFTP configuration in legacy format
        if (!empty($config['ftphost'])) {
            $destinations[] = [
                'id' => 'legacy_ftp',
                'name' => 'FTP (' . $config['ftphost'] . ')',
                'type' => 'FTP',
                'host' => $config['ftphost'],
                'port' => isset($config['ftpport']) ? (int)$config['ftpport'] : 21,
                'username' => isset($config['ftpuser']) ? $config['ftpuser'] : '',
                'path' => isset($config['ftpdir']) ? $config['ftpdir'] : '/',
                'enabled' => true
            ];
        }
        
        return $destinations;
    }
    
    /**
     * Get destination by ID
     * 
     * @param string $id Destination ID
     * @return array|null
     */
    public function getDestinationById($id) {
        $all = $this->getAvailableDestinations();
        
        foreach ($all['destinations'] as $dest) {
            if ($dest['id'] === $id || $dest['name'] === $id) {
                return $dest;
            }
        }
        
        return null;
    }
    
    /**
     * Get destination name by ID
     * 
     * @param string $id Destination ID
     * @return string
     */
    public function getDestinationName($id) {
        $dest = $this->getDestinationById($id);
        return $dest ? $dest['name'] : $id;
    }
    
    /**
     * Check if a destination exists
     * 
     * @param string $id Destination ID
     * @return bool
     */
    public function destinationExists($id) {
        return $this->getDestinationById($id) !== null;
    }
    
    /**
     * Get destinations directory path
     * 
     * @return string
     */
    public static function getDestinationsDir() {
        return self::TRANSPORT_CONFIG_DIR;
    }
}
