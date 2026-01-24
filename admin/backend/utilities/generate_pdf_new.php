<?php
// generate_pdf_stream_safe.php

declare(strict_types=1);

// --------------------------------------
// BOOTSTRAP & SECURITY
// --------------------------------------
require_once __DIR__ . '/../../utilities/config.php';
require_once __DIR__ . '/../../utilities/utils.php';

session_start();

function abort(string $message, int $code = 400): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
}

if (!isset($_SESSION['unique_id'], $_SESSION['role'])) {
    abort('Unauthorized', 401);
}

if (!in_array($_SESSION['role'], ['Super Admin', 'Admin'], true)) {
    abort('Access denied', 403);
}

// --------------------------------------
// TCPDF CHECK
// --------------------------------------
$tcpdfPath = __DIR__ . '/../../vendor/tecnickcom/tcpdf/tcpdf.php';
if (!file_exists($tcpdfPath)) {
    abort('TCPDF library not found', 500);
}

require_once $tcpdfPath;

// --------------------------------------
// INPUT VALIDATION
// --------------------------------------
$rawInput = file_get_contents('php://input');
if ($rawInput === false || trim($rawInput) === '') {
    abort('No input data provided', 400);
}

$data = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    abort('Invalid JSON payload', 400);
}

if (
    !isset($data['data']['columns']) ||
    !isset($data['data']['data']) ||
    !is_array($data['data']['columns']) ||
    !is_array($data['data']['data'])
) {
    abort('Invalid data structure', 422);
}

// --------------------------------------
// SAFE RUNTIME SETTINGS
// --------------------------------------
ini_set('memory_limit', '512M');
set_time_limit(0);

// --------------------------------------
// PDF SETUP
// --------------------------------------
$pdf = new TCPDF('L', PDF_UNIT, 'A4', true, 'UTF-8', false);

$pdf->SetCreator('Property Management System');
$pdf->SetAuthor('Admin');
$pdf->SetTitle('Query Report');

$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetAutoPageBreak(true, 15);
$pdf->setFontSubsetting(true);

// --------------------------------------
// LAYOUT SETTINGS
// --------------------------------------
$columns       = $data['data']['columns'];
$totalColumns = count($columns);
$pageWidth    = 270; // usable width in A4 landscape
$colWidth     = $pageWidth / max($totalColumns, 1);

$rowsPerPage = 35;     // SAFE number
$maxRows     = 1000;   // HARD LIMIT

// --------------------------------------
// HELPER: TABLE HEADER
// --------------------------------------
function drawTableHeader(TCPDF $pdf, array $columns, float $colWidth): void
{
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetFillColor(240, 240, 240);

    foreach ($columns as $header) {
        $pdf->Cell(
            $colWidth,
            7,
            mb_substr((string)$header, 0, 20),
            1,
            0,
            'L',
            true
        );
    }
    $pdf->Ln();
}

// --------------------------------------
// PDF CONTENT
// --------------------------------------
$pdf->AddPage();

// Title
$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 10, 'Query Report', 0, 1, 'C');

$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(0, 6, 'Generated: ' . date('Y-m-d H:i:s'), 0, 1);
$pdf->Cell(0, 6, 'Rows: ' . ($data['data']['row_count'] ?? count($data['data']['data'])), 0, 1);
$pdf->Ln(4);

// Table Header
drawTableHeader($pdf, $columns, $colWidth);

// Table Rows (STREAM-SAFE)
$pdf->SetFont('helvetica', '', 7);

$rowCounter     = 0;
$pageRowCounter = 0;

foreach ($data['data']['data'] as $row) {

    if ($rowCounter >= $maxRows) {
        break;
    }

    if ($pageRowCounter >= $rowsPerPage) {
        $pdf->AddPage();
        drawTableHeader($pdf, $columns, $colWidth);
        $pageRowCounter = 0;
    }

    foreach ($columns as $column) {
        $value = $row[$column] ?? '';
        $pdf->Cell(
            $colWidth,
            6,
            mb_substr((string)$value, 0, 30),
            1,
            0,
            'L'
        );
    }

    $pdf->Ln();
    $rowCounter++;
    $pageRowCounter++;
}

// Truncation Notice
$totalRows = $data['data']['row_count'] ?? count($data['data']['data']);
if ($totalRows > $maxRows) {
    $pdf->Ln(5);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->Cell(
        0,
        6,
        "Note: Showing first {$maxRows} rows of {$totalRows} total.",
        0,
        1
    );
}

// --------------------------------------
// CLEAN OUTPUT BUFFER (CRITICAL)
// --------------------------------------
if (ob_get_length()) {
    ob_end_clean();
}

// --------------------------------------
// OUTPUT
// --------------------------------------
$filename = 'report_' . date('Ymd_His') . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

$pdf->Output($filename, 'D');
exit;
