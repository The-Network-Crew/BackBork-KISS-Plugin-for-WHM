<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/
 *
 *  THIS FILE:
 *   Central initialization handler loading all required classes.
 *   Verifies user access and provides utility methods for the lifecycle.
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

// ============================================================================
// SECURITY & ERROR HANDLING
// ============================================================================

// Prevent direct file access - must be loaded via index.php or similar
if (!defined('BACKBORK_VERSION')) {
    die('Direct access not permitted');
}

// Error handling configuration
error_reporting(E_ALL);           // Catch all PHP errors
ini_set('display_errors', 0);     // Don't display errors to user (security)
ini_set('log_errors', 1);         // Log errors to PHP error log

// ============================================================================
// PATH DEFINITIONS
// ============================================================================

// Define base path for the plugin (parent of app/ directory)
define('BACKBORK_BASE_PATH', dirname(__DIR__));

// ============================================================================
// WHM INTEGRATION
// ============================================================================

// Include cPanel's WHM PHP library for header/footer rendering
// This automatically handles WHM's authentication and session
require_once('/usr/local/cpanel/php/WHM.php');

/**
 * Class BackBorkBootstrap
 * 
 * Static class handling application initialization and dependency management.
 * All methods are static to allow easy access without instantiation.
 */
class BackBorkBootstrap {
    
    // ========================================================================
    // STATIC PROPERTIES
    // ========================================================================
    
    /** @var bool Tracks whether init() has already been called */
    private static $initialised = false;
    
    /** @var BackBorkACL Singleton ACL instance for access control */
    private static $acl = null;
    
    /** @var array Cache of instantiated class singletons */
    private static $instances = [];
    
    // ========================================================================
    // INITIALIZATION
    // ========================================================================
    
    /**
     * Initialise the BackBork application
     * 
     * Loads all required class files, initialises ACL, and optionally
     * verifies user permissions. This must be called before using any
     * BackBork functionality.
     * 
     * @param bool $checkAcl Whether to verify ACL permissions (false for CLI)
     * @return bool True if initialization successful and user has access
     */
    public static function init($checkAcl = true) {
        // Prevent double initialization
        if (self::$initialised) {
            return true;
        }
        
        // === LOAD APPLICATION CLASSES ===
        // Core app functionality
        require_once(BACKBORK_BASE_PATH . '/app/ACL.php');        // Access control
        require_once(BACKBORK_BASE_PATH . '/app/Config.php');     // Configuration management
        require_once(BACKBORK_BASE_PATH . '/app/Notify.php');     // Email/Slack notifications
        require_once(BACKBORK_BASE_PATH . '/app/Queue.php');      // Job queue management
        require_once(BACKBORK_BASE_PATH . '/app/Log.php');        // Operation logging
        
        // === LOAD ENGINE CLASSES ===
        // WHM API wrappers
        require_once(BACKBORK_BASE_PATH . '/engine/whmapi1/Accounts.php');   // Account listing/ownership
        require_once(BACKBORK_BASE_PATH . '/engine/whmapi1/System.php');     // System info (DB version)
        
        // Transport layer (file transfer)
        require_once(BACKBORK_BASE_PATH . '/engine/transport/TransportInterface.php');  // Interface definition
        require_once(BACKBORK_BASE_PATH . '/engine/transport/Native.php');              // WHM native destinations
        require_once(BACKBORK_BASE_PATH . '/engine/transport/Local.php');               // Local filesystem
        
        // Destination handling
        require_once(BACKBORK_BASE_PATH . '/engine/destinations/Parser.php');     // Parse WHM destinations
        require_once(BACKBORK_BASE_PATH . '/engine/destinations/Validator.php');  // Validate destinations
        
        // Backup engine
        require_once(BACKBORK_BASE_PATH . '/engine/backup/SQL.php');              // Hot DB backups (mariadb-backup/mysqlbackup)
        require_once(BACKBORK_BASE_PATH . '/engine/backup/Pkgacct.php');          // pkgacct wrapper
        require_once(BACKBORK_BASE_PATH . '/engine/backup/Manifest.php');         // Backup tracking for pruning
        require_once(BACKBORK_BASE_PATH . '/engine/backup/BackupManager.php');    // Backup orchestration
        
        // Restore engine
        require_once(BACKBORK_BASE_PATH . '/engine/restore/SQL.php');             // Database restore
        require_once(BACKBORK_BASE_PATH . '/engine/restore/Retrieval.php');       // Download backups
        require_once(BACKBORK_BASE_PATH . '/engine/restore/RestoreManager.php');  // Restore orchestration
        
        // Queue processing
        require_once(BACKBORK_BASE_PATH . '/engine/queue/QueueProcessor.php');    // Cron job processor
        
        // === INITIALIZE ACL ===
        self::$acl = new BackBorkACL();
        
        // === VERIFY ACCESS (optional) ===
        // For web requests, verify user has list-accts permission
        if ($checkAcl && !self::$acl->hasAccess('list-accts')) {
            return false;  // Access denied
        }
        
        // Mark as initialised
        self::$initialised = true;
        return true;
    }
    
    /**
     * Initialise for CLI (cron) usage
     * 
     * Skips ACL checks since cron runs as root.
     * Use this instead of init() in cron/handler.php.
     * 
     * @return bool Always true after loading classes
     */
    public static function initCLI() {
        return self::init(false);
    }
    
    // ========================================================================
    // ACCESSORS
    // ========================================================================
    
    /**
     * Get the ACL instance
     * 
     * Initialises if not already done. Used throughout the app
     * for permission checking and user identification.
     * 
     * @return BackBorkACL ACL handler instance
     */
    public static function getACL() {
        // Lazy initialization if needed
        if (!self::$acl) {
            self::init(false);
        }
        return self::$acl;
    }
    
    /**
     * Get current authenticated user's username
     * 
     * Convenience method wrapping ACL::getCurrentUser()
     * 
     * @return string Username (e.g., 'root' or reseller name)
     */
    public static function getCurrentUser() {
        return self::getACL()->getCurrentUser();
    }
    
    /**
     * Check if current user is root
     * 
     * Convenience method wrapping ACL::isRoot()
     * 
     * @return bool True if user is root
     */
    public static function isRoot() {
        return self::getACL()->isRoot();
    }
    
    /**
     * Get or create a singleton instance of a class
     * 
     * Useful for classes that should only be instantiated once
     * (e.g., database connections, managers).
     * 
     * @param string $className Fully qualified class name
     * @return object Instance of the requested class
     */
    public static function getInstance($className) {
        // Check cache first
        if (!isset(self::$instances[$className])) {
            // Create and cache new instance
            self::$instances[$className] = new $className();
        }
        return self::$instances[$className];
    }
    
    // ========================================================================
    // UTILITY METHODS
    // ========================================================================
    
    /**
     * Display access denied page and terminate
     * 
     * Shows a WHM-styled error page when user lacks permission.
     * Exits after displaying the page.
     */
    public static function accessDenied() {
        WHM::header('BackBork KISS - Access Denied', 0, 0);
        echo '<div class="alert alert-danger">Access Denied. You do not have permission to access this plugin.</div>';
        WHM::footer();
        exit;
    }
    
    /**
     * Check if running from command line (CLI)
     * 
     * Used to detect cron execution vs web request.
     * 
     * @return bool True if running from CLI (e.g., cron)
     */
    public static function isCLI() {
        return php_sapi_name() === 'cli' || !isset($_SERVER['REQUEST_METHOD']);
    }
    
    /**
     * Get the requestor identifier (IP address or 'cron')
     * 
     * Returns the client IP address for web requests, or 'cron' for CLI execution.
     * Handles X-Forwarded-For header for requests behind proxies.
     * Used for logging and notifications to identify who initiated an operation.
     * 
     * @return string IP address or 'cron'
     */
    public static function getRequestor() {
        if (self::isCLI()) {
            return 'cron';
        }
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        }
        if (isset($_SERVER['REMOTE_ADDR'])) {
            return $_SERVER['REMOTE_ADDR'];
        }
        return 'unknown';
    }
    
    /**
     * Get the plugin base path
     * 
     * @return string Absolute path to plugin root directory
     */
    public static function getBasePath() {
        return BACKBORK_BASE_PATH;
    }
}
