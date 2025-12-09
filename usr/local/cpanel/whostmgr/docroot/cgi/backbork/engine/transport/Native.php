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

class BackBorkTransportNative implements BackBorkTransportInterface {
    
    const TRANSPORT_BIN = '/scripts/cpbackup_transport_file';
    
    /**
     * Upload a file using cpbackup_transport_file
     * 
     * @param string $localPath Local file path
     * @param string $remotePath Remote destination path (usually ignored, goes to manual_backup/)
     * @param array $destination Destination configuration
     * @return array Result
     */
    public function upload($localPath, $remotePath, $destination) {
        if (!file_exists($localPath)) {
            return [
                'success' => false,
                'message' => "Local file not found: {$localPath}"
            ];
        }
        
        if (!file_exists(self::TRANSPORT_BIN)) {
            return [
                'success' => false,
                'message' => 'Transport binary not found at ' . self::TRANSPORT_BIN
            ];
        }
        
        // Correct syntax: cpbackup_transport_file --transport <id> --upload </full/path/to/file>
        $transportCmd = self::TRANSPORT_BIN . 
                        ' --transport ' . escapeshellarg($destination['id']) .
                        ' --upload ' . escapeshellarg($localPath);
        
        $output = [];
        $returnCode = 0;
        exec($transportCmd . ' 2>&1', $output, $returnCode);
        
        $transportOutput = implode("\n", $output);
        
        if ($returnCode !== 0) {
            return [
                'success' => false,
                'message' => "Transport failed (exit code {$returnCode}): " . $transportOutput,
                'file' => basename($localPath),
                'command' => $transportCmd
            ];
        }
        
        // File ends up at: <remote_path>/manual_backup/<filename>
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
     * Download a file using cpbackup_transport_file
     * 
     * @param string $remotePath Remote file path
     * @param string $localPath Local destination path
     * @param array $destination Destination configuration
     * @return array Result
     */
    public function download($remotePath, $localPath, $destination) {
        if (!file_exists(self::TRANSPORT_BIN)) {
            return [
                'success' => false,
                'message' => 'Transport binary not found at ' . self::TRANSPORT_BIN
            ];
        }
        
        // Ensure local directory exists
        $localDir = dirname($localPath);
        if (!is_dir($localDir)) {
            mkdir($localDir, 0700, true);
        }
        
        // Correct syntax: cpbackup_transport_file --transport <id> --download <remote_path> --download-to <local_path>
        $transportCmd = self::TRANSPORT_BIN .
                        ' --transport ' . escapeshellarg($destination['id']) .
                        ' --download ' . escapeshellarg($remotePath) .
                        ' --download-to ' . escapeshellarg($localPath);
        
        $output = [];
        $returnCode = 0;
        exec($transportCmd . ' 2>&1', $output, $returnCode);
        
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
     * List files at remote destination
     * This is done via SSH for SFTP destinations
     * 
     * @param string $remotePath Remote path
     * @param array $destination Destination configuration
     * @return array
     */
    public function listFiles($remotePath, $destination) {
        $files = [];
        
        // For SFTP destinations, use SSH to list
        if ($destination['type'] === 'SFTP' || $destination['type'] === 'sftp') {
            $host = $destination['host'] ?? '';
            $port = $destination['port'] ?? 22;
            $username = $destination['username'] ?? '';
            $basePath = $destination['path'] ?? '/';
            
            if (empty($host) || empty($username)) {
                return $files;
            }
            
            $fullPath = rtrim($basePath, '/') . '/' . ltrim($remotePath, '/');
            
            $sshCmd = "ssh -p {$port} -o StrictHostKeyChecking=no -o BatchMode=yes " .
                      escapeshellarg("{$username}@{$host}") . " " .
                      "'find " . escapeshellarg($fullPath) . " -name \"cpmove-*.tar.gz\" -printf \"%f|%s|%T@\\n\" 2>/dev/null'";
            
            $output = [];
            exec($sshCmd, $output);
            
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
     * Delete a file from the destination
     * 
     * @param string $remotePath Remote file path
     * @param array $destination Destination configuration
     * @return array Result
     */
    public function delete($remotePath, $destination) {
        // For SFTP destinations, use SSH to delete
        if ($destination['type'] === 'SFTP' || $destination['type'] === 'sftp') {
            $host = $destination['host'] ?? '';
            $port = $destination['port'] ?? 22;
            $username = $destination['username'] ?? '';
            $basePath = $destination['path'] ?? '/';
            
            if (empty($host) || empty($username)) {
                return ['success' => false, 'message' => 'Invalid destination configuration'];
            }
            
            $fullPath = rtrim($basePath, '/') . '/' . ltrim($remotePath, '/');
            
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
     * Test connection to destination
     * 
     * @param array $destination Destination configuration
     * @return array Result
     */
    public function testConnection($destination) {
        if ($destination['type'] === 'SFTP' || $destination['type'] === 'sftp') {
            $host = $destination['host'] ?? '';
            $port = $destination['port'] ?? 22;
            $username = $destination['username'] ?? '';
            
            $cmd = "ssh -p {$port} -o StrictHostKeyChecking=no -o BatchMode=yes -o ConnectTimeout=10 " .
                   escapeshellarg("{$username}@{$host}") . " 'echo connected' 2>&1";
            
            $output = [];
            $returnCode = 0;
            exec($cmd, $output, $returnCode);
            
            if ($returnCode === 0 && in_array('connected', $output)) {
                return ['success' => true, 'message' => 'SFTP connection successful'];
            }
            
            return [
                'success' => false,
                'message' => 'SFTP connection failed: ' . implode(' ', $output)
            ];
        }
        
        if ($destination['type'] === 'FTP' || $destination['type'] === 'ftp') {
            $host = $destination['host'] ?? '';
            $port = $destination['port'] ?? 21;
            
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => "ftp://{$host}:{$port}/",
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FTP_USE_EPSV => true,
                CURLOPT_FTPLISTONLY => true
            ]);
            
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
