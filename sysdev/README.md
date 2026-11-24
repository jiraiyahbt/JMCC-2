# SafeGate

SafeGate is a PHP and MySQL guard-operated visitor log. Guards can use a camera or image file to read printed visitor-ID text in the browser. OCR suggests a name, mobile number, and labeled address for the guard to verify before recording a visit. QR codes are not scanned. The ID image is not uploaded or saved. The school continues using its existing visitor-pass and flap-barrier process.

## Requirements

- PHP 8.1 or newer with `pdo_mysql` enabled
- MySQL 8.0.16 or newer
- Apache with `mod_rewrite`, or PHP's development server for local testing
- Camera access requires HTTPS or localhost.

## Create the database

From this directory, run the schema using the MySQL client:

```powershell
cmd /c "mysql -u root -p < schema_mysql.sql"
```

The schema creates the `safegate` database and its tables.

Then create a separate application user. Choose a strong password and use the same value later in the `SAFEGATE_DB_PASSWORD` environment variable. The host in `CREATE USER` and `GRANT` must match:

```sql
CREATE USER 'safegate_app'@'localhost' IDENTIFIED BY 'replace-with-a-strong-password';
GRANT SELECT, INSERT, UPDATE, DELETE ON safegate.* TO 'safegate_app'@'localhost';
```

If the app later fails with "access denied" while connecting through `127.0.0.1`, create the same user and grant for `'safegate_app'@'127.0.0.1'` too.

Keep database credentials out of source control.

## Apply or update the database

After setting the database environment variables, the schema and all new tables can be applied with:

```powershell
php index.php migrate
```

The `migrate` command requires the database name and application database user to be reachable. Review each schema change before applying it to a production database.

## Run locally on Windows PowerShell

Set the connection settings in the same PowerShell window:

```powershell
$env:SAFEGATE_DB_HOST = "127.0.0.1"
$env:SAFEGATE_DB_PORT = "3306"
$env:SAFEGATE_DB_NAME = "safegate"
$env:SAFEGATE_DB_USER = "safegate_app"
$env:SAFEGATE_DB_PASSWORD = Read-Host "MySQL application password"
```

Create the first administrator. The CLI asks for a full name, email, and a password of at least 12 characters:

```powershell
php index.php create-admin
```

Run the test suite:

```powershell
php tests/test_helpers.php
```

Start the local server:

```powershell
php -S 127.0.0.1:5000 index.php
```

Open `http://127.0.0.1:5000`.

The development server is for local testing only. For real use, deploy behind HTTPS with Apache or another production web server, and set `SAFEGATE_COOKIE_SECURE=1`.

## Data and migration

New staff and visitor records are written to MySQL. The old `instance/safegate.sqlite3` file is left untouched, but its records are **not** automatically copied into MySQL. Back it up and plan a reviewed migration before removing or discarding it.

The SQLite file may contain real visitor data. Add it to `.gitignore` so it never gets pushed:

```gitignore
instance/
*.sqlite3
```

## Main workflows

- **Guards:** sign in, scan or manually enter visitor ID details, verify the name and mobile number, record check-in, and record checkout after the school's pass has been returned.
- **Administrators:** review and export visitor records, invite staff, and manage staff accounts.

## Modules

1. Authentication and account management: individual staff sign-in, roles, invitations, activation, password changes, and active/inactive access.
2. Guard operations: entrance selection, ID scanning or manual entry, OCR verification, check-in, check-out, and pass-return confirmation.
3. Visitor records: active visits, daily and completed visits, search/filtering, entrance and guard details, pagination, and CSV exports.
4. Administration: staff management, account roles/status, reports, and audit-log review.
5. Security and data: CSRF protection, password hashing, one-time invitation tokens, browser-only OCR, no ID-image upload, audit logging, and validation.
6. Visitor history and duplicate detection: guard can search prior visits and is warned before recording a matching active visitor.
7. Pass reference: each visit receives a unique text/QR reference. It does not replace or control the school's physical pass/barrier process.
8. Long-stay monitoring: active visits older than `SAFEGATE_LONG_STAY_HOURS` are highlighted on the guard dashboard.

## Camera and OCR

- Camera scanning requires HTTPS or `localhost`.
- Printed-text OCR runs in the browser using Tesseract.js; QR codes are not read by the visitor-ID scanner.
- OCR suggests name and mobile number and only suggests an address when it recognizes a printed `Address` or `Tirahan` label. ID layouts vary; the guard must verify or correct all suggestions. Destination and purpose must be entered manually.
- If local recognition assets are unavailable or scanning fails, guards can still enter details manually.
- Tesseract.js, its worker, language data, and WebAssembly assets are served locally from `static/vendor/tesseract`. Keep the bundled third-party assets with the approved school deployment.
- The captured ID image is never submitted to the server.
- The optional visitor address is saved with the visit record. Collect and retain it only if approved by the school.

## Privacy

Only collect record information approved by the school, and follow its privacy notice and retention rules.

## Operations and retention

- The application records failed and successful sign-ins, staff changes, visitor check-ins/check-outs, and invitation activation in `audit_log_entries`.
- Set `SAFEGATE_LONG_STAY_HOURS` to the school-approved notification threshold (default: 8 hours).
- Sign-in attempts are limited to five failures per email and IP address within 15 minutes.
- Set `SAFEGATE_VISITOR_RETENTION_DAYS` and `SAFEGATE_AUDIT_RETENTION_DAYS` before running retention cleanup.
- Only completed visits older than the configured retention period are deleted:

```powershell
$env:SAFEGATE_VISITOR_RETENTION_DAYS = "365"
$env:SAFEGATE_AUDIT_RETENTION_DAYS = "365"
php index.php purge-retention
```

- Login attempts older than 30 days are removed by the same command.
- Back up the MySQL database and reviewed configuration before applying migrations or retention cleanup.
- Deploy behind HTTPS, set `SAFEGATE_COOKIE_SECURE=1`, restrict database and filesystem permissions, and rotate database credentials if exposure is suspected.