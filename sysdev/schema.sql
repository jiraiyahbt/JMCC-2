PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS staff_accounts (
    staff_id INTEGER PRIMARY KEY AUTOINCREMENT,
    full_name TEXT NOT NULL CHECK (length(full_name) BETWEEN 1 AND 120),
    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
    role TEXT NOT NULL CHECK (role IN ('Administrator', 'Security Guard')),
    account_status TEXT NOT NULL CHECK (account_status IN ('Active', 'Invited', 'Inactive')),
    password_hash TEXT,
    invite_token_hash TEXT UNIQUE,
    invite_expires_at TEXT,
    created_at TEXT NOT NULL,
    CHECK (
        (account_status = 'Invited' AND invite_token_hash IS NOT NULL AND invite_expires_at IS NOT NULL)
        OR (account_status != 'Invited' AND invite_token_hash IS NULL AND invite_expires_at IS NULL)
    )
);

CREATE TABLE IF NOT EXISTS visitor_log_entries (
    visit_id INTEGER PRIMARY KEY AUTOINCREMENT,
    visitor_name TEXT NOT NULL CHECK (length(visitor_name) BETWEEN 1 AND 120),
    mobile_number TEXT CHECK (
        mobile_number IS NULL OR length(mobile_number) BETWEEN 7 AND 20
    ),
    destination TEXT NOT NULL CHECK (length(destination) BETWEEN 1 AND 100),
    purpose TEXT NOT NULL CHECK (length(purpose) BETWEEN 1 AND 255),
    entrance_name TEXT NOT NULL CHECK (length(entrance_name) BETWEEN 1 AND 100),
    exit_entrance_name TEXT CHECK (
        exit_entrance_name IS NULL OR length(exit_entrance_name) BETWEEN 1 AND 100
    ),
    entry_guard_id INTEGER NOT NULL REFERENCES staff_accounts(staff_id),
    exit_guard_id INTEGER REFERENCES staff_accounts(staff_id),
    entry_time TEXT NOT NULL,
    exit_time TEXT,
    status TEXT NOT NULL CHECK (
        status IN ('Inside campus', 'Exited')
    ),
    CHECK (
        (status = 'Inside campus' AND exit_time IS NULL AND exit_guard_id IS NULL)
        OR (status = 'Exited' AND exit_time IS NOT NULL AND exit_guard_id IS NOT NULL)
    )
);

CREATE INDEX IF NOT EXISTS idx_visitor_logs_entry_status
    ON visitor_log_entries(entry_time, status);
CREATE INDEX IF NOT EXISTS idx_visitor_logs_name
    ON visitor_log_entries(visitor_name);
