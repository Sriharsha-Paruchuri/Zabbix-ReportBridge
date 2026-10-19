<?php
/**
 * download_excel.php
 * Called when user clicks "Download Excel File" in the modal after 'both' export
 * Serves the pre-generated Excel/CSV from the session path.
 */
session_start();

if (!isset($_SESSION['xlsx_path']) || !file_exists($_SESSION['xlsx_path'])) {
    die("No Excel file available. Please generate the report again.");
}

$file     = $_SESSION['xlsx_path'];
$filename = $_SESSION['xlsx_filename'] ?? 'Availability.xlsx';

$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
if ($ext === 'xlsx') {
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
} else {
    header('Content-Type: text/csv');
}

header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Content-Length: ' . filesize($file));

readfile($file);

// Clean up
unlink($file);
unset($_SESSION['xlsx_path'], $_SESSION['xlsx_filename']);
exit;
?>