<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-Plugin-for-WHM/
 *
 *  THIS FILE:
 *   SQL backup handler for hot database backups using mariadb-backup.
 *   Performs lock-free database backups without table locking.
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
 * SQL backup handler for hot database backups.
 * Supports mariadb-backup (MariaDB) and mysqlbackup (MySQL Enterprise).
 * These tools perform hot backups without locking tables, unlike mysqldump.
 * 
 * mariadb-backup: Uses Percona XtraBackup engine for MariaDB
 *   - Supports --backup, --prepare, --copy-back operations
 *   - Can do incremental backups with --incremental-basedir
 *   - Supports compression with --compress
 *   - Parallel processing with --parallel
 * 
 * mysqlbackup: MySQL Enterprise Backup (commercial)
 *   - Supports backup-to-image for single-file backups
 *   - Incremental with --incremental
 *   - Compression with --compress
 */
class BackBorkSQLBackup {
    
    // Binary paths - checked in order of preference
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
    
    // Default backup directory within the account's backup
    const DEFAULT_DB_SUBDIR = 'databases';
    
    /**
     * Find the mariadb-backup binary.
     * Checks multiple paths and falls back to `which` command.
     * 
     * @return string|null Path to binary or null if not found
     */
    public function findMariadbBackup() {
        // Check known paths first
        foreach (self::MARIADB_BACKUP_PATHS as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }
        
        // Fall back to which command
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
        // Check known paths first
        foreach (self::MYSQL_BACKUP_PATHS as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }
        
        // Fall back to which command
        $path = trim(shell_exec('which mysqlbackup 2>/dev/null') ?? '');
        if (!empty($path) && is_executable($path)) {
            return $path;
        }
        
        return null;
    }
    
    /**
     * Check if the configured backup method is available.
     * 
     * @param string $method 'mariadb-backup' or 'mysqlbackup'
     * @return array Result with 'available' bool and 'path' or 'error'
     */
    public function isMethodAvailable($method) {
        if ($method === 'mariadb-backup') {
            $path = $this->findMariadbBackup();
            if ($path) {
                return ['available' => true, 'path' => $path];
            }
            return ['available' => false, 'error' => 'mariadb-backup not found. Install with: yum install MariaDB-backup'];
        }
        
        if ($method === 'mysqlbackup') {
            $path = $this->findMysqlBackup();
            if ($path) {
                return ['available' => true, 'path' => $path];
            }
            return ['available' => false, 'error' => 'mysqlbackup not found. Requires MySQL Enterprise Backup license.'];
        }
        
        return ['available' => false, 'error' => 'Unknown backup method: ' . $method];
    }
    
    /**
     * Execute database backup using the configured method.
     * Creates a compressed tar of the database backup for inclusion in account backup.
     * 
     * @param string $account Account username (for filtering databases)
     * @param string $targetDir Directory to store the database backup archive
     * @param array $userConfig User configuration with backup method and options
     * @return array Result with success status, path to archive, messages
     */
    public function backup($account, $targetDir, $userConfig) {
        $method = $userConfig['db_backup_method'] ?? 'pkgacct';
        
        // Skip if method is pkgacct (handled by pkgacct itself) or skip
        if ($method === 'pkgacct' || $method === 'skip') {
            return [
                'success' => true,
                'skipped' => true,
                'message' => "Database backup method is '{$method}', skipping separate DB backup"
            ];
        }
        
        // Check if the tool is available
        $availability = $this->isMethodAvailable($method);
        if (!$availability['available']) {
            return [
                'success' => false,
                'message' => $availability['error']
            ];
        }
        
        // Create database backup subdirectory
        $dbBackupDir = $userConfig['db_backup_target_dir'] ?? ($targetDir . '/' . self::DEFAULT_DB_SUBDIR);
        $accountDbDir = $dbBackupDir . '/' . $account . '_' . date('Y-m-d_H-i-s');
        
        if (!is_dir($accountDbDir)) {
            if (!mkdir($accountDbDir, 0700, true)) {
                return [
                    'success' => false,
                    'message' => "Failed to create database backup directory: {$accountDbDir}"
                ];
            }
        }
        
        // Execute the appropriate backup method
        if ($method === 'mariadb-backup') {
            $result = $this->executeMariadbBackup($account, $accountDbDir, $userConfig, $availability['path']);
        } else {
            $result = $this->executeMysqlBackup($account, $accountDbDir, $userConfig, $availability['path']);
        }
        
        // If backup succeeded, create a compressed archive
        if ($result['success']) {
            $archiveResult = $this->createArchive($accountDbDir, $targetDir, $account);
            if (!$archiveResult['success']) {
                return $archiveResult;
            }
            $result['archive'] = $archiveResult['archive'];
            $result['archive_size'] = $archiveResult['size'];
            
            // Clean up the uncompressed backup directory
            $this->removeDirectory($accountDbDir);
        }
        
        return $result;
    }
    
    /**
     * Execute mariadb-backup for a specific account's databases.
     * 
     * mariadb-backup options used:
     *   --backup           : Perform backup operation
     *   --target-dir       : Directory to store backup
     *   --user             : MySQL user (default: root)
     *   --databases        : Specific databases to backup (account_*)
     *   --compress         : Compress backup files (optional)
     *   --parallel         : Number of parallel threads (optional)
     *   --slave-info       : Include replication slave info (optional)
     *   --galera-info      : Include Galera cluster info (optional)
     * 
     * @param string $account Account username
     * @param string $targetDir Target directory for backup
     * @param array $userConfig User configuration
     * @param string $binary Path to mariadb-backup binary
     * @return array Result with success status and messages
     */
    private function executeMariadbBackup($account, $targetDir, $userConfig, $binary) {
        // Get list of databases owned by this account
        $databases = $this->getAccountDatabases($account);
        
        if (empty($databases)) {
            return [
                'success' => true,
                'skipped' => true,
                'message' => "No databases found for account: {$account}"
            ];
        }
        
        // Build command
        $cmd = escapeshellcmd($binary);
        $cmd .= ' --backup';
        $cmd .= ' --target-dir=' . escapeshellarg($targetDir);
        
        // Authentication - use root with socket auth (standard cPanel setup)
        $cmd .= ' --user=root';
        
        // Database selection - backup only this account's databases
        // mariadb-backup uses --databases with space-separated list
        $cmd .= ' --databases=' . escapeshellarg(implode(' ', $databases));
        
        // Optional: Compression (uses qpress)
        if (!empty($userConfig['mdb_compress'])) {
            $cmd .= ' --compress';
        }
        
        // Optional: Parallel threads
        if (!empty($userConfig['mdb_parallel'])) {
            $threads = (int)($userConfig['mdb_parallel_threads'] ?? 4);
            $threads = max(1, min(16, $threads)); // Clamp between 1-16
            $cmd .= ' --parallel=' . $threads;
        }
        
        // Optional: Slave info for replication setups
        if (!empty($userConfig['mdb_slave_info'])) {
            $cmd .= ' --slave-info';
        }
        
        // Optional: Galera cluster info
        if (!empty($userConfig['mdb_galera_info'])) {
            $cmd .= ' --galera-info';
        }
        
        // Optional: Extra arguments (advanced users)
        if (!empty($userConfig['mdb_extra_args'])) {
            // Sanitize extra args - only allow safe characters
            $extraArgs = preg_replace('/[^a-zA-Z0-9\s\-_=\/\.]/', '', $userConfig['mdb_extra_args']);
            $cmd .= ' ' . $extraArgs;
        }
        
        // Execute the backup
        BackBorkConfig::debugLog("Executing mariadb-backup: {$cmd}");
        
        $output = [];
        $returnCode = 0;
        exec($cmd . ' 2>&1', $output, $returnCode);
        
        $outputStr = implode("\n", $output);
        
        if ($returnCode !== 0) {
            BackBorkConfig::debugLog("mariadb-backup failed: {$outputStr}");
            return [
                'success' => false,
                'message' => "mariadb-backup failed (exit code {$returnCode})",
                'output' => $outputStr,
                'command' => $cmd
            ];
        }
        
        // mariadb-backup requires --prepare before the backup can be restored
        // Run prepare step to make backup consistent
        $prepareCmd = escapeshellcmd($binary);
        $prepareCmd .= ' --prepare';
        $prepareCmd .= ' --target-dir=' . escapeshellarg($targetDir);
        
        BackBorkConfig::debugLog("Preparing backup: {$prepareCmd}");
        
        $prepareOutput = [];
        exec($prepareCmd . ' 2>&1', $prepareOutput, $returnCode);
        
        if ($returnCode !== 0) {
            BackBorkConfig::debugLog("mariadb-backup --prepare failed: " . implode("\n", $prepareOutput));
            return [
                'success' => false,
                'message' => "mariadb-backup --prepare failed (exit code {$returnCode})",
                'output' => implode("\n", $prepareOutput),
                'command' => $prepareCmd
            ];
        }
        
        return [
            'success' => true,
            'message' => 'mariadb-backup completed successfully',
            'databases' => $databases,
            'output' => $outputStr
        ];
    }
    
    /**
     * Execute mysqlbackup (MySQL Enterprise Backup) for a specific account.
     * 
     * mysqlbackup options used:
     *   backup-to-image    : Create single backup image file
     *   --backup-image     : Path to output image file
     *   --backup-dir       : Temporary directory for backup
     *   --user             : MySQL user
     *   --include          : Regex to match database names
     *   --compress         : Enable compression (optional)
     *   --incremental      : Incremental backup (optional)
     * 
     * @param string $account Account username
     * @param string $targetDir Target directory for backup
     * @param array $userConfig User configuration
     * @param string $binary Path to mysqlbackup binary
     * @return array Result with success status and messages
     */
    private function executeMysqlBackup($account, $targetDir, $userConfig, $binary) {
        // Get list of databases owned by this account
        $databases = $this->getAccountDatabases($account);
        
        if (empty($databases)) {
            return [
                'success' => true,
                'skipped' => true,
                'message' => "No databases found for account: {$account}"
            ];
        }
        
        // Build regex pattern to match account's databases
        // cPanel database naming: username_dbname
        $includePattern = '^' . preg_quote($account, '/') . '_';
        
        // Build command for backup-to-image (creates single file)
        $imageFile = $targetDir . '/mysql_backup.mbi';
        
        $cmd = escapeshellcmd($binary);
        $cmd .= ' --user=root';
        $cmd .= ' --backup-dir=' . escapeshellarg($targetDir . '/tmp');
        $cmd .= ' --backup-image=' . escapeshellarg($imageFile);
        $cmd .= ' --include=' . escapeshellarg($includePattern);
        
        // Optional: Compression
        if (!empty($userConfig['myb_compress'])) {
            $cmd .= ' --compress';
        }
        
        // Optional: Incremental backup
        if (!empty($userConfig['myb_incremental'])) {
            $cmd .= ' --incremental';
            // Would need --incremental-base for actual incremental
            // For now, do full backup if no base exists
        }
        
        // Optional: Extra arguments
        if (!empty($userConfig['myb_extra_args'])) {
            $extraArgs = preg_replace('/[^a-zA-Z0-9\s\-_=\/\.]/', '', $userConfig['myb_extra_args']);
            $cmd .= ' ' . $extraArgs;
        }
        
        $cmd .= ' backup-to-image';
        
        // Execute the backup
        BackBorkConfig::debugLog("Executing mysqlbackup: {$cmd}");
        
        $output = [];
        $returnCode = 0;
        exec($cmd . ' 2>&1', $output, $returnCode);
        
        $outputStr = implode("\n", $output);
        
        if ($returnCode !== 0) {
            BackBorkConfig::debugLog("mysqlbackup failed: {$outputStr}");
            return [
                'success' => false,
                'message' => "mysqlbackup failed (exit code {$returnCode})",
                'output' => $outputStr,
                'command' => $cmd
            ];
        }
        
        return [
            'success' => true,
            'message' => 'mysqlbackup completed successfully',
            'databases' => $databases,
            'image_file' => $imageFile,
            'output' => $outputStr
        ];
    }
    
    /**
     * Get list of MySQL/MariaDB databases owned by an account.
     * cPanel convention: databases are prefixed with username_
     * 
     * @param string $account Account username
     * @return array List of database names
     */
    public function getAccountDatabases($account) {
        $databases = [];
        
        // Query MySQL for databases matching the account prefix
        $escapedAccount = escapeshellarg($account . '_%');
        $cmd = "mysql -N -e \"SHOW DATABASES LIKE {$escapedAccount}\" 2>/dev/null";
        
        $output = [];
        exec($cmd, $output);
        
        foreach ($output as $db) {
            $db = trim($db);
            if (!empty($db) && strpos($db, $account . '_') === 0) {
                $databases[] = $db;
            }
        }
        
        // Also check for the main database (some setups use just the username)
        $checkMain = "mysql -N -e \"SHOW DATABASES LIKE " . escapeshellarg($account) . "\" 2>/dev/null";
        $mainDb = trim(shell_exec($checkMain) ?? '');
        if (!empty($mainDb) && $mainDb === $account) {
            array_unshift($databases, $account);
        }
        
        return $databases;
    }
    
    /**
     * Create a compressed tar archive of the database backup.
     * 
     * @param string $sourceDir Directory containing backup files
     * @param string $targetDir Directory to store the archive
     * @param string $account Account name for archive naming
     * @return array Result with success status and archive path
     */
    private function createArchive($sourceDir, $targetDir, $account) {
        $archiveName = "db-backup-{$account}_" . date('Y-m-d_H-i-s') . '.tar.gz';
        $archivePath = $targetDir . '/' . $archiveName;
        
        // Create tar.gz archive
        $cmd = 'tar -czf ' . escapeshellarg($archivePath) . ' -C ' . escapeshellarg(dirname($sourceDir)) . ' ' . escapeshellarg(basename($sourceDir));
        
        $output = [];
        $returnCode = 0;
        exec($cmd . ' 2>&1', $output, $returnCode);
        
        if ($returnCode !== 0 || !file_exists($archivePath)) {
            return [
                'success' => false,
                'message' => 'Failed to create database backup archive',
                'output' => implode("\n", $output)
            ];
        }
        
        return [
            'success' => true,
            'archive' => $archivePath,
            'size' => filesize($archivePath)
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
