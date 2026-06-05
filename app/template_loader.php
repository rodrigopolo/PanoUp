<?php

define('APP_DIR',    __DIR__);
define('IMAGES_DIR', dirname(__DIR__) . '/images');

require_once __DIR__ . '/config.php';

// Validate pano_id — reject empty, traversal sequences, and path separators
$panoId = $_GET['pano_id'] ?? '';
if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $panoId)) {
    http_response_code(400);
    exit('Invalid panorama ID.');
}

// Compute siteRoot early — needed by both the processing template and viewer templates
$protocol = (
    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
    (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
) ? 'https://' : 'http://';
$appBase  = dirname(dirname($_SERVER['SCRIPT_NAME']));
$siteRoot = $protocol . $_SERVER['HTTP_HOST'] . rtrim($appBase, '/') . '/';
$panoURL  = $protocol . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

// Read meta.json — contains both job state and viewer metadata
$metaPath = IMAGES_DIR . '/' . $panoId . '/meta.json';
if (!is_file($metaPath)) {
    http_response_code(404);
    exit('Panorama not found.');
}

$meta = json_decode(file_get_contents($metaPath), true);
if (!$meta) {
    http_response_code(500);
    exit('Could not read panorama metadata.');
}

// Gate on job status; absent status means legacy panorama already done
$metaStatus = $meta['status'] ?? 'done';
if ($metaStatus === 'error') {
    http_response_code(500);
    $errMsg = htmlspecialchars($meta['error'] ?? 'Processing failed.', ENT_QUOTES, 'UTF-8');
    exit('Processing failed: ' . $errMsg);
}
if ($metaStatus !== 'done') {
    $job = $meta; // processing.php expects $job with title/progress/step
    include APP_DIR . '/templates/processing.php';
    exit;
}

// Map meta.json fields to template variables
$panoImage        = 'images/' . rawurlencode($panoId) . '/';
$imageTitle       = htmlspecialchars($meta['title'] ?? '', ENT_QUOTES, 'UTF-8');
$imageDescription = htmlspecialchars($meta['desc']  ?? '', ENT_QUOTES, 'UTF-8');
$panoLat          = $meta['lat'] ?? '';
$panoLon          = $meta['lng'] ?? '';
$tileResolution   = (int)($meta['multires']['tileResolution'] ?? 512);
$maxLevel         = (int)($meta['multires']['maxLevel']       ?? 1);
$cubeResolution   = (int)($meta['multires']['cubeResolution'] ?? 512);
$viewer           = $meta['viewer'] ?? '';

// Format panoTiles per viewer expectations
$rawTiles  = $meta['multires']['panoTiles'] ?? '';
switch ($viewer) {
    case 'avansel':
    case 'marzipano':
        // Stored as array in meta.json; templates expect a JS array literal
        $panoTiles = is_array($rawTiles) ? json_encode($rawTiles) : $rawTiles;
        break;
    case 'krpano':
        // Stored as comma-separated string; used verbatim in XML attribute
        $panoTiles = is_string($rawTiles) ? $rawTiles : implode(',', $rawTiles);
        break;
    default:
        $panoTiles = '';
}

// Dispatch
$format = $_GET['format'] ?? '';
if ($format === 'xml' && $viewer === 'krpano') {
    header('Content-Type: application/xml; charset=utf-8');
    include APP_DIR . '/templates/krpano_tour.php';
} else {
    switch ($viewer) {
        case 'avansel':   include APP_DIR . '/templates/avansel.php';   break;
        case 'krpano':    include APP_DIR . '/templates/krpano.php';    break;
        case 'marzipano': include APP_DIR . '/templates/marzipano.php'; break;
        case 'pannellum': include APP_DIR . '/templates/pannellum.php'; break;
        default:
            http_response_code(400);
            exit('Unknown viewer: ' . htmlspecialchars($viewer, ENT_QUOTES, 'UTF-8'));
    }
}
