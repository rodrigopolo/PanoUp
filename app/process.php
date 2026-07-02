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

// Detect a request PHP rejected for exceeding post_max_size. When this happens,
// PHP silently empties both $_POST and $_FILES before this script ever runs
// (it only logs a warning) — Content-Length still exceeding the configured
// limit while both superglobals are empty is the only way to detect it here.
$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
$postMaxBytes  = ini_to_bytes(ini_get('post_max_size'));
if (
	$_SERVER['REQUEST_METHOD'] === 'POST' &&
	$contentLength > 0 && $postMaxBytes > 0 && $contentLength > $postMaxBytes &&
	empty($_POST) && empty($_FILES)
) {
	http_response_code(413);
	echo json_encode(['error' => sprintf(
		'Upload too large: this request is %.1fMB but the server only accepts up to %.1fMB per request (post_max_size). Ask the site administrator to raise post_max_size/upload_max_filesize in php.ini.',
		$contentLength / 1048576, $postMaxBytes / 1048576
	)]);
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
		case 'spawn':    action_spawn();    break;
		case 'status':   action_status();   break;
		default:
			http_response_code(400);
			echo json_encode(['error' => 'Unknown action']);
	}
} catch (Throwable $e) {
	http_response_code(500);
	echo json_encode(['error' => $e->getMessage()]);
}


// -- Actions -------------------------------------------------------------------

function action_init(): void {
	$id  = generate_id();
	$dir = IMAGES_DIR . "/$id";
	if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
		throw new RuntimeException("Cannot create directory: $dir");
	}
	echo json_encode([
		'id'                => $id,
		'postMaxSize'       => ini_to_bytes(ini_get('post_max_size')),
		'uploadMaxFilesize' => ini_to_bytes(ini_get('upload_max_filesize')),
	]);
}

function action_upload(): void {
	$id   = validate_id();
	$face = validate_face();

	if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
		$code = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
		if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
			http_response_code(413);
			echo json_encode(['error' => sprintf(
				'Image exceeds the server upload limit (max %s). Ask the site administrator to raise upload_max_filesize/post_max_size.',
				ini_get('upload_max_filesize')
			)]);
			return;
		}
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


// -- Helpers -------------------------------------------------------------------

function ini_to_bytes(string $val): int {
	$val = trim($val);
	if ($val === '') return 0;
	$unit = strtolower(substr($val, -1));
	return match ($unit) {
		'g' => (int) $val * 1024 * 1024 * 1024,
		'm' => (int) $val * 1024 * 1024,
		'k' => (int) $val * 1024,
		default => (int) $val,
	};
}

function generate_id(): string {
	do {
		$id = rtrim(strtr(base64_encode(random_bytes(6)), '+/', '-_'), '=');
	} while (is_dir(IMAGES_DIR . "/$id"));
	return $id;
}

function validate_id(): string {
	$id = trim($_GET['id'] ?? $_POST['id'] ?? '');
	if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $id)) {
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

function action_spawn(): void {
	$id      = validate_id();
	$viewer  = $_POST['viewer'] ?? 'pannellum';
	$title   = htmlspecialchars(trim($_POST['title'] ?? 'Panorama'), ENT_XML1);
	$desc    = htmlspecialchars(trim($_POST['desc']  ?? ''),         ENT_XML1);
	$lat     = sanitize_coord($_POST['lat'] ?? '');
	$lng     = sanitize_coord($_POST['lng'] ?? '');
	$exif    = isset($_POST['exif']) && is_array($_POST['exif']) ? $_POST['exif'] : null;
	$cubefaceRaw = $_POST['cubeface'] ?? null;
	$cubeface    = is_array($cubefaceRaw) ? $cubefaceRaw
				 : ($cubefaceRaw ? json_decode($cubefaceRaw, true) : null);

	$outDir = IMAGES_DIR . "/$id";

	$meta = [
		'id'       => $id,
		'title'    => $title,
		'desc'     => $desc,
		'viewer'   => $viewer,
		'lat'      => $lat,
		'lng'      => $lng,
		'exif'     => $exif,
		'cubeface' => $cubeface,
		'status'   => 'pending',
		'progress' => 0,
		'step'     => 'Queued…',
		'created'  => date('c'),
		'multires' => null,
	];
	file_put_contents($outDir . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT));

	$phpBin = find_php_binary();
	$worker = escapeshellarg(__DIR__ . '/worker.php');
	$base   = escapeshellarg(dirname(__DIR__));
	$log    = escapeshellarg($outDir . '/worker.log');

	if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
		pclose(popen('start /B ' . escapeshellarg($phpBin) . " $worker $id $base", 'r'));
	} else {
		exec("(" . escapeshellarg($phpBin) . " $worker $id $base < /dev/null > $log 2>&1) &");
	}

	echo json_encode(['status' => 'ok', 'id' => $id, 'url' => "images/$id/"]);
}

function action_status(): void {
	$id       = validate_id();
	$metaPath = IMAGES_DIR . "/$id/meta.json";
	if (!file_exists($metaPath)) {
		http_response_code(404);
		echo json_encode(['error' => 'Job not found']);
		return;
	}
	$meta = json_decode(file_get_contents($metaPath), true);
	echo json_encode([
		'status'   => $meta['status']   ?? 'pending',
		'progress' => $meta['progress'] ?? 0,
		'step'     => $meta['step']     ?? '',
		'url'      => $meta['url']      ?? null,
		'error'    => $meta['error']    ?? null,
	]);
}

function find_php_binary(): string {
	if (!empty(PHP_BINARY)) return realpath(PHP_BINARY) ?: PHP_BINARY;
	$c = PHP_BINDIR . DIRECTORY_SEPARATOR . 'php';
	if (is_executable($c)) return realpath($c) ?: $c;
	if (!empty($_SERVER['_']) && is_executable($_SERVER['_']))
		return realpath($_SERVER['_']) ?: $_SERVER['_'];
	$v = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
	foreach (["php{$v}", 'php' . PHP_MAJOR_VERSION, 'php'] as $name) {
		$c = PHP_BINDIR . DIRECTORY_SEPARATOR . $name;
		if (is_executable($c)) return realpath($c) ?: $c;
	}
	$which = trim((string) shell_exec('command -v php 2>/dev/null'));
	if ($which && is_executable($which)) {
		$ver = trim((string) shell_exec(escapeshellarg($which) . ' -r "echo PHP_VERSION;" 2>/dev/null'));
		if (str_starts_with($ver, $v)) return realpath($which) ?: $which;
	}
	return '';
}
