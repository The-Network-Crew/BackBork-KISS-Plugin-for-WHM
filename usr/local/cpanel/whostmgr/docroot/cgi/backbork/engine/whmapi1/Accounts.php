<?php
/**
 * BackBork KISS - WHM API Accounts Handler
 * Wrapper for WHM API account-related functions
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

class BackBorkWhmApiAccounts {
    
    const WHMAPI_BIN = '/usr/local/cpanel/bin/whmapi1';
    
    /**
     * Get list of accounts accessible by a user
     * Root can access all, resellers only their own
     * 
     * @param string $user Username
     * @param bool $isRoot Whether user is root
     * @return array
     */
    public function getAccessibleAccounts($user, $isRoot) {
        $accounts = [];
        
        // Build command
        $command = self::WHMAPI_BIN . ' listaccts --output=json';
        
        if (!$isRoot) {
            // For resellers, filter by owner
            $command .= ' searchtype=owner search=' . escapeshellarg($user);
        }
        
        $output = shell_exec($command);
        $data = json_decode($output, true);
        
        if (isset($data['data']['acct']) && is_array($data['data']['acct'])) {
            foreach ($data['data']['acct'] as $acct) {
                // Double-check ownership for resellers
                if (!$isRoot && isset($acct['owner']) && $acct['owner'] !== $user) {
                    continue;
                }
                
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
     * Get account summary for a specific account
     * 
     * @param string $account Account username
     * @return array|null
     */
    public function getAccountSummary($account) {
        $command = self::WHMAPI_BIN . ' accountsummary user=' . escapeshellarg($account) . ' --output=json';
        $output = shell_exec($command);
        $data = json_decode($output, true);
        
        if (isset($data['data']['acct'][0])) {
            return $data['data']['acct'][0];
        }
        
        return null;
    }
    
    /**
     * Check if an account is owned by a specific user
     * 
     * @param string $account Account username
     * @param string $owner Owner username
     * @return bool
     */
    public function isAccountOwnedBy($account, $owner) {
        $summary = $this->getAccountSummary($account);
        
        if ($summary && isset($summary['owner'])) {
            return $summary['owner'] === $owner;
        }
        
        return false;
    }
    
    /**
     * Get account owner
     * 
     * @param string $account Account username
     * @return string|null
     */
    public function getAccountOwner($account) {
        $summary = $this->getAccountSummary($account);
        return $summary ? ($summary['owner'] ?? null) : null;
    }
    
    /**
     * Check if account exists
     * 
     * @param string $account Account username
     * @return bool
     */
    public function accountExists($account) {
        return $this->getAccountSummary($account) !== null;
    }
    
    /**
     * Get account home directory
     * 
     * @param string $account Account username
     * @return string|null
     */
    public function getAccountHomeDir($account) {
        $summary = $this->getAccountSummary($account);
        return $summary ? ($summary['homedir'] ?? '/home/' . $account) : null;
    }
}
