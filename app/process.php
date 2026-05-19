<?php
$auth_api_mode = true;
require_once __DIR__ . '/auth.php';

// Load classes
include_once __DIR__ . '/classes/AvanselTiler.php';
include_once __DIR__ . '/classes/KrpanoTiler.php';
include_once __DIR__ . '/classes/MarzipanoTiler.php';
include_once __DIR__ . '/classes/PannellumTiler.php';

// Memory limit
if (defined('MEMORY_LIMIT') && MEMORY_LIMIT !== '') {
    ini_set('memory_limit', MEMORY_LIMIT);
}else{
	ini_set('memory_limit', '1024M');
}

function make_tiler(string $viewer): TilerBase
{
	return match ($viewer) {
		'avansel'   => new AvanselTiler(),
		'krpano'    => new KrpanoTiler(),
		'marzipano' => new MarzipanoTiler(),
		default     => new PannellumTiler(),  // pannellum + any unknown value
	};
}


header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
	http_response_code(200);
	exit;
}

if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
	$_POST = array_merge($_POST, json_decode(file_get_contents('php://input'), true) ?? []);
}

define('IMAGES_DIR',   __DIR__ . '/../images');
define('JPEG_QUALITY', 90);
define('VALID_FACES',  ['f', 'b', 'r', 'l', 'u', 'd']);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
	switch ($action) {
		case 'init':     action_init();     break;
		case 'upload':   action_upload();   break;
		case 'tile':     action_tile();     break;
		case 'finalize': action_finalize(); break;
		default:
			http_response_code(400);
			echo json_encode(['error' => 'Unknown action']);
	}
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode(['error' => $e->getMessage()]);
}


// ── Actions ───────────────────────────────────────────────────────────────────

function action_init(): void {
	$next = next_image_id();
	$dir  = IMAGES_DIR . "/$next";
	if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
		throw new RuntimeException("Cannot create directory: $dir");
	}
	echo json_encode(['id' => $next]);
}

function action_upload(): void {
	$id   = validate_id();
	$face = validate_face();

	if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
		http_response_code(400);
		echo json_encode(['error' => 'Missing or failed image upload']);
		return;
	}

	$tmp  = $_FILES['image']['tmp_name'];
	$info = @getimagesize($tmp);
	if (!$info || $info[2] !== IMAGETYPE_JPEG) {
		http_response_code(400);
		echo json_encode(['error' => 'Uploaded file must be a JPEG']);
		return;
	}

	$dest = IMAGES_DIR . "/$id/{$face}.jpg";
	if (!move_uploaded_file($tmp, $dest)) {
		throw new RuntimeException("Cannot save face file: $dest");
	}

	echo json_encode(['status' => 'ok', 'face' => $face, 'id' => $id]);
}

function action_tile(): void {
	$id     = validate_id();
	$face   = validate_face();
	$viewer = $_GET['viewer'] ?? $_POST['viewer'] ?? 'pannellum';

	$dir  = IMAGES_DIR . "/$id";
	$dest = "$dir/{$face}.jpg";

	if (!file_exists($dest)) {
		http_response_code(400);
		echo json_encode(['error' => "Face file not found: {$face}.jpg"]);
		return;
	}

	$tiler  = make_tiler($viewer);
	$result = $tiler->processFace($dir, $face, $dir);

	if (!$result) {
		http_response_code(500);
		echo json_encode(['error' => "Tiling failed for face '$face'"]);
		return;
	}

	echo json_encode(['status' => 'ok', 'face' => $face, 'id' => $id]);
}

function action_finalize(): void {
	$id     = validate_id();
	$viewer = $_POST['viewer'] ?? 'pannellum';
	$title  = htmlspecialchars(trim($_POST['title'] ?? 'Panorama'), ENT_XML1);
	$desc   = htmlspecialchars(trim($_POST['desc']  ?? ''),         ENT_XML1);
	$lat    = sanitize_coord($_POST['lat'] ?? '');
	$lng    = sanitize_coord($_POST['lng'] ?? '');
	$exif   = isset($_POST['exif']) && is_array($_POST['exif']) ? $_POST['exif'] : null;

	$cubefaceRaw = $_POST['cubeface'] ?? null;
	$cubeface    = is_array($cubefaceRaw) ? $cubefaceRaw
	             : ($cubefaceRaw ? json_decode($cubefaceRaw, true) : null);

	$out_dir = IMAGES_DIR . "/$id";

	// Derive multires metadata — face files must still exist at this point
	$tiler    = make_tiler($viewer);
	$multires = $tiler->finalize($out_dir, $out_dir);

	// Faces are no longer needed; finalize() has already read their dimensions
	foreach (['f', 'b', 'r', 'l', 'u', 'd'] as $f) {
		@unlink($out_dir . '/' . $f . '.jpg');
	}

	$meta = [
		'id'       => $id,
		'title'    => $title,
		'desc'     => $desc,
		'viewer'   => $viewer,
		'lat'      => $lat,
		'lng'      => $lng,
		'exif'     => $exif,
		'cubeface' => $cubeface,
		'multires' => $multires,
	];
	file_put_contents($out_dir . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT));

	echo json_encode([
		'status'   => 'ok',
		'url'      => "images/$id/",
		'multires' => $multires,
	]);
}


// ── Helpers ───────────────────────────────────────────────────────────────────

function next_image_id(): int {
	$max = 0;
	foreach (glob(IMAGES_DIR . '/*/') as $dir) {
		$n = (int) basename($dir);
		if ($n > $max) $max = $n;
	}
	return $max + 1;
}

function validate_id(): int {
	$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
	if ($id <= 0) {
		http_response_code(400);
		echo json_encode(['error' => 'Invalid id']);
		exit;
	}
	return $id;
}

function validate_face(): string {
	$face = $_GET['face'] ?? $_POST['face'] ?? '';
	if (!in_array($face, VALID_FACES, true)) {
		http_response_code(400);
		echo json_encode(['error' => "Invalid face: $face"]);
		exit;
	}
	return $face;
}

function sanitize_coord(string $v): string {
	$v = trim($v);
	return preg_match('/^-?\d+(\.\d+)?$/', $v) ? $v : '';
}
