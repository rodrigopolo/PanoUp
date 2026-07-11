<?php

define('APP_DIR',    __DIR__);
define('IMAGES_DIR', dirname(__DIR__) . '/images');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/gpano_math.php';

// Defensively parses a GPano numeric field; null if missing, non-numeric, or out of range.
function gpano_float($value, float $min, float $max): ?float {
	if ($value === null || $value === '' || !is_numeric($value)) return null;
	$f = (float)$value;
	if (is_nan($f) || is_infinite($f) || $f < $min || $f > $max) return null;
	return $f;
}

// Normalizes a degree delta into (-180, 180], for pano-relative yaw.
function gpano_normalize_deg(float $deg): float {
	$deg = fmod($deg, 360.0);
	if ($deg <= -180.0) $deg += 360.0;
	if ($deg > 180.0)   $deg -= 360.0;
	return $deg;
}

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

// Photo Sphere XMP-GPano — initial view + heading (optional; viewer templates
// fall back to their own hardcoded defaults when these stay null).
// exifr (with mergeOutput's default of true) flattens GPano XMP tags directly
// into the top-level exif object rather than nesting them under a "GPano" key.
$gpano = is_array($meta['exif'] ?? null) ? $meta['exif'] : [];

$poseHeading = gpano_float($gpano['PoseHeadingDegrees'] ?? null, 0, 360);
$posePitch   = gpano_float($gpano['PosePitchDegrees'] ?? null, -90, 90);
$poseRoll    = gpano_float($gpano['PoseRollDegrees'] ?? null, -180, 180);
$viewHeading = gpano_float($gpano['InitialViewHeadingDegrees'] ?? null, 0, 360);
$viewPitch   = gpano_float($gpano['InitialViewPitchDegrees'] ?? null, -90, 90);
$viewRoll    = gpano_float($gpano['InitialViewRollDegrees'] ?? null, -180, 180);
$viewHfov    = gpano_float($gpano['InitialHorizontalFOVDegrees'] ?? null, 1, 360);

// Raw Pose passthrough — feeds each viewer's own horizon-tilt reprojection
// primitive (Pannellum horizonPitch/horizonRoll, krpano's roll-only
// prealign) and the compass/scene-heading indicator. Independent of the
// initial-view values below. Per-viewer sign flips (e.g. Pannellum negates
// horizonRoll) are applied in the template that needs them, not here — see
// app/templates/pannellum.php.
$panoHeading      = $poseHeading; // north offset / compass (Pannellum, krpano <scene heading>)
$panoHorizonPitch = $posePitch;   // Pannellum horizonPitch / Avansel mesh.rotation passthrough (no negation)
$panoHorizonRoll  = $poseRoll;    // raw PoseRollDegrees; krpano prealign / Avansel mesh.rotation (no negation); Pannellum negates it inline

// Local (pano-relative) yaw/pitch/roll for viewers with their OWN Pose-
// correction primitive (Pannellum horizonPitch/horizonRoll, krpano prealign
// rx/rz, Avansel's mesh.rotation hack — see app/templates/avansel.php).
// Per the authoritative reference implementation
// (https://github.com/rodrigopolo/360PanoMeta, js/viewer.js buildViewer())
// — a browser tool that live-renders Pannellum while editing GPano tags,
// tested against 15 reference images
// (https://github.com/rodrigopolo/360GPanoReference) — heading is the only
// axis that needs manual reconciliation between InitialView's compass frame
// and the image's raw column-0 (a pure Z-axis rotation, so it commutes
// cleanly). Pitch and roll are NOT composed with Pose: they're corrected
// separately at the texture level (§3.2/§4 below), so InitialView pitch/
// roll pass through raw. See GPANO.md §5 for why this superseded an earlier
// full rotation-matrix composition.
$panoInitialYaw   = $viewHeading !== null ? gpano_normalize_deg($viewHeading - ($poseHeading ?? 0)) : null;
$panoInitialPitch = $viewPitch;
$panoInitialRoll  = $viewRoll;
$panoInitialHfov  = $viewHfov;

// Marzipano-only: it has no Pose-correction primitive of its own (no
// horizonPitch/horizonRoll equivalent, no exposed geometry transform), so
// Pose has to be folded directly into the camera's initial yaw/pitch/roll
// via real rotation composition (gpano_math.php) instead of the simple
// formula above. See GPANO.md §4.4 for why this is the right tool here even
// though the same composition was deliberately removed project-wide
// earlier (it was wrong for viewers that already reproject Pose separately
// — Marzipano is the one viewer that doesn't).
$marzipanoInitialYaw = $marzipanoInitialPitch = $marzipanoInitialRoll = null;
if ($viewHeading !== null || $viewPitch !== null || $viewRoll !== null) {
	$pose = ['heading' => $poseHeading ?? 0, 'pitch' => $posePitch ?? 0, 'roll' => $poseRoll ?? 0];
	$view = ['heading' => $viewHeading ?? 0, 'pitch' => $viewPitch ?? 0, 'roll' => $viewRoll ?? 0];
	$local = gpano_world_view_to_local($pose, $view);
	$marzipanoInitialYaw   = gpano_normalize_deg($local['heading']);
	$marzipanoInitialPitch = $local['pitch'];
	$marzipanoInitialRoll  = $local['roll'];
}

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
