<?php
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

while (ob_get_level()) {
    ob_end_clean();
}
session_start();

if (!isset($_SESSION['token'])) {
    http_response_code(403);
    echo "data: " . json_encode(['error' => 'Not authenticated']) . "\n\n";
    exit;
}

$jobId = isset($_SESSION['current_job']) ? $_SESSION['current_job'] : null;

if (!$jobId) {
    http_response_code(400);
    echo "data: " . json_encode(['error' => 'No active job']) . "\n\n";
    exit;
}

session_write_close();

set_time_limit(0);
ini_set('max_execution_time', 0);
ignore_user_abort(true);

// Flush output buffering for real-time updates
if (ob_get_level() == 0) ob_start();

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Accel-Buffering: no');

echo ": SSE connection established\n\n";
ob_flush();
flush();

// Use Windows temp directory
if (function_exists('sys_get_temp_dir')) {
    $tempDir = sys_get_temp_dir();
} else {
    $tempDir = getenv('TEMP');
    if (!$tempDir) $tempDir = getenv('TMP');
    if (!$tempDir) $tempDir = 'C:\\tmp';
}

$progressFile = $tempDir . DIRECTORY_SEPARATOR . 'zbx_progress_' . $jobId . '.json';

$sent = -1;
$timeout = 1800;
$start = time();

while (true) {
    if (time() - $start > $timeout) {
        echo "data: " . json_encode(['status' => 'timeout', 'message' => 'Timeout', 'pct' => 0]) . "\n\n";
        ob_flush();
        flush();
        break;
    }

    if (!file_exists($progressFile)) {
        echo "data: " . json_encode(['status' => 'waiting', 'message' => 'Starting...', 'pct' => 0]) . "\n\n";
        ob_flush();
        flush();
        sleep(1);
        continue;
    }

    $fileContent = @file_get_contents($progressFile);
    
    if ($fileContent === false) {
        sleep(1);
        continue;
    }

    $data = @json_decode($fileContent, true);
    
    if (!$data || !is_array($data)) {
        sleep(1);
        continue;
    }

    $idx = isset($data['idx']) ? $data['idx'] : 0;

    if ($idx !== $sent) {
        $sent = $idx;
        
        if (isset($data['status']) && $data['status'] === 'done') {
            $data['download_url'] = 'download_report.php?job_id=' . urlencode($jobId);
        }
        
        echo "data: " . json_encode($data) . "\n\n";
        ob_flush();
        flush();
    }

    if (isset($data['status']) && ($data['status'] === 'done' || $data['status'] === 'error')) {
        sleep(1);
        @unlink($progressFile);
        break;
    }

    sleep(1);
}

// Clean shutdown
while (ob_get_level()) {
    ob_end_flush();
}
?>