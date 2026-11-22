# SafeGate

SafeGate is a guard-operated visitor log built with Flask and SQLite. A guard can scan a visitor's ID with a phone or tablet camera; browser-based OCR suggests a name and mobile number for the guard to verify. The guard records the person or office visited, visit purpose, entrance, and check-in/check-out times. Visitors do not log in, and the school continues using its own reusable pass and flap-barrier process.

## Requirements

- Python 3.11 or newer
- The packages listed in `requirements.txt`

## Run locally (Windows PowerShell)

From the project directory:

```powershell
py -m venv .venv
.\.venv\Scripts\Activate.ps1
python -m pip install -r requirements.txt
```

Set a stable secret key for the current PowerShell session. Generate a value with the first command, then paste it into the second:

```powershell
python -c "import secrets; print(secrets.token_urlsafe(32))"
$env:SAFEGATE_SECRET_KEY = "<paste-generated-value>"
```

Create the first administrator. The command prompts for their name, email, and a password of at least 12 characters:

```powershell
flask --app app create-admin
```

Start the development server:

```powershell
flask --app app run
```

Open `http://127.0.0.1:5000`. The SQLite database is created automatically at `instance/safegate.sqlite3`. Keep the secret key stable across restarts so existing sessions remain valid. Do not use Flask's development server in production.

## Main workflows

- Guards: sign in, open **Scan visitor ID**, capture the ID or choose an image, review/correct the suggested name and mobile number, add the person/office and purpose, and record check-in. At checkout, use the visitor log to record that the school pass has been returned and the visitor has exited.
- Administrators: review visitor records and manage staff invitations at **User accounts**.

Camera scanning requires a secure browser context: use `localhost` during local development or HTTPS when deployed. OCR runs in the browser using Tesseract.js; the captured image is not submitted to the Flask server or stored by the app. The OCR library is loaded from jsDelivr, so an internet connection is needed for OCR. If camera, OCR, or internet access is unavailable, the guard can enter the name and mobile number manually.

OCR results are suggestions and must be checked against the physical ID before saving. Mobile number is optional because it may not appear on an ID. The person/office visited and purpose are not printed on the ID and must be entered by the guard; school-specific choice lists can be added when those values are provided. Only record information approved by the school and follow its privacy notice and retention rules. The app does not read or issue the school's visitor pass and does not connect to the flap barrier.

Existing databases are not destructively migrated: old visitor-pass tables and records remain untouched, but the new app no longer displays or uses them. Back up and review those old records according to the school's retention policy before deciding whether to remove them.

## MySQL schema draft

[`schema_mysql.sql`](./schema_mysql.sql) is a MySQL 8.0.16+ starter schema for staff accounts and visitor logs. The current Flask app still uses SQLite and does not connect to this MySQL database yet. Migrating requires updating the Flask database connection, SQL handling, and conversion of timestamps to consistent Asia/Manila `DATETIME` values; do not switch databases just by running this file. Store database credentials in server environment variables, not in this SQL file or source code.

## Tests

```powershell
python -m unittest discover -s tests -v
```
