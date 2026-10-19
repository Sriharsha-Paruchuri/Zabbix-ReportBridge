<?php
session_start();
include 'config.php';

if (isset($_GET['logout'])) {
    $progressFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'zbx_progress_' . session_id() . '.json';
    @unlink($progressFile);

    if (isset($_SESSION['cookie']) && file_exists($_SESSION['cookie'])) {
        @unlink($_SESSION['cookie']);
    }

    $_SESSION = [];
    session_unset();
    session_destroy();
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    header("Location:index.php");
    exit;
}

if (!isset($_SESSION['token'])) {
    header("Location:index.php");
    exit;
}

$url   = $_SESSION['url'];
$token = $_SESSION['token'];

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

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
    $res = curl_exec($ch);
    curl_close($ch);
    $json = json_decode($res, true);
    return $json['result'] ?? [];
}

$groups = zbx("hostgroup.get", ["output" => ["groupid","name"], "sortfield" => "name"], $url, $token);
$hosts  = zbx("host.get", ["output" => ["hostid","name"], "sortfield" => "name"], $url, $token);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Zabbix Reports Dashboard</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js/public/assets/styles/choices.min.css">
<script src="https://cdn.jsdelivr.net/npm/choices.js/public/assets/scripts/choices.min.js"></script>
<link rel="icon" type="image/png" href="logos/logo.png">
<link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body>
<!-- Disclaimer Popup -->
<div id="loginPopup">
    <div class="popup-box">
        <h2>Disclaimer &amp; Instructions</h2>
        <p><strong>Disclaimer:</strong></p>
        <ul>
            <li>This tool generates reports using live Zabbix data.</li>
            <li>This tool is compatible only with Zabbix 7.</li>
            <li>Required templates must be attached to hosts in order to fetch monitoring data properly.</li>
            <li>Please verify all generated data before sharing externally.</li>
            <li>Use only authorized monitoring accounts.</li>
            <li>Generated PDF and Excel reports may contain sensitive infrastructure information.</li>
            <li>Excessive usage may cause load on the Zabbix server and report server. Please use responsibly.</li>
        </ul>
        <p><strong>Instructions:</strong></p>
        <ul>
            <li>Select either Hostgroups or Hosts.</li>
            <li>Select the required report date range.</li>
            <li>Choose the sections to include in the report.</li>
            <li>Click <strong>Generate PDF Report</strong> for availability reports.</li>
            <li>Please wait while the report opens in your browser. It may take some time depending on the data size.</li>
            <li>Ping graphs are still under development.</li>
            <li>For graphs to display correctly, the respective templates must be attached to the selected hosts.</li>
            <li><strong>Note:</strong>⚠ If you select <strong>Separate Excel</strong>, only the <strong>Availability Report</strong> Excel file will be downloaded.</li>
        </ul>
        <button class="popup-btn" onclick="closePopup()">I Understand / Continue</button>
    </div>
</div>
<div id="progressOverlay">
    <div class="progress-card" id="progressCard">
        <div class="pc-logo">📊</div>
        <h3 id="pcTitle">Generating Report…</h3>
        <p class="pc-sub">Please wait. Do not close this tab.</p>

        <div class="steps-row">
            <div class="step-pill" id="step_init"><span>⚙️</span> Init</div>
            <div class="step-pill" id="step_triggers"><span>🔔</span> Triggers</div>
            <div class="step-pill" id="step_avail"><span>📈</span> Availability</div>
            <div class="step-pill" id="step_graphs"><span>📊</span> Graphs</div>
            <div class="step-pill" id="step_done"><span>✅</span> Done</div>
        </div>

        <div class="prog-pct" id="progPct">0%</div>
        <div class="prog-bar-wrap"><div class="prog-bar-fill" id="progFill"></div></div>
        <div class="prog-msg" id="progMsg">Starting…</div>
        <div class="prog-detail" id="progDetail">&nbsp;</div>
        <div class="prog-timer">⏱ Elapsed: <span id="progTimer">0s</span></div>
    </div>
</div>

<div class="wrapper">

    <h1>🖨 Zabbix Reports</h1>
    <p class="sub">Corporate Monitoring Report Generator</p>

    <div style="text-align:right; margin-bottom:15px;">
        <a href="?logout=1" style="background:#dc3545;color:white;padding:10px 18px;text-decoration:none;border-radius:8px;font-weight:bold;">
            Logout
        </a>
    </div>

    <form method="post" action="generate.php" id="reportForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <div class="section">
            <p class="section-title">Report Details</p>
            <label for="title">Report Title</label>
            <input type="text" id="title" name="title" value="Monthly Monitoring Report" required>
        </div>

        <div class="section">
            <p class="section-title">Target Selection</p>
            <div class="grid2">
                <div>
                    <label for="groupid">Host Groups</label>
                    <select name="groupid[]" id="groupid" multiple>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= htmlspecialchars($g['groupid']) ?>"><?= htmlspecialchars($g['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="hostid">Individual Hosts</label>
                    <select name="hostid[]" id="hostid" multiple>
                        <?php foreach ($hosts as $h): ?>
                            <option value="<?= htmlspecialchars($h['hostid']) ?>"><?= htmlspecialchars($h['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="section">
            <p class="section-title">Reporting Period</p>
            <label for="range">Select Range</label>
            <select name="range" id="range" onchange="onRangeChange()">
                <option value="1h">Last 1 Hour</option>
                <option value="24h" selected>Last 24 Hours</option>
                <option value="7d">Last 7 Days</option>
                <option value="30d">Last 30 Days</option>
                <option value="90d">Last 90 Days</option>
                <option value="custom">Custom Range…</option>
            </select>
            <div id="customRange" class="grid2">
                <div>
                    <label for="from_date">From</label>
                    <input type="datetime-local" name="from_date" id="from_date">
                </div>
                <div>
                    <label for="to_date">To</label>
                    <input type="datetime-local" name="to_date" id="to_date">
                </div>
            </div>
            <div class="range-note" id="rangeNote">
                <strong>Note:</strong> Custom range overrides preset selections.
            </div>
        </div>

        <div class="section">
            <p class="section-title">Report Content</p>
            <div class="check-row">
                <label class="check-item">
                    <input type="checkbox" name="show_triggers" value="1">
                    🔔 Show Triggers
                </label>
                <label class="check-item">
                    <input type="checkbox" name="show_trends" id="show_trends" value="1">
                    📈 Availability Report
                </label>
                <label class="check-item">
                    <input type="checkbox" name="include_graphs" id="include_graphs" value="1">
                    📊 Include Graphs
                </label>
                <label class="check-item disabled">
                    <input type="checkbox" name="show_items_status" disabled>
                    📋 Items Status (in progress)
                </label>
            </div>

            <div id="availExportSection">
                <p>📤 Where should the Availability Report go?</p>
                <div class="radio-row">
                    <label class="radio-card " id="rc_pdf" onclick="selectExport('pdf')">
                        <input type="radio" name="avail_export" value="pdf" >
                        <div class="rc-icon">📄</div>
                        <div class="rc-title">Inside PDF</div>
                        <div class="rc-desc">Included in main report</div>
                    </label>
                    <label class="radio-card " id="rc_excel" onclick="selectExport('excel')">
                        <input type="radio" name="avail_export" value="excel" id="avail_excel" >
                        <div class="rc-icon">📊</div>
                        <div class="rc-title">Separate Excel</div>
                        <div class="rc-desc">Download as .xlsx</div>
                    </label>
                    <!-- <label class="radio-card disabled" id="rc_both" onclick="selectExport('both')">
                        <input type="radio" name="avail_export" value="both" id="avail_both" disabled>
                        <div class="rc-icon">📦</div>
                        <div class="rc-text">
                            <div class="rc-title disabled">Both Files</div>
                            <div class="rc-desc">PDF + Excel file</div>
                        </div>
                    </label> -->
                </div>
            </div>
        </div>

        <div class="section" id="graphTypeSection">
            <p class="section-title">Graph Categories</p>
            <div class="card-grid">
                <div class="graph-card active" id="card_cpu" onclick="toggleCard('cpu')">
                    <input type="checkbox" name="graph_types[]" value="cpu" id="chk_cpu" onclick="event.stopPropagation(); syncCard('cpu')">
                    <div class="card-icon">🖥️</div>
                    <div class="card-name">CPU Utilization</div>
                </div>
                <div class="graph-card active" id="card_memory" onclick="toggleCard('memory')">
                    <input type="checkbox" name="graph_types[]" value="memory" id="chk_memory" onclick="event.stopPropagation(); syncCard('memory')">
                    <div class="card-icon">💾</div>
                    <div class="card-name">Memory Utilization</div>
                </div>
                <div class="graph-card active" id="card_disk" onclick="toggleCard('disk')">
                    <input type="checkbox" name="graph_types[]" value="disk" id="chk_disk" onclick="event.stopPropagation(); syncCard('disk')">
                    <div class="card-icon">🗄️</div>
                    <div class="card-name">Disk Space</div>
                </div>
                <div class="graph-card active" id="card_ping" onclick="toggleCard('ping')">
                    <input type="checkbox" name="graph_types[]" value="ping" id="chk_ping" onclick="event.stopPropagation(); syncCard('ping')">
                    <div class="card-icon">📡</div>
                    <div class="card-name">Ping / ICMP</div>
                </div>
                <div class="graph-card active" id="card_traffic" onclick="toggleCard('traffic')">
                    <input type="checkbox" name="graph_types[]" value="traffic" id="chk_traffic" onclick="event.stopPropagation(); syncCard('traffic')">
                    <div class="card-icon">🌐</div>
                    <div class="card-name">Network Traffic</div>
                </div>
                <div class="graph-card" id="card_other" onclick="toggleCard('other')">
                    <input type="checkbox" name="graph_types[]" value="other" id="chk_other" onclick="event.stopPropagation(); syncCard('other')">
                    <div class="card-icon">📊</div>
                    <div class="card-name">Other Graphs</div>
                </div>
            </div>
            <div style="margin-top: 12px; display: flex; gap: 10px;">
                <button type="button" class="btn-sm btn-filled" onclick="setAllCards(true)">Select All</button>
                <button type="button" class="btn-sm btn-outline" onclick="setAllCards(false)">Clear All</button>
            </div>
        </div>

        <button type="submit" class="btn-submit" id="submitBtn" disabled>
            ⬇ Generate Report
        </button>

    </form>

</div>

<script src="assets/js/dashboard.js"></script>
</body>
</html>