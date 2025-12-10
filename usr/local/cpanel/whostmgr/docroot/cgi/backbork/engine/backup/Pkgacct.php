<?php
/**
 * BackBork KISS - Pkgacct Wrapper
 * Wraps WHM's /scripts/pkgacct for account packaging
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
        
        // Database backup type: 'all', 'mysql', 'pgsql', etc.
        if (!empty($userConfig['dbbackup_type']) && $userConfig['dbbackup_type'] !== 'all') {
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
     * @return array Result with success status, file path, and execution details
     */
    public function execute($account, $workDir, $userConfig = []) {
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
        
        // Execute pkgacct and capture output
        $output = [];
        $returnCode = 0;
        exec($command . ' 2>&1', $output, $returnCode);  // Capture stderr too
        
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
     * Find the backup file created by pkgacct.
     * Searches for standard cpmove-*.tar.gz naming convention.
     * 
     * @param string $account Account username
     * @param string $workDir Working directory to search
     * @return string|null Filename if found, null otherwise
     */
    private function findCreatedBackup($account, $workDir) {
        // Check for standard naming: cpmove-<account>.tar.gz
        $standardName = 'cpmove-' . $account . '.tar.gz';
        if (file_exists($workDir . '/' . $standardName)) {
            return $standardName;
        }
        
        // Try pattern matching for non-standard names (e.g., with timestamps)
        $pattern = $workDir . '/cpmove-' . $account . '*';
        $files = glob($pattern);
        
        // Return first matching file if found
        if (!empty($files)) {
            return basename($files[0]);
        }
        
        return null;
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
