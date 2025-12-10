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

/**
 * Parser for WHM backup destination configurations.
 * Reads destination settings from WHM's backup configuration files.
 * Supports both modern destination files and legacy configuration formats.
 */
class BackBorkDestinationsParser {
    
    // Directory containing individual destination configuration files
    const TRANSPORT_CONFIG_DIR = '/var/cpanel/backups/destinations';
    
    // Legacy backup configuration file path
    const BACKUP_CONFIG_FILE = '/var/cpanel/backups/config';
    
    /**
     * Get all available backup destinations from WHM configuration.
     * Combines destinations from config directory, legacy config, and ensures local option.
     * 
     * @return array Array with 'destinations' key containing list of destination configs
     */
    public function getAvailableDestinations() {
        $destinations = [];
        
        // Read destinations from the WHM backup destinations directory
        if (is_dir(self::TRANSPORT_CONFIG_DIR)) {
            $files = glob(self::TRANSPORT_CONFIG_DIR . '/*');
            
            // Parse each destination file
            foreach ($files as $file) {
                if (is_file($file)) {
                    $dest = $this->parseDestinationFile($file);
                    if ($dest) {
                        $destinations[] = $dest;
                    }
                }
            }
        }
        
        // Also check for legacy FTP/SFTP configuration format
        $legacyDestinations = $this->parseLegacyConfig();
        $destinations = array_merge($destinations, $legacyDestinations);
        
        // Always include local storage option
        $hasLocal = false;
        foreach ($destinations as $dest) {
            if ($dest['id'] === 'local' || $dest['type'] === 'Local') {
                $hasLocal = true;
                break;
            }
        }
        
        // Add local storage if not already present
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
     * Parse a single destination configuration file.
     * Supports both YAML-like and key=value formats.
     * 
     * @param string $file Absolute path to destination config file
     * @return array|null Parsed destination config or null if invalid
     */
    public function parseDestinationFile($file) {
        $content = file_get_contents($file);
        $config = [];
        
        // Parse configuration lines
        $lines = explode("\n", $content);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // Skip empty lines and comments
            if (empty($line) || strpos($line, '#') === 0) continue;
            
            // Handle YAML format: key: value
            if (preg_match('/^(\w+):\s*(.*)$/', $line, $matches)) {
                $config[strtolower($matches[1])] = trim($matches[2], '"\'');
            }
            // Handle key=value format
            elseif (preg_match('/^(\w+)=(.*)$/', $line, $matches)) {
                $config[strtolower($matches[1])] = trim($matches[2], '"\'');
            }
        }
        
        // Return null if no valid config found
        if (empty($config)) {
            return null;
        }
        
        // Build destination array with defaults
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
     * Parse legacy backup configuration file for FTP/SFTP destinations.
     * Handles older WHM backup config format.
     * 
     * @return array List of parsed legacy destinations
     */
    private function parseLegacyConfig() {
        $destinations = [];
        
        // Skip if legacy config doesn't exist
        if (!file_exists(self::BACKUP_CONFIG_FILE)) {
            return $destinations;
        }
        
        $content = file_get_contents(self::BACKUP_CONFIG_FILE);
        $config = [];
        
        // Parse the legacy config file (YAML-like format)
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) continue;
            
            if (preg_match('/^(\w+):\s*(.*)$/', $line, $matches)) {
                $config[strtolower($matches[1])] = trim($matches[2], '"\'');
            }
        }
        
        // Check for FTP configuration in legacy format
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
     * Get a destination by its ID.
     * Also matches by name for flexibility.
     * 
     * @param string $id Destination ID or name
     * @return array|null Destination config or null if not found
     */
    public function getDestinationById($id) {
        $all = $this->getAvailableDestinations();
        
        // Search by ID or name
        foreach ($all['destinations'] as $dest) {
            if ($dest['id'] === $id || $dest['name'] === $id) {
                return $dest;
            }
        }
        
        return null;
    }
    
    /**
     * Get the display name for a destination.
     * Returns ID if destination not found.
     * 
     * @param string $id Destination ID
     * @return string Destination name or ID
     */
    public function getDestinationName($id) {
        $dest = $this->getDestinationById($id);
        return $dest ? $dest['name'] : $id;
    }
    
    /**
     * Check if a destination exists.
     * 
     * @param string $id Destination ID
     * @return bool True if destination exists
     */
    public function destinationExists($id) {
        return $this->getDestinationById($id) !== null;
    }
    
    /**
     * Get the WHM destinations directory path.
     * Static method for external access to config location.
     * 
     * @return string Path to destinations directory
     */
    public static function getDestinationsDir() {
        return self::TRANSPORT_CONFIG_DIR;
    }
}
