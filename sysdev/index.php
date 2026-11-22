<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Manila');

const STAFF_ROLES = ['Administrator', 'Security Guard'];
const ENTRANCES = ['Gate 1 - Main entrance', 'Gate 2 - North entrance'];

function h(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function db(): PDO
{
    static $connection = null;
    if ($connection instanceof PDO) {
        return $connection;
    }

    $host = getenv('SAFEGATE_DB_HOST') ?: '127.0.0.1';
    $port = getenv('SAFEGATE_DB_PORT') ?: '3306';
    $name = getenv('SAFEGATE_DB_NAME') ?: 'safegate';
    $user = getenv('SAFEGATE_DB_USER') ?: 'safegate_app';
    $password = getenv('SAFEGATE_DB_PASSWORD');
    if ($password === false || $password === '') {
        throw new RuntimeException('Set SAFEGATE_DB_PASSWORD before starting SafeGate.');
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
    $connection = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $connection->exec("SET time_zone = '+08:00'");
    return $connection;
}

function now_string(): string
{
    return date('Y-m-d H:i:s');
}

function csrf_token_value(): string
{
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

function csrf_field(): void
{
    echo '<input type="hidden" name="_csrf_token" value="' . h(csrf_token_value()) . '">';
}

function flash(string $category, string $message): void
{
    $_SESSION['_flashes'][] = [$category, $message];
}

function redirect_to(string $path): never
{
    header('Location: ' . $path, true, 302);
    exit;
}

function url(string $path, array $query = []): string
{
    return $path . ($query ? '?' . http_build_query($query) : '');
}

function render(string $template, array $data = [], int $status = 200): never
{
    http_response_code($status);
    $user = current_user();
    $messages = $_SESSION['_flashes'] ?? [];
    unset($_SESSION['_flashes']);
    extract($data, EXTR_SKIP);
    ob_start();
    require __DIR__ . '/templates/' . $template . '.php';
    $content = ob_get_clean();
    require __DIR__ . '/templates/base.php';
    exit;
}

function fail(int $status, string $description): never
{
    $names = [400 => 'Bad Request', 403 => 'Forbidden', 404 => 'Not Found'];
    render('error', [
        'error' => [
            'code' => $status,
            'name' => $names[$status] ?? 'Error',
            'description' => $description,
        ],
    ], $status);
}

function post_value(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

function current_user(): ?array
{
    static $loaded = false;
    static $user = null;
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    $staffId = $_SESSION['staff_id'] ?? null;
    if ($staffId === null) {
        return null;
    }

    $statement = db()->prepare(
        'SELECT staff_id, full_name, email, role, account_status
         FROM staff_accounts WHERE staff_id = ?'
    );
    $statement->execute([$staffId]);
    $user = $statement->fetch() ?: null;
    if ($user === null || $user['account_status'] !== 'Active') {
        $_SESSION = [];
        session_regenerate_id(true);
        return null;
    }
    return $user;
}

function require_login(?string $role = null): array
{
    $user = current_user();
    if ($user === null) {
        flash('warning', 'Sign in to continue.');
        redirect_to('/login');
    }
    if ($role !== null && $user['role'] !== $role) {
        fail(403, 'You do not have permission to access this page.');
    }
    return $user;
}

function campus_date(): string
{
    return date('Y-m-d');
}

function status_class(string $status): string
{
    return strtolower(str_replace(' ', '-', $status));
}

function csv_safe(mixed $value): mixed
{
    if (is_string($value) && preg_match('/^[=+\-@\t\r]/', $value)) {
        return "'" . $value;
    }
    return $value;
}

function record_rows(array $filters): array
{
    $clauses = [];
    $values = [];
    if ($filters['q'] !== '') {
        $clauses[] = '(vl.visitor_name LIKE ? OR vl.mobile_number LIKE ?)';
        $term = '%' . $filters['q'] . '%';
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
    $statement = db()->prepare(
        "SELECT vl.visit_id, vl.visitor_name, vl.mobile_number, vl.destination,
                vl.purpose, vl.status, vl.entrance_name, vl.exit_entrance_name,
                vl.entry_time, vl.exit_time,
                entry_guard.full_name AS entry_guard_name,
                exit_guard.full_name AS exit_guard_name
         FROM visitor_log_entries AS vl
         JOIN staff_accounts AS entry_guard ON entry_guard.staff_id = vl.entry_guard_id
         LEFT JOIN staff_accounts AS exit_guard ON exit_guard.staff_id = vl.exit_guard_id
         $where
         ORDER BY vl.entry_time DESC
         LIMIT 500"
    );
    $statement->execute($values);
    return $statement->fetchAll();
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
        if ($error->getCode() === '23000') {
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
        $statement = db()->prepare(
            "SELECT * FROM staff_accounts
             WHERE email = ? AND account_status = 'Active'"
        );
        $statement->execute([$email]);
        $staff = $statement->fetch();
        if (!$staff || !$staff['password_hash'] || !password_verify($password, $staff['password_hash'])) {
            flash('error', 'Email or password is incorrect, or the account is inactive.');
            render('login', ['email' => $email], 401);
        }
        session_regenerate_id(true);
        $_SESSION = [];
        $_SESSION['staff_id'] = $staff['staff_id'];
        csrf_token_value();
        flash('success', 'Welcome, ' . $staff['full_name'] . '.');
        redirect_to($staff['role'] === 'Administrator' ? '/admin' : '/guard');
    }

    if ($path === '/logout' && $method === 'POST') {
        require_login();
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
            "SELECT visit_id, visitor_name, mobile_number, destination, purpose,
                    entrance_name, exit_entrance_name, entry_time, exit_time, status
             FROM visitor_log_entries
             WHERE DATE(entry_time) = ? OR status = 'Inside campus'
             ORDER BY entry_time DESC LIMIT 100"
        );
        $statement->execute([$today]);
        render('guard_dashboard', [
            'entrance' => $entrance,
            'stats' => ['inside' => $inside, 'exited' => $exited, 'visits' => $visitsToday],
            'visits' => $statement->fetchAll(),
            'today' => $today,
            'entrances' => $entrances,
        ]);
    }

    if ($path === '/guard/verify' && in_array($method, ['GET', 'POST'], true)) {
        require_login('Security Guard');
        $entrance = (string) ($_GET['entrance'] ?? $_POST['entrance'] ?? ENTRANCES[0]);
        if (!in_array($entrance, $entrances, true)) {
            $entrance = ENTRANCES[0];
        }
        $formData = [
            'visitor_name' => post_value('visitor_name'),
            'mobile_number' => post_value('mobile_number'),
            'destination' => post_value('destination'),
            'purpose' => post_value('purpose'),
        ];
        $errors = [];
        if ($method === 'POST') {
            $entrance = (string) ($_POST['entrance'] ?? '');
            if (($_POST['action'] ?? '') !== 'check_in') {
                fail(400, 'Choose a valid check-in action.');
            }
            if (strlen($formData['visitor_name']) < 1 || strlen($formData['visitor_name']) > 120) {
                $errors[] = 'Visitor name is required and must be 120 characters or fewer.';
            }
            if ($formData['mobile_number'] !== '' &&
                !preg_match('/^[+0-9][0-9()\-\s]{6,19}$/', $formData['mobile_number'])) {
                $errors[] = 'Enter a valid mobile number or leave it blank.';
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
            if (($_POST['identity_confirmed'] ?? '') !== 'yes') {
                $errors[] = 'Confirm that you reviewed the visitor details against their ID.';
            }
            if (!$errors) {
                $statement = db()->prepare(
                    "INSERT INTO visitor_log_entries
                     (visitor_name, mobile_number, destination, purpose, entrance_name,
                      entry_guard_id, entry_time, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'Inside campus')"
                );
                $statement->execute([
                    $formData['visitor_name'],
                    $formData['mobile_number'] ?: null,
                    $formData['destination'],
                    $formData['purpose'],
                    $entrance,
                    $user['staff_id'],
                    now_string(),
                ]);
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
            flash('success', 'Visitor checkout recorded.');
        }
        redirect_to(url('/guard', ['entrance' => $entrance]));
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
            'records' => record_rows($filters),
            'totals' => $statement->fetch(),
            'today' => $today,
            'entrances' => $entrances,
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
            'Check-out guard', 'Status',
        ]);
        foreach (record_rows($filters) as $record) {
            fputcsv($output, array_map('csv_safe', [
                $record['visitor_name'],
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
            ]));
        }
        fclose($output);
        exit;
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
                flash('success', 'Password updated.');
                redirect_to('/account/password');
            }
        }
        render('change_password');
    }

    fail(404, 'The requested page could not be found.');
}
