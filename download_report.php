<?php
$jobId = $_GET['job_id'] ?? '';

if (!$jobId) exit('Missing job id');

$file = sys_get_temp_dir() . '/zbx_download_' . $jobId . '.json';

$wait = 0;
while (!file_exists($file) && $wait < 10) {
    sleep(1);
    $wait++;
}

if (!file_exists($file)) {
    exit('File not ready. Please retry.');
}

$data = json_decode(file_get_contents($file), true);

if (!$data) {
    exit('Invalid download file.');
}

/* Excel first */
if (!empty($data['excel_path']) && file_exists($data['excel_path'])) {

    while (ob_get_level()) ob_end_clean();

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . ($data['excel_name'] ?? 'Report.xlsx') . '"');
    header('Content-Length: ' . filesize($data['excel_path']));
    readfile($data['excel_path']);
    exit;
}

/* PDF */
if (!empty($data['pdf_path']) && file_exists($data['pdf_path'])) {

    while (ob_get_level()) ob_end_clean();

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . ($data['pdf_name'] ?? 'Report.pdf') . '"');
    header('Content-Length: ' . filesize($data['pdf_path']));
    readfile($data['pdf_path']);
    exit;
}

exit('Generated file missing.');
?>