<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Panorama Uploader</title>
  <link rel="stylesheet" href="public/upload/style.css" />
</head>
<body>

<div class="layout">

  <header>
    <h1>Panorama Uploader</h1>
    <p>Equirectangular · 2:1 · JPG</p>
  </header>

  <form id="uploadForm" novalidate>

    <!-- Image File -->
    <div class="card">
      <div class="card-title">Image File</div>
      <div class="card-body">
        <div class="drop-zone" id="dropZone" role="button" tabindex="0"
             aria-label="Drop zone for JPG panorama">
          <div class="drop-zone__inner">
            <div class="drop-zone__icon" aria-hidden="true">
              <svg viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="4" y="10" width="24" height="16" rx="1" stroke="currentColor" stroke-width="1.5"/>
                <path d="M4 18 L10 13 L16 18 L22 11 L28 18" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>
                <path d="M16 6 L16 2 M16 2 L13 5 M16 2 L19 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </div>
            <p class="drop-zone__primary">Drop your JPG here</p>
            <p class="drop-zone__secondary">or <button type="button" class="browse-link" id="browseBtn">browse file</button></p>
            <p class="drop-zone__hint">2:1 equirectangular panorama</p>
            <p class="drop-zone__filename" id="dropZoneFilename"></p>
          </div>
          <input type="file" id="fileInput" accept=".jpg,.jpeg,image/jpeg" hidden aria-hidden="true" />
        </div>
        <div class="field-error" id="fileError" role="alert" hidden></div>
      </div>
    </div>

    <!-- Title -->
    <div class="card">
      <div class="card-title">Title</div>
      <div class="card-body">
        <input
          type="text"
          id="titleInput"
          name="title"
          class="text-input"
          placeholder="e.g. Rooftop at Sunset — Guatemala City"
          maxlength="120"
          autocomplete="off"
        />
        <div class="field-error" id="titleError" role="alert" hidden></div>
      </div>
    </div>

    <!-- Description -->
    <div class="card">
      <div class="card-title">Description <span class="card-title-opt">optional</span></div>
      <div class="card-body">
        <textarea
          id="descriptionInput"
          name="description"
          class="textarea-input"
          placeholder="Describe the location, shoot conditions, or intended use…"
          rows="3"
          maxlength="600"
        ></textarea>
        <div class="char-count" id="charCount">0 / 600</div>
      </div>
    </div>

    <!-- Viewer -->
    <div class="card">
      <div class="card-title">Viewer</div>
      <div class="card-body">
        <div class="select-wrapper">
          <select id="viewerSelect" name="viewer" class="select-input">
            <option value="" disabled <?= DEFAULT_VIEWER === '' ? 'selected' : '' ?>>— choose a viewer —</option>
            <option value="avansel"<?= DEFAULT_VIEWER === 'avansel' ? ' selected' : '' ?>>Avansel</option>
            <?php if (defined('KRPANO_DIR')): ?>
            <option value="krpano"<?= DEFAULT_VIEWER === 'krpano' ? ' selected' : '' ?>>Krpano</option>
            <?php endif; ?>
            <option value="marzipano"<?= DEFAULT_VIEWER === 'marzipano' ? ' selected' : '' ?>>Marzipano</option>
            <option value="pannellum"<?= DEFAULT_VIEWER === 'pannellum' ? ' selected' : '' ?>>Pannellum</option>
          </select>
          <div class="select-arrow" aria-hidden="true">
            <svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.5">
              <polyline points="3,5 7,9 11,5"/>
            </svg>
          </div>
        </div>
        <div class="field-error" id="viewerError" role="alert" hidden></div>
      </div>
    </div>

    <!-- Submit -->
    <div class="submit-row">
      <button type="submit" class="btn" id="submitBtn">
        <span class="submit-btn__text">Process &amp; Upload</span>
      </button>
    </div>

  </form>

  <!-- Progress panel (shown during upload) -->
  <div id="progress">
    <div class="progress-header">
      <span id="progressLabel"></span>
      <span id="progressFraction"></span>
    </div>
    <div class="progress-track">
      <div class="progress-fill" id="progressFill"></div>
    </div>
  </div>

  <!-- Success panel (shown after spawn) -->
  <div id="successPanel">
    <div class="success-inner">
      <div class="success-check" aria-hidden="true">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="2,8 6,12 14,4"/>
        </svg>
      </div>
      <div class="success-body">
        <p class="success-title">Upload complete</p>
        <a id="panoUrl" class="success-url" target="_blank" rel="noopener"></a>
      </div>
    </div>
    <div class="success-actions">
      <button type="button" id="uploadAnotherBtn" class="btn secondary">
        Upload another
      </button>
    </div>
  </div>

</div><!-- /.layout -->

<script src="https://cdn.jsdelivr.net/npm/exifr@7/dist/full.umd.js"></script>
<script src="public/upload/main.js"></script>
<script src="public/upload/webgl.js"></script>
</body>
</html>
