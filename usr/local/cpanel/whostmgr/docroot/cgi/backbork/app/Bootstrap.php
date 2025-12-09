<?php
/**
 * BackBork KISS - Application Bootstrap
 * Handles initialization, class loading, and ACL verification
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

// Prevent direct access
if (!defined('BACKBORK_VERSION')) {
    die('Direct access not permitted');
}

// Error handling - log errors but don't display
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Base path for the plugin
define('BACKBORK_BASE_PATH', dirname(__DIR__));

// Include WHM PHP library (handles headers automatically)
require_once('/usr/local/cpanel/php/WHM.php');

/**
 * Class BackBorkBootstrap
 * Handles application initialization and dependency loading
 */
class BackBorkBootstrap {
    
    /** @var bool Whether the app has been initialized */
    private static $initialized = false;
    
    /** @var BackBorkACL ACL instance */
    private static $acl = null;
    
    /** @var array Loaded class instances */
    private static $instances = [];
    
    /**
     * Initialize the application
     * Loads all required classes and verifies ACL
     * 
     * @param bool $checkAcl Whether to perform ACL check (disable for CLI)
     * @return bool
     */
    public static function init($checkAcl = true) {
        if (self::$initialized) {
            return true;
        }
        
        // Load app classes
        require_once(BACKBORK_BASE_PATH . '/app/ACL.php');
        require_once(BACKBORK_BASE_PATH . '/app/Config.php');
        require_once(BACKBORK_BASE_PATH . '/app/Notify.php');
        require_once(BACKBORK_BASE_PATH . '/app/Queue.php');
        require_once(BACKBORK_BASE_PATH . '/app/Log.php');
        
        // Load engine classes
        require_once(BACKBORK_BASE_PATH . '/engine/whmapi1/Accounts.php');
        require_once(BACKBORK_BASE_PATH . '/engine/whmapi1/System.php');
        require_once(BACKBORK_BASE_PATH . '/engine/transport/TransportInterface.php');
        require_once(BACKBORK_BASE_PATH . '/engine/transport/Native.php');
        require_once(BACKBORK_BASE_PATH . '/engine/transport/Local.php');
        require_once(BACKBORK_BASE_PATH . '/engine/destinations/Parser.php');
        require_once(BACKBORK_BASE_PATH . '/engine/destinations/Validator.php');
        require_once(BACKBORK_BASE_PATH . '/engine/backup/Pkgacct.php');
        require_once(BACKBORK_BASE_PATH . '/engine/backup/BackupManager.php');
        require_once(BACKBORK_BASE_PATH . '/engine/restore/Retrieval.php');
        require_once(BACKBORK_BASE_PATH . '/engine/restore/RestoreManager.php');
        require_once(BACKBORK_BASE_PATH . '/engine/queue/QueueProcessor.php');
        
        // Initialize ACL
        self::$acl = new BackBorkACL();
        
        // Verify access if required
        if ($checkAcl && !self::$acl->hasAccess('list-accts')) {
            return false;
        }
        
        self::$initialized = true;
        return true;
    }
    
    /**
     * Initialize for CLI (cron) usage
     * Skips ACL check and WHM header requirements
     * 
     * @return bool
     */
    public static function initCLI() {
        return self::init(false);
    }
    
    /**
     * Get ACL instance
     * 
     * @return BackBorkACL
     */
    public static function getACL() {
        if (!self::$acl) {
            self::init(false);
        }
        return self::$acl;
    }
    
    /**
     * Get current user
     * 
     * @return string
     */
    public static function getCurrentUser() {
        return self::getACL()->getCurrentUser();
    }
    
    /**
     * Check if current user is root
     * 
     * @return bool
     */
    public static function isRoot() {
        return self::getACL()->isRoot();
    }
    
    /**
     * Get or create a singleton instance of a class
     * 
     * @param string $className Class name
     * @return object
     */
    public static function getInstance($className) {
        if (!isset(self::$instances[$className])) {
            self::$instances[$className] = new $className();
        }
        return self::$instances[$className];
    }
    
    /**
     * Display access denied page and exit
     */
    public static function accessDenied() {
        WHM::header('BackBork KISS - Access Denied', 0, 0);
        echo '<div class="alert alert-danger">Access Denied. You do not have permission to access this plugin.</div>';
        WHM::footer();
        exit;
    }
    
    /**
     * Check if running from CLI
     * 
     * @return bool
     */
    public static function isCLI() {
        return php_sapi_name() === 'cli' || !isset($_SERVER['REQUEST_METHOD']);
    }
    
    /**
     * Get base path
     * 
     * @return string
     */
    public static function getBasePath() {
        return BACKBORK_BASE_PATH;
    }
}
