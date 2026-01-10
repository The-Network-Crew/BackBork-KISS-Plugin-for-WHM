<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/
 *
 *  THIS FILE:
 *   Parser for WHM backup destination configurations.
 *   Reads SFTP/FTP/Local destinations from WHM Backup Configuration API.
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
 * Parser for WHM backup destination configurations.
 * Uses WHM API (backup_destination_list) to get destinations.
 */
class BackBorkDestinationsParser {
    
    /**
     * Get all available backup destinations from WHM API.
     * Uses backup_destination_list API for accurate destination info.
     * Filters out root-only destinations for non-root users.
     * 
     * @param bool $isRoot Whether the requesting user is root (default true for backwards compat)
     * @return array Array with 'destinations' key containing list of destination configs
     */
    public function getAvailableDestinations($isRoot = true) {
        // Get destinations from WHM API
        $destinations = $this->getDestinationsFromApi();
        
        // Always include local storage option
        $hasLocal = false;
        foreach ($destinations as $dest) {
            if ($dest['id'] === 'local' || strtolower($dest['type']) === 'local') {
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
        
        // Filter out root-only destinations for non-root users
        if (!$isRoot) {
            $destinations = $this->filterRootOnlyDestinations($destinations);
        }
        
        return ['destinations' => $destinations];
    }
    
    /**
     * Get destinations using WHM API backup_destination_list.
     * 
     * @return array List of destinations from API
     */
    private function getDestinationsFromApi() {
        $destinations = [];
        
        // Use whmapi1 CLI to get destinations
        $cmd = '/usr/local/cpanel/bin/whmapi1 --output=json backup_destination_list 2>/dev/null';
        $output = shell_exec($cmd);
        
        if (empty($output)) {
            return $destinations;
        }
        
        $result = json_decode($output, true);
        
        // Check for valid response - API returns data.destination_list
        if (!isset($result['data']['destination_list']) || !is_array($result['data']['destination_list'])) {
            return $destinations;
        }
        
        // Parse each destination
        foreach ($result['data']['destination_list'] as $dest) {
            $type = isset($dest['type']) ? $dest['type'] : 'Unknown';
            $id = isset($dest['id']) ? $dest['id'] : (isset($dest['name']) ? $dest['name'] : '');
            
            if (empty($id)) continue;
            
            $destinations[] = [
                'id' => $id,
                'name' => isset($dest['name']) ? $dest['name'] : $id,
                'type' => $type,
                'host' => isset($dest['host']) ? $dest['host'] : '',
                'port' => isset($dest['port']) ? (int)$dest['port'] : ($type === 'SFTP' ? 22 : 21),
                'path' => isset($dest['path']) ? $dest['path'] : '/',
                'enabled' => isset($dest['disabled']) ? !$dest['disabled'] : true,
                'timeout' => isset($dest['timeout']) ? (int)$dest['timeout'] : 30
            ];
        }
        
        return $destinations;
    }
    
    /**
     * Filter out destinations marked as root-only.
     * 
     * @param array $destinations List of all destinations
     * @return array Filtered list excluding root-only destinations
     */
    private function filterRootOnlyDestinations($destinations) {
        // Get the list of root-only destination IDs from global config
        $config = new BackBorkConfig();
        $globalConfig = $config->getGlobalConfig();
        $rootOnlyIDs = $globalConfig['root_only_destinations'] ?? [];
        
        // If no restrictions, return all
        if (empty($rootOnlyIDs)) {
            return $destinations;
        }
        
        // Filter out destinations that are marked root-only
        return array_values(array_filter($destinations, function($dest) use ($rootOnlyIDs) {
            return !in_array($dest['id'], $rootOnlyIDs, true);
        }));
    }
    
    /**
     * Get a destination by its ID.
     * Also matches by name for flexibility.
     * 
     * @param string $id Destination ID or name
     * @return array|null Destination config or null if not found
     */
    public function getDestinationByID($id) {
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
        $dest = $this->getDestinationByID($id);
        return $dest ? $dest['name'] : $id;
    }
    
    /**
     * Check if a destination exists.
     * 
     * @param string $id Destination ID
     * @return bool True if destination exists
     */
    public function destinationExists($id) {
        return $this->getDestinationByID($id) !== null;
    }
}
