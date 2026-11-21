# EARS — E-Attendance Reporting System

Web interface for internship attendance and weekly activity reporting at Kampala Capital City Authority (KCCA).

## Current release status

The repository contains a demonstration interface and an incomplete PHP API. The interface currently uses sample records and browser storage; it is not connected to a production attendance database. Do not use it to collect, approve, or store official staff records until the release gates below are complete.

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

The static Node server deliberately does not expose files from `backend/`. Run the API behind a PHP-capable server, keep configuration and dependency files outside its document root, and configure the reverse proxy so `/backend/api/` and `/backend/auth/` reach PHP while frontend paths reach the Node service. Keep both on the same HTTPS origin unless a specific, allowlisted cross-origin setup is implemented. Set `UPLOAD_DIR` to a private directory outside every web document root; uploaded attachments do not yet have a complete authorized download flow.

## Production release gates

The following work is still required before this application can safely handle official attendance data:

1. Replace the sample data and browser-storage flows with authenticated API calls. The current interface must remain in demonstration use until then.
2. Complete and review authentication, role-based authorization, report approval transitions, session expiry, account recovery, and audit logging. The existing PHP endpoint set is not a complete, coherent API yet.
3. Use the current `backend/database/init.sql` only for a new empty database, then add versioned migrations and review them against the actual KCCA data model.
4. Complete request validation, CSRF protections if cookie authentication is introduced, rate limits for login and uploads, an authorized attachment download flow, and a security review of every endpoint. Authorization coverage and report status-transition rules still need a complete review.
5. Add automated checks and deployment health monitoring; configure HTTPS, backups, restore drills, retention, and access controls with KCCA.
6. Review the sample names, dates, and other illustrative records in the UI and replace them with neutral placeholders before public release.

## Repository layout

- `ears-system/frontend/` — current client and admin portal interface
- `frontend/` — earlier role-specific pages
- `backend/` — PHP API, authentication, database configuration, and models
- `backend/database/init.sql` — initial schema for a new installation; not a migration for existing data
- `server.js` — static web server; PHP is intentionally not served here
