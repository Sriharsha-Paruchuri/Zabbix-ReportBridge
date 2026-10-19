# PHP configuration notes for Zabbix ReportBridge

Do not replace your full `php.ini` with a developer machine's configuration.

## Extensions to check

The project setup currently lists these extensions:

```ini
extension=curl
extension=gd
extension=mbstring
extension=openssl
extension=fileinfo
extension=zip
```

These lines are examples only. Check your existing `php.ini`, enable only extensions required by the application/dependencies, and avoid duplicate entries.

## Security settings for a shared/production-facing server

```ini
display_errors=Off
display_startup_errors=Off
log_errors=On
```

Set a suitable error-log path and restrict access to it. Review resource limits according to the server's capacity and the report size.

## XAMPP

1. Back up the current `php.ini`.
2. In XAMPP Control Panel, choose Apache > Config > PHP (php.ini).
3. Review the extension settings and local paths.
4. Restart Apache.
5. Verify the web-server PHP configuration and CLI configuration separately (`php --ini`, `php -m`).

The original developer `php.ini` contains machine-specific `C:\xampp\...` paths, high resource limits, development error display settings, and legacy directives. Do not copy those settings blindly.
