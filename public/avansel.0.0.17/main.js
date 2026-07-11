import { Avansel } from "avansel"
const container = document.querySelector(`#${panorama.domid}`)
const viewer = new Avansel(container).multires(panorama.tiles, () => (s, l, x, y) => {
	l = parseInt(l) + 1
	return `${panorama.prefix}/${l}/${s}_${y}_${x}.jpg`
});

// GPano Pose pitch/roll: true texture-level correction via the raw Three.js
// mesh/group Avansel exposes through pano.get() — this and everything below
// reaches into internal properties that aren't part of Avansel's documented
// API (.sphere()/.multires()/.start()/.stop()/.render()/.withTween()), a
// deliberate tradeoff to get GPano support at all — see GPANO.md. Heading is
// not reprojected here, matching every other viewer in this project (Pose
// heading only ever affects the initial camera yaw, never the texture).
// Axis mapping/signs are a best-effort guess from reading Avansel's source,
// not yet confirmed against a live render — see GPANO.md §7.
if (panorama.horizonPitch !== null || panorama.horizonRoll !== null) {
	const mesh = viewer.pano.get();
	mesh.rotation.set(
		(panorama.horizonPitch ?? 0) * Math.PI / 180,
		0,
		(panorama.horizonRoll ?? 0) * Math.PI / 180
	);
}

// InitialView heading/pitch via Controls' own reachable lat/lng properties,
// applied the same way Controls.init() applies its own defaults
// (this.camera.lookAt(this.lat, this.lng)). Also unverified — see GPANO.md.
if (panorama.initialYaw !== null || panorama.initialPitch !== null) {
	const lat = panorama.initialPitch ?? 0;
	const lng = panorama.initialYaw ?? 90;
	viewer.controls.lat = viewer.controls.latVector = lat;
	viewer.controls.lng = viewer.controls.lngVector = lng;
	viewer.camera.lookAt(lat, lng);
}

// InitialViewRollDegrees: Avansel has no roll concept anywhere in its camera
// code, so this is only reachable via a raw post-lookAt rotation on the
// underlying Three.js camera. Controls.onPosChanged() re-calls lookAt() on
// every user drag, which resets this — so it only guarantees the *initial*,
// pre-interaction frame is rolled correctly. Disclosed limitation, not a bug.
if (panorama.initialRoll !== null) {
	viewer.camera.get().rotateZ(panorama.initialRoll * Math.PI / 180);
}

viewer.start();
