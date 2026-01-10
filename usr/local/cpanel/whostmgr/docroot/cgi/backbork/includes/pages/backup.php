<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/
 *
 *  THIS FILE:
 *   Backup panel template for creating ad-hoc backups.
 *   Interface for selecting destinations, accounts, and executing backups.
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
     BACKUP PANEL
     Interface for creating ad-hoc backups and queueing backup jobs
     - Select remote destination from WHM's configured destinations
     - Choose one or more accounts to back up
     - Execute immediately or add to queue for cron processing
======================================================================== -->
<div id="panel-backup" class="backbork-panel active">
    <div class="backbork-card">
        <h3>Create New Backup</h3>
        
        <!-- Destination Selector: Populated via JavaScript from WHM API -->
        <div class="form-row">
            <div class="form-group">
                <label for="backup-destination">Backup Storage</label>
                <select id="backup-destination" class="destination-select">
                    <option value="">Loading destinations...</option>
                </select>
            </div>
        </div>

        <!-- Account Selection: Checkboxes for each accessible account -->
        <div class="form-group">
            <label>Select Account/s to Backup</label>
            <div class="account-list" id="backup-account-list">
                <div class="select-all-container">
                    <!-- Toggle all visible accounts at once -->
                    <label>
                        <input type="checkbox" id="select-all-backup"> Select All
                    </label>
                </div>
                <div id="backup-accounts-container">
                    <div class="loading-spinner"></div> Loading accounts...
                </div>
            </div>
        </div>

        <!-- Action Buttons: Immediate backup or queue for later -->
        <div class="form-row">
            <div class="form-group">
                <!-- Backup Now: Runs immediately (may take a while) -->
                <button type="button" class="btn btn-primary" id="btn-backup-now">
                    📦 Backup Now
                </button>
                <!-- Add to Queue: Job will run on next cron cycle -->
                <button type="button" class="btn btn-secondary" id="btn-backup-queue">
                    📋 Add to Queue
                </button>
            </div>
        </div>
    </div>

    <!-- Progress Display: Shown during active backup operations -->
    <div id="backup-progress" class="backbork-card" style="display: none;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
            <h3 style="margin: 0;">Backup Progress</h3>
            <div id="backup-status-message"></div>
        </div>
        <!-- Progress bar updated via JavaScript polling -->
        <div class="progress-bar">
            <div class="progress-bar-fill" id="backup-progress-bar" style="width: 0%"></div>
        </div>
        <!-- Real-time log output from backup process -->
        <div id="backup-log"></div>
    </div>
</div>
