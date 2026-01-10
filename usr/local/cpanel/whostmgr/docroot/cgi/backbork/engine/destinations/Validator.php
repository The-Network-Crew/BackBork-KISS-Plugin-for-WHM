<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/
 *
 *  THIS FILE:
 *   Validator and tester for backup destinations.
 *   Tests connectivity, validates configuration, and provides transport handlers.
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
 * Validator and tester for backup destinations.
 * Validates configuration, tests connectivity, and provides transport handlers.
 */
class BackBorkDestinationsValidator {
    
    /** @var BackBorkDestinationsParser Configuration parser instance */
    private $parser;
    
    /**
     * Constructor - Initialise parser.
     */
    public function __construct() {
        $this->parser = new BackBorkDestinationsParser();
    }
    
    /**
     * Test connectivity to a destination by ID.
     * Loads destination config and runs connection test.
     * 
     * @param string $id Destination ID
     * @return array Result with success status and message
     */
    public function testDestination($id) {
        // Look up destination configuration
        $dest = $this->parser->getDestinationByID($id);
        
        if (!$dest) {
            return ['success' => false, 'message' => 'Destination not found'];
        }
        
        // Get appropriate transport handler and test connection
        $transport = $this->getTransportForDestination($dest);
        return $transport->testConnection($dest);
    }
    
    /**
     * Test connectivity using a destination configuration array.
     * For testing destinations not yet saved.
     * 
     * @param array $dest Destination configuration array
     * @return array Result with success status and message
     */
    public function testDestinationConfig($dest) {
        $transport = $this->getTransportForDestination($dest);
        return $transport->testConnection($dest);
    }
    
    /**
     * Validate destination configuration completeness and correctness.
     * Checks required fields and type-specific requirements.
     * 
     * @param array $dest Destination configuration array
     * @return array Validation result with 'valid' flag and 'errors' array
     */
    public function validateDestination($dest) {
        $errors = [];
        
        // Check required fields
        if (empty($dest['id'])) {
            $errors[] = 'Destination ID is required';
        }
        
        if (empty($dest['type'])) {
            $errors[] = 'Destination type is required';
        }
        
        // Type-specific validation
        $type = strtolower($dest['type'] ?? '');
        
        // SFTP/FTP require host and username
        if ($type === 'sftp' || $type === 'ftp') {
            if (empty($dest['host'])) {
                $errors[] = 'Host is required for SFTP/FTP destinations';
            }
            
            if (empty($dest['username'])) {
                $errors[] = 'Username is required for SFTP/FTP destinations';
            }
            
            // Validate port range if specified
            if (!empty($dest['port'])) {
                $port = (int)$dest['port'];
                if ($port < 1 || $port > 65535) {
                    $errors[] = 'Port must be between 1 and 65535';
                }
            }
        }
        
        // Local destinations require a valid path
        if ($type === 'local') {
            if (empty($dest['path'])) {
                $errors[] = 'Path is required for local destinations';
            } elseif (!is_dir($dest['path'])) {
                $errors[] = 'Local path does not exist: ' . $dest['path'];
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Get the appropriate transport handler for a destination.
     * Returns Local or Native transport based on destination type.
     * 
     * @param array $dest Destination configuration
     * @return BackBorkTransportInterface Transport handler instance
     */
    public function getTransportForDestination($dest) {
        $type = strtolower($dest['type'] ?? 'local');
        
        // Local destinations use local filesystem transport
        if ($type === 'local') {
            return new BackBorkTransportLocal();
        }
        
        // SFTP and FTP use BackBork's Perl helper wrapping Cpanel::Transport::Files
        return new BackBorkTransportNative();
    }
    
    /**
     * Check if a destination is enabled.
     * 
     * @param string $id Destination ID
     * @return bool True if destination exists and is enabled
     */
    public function isDestinationEnabled($id) {
        $dest = $this->parser->getDestinationByID($id);
        
        if (!$dest) {
            return false;
        }
        
        return $dest['enabled'] ?? true;
    }
    
    /**
     * Check available disk space at local destination.
     * Only works for local type destinations.
     * 
     * @param string $id Destination ID
     * @return array|null Space info or null if not local
     */
    public function checkDestinationSpace($id) {
        $dest = $this->parser->getDestinationByID($id);
        
        // Only applicable to local destinations
        if (!$dest || strtolower($dest['type']) !== 'local') {
            return null;
        }
        
        $path = $dest['path'] ?? '/backup';
        
        // Verify path exists
        if (!is_dir($path)) {
            return null;
        }
        
        // Get disk space statistics
        $free = disk_free_space($path);
        $total = disk_total_space($path);
        
        return [
            'path' => $path,
            'free' => $free,
            'total' => $total,
            'used' => $total - $free,
            'free_formatted' => $this->formatSize($free),
            'total_formatted' => $this->formatSize($total)
        ];
    }
    
    /**
     * Verify backup file integrity at a destination.
     * For local destinations, checks tar.gz validity.
     * 
     * @param string $destinationID Destination ID
     * @param string $filePath Path to backup file at destination
     * @return array Result with success status and message
     */
    public function verifyBackupIntegrity($destinationID, $filePath) {
        $dest = $this->parser->getDestinationByID($destinationID);
        
        if (!$dest) {
            return ['success' => false, 'message' => 'Destination not found'];
        }
        
        // For local destinations, verify file directly
        if (strtolower($dest['type']) === 'local') {
            // Build full path
            $fullPath = rtrim($dest['path'], '/') . '/' . ltrim($filePath, '/');
            
            // Check file exists
            if (!file_exists($fullPath)) {
                return ['success' => false, 'message' => 'File not found'];
            }
            
            // Try to verify it's a valid tar.gz by listing contents
            $output = [];
            $returnCode = 0;
            exec('tar -tzf ' . escapeshellarg($fullPath) . ' > /dev/null 2>&1', $output, $returnCode);
            
            if ($returnCode === 0) {
                return ['success' => true, 'message' => 'Backup file is valid'];
            }
            
            return ['success' => false, 'message' => 'Backup file appears to be corrupted'];
        }
        
        // Remote verification would require downloading file
        return ['success' => true, 'message' => 'Remote verification not yet implemented'];
    }
    
    /**
     * Format file size in human-readable units.
     * 
     * @param int $bytes Size in bytes
     * @return string Formatted size with unit
     */
    private function formatSize($bytes) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
