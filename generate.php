<?php
session_start();

$isBackgroundMode = (php_sapi_name() === 'cli' && isset($argv[1]));
$jobId = null;

if ($isBackgroundMode) {

    $jobId = $argv[1];
    $tempDir = sys_get_temp_dir();

    $jobFile = $tempDir . '/zbx_job_' . $jobId . '.json';
    $sessionFile = $tempDir . '/zbx_session_' . $jobId . '.json';

    if (file_exists($jobFile)) {
        $_POST = json_decode(file_get_contents($jobFile), true);
    }

    if (file_exists($sessionFile)) {
        $sess = json_decode(file_get_contents($sessionFile), true);

        $_SESSION['url'] = $sess['url'];
        $_SESSION['token'] = $sess['token'];
        $_SESSION['cookie'] = $sess['cookie'] ?? '';
    }

    $_SESSION['current_job'] = $jobId;
}

if (!$isBackgroundMode) {
    // This is a direct browser POST (Excel-only export path) — CSRF check applies
    if (!isset($_SESSION['csrf_token']) || !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Invalid or expired request. Please go back to the dashboard and try again.");
    }
}

$url   = $_SESSION['url'] ?? '';
$token = $_SESSION['token'] ?? '';

session_write_close();   // VERY IMPORTANT

$progressFile = sys_get_temp_dir() . '/zbx_progress_' .
(isset($_SESSION['current_job']) ? $_SESSION['current_job'] : session_id()) . '.json';
set_time_limit(600);
ini_set('max_execution_time', 600);
ob_start();

include 'config.php';
require_once('tcpdf/tcpdf.php');

/* ─── PROGRESS HELPER ─────────────────────────────────────── */
$progressFile = sys_get_temp_dir() . '/zbx_progress_' .
(isset($_SESSION['current_job']) ? $_SESSION['current_job'] : session_id()) . '.json';
$progressIdx  = 0;

function writeProgress($status, $message, $pct, $detail = '') {
    global $progressFile, $progressIdx;
    $progressIdx++;
    $payload = [
        'idx'     => $progressIdx,
        'status'  => $status,
        'message' => $message,
        'pct'     => $pct,
        'detail'  => $detail,
        'ts'      => time()
    ];
    file_put_contents($progressFile, json_encode($payload));
}
/* ─────────────────────────────────────────────────────────── */

writeProgress('running', 'Initialising report…', 2);

$title    = $_POST['title']   ?? 'Monitoring Report';
$groupids = $_POST['groupid'] ?? [];
$hostids  = $_POST['hostid']  ?? [];
$range    = $_POST['range']   ?? '24h';

$url   = rtrim($_SESSION['url'], '/');
$token = $_SESSION['token'];

/* ── DATE RANGE ── */
$customFromDate = $_POST['from_date'] ?? '';
$customToDate   = $_POST['to_date']   ?? '';

if (!empty($customFromDate) && !empty($customToDate)) {
    $range = 'custom';
    $from  = str_replace('T', ' ', $customFromDate);
    $to    = str_replace('T', ' ', $customToDate);
    if (strlen($from) == 10) $from .= ' 00:00:00';
    if (strlen($to)   == 10) $to   .= ' 23:59:59';

    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $from)) $from .= ':00';
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $to))   $to   .= ':59';
} else {
    switch ($range) {
        case '1h':  $from = date('Y-m-d H:i:s', strtotime('-1 hour'));   $to = date('Y-m-d H:i:s'); break;
        case '24h': $from = date('Y-m-d H:i:s', strtotime('-1 day'));    $to = date('Y-m-d H:i:s'); break;
        case '7d':  $from = date('Y-m-d H:i:s', strtotime('-7 days'));   $to = date('Y-m-d H:i:s'); break;
        case '30d': $from = date('Y-m-d H:i:s', strtotime('-30 days'));  $to = date('Y-m-d H:i:s'); break;
        case '90d': $from = date('Y-m-d H:i:s', strtotime('-90 days'));  $to = date('Y-m-d H:i:s'); break;
        default:    $from = date('Y-m-d H:i:s', strtotime('-1 day'));    $to = date('Y-m-d H:i:s');
    }
}

error_log("=== PDF GENERATION === From: $from  To: $to");

/* ── ZABBIX API ── */
function zbx($method, $params, $url, $token) {
    $payload = json_encode([
        "jsonrpc" => "2.0",
        "method"  => $method,
        "params"  => $params,
        "id"      => 1
    ]);
    $ch = curl_init($url . '/api_jsonrpc.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $payload);
    // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    // curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, ZBX_VERIFY_SSL);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, ZBX_VERIFY_SSL ? 2 : 0);
    curl_setopt($ch, CURLOPT_TIMEOUT,        30);
    $res = curl_exec($ch);
    curl_close($ch);
    $json = json_decode($res, true);
    return $json['result'] ?? [];
}

/* ── TARGET NAME ── */
writeProgress('running', 'Fetching target names…', 5);
$targetName = 'All Systems';
if (!empty($groupids)) {
    $r = zbx("hostgroup.get", ["output" => ["name"], "groupids" => $groupids], $url, $token);
    $targetName = implode(', ', array_column($r, 'name'));
}
if (!empty($hostids)) {
    $r = zbx("host.get", ["output" => ["name"], "hostids" => $hostids], $url, $token);
    $targetName = implode(', ', array_column($r, 'name'));
}

/* ── HOST LIST ── */
writeProgress('running', 'Fetching host list…', 8);
$hostParams = [
    "output"           => ["hostid","name"],
    "selectInterfaces" => ["ip"],
    "monitored_hosts"  => true,
    "sortfield"        => "name"
];
if (!empty($groupids)) $hostParams["groupids"] = $groupids;
if (!empty($hostids))  $hostParams["hostids"]  = $hostids;
$hosts = zbx("host.get", $hostParams, $url, $token);

/* ══════════════════════════════════════════════════════
   COLLECT AVAILABILITY DATA (used by both PDF and Excel)
   ══════════════════════════════════════════════════════ */
$availRows    = [];
$availEnabled = isset($_POST['show_trends']);
$availExport  = $_POST['avail_export'] ?? 'pdf';

if ($availEnabled) {
    $time_from = strtotime($from);
    $time_till = (strlen($to) <= 10) ? strtotime($to . ' 23:59:59') : strtotime($to);

    $HISTORY_RETENTION_DAYS = 14;
    $historyBoundary = time() - ($HISTORY_RETENTION_DAYS * 86400);

    foreach ($hosts as $h) {
        $hid    = $h['hostid'];
        $hname  = $h['name'];
        $ip     = $h['interfaces'][0]['ip'] ?? '-';
        $avail  = 0;
        $method = 'No Data';
        $source   = 'SLA Estimate';
        $dataFrom = null;
        $dataTill = null;
        $total    = 0;
        $up       = 0;
        $sumAvg   = 0;

        // Priority 1: ICMP ping
        $items = zbx("item.get", [
            "output"  => ["itemid","value_type","key_"],
            "hostids" => $hid,
            "search"  => ["key_" => "icmpping"],
            "limit"   => 1
        ], $url, $token);

        if (count($items) == 0) {
            $items = zbx("item.get", [
                "output"  => ["itemid","value_type","key_"],
                "hostids" => $hid,
                "search"  => ["key_" => "agent.ping"],
                "limit"   => 1
            ], $url, $token);
        }

        if (count($items) == 0) {
            $items = zbx("item.get", [
                "output"  => ["itemid","value_type","key_"],
                "hostids" => $hid,
                "search"  => ["key_" => "system.uptime"],
                "limit"   => 1
            ], $url, $token);
        }

        if (count($items) > 0) {
            $itemid = $items[0]['itemid'];
            $type   = $items[0]['value_type'];
            $key    = $items[0]['key_'];
            $method = str_replace(
                ['icmpping','agent.ping','system.uptime'],
                ['ICMP Ping','Agent Ping','System Uptime'],
                $key
            );

            if ($time_from >= $historyBoundary) {
                $history = zbx("history.get", [
                    "output"    => ["value","clock"],
                    "history"   => $type,
                    "itemids"   => $itemid,
                    "time_from" => $time_from,
                    "time_till" => $time_till,
                    "sortfield" => "clock",
                    "sortorder" => "ASC",
                    "limit"     => 50000
                ], $url, $token);

                $total = count($history);
                foreach ($history as $row) {
                    $val = floatval($row['value']);
                    if ($key === 'system.uptime') { if ($val > 0) $up++; }
                    else                          { if ($val >= 1) $up++; }

                    $c = intval($row['clock']);
                    if ($dataFrom === null || $c < $dataFrom) $dataFrom = $c;
                    if ($dataTill === null || $c > $dataTill) $dataTill = $c;
                }
                if ($total > 0) $avail = round(($up / $total) * 100, 2);
                $source = 'History';

            } else {
                $trends = zbx("trend.get", [
                    "output"    => ["value_avg","value_min","clock"],
                    "itemids"   => $itemid,
                    "time_from" => $time_from,
                    "time_till" => $time_till
                ], $url, $token);

                $total = count($trends);
                if ($total > 0) {
                    if ($key === 'system.uptime') {
                        foreach ($trends as $row) {
                            if (floatval($row['value_min']) > 0) $up++;
                            $c = intval($row['clock']);
                            if ($dataFrom === null || $c < $dataFrom) $dataFrom = $c;
                            if ($dataTill === null || $c > $dataTill) $dataTill = $c;
                        }
                        $avail = round(($up / $total) * 100, 2);
                    } else {
                        foreach ($trends as $row) {
                            $sumAvg += floatval($row['value_avg']);
                            $c = intval($row['clock']);
                            if ($dataFrom === null || $c < $dataFrom) $dataFrom = $c;
                            if ($dataTill === null || $c > $dataTill) $dataTill = $c;
                        }
                        $avail = round(($sumAvg / $total) * 100, 2);
                    }
                }
                $source = 'Trends';
            }

            if ($total == 0) {
                $method = 'Trigger SLA (no data)';
                $source = 'SLA Estimate';
                $events = zbx("event.get", [
                    "output"    => ["eventid","value"],
                    "hostids"   => $hid,
                    "time_from" => $time_from,
                    "time_till" => $time_till
                ], $url, $token);

                $problems = 0;
                foreach ($events as $e) { if ($e['value'] == 1) $problems++; }
                $avail = ($problems == 0) ? 100 : max(0, 100 - ($problems * 2));
            }

            error_log("Host: $hname | Item: $key | requested_from=$time_from requested_till=$time_till | actual_from=$dataFrom actual_till=$dataTill | total=$total | avail=$avail | source=$source");

        } else {
            $method = 'Trigger SLA';
            $source = 'SLA Estimate';
            $events = zbx("event.get", [
                "output"    => ["eventid","value"],
                "hostids"   => $hid,
                "time_from" => $time_from,
                "time_till" => $time_till
            ], $url, $token);

            $problems = 0;
            foreach ($events as $e) { if ($e['value'] == 1) $problems++; }
            $avail = ($problems == 0) ? 100 : max(0, 100 - ($problems * 2));
        }

        $availRows[] = [
            'host'      => $hname,
            'ip'        => $ip,
            'method'    => $method,
            'avail'     => $avail,
            'source'    => $source,
            'points'    => $total,
            'data_from' => $dataFrom ? date('Y-m-d H:i:s', $dataFrom) : '-',
            'data_till' => $dataTill ? date('Y-m-d H:i:s', $dataTill) : '-'
        ];
    }
}
/* ══════════════════════════════════════════════════════
   EXCEL EXPORT (if selected)
   Uses PhpSpreadsheet — install via: composer require phpoffice/phpspreadsheet
   ══════════════════════════════════════════════════════ */
$xlsxFile = null;
if ($availEnabled && in_array($availExport, ['excel','both'])) {
    writeProgress('running', 'Building Excel file…', 32);

    $xlsxFile = sys_get_temp_dir() . '/avail_' . time() . '.xlsx';

    // Try PhpSpreadsheet if available
    $spreadsheetAvailable = false;
    $vendorPaths = [
        __DIR__ . '/vendor/autoload.php',
        __DIR__ . '/../vendor/autoload.php',
    ];
    foreach ($vendorPaths as $vp) {
        if (file_exists($vp)) {
            require_once $vp;
            $spreadsheetAvailable = class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet');
            break;
        }
    }

    if ($spreadsheetAvailable) {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Availability Report');

        // Title row
        $sheet->setCellValue('A1', 'Availability Report');
        $sheet->mergeCells('A1:E1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');

        // Meta with Report Period and Generated Date
        $sheet->setCellValue('A2', 'Report Period: ' . $from . ' to ' . $to);
        $sheet->mergeCells('A2:E2');
        $sheet->setCellValue('A3', 'Target: ' . $targetName);
        $sheet->mergeCells('A3:E3');
        $sheet->setCellValue('A4', 'Report Generated: ' . date('Y-m-d H:i:s'));
        $sheet->mergeCells('A4:E4');

        // Header row (with Sr. No)
        $headers = ['Sr. No', 'Host', 'IP Address', 'Method', 'Availability %'];
        foreach ($headers as $ci => $hdr) {
            $col = chr(65 + $ci);
            $sheet->setCellValue($col . '5', $hdr);
            $sheet->getStyle($col . '5')->getFont()->setBold(true);
            $sheet->getStyle($col . '5')->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FF1B4FA2');
            $sheet->getStyle($col . '5')->getFont()->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle($col . '5')->getAlignment()->setHorizontal('center');
        }

        // Data rows
        $rowNum = 6;
        $srNo = 1;
        foreach ($availRows as $row) {
            $sheet->setCellValue('A' . $rowNum, $srNo);
            $sheet->setCellValue('B' . $rowNum, $row['host']);
            $sheet->setCellValue('C' . $rowNum, $row['ip']);
            $sheet->setCellValue('D' . $rowNum, $row['method']);
            $sheet->setCellValue('E' . $rowNum, $row['avail'] . '%');

            // Colour-code availability
            $avPct = $row['avail'];
            if ($avPct >= 99)      $bg = 'FF92D050'; // green
            elseif ($avPct >= 95)  $bg = 'FFFFC000'; // amber
            else                   $bg = 'FFFF0000'; // red

            $sheet->getStyle('E' . $rowNum)->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setARGB($bg);
            $sheet->getStyle('E' . $rowNum)->getAlignment()->setHorizontal('center');

            // Alternate row shading
            if ($rowNum % 2 == 0) {
                $sheet->getStyle('A' . $rowNum . ':D' . $rowNum)->getFill()
                    ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFF0F5FF');
            }

            $rowNum++;
            $srNo++;
        }

        // Auto-width
        foreach (range('A','E') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        // Add border to data table
        $lastRow = $rowNum - 1;
        if ($lastRow >= 5) {
            $sheet->getStyle('A5:E' . $lastRow)->getBorders()->getAllBorders()
                ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($xlsxFile);

        // If Excel-only: output and exit
        if ($availExport === 'excel') {
            writeProgress('done', 'Excel file ready — downloading…', 100);
            while (ob_get_level()) ob_end_clean();
            $filename = 'Availability_' . date('Ymd_His') . '.xlsx';
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: max-age=0');
            readfile($xlsxFile);
            unlink($xlsxFile);
            exit;
        }

        // Store path for 'both' case
        $_SESSION['xlsx_path']     = $xlsxFile;
        $_SESSION['xlsx_filename'] = 'Availability_' . date('Ymd_His') . '.xlsx';

    } else {
        // PhpSpreadsheet not installed — fall back to CSV
        $csvFile = sys_get_temp_dir() . '/avail_' . time() . '.csv';
        $fp = fopen($csvFile, 'w');

        // Write metadata at the top
        fputcsv($fp, ['Availability Report']);
        fputcsv($fp, ['Report Period: ' . $from . ' to ' . $to]);
        fputcsv($fp, ['Target: ' . $targetName]);
        fputcsv($fp, ['Report Generated: ' . date('Y-m-d H:i:s')]);
        fputcsv($fp, []); // Blank row

        // Write headers
        fputcsv($fp, ['Sr. No','Host','IP Address','Method','Availability %']);

        // Write data
        $srNo = 1;
        foreach ($availRows as $row) {
            fputcsv($fp, [$srNo, $row['host'], $row['ip'], $row['method'], $row['avail'] . '%']);
            $srNo++;
        }
        fclose($fp);

        if ($availExport === 'excel') {
            writeProgress('done', 'CSV file ready — downloading…', 100);
            while (ob_get_level()) ob_end_clean();
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="Availability_' . date('Ymd_His') . '.csv"');
            readfile($csvFile);
            unlink($csvFile);
            exit;
        }
        $_SESSION['xlsx_path']     = $csvFile;
        $_SESSION['xlsx_filename'] = 'Availability_' . date('Ymd_His') . '.csv';
    }

    writeProgress('running', 'Excel file ready.', 35);
}

/* ══════════════════════════════════════════════════════
   PDF GENERATION
   ══════════════════════════════════════════════════════ */
writeProgress('running', 'Building PDF structure…', 37);

class MYPDF extends TCPDF {
    public $header_title = '';

    public function Header() {
        if ($this->page > 1) {
            $logo = __DIR__ . '/logos/logo.png';
            if (file_exists($logo)) $this->Image($logo, 10, 6, 18);
            $this->SetFont('helvetica', 'B', 11);
            $this->Cell(0, 10, 'Company Name Monitoring Report | ' . $this->header_title, 0, 1, 'C');
            $this->Line(10, 20, 200, 20);
        }
    }

    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', '', 9);
        $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages(), 0, 0, 'C');
    }
}

$pdf = new MYPDF();
$pdf->header_title = $targetName;
$pdf->SetMargins(10, 25, 10);
$pdf->SetAutoPageBreak(true, 20);

/* ── COVER PAGE ── */
writeProgress('running', 'Creating cover page…', 39);
$pdf->AddPage();
$logo = __DIR__ . '/logos/logo.png';
if (file_exists($logo)) $pdf->Image($logo, 55, 35, 100);
$pdf->Ln(95);
$pdf->SetFont('helvetica', 'B', 24);
$pdf->Cell(0, 15, 'Your Company', 0, 1, 'C');
$pdf->Ln(5);
$pdf->SetFont('helvetica', 'B', 20);
$pdf->Cell(0, 12, $title, 0, 1, 'C');
$pdf->Ln(10);
$pdf->SetFont('helvetica', '', 14);
$pdf->Cell(0, 10, 'Target: ' . $targetName,                0, 1, 'C');
$pdf->Cell(0, 10, 'Period: ' . $from . ' to ' . $to,       0, 1, 'C');
$pdf->Cell(0, 10, 'Range: '  . $range,                     0, 1, 'C');
$pdf->Cell(0, 10, 'Generated: ' . date('Y-m-d H:i:s'),     0, 1, 'C');

/* ── EXECUTIVE SUMMARY ── */
writeProgress('running', 'Writing executive summary…', 41);
$pdf->AddPage();
$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, 'Executive Summary', 0, 1);
$pdf->Ln(5);
$pdf->SetFont('helvetica', '', 11);
$pdf->MultiCell(0, 6,
    "Target: " . $targetName .
    "\nReporting Period: " . $from . " to " . $to .
    "\nRange: " . $range .
    "\nGenerated: " . date('Y-m-d H:i:s')
);

/* ── TRIGGERS ── */
if (isset($_POST['show_triggers'])) {
    writeProgress('running', 'Fetching active triggers…', 44);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'Triggers', 0, 1);
    $pdf->Ln(5);

    $params = [
        "output"      => ["description","priority","value"],
        "selectHosts" => ["name"],
        "filter"      => ["value" => 1],
        "sortfield"   => ["priority","description"],
        "sortorder"   => "DESC",
        "limit"       => 50
    ];
    if (!empty($groupids)) $params["groupids"] = $groupids;
    if (!empty($hostids))  $params["hostids"]  = $hostids;

    $triggers = zbx("trigger.get", $params, $url, $token);
    $sevText  = [0=>'Not classified',1=>'Info',2=>'Warning',3=>'Average',4=>'High',5=>'Disaster'];

    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(200, 220, 255);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.3);
    $pdf->Cell(15,  8, 'Sr',         1, 0, 'C', true);
    $pdf->Cell(30,  8, 'Severity',   1, 0, 'C', true);
    $pdf->Cell(85,  8, 'Description',1, 0, 'C', true);
    $pdf->Cell(44,  8, 'Host',       1, 1, 'C', true);

    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetDrawColor(180, 180, 180);

    if (count($triggers) == 0) {
        $pdf->Cell(174, 8, 'No active triggers found.', 1, 1, 'C');
    } else {
        $srNo = 1;
        foreach ($triggers as $t) {
            $sev  = $sevText[$t['priority']] ?? $t['priority'];
            $desc = substr($t['description'],            0, 55);
            $host = substr($t['hosts'][0]['name'] ?? '-', 0, 25);
            $pdf->Cell(15,  8, $srNo, 1, 0, 'C');
            $pdf->Cell(30,  8, $sev,  1, 0, 'L');
            $pdf->Cell(85,  8, $desc, 1, 0, 'L');
            $pdf->Cell(44,  8, $host, 1, 1, 'L');
            $srNo++;
        }
    }
    writeProgress('running', 'Triggers section done.', 47);
}

/* ── AVAILABILITY IN EXCEL ONLY (NOT IN PDF) ── */
// Availability data is only included in Excel export
// PDF availability section has been removed per requirements
if ($availEnabled && in_array($availExport, ['pdf','both'])) {
    $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'Availability Report', 0, 1);
    $pdf->Ln(2);

    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'Requested Period: ' . $from . ' to ' . $to, 0, 1);

    // Overall output period across all hosts (earliest/latest actual data used)
    $overallFrom = null;
    $overallTill = null;
    foreach ($availRows as $r) {
        if ($r['data_from'] !== '-') {
            $t = strtotime($r['data_from']);
            if ($overallFrom === null || $t < $overallFrom) $overallFrom = $t;
        }
        if ($r['data_till'] !== '-') {
            $t = strtotime($r['data_till']);
            if ($overallTill === null || $t > $overallTill) $overallTill = $t;
        }
    }
    $outputFromStr = $overallFrom ? date('Y-m-d H:i:s', $overallFrom) : '-';
    $outputTillStr = $overallTill ? date('Y-m-d H:i:s', $overallTill) : '-';

    $pdf->Cell(0, 6, 'Output Period: ' . $outputFromStr . ' to ' . $outputTillStr, 0, 1);
    $pdf->Ln(3);

    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(200, 220, 255);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.3);
    $pdf->Cell(65, 8, 'Host',         1, 0, 'C', true);
    $pdf->Cell(35, 8, 'IP Address',   1, 0, 'C', true);
    $pdf->Cell(45, 8, 'Method',       1, 0, 'C', true);
    $pdf->Cell(29, 8, 'Availability', 1, 1, 'C', true);

    $pdf->SetFont('helvetica', '', 9);
    $row_i = 0;
    foreach ($availRows as $row) {
        if ($row_i % 2 == 0) $pdf->SetFillColor(245, 248, 255);
        else                  $pdf->SetFillColor(255, 255, 255);

        $pdf->SetDrawColor(180, 180, 180);
        $pdf->Cell(65, 8, substr($row['host'],   0, 38), 1, 0, 'L', true);
        $pdf->Cell(35, 8, substr($row['ip'],     0, 20), 1, 0, 'C', true);
        $pdf->Cell(45, 8, substr($row['method'], 0, 25), 1, 0, 'C', true);

        $av = $row['avail'];
        if ($av >= 99)     $pdf->SetFillColor(146, 208, 80);   // green
        elseif ($av >= 95) $pdf->SetFillColor(255, 192, 0);    // amber
        else               $pdf->SetFillColor(255, 80,  80);   // red

        $pdf->Cell(29, 8, $av . '%', 1, 1, 'C', true);
        $row_i++;
    }

    $pdf->Ln(4);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->MultiCell(0, 5, 'Method priority: ICMP Ping > Agent Ping > System Uptime > Trigger SLA estimate');

    if ($availExport === 'both' && !empty($_SESSION['xlsx_filename'])) {
        $pdf->Ln(3);
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->Cell(0, 6, 'An Excel copy was also downloaded: ' . $_SESSION['xlsx_filename'], 0, 1);
    }

    writeProgress('running', 'Availability section complete.', 53);
}

/* ══════════════════════════════════════════════════════
   GRAPHS – IMPROVED FILTERING FOR SPECIFIC GRAPH TYPES
   ══════════════════════════════════════════════════════ */
if (isset($_POST['include_graphs'])) {
    error_log("=== GRAPHS SECTION ===");

    $selectedTypes = $_POST['graph_types'] ?? ['cpu','memory','disk','ping','traffic'];
    $wantCpu     = in_array('cpu',     $selectedTypes);
    $wantMemory  = in_array('memory',  $selectedTypes);
    $wantDisk    = in_array('disk',    $selectedTypes);
    $wantPing    = in_array('ping',    $selectedTypes);
    $wantTraffic = in_array('traffic', $selectedTypes);
    $wantOther   = in_array('other',   $selectedTypes);

    // A4=297mm, top=25mm, footer=15mm → usable=257mm
    // Header row 11mm → 246mm ÷ 4 = 61mm per slot
    $GRAPHS_PER_PAGE = 4;
    $SLOT_HEIGHT     = 61;
    $IMG_HEIGHT      = 52;
    $LABEL_HEIGHT    = 5;
    $FIRST_Y         = 36;

    $total_hosts_graph = count($hosts);
    $graph_base        = 53;   // pct after availability
    $graph_budget      = 44;   // pct allocated to graphs (53→97)

    foreach ($hosts as $hi => $host) {
        $hid      = $host['hostid'];
        $host_pct = $graph_base + intval(($hi / max($total_hosts_graph, 1)) * $graph_budget);
        writeProgress('running', 'Graphs: fetching for ' . $host['name'] . '…', $host_pct, 'Host ' . ($hi + 1) . '/' . $total_hosts_graph);
        usleep(500000);
        $graphs = zbx("graph.get", [
            "output"    => ["graphid","name"],
            "hostids"   => $hid,
            "sortfield" => "name"
        ], $url, $token);

        if (!$graphs || count($graphs) == 0) continue;

        /* ── FETCH PING ITEMS: ICMP first, then Agent ping as fallback ── */
        $pingItemObjects = [];

        // Priority 1: Try ICMP ping
        $icmpItems = zbx("item.get", [
            "output"      => ["itemid","name","key_"],
            "hostids"     => $hid,
            "search"      => ["key_" => "icmpping"],
            "searchByAny" => false,
        ], $url, $token);

        if (!empty($icmpItems)) {
            // ICMP ping found - use it
            foreach ($icmpItems as $item) {
                $pingItemObjects[] = [
                    'type'    => 'item',
                    'graphid' => null,
                    'itemid'  => $item['itemid'],
                    'name'    => $host['name'] . ': ' . $item['name'],
                    'key'     => $item['key_']
                ];
            }
        } else {
            // Priority 2: ICMP not found, try Agent ping
            $agentItems = zbx("item.get", [
                "output"      => ["itemid","name","key_"],
                "hostids"     => $hid,
                "search"      => ["key_" => "agent.ping"],
                "searchByAny" => false,
            ], $url, $token);

            foreach ($agentItems as $item) {
                $pingItemObjects[] = [
                    'type'    => 'item',
                    'graphid' => null,
                    'itemid'  => $item['itemid'],
                    'name'    => $host['name'] . ': ' . $item['name'],
                    'key'     => $item['key_']
                ];
            }
        }

        /* ── CLASSIFY GRAPHS WITH PRIORITY MATCHING ── */
        $cpuGraphs = $memoryGraphs = $diskGraphs = $pingGraphs = $trafficGraphs = $otherGraphs = [];

        foreach ($graphs as $g) {
            $n = strtolower(trim($g['name']));

            // CPU Utilization - HIGHEST PRIORITY exact match first
            if (preg_match('/cpu utilization/', $n)) {
                array_unshift($cpuGraphs, $g);  // Put exact matches at beginning
            } elseif (preg_match('/cpu usage|cpu util|processor usage|processor utilization|total cpu usage/', $n)) {
                $cpuGraphs[] = $g;

            // Memory Utilization - similar priority approach
            } elseif (preg_match('/memory utilization|memory usage|memory util|ram usage|ram utilization/', $n)) {
                $memoryGraphs[] = $g;

            // Disk Space - only specific patterns
            } elseif (preg_match('/disk space|space usage|filesystem usage|free disk|used disk/', $n) &&
                      !preg_match('/chart|pie|utilization chart|queue|latency|iops/', $n)) {
                $diskGraphs[] = $g;

            // Ping/ICMP - catches all ping-related graphs
            } elseif (preg_match('/ICMP ping|ping|response|latency|loss/', $n)) {
                $pingGraphs[] = $g;

            // Traffic - network interface traffic
            } elseif (preg_match('/traffic|ethernet|interface|bandwidth|bits sent|bits received/', $n)) {
                $trafficGraphs[] = $g;

            // Everything else
            } else {
                $otherGraphs[] = $g;
            }
        }

        /* ── ADD PING ITEMS TO PING GRAPHS (ICMP first, Agent ping as fallback) ── */
        if (!empty($pingItemObjects)) {
            // Prepend ping items before named graphs
            $pingGraphs = array_merge($pingItemObjects, $pingGraphs);
        }

        // Sort disk graphs by drive letter (C:, D:, E: …)
        usort($diskGraphs, function ($a, $b) {
            $na = strtolower($a['name']);
            $nb = strtolower($b['name']);
            $letters = ['c:','d:','e:','f:','g:','h:','i:','j:','k:','l:','m:',
                           'n:','o:','p:','q:','r:','s:','t:','u:','v:','w:','x:','y:','z:'];
            $pa = $pb = 999;
            foreach ($letters as $k => $v) {
                if (strpos($na, $v) !== false) $pa = $k;
                if (strpos($nb, $v) !== false) $pb = $k;
            }
            return ($pa == $pb) ? strcmp($na, $nb) : $pa <=> $pb;
        });

        /* ── BUILD ORDERED SELECTION - ONE GRAPH PER CATEGORY ── */
        $selected = [];
        if ($wantCpu    && !empty($cpuGraphs))     $selected[] = $cpuGraphs[0];     // Only FIRST CPU graph
        if ($wantMemory && !empty($memoryGraphs))  $selected[] = $memoryGraphs[0];  // Only FIRST Memory graph
        if ($wantDisk   && !empty($diskGraphs)) {
            foreach ($diskGraphs as $d) $selected[] = $d;                           // ALL disk graphs (sorted by drive)
        }
        if ($wantPing    && !empty($pingGraphs))    $selected[] = $pingGraphs[0];   // Only FIRST Ping graph
        if ($wantTraffic && !empty($trafficGraphs)) {
            foreach ($trafficGraphs as $t) $selected[] = $t;                        // ALL traffic graphs
        }
        if ($wantOther && !empty($otherGraphs)) {
            foreach (array_slice($otherGraphs, 0, 8) as $o) $selected[] = $o;      // Max 8 other graphs
        }

        if (count($selected) == 0) continue;

        /* ── RENDER IN FIXED 4-PER-PAGE SLOTS ── */
        $pages        = array_chunk($selected, $GRAPHS_PER_PAGE);
        $total_graphs = count($selected);

        foreach ($pages as $pi => $pageGraphs) {
            $pdf->AddPage();
            $pdf->SetAutoPageBreak(false);

            $pdf->SetFont('helvetica', 'B', 13);
            $pdf->SetXY(10, 25);
            $pdf->Cell(0, 8, $host['name'] . ' - Graphs', 0, 1);

            foreach ($pageGraphs as $slotIdx => $gr) {
                $graphid = $gr['graphid'];
                $itemid  = $gr['itemid'] ?? null;  // ICMP items have itemid, not graphid
                $gname   = $gr['name'];
                $type    = $gr['type'] ?? 'graph';  // 'graph' or 'item'

                $gi_abs = $pi * $GRAPHS_PER_PAGE + $slotIdx + 1;
                writeProgress(
                    'running',
                    'Rendering graph ' . $gi_abs . '/' . $total_graphs . ' for ' . $host['name'],
                    min(97, $host_pct + intval(($gi_abs / max($total_graphs, 1)) * ($graph_budget / max($total_hosts_graph, 1)))),
                    $gname
                );
                usleep(500000);
                $imgY   = $FIRST_Y + ($slotIdx * $SLOT_HEIGHT);
                $labelY = $imgY + $IMG_HEIGHT + 1;

                // Determine which endpoint to use
                if ($type === 'item' && $itemid) {
                    // ICMP items: use chart.php with itemids parameter
                    $img = sys_get_temp_dir() . '/zbx_item_' . $itemid . '_' . md5($from.$to) . '.png';
                    $chartUrl = $url . '/chart.php?itemids[]=' . $itemid .
                        '&from=' . urlencode($from) .
                        '&to='   . urlencode($to) .
                        '&width=1000&height=200&profileIdx=web.charts.filter&resolve_macros=1';
                } else {
                    // Named graphs: use chart2.php with graphid parameter
                    $img = sys_get_temp_dir() . '/zbx_' . $graphid . '_' . md5($from.$to) . '.png';
                    $chartUrl = $url . '/chart2.php?graphid=' . $graphid .
                        '&from=' . urlencode($from) .
                        '&to='   . urlencode($to) .
                        '&width=1000&height=200&profileIdx=web.graphs.filter';
                }

                error_log("Graph URL: " . $chartUrl);

                $ch = curl_init($chartUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_COOKIEFILE,     $_SESSION['cookie'] ?? '');
                curl_setopt($ch, CURLOPT_COOKIEJAR,      $_SESSION['cookie'] ?? '');
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                // curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                // curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, ZBX_VERIFY_SSL);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, ZBX_VERIFY_SSL ? 2 : 0);
                curl_setopt($ch, CURLOPT_USERAGENT,      'Mozilla/5.0');
                curl_setopt($ch, CURLOPT_TIMEOUT,        30);
                $imgData = curl_exec($ch);
                curl_close($ch);

                if ($imgData !== false) file_put_contents($img, $imgData);

                if (file_exists($img) && filesize($img) > 2000) {
                    $pdf->Image($img, 10, $imgY, 190, $IMG_HEIGHT, 'PNG');
                    error_log("✓ Graph added: $graphid  ($gname)");
                } else {
                    $pdf->SetDrawColor(200, 200, 200);
                    $pdf->SetFillColor(248, 248, 248);
                    $pdf->Rect(10, $imgY, 190, $IMG_HEIGHT, 'DF');
                    $pdf->SetFont('helvetica', '', 9);
                    $pdf->SetXY(10, $imgY + ($IMG_HEIGHT / 2) - 4);
                    $pdf->Cell(190, 8, 'Graph unavailable: ' . $gname, 0, 0, 'C');
                    error_log("✗ Graph failed: $graphid  ($gname)");
                }

                $pdf->SetFont('helvetica', '', 8);
                $pdf->SetXY(10, $labelY);
                $pdf->Cell(190, $LABEL_HEIGHT, $gname, 0, 0, 'L');
            }

            $pdf->SetAutoPageBreak(true, 20);
        }
    }
}

/* ── OUTPUT PDF & HANDLE BOTH EXPORT ── */

writeProgress('running', 'Finalizing report…', 95);
usleep(500000);   
while (ob_get_level()) {
    ob_end_clean();
}

$pdfFilename = 'Your_Company_Report_' . date('Ymd_His') . '.pdf';

if ($isBackgroundMode) {

    $pdfPath = sys_get_temp_dir() . '/zbx_pdf_' . $jobId . '.pdf';
    $downloadInfo = sys_get_temp_dir() . '/zbx_download_' . $jobId . '.json';

    $pdf->Output($pdfPath, 'F');

    $downloadData = [
        'pdf_path' => $pdfPath,
        'pdf_name' => $pdfFilename
    ];

    if (!empty($xlsxFile) && file_exists($xlsxFile)) {
        $downloadData['excel_path'] = $xlsxFile;
        $downloadData['excel_name'] = 'Availability_' . date('Ymd_His') . '.xlsx';
    }

    file_put_contents($downloadInfo, json_encode($downloadData));

    clearstatcache();

    writeProgress('done', 'Report generated successfully', 100, 'Ready for download');

    exit;

} else {

    if ($availExport === 'both') {

        $pdfPath = sys_get_temp_dir() . '/pdf_' . time() . '.pdf';
        $pdf->Output($pdfPath, 'F');

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $pdfFilename . '"');
        readfile($pdfPath);
        @unlink($pdfPath);
        exit;

    } else {

        $pdf->Output($pdfFilename, 'D');
        exit;
    }
}

?>