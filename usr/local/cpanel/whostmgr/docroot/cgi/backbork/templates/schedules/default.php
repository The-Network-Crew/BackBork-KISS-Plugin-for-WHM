<?php
/**
 *  BackBork KISS :: Open-source Disaster Recovery Plugin (for WHM)
 *   Copyright (C) The Network Crew Pty Ltd & Velocity Host Pty Ltd
 *   https://github.com/The-Network-Crew/BackBork-KISS-for-WHM/
 *
 *  THIS FILE:
 *   Default schedule configuration structure template.
 *   Defines the standard fields for recurring backup schedules.
 *
 *  This program is free software: you can redistribute it and/or modify
 *   it under the terms of the GNU Affero General Public License as published by
 *   the Free Software Foundation, either version 3 of the License, or
 *   (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *   but WITHOUT ANY WARRANTY; without even the implied warranty of
 *   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *   GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *   along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 *  @package BackBork
 *  @version See version.php (constant: BACKBORK_VERSION)
 *  @author The Network Crew Pty Ltd & Velocity Host Pty Ltd
 */

return [
    // Schedule metadata
    'id' => '',
    'name' => '',
    'enabled' => true,
    'created_at' => '',
    'created_by' => '',
    
    // Schedule timing
    'frequency' => 'daily', // hourly, daily, weekly, monthly
    'hour' => 2, // 0-23 (preferred time for daily/weekly/monthly)
    'day_of_week' => 0, // 0-6 (0=Sunday, 1=Monday, ..., 6=Saturday) for weekly schedules
    'day_of_month' => 1, // 1-31 for monthly (always 1st)
    
    // Backup configuration
    'accounts' => [], // Array of account usernames
    'destination' => 'local', // Destination ID
    'retention' => 30, // Days to keep backups
    
    // Notification settings
    'notify_on_success' => true,
    'notify_on_failure' => true,
    
    // Last run info
    'last_run' => null,
    'last_status' => null,
    'next_run' => null,
];
