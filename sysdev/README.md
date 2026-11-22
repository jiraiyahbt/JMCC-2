# SafeGate

SafeGate is a PHP and MySQL guard-operated visitor log. Guards can use a phone or tablet camera to scan a visitor ID; OCR suggests a name and mobile number in the browser, and the guard verifies the details before recording a visit. The ID image is not uploaded or saved. The school continues using its existing visitor-pass and flap-barrier process.

## Requirements

- PHP 8.1 or newer with `pdo_mysql` enabled
- MySQL 8.0.16 or newer
- Apache with `mod_rewrite`, or PHP's development server for local testing

## Create the database

From this directory, run the schema using the MySQL client:

```powershell
cmd /c "mysql -u root -p < schema_mysql.sql"
```

Create a separate application user in MySQL (choose a strong password and use the same value in the `SAFEGATE_DB_PASSWORD` environment variable):

```sql
CREATE USER 'safegate_app'@'127.0.0.1' IDENTIFIED BY 'replace-with-a-strong-password';
GRANT SELECT, INSERT, UPDATE, DELETE ON safegate.* TO 'safegate_app'@'localhost';
```

The schema creates the `safegate` database and its tables. Keep database credentials out of source control.

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

Start the local server:

```powershell
php -S 127.0.0.1:5000 index.php
```

Open `http://127.0.0.1:5000`. The PHP runtime must have the PDO MySQL extension enabled. The development server is for local testing only; deploy behind HTTPS with Apache or another production web server. Set `SAFEGATE_COOKIE_SECURE=1` when serving over HTTPS.

## Data and migration

New staff and visitor records are written to MySQL. The existing `instance/safegate.sqlite3` file is left untouched, but its records are not automatically copied into MySQL. Back it up and arrange a reviewed migration before removing or discarding it.

## Main workflows

- Guards: sign in, scan or manually enter visitor ID details, verify the name and mobile number, record check-in, and record checkout after the school's pass has been returned.
- Administrators: review and export visitor records, invite staff, and manage staff accounts.

Camera scanning requires HTTPS or `localhost`. OCR runs in the browser using Tesseract.js; an internet connection is needed to load the OCR library. The captured ID image is not submitted to the server. Only record information approved by the school and follow its privacy notice and retention rules.
