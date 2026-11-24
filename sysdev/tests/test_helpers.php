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
$scannerScript = file_get_contents(__DIR__ . '/../static/js/id_scan.js');
$scannerLayout = file_get_contents(__DIR__ . '/../templates/base.php');
$assert(
    is_string($scannerScript) && !str_contains($scannerScript, 'decodeQr') &&
        is_string($scannerLayout) && !str_contains($scannerLayout, 'jsQR.js'),
    'Visitor ID scanner uses printed-text OCR without loading QR decoding'
);

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
ob_start();
require __DIR__ . '/../templates/admin_records.php';
$adminRecordsHtml = ob_get_clean();
restore_error_handler();
$assert(
    str_contains($adminRecordsHtml, 'matching record(s); page 1 of 1'),
    'Admin records template renders safely with default data'
);

ob_start();
require __DIR__ . '/../templates/error.php';
$errorHtml = ob_get_clean();
$assert(
    str_contains($errorHtml, '404 · Not Found') &&
        str_contains($errorHtml, 'The requested page could not be found.'),
    'Error template renders safely without supplied error details'
);

$_SESSION = [];
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
ob_start();
require __DIR__ . '/../templates/guard_dashboard.php';
$guardDashboardHtml = ob_get_clean();
restore_error_handler();
$assert(
    str_contains($guardDashboardHtml, 'Visitor log') &&
        str_contains($guardDashboardHtml, 'No visits have been logged today.'),
    'Guard dashboard renders safely with default data'
);

$today = date('Y-m-d');
$entrance = ENTRANCES[0];
$entrances = ENTRANCES;
$stats = ['inside' => 1, 'visits' => 1, 'exited' => 0, 'long_stays' => 1];
$visits = [[
    'visit_id' => 1,
    'visitor_name' => 'Test Visitor',
    'mobile_number' => null,
    'destination' => 'Reception',
    'purpose' => 'Meeting',
    'entry_time' => $today . ' 09:00:00',
    'exit_time' => null,
    'entrance_name' => ENTRANCES[0],
    'exit_entrance_name' => null,
    'status' => 'Inside campus',
    'pass_reference' => 'SG-TEST',
]];
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
ob_start();
require __DIR__ . '/../templates/guard_dashboard.php';
$populatedDashboardHtml = ob_get_clean();
restore_error_handler();
$assert(
    str_contains($populatedDashboardHtml, 'Test Visitor') &&
        str_contains($populatedDashboardHtml, 'Long-stay alert'),
    'Guard dashboard renders active visit and alert data'
);

$entrances = ENTRANCES;
$entrance = ENTRANCES[0];
$form_data = [
    'visitor_name' => '<Test Visitor>',
    'mobile_number' => '',
    'visitor_address' => '<Test Address>',
    'destination' => 'Reception',
    'purpose' => 'Meeting',
];
$errors = ['Please confirm the separate active visit.'];
$duplicates = [['visit_id' => 5]];
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
ob_start();
require __DIR__ . '/../templates/verify_visitor.php';
$visitorFormHtml = ob_get_clean();
restore_error_handler();
$assert(
    str_contains($visitorFormHtml, 'Possible duplicate visitor') &&
        str_contains($visitorFormHtml, '&lt;Test Visitor&gt;') &&
        str_contains($visitorFormHtml, '&lt;Test Address&gt;') &&
        str_contains($visitorFormHtml, 'Please confirm the separate active visit.'),
    'Visitor verification form safely renders errors, duplicate warnings, and submitted values'
);

$name = null;
$mobile = null;
$history = null;
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
ob_start();
require __DIR__ . '/../templates/visitor_history.php';
$emptyHistoryHtml = ob_get_clean();
restore_error_handler();
$assert(
    str_contains($emptyHistoryHtml, 'Enter a visitor name to view previous visits.'),
    'Visitor history renders an empty state when search data is missing'
);

$name = 'Test Visitor';
$mobile = '';
$history = [[
    'entry_time' => '2026-11-23 09:00:00',
    'status' => 'Inside campus',
    'visitor_address' => '12 Sample Street',
]];
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
ob_start();
require __DIR__ . '/../templates/visitor_history.php';
$historyResultsHtml = ob_get_clean();
restore_error_handler();
$assert(
    str_contains($historyResultsHtml, 'Test Visitor') &&
        str_contains($historyResultsHtml, 'Not exited') &&
        str_contains($historyResultsHtml, '12 Sample Street'),
    'Visitor history safely renders results with optional fields missing'
);

$visit = null;
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
ob_start();
require __DIR__ . '/../templates/visitor_pass.php';
$emptyPassHtml = ob_get_clean();
restore_error_handler();
$assert(
    str_contains($emptyPassHtml, 'QR reference is unavailable for this visit.') &&
        !str_contains($emptyPassHtml, '<div id="visitorQr"'),
    'Visitor pass renders safely when visit and QR data are missing'
);

$visit = [
    'visit_id' => 42,
    'pass_reference' => 'SG-TEST42',
    'visitor_name' => 'Test Visitor',
    'destination' => 'Reception',
    'purpose' => 'Meeting',
    'entrance_name' => ENTRANCES[0],
    'entry_time' => '2026-11-23 09:00:00',
    'status' => 'Inside campus',
];
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
ob_start();
require __DIR__ . '/../templates/visitor_pass.php';
$passHtml = ob_get_clean();
restore_error_handler();
$assert(
    str_contains($passHtml, 'SG-TEST42') &&
        str_contains($passHtml, 'Test Visitor') &&
        str_contains($passHtml, 'text: "SG-TEST42"'),
    'Visitor pass renders visit details and QR reference'
);

fwrite(STDOUT, "{$passed} tests passed.\n");
