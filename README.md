# EARS — E-Attendance Reporting System

Web interface for internship attendance and weekly activity reporting at Kampala Capital City Authority (KCCA).

## Current release status

The repository now includes server-backed intern sign-in, clock-in/clock-out, and weekly report submission, plus limited administrator account creation/deactivation and account activity auditing. It remains an incomplete application and is not approved for official attendance records: deployment, role portals, approvals, and operational controls still need review and completion.

The Node service serves static frontend files only. It does not execute PHP and returns an explicit error for API requests. Deploy the PHP API separately behind a PHP-capable web server and route it through the same trusted origin before enabling the interface for real users.

## Requirements

- Node.js 20 or newer
- PHP 8.1 or newer with PDO MySQL
- Composer 2
- MySQL 8 or a compatible managed MySQL service

## Run the static interface

```sh
npm run build
npm start
```

The server listens on `http://localhost:3000` by default. Set `HOST` and `PORT` in the runtime environment to change the bind address and port. `npm start` creates a fresh `dist/` build before starting the static server.

## PHP API configuration

Install PHP dependencies from the repository root:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
```

Set the database values and a unique `JWT_SECRET` in the PHP runtime's secret manager. See [.env.example](.env.example) for variable names. `JWT_SECRET` must contain at least 32 characters. Do not commit actual credentials or copy `.env.example` unchanged into production.

For a new empty database, run `backend/database/init.sql` and provision the first administrator from a trusted shell. Set `BOOTSTRAP_ADMIN_NAME`, `BOOTSTRAP_ADMIN_EMAIL`, and `BOOTSTRAP_ADMIN_PASSWORD` only for that one-time command, then clear the password from the shell environment:

```sh
php backend/scripts/create-initial-admin.php
unset BOOTSTRAP_ADMIN_PASSWORD
```

The bootstrap script refuses to create an administrator if an active administrator already exists and cannot be invoked over HTTP.

For an existing installation, do not rerun `init.sql`. Review and apply the versioned SQL files in `backend/database/migrations/` through the database change process; migration `20261121_001_login_attempts.sql` adds the hashed login-attempt store required by sign-in throttling. Take a verified backup first and record applied migration versions.

The static Node server deliberately does not expose files from `backend/`. Run the API behind a PHP-capable server, keep configuration and dependency files outside its document root, and configure the reverse proxy so `/backend/api/` and `/backend/auth/` reach PHP while frontend paths reach the Node service. Keep both on the same HTTPS origin unless a specific, allowlisted cross-origin setup is implemented. Login uses a short-lived `HttpOnly`, `SameSite=Strict` cookie and a CSRF token for state-changing API requests. Keep `COOKIE_SECURE=true` in production; set it to `false` only for local HTTP development. Set `UPLOAD_DIR` to a private directory outside every web document root; uploaded attachments do not yet have a complete authorized download flow.

The admin portal now reads user accounts and audit events from the API, and can create accounts and deactivate accounts. New account passwords must have at least 12 characters. Account activity is recorded server-side; audit events cannot be cleared from the portal. Other administrator panels, including dashboard metrics, organizational edits, supervisor assignment, and system settings, remain demonstrations. A limited supervisor/HR review screen now uses role-scoped queues and guarded status transitions. Interns can edit the weekday activity descriptions and resubmit a rejected report; its original attendance times and weekly summary are retained.

Sign-in throttling counts failed attempts by normalized email and observed client IP over a rolling 15-minute window. The API hashes both values before storing them. If deploying behind a reverse proxy, configure the PHP server to expose the actual client IP safely; do not trust arbitrary client-supplied forwarding headers.

## Production release gates

The following work is still required before this application can safely handle official attendance data:

1. Complete supervisor and HR workflows beyond report review. Intern, admin, supervisor, and HR login routes are available; admin account creation/deactivation and supervisor/HR report review now use the API. The rest of the admin portal remains a demonstration; do not use it to manage organizational data.
2. Independently review role-based authorization and report transitions; add session invalidation/revocation and account recovery. Account and review events are recorded, but a full audit policy and retention controls are not.
3. Use `backend/database/init.sql` only for a new empty database, then add versioned migrations and review the schema against the actual KCCA data model.
4. Complete request validation and rate limits for all relevant endpoints, including uploads; add an authorized attachment download flow and review every endpoint. Attendance records use server timestamps but do not verify physical work location.
5. Remove or implement remaining demonstration-only profile, analytics, notification, and policy UI. Configure approved work-hour rules and attendance controls with KCCA instead of assuming them in the interface.
6. Add automated checks and deployment health monitoring; configure HTTPS, backups, restore drills, retention, and access controls with KCCA.

## Repository layout

- `ears-system/frontend/` — current client and admin portal interface
- `frontend/` — archived role-specific pages; excluded from the production static build
- `backend/` — PHP API, authentication, database configuration, and models
- `backend/database/init.sql` — initial schema for a new installation; not a migration for existing data
- `backend/database/migrations/` — reviewed, versioned schema updates for existing installations
- `server.js` — static web server; PHP is intentionally not served here
