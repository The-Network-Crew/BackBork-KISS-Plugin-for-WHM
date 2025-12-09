<?php
/**
 * BackBork KISS - ACL Handler
 * Manages access control for resellers and root users
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

class BackBorkACL {
    
    private $currentUser;
    private $isRoot;
    private $userACLs = [];
    
    /**
     * Constructor - Initialize ACL checking
     */
    public function __construct() {
        $this->currentUser = $this->detectCurrentUser();
        $this->isRoot = ($this->currentUser === 'root');
        $this->loadUserACLs();
    }
    
    /**
     * Detect the current authenticated user from WHM environment
     * Uses getenv() exactly like the WHM.php library does
     * 
     * @return string
     */
    private function detectCurrentUser() {
        // Use getenv() exactly like WHM.php library does
        $user = getenv('REMOTE_USER');
        
        if ($user) {
            return $user;
        }
        
        // If running from CLI (cron), default to root
        if (php_sapi_name() === 'cli' || !isset($_SERVER['REQUEST_METHOD'])) {
            return 'root';
        }
        
        // Default to root for WHM access - the ACL system will handle permissions
        // This handles edge cases where REMOTE_USER might not be set
        return 'root';
    }
    
    /**
     * Load ACLs for the current user from /var/cpanel/resellers
     */
    private function loadUserACLs() {
        if ($this->isRoot) {
            $this->userACLs = ['all'];
            return;
        }
        
        $resellersFile = '/var/cpanel/resellers';
        if (!file_exists($resellersFile)) {
            return;
        }
        
        $content = file_get_contents($resellersFile);
        $lines = explode("\n", $content);
        
        foreach ($lines as $line) {
            if (preg_match("/^{$this->currentUser}:/", $line)) {
                $line = preg_replace("/^{$this->currentUser}:/", "", $line);
                $this->userACLs = explode(",", trim($line));
                break;
            }
        }
    }
    
    /**
     * Get current user
     * 
     * @return string
     */
    public function getCurrentUser() {
        return $this->currentUser;
    }
    
    /**
     * Check if current user is root
     * 
     * @return bool
     */
    public function isRoot() {
        return $this->isRoot;
    }
    
    /**
     * Check if user has a specific ACL permission
     * 
     * @param string $acl ACL to check
     * @return bool
     */
    public function hasAccess($acl) {
        if ($this->isRoot) {
            return true;
        }
        
        if (in_array('all', $this->userACLs)) {
            return true;
        }
        
        return in_array($acl, $this->userACLs);
    }
    
    /**
     * Check ACL using the same logic as WHM PHP example
     * 
     * @param string $acl ACL to check
     * @return int 1 if allowed, 0 if denied
     */
    public function checkacl($acl) {
        if ($this->currentUser === 'root') {
            return 1;
        }
        
        $resellersFile = '/var/cpanel/resellers';
        if (!file_exists($resellersFile)) {
            return 0;
        }
        
        $reseller = file_get_contents($resellersFile);
        foreach (explode("\n", $reseller) as $line) {
            if (preg_match("/^{$this->currentUser}:/", $line)) {
                $line = preg_replace("/^{$this->currentUser}:/", "", $line);
                foreach (explode(",", $line) as $perm) {
                    if ($perm === 'all' || $perm === $acl) {
                        return 1;
                    }
                }
            }
        }
        
        return 0;
    }
    
    /**
     * Get list of accounts the current user can access
     * Root can access all, resellers can only access their own accounts
     * 
     * @return array
     */
    public function getAccessibleAccounts() {
        // Delegate to the WHM API engine
        $accountsEngine = new BackBorkWhmApiAccounts();
        return $accountsEngine->getAccessibleAccounts($this->currentUser, $this->isRoot);
    }
    
    /**
     * Check if user can access a specific account
     * 
     * @param string $account Account username
     * @return bool
     */
    public function canAccessAccount($account) {
        if ($this->isRoot) {
            return true;
        }
        
        // Use WHM API engine to check account ownership
        $accountsEngine = new BackBorkWhmApiAccounts();
        return $accountsEngine->isAccountOwnedBy($account, $this->currentUser);
    }
    
    /**
     * Get reseller's owned domains count
     * 
     * @return int
     */
    public function getOwnedAccountsCount() {
        $accounts = $this->getAccessibleAccounts();
        return count($accounts);
    }
    
    /**
     * Validate that user has backup-related permissions
     * 
     * @return bool
     */
    public function canPerformBackups() {
        // Check for list-accts which we require in appconfig
        return $this->hasAccess('list-accts');
    }
    
    /**
     * Get user's ACL list
     * 
     * @return array
     */
    public function getUserACLs() {
        return $this->userACLs;
    }
}
