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
     * @param string $remotePath Path at remote destination
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
        
        // Build download command
        // Syntax: cpbackup_transport_file --transport <id> --download <remote_path> --download-to <local_path>
        $transportCmd = self::TRANSPORT_BIN .
                        ' --transport ' . escapeshellarg($destination['id']) .
                        ' --download ' . escapeshellarg($remotePath) .
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
     * Uses SSH for SFTP destinations to enumerate backup files.
     * 
     * @param string $remotePath Path to list (relative to destination base)
     * @param array $destination Destination configuration from WHM
     * @return array List of file info arrays with filename, size, and date
     */
    public function listFiles($remotePath, $destination) {
        $files = [];
        
        // Handle SFTP destinations via direct SSH connection
        if ($destination['type'] === 'SFTP' || $destination['type'] === 'sftp') {
            // Extract connection details from destination config
            $host = $destination['host'] ?? '';
            $port = $destination['port'] ?? 22;
            $username = $destination['username'] ?? '';
            $basePath = $destination['path'] ?? '/';
            
            // Validate required connection details
            if (empty($host) || empty($username)) {
                return $files;
            }
            
            // Build full path to list
            $fullPath = rtrim($basePath, '/') . '/' . ltrim($remotePath, '/');
            
            // Use find command to locate backup files and get metadata
            // Output format: filename|size|mtime
            $sshCmd = "ssh -p {$port} -o StrictHostKeyChecking=no -o BatchMode=yes " .
                      escapeshellarg("{$username}@{$host}") . " " .
                      "'find " . escapeshellarg($fullPath) . " -name \"cpmove-*.tar.gz\" -printf \"%f|%s|%T@\\n\" 2>/dev/null'";
            
            $output = [];
            exec($sshCmd, $output);
            
            // Parse find output into file info arrays
            foreach ($output as $line) {
                $parts = explode('|', $line);
                if (count($parts) >= 3) {
                    $files[] = [
                        'file' => $parts[0],
                        'size' => (int)$parts[1],
                        'mtime' => (int)$parts[2],
                        'date' => date('Y-m-d H:i:s', (int)$parts[2])
                    ];
                }
            }
        }
        
        return $files;
    }
    
    /**
     * Delete a file from the destination.
     * Uses SSH for SFTP destinations to remove files.
     * 
     * @param string $remotePath Path to file (relative to destination base)
     * @param array $destination Destination configuration from WHM
     * @return array Result with success status and message
     */
    public function delete($remotePath, $destination) {
        // Handle SFTP destinations via direct SSH connection
        if ($destination['type'] === 'SFTP' || $destination['type'] === 'sftp') {
            // Extract connection details
            $host = $destination['host'] ?? '';
            $port = $destination['port'] ?? 22;
            $username = $destination['username'] ?? '';
            $basePath = $destination['path'] ?? '/';
            
            // Validate required connection details
            if (empty($host) || empty($username)) {
                return ['success' => false, 'message' => 'Invalid destination configuration'];
            }
            
            // Build full path to delete
            $fullPath = rtrim($basePath, '/') . '/' . ltrim($remotePath, '/');
            
            // Execute rm command via SSH
            $sshCmd = "ssh -p {$port} -o StrictHostKeyChecking=no -o BatchMode=yes " .
                      escapeshellarg("{$username}@{$host}") . " " .
                      "'rm -f " . escapeshellarg($fullPath) . "'";
            
            $returnCode = 0;
            exec($sshCmd . ' 2>&1', $output, $returnCode);
            
            return [
                'success' => $returnCode === 0,
                'message' => $returnCode === 0 ? 'File deleted' : 'Delete failed: ' . implode("\n", $output)
            ];
        }
        
        return ['success' => false, 'message' => 'Delete not supported for this destination type'];
    }
    
    /**
     * Test connection to destination.
     * Verifies SFTP/FTP connectivity and authentication.
     * 
     * @param array $destination Destination configuration from WHM
     * @return array Result with success status and connection message
     */
    public function testConnection($destination) {
        // Test SFTP connection via SSH
        if ($destination['type'] === 'SFTP' || $destination['type'] === 'sftp') {
            $host = $destination['host'] ?? '';
            $port = $destination['port'] ?? 22;
            $username = $destination['username'] ?? '';
            
            // Build SSH test command with short timeout
            $cmd = "ssh -p {$port} -o StrictHostKeyChecking=no -o BatchMode=yes -o ConnectTimeout=10 " .
                   escapeshellarg("{$username}@{$host}") . " 'echo connected' 2>&1";
            
            $output = [];
            $returnCode = 0;
            exec($cmd, $output, $returnCode);
            
            // Check for successful connection
            if ($returnCode === 0 && in_array('connected', $output)) {
                return ['success' => true, 'message' => 'SFTP connection successful'];
            }
            
            return [
                'success' => false,
                'message' => 'SFTP connection failed: ' . implode(' ', $output)
            ];
        }
        
        // Test FTP connection via curl
        if ($destination['type'] === 'FTP' || $destination['type'] === 'ftp') {
            $host = $destination['host'] ?? '';
            $port = $destination['port'] ?? 21;
            
            // Use curl to test FTP connection
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => "ftp://{$host}:{$port}/",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FTP_USE_EPSV => true,
                CURLOPT_FTPLISTONLY => true
            ]);
            
            // Add credentials if provided
            if (!empty($destination['username'])) {
                curl_setopt($ch, CURLOPT_USERPWD, $destination['username'] . ':');
            }
            
            $result = curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($result !== false) {
                return ['success' => true, 'message' => 'FTP connection successful'];
            }
            
            return ['success' => false, 'message' => 'FTP connection failed: ' . $error];
        }
        
        return ['success' => false, 'message' => 'Unsupported destination type'];
    }
}
