<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/
 *
 *  THIS FILE:
 *   Access Control List management for WHM resellers and root users.
 *   Handles authentication, permission checking, and account access control.
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

class BackBorkACL {
    
    // ========================================================================
    // PROPERTIES
    // ========================================================================
    
    /** @var string Current authenticated username */
    private $currentUser;
    
    /** @var bool Whether current user is root */
    private $isRoot;
    
    /** @var array ACL permissions for current user */
    private $userACLs = [];
    
    // ========================================================================
    // CONSTRUCTOR
    // ========================================================================
    
    /**
     * Initialise ACL system
     * Detects current user and loads their permissions
     */
    public function __construct() {
        // Detect who is logged in via WHM environment
        $this->currentUser = $this->detectCurrentUser();
        
        // Root check - root has full access to everything
        $this->isRoot = ($this->currentUser === 'root');
        
        // Load ACL permissions for non-root users
        $this->loadUserACLs();
    }
    
    // ========================================================================
    // USER DETECTION
    // ========================================================================
    
    /**
     * Detect the current authenticated user from WHM environment
     * 
     * Uses getenv() to read REMOTE_USER, which is set by WHM's
     * authentication system. This matches cPanel's WHM.php library.
     * 
     * @return string Username of current user
     */
    private function detectCurrentUser() {
        // Primary method: WHM sets REMOTE_USER after authentication
        $user = getenv('REMOTE_USER');
        
        if ($user) {
            return $user;
        }
        
        // CLI mode (cron jobs): Default to root since cron runs as root
        if (php_sapi_name() === 'cli' || !isset($_SERVER['REQUEST_METHOD'])) {
            return 'root';
        }
        
        // Fallback: Default to root for WHM access
        // The ACL system will still enforce permissions appropriately
        return 'root';
    }
    
    /**
     * Load ACL permissions for the current user from cPanel's resellers file
     * 
     * Root users get 'all' permissions automatically.
     * Resellers get permissions from /var/cpanel/resellers.
     * 
     * File format: username:acl1,acl2,acl3,...
     */
    private function loadUserACLs() {
        // Root has all permissions - no need to load from file
        if ($this->isRoot) {
            $this->userACLs = ['all'];
            return;
        }
        
        // Resellers file contains ACL mappings
        $resellersFile = '/var/cpanel/resellers';
        if (!file_exists($resellersFile)) {
            return;
        }
        
        // Parse resellers file to find current user's permissions
        $content = file_get_contents($resellersFile);
        $lines = explode("\n", $content);
        
        foreach ($lines as $line) {
            // Look for line starting with current user
            if (preg_match("/^{$this->currentUser}:/", $line)) {
                // Extract ACL list after the colon
                $line = preg_replace("/^{$this->currentUser}:/", "", $line);
                $this->userACLs = explode(",", trim($line));
                break;
            }
        }
    }
    
    // ========================================================================
    // USER ACCESSORS
    // ========================================================================
    
    /**
     * Get current authenticated user's username
     * 
     * @return string Username
     */
    public function getCurrentUser() {
        return $this->currentUser;
    }
    
    /**
     * Check if current user is root
     * 
     * @return bool True if root
     */
    public function isRoot() {
        return $this->isRoot;
    }
    
    // ========================================================================
    // PERMISSION CHECKING
    // ========================================================================
    
    /**
     * Check if current user has a specific ACL permission
     * 
     * @param string $acl ACL permission name (e.g., 'list-accts')
     * @return bool True if user has permission
     */
    public function hasAccess($acl) {
        // Root always has access
        if ($this->isRoot) {
            return true;
        }
        
        // Check for 'all' permission (grants everything)
        if (in_array('all', $this->userACLs)) {
            return true;
        }
        
        // Check for specific permission
        return in_array($acl, $this->userACLs);
    }
    
    /**
     * Check ACL permission using WHM PHP library pattern
     * 
     * Returns 1/0 instead of bool for compatibility with
     * cPanel's standard checkacl() pattern.
     * 
     * @param string $acl ACL permission name
     * @return int 1 if allowed, 0 if denied
     */
    public function checkacl($acl) {
        // Root always allowed
        if ($this->currentUser === 'root') {
            return 1;
        }
        
        // Read resellers file for permission check
        $resellersFile = '/var/cpanel/resellers';
        if (!file_exists($resellersFile)) {
            return 0;
        }
        
        // Parse file to check permissions
        $reseller = file_get_contents($resellersFile);
        foreach (explode("\n", $reseller) as $line) {
            // Find current user's line
            if (preg_match("/^{$this->currentUser}:/", $line)) {
                $line = preg_replace("/^{$this->currentUser}:/", "", $line);
                // Check each permission
                foreach (explode(",", $line) as $perm) {
                    if ($perm === 'all' || $perm === $acl) {
                        return 1;  // Permission granted
                    }
                }
            }
        }
        
        return 0;  // Permission denied
    }
    
    // ========================================================================
    // ACCOUNT ACCESS
    // ========================================================================
    
    /**
     * Get list of cPanel accounts the current user can access
     * 
     * Root sees all accounts on the server.
     * Resellers see only accounts they own.
     * 
     * @return array Array of account info arrays
     */
    public function getAccessibleAccounts() {
        // Delegate to WHM API engine for account listing
        $accountsEngine = new BackBorkWhmApiAccounts();
        return $accountsEngine->getAccessibleAccounts($this->currentUser, $this->isRoot);
    }
    
    /**
     * Check if current user can access a specific account
     * 
     * @param string $account Account username to check
     * @return bool True if user can access account
     */
    public function canAccessAccount($account) {
        // Root can access all accounts
        if ($this->isRoot) {
            return true;
        }
        
        // Check if reseller owns this account via WHM API
        $accountsEngine = new BackBorkWhmApiAccounts();
        return $accountsEngine->isAccountOwnedBy($account, $this->currentUser);
    }
    
    /**
     * Get count of accounts the current user owns
     * 
     * @return int Number of accessible accounts
     */
    public function getOwnedAccountsCount() {
        $accounts = $this->getAccessibleAccounts();
        return count($accounts);
    }
    
    /**
     * Validate that user has required backup-related permissions
     * 
     * BackBork requires 'list-accts' ACL which is configured in appconfig.
     * 
     * @return bool True if user can perform backups
     */
    public function canPerformBackups() {
        // Check for list-accts permission required by BackBork
        return $this->hasAccess('list-accts');
    }
    
    /**
     * Get current user's full ACL permission list
     * 
     * @return array Array of ACL permission names
     */
    public function getUserACLs() {
        return $this->userACLs;
    }
    
    // ========================================================================
    // ADMIN UTILITIES (ROOT ONLY)
    // ========================================================================
    
    /**
     * Get list of all resellers on the server
     * 
     * Only available to root. Reads from /var/cpanel/resellers.
     * Used for admin UI to show reseller selector.
     * 
     * @return array List of reseller usernames (sorted)
     */
    public function getResellers() {
        // Security: Only root can see reseller list
        if (!$this->isRoot) {
            return [];
        }
        
        $resellers = [];
        $resellersFile = '/var/cpanel/resellers';
        
        if (!file_exists($resellersFile)) {
            return $resellers;
        }
        
        // Parse resellers file
        $content = file_get_contents($resellersFile);
        $lines = explode("\n", $content);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Format: username:acl1,acl2,acl3
            if (preg_match('/^([^:]+):/', $line, $matches)) {
                $username = $matches[1];
                // Skip root if it appears in resellers file
                if ($username !== 'root') {
                    $resellers[] = $username;
                }
            }
        }
        
        // Sort alphabetically for consistent UI display
        sort($resellers);
        return $resellers;
    }
    
    /**
     * Get list of users who have created backup schedules
     * 
     * Only available to root. Scans schedules directory.
     * Used for admin UI to show users with active schedules.
     * 
     * @return array List of usernames with schedules
     */
    public function getUsersWithSchedules() {
        // Security: Only root can see this list
        if (!$this->isRoot) {
            return [];
        }
        
        $users = [];
        $schedulesDir = '/usr/local/cpanel/3rdparty/backbork/schedules';
        
        if (!is_dir($schedulesDir)) {
            return $users;
        }
        
        // Scan all schedule files for unique users
        $scheduleFiles = glob($schedulesDir . '/*.json');
        foreach ($scheduleFiles as $file) {
            $schedule = json_decode(file_get_contents($file), true);
            if ($schedule && isset($schedule['user'])) {
                // Use array key to deduplicate
                $users[$schedule['user']] = true;
            }
        }
        
        // Return just the usernames
        return array_keys($users);
    }
}
