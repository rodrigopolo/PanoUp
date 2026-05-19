<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Panorama Upload</title>
  <link rel="stylesheet" href="public/upload/style.css" />
</head>
<body>

  <div class="grid-overlay" aria-hidden="true"></div>

  <main class="container">

    <header class="header">
      <div class="header-badge">360°</div>
      <div class="header-text">
        <h1>Panorama Uploader</h1>
        <small>by RodrigoPolo.com</small>
        <p class="subtitle">Equirectangular · 2:1 ratio · JPG only</p>
      </div>
    </header>

    <form id="uploadForm" novalidate>

      <!-- Drop Zone -->
      <section class="form-section">
        <label class="section-label">
          <span class="label-index">01</span> Image File
        </label>
        <div class="drop-zone" id="dropZone" role="button" tabindex="0" aria-label="Drop zone for JPG panorama">
          <div class="drop-zone__inner">
            <div class="drop-zone__icon" aria-hidden="true">
              <svg viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect x="8" y="20" width="48" height="32" rx="2" stroke="currentColor" stroke-width="2"/>
                <path d="M8 36 L20 26 L30 34 L42 22 L56 36" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                <circle cx="22" cy="30" r="3" fill="currentColor" opacity="0.5"/>
                <path d="M32 12 L32 4 M32 4 L28 8 M32 4 L36 8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </div>
            <p class="drop-zone__primary">Drop your JPG here</p>
            <p class="drop-zone__secondary">or <button type="button" class="browse-link" id="browseBtn">browse file</button></p>
            <p class="drop-zone__hint">Must be a 2:1 equirectangular panorama</p>
            <p class="drop-zone__filename" id="dropZoneFilename"></p>
          </div>

          <input type="file" id="fileInput" accept=".jpg,.jpeg,image/jpeg" hidden aria-hidden="true" />
        </div>

        <!-- Error message -->
        <div class="field-error" id="fileError" role="alert" hidden></div>
      </section>

      <!-- Title -->
      <section class="form-section">
        <label class="section-label" for="titleInput">
          <span class="label-index">02</span> Title
        </label>
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
      </section>

      <!-- Description -->
      <section class="form-section">
        <label class="section-label" for="descriptionInput">
          <span class="label-index">03</span> Description
          <span class="label-optional">optional</span>
        </label>
        <textarea
          id="descriptionInput"
          name="description"
          class="textarea-input"
          placeholder="Describe the location, shoot conditions, or intended use…"
          rows="4"
          maxlength="600"
        ></textarea>
        <div class="char-count" id="charCount">0 / 600</div>
      </section>

      <!-- Viewer Selector -->
      <section class="form-section">
        <label class="section-label" for="viewerSelect">
          <span class="label-index">04</span> Viewer
        </label>
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
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="4,6 8,10 12,6"/>
            </svg>
          </div>
        </div>
        <div class="field-error" id="viewerError" role="alert" hidden></div>
      </section>

      <!-- Submit -->
      <div class="submit-row">
        <button type="submit" class="submit-btn" id="submitBtn">
          <span class="submit-btn__text">Process &amp; Upload</span>
          <span class="submit-btn__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M5 12h14M13 6l6 6-6 6"/>
            </svg>
          </span>
        </button>
      </div>

    </form>

    <!-- ── Progress panel (shown during upload) ──────────── -->
    <div id="progress">
      <div class="progress-header">
        <span class="progress-label" id="progressLabel"></span>
        <span class="progress-fraction" id="progressFraction">0 / 20</span>
      </div>
      <div class="progress-track">
        <div class="progress-fill" id="progressFill"></div>
      </div>
    </div>

    <!-- ── Success panel (shown after upload completes) ───── -->
    <div id="successPanel">
      <div class="success-inner">
        <div class="success-check" aria-hidden="true">✓</div>
        <div class="success-body">
          <p class="success-title">Panorama uploaded</p>
          <a id="panoUrl" class="success-url" target="_blank" rel="noopener"></a>
        </div>
      </div>
      <button type="button" id="uploadAnotherBtn" class="upload-another-btn">
        Upload another
      </button>
    </div>

  </main>
  
  <script src="https://cdn.jsdelivr.net/npm/exifr@7/dist/lite.umd.js"></script>
  <script src="public/upload/main.js"></script>
  <script src="public/upload/webgl.js"></script>
</body>
</html>
