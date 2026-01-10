<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/
 *
 *  THIS FILE:
 *   Local filesystem transport implementation for backup storage.
 *   Handles backup operations on local disk with account folder organization.
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
 * Local filesystem transport implementation.
 * Handles backup storage on local disk (/backup or custom paths).
 * Backups are organised in account folders: {base_path}/{account}/backup-MM.DD.YYYY_HH-MM-SS_{account}.tar.gz
 */
class BackBorkTransportLocal implements BackBorkTransportInterface {
    
    /**
     * Upload (copy) a file to local destination.
     * Creates destination directory if needed and copies file.
     * Files are stored in account folders: {base_path}/{account}/{filename}
     * 
     * @param string $localPath Source file absolute path
     * @param string $remotePath Destination path (e.g., "account/filename.tar.gz")
     * @param array $destination Destination configuration with 'path' key
     * @return array Result with success status and destination path
     */
    public function upload($localPath, $remotePath, $destination) {
        // Verify source file exists
        if (!file_exists($localPath)) {
            return [
                'success' => false,
                'message' => "Source file not found: {$localPath}"
            ];
        }
        
        // Build destination paths
        $basePath = $destination['path'] ?? '/backup';
        $destDir = rtrim($basePath, '/') . '/' . ltrim(dirname($remotePath), '/');
        $destFile = $destDir . '/' . basename($localPath);
        
        // Create destination directory if it doesn't exist
        if (!is_dir($destDir)) {
            if (!mkdir($destDir, 0700, true)) {
                return [
                    'success' => false,
                    'message' => "Failed to create directory: {$destDir}"
                ];
            }
        }
        
        // Copy file to destination with secure permissions
        if (copy($localPath, $destFile)) {
            chmod($destFile, 0600);  // Read/write owner only
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
     * Download (copy) a file from local destination.
     * Copies from destination storage to specified local path.
     * 
     * @param string $remotePath Source path (relative to destination base)
     * @param string $localPath Destination path for the copy
     * @param array $destination Destination configuration with 'path' key
     * @return array Result with success status and local path
     */
    public function download($remotePath, $localPath, $destination) {
        // Build full source path
        $basePath = $destination['path'] ?? '/backup';
        $sourcePath = rtrim($basePath, '/') . '/' . ltrim($remotePath, '/');
        
        // Verify source file exists
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
        
        // Copy file
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
     * List backup files at local destination.
     * When path is empty, lists account directories.
     * When path is an account name, lists backup-* files in that directory.
     * 
     * @param string $remotePath Path to list (relative to destination base)
     * @param array $destination Destination configuration with 'path' key
     * @return array List of file/directory info arrays
     */
    public function listFiles($remotePath, $destination) {
        $files = [];
        $basePath = $destination['path'] ?? '/backup';
        $searchPath = rtrim($basePath, '/');
        
        // Add remote path if specified
        if (!empty($remotePath)) {
            $searchPath .= '/' . ltrim($remotePath, '/');
        }
        
        // If no path specified (root listing), return account directories
        if (empty($remotePath)) {
            // List directories at base path (account folders)
            $dirs = glob($searchPath . '/*', GLOB_ONLYDIR);
            foreach ($dirs as $dir) {
                $dirname = basename($dir);
                // Skip hidden and system directories
                if (strpos($dirname, '.') === 0 || in_array($dirname, ['lost+found', 'tmp', 'temp', 'cpbackup'])) {
                    continue;
                }
                // Check if this directory contains any backups (official format)
                $hasBackups = !empty(glob($dir . '/backup-*.tar.gz'));
                if ($hasBackups) {
                    $files[] = [
                        'file' => $dirname,
                        'path' => $dir,
                        'size' => 0,
                        'type' => 'dir',
                        'mtime' => filemtime($dir),
                        'date' => date('Y-m-d H:i:s', filemtime($dir)),
                        'location' => 'local'
                    ];
                }
            }
            
            // Also check for flat backup files at root (fallback)
            $flatOfficialFiles = glob($searchPath . '/backup-*.tar.gz');
            foreach ($flatOfficialFiles as $file) {
                $files[] = [
                    'file' => basename($file),
                    'path' => $file,
                    'size' => filesize($file),
                    'type' => 'file',
                    'mtime' => filemtime($file),
                    'date' => date('Y-m-d H:i:s', filemtime($file)),
                    'location' => 'local'
                ];
            }
        } else {
            // Path specified - list backup files in that directory
            if (is_dir($searchPath)) {
                // Official format: backup-*.tar.gz
                $officialFound = glob($searchPath . '/backup-*.tar.gz');
                foreach ($officialFound as $file) {
                    $files[] = [
                        'file' => basename($file),
                        'path' => $file,
                        'size' => filesize($file),
                        'type' => 'file',
                        'mtime' => filemtime($file),
                        'date' => date('Y-m-d H:i:s', filemtime($file)),
                        'location' => 'local'
                    ];
                }
            }
        }
        
        // Sort by modification time descending (most recent first)
        usort($files, function($a, $b) {
            return $b['mtime'] - $a['mtime'];
        });
        
        return $files;
    }
    
    /**
     * Check if a file exists at the destination.
     * 
     * @param string $remotePath File path (relative to destination base)
     * @param array $destination Destination configuration with 'path' key
     * @return bool True if file exists
     */
    public function fileExists($remotePath, $destination) {
        $basePath = $destination['path'] ?? '/backup';
        $filePath = rtrim($basePath, '/') . '/' . ltrim($remotePath, '/');
        return file_exists($filePath);
    }
    
    /**
     * Delete a file from local destination.
     * Removes the specified backup file from storage.
     * 
     * @param string $remotePath File path (relative to destination base)
     * @param array $destination Destination configuration with 'path' key
     * @return array Result with success status and message
     */
    public function delete($remotePath, $destination) {
        // Build full file path
        $basePath = $destination['path'] ?? '/backup';
        $filePath = rtrim($basePath, '/') . '/' . ltrim($remotePath, '/');
        
        // Verify file exists
        if (!file_exists($filePath)) {
            return ['success' => false, 'message' => 'File not found'];
        }
        
        // Attempt deletion
        if (unlink($filePath)) {
            return ['success' => true, 'message' => 'File deleted'];
        }
        
        return ['success' => false, 'message' => 'Failed to delete file'];
    }
    
    /**
     * Test local destination accessibility.
     * Verifies path exists and is writable.
     * 
     * @param array $destination Destination configuration with 'path' key
     * @return array Result with success status and message
     */
    public function testConnection($destination) {
        $path = $destination['path'] ?? '/backup';
        
        // Check directory exists
        if (!is_dir($path)) {
            return ['success' => false, 'message' => "Path does not exist: {$path}"];
        }
        
        // Check directory is writable
        if (!is_writable($path)) {
            return ['success' => false, 'message' => "Path not writable: {$path}"];
        }
        
        return ['success' => true, 'message' => 'Local storage accessible'];
    }
    
    /**
     * Find backup files for a specific account.
     * Searches the account's dedicated folder.
     * 
     * @param string $account Account username to search for
     * @param array $destination Destination configuration with 'path' key
     * @return array List of backup files sorted by date descending
     */
    public function findAccountBackups($account, $destination) {
        $backups = [];
        $basePath = $destination['path'] ?? '/backup';
        
        // Primary location: {base_path}/{account}/
        $searchDirs = [
            $basePath . '/' . $account,              // BackBork: /backup/account/
            $basePath,                               // Flat files at /backup/
        ];
        
        // Search each directory for account backups
        foreach ($searchDirs as $dir) {
            if (!is_dir($dir)) continue;
            
            // Search for backup-*_{account}.tar.gz files
            $pattern = $dir . '/backup-*_' . $account . '.tar.gz';
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
        
        // Remove duplicates based on path
        $unique = [];
        foreach ($backups as $backup) {
            $unique[$backup['path']] = $backup;
        }
        $backups = array_values($unique);
        
        // Sort by date descending (most recent first)
        usort($backups, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });
        
        return $backups;
    }
}
