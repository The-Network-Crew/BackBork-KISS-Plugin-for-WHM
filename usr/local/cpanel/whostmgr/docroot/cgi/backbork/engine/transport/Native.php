<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/
 *
 *  THIS FILE:
 *   Native transport using cPanel's Cpanel::Transport::Files module.
 *   All remote operations (SFTP/FTP) go through the Perl helper script.
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
 * Native transport implementation using cPanel's Cpanel::Transport::Files module.
 * All operations (upload, download, list, delete) go through our Perl helper
 * which provides direct access to the cPanel transport API with proper error handling.
 */
class BackBorkTransportNative implements BackBorkTransportInterface {
    
    // Path to our Perl helper that uses Cpanel::Transport::Files directly
    const PERL_HELPER = '/usr/local/cpanel/whostmgr/docroot/cgi/backbork/engine/transport/cpanel_transport.pl';
    
    /**
     * Execute the Perl helper with given action and arguments.
     * Captures stdout (JSON result) and stderr (debug messages) separately.
     * 
     * @param string $action Action to perform: upload, download, ls, delete, mkdir
     * @param array $args Associative array of arguments (transport, local, remote, path)
     * @return array Decoded JSON result or error array
     */
    private function execPerlHelper($action, $args) {
        if (!file_exists(self::PERL_HELPER)) {
            return [
                'success' => false,
                'message' => 'Perl helper not found at ' . self::PERL_HELPER
            ];
        }
        
        // Build command
        $cmd = '/usr/local/cpanel/3rdparty/bin/perl ' . escapeshellarg(self::PERL_HELPER);
        $cmd .= ' --action=' . escapeshellarg($action);
        
        foreach ($args as $key => $value) {
            if ($value !== null && $value !== '') {
                $cmd .= ' --' . $key . '=' . escapeshellarg($value);
            }
        }
        
        BackBorkConfig::debugLog("Native::execPerlHelper: $cmd");
        
        // Execute and capture stdout/stderr separately
        $descriptorSpec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout (JSON)
            2 => ['pipe', 'w']   // stderr (debug)
        ];
        
        $process = proc_open($cmd, $descriptorSpec, $pipes);
        
        if (!is_resource($process)) {
            return [
                'success' => false,
                'message' => 'Failed to execute Perl helper'
            ];
        }
        
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $returnCode = proc_close($process);
        
        // Log debug output
        if (!empty($stderr)) {
            BackBorkConfig::debugLog("Native::execPerlHelper STDERR: " . $stderr);
        }
        
        BackBorkConfig::debugLog("Native::execPerlHelper: exit=$returnCode stdout=" . substr($stdout, 0, 500));
        
        // Parse JSON response
        $result = json_decode($stdout, true);
        
        if (!$result || !isset($result['success'])) {
            return [
                'success' => false,
                'message' => 'Invalid response from Perl helper: ' . substr($stdout, 0, 200),
                'stderr' => $stderr
            ];
        }
        
        // Add stderr to result for debugging
        $result['debug_output'] = $stderr;
        
        return $result;
    }
    
    /**
     * Upload a file using Cpanel::Transport::Files->put()
     * 
     * @param string $localPath Absolute path to local file
     * @param string $remotePath Remote destination path (relative to destination base path)
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
        
        BackBorkConfig::debugLog("Native::upload: local={$localPath} remote={$remotePath} dest=" . ($destination['id'] ?? 'unknown'));
        
        $result = $this->execPerlHelper('upload', [
            'transport' => $destination['id'],
            'local'     => $localPath,
            'remote'    => $remotePath ?: ''
        ]);
        
        if ($result['success']) {
            return [
                'success'     => true,
                'message'     => $result['message'] ?? "Uploaded to: {$destination['name']}",
                'file'        => $result['file'] ?? basename($localPath),
                'remote_path' => $result['remote_path'] ?? $remotePath,
                'size'        => $result['size'] ?? filesize($localPath),
                'transport_output' => $result['debug_output'] ?? ''
            ];
        }
        
        return [
            'success' => false,
            'message' => $result['message'] ?? 'Upload failed',
            'file'    => basename($localPath),
            'transport_output' => $result['debug_output'] ?? ''
        ];
    }
    
    /**
     * Download a file using Cpanel::Transport::Files->get()
     * 
     * @param string $remotePath Path at remote destination (relative to destination base path)
     * @param string $localPath Local path to save downloaded file
     * @param array $destination Destination configuration from WHM
     * @return array Result with success status and local path
     */
    public function download($remotePath, $localPath, $destination) {
        BackBorkConfig::debugLog("Native::download: remote={$remotePath} local={$localPath} dest=" . ($destination['id'] ?? 'unknown'));
        
        // Ensure local directory exists
        $localDir = dirname($localPath);
        if (!is_dir($localDir)) {
            mkdir($localDir, 0700, true);
        }
        
        $result = $this->execPerlHelper('download', [
            'transport' => $destination['id'],
            'remote'    => $remotePath,
            'local'     => $localPath
        ]);
        
        if ($result['success'] && file_exists($localPath)) {
            return [
                'success'    => true,
                'message'    => $result['message'] ?? "Downloaded to: {$localPath}",
                'local_path' => $localPath,
                'size'       => filesize($localPath)
            ];
        }
        
        return [
            'success' => false,
            'message' => $result['message'] ?? 'Download failed'
        ];
    }
    
    /**
     * List files at remote destination using Cpanel::Transport::Files->ls()
     * 
     * @param string $remotePath Path to list (relative to destination base path)
     * @param array $destination Destination configuration from WHM
     * @return array Array of file info with 'file', 'size', 'type' keys
     */
    public function listFiles($remotePath, $destination) {
        BackBorkConfig::debugLog('Native::listFiles: dest=' . ($destination['id'] ?? 'unknown') . ' path=' . ($remotePath ?: 'default'));
        
        $result = $this->execPerlHelper('ls', [
            'transport' => $destination['id'],
            'path'      => $remotePath ?: ''
        ]);
        
        if (!$result['success']) {
            BackBorkConfig::debugLog('Native::listFiles: Failed - ' . ($result['message'] ?? 'Unknown error'));
            return [];
        }
        
        $files = $result['files'] ?? [];
        BackBorkConfig::debugLog('Native::listFiles: Found ' . count($files) . ' files');
        
        return $files;
    }
    
    /**
     * Check if a file exists at the destination.
     * Implemented by listing the parent directory and checking for the file.
     * 
     * @param string $remotePath Path to file (relative to destination base path)
     * @param array $destination Destination configuration from WHM
     * @return bool True if file exists
     */
    public function fileExists($remotePath, $destination) {
        $dir = dirname($remotePath);
        $filename = basename($remotePath);
        
        $files = $this->listFiles($dir === '.' ? '' : $dir, $destination);
        
        foreach ($files as $file) {
            if (($file['file'] ?? '') === $filename) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Delete a file using Cpanel::Transport::Files->delete()
     * 
     * @param string $remotePath Path to file (relative to destination base path)
     * @param array $destination Destination configuration from WHM
     * @return array Result with success status and message
     */
    public function delete($remotePath, $destination) {
        BackBorkConfig::debugLog("Native::delete: path={$remotePath} dest=" . ($destination['id'] ?? 'unknown'));
        
        if (empty($remotePath)) {
            return [
                'success' => false,
                'message' => 'Path is required for delete'
            ];
        }
        
        $result = $this->execPerlHelper('delete', [
            'transport' => $destination['id'],
            'path'      => $remotePath
        ]);
        
        return [
            'success' => $result['success'] ?? false,
            'message' => $result['message'] ?? ($result['success'] ? 'Deleted' : 'Delete failed')
        ];
    }
    
    /**
     * Create a directory at the destination using Cpanel::Transport::Files->mkdir()
     * 
     * @param string $remotePath Directory path to create (relative to destination base path)
     * @param array $destination Destination configuration from WHM
     * @return array Result with success status and message
     */
    public function mkdir($remotePath, $destination) {
        BackBorkConfig::debugLog("Native::mkdir: path={$remotePath} dest=" . ($destination['id'] ?? 'unknown'));
        
        if (empty($remotePath)) {
            return [
                'success' => false,
                'message' => 'Path is required for mkdir'
            ];
        }
        
        $result = $this->execPerlHelper('mkdir', [
            'transport' => $destination['id'],
            'path'      => $remotePath
        ]);
        
        return [
            'success' => $result['success'] ?? false,
            'message' => $result['message'] ?? ($result['success'] ? 'Created' : 'mkdir failed')
        ];
    }
    
    /**
     * Test connection to destination.
     * Uses WHM's backup_destination_validate API.
     * 
     * @param array $destination Destination configuration from WHM
     * @return array Result with success status and connection message
     */
    public function testConnection($destination) {
        if (empty($destination['id'])) {
            return [
                'success' => false,
                'message' => 'Destination ID is required'
            ];
        }
        
        // Use WHM API to validate destination
        $cmd = 'whmapi1 backup_destination_validate id=' . escapeshellarg($destination['id']) . ' --output=json';
        
        $output = [];
        $returnCode = 0;
        exec($cmd . ' 2>&1', $output, $returnCode);
        
        $jsonOutput = implode("\n", $output);
        $result = json_decode($jsonOutput, true);
        
        if ($result && isset($result['metadata']['result']) && $result['metadata']['result'] == 1) {
            return [
                'success' => true,
                'message' => 'Destination validated successfully'
            ];
        }
        
        $reason = $result['metadata']['reason'] ?? 'Unknown error';
        return [
            'success' => false,
            'message' => 'Validation failed: ' . $reason
        ];
    }
}
