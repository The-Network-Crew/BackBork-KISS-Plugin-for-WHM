<?php
/**
 * BackBork KISS - Native Transport
 * Uses WHM's cpbackup_transport_file for SFTP/FTP transfers
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
 * Native transport implementation using WHM's cpbackup_transport_file.
 * Handles SFTP and FTP transfers via cPanel's built-in transport system.
 * Leverages existing WHM destination configurations for authentication.
 */
class BackBorkTransportNative implements BackBorkTransportInterface {
    
    // Path to cPanel's native backup transport binary
    const TRANSPORT_BIN = '/scripts/cpbackup_transport_file';
    
    /**
     * Upload a file using cpbackup_transport_file.
     * Files are placed in manual_backup/ subdirectory at destination.
     * 
     * @param string $localPath Absolute path to local file
     * @param string $remotePath Remote destination path (often ignored by cpbackup_transport)
     * @param array $destination Destination configuration from WHM
     * @return array Result with success status, file info, and remote path
     */
    public function upload($localPath, $remotePath, $destination) {
        // Verify local file exists before attempting upload
        if (!file_exists($localPath)) {
            return [
                'success' => false,
                'message' => "Local file not found: {$localPath}"
            ];
        }
        
        // Verify transport binary is available
        if (!file_exists(self::TRANSPORT_BIN)) {
            return [
                'success' => false,
                'message' => 'Transport binary not found at ' . self::TRANSPORT_BIN
            ];
        }
        
        // Build cpbackup_transport_file command
        // Syntax: cpbackup_transport_file --transport <id> --upload </full/path/to/file>
        $transportCmd = self::TRANSPORT_BIN . 
                        ' --transport ' . escapeshellarg($destination['id']) .
                        ' --upload ' . escapeshellarg($localPath);
        
        // Execute transport command and capture output
        $output = [];
        $returnCode = 0;
        exec($transportCmd . ' 2>&1', $output, $returnCode);
        
        $transportOutput = implode("\n", $output);
        
        // Check for execution failure
        if ($returnCode !== 0) {
            return [
                'success' => false,
                'message' => "Transport failed (exit code {$returnCode}): " . $transportOutput,
                'file' => basename($localPath),
                'command' => $transportCmd
            ];
        }
        
        // cpbackup_transport places files in manual_backup/ subdirectory
        $actualRemotePath = 'manual_backup/' . basename($localPath);
        
        return [
            'success' => true,
            'message' => "Backup uploaded to: {$destination['name']}/{$actualRemotePath}",
            'file' => basename($localPath),
            'remote_path' => $actualRemotePath,
            'size' => filesize($localPath)
        ];
    }
    
    /**
     * Download a file using cpbackup_transport_file.
     * Retrieves backup from remote destination to local path.
     * 
     * NOTE: cpbackup_transport stores uploads in manual_backup/ subdirectory.
     * This method expects remotePath to be just the filename (not including manual_backup/).
     * 
     * @param string $remotePath Filename or path at remote destination
     * @param string $localPath Local path to save downloaded file
     * @param array $destination Destination configuration from WHM
     * @return array Result with success status and local path
     */
    public function download($remotePath, $localPath, $destination) {
        // Verify transport binary is available
        if (!file_exists(self::TRANSPORT_BIN)) {
            return [
                'success' => false,
                'message' => 'Transport binary not found at ' . self::TRANSPORT_BIN
            ];
        }
        
        // Ensure local directory exists for download
        $localDir = dirname($localPath);
        if (!is_dir($localDir)) {
            mkdir($localDir, 0700, true);
        }
        
        // cpbackup_transport stores files in manual_backup/ subdirectory
        // Prepend this path if not already present
        $downloadPath = $remotePath;
        if (strpos($remotePath, 'manual_backup/') !== 0) {
            $downloadPath = 'manual_backup/' . ltrim($remotePath, '/');
        }
        
        // Build download command
        // Syntax: cpbackup_transport_file --transport <id> --download <remote_path> --download-to <local_path>
        $transportCmd = self::TRANSPORT_BIN .
                        ' --transport ' . escapeshellarg($destination['id']) .
                        ' --download ' . escapeshellarg($downloadPath) .
                        ' --download-to ' . escapeshellarg($localPath);
        
        // Execute download command
        $output = [];
        $returnCode = 0;
        exec($transportCmd . ' 2>&1', $output, $returnCode);
        
        // Verify download succeeded and file exists
        if ($returnCode === 0 && file_exists($localPath)) {
            return [
                'success' => true,
                'message' => "Downloaded to: {$localPath}",
                'local_path' => $localPath,
                'size' => filesize($localPath)
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Download failed: ' . implode("\n", $output)
        ];
    }
    
    /**
     * List files at remote destination.
     * 
     * NOT SUPPORTED for Native transport - cpbackup_transport_file only
     * supports upload and download operations, not listing.
     * 
     * @param string $remotePath Path to list (relative to destination base)
     * @param array $destination Destination configuration from WHM
     * @return array Empty array - listing not supported
     */
    public function listFiles($remotePath, $destination) {
        // cpbackup_transport_file does not support listing files
        // Return empty array - caller should handle gracefully
        return [];
    }
    
    /**
     * Check if a file exists at the destination.
     * 
     * NOT SUPPORTED for Native transport - cpbackup_transport_file only
     * supports upload and download operations.
     * 
     * @param string $remotePath Path to file (relative to destination base)
     * @param array $destination Destination configuration from WHM
     * @return bool Always false - existence check not supported
     */
    public function fileExists($remotePath, $destination) {
        // cpbackup_transport_file does not support file existence checks
        return false;
    }
    
    /**
     * Delete a file from the destination.
     * 
     * NOT SUPPORTED for Native transport - cpbackup_transport_file only
     * supports upload and download operations, not deletion.
     * 
     * @param string $remotePath Path to file (relative to destination base)
     * @param array $destination Destination configuration from WHM
     * @return array Result indicating operation not supported
     */
    public function delete($remotePath, $destination) {
        // cpbackup_transport_file does not support file deletion
        return [
            'success' => false,
            'message' => 'Delete not supported for remote destinations via cpbackup_transport'
        ];
    }
    
    /**
     * Test connection to destination.
     * 
     * Uses WHM's backup_cmd to validate the transport destination.
     * 
     * @param array $destination Destination configuration from WHM
     * @return array Result with success status and connection message
     */
    public function testConnection($destination) {
        // Verify destination has required fields
        if (empty($destination['id'])) {
            return [
                'success' => false,
                'message' => 'Destination ID is required'
            ];
        }
        
        // Use WHM's backup_cmd to validate the transport
        $cmd = '/usr/local/cpanel/bin/backup_cmd id=' . escapeshellarg($destination['id']) . ' disableonfail=0';
        
        $output = [];
        $returnCode = 0;
        exec($cmd . ' 2>&1', $output, $returnCode);
        
        if ($returnCode === 0) {
            return [
                'success' => true,
                'message' => 'Transport validated successfully'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Transport validation failed: ' . implode(' ', $output)
        ];
    }
}
