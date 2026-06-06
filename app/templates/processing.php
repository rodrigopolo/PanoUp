<?php
// Variables from template_loader.php: $panoId, $siteRoot, $panoURL, $job
header('Cache-Control: no-store');
header('Pragma: no-cache');
header('Expires: 0');

$pageTitle  = htmlspecialchars($job['title'] ?? 'Processing…', ENT_QUOTES, 'UTF-8');
$initPct    = (int)($job['progress'] ?? 0);
$initStep   = htmlspecialchars($job['step'] ?? 'Processing…', ENT_QUOTES, 'UTF-8');
$statusBase = rtrim($siteRoot, '/') . '/api?action=status&id=' . rawurlencode($panoId);
$css_href   = htmlspecialchars($siteRoot, ENT_QUOTES, 'UTF-8') . 'public/upload/style.css';
?><!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<title><?= $pageTitle ?> — Processing</title>
	<link rel="stylesheet" href="<?= $css_href ?>" />
</head>
<body>
	<div class="processing-wrap">
		<div class="processing-card">

			<header>
				<h1>Processing</h1>
				<p><?= $pageTitle ?></p>
			</header>

			<div class="card" style="margin-top: 2px;">
				<div class="card-title">
					<span class="spinner"></span>Generating tiles
				</div>
				<div class="card-body">
					<p id="stepLabel" style="font-size:12px; color:var(--text);"><?= $initStep ?></p>
					<div class="progress-track">
						<div class="progress-fill" id="progressFill" style="width:<?= $initPct ?>%"></div>
					</div>
					<p class="pct-label" id="pctLabel"><?= $initPct ?>%</p>
					<p class="error-msg" id="errorMsg"></p>
				</div>
			</div>

			<p style="font-size:11px; color:var(--muted); margin-top:10px; padding:0 2px;">
				This page refreshes automatically when processing is complete.
			</p>

		</div>
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
