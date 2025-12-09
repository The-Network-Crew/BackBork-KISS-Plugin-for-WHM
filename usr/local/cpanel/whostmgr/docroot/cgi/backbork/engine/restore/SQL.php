<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-Plugin-for-WHM/
 *
 *  THIS FILE:
 *   SQL restore handler for database restoration from hot backup archives.
 *   Restores databases from mariadb-backup or mysqlbackup format archives.
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
 * SQL restore handler for hot database backup archives.
 * Restores databases from mariadb-backup or mysqlbackup format archives.
 * 
 * Restore methods:
 *   - mariadb-backup: Uses --export then tablespace import, or SQL dumps
 *   - mysqlbackup: Uses image-to-backup-dir then copy-back
 *   - SQL dumps: Direct mysql client import
 */
class BackBorkSQLRestore {
    
    // Binary paths - same as backup handler
    const MARIADB_BACKUP_PATHS = [
        '/usr/bin/mariadb-backup',
        '/usr/bin/mariabackup',
        '/usr/local/bin/mariadb-backup',
        '/usr/local/bin/mariabackup'
    ];
    
    const MYSQL_BACKUP_PATHS = [
        '/usr/bin/mysqlbackup',
        '/usr/local/mysql/bin/mysqlbackup',
        '/opt/mysql/meb/bin/mysqlbackup'
    ];
    
    /**
     * Find the mariadb-backup binary.
     * 
     * @return string|null Path to binary or null if not found
     */
    public function findMariadbBackup() {
        foreach (self::MARIADB_BACKUP_PATHS as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }
        
        $path = trim(shell_exec('which mariadb-backup 2>/dev/null') ?? '');
        if (!empty($path) && is_executable($path)) {
            return $path;
        }
        
        $path = trim(shell_exec('which mariabackup 2>/dev/null') ?? '');
        if (!empty($path) && is_executable($path)) {
            return $path;
        }
        
        return null;
    }
    
    /**
     * Find the mysqlbackup binary.
     * 
     * @return string|null Path to binary or null if not found
     */
    public function findMysqlBackup() {
        foreach (self::MYSQL_BACKUP_PATHS as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }
        
        $path = trim(shell_exec('which mysqlbackup 2>/dev/null') ?? '');
        if (!empty($path) && is_executable($path)) {
            return $path;
        }
        
        return null;
    }
    
    /**
     * Restore databases from a hot backup archive.
     * Extracts the archive and uses mariadb-backup --copy-back or mysql import.
     * 
     * @param string $account Account username
     * @param string $archivePath Path to the db-backup-*.tar.gz file
     * @param array $userConfig User configuration
     * @return array Result with success status and message
     */
    public function restore($account, $archivePath, $userConfig) {
        // Create temp directory for extraction
        $extractDir = '/home/backbork_tmp/db_restore_' . $account . '_' . time();
        
        if (!mkdir($extractDir, 0700, true)) {
            return [
                'success' => false,
                'message' => "Failed to create extraction directory: {$extractDir}"
            ];
        }
        
        BackBorkConfig::debugLog("Extracting DB backup archive to: {$extractDir}");
        
        // Extract the archive
        $cmd = 'tar -xzf ' . escapeshellarg($archivePath) . ' -C ' . escapeshellarg($extractDir);
        $output = [];
        $returnCode = 0;
        exec($cmd . ' 2>&1', $output, $returnCode);
        
        if ($returnCode !== 0) {
            $this->removeDirectory($extractDir);
            return [
                'success' => false,
                'message' => "Failed to extract DB backup archive",
                'output' => implode("\n", $output)
            ];
        }
        
        // Find the actual backup directory inside
        $dirs = glob($extractDir . '/*', GLOB_ONLYDIR);
        $backupDir = !empty($dirs) ? $dirs[0] : $extractDir;
        
        BackBorkConfig::debugLog("Backup directory: {$backupDir}");
        
        // Detect backup type and restore accordingly
        $result = $this->executeRestore($account, $backupDir, $userConfig);
        
        // Cleanup extraction directory
        $this->removeDirectory($extractDir);
        
        return $result;
    }
    
    /**
     * Execute the actual database restore based on backup type.
     * 
     * @param string $account Account username
     * @param string $backupDir Directory containing extracted backup
     * @param array $userConfig User configuration
     * @return array Result with success status
     */
    private function executeRestore($account, $backupDir, $userConfig) {
        // Check for mariadb-backup format (has xtrabackup_info file)
        if (file_exists($backupDir . '/xtrabackup_info') || file_exists($backupDir . '/backup-my.cnf')) {
            BackBorkConfig::debugLog("Detected mariadb-backup format");
            return $this->restoreViaMariadbBackup($account, $backupDir, $userConfig);
        }
        
        // Check for mysqlbackup format (.mbi image file)
        $mbiFiles = glob($backupDir . '/*.mbi');
        if (!empty($mbiFiles)) {
            BackBorkConfig::debugLog("Detected mysqlbackup format (.mbi)");
            return $this->restoreViaMysqlBackup($account, $mbiFiles[0], $userConfig);
        }
        
        // Check for SQL dump files
        $sqlFiles = glob($backupDir . '/*.sql');
        if (!empty($sqlFiles)) {
            BackBorkConfig::debugLog("Detected SQL dump format");
            return $this->restoreFromSqlDumps($account, $sqlFiles);
        }
        
        // Check nested directories for SQL files
        $nestedSqlFiles = glob($backupDir . '/*/*.sql');
        if (!empty($nestedSqlFiles)) {
            BackBorkConfig::debugLog("Detected nested SQL dump format");
            return $this->restoreFromSqlDumps($account, $nestedSqlFiles);
        }
        
        return [
            'success' => false,
            'message' => 'Unknown database backup format - no recognized files found in ' . basename($backupDir)
        ];
    }
    
    /**
     * Restore using mariadb-backup.
     * For cPanel per-account restore, we export tables and import via mysql.
     * 
     * @param string $account Account username
     * @param string $backupDir Directory containing mariadb-backup files
     * @param array $userConfig User configuration
     * @return array Result with success status
     */
    private function restoreViaMariadbBackup($account, $backupDir, $userConfig) {
        // Check if there are SQL dump files (alternative format we may have created)
        $sqlFiles = glob($backupDir . '/*.sql');
        if (!empty($sqlFiles)) {
            return $this->restoreFromSqlDumps($account, $sqlFiles);
        }
        
        // For raw InnoDB files, we need to use mariadb-backup --export
        $binary = $this->findMariadbBackup();
        if (!$binary) {
            return [
                'success' => false,
                'message' => 'mariadb-backup not found for restore - install MariaDB-backup package'
            ];
        }
        
        // Check if backup is already prepared
        if (!file_exists($backupDir . '/xtrabackup_checkpoints')) {
            // Need to prepare the backup first
            $prepareCmd = escapeshellcmd($binary) . ' --prepare --target-dir=' . escapeshellarg($backupDir);
            
            BackBorkConfig::debugLog("Preparing backup before restore: {$prepareCmd}");
            
            $output = [];
            $returnCode = 0;
            exec($prepareCmd . ' 2>&1', $output, $returnCode);
            
            if ($returnCode !== 0) {
                BackBorkConfig::debugLog("mariadb-backup --prepare failed: " . implode("\n", $output));
                return [
                    'success' => false,
                    'message' => 'mariadb-backup --prepare failed',
                    'output' => implode("\n", $output)
                ];
            }
        }
        
        // Export tables as .cfg and .ibd files for import
        $exportCmd = escapeshellcmd($binary) . ' --export --target-dir=' . escapeshellarg($backupDir);
        
        BackBorkConfig::debugLog("Exporting tables: {$exportCmd}");
        
        $output = [];
        $returnCode = 0;
        exec($exportCmd . ' 2>&1', $output, $returnCode);
        
        if ($returnCode !== 0) {
            BackBorkConfig::debugLog("mariadb-backup --export failed: " . implode("\n", $output));
            // This isn't fatal - we can still try tablespace import
        }
        
        // Now import the exported tablespaces
        $result = $this->importTablespaces($account, $backupDir);
        
        return $result;
    }
    
    /**
     * Import InnoDB tablespaces from mariadb-backup export.
     * Uses ALTER TABLE ... IMPORT TABLESPACE for each table.
     * 
     * @param string $account Account username
     * @param string $backupDir Directory containing exported files
     * @return array Result with success status
     */
    private function importTablespaces($account, $backupDir) {
        $imported = 0;
        $errors = [];
        
        // Find all database directories in the backup
        $dbDirs = glob($backupDir . '/*', GLOB_ONLYDIR);
        
        foreach ($dbDirs as $dbDir) {
            $dbName = basename($dbDir);
            
            // Only restore databases belonging to this account
            if (strpos($dbName, $account . '_') !== 0 && $dbName !== $account) {
                continue;
            }
            
            // Find .ibd files (InnoDB tablespaces)
            $ibdFiles = glob($dbDir . '/*.ibd');
            
            foreach ($ibdFiles as $ibdFile) {
                $tableName = basename($ibdFile, '.ibd');
                $cfgFile = $dbDir . '/' . $tableName . '.cfg';
                
                // MySQL data directory for this database
                $mysqlDataDir = '/var/lib/mysql/' . $dbName;
                
                if (!is_dir($mysqlDataDir)) {
                    // Database doesn't exist yet - create it
                    $createCmd = "mysql -e " . escapeshellarg("CREATE DATABASE IF NOT EXISTS `{$dbName}`") . " 2>&1";
                    shell_exec($createCmd);
                    
                    if (!is_dir($mysqlDataDir)) {
                        $errors[] = "Failed to create database {$dbName}";
                        continue;
                    }
                }
                
                // Discard existing tablespace
                $discardCmd = "mysql -e " . escapeshellarg("ALTER TABLE `{$dbName}`.`{$tableName}` DISCARD TABLESPACE") . " 2>&1";
                shell_exec($discardCmd);
                
                // Copy .ibd and .cfg files to MySQL data directory
                copy($ibdFile, $mysqlDataDir . '/' . $tableName . '.ibd');
                if (file_exists($cfgFile)) {
                    copy($cfgFile, $mysqlDataDir . '/' . $tableName . '.cfg');
                }
                
                // Set ownership
                chown($mysqlDataDir . '/' . $tableName . '.ibd', 'mysql');
                chgrp($mysqlDataDir . '/' . $tableName . '.ibd', 'mysql');
                
                // Import the tablespace
                $importCmd = "mysql -e " . escapeshellarg("ALTER TABLE `{$dbName}`.`{$tableName}` IMPORT TABLESPACE") . " 2>&1";
                $result = shell_exec($importCmd);
                
                if ($result && stripos($result, 'error') !== false) {
                    $errors[] = "{$dbName}.{$tableName}: {$result}";
                } else {
                    $imported++;
                }
            }
        }
        
        if (!empty($errors) && $imported === 0) {
            return [
                'success' => false,
                'message' => 'All tablespace imports failed: ' . implode('; ', array_slice($errors, 0, 3)),
                'errors' => $errors
            ];
        }
        
        return [
            'success' => true,
            'message' => "Imported {$imported} tablespace(s)" . (!empty($errors) ? " with " . count($errors) . " error(s)" : ''),
            'imported_count' => $imported,
            'errors' => $errors
        ];
    }
    
    /**
     * Restore using mysqlbackup.
     * 
     * @param string $account Account username
     * @param string $imageFile Path to .mbi image file
     * @param array $userConfig User configuration
     * @return array Result with success status
     */
    private function restoreViaMysqlBackup($account, $imageFile, $userConfig) {
        $binary = $this->findMysqlBackup();
        if (!$binary) {
            return [
                'success' => false,
                'message' => 'mysqlbackup not found for restore - requires MySQL Enterprise Backup'
            ];
        }
        
        // Extract from image to backup directory
        $tempDir = dirname($imageFile) . '/restore_tmp';
        mkdir($tempDir, 0700, true);
        
        $cmd = escapeshellcmd($binary);
        $cmd .= ' --backup-image=' . escapeshellarg($imageFile);
        $cmd .= ' --backup-dir=' . escapeshellarg($tempDir);
        $cmd .= ' image-to-backup-dir';
        
        BackBorkConfig::debugLog("Extracting mysqlbackup image: {$cmd}");
        
        $output = [];
        $returnCode = 0;
        exec($cmd . ' 2>&1', $output, $returnCode);
        
        if ($returnCode !== 0) {
            $this->removeDirectory($tempDir);
            return [
                'success' => false,
                'message' => 'mysqlbackup image extraction failed',
                'output' => implode("\n", $output)
            ];
        }
        
        // Apply logs to make backup consistent
        $applyCmd = escapeshellcmd($binary);
        $applyCmd .= ' --backup-dir=' . escapeshellarg($tempDir);
        $applyCmd .= ' apply-log';
        
        BackBorkConfig::debugLog("Applying logs: {$applyCmd}");
        
        exec($applyCmd . ' 2>&1', $output, $returnCode);
        
        if ($returnCode !== 0) {
            $this->removeDirectory($tempDir);
            return [
                'success' => false,
                'message' => 'mysqlbackup apply-log failed',
                'output' => implode("\n", $output)
            ];
        }
        
        // For per-account restore, copy only the relevant databases
        $result = $this->copyAccountDatabases($account, $tempDir);
        
        $this->removeDirectory($tempDir);
        
        return $result;
    }
    
    /**
     * Copy specific account databases from mysqlbackup extract.
     * 
     * @param string $account Account username
     * @param string $backupDir Extracted backup directory
     * @return array Result with success status
     */
    private function copyAccountDatabases($account, $backupDir) {
        $dataDir = $backupDir . '/datadir';
        if (!is_dir($dataDir)) {
            $dataDir = $backupDir;
        }
        
        $copied = 0;
        $errors = [];
        
        // Find database directories
        $dbDirs = glob($dataDir . '/*', GLOB_ONLYDIR);
        
        foreach ($dbDirs as $dbDir) {
            $dbName = basename($dbDir);
            
            // Only restore databases belonging to this account
            if (strpos($dbName, $account . '_') !== 0 && $dbName !== $account) {
                continue;
            }
            
            $targetDir = '/var/lib/mysql/' . $dbName;
            
            // Stop MySQL briefly to copy files safely
            // Note: For production, consider using transportable tablespaces instead
            
            // Create database if it doesn't exist
            shell_exec("mysql -e " . escapeshellarg("CREATE DATABASE IF NOT EXISTS `{$dbName}`") . " 2>&1");
            
            // Copy files
            $copyCmd = "cp -r " . escapeshellarg($dbDir) . "/* " . escapeshellarg($targetDir) . "/ 2>&1";
            $result = shell_exec($copyCmd);
            
            // Fix ownership
            shell_exec("chown -R mysql:mysql " . escapeshellarg($targetDir));
            
            if ($result && stripos($result, 'error') !== false) {
                $errors[] = $dbName;
            } else {
                $copied++;
            }
        }
        
        if ($copied === 0 && !empty($errors)) {
            return [
                'success' => false,
                'message' => 'Failed to copy database files',
                'errors' => $errors
            ];
        }
        
        return [
            'success' => true,
            'message' => "Restored {$copied} database(s) via mysqlbackup",
            'copied_count' => $copied
        ];
    }
    
    /**
     * Restore from SQL dump files.
     * 
     * @param string $account Account username
     * @param array $sqlFiles List of SQL file paths
     * @return array Result with success status
     */
    public function restoreFromSqlDumps($account, $sqlFiles) {
        $errors = [];
        $restored = 0;
        
        BackBorkConfig::debugLog("Restoring from " . count($sqlFiles) . " SQL dump files");
        
        foreach ($sqlFiles as $sqlFile) {
            $dbName = basename($sqlFile, '.sql');
            
            // Only restore databases belonging to this account
            if (strpos($dbName, $account . '_') !== 0 && $dbName !== $account) {
                BackBorkConfig::debugLog("Skipping {$dbName} - doesn't belong to account {$account}");
                continue;
            }
            
            BackBorkConfig::debugLog("Restoring database: {$dbName}");
            
            // Create database if it doesn't exist
            $createCmd = "mysql -e " . escapeshellarg("CREATE DATABASE IF NOT EXISTS `{$dbName}`") . " 2>&1";
            shell_exec($createCmd);
            
            // Import the SQL file
            $cmd = 'mysql ' . escapeshellarg($dbName) . ' < ' . escapeshellarg($sqlFile) . ' 2>&1';
            $output = shell_exec($cmd);
            
            if ($output && stripos($output, 'error') !== false) {
                $errors[] = "{$dbName}: {$output}";
                BackBorkConfig::debugLog("Error restoring {$dbName}: {$output}");
            } else {
                $restored++;
                BackBorkConfig::debugLog("Successfully restored {$dbName}");
            }
        }
        
        if (!empty($errors) && $restored === 0) {
            return [
                'success' => false,
                'message' => 'All database restores failed: ' . implode('; ', array_slice($errors, 0, 3)),
                'errors' => $errors,
                'restored_count' => 0
            ];
        }
        
        return [
            'success' => true,
            'message' => "Restored {$restored} database(s)" . (!empty($errors) ? " with " . count($errors) . " error(s)" : ''),
            'restored_count' => $restored,
            'errors' => $errors
        ];
    }
    
    /**
     * Recursively remove a directory and its contents.
     * 
     * @param string $dir Directory path to remove
     * @return bool True on success
     */
    private function removeDirectory($dir) {
        if (!is_dir($dir)) {
            return true;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        
        return rmdir($dir);
    }
}
