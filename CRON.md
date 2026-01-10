# ⏰ BackBork KISS — Cron Configuration

Everything you need to know about BackBork's cron requirements and monitoring.

---

## 📑 Table of Contents

| Section | Description |
|---------|-------------|
| [⚠️ Why Cron Is Required](#️-why-cron-is-required) | Understanding the dependency |
| [🔧 Automatic Installation](#-automatic-installation) | What the installer does |
| [📋 Cron Jobs Explained](#-cron-jobs-explained) | What each job does |
| [✅ Status Monitoring](#-status-monitoring) | How BackBork checks cron |
| [🚨 Failure Notifications](#-failure-notifications) | Alerts when cron fails |
| [🔍 Manual Verification](#-manual-verification) | How to check yourself |
| [🛠️ Troubleshooting](#️-troubleshooting) | Common issues and fixes |

---

## ⚠️ Why Cron Is Required

> [!IMPORTANT]
> **BackBork requires cron to function.** Without it, scheduled backups won't run and the queue won't process.

BackBork uses cron for:

| Function | Why It Needs Cron |
|----------|-------------------|
| 📅 **Scheduled Backups** | Triggers hourly/daily/weekly/monthly jobs |
| 📋 **Queue Processing** | Processes pending backup/restore jobs |
| 🧹 **Cleanup** | Removes old completed jobs and logs |
| 🔍 **Self-Monitoring** | Checks its own health status |

### What Happens Without Cron?

- ❌ Scheduled backups **never run**
- ❌ Queued jobs **stay pending forever**
- ❌ Old logs and job files **accumulate**
- ❌ No automatic health monitoring

---

## 🔧 Automatic Installation

The `install.sh` script **automatically installs** the cron configuration:

```bash
# This happens during installation
cat > /etc/cron.d/backbork << 'EOF'
# BackBork KISS - Queue and Schedule Processor
*/5 * * * * root /usr/local/cpanel/3rdparty/bin/php /usr/local/cpanel/whostmgr/docroot/cgi/backbork/cron/handler.php >> /usr/local/cpanel/3rdparty/backbork/logs/cron.log 2>&1

# Daily summary notification (runs at midnight)
0 0 * * * root /usr/local/cpanel/3rdparty/bin/php /usr/local/cpanel/whostmgr/docroot/cgi/backbork/cron/handler.php summary >> /usr/local/cpanel/3rdparty/backbork/logs/cron.log 2>&1

# Daily cleanup of old completed jobs and logs (runs at 3 AM)
0 3 * * * root /usr/local/cpanel/3rdparty/bin/php /usr/local/cpanel/whostmgr/docroot/cgi/backbork/cron/handler.php cleanup >> /usr/local/cpanel/3rdparty/backbork/logs/cron.log 2>&1
EOF
```

> [!NOTE]
> You don't need to manually configure cron — the installer handles everything.

---

## 📋 Cron Jobs Explained

### 1️⃣ Queue Processor (Every 5 minutes)

```cron
*/5 * * * * root php .../cron/handler.php
```

| Aspect | Details |
|--------|---------|
| **Schedule** | Every 5 minutes |

> **Note:** Manual processing using the "Process Queue Now" button is only available to root users; resellers cannot trigger it from the UI.
| **User** | root |
| **Purpose** | Process backup queue and scheduled jobs |

**What It Does:**

1. 📅 Checks for scheduled backups due to run
2. ✅ **Verifies destination is enabled** (skips disabled destinations)
3. 📋 Adds due schedules to the queue
4. 🏃 Processes pending queue items
5. 📊 Updates job status
6. 📧 Sends notifications on completion
7. 🗑️ **Prunes old backups** based on schedule retention settings (manifest-based)
8. 🔍 Performs self-health check

> [!NOTE]
> **Destination Validation:** If a WHM backup destination is disabled, schedules targeting that destination are skipped. This prevents backup failures and wasted processing when destinations are temporarily unavailable.

### 2️⃣ Retention Pruning (Manifest-Based)

> [!IMPORTANT]
> **New in v1.4.3:** BackBork now uses a manifest system to track which backups belong to which schedules, enabling intelligent per-schedule pruning.

Retention pruning runs **after each scheduled backup job completes** and uses the manifest to identify which backups to prune.

**How It Works:**

| Aspect | Details |
|--------|---------|
| **Trigger** | After each scheduled backup completes |
| **Scope** | Per-schedule (only prunes backups created by that schedule) |
| **Retention = 0** | Unlimited (no pruning) |
| **Default** | 30 backups |
| **Method** | Manifest-based count (keeps N newest per schedule) |
| **Manual backups** | Never pruned (tracked as `_manual` in manifest) |

**Pruning Logic:**

1. 📋 Reads the manifest file for the destination
2. 🔍 Filters entries for the specific schedule ID
3. 🗓️ Sorts entries by creation date (newest first)
4. 📊 Compares count: if entries ≤ retention, **nothing is deleted**
5. 🗑️ If entries > retention, deletes the oldest excess backups **and their DB files**
6. 📝 Removes pruned entries from the manifest

> [!NOTE]
> **Schedule Isolation:** Each schedule manages its own backups independently. A daily schedule with 7-day retention won't delete backups from a monthly schedule with 12-month retention, even for the same account.

**Example:**
- Daily schedule has `retention: 7` for account `someuser`
- Monthly schedule has `retention: 4` for account `someuser`
- After daily backup: prunes daily backups only (keeps 7 newest)
- After monthly backup: prunes monthly backups only (keeps 4 newest)
- Both schedule types coexist without interfering

**Manual/One-Time Backups:**
- Tracked in manifest with `schedule_id: "_manual"`
- **Never automatically pruned** — must be deleted manually via Data tab
- This ensures on-demand backups are preserved regardless of retention settings

> [!WARNING]
> **Legacy Backups:** Backups created before v1.4.3 are not tracked in the manifest and will NOT be automatically pruned. You can delete these manually via the Data tab or command line.

### 3️⃣ Daily Summary (Midnight)

```cron
0 0 * * * root php .../cron/handler.php summary
```

| Aspect | Details |
|--------|---------|
| **Schedule** | Daily at midnight (00:00) |
| **User** | root (sends to all opted-in users) |
| **Purpose** | Send daily activity digest via Email/Slack |

> [!IMPORTANT]
> **New in v1.2.8:** Daily summary notifications provide a digest of the past 24 hours.

**Opt-In Setting:**

Each user can enable daily summaries in **Settings → Notification Settings**:
- ☑️ **Daily Summary (midnight)** checkbox

The cron job scans all user configs and sends summaries only to users who have:
1. Enabled the "Daily Summary" checkbox
2. Configured Email and/or Slack webhook

**What It Reports:**

| Metric | Description |
|--------|-------------|
| 📊 **Backups** | Successful and failed backup counts |
| 🔄 **Restores** | Successful and failed restore counts |
| 📅 **Schedules** | Number of scheduled jobs that ran |
| 🗑️ **Pruned** | Backups deleted by retention policy |
| 📋 **Queue** | Current pending/completed/failed jobs |
| ❌ **Errors** | Recent error messages (up to 5) |

**Notification Channels:**
- **Email:** Plain text digest to configured address
- **Slack:** Rich Block Kit formatted message with sections

**Status Indicators:**
- ✅ **All Good** — Activity with no failures
- ⚠️ **Issues Detected** — One or more failures occurred
- ℹ️ **No Activity** — Nothing happened in 24 hours

### 4️⃣ Daily Cleanup

```cron
0 3 * * * root php .../cron/handler.php cleanup
```

| Aspect | Details |
|--------|---------|
| **Schedule** | Daily at 3:00 AM |
| **User** | root |
| **Purpose** | Housekeeping and maintenance |

**What It Does:**

1. 🗑️ Removes completed job files older than 30 days
2. 📝 Rotates log files
3. 🧹 Cleans up orphaned temp files

---

## ✅ Status Monitoring

### In-App Status Check

BackBork **automatically checks cron status** when you open the Settings tab:

```
Settings → Cron Status
```

The status display shows:

| Status | Meaning |
|--------|---------|
| ✅ **Installed** | Cron file exists and is configured |
| ⏱️ **Last Run** | When cron last executed |
| 📅 **Next Run** | When cron will next execute |
| ❌ **Not Installed** | Cron file missing — needs attention |
| ⚠️ **Stale** | Cron hasn't run recently — may be stuck |

### API Endpoint

```bash
curl -k -H "Authorization: whm root:TOKEN" \
  "https://server:2087/cgi/backbork/api/router.php?action=check_cron"
```

**Response:**
```json
{
  "success": true,
  "cron": {
    "installed": true,
    "file": "/etc/cron.d/backbork",
    "last_run": "2024-01-15T10:00:00Z",
    "next_run": "2024-01-15T11:00:00Z",
    "status": "healthy"
  }
}
```

---

## 🚨 Failure Notifications

> [!IMPORTANT]
> **New in v1.1.1:** BackBork now sends email/Slack alerts if cron status checks fail.

### What Triggers an Alert?

| Condition | Alert Sent |
|-----------|------------|
| 🚫 Cron file missing | Yes |
| ⏱️ No run in 30+ minutes | Yes |
| ❌ Cron execution error | Yes |
| 🔒 Permission denied | Yes |

### Alert Content

**Email Subject:**
```
⚠️ [BackBork KISS] Cron Health Check Failed on server.example.com
```

**Email Body:**
```
BackBork KISS - Cron Health Alert
------------------------------

Server: server.example.com
Time: 2024-01-15 12:00:00 UTC
Status: FAILED

Issue: Cron has not run in over 30 minutes

Last successful run: 2024-01-15 09:00:00 UTC
Expected interval: ~5 minutes

Action Required:
1. Check if crond service is running
2. Verify /etc/cron.d/backbork exists
3. Check cron logs: /var/log/cron

──────────────────────────
This is an automated alert from BackBork KISS
```

**Slack Message:**
```json
{
  "text": "⚠️ BackBork Cron Health Check Failed",
  "blocks": [
    {
      "type": "header",
      "text": "⚠️ Cron Health Alert"
    },
    {
      "type": "section",
      "fields": [
        { "type": "mrkdwn", "text": "*Server:*\nserver.example.com" },
        { "type": "mrkdwn", "text": "*Status:*\n🔴 FAILED" },
        { "type": "mrkdwn", "text": "*Issue:*\nNo run in 30+ minutes" },
        { "type": "mrkdwn", "text": "*Last Run:*\n2024-01-15 09:00" }
      ]
    }
  ]
}
```

### Configuring Alerts

Cron failure alerts use your existing notification settings:

1. Go to **Settings** tab
2. Configure **Email Address** and/or **Slack Webhook**
3. Alerts are sent automatically when issues are detected

> [!TIP]
> Enable both email and Slack for redundant alerting — if one fails, the other still notifies you.

---

## 🔍 Manual Verification

### Check Cron File Exists

```bash
cat /etc/cron.d/backbork
```

**Expected Output:**
```cron
# BackBork KISS - Queue and Schedule Processor
*/5 * * * * root /usr/local/cpanel/3rdparty/bin/php /usr/local/cpanel/whostmgr/docroot/cgi/backbork/cron/handler.php >> /usr/local/cpanel/3rdparty/backbork/logs/cron.log 2>&1

# Daily cleanup
0 3 * * * root /usr/local/cpanel/3rdparty/bin/php /usr/local/cpanel/whostmgr/docroot/cgi/backbork/cron/handler.php cleanup >> /usr/local/cpanel/3rdparty/backbork/logs/cron.log 2>&1
```

### Check Cron Service

```bash
# CentOS/RHEL/AlmaLinux
systemctl status crond

# Ubuntu/Debian
systemctl status cron
```

### Check Cron Logs

```bash
# System cron log
tail -f /var/log/cron

# BackBork cron log
tail -f /usr/local/cpanel/3rdparty/backbork/logs/cron.log
```

### Test Manual Execution

```bash
/usr/local/cpanel/3rdparty/bin/php \
  /usr/local/cpanel/whostmgr/docroot/cgi/backbork/cron/handler.php
```

---

## 🛠️ Troubleshooting

### ❌ Cron Not Installed

**Symptom:** Settings shows "Cron: Not Installed"

**Fix:**
```bash
# Re-run installer
cd /path/to/BackBork-KISS-for-WHM
./install.sh

# Or manually create:
cat > /etc/cron.d/backbork << 'EOF'
*/5 * * * * root /usr/local/cpanel/3rdparty/bin/php /usr/local/cpanel/whostmgr/docroot/cgi/backbork/cron/handler.php >> /usr/local/cpanel/3rdparty/backbork/logs/cron.log 2>&1
0 3 * * * root /usr/local/cpanel/3rdparty/bin/php /usr/local/cpanel/whostmgr/docroot/cgi/backbork/cron/handler.php cleanup >> /usr/local/cpanel/3rdparty/backbork/logs/cron.log 2>&1
EOF
chmod 644 /etc/cron.d/backbork
```

### ⏱️ Cron Not Running

**Symptom:** Last run time is hours/days ago

**Checks:**
```bash
# Is crond running?
systemctl status crond

# Restart if needed
systemctl restart crond

# Check for syntax errors
crontab -l
cat /etc/cron.d/backbork
```

### 🔒 Permission Denied

**Symptom:** Cron log shows permission errors

**Fix:**
```bash
# Fix permissions
chmod 755 /usr/local/cpanel/whostmgr/docroot/cgi/backbork/cron/handler.php
chmod 700 /usr/local/cpanel/3rdparty/backbork/
chmod 700 /usr/local/cpanel/3rdparty/backbork/logs/
```

### 📝 No Log Output

**Symptom:** cron.log is empty or not updating

**Checks:**
```bash
# Check log directory exists
ls -la /usr/local/cpanel/3rdparty/backbork/logs/

# Check disk space
df -h

# Test write permissions
touch /usr/local/cpanel/3rdparty/backbork/logs/test.txt
rm /usr/local/cpanel/3rdparty/backbork/logs/test.txt
```

### 🔄 Cron Running But Jobs Not Processing

**Symptom:** Cron runs but queue items stay pending

**Checks:**
```bash
# Check for lock file (indicates already running)
ls -la /usr/local/cpanel/3rdparty/backbork/queue.lock

# Remove stale lock (if process died)
rm -f /usr/local/cpanel/3rdparty/backbork/queue.lock

# Check queue files
ls -la /usr/local/cpanel/3rdparty/backbork/queue/
```

---

## 📚 Related Documentation

| Resource | Link |
|----------|------|
| 📖 **README** | [README.md](README.md) |
| 🔧 **Technical Docs** | [TECHNICAL.md](TECHNICAL.md) |
| 🔌 **API Reference** | [API.md](API.md) |
| 🐛 **Report Issues** | [GitHub Issues](https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/issues) |

---

**Made with 💜 by [The Network Crew Pty Ltd](https://tnc.works) & [Velocity Host Pty Ltd](https://velocityhost.com.au)** 💜
