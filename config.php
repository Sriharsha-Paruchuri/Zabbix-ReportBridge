<?php

/* ---------------------------------------
      TLS / SSL VERIFICATION
      Set to true for production use with valid certificates.
      Only set to false if your Zabbix instance uses a
      self-signed certificate you understand the risk of trusting.
   --------------------------------------- */
define('ZBX_VERIFY_SSL', false);
// CONFIGURATION FILE

date_default_timezone_set('Asia/Kolkata');
error_reporting(E_ALL);
ini_set('display_errors', 0);

/* Log errors */
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php_errors.log');

/* Create logs folder */
if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}

/* ---------------------------------------
   AUTO LOGOUT AFTER 30 MINUTES
--------------------------------------- */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$timeout = 1800; // 30 minutes = 1800 sec

if (isset($_SESSION['LAST_ACTIVITY'])) {

    if (time() - $_SESSION['LAST_ACTIVITY'] > $timeout) {

        /* Delete saved graph cookie file */
        if (isset($_SESSION['cookie']) && file_exists($_SESSION['cookie'])) {
            unlink($_SESSION['cookie']);
        }

        /* Destroy session */
        $_SESSION = [];
        session_unset();
        session_destroy();

        /* Delete PHP session cookie */
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }

        header("Location: index.php?timeout=1");
        exit;
    }
}

/* Update activity time */
$_SESSION['LAST_ACTIVITY'] = time();
?>