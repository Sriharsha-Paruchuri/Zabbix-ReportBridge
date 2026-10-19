
var groupChoice, hostChoice;
var CARDS = ['cpu','memory','disk','ping','traffic','other'];
var progressSSE = null;
var timerInterval = null;
var elapsedSeconds = 0;

var STEP_MAP = [
    { id: 'step_init', from: 0, to: 19 },
    { id: 'step_triggers', from: 20, to: 34 },
    { id: 'step_avail', from: 35, to: 54 },
    { id: 'step_graphs', from: 55, to: 99 },
    { id: 'step_done', from: 100, to: 100 }
];

function onRangeChange() {
    var isCustom = document.getElementById('range').value === 'custom';
    document.getElementById('customRange').style.display = isCustom ? 'grid' : 'none';
    document.getElementById('rangeNote').style.display = isCustom ? 'block' : 'none';
    if (!isCustom) {
        document.getElementById('from_date').value = '';
        document.getElementById('to_date').value = '';
    }
}

function selectExport(val) {

    /* set radio checked */
    var radios = document.getElementsByName('avail_export');
    for (var i = 0; i < radios.length; i++) {
        radios[i].checked = (radios[i].value === val);
    }

    /* card highlight */
    ['pdf','excel','both'].forEach(function(v){
        var card = document.getElementById('rc_' + v);
        if (card) card.classList.toggle('selected', v === val);
    });

    var trig = document.querySelector('input[name="show_triggers"]');
    var graph = document.getElementById('include_graphs');

    var trigLabel = trig.closest('label');
    var graphLabel = graph.closest('label');

    var bothRadio = document.querySelector('input[name="avail_export"][value="both"]');
    var bothCard = document.getElementById('rc_both');

    if (val === 'excel') {

        /* disable */
        trig.checked = false;
        graph.checked = false;

        trig.disabled = true;
        graph.disabled = true;

        trigLabel.style.opacity = '0.4';
        graphLabel.style.opacity = '0.4';

        document.getElementById('graphTypeSection').style.display = 'none';

        bothRadio.disabled = true;
        bothCard.style.opacity = '0.4';
        bothCard.style.pointerEvents = 'none';

    } else {

        /* restore instantly */
        trig.disabled = false;
        graph.disabled = false;

        trigLabel.style.opacity = '1';
        graphLabel.style.opacity = '1';

        bothRadio.disabled = false;
        bothCard.style.opacity = '1';
        bothCard.style.pointerEvents = 'auto';

        /* restore graph section if checked */
        if (graph.checked) {
            document.getElementById('graphTypeSection').style.display = 'block';
        }
    }
}

function onAvailToggle() {
    var show = document.getElementById('show_trends').checked;
    document.getElementById('availExportSection').style.display = show ? 'block' : 'none';
}

function onGraphToggle() {
    var show = document.getElementById('include_graphs').checked;
    document.getElementById('graphTypeSection').style.display = show ? 'block' : 'none';
}

function toggleCard(type) {
    var chk = document.getElementById('chk_' + type);
    var card = document.getElementById('card_' + type);
    chk.checked = !chk.checked;
    card.classList.toggle('active', chk.checked);
}

function syncCard(type) {
    var chk = document.getElementById('chk_' + type);
    var card = document.getElementById('card_' + type);
    card.classList.toggle('active', chk.checked);
}

function setAllCards(state) {
    CARDS.forEach(function(t) {
        document.getElementById('chk_' + t).checked = state;
        document.getElementById('card_' + t).classList.toggle('active', state);
    });
}

function updateStepPills(pct) {
    STEP_MAP.forEach(function(s) {
        var el = document.getElementById(s.id);
        if (!el) return;
        el.classList.remove('active','done');
        if (pct >= s.to && s.to < 100) {
            el.classList.add('done');
        } else if (pct >= s.from && pct < s.to + 1) {
            el.classList.add('active');
        }
    });
    if (pct >= 100) {
        STEP_MAP.forEach(function(s) { var el = document.getElementById(s.id); if (el) el.classList.add('done'); });
    }
}

function setProgress(pct, msg, detail) {
    document.getElementById('progFill').style.width = pct + '%';
    document.getElementById('progPct').textContent = pct + '%';
    document.getElementById('progMsg').textContent = msg || '';
    document.getElementById('progDetail').textContent = detail || '\u00a0';
    updateStepPills(pct);
}

function startTimer() {
    elapsedSeconds = 0;
    document.getElementById('progTimer').textContent = '0s';
    timerInterval = setInterval(function() {
        elapsedSeconds++;
        var m = Math.floor(elapsedSeconds / 60);
        var s = elapsedSeconds % 60;
        document.getElementById('progTimer').textContent = (m > 0 ? m + 'm ' : '') + s + 's';
    }, 1000);
}

function stopTimer() {
    if (timerInterval) {
        clearInterval(timerInterval);
        timerInterval = null;
    }
}

function showOverlay() {
    document.getElementById('progressOverlay').classList.add('show');
}

function hideOverlay() {
    document.getElementById('progressOverlay').classList.remove('show');
    document.getElementById('progressCard').classList.remove('done-state');
}

function resetOverlay() {
    setProgress(0, 'Starting…', '');
    document.getElementById('pcTitle').textContent = 'Generating Report…';
    document.getElementById('progressCard').classList.remove('done-state');
    STEP_MAP.forEach(function(s) {
        var el = document.getElementById(s.id);
        if (el) el.classList.remove('active','done');
    });
}
function closePopup() {
    document.getElementById('loginPopup').style.display = 'none';
}
function startProgress() {
    resetOverlay();
    showOverlay();
    startTimer();
    document.getElementById('submitBtn').disabled = true;

    if (progressSSE) {
        progressSSE.close();
        progressSSE = null;
    }

    progressSSE = new EventSource('progress.php');

    progressSSE.onmessage = function(e) {
        try {
            var data = JSON.parse(e.data);
            setProgress(data.pct || 0, data.message || '', data.detail || '');

            if (data.status === 'done') {
                progressSSE.close();
                progressSSE = null;
                stopTimer();
                setProgress(100, 'Report ready — downloading!', '');
                document.getElementById('pcTitle').textContent = '✅ Report Complete!';
                document.getElementById('progressCard').classList.add('done-state');
                
                if (data.download_url) {
                    setTimeout(function() {
                        window.open(data.download_url, '_blank');
                    }, 3000);
                }
                
                setTimeout(function() {
                    hideOverlay();
                    document.getElementById('submitBtn').disabled = false;
                }, 2200);
            }

            if (data.status === 'error' || data.status === 'timeout') {
                progressSSE.close();
                progressSSE = null;
                stopTimer();
                setProgress(0, '⚠ ' + (data.message || 'Error occurred.'), '');
                setTimeout(function() {
                    hideOverlay();
                    document.getElementById('submitBtn').disabled = false;
                }, 3000);
            }
        } catch(ex) {
            console.error('Error:', ex);
        }
    };

    progressSSE.onerror = function() {
        progressSSE && progressSSE.close();
        progressSSE = null;
    };
}

document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('show_trends').addEventListener('change', applyExcelRestrictions);

    applyExcelRestrictions();                     
    groupChoice = new Choices('#groupid', {
        removeItemButton: true,
        searchEnabled: true,
        placeholderValue: 'Search host groups…'
    });
    hostChoice = new Choices('#hostid', {
        removeItemButton: true,
        searchEnabled: true,
        placeholderValue: 'Search hosts…'
    });

    document.getElementById('groupid').addEventListener('change', function () {
        if (groupChoice.getValue(true).length > 0) {
            hostChoice.disable();
        } else {
            hostChoice.enable();
        }

        validateGenerateButton();
        });
    document.getElementById('hostid').addEventListener('change', function () {
            if (hostChoice.getValue(true).length > 0) {
                groupChoice.disable();
            } else {
                groupChoice.enable();
            }

            validateGenerateButton();
        });

    document.getElementById('show_trends').addEventListener('change', onAvailToggle);
    document.getElementById('include_graphs').addEventListener('change', onGraphToggle);

    validateGenerateButton();
    onRangeChange();
    onAvailToggle();
    onGraphToggle();
    CARDS.forEach(function(t) { syncCard(t); });

    document.getElementById('reportForm').addEventListener('submit', function(e){

            var selectedExport = document.querySelector('input[name="avail_export"]:checked');
            var mode = selectedExport ? selectedExport.value : 'pdf';

            if(mode === 'excel'){
                this.action = 'generate.php';
                this.method = 'POST';
                return true;
            }

            e.preventDefault();

            var formData = new FormData(this);

            fetch('generate_async.php',{
                method:'POST',
                body:formData
            })
            .then(r=>r.json())
            .then(data=>{
                if(data.success){
                    startProgress();
                }else{
                    alert(data.error || 'Error');
                }
            });
    });

    window.addEventListener('beforeunload', function() {
        if (progressSSE) {
            progressSSE.close();
            progressSSE = null;
        }
        stopTimer();
    });


});

function validateGenerateButton() {

    var groups = groupChoice.getValue(true).length;
    var hosts  = hostChoice.getValue(true).length;

    var btn = document.getElementById('submitBtn');

    if (groups > 0 || hosts > 0) {
        btn.disabled = false;
        btn.classList.add('enabled');
    } else {
        btn.disabled = true;
        btn.classList.remove('enabled');
    }
}

function selectExport(type) {
    const input = document.getElementById('avail_' + type);
    if (input.disabled) return; // 🚫 stop if disabled
    
    input.checked = true;
    // rest of your logic
}

document.getElementById('avail_excel').addEventListener('change', function () {

    if (this.checked) {

        document.querySelector('input[name="show_triggers"]').checked = false;
        document.getElementById('include_graphs').checked = false;

        document.querySelector('input[name="show_triggers"]').disabled = true;
        document.getElementById('include_graphs').disabled = true;

        document.getElementById('graphTypeSection').style.display = 'none';

        document.getElementById('show_trends').checked = true;
        document.getElementById('show_trends').disabled = true;
    }
});

document.getElementById('avail_pdf').addEventListener('change', resetOptions);
document.getElementById('avail_both').addEventListener('change', resetOptions);

function resetOptions() {
    document.querySelector('input[name="show_triggers"]').disabled = false;
    document.getElementById('include_graphs').disabled = false;
    document.getElementById('show_trends').disabled = false;
}

function handleExcelMode() {

    var availChecked = document.getElementById('show_trends').checked;
    var excelChecked = document.getElementById('avail_excel').checked;

    var trig = document.querySelector('input[name="show_triggers"]');
    var graph = document.getElementById('include_graphs');

    if (availChecked && excelChecked) {

        trig.checked = false;
        graph.checked = false;

        trig.disabled = true;
        graph.disabled = true;

        trig.closest('label').style.opacity = '0.5';
        graph.closest('label').style.opacity = '0.5';

        document.getElementById('graphTypeSection').style.display = 'none';

    } else {

        trig.disabled = false;
        graph.disabled = false;

        trig.closest('label').style.opacity = '1';
        graph.closest('label').style.opacity = '1';
    }
}

/* Events */
document.getElementById('show_trends').addEventListener('change', handleExcelMode);
document.getElementById('avail_excel').addEventListener('change', handleExcelMode);
document.getElementById('avail_pdf').addEventListener('change', handleExcelMode);
document.getElementById('avail_both').addEventListener('change', handleExcelMode);

/* Initial run */
handleExcelMode();


function applyExcelRestrictions() {

    var avail = document.getElementById('show_trends');
    var trig = document.querySelector('input[name="show_triggers"]');
    var graph = document.getElementById('include_graphs');

    var excelRadio = document.querySelector('input[name="avail_export"][value="excel"]');

    if (!avail || !trig || !graph || !excelRadio) return;

    if (avail.checked && excelRadio.checked) {

        trig.checked = false;
        graph.checked = false;

        trig.disabled = true;
        graph.disabled = true;

        trig.closest('label').style.opacity = '0.45';
        graph.closest('label').style.opacity = '0.45';

        document.getElementById('graphTypeSection').style.display = 'none';

    } else {

        trig.disabled = false;
        graph.disabled = false;

        trig.closest('label').style.opacity = '1';
        graph.closest('label').style.opacity = '1';
    }
}
