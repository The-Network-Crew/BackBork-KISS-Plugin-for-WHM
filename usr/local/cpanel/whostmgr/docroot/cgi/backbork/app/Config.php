<?php
/**
 * BackBork KISS - Configuration Manager
 * Stores per-user configuration for root and resellers
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

class BackBorkConfig {
    
    const CONFIG_DIR = '/usr/local/cpanel/3rdparty/backbork';
    const GLOBAL_CONFIG_FILE = '/usr/local/cpanel/3rdparty/backbork/global.json';
    
    /**
     * Constructor - Ensure config directory exists
     */
    public function __construct() {
        $this->ensureConfigDir();
    }
    
    /**
     * Ensure configuration directory exists
     */
    private function ensureConfigDir() {
        if (!is_dir(self::CONFIG_DIR)) {
            mkdir(self::CONFIG_DIR, 0700, true);
        }
        
        // User configs subdirectory
        $userConfigDir = self::CONFIG_DIR . '/users';
        if (!is_dir($userConfigDir)) {
            mkdir($userConfigDir, 0700, true);
        }
        
        // Schedules directory
        $schedulesDir = self::CONFIG_DIR . '/schedules';
        if (!is_dir($schedulesDir)) {
            mkdir($schedulesDir, 0700, true);
        }
        
        // Queue directory
        $queueDir = self::CONFIG_DIR . '/queue';
        if (!is_dir($queueDir)) {
            mkdir($queueDir, 0700, true);
        }
        
        // Logs directory
        $logsDir = self::CONFIG_DIR . '/logs';
        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0700, true);
        }
    }
    
    /**
     * Get user-specific configuration file path
     * 
     * @param string $user Username
     * @return string
     */
    private function getUserConfigFile($user) {
        return self::CONFIG_DIR . '/users/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $user) . '.json';
    }
    
    /**
     * Get user configuration
     * 
     * @param string $user Username
     * @return array
     */
    public function getUserConfig($user) {
        $configFile = $this->getUserConfigFile($user);
        $defaults = $this->getDefaults();
        
        if (!file_exists($configFile)) {
            return $defaults;
        }
        
        $content = file_get_contents($configFile);
        $config = json_decode($content, true);
        
        if (!is_array($config)) {
            return $defaults;
        }
        
        return array_merge($defaults, $config);
    }
    
    /**
     * Save user configuration
     * 
     * @param string $user Username
     * @param array $config Configuration data
     * @return array Result with success status
     */
    public function saveUserConfig($user, $config) {
        $configFile = $this->getUserConfigFile($user);
        
        // Sanitize configuration
        $sanitized = $this->sanitizeConfig($config);
        
        // Merge with existing config
        $existing = $this->getUserConfig($user);
        $merged = array_merge($existing, $sanitized);
        $merged['updated_at'] = date('Y-m-d H:i:s');
        
        // Save user config
        $result = file_put_contents($configFile, json_encode($merged, JSON_PRETTY_PRINT));
        
        if ($result === false) {
            return ['success' => false, 'message' => 'Failed to save configuration'];
        }
        
        chmod($configFile, 0600);
        
        // If root user, also sync global settings (debug_mode) to global config
        if ($user === 'root' && isset($sanitized['debug_mode'])) {
            $this->saveGlobalSetting('debug_mode', $sanitized['debug_mode']);
        }
        
        // Log config update with option names and values
        if (class_exists('BackBorkLog')) {
            $requestor = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : (BackBorkBootstrap::isCLI() ? 'cron' : 'local'));
            $changes = [];
            foreach ($sanitized as $key => $value) {
                if (is_bool($value)) {
                    $changes[] = $key . '=' . ($value ? 'true' : 'false');
                } elseif (is_array($value)) {
                    $changes[] = $key . '=' . json_encode($value);
                } else {
                    $changes[] = $key . '=' . $value;
                }
            }
            BackBorkLog::logEvent($user, 'config_update', $changes, true, 'Configuration saved', $requestor);
        }
        
        return ['success' => true, 'message' => 'Configuration saved successfully'];
    }
    
    /**
     * Save a setting to the global config file
     * 
     * @param string $key Setting key
     * @param mixed $value Setting value
     * @return bool Success
     */
    private function saveGlobalSetting($key, $value) {
        $globalConfig = [];
        if (file_exists(self::GLOBAL_CONFIG_FILE)) {
            $globalConfig = json_decode(file_get_contents(self::GLOBAL_CONFIG_FILE), true) ?: [];
        }
        $globalConfig[$key] = $value;
        $globalConfig['updated_at'] = date('Y-m-d H:i:s');
        
        $result = file_put_contents(self::GLOBAL_CONFIG_FILE, json_encode($globalConfig, JSON_PRETTY_PRINT));
        if ($result !== false) {
            chmod(self::GLOBAL_CONFIG_FILE, 0600);
            return true;
        }
        return false;
    }
    
    /**
     * Get default configuration
     * 
     * @return array
     */
    public function getDefaults() {
        return [
            'notify_email' => '',
            'slack_webhook' => '',
            'notify_success' => true,
            'notify_failure' => true,
            'notify_start' => false,
            'compression_level' => '5',
            'temp_directory' => '/home/backbork_tmp',
            'exclude_paths' => '',
            'default_retention' => 30,
            'default_schedule' => 'daily',
            'debug_mode' => false,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
    }
    
    /**
     * Check if debug mode is enabled (global setting)
     * 
     * @return bool
     */
    public static function isDebugMode() {
        $globalConfig = self::GLOBAL_CONFIG_FILE;
        if (file_exists($globalConfig)) {
            $config = json_decode(file_get_contents($globalConfig), true);
            return !empty($config['debug_mode']);
        }
        return false;
    }
    
    /**
     * Log debug message if debug mode is enabled
     * 
     * @param string $message Debug message
     */
    public static function debugLog($message) {
        if (self::isDebugMode()) {
            error_log('[BackBork DEBUG] ' . $message);
        }
    }
    
    /**
     * Sanitize configuration input
     * 
     * @param array $config Input configuration
     * @return array Sanitized configuration
     */
    private function sanitizeConfig($config) {
        $sanitized = [];
        
        // Email
        if (isset($config['notify_email'])) {
            $email = filter_var($config['notify_email'], FILTER_SANITIZE_EMAIL);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) || empty($email)) {
                $sanitized['notify_email'] = $email;
            }
        }
        
        // Slack webhook
        if (isset($config['slack_webhook'])) {
            $webhook = filter_var($config['slack_webhook'], FILTER_SANITIZE_URL);
            if (empty($webhook) || preg_match('/^https:\/\/hooks\.slack\.com\//', $webhook)) {
                $sanitized['slack_webhook'] = $webhook;
            }
        }
        
        // Booleans
        $booleans = ['notify_success', 'notify_failure', 'notify_start', 'debug_mode'];
        foreach ($booleans as $key) {
            if (isset($config[$key])) {
                $sanitized[$key] = (bool)$config[$key];
            }
        }
        
        // Compression level
        if (isset($config['compression_level'])) {
            $level = (int)$config['compression_level'];
            if ($level >= 1 && $level <= 9) {
                $sanitized['compression_level'] = (string)$level;
            }
        }
        
        // Temp directory
        if (isset($config['temp_directory'])) {
            $dir = preg_replace('/[^a-zA-Z0-9_\/\-.]/', '', $config['temp_directory']);
            if (strpos($dir, '/') === 0) {
                $sanitized['temp_directory'] = $dir;
            }
        }
        
        // Exclude paths
        if (isset($config['exclude_paths'])) {
            $paths = preg_replace('/[^a-zA-Z0-9_\/\-.\n]/', '', $config['exclude_paths']);
            $sanitized['exclude_paths'] = $paths;
        }
        
        // Retention
        if (isset($config['default_retention'])) {
            $retention = (int)$config['default_retention'];
            if ($retention >= 1 && $retention <= 365) {
                $sanitized['default_retention'] = $retention;
            }
        }
        
        // Pass through all other recognized config keys
        $passthrough = [
            'mysql_version', 'dbbackup_type', 'compression_option',
            'opt_incremental', 'opt_split', 'opt_use_backups',
            'skip_homedir', 'skip_publichtml', 'skip_mysql', 'skip_pgsql',
            'skip_logs', 'skip_mailconfig', 'skip_mailman', 'skip_dnszones',
            'skip_ssl', 'skip_bwdata', 'skip_quota', 'skip_ftpusers',
            'skip_domains', 'skip_acctdb', 'skip_apitokens', 'skip_authnlinks',
            'skip_locale', 'skip_passwd', 'skip_shell', 'skip_resellerconfig',
            'skip_userdata', 'skip_linkednodes', 'skip_integrationlinks',
            'db_backup_method', 'db_backup_target_dir',
            'mdb_compress', 'mdb_parallel', 'mdb_slave_info', 'mdb_galera_info',
            'mdb_parallel_threads', 'mdb_extra_args',
            'myb_compress', 'myb_incremental', 'myb_backup_dir', 'myb_extra_args'
        ];
        
        foreach ($passthrough as $key) {
            if (isset($config[$key])) {
                $sanitized[$key] = $config[$key];
            }
        }
        
        return $sanitized;
    }
    
    /**
     * Get global configuration (for root only)
     * 
     * @return array
     */
    public function getGlobalConfig() {
        if (!file_exists(self::GLOBAL_CONFIG_FILE)) {
            return [
                'max_concurrent_jobs' => 2,
                'max_queue_size' => 100,
                'enable_compression' => true,
                'log_retention_days' => 90
            ];
        }
        
        $content = file_get_contents(self::GLOBAL_CONFIG_FILE);
        return json_decode($content, true) ?: [];
    }
    
    /**
     * Save global configuration (for root only)
     * 
     * @param array $config Configuration data
     * @return array Result
     */
    public function saveGlobalConfig($config) {
        $result = file_put_contents(self::GLOBAL_CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT));
        
        if ($result === false) {
            return ['success' => false, 'message' => 'Failed to save global configuration'];
        }
        
        chmod(self::GLOBAL_CONFIG_FILE, 0600);
        
        return ['success' => true, 'message' => 'Global configuration saved'];
    }
    
    /**
     * Get exclude paths as array
     * 
     * @param string $user Username
     * @return array
     */
    public function getExcludePaths($user) {
        $config = $this->getUserConfig($user);
        $paths = isset($config['exclude_paths']) ? $config['exclude_paths'] : '';
        
        if (empty($paths)) {
            return [];
        }
        
        return array_filter(array_map('trim', explode("\n", $paths)));
    }
    
    /**
     * Get config directory path
     * 
     * @return string
     */
    public static function getConfigDir() {
        return self::CONFIG_DIR;
    }
}
