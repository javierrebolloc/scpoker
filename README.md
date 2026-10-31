# Planning Poker

A simple, self-hosted web app for teams to estimate User Stories with cards from 1 to 10.

It runs on any shared PHP 8 host: **no database, no Node.js, no cron jobs, no external services.**
Upload the files, open the page, create a room and share the link.

## Features

- Create a room in one click and share its link. No accounts or passwords.
- Votes stay hidden until the admin reveals them, then everyone sees the cards at the same time.
- Results: average, rounded value, mode (with ties) and votes cast.
- History of the rounds played in the room, which can be copied as text.
- The admin can set the User Story title, reveal, hide and reset rounds, remove people and hand over the role.
- If the admin goes away, the role passes automatically to someone who is still online.
- Shows who is online and who has voted, and warns before the room expires.
- Works on phones and desktops, with keyboard and screen reader support.
- Rooms are deleted automatically after 3 hours.

## How it works

```
 Browser A ──┐                      ┌── data/<room>.php  (one file per room)
 Browser B ──┼── POST api.php ──────┤
 Browser C ──┘   every 1.5 s        └── locked while it is read and written
```

- **One file per room.** Each room is a JSON document stored in `data/<code>.php`. The file starts with
  `<?php exit; ?>`, so it prints nothing even if it is requested directly.
- **Polling.** Every browser asks the server for the room state every 1.5 seconds. Changes (a new vote,
  someone joining, a reveal) reach everybody within that time. No WebSockets are needed, so it works on
  shared hosting.
- **Hidden votes.** Until the round is revealed, the server only tells other browsers *that* someone has
  voted, never *what* they voted. Votes cannot be read from the network tab.
- **Identity.** Joining a room returns a user ID and a random token, kept in the browser's `localStorage`
  so a reload puts you back in your seat. The server stores only a SHA-256 hash of the token.
- **Concurrency.** Every request locks the room file (`flock`), reads it, applies the change and writes it
  back atomically (temporary file + rename). Simultaneous votes are never lost and a file is never left
  half-written.
- **Cleanup.** Expired rooms are deleted by later requests (at most once a minute), so no cron job is needed.

## Requirements

- PHP 8.0 or later.
- Apache or LiteSpeed with `.htaccess` support (the default on most shared hosts, including Hostinger).
- A writable `data/` folder.

## Installation

1. Download the code (*Code → Download ZIP* on GitHub, or `git clone`).
2. Upload **the contents** of the repository to your web root (for example `public_html/`) or to a
   subfolder (`public_html/poker/`).
   Make sure the hidden files `data/.htaccess` and `lib/.htaccess` are uploaded too. In FileZilla, enable
   *Server → Force showing hidden files*.
3. Make `data/` writable by PHP: permissions **755**, or **775** if creating a room shows
   "The data/ folder does not exist or is not writable".
4. Enable HTTPS for the domain. "Copy link" works best over HTTPS, and over HTTPS the app tells browsers
   to always use it (HSTS).

### Checking the installation

- Open `https://your-domain/` and create a room. `data/` should now contain `<code>.php` and `<code>.lock`.
- `https://your-domain/data/<code>.php` and `https://your-domain/lib/room.php` must return
  **403 Forbidden**.

## Configuration

Settings live in `lib/config.php`:

| Key | Default | What it does |
|---|---|---|
| `ROOM_TTL` | 10800 (3 h) | Maximum lifetime of a room since it was created |
| `EXPIRY_WARNING` | 600 (10 min) | How long before expiry the warning is shown |
| `OFFLINE_AFTER` | 30 s | Inactivity after which someone is shown as offline |
| `ADMIN_TIMEOUT` | 120 s | Admin inactivity after which the role passes to someone else |
| `POLL_INTERVAL_MS` | 1500 ms | How often the browser polls the server |
| `CLEANUP_INTERVAL` | 60 s | Minimum time between automatic cleanups of expired rooms |
| `MAX_NAME` / `MAX_STORY` | 30 / 120 | Maximum length of names and User Story titles |
| `MAX_USERS` | 30 | People per room. Offline people count too, because nobody is removed for inactivity |
| `MAX_ROOMS` | 500 | Active rooms at the same time (activity in the last 10 min); there is also a fixed cap of 10,000 files |
| `MAX_ROOMS_PER_IP_HOUR` | 20 | Rooms a single IP (or IPv6 /64 network) can create per hour |
| `MAX_ROOMS_PER_NET_HOUR` | 60 | Rooms a whole IPv6 /48 network can create per hour |

## Usage

- Whoever creates the room is the admin. Clicking a teammate lets the admin make them the admin
  (if online) or remove them.
- **Copy link** copies the room URL (`index.php?room=CODE`) to share it.
- When a revealed round is reset, its results go to the history. While votes are revealed the title is
  locked; reset the round before moving to the next story.
- "Average" is shown with one decimal. "Rounded" is the nearest integer to the exact average (an exact .5
  rounds up). "Mode (tie)" means several values got the same, highest number of votes.
- If someone joins with a name already used in the room, " (2)", " (3)"… is added.

## Privacy and security

- No accounts, cookies, analytics or third-party resources. A strict Content Security Policy only allows
  the app's own files.
- Stored data is limited to what a room needs (names, votes, story titles, history) and is deleted when the
  room expires.
- The per-IP room limit stores keyed hashes (HMAC) of IP addresses, not the addresses themselves.
- `.htaccess` files block direct access to `lib/` and `data/`.
- The interface is rendered with `textContent` only, so user input is never interpreted as HTML.

## Good to know

- The per-IP limit uses the IP that PHP sees. Behind a proxy that does not pass the client IP on
  (Cloudflare in proxy mode, for example), all users share the same limit.
- Removing someone does not ban them: anyone with the link can join again. If that becomes a problem,
  create a new room and share the new link only with your team.
- Browsers slow down background tabs and phones suspend them when locked, so those people may show as
  offline for a while. When they come back they get their seat and vote back.

## License

[MIT](LICENSE)
