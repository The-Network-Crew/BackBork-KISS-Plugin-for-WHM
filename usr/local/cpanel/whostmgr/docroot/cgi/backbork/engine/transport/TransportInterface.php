<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-Plugin-for-WHM/
 *
 *  THIS FILE:
 *   Interface for backup transport implementations (Local, Native/SFTP).
 *   Defines consistent API for upload, download, listing, and deletion.
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
 * Interface for backup transport implementations.
 * All transport types (Local, Native/SFTP, etc.) must implement this interface.
 * Provides consistent API for upload, download, listing, and deletion operations.
 */
interface BackBorkTransportInterface {
    
    /**
     * Upload a file to the destination.
     * Transfers a local backup file to the remote/destination storage.
     * 
     * @param string $localPath Absolute path to local file
     * @param string $remotePath Destination path (relative to destination base)
     * @param array $destination Destination configuration array
     * @return array Result with 'success' (bool) and 'message' (string)
     */
    public function upload($localPath, $remotePath, $destination);
    
    /**
     * Download a file from the destination.
     * Retrieves a backup file from remote/destination storage to local path.
     * 
     * @param string $remotePath Path at destination (relative to base)
     * @param string $localPath Local path to save downloaded file
     * @param array $destination Destination configuration array
     * @return array Result with 'success' (bool) and 'message' (string)
     */
    public function download($remotePath, $localPath, $destination);
    
    /**
     * List files at the destination.
     * Returns available backup files at the specified path.
     * 
     * @param string $remotePath Path to list (relative to destination base)
     * @param array $destination Destination configuration array
     * @return array List of file info arrays (file, size, date, etc.)
     */
    public function listFiles($remotePath, $destination);
    
    /**
     * Check if a file exists at the destination.
     * 
     * @param string $remotePath Path to file (relative to destination base)
     * @param array $destination Destination configuration array
     * @return bool True if file exists
     */
    public function fileExists($remotePath, $destination);
    
    /**
     * Delete a file from the destination.
     * Removes a backup file from remote/destination storage.
     * 
     * @param string $remotePath Path to file (relative to destination base)
     * @param array $destination Destination configuration array
     * @return array Result with 'success' (bool) and 'message' (string)
     */
    public function delete($remotePath, $destination);
    
    /**
     * Test connection to the destination.
     * Verifies that the destination is accessible and credentials work.
     * 
     * @param array $destination Destination configuration array
     * @return array Result with 'success' (bool) and 'message' (string)
     */
    public function testConnection($destination);
}
