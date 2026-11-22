-- Store DATETIME values consistently as Asia/Manila local time.
CREATE DATABASE IF NOT EXISTS safegate
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE safegate;

CREATE TABLE IF NOT EXISTS staff_accounts (
    staff_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(254) NOT NULL,
    role ENUM('Administrator', 'Security Guard') NOT NULL,
    account_status ENUM('Active', 'Invited', 'Inactive') NOT NULL,
    password_hash VARCHAR(255) NULL,
    invite_token_hash CHAR(64) NULL,
    invite_expires_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL,
    PRIMARY KEY (staff_id),
    UNIQUE KEY uq_staff_email (email),
    UNIQUE KEY uq_staff_invite_token_hash (invite_token_hash),
    CONSTRAINT chk_staff_invitation_state CHECK (
        (account_status = 'Invited'
            AND invite_token_hash IS NOT NULL
            AND invite_expires_at IS NOT NULL
            AND password_hash IS NULL)
        OR
        (account_status IN ('Active', 'Inactive')
            AND invite_token_hash IS NULL
            AND invite_expires_at IS NULL
            AND password_hash IS NOT NULL)
    )
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS visitor_log_entries (
    visit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    visitor_name VARCHAR(120) NOT NULL,
    mobile_number VARCHAR(20) NULL,
    destination VARCHAR(100) NOT NULL,
    purpose VARCHAR(255) NOT NULL,
    entrance_name VARCHAR(100) NOT NULL,
    exit_entrance_name VARCHAR(100) NULL,
    entry_guard_id BIGINT UNSIGNED NOT NULL,
    exit_guard_id BIGINT UNSIGNED NULL,
    entry_time DATETIME(6) NOT NULL,
    exit_time DATETIME(6) NULL,
    status ENUM('Inside campus', 'Exited') NOT NULL,
    PRIMARY KEY (visit_id),
    KEY idx_visitor_logs_entry_status (entry_time, status),
    KEY idx_visitor_logs_name (visitor_name),
    KEY idx_visitor_logs_mobile (mobile_number),
    CONSTRAINT fk_visitor_logs_entry_guard
        FOREIGN KEY (entry_guard_id) REFERENCES staff_accounts (staff_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_visitor_logs_exit_guard
        FOREIGN KEY (exit_guard_id) REFERENCES staff_accounts (staff_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_visitor_log_fields CHECK (
        CHAR_LENGTH(visitor_name) BETWEEN 1 AND 120
        AND (mobile_number IS NULL OR CHAR_LENGTH(mobile_number) BETWEEN 7 AND 20)
        AND CHAR_LENGTH(destination) BETWEEN 1 AND 100
        AND CHAR_LENGTH(purpose) BETWEEN 1 AND 255
        AND CHAR_LENGTH(entrance_name) BETWEEN 1 AND 100
        AND (exit_entrance_name IS NULL
            OR CHAR_LENGTH(exit_entrance_name) BETWEEN 1 AND 100)
    ),
    CONSTRAINT chk_visitor_log_checkout_state CHECK (
        (status = 'Inside campus'
            AND exit_time IS NULL
            AND exit_entrance_name IS NULL
            AND exit_guard_id IS NULL)
        OR
        (status = 'Exited'
            AND exit_time IS NOT NULL
            AND exit_entrance_name IS NOT NULL
            AND exit_guard_id IS NOT NULL)
    )
) ENGINE=InnoDB;
