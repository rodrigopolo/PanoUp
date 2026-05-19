<?php
// config.php — site configuration.
// This file is safe to commit; it contains no secrets.
// To enable password protection, create password.txt in the project root.

// ── Memory limit ─────────────────────────────────────────────────────────────
// Sets the PHP memory limit (in bytes) while processing images.
// Common values: '512M', '1024M', '2048M', etc.
// Use '-1' for unlimited (not recommended in production).
define('MEMORY_LIMIT', '1024M');

// ── Default viewer ────────────────────────────────────────────────────────────
// Pre-selects a viewer in the upload form.
// Valid values: 'avansel', 'krpano', 'marzipano', 'pannellum'
// Set to '' to show the placeholder "— choose a viewer —" with no default.
define('DEFAULT_VIEWER', 'pannellum');

// ── krpano library directory ──────────────────────────────────────────────────
// Directory name inside public/ where krpano is installed.
// Update this string when you install a new version of krpano.
// define('KRPANO_DIR', 'krpano.1.23.3');
