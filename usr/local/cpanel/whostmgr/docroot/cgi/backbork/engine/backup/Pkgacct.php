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

class BackBorkPkgacct {
    
    const PKGACCT_BIN = '/scripts/pkgacct';
    const DEFAULT_TEMP_DIR = '/home/backbork_tmp';
    
    /**
     * Check if pkgacct binary exists
     * 
     * @return bool
     */
    public function isAvailable() {
        return file_exists(self::PKGACCT_BIN) && is_executable(self::PKGACCT_BIN);
    }
    
    /**
     * Build pkgacct command options from user config
     * 
     * @param array $userConfig User configuration
     * @return string Command line options
     */
    public function buildOptions($userConfig) {
        $options = '';
        
        // Compression option (--compress or --nocompress, no argument)
        if (isset($userConfig['compression_option'])) {
            if ($userConfig['compression_option'] === 'nocompress') {
                $options .= ' --nocompress';
            } else {
                $options .= ' --compress';
            }
        }
        
        // MySQL version
        if (!empty($userConfig['mysql_version'])) {
            $options .= ' --mysql=' . escapeshellarg($userConfig['mysql_version']);
        }
        
        // Database backup type
        if (!empty($userConfig['dbbackup_type']) && $userConfig['dbbackup_type'] !== 'all') {
            $options .= ' --dbbackup=' . escapeshellarg($userConfig['dbbackup_type']);
        }
        
        // Backup mode options
        if (!empty($userConfig['opt_incremental'])) {
            $options .= ' --incremental';
        }
        if (!empty($userConfig['opt_split'])) {
            $options .= ' --split';
        }
        if (!empty($userConfig['opt_use_backups'])) {
            $options .= ' --use_backups';
        }
        
        // Skip options - map config keys to pkgacct flags
        $skipOptions = [
            'skip_homedir' => '--skiphomedir',
            'skip_publichtml' => '--skippublichtml',
            'skip_mysql' => '--skipmysql',
            'skip_pgsql' => '--skippgsql',
            'skip_logs' => '--skiplogs',
            'skip_mailconfig' => '--skipmailconfig',
            'skip_mailman' => '--skipmailman',
            'skip_dnszones' => '--skipdnszones',
            'skip_ssl' => '--skipssl',
            'skip_bwdata' => '--skipbwdata',
            'skip_quota' => '--skipquota',
            'skip_ftpusers' => '--skipftpusers',
            'skip_domains' => '--skipdomains',
            'skip_acctdb' => '--skipacctdb',
            'skip_apitokens' => '--skipapitokens',
            'skip_authnlinks' => '--skipauthnlinks',
            'skip_locale' => '--skiplocale',
            'skip_passwd' => '--skippasswd',
            'skip_shell' => '--skipshell',
            'skip_resellerconfig' => '--skipresellerconfig',
            'skip_userdata' => '--skipuserdata',
            'skip_linkednodes' => '--skiplinkednodes',
            'skip_integrationlinks' => '--skipintegrationlinks',
        ];
        
        foreach ($skipOptions as $configKey => $flag) {
            if (!empty($userConfig[$configKey])) {
                $options .= ' ' . $flag;
            }
        }
        
        return $options;
    }
    
    /**
     * Execute pkgacct for an account
     * 
     * @param string $account Account username
     * @param string $workDir Working directory for backup output
     * @param array $userConfig User configuration for options
     * @return array Result with success status and details
     */
    public function execute($account, $workDir, $userConfig = []) {
        if (!$this->isAvailable()) {
            return [
                'success' => false,
                'message' => 'pkgacct binary not found at ' . self::PKGACCT_BIN
            ];
        }
        
        // Ensure work directory exists
        if (!is_dir($workDir)) {
            if (!mkdir($workDir, 0700, true)) {
                return [
                    'success' => false,
                    'message' => "Failed to create work directory: {$workDir}"
                ];
            }
        }
        
        // Build command
        $command = self::PKGACCT_BIN;
        $command .= $this->buildOptions($userConfig);
        $command .= ' ' . escapeshellarg($account);
        $command .= ' ' . escapeshellarg($workDir);
        
        // Execute
        $output = [];
        $returnCode = 0;
        exec($command . ' 2>&1', $output, $returnCode);
        
        $outputStr = implode("\n", $output);
        
        if ($returnCode !== 0) {
            return [
                'success' => false,
                'message' => "pkgacct failed (exit code {$returnCode}): " . $outputStr,
                'command' => $command,
                'output' => $output,
                'return_code' => $returnCode
            ];
        }
        
        // Find the created backup file
        $backupFile = $this->findCreatedBackup($account, $workDir);
        
        if (!$backupFile) {
            return [
                'success' => false,
                'message' => "Backup file not found after pkgacct completed",
                'command' => $command,
                'output' => $output,
                'dir_contents' => glob($workDir . '/*')
            ];
        }
        
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
     * Find the backup file created by pkgacct
     * 
     * @param string $account Account username
     * @param string $workDir Working directory
     * @return string|null Filename or null if not found
     */
    private function findCreatedBackup($account, $workDir) {
        // Standard naming: cpmove-<account>.tar.gz
        $standardName = 'cpmove-' . $account . '.tar.gz';
        if (file_exists($workDir . '/' . $standardName)) {
            return $standardName;
        }
        
        // Try pattern matching
        $pattern = $workDir . '/cpmove-' . $account . '*';
        $files = glob($pattern);
        
        if (!empty($files)) {
            return basename($files[0]);
        }
        
        return null;
    }
    
    /**
     * Get estimated backup size for an account
     * 
     * @param string $account Account username
     * @return int|null Estimated size in bytes
     */
    public function estimateBackupSize($account) {
        // Get home directory size
        $homeDir = '/home/' . $account;
        
        if (!is_dir($homeDir)) {
            return null;
        }
        
        $output = [];
        exec('du -sb ' . escapeshellarg($homeDir) . ' 2>/dev/null', $output);
        
        if (!empty($output[0])) {
            $parts = preg_split('/\s+/', $output[0]);
            return (int)$parts[0];
        }
        
        return null;
    }
}
