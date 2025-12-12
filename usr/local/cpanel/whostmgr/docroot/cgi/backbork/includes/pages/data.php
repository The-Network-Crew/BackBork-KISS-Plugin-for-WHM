<?php
/**
 * BackBork KISS - Data Management Panel
 * Browse and delete backup files
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
?>
<!-- ========================================================================
     DATA MANAGEMENT PANEL
     Browse backup files by account and delete if needed
     - Left sidebar: Account list (A-Z)
     - Right panel: Backup files for selected account
======================================================================== -->
<div id="panel-data" class="backbork-panel">
    <div class="backbork-card">
        <h3>Data Management</h3>
        
        <!-- Destination selector -->
        <div class="form-group" style="margin-bottom: 20px;">
            <label for="data-destination">Backup Storage</label>
            <select id="data-destination" class="destination-select" onchange="loadDataAccounts()">
                <option value="">Loading destinations...</option>
            </select>
        </div>
        
        <!-- Two-column layout: accounts list + backups list -->
        <div class="data-browser">
            <!-- Left: Account List (20%) -->
            <div class="accounts-sidebar">
                <div class="accounts-header">
                    <i class="fas fa-users"></i> Accounts
                </div>
                <div class="accounts-list" id="data-accounts-list">
                    <div class="accounts-empty">Select a destination</div>
                </div>
            </div>
            
            <!-- Right: Backups List (80%) -->
            <div class="backups-main">
                <div class="backups-header">
                    <span class="backups-title" id="data-backups-title">
                        <i class="fas fa-archive"></i> Backups
                    </span>
                    <span class="backups-count" id="data-backups-count"></span>
                </div>
                <div class="backups-list" id="data-backups-list">
                    <div class="backups-placeholder">
                        <i class="fas fa-folder-open"></i>
                        <p>Select an account to view backups</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
