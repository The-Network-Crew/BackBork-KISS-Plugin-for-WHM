<?php
/**
 * BackBork KISS - Local Transport
 * Handles local filesystem backup storage
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

class BackBorkTransportLocal implements BackBorkTransportInterface {
    
    /**
     * Upload (copy) a file to local destination
     * 
     * @param string $localPath Source file path
     * @param string $remotePath Destination path (relative to destination base)
     * @param array $destination Destination configuration
     * @return array Result
     */
    public function upload($localPath, $remotePath, $destination) {
        if (!file_exists($localPath)) {
            return [
                'success' => false,
                'message' => "Source file not found: {$localPath}"
            ];
        }
        
        $basePath = $destination['path'] ?? '/backup';
        $destDir = rtrim($basePath, '/') . '/' . ltrim(dirname($remotePath), '/');
        $destFile = $destDir . '/' . basename($localPath);
        
        // Create destination directory if needed
        if (!is_dir($destDir)) {
            if (!mkdir($destDir, 0700, true)) {
                return [
                    'success' => false,
                    'message' => "Failed to create directory: {$destDir}"
                ];
            }
        }
        
        // Copy file
        if (copy($localPath, $destFile)) {
            chmod($destFile, 0600);
            return [
                'success' => true,
                'message' => "Backup saved to: {$destFile}",
                'file' => basename($localPath),
                'remote_path' => $destFile,
                'size' => filesize($destFile)
            ];
        }
        
        return [
            'success' => false,
            'message' => "Failed to copy backup to: {$destFile}"
        ];
    }
    
    /**
     * Download (copy) a file from local destination
     * 
     * @param string $remotePath Source path
     * @param string $localPath Destination path
     * @param array $destination Destination configuration
     * @return array Result
     */
    public function download($remotePath, $localPath, $destination) {
        $basePath = $destination['path'] ?? '/backup';
        $sourcePath = rtrim($basePath, '/') . '/' . ltrim($remotePath, '/');
        
        if (!file_exists($sourcePath)) {
            return [
                'success' => false,
                'message' => "Source file not found: {$sourcePath}"
            ];
        }
        
        // Create local directory if needed
        $localDir = dirname($localPath);
        if (!is_dir($localDir)) {
            mkdir($localDir, 0700, true);
        }
        
        if (copy($sourcePath, $localPath)) {
            return [
                'success' => true,
                'message' => "Copied to: {$localPath}",
                'local_path' => $localPath,
                'size' => filesize($localPath)
            ];
        }
        
        return [
            'success' => false,
            'message' => "Failed to copy file"
        ];
    }
    
    /**
     * List backup files at local destination
     * 
     * @param string $remotePath Path to list (relative to base)
     * @param array $destination Destination configuration
     * @return array List of files
     */
    public function listFiles($remotePath, $destination) {
        $files = [];
        $basePath = $destination['path'] ?? '/backup';
        $searchPath = rtrim($basePath, '/');
        
        if (!empty($remotePath)) {
            $searchPath .= '/' . ltrim($remotePath, '/');
        }
        
        // Search for backup files
        $patterns = [
            $searchPath . '/cpmove-*.tar.gz',
            $searchPath . '/*/cpmove-*.tar.gz',
            $searchPath . '/*/*/cpmove-*.tar.gz'
        ];
        
        foreach ($patterns as $pattern) {
            $found = glob($pattern);
            foreach ($found as $file) {
                $files[] = [
                    'file' => basename($file),
                    'path' => $file,
                    'size' => filesize($file),
                    'mtime' => filemtime($file),
                    'date' => date('Y-m-d H:i:s', filemtime($file)),
                    'location' => 'local'
                ];
            }
        }
        
        // Sort by date descending
        usort($files, function($a, $b) {
            return $b['mtime'] - $a['mtime'];
        });
        
        return $files;
    }
    
    /**
     * Delete a file from local destination
     * 
     * @param string $remotePath File path (relative to base)
     * @param array $destination Destination configuration
     * @return array Result
     */
    public function delete($remotePath, $destination) {
        $basePath = $destination['path'] ?? '/backup';
        $filePath = rtrim($basePath, '/') . '/' . ltrim($remotePath, '/');
        
        if (!file_exists($filePath)) {
            return ['success' => false, 'message' => 'File not found'];
        }
        
        if (unlink($filePath)) {
            return ['success' => true, 'message' => 'File deleted'];
        }
        
        return ['success' => false, 'message' => 'Failed to delete file'];
    }
    
    /**
     * Test local destination (check path is writable)
     * 
     * @param array $destination Destination configuration
     * @return array Result
     */
    public function testConnection($destination) {
        $path = $destination['path'] ?? '/backup';
        
        if (!is_dir($path)) {
            return ['success' => false, 'message' => "Path does not exist: {$path}"];
        }
        
        if (!is_writable($path)) {
            return ['success' => false, 'message' => "Path not writable: {$path}"];
        }
        
        return ['success' => true, 'message' => 'Local storage accessible'];
    }
    
    /**
     * Find backup files for a specific account
     * 
     * @param string $account Account username
     * @param array $destination Destination configuration
     * @return array List of backups
     */
    public function findAccountBackups($account, $destination) {
        $backups = [];
        $basePath = $destination['path'] ?? '/backup';
        
        // Common backup locations
        $searchDirs = [
            $basePath,
            $basePath . '/cpbackup',
            $basePath . '/cpbackup/daily/' . $account,
            $basePath . '/cpbackup/weekly/' . $account,
            $basePath . '/cpbackup/monthly/' . $account,
            $basePath . '/' . $account
        ];
        
        foreach ($searchDirs as $dir) {
            if (!is_dir($dir)) continue;
            
            // Look for cpmove files
            $pattern = $dir . '/cpmove-' . $account . '*.tar.gz';
            $files = glob($pattern);
            
            foreach ($files as $file) {
                $backups[] = [
                    'file' => basename($file),
                    'path' => $file,
                    'size' => filesize($file),
                    'date' => date('Y-m-d H:i:s', filemtime($file)),
                    'location' => 'local'
                ];
            }
        }
        
        // Remove duplicates and sort
        $unique = [];
        foreach ($backups as $backup) {
            $unique[$backup['path']] = $backup;
        }
        $backups = array_values($unique);
        
        usort($backups, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });
        
        return $backups;
    }
}
