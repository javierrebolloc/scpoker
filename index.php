<?php
// Planning Poker single page. The UI logic lives in assets/app.js.
declare(strict_types=1);

ini_set('display_errors', '0');

require __DIR__ . '/lib/storage.php';

$cfg = require __DIR__ . '/lib/config.php';
cleanup_expired($cfg['CLEANUP_INTERVAL']);

header_remove('X-Powered-By');
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'");
header('X-Frame-Options: DENY'); // for old browsers without frame-ancestors
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
// Over HTTPS the browser is told to always use HTTPS (not sent over plain HTTP, where it is ignored).
if (($_SERVER['HTTPS'] ?? '') !== '' && strtolower($_SERVER['HTTPS']) !== 'off') {
    header('Strict-Transport-Security: max-age=31536000');
}

$clientCfg = json_encode([
    'pollMs'        => $cfg['POLL_INTERVAL_MS'],
    'roomTtl'       => $cfg['ROOM_TTL'],
    'expiryWarning' => $cfg['EXPIRY_WARNING'],
    'maxName'       => $cfg['MAX_NAME'],
    'maxStory'      => $cfg['MAX_STORY'],
]);
$v = (string) filemtime(__DIR__ . '/assets/app.js') . filemtime(__DIR__ . '/assets/app.css');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Planning Poker</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect x='6' y='3' width='20' height='26' rx='4' fill='%234f46e5'/%3E%3Ctext x='16' y='22' font-size='14' text-anchor='middle' fill='white' font-family='sans-serif' font-weight='bold'%3E5%3C/text%3E%3C/svg%3E">
<link rel="stylesheet" href="assets/app.css?v=<?= htmlspecialchars($v) ?>">
<script src="assets/app.js?v=<?= htmlspecialchars($v) ?>" defer></script>
</head>
<body data-config="<?= htmlspecialchars($clientCfg, ENT_QUOTES) ?>">

<header class="topbar">
  <a class="brand" href="index.php"><span class="brand-card">5</span> Planning Poker</a>
  <div class="topbar-actions" id="room-actions" hidden>
    <span class="room-code" title="Room code">Room <strong id="room-code"></strong></span>
    <button type="button" class="btn btn-ghost" id="btn-copy-link">Copy link</button>
    <button type="button" class="btn btn-ghost btn-danger-text" id="btn-leave">Leave room</button>
  </div>
</header>

<div class="toast" id="toast" role="status" aria-live="polite" hidden></div>
<div class="sr-only" id="sr-status" role="status" aria-live="polite"></div>

<main>
  <!-- Home: create a room -->
  <section class="screen panel narrow" id="screen-home" hidden>
    <div class="notice" id="home-notice" hidden></div>
    <h1>Estimate as a team</h1>
    <p class="muted">Create a room, share the link and vote on your User Stories from 1 to 10.</p>
    <form id="form-create" class="stack" autocomplete="off">
      <label for="create-name">Your name</label>
      <input id="create-name" name="name" required>
      <button type="submit" class="btn btn-primary">Create room</button>
    </form>
  </section>

  <!-- Join a room -->
  <section class="screen panel narrow" id="screen-join" hidden>
    <div class="notice" id="join-notice" hidden></div>
    <h1>Join the room</h1>
    <p class="muted">Room <strong id="join-code"></strong></p>
    <form id="form-join" class="stack" autocomplete="off">
      <label for="join-name">Your name</label>
      <input id="join-name" name="name" required>
      <button type="submit" class="btn btn-primary">Join</button>
    </form>
  </section>

  <!-- Missing, expired or invalid room -->
  <section class="screen panel narrow" id="screen-missing" hidden>
    <h1 id="missing-title">This room does not exist</h1>
    <p class="muted" id="missing-text"></p>
    <a class="btn btn-primary" href="index.php">Create a new room</a>
  </section>

  <!-- Loading -->
  <section class="screen loading" id="screen-loading">
    <div class="spinner" aria-label="Loading"></div>
  </section>

  <!-- Room -->
  <section class="screen room" id="screen-room" hidden>
    <div class="banner banner-warn" id="expiry-banner" hidden></div>
    <div class="banner banner-error" id="conn-banner" hidden>No connection to the server. Retrying…</div>

    <div class="story">
      <span class="story-label">User Story</span>
      <p class="story-title" id="story-title"></p>
      <p class="muted small" id="story-hint" hidden>Reset the round to change the User Story.</p>
      <form class="story-form" id="form-story" hidden autocomplete="off">
        <input id="story-input" placeholder="User Story title (optional)">
        <button type="submit" class="btn btn-secondary">Save</button>
      </form>
    </div>

    <div class="table-wrap">
      <div class="table-area" id="table-area">
        <div class="table-felt">
          <div class="table-center" id="table-center"></div>
        </div>
        <div id="seats"></div>
      </div>
    </div>

    <div class="admin-bar" id="admin-bar" hidden>
      <button type="button" class="btn btn-primary" id="btn-reveal">Reveal votes</button>
      <button type="button" class="btn btn-secondary" id="btn-hide">Hide votes</button>
      <button type="button" class="btn btn-secondary" id="btn-reset">Reset round</button>
      <span class="muted small">Click a teammate to remove them or make them the admin.</span>
    </div>

    <div class="results panel" id="results" hidden></div>

    <div class="deck-wrap">
      <p class="deck-hint" id="deck-hint">Pick your card</p>
      <div class="deck" id="deck" role="group" aria-label="Cards" tabindex="-1"></div>
    </div>

    <section class="history panel">
      <div class="history-head">
        <h2>History</h2>
        <button type="button" class="btn btn-ghost" id="btn-copy-history" disabled>Copy history</button>
      </div>
      <ol class="history-list" id="history-list"></ol>
      <p class="muted small" id="history-empty">No rounds saved yet. A round is saved when a revealed round is reset.</p>
    </section>
  </section>
</main>

<!-- Dialog (admin actions on a user, leave confirmation) -->
<div class="modal" id="modal" hidden>
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="modal-title">
    <h2 id="modal-title"></h2>
    <p class="muted" id="modal-text"></p>
    <div class="modal-actions" id="modal-actions"></div>
  </div>
</div>

</body>
</html>
