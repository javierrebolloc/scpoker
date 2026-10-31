<?php
// Room logic: users, presence, admin role, votes and results.
declare(strict_types=1);

const USER_ID_REGEX = '/^u_[a-f0-9]{12}$/D';
const TOKEN_REGEX = '/^[a-f0-9]{64}$/D';
const MAX_HISTORY = 200;  // Maximum rounds kept in the history
const MAX_KICKED = 100;   // Removals remembered (only used to notify the removed user)

/**
 * Cleans user text: valid UTF-8, no control characters, normalised spaces and a maximum length.
 * Invisible format characters (bidi overrides…) are removed, except the zero-width joiner and
 * non-joiner (U+200D, U+200C), which compound emoji and some scripts (Persian, Indic) need.
 */
function clean_text(mixed $value, int $max): string
{
    // preg with /u fails (returns false/null) on invalid UTF-8. No dependency on mbstring.
    if (!is_string($value) || preg_match('//u', $value) !== 1) {
        return '';
    }
    $value = preg_replace('/(?:[\p{Cc}\p{Zl}\p{Zp}]|(?![\x{200C}\x{200D}])\p{Cf})+/u', ' ', $value) ?? '';
    $value = preg_replace('/\s+/u', ' ', $value) ?? '';
    $edges = '/^[\s\x{200C}\x{200D}]+|[\s\x{200C}\x{200D}]+$/u';
    $value = preg_replace($edges, '', $value) ?? '';
    preg_match('/^.{0,' . $max . '}/us', $value, $m);
    return preg_replace($edges, '', $m[0] ?? '') ?? '';
}

function require_name(mixed $value, array $cfg): string
{
    $name = clean_text($value, $cfg['MAX_NAME']);
    // At least one visible character (letter, digit, symbol or punctuation).
    if (preg_match('/[\p{L}\p{N}\p{S}\p{P}]/u', $name) !== 1) {
        throw new ApiError('invalid_name', 'Please enter a name.');
    }
    return $name;
}

/** If the name is already used in the room, adds " (2)", " (3)"… so people can be told apart. */
function unique_name(array $room, string $name, array $cfg): string
{
    $taken = [];
    foreach ($room['users'] as $user) {
        $taken[strtolower($user['name'])] = true;
    }
    $candidate = $name;
    // MAX_USERS people can use at most MAX_USERS numbers, so this always ends.
    for ($n = 2; isset($taken[strtolower($candidate)]); $n++) {
        $suffix = ' (' . $n . ')';
        // The name is shortened if needed so the suffix still fits in MAX_NAME.
        preg_match('/^.{1,' . max(1, $cfg['MAX_NAME'] - strlen($suffix)) . '}/us', $name, $m);
        $candidate = rtrim($m[0]) . $suffix;
    }
    return $candidate;
}

/** Creates a new user. Returns [userId, token, record]. The token is only stored as a hash. */
function new_user(string $name): array
{
    $id = 'u_' . bin2hex(random_bytes(6));
    $token = bin2hex(random_bytes(32));
    $now = time();
    return [$id, $token, [
        'name'      => $name,
        'tokenHash' => hash('sha256', $token),
        'vote'      => null,
        'joinedAt'  => $now,
        'lastSeen'  => $now,
    ]];
}

function new_room(string $id, string $userId, array $user, array $cfg): array
{
    $now = time();
    return [
        'id'        => $id,
        'createdAt' => $now,
        'expiresAt' => $now + $cfg['ROOM_TTL'],
        'adminId'   => $userId,
        'story'     => '',
        'revealed'  => false,
        'users'     => [$userId => $user],
        'kicked'    => [],
        'history'   => [],
    ];
}

/** Checks user and token. Throws 'kicked' or 'not_member' if the caller is not in the room. */
function authenticate(array $room, mixed $userId, mixed $token): string
{
    if (!is_string($userId) || !is_string($token)
        || preg_match(USER_ID_REGEX, $userId) !== 1 || preg_match(TOKEN_REGEX, $token) !== 1) {
        throw new ApiError('not_member', 'You are not in this room.', 403);
    }
    $user = $room['users'][$userId] ?? null;
    if ($user === null || !hash_equals($user['tokenHash'], hash('sha256', $token))) {
        if (isset($room['kicked'][$userId]) && hash_equals($room['kicked'][$userId], hash('sha256', $token))) {
            throw new ApiError('kicked', 'The admin removed you from the room.', 403);
        }
        throw new ApiError('not_member', 'You are not in this room.', 403);
    }
    return $userId;
}

function require_admin(array $room, string $userId): void
{
    if ($room['adminId'] !== $userId) {
        throw new ApiError('forbidden', 'Only the admin can do this.', 403);
    }
}

function is_online(array $user, array $cfg, int $now): bool
{
    return $now - $user['lastSeen'] <= $cfg['OFFLINE_AFTER'];
}

/** Longest-standing user in the room (optionally online only), excluding $exclude. */
function oldest_user(array $room, ?string $exclude, bool $onlineOnly, array $cfg): ?string
{
    $now = time();
    $best = null;
    foreach ($room['users'] as $id => $user) {
        if ($id === $exclude || ($onlineOnly && !is_online($user, $cfg, $now))) {
            continue;
        }
        if ($best === null || $user['joinedAt'] < $room['users'][$best]['joinedAt']) {
            $best = $id;
        }
    }
    return $best;
}

/** Makes sure there is a valid admin and hands the role over if the admin has been inactive too long. */
function ensure_admin(array &$room, array $cfg): void
{
    $adminId = $room['adminId'];
    if ($adminId === null || !isset($room['users'][$adminId])) {
        $room['adminId'] = oldest_user($room, null, true, $cfg) ?? oldest_user($room, null, false, $cfg);
        return;
    }
    if (time() - $room['users'][$adminId]['lastSeen'] > $cfg['ADMIN_TIMEOUT']) {
        $next = oldest_user($room, $adminId, true, $cfg);
        if ($next !== null) {
            $room['adminId'] = $next;
        }
    }
}

/**
 * Records user activity. To avoid rewriting the file on every poll, it is only updated after
 * a fraction of the offline threshold has passed (5 s with 30 s).
 */
function touch_user(array &$room, string $userId, array $cfg): void
{
    $step = max(1, intdiv($cfg['OFFLINE_AFTER'], 6));
    if (time() - $room['users'][$userId]['lastSeen'] >= $step) {
        $room['users'][$userId]['lastSeen'] = time();
    }
}

/** Adds a round to the history, keeping only the last MAX_HISTORY. */
function add_history(array &$room, array $results, string $story): void
{
    $room['history'][] = [
        'story'   => $story,
        'votes'   => $results['votes'],
        'mean'    => $results['mean'],
        'rounded' => $results['rounded'],
        'mode'    => $results['mode'],
        'at'      => time(),
    ];
    $room['history'] = array_slice($room['history'], -MAX_HISTORY);
}

/** Removes a user and remembers their token (hash) so they can be told they were removed. */
function kick_user(array &$room, string $userId, array $cfg): void
{
    $room['kicked'][$userId] = $room['users'][$userId]['tokenHash'];
    $room['kicked'] = array_slice($room['kicked'], -MAX_KICKED, null, true);
    remove_user($room, $userId, $cfg);
}

/** Removes a user from the room and, if they were the admin, hands the role to the next one. */
function remove_user(array &$room, string $userId, array $cfg): void
{
    unset($room['users'][$userId]);
    if ($room['adminId'] === $userId) {
        $room['adminId'] = oldest_user($room, null, true, $cfg) ?? oldest_user($room, null, false, $cfg);
    }
}

/** Computes the results of a round. Returns null if nobody voted. */
function compute_results(array $room): ?array
{
    $votes = [];
    foreach ($room['users'] as $user) {
        if (is_int($user['vote'])) {
            $votes[] = $user['vote'];
        }
    }
    if ($votes === []) {
        return null;
    }
    $exact = array_sum($votes) / count($votes);
    $counts = array_count_values($votes);
    $top = max($counts);
    $mode = array_keys(array_filter($counts, fn ($c) => $c === $top));
    sort($mode);
    return [
        'votes'     => count($votes),
        'mean'      => round($exact, 1),
        // Nearest integer to the exact mean (not to the mean already rounded to 1 decimal).
        'rounded'   => (int) round($exact),
        'mode'      => $mode,
        'min'       => min($votes),
        'max'       => max($votes),
        'consensus' => count($votes) >= 2 && min($votes) === max($votes),
    ];
}

/** Room view sent to the browser. It never includes other people's votes before they are revealed. */
function public_view(array $room, string $me, array $cfg): array
{
    $now = time();
    $users = [];
    foreach ($room['users'] as $id => $user) {
        $users[] = [
            'id'       => $id,
            'name'     => $user['name'],
            'isAdmin'  => $id === $room['adminId'],
            'hasVoted' => $user['vote'] !== null,
            'online'   => is_online($user, $cfg, $now),
            'vote'     => $room['revealed'] ? $user['vote'] : null,
        ];
    }
    return [
        'id'         => $room['id'],
        'story'      => $room['story'],
        'revealed'   => $room['revealed'],
        'users'      => $users,
        'me'         => [
            'id'      => $me,
            'isAdmin' => $room['adminId'] === $me,
            'vote'    => $room['users'][$me]['vote'],
        ],
        'results'    => $room['revealed'] ? compute_results($room) : null,
        'history'    => $room['history'],
        'expiresAt'  => $room['expiresAt'],
        'serverTime' => $now,
    ];
}
