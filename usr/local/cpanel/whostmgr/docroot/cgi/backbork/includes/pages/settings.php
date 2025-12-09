<?php
/**
 * BackBork KISS - Settings Panel
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
<div id="panel-settings" class="backbork-panel">
    <div class="backbork-card">
        <h3>Cron Status</h3>
        <div id="cron-status-container">
            <div class="loading-spinner"></div> Checking cron configuration...
        </div>
    </div>

    <div class="backbork-card">
        <h3>Destination Configuration</h3>
        <div class="alert alert-info">
            BackBork uses the remote destinations configured in WHM's Backup Configuration.<br><br>
            Add or manage your SFTP/FTP destinations there, then use BackBork to run backup and restore jobs to those destinations.
        </div>
        <a href="../../scripts/backup_configuration/destinations" target="_blank" class="btn btn-primary">
            📁 Open Backup Configuration (Destinations)
        </a>
    </div>

    <div class="backbork-card">
        <h3>Notification Settings</h3>
        
        <div class="form-row">
            <div class="form-group">
                <label for="notify-email">Email Address for Alerts</label>
                <input type="email" id="notify-email" placeholder="admin@example.com">
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <button type="button" class="btn btn-sm btn-secondary" id="btn-test-email">
                    📧 Test Email
                </button>
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="slack-webhook">Slack Webhook URL</label>
                <input type="text" id="slack-webhook" placeholder="https://hooks.slack.com/services/...">
            </div>
            <div class="form-group">
                <label>&nbsp;</label>
                <button type="button" class="btn btn-sm btn-secondary" id="btn-test-slack">
                    💬 Test Slack
                </button>
            </div>
        </div>

        <div class="checkbox-group">
            <label><input type="checkbox" id="notify-success" checked> Notify on Success</label>
            <label><input type="checkbox" id="notify-failure" checked> Notify on Failure</label>
            <label><input type="checkbox" id="notify-start"> Notify on Job Start</label>
        </div>
    </div>

    <div class="backbork-card">
        <h3>Database Server</h3>
        <div id="db-server-info">
            <div class="loading-spinner"></div> Detecting database server...
        </div>
    </div>

    <div class="backbork-card">
        <h3>Database Backup Settings</h3>
        
        <div class="form-row">
            <div class="form-group">
                <label for="db-backup-method">Database Backup Method</label>
                <select id="db-backup-method">
                    <option value="pkgacct" selected>pkgacct (default mysqldump)</option>
                    <option value="mariadb-backup">mariadb-backup (hot backup)</option>
                    <option value="mysqlbackup">mysqlbackup (MySQL Enterprise)</option>
                    <option value="skip">Skip databases (use --skipmysql)</option>
                </select>
            </div>
            <div class="form-group">
                <label for="db-backup-target-dir">Database Backup Directory</label>
                <input type="text" id="db-backup-target-dir" placeholder="/home/backbork_tmp/db" value="">
            </div>
        </div>

        <div id="mariadb-backup-options" style="display: none;">
            <h4 style="margin: 16px 0 12px 0; font-size: 13px; color: var(--text-secondary);">mariadb-backup Options</h4>
            <div class="checkbox-group">
                <label><input type="checkbox" id="mdb-compress"> Compress backup</label>
                <label><input type="checkbox" id="mdb-parallel" checked> Parallel threads</label>
                <label><input type="checkbox" id="mdb-slave-info"> Include slave info</label>
                <label><input type="checkbox" id="mdb-galera-info"> Include Galera info</label>
            </div>
            <div class="form-row" style="margin-top: 12px;">
                <div class="form-group">
                    <label for="mdb-parallel-threads">Parallel Threads</label>
                    <input type="number" id="mdb-parallel-threads" value="4" min="1" max="16">
                </div>
                <div class="form-group">
                    <label for="mdb-extra-args">Extra Arguments</label>
                    <input type="text" id="mdb-extra-args" placeholder="--no-lock">
                </div>
            </div>
        </div>

        <div id="mysqlbackup-options" style="display: none;">
            <h4 style="margin: 16px 0 12px 0; font-size: 13px; color: var(--text-secondary);">mysqlbackup Options</h4>
            <div class="checkbox-group">
                <label><input type="checkbox" id="myb-compress"> Compress backup</label>
                <label><input type="checkbox" id="myb-incremental"> Incremental backup</label>
            </div>
            <div class="form-row" style="margin-top: 12px;">
                <div class="form-group">
                    <label for="myb-backup-dir">Backup Image Path</label>
                    <input type="text" id="myb-backup-dir" placeholder="/backup/mysql">
                </div>
                <div class="form-group">
                    <label for="myb-extra-args">Extra Arguments</label>
                    <input type="text" id="myb-extra-args" placeholder="">
                </div>
            </div>
        </div>
    </div>

    <div class="backbork-card">
        <h3>Backup Settings (pkgacct options)</h3>
        
        <div class="form-row">
            <div class="form-group">
                <label for="temp-directory">Temporary Directory</label>
                <input type="text" id="temp-directory" value="/home/backbork_tmp" placeholder="/home/backbork_tmp">
            </div>
            <div class="form-group">
                <label for="mysql-version">Target MySQL Version (optional)</label>
                <input type="text" id="mysql-version" placeholder="e.g. 8.0">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="dbbackup-type">Database Backup Type (pkgacct)</label>
                <select id="dbbackup-type">
                    <option value="all" selected>All (full backup)</option>
                    <option value="schema">Schema only</option>
                    <option value="name">Database names only</option>
                </select>
            </div>
            <div class="form-group">
                <label for="compression-option">Compression</label>
                <select id="compression-option">
                    <option value="compress" selected>Compress (Gzip)</option>
                    <option value="nocompress">No Compression</option>
                </select>
            </div>
        </div>

        <h4 style="margin: 20px 0 12px 0; font-size: 13px; color: var(--text-secondary);">Backup Mode</h4>
        <div class="checkbox-group">
            <label><input type="checkbox" id="opt-incremental"> Incremental (refresh existing)</label>
            <label><input type="checkbox" id="opt-split"> Split into chunks</label>
            <label><input type="checkbox" id="opt-use-backups"> Use last backup as template</label>
        </div>

        <h4 style="margin: 20px 0 12px 0; font-size: 13px; color: var(--text-secondary);">Exclude from Backup</h4>
        <div class="checkbox-group">
            <label><input type="checkbox" id="skip-homedir"> Home Directory</label>
            <label><input type="checkbox" id="skip-publichtml"> public_html</label>
            <label><input type="checkbox" id="skip-mysql"> MySQL Databases</label>
            <label><input type="checkbox" id="skip-pgsql"> PostgreSQL</label>
            <label><input type="checkbox" id="skip-logs"> Log Files</label>
            <label><input type="checkbox" id="skip-mailconfig"> Mail Configuration</label>
            <label><input type="checkbox" id="skip-mailman"> Mailing Lists</label>
            <label><input type="checkbox" id="skip-dnszones"> DNS Zones</label>
            <label><input type="checkbox" id="skip-ssl"> SSL Certificates</label>
            <label><input type="checkbox" id="skip-bwdata"> Bandwidth Data</label>
            <label><input type="checkbox" id="skip-quota"> Disk Quotas</label>
            <label><input type="checkbox" id="skip-ftpusers"> FTP Accounts</label>
            <label><input type="checkbox" id="skip-domains"> Addon/Parked Domains</label>
            <label><input type="checkbox" id="skip-acctdb"> Account Databases</label>
            <label><input type="checkbox" id="skip-apitokens"> API Tokens</label>
            <label><input type="checkbox" id="skip-authnlinks"> External Auth</label>
            <label><input type="checkbox" id="skip-locale"> Locale Settings</label>
            <label><input type="checkbox" id="skip-passwd"> User Password</label>
            <label><input type="checkbox" id="skip-shell"> Shell Privileges</label>
            <label><input type="checkbox" id="skip-resellerconfig"> Reseller Config</label>
            <label><input type="checkbox" id="skip-userdata"> Domain Config</label>
            <label><input type="checkbox" id="skip-linkednodes"> Linked Nodes</label>
            <label><input type="checkbox" id="skip-integrationlinks"> Integration Links</label>
        </div>
    </div>

    <div class="backbork-card">
        <h3>Advanced Settings</h3>
        <div class="checkbox-group">
            <label><input type="checkbox" id="debug-mode"> Enable Debug Mode (verbose logging to error_log)</label>
        </div>
        <p style="font-size: 12px; color: var(--text-muted); margin-top: 8px;">
            When enabled, detailed debug information will be written to the PHP error log for troubleshooting.
        </p>
    </div>

    <div class="backbork-card" style="padding: 16px 20px;">
        <button type="button" class="btn btn-primary" id="btn-save-settings">
            💾 Save Settings
        </button>
    </div>
</div>
