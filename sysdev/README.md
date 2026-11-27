# SafeGate

SafeGate is a PHP and MySQL guard-operated visitor log. Guards can use a camera or image file to read printed visitor-ID text in the browser. OCR fills visitor name, Philippine mobile number, and address fields when detected. The guard verifies those fields against the physical ID before check-in; the full OCR text and ID image are not stored. New visits are not matched against older visits by name or mobile number. QR codes are not scanned. The school continues using its existing visitor-pass and flap-barrier process.

## Requirements

- PHP 8.1 or newer with `pdo_mysql` enabled
- MySQL 8.0.16 or newer
- Apache with `mod_rewrite`, or PHP's development server for local testing
- Camera access requires HTTPS or localhost.

## Optional Python ID OCR utility

`tools/philippine_id_ocr.py` is a standalone local OCR utility for extracting a name, mobile number, and address from Philippine IDs. It is not connected to SafeGate's browser-based scanner and does not change the website's check-in workflow.

Install the Python dependencies from the `sysdev` directory:

```powershell
python -m pip install -r tools\requirements.txt
```

Install the Tesseract OCR executable separately. On Windows, either pass its path:

```powershell
python tools\philippine_id_ocr.py "C:\path\to\visitor-id.jpg" --tesseract-cmd "C:\Program Files\Tesseract-OCR\tesseract.exe"
```

or set `TESSERACT_CMD` in the environment:

```powershell
$env:TESSERACT_CMD = "C:\Program Files\Tesseract-OCR\tesseract.exe"
python tools\philippine_id_ocr.py "C:\path\to\visitor-id.jpg"
```

The default language is English; `--lang eng+tgl` requires the Tagalog trained data to be installed. Run the parser tests with:

```powershell
python -m unittest discover -s tools
```

The utility returns only the extracted name, mobile number, and address; it does not save or print the full OCR text. ID images and extracted details are sensitive personal information; handle them only under the school's approved privacy and retention rules.

## Create the database

For a fresh installation, start MySQL in XAMPP, open PowerShell in this directory, and import the current schema as the MySQL administrator:

```powershell
cmd /c "C:\xampp\mysql\bin\mysql.exe -u root -p < C:\xampp\htdocs\JMCC-2\sysdev\schema_mysql.sql"
```

The schema creates the `safegate` database, tables, and current columns. A fresh installation does not need the migration command below.

Then create a separate application user. Choose a strong password and use the same value later in the `SAFEGATE_DB_PASSWORD` environment variable. The host in `CREATE USER` and `GRANT` must match:

```sql
CREATE USER 'safegate_app'@'127.0.0.1' IDENTIFIED BY 'replace-with-a-strong-password';
GRANT SELECT, INSERT, UPDATE, DELETE ON safegate.* TO 'safegate_app'@'127.0.0.1';
```

Run those statements in phpMyAdmin's SQL tab while signed in as the MySQL administrator. Keep database credentials out of source control.

## Apply or update the database

For an existing SafeGate database, back it up and apply pending migrations with:

```powershell
php index.php migrate
```

Run this from the `sysdev` directory after setting the database environment variables. Migrations change the schema and need a database account with `CREATE` and `ALTER` privileges. The normal `safegate_app` account above has only runtime read/write privileges, so do not use it for migrations unless you temporarily grant the required privileges and remove them afterward.

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

- **Guards:** sign in, scan or manually enter visitor ID details, review the name, mobile number, and required address against the physical ID (manually enter the address if OCR misses it), record check-in, and record checkout after the school's pass has been returned.
- **Administrators:** review and export visitor records, invite staff, and manage staff accounts.

## Modules

1. Authentication and account management: individual staff sign-in, roles, invitations, activation, password changes, and active/inactive access.
2. Guard operations: entrance selection, ID scanning or manual entry, OCR verification, check-in, check-out, and pass-return confirmation.
3. Visitor records: active visits, daily and completed visits, search/filtering, entrance and guard details, pagination, and CSV exports.
4. Administration: staff management, account roles/status, reports, and audit-log review.
5. Security and data: CSRF protection, password hashing, one-time invitation tokens, browser-only OCR, no ID-image upload, audit logging, and validation.
6. Visitor history: guards can search prior visits by visitor name, mobile number, or address. New visits do not use name/mobile duplicate matching.
7. Pass reference: each visit receives a unique text/QR reference. It does not replace or control the school's physical pass/barrier process.
8. Long-stay monitoring: active visits older than `SAFEGATE_LONG_STAY_HOURS` are highlighted on the guard dashboard.

## Camera and OCR

- Camera scanning requires HTTPS or `localhost`.
- Printed-text OCR runs in the browser using Tesseract.js; QR codes are not read by the visitor-ID scanner.
- Camera capture is cropped to the visible ID viewfinder, then grayscaled and contrast-enhanced on a canvas before OCR. The camera preview and canvas are not mirrored. Uploaded images are OCR'd in full.
- Recognition tries sparse-text (PSM 11) and uniform-block (PSM 6) modes on the enhanced image, then repeats on an Otsu-thresholded image if the address is missing or incomplete. Address labels may be bilingual or misread; when a label is missed, common Philippine address terms are used as fallback cues.
- OCR runs locally in the browser and only the suggested name, Philippine mobile number, and address are placed in the form. OCR cannot reliably identify every ID layout, so the guard must verify or correct each value. The full OCR text is not displayed or submitted to SafeGate, and neither the ID image nor OCR text is sent to a third-party service.
- The form posts to `/record_visit.php`, which is routed through the existing authenticated, CSRF-protected `index.php` check-in handler.
- If local recognition assets are unavailable or scanning fails, guards can still enter details manually.
- Tesseract.js, its worker, language data, and WebAssembly assets are served locally from `static/vendor/tesseract`. Keep the bundled third-party assets with the approved school deployment.
- The captured ID image is never submitted to the server.
- Only the reviewed name, mobile number, and address are retained from the ID. The migration removes the former full-text OCR column and its saved values.

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