<?php
/**
 * BackBork KISS - Transport Interface
 * Interface for backup transport implementations
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

interface BackBorkTransportInterface {
    
    /**
     * Upload a file to the destination
     * 
     * @param string $localPath Local file path
     * @param string $remotePath Remote destination path
     * @param array $destination Destination configuration
     * @return array Result with success status and message
     */
    public function upload($localPath, $remotePath, $destination);
    
    /**
     * Download a file from the destination
     * 
     * @param string $remotePath Remote file path
     * @param string $localPath Local destination path
     * @param array $destination Destination configuration
     * @return array Result with success status and message
     */
    public function download($remotePath, $localPath, $destination);
    
    /**
     * List files at the destination
     * 
     * @param string $remotePath Remote path to list
     * @param array $destination Destination configuration
     * @return array List of files
     */
    public function listFiles($remotePath, $destination);
    
    /**
     * Delete a file from the destination
     * 
     * @param string $remotePath Remote file path
     * @param array $destination Destination configuration
     * @return array Result with success status
     */
    public function delete($remotePath, $destination);
    
    /**
     * Test connection to the destination
     * 
     * @param array $destination Destination configuration
     * @return array Result with success status
     */
    public function testConnection($destination);
}
