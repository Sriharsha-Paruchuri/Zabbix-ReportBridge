<?php
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['token'])) {
    die(json_encode([
        'success' => false,
        'error' => 'Session expired'
    ]));
}

// CSRF check — this is the real HTTP entry point from the browser
if (!isset($_SESSION['csrf_token']) || !isset($_POST['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    die(json_encode([
        'success' => false,
        'error' => 'Invalid request. Please refresh the dashboard and try again.'
    ]));
}

$jobId = uniqid('job_');

$tempDir = sys_get_temp_dir();

$jobFile = $tempDir . DIRECTORY_SEPARATOR . 'zbx_job_' . $jobId . '.json';
$sessionFile = $tempDir . DIRECTORY_SEPARATOR . 'zbx_session_' . $jobId . '.json';

file_put_contents($jobFile, json_encode($_POST));

file_put_contents($sessionFile, json_encode([
    'url'    => $_SESSION['url'],
    'token'  => $_SESSION['token'],
    'cookie' => $_SESSION['cookie'] ?? ''
]));

$_SESSION['current_job'] = $jobId;
$_SESSION['avail_export'] = $_POST['avail_export'] ?? 'pdf';
session_write_close();

$phpPath = 'C:\\xampp\\php\\php.exe';
$scriptPath = __DIR__ . '\\generate.php';

if (!file_exists($phpPath)) {
    die(json_encode([
        'success' => false,
        'error' => 'php.exe not found'
    ]));
}

if (!file_exists($scriptPath)) {
    die(json_encode([
        'success' => false,
        'error' => 'generate.php not found'
    ]));
}

$cmd = 'start /B "" "' . $phpPath . '" "' . $scriptPath . '" "' . $jobId . '"';

pclose(popen($cmd, 'r'));

echo json_encode([
    'success' => true,
    'job_id' => $jobId
]);
exit;
?>