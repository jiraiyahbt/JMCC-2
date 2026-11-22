<?php
declare(strict_types=1);

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
    require dirname(__DIR__) . '/templates/' . $template . '.php';
    $content = ob_get_clean();
    require dirname(__DIR__) . '/templates/base.php';
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
