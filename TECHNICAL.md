# 🔧 BackBork KISS — Technical Documentation

Everything you need to know about how BackBork works under the hood.

---

## 📑 Table of Contents

| Section | What's Covered |
|---------|----------------|
| [🏗️ Architecture](#️-architecture) | How the pieces fit together |
| [🛠️ WHM CLI Tools](#️-whm-cli-tools) | The tools we wrap |
| [📦 Installer](#-installer) | What `install.sh` does |
| [⚙️ Backend Classes](#️-backend-classes) | PHP class overview |
| [💾 Data Storage](#-data-storage) | Where files live |
| [📤 Backup Flow](#-backup-flow) | Step-by-step backup process |
| [📥 Restore Flow](#-restore-flow) | Step-by-step restore process |
| [⚠️ Limitations](#️-limitations--workarounds) | Known issues and fixes |
| [📄 File Formats](#-file-formats) | JSON structures |
| [🔌 API Endpoints](#-api-endpoints) | Available actions |
| [🔒 Security](#-security) | How we keep things safe |
| [🐛 Debugging](#-debugging) | Troubleshooting tips |

---

## 🏗️ Architecture

BackBork is a **thin wrapper** around WHM's existing backup infrastructure:

```
┌─────────────────────────────────────────────────────────┐
│                 🖥️  WHM Interface                       │
│                    index.php                            │
├─────────────────────────────────────────────────────────┤
│  📦 BackBorkBackup     🔄 BackBorkRestore               │
│  📋 BackBorkQueue      ⚙️ BackBorkConfig                │
│  📧 BackBorkNotify     📍 BackBorkDestinations          │
├─────────────────────────────────────────────────────────┤
│              🛠️  WHM CLI Tools                          │
│  ┌────────────┐ ┌──────────────────┐ ┌───────────────┐  │
│  │  pkgacct   │ │ restore_manager  │ │   transport   │  │
│  └────────────┘ └──────────────────┘ └───────────────┘  │
└─────────────────────────────────────────────────────────┘
```

### 🤔 Why This Design?

| Reason | Benefit |
|--------|--------|
| ✅ **Stability** | WHM maintains these tools |
| ✅ **Compatibility** | Standard cpmove format works everywhere |
| ✅ **Simplicity** | Less code = fewer bugs |
| ✅ **Free Updates** | WHM improves tools, we benefit |

> [!TIP]
> By wrapping existing WHM tools instead of reimplementing them, BackBork stays compatible with future WHM updates automatically.

---

## 🛠️ WHM CLI Tools

### 📦 `/scripts/pkgacct` — Account Packaging

Creates a complete backup of an account.

```bash
/scripts/pkgacct [options] <username> [target_directory]
```

**All Supported Options:**

#### Compression & Mode

| Option | What It Does |
|--------|--------------|
| `--compress` | Gzip compression (default) |
| `--nocompress` | No compression (faster, larger files) |
| `--incremental` | Update existing backup (faster for repeat backups) |
| `--split` | Break into smaller chunks for transport |
| `--use_backups` | Use last backup as base for incremental |

#### Database Options

| Option | What It Does |
|--------|--------------|
| `--mysql=<ver>` | Target MySQL version for compatibility |
| `--dbbackup=<type>` | `all` (full), `schema` (structure only), `name` (list only) |

#### Skip Options (22 Total)

| Option | What It Skips |
|--------|---------------|
| `--skiphomedir` | Entire home directory |
| `--skippublichtml` | public_html folder only |
| `--skipmysql` | MySQL/MariaDB databases |
| `--skippgsql` | PostgreSQL databases |
| `--skiplogs` | Access/error logs |
| `--skipmailconfig` | Mail configuration files |
| `--skipmailman` | Mailing list data |
| `--skipdnszones` | DNS zone files |
| `--skipssl` | SSL certificates & keys |
| `--skipbwdata` | Bandwidth tracking data |
| `--skipquota` | Disk quota settings |
| `--skipftpusers` | FTP account data |
| `--skipdomains` | Addon/parked domains |
| `--skipacctdb` | Account database entries |
| `--skipapitokens` | API token data |
| `--skipauthnlinks` | Authentication links |
| `--skiplocale` | Locale/language settings |
| `--skippasswd` | Password file entries |
| `--skipshell` | Shell access settings |
| `--skipresellerconfig` | Reseller configuration |
| `--skipuserdata` | User metadata |
| `--skiplinkednodes` | Linked node data |
| `--skipintegrationlinks` | Third-party integrations |

**Output:** `cpmove-<username>.tar.gz`

---

### 📤 BackBork Perl Transport Helper

BackBork uses a custom Perl helper that interfaces directly with `Cpanel::Transport::Files` for all remote operations. This provides better error handling and control than cPanel's CLI wrapper.

**Location:** `engine/transport/cpanel_transport.pl`

#### ⬆️ Upload (Backup → Remote)

```bash
/usr/local/cpanel/3rdparty/bin/perl cpanel_transport.pl \
  --action=upload --transport=<id> --local=/path/to/file [--remote=subdir/]
```

#### ⬇️ Download (Remote → Local)

```bash
/usr/local/cpanel/3rdparty/bin/perl cpanel_transport.pl \
  --action=download --transport=<id> --remote=<filename> --local=/path/to/save
```

#### 📂 List Files

```bash
/usr/local/cpanel/3rdparty/bin/perl cpanel_transport.pl \
  --action=ls --transport=<id> [--path=subdir]
```

#### 🗑️ Delete File

```bash
/usr/local/cpanel/3rdparty/bin/perl cpanel_transport.pl \
  --action=delete --transport=<id> --path=<filename>
```

> [!NOTE]
> Files are stored directly at the destination's configured base path. The helper automatically prepends the base path from the destination config.

---

### 🔄 `/usr/local/cpanel/bin/backup_restore_manager` — Restore Queue

Manages WHM's restore queue.

```bash
backup_restore_manager <command> [options]
```

| Command | What It Does |
|---------|--------------|
| `add user=X restore_point=YYYY-MM-DD` | Queue a restore |
| `activate` | Start processing |
| `list` | Show queue |
| `list_active` | Show running |
| `list_finished` | Show completed |
| `delete user=X` | Remove from queue |
| `state` | Full status |
| `is_active` | Check if running |

> [!CAUTION]
> **CLI Defaults Differ from GUI!** The options below are **OFF by default** when using CLI — you must explicitly enable them:

```bash
backup_restore_manager add user=someuser restore_point=2024-01-15 \
  mysql=1 \
  mail_config=1 \
  subdomains=1 \
  destid=YOUR_DESTINATION_ID
```

> [!TIP]
> Set `FORCE_SCRIPT_OUTPUT=1` for machine-readable output.

---

### ✅ `/usr/local/cpanel/bin/backup_cmd` — Validate Destinations

```bash
/usr/local/cpanel/bin/backup_cmd id=<transport_id> disableonfail=0
```

| Param | Meaning |
|-------|---------|
| `id` | Transport ID from WHM |
| `disableonfail` | `1` = disable if test fails |

---

### 🗄️ `whmapi1 current_mysql_version` — Database Detection

```yaml
data: 
  server: mariadb
  version: '10.6'
```

We use this to:
- Show database info in Settings
- Recommend mariadb-backup vs mysqlbackup

---

### 📂 cPanel Perl Modules — Internal Transport Layer

cPanel's backup/restore system uses internal Perl modules for transport operations. These are useful reference points for understanding how WHM handles remote storage:

#### Module Locations

```
/usr/local/cpanel/Cpanel/
├── Backup/
│   ├── Config.pm          # Backup configuration
│   ├── Metadata.pm        # Backup metadata handling
│   ├── Queue.pm           # WHM's native backup queue
│   ├── Restore.pm         # Restore operations
│   ├── StreamFileList.pm  # File listing utilities
│   └── Transport/
│       ├── DB.pm          # Transport database
│       ├── History.pm     # Transport history tracking
│       └── Session.pm     # Transport session management
│
└── Transport/
    ├── Files.pm           # Base transport class
    ├── Response.pm        # Transport response handling
    └── Files/
        ├── AmazonS3.pm    # S3 transport
        ├── Backblaze.pm   # B2 transport
        ├── Custom.pm      # Custom transport scripts
        ├── FTP.pm         # FTP transport
        ├── GoogleDrive.pm # Google Drive transport
        ├── Local.pm       # Local filesystem transport
        ├── Rsync.pm       # Rsync transport
        ├── S3Compatible.pm# S3-compatible (MinIO, etc.)
        ├── SFTP.pm        # SFTP transport ← Used by BackBork's Perl helper
        └── WebDAV.pm      # WebDAV transport
```

#### Key Files for Investigation

| File | Purpose | Why It Matters |
|------|---------|----------------|
| `Transport/Files.pm` | Base class for all transports | Defines common interface (get, put, list?) |
| `Transport/Files/SFTP.pm` | SFTP implementation | How WHM handles SFTP operations |
| `Backup/Restore.pm` | Restore orchestration | How "File & Directory Restoration" works |
| `Backup/StreamFileList.pm` | File listing | May contain remote listing logic |
| `Backup/Metadata.pm` | Backup metadata | How WHM tracks what's on remote storage |

#### Examining These Modules

```bash
# View available methods in the base transport class
grep "^sub " /usr/local/cpanel/Cpanel/Transport/Files.pm

# Check SFTP-specific implementation
grep "^sub " /usr/local/cpanel/Cpanel/Transport/Files/SFTP.pm

# Look for list/browse functionality
grep -r "sub list\|sub browse\|sub dir" /usr/local/cpanel/Cpanel/Transport/

# Check how restore reads remote files
grep -r "list\|browse\|readdir" /usr/local/cpanel/Cpanel/Backup/Restore.pm
```

> [!NOTE]
> These modules are cPanel internal and may change between versions. BackBork's Perl helper (`cpanel_transport.pl`) provides a stable interface to these modules.

#### How the Perl Helper Works

1. Reads transport config from `Cpanel::Backup::Transport->get_enabled_destinations()`
2. Instantiates `Cpanel::Transport::Files->new($type, $config)`
3. Calls the appropriate method: `put()`, `get()`, `ls()`, `delete()`, or `mkdir()`
4. Returns JSON to stdout for PHP consumption
5. Debug output goes to stderr for logging

This enables BackBork to:
- Upload backups directly to remote SFTP/FTP destinations
- Download backups for restore
- List backups on remote destinations
- Delete old backups for retention management
- Create directories as needed

---

### 🔥 Hot Database Backups

BackBork supports hot database backups using mariadb-backup or mysqlbackup.

#### How It Works

| Step | Action |
|------|--------|
| 1️⃣ | pkgacct runs with `--dbbackup=schema` (schema only, no data) |
| 2️⃣ | mariadb-backup/mysqlbackup runs to capture DB data |
| 3️⃣ | Both files uploaded: `cpmove-*` + `db-backup-*` |
| 4️⃣ | On restore: main backup restored first (includes schema) |
| 5️⃣ | DB data restored from hot backup file |
| 6️⃣ | Both temp files cleaned up |

#### Backup Methods

| Method | Description |
|--------|-------------|
| `pkgacct` | Default mysqldump via pkgacct (locks tables briefly) |
| `mariadb-backup` | Hot backup for MariaDB (no locks!) |
| `mysqlbackup` | MySQL Enterprise Backup (commercial) |
| `skip` | Skip databases entirely |

---

## 📦 Installer

### What `install.sh` Does

| Step | Action |
|------|--------|
| 1️⃣ | Check running as root |
| 2️⃣ | Verify WHM exists |
| 3️⃣ | Detect MySQL/MariaDB |
| 4️⃣ | Install mariadb-backup (if MariaDB) |
| 5️⃣ | Create directories |
| 6️⃣ | Copy plugin files |
| 7️⃣ | Set permissions |
| 8️⃣ | Register AppConfig |
| 9️⃣ | Setup cron jobs |
| 🔟 | Restart cpsrvd |

### 📁 Directories Created

```
/usr/local/cpanel/whostmgr/docroot/cgi/backbork/
/usr/local/cpanel/3rdparty/backbork/
  ├── users/
  ├── schedules/
  ├── queue/
  ├── running/
  ├── restores/
  ├── completed/
  └── logs/
```

### 🔐 Permissions Set

| Path | Mode | Why |
|------|------|-----|
| Plugin directory | 755 | Web accessible |
| PHP files | 644 | Read-only |
| cron.php | 755 | Executable |
| Data directories | 700 | Root only |
| Config files | 600 | Root only |

### ⏰ Cron Jobs

```cron
# Queue Processor: Process backup queue (every 5 minutes)
*/5 * * * * root php .../cron.php process

# Daily 3AM: Cleanup old jobs/logs
0 3 * * * root php .../cron.php cleanup
```

---

## ⚙️ Backend Classes

| Class | Purpose |
|-------|---------|
| `BackBorkBackup` | 📦 Create backups, run pkgacct |
| `BackBorkRestore` | 🔄 Restore operations |
| `BackBorkQueue` | 📋 Job queue management |
| `BackBorkConfig` | ⚙️ Per-user settings |
| `BackBorkDestinations` | 📍 Read WHM destinations |
| `BackBorkNotify` | 📧 Email/Slack alerts |
| `BackBorkACL` | 🔒 Access control |

---

## 💾 Data Storage

Everything lives in `/usr/local/cpanel/3rdparty/backbork/`:

```
backbork/
├── 👤 users/           Per-user configs (root.json, reseller.json)
├── 📅 schedules/       Scheduled job definitions
├── 📋 queue/           Pending jobs
├── 🏃 running/         Currently executing
├── 🔄 restores/        Active restore tracking
├── ✅ completed/       Job history
└── 📝 logs/            Operation logs
```

> [!NOTE]
> These are metadata files only. Actual backup archives go to your configured destination (local path or remote SFTP server).

---

## 📤 Backup Flow

BackBork provides real-time progress logging throughout the backup process. Each step is logged to a unique backup log file that can be polled for live updates.

```
👆 User clicks "Backup"
         │
         ▼
    ┌─────────┐
    │ 📝 Log  │  Create backup_<timestamp>_<id>.log
    └────┬────┘
         │
         ▼
    ┌─────────┐
    │ 🔒 ACL  │  Can this user backup this account?
    └────┬────┘
         │
         ▼
    ┌─────────┐
    │ ✅ Dest │  [STEP 1/5] Validate destination
    └────┬────┘
         │
         ▼
    ┌─────────┐
    │ 📧 Start│  [STEP 2/5] Send notification (if enabled)
    └────┬────┘
         │
         ▼
    ┌─────────────────────────────────────────┐
    │ 📦 [STEP 3/5] Process Each Account      │
    │                                         │
    │  [3a] Prepare environment               │
    │  [3b] Run pkgacct                       │
    │  [3c] Hot DB backup (if configured)     │
    │  [3d] Upload to destination             │
    │  [3e] Cleanup temp files                │
    └────┬────────────────────────────────────┘
         │
         ▼
    ┌─────────┐
    │ 📊 Sum  │  [STEP 4/5] Summary (success/fail counts)
    └────┬────┘
         │
         ▼
    ┌─────────┐
    │ 📧 Done │  [STEP 5/5] Send completion notification
    └─────────┘
```

### Backup Log File

Each backup creates a log file at:
```
/usr/local/cpanel/3rdparty/backbork/logs/backup_<timestamp>_<id>.log
```

The UI polls this file in real-time using `GET ?action=get_backup_log&backup_id=<id>&offset=<bytes>` to show live progress.

---

## 📥 Restore Flow

BackBork provides real-time progress logging throughout the restore process with detailed step-by-step updates.

```
👆 User clicks "Restore"
         │
         ▼
    ┌─────────┐
    │ 📝 Log  │  Create restore_<timestamp>_<id>.log
    └────┬────┘
         │
         ▼
    ┌─────────┐
    │ 🔒 ACL  │  Can this user restore this account?
    └────┬────┘
         │
         ▼
    ┌─────────┐
    │ 📥 Fetch│  [STEP 1/8] Download from remote (if needed)
    └────┬────┘
         │
         ▼
    ┌─────────┐
    │ ✅ Verify│ [STEP 2/8] Check backup file integrity
    └────┬────┘
         │
         ▼
    ┌─────────┐
    │ 🗄️ DB  │  [STEP 3/8] Check for hot DB backup file
    └────┬────┘
         │
         ▼
    ┌─────────┐
    │ 📧 Start│  [STEP 4/8] Send start notification
    └────┬────┘
         │
         ▼
    ┌──────────────────────────┐
    │ 🔄 [STEP 5/8] Restore    │
    │                          │
    │ Full: restorepkg         │
    │ Selective: restore_mgr   │
    └────┬─────────────────────┘
         │
         ▼
    ┌─────────┐
    │ 🗄️ DB  │  [STEP 6/8] Restore hot DB data (if exists)
    └────┬────┘
         │
         ▼
    ┌─────────┐
    │ 🧹 Clean│  [STEP 7/8] Remove downloaded temp files
    └────┬────┘
         │
         ▼
    ┌─────────┐
    │ 📧 Done │  [STEP 8/8] Send completion notification
    └─────────┘
```

### Restore Log File

Each restore creates a log file at:
```
/usr/local/cpanel/3rdparty/backbork/logs/restore_<timestamp>_<id>.log
```

The UI polls this file in real-time using `GET ?action=get_restore_log&restore_id=<id>&offset=<bytes>` to show live progress.

> [!NOTE]
> Downloaded backup files are automatically cleaned up after restore completes (success or failure). The cron job also runs `cleanupTempFiles(24)` to catch any orphaned files older than 24 hours.

---

## ⚠️ Limitations & Workarounds

> [!WARNING]
> These are known limitations of WHM's underlying tools, not BackBork bugs. We've documented workarounds below.

### 1️⃣ No Auto Folder Creation

| Problem | Can't auto-create `daily/2024-01-15/` folders |
|---------|-----------------------------------------------|
| **Workaround** | Timestamps in filenames: `cpmove-user_2024-01-15_14-30-00.tar.gz` |

### 3️⃣ CLI Restore Defaults

| Problem | `mysql=1`, `mail_config=1` are OFF by default from CLI |
|---------|--------------------------------------------------------|
| **Workaround** | We explicitly pass all options |

### 4️⃣ Reseller Destinations

| Problem | Resellers can't create destinations |
|---------|-------------------------------------|
| **Workaround** | Root configures in WHM; resellers see (read-only) |

### 5️⃣ Database Table Locks

| Problem | mysqldump locks tables |
|---------|------------------------|
| **Workaround** | Support mariadb-backup for hot backups |

### 6️⃣ Split Files

| Problem | Some transports split large files |
|---------|-----------------------------------|
| **Workaround** | `--download` auto-reconstructs from parts |

---

## 📄 File Formats

### 🌐 Global Config

`global.json` (root-only settings that affect all users):

```json
{
  "schedules_locked": false,
  "debug_mode": false,
  "updated_at": "2024-01-15 14:30:00"
}
```

| Field | Type | Description |
|-------|------|-------------|
| `schedules_locked` | bool | Prevent resellers from managing schedules |
| `debug_mode` | bool | Enable verbose logging to PHP error_log |
| `updated_at` | string | Last modification time |

> [!NOTE]
> When `schedules_locked` is enabled, resellers see a lock icon and cannot create, edit, or delete schedules. Existing schedules continue to run.

### 👤 User Config

`users/{username}.json`:

```json
{
  "notify_email": "admin@example.com",
  "slack_webhook": "https://hooks.slack.com/services/...",
  "notify_success": true,
  "notify_failure": true,
  "notify_start": false,
  "compression_option": "compress",
  "compression_level": "5",
  "temp_directory": "/home/backbork_tmp",
  "exclude_paths": "",
  "default_retention": 30,
  "default_schedule": "daily",
  "mysql_version": "",
  "dbbackup_type": "all",
  "db_backup_method": "pkgacct",
  "opt_incremental": false,
  "opt_split": false,
  "opt_use_backups": false,
  "skip_homedir": false,
  "skip_publichtml": false,
  "skip_mysql": false,
  "skip_pgsql": false,
  "skip_logs": true,
  "skip_mailconfig": false,
  "skip_mailman": false,
  "skip_dnszones": false,
  "skip_ssl": false,
  "skip_bwdata": true,
  "skip_quota": false,
  "skip_ftpusers": false,
  "skip_domains": false,
  "skip_acctdb": false,
  "skip_apitokens": false,
  "skip_authnlinks": false,
  "skip_locale": false,
  "skip_passwd": false,
  "skip_shell": false,
  "skip_resellerconfig": false,
  "skip_userdata": false,
  "skip_linkednodes": false,
  "skip_integrationlinks": false,
  "created_at": "2024-01-15 10:00:00",
  "updated_at": "2024-01-15 14:30:00"
}
```

#### Config Fields Explained

| Field | Type | Description |
|-------|------|-------------|
| **Notifications** |||
| `notify_email` | string | Email address for notifications |
| `slack_webhook` | string | Slack incoming webhook URL |
| `notify_success` | bool | Send notification on successful backup |
| `notify_failure` | bool | Send notification on failed backup |
| `notify_start` | bool | Send notification when backup starts |
| **Compression** |||
| `compression_option` | string | `compress` or `nocompress` |
| `compression_level` | string | Gzip level 1-9 (default: 5) |
| **Paths** |||
| `temp_directory` | string | Temp storage for backups before upload |
| `exclude_paths` | string | Comma-separated paths to exclude |
| **Defaults** |||
| `default_retention` | int | Number of backups to keep (0 = unlimited) |
| `default_schedule` | string | Default frequency for new schedules |
| **Database** |||
| `mysql_version` | string | Target MySQL version for compatibility |
| `dbbackup_type` | string | `all`, `schema`, or `name` |
| `db_backup_method` | string | `pkgacct`, `mariadb-backup`, or `skip` |
| **Backup Mode** |||
| `opt_incremental` | bool | Use incremental backups |
| `opt_split` | bool | Split into smaller files |
| `opt_use_backups` | bool | Use previous backup as base |
| **Skip Options** |||
| `skip_homedir` | bool | Skip entire home directory |
| `skip_publichtml` | bool | Skip public_html only |
| `skip_mysql` | bool | Skip MySQL databases |
| `skip_pgsql` | bool | Skip PostgreSQL databases |
| `skip_logs` | bool | Skip access/error logs |
| `skip_mailconfig` | bool | Skip mail configuration |
| `skip_mailman` | bool | Skip mailing lists |
| `skip_dnszones` | bool | Skip DNS zones |
| `skip_ssl` | bool | Skip SSL certs |
| `skip_bwdata` | bool | Skip bandwidth data |
| `skip_quota` | bool | Skip quota settings |
| `skip_ftpusers` | bool | Skip FTP accounts |
| `skip_domains` | bool | Skip addon/parked domains |
| `skip_acctdb` | bool | Skip account database |
| `skip_apitokens` | bool | Skip API tokens |
| `skip_authnlinks` | bool | Skip auth links |
| `skip_locale` | bool | Skip locale settings |
| `skip_passwd` | bool | Skip password entries |
| `skip_shell` | bool | Skip shell settings |
| `skip_resellerconfig` | bool | Skip reseller config |
| `skip_userdata` | bool | Skip user metadata |
| `skip_linkednodes` | bool | Skip linked nodes |
| `skip_integrationlinks` | bool | Skip integrations |
| **Timestamps** |||
| `created_at` | string | When config was created |
| `updated_at` | string | Last modification time |

### 📅 Schedule

`schedules/{id}.json`:

```json
{
  "id": "sched_abc123",
  "name": "Daily Important Accounts",
  "accounts": ["user1", "user2"],
  "all_accounts": false,
  "destination": "SFTP_Server",
  "frequency": "daily",
  "hour": 2,
  "retention_days": 30,
  "enabled": true,
  "owner": "root",
  "last_run": "2024-01-16T02:00:00Z",
  "next_run": "2024-01-17T02:00:00Z"
}
```

| Field | Type | Description |
|-------|------|-------------|
| `all_accounts` | bool | When `true`, dynamically includes all accounts accessible to the owner at runtime |
| `retention_days` | int | Number of backups to keep per account (0 = unlimited) |
| `owner` | string | Username who created the schedule (for ACL filtering) |

> [!TIP]
> Use `all_accounts: true` for schedules that should automatically include newly created accounts without manual updates.

> [!NOTE]
> **Retention Pruning (v1.2.8+):** Uses count-based retention. When an account has more backups than `retention_days`, the oldest excess backups are deleted during the hourly cron run. Set to `0` for unlimited retention. This is inherently safe: if you have fewer backups than the limit, nothing is deleted.
```

### 📋 Job

`queue/{id}.json` or `running/{id}.json`:

```json
{
  "id": "job_xyz789",
  "type": "backup",
  "accounts": ["user1"],
     "destination": "SFTP_Server",
  "status": "running",
  "started_at": "2024-01-15T02:00:05Z",
  "progress": {
    "current": 1,
    "total": 1,
    "current_account": "user1"
  }
}
```

---

## 🔌 API Endpoints

Calls should be made to the internal API router. While `index.php?action=...` was previously supported, call `api/router.php?action=...` directly for clarity and consistency.

All require WHM authentication.

> [!NOTE]
> For detailed API documentation including request/response examples, see [API.md](API.md).

| Endpoint | Method | What It Does |
|----------|--------|--------------|
| `?action=get_accounts` | GET | List accounts (ACL filtered) |
| `?action=get_destinations` | GET | List destinations |
| `?action=get_config` | GET | Get user config |
| `?action=save_config` | POST | Save user config |
| `?action=get_global_config` | GET | Get global config (root only) |
| `?action=save_global_config` | POST | Save global config (root only) |
| `?action=create_backup` | POST | Start backup |
| `?action=queue_backup` | POST | Add a backup job to the queue (immediate or scheduled) |
| `?action=remove_from_queue` | POST | Remove a specific queued job or schedule |
| `?action=get_queue` | GET | Queue status (includes queued, running, and schedules) |
| `?action=get_backups` | GET | List local backups for an account |
| `?action=get_remote_backups` | GET | List backups on a remote destination (optional account substring filter) |
| `?action=get_backup_accounts` | GET | List accounts with backups at a destination |
| `?action=list_backups` | GET | List backup files for an account at a destination |
| `?action=delete_backup` | POST | Delete a backup file (local destinations only) |
| `?action=create_schedule` | POST | Create schedule |
| `?action=delete_schedule` | POST | Delete schedule |
| `?action=process_queue` | POST | Manually trigger queue processing (also run by cron) |
| `?action=restore_backup` | POST | Start restore |
| `?action=get_logs` | GET | Get logs |
| `?action=get_db_info` | GET | Database info |
| `?action=check_cron` | GET | Cron status |
| `?action=test_notification` | POST | Test alert |

> [!NOTE]
> All operations (backups, restores, queue and schedule changes, configuration updates) are audited to `/usr/local/cpanel/3rdparty/backbork/logs/operations.log`. Log entries include the requesting user and requestor IP (or 'cron' for scheduled tasks).

---

## 🔒 Security

| Measure | How |
|---------|-----|
| 🔐 **Authentication** | All requests through WHM auth |
| 👥 **ACL Enforcement** | Resellers only see their accounts |
| 📁 **File Permissions** | 600/700 for data files |
| 🚫 **No Stored Creds** | SFTP creds stay in WHM config |
| 🧹 **Input Sanitization** | Account names validated/escaped |

> [!CAUTION]
> **Never expose WHM ports (2086/2087) to the public internet.** Always use firewall rules to restrict access to trusted IPs only.

---

## 🐛 Debugging

### 📝 Check Logs

```bash
# Plugin logs
tail -f /usr/local/cpanel/3rdparty/backbork/logs/cron.log

# Job-specific logs
cat /usr/local/cpanel/3rdparty/backbork/logs/job_*.log

# WHM backup logs
tail -f /usr/local/cpanel/logs/cpbackup/*
```

### 🔍 Debug Transport

```bash
# Test upload via BackBork's Perl helper
/usr/local/cpanel/3rdparty/bin/perl \
  /usr/local/cpanel/whostmgr/docroot/cgi/backbork/engine/transport/cpanel_transport.pl \
  --action=upload --transport=SFTP_Server --local=/path/to/file 2>&1
```

### ✅ Test Destination

```bash
/usr/local/cpanel/bin/backup_cmd id=SFTP_Server disableonfail=0
```

> [!TIP]
> If backups fail silently, check `/usr/local/cpanel/logs/cpbackup/` for WHM's own error logs. These often contain more detail than BackBork's logs.

---

## 📚 Related Documentation

| Resource | Link |
|----------|------|
| 📖 **README** | [README.md](README.md) |
| 🔌 **API Reference** | [API.md](API.md) |
| ⏰ **Cron Configuration** | [CRON.md](CRON.md) |
| 🐛 **Report Issues** | [GitHub Issues](https://github.com/The-Network-Crew/BackBork-KISS-Plugin-for-WHM/issues) |

---

<div align="center">

📖 See [README.md](README.md) for user-focused documentation

**Made with 💜 by [The Network Crew Pty Ltd](https://tnc.works) & [Velocity Host Pty Ltd](https://velocityhost.com.au)** 💜

</div>
