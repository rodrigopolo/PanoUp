<?php
/**
 * worker.php  —  Detached background tile processor.
 * CLI only: php worker.php {job_id} {project_root}
 *
 * Spawned by action=spawn in process.php. Reads params from job.json,
 * tiles all 6 cube faces, writes meta.json, then marks the job done.
 */

if (php_sapi_name() !== 'cli') exit(1);

$id      = (int)($argv[1] ?? 0);
$baseDir = rtrim($argv[2] ?? '', '/\\');

if ($id <= 0 || $baseDir === '') exit(1);

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

$outDir  = IMAGES_DIR . "/$id";
$jobFile = $outDir . '/job.json';

// ── Helpers ───────────────────────────────────────────────────────────────────

function readJob(string $file): array
{
    return json_decode(file_get_contents($file), true) ?? [];
}

function writeJob(string $file, array $data): void
{
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

function updateProgress(string $jobFile, int $pct, string $step): void
{
    $d = readJob($jobFile);
    $d['progress'] = $pct;
    $d['step']     = $step;
    writeJob($jobFile, $d);
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

$job    = readJob($jobFile);
$params = $job['params'] ?? [];

$job['status'] = 'processing';
$job['step']   = 'Starting…';
writeJob($jobFile, $job);

try {
    $viewer = $params['viewer'] ?? 'pannellum';
    $tiler  = make_worker_tiler($viewer);

    $faces     = ['f', 'b', 'r', 'l', 'u', 'd'];
    $faceNames = ['f' => 'Front', 'b' => 'Back', 'r' => 'Right',
                  'l' => 'Left',  'u' => 'Up',   'd' => 'Down'];
    $facePcts  = [0, 14, 28, 42, 56, 70];

    foreach ($faces as $i => $face) {
        // Report progress before starting each face so label reflects current work
        updateProgress($jobFile, $facePcts[$i], "Tiling {$faceNames[$face]} face…");
        $result = $tiler->processFace($outDir, $face, $outDir);
        if (!$result) {
            throw new RuntimeException("Tiling failed for face '$face'");
        }
    }

    updateProgress($jobFile, 85, 'Finalizing…');
    $multires = $tiler->finalize($outDir, $outDir);

    // Delete raw face files now that tiles are generated
    foreach ($faces as $f) {
        @unlink($outDir . '/' . $f . '.jpg');
    }

    // Write meta.json BEFORE marking job done so any reload triggered by the
    // client always finds meta.json ready
    $meta = [
        'id'       => $id,
        'title'    => $params['title']    ?? '',
        'desc'     => $params['desc']     ?? '',
        'viewer'   => $viewer,
        'lat'      => $params['lat']      ?? '',
        'lng'      => $params['lng']      ?? '',
        'exif'     => $params['exif']     ?? null,
        'cubeface' => $params['cubeface'] ?? null,
        'multires' => $multires,
    ];
    file_put_contents($outDir . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT));

    // Mark job done
    $d = readJob($jobFile);
    $d['status']   = 'done';
    $d['progress'] = 100;
    $d['step']     = 'Processing complete.';
    $d['url']      = "images/$id/";
    $d['finished'] = date('c');
    writeJob($jobFile, $d);

} catch (Throwable $e) {
    $d = readJob($jobFile);
    $d['status'] = 'error';
    $d['step']   = 'Error: ' . $e->getMessage();
    $d['error']  = $e->getMessage();
    writeJob($jobFile, $d);
    exit(1);
}
