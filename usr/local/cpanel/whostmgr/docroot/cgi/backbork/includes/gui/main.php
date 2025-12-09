<?php
/**
 * BackBork KISS - Main GUI Template
 * HTML structure for the WHM interface
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

// Prevent direct access
if (!defined('BACKBORK_VERSION')) {
    die('Access denied');
}
?>
<div class="backbork-container">
    <div class="backbork-header">
        <div class="backbork-header-left">
            <h1><span class="shield-icon">🛡️</span> BackBork KISS <span style="font-size: 12px; font-weight: 400; color: var(--text-muted); margin-left: 8px;"><code>v<?php echo BACKBORK_VERSION; ?></code></span></h1>
        </div>
        
        <!-- Status Monitor -->
        <div class="status-monitor">
            <div class="status-item processing" id="status-processing-indicator" style="display: none;">
                <span class="processing-cog">⚙️</span>
                <span class="label">Processing</span>
            </div>
            <div class="status-item restores">
                <span class="label">Restores</span>
                <span class="value" id="status-restores">0</span>
            </div>
            <div class="status-item jobs">
                <span class="label">Back-ups</span>
                <span class="value" id="status-jobs">0</span>
            </div>
            <div class="status-item transit">
                <span class="label">In-Transit</span>
                <span class="value" id="status-transit">0</span>
            </div>
            <div class="status-item alerts">
                <span class="label">Alerts</span>
                <span class="value" id="status-alerts">0</span>
            </div>
        </div>
        
        <div class="user-info">
            <span><?php echo htmlspecialchars($currentUser); ?></span>
            <?php if ($isRoot): ?>
                <span class="status-badge status-success">Root</span>
            <?php else: ?>
                <span class="status-badge status-pending">Reseller</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="backbork-tabs">
        <div class="backbork-tab active" data-tab="backup">📦 Backup</div>
        <div class="backbork-tab" data-tab="restore">🔄 Restore</div>
        <div class="backbork-tab" data-tab="schedule">⏰ Schedules</div>
        <div class="backbork-tab" data-tab="queue">📋 Queue</div>
        <div class="backbork-tab" data-tab="logs">📜 Logs</div>
        <div class="backbork-tab" data-tab="settings">⚙️ Settings</div>
    </div>

    <!-- Backup Panel -->
    <?php include(__DIR__ . '/../pages/backup.php'); ?>

    <!-- Restore Panel -->
    <?php include(__DIR__ . '/../pages/restore.php'); ?>

    <!-- Schedule Panel -->
    <?php include(__DIR__ . '/../pages/schedule.php'); ?>

    <!-- Queue Panel -->
    <?php include(__DIR__ . '/../pages/queue.php'); ?>

    <!-- Logs Panel -->
    <?php include(__DIR__ . '/../pages/logs.php'); ?>

    <!-- Settings Panel -->
    <?php include(__DIR__ . '/../pages/settings.php'); ?>

    <!-- Footer -->
    <div class="backbork-footer">
        <div><code>v<?php echo BACKBORK_VERSION; ?></code> <strong>&bull; <a href="https://backbork.com" target="_blank">Open-source Disaster Recovery for WHM</a></strong></div>
        <div><strong>&copy; <a href="https://tnc.works" target="_blank">The Network Crew Pty Ltd</a> & <a href="https://velocityhost.com.au" target="_blank">Velocity Host Pty Ltd</a></strong> 💜</div>
    </div>
</div>

<!-- Restore Options Modal -->
<div id="restore-modal" class="modal-overlay">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Confirm Restore</h3>
            <button class="modal-close" onclick="closeModal('restore-modal')">&times;</button>
        </div>
        <div class="alert alert-warning">
            <strong>Warning:</strong> This will overwrite existing data for the selected account. Make sure you have a recent backup if needed.
        </div>
        <div id="restore-confirm-details"></div>
        <div style="margin-top: 20px; text-align: right;">
            <button class="btn btn-secondary" onclick="closeModal('restore-modal')">Cancel</button>
            <button class="btn btn-danger" id="btn-confirm-restore">Confirm Restore</button>
        </div>
    </div>
</div>
