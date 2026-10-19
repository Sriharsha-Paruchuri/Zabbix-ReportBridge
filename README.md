# Zabbix ReportBridge

**Generate PDF and Excel monitoring reports from a reachable Zabbix instance.**

Zabbix ReportBridge is a self-hosted PHP web application for administrators, managed service providers (MSPs), and NOC teams that need a repeatable way to generate client-ready monitoring reports from different Zabbix servers.

Enter the Zabbix URL and credentials at runtime, select host groups or individual hosts, choose a reporting period and report sections, then generate a report with supported trigger, availability, and graph information.

> **Project status:** Community project / early public release. Test in a non-production environment before operational use.
>
> **AI-assisted development:** This project was developed iteratively with AI assistance and refined through human testing and decisions. It has not undergone a formal independent security audit. Review the code and test it in your own environment before using it with sensitive infrastructure.
>
> **Trademark notice:** This is an independent community project. It is not affiliated with, endorsed by, or supported by Zabbix LLC. “Zabbix” is a trademark of Zabbix LLC.

## Features

- **Runtime Zabbix connection:** Enter a Zabbix URL and credentials when logging in; the application is not intended to be tied to a single Zabbix server.
- **API endpoint check:** Checks that the supplied endpoint responds as a Zabbix API.
- **Flexible targeting:** Select host groups or individual hosts using the application's selection controls.
- **Time ranges:** Choose a supported preset or a custom date/time range.
- **PDF reporting:** Generate a PDF with supported report sections such as triggers, availability, and graphs.
- **Availability export:** Export the availability report separately to Excel where supported; CSV fallback may be available if the spreadsheet dependency is unavailable.
- **Graph categories:** Includes categories such as CPU, memory, disk, ping/ICMP, network traffic, and other graphs, subject to the items, templates, data, and permissions available in Zabbix.
- **Progress feedback:** Background report generation and progress display are provided by the application.
- **Bundled libraries:** TCPDF and spreadsheet-related files may be included in the repository. See the license notices shipped with each third-party component.

Feature behavior depends on the Zabbix version, user permissions, PHP setup, available monitoring items, and the selected report options. Validate output against your own Zabbix environment.

## How availability is calculated

The current project documentation describes the following availability-source order:

1. ICMP Ping (`icmpping`)
2. Agent Ping (`agent.ping`)
3. System uptime (`system.uptime`)
4. Trigger-based estimate as a fallback when suitable item data is unavailable

For recent periods, the application may use history data; for older periods, it may use trends where available. Check the generated report and test this behavior against your Zabbix housekeeping and data-retention settings.

## Screenshots

Add screenshots of the dashboard, report-generation progress, a sample PDF page, and the Excel export here. Use sanitized demo data only; do not publish customer names, hostnames, IP addresses, URLs, graphs, or credentials from a real environment.

| Dashboard | Sample PDF | Excel export |
|---|---|---|
| _Add screenshot_ | _Add screenshot_ | _Add screenshot_ |

## Requirements

| Requirement | Notes |
|---|---|
| Zabbix | Developed for and tested against Zabbix 7.x; other versions should be treated as unverified until tested. |
| PHP | PHP 8.x is recommended; the existing project has been tested with PHP 8.2 on XAMPP. Confirm compatibility with your deployed version. |
| Web server | Current background-generation implementation is Windows/XAMPP-oriented. See [Known limitations](#known-limitations). |
| PHP extensions | Enable the extensions required by the app and its bundled libraries. The supplied PHP configuration currently lists `curl`, `gd`, `mbstring`, `openssl`, `fileinfo`, and `zip`; confirm which are required by the exact version you deploy. |
| Zabbix account | Use an account with API access and read permissions for the groups, hosts, items, history, trends, triggers, and graphs needed by your selected reports. |
| Browser/network | The web server must be able to reach the chosen Zabbix URL and the user's browser must be able to reach the ReportBridge web server. |

TCPDF and spreadsheet-related libraries are included in the project where noted. Composer may not be required for the current bundled setup; preserve the third-party license files when redistributing.

## Installation: Windows with XAMPP

1. Install a compatible PHP/XAMPP distribution and confirm the PHP version.
2. Clone the repository into the XAMPP web root, or download and extract a release. For example:

   ```powershell
   cd C:\xampp\htdocs
   git clone https://github.com/<YOUR-GITHUB-USERNAME>/Zabbix-ReportBridge.git
   ```

   Replace the placeholder with the actual repository URL. If you downloaded a ZIP, extract the project folder into `C:\xampp\htdocs\`.

3. Review the project files before running them. Do not deploy an unreviewed public repository directly into a production environment.
4. Open the XAMPP Control Panel and start Apache.
5. Browse to the matching local URL, for example:

   `http://localhost/Zabbix-ReportBridge/`

6. Sign in using the Zabbix URL and credentials for the Zabbix instance you want to report on.
7. Run a small test report first and compare its output with the Zabbix UI.

### PHP configuration: do not replace your entire `php.ini`

The `php.ini` uploaded during project preparation is a full, machine-specific XAMPP/PHP configuration, not a portable application configuration. **Do not ask users to replace their existing `php.ini` with that file.** It includes local paths such as `C:\xampp\php\ext`, `C:\xampp\tmp`, and `C:\xampp\apache\bin\curl-ca-bundle.crt`, as well as settings that may be outdated or unsuitable for production.

Instead:

1. In XAMPP Control Panel, open **Apache → Config → PHP (php.ini)**.
2. Back up the existing file before editing it.
3. Enable only the extensions required by the application and its bundled dependencies. The current configuration lists `curl`, `gd`, `mbstring`, `openssl`, `fileinfo`, and `zip`. Avoid duplicate extension entries; confirm each extension is actually loaded.
4. Check the active configuration with `php --ini` and `php -m` for the PHP CLI used by background report generation. The PHP used by Apache and the PHP CLI can load different configuration files.
5. Adjust resource limits only when required by your workload. Do not copy the very high memory, upload, or POST limits from a development machine without considering the server's capacity and exposure.
6. Restart Apache after changes and test a small report.

The supplied `php.ini` has `display_errors=On` and `display_startup_errors=On`. For any shared or production-facing installation, set these to `Off` and keep `log_errors=On`. Review the configured log path and protect log files from web access. The supplied file also includes legacy/deprecated directives and a `Europe/Berlin` timezone setting; do not copy these blindly. Set the timezone to the one appropriate for your environment only if the application requires it.

### Background report generation and PHP path

The current implementation launches the PHP CLI using a Windows/XAMPP-specific path (`C:\xampp\php\php.exe`) and Windows background-process behavior. If your PHP installation is in a different location, review `generate_async.php` and update the path to the correct PHP CLI executable. Keep the web-server PHP and CLI PHP versions/configuration aligned and test background generation after making changes.

## Self-signed Zabbix certificates and TLS verification

TLS certificate verification should remain enabled whenever possible. If the Zabbix server uses a self-signed certificate or an internal certificate authority (CA), the preferred solution is to configure PHP/cURL to trust the correct certificate or CA certificate.

The application may provide a `ZBX_VERIFY_SSL` configuration option. If you deliberately set it to `false` to work around a self-signed certificate, be aware that this disables certificate verification and exposes the connection to man-in-the-middle attacks. Use this only as a temporary, controlled exception on a trusted network; do not present it as a secure default. Never disable verification when connecting across untrusted networks.

The `curl.cainfo` and `openssl.cafile` values in the supplied `php.ini` point to a local XAMPP certificate-bundle path. That path may not exist on another installation. Use a valid CA bundle path for your environment or rely on the platform's supported certificate store behavior.

## 🎨 Customization and Branding

Zabbix ReportBridge can be customized to match your organization's branding before deployment.

### 1. Replace the Logo

Replace the default logo in the `logos/` folder with your organization's logo.

- Keep the existing filename and file extension if you do not want to modify the application code.
- If you use a different filename or location, update the corresponding references in the PHP files.
- Check the generated PDF reports and application pages to ensure the logo displays correctly.

**Recommended:** Keep a backup of the original logo before replacing it.

### 2. Update Company Name and Branding Text

Search the project files for company-specific names, logos, headers, footers, report titles, and other branding text.

Update the relevant references to match your organization. Depending on your deployment, these may be located in files such as:

- `generate.php` — PDF cover page, report headers, and branding.
- `dashboard.php` — dashboard headings and descriptive text.
- `index.php` — login page title and branding.
- Other PHP files that generate reports or display organization-specific text.

Not every installation will require changes to every file. Review each occurrence before editing it, and preserve the application's functionality.

### 3. Verify Your Changes

After customization:

1. Save all modified files.
2. Open the application and verify that the logo and organization name display correctly.
3. Generate a sample PDF report and check its cover page, headers, and footers.
4. Confirm that no unwanted placeholder names or branding remain.

**Note:** Customize only the branding you are authorized to use. Zabbix ReportBridge is an independent community project and is not an official Zabbix product.

## Usage

1. Open ReportBridge in a browser.
2. Enter the URL and credentials for the Zabbix instance to report on.
3. Confirm the target server and continue through any application disclaimer.
4. Enter a report title.
5. Select host groups or individual hosts, as supported by the UI.
6. Choose a preset or custom reporting period.
7. Select the available report sections, such as triggers, availability, and graphs.
8. Choose the applicable export option.
9. Start report generation and wait for the progress display to finish.
10. Review the generated report before distributing it.

For graphs to appear, the relevant Zabbix items, graph definitions, templates, history/trend data, and permissions must be available for the selected hosts. A separate Excel export, where provided, may contain only the availability section.

## Security guidance

ReportBridge handles infrastructure access and potentially sensitive monitoring data. Treat it as an administrative tool.

- **Use HTTPS** for the ReportBridge web interface, especially when credentials are submitted.
- **Use a dedicated, least-privilege Zabbix account** for reporting; avoid administrator credentials where possible.
- **Keep TLS verification enabled** and configure trust for internal/self-signed certificates rather than disabling verification.
- **Do not commit secrets or operational data.** Never commit passwords, API tokens, session cookies, logs, generated reports, customer hostnames/IPs, or real screenshots.
- **Protect runtime folders.** Ensure `logs/`, `tmp/`, and `output/` cannot be browsed or downloaded directly. Keep generated reports outside the web root where practical, and apply restrictive filesystem permissions.
- **Keep errors out of the browser.** In shared environments, use `display_errors=Off` and `log_errors=On`; protect logs and redact sensitive details.
- **Review URL validation and SSRF exposure.** The user-supplied Zabbix endpoint can target internal systems. This can be acceptable for a tightly controlled, self-hosted deployment, but a centrally hosted service accepting untrusted users needs robust SSRF protections and network egress controls.
- **Restrict access to the application.** Do not expose it publicly without authentication, HTTPS, access controls, and a security review.
- **Update dependencies carefully.** Preserve third-party notices and review library updates before upgrading.
- **Back up and rotate credentials** if they are accidentally exposed.

This project has not undergone a formal third-party security audit. Test it in a non-production environment and review the source code before using it for sensitive operations.

### Reporting a security issue

Please do not post passwords, tokens, session files, customer data, or a weaponizable vulnerability in a public issue. Use GitHub's private vulnerability reporting/security advisory feature if it is enabled for the repository; otherwise contact the maintainer privately.

## Runtime folders and `.gitignore`

Runtime folders such as `logs/`, `tmp/`, and `output/` may be needed by the application, but their contents should not be committed. The repository `.gitignore` should exclude runtime logs, sessions, temporary files, generated reports, local configuration overrides, and editor/OS files. If a sensitive file was committed previously, adding it to `.gitignore` is not enough: remove it from Git tracking, clean repository history if necessary, and rotate any exposed secrets.

## Known limitations

- **Windows/XAMPP-oriented background execution:** the current asynchronous launcher uses a hardcoded Windows PHP CLI path and Windows process-launch behavior.
- **Zabbix version compatibility:** Zabbix 7.x is the documented target; other versions have not been confirmed.
- **Branding:** inspect the `logos/` directory and any PDF header/footer branding before distributing reports. Only publish logos you own or are authorized to use.
- **Report coverage:** available sections depend on the code, host data, templates, permissions, and Zabbix API responses. Items Status and some graph types may be incomplete or unavailable in the current release; verify against the current code before advertising them as supported.
- **Resource usage:** large reports with many hosts and graphs can take several minutes and increase load on both ReportBridge and Zabbix.

## Roadmap

Potential future improvements include:

- Configurable branding and logo settings
- Configurable PHP CLI path and cross-platform background execution
- Linux and container support
- Optional API-token authentication
- Scheduled reports and email delivery
- Additional SSRF protections for hosted deployments
- Broader report sections and expanded test coverage
- Multi-language UI

## Contributing

Issues, code review, testing, and pull requests are welcome.

1. Open an issue describing the bug or proposed improvement. Do not include secrets or customer data.
2. Fork the repository and create a feature branch.
3. Test changes against a non-production Zabbix environment.
4. Submit a pull request describing the change and how it was tested.

Because the project is AI-assisted and has not had an independent security audit, careful review and reproducible test reports are especially appreciated.

## License

Zabbix ReportBridge's original application code is released under the **MIT License**. See the [LICENSE](LICENSE) file for the full license text.

### Third-party library: TCPDF

This project includes TCPDF for PDF generation. TCPDF is distributed under the **GNU Lesser General Public License (LGPL), version 3 or later**, as stated in its included license notice.

The TCPDF library remains subject to its own license and copyright notices. See the license files distributed with the `TCPDF/` directory for the applicable terms.

The MIT License for Zabbix ReportBridge's original code does not replace or override the license terms applicable to TCPDF.

## Author and acknowledgement

Created and maintained by **Sriharsha Paruchuri**.

If you find the project useful, consider starring the repository, opening an issue with constructive feedback, or contributing a tested improvement.

---

**Independent community project — not affiliated with, endorsed by, or supported by Zabbix LLC.**
