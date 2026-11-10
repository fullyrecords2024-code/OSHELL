# Oshel Skin Care production SSH and deployment guide

## Status and safety boundaries

These scripts are fail-closed deployment tooling, not evidence that the specified host is configured or that a release has been deployed. The confirmed SSH account is `oshelski`; the target is `169.58.230.169:6622`, with expected home `/home/oshelski`.

The repository has two applications: a Laravel API in `backend/` and a Vite-built React/TanStack SPA in `frontend/`. The SPA defaults to `/api/v1`; the local Vite proxy is development-only. Laravel's `public/` is the PHP document root. This repository does not define the production domain, server vhost, actual document roots, database, upload paths, or existing release layout. Discover these on the host and obtain the hosting owner's approval before configuring the web server or deploying.

Never copy `backend/.env`, local databases, `backend/storage`, user uploads, credentials, or SSH private keys into a release/archive/repository. The deploy archive is assembled from an allowlist; production `.env` and the complete storage directory are external shared paths that the script only symlinks to. Existing uploads and environment values are not copied, overwritten, or deleted.

`deploy-production.ps1` never runs migrations, seeds, `migrate:fresh`, rollback migrations, or destructive SQL. It checks migration status and refuses to switch releases if any migrations are pending. A DBA must independently approve and perform a database backup and migration under the production change procedure before deployment is retried. Application rollback changes only the `current` symlink; it does not revert database state or uploads.

## Repository and hosting inspection already performed

- Laravel 13 / PHP `^8.3`; its runtime application entry point is `backend/public/index.php`.
- Frontend uses npm/Vite, TypeScript, React, and TanStack libraries. Its production output is `frontend/dist`; API URL is compiled via `VITE_API_BASE_URL`, and canonical site URL via `VITE_PUBLIC_SITE_URL`.
- Laravel's local `.env` is ignored and was inspected by variable names only; its values were not copied into these scripts or printed. It is not production configuration.
- Laravel's local `public/.htaccess` uses Apache `mod_rewrite`; this does not establish that the remote host uses Apache, permits `.htaccess`, supports symlinks, or points its document root at the expected path.
- Local Laravel storage distinguishes private files and public media; therefore the deploy process preserves the entire existing production storage directory.
- The downloaded key is a PuTTY PPK outside the repository. PuTTY, Pageant, Plink, and PSCP are installed from the official PuTTY package. Deployment uses the PPK directly through Pageant; no OpenSSH conversion or OpenSSH Agent is required.
- No authenticated SSH login, remote `.env` inspection, web-root/database permission inspection, backup, or deployment is claimed until `test-ssh.ps1` succeeds with an independently verified host fingerprint.

## One-time SSH setup

Run in an interactive Windows PowerShell session, not a CI log or a recorded command transcript:

```powershell
.\setup-ssh.ps1 -KeyPath "$env:USERPROFILE\Downloads\id_rsa.1793363358.ppk"
```

Use the downloaded PPK path verified on this workstation; the command above uses the newer key found in Downloads during setup. The script copies it to `%USERPROFILE%\.ssh\oshel-production\production-key.ppk` with a restricted ACL and starts Pageant with it. **Enter its passphrase only in Pageant's local prompt.** The passphrase is never a script parameter, environment variable, file, or log. Later runs may omit `-KeyPath` and reuse that protected copy.

Pageant reuses the unlocked key for PuTTY, Plink, and PSCP while Pageant remains running. After reboot or sign-out, run `.\setup-ssh.ps1` and unlock it locally. To stop caching the key, remove it from Pageant or exit Pageant.

Check that the intended key is loaded in the Pageant tray menu before continuing.

## Verify the server host key before the first login

Obtain the server's SHA256 host-key fingerprint from the hosting control panel or administrator over a trusted channel. Do not treat an unauthenticated network scan as the source of trust.

```powershell
.\test-ssh.ps1 -VerifiedHostKeyFingerprint 'SHA256:...'
```

Plink and PSCP receive the provider-verified SHA256 fingerprint via `-hostkey`; they refuse a different server key. The scripts do not accept or persist a host key obtained only from an unauthenticated scan.

The initial inventory reports the authenticated username/home, OS and available runtimes/tools, likely home web directories, Laravel `.env` file paths (paths only), and candidate app roots. To inspect an identified Laravel root, rerun with its path:

```powershell
.\test-ssh.ps1 -VerifiedHostKeyFingerprint 'SHA256:...' `
  -ApplicationPath '/home/oshelski/path/to/laravel' `
  -ExpectedEnvironmentPath '/home/oshelski/shared/oshel/.env'
```

That inspection prints `.env` variable names only, `php artisan migrate:status`, database driver/server identity, MySQL grants or PostgreSQL database/table privileges, and file permissions. It verifies the Laravel root resolves to the same shared `.env` path intended for deployment. Database statements are read-only; Artisan may initialize missing generated framework cache metadata. Authentication details in MySQL grant output are redacted. It never prints environment values or passwords. Hosting control-panel configuration and root-only web-server files may remain inaccessible; ask the hosting owner for the exact vhost document roots and PHP-FPM/runtime settings rather than guessing.

## Prepare safe release layout

Before deploying, obtain approval and verify on the remote account:

1. The public website URL and API URL, TLS certificates, and correct production domain.
2. The actual configured Laravel and SPA document roots, and that both are already symlinks to the stable paths below. The script will not replace an ordinary document-root directory or edit web-server configuration.
3. An application release root owned/writable by `oshelski`, outside all document roots.
4. An existing Laravel application root with its production `.env` and installed vendor dependencies. The deployment script requires `ExistingLaravelPath` so it can inspect live migration state and database grants before building/uploading a release.
5. The exact existing production `.env` file and storage directory. Keep both in place, outside web roots, readable/writable only as required by the PHP/web account. Do not substitute `backend/.env`.
6. PHP 8.3+, Composer, required PHP extensions, database driver, and outbound access needed for `composer install`.
7. A successful independent production backup/restore procedure and approved release/change window.

The deployment layout expected by the scripts is:

```text
/home/oshelski/<approved-app-root>/
  releases/<release-id>/
  current -> releases/<active-release-id>
  previous -> releases/<prior-release-id>

<Laravel document root> -> <approved-app-root>/current/backend/public
<SPA document root>     -> <approved-app-root>/current/frontend/dist
<shared .env>            (outside both document roots)
<shared storage>/        (existing persistent Laravel storage/uploads)
```

Configure the web server/provider to use those stable document-root symlinks before the first release. Ensure it follows symlinks and supports Laravel's PHP front controller/API routing and SPA fallback behavior. Do not point a domain at an unreviewed path or expose the Laravel project root, `.env`, or private storage. This repository's Apache `.htaccess` alone is not a server configuration.

If the host is shared hosting that cannot provide this layout, Composer/runtime access, two suitable document roots, or symlink support, stop. Do not work around the provider restrictions by overwriting `public_html`; ask the host for an approved deployment layout and adapt the scripts after reviewing it.

## Production preflight and deploy

First complete remote inspection and obtain values approved by the host/application owner. Do not use a local development URL or local `.env` values. Example only; replace every path and URL with verified values:

```powershell
.\deploy-production.ps1 `
  -VerifiedHostKeyFingerprint 'SHA256:...' `
  -ApplicationRoot '/home/oshelski/apps/oshel' `
  -LaravelDocumentRoot '/home/oshelski/public_html/api' `
  -FrontendDocumentRoot '/home/oshelski/public_html' `
  -SharedEnvironmentPath '/home/oshelski/shared/oshel/.env' `
  -SharedStoragePath '/home/oshelski/shared/oshel/storage' `
  -ExistingLaravelPath '/home/oshelski/apps/oshel/current/backend' `
  -ProductionUrl 'https://verified-public-domain.example/' `
  -ApiBaseUrl 'https://verified-api-domain.example/api/v1' `
  -Confirm 'DEPLOY PRODUCTION'
```

This command intentionally refuses unverified host keys, paths outside `/home/oshelski`, an uninspected Laravel root or mismatched shared `.env`, missing shared storage, non-symlink document roots, insufficient PHP/database/runtime tooling, failed tests/audits/build, missing production settings, pending migrations, failed HTTP health checks, and untyped confirmations. It requires acknowledgement after reviewing remote inspection output and a separate interactive confirmation before upload/switch.

The script runs the Laravel suite and Composer audit locally, builds the frontend with the supplied production URLs, creates an allowlisted archive excluding `.env`, `storage`, `vendor`, `node_modules`, tests, generated Laravel cache files and SQLite files, and rejects links or paths outside the backend/frontend roots before upload. It uses pinned Plink commands and PSCP through Pageant. It installs production Composer dependencies in a new release, checks `APP_ENV=production` and `APP_DEBUG=false`, runs read-only migration status, and switches the stable symlink atomically. It additionally verifies that application/shared paths resolve under the confirmed account home, the environment file is not world-accessible or inside a web root, persistent storage is outside web roots, and current release pointers remain inside the approved releases directory. The health endpoint is the Laravel root `/up` on the configured API host. It does not run npm, seeding, database writes, or migrations on production. If post-switch HTTP checks fail, it restores the prior application symlink; database/uploads remain untouched. Previous releases are retained; cleanup must be separately reviewed and approved.

**Backup before any migration:** because migrations are intentionally not run by this deployer, an external migration must be preceded by a verified, approved production database backup/restore point. Confirm its integrity and record it in the change record. Never use `migrate:fresh`, `migrate:rollback`, `db:wipe`, seeders, or destructive SQL in production. Review migration code and coordinate application compatibility, backup, migration, smoke checks, and rollback with the DBA and release owner.

## Application-only rollback

Rollback requires a valid SSH host pin, an existing `previous` release, successful `current`/`previous` path checks, and a typed confirmation:

```powershell
.\rollback-production.ps1 `
  -VerifiedHostKeyFingerprint 'SHA256:...' `
  -ApplicationRoot '/home/oshelski/apps/oshel' `
  -ProductionUrl 'https://verified-public-domain.example/' `
  -ApiBaseUrl 'https://verified-api-domain.example/api/v1' `
  -Confirm 'ROLLBACK APP ONLY'
```

The rollback switches application code only, then checks the website and Laravel `/up` endpoint. It never restores a database, removes a release, deletes uploads, or reverts migrations. If a database migration was separately approved, a code-only rollback may be incompatible; stop and have the DBA/release owner choose an approved forward fix or coordinated recovery.

## Evidence and current blockers

Record release ID, operator, approver, verified SSH host fingerprint, app/document-root/shared-path values, test/audit/build results, pre-release backup reference (if any migration is scheduled), migration status, HTTP checks, and rollback decision in the approved operations system. Never record private keys, passphrases, `.env` contents, patient data, or payment secrets.

The provider fingerprint was independently confirmed by the operator and is passed as a Plink/PSCP host-key pin. A read-only PuTTY/Plink connection has now authenticated as `oshelski` with home `/home/oshelski`. The inventory found PHP 8.2.35, no Composer or Node/npm commands, no Laravel `artisan` or `.env` files under the account, and a regular `/home/oshelski/public_html` directory containing WordPress files rather than the required stable document-root symlink. This application requires PHP 8.3 or newer. Since there is no Laravel installation to inspect, the production database, migration state, and database grants were not inspected.

Do not run `deploy-production.ps1` against this hosting layout. Deployment remains blocked until the hosting provider confirms an approved Laravel-capable runtime and deployment layout (including document roots, Composer/build strategy, shared `.env` and storage paths, and symlink support), and the application owner verifies production database/backup arrangements and approves the release. No files were uploaded and no production changes were made during the inventory.
