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
    $action = htmlspecialchars(strtok($_SERVER['REQUEST_URI'], '?'), ENT_QUOTES, 'UTF-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Panorama Uploader</title>
  <style>
    :root {
      --bg:             #1c1c1c;
      --bg-raised:      #252525;
      --bg-elevated:    #2f2f2f;
      --border:         #454545;
      --blue:           #4a9eff;
      --blue-dim:       #2870c8;
      --blue-glow:      rgba(74,158,255,0.12);
      --red:            #e05252;
      --text-primary:   #f0f0f0;
      --text-secondary: #9a9a9a;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      background: var(--bg);
      color: var(--text-primary);
      font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .card {
      background: var(--bg-raised);
      border: 1px solid var(--border);
      border-radius: 4px;
      padding: 2.5rem 2rem;
      width: 100%;
      max-width: 380px;
    }
    .badge {
      display: inline-block;
      font-family: 'Menlo', 'Consolas', monospace;
      font-weight: 700;
      font-size: 0.65rem;
      letter-spacing: 0.15em;
      text-transform: uppercase;
      color: var(--blue);
      border: 1px solid var(--blue-dim);
      background: var(--blue-glow);
      border-radius: 4px;
      padding: 0.25rem 0.5rem;
      margin-bottom: 1.25rem;
    }
    h1 {
      font-weight: 700;
      font-size: 1.5rem;
      margin-bottom: 0.25rem;
    }
    .subtitle {
      font-size: 0.75rem;
      color: var(--text-secondary);
      margin-bottom: 2rem;
    }
    label {
      display: block;
      font-size: 0.7rem;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--text-secondary);
      margin-bottom: 0.5rem;
      font-family: 'Menlo', 'Consolas', monospace;
    }
    input[type="password"] {
      width: 100%;
      background: var(--bg-elevated);
      border: 1px solid var(--border);
      border-radius: 4px;
      color: var(--text-primary);
      font-family: 'Menlo', 'Consolas', monospace;
      font-size: 0.9rem;
      padding: 0.65rem 0.85rem;
      outline: none;
      transition: border-color 0.15s;
    }
    input[type="password"]:focus { border-color: var(--blue-dim); }
    .auth-error {
      font-size: 0.75rem;
      color: var(--red);
      margin-top: 0.5rem;
    }
    button {
      margin-top: 1.5rem;
      width: 100%;
      background: var(--blue);
      border: none;
      border-radius: 4px;
      color: #ffffff;
      font-family: 'Menlo', 'Consolas', monospace;
      font-size: 0.85rem;
      font-weight: 700;
      letter-spacing: 0.05em;
      padding: 0.75rem 1rem;
      cursor: pointer;
      transition: background 0.15s, box-shadow 0.15s;
    }
    button:hover {
      background: #6db3ff;
      box-shadow: 0 0 24px rgba(74,158,255,0.3);
    }
  </style>
</head>
<body>
  <div class="card">
    <div class="badge">360°</div>
    <h1>Panorama Uploader</h1>
    <p class="subtitle">This instance is password-protected.</p>
    <form method="POST" action="<?= $action ?>">
      <label for="panoup_password">Password</label>
      <input type="password" id="panoup_password" name="panoup_password"
             autocomplete="current-password" autofocus required />
      <?= $err_html ?>
      <button type="submit">Enter</button>
    </form>
  </div>
</body>
</html>
    <?php
}
