<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/
 *
 *  THIS FILE:
 *   Manifest management for tracking backup files created by schedules.
 *   Enables schedule-aware pruning without guessing file associations.
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
 * Manifest handler for tracking backup file associations.
 * 
 * Each schedule has a manifest file: {schedule_id}.json
 * Manual backups use: _manual.json (never pruned automatically)
 * 
 * Manifest structure:
 * {
 *   "schedule_id": "abc123",
 *   "retention": 30,
 *   "destination": "dest_id",
 *   "entries": [
 *     {
 *       "account": "username",
 *       "file": "backup-01.15.2025_02-00-00_username.tar.gz",
 *       "db_file": "db-01.15.2025_02-00-00_username.tar.gz",
 *       "timestamp": "2025-01-15T02:00:00Z",
 *       "size": 123456
 *     }
 *   ]
 * }
 */
class BackBorkManifest {
    
    /** @var string Directory for manifest files */
    const MANIFEST_DIR = '/usr/local/cpanel/3rdparty/backbork/manifests';
    
    /** @var string Manifest ID for manual (non-scheduled) backups */
    const MANUAL_MANIFEST_ID = '_manual';
    
    /**
     * Ensure manifest directory exists.
     */
    public function __construct() {
        if (!is_dir(self::MANIFEST_DIR)) {
            mkdir(self::MANIFEST_DIR, 0700, true);
        }
    }
    
    /**
     * Get the path to a manifest file.
     * 
     * @param string $scheduleID Schedule ID or '_manual' for manual backups
     * @return string Full path to manifest file
     */
    public function getManifestPath($scheduleID) {
        return self::MANIFEST_DIR . '/' . $scheduleID . '.json';
    }
    
    /**
     * Load a manifest file.
     * 
     * @param string $scheduleID Schedule ID
     * @return array Manifest data or empty structure if not found
     */
    public function loadManifest($scheduleID) {
        $path = $this->getManifestPath($scheduleID);
        
        if (!file_exists($path)) {
            return [
                'schedule_id' => $scheduleID,
                'retention' => ($scheduleID === self::MANUAL_MANIFEST_ID) ? 0 : 30,
                'destination' => null,
                'entries' => []
            ];
        }
        
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) {
            return [
                'schedule_id' => $scheduleID,
                'retention' => ($scheduleID === self::MANUAL_MANIFEST_ID) ? 0 : 30,
                'destination' => null,
                'entries' => []
            ];
        }
        
        return $data;
    }
    
    /**
     * Save a manifest file.
     * 
     * @param string $scheduleID Schedule ID
     * @param array $manifest Manifest data
     * @return bool Success
     */
    public function saveManifest($scheduleID, $manifest) {
        $path = $this->getManifestPath($scheduleID);
        $result = file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT));
        
        if ($result !== false) {
            chmod($path, 0600);
            return true;
        }
        
        return false;
    }
    
    /**
     * Add a backup entry to a manifest.
     * 
     * @param string $scheduleID Schedule ID (or '_manual' for manual backups)
     * @param string $account Account username
     * @param string $file Primary backup filename
     * @param string|null $dbFile Database backup filename (optional)
     * @param int $size File size in bytes
     * @param string $destination Destination ID
     * @param int $retention Retention count (0 = unlimited)
     * @return bool Success
     */
    public function addEntry($scheduleID, $account, $file, $dbFile, $size, $destination, $retention = 30) {
        $manifest = $this->loadManifest($scheduleID);
        
        // Update manifest metadata
        $manifest['destination'] = $destination;
        $manifest['retention'] = $retention;
        
        // Add new entry
        $manifest['entries'][] = [
            'account' => $account,
            'file' => $file,
            'db_file' => $dbFile,
            'timestamp' => date('c'),
            'size' => $size
        ];
        
        return $this->saveManifest($scheduleID, $manifest);
    }
    
    /**
     * Remove entries from a manifest.
     * Used after pruning files to keep manifest in sync.
     * 
     * @param string $scheduleID Schedule ID
     * @param array $filesToRemove List of filenames to remove
     * @return bool Success
     */
    public function removeEntries($scheduleID, $filesToRemove) {
        $manifest = $this->loadManifest($scheduleID);
        
        $manifest['entries'] = array_values(array_filter($manifest['entries'], function($entry) use ($filesToRemove) {
            return !in_array($entry['file'], $filesToRemove, true);
        }));
        
        return $this->saveManifest($scheduleID, $manifest);
    }
    
    /**
     * Get all entries for a specific account from a manifest.
     * 
     * @param string $scheduleID Schedule ID
     * @param string $account Account username
     * @return array Array of entries for this account
     */
    public function getEntriesForAccount($scheduleID, $account) {
        $manifest = $this->loadManifest($scheduleID);
        
        return array_filter($manifest['entries'], function($entry) use ($account) {
            return $entry['account'] === $account;
        });
    }
    
    /**
     * Get entries that exceed retention count for a specific account.
     * Returns oldest entries first (those to be pruned).
     * 
     * @param string $scheduleID Schedule ID
     * @param string $account Account username
     * @param int $retentionCount Number of backups to keep
     * @return array Array of entries to prune
     */
    public function getExpiredEntries($scheduleID, $account, $retentionCount) {
        // Manual backups never expire
        if ($scheduleID === self::MANUAL_MANIFEST_ID) {
            return [];
        }
        
        // Unlimited retention
        if ($retentionCount <= 0) {
            return [];
        }
        
        $entries = array_values($this->getEntriesForAccount($scheduleID, $account));
        
        // Sort by timestamp (oldest first)
        usort($entries, function($a, $b) {
            return strcmp($a['timestamp'], $b['timestamp']);
        });
        
        // Keep the newest $retentionCount, return the rest
        $toKeep = $retentionCount;
        $total = count($entries);
        
        if ($total <= $toKeep) {
            return [];
        }
        
        return array_slice($entries, 0, $total - $toKeep);
    }
    
    /**
     * List all manifest IDs (schedule IDs with manifests).
     * 
     * @return array Array of schedule IDs
     */
    public function listManifests() {
        $files = glob(self::MANIFEST_DIR . '/*.json');
        $ids = [];
        
        foreach ($files as $file) {
            $ids[] = basename($file, '.json');
        }
        
        return $ids;
    }
    
    /**
     * Check if a manifest exists for a schedule.
     * 
     * @param string $scheduleID Schedule ID
     * @return bool True if manifest exists
     */
    public function hasManifest($scheduleID) {
        return file_exists($this->getManifestPath($scheduleID));
    }
    
    /**
     * Delete a manifest file (when schedule is deleted).
     * 
     * @param string $scheduleID Schedule ID
     * @return bool Success
     */
    public function deleteManifest($scheduleID) {
        $path = $this->getManifestPath($scheduleID);
        
        if (file_exists($path)) {
            return unlink($path);
        }
        
        return true;
    }
}
