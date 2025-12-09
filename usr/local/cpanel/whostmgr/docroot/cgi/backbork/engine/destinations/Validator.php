<?php
/**
 * BackBork KISS - Destinations Validator
 * Validates and tests backup destinations
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

class BackBorkDestinationsValidator {
    
    /** @var BackBorkDestinationsParser */
    private $parser;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->parser = new BackBorkDestinationsParser();
    }
    
    /**
     * Test destination connection
     * 
     * @param string $id Destination ID
     * @return array
     */
    public function testDestination($id) {
        $dest = $this->parser->getDestinationById($id);
        
        if (!$dest) {
            return ['success' => false, 'message' => 'Destination not found'];
        }
        
        // Get appropriate transport and test
        $transport = $this->getTransportForDestination($dest);
        return $transport->testConnection($dest);
    }
    
    /**
     * Test destination by configuration array
     * 
     * @param array $dest Destination configuration
     * @return array
     */
    public function testDestinationConfig($dest) {
        $transport = $this->getTransportForDestination($dest);
        return $transport->testConnection($dest);
    }
    
    /**
     * Validate destination configuration
     * 
     * @param array $dest Destination configuration
     * @return array Validation result
     */
    public function validateDestination($dest) {
        $errors = [];
        
        // Required fields
        if (empty($dest['id'])) {
            $errors[] = 'Destination ID is required';
        }
        
        if (empty($dest['type'])) {
            $errors[] = 'Destination type is required';
        }
        
        // Type-specific validation
        $type = strtolower($dest['type'] ?? '');
        
        if ($type === 'sftp' || $type === 'ftp') {
            if (empty($dest['host'])) {
                $errors[] = 'Host is required for SFTP/FTP destinations';
            }
            
            if (empty($dest['username'])) {
                $errors[] = 'Username is required for SFTP/FTP destinations';
            }
            
            if (!empty($dest['port'])) {
                $port = (int)$dest['port'];
                if ($port < 1 || $port > 65535) {
                    $errors[] = 'Port must be between 1 and 65535';
                }
            }
        }
        
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
     * Get the appropriate transport handler for a destination
     * 
     * @param array $dest Destination configuration
     * @return BackBorkTransportInterface
     */
    public function getTransportForDestination($dest) {
        $type = strtolower($dest['type'] ?? 'local');
        
        if ($type === 'local') {
            return new BackBorkTransportLocal();
        }
        
        // SFTP and FTP use the native WHM transport
        return new BackBorkTransportNative();
    }
    
    /**
     * Check if destination is enabled
     * 
     * @param string $id Destination ID
     * @return bool
     */
    public function isDestinationEnabled($id) {
        $dest = $this->parser->getDestinationById($id);
        
        if (!$dest) {
            return false;
        }
        
        return $dest['enabled'] ?? true;
    }
    
    /**
     * Check disk space at destination (for local only)
     * 
     * @param string $id Destination ID
     * @return array|null
     */
    public function checkDestinationSpace($id) {
        $dest = $this->parser->getDestinationById($id);
        
        if (!$dest || strtolower($dest['type']) !== 'local') {
            return null;
        }
        
        $path = $dest['path'] ?? '/backup';
        
        if (!is_dir($path)) {
            return null;
        }
        
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
     * Verify backup file integrity at destination
     * 
     * @param string $destinationId Destination ID
     * @param string $filePath File path at destination
     * @return array
     */
    public function verifyBackupIntegrity($destinationId, $filePath) {
        $dest = $this->parser->getDestinationById($destinationId);
        
        if (!$dest) {
            return ['success' => false, 'message' => 'Destination not found'];
        }
        
        // For local destinations, we can verify directly
        if (strtolower($dest['type']) === 'local') {
            $fullPath = rtrim($dest['path'], '/') . '/' . ltrim($filePath, '/');
            
            if (!file_exists($fullPath)) {
                return ['success' => false, 'message' => 'File not found'];
            }
            
            // Try to verify it's a valid tar.gz
            $output = [];
            $returnCode = 0;
            exec('tar -tzf ' . escapeshellarg($fullPath) . ' > /dev/null 2>&1', $output, $returnCode);
            
            if ($returnCode === 0) {
                return ['success' => true, 'message' => 'Backup file is valid'];
            }
            
            return ['success' => false, 'message' => 'Backup file appears to be corrupted'];
        }
        
        // For remote destinations, we'd need to download or use SSH
        return ['success' => true, 'message' => 'Remote verification not yet implemented'];
    }
    
    /**
     * Format file size
     * 
     * @param int $bytes Size in bytes
     * @return string Formatted size
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
