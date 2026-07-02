<?php
// config.php — site configuration.
// This file is safe to commit; it contains no secrets.
// To enable password protection, create password.txt in the project root.

// -- Memory limit -------------------------------------------------------------
// Sets the PHP memory limit (in bytes) while processing images.
// Common values: '512M', '1024M', '2048M', etc.
// Use '-1' for unlimited (not recommended in production).
define('MEMORY_LIMIT', '1024M');

// -- Upload size limits --------------------------------------------------------
// post_max_size / upload_max_filesize cannot be set here: PHP enforces them
// before any application code (including this file) runs, so ini_set() has no
// effect on the request that would exceed them. They're set in ../.htaccess
// (php_value, mod_php only) instead. See README.md for php-fpm/CGI hosts.

// -- Default viewer ------------------------------------------------------------
// Pre-selects a viewer in the upload form.
// Valid values: 'avansel', 'krpano', 'marzipano', 'pannellum'
// Set to '' to show the placeholder "— choose a viewer —" with no default.
define('DEFAULT_VIEWER', 'pannellum');

// -- krpano library directory --------------------------------------------------
// Directory name inside public/ where krpano is installed.
// Update this string when you install a new version of krpano.
define('KRPANO_DIR', 'krpano.1.23.2');
