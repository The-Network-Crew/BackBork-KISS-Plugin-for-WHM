# 🤖 BackBork KISS — Automation & Orchestration

> **Note:** Despite the filename, this document covers **Ansible** automation. 😉

This guide demonstrates how to automate BackBork configuration using Ansible playbooks, enabling Infrastructure as Code (IaC) for your backup strategy across multiple cPanel/WHM servers.

---

## 📋 Overview

BackBork's API can be accessed via PHP CLI directly on the server, making it perfect for configuration management tools like Ansible. No network API exposure required — all commands run locally as root.

### Why CLI over HTTP?

| Approach | Pros | Cons |
|----------|------|------|
| **PHP CLI (recommended)** | No network exposure, no auth tokens, runs as root | Requires SSH access |
| **HTTP API** | Remote access possible | Requires WHM port exposure, API tokens |

---

## 🔧 Prerequisites

### On Control Node (Ansible Server)

```bash
# Install Ansible
pip install ansible

# Verify
ansible --version
```

### On Managed Nodes (cPanel/WHM Servers)

- BackBork installed (`./install.sh`)
- SSH access with root or sudo privileges
- PHP CLI available (standard on cPanel)

---

## 🖥️ PHP CLI API Access

BackBork can be invoked directly via PHP CLI, bypassing WHM's web authentication:

```bash
# Basic syntax
php /usr/local/cpanel/whostmgr/docroot/cgi/backbork/api/router.php \
    --action=<action> \
    [--data='<json>']
```

### Example: Get Current Configuration

```bash
php /usr/local/cpanel/whostmgr/docroot/cgi/backbork/api/router.php \
    --action=get_config
```

### Example: Save Configuration

```bash
php /usr/local/cpanel/whostmgr/docroot/cgi/backbork/api/router.php \
    --action=save_config \
    --data='{"notify_email":"admin@example.com","notify_backup_failure":true}'
```

### Example: Create Schedule

```bash
php /usr/local/cpanel/whostmgr/docroot/cgi/backbork/api/router.php \
    --action=create_schedule \
    --data='{"name":"Daily Backups","accounts":[],"all_accounts":true,"destination":"SFTP_Server","frequency":"daily","hour":2,"minute":0,"retention":30}'
```

---

## 📁 Ansible Inventory

### `inventory/hosts.yml`

```yaml
all:
  children:
    cpanel_servers:
      hosts:
        whm1.example.com:
          ansible_host: 192.168.1.10
          ansible_user: root
        whm2.example.com:
          ansible_host: 192.168.1.11
          ansible_user: root
        whm3.example.com:
          ansible_host: 192.168.1.12
          ansible_user: root
      vars:
        ansible_python_interpreter: /usr/bin/python3
        backbork_api_path: /usr/local/cpanel/whostmgr/docroot/cgi/backbork/api/router.php
```

---

## 📦 Complete Example: SFTP Destination with Daily & Monthly Schedules

This example configures:
- ✅ SFTP destination for offsite backups
- ✅ Daily schedule with 30-day retention
- ✅ Monthly schedule with 4-month retention  
- ✅ Email notifications for root
- ✅ Schedules locked for resellers

### `playbooks/backbork_configure.yml`

```yaml
---
# BackBork KISS - Full Configuration Playbook
# Configures SFTP backups with daily (30x) and monthly (4x) retention
#
# Usage:
#   ansible-playbook -i inventory/hosts.yml playbooks/backbork_configure.yml
#
- name: Configure BackBork Backup Solution
  hosts: cpanel_servers
  become: yes
  gather_facts: no

  vars:
    # SFTP Destination (configured in WHM Backup Configuration)
    sftp_destination_id: "SFTP_OffsiteBackup"
    
    # Notification Settings
    admin_email: "backups@example.com"
    slack_webhook: ""  # Optional: https://hooks.slack.com/services/...
    
    # Retention Policies
    daily_retention: 30    # Keep 30 daily backups
    monthly_retention: 4   # Keep 4 monthly backups
    
    # Schedule Times (24-hour format, server timezone)
    daily_backup_hour: 2
    daily_backup_minute: 0
    monthly_backup_hour: 3
    monthly_backup_minute: 0
    monthly_backup_day: 1  # 1st of month

  tasks:
    # =========================================================================
    # STEP 1: Verify BackBork Installation
    # =========================================================================
    - name: Check BackBork is installed
      stat:
        path: "{{ backbork_api_path }}"
      register: backbork_check
      failed_when: not backbork_check.stat.exists

    - name: Get BackBork version
      command: cat /usr/local/cpanel/whostmgr/docroot/cgi/backbork/version.php
      register: version_output
      changed_when: false

    - name: Display BackBork version
      debug:
        msg: "BackBork installed: {{ version_output.stdout | regex_search(\"'([0-9.]+)'\", '\\1') | first }}"

    # =========================================================================
    # STEP 2: Configure Global Settings (Root Only)
    # =========================================================================
    - name: Lock schedules for resellers
      command: >
        php {{ backbork_api_path }}
        --action=save_global_config
        --data='{"schedules_locked": true, "debug_mode": false}'
      register: global_config_result
      changed_when: "'saved' in global_config_result.stdout"

    - name: Verify global settings applied
      command: >
        php {{ backbork_api_path }} --action=get_global_config
      register: global_check
      changed_when: false
      failed_when: "'schedules_locked' not in global_check.stdout"

    # =========================================================================
    # STEP 3: Configure Root User Settings & Notifications
    # =========================================================================
    - name: Configure root user settings with notifications
      command: >
        php {{ backbork_api_path }}
        --action=save_config
        --data='{
          "notify_email": "{{ admin_email }}",
          "slack_webhook": "{{ slack_webhook }}",
          "notify_backup_success": true,
          "notify_backup_failure": true,
          "notify_backup_start": false,
          "notify_restore_success": true,
          "notify_restore_failure": true,
          "notify_restore_start": false,
          "notify_daily_summary": true,
          "temp_directory": "/home/backbork_tmp",
          "compression_option": "compress",
          "dbbackup_type": "all",
          "db_backup_method": "pkgacct",
          "skip_logs": true,
          "skip_bwdata": true
        }'
      register: config_result
      changed_when: "'saved' in config_result.stdout"

    # =========================================================================
    # STEP 4: Validate SFTP Destination
    # =========================================================================
    - name: Validate SFTP destination exists and is accessible
      command: >
        php {{ backbork_api_path }}
        --action=validate_destination
        --data='{"destination": "{{ sftp_destination_id }}"}'
      register: dest_validation
      failed_when: "'success' not in dest_validation.stdout or 'false' in dest_validation.stdout"
      changed_when: false

    - name: Display destination validation result
      debug:
        msg: "SFTP destination '{{ sftp_destination_id }}' validated successfully"
      when: "'success' in dest_validation.stdout"

    # =========================================================================
    # STEP 5: Create Daily Backup Schedule (All Accounts, 30-day retention)
    # =========================================================================
    - name: Check if daily schedule exists
      command: >
        php {{ backbork_api_path }} --action=get_schedules
      register: existing_schedules
      changed_when: false

    - name: Create daily backup schedule
      command: >
        php {{ backbork_api_path }}
        --action=create_schedule
        --data='{
          "name": "Daily All Accounts",
          "accounts": [],
          "all_accounts": true,
          "destination": "{{ sftp_destination_id }}",
          "frequency": "daily",
          "hour": {{ daily_backup_hour }},
          "minute": {{ daily_backup_minute }},
          "retention": {{ daily_retention }},
          "enabled": true
        }'
      register: daily_schedule_result
      when: "'Daily All Accounts' not in existing_schedules.stdout"
      changed_when: "'created' in daily_schedule_result.stdout"

    # =========================================================================
    # STEP 6: Create Monthly Backup Schedule (All Accounts, 4-month retention)
    # =========================================================================
    - name: Create monthly backup schedule
      command: >
        php {{ backbork_api_path }}
        --action=create_schedule
        --data='{
          "name": "Monthly All Accounts",
          "accounts": [],
          "all_accounts": true,
          "destination": "{{ sftp_destination_id }}",
          "frequency": "monthly",
          "day_of_month": {{ monthly_backup_day }},
          "hour": {{ monthly_backup_hour }},
          "minute": {{ monthly_backup_minute }},
          "retention": {{ monthly_retention }},
          "enabled": true
        }'
      register: monthly_schedule_result
      when: "'Monthly All Accounts' not in existing_schedules.stdout"
      changed_when: "'created' in monthly_schedule_result.stdout"

    # =========================================================================
    # STEP 7: Verify Final Configuration
    # =========================================================================
    - name: Get final schedule list
      command: >
        php {{ backbork_api_path }} --action=get_schedules
      register: final_schedules
      changed_when: false

    - name: Display configured schedules
      debug:
        msg: "{{ final_schedules.stdout | from_json | json_query('schedules[*].{name: name, frequency: frequency, retention: retention, destination: destination}') }}"
      when: final_schedules.stdout | length > 0

    - name: Configuration complete
      debug:
        msg: |
          ✅ BackBork configuration complete!
          
          Summary:
          - Schedules locked for resellers: YES
          - Notifications enabled: {{ admin_email }}
          - Daily backups: {{ daily_backup_hour }}:{{ '%02d' | format(daily_backup_minute) }} ({{ daily_retention }}x retention)
          - Monthly backups: Day {{ monthly_backup_day }} @ {{ monthly_backup_hour }}:{{ '%02d' | format(monthly_backup_minute) }} ({{ monthly_retention }}x retention)
          - Destination: {{ sftp_destination_id }}
```

---

## 🎯 Individual Task Examples

### Lock Schedules for Resellers

```yaml
- name: Lock reseller schedule access
  command: >
    php {{ backbork_api_path }}
    --action=save_global_config
    --data='{"schedules_locked": true}'
```

### Enable Debug Mode

```yaml
- name: Enable debug mode for troubleshooting
  command: >
    php {{ backbork_api_path }}
    --action=save_global_config
    --data='{"debug_mode": true}'
```

### Configure Email Notifications Only

```yaml
- name: Set notification email
  command: >
    php {{ backbork_api_path }}
    --action=save_config
    --data='{
      "notify_email": "alerts@example.com",
      "notify_backup_failure": true,
      "notify_restore_failure": true
    }'
```

### Create Specific Account Schedule

```yaml
- name: Create schedule for specific accounts
  command: >
    php {{ backbork_api_path }}
    --action=create_schedule
    --data='{
      "name": "VIP Accounts Hourly",
      "accounts": ["vip1", "vip2", "vip3"],
      "all_accounts": false,
      "destination": "SFTP_Premium",
      "frequency": "hourly",
      "retention": 48,
      "enabled": true
    }'
```

### Delete a Schedule

```yaml
- name: Remove old schedule
  command: >
    php {{ backbork_api_path }}
    --action=delete_schedule
    --data='{"job_id": "sched_abc123"}'
```

### Trigger Immediate Backup

```yaml
- name: Run immediate backup for critical account
  command: >
    php {{ backbork_api_path }}
    --action=create_backup
    --data='{
      "accounts": ["critical_account"],
      "destination": "SFTP_OffsiteBackup"
    }'
```

---

## 📊 Ansible Roles Structure

For larger deployments, consider organizing as a role:

```
roles/
└── backbork/
    ├── defaults/
    │   └── main.yml          # Default variables
    ├── tasks/
    │   ├── main.yml          # Main task list
    │   ├── install.yml       # Installation tasks
    │   ├── configure.yml     # Configuration tasks
    │   └── schedules.yml     # Schedule management
    ├── templates/
    │   └── schedule.json.j2  # Schedule template
    └── handlers/
        └── main.yml          # Handlers (if needed)
```

### `roles/backbork/defaults/main.yml`

```yaml
---
backbork_api_path: /usr/local/cpanel/whostmgr/docroot/cgi/backbork/api/router.php

# Global settings
backbork_schedules_locked: true
backbork_debug_mode: false

# Notifications
backbork_notify_email: ""
backbork_slack_webhook: ""
backbork_notify_backup_success: true
backbork_notify_backup_failure: true
backbork_notify_restore_failure: true

# Default schedules
backbork_schedules:
  - name: "Daily All Accounts"
    all_accounts: true
    destination: "local"
    frequency: "daily"
    hour: 2
    minute: 0
    retention: 30
    enabled: true
```

---

## 🔒 Security Considerations

1. **SSH Key Authentication**: Use SSH keys, not passwords
2. **Ansible Vault**: Encrypt sensitive variables (webhooks, etc.)
3. **Least Privilege**: Run only necessary tasks
4. **Audit Logging**: BackBork logs all API operations to `/usr/local/cpanel/3rdparty/backbork/logs/operations.log`

### Using Ansible Vault for Secrets

```bash
# Create encrypted vars file
ansible-vault create vars/secrets.yml
```

```yaml
# vars/secrets.yml (encrypted)
slack_webhook: "https://hooks.slack.com/services/REAL/WEBHOOK/URL"
admin_email: "real-admin@example.com"
```

```yaml
# In playbook
- name: Configure BackBork
  hosts: cpanel_servers
  vars_files:
    - vars/secrets.yml
  tasks:
    # ... tasks using {{ slack_webhook }}
```

---

## 🧪 Testing Your Playbook

### Dry Run (Check Mode)

```bash
ansible-playbook -i inventory/hosts.yml playbooks/backbork_configure.yml --check
```

### Single Host Test

```bash
ansible-playbook -i inventory/hosts.yml playbooks/backbork_configure.yml --limit whm1.example.com
```

### Verbose Output

```bash
ansible-playbook -i inventory/hosts.yml playbooks/backbork_configure.yml -vvv
```

---

## 📚 API Reference

For complete API documentation, see [API.md](API.md).

### Key Endpoints for Automation

| Action | Description |
|--------|-------------|
| `get_config` | Get current user configuration |
| `save_config` | Save user configuration |
| `get_global_config` | Get global settings (root only) |
| `save_global_config` | Save global settings (root only) |
| `get_schedules` | List all schedules |
| `create_schedule` | Create new schedule |
| `delete_schedule` | Delete schedule |
| `create_backup` | Trigger immediate backup |
| `validate_destination` | Test destination connectivity |
| `get_destinations` | List available destinations |

---

## 🆘 Troubleshooting

### Command Not Found

```bash
# Ensure PHP is in PATH
which php
# Should return: /usr/local/cpanel/3rdparty/bin/php or /usr/bin/php
```

### Permission Denied

```bash
# API must run as root for full access
sudo php /usr/local/cpanel/whostmgr/docroot/cgi/backbork/api/router.php --action=get_config
```

### JSON Parse Errors

```bash
# Validate JSON before sending
echo '{"test": true}' | python -m json.tool
```

### Debug API Calls

```bash
# Enable debug mode first
php /path/to/router.php --action=save_global_config --data='{"debug_mode": true}'

# Then check logs
tail -f /usr/local/cpanel/logs/error_log | grep -i backbork
```

---

*Documentation for BackBork KISS v1.2.9+*
