/**
 * BackBork KISS - JavaScript
 * Main application logic
 * 
 * @package BackBork
 * @author The Network Crew Pty Ltd & Velocity Host Pty Ltd
 */

(function() {
    'use strict';

    // State
    let accounts = [];
    let destinations = [];
    let currentConfig = {};
    let currentLogPage = 1;
    let isRootUser = false;
    let schedulesLocked = false;
    let currentScheduleViewUser = 'all';

    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        initTabs();
        loadDestinations();
        loadAccounts();
        loadConfig();
        loadQueue();
        loadLogs();
        initEventListeners();
        
        // Refresh status every 30 seconds
        setInterval(refreshStatus, 30000);
        
        // Initial status monitor update
        refreshStatus();
    });

    // Tab Navigation
    function initTabs() {
        document.querySelectorAll('.backbork-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.backbork-tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.backbork-panel').forEach(p => p.classList.remove('active'));
                
                this.classList.add('active');
                document.getElementById('panel-' + this.dataset.tab).classList.add('active');
                
                // Refresh data when switching tabs
                if (this.dataset.tab === 'queue') loadQueue();
                if (this.dataset.tab === 'logs') loadLogs();
                if (this.dataset.tab === 'schedule') loadSchedules();
                if (this.dataset.tab === 'settings') checkCronStatus();
            });
        });
    }

    // API Helper
    function apiCall(action, data = {}, method = 'POST') {
        // Build URL to explicit API router to avoid routing issues through index.php
        const options = {
            method: method,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'Content-Type': 'application/json'
            }
        };

        // Compute base path for the API - route through index.php (registered with AppConfig)
        const path = window.location.pathname;
        let base = path;
        // Remove index.php and any querystring part
        if (base.indexOf('/index.php') !== -1) {
            base = base.substring(0, base.lastIndexOf('/')) + '/';
        } else if (base.endsWith('/')) {
            // Keep it as-is
        } else {
            base = base.substring(0, base.lastIndexOf('/') + 1);
        }

        // Use index.php for API calls - it detects XMLHttpRequest and routes to router.php
        let url = base + 'index.php?action=' + encodeURIComponent(action);

        if (method === 'POST') {
            options.body = JSON.stringify(data);
        } else if (method === 'GET' && Object.keys(data).length > 0) {
            url += '&' + new URLSearchParams(data).toString();
        }

        // Robust error handling: parse as text, check content-type, and then parse JSON
        return fetch(url, options).then(async r => {
            const text = await r.text();
            const ct = r.headers.get('content-type') || '';

            if (!r.ok) {
                console.error('API request error:', r.status, r.statusText, url, text);
                throw new Error('API request failed: ' + r.status + ' ' + r.statusText);
            }

            if (ct.indexOf('application/json') !== -1) {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Invalid JSON response from API:', url, text);
                    throw new Error('Invalid JSON response from API');
                }
            }

            // If content-type is not JSON, try to parse anyway, otherwise log and throw
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Unexpected non-JSON API response:', url, text);
                throw new Error('Unexpected non-JSON response from server');
            }
        });
    }

    // Load Destinations
    function loadDestinations() {
        apiCall('get_destinations', {}, 'GET').then(data => {
            destinations = data.destinations || [];
            
            document.querySelectorAll('.destination-select').forEach(select => {
                select.innerHTML = '<option value="">-- Select Destination --</option>';
                destinations.forEach(dest => {
                    select.innerHTML += `<option value="${dest.id}">${dest.name} (${dest.type})</option>`;
                });
            });
        }).catch(err => {
            console.error('Failed to load destinations', err);
            document.querySelectorAll('.destination-select').forEach(select => {
                if (select) select.innerHTML = '<option value="">-- Unable to load destinations --</option>';
            });
        });
    }

    // Load Accounts
    function loadAccounts() {
        apiCall('get_accounts', {}, 'GET').then(data => {
            accounts = data || [];
            renderAccountLists();
        }).catch(err => {
            console.error('Failed to load accounts', err);
            accounts = [];
            renderAccountLists();
        });
    }

    // Render Account Lists
    function renderAccountLists() {
        const containers = ['backup-accounts-container', 'schedule-accounts-container'];
        
        containers.forEach(containerId => {
            const container = document.getElementById(containerId);
            if (!container) return;
            
            if (accounts.length === 0) {
                container.innerHTML = '<div class="alert alert-info">No accounts available.</div>';
                return;
            }
            
            container.innerHTML = accounts.map(acc => `
                <div class="account-item">
                    <input type="checkbox" value="${acc.user}" class="account-checkbox">
                    <div class="account-info">
                        <div class="account-name">${acc.user}</div>
                        <div class="account-domain">${acc.domain || 'N/A'}</div>
                    </div>
                    ${acc.owner ? `<span class="account-owner">${acc.owner}</span>` : ''}
                </div>
            `).join('');
        });
        
        // Populate restore account dropdown
        const restoreAccount = document.getElementById('restore-account');
        if (restoreAccount) {
            restoreAccount.innerHTML = '<option value="">-- Select Account --</option>';
            accounts.forEach(acc => {
                restoreAccount.innerHTML += `<option value="${acc.user}">${acc.user} (${acc.domain || 'N/A'})</option>`;
            });
        }
    }

    // Load Configuration
    function loadConfig() {
        apiCall('get_config', {}, 'GET').then(data => {
            currentConfig = data || {};
            
            // Notification settings
            if (data.notify_email) document.getElementById('notify-email').value = data.notify_email;
            if (data.slack_webhook) document.getElementById('slack-webhook').value = data.slack_webhook;
            if (data.notify_success !== undefined) document.getElementById('notify-success').checked = data.notify_success;
            if (data.notify_failure !== undefined) document.getElementById('notify-failure').checked = data.notify_failure;
            if (data.notify_start !== undefined) document.getElementById('notify-start').checked = data.notify_start;
            if (data.notify_daily_summary !== undefined) document.getElementById('notify-daily-summary').checked = data.notify_daily_summary;
            
            // Backup settings
            if (data.temp_directory) document.getElementById('temp-directory').value = data.temp_directory;
            if (data.mysql_version) document.getElementById('mysql-version').value = data.mysql_version;
            if (data.dbbackup_type) document.getElementById('dbbackup-type').value = data.dbbackup_type;
            if (data.compression_option) document.getElementById('compression-option').value = data.compression_option;
            
            // Backup mode options
            if (data.opt_incremental) document.getElementById('opt-incremental').checked = data.opt_incremental;
            if (data.opt_split) document.getElementById('opt-split').checked = data.opt_split;
            if (data.opt_use_backups) document.getElementById('opt-use-backups').checked = data.opt_use_backups;
            
            // Skip options
            const skipOptions = ['homedir', 'publichtml', 'mysql', 'pgsql', 'logs', 'mailconfig', 
                'mailman', 'dnszones', 'ssl', 'bwdata', 'quota', 'ftpusers', 'domains', 
                'acctdb', 'apitokens', 'authnlinks', 'locale', 'passwd', 'shell', 
                'resellerconfig', 'userdata', 'linkednodes', 'integrationlinks'];
            skipOptions.forEach(opt => {
                const el = document.getElementById('skip-' + opt);
                if (el && data['skip_' + opt]) el.checked = data['skip_' + opt];
            });
            
            // Database backup settings
            if (data.db_backup_method) {
                document.getElementById('db-backup-method').value = data.db_backup_method;
                toggleDbBackupOptions(data.db_backup_method);
            }
            if (data.db_backup_target_dir) document.getElementById('db-backup-target-dir').value = data.db_backup_target_dir;
            
            // MariaDB backup options
            if (data.mdb_compress) document.getElementById('mdb-compress').checked = data.mdb_compress;
            if (data.mdb_parallel) document.getElementById('mdb-parallel').checked = data.mdb_parallel;
            if (data.mdb_slave_info) document.getElementById('mdb-slave-info').checked = data.mdb_slave_info;
            if (data.mdb_galera_info) document.getElementById('mdb-galera-info').checked = data.mdb_galera_info;
            if (data.mdb_parallel_threads) document.getElementById('mdb-parallel-threads').value = data.mdb_parallel_threads;
            if (data.mdb_extra_args) document.getElementById('mdb-extra-args').value = data.mdb_extra_args;
            
            // MySQL backup options
            if (data.myb_compress) document.getElementById('myb-compress').checked = data.myb_compress;
            if (data.myb_incremental) document.getElementById('myb-incremental').checked = data.myb_incremental;
            if (data.myb_backup_dir) document.getElementById('myb-backup-dir').value = data.myb_backup_dir;
            if (data.myb_extra_args) document.getElementById('myb-extra-args').value = data.myb_extra_args;
            
            // Debug mode (advanced settings)
            const debugModeEl = document.getElementById('debug-mode');
            if (debugModeEl && data.debug_mode !== undefined) {
                debugModeEl.checked = data.debug_mode;
            }
            
            // Handle global config (root only) or lock status (resellers)
            if (data._global) {
                // Root user - has full global config
                isRootUser = true;
                schedulesLocked = data._global.schedules_locked || false;
                
                // Set schedules lock checkbox
                const lockEl = document.getElementById('schedules-locked');
                if (lockEl) {
                    lockEl.checked = schedulesLocked;
                }
                
                // Populate "View as user" dropdown in schedules
                const viewUserSelect = document.getElementById('schedule-view-user');
                if (viewUserSelect && data._users_with_schedules) {
                    viewUserSelect.innerHTML = '<option value="all">All Users</option>';
                    // Add root first if they have schedules
                    if (data._users_with_schedules.includes('root')) {
                        viewUserSelect.innerHTML += '<option value="root">root</option>';
                    }
                    // Add resellers
                    if (data._resellers) {
                        data._resellers.forEach(reseller => {
                            const hasSchedules = data._users_with_schedules.includes(reseller);
                            viewUserSelect.innerHTML += '<option value="' + reseller + '">' + reseller + (hasSchedules ? '' : ' (no schedules)') + '</option>';
                        });
                    }
                }
            } else if (data._schedules_locked !== undefined) {
                // Non-root user - just get lock status
                isRootUser = false;
                schedulesLocked = data._schedules_locked;
                
                // Update schedule UI based on lock status
                updateScheduleLockUI();
            }
        }).catch(err => {
            console.error('Failed to load config', err);
            // Keep currentConfig as-is and show a warning placeholder if present
            const settingsPanel = document.getElementById('panel-settings');
            if (settingsPanel) {
                // show a small warning in the settings panel
                const e = document.createElement('div');
                e.className = 'alert alert-warning';
                e.textContent = 'Unable to load configuration.';
                settingsPanel.prepend(e);
            }
        });
        
        // Load database server info
        loadDbServerInfo();
    }
    
    // Load Database Server Info
    function loadDbServerInfo() {
        apiCall('get_db_info', {}, 'GET').then(data => {
            const container = document.getElementById('db-server-info');
            if (!container) return;
            
            let html = `<div style="margin-bottom: 0;">`;
            html += `<strong style="font-size: 14px;">${data.type} ${data.version}</strong>`;
            html += `<div style="background: #1e293b; color: #22d3ee; font-family: 'Monaco', 'Menlo', 'Ubuntu Mono', monospace; font-size: 12px; padding: 10px 14px; border-radius: 6px; margin: 10px 0 16px 0; overflow-x: auto;">${data.full_version}</div>`;
            html += `<div style="display: flex; gap: 16px; flex-wrap: wrap;">`;
            html += `<div style="display: flex; align-items: center; gap: 8px;"><strong style="color: #1e293b;">mariadb-backup</strong>`;
            html += data.mariadb_backup_available 
                ? '<span style="background: #22c55e; color: white; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 600;">Available</span>' 
                : '<span style="background: #ef4444; color: white; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 600;">Not Found</span>';
            html += `</div>`;
            html += `<div style="display: flex; align-items: center; gap: 8px;"><strong style="color: #1e293b;">mysqlbackup</strong>`;
            html += data.mysqlbackup_available 
                ? '<span style="background: #22c55e; color: white; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 600;">Available</span>' 
                : '<span style="background: #ef4444; color: white; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 600;">Not Found</span>';
            html += `</div>`;
            html += `</div></div>`;
            
            container.innerHTML = html;
        }).catch(err => {
            const container = document.getElementById('db-server-info');
            if (container) {
                container.innerHTML = '<div class="alert alert-warning">Unable to detect database server</div>';
            }
        });
    }
    
    // Toggle database backup options visibility
    function toggleDbBackupOptions(method) {
        document.getElementById('mariadb-backup-options').style.display = 
            method === 'mariadb-backup' ? 'block' : 'none';
        document.getElementById('mysqlbackup-options').style.display = 
            method === 'mysqlbackup' ? 'block' : 'none';
        
        // Auto-check skip-mysql if using external backup method
        if (method === 'mariadb-backup' || method === 'mysqlbackup' || method === 'skip') {
            document.getElementById('skip-mysql').checked = true;
        }
    }

    // Load Queue
    function loadQueue() {
        apiCall('get_queue', {}, 'GET').then(data => {
            const queueTbody = document.getElementById('queue-tbody');
            const runningTbody = document.getElementById('running-jobs-tbody');
            
            // Update status monitor
            updateStatusMonitor(data);
            
            // Queued jobs
            if (data.queued && data.queued.length > 0) {
                queueTbody.innerHTML = data.queued.map(job => `
                    <tr>
                        <td>${job.id}</td>
                        <td>${job.type}</td>
                        <td>${job.accounts.join(', ')}</td>
                        <td>${job.destination_name || job.destination}</td>
                        <td>${job.created_at}</td>
                        <td>
                            <button class="btn btn-sm btn-danger" onclick="removeFromQueue('${job.id}')">Remove</button>
                        </td>
                    </tr>
                `).join('');
            } else {
                queueTbody.innerHTML = '<tr><td colspan="6">No queued jobs.</td></tr>';
            }
            
            // Running jobs
            if (data.running && data.running.length > 0) {
                runningTbody.innerHTML = data.running.map(job => `
                    <tr>
                        <td>${job.id}</td>
                        <td>${job.type}</td>
                        <td>${job.account}</td>
                        <td>${job.started_at}</td>
                        <td><span class="status-badge status-running">${job.status}</span></td>
                        <td>
                            <div class="progress-bar" style="width: 100px;">
                                <div class="progress-bar-fill" style="width: ${job.progress || 0}%"></div>
                            </div>
                        </td>
                    </tr>
                `).join('');
            } else {
                runningTbody.innerHTML = '<tr><td colspan="6">No running jobs.</td></tr>';
            }
        }).catch(err => {
            console.error('Failed to load queue', err);
            const queueTbody = document.getElementById('queue-tbody');
            const runningTbody = document.getElementById('running-jobs-tbody');
            if (queueTbody) queueTbody.innerHTML = '<tr><td colspan="6">Unable to load queue.</td></tr>';
            if (runningTbody) runningTbody.innerHTML = '<tr><td colspan="6">Unable to load running jobs.</td></tr>';
            updateStatusMonitor({ queued: [], running: [], restores: [] });
        });
    }
    
    // Update Schedule Lock UI (for resellers when locked)
    function updateScheduleLockUI() {
        const lockedAlert = document.getElementById('schedules-locked-alert');
        const createCard = document.getElementById('schedule-create-card');
        const createBtn = document.getElementById('btn-create-schedule');
        
        if (schedulesLocked && !isRootUser) {
            // Show locked alert
            if (lockedAlert) lockedAlert.style.display = 'block';
            // Disable create button
            if (createBtn) {
                createBtn.disabled = true;
                createBtn.innerHTML = '🔒 Schedules Locked';
            }
            // Optionally dim the create card
            if (createCard) createCard.style.opacity = '0.6';
        } else {
            // Hide locked alert
            if (lockedAlert) lockedAlert.style.display = 'none';
            // Enable create button
            if (createBtn) {
                createBtn.disabled = false;
                createBtn.innerHTML = '⏰ Create Schedule';
            }
            if (createCard) createCard.style.opacity = '1';
        }
    }

    // Load Schedules
    function loadSchedules() {
        // Build request params - include view_user for root
        let params = {};
        if (isRootUser && currentScheduleViewUser && currentScheduleViewUser !== 'all') {
            params.view_user = currentScheduleViewUser;
        }
        
        apiCall('get_queue', params, 'GET').then(data => {
            const tbody = document.getElementById('schedules-tbody');
            const colCount = isRootUser ? 7 : 6;
            
            // Update lock UI in case it changed
            updateScheduleLockUI();
            
            if (data.schedules && data.schedules.length > 0) {
                tbody.innerHTML = data.schedules.map(schedule => {
                    // Determine if delete button should be disabled
                    const canDelete = isRootUser || !schedulesLocked;
                    const deleteBtn = canDelete 
                        ? '<button class="btn btn-sm btn-danger" onclick="removeSchedule(\'' + schedule.id + '\')">Delete</button>'
                        : '<button class="btn btn-sm btn-danger" disabled title="Schedules locked by administrator">🔒</button>';
                    
                    // Display accounts - show "All Accounts" badge if dynamic
                    let accountsDisplay;
                    if (schedule.all_accounts || (schedule.accounts.length === 1 && schedule.accounts[0] === '*')) {
                        accountsDisplay = '<span class="status-badge" style="background: var(--primary); color: #fff;">🌐 All Accounts</span>';
                    } else {
                        accountsDisplay = schedule.accounts.join(', ');
                    }
                    
                    let row = '<tr>' +
                        '<td>' + accountsDisplay + '</td>' +
                        '<td>' + (schedule.destination_name || schedule.destination) + '</td>' +
                        '<td>' + schedule.schedule + '</td>' +
                        '<td>' + (schedule.retention == 0 ? '∞' : schedule.retention) + '</td>' +
                        '<td>' + schedule.next_run + '</td>';
                    
                    // Add owner column for root
                    if (isRootUser) {
                        row += '<td><span class="status-badge">' + (schedule.user || 'unknown') + '</span></td>';
                    }
                    
                    row += '<td>' + deleteBtn + '</td></tr>';
                    return row;
                }).join('');
            } else {
                tbody.innerHTML = '<tr><td colspan="' + colCount + '">No active schedules.</td></tr>';
            }
        }).catch(err => {
            console.error('Failed to load schedules', err);
            const tbody = document.getElementById('schedules-tbody');
            if (tbody) tbody.innerHTML = '<tr><td colspan="6">Unable to load schedules.</td></tr>';
        });
    }

    // Load Logs
    function loadLogs(page = 1) {
        currentLogPage = page;
        const filter = document.getElementById('log-filter').value;
        
        apiCall('get_logs', { page: page, filter: filter }, 'GET').then(data => {
            const tbody = document.getElementById('logs-tbody');
            
            if (data.logs && data.logs.length > 0) {
                tbody.innerHTML = data.logs.map(log => 
                    '<tr>' +
                        '<td><div class="log-cell-meta">' +
                            '<span class="log-timestamp">' + log.timestamp + '</span>' +
                            '<span class="status-badge status-' + log.status + '">' + log.status + '</span>' +
                        '</div></td>' +
                        '<td><div class="log-cell-type">' +
                            '<span class="log-type">' + log.type + '</span>' +
                            '<span class="log-user">' + log.user + ' <span class="log-requestor">(' + (log.requestor || 'N/A') + ')</span></span>' +
                        '</div></td>' +
                        '<td class="log-cell-account">' + (log.account || 'N/A') + '</td>' +
                        '<td class="log-cell-details"><pre class="log-details">' + (log.message || '') + '</pre></td>' +
                    '</tr>'
                ).join('');
                
                // Pagination
                renderPagination(data.total_pages, page);
            } else {
                tbody.innerHTML = '<tr><td colspan="4">No logs found.</td></tr>';
            }
        }).catch(err => {
            console.error('Failed to load logs', err);
            const tbody = document.getElementById('logs-tbody');
            if (tbody) tbody.innerHTML = '<tr><td colspan="4">Unable to load logs.</td></tr>';
            renderPagination(0, 1);
        });
    }

    // Render Pagination
    function renderPagination(totalPages, currentPage) {
        const container = document.getElementById('logs-pagination');
        if (totalPages <= 1) {
            container.innerHTML = '';
            return;
        }
        
        let html = '';
        for (let i = 1; i <= totalPages; i++) {
            html += `<button class="btn btn-sm ${i === currentPage ? 'btn-primary' : 'btn-secondary'}" 
                     onclick="loadLogs(${i})" style="margin: 0 2px;">${i}</button>`;
        }
        container.innerHTML = html;
    }

    // Update Status Monitor
    function updateStatusMonitor(data) {
        const jobsEl = document.getElementById('status-jobs');
        const transitEl = document.getElementById('status-transit');
        const alertsEl = document.getElementById('status-alerts');
        const processingIndicator = document.getElementById('status-processing-indicator');
        
        if (!jobsEl || !transitEl || !alertsEl) return;
        
        const queuedCount = (data.queued || []).length;
        const runningCount = (data.running || []).length;
        const totalJobs = queuedCount + runningCount;
        
        // Count jobs in transit (running)
        const inTransit = runningCount;
        
        // Count failed jobs from recent completed (we'll use 0 as placeholder)
        const alerts = 0;
        
        jobsEl.textContent = totalJobs;
        transitEl.textContent = inTransit;
        alertsEl.textContent = alerts;
        
        // Show/hide processing indicator based on running jobs
        if (processingIndicator) {
            if (runningCount > 0) {
                processingIndicator.style.display = 'flex';
            } else {
                processingIndicator.style.display = 'none';
            }
        }
        
        // Update restores count
        const restoresEl = document.getElementById('status-restores');
        if (restoresEl) {
            const restoreCount = (data.restores || []).length;
            restoresEl.textContent = restoreCount;
        }
    }

    // Check Cron Status
    function checkCronStatus() {
        apiCall('check_cron', {}, 'GET').then(data => {
            const container = document.getElementById('cron-status-container');
            if (!container) return;
            
            if (data.installed) {
                const cronLine = data.schedule && data.command 
                    ? `${data.schedule} ${data.command}`
                    : (data.command || 'Cron entry found');
                container.innerHTML = `
                    <div class="alert alert-success" style="margin-bottom: 0;">
                        <strong>✓ Cron is properly configured</strong>
                        <small style="display: block; opacity: 0.8; margin-top: 4px;">Path: ${data.path || '/etc/cron.d/backbork'}</small>
                    </div>
                    <code style="display: block; margin-top: 12px; padding: 10px; background: var(--terminal-bg); border-radius: 6px; font-size: 12px; color: var(--terminal-text); overflow-x: auto; white-space: pre-wrap; word-break: break-all;">${cronLine}</code>
                `;
            } else {
                container.innerHTML = `
                    <div class="alert alert-danger" style="margin-bottom: 0;">
                        <strong>✗ Cron not configured</strong><br>
                        <small>${data.message || 'Run the install script to set up the cron job.'}</small>
                    </div>
                `;
            }
        }).catch(err => {
            const container = document.getElementById('cron-status-container');
            if (container) {
                container.innerHTML = `
                    <div class="alert alert-warning" style="margin-bottom: 0;">
                        <strong>Unable to check cron status</strong><br>
                        <small>${err.message || 'An error occurred.'}</small>
                    </div>
                `;
            }
        });
    }

    // Refresh Status
    function refreshStatus() {
        const activeTab = document.querySelector('.backbork-tab.active');
        if (activeTab && activeTab.dataset.tab === 'queue') {
            loadQueue();
        }
        // Also refresh status monitor
        apiCall('get_queue', {}, 'GET').then(data => {
            updateStatusMonitor(data);
        }).catch(err => { console.error('Failed to refresh status', err); updateStatusMonitor({ queued: [], running: [], restores: [] }); });
    }

    // Event Listeners
    function initEventListeners() {
        // Select All checkboxes
        const selectAllBackup = document.getElementById('select-all-backup');
        if (selectAllBackup) {
            selectAllBackup.addEventListener('change', function() {
                document.querySelectorAll('#backup-accounts-container .account-checkbox').forEach(cb => {
                    cb.checked = this.checked;
                });
            });
        }
        
        const selectAllSchedule = document.getElementById('select-all-schedule');
        if (selectAllSchedule) {
            selectAllSchedule.addEventListener('change', function() {
                document.querySelectorAll('#schedule-accounts-container .account-checkbox').forEach(cb => {
                    cb.checked = this.checked;
                });
            });
        }

        // Backup Now
        const btnBackupNow = document.getElementById('btn-backup-now');
        if (btnBackupNow) {
            btnBackupNow.addEventListener('click', function() {
                const selectedAccounts = getSelectedAccounts('backup-accounts-container');
                const destination = document.getElementById('backup-destination').value;
                
                if (selectedAccounts.length === 0) {
                    alert('Please select at least one account.');
                    return;
                }
                
                if (!destination) {
                    alert('Please select a destination.');
                    return;
                }
                
                startBackup(selectedAccounts, destination);
            });
        }

        // Add to Queue
        const btnBackupQueue = document.getElementById('btn-backup-queue');
        if (btnBackupQueue) {
            btnBackupQueue.addEventListener('click', function() {
                const selectedAccounts = getSelectedAccounts('backup-accounts-container');
                const destination = document.getElementById('backup-destination').value;
                
                if (selectedAccounts.length === 0) {
                    alert('Please select at least one account.');
                    return;
                }
                
                if (!destination) {
                    alert('Please select a destination.');
                    return;
                }
                
                apiCall('queue_backup', {
                    accounts: selectedAccounts,
                    destination: destination,
                    schedule: 'once'
                }).then(data => {
                    if (data.success) {
                        alert('Jobs added to queue successfully!');
                        loadQueue();
                    } else {
                        alert('Error: ' + (data.message || 'Unknown error'));
                    }
                }).catch(err => { console.error('Error queue_backup', err); alert('Failed to queue backup: ' + (err.message || 'Unknown error')); });
            });
        }

        // Restore
        const btnRestore = document.getElementById('btn-restore');
        if (btnRestore) {
            btnRestore.addEventListener('click', function() {
                const destination = document.getElementById('restore-destination').value;
                const account = document.getElementById('restore-account').value;
                const backupFile = document.getElementById('restore-backup-file').value;
                
                if (!destination || !account || !backupFile) {
                    alert('Please select destination, account, and backup file.');
                    return;
                }
                
                // Show confirmation modal
                document.getElementById('restore-confirm-details').innerHTML = `
                    <p><strong>Account:</strong> ${account}</p>
                    <p><strong>Backup File:</strong> ${backupFile}</p>
                    <p><strong>Source:</strong> ${destination}</p>
                `;
                document.getElementById('restore-modal').classList.add('active');
            });
        }

        const btnConfirmRestore = document.getElementById('btn-confirm-restore');
        if (btnConfirmRestore) {
            btnConfirmRestore.addEventListener('click', function() {
                closeModal('restore-modal');
                
                const destination = document.getElementById('restore-destination').value;
                const account = document.getElementById('restore-account').value;
                const backupFile = document.getElementById('restore-backup-file').value;
                
                const options = {
                    homedir: document.querySelector('[name="restore_homedir"]').checked,
                    mysql: document.querySelector('[name="restore_mysql"]').checked,
                    mail: document.querySelector('[name="restore_mail"]').checked,
                    ssl: document.querySelector('[name="restore_ssl"]').checked,
                    cron: document.querySelector('[name="restore_cron"]').checked,
                    dns: document.querySelector('[name="restore_dns"]').checked,
                    subdomains: document.querySelector('[name="restore_subdomains"]').checked,
                    addon_domains: document.querySelector('[name="restore_addon_domains"]').checked
                };
                
                startRestore(backupFile, account, options, destination);
            });
        }

        // Load backups when destination/account changes
        const restoreDestination = document.getElementById('restore-destination');
        if (restoreDestination) {
            restoreDestination.addEventListener('change', loadAvailableBackups);
        }
        
        const restoreAccount = document.getElementById('restore-account');
        if (restoreAccount) {
            restoreAccount.addEventListener('change', loadAvailableBackups);
        }

        // Create Schedule
        // All Accounts toggle for schedules
        const scheduleAllAccounts = document.getElementById('schedule-all-accounts');
        if (scheduleAllAccounts) {
            scheduleAllAccounts.addEventListener('change', function() {
                const container = document.getElementById('schedule-accounts-container');
                const selectAll = document.getElementById('select-all-schedule');
                const hint = document.getElementById('all-accounts-hint');
                
                if (this.checked) {
                    // Dim the account list and uncheck individual selections
                    if (container) container.style.opacity = '0.4';
                    if (selectAll) {
                        selectAll.checked = false;
                        selectAll.disabled = true;
                    }
                    document.querySelectorAll('#schedule-accounts-container .account-checkbox').forEach(cb => {
                        cb.checked = false;
                        cb.disabled = true;
                    });
                    if (hint) hint.style.display = 'block';
                } else {
                    // Restore the account list
                    if (container) container.style.opacity = '1';
                    if (selectAll) selectAll.disabled = false;
                    document.querySelectorAll('#schedule-accounts-container .account-checkbox').forEach(cb => {
                        cb.disabled = false;
                    });
                    if (hint) hint.style.display = 'none';
                }
            });
        }

        // Create Schedule
        const btnCreateSchedule = document.getElementById('btn-create-schedule');
        if (btnCreateSchedule) {
            btnCreateSchedule.addEventListener('click', function() {
                const allAccountsChecked = document.getElementById('schedule-all-accounts')?.checked || false;
                const selectedAccounts = allAccountsChecked ? ['*'] : getSelectedAccounts('schedule-accounts-container');
                const destination = document.getElementById('schedule-destination').value;
                const frequency = document.getElementById('schedule-frequency').value;
                const retention = document.getElementById('schedule-retention').value;
                const time = document.getElementById('schedule-time').value;
                
                if (selectedAccounts.length === 0) {
                    alert('Please select at least one account or enable "All Accounts".');
                    return;
                }
                
                if (!destination) {
                    alert('Please select a destination.');
                    return;
                }
                
                apiCall('create_schedule', {
                    accounts: selectedAccounts,
                    destination: destination,
                    schedule: frequency,
                    retention: parseInt(retention),
                    preferred_time: parseInt(time),
                    all_accounts: allAccountsChecked
                }).then(data => {
                    if (data.success) {
                        alert('Schedule created successfully!');
                        loadSchedules();
                    } else {
                        alert('Error: ' + (data.message || 'Unknown error'));
                    }
                }).catch(err => { console.error('Error schedule create', err); alert('Failed to create schedule: ' + (err.message || 'Unknown error')); });
            });
        }

        // Save Settings
        const btnSaveSettings = document.getElementById('btn-save-settings');
        if (btnSaveSettings) {
            btnSaveSettings.addEventListener('click', function() {
                const debugModeEl = document.getElementById('debug-mode');
                const config = {
                    // Notification settings
                    notify_email: document.getElementById('notify-email').value,
                    slack_webhook: document.getElementById('slack-webhook').value,
                    notify_success: document.getElementById('notify-success').checked,
                    notify_failure: document.getElementById('notify-failure').checked,
                    notify_start: document.getElementById('notify-start').checked,
                    notify_daily_summary: document.getElementById('notify-daily-summary').checked,
                    
                    // Debug mode
                    debug_mode: debugModeEl ? debugModeEl.checked : false,
                    
                    // Database backup settings
                    db_backup_method: document.getElementById('db-backup-method').value,
                    db_backup_target_dir: document.getElementById('db-backup-target-dir').value,
                    
                    // MariaDB backup options
                    mdb_compress: document.getElementById('mdb-compress').checked,
                    mdb_parallel: document.getElementById('mdb-parallel').checked,
                    mdb_slave_info: document.getElementById('mdb-slave-info').checked,
                    mdb_galera_info: document.getElementById('mdb-galera-info').checked,
                    mdb_parallel_threads: document.getElementById('mdb-parallel-threads').value,
                    mdb_extra_args: document.getElementById('mdb-extra-args').value,
                    
                    // MySQL backup options
                    myb_compress: document.getElementById('myb-compress').checked,
                    myb_incremental: document.getElementById('myb-incremental').checked,
                    myb_backup_dir: document.getElementById('myb-backup-dir').value,
                    myb_extra_args: document.getElementById('myb-extra-args').value,
                    
                    // pkgacct settings
                    temp_directory: document.getElementById('temp-directory').value,
                    mysql_version: document.getElementById('mysql-version').value,
                    dbbackup_type: document.getElementById('dbbackup-type').value,
                    compression_option: document.getElementById('compression-option').value,
                    
                    // Backup mode options
                    opt_incremental: document.getElementById('opt-incremental').checked,
                    opt_split: document.getElementById('opt-split').checked,
                    opt_use_backups: document.getElementById('opt-use-backups').checked,
                    
                    // Skip options
                    skip_homedir: document.getElementById('skip-homedir').checked,
                    skip_publichtml: document.getElementById('skip-publichtml').checked,
                    skip_mysql: document.getElementById('skip-mysql').checked,
                    skip_pgsql: document.getElementById('skip-pgsql').checked,
                    skip_logs: document.getElementById('skip-logs').checked,
                    skip_mailconfig: document.getElementById('skip-mailconfig').checked,
                    skip_mailman: document.getElementById('skip-mailman').checked,
                    skip_dnszones: document.getElementById('skip-dnszones').checked,
                    skip_ssl: document.getElementById('skip-ssl').checked,
                    skip_bwdata: document.getElementById('skip-bwdata').checked,
                    skip_quota: document.getElementById('skip-quota').checked,
                    skip_ftpusers: document.getElementById('skip-ftpusers').checked,
                    skip_domains: document.getElementById('skip-domains').checked,
                    skip_acctdb: document.getElementById('skip-acctdb').checked,
                    skip_apitokens: document.getElementById('skip-apitokens').checked,
                    skip_authnlinks: document.getElementById('skip-authnlinks').checked,
                    skip_locale: document.getElementById('skip-locale').checked,
                    skip_passwd: document.getElementById('skip-passwd').checked,
                    skip_shell: document.getElementById('skip-shell').checked,
                    skip_resellerconfig: document.getElementById('skip-resellerconfig').checked,
                    skip_userdata: document.getElementById('skip-userdata').checked,
                    skip_linkednodes: document.getElementById('skip-linkednodes').checked,
                    skip_integrationlinks: document.getElementById('skip-integrationlinks').checked
                };
                
                apiCall('save_config', config).then(data => {
                    if (data.success) {
                        alert('Settings saved successfully!');
                    } else {
                        alert('Error: ' + (data.message || 'Unknown error'));
                    }
                }).catch(err => { console.error('Error save_config', err); alert('Failed to save configuration: ' + (err.message || 'Unknown error')); });
            });
        }
        
        // Database backup method change handler
        const dbBackupMethod = document.getElementById('db-backup-method');
        if (dbBackupMethod) {
            dbBackupMethod.addEventListener('change', function() {
                toggleDbBackupOptions(this.value);
            });
        }

        // Test notifications
        const btnTestEmail = document.getElementById('btn-test-email');
        if (btnTestEmail) {
            btnTestEmail.addEventListener('click', function() {
                apiCall('test_notification', { type: 'email' }).then(data => {
                    alert(data.success ? 'Test email sent!' : 'Error: ' + data.message);
                }).catch(err => { console.error('Error test_notification email', err); alert('Failed to send test email: ' + (err.message || 'Unknown error')); });
            });
        }

        const btnTestSlack = document.getElementById('btn-test-slack');
        if (btnTestSlack) {
            btnTestSlack.addEventListener('click', function() {
                apiCall('test_notification', { type: 'slack' }).then(data => {
                    alert(data.success ? 'Test Slack message sent!' : 'Error: ' + data.message);
                }).catch(err => { console.error('Error test_notification slack', err); alert('Failed to send test slack: ' + (err.message || 'Unknown error')); });
            });
        }

        // Refresh logs
        const btnRefreshLogs = document.getElementById('btn-refresh-logs');
        if (btnRefreshLogs) {
            btnRefreshLogs.addEventListener('click', function() {
                loadLogs(currentLogPage);
            });
        }

        const logFilter = document.getElementById('log-filter');
        if (logFilter) {
            logFilter.addEventListener('change', function() {
                loadLogs(1);
            });
        }
        
        // Schedule View User selector (root only)
        const scheduleViewUser = document.getElementById('schedule-view-user');
        if (scheduleViewUser) {
            scheduleViewUser.addEventListener('change', function() {
                currentScheduleViewUser = this.value;
                loadSchedules();
            });
        }
        
        // Schedules Locked toggle (root only)
        const schedulesLockedToggle = document.getElementById('schedules-locked');
        if (schedulesLockedToggle) {
            schedulesLockedToggle.addEventListener('change', function() {
                const newLockState = this.checked;
                
                apiCall('save_global_config', { schedules_locked: newLockState }).then(data => {
                    if (data.success) {
                        schedulesLocked = newLockState;
                        updateScheduleLockUI();
                        alert(newLockState ? 'Schedules are now locked for resellers.' : 'Schedules are now unlocked for resellers.');
                    } else {
                        // Revert checkbox on failure
                        schedulesLockedToggle.checked = !newLockState;
                        alert('Error: ' + (data.message || 'Failed to update lock status'));
                    }
                }).catch(err => {
                    console.error('Error save_global_config', err);
                    schedulesLockedToggle.checked = !newLockState;
                    alert('Failed to update lock status: ' + (err.message || 'Unknown error'));
                });
            });
        }
        
        // Process Queue Now button
        const btnProcessQueue = document.getElementById('btn-process-queue');
        if (btnProcessQueue) {
            btnProcessQueue.addEventListener('click', function() {
                if (!confirm('Process queue now? This will also check schedules and run queued jobs.')) return;
                
                btnProcessQueue.disabled = true;
                btnProcessQueue.innerHTML = '<span class="loading-spinner-small"></span> Processing...';
                
                apiCall('process_queue', {}, 'POST').then(data => {
                    if (data.success) {
                        // Show results in a nicer way
                        const scheduled = data.scheduled?.scheduled || {};
                        const processed = data.processed?.processed || 0;
                        const failed = data.processed?.failed || 0;
                        
                        let msg = 'Queue processed successfully!';
                        if (Object.keys(scheduled).length > 0) {
                            msg += '\n\nScheduled jobs queued: ' + Object.keys(scheduled).length;
                        }
                        if (processed > 0 || failed > 0) {
                            msg += '\n\nProcessed: ' + processed + ', Failed: ' + failed;
                        }
                        
                        alert(msg);
                        loadQueue();
                    } else {
                        alert('Failed to process queue: ' + (data.message || 'Unknown error'));
                    }
                }).catch(err => {
                    console.error('Error process_queue', err);
                    alert('Error processing queue: ' + (err.message || 'Unknown error'));
                }).finally(() => {
                    btnProcessQueue.disabled = false;
                    btnProcessQueue.innerHTML = '▶ Process Queue Now';
                });
            });
        }
    }

    // Get Selected Accounts
    function getSelectedAccounts(containerId) {
        const checkboxes = document.querySelectorAll(`#${containerId} .account-checkbox:checked`);
        return Array.from(checkboxes).map(cb => cb.value);
    }

    // Start Backup
    function startBackup(accounts, destination) {
        const progressCard = document.getElementById('backup-progress');
        const progressBar = document.getElementById('backup-progress-bar');
        const statusMessage = document.getElementById('backup-status-message');
        const logDiv = document.getElementById('backup-log');
        
        progressCard.style.display = 'block';
        progressBar.style.width = '0%';
        statusMessage.innerHTML = '<div class="loading-spinner"></div> Starting backup...';
        logDiv.innerHTML = '';
        
        apiCall('create_backup', {
            accounts: accounts,
            destination: destination
        }).then(data => {
            if (data.success) {
                progressBar.style.width = '100%';
                statusMessage.innerHTML = '<span class="status-badge status-success">Backup completed!</span>';
                logDiv.textContent = data.log || 'Backup job submitted successfully.';
                loadQueue();
            } else {
                statusMessage.innerHTML = '<span class="status-badge status-error">Backup failed</span>';
                // Show detailed error information
                let errorOutput = '';
                if (data.message) {
                    errorOutput += data.message + '\n\n';
                }
                if (data.errors && data.errors.length > 0) {
                    errorOutput += 'Errors:\n' + data.errors.join('\n') + '\n\n';
                }
                if (data.log) {
                    errorOutput += 'Log:\n' + data.log;
                }
                logDiv.textContent = errorOutput || 'Unknown error occurred.';
            }
        }).catch(err => {
            statusMessage.innerHTML = '<span class="status-badge status-error">Error</span>';
            logDiv.textContent = 'Request failed: ' + (err.message || JSON.stringify(err));
        });
    }

    // Start Restore
    function startRestore(backupFile, account, options, destination) {
        const progressCard = document.getElementById('restore-progress');
        const progressBar = document.getElementById('restore-progress-bar');
        const statusMessage = document.getElementById('restore-status-message');
        const logDiv = document.getElementById('restore-log');
        
        progressCard.style.display = 'block';
        progressBar.style.width = '0%';
        statusMessage.innerHTML = '<div class="loading-spinner"></div> Starting restore...';
        logDiv.innerHTML = '';
        
        apiCall('restore_backup', {
            backup_file: backupFile,
            account: account,
            options: options,
            destination: destination
        }).then(data => {
            if (data.success) {
                progressBar.style.width = '100%';
                statusMessage.innerHTML = '<span class="status-badge status-success">Restore completed!</span>';
                logDiv.innerHTML = data.log || 'Restore completed successfully.';
            } else {
                statusMessage.innerHTML = '<span class="status-badge status-error">Restore failed</span>';
                logDiv.innerHTML = data.message || 'Unknown error occurred.';
            }
        }).catch(err => {
            statusMessage.innerHTML = '<span class="status-badge status-error">Error</span>';
            logDiv.innerHTML = err.message;
        });
    }

    // Load Available Backups
    function loadAvailableBackups() {
        const destination = document.getElementById('restore-destination').value;
        const account = document.getElementById('restore-account').value;
        const select = document.getElementById('restore-backup-file');
        
        if (!destination || !account) {
            select.innerHTML = '<option value="">Select destination and account first...</option>';
            return;
        }
        
        select.innerHTML = '<option value="">Loading backups...</option>';
        
        apiCall('get_remote_backups', { destination: destination, account: account }, 'GET').then(data => {
            if (data.backups && data.backups.length > 0) {
                select.innerHTML = '<option value="">-- Select Backup --</option>';
                data.backups.forEach(backup => {
                    select.innerHTML += `<option value="${backup.file}">${backup.file} (${backup.size}, ${backup.date})</option>`;
                });
            } else {
                select.innerHTML = '<option value="">No backups found</option>';
            }
        }).catch(err => { console.error('Error get_remote_backups', err); const tbody = document.getElementById('remote-backups-tbody'); if(tbody) tbody.innerHTML = '<tr><td colspan="5">Unable to load remote backups.</td></tr>'; });
    }

    // Remove from Queue
    window.removeFromQueue = function(jobId) {
        if (!confirm('Are you sure you want to remove this job from the queue?')) return;
        
        apiCall('remove_from_queue', { job_id: jobId }).then(data => {
            if (data.success) {
                loadQueue();
            } else {
                alert('Error: ' + (data.message || 'Unknown error'));
            }
        }).catch(err => { console.error('Error remove_from_queue', err); alert('Failed to remove job: ' + (err.message || 'Unknown error')); });
    };

    // Remove Schedule
    window.removeSchedule = function(scheduleId) {
        if (!confirm('Are you sure you want to delete this schedule?')) return;
        
        // Use dedicated API action for deleting schedules
        apiCall('delete_schedule', { job_id: scheduleId }).then(data => {
            if (data.success) {
                loadSchedules();
            } else {
                alert('Error: ' + (data.message || 'Unknown error'));
            }
        }).catch(err => { console.error('Error delete_schedule', err); alert('Failed to remove schedule: ' + (err.message || 'Unknown error')); });
    };

    // Close Modal
    window.closeModal = function(modalId) {
        document.getElementById(modalId).classList.remove('active');
    };

    // Make loadLogs global for pagination
    window.loadLogs = loadLogs;

})();
