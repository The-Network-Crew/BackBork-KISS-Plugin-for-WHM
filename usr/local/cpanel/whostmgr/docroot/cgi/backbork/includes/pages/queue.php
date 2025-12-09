<?php
/**
 * BackBork KISS - Queue Panel
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
<div id="panel-queue" class="backbork-panel">
    <div class="queue-header-actions">
        <?php if ($isRoot): ?>
            <button id="btn-process-queue" class="btn btn-process-queue">
                <span class="btn-icon">▶</span> Process Queue Now
            </button>
            <span class="cron-hint">Automatically runs every 5 minutes via cron</span>
        <?php else: ?>
            <div class="cron-info-box">
                <span class="info-icon">ℹ️</span>
                Queue processing runs automatically every 5 minutes. Manual processing requires root access.
            </div>
        <?php endif; ?>
    </div>
    <div class="backbork-card">
        <h3>Running Jobs</h3>
        <div class="table-container">
            <table class="backbork-table">
                <thead>
                    <tr>
                        <th>Job ID</th>
                        <th>Type</th>
                        <th>Account</th>
                        <th>Started</th>
                        <th>Status</th>
                        <th>Progress</th>
                    </tr>
                </thead>
                <tbody id="running-jobs-tbody">
                    <tr><td colspan="6">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="backbork-card">
        <h3>Queued Jobs</h3>
        <div class="table-container">
            <table class="backbork-table">
                <thead>
                    <tr>
                        <th>Job ID</th>
                        <th>Type</th>
                        <th>Accounts</th>
                        <th>Destination</th>
                        <th>Queued At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="queue-tbody">
                    <tr><td colspan="6">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
