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
        str_contains($scannerScript, 'function parseIdText(text)') &&
        !str_contains($scannerScript, 'ocrRawOutput') &&
        !str_contains($scannerScript, 'ocrText.value') &&
        str_contains($scannerScript, 'visitorName.value = parsed.name') &&
        str_contains($scannerScript, 'visitorMobile.value = parsed.mobile') &&
        str_contains($scannerScript, 'visitorAddress.value = parsed.address') &&
        str_contains($scannerScript, 'visitorAddress.value = ""') &&
        str_contains($scannerScript, 'Could not read the ') &&
        str_contains($scannerScript, 'The address must be detected by scanning the ID.') &&
        str_contains($scannerScript, 'createWorker') &&
        str_contains($scannerScript, 'getImageData') &&
        str_contains($scannerScript, 'visibilitychange'),
    'Visitor ID OCR clears old fields and requires a scanned address'
);
$assert(
    is_string($scannerScript) &&
        str_contains($scannerScript, '"Residential Address"') &&
        str_contains($scannerScript, '"Addres"') &&
        str_contains($scannerScript, '"Given Name"') &&
        str_contains($scannerScript, '(?:/|[1lI|])') &&
        str_contains($scannerScript, 'function buildFieldCrop(sourceCanvas, words, labelPattern, cuePattern, linesBelow, fieldName)') &&
        str_contains($scannerScript, 'function buildNameCrop(sourceCanvas, words)') &&
        str_contains($scannerScript, 'const addressLinePattern') &&
        str_contains($scannerScript, 'const repeatedAddressLabel = candidate.match(addressLinePattern)') &&
        str_contains($scannerScript, 'function extractUnlabeledAddress(lines)') &&
        str_contains($scannerScript, 'addressCuePattern') &&
        str_contains($scannerScript, 'address: extractAddress(lines)') &&
        str_contains($scannerScript, 'function buildAddressCrop(sourceCanvas, words)') &&
        str_contains($scannerScript, 'function makeBinaryOcrCanvas(sourceCanvas)') &&
        str_contains($scannerScript, 'const histogram = new Uint32Array(256)') &&
        str_contains($scannerScript, 'const ocrAttempts = [') &&
        str_contains($scannerScript, 'tessedit_pageseg_mode: attempt.mode') &&
        str_contains($scannerScript, 'function fieldCandidateScore(field, value, text, confidence, region)') &&
        str_contains($scannerScript, 'considerCandidate(candidateText, Number(result.data.confidence), "address")'),
    'Visitor ID OCR uses address parsing and image-processing fallbacks'
);
$assert(
    is_string($visitorController) &&
        is_string($mysqlSchema) &&
        str_contains($visitorController, 'ALTER TABLE visitor_log_entries DROP COLUMN visitor_id_text') &&
        !str_contains($visitorController, '(pass_reference, visitor_name, mobile_number, visitor_address, visitor_id_text') &&
        !str_contains($visitorController, 'id_details') &&
        str_contains($visitorController, '$formData[\'visitor_name\']') &&
        str_contains($visitorController, '$formData[\'mobile_number\']') &&
        str_contains($visitorController, '$formData[\'visitor_address\']') &&
        str_contains($visitorController, "'/record_visit.php'") &&
        !str_contains($mysqlSchema, 'visitor_id_text TEXT NULL'),
    'Visitor check-in stores reviewed fields and removes the full OCR text column'
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
    str_contains($populatedAdminRecordsHtml, 'Name not recorded') &&
        !str_contains($populatedAdminRecordsHtml, 'FULL NAME:') &&
        !str_contains($populatedAdminRecordsHtml, 'View saved OCR text'),
    'Admin records do not render legacy full OCR text'
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
    str_contains($populatedDashboardHtml, 'Name not recorded') &&
        !str_contains($populatedDashboardHtml, 'FULL NAME:') &&
        !str_contains($populatedDashboardHtml, 'View saved OCR text') &&
        str_contains($populatedDashboardHtml, 'Long-stay alert'),
    'Guard dashboard does not render legacy full OCR text'
);

$entrances = ENTRANCES;
$entrance = ENTRANCES[0];
$form_data = [
    'visitor_name' => 'Test Visitor',
    'mobile_number' => '09171234567',
    'visitor_address' => '12 Sample Street',
    'destination' => 'Reception',
    'purpose' => 'Meeting',
];
$errors = ['Confirm that you reviewed the visitor details against the physical ID.'];
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
ob_start();
require __DIR__ . '/../templates/verify_visitor.php';
$visitorFormHtml = ob_get_clean();
restore_error_handler();
$addressTextareaAttributes = [];
$assert(
    str_contains($visitorFormHtml, 'id="recordVisitForm"') &&
    !str_contains($visitorFormHtml, 'ocrRawOutput') &&
    !str_contains($visitorFormHtml, 'id_details') &&
    !str_contains($visitorFormHtml, 'complete OCR text') &&
    str_contains($visitorFormHtml, 'action="/record_visit.php?entrance=') &&
    str_contains($visitorFormHtml, 'id="visitorName" name="visitor_name"') &&
    str_contains($visitorFormHtml, 'id="visitorMobile" name="mobile_number"') &&
    str_contains($visitorFormHtml, 'id="visitorAddress" name="visitor_address"') &&
    preg_match('/<textarea id="visitorAddress"([^>]*)>/', $visitorFormHtml, $addressTextareaAttributes) === 1 &&
        str_contains($addressTextareaAttributes[1], 'required') &&
        str_contains($addressTextareaAttributes[1], 'readonly') &&
        str_contains($visitorController, "\$formData['visitor_address'] === ''") &&
        str_contains($visitorController, 'Visitor address is required; scan the ID to detect it.') &&
    str_contains($visitorFormHtml, 'id="cameraMessage" aria-live="polite"') &&
    str_contains($visitorFormHtml, 'id="scanMessage" aria-live="polite"') &&
    str_contains($visitorFormHtml, '<progress id="ocrProgress" max="100"') &&
    str_contains($visitorFormHtml, 'id="idVideo" autoplay muted playsinline') &&
    str_contains($visitorFormHtml, 'name="person_visited"') &&
    str_contains($visitorFormHtml, 'name="purpose_of_visit"') &&
    str_contains($visitorFormHtml, 'id="entranceGate" name="entrance"') &&
        str_contains($visitorFormHtml, 'name="visitor_details_confirmed"') &&
        str_contains($visitorFormHtml, 'I reviewed the visitor name, mobile number, and address against the physical ID.'),
    'Visitor verification form submits reviewed fields and requires an address'
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
    str_contains($emptyHistoryHtml, 'Enter visitor details to search prior visits.') &&
        !str_contains($emptyHistoryHtml, 'OCR text'),
    'Visitor history renders an empty state when search data is missing'
);

$name = 'Test Visitor';
$mobile = '';
$history = [[
    'entry_time' => '2026-11-23 09:00:00',
    'status' => 'Inside campus',
    'visitor_name' => 'Test Visitor',
    'mobile_number' => '09171234567',
    'visitor_address' => '12 Sample Street',
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
        str_contains($historyResultsHtml, '09171234567') &&
        str_contains($historyResultsHtml, '12 Sample Street') &&
        str_contains($historyResultsHtml, 'Not exited') &&
        !str_contains($historyResultsHtml, 'FULL NAME:') &&
        !str_contains($historyResultsHtml, 'View saved OCR text'),
    'Visitor history renders only the reviewed identity fields'
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
    str_contains($emptyPassHtml, 'Visitor record') &&
        str_contains($emptyPassHtml, 'Name not recorded') &&
        !str_contains($emptyPassHtml, 'visitorQr') &&
        !str_contains($emptyPassHtml, 'QRCode'),
    'Visitor record renders safely without a QR code or visit data'
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
    'exit_entrance_name' => '',
    'exit_time' => '',
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
    !str_contains($passHtml, 'SG-TEST42') &&
        str_contains($passHtml, 'Visitor record') &&
        str_contains($passHtml, 'Reception') &&
        str_contains($passHtml, 'Meeting') &&
        str_contains($passHtml, 'Nov 23, 2026, 9:00 AM') &&
        str_contains($passHtml, 'Check-out time') &&
        str_contains($passHtml, 'Not checked out') &&
        str_contains($passHtml, 'Name not recorded') &&
        !str_contains($passHtml, 'FULL NAME:') &&
        !str_contains($passHtml, 'View saved OCR text') &&
        !str_contains($passHtml, 'visitorQr') &&
        !str_contains($passHtml, 'QRCode'),
    'Visitor record shows active visits without a checkout time'
);

$visit['exit_entrance_name'] = ENTRANCES[1];
$visit['exit_time'] = '2026-11-23 10:15:00';
$visit['status'] = 'Exited';
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
ob_start();
require __DIR__ . '/../templates/visitor_pass.php';
$checkedOutPassHtml = ob_get_clean();
restore_error_handler();
$assert(
    str_contains($checkedOutPassHtml, 'Nov 23, 2026, 10:15 AM') &&
        str_contains($checkedOutPassHtml, 'Exit entrance') &&
        str_contains($checkedOutPassHtml, ENTRANCES[1]),
    'Visitor record shows checkout time and exit entrance when available'
);

fwrite(STDOUT, "{$passed} tests passed.\n");
