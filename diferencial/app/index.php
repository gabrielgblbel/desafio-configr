<?php
date_default_timezone_set('America/Sao_Paulo');

$db_host    = getenv('MYSQL_HOST')     ?: 'mariadb';
$db_name    = getenv('MYSQL_DATABASE') ?: 'appdb';
$db_user    = getenv('MYSQL_USER')     ?: 'appuser';
$db_pass    = getenv('MYSQL_PASSWORD') ?: 'apppass';

$db_ok      = false;
$db_error   = '';
$db_version = '';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass, [PDO::ATTR_TIMEOUT => 3]);
    $db_ok = true;
    $row = $pdo->query('SELECT VERSION() AS v')->fetch();
    $db_version = $row['v'] ?? '';
} catch (PDOException $e) {
    $db_error = htmlspecialchars($e->getMessage());
}

$php_version = PHP_VERSION;
$nginx_ver   = $_SERVER['SERVER_SOFTWARE'] ?? 'nginx';
$hostname    = gethostname();
$mem_limit   = ini_get('memory_limit');
$timestamp   = date('d/m/Y H:i:s');
$ts_unix     = time();
$os          = php_uname('s') . ' ' . php_uname('r');

$uptime_sec = 0;
$uptime     = '—';
if (file_exists('/proc/uptime')) {
    $uptime_sec = (int)(float) explode(' ', file_get_contents('/proc/uptime'))[0];
    $d          = floor($uptime_sec / 86400);
    $h          = floor(($uptime_sec % 86400) / 3600);
    $m          = floor(($uptime_sec % 3600) / 60);
    $uptime     = "{$d}d {$h}h {$m}m";
}

$load = '—';
if (function_exists('sys_getloadavg')) {
    $avg  = sys_getloadavg();
    $load = number_format($avg[0], 2) . '  ' . number_format($avg[1], 2) . '  ' . number_format($avg[2], 2);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Stack Monitor — Configr</title>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600;700&family=Outfit:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --bg:      #060910;
  --panel:   #0c1018;
  --card:    #101620;
  --border:  #1a2540;
  --accent:  #00e8a2;
  --blue:    #38a8ff;
  --red:     #ff4466;
  --yellow:  #ffcc44;
  --purple:  #a78bfa;
  --text:    #dde6ff;
  --muted:   #8899bb;
  --white:   #ffffff;
  --mono:    'JetBrains Mono', monospace;
  --sans:    'Outfit', sans-serif;
}

html, body {
  background: var(--bg);
  color: var(--text);
  font-family: var(--sans);
  font-size: 15px;
  line-height: 1.5;
  min-height: 100vh;
}

/* ─── HEADER ─── */
header {
  background: var(--panel);
  border-bottom: 1px solid var(--border);
  padding: 0 3rem;
  height: 60px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  position: sticky;
  top: 0;
  z-index: 100;
}

.brand {
  display: flex;
  align-items: center;
  gap: .75rem;
}

.brand-icon {
  width: 34px; height: 34px;
  border: 2px solid var(--accent);
  border-radius: 7px;
  display: grid;
  place-items: center;
  box-shadow: 0 0 14px rgba(0,232,162,.25);
}

.brand-icon svg { width: 18px; height: 18px; }

.brand-name {
  font-family: var(--sans);
  font-size: 1.05rem;
  font-weight: 800;
  color: var(--white);
  letter-spacing: .02em;
}

.brand-sub {
  font-size: .75rem;
  color: var(--muted);
  letter-spacing: .12em;
  text-transform: uppercase;
  margin-left: .5rem;
  font-weight: 400;
}

.header-meta {
  display: flex;
  gap: 3rem;
  font-family: var(--mono);
  font-size: .8rem;
}

.meta-item .label {
  font-size: .65rem;
  text-transform: uppercase;
  letter-spacing: .14em;
  color: var(--muted);
}

.meta-item .value {
  color: var(--text);
  font-weight: 600;
  margin-top: 1px;
}

/* ─── PAGE ─── */
.page {
  padding: 2.5rem 3rem;
  max-width: 1600px;
  margin: 0 auto;
}

/* ─── SECTION HEADING ─── */
.section-label {
  font-family: var(--mono);
  font-size: .7rem;
  letter-spacing: .25em;
  text-transform: uppercase;
  color: var(--muted);
  margin-bottom: 1rem;
  display: flex;
  align-items: center;
  gap: .75rem;
}
.section-label::after {
  content: '';
  flex: 1;
  height: 1px;
  background: var(--border);
}

/* ─── SERVICES GRID ─── */
.services {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 1.25rem;
  margin-bottom: 2.5rem;
}

.card {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 1.5rem;
  position: relative;
  overflow: hidden;
  animation: fadeUp .35s ease both;
}

.card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 3px;
  border-radius: 10px 10px 0 0;
}

.card.c-blue::before   { background: var(--blue);   box-shadow: 0 0 12px rgba(56,168,255,.5); }
.card.c-green::before  { background: var(--accent);  box-shadow: 0 0 12px rgba(0,232,162,.5); }
.card.c-red::before    { background: var(--red);     box-shadow: 0 0 12px rgba(255,68,102,.5); }

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 1.25rem;
}

.svc-name {
  font-family: var(--sans);
  font-weight: 700;
  font-size: 1.15rem;
  color: var(--white);
  letter-spacing: .01em;
}

.svc-role {
  font-size: .72rem;
  text-transform: uppercase;
  letter-spacing: .1em;
  color: var(--muted);
  margin-top: 3px;
}

.badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 12px;
  border-radius: 100px;
  font-size: .72rem;
  font-weight: 700;
  letter-spacing: .08em;
  text-transform: uppercase;
  white-space: nowrap;
}

.badge.ok  { background: rgba(0,232,162,.12); color: var(--accent); border: 1px solid rgba(0,232,162,.35); }
.badge.err { background: rgba(255,68,102,.12); color: var(--red);   border: 1px solid rgba(255,68,102,.35); }

.dot {
  width: 7px; height: 7px;
  border-radius: 50%;
  background: currentColor;
  flex-shrink: 0;
}

.badge.ok .dot { animation: pulse 2s ease-in-out infinite; }

@keyframes pulse {
  0%,100% { opacity: 1; transform: scale(1); }
  50%      { opacity: .35; transform: scale(.7); }
}

.card-rows { display: flex; flex-direction: column; }

.card-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: .55rem 0;
  border-top: 1px solid var(--border);
  gap: 1rem;
}

.card-row .k {
  font-family: var(--mono);
  font-size: .8rem;
  color: var(--muted);
  white-space: nowrap;
  flex-shrink: 0;
}

.card-row .v {
  font-family: var(--mono);
  font-size: .85rem;
  color: var(--text);
  text-align: right;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.card-row .v.green  { color: var(--accent); }
.card-row .v.yellow { color: var(--yellow); }
.card-row .v.red    { color: var(--red); }
.card-row .v.blue   { color: var(--blue); }

/* ─── INFO GRID ─── */
.info-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 1.25rem;
  margin-bottom: 2.5rem;
  align-items: stretch;
}

.info-card {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 1.25rem 1.5rem;
  animation: fadeUp .35s ease both;
  overflow: visible;
  height: auto;
}

.info-card .ik {
  font-family: var(--mono);
  font-size: .68rem;
  text-transform: uppercase;
  letter-spacing: .15em;
  color: var(--muted);
  margin-bottom: .4rem;
}

.info-card .iv {
  font-family: var(--mono);
  font-size: 1rem;
  font-weight: 700;
  color: var(--white);
  line-height: 1.3;
}

.info-card .isub {
  font-family: var(--mono);
  font-size: .7rem;
  color: var(--muted);
  margin-top: 4px;
}

/* ─── PHPINFO ─── */
.toggle-btn {
  background: var(--card);
  border: 1px solid var(--border);
  color: var(--muted);
  font-family: var(--mono);
  font-size: .8rem;
  padding: .6rem 1.25rem;
  border-radius: 7px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: .6rem;
  letter-spacing: .06em;
  transition: color .15s, border-color .15s, background .15s;
}

.toggle-btn:hover {
  color: var(--text);
  border-color: rgba(0,232,162,.4);
  background: rgba(0,232,162,.05);
}

.arrow { transition: transform .25s; display: inline-block; }
.toggle-btn.open .arrow { transform: rotate(90deg); }

.phpinfo-wrap {
  display: none;
  margin-top: 1rem;
  border: 1px solid var(--border);
  border-radius: 10px;
  overflow: hidden;
  background: #fff;
  width: 100%;
}
.phpinfo-wrap.open { display: block; }

.phpinfo-wrap iframe {
  width: 100%;
  border: none;
  display: block;
  min-height: 100px;
}

/* ─── FOOTER ─── */
footer {
  margin-top: 3rem;
  border-top: 1px solid var(--border);
  padding: 2.5rem 3rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: .75rem;
  text-align: center;
}

.footer-brand {
  font-family: var(--sans);
  font-size: 1rem;
  font-weight: 700;
  color: var(--white);
  letter-spacing: .02em;
}

.footer-brand span {
  color: var(--accent);
}

.footer-divider {
  width: 48px;
  height: 2px;
  background: linear-gradient(90deg, transparent, var(--accent), transparent);
  border-radius: 2px;
}

.footer-meta {
  font-family: var(--mono);
  font-size: .72rem;
  color: var(--muted);
  letter-spacing: .1em;
  text-transform: uppercase;
}

.footer-candidate {
  font-family: var(--mono);
  font-size: .75rem;
  color: var(--text);
  letter-spacing: .06em;
}

/* ─── ANIMATIONS ─── */
@keyframes fadeUp {
  from { opacity: 0; transform: translateY(8px); }
  to   { opacity: 1; transform: translateY(0); }
}

.card:nth-child(1) { animation-delay: .05s; }
.card:nth-child(2) { animation-delay: .10s; }
.card:nth-child(3) { animation-delay: .15s; }
.info-card:nth-child(1) { animation-delay: .18s; }
.info-card:nth-child(2) { animation-delay: .22s; }
.info-card:nth-child(3) { animation-delay: .26s; }
.info-card:nth-child(4) { animation-delay: .30s; }
</style>
</head>
<body>

<header>
  <div class="brand">
    <div class="brand-icon">
      <svg viewBox="0 0 18 18" fill="none" stroke="#00e8a2" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <rect x="1" y="2" width="16" height="11" rx="2"/>
        <path d="M6 16h6M9 13v3"/>
        <circle cx="5.5" cy="7.5" r="1" fill="#00e8a2" stroke="none"/>
        <circle cx="9" cy="7.5" r="1" fill="#00e8a2" stroke="none"/>
        <circle cx="12.5" cy="7.5" r="1" fill="#00e8a2" stroke="none"/>
      </svg>
    </div>
    <div>
      <span class="brand-name">Stack Monitor</span>
      <span class="brand-sub">/ Configr Dev</span>
    </div>
  </div>
  <div class="header-meta">
    <div class="meta-item">
      <div class="label">Host</div>
      <div class="value"><?= htmlspecialchars($hostname) ?></div>
    </div>
    <div class="meta-item">
      <div class="label">Horário (BRT)</div>
      <div class="value" id="clock"><?= $timestamp ?></div>
    </div>
  </div>
</header>

<div class="page">

  <div class="section-label">Serviços</div>

  <div class="services">

    <!-- Nginx -->
    <div class="card c-blue">
      <div class="card-header">
        <div>
          <div class="svc-name">Nginx</div>
          <div class="svc-role">Web Server</div>
        </div>
        <div class="badge ok"><span class="dot"></span> Online</div>
      </div>
      <div class="card-rows">
        <div class="card-row"><span class="k">Software</span><span class="v blue"><?= htmlspecialchars($nginx_ver) ?></span></div>
        <div class="card-row"><span class="k">Porta</span><span class="v yellow">:80</span></div>
        <div class="card-row"><span class="k">Protocolo</span><span class="v"><?= $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1' ?></span></div>
      </div>
    </div>

    <!-- PHP-FPM -->
    <div class="card c-green">
      <div class="card-header">
        <div>
          <div class="svc-name">PHP-FPM</div>
          <div class="svc-role">FastCGI Process Manager</div>
        </div>
        <div class="badge ok"><span class="dot"></span> Online</div>
      </div>
      <div class="card-rows">
        <div class="card-row"><span class="k">Versão</span><span class="v green"><?= $php_version ?></span></div>
        <div class="card-row"><span class="k">SAPI</span><span class="v"><?= php_sapi_name() ?></span></div>
        <div class="card-row"><span class="k">Memory limit</span><span class="v yellow"><?= $mem_limit ?></span></div>
      </div>
    </div>

    <!-- MariaDB -->
    <div class="card <?= $db_ok ? 'c-green' : 'c-red' ?>">
      <div class="card-header">
        <div>
          <div class="svc-name">MariaDB</div>
          <div class="svc-role">Database Server</div>
        </div>
        <?php if ($db_ok): ?>
          <div class="badge ok"><span class="dot"></span> Online</div>
        <?php else: ?>
          <div class="badge err"><span class="dot"></span> Offline</div>
        <?php endif; ?>
      </div>
      <div class="card-rows">
        <div class="card-row">
          <span class="k">Versão</span>
          <span class="v <?= $db_ok ? 'green' : 'red' ?>"><?= $db_ok ? htmlspecialchars($db_version) : 'indisponível' ?></span>
        </div>
        <div class="card-row"><span class="k">Database</span><span class="v"><?= htmlspecialchars($db_name) ?></span></div>
        <div class="card-row"><span class="k">Host</span><span class="v"><?= htmlspecialchars($db_host) ?></span></div>
      </div>
    </div>

  </div>

  <div class="section-label">Sistema</div>

  <div class="info-grid">
    <div class="info-card">
      <div class="ik">Sistema Operacional</div>
      <div class="iv" style="font-size:.82rem;line-height:1.4"><?= htmlspecialchars($os) ?></div>
    </div>
    <div class="info-card">
      <div class="ik">Uptime</div>
      <div class="iv" id="uptime-display"><?= $uptime ?></div>
      <div class="isub">desde último boot</div>
    </div>
    <div class="info-card" style="position:relative">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem">
        <div class="ik" style="margin-bottom:0">Load Average</div>
        <button onclick="toggleLoadInfo()" style="background:rgba(56,168,255,.12);border:1px solid rgba(56,168,255,.3);color:#38a8ff;border-radius:50%;width:20px;height:20px;font-size:.65rem;font-weight:700;cursor:pointer;line-height:1;padding:0;flex-shrink:0">?</button>
      </div>
      <div id="load-info" style="display:none;position:absolute;top:2.5rem;right:0;z-index:200;width:260px;background:#0d1a2e;border:1px solid rgba(56,168,255,.35);border-radius:10px;padding:.9rem 1rem;font-family:var(--mono);font-size:.72rem;line-height:1.7;color:var(--text);box-shadow:0 8px 32px rgba(0,0,0,.6)">
        Média de processos aguardando CPU.<br>
        Ideal: valor &lt; nº de CPUs do servidor.<br><br>
        <span style="color:#00e8a2">●</span> &lt; 0.5 — ocioso<br>
        <span style="color:#ffcc44">●</span> &lt; 1.0 — moderado<br>
        <span style="color:#ff4466">●</span> ≥ 1.0 — alto
      </div>
      <div style="display:flex;gap:1.25rem;margin-top:.25rem">
        <div style="display:flex;flex-direction:column;align-items:center;gap:3px">
          <span id="load-1" class="iv" style="font-size:1rem"><?= number_format(sys_getloadavg()[0] ?? 0, 2) ?></span>
          <span class="isub">1 min</span>
        </div>
        <div style="display:flex;flex-direction:column;align-items:center;gap:3px">
          <span id="load-5" class="iv" style="font-size:1rem"><?= number_format(sys_getloadavg()[1] ?? 0, 2) ?></span>
          <span class="isub">5 min</span>
        </div>
        <div style="display:flex;flex-direction:column;align-items:center;gap:3px">
          <span id="load-15" class="iv" style="font-size:1rem"><?= number_format(sys_getloadavg()[2] ?? 0, 2) ?></span>
          <span class="isub">15 min</span>
        </div>
      </div>
    </div>
    <div class="info-card">
      <div class="ik">Timezone</div>
      <div class="iv"><?= date_default_timezone_get() ?></div>
      <div class="isub"><?= date('P') ?> UTC offset</div>
    </div>
  </div>

  <div class="section-label">PHP Info</div>

  <button class="toggle-btn" id="toggle-btn" onclick="togglePhp()">
    <span class="arrow">▶</span>
    <span id="toggle-label">Expandir phpinfo()</span>
  </button>

  <div class="phpinfo-wrap" id="phpinfo-wrap">
    <iframe id="phpinfo-frame" src="/phpinfo_raw.php" title="phpinfo()" scrolling="no"></iframe>
  </div>

</div>

<footer>
  <div class="footer-brand">Stack <span>Monitor</span></div>
  <div class="footer-divider"></div>
  <div class="footer-meta">Desafio Técnico · Configr / Umbler · 2026</div>
  <div class="footer-candidate">Gabriel da Silva Araujo</div>
</footer>

<script>
// Relógio em tempo real — parte do timestamp do servidor (BRT)
(function() {
  var serverTs = <?= $ts_unix ?> * 1000;
  var diff     = serverTs - Date.now();

  function pad(n) { return String(n).padStart(2, '0'); }

  function tick() {
    var now = new Date(Date.now() + diff);
    var d   = pad(now.getDate()) + '/' + pad(now.getMonth() + 1) + '/' + now.getFullYear();
    var t   = pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
    var c = document.getElementById('clock');
    if (c) c.textContent = d + ' ' + t;
  }

  tick();
  setInterval(tick, 1000);
})();

// Load average — atualiza a cada 5s via fetch
(function() {
  function loadColor(v) {
    if (v < 0.5)  return '#00e8a2'; // verde
    if (v < 1.0)  return '#ffcc44'; // amarelo
    return '#ff4466';               // vermelho
  }

  function updateLoad() {
    fetch('/load.php')
      .then(function(r) { return r.json(); })
      .then(function(d) {
        var e1  = document.getElementById('load-1');
        var e5  = document.getElementById('load-5');
        var e15 = document.getElementById('load-15');
        if (e1)  { e1.textContent  = d.l1.toFixed(2);  e1.style.color  = loadColor(d.l1);  }
        if (e5)  { e5.textContent  = d.l5.toFixed(2);  e5.style.color  = loadColor(d.l5);  }
        if (e15) { e15.textContent = d.l15.toFixed(2); e15.style.color = loadColor(d.l15); }
      })
      .catch(function() {});
  }

  updateLoad();
  setInterval(updateLoad, 5000);
})();

// Uptime em tempo real — conta a partir dos segundos do servidor
(function() {
  var secs = <?= $uptime_sec ?>;

  function fmtUptime(s) {
    var d = Math.floor(s / 86400);
    var h = Math.floor((s % 86400) / 3600);
    var m = Math.floor((s % 3600) / 60);
    var sec = s % 60;
    return d + 'd ' + h + 'h ' + m + 'm ' + sec + 's';
  }

  var el = document.getElementById('uptime-display');
  if (el) {
    setInterval(function() {
      secs++;
      el.textContent = fmtUptime(secs);
    }, 1000);
  }
})();

function toggleLoadInfo() {
  var el = document.getElementById('load-info');
  el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

function togglePhp() {
  const btn   = document.getElementById('toggle-btn');
  const wrap  = document.getElementById('phpinfo-wrap');
  const frame = document.getElementById('phpinfo-frame');
  const label = document.getElementById('toggle-label');
  const open  = wrap.classList.toggle('open');
  btn.classList.toggle('open', open);
  label.textContent = open ? 'Recolher phpinfo()' : 'Expandir phpinfo()';

  if (open) {
    frame.onload = function() {
      try {
        const doc = frame.contentDocument || frame.contentWindow.document;
        const h   = Math.max(doc.body.scrollHeight, doc.documentElement.scrollHeight);
        frame.style.height = (h + 40) + 'px';
      } catch(e) {
        frame.style.height = '4000px';
      }
    };
    // Se já carregou antes, disparar manualmente
    try {
      const doc = frame.contentDocument || frame.contentWindow.document;
      if (doc && doc.readyState === 'complete') {
        const h = Math.max(doc.body.scrollHeight, doc.documentElement.scrollHeight);
        if (h > 100) frame.style.height = (h + 40) + 'px';
      }
    } catch(e) {}
  }
}
</script>

</body>
</html>
