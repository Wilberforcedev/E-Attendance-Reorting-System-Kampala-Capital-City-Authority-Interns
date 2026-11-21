# EARS — E-Attendance Reporting System

Web interface for internship attendance and weekly activity reporting at Kampala Capital City Authority (KCCA).

## Current release status

The repository now includes a server-backed intern sign-in, clock-in/clock-out, and weekly report submission flow. It remains an incomplete application and is not approved for official staff records: deployment, role portals, administration, approvals, auditability, and operational controls still need review and completion.

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

The static Node server deliberately does not expose files from `backend/`. Run the API behind a PHP-capable server, keep configuration and dependency files outside its document root, and configure the reverse proxy so `/backend/api/` and `/backend/auth/` reach PHP while frontend paths reach the Node service. Keep both on the same HTTPS origin unless a specific, allowlisted cross-origin setup is implemented. Login uses a short-lived `HttpOnly`, `SameSite=Strict` cookie and a CSRF token for state-changing API requests. Keep `COOKIE_SECURE=true` in production; set it to `false` only for local HTTP development. Set `UPLOAD_DIR` to a private directory outside every web document root; uploaded attachments do not yet have a complete authorized download flow.

## Production release gates

The following work is still required before this application can safely handle official attendance data:

1. Finish all role portals. Login currently serves interns and admins; supervisor and HR access is deliberately unavailable. The administrator interface is still a demonstration and must not be used to manage real accounts or organizational data.
2. Complete and independently review role-based authorization, report approval transitions, session invalidation/expiry, account recovery, and audit logging. Admin, supervisor, and HR operations are not yet connected end to end.
3. Use `backend/database/init.sql` only for a new empty database, then add versioned migrations and review the schema against the actual KCCA data model.
4. Complete request validation, login and upload rate limits, an authorized attachment download flow, and a security review of every endpoint. Attendance records use server timestamps but do not verify physical work location.
5. Remove or implement remaining demonstration-only profile, analytics, notification, and policy UI. Configure approved work-hour rules and attendance controls with KCCA instead of assuming them in the interface.
6. Add automated checks and deployment health monitoring; configure HTTPS, backups, restore drills, retention, and access controls with KCCA.

## Repository layout

- `ears-system/frontend/` — current client and admin portal interface
- `frontend/` — archived role-specific pages; excluded from the production static build
- `backend/` — PHP API, authentication, database configuration, and models
- `backend/database/init.sql` — initial schema for a new installation; not a migration for existing data
- `server.js` — static web server; PHP is intentionally not served here
