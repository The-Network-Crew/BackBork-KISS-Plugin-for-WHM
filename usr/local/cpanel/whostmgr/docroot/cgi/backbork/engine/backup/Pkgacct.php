<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/
 *
 *  THIS FILE:
 *   Wrapper for cPanel's /scripts/pkgacct account packaging utility.
 *   Builds command options, executes pkgacct, and locates output files.
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
 * Wrapper for cPanel's /scripts/pkgacct utility.
 * Handles building command options, executing pkgacct, and locating output files.
 * pkgacct creates cpmove-style account backup archives.
 */
class BackBorkPkgacct {
    
    // Path to cPanel's pkgacct script
    const PKGACCT_BIN = '/scripts/pkgacct';
    
    // Default temporary directory for staging backup output
    const DEFAULT_TEMP_DIR = '/home/backbork_tmp';
    
    /**
     * Check if pkgacct binary exists and is executable.
     * Should be verified before attempting any backup operations.
     * 
     * @return bool True if pkgacct is available
     */
    public function isAvailable() {
        return file_exists(self::PKGACCT_BIN) && is_executable(self::PKGACCT_BIN);
    }
    
    /**
     * Build pkgacct command line options from user configuration.
     * Maps BackBork config keys to pkgacct command-line flags.
     * 
     * @param array $userConfig User configuration with backup preferences
     * @return string Space-prefixed command line options string
     */
    public function buildOptions($userConfig) {
        $options = '';
        
        // Compression option: --compress (default) or --nocompress
        if (isset($userConfig['compression_option'])) {
            if ($userConfig['compression_option'] === 'nocompress') {
                $options .= ' --nocompress';
            } else {
                $options .= ' --compress';
            }
        }
        
        // MySQL version specification (e.g., '5.7', '8.0')
        if (!empty($userConfig['mysql_version'])) {
            $options .= ' --mysql=' . escapeshellarg($userConfig['mysql_version']);
        }
        
        // Database backup method handling
        $dbMethod = $userConfig['db_backup_method'] ?? 'pkgacct';
        if ($dbMethod === 'skip') {
            // User explicitly wants to skip databases entirely
            $options .= ' --skipmysql';
            BackBorkConfig::debugLog("pkgacct: Adding --skipmysql (db_backup_method=skip)");
        } elseif (in_array($dbMethod, ['mariadb-backup', 'mysqlbackup'], true)) {
            // Using hot DB backup tool - export schema only from pkgacct
            // The actual data will come from mariadb-backup/mysqlbackup
            $options .= ' --dbbackup=schema';
            BackBorkConfig::debugLog("pkgacct: Adding --dbbackup=schema (db_backup_method={$dbMethod})");
        } elseif (!empty($userConfig['dbbackup_type']) && $userConfig['dbbackup_type'] !== 'all') {
            // Database backup type: 'all', 'schema', or specific type
            $options .= ' --dbbackup=' . escapeshellarg($userConfig['dbbackup_type']);
        }
        
        // Backup mode options
        if (!empty($userConfig['opt_incremental'])) {
            $options .= ' --incremental';  // Incremental backup mode
        }
        if (!empty($userConfig['opt_split'])) {
            $options .= ' --split';  // Split large archives
        }
        if (!empty($userConfig['opt_use_backups'])) {
            $options .= ' --use_backups';  // Use existing cPanel backups
        }
        
        // Skip options - map config keys to pkgacct flags
        // Each option excludes specific data from the backup
        $skipOptions = [
            'skip_homedir' => '--skiphomedir',           // Skip entire home directory
            'skip_publichtml' => '--skippublichtml',     // Skip public_html folder
            'skip_mysql' => '--skipmysql',               // Skip MySQL databases
            'skip_pgsql' => '--skippgsql',               // Skip PostgreSQL databases
            'skip_logs' => '--skiplogs',                 // Skip access/error logs
            'skip_mailconfig' => '--skipmailconfig',     // Skip email configuration
            'skip_mailman' => '--skipmailman',           // Skip mailing lists
            'skip_dnszones' => '--skipdnszones',         // Skip DNS zone files
            'skip_ssl' => '--skipssl',                   // Skip SSL certificates
            'skip_bwdata' => '--skipbwdata',             // Skip bandwidth data
            'skip_quota' => '--skipquota',               // Skip quota information
            'skip_ftpusers' => '--skipftpusers',         // Skip FTP accounts
            'skip_domains' => '--skipdomains',           // Skip addon domains
            'skip_acctdb' => '--skipacctdb',             // Skip account database
            'skip_apitokens' => '--skipapitokens',       // Skip API tokens
            'skip_authnlinks' => '--skipauthnlinks',     // Skip authentication links
            'skip_locale' => '--skiplocale',             // Skip locale settings
            'skip_passwd' => '--skippasswd',             // Skip password files
            'skip_shell' => '--skipshell',               // Skip shell preferences
            'skip_resellerconfig' => '--skipresellerconfig', // Skip reseller config
            'skip_userdata' => '--skipuserdata',         // Skip user data files
            'skip_linkednodes' => '--skiplinkednodes',   // Skip linked nodes
            'skip_integrationlinks' => '--skipintegrationlinks', // Skip integration links
        ];
        
        // Add each enabled skip option to command line
        foreach ($skipOptions as $configKey => $flag) {
            if (!empty($userConfig[$configKey])) {
                $options .= ' ' . $flag;
            }
        }
        
        return $options;
    }
    
    /**
     * Execute pkgacct to create a backup archive for an account.
     * Builds command with options, runs pkgacct, and locates output file.
     * 
     * @param string $account Account username to backup
     * @param string $workDir Working directory for backup output
     * @param array $userConfig User configuration for pkgacct options
     * @param string|null $logFile Optional log file for real-time output streaming
     * @return array Result with success status, file path, and execution details
     */
    public function execute($account, $workDir, $userConfig = [], $logFile = null) {
        // Verify pkgacct binary is available
        if (!$this->isAvailable()) {
            return [
                'success' => false,
                'message' => 'pkgacct binary not found at ' . self::PKGACCT_BIN
            ];
        }
        
        // Ensure work directory exists with secure permissions
        if (!is_dir($workDir)) {
            if (!mkdir($workDir, 0700, true)) {
                return [
                    'success' => false,
                    'message' => "Failed to create work directory: {$workDir}"
                ];
            }
        }
        
        // Build complete pkgacct command
        $command = self::PKGACCT_BIN;
        $command .= $this->buildOptions($userConfig);  // Add user-configured options
        $command .= ' ' . escapeshellarg($account);    // Account username
        $command .= ' ' . escapeshellarg($workDir);    // Output directory
        
        // Execute pkgacct with real-time output streaming if log file provided
        if ($logFile !== null) {
            $result = $this->executeWithLogging($command, $logFile);
            $output = $result['output'];
            $returnCode = $result['return_code'];
        } else {
            // Legacy execution without streaming
            $output = [];
            $returnCode = 0;
            exec($command . ' 2>&1', $output, $returnCode);
        }
        
        $outputStr = implode("\n", $output);
        
        // Check for execution failure
        if ($returnCode !== 0) {
            return [
                'success' => false,
                'message' => "pkgacct failed (exit code {$returnCode}): " . $outputStr,
                'command' => $command,
                'output' => $output,
                'return_code' => $returnCode
            ];
        }
        
        // Locate the created backup file
        $backupFile = $this->findCreatedBackup($account, $workDir);
        
        // Verify backup file was actually created
        if (!$backupFile) {
            return [
                'success' => false,
                'message' => "Backup file not found after pkgacct completed",
                'command' => $command,
                'output' => $output,
                'dir_contents' => glob($workDir . '/*')  // Debug: show what's in directory
            ];
        }
        
        // Return success with backup file details
        return [
            'success' => true,
            'message' => 'Backup created successfully',
            'file' => $backupFile,
            'path' => $workDir . '/' . $backupFile,
            'size' => filesize($workDir . '/' . $backupFile),
            'command' => $command,
            'output' => $output
        ];
    }
    
    /**
     * Execute command with real-time logging to file.
     * Streams output line-by-line to log file as it's produced.
     * 
     * @param string $command Command to execute
     * @param string $logFile Path to log file for output
     * @return array Result with output array and return code
     */
    private function executeWithLogging($command, $logFile) {
        $output = [];
        $returnCode = 0;
        
        // Open process with separate stdout/stderr pipes
        $descriptorSpec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w']   // stderr
        ];
        
        $process = proc_open($command, $descriptorSpec, $pipes);
        
        if (!is_resource($process)) {
            $this->appendToLog($logFile, "      [ERROR] Failed to start pkgacct process");
            return ['output' => ['Failed to start process'], 'return_code' => 1];
        }
        
        // Close stdin - we don't need it
        fclose($pipes[0]);
        
        // Set streams to non-blocking for interleaved reading
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        
        $this->appendToLog($logFile, "      --- pkgacct output begin ---");
        
        // Read from both streams until process completes
        while (true) {
            $stdout = fgets($pipes[1]);
            $stderr = fgets($pipes[2]);
            
            if ($stdout !== false) {
                $line = rtrim($stdout);
                $output[] = $line;
                $this->appendToLog($logFile, "      " . $line);
            }
            
            if ($stderr !== false) {
                $line = rtrim($stderr);
                $output[] = $line;
                $this->appendToLog($logFile, "      " . $line);
            }
            
            // Check if process is still running
            $status = proc_get_status($process);
            if (!$status['running']) {
                // Read any remaining output
                while (($line = fgets($pipes[1])) !== false) {
                    $line = rtrim($line);
                    $output[] = $line;
                    $this->appendToLog($logFile, "      " . $line);
                }
                while (($line = fgets($pipes[2])) !== false) {
                    $line = rtrim($line);
                    $output[] = $line;
                    $this->appendToLog($logFile, "      " . $line);
                }
                $returnCode = $status['exitcode'];
                break;
            }
            
            // Small delay to prevent CPU spinning
            usleep(10000); // 10ms
        }
        
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        
        $this->appendToLog($logFile, "      --- pkgacct output end ---");
        
        return ['output' => $output, 'return_code' => $returnCode];
    }
    
    /**
     * Append a line to the log file with timestamp.
     * 
     * @param string $logFile Path to log file
     * @param string $message Message to append
     */
    private function appendToLog($logFile, $message) {
        $timestamp = date('H:i:s');
        file_put_contents($logFile, "[{$timestamp}] {$message}\n", FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Find the backup file created by pkgacct.
     * Searches for standard cpmove-*.tar.gz naming convention.
     * If --nocompress was used, compresses the directory to tar.gz.
     * 
     * @param string $account Account username
     * @param string $workDir Working directory to search
     * @return string|null Filename if found, null otherwise
     */
    private function findCreatedBackup($account, $workDir) {
        // Check for standard naming: cpmove-<account>.tar.gz
        $standardName = 'cpmove-' . $account . '.tar.gz';
        if (file_exists($workDir . '/' . $standardName) && is_file($workDir . '/' . $standardName)) {
            return $standardName;
        }
        
        // Check for uncompressed cpmove directory (happens with --nocompress option)
        // Compress it to tar.gz before returning
        $cpmoveDir = $workDir . '/cpmove-' . $account;
        if (is_dir($cpmoveDir)) {
            $tarFile = $workDir . '/' . $standardName;
            $tarCmd = 'cd ' . escapeshellarg($workDir) . ' && tar -czf ' . escapeshellarg($standardName) . ' ' . escapeshellarg('cpmove-' . $account) . ' 2>&1';
            $output = [];
            $returnCode = 0;
            exec($tarCmd, $output, $returnCode);
            
            if ($returnCode === 0 && file_exists($tarFile) && is_file($tarFile)) {
                // Remove the directory after successful compression
                $this->recursiveDelete($cpmoveDir);
                return $standardName;
            }
        }
        
        // Try pattern matching for tar.gz files with non-standard names
        $pattern = $workDir . '/cpmove-' . $account . '*.tar.gz';
        $files = glob($pattern);
        
        // Return first matching tar.gz file if found
        if (!empty($files)) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    return basename($file);
                }
            }
        }
        
        return null;
    }
    
    /**
     * Recursively delete a directory and its contents.
     * 
     * @param string $dir Directory path to delete
     * @return bool True on success
     */
    private function recursiveDelete($dir) {
        if (!is_dir($dir)) {
            return false;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->recursiveDelete($path);
            } else {
                unlink($path);
            }
        }
        return rmdir($dir);
    }
    
    /**
     * Estimate backup size for an account based on home directory.
     * Uses 'du' command to calculate total disk usage.
     * 
     * @param string $account Account username
     * @return int|null Estimated size in bytes, null if unable to calculate
     */
    public function estimateBackupSize($account) {
        // Construct home directory path
        $homeDir = '/home/' . $account;
        
        // Verify home directory exists
        if (!is_dir($homeDir)) {
            return null;
        }
        
        // Execute 'du -sb' for total bytes used (suppress errors)
        $output = [];
        exec('du -sb ' . escapeshellarg($homeDir) . ' 2>/dev/null', $output);
        
        // Parse output: "<bytes>\t<path>"
        if (!empty($output[0])) {
            $parts = preg_split('/\s+/', $output[0]);
            return (int)$parts[0];
        }
        
        return null;
    }
}
