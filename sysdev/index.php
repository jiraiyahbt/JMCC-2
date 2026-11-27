<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

const STAFF_ROLES = ['Administrator', 'Security Guard'];
const ENTRANCES = ['Gate 1 - Main entrance', 'Gate 2 - North entrance'];
require_once __DIR__ . '/lib/core.php';

function client_ip(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function audit_log(
    string $action,
    string $entityType,
    mixed $entityId = null,
    ?string $details = null,
    ?array $actor = null
): void {
    $actor ??= current_user();
    $statement = db()->prepare(
        'INSERT INTO audit_log_entries
         (actor_staff_id, actor_name, action, entity_type, entity_id, details, ip_address, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([
        $actor['staff_id'] ?? null,
        $actor['full_name'] ?? null,
        $action,
        $entityType,
        $entityId === null ? null : (string) $entityId,
        $details,
        client_ip(),
        now_string(),
    ]);
}

function login_attempt_count(string $email): int
{
    $statement = db()->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE email = ? AND ip_address = ?
           AND successful = FALSE
           AND attempted_at >= DATE_SUB(NOW(6), INTERVAL 15 MINUTE)"
    );
    $statement->execute([$email, client_ip()]);
    return (int) $statement->fetchColumn();
}

function record_login_attempt(string $email, bool $successful): void
{
    $statement = db()->prepare(
        'INSERT INTO login_attempts (email, ip_address, attempted_at, successful)
         VALUES (?, ?, ?, ?)'
    );
    $statement->execute([$email, client_ip(), now_string(), $successful]);
}

function record_rows(array $filters, int $limit = 500, int $offset = 0): array
{
    $clauses = [];
    $values = [];
    if ($filters['q'] !== '') {
        $clauses[] = '(vl.visitor_name LIKE ? OR vl.mobile_number LIKE ? OR vl.visitor_address LIKE ?)';
        $term = '%' . $filters['q'] . '%';
        $values[] = $term;
        $values[] = $term;
        $values[] = $term;
    }
    if ($filters['visit_date'] !== '') {
        $clauses[] = 'DATE(vl.entry_time) = ?';
        $values[] = $filters['visit_date'];
    }
    if (in_array($filters['status'], ['Inside campus', 'Exited'], true)) {
        $clauses[] = 'vl.status = ?';
        $values[] = $filters['status'];
    }
    if (in_array($filters['entrance'], ENTRANCES, true)) {
        $clauses[] = 'vl.entrance_name = ?';
        $values[] = $filters['entrance'];
    }

    $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';
    $limitSql = $limit > 0
        ? 'LIMIT ' . max(1, min($limit, 10000)) . ' OFFSET ' . max(0, $offset)
        : '';
    $statement = db()->prepare(
        "SELECT vl.visit_id, vl.visitor_name, vl.mobile_number, vl.visitor_address,
                vl.destination,
                vl.purpose, vl.status, vl.entrance_name, vl.exit_entrance_name,
                vl.entry_time, vl.exit_time, vl.pass_reference,
                entry_guard.full_name AS entry_guard_name,
                exit_guard.full_name AS exit_guard_name
         FROM visitor_log_entries AS vl
         JOIN staff_accounts AS entry_guard ON entry_guard.staff_id = vl.entry_guard_id
         LEFT JOIN staff_accounts AS exit_guard ON exit_guard.staff_id = vl.exit_guard_id
         $where
         ORDER BY vl.entry_time DESC
         $limitSql"
    );
    $statement->execute($values);
    return $statement->fetchAll();
}

function record_row_count(array $filters): int
{
    $clauses = [];
    $values = [];
    if ($filters['q'] !== '') {
        $clauses[] = '(visitor_name LIKE ? OR mobile_number LIKE ? OR visitor_address LIKE ?)';
        $term = '%' . $filters['q'] . '%';
        $values[] = $term;
        $values[] = $term;
        $values[] = $term;
    }
    if ($filters['visit_date'] !== '') {
        $clauses[] = 'DATE(entry_time) = ?';
        $values[] = $filters['visit_date'];
    }
    if (in_array($filters['status'], ['Inside campus', 'Exited'], true)) {
        $clauses[] = 'status = ?';
        $values[] = $filters['status'];
    }
    if (in_array($filters['entrance'], ENTRANCES, true)) {
        $clauses[] = 'entrance_name = ?';
        $values[] = $filters['entrance'];
    }
    $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';
    $statement = db()->prepare('SELECT COUNT(*) FROM visitor_log_entries ' . $where);
    $statement->execute($values);
    return (int) $statement->fetchColumn();
}

function visitor_history(string $searchTerm, ?string $mobile = null): array
{
    $term = '%' . $searchTerm . '%';
    if ($mobile) {
        $statement = db()->prepare(
            'SELECT visit_id, visitor_name, mobile_number, visitor_address, destination, purpose,
                    entrance_name, exit_entrance_name, entry_time, exit_time, status, pass_reference
             FROM visitor_log_entries
             WHERE visitor_name LIKE ? OR mobile_number = ? OR visitor_address LIKE ?
             ORDER BY entry_time DESC LIMIT 100'
        );
        $statement->execute([$term, $mobile, $term]);
    } else {
        $statement = db()->prepare(
            'SELECT visit_id, visitor_name, mobile_number, visitor_address, destination, purpose,
                    entrance_name, exit_entrance_name, entry_time, exit_time, status, pass_reference
             FROM visitor_log_entries
             WHERE visitor_name LIKE ? OR visitor_address LIKE ?
             ORDER BY entry_time DESC LIMIT 100'
        );
        $statement->execute([$term, $term]);
    }
    return $statement->fetchAll();
}

function create_pass_reference(): string
{
    return 'SG-' . strtoupper(bin2hex(random_bytes(6)));
}

function run_migrations_command(): never
{
    $sql = file_get_contents(__DIR__ . '/schema_mysql.sql');
    if ($sql === false) {
        fwrite(STDERR, "Could not read schema_mysql.sql.\n");
        exit(1);
    }
    foreach (preg_split('/;\s*(?:\R|$)/', $sql) as $statementSql) {
        $statementSql = trim(preg_replace('/^\s*--.*$/m', '', $statementSql));
        if ($statementSql === '' || str_starts_with(strtoupper($statementSql), 'CREATE DATABASE') ||
            str_starts_with(strtoupper($statementSql), 'USE ')) {
            continue;
        }
        db()->exec($statementSql);
    }
    $column = db()->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'visitor_log_entries'
           AND COLUMN_NAME = 'pass_reference'"
    )->fetchColumn();
    if ((int) $column === 0) {
        db()->exec(
            'ALTER TABLE visitor_log_entries
             ADD COLUMN pass_reference VARCHAR(32) NULL AFTER visit_id,
             ADD UNIQUE KEY uq_visitor_pass_reference (pass_reference)'
        );
    }
    $addressColumn = db()->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'visitor_log_entries'
           AND COLUMN_NAME = 'visitor_address'"
    )->fetchColumn();
    if ((int) $addressColumn === 0) {
        db()->exec(
            'ALTER TABLE visitor_log_entries
             ADD COLUMN visitor_address VARCHAR(500) NULL AFTER mobile_number'
        );
    }
    $visitorNameNullable = db()->query(
        "SELECT IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'visitor_log_entries'
           AND COLUMN_NAME = 'visitor_name'"
    )->fetchColumn();
    if ($visitorNameNullable === 'NO') {
        db()->exec(
            'ALTER TABLE visitor_log_entries
             MODIFY COLUMN visitor_name VARCHAR(120) NULL'
        );
    }
    $ocrTextColumn = db()->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'visitor_log_entries'
           AND COLUMN_NAME = 'visitor_id_text'"
    )->fetchColumn();
    if ((int) $ocrTextColumn > 0) {
        db()->exec(
            'ALTER TABLE visitor_log_entries DROP COLUMN visitor_id_text'
        );
    }
    fwrite(STDOUT, "SafeGate database migrations applied.\n");
    exit(0);
}

function purge_retention_command(): never
{
    $visitorDays = (int) (getenv('SAFEGATE_VISITOR_RETENTION_DAYS') ?: 0);
    $auditDays = (int) (getenv('SAFEGATE_AUDIT_RETENTION_DAYS') ?: 365);
    $attemptDays = 30;
    if ($visitorDays < 1) {
        fwrite(STDERR, "Set SAFEGATE_VISITOR_RETENTION_DAYS to a positive number before purging.\n");
        exit(1);
    }
    db()->beginTransaction();
    try {
        $visitor = db()->prepare(
            'DELETE FROM visitor_log_entries
             WHERE status = \'Exited\' AND exit_time IS NOT NULL
               AND exit_time < DATE_SUB(NOW(6), INTERVAL ? DAY)'
        );
        $visitor->execute([$visitorDays]);
        $audits = db()->prepare(
            'DELETE FROM audit_log_entries
             WHERE created_at < DATE_SUB(NOW(6), INTERVAL ? DAY)'
        );
        $audits->execute([$auditDays]);
        $attempts = db()->prepare(
            'DELETE FROM login_attempts
             WHERE attempted_at < DATE_SUB(NOW(6), INTERVAL ? DAY)'
        );
        $attempts->execute([$attemptDays]);
        db()->commit();
    } catch (Throwable $error) {
        db()->rollBack();
        throw $error;
    }
    fwrite(STDOUT, "Retention purge completed.\n");
    exit(0);
}

function create_admin_command(): never
{
    $read = static function (string $prompt): string {
        fwrite(STDOUT, $prompt);
        return trim((string) fgets(STDIN));
    };
    $name = $read('Full name: ');
    $email = strtolower($read('Email: '));
    $password = $read('Password (at least 12 characters): ');
    $confirmation = $read('Confirm password: ');
    if ($name === '' || strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fwrite(STDERR, "Enter a valid name and email address.\n");
        exit(1);
    }
    if (strlen($password) < 12 || $password !== $confirmation) {
        fwrite(STDERR, "Use at least 12 characters and enter the same password twice.\n");
        exit(1);
    }

    try {
        $statement = db()->prepare(
            "INSERT INTO staff_accounts
             (full_name, email, role, account_status, password_hash, created_at)
             VALUES (?, ?, 'Administrator', 'Active', ?, ?)"
        );
        $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), now_string()]);
    } catch (PDOException $error) {
        if (($error->errorInfo[0] ?? null) === '23000') {
            fwrite(STDERR, "That email address already has an account.\n");
            exit(1);
        }
        throw $error;
    }
    fwrite(STDOUT, "Administrator account created.\n");
    exit(0);
}

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'create-admin') {
    create_admin_command();
}

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'migrate') {
    run_migrations_command();
}

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'purge-retention') {
    purge_retention_command();
}

if (PHP_SAPI !== 'cli') {
    set_exception_handler(static function (Throwable $error): void {
        error_log((string) $error);
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo 'An unexpected error occurred. Check the server log.';
    });
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', '28800');
    session_set_cookie_params([
        'lifetime' => 28800,
        'path' => '/',
        'secure' => getenv('SAFEGATE_COOKIE_SECURE') === '1',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    if (PHP_SAPI === 'cli-server') {
        $requestedPath = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        $staticRoot = realpath(__DIR__ . '/static');
        $requestedFile = realpath(__DIR__ . $requestedPath);
        if ($staticRoot !== false && $requestedFile !== false &&
            str_starts_with($requestedFile, $staticRoot . DIRECTORY_SEPARATOR) &&
            is_file($requestedFile)) {
            return false;
        }
    }

    $user = current_user();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'POST') {
        $expected = $_SESSION['_csrf_token'] ?? '';
        $submitted = (string) ($_POST['_csrf_token'] ?? '');
        if ($expected === '' || $submitted === '' || !hash_equals($expected, $submitted)) {
            fail(400, 'The form expired. Reload the page and try again.');
        }
    }

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $entrances = ENTRANCES;

    if ($path === '/' && $method === 'GET') {
        if ($user !== null) {
            redirect_to($user['role'] === 'Administrator' ? '/admin' : '/guard');
        }
        redirect_to('/login');
    }

    if ($path === '/login' && $method === 'GET') {
        if ($user !== null) {
            redirect_to($user['role'] === 'Administrator' ? '/admin' : '/guard');
        }
        render('login');
    }

    if ($path === '/login' && $method === 'POST') {
        $email = strtolower(post_value('email'));
        $password = (string) ($_POST['password'] ?? '');
        if (login_attempt_count($email) >= 5) {
            record_login_attempt($email, false);
            flash('error', 'Too many failed sign-in attempts. Wait 15 minutes before trying again.');
            render('login', ['email' => $email], 429);
        }
        $statement = db()->prepare(
            "SELECT * FROM staff_accounts
             WHERE email = ? AND account_status = 'Active'"
        );
        $statement->execute([$email]);
        $staff = $statement->fetch();
        if (!$staff || !$staff['password_hash'] || !password_verify($password, $staff['password_hash'])) {
            record_login_attempt($email, false);
            flash('error', 'Email or password is incorrect, or the account is inactive.');
            render('login', ['email' => $email], 401);
        }
        record_login_attempt($email, true);
        session_regenerate_id(true);
        $_SESSION = [];
        $_SESSION['staff_id'] = $staff['staff_id'];
        csrf_token_value();
        audit_log(
            'Signed in',
            'staff_account',
            $staff['staff_id'],
            null,
            ['staff_id' => $staff['staff_id'], 'full_name' => $staff['full_name']]
        );
        flash('success', 'Welcome, ' . $staff['full_name'] . '.');
        redirect_to($staff['role'] === 'Administrator' ? '/admin' : '/guard');
    }

    if ($path === '/logout' && $method === 'POST') {
        require_login();
        audit_log('Signed out', 'staff_account', $user['staff_id']);
        $_SESSION = [];
        session_regenerate_id(true);
        flash('info', 'You have signed out.');
        redirect_to('/login');
    }

    if ($path === '/guard' && $method === 'GET') {
        require_login('Security Guard');
        $entrance = (string) ($_GET['entrance'] ?? ENTRANCES[0]);
        if (!in_array($entrance, $entrances, true)) {
            $entrance = ENTRANCES[0];
        }
        $today = campus_date();
        $inside = (int) db()->query(
            "SELECT COUNT(*) FROM visitor_log_entries WHERE status = 'Inside campus'"
        )->fetchColumn();
        $statement = db()->prepare(
            "SELECT COUNT(*) FROM visitor_log_entries
             WHERE DATE(entry_time) = ? AND status = 'Exited'"
        );
        $statement->execute([$today]);
        $exited = (int) $statement->fetchColumn();
        $statement = db()->prepare(
            'SELECT COUNT(*) FROM visitor_log_entries WHERE DATE(entry_time) = ?'
        );
        $statement->execute([$today]);
        $visitsToday = (int) $statement->fetchColumn();
        $statement = db()->prepare(
            "SELECT visit_id, visitor_name, mobile_number, visitor_address, destination, purpose,
                    entrance_name, exit_entrance_name, entry_time, exit_time, status, pass_reference
             FROM visitor_log_entries
             WHERE DATE(entry_time) = ? OR status = 'Inside campus'
             ORDER BY entry_time DESC LIMIT 100"
        );
        $statement->execute([$today]);
        $visits = $statement->fetchAll();
        $longStayHours = max(1, (int) (getenv('SAFEGATE_LONG_STAY_HOURS') ?: 8));
        $longStayCount = 0;
        foreach ($visits as $visit) {
            if ($visit['status'] === 'Inside campus') {
                $minutes = (time() - strtotime($visit['entry_time'])) / 60;
                if ($minutes >= $longStayHours * 60) {
                    $longStayCount++;
                }
            }
        }
        render('guard_dashboard', [
            'entrance' => $entrance,
            'stats' => [
                'inside' => $inside,
                'exited' => $exited,
                'visits' => $visitsToday,
                'long_stays' => $longStayCount,
            ],
            'visits' => $visits,
            'today' => $today,
            'entrances' => $entrances,
        ]);
    }

    if (in_array($path, ['/guard/verify', '/record_visit.php'], true) &&
        in_array($method, ['GET', 'POST'], true)) {
        require_login('Security Guard');
        $entrance = (string) ($_GET['entrance'] ?? $_POST['entrance'] ?? ENTRANCES[0]);
        if (!in_array($entrance, $entrances, true)) {
            $entrance = ENTRANCES[0];
        }
        $submittedVisitorName = $_POST['visitor_name'] ?? '';
        $submittedMobileNumber = $_POST['mobile_number'] ?? '';
        $submittedVisitorAddress = $_POST['visitor_address'] ?? '';
        $submittedDestination = $_POST['person_visited'] ?? $_POST['destination'] ?? '';
        $submittedPurpose = $_POST['purpose_of_visit'] ?? $_POST['purpose'] ?? '';
        $formData = [
            'visitor_name' => is_string($submittedVisitorName) ? trim($submittedVisitorName) : '',
            'mobile_number' => is_string($submittedMobileNumber) ? trim($submittedMobileNumber) : '',
            'visitor_address' => is_string($submittedVisitorAddress) ? trim($submittedVisitorAddress) : '',
            'destination' => is_string($submittedDestination) ? trim($submittedDestination) : '',
            'purpose' => is_string($submittedPurpose) ? trim($submittedPurpose) : '',
        ];
        $errors = [];
        if ($method === 'POST') {
            $entrance = (string) ($_POST['entrance'] ?? '');
            if (($_POST['action'] ?? '') !== 'check_in') {
                fail(400, 'Choose a valid check-in action.');
            }
            if (!is_string($submittedVisitorName)) {
                $errors[] = 'Visitor name must be plain text.';
            } elseif (strlen($formData['visitor_name']) < 1 || strlen($formData['visitor_name']) > 120) {
                $errors[] = 'Visitor name is required and must be 120 characters or fewer.';
            }
            if (!is_string($submittedMobileNumber)) {
                $errors[] = 'Mobile number must be plain text.';
            } elseif ($formData['mobile_number'] !== '' &&
                !preg_match('/^09\d{9}$/', $formData['mobile_number'])) {
                $errors[] = 'Mobile number must be an 11-digit Philippine number beginning with 09.';
            }
            if (!is_string($submittedVisitorAddress)) {
                $errors[] = 'Visitor address must be plain text.';
            } elseif ($formData['visitor_address'] === '') {
                $errors[] = 'Visitor address is required.';
            } elseif (strlen($formData['visitor_address']) > 500) {
                $errors[] = 'Visitor address must be 500 characters or fewer.';
            }
            if (strlen($formData['destination']) < 1 || strlen($formData['destination']) > 100) {
                $errors[] = 'Person or office visited is required and must be 100 characters or fewer.';
            }
            if (strlen($formData['purpose']) < 1 || strlen($formData['purpose']) > 255) {
                $errors[] = 'Visit purpose is required and must be 255 characters or fewer.';
            }
            if (!in_array($entrance, $entrances, true)) {
                $errors[] = 'Choose a participating entrance.';
            }
            if (($_POST['visitor_details_confirmed'] ?? '') !== 'yes') {
                $errors[] = 'Confirm that you reviewed the visitor details against the physical ID.';
            }
            if (!$errors) {
                $statement = db()->prepare(
                    "INSERT INTO visitor_log_entries
                     (pass_reference, visitor_name, mobile_number, visitor_address,
                      destination, purpose, entrance_name, entry_guard_id, entry_time, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Inside campus')"
                );
                $statement->execute([
                    create_pass_reference(),
                    $formData['visitor_name'],
                    $formData['mobile_number'] !== '' ? $formData['mobile_number'] : null,
                    $formData['visitor_address'],
                    $formData['destination'],
                    $formData['purpose'],
                    $entrance,
                    $user['staff_id'],
                    now_string(),
                ]);
                audit_log(
                    'Checked in visitor',
                    'visitor_log_entry',
                    db()->lastInsertId(),
                    'Entrance: ' . $entrance
                );
                flash('success', "Visitor check-in recorded. Continue the school's existing pass process.");
                redirect_to(url('/guard', ['entrance' => $entrance]));
            }
        }
        render('verify_visitor', [
            'errors' => $errors,
            'entrance' => $entrance,
            'form_data' => $formData,
            'entrances' => $entrances,
        ], $errors ? 400 : 200);
    }

    if (preg_match('#^/guard/visits/(\d+)/checkout$#', $path, $matches) && $method === 'POST') {
        require_login('Security Guard');
        $entrance = (string) ($_POST['exit_entrance'] ?? '');
        if (!in_array($entrance, $entrances, true)) {
            fail(400, 'Choose a valid exit entrance.');
        }
        if (($_POST['checkout_confirmed'] ?? '') !== 'yes') {
            flash('error', "Confirm that the visitor has returned the school's pass and left campus.");
            redirect_to(url('/guard', ['entrance' => $entrance]));
        }
        $statement = db()->prepare(
            "UPDATE visitor_log_entries
             SET status = 'Exited', exit_time = ?, exit_guard_id = ?, exit_entrance_name = ?
             WHERE visit_id = ? AND status = 'Inside campus'"
        );
        $statement->execute([now_string(), $user['staff_id'], $entrance, (int) $matches[1]]);
        if ($statement->rowCount() !== 1) {
            flash('warning', 'This visit is already checked out or could not be found.');
        } else {
            audit_log(
                'Checked out visitor',
                'visitor_log_entry',
                (int) $matches[1],
                'Exit entrance: ' . $entrance
            );
            flash('success', 'Visitor checkout recorded.');
        }
        redirect_to(url('/guard', ['entrance' => $entrance]));
    }

    if (preg_match('#^/guard/visits/(\d+)/pass$#', $path, $matches) && $method === 'GET') {
        require_login('Security Guard');
        $statement = db()->prepare(
            'SELECT visit_id, visitor_name, destination, purpose,
                    entrance_name, entry_time, exit_entrance_name, exit_time, status
             FROM visitor_log_entries WHERE visit_id = ?'
        );
        $statement->execute([(int) $matches[1]]);
        $visit = $statement->fetch();
        if (!$visit) {
            fail(404, 'The visitor pass record could not be found.');
        }
        render('visitor_pass', ['visit' => $visit]);
    }

    if ($path === '/guard/visitor-history' && $method === 'GET') {
        require_login('Security Guard');
        $name = trim((string) ($_GET['name'] ?? ''));
        $mobile = trim((string) ($_GET['mobile'] ?? ''));
        $history = $name !== ''
            ? visitor_history($name, $mobile !== '' ? $mobile : null)
            : [];
        render('visitor_history', [
            'name' => $name,
            'mobile' => $mobile,
            'history' => $history,
        ]);
    }

    if ($path === '/admin' && $method === 'GET') {
        require_login('Administrator');
        redirect_to('/admin/records');
    }

    if ($path === '/admin/records' && $method === 'GET') {
        require_login('Administrator');
        $filters = [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'visit_date' => trim((string) ($_GET['visit_date'] ?? '')),
            'status' => trim((string) ($_GET['status'] ?? '')),
            'entrance' => trim((string) ($_GET['entrance'] ?? '')),
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 50;
        $totalRecords = record_row_count($filters);
        $totalPages = max(1, (int) ceil($totalRecords / $perPage));
        $page = min($page, $totalPages);
        $today = campus_date();
        $statement = db()->prepare(
            "SELECT
                (SELECT COUNT(*) FROM visitor_log_entries WHERE DATE(entry_time) = ?) AS all_visits,
                (SELECT COUNT(*) FROM visitor_log_entries WHERE status = 'Inside campus') AS inside,
                (SELECT COUNT(*) FROM visitor_log_entries
                 WHERE DATE(entry_time) = ? AND status = 'Exited') AS exited"
        );
        $statement->execute([$today, $today]);
        render('admin_records', [
            'filters' => $filters,
            'records' => record_rows($filters, $perPage, ($page - 1) * $perPage),
            'totals' => $statement->fetch(),
            'today' => $today,
            'entrances' => $entrances,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'total_records' => $totalRecords,
        ]);
    }

    if ($path === '/admin/records.csv' && $method === 'GET') {
        require_login('Administrator');
        $filters = [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'visit_date' => trim((string) ($_GET['visit_date'] ?? '')),
            'status' => trim((string) ($_GET['status'] ?? '')),
            'entrance' => trim((string) ($_GET['entrance'] ?? '')),
        ];
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=safegate-visitor-records.csv');
        echo "\xEF\xBB\xBF";
        $output = fopen('php://output', 'w');
        fputcsv($output, [
            'Visitor', 'Mobile', 'Person or office visited', 'Visit purpose', 'Check-in',
            'Check-in entrance', 'Check-in guard', 'Check-out', 'Check-out entrance',
            'Check-out guard', 'Status', 'Pass reference', 'Visitor address',
        ]);
        foreach (record_rows($filters, 0) as $record) {
            fputcsv($output, array_map('csv_safe', [
                ($record['visitor_name'] ?? '') ?: 'Name not recorded',
                $record['mobile_number'] ?? '',
                $record['destination'],
                $record['purpose'],
                $record['entry_time'] ?? '',
                $record['entrance_name'] ?? '',
                $record['entry_guard_name'] ?? '',
                $record['exit_time'] ?? '',
                $record['exit_entrance_name'] ?? '',
                $record['exit_guard_name'] ?? '',
                $record['status'],
                $record['pass_reference'] ?? '',
                $record['visitor_address'] ?? '',
            ]));
        }
        fclose($output);
        exit;
    }

    if ($path === '/admin/audit' && $method === 'GET') {
        require_login('Administrator');
        $entries = db()->query(
            'SELECT audit_id, actor_name, action, entity_type, entity_id,
                    details, ip_address, created_at
             FROM audit_log_entries
             ORDER BY created_at DESC
             LIMIT 200'
        )->fetchAll();
        render('admin_audit', ['entries' => $entries]);
    }

    if ($path === '/admin/users' && in_array($method, ['GET', 'POST'], true)) {
        require_login('Administrator');
        $invitationUrl = null;
        if ($method === 'POST') {
            $fullName = post_value('full_name');
            $email = strtolower(post_value('email'));
            $role = (string) ($_POST['role'] ?? '');
            if (strlen($fullName) < 1 || strlen($fullName) > 120) {
                flash('error', 'Full name is required and must be 120 characters or fewer.');
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('error', 'Enter a valid email address.');
            } elseif (!in_array($role, STAFF_ROLES, true)) {
                flash('error', 'Choose an administrator or security guard role.');
            } else {
                $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                try {
                    $statement = db()->prepare(
                        "INSERT INTO staff_accounts
                         (full_name, email, role, account_status, invite_token_hash,
                          invite_expires_at, created_at)
                         VALUES (?, ?, ?, 'Invited', ?, ?, ?)"
                    );
                    $expires = (new DateTimeImmutable('+48 hours'))->format('Y-m-d H:i:s');
                    $statement->execute([
                        $fullName, $email, $role, hash('sha256', $token), $expires, now_string(),
                    ]);
                    audit_log('Created staff invitation', 'staff_account', db()->lastInsertId(), 'Role: ' . $role);
                    $invitationUrl = url('/accept-invite/' . rawurlencode($token));
                    flash('success', 'Staff invitation created. Share the one-time link with the staff member.');
                } catch (PDOException $error) {
                    if ($error->getCode() !== '23000') {
                        throw $error;
                    }
                    flash('error', 'An account already uses that email address.');
                }
            }
        }
        $accounts = db()->query(
            'SELECT staff_id, full_name, email, role, account_status, created_at
             FROM staff_accounts ORDER BY created_at DESC'
        )->fetchAll();
        render('admin_users', [
            'invitation_url' => $invitationUrl,
            'accounts' => $accounts,
            'staff_roles' => STAFF_ROLES,
        ]);
    }

    if (preg_match('#^/admin/users/(\d+)/update$#', $path, $matches) && $method === 'POST') {
        require_login('Administrator');
        $staffId = (int) $matches[1];
        $statement = db()->prepare('SELECT * FROM staff_accounts WHERE staff_id = ?');
        $statement->execute([$staffId]);
        $account = $statement->fetch();
        if (!$account) {
            fail(404, 'The requested staff account could not be found.');
        }
        $role = (string) ($_POST['role'] ?? $account['role']);
        $status = (string) ($_POST['account_status'] ?? $account['account_status']);
        if (!in_array($role, STAFF_ROLES, true) || !in_array($status, ['Active', 'Inactive'], true)) {
            fail(400, 'Choose a valid role and account status.');
        }
        if ($account['account_status'] === 'Invited') {
            flash('warning', 'Invited accounts must accept their invitation before they can be activated.');
            redirect_to('/admin/users');
        }
        if ((int) $staffId === (int) $user['staff_id'] && $status === 'Inactive') {
            flash('error', 'You cannot deactivate your own account.');
            redirect_to('/admin/users');
        }
        if ($account['role'] === 'Administrator' &&
            ($role !== 'Administrator' || $status !== 'Active')) {
            $activeAdmins = (int) db()->query(
                "SELECT COUNT(*) FROM staff_accounts
                 WHERE role = 'Administrator' AND account_status = 'Active'"
            )->fetchColumn();
            if ($activeAdmins <= 1) {
                flash('error', 'The last active administrator cannot be deactivated or demoted.');
                redirect_to('/admin/users');
            }
        }
        $statement = db()->prepare(
            'UPDATE staff_accounts SET role = ?, account_status = ? WHERE staff_id = ?'
        );
        $statement->execute([$role, $status, $staffId]);
        audit_log('Updated staff account', 'staff_account', $staffId, 'Role: ' . $role . '; Status: ' . $status);
        flash('success', 'Staff account updated.');
        redirect_to('/admin/users');
    }

    if (preg_match('#^/accept-invite/([^/]+)$#', $path, $matches) &&
        in_array($method, ['GET', 'POST'], true)) {
        $token = rawurldecode($matches[1]);
        $statement = db()->prepare(
            "SELECT staff_id, full_name, email FROM staff_accounts
             WHERE invite_token_hash = ? AND account_status = 'Invited'
               AND invite_expires_at >= ?"
        );
        $statement->execute([hash('sha256', $token), now_string()]);
        $invited = $statement->fetch();
        if (!$invited) {
            fail(404, 'This invitation is invalid or expired.');
        }
        if ($method === 'POST') {
            $password = (string) ($_POST['password'] ?? '');
            $confirmation = (string) ($_POST['password_confirmation'] ?? '');
            if (strlen($password) < 12) {
                flash('error', 'Password must contain at least 12 characters.');
            } elseif ($password !== $confirmation) {
                flash('error', 'The passwords do not match.');
            } else {
                $statement = db()->prepare(
                    "UPDATE staff_accounts
                     SET password_hash = ?, account_status = 'Active',
                         invite_token_hash = NULL, invite_expires_at = NULL
                     WHERE staff_id = ? AND account_status = 'Invited'"
                );
                $statement->execute([password_hash($password, PASSWORD_DEFAULT), $invited['staff_id']]);
                audit_log('Accepted staff invitation', 'staff_account', $invited['staff_id']);
                flash('success', 'Account activated. You can now sign in.');
                redirect_to('/login');
            }
        }
        render('accept_invite', ['invited' => $invited]);
    }

    if ($path === '/account/password' && in_array($method, ['GET', 'POST'], true)) {
        $user = require_login();
        if ($method === 'POST') {
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            $newPassword = (string) ($_POST['new_password'] ?? '');
            $confirmation = (string) ($_POST['password_confirmation'] ?? '');
            $statement = db()->prepare('SELECT password_hash FROM staff_accounts WHERE staff_id = ?');
            $statement->execute([$user['staff_id']]);
            $account = $statement->fetch();
            if (!$account || !password_verify($currentPassword, $account['password_hash'])) {
                flash('error', 'Current password is incorrect.');
            } elseif (strlen($newPassword) < 12) {
                flash('error', 'New password must contain at least 12 characters.');
            } elseif ($newPassword !== $confirmation) {
                flash('error', 'The new passwords do not match.');
            } else {
                $statement = db()->prepare(
                    'UPDATE staff_accounts SET password_hash = ? WHERE staff_id = ?'
                );
                $statement->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['staff_id']]);
                audit_log('Changed own password', 'staff_account', $user['staff_id']);
                flash('success', 'Password updated.');
                redirect_to('/account/password');
            }
        }
        render('change_password');
    }

    fail(404, 'The requested page could not be found.');
}
