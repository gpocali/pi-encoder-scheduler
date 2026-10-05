# pi-encoder-scheduler

A PHP/MySQL-based graphic scheduler for radio station automation. Designed for WRHU Radio at Hofstra University, this system manages the scheduling and playback of graphics/encoder assets across multiple output channels (tags).

## Features

### Core Scheduling
- **Event Management**: Create, edit, and delete scheduled events with start/end times
- **Asset Association**: Link graphics files to events for playback
- **Tag-based Routing**: Events are routed to specific output channels (tags)
- **Priority System**: Higher priority events preempt lower priority ones
- **Schedule Conflict Resolution**: Automatic handling of overlapping events based on priority

### User Management
- **Role-based Access Control**:
  - `admin` - Full access to all features
  - `user` - Can create/edit events for all tags
  - `tag_editor` - Restricted to specific assigned tags
- **User CRUD**: Create, edit, delete users with role assignment
- **2FA Support**: TOTP-based two-factor authentication
- **Password Management**: Admin can reset user passwords

### Tag Management
- **CRUD Operations**: Create, update, and delete output tags
- **Storage Limits**: Configure per-tag storage limits
- **Default Assets**: Set fallback graphics for each tag when no event is active

### Import/Export (New)
- **Event Export**: Export events within a date range as JSON
- **Bulk Import**: Import events from JSON with upsert logic
  - Events with existing ID > 0 are updated
  - Events with null/missing/0 ID are created new
- **Tag Preservation**: Tag associations maintained during import

### Asset Management
- **Upload**: Upload graphic files with tag association
- **Preview**: View assets before assignment
- **File Storage**: Files stored with unique names and MD5 hashing

## Architecture

### Technology Stack
- **Backend**: PHP 8.x
- **Database**: MySQL/MariaDB with PDO
- **Web Server**: Nginx
- **Authentication**: Session-based with optional TOTP 2FA

### Database Schema
```
users           - User accounts with roles and 2FA secrets
tags            - Output channel definitions
assets          - Uploaded graphic files metadata
events          - One-off scheduled events
event_tags      - Many-to-many: events ↔ tags
asset_tags      - Many-to-many: assets ↔ tags
user_tags       - Many-to-many: users ↔ tags (for tag_editor role)
default_assets  - Fallback asset per tag
```

### Key Components
- `ScheduleLogic.php` - Priority-based schedule conflict resolution
- `EventRepository.php` (Event-Refactor) - Repository pattern for event data access
- `api_import_export.php` - REST API for event import/export
- `poller.php` - Background process for schedule enforcement

## Installation

### Requirements
- PHP 8.x with PDO MySQL extension
- MySQL/MariaDB database
- Nginx web server
- SSL/TLS certificate (recommended)

### Setup
1. Clone the repository
2.Configure database connection in `/etc/scheduler/db_info.conf`:
   ```conf
   username=your_db_user
   password=your_db_password
   ```
3. Run the database initialization:
   ```bash
   mysql -u root -p < initialize.sql
   ```
4. Run migration if needed:
   ```bash
   php root/admin/migrate_db.php
   ```
5. Create admin user:
   ```bash
   php root/create_admin.php
   ```
6. Configure Nginx using `nginx_default.conf`

## Event-Refactor Branch Analysis

The `Event-Refactor` branch contains experimental features that were being developed but never merged to main. Below is an analysis of the intent and value of each feature.

### ✅ Recommended for Integration

#### 1. Recurring Events System
**Commit**: `2add4b2` - Event Refactor

**Intent**: Add support for recurring events (daily/weekly) with exception handling.

**Value**: HIGH - This is a fundamental feature gap. Radio stations need recurring shows.

**Implementation**:
- New tables: `recurring_events`, `recurring_event_tags`
- `EventRepository.php` with expansion logic
- Exception handling for individual event overrides
- Series-based editing (update all instances)

**Concerns**:
- Schema changes via `migrate_db.php` rather than `initialize.sql`
- Complex timezone handling (local time for recurrence, UTC for storage)
- May need simplification - the expansion logic iterates day-by-day

---

#### 2. EventRepository Pattern
**Commit**: `2add4b2` - Event Refactor

**Intent**: Introduce repository pattern for data access abstraction.

**Value**: MEDIUM - Better code organization, but adds complexity.

**Implementation**:
- Centralized data access in `includes/EventRepository.php`
- Methods: `getEvents()`, `getCurrentEvent()`, `getRecurringSeries()`, `getFutureEvents()`

**Concerns**:
- Tightly coupled to specific schema
- May be over-engineering for a single-application use case

---

#### 3. Combined Create/Edit Event Pages
**Commit**: `6931ccb` - Combined Create and Edit Event pages

**Intent**: Consolidate event creation and editing into a single page.

**Value**: MEDIUM - Reduces code duplication, simpler maintenance.

**Concerns**:
- May have introduced complexity in the unified form logic
- Should verify the combined page handles both create and edit cleanly

---

#### 4. Chart.js for Statistics
**Commit**: `cb23f27` - Changed graphs to chart.js

**Intent**: Modernize statistics visualization.

**Value**: LOW-MEDIUM - Visual improvement only.

**Concerns**:
- Adds external dependency (CDN)
- Verify the chart implementation works correctly

---

#### 5. Superadmin Role
**Commit**: `10b4f46` - Added superadmin role to prevent account lockout

**Intent**: Add a higher-privilege role that cannot be locked out.

**Value**: LOW - Security through obscurity; admin should be able to manage all users.

**Concerns**:
- Adds role complexity without clear benefit
- The "prevent account lockout" rationale suggests a UX issue rather than needing a new role

---

### ⚠️ Needs Review Before Integration

#### 6. Gap Filler for Unscheduled Time
**Commit**: `5d31280` - Added gap filler for unscheduled time

**Intent**: Handle gaps in schedule when no event is active.

**Concerns**:
- Need to understand what "gap filler" actually does
- May conflict with default asset functionality

---

#### 7. Cleanup Events Script
**Commit**: `a8f1491` - Create cleanup_events.php

**Intent**: Maintenance utility for cleaning up old/expired events.

**Value**: MEDIUM - Useful utility if it works correctly.

**Concerns**:
- Need to review what cleanup operations it performs
- Should be run via cron, needs documentation

---

### ❌ Low Value / Potential Issues

#### 8. Priority Color Coding (Reverted then Re-added)
**Commits**: Multiple revisions

**Concerns**:
- Went through multiple iterations with reverts
- Suggests the implementation was problematic
- CSS-based priority indication may be simpler

---

## Current State vs Event-Refactor

| Feature | Main | Event-Refactor | Notes |
|---------|------|----------------|-------|
| One-off Events | ✅ | ✅ | Main has working implementation |
| Recurring Events | ❌ | ✅ | Event-Refactor has full implementation |
| Event Repository | ❌ | ✅ | Abstraction layer |
| Import/Export | ✅ (new) | ❓ | Need to check if Event-Refactor has this |
| Bulk JSON Import | ✅ (new) | ❓ | Need to check |
| Multi-tag Events | ✅ | ✅ | Both support via event_tags |
| Tag Editor Role | ✅ | ✅ | Both have user_tags |
| 2FA | ✅ | ✅ | Both have TOTP |
| Chart.js Stats | ❌ | ✅ | Event-Refactor has it |
| Superadmin | ❌ | ✅ | Event-Refactor has it |

## Usage

### Creating an Event
1. Log in as admin or user
2. Navigate to "Create Event"
3. Enter event name, select start/end times
4. Choose asset and tags
5. Set priority (higher = takes precedence)
6. Submit

### Importing Events
1. Navigate to "Import / Export" (admin only)
2. Export: Select date range, click Export, download JSON
3. Import: Paste JSON or upload file, click Import
4. Review results for created/updated counts and errors

### JSON Import Format
```json
[
  {
    "event_name": "Morning Show",
    "start_time": "2025-06-01 09:00:00",
    "end_time": "2025-06-01 10:00:00",
    "asset_id": 5,
    "tag_ids": [1, 2],
    "priority": 1
  },
  {
    "id": 12,
    "event_name": "Updated Show",
    "start_time": "2025-06-01 09:00:00",
    "end_time": "2025-06-01 10:00:00",
    "asset_id": 5,
    "tag_ids": [1],
    "priority": 2
  }
]
```

## License

GPL-3.0

## Author

Gregory Pocali
WRHU Radio, Hofstra University

---

*Built with assistance from Google Gemini 3*
