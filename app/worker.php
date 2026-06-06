<?php
/**
 * worker.php  —  Detached background tile processor.
 * CLI only: php worker.php {job_id} {project_root}
 *
 * Spawned by action=spawn in process.php. Reads params from meta.json,
 * tiles all 6 cube faces, then marks the job done in the same file.
 */

if (php_sapi_name() !== 'cli') exit(1);

$id      = $argv[1] ?? '';
$baseDir = rtrim($argv[2] ?? '', '/\\');

if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $id) || $baseDir === '') exit(1);

define('IMAGES_DIR', $baseDir . '/images');

require_once $baseDir . '/app/config.php';
require_once $baseDir . '/app/classes/TilerBase.php';
require_once $baseDir . '/app/classes/AvanselTiler.php';
require_once $baseDir . '/app/classes/KrpanoTiler.php';
require_once $baseDir . '/app/classes/MarzipanoTiler.php';
require_once $baseDir . '/app/classes/PannellumTiler.php';

if (defined('MEMORY_LIMIT') && MEMORY_LIMIT !== '') {
	ini_set('memory_limit', MEMORY_LIMIT);
} else {
	ini_set('memory_limit', '1024M');
}

$outDir   = IMAGES_DIR . "/$id";
$metaFile = $outDir . '/meta.json';

// ── Helpers ───────────────────────────────────────────────────────────────────

function readMeta(string $file): array
{
	return json_decode(file_get_contents($file), true) ?? [];
}

function writeMeta(string $file, array $data): void
{
	file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

function updateProgress(string $metaFile, int $pct, string $step): void
{
	$d = readMeta($metaFile);
	$d['progress'] = $pct;
	$d['step']     = $step;
	writeMeta($metaFile, $d);
}

function make_worker_tiler(string $viewer): TilerBase
{
	return match ($viewer) {
		'avansel'   => new AvanselTiler(),
		'krpano'    => new KrpanoTiler(),
		'marzipano' => new MarzipanoTiler(),
		default     => new PannellumTiler(),
	};
}

// ── Main processing ───────────────────────────────────────────────────────────

$meta = readMeta($metaFile);

$meta['status'] = 'processing';
$meta['step']   = 'Starting…';
writeMeta($metaFile, $meta);

try {
	$viewer = $meta['viewer'] ?? 'pannellum';
	$tiler  = make_worker_tiler($viewer);

	$faces     = ['f', 'b', 'r', 'l', 'u', 'd'];
	$faceNames = ['f' => 'Front', 'b' => 'Back', 'r' => 'Right',
				  'l' => 'Left',  'u' => 'Up',   'd' => 'Down'];
	$facePcts  = [0, 14, 28, 42, 56, 70];

	foreach ($faces as $i => $face) {
		// Report progress before starting each face so label reflects current work
		updateProgress($metaFile, $facePcts[$i], "Tiling {$faceNames[$face]} face…");
		$result = $tiler->processFace($outDir, $face, $outDir);
		if (!$result) {
			throw new RuntimeException("Tiling failed for face '$face'");
		}
	}

	updateProgress($metaFile, 85, 'Finalizing…');
	$multires = $tiler->finalize($outDir, $outDir);

	// Delete raw face files now that tiles are generated
	foreach ($faces as $f) {
		@unlink($outDir . '/' . $f . '.jpg');
	}

	// Write multires + status=done in one atomic update — meta.json is fully
	// complete before status becomes 'done', so any reload finds it ready
	$meta             = readMeta($metaFile);
	$meta['multires'] = $multires;
	$meta['status']   = 'done';
	$meta['progress'] = 100;
	$meta['step']     = 'Processing complete.';
	$meta['url']      = "images/$id/";
	$meta['finished'] = date('c');
	writeMeta($metaFile, $meta);

} catch (Throwable $e) {
	$meta            = readMeta($metaFile);
	$meta['status']  = 'error';
	$meta['step']    = 'Error: ' . $e->getMessage();
	$meta['error']   = $e->getMessage();
	writeMeta($metaFile, $meta);
	exit(1);
}
