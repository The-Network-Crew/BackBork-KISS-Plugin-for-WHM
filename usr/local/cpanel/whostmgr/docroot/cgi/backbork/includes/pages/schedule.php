<?php
/**
 * BackBork KISS - Schedule Panel
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
<div id="panel-schedule" class="backbork-panel">
    <div class="backbork-card">
        <h3>Scheduled Backups</h3>
        
        <div class="form-row">
            <div class="form-group">
                <label for="schedule-destination">Remote Destination</label>
                <select id="schedule-destination" class="destination-select">
                    <option value="">Loading destinations...</option>
                </select>
            </div>
            <div class="form-group">
                <label for="schedule-frequency">Schedule Frequency</label>
                <select id="schedule-frequency">
                    <option value="hourly">Hourly</option>
                    <option value="daily" selected>Daily</option>
                    <option value="weekly">Weekly</option>
                    <option value="monthly">Monthly</option>
                </select>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="schedule-retention">Retention Period (days)</label>
                <input type="number" id="schedule-retention" value="30" min="1" max="365">
            </div>
            <div class="form-group">
                <label for="schedule-time">Preferred Time (for daily)</label>
                <select id="schedule-time">
                    <?php for ($i = 0; $i < 24; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $i === 2 ? 'selected' : ''; ?>>
                            <?php echo sprintf('%02d:00', $i); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label>Select Accounts</label>
            <div class="account-list" id="schedule-account-list">
                <div class="select-all-container">
                    <label>
                        <input type="checkbox" id="select-all-schedule"> Select All
                    </label>
                </div>
                <div id="schedule-accounts-container">
                    <div class="loading-spinner"></div> Loading accounts...
                </div>
            </div>
        </div>

        <button type="button" class="btn btn-primary" id="btn-create-schedule">
            ⏰ Create Schedule
        </button>
    </div>

    <div class="backbork-card">
        <h3>Active Schedules</h3>
        <div class="table-container">
            <table class="backbork-table" id="schedules-table">
                <thead>
                    <tr>
                        <th>Accounts</th>
                        <th>Destination</th>
                        <th>Frequency</th>
                        <th>Retention</th>
                        <th>Next Run</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="schedules-tbody">
                    <tr><td colspan="6">Loading schedules...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
