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
$visitorController = file_get_contents(__DIR__ . '/../index.php');
$mysqlSchema = file_get_contents(__DIR__ . '/../schema_mysql.sql');
$assert(
    is_string($scannerScript) && !str_contains($scannerScript, 'decodeQr') &&
        is_string($scannerLayout) && !str_contains($scannerLayout, 'jsQR.js'),
    'Visitor ID scanner uses printed-text OCR without loading QR decoding'
);
$assert(
    is_string($scannerScript) &&
        !str_contains($scannerScript, 'extractName') &&
        !str_contains($scannerScript, 'extractMobile') &&
        !str_contains($scannerScript, 'extractAddress') &&
        str_contains($scannerScript, 'ocrText.value = text'),
    'Visitor ID OCR transfers unparsed full text to the visit form'
);
$assert(
    is_string($visitorController) &&
        is_string($mysqlSchema) &&
        str_contains($visitorController, 'visitor_id_text') &&
        str_contains($visitorController, "'/record_visit.php'") &&
        str_contains($mysqlSchema, 'visitor_id_text TEXT NULL'),
    'Visitor check-in POST persists the full OCR text in the visit schema'
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

$filters = ['q' => '', 'visit_date' => '', 'status' => '', 'entrance' => ''];
$entrances = ENTRANCES;
$records = [[
    'visitor_name' => null,
    'visitor_id_text' => "FULL NAME: <Test Visitor>\nADDRESS: <Test Address>",
    'mobile_number' => null,
    'visitor_address' => null,
    'destination' => 'Reception',
    'purpose' => 'Meeting',
    'entry_time' => '2026-11-23 09:00:00',
    'exit_time' => null,
    'status' => 'Inside campus',
    'entrance_name' => ENTRANCES[0],
    'entry_guard_name' => 'Test Guard',
    'exit_entrance_name' => null,
    'exit_guard_name' => null,
    'pass_reference' => 'SG-TEST',
]];
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
ob_start();
require __DIR__ . '/../templates/admin_records.php';
$populatedAdminRecordsHtml = ob_get_clean();
restore_error_handler();
$assert(
    str_contains($populatedAdminRecordsHtml, 'ID details recorded') &&
        str_contains($populatedAdminRecordsHtml, 'View saved OCR text') &&
        str_contains($populatedAdminRecordsHtml, '&lt;Test Visitor&gt;') &&
        !str_contains($populatedAdminRecordsHtml, '<Test Visitor>'),
    'Admin records safely render escaped OCR identity when the legacy name is empty'
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
    'visitor_name' => null,
    'mobile_number' => null,
    'visitor_id_text' => "FULL NAME: <Test Visitor>\nADDRESS: <Test Address>",
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
    str_contains($populatedDashboardHtml, 'ID details recorded') &&
        str_contains($populatedDashboardHtml, '&lt;Test Visitor&gt;') &&
        str_contains($populatedDashboardHtml, 'Long-stay alert'),
    'Guard dashboard safely renders raw OCR identity for a name-less visit'
);

$entrances = ENTRANCES;
$entrance = ENTRANCES[0];
$form_data = [
    'visitor_id_text' => "FULL NAME: <Test Visitor>\nADDRESS: <Test Address>",
    'destination' => 'Reception',
    'purpose' => 'Meeting',
];
$errors = ['Confirm that you reviewed the OCR text against the physical ID.'];
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
ob_start();
require __DIR__ . '/../templates/verify_visitor.php';
$visitorFormHtml = ob_get_clean();
restore_error_handler();
$ocrTextareaAttributes = [];
$assert(
    str_contains($visitorFormHtml, "FULL NAME: &lt;Test Visitor&gt;\nADDRESS: &lt;Test Address&gt;") &&
    str_contains($visitorFormHtml, 'id="recordVisitForm"') &&
    str_contains($visitorFormHtml, 'id="ocrRawOutput"') &&
    str_contains($visitorFormHtml, 'action="/record_visit.php?entrance=') &&
    preg_match('/<textarea id="ocrRawOutput"([^>]*)>/', $visitorFormHtml, $ocrTextareaAttributes) === 1 &&
        !str_contains($ocrTextareaAttributes[1], 'readonly') &&
    str_contains($ocrTextareaAttributes[1], 'required') &&
    str_contains($ocrTextareaAttributes[1], 'name="id_details"') &&
    str_contains($visitorFormHtml, 'name="person_visited"') &&
    str_contains($visitorFormHtml, 'name="purpose_of_visit"') &&
    !str_contains($visitorFormHtml, 'name="visitor_name"') &&
    !str_contains($visitorFormHtml, 'name="mobile_number"') &&
    !str_contains($visitorFormHtml, 'name="visitor_address"') &&
    str_contains($visitorFormHtml, 'id="entranceGate" name="entrance"') &&
        str_contains($visitorFormHtml, 'Confirm that you reviewed the OCR text against the physical ID.'),
    'Visitor verification form safely renders an editable, escaped OCR text field submitted with the visit'
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
    str_contains($emptyHistoryHtml, 'Enter a visitor detail or OCR text fragment to search prior visits.'),
    'Visitor history renders an empty state when search data is missing'
);

$name = 'Test Visitor';
$mobile = '';
$history = [[
    'entry_time' => '2026-11-23 09:00:00',
    'status' => 'Inside campus',
    'visitor_id_text' => "FULL NAME: <Test Visitor>\nADDRESS: 12 Sample Street",
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
        str_contains($historyResultsHtml, '&lt;Test Visitor&gt;') &&
        str_contains($historyResultsHtml, '12 Sample Street'),
    'Visitor history safely renders OCR identity for a name-less visit'
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
    'visitor_name' => null,
    'visitor_id_text' => "FULL NAME: <Test Visitor>\nADDRESS: 12 Sample Street",
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
        str_contains($passHtml, 'ID details recorded') &&
        str_contains($passHtml, '&lt;Test Visitor&gt;') &&
        str_contains($passHtml, 'text: "SG-TEST42"'),
    'Visitor pass renders raw OCR identity and QR reference for a name-less visit'
);

fwrite(STDOUT, "{$passed} tests passed.\n");
