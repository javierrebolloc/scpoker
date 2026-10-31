// Planning Poker: dependency-free JavaScript UI.
// All user-provided text is rendered with textContent (never innerHTML).
'use strict';

(function () {
  const CFG = JSON.parse(document.body.dataset.config);
  const ROOM_ID_RE = /^[a-km-np-z2-9]{8}$/;
  const REQUEST_TIMEOUT_MS = 8000;
  const MAX_BACKOFF_MS = 15000;
  const CARDS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];

  const $ = (id) => document.getElementById(id);

  let roomId = null;
  let creds = null;          // { userId, token }
  let state = null;          // last view received from the server
  let lastSignature = '';
  let clockOffset = 0;       // seconds: server time - local time
  let pollTimer = null;
  let pollInFlight = null;   // session of the poll in progress (null if none)
  let pollFailures = 0;      // consecutive connection failures, used to back off retries
  let session = 0;           // incremented when leaving a room, to discard stale responses
  let expiryTimer = null;
  let storyDirty = false;
  let actionSeq = 0;         // incremented on each action, so older responses do not undo it
  let modalOpener = null;    // element focused before the dialog opened

  // ---------- Utilities ----------

  function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = String(text);
    return node;
  }

  function storageKey(id) { return 'pp_' + id; }

  function loadCreds(id) {
    try {
      const data = JSON.parse(localStorage.getItem(storageKey(id)) || 'null');
      if (data && typeof data.userId === 'string' && typeof data.token === 'string') return data;
    } catch (e) { /* localStorage unavailable or corrupt */ }
    return null;
  }

  function saveCreds(id, data) {
    try { localStorage.setItem(storageKey(id), JSON.stringify(data)); } catch (e) { /* no persistence */ }
  }

  function clearCreds(id) {
    try { localStorage.removeItem(storageKey(id)); } catch (e) { /* nothing to do */ }
  }

  function rememberName(name) {
    try { localStorage.setItem('pp_name', name); } catch (e) { /* nothing to do */ }
  }

  function lastName() {
    try { return localStorage.getItem('pp_name') || ''; } catch (e) { return ''; }
  }

  function formatNumber(n) {
    return Number(n).toLocaleString('en-US', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
  }

  function plural(n, word) {
    return n + ' ' + word + (n === 1 ? '' : 's');
  }

  function durationText(seconds) {
    const h = Math.floor(seconds / 3600);
    const m = Math.round((seconds % 3600) / 60);
    const parts = [];
    if (h) parts.push(plural(h, 'hour'));
    if (m) parts.push(plural(m, 'minute'));
    return parts.join(' and ') || plural(seconds, 'second');
  }

  let toastTimer = null;
  function toast(message, isError) {
    const t = $('toast');
    t.textContent = message;
    t.classList.toggle('toast-error', !!isError);
    t.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { t.hidden = true; }, 3500);
  }

  async function copyText(text) {
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
        return true;
      }
    } catch (e) { /* fall back to the legacy method */ }
    const area = el('textarea');
    area.value = text;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    area.remove();
    return ok;
  }

  function roomUrl(id) {
    const url = new URL(window.location.href);
    url.search = '?room=' + encodeURIComponent(id);
    url.hash = '';
    return url.toString();
  }

  function setUrl(id) {
    const url = id ? 'index.php?room=' + encodeURIComponent(id) : 'index.php';
    history.replaceState(null, '', url);
  }

  // ---------- Screens ----------

  function showScreen(name) {
    document.querySelectorAll('.screen').forEach((s) => { s.hidden = s.id !== 'screen-' + name; });
    $('room-actions').hidden = name !== 'room';
    if (name !== 'room') closeModal();
  }

  function showHome(notice) {
    stopRoom();
    roomId = null;
    setUrl(null);
    const n = $('home-notice');
    n.textContent = notice || '';
    n.hidden = !notice;
    $('create-name').value = lastName();
    showScreen('home');
    $('create-name').focus();
  }

  function showMissing(kind) {
    stopRoom();
    const texts = {
      invalid: ['Invalid link', 'The room code in the link is not valid. Make sure you copied the whole link.'],
      expired: ['This room has expired', 'Rooms last at most ' + durationText(CFG.roomTtl) +
        '. You can create a new one and share the link.'],
      missing: ['This room does not exist', 'It may have expired or the link may be wrong. You can create a new one.'],
    };
    const [title, text] = texts[kind] || texts.missing;
    $('missing-title').textContent = title;
    $('missing-text').textContent = text;
    showScreen('missing');
  }

  function showJoin(notice) {
    stopRoom();
    setUrl(roomId);
    const n = $('join-notice');
    n.textContent = notice || '';
    n.hidden = !notice;
    $('join-code').textContent = roomId;
    $('join-name').value = lastName();
    showScreen('join');
    $('join-name').focus();
  }

  // ---------- API ----------

  class ApiFailure extends Error {
    constructor(code, message) { super(message); this.code = code; }
  }

  async function api(action, payload) {
    const body = Object.assign({ action: action }, payload || {});
    if (roomId && !body.room) body.room = roomId;
    if (creds && action !== 'join' && action !== 'create' && action !== 'info') {
      body.userId = creds.userId;
      body.token = creds.token;
    }
    // A hung request must not block polling: it is aborted after REQUEST_TIMEOUT_MS.
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);
    let res;
    let data = null;
    try {
      res = await fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
        cache: 'no-store',
        signal: controller.signal,
      });
      try { data = await res.json(); } catch (e) { /* non-JSON response */ }
    } catch (e) {
      throw new ApiFailure('network', 'Could not connect to the server.');
    } finally {
      clearTimeout(timer);
    }
    // A hosting error page (503, 508…) is not JSON: treat it as a connection failure.
    if (!data) throw new ApiFailure('network', 'The server is not responding. Please try again in a few seconds.');
    if (data.ok !== true) {
      throw new ApiFailure(data && data.error ? data.error : 'server',
        data && data.message ? data.message : 'Unexpected server error.');
    }
    return data;
  }

  // Handles the common errors of any request made from the room.
  function handleRoomError(err) {
    switch (err.code) {
      case 'kicked':
        clearCreds(roomId);
        creds = null;
        showJoin('The admin removed you from the room. You can join again if you want.');
        return;
      case 'not_member':
        clearCreds(roomId);
        creds = null;
        showJoin('You are no longer in the room. Enter your name to join again.');
        return;
      case 'expired':
        clearCreds(roomId);
        showMissing('expired');
        return;
      case 'not_found':
        clearCreds(roomId);
        showMissing('missing');
        return;
      case 'invalid_room':
        showMissing('invalid');
        return;
      case 'network':
        $('conn-banner').hidden = false;
        return;
      default:
        toast(err.message, true);
    }
  }

  // ---------- Room: lifecycle and polling ----------

  function enterRoom(initialState) {
    session++;
    storyDirty = false;
    lastSignature = '';
    showScreen('room');
    $('room-code').textContent = roomId;
    if (initialState) applyState(initialState);
    schedulePoll(0);
    clearInterval(expiryTimer);
    expiryTimer = setInterval(updateExpiry, 1000);
  }

  function stopRoom() {
    session++;
    clearTimeout(pollTimer);
    clearInterval(expiryTimer);
    pollTimer = null;
    state = null;
    lastSignature = '';
  }

  function schedulePoll(delay) {
    clearTimeout(pollTimer);
    pollTimer = setTimeout(poll, delay === undefined ? CFG.pollMs : delay);
  }

  async function poll() {
    // Only skip if a poll for this same room (not a previous one) is already in progress.
    if (!creds || pollInFlight === session) return;
    const mySession = session;
    const mySeq = actionSeq;
    pollInFlight = mySession;
    try {
      const data = await api('state');
      if (mySession !== session) return;
      pollFailures = 0;
      $('conn-banner').hidden = true;
      // If an action was sent while this poll was on its way, this state may be older than it.
      if (mySeq === actionSeq) applyState(data.state);
    } catch (err) {
      if (mySession !== session) return;
      if (['network', 'server', 'storage', 'busy'].includes(err.code)) {
        // Temporary failure: banner (no repeated toasts) and increasingly spaced retries.
        pollFailures++;
        $('conn-banner').hidden = false;
      } else {
        handleRoomError(err);
      }
    } finally {
      if (pollInFlight === mySession) pollInFlight = null;
      if (mySession === session) {
        schedulePoll(Math.min(CFG.pollMs * Math.pow(2, pollFailures), MAX_BACKOFF_MS));
      }
    }
  }

  // Runs an action and renders the returned state without waiting for the next poll.
  async function roomAction(action, payload) {
    const mySession = session;
    const mySeq = ++actionSeq;
    try {
      const data = await api(action, payload);
      if (mySession !== session) return null;
      $('conn-banner').hidden = true;
      if (data.state && mySeq === actionSeq) applyState(data.state);
      return data;
    } catch (err) {
      if (mySession === session) handleRoomError(err);
      return null;
    }
  }

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && creds && state) schedulePoll(0);
  });

  // ---------- Rendering the room ----------

  function applyState(s) {
    const wasRevealed = state ? state.revealed : null;
    const wasAdmin = state ? state.me.isAdmin : null;
    state = s;
    if (wasRevealed !== null && wasRevealed !== s.revealed) announceRound();
    if (wasAdmin === false && s.me.isAdmin) toast('You are now the admin.');
    clockOffset = s.serverTime - Date.now() / 1000;
    updateExpiry();
    const signature = JSON.stringify([s.story, s.revealed, s.users, s.me, s.results, s.history]);
    if (signature === lastSignature) return;
    lastSignature = signature;
    renderStory();
    renderTable();
    renderAdminBar();
    renderResults();
    renderDeck();
    renderHistory();
  }

  // Tells screen readers that the votes were revealed (with the results) or hidden again.
  function announceRound() {
    const r = state.results;
    // After a reset nobody has voted; after "Hide votes" the votes are kept.
    let text = state.users.some((u) => u.hasVoted) ? 'Votes hidden.' : 'New round. Pick your card.';
    if (state.revealed) {
      // A long tie is summarised so the announcement stays short.
      const mode = r && r.mode.length > 3 ? 'tied between ' + r.mode.length + ' values' : r && r.mode.join(' and ');
      text = r ? 'Votes revealed. Average ' + formatNumber(r.mean) + ', rounded ' + r.rounded +
        ', mode ' + mode + ', ' + plural(r.votes, 'vote') + '.' : 'Votes revealed. Nobody voted.';
    }
    $('sr-status').textContent = text;
  }

  function updateExpiry() {
    if (!state) return;
    const remaining = Math.floor(state.expiresAt - (Date.now() / 1000 + clockOffset));
    const banner = $('expiry-banner');
    if (remaining <= 0) {
      clearCreds(roomId);
      showMissing('expired');
      return;
    }
    if (remaining <= CFG.expiryWarning) {
      const m = Math.floor(remaining / 60);
      const sec = String(remaining % 60).padStart(2, '0');
      banner.textContent = 'This room expires in ' + m + ':' + sec + '. After that you will need to create a new one.';
      banner.hidden = false;
    } else {
      banner.hidden = true;
    }
  }

  function renderStory() {
    const isAdmin = state.me.isAdmin;
    const title = $('story-title');
    const form = $('form-story');
    const input = $('story-input');
    title.textContent = state.story || 'Untitled';
    title.classList.toggle('muted', !state.story);
    // While votes are revealed the title is locked until reset (so the history stores it correctly).
    const editable = isAdmin && !state.revealed;
    title.hidden = editable;
    form.hidden = !editable;
    $('story-hint').hidden = !(isAdmin && state.revealed);
    if (editable && document.activeElement !== input && !storyDirty) {
      input.value = state.story;
    }
  }

  // Spreads n points evenly (by arc length) around an ellipse, starting at the bottom.
  // Returns positions as % of the area. w and h are the real sizes in pixels.
  function seatPositions(n, w, h, rxPct, ryPct) {
    const rx = (w * rxPct) / 100;
    const ry = (h * ryPct) / 100;
    const steps = 720;
    const pts = [];
    let total = 0;
    for (let k = 0; k <= steps; k++) {
      const a = Math.PI / 2 + (k / steps) * 2 * Math.PI;
      const p = { x: rx * Math.cos(a), y: ry * Math.sin(a), len: 0 };
      if (k > 0) total += Math.hypot(p.x - pts[k - 1].x, p.y - pts[k - 1].y);
      p.len = total;
      pts.push(p);
    }
    const out = [];
    let k = 0;
    for (let i = 0; i < n; i++) {
      const target = (i * total) / n;
      while (k < steps && pts[k].len < target) k++;
      out.push({ x: 50 + (pts[k].x / w) * 100, y: 50 + (pts[k].y / h) * 100 });
    }
    return out;
  }

  function seatsOverlap(nodes) {
    const r = Array.from(nodes, (node) => node.getBoundingClientRect());
    for (let i = 0; i < r.length; i++) {
      for (let j = i + 1; j < r.length; j++) {
        if (Math.min(r[i].right, r[j].right) - Math.max(r[i].left, r[j].left) > 0 &&
            Math.min(r[i].bottom, r[j].bottom) - Math.max(r[i].top, r[j].top) > 0) return true;
      }
    }
    return false;
  }

  function renderTable() {
    const users = state.users;
    const seats = $('seats');
    // Rebuilding the seats would drop keyboard focus, so remember which seat had it.
    const focusedId = seats.contains(document.activeElement) ? document.activeElement.dataset.userId : null;
    seats.replaceChildren();
    const n = users.length;
    const meIndex = Math.max(0, users.findIndex((u) => u.id === state.me.id));
    const area = $('table-area');
    area.dataset.count = String(n);
    area.classList.remove('crowded');
    const w = area.clientWidth || 1;
    const h = area.clientHeight || 1;
    const portrait = h > w;
    // Radii of the seat ellipse, as % of the table area.
    const positions = seatPositions(n, w, h, portrait ? 39 : 43, portrait ? 43 : 39);
    const res = state.revealed ? state.results : null;
    const spread = res && res.min !== res.max;

    users.forEach((u, i) => {
      // The current user sits at the bottom; everyone else goes clockwise.
      const pos = positions[(i - meIndex + n) % n];
      const canManage = state.me.isAdmin && u.id !== state.me.id;
      const seat = el(canManage ? 'button' : 'div', 'seat');
      if (canManage) {
        seat.type = 'button';
        seat.title = 'Options for ' + u.name;
        seat.addEventListener('click', () => openUserMenu(u));
      }
      seat.dataset.userId = u.id;
      seat.style.left = pos.x + '%';
      seat.style.top = pos.y + '%';
      if (!u.online) seat.classList.add('offline');
      if (u.id === state.me.id) seat.classList.add('is-me');
      if (spread && u.vote === res.min) seat.classList.add('low');
      if (spread && u.vote === res.max) seat.classList.add('high');

      let card;
      if (state.revealed && u.vote !== null) {
        card = el('div', 'card card-face', u.vote);
      } else if (u.hasVoted) {
        card = el('div', 'card card-back');
      } else {
        card = el('div', 'card card-empty');
      }
      card.setAttribute('aria-hidden', 'true');
      seat.appendChild(card);

      const name = el('div', 'seat-name', u.name);
      name.title = u.name;
      seat.appendChild(name);

      const tags = el('div', 'seat-tags');
      if (u.isAdmin) tags.appendChild(el('span', 'tag tag-admin', '★ Admin'));
      if (u.id === state.me.id) tags.appendChild(el('span', 'tag tag-me', 'You'));
      if (!u.online) tags.appendChild(el('span', 'tag tag-off', 'Offline'));
      if (spread && u.vote === res.min) tags.appendChild(el('span', 'tag tag-low', 'Lowest'));
      if (spread && u.vote === res.max) tags.appendChild(el('span', 'tag tag-high', 'Highest'));
      seat.appendChild(tags);

      let status;
      if (state.revealed) status = u.vote !== null ? 'voted ' + u.vote : 'did not vote';
      else status = u.hasVoted ? 'has voted' : 'has not voted';
      seat.setAttribute('aria-label', u.name + (u.isAdmin ? ' (admin)' : '') + ', ' + status +
        (u.online ? '' : ', offline'));
      seats.appendChild(seat);
      if (canManage && u.id === focusedId) seat.focus();
    });
    // The focused seat is gone (removed) or no longer a button (no longer admin): focus moves to
    // the first teammate's seat, or to the cards.
    if (focusedId && !seats.contains(document.activeElement)) {
      (seats.querySelector('button.seat') || $('deck')).focus();
    }
    // If the seats overlap around the ellipse, show them in a grid below the table instead.
    if (seatsOverlap(seats.children)) area.classList.add('crowded');

    const center = $('table-center');
    center.replaceChildren();
    const voted = users.filter((u) => u.hasVoted).length;
    if (state.revealed) {
      if (res) {
        center.appendChild(el('div', 'center-big', formatNumber(res.mean)));
        center.appendChild(el('div', 'center-small', 'average of ' + plural(res.votes, 'vote')));
      } else {
        center.appendChild(el('div', 'center-small', 'Nobody voted'));
      }
    } else {
      center.appendChild(el('div', 'center-big', voted + ' / ' + n));
      center.appendChild(el('div', 'center-small', 'voted'));
    }
  }

  function renderAdminBar() {
    const isAdmin = state.me.isAdmin;
    $('admin-bar').hidden = !isAdmin;
    if (!isAdmin) return;
    const reveal = $('btn-reveal');
    const hide = $('btn-hide');
    const focused = document.activeElement;
    reveal.disabled = state.revealed;
    hide.disabled = !state.revealed;
    // A disabled button loses keyboard focus: it moves to the button that does the opposite.
    if (focused === reveal && reveal.disabled) hide.focus();
    if (focused === hide && hide.disabled) reveal.focus();
  }

  function renderResults() {
    const box = $('results');
    box.replaceChildren();
    if (!state.revealed) { box.hidden = true; return; }
    box.hidden = false;
    const r = state.results;
    if (!r) {
      box.appendChild(el('p', 'muted', 'Nobody voted in this round.'));
      return;
    }
    if (r.consensus) {
      box.appendChild(el('div', 'consensus', 'Consensus! Everyone voted ' + r.min + '.'));
    }
    const grid = el('div', 'stats');
    const stat = (label, value) => {
      const s = el('div', 'stat');
      s.appendChild(el('div', 'stat-value', value));
      s.appendChild(el('div', 'stat-label', label));
      grid.appendChild(s);
    };
    stat('Average', formatNumber(r.mean));
    stat('Rounded', r.rounded);
    stat(r.mode.length > 1 ? 'Mode (tie)' : 'Mode', r.mode.join(' · '));
    stat('Votes', r.votes + ' of ' + state.users.length);
    box.appendChild(grid);
    if (!r.consensus && r.min !== r.max) {
      box.appendChild(el('p', 'muted small', 'Lowest vote: ' + r.min + ' · Highest vote: ' + r.max +
        '. Discuss the extremes before voting again.'));
    }
  }

  function renderDeck() {
    const deck = $('deck');
    if (!deck.childElementCount) {
      CARDS.forEach((n) => {
        const b = el('button', 'deck-card', n);
        b.type = 'button';
        b.dataset.value = String(n);
        b.setAttribute('aria-label', 'Vote ' + n);
        b.addEventListener('click', () => vote(n));
        deck.appendChild(b);
      });
    }
    const focusedCard = deck.contains(document.activeElement);
    deck.querySelectorAll('.deck-card').forEach((b) => {
      const selected = Number(b.dataset.value) === state.me.vote;
      b.classList.toggle('selected', selected);
      b.setAttribute('aria-pressed', selected ? 'true' : 'false');
      b.disabled = state.revealed;
    });
    // The cards are disabled when votes are revealed: focus stays on the deck instead of the page.
    if (focusedCard && state.revealed) deck.focus();
    $('deck-hint').textContent = state.revealed
      ? 'Votes revealed. Wait for the admin to reset the round.'
      : state.me.vote === null
        ? 'Pick your card'
        : 'You voted ' + state.me.vote + ' · tap another card to change it';
  }

  function historyLine(h, i) {
    return (i + 1) + '. ' + (h.story || 'Untitled') + ' — Average ' + formatNumber(h.mean) +
      ' · Rounded ' + h.rounded + ' · Mode ' + h.mode.join(', ') + ' (' + plural(h.votes, 'vote') + ')';
  }

  function renderHistory() {
    const list = $('history-list');
    list.replaceChildren();
    state.history.forEach((h) => {
      const li = el('li', 'history-item');
      li.appendChild(el('span', 'history-story', h.story || 'Untitled'));
      const meta = el('span', 'history-meta');
      meta.appendChild(el('span', 'pill', 'Average ' + formatNumber(h.mean)));
      meta.appendChild(el('span', 'pill pill-strong', 'Rounded ' + h.rounded));
      meta.appendChild(el('span', 'pill', 'Mode ' + h.mode.join(', ')));
      meta.appendChild(el('span', 'pill pill-muted', plural(h.votes, 'vote')));
      li.appendChild(meta);
      list.appendChild(li);
    });
    $('history-empty').hidden = state.history.length > 0;
    $('btn-copy-history').disabled = state.history.length === 0;
  }

  // ---------- Actions ----------

  async function vote(n) {
    if (!state || state.revealed) return;
    const value = state.me.vote === n ? null : n;
    await roomAction('vote', { vote: value });
  }

  // In-page dialog (browser alert/confirm are not used). actions: [[label, cssClass, handler], …]
  function openModal(title, text, actions) {
    $('modal-title').textContent = title;
    $('modal-text').textContent = text;
    const box = $('modal-actions');
    box.replaceChildren();
    actions.forEach(([label, cls, fn]) => {
      const b = el('button', 'btn ' + cls, label);
      b.type = 'button';
      b.addEventListener('click', () => { closeModal(); if (fn) fn(); });
      box.appendChild(b);
    });
    modalOpener = document.activeElement;
    $('modal').hidden = false;
    box.lastChild.focus(); // focus on "Cancel": Enter never triggers a destructive action by accident
  }

  function openUserMenu(user) {
    const actions = [];
    if (user.online) {
      actions.push(['Make admin', 'btn-secondary', async () => {
        const ok = await roomAction('transfer', { target: user.id });
        if (ok && !ok.state.me.isAdmin) toast(user.name + ' is now the admin.');
      }]);
    }
    actions.push(['Remove from room', 'btn-danger', async () => {
      const ok = await roomAction('kick', { target: user.id });
      if (ok) toast(user.name + ' was removed from the room.');
    }]);
    actions.push(['Cancel', 'btn-ghost', null]);
    openModal(user.name, user.online ? 'What do you want to do with this user?' :
      'This user is offline. The admin role can only be given to someone who is online.', actions);
  }

  function closeModal() {
    if ($('modal').hidden) return;
    $('modal').hidden = true;
    // Focus goes back to what opened the dialog. If it was a seat that has been rebuilt since,
    // the seat of the same user is used.
    let target = modalOpener;
    modalOpener = null;
    if (target && !target.isConnected && target.dataset.userId) {
      target = $('seats').querySelector('[data-user-id="' + CSS.escape(target.dataset.userId) + '"]');
    }
    if (target && target.isConnected) target.focus();
  }

  $('modal').addEventListener('click', (e) => { if (e.target === $('modal')) closeModal(); });
  document.addEventListener('keydown', (e) => {
    if ($('modal').hidden) return;
    if (e.key === 'Escape') {
      closeModal();
    } else if (e.key === 'Tab') {
      // Keeps keyboard focus inside the dialog.
      const buttons = $('modal-actions').querySelectorAll('button');
      const first = buttons[0];
      const last = buttons[buttons.length - 1];
      if (e.shiftKey && (document.activeElement === first || !$('modal').contains(document.activeElement))) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && (document.activeElement === last || !$('modal').contains(document.activeElement))) {
        e.preventDefault();
        first.focus();
      }
    }
  });

  $('btn-reveal').addEventListener('click', () => roomAction('reveal'));
  $('btn-hide').addEventListener('click', () => roomAction('hide'));
  $('btn-reset').addEventListener('click', () => roomAction('reset'));

  $('btn-copy-link').addEventListener('click', async () => {
    const ok = await copyText(roomUrl(roomId));
    toast(ok ? 'Link copied. Share it with your team.' : 'Could not copy. Copy the address from the browser bar.', !ok);
  });

  $('btn-copy-history').addEventListener('click', async () => {
    if (!state || !state.history.length) return;
    const text = 'Planning Poker · Room ' + roomId + '\n' + state.history.map(historyLine).join('\n');
    const ok = await copyText(text);
    toast(ok ? 'History copied.' : 'Could not copy the history.', !ok);
  });

  $('btn-leave').addEventListener('click', () => {
    const text = state && state.me.isAdmin && state.users.length > 1
      ? 'You will give up your seat and the admin role will pass to someone else.'
      : 'You will give up your seat at the table. You can join again with the link.';
    openModal('Leave the room?', text, [
      ['Leave room', 'btn-danger', leaveRoom],
      ['Cancel', 'btn-ghost', null],
    ]);
  });

  async function leaveRoom() {
    const id = roomId;
    try {
      await api('leave');
    } catch (err) {
      if (err.code === 'network') { toast(err.message, true); return; }
      // If they were no longer a member or the room is gone, leave anyway.
    }
    clearCreds(id);
    creds = null;
    showHome('You left the room.');
  }

  // Limits a text field to max characters counted like the server does (an emoji counts as 1, not 2).
  function limitLength(input, max) {
    input.maxLength = max * 2; // UTF-16 units: a hard cap, the exact limit is applied below
    input.addEventListener('input', () => {
      const chars = Array.from(input.value);
      if (chars.length > max) input.value = chars.slice(0, max).join('');
    });
  }

  limitLength($('story-input'), CFG.maxStory);
  $('story-input').addEventListener('input', () => { storyDirty = true; });
  $('form-story').addEventListener('submit', async (e) => {
    e.preventDefault();
    const ok = await roomAction('story', { story: $('story-input').value });
    if (ok) {
      storyDirty = false;
      $('story-input').value = ok.state.story;
      $('story-input').blur();
      toast('Title saved.');
    }
  });

  // ---------- Create and join ----------

  function lockForm(form, locked) {
    form.querySelectorAll('button, input').forEach((c) => { c.disabled = locked; });
  }

  limitLength($('create-name'), CFG.maxName);
  limitLength($('join-name'), CFG.maxName);

  // Returns the trimmed name, or null (with a message) if it is empty.
  function readName(input) {
    const name = input.value.trim();
    if (name) return name;
    input.value = '';
    input.focus();
    toast('Please enter a name.', true);
    return null;
  }

  $('form-create').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.currentTarget;
    const name = readName($('create-name'));
    if (!name) return;
    lockForm(form, true);
    try {
      const data = await api('create', { name: name });
      rememberName(name);
      roomId = data.room;
      creds = { userId: data.userId, token: data.token };
      saveCreds(roomId, creds);
      setUrl(roomId);
      enterRoom(data.state);
      toast('Room created. Copy the link and share it with your team.');
    } catch (err) {
      toast(err.message, true);
    } finally {
      lockForm(form, false);
    }
  });

  $('form-join').addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.currentTarget;
    const name = readName($('join-name'));
    if (!name) return;
    lockForm(form, true);
    try {
      const data = await api('join', { name: name });
      rememberName(name);
      creds = { userId: data.userId, token: data.token };
      saveCreds(roomId, creds);
      enterRoom(data.state);
    } catch (err) {
      if (['expired', 'not_found', 'invalid_room'].includes(err.code)) handleRoomError(err);
      else toast(err.message, true);
    } finally {
      lockForm(form, false);
    }
  });

  // Re-lays out the seats when the phone rotates or the window is resized.
  let resizeTimer = null;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => { if (state) renderTable(); }, 150);
  });

  // ---------- Startup ----------

  async function start() {
    // Codes copied from a chat may come with spaces or in capitals.
    const param = (new URLSearchParams(window.location.search).get('room') || '').trim().toLowerCase();
    if (param === '') {
      showHome();
      return;
    }
    if (!ROOM_ID_RE.test(param)) {
      showMissing('invalid');
      return;
    }
    roomId = param;
    setUrl(roomId);
    creds = loadCreds(roomId);
    try {
      if (creds) {
        const data = await api('state');
        enterRoom(data.state);
      } else {
        await api('info');
        showJoin();
      }
    } catch (err) {
      if (err.code === 'network') {
        // There is no room screen yet: retry the startup.
        toast('No connection to the server. Retrying…', true);
        setTimeout(start, 3000);
      } else {
        handleRoomError(err);
      }
    }
  }

  start();
})();
