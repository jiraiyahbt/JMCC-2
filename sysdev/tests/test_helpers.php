<?php
declare(strict_types=1);

require __DIR__ . '/../index.php';

$passed = 0;

$assert = static function (bool $condition, string $description) use (&$passed): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$description}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$description}\n");
    $passed++;
};

$assert(h('<script>') === '&lt;script&gt;', 'HTML output is escaped');
$assert(url('/admin/records', ['status' => 'Exited', 'page' => 2]) === '/admin/records?status=Exited&page=2', 'URLs encode query parameters');
$assert(status_class('Inside campus') === 'inside-campus', 'Status CSS names are normalized');
$assert(csv_safe('=SUM(A1:A2)') === "'=SUM(A1:A2)", 'CSV formula injection is neutralized');
$assert(csv_safe('Maria Santos') === 'Maria Santos', 'Normal CSV values are unchanged');
$assert(campus_date() === date('Y-m-d'), 'Campus date uses the application timezone');

fwrite(STDOUT, "{$passed} tests passed.\n");
