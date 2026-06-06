/**
 * Panorama Uploader
 * Handles: drag & drop, file validation (JPG + 2:1 ratio),
 *          EXIF GPS extraction, per-viewer cubeface calculation,
 *          form validation, and form submission.
 */

'use strict';

/* -- DOM refs ----------------------------------------------- */
const dropZone      = document.getElementById('dropZone');
const fileInput     = document.getElementById('fileInput');
const browseBtn     = document.getElementById('browseBtn');
const fileError     = document.getElementById('fileError');

const titleInput      = document.getElementById('titleInput');
const titleError      = document.getElementById('titleError');

const descriptionInput = document.getElementById('descriptionInput');
const charCount        = document.getElementById('charCount');

const viewerSelect  = document.getElementById('viewerSelect');
const viewerError   = document.getElementById('viewerError');

const uploadForm    = document.getElementById('uploadForm');
const submitBtn     = document.getElementById('submitBtn');

const progress         = document.getElementById('progress');
const progressLabel    = document.getElementById('progressLabel');
const progressFraction = document.getElementById('progressFraction');
const progressFill     = document.getElementById('progressFill');
const successPanel     = document.getElementById('successPanel');
const panoUrlEl        = document.getElementById('panoUrl');
const uploadAnotherBtn = document.getElementById('uploadAnotherBtn');

/* -- Screen Wake Lock --------------------------------------- */
let _wakeLock = null;

async function requestWakeLock() {
	if (!('wakeLock' in navigator)) return;
	try { _wakeLock = await navigator.wakeLock.request('screen'); }
	catch (_) { /* permission denied or unsupported — non-fatal */ }
}

function releaseWakeLock() {
	_wakeLock?.release().catch(() => {});
	_wakeLock = null;
}

// Re-acquire if the tab becomes visible while a lock is held
document.addEventListener('visibilitychange', () => {
	if (_wakeLock !== null && document.visibilityState === 'visible') requestWakeLock();
});

/* -- State -------------------------------------------------- */
let selectedFile = null; // the validated File object (or null)
let imageWidth   = 0;    // pixel width of the validated image
let gpsLat       = '';   // GPS latitude string (8 decimals) or ''
let gpsLng       = '';   // GPS longitude string (8 decimals) or ''
let exifData     = null; // full parsed EXIF object (or null)

window.panoFile = null;
window.panoExif = null;

/* -- Helpers ------------------------------------------------ */

function showError(el, message) {
	el.textContent = message;
	el.hidden = false;
}

function clearError(el) {
	el.textContent = '';
	el.hidden = true;
}

function setInputState(el, hasError) {
	if (hasError) {
		el.classList.add('has-error');
	} else {
		el.classList.remove('has-error');
	}
}

const TOTAL_STEPS = 14; // 1 init + 6 render + 6 upload + 1 spawn = 14 (tiling is server-side)

function showProgress(label, step) {
	if (!progress.classList.contains('is-active')) {
		collapseForm();
		progress.classList.add('is-active');
	}
	progressLabel.textContent    = label;
	progressFraction.textContent = `${step} / ${TOTAL_STEPS}`;
	progressFill.style.width     = `${Math.round(step / TOTAL_STEPS * 100)}%`;
}

function showSuccess(url) {
	progress.classList.remove('is-active');
	const fullUrl         = new URL(url, window.location.href).href;
	panoUrlEl.href        = fullUrl;
	panoUrlEl.textContent = fullUrl;
	successPanel.classList.add('is-active');
	submitBtn.querySelector('.submit-btn__text').textContent = 'Process & Upload';
	submitBtn.style.background = '';
}

function resetUpload() {
	selectedFile     = null;
	imageWidth       = 0;
	gpsLat           = '';
	gpsLng           = '';
	exifData         = null;
	window.panoFile  = null;
	window.panoExif  = null;

	clearDropZoneError();
	setFileAccepted(false);
	fileInput.value = '';

	titleInput.value       = '';
	descriptionInput.value = '';
	charCount.textContent  = '0 / 600';
	charCount.style.color  = '';

	clearError(fileError);
	clearError(titleError);
	clearError(viewerError);
	setInputState(titleInput, false);
	setInputState(viewerSelect, false);

	submitBtn.disabled = false;

	successPanel.classList.remove('is-active');
	progress.classList.remove('is-active');
	progressFill.style.width = '0%';
	expandForm();

	document.getElementById('serverAlert')?.remove();
}

function collapseForm() {
	uploadForm.style.maxHeight  = uploadForm.scrollHeight + 'px';
	uploadForm.style.overflow   = 'hidden';
	uploadForm.style.transition = 'max-height 0.4s cubic-bezier(0.4,0,0.2,1), opacity 0.25s ease';
	void uploadForm.offsetHeight; // force reflow so start-value registers
	uploadForm.style.maxHeight     = '0';
	uploadForm.style.opacity       = '0';
	uploadForm.style.pointerEvents = 'none';
	window.scrollTo({ top: 0, behavior: 'smooth' });
}

function expandForm(delayMs = 150) {
	setTimeout(() => {
		uploadForm.style.transition    = 'max-height 0.45s cubic-bezier(0.4,0,0.2,1), opacity 0.35s ease';
		uploadForm.style.maxHeight     = '3000px'; // expanding from 0 — large ceiling is fine
		uploadForm.style.opacity       = '1';
		uploadForm.style.pointerEvents = '';
		uploadForm.addEventListener('transitionend', (e) => {
			if (e.propertyName !== 'max-height') return;
			uploadForm.style.maxHeight  = '';
			uploadForm.style.overflow   = '';
			uploadForm.style.transition = '';
		}, { once: true });
	}, delayMs);
}

/* -- File Validation ---------------------------------------- */

/**
 * Checks MIME type / extension.
 * Returns null on pass, error string on fail.
 */
function validateJpeg(file) {
	const validTypes = ['image/jpeg', 'image/jpg'];
	const validExts  = /\.(jpe?g)$/i;

	if (!validTypes.includes(file.type) && !validExts.test(file.name)) {
		return 'Only JPG/JPEG files are accepted.';
	}
	return null;
}

/**
 * Loads an image and checks for a 2:1 width-to-height ratio.
 * Uses a tolerance of ±1% to account for minor rounding differences.
 * Returns a Promise that resolves to { width, height, valid, error? }.
 */
function checkAspectRatio(file) {
	return new Promise((resolve) => {
		const url = URL.createObjectURL(file);
		const img = new Image();

		img.onload = () => {
			URL.revokeObjectURL(url);
			const { naturalWidth: w, naturalHeight: h } = img;
			const ratio     = w / h;
			const target    = 2.0;
			const tolerance = 0.01; // 1%

			if (Math.abs(ratio - target) > tolerance) {
				resolve({
					width:  w,
					height: h,
					valid:  false,
					error:  `The image is not a 2:1 panorama (detected ${w}×${h}, ratio ${ratio.toFixed(3)}).`,
				});
			} else {
				resolve({ width: w, height: h, valid: true });
			}
		};

		img.onerror = () => {
			URL.revokeObjectURL(url);
			resolve({ width: 0, height: 0, valid: false, error: 'Could not read the image file.' });
		};

		img.src = url;
	});
}

/**
 * Attempts to read GPS coordinates from EXIF via exifr (lite build).
 * Populates the module-level gpsLat / gpsLng strings.
 * Never throws — silently leaves them empty if unavailable.
 */
async function extractGps(file) {
	gpsLat = '';
	gpsLng = '';
	try {
		const exif = await exifr.parse(file);
		exifData        = exif ?? null;
		window.panoExif = exifData;
		if (exif && exif.latitude != null) {
			gpsLat = exif.latitude.toFixed(8);
			gpsLng = exif.longitude.toFixed(8);
		}
	} catch (_) { /* EXIF unreadable — not a blocking error */ }
}

/**
 * Full validation pipeline for a File object.
 * Shows/hides errors and preview.
 */
async function processFile(file) {
	// 1. Reset state
	clearDropZoneError();
	clearError(fileError);
	setFileAccepted(false);
	selectedFile     = null;
	imageWidth       = 0;
	gpsLat           = '';
	gpsLng           = '';
	exifData         = null;
	window.panoFile  = null;
	window.panoExif  = null;

	// 2. JPEG check
	const typeError = validateJpeg(file);
	if (typeError) {
		setDropZoneError();
		showError(fileError, typeError);
		return;
	}

	// 3. Aspect-ratio check
	const { width, height, valid, error } = await checkAspectRatio(file);

	if (!valid) {
		setDropZoneError();
		showError(fileError, error);
		return;
	}

	// 4. EXIF GPS (non-blocking — runs after ratio is confirmed valid)
	await extractGps(file);

	// 5. All good — persist state and mark drop zone accepted
	selectedFile    = file;
	imageWidth      = width;
	window.panoFile = selectedFile;
	setFileAccepted(true);
}

/* -- Cubeface calculation ----------------------------------- */

/**
 * Returns the nearest multiple of `step` to `value`.
 */
function nearestMultiple(value, step) {
	return Math.round(value / step) * step;
}

/**
 * Pannellum / Marzipano — identical algorithm.
 *
 * 1. raw     = round(W / π)
 * 2. snapped = nearest multiple of 512
 * 3. levels  = all entries in FIXED_LIST where entry <= snapped
 * 4. maxCubeface = largest selected level
 *
 * Returns { maxCubeface, levels }
 */
function calcPannellumMarzipano(W) {
	const FIXED_LIST = [512, 1024, 2048, 4096, 8192, 16384];
	const raw        = Math.round(W / Math.PI);
	const snapped    = nearestMultiple(raw, 512);
	const levels     = FIXED_LIST.filter((s) => s <= snapped);
	const maxCubeface = levels.length ? levels[levels.length - 1] : FIXED_LIST[0];
	return { maxCubeface, levels };
}

/**
 * Krpano — rounds to nearest 128, then steps down by halving-to-128.
 *
 * 1. maxCubeface = round(W / π / 128) × 128
 * 2. While current > 512: push current, then current = floor(current / 256) × 128
 * 3. Sort ascending
 *
 * Returns { maxCubeface, levels }
 */
function calcKrpano(W) {
	const maxCubeface = Math.round(W / Math.PI / 128) * 128;
	const levels = [];
	let current = maxCubeface;

	while (current > 512) {
		levels.push(current);
		current = Math.floor(current / 256) * 128;
	}

	levels.sort((a, b) => a - b);
	return { maxCubeface, levels };
}

/**
 * Avansel — raw (no snap), halve via ceil until ≤ 512, plus a fallback level.
 *
 * 1. maxCubeface  = round(W / π)  — no rounding to multiples
 * 2. While current > 512: push current, then current = ceil(current / 2)
 * 3. Sort ascending
 * 4. fallbackSize = min(ceil(levels[0] / 2), 512)
 *
 * Returns { maxCubeface, levels, fallbackSize }
 */
function calcAvansel(W) {
	const maxCubeface = Math.round(W / Math.PI);
	const levels = [];
	let current = maxCubeface;

	while (current > 512) {
		levels.push(current);
		current = Math.ceil(current / 2);
	}

	levels.sort((a, b) => a - b);

	const fallbackSize = levels.length
		? Math.min(Math.ceil(levels[0] / 2), 512)
		: Math.min(Math.ceil(maxCubeface / 2), 512);

	return { maxCubeface, levels, fallbackSize };
}

/**
 * Dispatcher — returns a plain object with cubeface data for the
 * selected viewer, plus a `viewer` key for reference.
 * Returns null if W is 0 or viewer is unrecognised.
 */
function calcCubefaceForViewer(W, viewer) {
	if (!W || !viewer) return null;

	switch (viewer) {
		case 'pannellum':
		case 'marzipano': {
			const { maxCubeface, levels } = calcPannellumMarzipano(W);
			return { viewer, maxCubeface, levels };
		}
		case 'krpano': {
			const { maxCubeface, levels } = calcKrpano(W);
			return { viewer, maxCubeface, levels };
		}
		case 'avansel': {
			const { maxCubeface, levels, fallbackSize } = calcAvansel(W);
			return { viewer, maxCubeface, levels, fallbackSize };
		}
		default:
			return null;
	}
}

/* -- Drop Zone UI states ------------------------------------ */

function setDropZoneError() {
	dropZone.classList.add('has-error');
}

function clearDropZoneError() {
	dropZone.classList.remove('has-error');
}

function setFileAccepted(accepted) {
	dropZone.classList.toggle('has-file', accepted);
	const fn = document.getElementById('dropZoneFilename');
	if (fn) fn.textContent = accepted && selectedFile
		? `${selectedFile.name}  ·  ${imageWidth.toLocaleString()} × ${Math.round(imageWidth / 2).toLocaleString()} px`
		: '';
}

/* -- Drag & Drop events ------------------------------------- */

// Prevent browser from opening dropped files
['dragenter', 'dragover', 'dragleave', 'drop'].forEach((evt) => {
	dropZone.addEventListener(evt, (e) => { e.preventDefault(); e.stopPropagation(); });
	document.body.addEventListener(evt, (e) => { e.preventDefault(); e.stopPropagation(); });
});

dropZone.addEventListener('dragenter', () => {
	dropZone.classList.add('drag-over');
});

dropZone.addEventListener('dragover', () => {
	dropZone.classList.add('drag-over');
});

dropZone.addEventListener('dragleave', (e) => {
	// Only remove if leaving the zone itself (not a child)
	if (!dropZone.contains(e.relatedTarget)) {
		dropZone.classList.remove('drag-over');
	}
});

dropZone.addEventListener('drop', (e) => {
	dropZone.classList.remove('drag-over');
	const files = e.dataTransfer.files;
	if (files.length > 0) {
		processFile(files[0]);
	}
});

/* -- Click to browse ---------------------------------------- */

browseBtn.addEventListener('click', (e) => {
	e.stopPropagation();
	fileInput.click();
});

// Also allow clicking anywhere on the drop zone
dropZone.addEventListener('click', () => {
	fileInput.click();
});

// Keyboard: Enter or Space activates browse
dropZone.addEventListener('keydown', (e) => {
	if (e.key === 'Enter' || e.key === ' ') {
		e.preventDefault();
		fileInput.click();
	}
});

fileInput.addEventListener('change', () => {
	if (fileInput.files.length > 0) {
		processFile(fileInput.files[0]);
		// Reset so the same file can be re-selected after removal
		fileInput.value = '';
	}
});

/* -- Character counter -------------------------------------- */

descriptionInput.addEventListener('input', () => {
	const len = descriptionInput.value.length;
	const max = parseInt(descriptionInput.getAttribute('maxlength'), 10);
	charCount.textContent = `${len} / ${max}`;
	charCount.style.color = len >= max * 0.9
		? 'var(--amber)'
		: 'var(--text-tertiary)';
});

/* -- Live clear errors -------------------------------------- */

titleInput.addEventListener('input', () => {
	if (titleInput.value.trim()) {
		clearError(titleError);
		setInputState(titleInput, false);
	}
});

viewerSelect.addEventListener('change', () => {
	if (viewerSelect.value) {
		clearError(viewerError);
		setInputState(viewerSelect, false);
	}
});

uploadAnotherBtn.addEventListener('click', resetUpload);

/* -- XHR upload helper -------------------------------------- */

/**
 * Upload one cube face via XHR with byte-level upload progress reporting.
 * @param {number}   id          Panorama session id
 * @param {string}   face        One of 'f','b','r','l','u','d'
 * @param {Blob}     blob        JPEG blob from renderFaceAsBlob()
 * @param {string}   faceName    Display name e.g. "Front"
 * @param {function} onProgress  Called with (loadedBytes, totalBytes) each progress event
 * @returns {Promise<void>}
 */
function uploadFaceXhr(id, face, blob, faceName, onProgress) {
	return new Promise((resolve, reject) => {
		const fd = new FormData();
		fd.append('action', 'upload');
		fd.append('id',     String(id));
		fd.append('face',   face);
		fd.append('image',  blob, `${faceName}.jpg`);

		const xhr = new XMLHttpRequest();
		xhr.upload.onprogress = (e) => {
			if (e.lengthComputable) onProgress(e.loaded, e.total);
		};
		xhr.onload = () => {
			if (xhr.status < 200 || xhr.status >= 300) {
				reject(new Error(`${faceName} Face: upload error ${xhr.status}`)); return;
			}
			let json;
			try   { json = JSON.parse(xhr.responseText); }
			catch { reject(new Error(`${faceName} Face: invalid JSON response`)); return; }
			json.error ? reject(new Error(`${faceName} Face: ${json.error}`)) : resolve();
		};
		xhr.onerror   = () => reject(new Error(`${faceName} Face: network error`));
		xhr.ontimeout = () => reject(new Error(`${faceName} Face: upload timed out`));
		xhr.open('POST', './api');
		xhr.send(fd);
	});
}

/* -- Form Submission ---------------------------------------- */

uploadForm.addEventListener('submit', async (e) => {
	e.preventDefault();

	let hasError = false;

	// Validate file
	if (!selectedFile) {
		showError(fileError, 'Please select a valid 2:1 JPG panorama.');
		setDropZoneError();
		hasError = true;
	} else {
		clearError(fileError);
	}

	// Validate title
	if (!titleInput.value.trim()) {
		showError(titleError, 'A title is required.');
		setInputState(titleInput, true);
		hasError = true;
	} else {
		clearError(titleError);
		setInputState(titleInput, false);
	}

	// Validate viewer
	if (!viewerSelect.value) {
		showError(viewerError, 'Please select a panorama viewer.');
		setInputState(viewerSelect, true);
		hasError = true;
	} else {
		clearError(viewerError);
		setInputState(viewerSelect, false);
	}

	if (hasError) return;

	// Calculate cubeface parameters for the selected viewer
	const cubeface = calcCubefaceForViewer(imageWidth, viewerSelect.value);

	// -- Disable UI and start progress ------------------------
	submitBtn.disabled = true;
	document.getElementById('serverAlert')?.remove();

	let step = 0;
	showProgress('Initializing…', step);

	await requestWakeLock();

	try {
		// -- init -------------------------------------------------
		const initResp = await fetch('./api', {
			method:  'POST',
			headers: { 'Content-Type': 'application/json' },
			body:    JSON.stringify({ action: 'init' }),
		});
		if (!initResp.ok) throw new Error(`Init failed (${initResp.status})`);
		const { id } = await initResp.json();
		step++;

		// -- decode source image for WebGL -------------------------
		const srcUrl = URL.createObjectURL(selectedFile);
		const srcImg = await new Promise((resolve, reject) => {
			const img = new Image();
			img.onload  = () => resolve(img);
			img.onerror = () => reject(new Error('Could not decode panorama image'));
			img.src = srcUrl;
		});
		URL.revokeObjectURL(srcUrl);

		// -- Phase 1: render + upload, one face at a time ------------
		const faceSize  = cubeface ? cubeface.maxCubeface : Math.round(imageWidth / Math.PI);
		const mapper    = new CubeMapper(srcImg, faceSize);
		const faceNames = { f: 'Front', b: 'Back', r: 'Right', l: 'Left', u: 'Up', d: 'Down' };
		const faces     = ['f', 'b', 'r', 'l', 'u', 'd'];

		try {
			for (const face of faces) {
				showProgress(`${faceNames[face]} Face — rendering…`, step);
				const blob = await mapper.renderFaceAsBlob(face);
				step++;
				showProgress(`${faceNames[face]} Face — rendered`, step);

				const uploadStartStep = step;
				await uploadFaceXhr(id, face, blob, faceNames[face], (loaded, total) => {
					const barPct = Math.round((uploadStartStep + loaded / total) / TOTAL_STEPS * 100);
					progressLabel.textContent    = `${faceNames[face]} Face — uploading…`;
					progressFraction.textContent = `${(loaded / 1048576).toFixed(1)} / ${(total / 1048576).toFixed(1)} MB`;
					progressFill.style.width     = `${barPct}%`;
				});
				step++;
				// blob goes out of scope here → eligible for GC before next face renders
			}
		} finally {
			mapper.destroy();   // free GPU resources regardless of success/failure
		}

		// -- Spawn background worker -------------------------------
		showProgress('Starting processing…', step);  // step=13, bar at 93%
		const spawnResp = await fetch('./api', {
			method:  'POST',
			headers: { 'Content-Type': 'application/json' },
			body:    JSON.stringify({
				action:   'spawn',
				id,
				viewer:   viewerSelect.value,
				title:    titleInput.value.trim(),
				desc:     descriptionInput.value.trim(),
				lat:      gpsLat,
				lng:      gpsLng,
				exif:     exifData,
				cubeface: cubeface ?? null,
			}),
		});
		if (!spawnResp.ok) throw new Error(`Spawn failed (${spawnResp.status})`);
		const spawnJson = await spawnResp.json();
		if (spawnJson.error) throw new Error(spawnJson.error);

		showSuccess(spawnJson.url);

	} catch (err) {
		console.error('[PanoramaUploader]', err);

		progress.classList.remove('is-active');
		expandForm(0);
		submitBtn.disabled = false;
		submitBtn.querySelector('.submit-btn__text').textContent = 'Process & Upload';

		const alert = document.createElement('p');
		alert.id = 'serverAlert';
		alert.style.cssText = 'font-size:11px;color:var(--error);margin-top:10px;';
		alert.textContent = `Upload error: ${err.message}`;
		document.querySelector('.submit-row').appendChild(alert);
	} finally {
		releaseWakeLock();
	}
});
