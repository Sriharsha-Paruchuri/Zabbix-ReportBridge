<?php
include 'config.php';


$url  = rtrim($_POST['url'], '/');
$user = trim($_POST['username']);
$pass = $_POST['password'];

/* ── VALIDATE URL FORMAT ── */
if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
    die("Invalid Zabbix URL. Please enter a full URL starting with http:// or https://");
}

/* ── VERIFY THIS IS ACTUALLY A ZABBIX API ── */
$versionCheck = json_encode([
    "jsonrpc" => "2.0",
    "method"  => "apiinfo.version",
    "params"  => [],
    "id"      => 1
]);

$ch = curl_init($url . '/api_jsonrpc.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, $versionCheck);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, ZBX_VERIFY_SSL);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, ZBX_VERIFY_SSL ? 2 : 0);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

$versionResponse = curl_exec($ch);
$curlError       = curl_error($ch);
curl_close($ch);

if ($versionResponse === false) {
    die("Could not reach a Zabbix server at that URL. Please check the address and try again. (" . htmlspecialchars($curlError) . ")");
}

$versionData = json_decode($versionResponse, true);

if (!isset($versionData['result']) || !preg_match('/^\d+\.\d+/', $versionData['result'])) {
    die("The URL you entered doesn't appear to be a Zabbix server. Please double-check the address.");
}

/* ---------------------------------------
   API LOGIN
--------------------------------------- */
$payload = json_encode([
    "jsonrpc" => "2.0",
    "method"  => "user.login",
    "params"  => [
        "username" => $user,
        "password" => $pass
    ],
    "id" => 1
]);

$api = $url . '/api_jsonrpc.php';

$ch = curl_init($api);

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
// curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
// curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, ZBX_VERIFY_SSL);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, ZBX_VERIFY_SSL ? 2 : 0);


$response = curl_exec($ch);
error_log("CURL ERROR: " . curl_error($ch));
error_log("HTTP CODE: " . curl_getinfo($ch, CURLINFO_HTTP_CODE));
curl_close($ch);

$data = json_decode($response, true);

if (!isset($data['result'])) {
    die("API Login failed.");
}

$_SESSION['token'] = $data['result'];
$_SESSION['url']   = $url;

/* ---------------------------------------
   FRONTEND UI LOGIN (For Graph Cookie)
--------------------------------------- */

// $cookie = __DIR__ . '/cookie_' . md5($user) . '.txt';
$cookie = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zbx_cookie_' . md5($user) . '.txt';
$cookie = $_SESSION['cookie'];

$cookieDir = __DIR__ . '/tmp';

if (!is_dir($cookieDir)) {
    mkdir($cookieDir, 0777, true);
}

$_SESSION['cookie'] = $cookieDir . '/zbx_cookie_' . md5(session_id()) . '.txt';

$cookie = $_SESSION['cookie'];

$loginUrl = $url . '/index.php';

/* Step 1: GET the login page first — this establishes a session cookie
   and gives us the per-session "sid" CSRF token that Zabbix requires
   on the login POST. Without this, the POST below gets silently
   rejected back to the login form even though it returns HTTP 200. */
$ch = curl_init($loginUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, ZBX_VERIFY_SSL);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, ZBX_VERIFY_SSL ? 2 : 0);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');

$loginPage = curl_exec($ch);
curl_close($ch);

/* DEBUG: always dump the initial GET response so we can see what the
   real login page markup looks like on this Zabbix version. Remove
   this once diagnosed. */
@file_put_contents(
    sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zbx_debug_01_get_loginpage.html',
    (string)$loginPage
);

$sid = '';
if (is_string($loginPage) && preg_match('/name="sid"\s+value="([a-f0-9]+)"/', $loginPage, $m)) {
    $sid = $m[1];
}
error_log("Frontend login: sid " . ($sid !== '' ? "found ($sid)" : "NOT FOUND in login page markup"));

/* Step 2: POST credentials, including the sid token if we found one */
$postFields = http_build_query(array_filter([
    'name'     => $user,
    'password' => $pass,
    'enter'    => 'Sign in',
    'sid'      => $sid
], fn($v) => $v !== ''));

$ch = curl_init($loginUrl);

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
// curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
// curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, ZBX_VERIFY_SSL);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, ZBX_VERIFY_SSL ? 2 : 0);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');

$res = curl_exec($ch);
$frontendHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$frontendFinalUrl  = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
curl_close($ch);

error_log("Frontend login POST: HTTP $frontendHttpCode, final URL after redirects: $frontendFinalUrl");

/* DEBUG: always dump the POST response too — no more guessing based on
   substring heuristics, just look at the file directly. Remove this
   block once diagnosed. */
@file_put_contents(
    sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zbx_debug_02_post_result.html',
    (string)$res
);

/* Also dump the cookie jar contents so we can see what Zabbix actually
   set (or didn't set) for this session. */
@copy($cookie, sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zbx_debug_03_cookiejar.txt');

error_log("Frontend login debug files written to: " . sys_get_temp_dir());

/* Save cookie path to session */
$_SESSION['cookie'] = $cookie;

/* ---------------------------------------
   SUCCESS
--------------------------------------- */
header("Location: dashboard.php");
exit;
?>