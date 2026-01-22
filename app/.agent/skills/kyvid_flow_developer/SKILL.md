---
name: Kyvid Flow Developer Context
description: Comprehensive knowledge base and developer guide for the Kyvid Flow application. Use this skill to understand the project architecture, logic flows, and key files without needing to re-scan the codebase.
---

# Kyvid Flow - Project Context & Developer Guide

## 1. Project Overview
**Kyvid Flow** is a Self-Hosted Intelligent Data Orchestration Platform. It goes beyond simple visualization by allowing data to trigger real-world actions. "Where your data takes action".

- **Philosophy**: Low-Code, Self-Hosted, Privacy-First.
- **Key Use Case**: Connecting databases, generating SQL reports via AI, visualizing them, and automating follow-up actions (email, API calls) based on the results.

## 2. Technical Architecture

### Tech Stack
- **Backend API**: Pure PHP 8.2 (No framework, bare metal performance).
  - Entry points: `api/index.php` (Reports/General), `api/scenarios.php` (Dashboards), `api/auth.php` (Security).
  - Database: MySQL 8 (primary) + Connections to external PostgreSQL/MySQL dbs.
- **Frontend**: Hybrid SPA.
  - Structure: `index.php` (Main layout).
  - Styles: `Bootstrap 5` + Custom CSS overrides.
  - Logic: `assets/js/app.js` (Core), `scenarios.js` (Dashboarding), `tables.js` (CRUD), `connections.js` (Integrations).
  - Visualization: `Chart.js`, `PivotTable.js`, `Gridstack.js`, `DataTables`.
- **Infrastructure**: Dockerized (Docker Compose + Caddy Proxy).

### Directory Map
- `/` -> Root `index.php` (The UI).
- `/api` -> All backend logic.
    - `index.php`: Report execution `action=execute`, saving, processing.
    - `scenarios.php`: Dashboard logic.
    - `robot.php`: Automation runner (cron).
    - `auth.php`: Session & Admin handling.
- `/config` -> Database connection (`db.php`).
- `/src` -> Backend classes (`Security.php`, `ReportFilterBuilder.php`, `ActionExecutor.php`).
- `/assets` -> JS/CSS.
- `schema.sql` -> Database definition.

## 3. Key Data Structures (Database)

### `reports` Table
The core entity. Stores the definition of a data report.
- `sql_query`: The raw SQL to fetch data.
- `php_script`: Pre-processing PHP script (run before JSON output).
- `post_action_code` / `post_action_params`: The action to trigger (e.g., 'UTIL_EMAIL_REPORT').
- `phpscript2`: Legacy post-action script (deprecated but supported).
- `is_automatic` / `cron_interval_minutes`: Automation settings.

### `scenarios` & `scenario_widgets`
Dashboards are called "Scenarios".
- `scenarios`: Container (Name, Category).
- `scenario_widgets`: Items on the canvas. Links a `report_id` to a `scenario_id` with a `grid_layout`.

### `custom_actions`
FaaS (Function as a Service) table.
- Stores isolated PHP logic that can be invoked by the Action Engine.

### `shared_reports` & `shared_scenarios`
Stores public tokens for sharing specific filtered views.
- `token`: Unique access key.
- `filters_json`: Frozen state of filters at share time.

## 4. Workflows & Logic

### Report Execution Flow
1. **Frontend**: Calls `executeReport()` in `app.js`.
2. **API**: `api/index.php?action=execute` receives `report_id` + `filters`.
3. **Builder**: `ReportFilterBuilder.php` injects filters into `WHERE` clauses of the SQL.
4. **Query**: Executes SQL against Local or External DB (`PDO`).
5. **Post-Process**: Runs `php_script` (if any) to transform data arrays.
6. **Return**: JSON response for DataTables/Charts.

### Automation Flow (The Robot)
1. **Client-Side**: `app.js` has a "Master Tab" election logic using `localStorage`. Only one active tab acts as the robot.
2. **Heartbeat**: Every minute, the master tab calls `api/robot.php`.
3. **Server-Side**: `robot.php` checks `reports` where `is_automatic=1` and `last_execution` > interval.
4. **Action**: Executes the report internally and triggers the `post_action_code` (e.g., Email).

### Shared Links Flow
1. **Save**: `share_save` creates a record with a random token and the current JSON filters.
2. **Load**: `?token=XYZ` or `?stoken=XYZ` in URL triggers a special load mode in `index.php`.
   - Hides navigation.
   - Loads the specific report/scenario definition associated with the token.
   - Locks filters to the saved state.

## 5. Development Guidelines (Rules)

- **Admin Mode**: Critical actions (save/delete/configuration) require `$_SESSION['isAdmin']`.
- **Security Codes**: 'Murcielago' cipher used for visual verification codes in PDFs.
- **Responsive Design**: Always ensure layouts work on mobile (100% width widgets, scrollable tabs).
- **No Frameworks**: Do not introduce Composer dependencies or npm builds unless strictly necessary. Keep it simple and portable.
- **Context Preservation**: When modifying `js` files, remember they are large. Use `view_file` to locate functions before replacing.

### Critical Tech Constraints
1. **Secrets Management**: Sensitive credentials (passwords, tokens) MUST be encrypted using `Security::encrypt($val)` before INSERT/UPDATE. Never store plain text secrets in the DB.
2. **UI Consistency**: Always use `SweetAlert2` (`Swal.fire`) for user notifications. Do NOT use native `alert()` or `confirm()`. Use CSS variables (e.g., `var(--primary-color)`) for styling to maintain theming.
3. **Cross-DB Logic**: Cross-Database SQL `JOIN`s are NOT supported natively. Rely on the `resolveVirtualViews` PHP logic logic (Recursive CTEs within PHP) or `phpscript2` post-processing to merge data from different sources.

## 6. Common Tasks (Quick Reference)

### Adding a new "Skill" or Feature
1. Define the UI in `index.php` (hidden container by default).
2. Add the JS controller in `assets/js/`.
3. Add the backend handler in `api/`.

### Debugging Actions
1. Check `custom_actions` table for logic.
2. Use `connections.js` to test external services (SMTP/Telegram).
3. Logs are currently displayed in the Browser Console or returned as JSON errors.

## 7. AI Persona
You are **Antigravity**, a Google Deepmind Agent. You follow the "Kyvid Flow" philosophy: Powerful, Autonomous, and Direct.
