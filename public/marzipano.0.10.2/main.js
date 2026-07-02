'use strict';
var previewUrl = panorama.prefix + "/preview.jpg";

var tileUrl = function(f, z, x, y) {
	return panorama.prefix + "/" + z + "/" + f + "_" + y + "_" + x + ".jpg";
};

var container = document.getElementById(panorama.domid);
var viewer = new Marzipano.Viewer(container, {stage: {progressive: true}});

var source = new Marzipano.ImageUrlSource(function(tile) {
	if (tile.z === 0) {
		var mapY = 'lfrbud'.indexOf(tile.face) / 6;
		return { url: previewUrl, rect: { x: 0, y: mapY, width: 1, height: 1/6 }};
	} else {
		return { url: tileUrl(tile.face, tile.z, tile.x+1, tile.y+1) };
	}
});

// Create geometry.
var geometry = new Marzipano.CubeGeometry(panorama.tiles);

// Marzipano's pitch is positive-down in actual behavior — confirmed
// empirically (a live view.pitch()=-78.7° readback showed the sky/up face,
// the opposite of intended), and independently corroborated by CubeGeometry's
// own face mapping (u -> +Y, d -> -Y) combined with Rectilinear.js's own
// coordinatesToScreen() ray math (y = -Math.sin(pitch), so positive pitch ->
// negative y -> the 'd'/down face). RectilinearView's own JSDoc claims the
// opposite ("pitch > 0 rotates upwards") — that JSDoc is wrong for this
// build; the empirical browser test is the authority here, not the comment.
// Its fov IS vertical (JSDoc, and this part checks out), while GPano's
// InitialHorizontalFOVDegrees is horizontal, so it needs a proper h->v
// conversion against the actual container size — but only when GPano
// supplied a real value; the hardcoded 90° default is kept as-is (already a
// vertical fov, never meant to be converted) so untagged panoramas render
// identically to before.
var initialVfov = panorama.initialHfov !== null
	? Marzipano.util.convertFov.htov(panorama.initialHfov * Math.PI / 180, container.clientWidth, container.clientHeight)
	: 90 * Math.PI / 180;

var initialView = {
  yaw: panorama.initialYaw * Math.PI / 180,
  pitch: -panorama.initialPitch * Math.PI / 180,
  // Roll has no live-browser verification yet (unlike the pitch negation
  // above), so it's passed through unflipped per the GPano math model —
  // revisit if a live render shows it's mirrored.
  roll: panorama.initialRoll * Math.PI / 180,
  fov: initialVfov
};

// Create view.
var limiter = Marzipano.RectilinearView.limit.traditional(panorama.tiles[panorama.tiles.length-1].size*4, 140*Math.PI/180);
var view = new Marzipano.RectilinearView(initialView, limiter);

// Create scene.
var scene = viewer.createScene({
	source: source,
	geometry: geometry,
	view: view,
	pinFirstLevel: true
});

// Display scene.
scene.switchTo();

function attribution(first, second, url, position) {
	if (!url) return console.error('URL is required for the attribution link.');
	position = position || 'bottomleft';

	var link = document.createElement('a');
	link.href = url;
	link.target = '_blank';
	link.style = `
		position:absolute;display:block;font-family:Helvetica,Arial,sans-serif;
		text-transform:uppercase;text-decoration:none;color:#fff;opacity:.8;
		pointer-events:none;
		${position.replace(/bottom|top|left|right/g, m => `${m}:10px;`)}
		text-align:${/right/.test(position)?'right':'left'};`;

	var firstDiv = document.createElement('div');
	firstDiv.innerHTML = first;
	firstDiv.style = 'font-size:11px;margin-bottom:4px;';

	var secondDiv = document.createElement('div');
	secondDiv.innerHTML = second;
	secondDiv.style = 'font-size:16px;';

	link.appendChild(firstDiv);
	link.appendChild(secondDiv);
	document.body.appendChild(link);
}

if (panorama.attribution) {
	var { first = '', second = '', url = '', position = 'bottomleft' } = panorama.attribution;
	if (url) {
		attribution(first, second, url, position);
	} else {
		console.error('URL is required for the attribution link but was not provided.');
	}
}
