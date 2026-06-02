<?php
// Variables from template_loader.php: $panoId, $siteRoot, $panoURL, $job
header('Cache-Control: no-store');
header('Pragma: no-cache');
header('Expires: 0');

$pageTitle  = htmlspecialchars($job['title'] ?? 'Processing…', ENT_QUOTES, 'UTF-8');
$initPct    = (int)($job['progress'] ?? 0);
$initStep   = htmlspecialchars($job['step'] ?? 'Processing…', ENT_QUOTES, 'UTF-8');
$statusBase = rtrim($siteRoot, '/') . '/api?action=status&id=' . (int)$panoId;
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?= $pageTitle ?> — Processing</title>
  <style>
    :root {
      --bg:             #1c1c1c;
      --bg-raised:      #252525;
      --bg-elevated:    #2f2f2f;
      --border:         #454545;
      --blue:           #4a9eff;
      --blue-dim:       #2870c8;
      --blue-glow:      rgba(74,158,255,0.12);
      --red:            #e05252;
      --text-primary:   #f0f0f0;
      --text-secondary: #9a9a9a;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      background: var(--bg);
      color: var(--text-primary);
      font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .card {
      background: var(--bg-raised);
      border: 1px solid var(--border);
      border-radius: 4px;
      padding: 2.5rem 2rem;
      width: 100%;
      max-width: 480px;
    }
    .badge {
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
      font-family: 'Menlo', 'Consolas', monospace;
      font-weight: 700;
      font-size: 0.65rem;
      letter-spacing: 0.15em;
      text-transform: uppercase;
      color: var(--blue);
      border: 1px solid var(--blue-dim);
      background: var(--blue-glow);
      border-radius: 4px;
      padding: 0.25rem 0.5rem;
      margin-bottom: 1.25rem;
    }
    .spinner {
      width: 0.6rem;
      height: 0.6rem;
      border: 2px solid var(--blue-dim);
      border-top-color: var(--blue);
      border-radius: 50%;
      animation: spin 0.8s linear infinite;
      flex-shrink: 0;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    h1 {
      font-weight: 700;
      font-size: 1.25rem;
      margin-bottom: 0.3rem;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .subtitle {
      font-size: 0.75rem;
      color: var(--text-secondary);
      margin-bottom: 2rem;
    }
    .step-label {
      font-family: 'Menlo', 'Consolas', monospace;
      font-size: 0.75rem;
      color: var(--text-secondary);
      margin-bottom: 0.6rem;
      min-height: 1.1em;
    }
    .progress-track {
      background: var(--bg-elevated);
      border: 1px solid var(--border);
      border-radius: 4px;
      height: 6px;
      overflow: hidden;
    }
    .progress-fill {
      height: 100%;
      background: var(--blue);
      border-radius: 4px;
      transition: width 0.4s ease;
      width: <?= $initPct ?>%;
    }
    .pct-label {
      font-family: 'Menlo', 'Consolas', monospace;
      font-size: 0.7rem;
      color: var(--text-secondary);
      margin-top: 0.5rem;
      text-align: right;
    }
    .error-msg {
      font-family: 'Menlo', 'Consolas', monospace;
      font-size: 0.75rem;
      color: var(--red);
      margin-top: 1.5rem;
      display: none;
    }
  </style>
</head>
<body>
  <div class="card">
    <div class="badge"><span class="spinner"></span>Processing</div>
    <h1><?= $pageTitle ?></h1>
    <p class="subtitle">Generating tiles — this page will refresh automatically.</p>
    <p class="step-label" id="stepLabel"><?= $initStep ?></p>
    <div class="progress-track">
      <div class="progress-fill" id="progressFill"></div>
    </div>
    <p class="pct-label" id="pctLabel"><?= $initPct ?>%</p>
    <p class="error-msg" id="errorMsg"></p>
  </div>

  <script>
    const statusBase = <?= json_encode($statusBase) ?>;
    const fill      = document.getElementById('progressFill');
    const stepLabel = document.getElementById('stepLabel');
    const pctLabel  = document.getElementById('pctLabel');
    const errorMsg  = document.getElementById('errorMsg');

    const timer = setInterval(async () => {
      try {
        const resp = await fetch(statusBase + '&_t=' + Date.now(), { cache: 'no-store' });
        if (!resp.ok) return; // network blip — retry next tick
        const data = await resp.json();

        if (data.error && !data.status) {
          clearInterval(timer);
          errorMsg.textContent = 'Error: ' + data.error;
          errorMsg.style.display = 'block';
          return;
        }

        const pct = data.progress ?? 0;
        fill.style.width      = pct + '%';
        pctLabel.textContent  = pct + '%';
        stepLabel.textContent = data.step || 'Processing…';

        if (data.status === 'done') {
          clearInterval(timer);
          window.location.reload();
        } else if (data.status === 'error') {
          clearInterval(timer);
          errorMsg.textContent = 'Processing failed: ' + (data.error || data.step || 'Unknown error');
          errorMsg.style.display = 'block';
        }
      } catch (_) { /* network hiccup — keep polling */ }
    }, 1500);
  </script>
</body>
</html>
