<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/
 *
 *  THIS FILE:
 *   WHM API wrapper for account-related operations.
 *   Lists, queries, and verifies cPanel accounts with ACL awareness.
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
 * WHM API wrapper for account-related operations.
 * Provides methods to list, query, and verify cPanel accounts.
 * Handles root vs reseller access restrictions.
 */
class BackBorkWhmApiAccounts {
    
    // Path to WHM API command-line tool
    const WHMAPI_BIN = '/usr/local/cpanel/bin/whmapi1';
    
    /**
     * Get list of accounts accessible by a specific user.
     * Root users see all accounts; resellers only see accounts they own.
     * 
     * @param string $user Username requesting the list
     * @param bool $isRoot Whether user is root (full access)
     * @return array List of account info arrays
     */
    public function getAccessibleAccounts($user, $isRoot) {
        $accounts = [];
        
        // Build whmapi1 listaccts command
        $command = self::WHMAPI_BIN . ' listaccts --output=json';
        
        // For non-root users (resellers), filter by owner
        if (!$isRoot) {
            $command .= ' searchtype=owner search=' . escapeshellarg($user);
        }
        
        // Execute API call
        $output = shell_exec($command);
        $data = json_decode($output, true);
        
        // Parse account list from API response
        if (isset($data['data']['acct']) && is_array($data['data']['acct'])) {
            foreach ($data['data']['acct'] as $acct) {
                // Double-check ownership for resellers (belt and suspenders)
                if (!$isRoot && isset($acct['owner']) && $acct['owner'] !== $user) {
                    continue;
                }
                
                // Build normalised account info array
                $accounts[] = [
                    'user' => $acct['user'],
                    'domain' => isset($acct['domain']) ? $acct['domain'] : '',
                    'owner' => isset($acct['owner']) ? $acct['owner'] : 'root',
                    'email' => isset($acct['email']) ? $acct['email'] : '',
                    'plan' => isset($acct['plan']) ? $acct['plan'] : '',
                    'suspended' => isset($acct['suspended']) ? (bool)$acct['suspended'] : false,
                    'suspendreason' => isset($acct['suspendreason']) ? $acct['suspendreason'] : ''
                ];
            }
        }
        
        return $accounts;
    }
    
    /**
     * Get detailed summary for a specific account.
     * Returns full account information from WHM.
     * 
     * @param string $account Account username
     * @return array|null Account info or null if not found
     */
    public function getAccountSummary($account) {
        // Call accountsummary API
        $command = self::WHMAPI_BIN . ' accountsummary user=' . escapeshellarg($account) . ' --output=json';
        $output = shell_exec($command);
        $data = json_decode($output, true);
        
        // Return first account result if found
        if (isset($data['data']['acct'][0])) {
            return $data['data']['acct'][0];
        }
        
        return null;
    }
    
    /**
     * Check if an account is owned by a specific user.
     * Used for reseller permission verification.
     * 
     * @param string $account Account username to check
     * @param string $owner Expected owner username
     * @return bool True if account is owned by specified user
     */
    public function isAccountOwnedBy($account, $owner) {
        $summary = $this->getAccountSummary($account);
        
        if ($summary && isset($summary['owner'])) {
            return $summary['owner'] === $owner;
        }
        
        return false;
    }
    
    /**
     * Get the owner of an account.
     * 
     * @param string $account Account username
     * @return string|null Owner username or null if not found
     */
    public function getAccountOwner($account) {
        $summary = $this->getAccountSummary($account);
        return $summary ? ($summary['owner'] ?? null) : null;
    }
    
    /**
     * Check if an account exists on the server.
     * 
     * @param string $account Account username
     * @return bool True if account exists
     */
    public function accountExists($account) {
        return $this->getAccountSummary($account) !== null;
    }
    
    /**
     * Get the home directory path for an account.
     * 
     * @param string $account Account username
     * @return string|null Home directory path or null if not found
     */
    public function getAccountHomeDir($account) {
        $summary = $this->getAccountSummary($account);
        // Fall back to standard /home/username if not specified
        return $summary ? ($summary['homedir'] ?? '/home/' . $account) : null;
    }
}
