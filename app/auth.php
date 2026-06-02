<?php
$_auth_file = dirname(__DIR__) . '/password.txt';

if (!is_file($_auth_file)) return;
$_auth_pass = trim(file_get_contents($_auth_file));
if ($_auth_pass === '') return;

session_start();

if (!empty($_SESSION['panoup_authed'])) return;

// API mode: caller sets $auth_api_mode = true before require_once.
if (!empty($auth_api_mode)) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// HTML mode: handle login POST, then render form.
$_auth_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['panoup_password'])) {
    if ($_POST['panoup_password'] === $_auth_pass) {
        $_SESSION['panoup_authed'] = true;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    $_auth_error = 'Incorrect password.';
}

_auth_render_login($_auth_error);
exit;

function _auth_render_login(string $err): void
{
    $err_html = $err !== ''
        ? '<p class="auth-error">' . htmlspecialchars($err, ENT_QUOTES, 'UTF-8') . '</p>'
        : '';
    $action    = htmlspecialchars(strtok($_SERVER['REQUEST_URI'], '?'), ENT_QUOTES, 'UTF-8');
    // Compute root-relative base so the stylesheet resolves from any subdir depth
    $_auth_root = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
    $css_href   = htmlspecialchars($_auth_root, ENT_QUOTES, 'UTF-8') . 'public/upload/style.css';
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Panorama Uploader</title>
  <link rel="stylesheet" href="<?= $css_href ?>" />
</head>
<body>
  <div class="auth-wrap">
    <div class="auth-card">

      <header>
        <h1>Panorama Uploader</h1>
        <p>Sign in to continue</p>
      </header>

      <div class="card" style="margin-top: 2px;">
        <div class="card-title">Authentication</div>
        <div class="card-body">
          <form method="POST" action="<?= $action ?>">
            <label class="auth-label" for="panoup_password">Password</label>
            <input
              type="password"
              id="panoup_password"
              name="panoup_password"
              class="text-input"
              style="margin-top: 6px;"
              autocomplete="current-password"
              autofocus
              required
            />
            <?= $err_html ?>
            <button type="submit" class="btn" style="margin-top: 12px; width: 100%;">
              Enter
            </button>
          </form>
        </div>
      </div>

    </div>
  </div>
</body>
</html>
    <?php
}
