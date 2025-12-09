# 🔌 BackBork KISS — API Reference

Complete API documentation for BackBork's internal endpoints.

---

## 🔐 Authentication & Security

> [!IMPORTANT]
> **All API endpoints require WHM authentication.** There is no public API access.

### How Authentication Works

BackBork runs inside WHM's CGI environment. Every request is automatically authenticated by WHM's `WHM.php` library before your code executes.

```php
// This happens automatically when index.php loads
require_once('/usr/local/cpanel/php/WHM.php');

// WHM validates the session and provides:
// - $appname (always 'backbork')
// - Authenticated user context
// - Session token validation
```

### Security Layers

| Layer | Protection |
|-------|------------|
| 🔒 **WHM Session** | Must be logged into WHM with valid session |
| 🎫 **CSRF Token** | WHM's built-in token validation |
| 👤 **ACL Check** | User must have `list-accts` privilege |
| 🔑 **Ownership Filter** | Resellers only see their own accounts |

> [!CAUTION]
> **Never expose WHM ports (2086/2087) to the public internet.** Use firewall rules to restrict access to trusted IPs only.

## 🗄️ Audit Logging

BackBork writes operations and events to an audit log for operations tracing. Each entry includes a timestamp, requesting user, event type, affected items, success/failure, message, and the requestor (IP or 'cron'/'local'). The default log location is:

```
/usr/local/cpanel/3rdparty/backbork/logs/operations.log
```

Use this log to trace actions like queue add/remove, schedule create/delete, backup and restore operations, and configuration changes.

---

## 📡 Making Requests

### Base URL

```
https://your-server:2087/cgi/backbork/api/router.php
```

### Request Format

All endpoints use query parameters for the action and POST body for data:

```bash
# GET request
curl -k -H "Authorization: whm root:YOUR_API_TOKEN" \
  "https://server:2087/cgi/backbork/api/router.php?action=get_accounts"

# POST request
curl -k -X POST \
  -H "Authorization: whm root:YOUR_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"accounts":["user1"],"destination":"SFTP_Server"}' \
  "https://server:2087/cgi/backbork/api/router.php?action=create_backup"
```

> [!NOTE]
> The `-k` flag skips SSL verification. In production, use proper certificates.

### Response Format

All responses are JSON:

```json
{
  "success": true,
  "data": { ... },
  "message": "Operation completed"
}
```

Or on error:

```json
{
  "success": false,
  "error": "Error description",
  "code": "ERROR_CODE"
}
```

---

## 📋 Endpoints Reference

### Account Management

#### `GET ?action=get_accounts`

Lists accounts the current user can access.

**Response:**
```json
{
  "success": true,
  "accounts": [
    {
      "user": "someuser",
      "domain": "example.com",
      "owner": "root",
      "email": "user@example.com",
      "plan": "default",
      "suspended": false,
      "diskused": "1.2G",
      "disklimit": "unlimited"
    }
  ]
}
```

> [!TIP]
> For resellers, this automatically filters to only show accounts they own.

---

### Destination Management

#### `GET ?action=get_destinations`

Lists available backup destinations from WHM Backup Configuration.

**Response:**
```json
{
  "success": true,
  "destinations": [
    {
      "id": "SFTP_BackupServer",
      "name": "Backup Server",
      "type": "SFTP",
      "host": "backup.example.com",
      "port": 22,
      "path": "/backups",
      "enabled": true
    },
    {
      "id": "local",
      "name": "Local Storage",
      "type": "Local",
      "path": "/backup",
      "enabled": true
    }
  ]
}
```

#### `POST ?action=validate_destination`

Tests a destination connection.

**Request:**
```json
{
  "destination": "SFTP_BackupServer"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Destination validated successfully"
}
```

> [!NOTE]
> This wraps `/usr/local/cpanel/bin/backup_cmd` internally.

---

### Backup Operations

#### `POST ?action=create_backup`

Starts a backup job for one or more accounts.

**Request:**
```json
{
  "accounts": ["user1", "user2"],
  "destination": "SFTP_BackupServer"
}
```

**Response:**
```json
{
  "success": true,
  "job_id": "job_1702234567_a1b2c3d4",
  "message": "Backup job started",
  "accounts": ["user1", "user2"]
}
```

> [!WARNING]
> Large accounts may take significant time. The job runs asynchronously — use `get_queue` to check progress.

#### `GET ?action=get_queue`

Returns current backup queue status.

**Response:**
```json
{
  "success": true,

#### `POST ?action=process_queue` (Manual Queue Processing) — Root-only

Manually triggers the queue processor to run scheduled jobs and process the pending queue. This action is intended for administrators (root access required) and will also be performed automatically by cron.

**Request:**
```
?action=process_queue
```

**Response:**
```json
{
  "success": true,
  "scheduled": { /* result from processSchedules */ },
  "processed": { /* result from processQueue */ }
}
```

#### `POST ?action=queue_backup`

Adds a backup job to the queue; can be used for immediate (`schedule: 'once'`) or recurring schedules (e.g., `daily`, `weekly`).

**Request:**
```json
{
  "accounts": ["user1", "user2"],
  "destination": "SFTP_BackupServer",
  "schedule": "once", // or 'daily', 'weekly', 'monthly', 'hourly'
  "retention": 30,
  "preferred_time": 2
}
```

**Response:**
```json
{
  "success": true,
  "job_id": "job_1702234567_a1b2c3d4",
  "message": "Job added to queue"
}
```

#### `POST ?action=remove_from_queue`

Removes a queued job immediately. This endpoint also supports removing schedules; if a schedule ID is provided the schedule will be removed instead.

**Request:**
```json
{ "job_id": "job_1702234567_a1b2c3d4" }
```

**Response:**
```json
{ "success": true, "message": "Job removed from queue" }
```
  "queue": {
    "pending": [
      {
        "id": "job_123",
        "accounts": ["user1"],
        "status": "pending",
        "created_at": "2024-01-15T10:00:00Z"
      }
    ],
    "running": [
      {
        "id": "job_456",
        "accounts": ["user2"],
        "status": "running",
        "started_at": "2024-01-15T10:05:00Z",
        "progress": {
          "current": 1,
          "total": 1,
          "current_account": "user2"
        }
      }
    ],
    "completed": [
      {
        "id": "job_789",
        "accounts": ["user3"],
        "status": "completed",
        "success": true,
        "completed_at": "2024-01-15T09:30:00Z"
      }
    ]
  }
}
```

#### `POST ?action=cancel_job`

Cancels a pending backup job.

**Request:**
```json
{
  "job_id": "job_1702234567_a1b2c3d4"
}
```

> [!CAUTION]
> Running jobs cannot be cancelled — only pending jobs in the queue.

---

### Restore Operations

#### `POST ?action=restore_backup`

Initiates a restore operation.

**Request:**
```json
{
  "account": "someuser",
  "backup_file": "cpmove-someuser_2024-01-15_02-00-00.tar.gz",
  "destination": "SFTP_BackupServer",
  "options": {
    "mysql": true,
    "mail_config": true,
    "subdomains": true,
    "homedir": true,
    "ssl": true,
    "cron": true
  }
}
```

**Response:**
```json
{
  "success": true,
  "restore_id": "restore_1702234567_e5f6g7h8",
  "message": "Restore initiated"
}
```

> [!IMPORTANT]
> If `options` is omitted or empty, a **full restore** is performed.

#### `GET ?action=get_restore_status`

Check status of active restores.

**Request:**
```
?action=get_restore_status&restore_id=restore_1702234567_e5f6g7h8
```

**Response:**
```json
{
  "success": true,
  "restore": {
    "id": "restore_1702234567_e5f6g7h8",
    "account": "someuser",
    "status": "running",
    "started_at": "2024-01-15T10:00:00Z",
    "step": "Restoring MySQL databases"
  }
}
```

#### `GET ?action=get_backups` (Local/backups for an account)

Lists available backups stored locally for an account.

**Request:**
```
?action=get_backups&account=someuser
```

**Response:**
```json
{
  "success": true,
  "backups": [
    {
      "file": "cpmove-someuser_2024-01-15_02-00-00.tar.gz",
      "date": "2024-01-15T02:00:00Z",
      "size": 1073741824,
      "destination": "SFTP_BackupServer"
    },
    {
      "file": "cpmove-someuser_2024-01-14_02-00-00.tar.gz",
      "date": "2024-01-14T02:00:00Z",
      "size": 1048576000,
      "destination": "SFTP_BackupServer"
    }
  ]
}
```

#### `GET ?action=get_remote_backups` (Remote backups for a destination)

Lists available backups stored on a remote destination. You can optionally filter by an account substring in the filename using `account` query parameter.

**Request:**
```
?action=get_remote_backups&destination=SFTP_BackupServer&account=someuser
```

**Response:**
```json
{
  "success": true,
  "backups": [
    {
      "file": "cpmove-someuser_20250101_120000.tar.gz",
      "size": "1.2 GB",
      "date": "2025-01-01T12:00:00Z",
      "location": "remote"
    }
  ]
}
```

---

### Schedule Management

#### `GET ?action=get_queue` (includes schedules)

Lists queued jobs, running jobs, and schedules for the current user. Use this endpoint to fetch schedules since the router returns all queue data in one request.

**Response:**
```json
{
  "success": true,
  "queued": [],
  "running": [],
  "schedules": [
    {
      "id": "sched_abc123",
      "name": "Daily Production Backups",
      "accounts": ["user1", "user2"],
      "destination": "SFTP_BackupServer",
      "frequency": "daily",
      "hour": 2,
      "minute": 0,
      "retention_days": 30,
      "enabled": true,
      "last_run": "2024-01-15T02:00:00Z",
      "next_run": "2024-01-16T02:00:00Z"
    }
  ],
  "restores": []
}
```

#### `POST ?action=create_schedule`

Creates a new backup schedule.

**Request:**
```json
{
  "name": "Daily Important Accounts",
  "accounts": ["user1", "user2"],
  "destination": "SFTP_BackupServer",
  "frequency": "daily",
  "hour": 2,
  "minute": 0,
  "retention_days": 30
}
```

**Frequency Options:**

| Value | Description |
|-------|-------------|
| `hourly` | Every hour |
| `daily` | Once per day at specified hour |
| `weekly` | Once per week (requires `day_of_week`: 0-6) |
| `monthly` | Once per month (requires `day_of_month`: 1-31) |

**Response:**
```json
{
  "success": true,
  "job_id": "sched_def456",
  "message": "Schedule created"
}
```

#### `POST ?action=update_schedule` (Not implemented)

This API action is not currently implemented. To update a schedule you can either:

- Delete the schedule (`delete_schedule`) and create a new one (`create_schedule`), or
- Edit the schedule file in the schedules directory directly on the server (advanced / not recommended).

If/when `update_schedule` is implemented in the router, it will accept a `job_id` and a JSON body describing the fields to change (e.g., `enabled`, `frequency`, `hour`, `minute`, `retention`).

#### `POST ?action=delete_schedule`

Deletes a schedule.

**Request:**
```json
{
  "job_id": "sched_abc123"
}
```

> [!TIP]
> Deleting a schedule does not delete any backups that were created by it.

---

### Configuration

#### `GET ?action=get_config`

Gets the current user's configuration.

**Response:**
```json
{
  "success": true,
  "config": {
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
}
```

> [!TIP]
> See [TECHNICAL.md](TECHNICAL.md#config-fields-explained) for a full explanation of each config field.

#### `POST ?action=save_config`

Saves user configuration. You can send partial updates — only the fields you include will be changed.

**Request (minimal):**
```json
{
  "notify_email": "admin@example.com",
  "notify_failure": true
}
```

**Request (full):**
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
  "skip_integrationlinks": false
}
```

> [!NOTE]
> Each user (root, reseller) has their own separate configuration.

---

### Utility Endpoints

#### `GET ?action=get_db_info`

Returns MySQL/MariaDB server information.

**Response:**
```json
{
  "success": true,
  "database": {
    "type": "MariaDB",
    "version": "10.6.12",
    "mariadb_backup_available": true,
    "mysqlbackup_available": false
  }
}
```

#### `GET ?action=check_cron`

Verifies the cron job is configured correctly.

**Response:**
```json
{
  "success": true,
  "cron": {
    "installed": true,
    "file": "/etc/cron.d/backbork",
    "last_run": "2024-01-15T10:00:00Z"
  }
}
```

#### `POST ?action=test_notification`

Sends a test notification.

**Request:**
```json
{
  "type": "email"
}
```

Or for Slack:
```json
{
  "type": "slack"
}
```

#### `GET ?action=get_logs`

Retrieves operation logs.

**Request:**
```
?action=get_logs&job_id=job_1702234567_a1b2c3d4
```

Or for recent logs:
```
?action=get_logs&lines=100
```

---

## 🚨 Error Codes

| Code | Description |
|------|-------------|
| `AUTH_REQUIRED` | Not authenticated to WHM |
| `ACCESS_DENIED` | User lacks permission for this action |
| `INVALID_ACTION` | Unknown action parameter |
| `INVALID_PARAMS` | Missing or invalid request parameters |
| `ACCOUNT_NOT_FOUND` | Specified account doesn't exist |
| `DESTINATION_NOT_FOUND` | Invalid destination ID |
| `DESTINATION_DISABLED` | Destination is disabled |
| `BACKUP_NOT_FOUND` | Backup file not found |
| `JOB_NOT_FOUND` | Job ID not found |
| `SCHEDULE_NOT_FOUND` | Schedule ID not found |
| `ALREADY_RUNNING` | A backup/restore is already running for this account |
| `TRANSPORT_FAILED` | Failed to upload/download via transport |
| `INTERNAL_ERROR` | Unexpected server error |

---

## 🔄 Rate Limiting

> [!NOTE]
> BackBork does not implement its own rate limiting. However, WHM naturally limits concurrent connections per session.

### Best Practices

1. **Don't poll aggressively** — Check queue status every 30-60 seconds, not every second
2. **Batch operations** — Backup multiple accounts in one request
3. **Use webhooks** — Configure Slack/email notifications instead of polling
4. **Respect job limits** — Default concurrent job limit is 2

---

## 🔧 Integration Examples

### Bash Script: Backup All Accounts

```bash
#!/bin/bash

API_TOKEN="your_whm_api_token"
SERVER="your-server.com"

# Get all accounts
ACCOUNTS=$(curl -sk \
  -H "Authorization: whm root:$API_TOKEN" \
  "https://$SERVER:2087/cgi/backbork/api/router.php?action=get_accounts" \
  | jq -r '.accounts[].user' | tr '\n' ',' | sed 's/,$//')

# Start backup
curl -sk -X POST \
  -H "Authorization: whm root:$API_TOKEN" \
  -H "Content-Type: application/json" \
  -d "{\"accounts\":[\"${ACCOUNTS//,/\",\"}\"],\"destination\":\"SFTP_Server\"}" \
  "https://$SERVER:2087/cgi/backbork/api/router.php?action=create_backup"
```

# Delete a schedule
curl -sk -X POST \
  -H "Authorization: whm root:$API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"job_id":"sched_def456"}' \
  "https://$SERVER:2087/cgi/backbork/api/router.php?action=delete_schedule"

### PHP: Check Backup Status

```php
<?php
$server = 'your-server.com';
$token = 'your_whm_api_token';

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => "https://{$server}:2087/cgi/backbork/api/router.php?action=get_queue",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER => [
        "Authorization: whm root:{$token}"
    ]
]);

$response = json_decode(curl_exec($ch), true);
curl_close($ch);

if ($response['success']) {
    $running = count($response['queue']['running']);
    $pending = count($response['queue']['pending']);
    echo "Running: {$running}, Pending: {$pending}\n";
}
```

---

## 📚 Related Documentation

| Resource | Link |
|----------|------|
| 📖 **README** | [README.md](README.md) |
| 🔧 **Technical Docs** | [TECHNICAL.md](TECHNICAL.md) |
| ⏰ **Cron Configuration** | [CRON.md](CRON.md) |
| 🐛 **Report Issues** | [GitHub Issues](https://github.com/The-Network-Crew/BackBork-KISS-Plugin-for-WHM/issues) |

---

<div align="center">

**Made with 💜 by [The Network Crew Pty Ltd](https://tnc.works) & [Velocity Host Pty Ltd](https://velocityhost.com.au)** 💜

</div>
