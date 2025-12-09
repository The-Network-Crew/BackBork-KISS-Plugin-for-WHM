<?php
/**
 * BackBork KISS - Logs Panel
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
<div id="panel-logs" class="backbork-panel">
    <div class="backbork-card">
        <h3>Activity Logs</h3>
        <div class="form-row">
            <div class="form-group">
                <label for="log-filter">Filter</label>
                <select id="log-filter">
                    <option value="all">All Activities</option>
                    <option value="backup">Backups Only</option>
                    <option value="restore">Restores Only</option>
                    <option value="error">Errors Only</option>
                </select>
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <button type="button" class="btn btn-secondary" id="btn-refresh-logs">
                    🔄 Refresh
                </button>
            </div>
        </div>
        <div class="table-container">
            <table class="backbork-table logs-table">
                <thead>
                    <tr>
                        <th>When / Status</th>
                        <th>Type / User</th>
                        <th>Account</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody id="logs-tbody">
                    <tr><td colspan="4">Loading logs...</td></tr>
                </tbody>
            </table>
        </div>
        <div id="logs-pagination" style="margin-top: 15px; text-align: center;"></div>
    </div>
</div>
