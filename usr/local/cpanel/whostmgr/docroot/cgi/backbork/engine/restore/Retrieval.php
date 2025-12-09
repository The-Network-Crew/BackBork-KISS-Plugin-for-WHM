<?php
/**
 * BackBork KISS - Backup Retrieval
 * Retrieves backup files from destinations for restore
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

class BackBorkRetrieval {
    
    const TEMP_DIR = '/home/backbork_tmp';
    
    /** @var BackBorkDestinationsParser */
    private $destinations;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->destinations = new BackBorkDestinationsParser();
    }
    
    /**
     * Retrieve backup file from destination
     * 
     * @param string $destinationId Destination ID
     * @param string $backupFile Backup file path at destination
     * @param string $localPath Local path to save to (optional)
     * @return array Result with local file path
     */
    public function retrieveBackup($destinationId, $backupFile, $localPath = null) {
        $destination = $this->destinations->getDestinationById($destinationId);
        
        if (!$destination) {
            return ['success' => false, 'message' => 'Destination not found'];
        }
        
        // For local destinations, just return the path
        if (strtolower($destination['type']) === 'local') {
            $fullPath = rtrim($destination['path'], '/') . '/' . ltrim($backupFile, '/');
            
            if (!file_exists($fullPath)) {
                return ['success' => false, 'message' => 'Backup file not found: ' . $fullPath];
            }
            
            return [
                'success' => true,
                'local_path' => $fullPath,
                'size' => filesize($fullPath)
            ];
        }
        
        // For remote destinations, download the file
        $tempDir = self::TEMP_DIR;
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0700, true);
        }
        
        if (!$localPath) {
            $localPath = $tempDir . '/' . basename($backupFile);
        }
        
        $validator = new BackBorkDestinationsValidator();
        $transport = $validator->getTransportForDestination($destination);
        
        $result = $transport->download($backupFile, $localPath, $destination);
        
        if ($result['success']) {
            $result['local_path'] = $localPath;
            $result['size'] = file_exists($localPath) ? filesize($localPath) : 0;
        }
        
        return $result;
    }
    
    /**
     * List available backups from destination
     * 
     * @param string $destinationId Destination ID
     * @param string $accountFilter Optional account filter
     * @return array
     */
    public function listAvailableBackups($destinationId, $accountFilter = null) {
        $destination = $this->destinations->getDestinationById($destinationId);
        
        if (!$destination) {
            return ['success' => false, 'backups' => [], 'message' => 'Destination not found'];
        }
        
        $validator = new BackBorkDestinationsValidator();
        $transport = $validator->getTransportForDestination($destination);
        
        // Get listing
        $path = $accountFilter ? $accountFilter : '';
        $files = $transport->listFiles($path, $destination);
        
        // Parse backup files
        $backups = [];
        foreach ($files as $file) {
            $filename = $file['file'];
            
            // Skip non-backup files
            if (!preg_match('/cpmove-([a-z0-9_]+)/i', $filename, $matches)) {
                continue;
            }
            
            $account = $matches[1];
            
            // Parse timestamp if present
            $timestamp = null;
            if (preg_match('/_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})/', $filename, $tsMatch)) {
                $timestamp = str_replace(['_', '-'], [' ', ':'], $tsMatch[1]);
                $timestamp = substr_replace($timestamp, '-', 10, 1);
                $timestamp = substr_replace($timestamp, '-', 7, 1);
            }
            
            $backups[] = [
                'file' => $filename,
                'path' => $file['path'] ?? $filename,
                'account' => $account,
                'size' => $file['size'] ?? 0,
                'date' => $timestamp ?? ($file['date'] ?? 'Unknown'),
                'destination' => $destinationId
            ];
        }
        
        // Sort by date descending
        usort($backups, function($a, $b) {
            return strcmp($b['date'], $a['date']);
        });
        
        return ['success' => true, 'backups' => $backups];
    }
    
    /**
     * Find local WHM backup location
     * 
     * @return string|null
     */
    public function findLocalBackupDirectory() {
        $possiblePaths = [
            '/backup',
            '/backup/cpbackup',
            '/backup/daily',
            '/backup/weekly',
            '/backup/monthly',
            '/home/backup'
        ];
        
        foreach ($possiblePaths as $path) {
            if (is_dir($path)) {
                return $path;
            }
        }
        
        return null;
    }
    
    /**
     * Find backups from WHM's standard backup locations
     * 
     * @param string $account Account username
     * @return array
     */
    public function findCpanelBackups($account) {
        $backups = [];
        
        $locations = [
            '/backup/cpbackup/daily',
            '/backup/cpbackup/weekly',
            '/backup/cpbackup/monthly',
            '/backup/daily',
            '/backup/weekly',
            '/backup/monthly'
        ];
        
        foreach ($locations as $location) {
            if (!is_dir($location)) continue;
            
            // Check for account directory
            $accountDir = $location . '/' . $account;
            if (is_dir($accountDir)) {
                $files = glob($accountDir . '/*.tar.gz');
                foreach ($files as $file) {
                    $backups[] = [
                        'file' => basename($file),
                        'path' => $file,
                        'account' => $account,
                        'size' => filesize($file),
                        'date' => date('Y-m-d H:i:s', filemtime($file)),
                        'source' => basename($location),
                        'destination' => 'local'
                    ];
                }
            }
            
            // Check for cpmove files directly
            $pattern = $location . '/cpmove-' . $account . '*';
            $files = glob($pattern);
            foreach ($files as $file) {
                if (is_file($file)) {
                    $backups[] = [
                        'file' => basename($file),
                        'path' => $file,
                        'account' => $account,
                        'size' => filesize($file),
                        'date' => date('Y-m-d H:i:s', filemtime($file)),
                        'source' => basename($location),
                        'destination' => 'local'
                    ];
                }
            }
        }
        
        // Sort by date descending
        usort($backups, function($a, $b) {
            return strcmp($b['date'], $a['date']);
        });
        
        return $backups;
    }
    
    /**
     * Verify retrieved backup file
     * 
     * @param string $localPath Local path to backup file
     * @return array
     */
    public function verifyBackupFile($localPath) {
        if (!file_exists($localPath)) {
            return ['valid' => false, 'message' => 'File not found'];
        }
        
        // Check file extension
        if (!preg_match('/\.(tar\.gz|tgz)$/i', $localPath)) {
            return ['valid' => false, 'message' => 'Invalid file format - expected .tar.gz'];
        }
        
        // Check if it's a valid tar.gz
        $output = [];
        $returnCode = 0;
        exec('tar -tzf ' . escapeshellarg($localPath) . ' > /dev/null 2>&1', $output, $returnCode);
        
        if ($returnCode !== 0) {
            return ['valid' => false, 'message' => 'File appears to be corrupted'];
        }
        
        // Check for expected cpmove structure
        $output = [];
        exec('tar -tzf ' . escapeshellarg($localPath) . ' 2>/dev/null | head -20', $output);
        
        $hasCpmoveDir = false;
        $hasHomedir = false;
        
        foreach ($output as $line) {
            if (strpos($line, 'cpmove-') === 0) $hasCpmoveDir = true;
            if (strpos($line, 'homedir') !== false) $hasHomedir = true;
        }
        
        if (!$hasCpmoveDir && !$hasHomedir) {
            return ['valid' => false, 'message' => 'Not a valid WHM backup format'];
        }
        
        return [
            'valid' => true,
            'message' => 'Backup file verified',
            'size' => filesize($localPath)
        ];
    }
    
    /**
     * Clean up temporary downloaded files
     * 
     * @param int $olderThanHours Delete files older than this many hours
     * @return int Number of files deleted
     */
    public function cleanupTempFiles($olderThanHours = 24) {
        $tempDir = self::TEMP_DIR;
        
        if (!is_dir($tempDir)) {
            return 0;
        }
        
        $deleted = 0;
        $cutoff = time() - ($olderThanHours * 3600);
        
        $files = glob($tempDir . '/*');
        
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                if (unlink($file)) {
                    $deleted++;
                }
            }
        }
        
        return $deleted;
    }
}
