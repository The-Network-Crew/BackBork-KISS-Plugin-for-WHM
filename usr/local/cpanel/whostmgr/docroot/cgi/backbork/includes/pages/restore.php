<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-Plugin-for-WHM/
 *
 *  THIS FILE:
 *   Restore panel for restoring cPanel accounts from backup destinations.
 *   Interface for selecting source, account, backup file, and restore options.
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
?>
<!-- ========================================================================
     RESTORE PANEL
     Interface for restoring cPanel accounts from remote backup destinations
     - Select source destination and account
     - Browse available backup files
     - Choose granular restore options (home dir, mysql, mail, etc.)
======================================================================== -->
<div id="panel-restore" class="backbork-panel">
    <div class="backbork-card">
        <h3>Restore from Backup</h3>
        
        <!-- Source Selection: Destination and account dropdowns -->
        <div class="form-row">
            <div class="form-group">
                <!-- Select which remote destination to restore from -->
                <label for="restore-destination">Backup Storage</label>
                <select id="restore-destination" class="destination-select">
                    <option value="">Loading destinations...</option>
                </select>
            </div>
            <div class="form-group">
                <!-- Account dropdown populated after destination is selected -->
                <label for="restore-account">Account to Restore</label>
                <select id="restore-account">
                    <option value="">Select account...</option>
                </select>
            </div>
        </div>

        <!-- Backup File Selection: Shows available backups for selected account -->
        <div class="form-group">
            <label for="restore-backup-file">Available Backups</label>
            <select id="restore-backup-file">
                <option value="">Select destination and account first...</option>
            </select>
        </div>

        <!-- Restore Options Card: Granular control over what to restore -->
        <div class="backbork-card">
            <h3>Restore Options</h3>
            <!-- Each checkbox controls a specific restorepkg option -->
            <div class="checkbox-group">
                <label><input type="checkbox" name="restore_homedir" checked> Home Directory</label>
                <label><input type="checkbox" name="restore_mysql" checked> MySQL Databases</label>
                <label><input type="checkbox" name="restore_mail" checked> Email & Forwarders</label>
                <label><input type="checkbox" name="restore_ssl" checked> SSL Certificates</label>
                <label><input type="checkbox" name="restore_cron" checked> Cron Jobs</label>
                <label><input type="checkbox" name="restore_dns" checked> DNS Zones</label>
                <label><input type="checkbox" name="restore_subdomains" checked> Subdomains</label>
                <label><input type="checkbox" name="restore_addon_domains" checked> Addon Domains</label>
            </div>
        </div>

        <!-- Restore trigger button - opens confirmation modal -->
        <button type="button" class="btn btn-primary" id="btn-restore">
            🔄 Start Restore
        </button>
    </div>

    <!-- Progress Display: Shown during active restore operations -->
    <div id="restore-progress" class="backbork-card" style="display: none;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
            <h3 style="margin: 0;">Restore Progress</h3>
            <div id="restore-status-message"></div>
        </div>
        <!-- Progress bar updated via JavaScript polling -->
        <div class="progress-bar">
            <div class="progress-bar-fill" id="restore-progress-bar" style="width: 0%"></div>
        </div>
        <!-- Real-time log output from restore process -->
        <div id="restore-log"></div>
    </div>
</div>
