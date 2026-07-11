<?php
// gpano_math.php — rotation-matrix composition for GPano Pose + InitialView.
//
// Scoped to viewers with NO Pose-correction primitive of their own. Most
// viewers in this project (Pannellum's horizonPitch/horizonRoll, krpano's
// prealign rx/rz, Avansel's raw mesh.rotation hack) reproject Pose pitch/
// roll at the texture level, so they just need the simple formula in
// template_loader.php (yaw = InitialViewHeadingDegrees - PoseHeadingDegrees,
// pitch/roll passed through raw — see GPANO.md §3.1). Marzipano has no such
// primitive — its camera view is the only lever — so Pose has to be folded
// directly into the camera's initial yaw/pitch/roll via real composition.
// This only corrects Marzipano's *starting* view; panning away still shows
// the uncorrected tilt, since there's no way to reproject its texture.
//
// Vectors are [x,y,z] arrays; matrices are 3 arrays of 3 floats (row-major).

const GPANO_D2R = M_PI / 180;

function gpano_rz(float $a): array {
	$c = cos($a); $s = sin($a);
	return [[$c, -$s, 0.0], [$s, $c, 0.0], [0.0, 0.0, 1.0]];
}
function gpano_rx(float $a): array {
	$c = cos($a); $s = sin($a);
	return [[1.0, 0.0, 0.0], [0.0, $c, -$s], [0.0, $s, $c]];
}
function gpano_ry(float $a): array {
	$c = cos($a); $s = sin($a);
	return [[$c, 0.0, $s], [0.0, 1.0, 0.0], [-$s, 0.0, $c]];
}

function gpano_matmul(array $a, array $b): array {
	$r = [[0.0, 0.0, 0.0], [0.0, 0.0, 0.0], [0.0, 0.0, 0.0]];
	for ($i = 0; $i < 3; $i++) {
		for ($j = 0; $j < 3; $j++) {
			$sum = 0.0;
			for ($k = 0; $k < 3; $k++) $sum += $a[$i][$k] * $b[$k][$j];
			$r[$i][$j] = $sum;
		}
	}
	return $r;
}

function gpano_matvec(array $m, array $v): array {
	return [
		$m[0][0] * $v[0] + $m[0][1] * $v[1] + $m[0][2] * $v[2],
		$m[1][0] * $v[0] + $m[1][1] * $v[1] + $m[1][2] * $v[2],
		$m[2][0] * $v[0] + $m[2][1] * $v[1] + $m[2][2] * $v[2],
	];
}

function gpano_transpose(array $m): array {
	return [
		[$m[0][0], $m[1][0], $m[2][0]],
		[$m[0][1], $m[1][1], $m[2][1]],
		[$m[0][2], $m[1][2], $m[2][2]],
	];
}

// Spec formula: R = Rz(-heading) * Rx(pitch) * Ry(roll)
function gpano_orientation_matrix(float $headingDeg, float $pitchDeg, float $rollDeg): array {
	return gpano_matmul(
		gpano_matmul(gpano_rz(-$headingDeg * GPANO_D2R), gpano_rx($pitchDeg * GPANO_D2R)),
		gpano_ry($rollDeg * GPANO_D2R)
	);
}

// Canonical basis at identity orientation: center pixel faces due north.
const GPANO_FORWARD0 = [0.0, 1.0, 0.0];
const GPANO_UP0       = [0.0, 0.0, 1.0];

function gpano_vec_to_heading_pitch(array $v): array {
	$heading = atan2($v[0], $v[1]) / GPANO_D2R;
	if ($heading < 0) $heading += 360.0;
	$pitch = asin(max(-1.0, min(1.0, $v[2]))) / GPANO_D2R;
	return ['heading' => $heading, 'pitch' => $pitch];
}

// Signed angle from $from to $to, both projected perpendicular to $axis.
// Positive = counterclockwise around axis (matches GPano's roll convention).
function gpano_signed_angle_around(array $axis, array $from, array $to): float {
	$dot   = fn(array $a, array $b) => $a[0] * $b[0] + $a[1] * $b[1] + $a[2] * $b[2];
	$cross = fn(array $a, array $b) => [
		$a[1] * $b[2] - $a[2] * $b[1],
		$a[2] * $b[0] - $a[0] * $b[2],
		$a[0] * $b[1] - $a[1] * $b[0],
	];
	$sub   = fn(array $a, array $b) => [$a[0] - $b[0], $a[1] - $b[1], $a[2] - $b[2]];
	$scale = fn(array $a, float $s) => [$a[0] * $s, $a[1] * $s, $a[2] * $s];

	$fromP = $sub($from, $scale($axis, $dot($axis, $from)));
	$toP   = $sub($to, $scale($axis, $dot($axis, $to)));

	$cosA = $dot($fromP, $toP);
	$sinA = $dot($cross($fromP, $toP), $axis);
	return atan2($sinA, $cosA) / GPANO_D2R;
}

// World -> local: given the file's Pose and InitialView (both world-frame),
// compute the local orientation — the yaw/pitch/roll a viewer should use in
// the pano's own untouched pixel-grid frame. Used only by viewers with no
// Pose-correction primitive of their own (currently: Marzipano).
//
// $pose/$view: ['heading' => deg, 'pitch' => deg, 'roll' => deg]
// returns:     ['heading' => deg, 'pitch' => deg, 'roll' => deg]
function gpano_world_view_to_local(array $pose, array $view): array {
	$poseR = gpano_orientation_matrix($pose['heading'], $pose['pitch'], $pose['roll']);
	$viewR = gpano_orientation_matrix($view['heading'], $view['pitch'], $view['roll']);

	$forwardWorld = gpano_matvec($viewR, GPANO_FORWARD0);
	$upWorld      = gpano_matvec($viewR, GPANO_UP0);

	$poseRInv     = gpano_transpose($poseR); // world -> local (rotation matrices are orthonormal)
	$forwardLocal = gpano_matvec($poseRInv, $forwardWorld);
	$upLocal      = gpano_matvec($poseRInv, $upWorld);

	$hp      = gpano_vec_to_heading_pitch($forwardLocal);
	$levelUp = gpano_matvec(gpano_orientation_matrix($hp['heading'], $hp['pitch'], 0.0), GPANO_UP0);
	$roll    = gpano_signed_angle_around($forwardLocal, $levelUp, $upLocal);

	return ['heading' => $hp['heading'], 'pitch' => $hp['pitch'], 'roll' => $roll];
}
