<?php
// Room persistence: one file per room in data/, protected with flock().
//
// Each room is stored as data/<id>.php: a first line that stops execution (FILE_GUARD)
// followed by the JSON. Even if the server ignored data/.htaccess, requesting the file shows nothing.
// Locking uses data/<id>.lock and writes are atomic (temporary file + rename), so a failed
// write (e.g. full disk) never leaves the room empty or half-written.
declare(strict_types=1);

const DATA_DIR = __DIR__ . '/../data';
const ROOM_ID_ALPHABET = 'abcdefghijkmnpqrstuvwxyz23456789'; // no 0/o/1/l to avoid confusion
const ROOM_ID_REGEX = '/^[a-km-np-z2-9]{8}$/D';               // exactly the alphabet above
const FILE_GUARD = "<?php exit; ?" . ">\n"; // split so it does not close this file's PHP block

final class ApiError extends Exception
{
    /** @var string */
    public $errorCode;
    /** @var int */
    public $status;

    public function __construct(string $errorCode, string $message, int $status = 400)
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->status = $status;
    }
}

function room_id_valid($id): bool
{
    return is_string($id) && preg_match(ROOM_ID_REGEX, $id) === 1;
}

function room_path(string $id): string
{
    if (!room_id_valid($id)) {
        throw new ApiError('invalid_room', 'Invalid room ID.');
    }
    return DATA_DIR . '/' . $id . '.php';
}

function lock_path(string $id): string
{
    return DATA_DIR . '/' . $id . '.lock';
}

function ensure_data_dir(): void
{
    if (!is_dir(DATA_DIR) || !is_writable(DATA_DIR)) {
        throw new ApiError('storage', 'The data/ folder does not exist or is not writable.', 500);
    }
}

function random_room_id(): string
{
    $id = '';
    $max = strlen(ROOM_ID_ALPHABET) - 1;
    for ($i = 0; $i < 8; $i++) {
        $id .= ROOM_ID_ALPHABET[random_int(0, $max)];
    }
    return $id;
}

/** Reads a guarded file (FILE_GUARD header + JSON). Returns null if it is missing or invalid. */
function read_guarded(string $path): ?array
{
    $raw = @file_get_contents($path);
    if ($raw === false || strncmp($raw, FILE_GUARD, strlen(FILE_GUARD)) !== 0) {
        return null;
    }
    $data = json_decode(substr($raw, strlen(FILE_GUARD)), true);
    return is_array($data) ? $data : null;
}

/** Writes a guarded file atomically: if anything fails, the previous file is left untouched. */
function write_guarded(string $path, string $json): void
{
    $tmp = substr($path, 0, -4) . '-' . bin2hex(random_bytes(4)) . '.tmp.php';
    $data = FILE_GUARD . $json;
    $written = @file_put_contents($tmp, $data) === strlen($data);
    // rename() is atomic. On some systems (e.g. Windows with an antivirus) it can fail for a moment
    // while another process has the target open, so it is retried briefly.
    for ($try = 0; $written && $try < 5; $try++) {
        if (@rename($tmp, $path)) {
            return;
        }
        usleep(20000 * ($try + 1));
    }
    @unlink($tmp);
    throw new ApiError('storage', 'The room could not be saved. Please try again.', 500);
}

function encode_room(array $room): string
{
    // JSON_HEX_TAG writes < and > as < / >, so names can never put "<?php" into the stored file.
    return json_encode($room, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_THROW_ON_ERROR);
}

function is_valid_room($room): bool
{
    return is_array($room) && is_int($room['expiresAt'] ?? null) && is_array($room['users'] ?? null)
        && is_array($room['history'] ?? null) && is_array($room['kicked'] ?? null);
}

const LOCK_TIMEOUT = 5.0; // maximum seconds spent waiting for a lock

/**
 * Opens and exclusively locks a lock file. It never waits forever: if the lock is not acquired
 * within LOCK_TIMEOUT seconds it answers "busy", so PHP processes do not pile up on the host.
 */
function acquire_lock(string $lockFile)
{
    $fp = @fopen($lockFile, 'c');
    if ($fp === false) {
        throw new ApiError('storage', 'Cannot access data/. Check the folder permissions.', 500);
    }
    $deadline = microtime(true) + LOCK_TIMEOUT;
    while (!flock($fp, LOCK_EX | LOCK_NB)) {
        if (microtime(true) > $deadline) {
            fclose($fp);
            throw new ApiError('busy', 'The server is busy. Please try again in a few seconds.', 503);
        }
        usleep(20000);
    }
    return $fp;
}

function lock_room(string $id)
{
    return acquire_lock(lock_path($id));
}

function unlock_room($fp): void
{
    flock($fp, LOCK_UN);
    fclose($fp);
}

/**
 * Creates a new room with a free ID. $build receives the ID and returns the room array.
 */
function create_room(callable $build): array
{
    ensure_data_dir();
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $id = random_room_id();
        $path = room_path($id);
        // Mode 'x' fails if the file already exists: it reserves the ID even under concurrent requests.
        $reserve = @fopen($path, 'x');
        if ($reserve === false) {
            continue;
        }
        fclose($reserve);
        $lock = lock_room($id);
        try {
            $room = $build($id);
            write_guarded($path, encode_room($room));
            return $room;
        } catch (Throwable $e) {
            @unlink($path);
            throw $e;
        } finally {
            unlock_room($lock);
        }
    }
    throw new ApiError('storage', 'The room could not be created. Please try again.', 500);
}

/**
 * Locks the room, runs $fn(&$room) and saves it if it changed.
 * If $fn throws, nothing is saved.
 */
function with_room(string $id, callable $fn)
{
    $path = room_path($id);
    if (!is_file($path)) {
        throw new ApiError('not_found', 'The room does not exist or has expired.', 404);
    }
    $lock = lock_room($id);
    try {
        clearstatcache(true, $path);
        if (!is_file($path)) {
            throw new ApiError('not_found', 'The room does not exist or has expired.', 404);
        }
        if (!is_readable($path)) {
            throw new ApiError('storage', 'The room cannot be read. Check the permissions of data/.', 500);
        }
        $room = read_guarded($path);
        if (!is_valid_room($room)) {
            throw new ApiError('not_found', 'The room does not exist or has expired.', 404);
        }
        if ($room['expiresAt'] <= time()) {
            throw new ApiError('expired', 'The room has expired.', 410);
        }
        $before = encode_room($room);
        $result = $fn($room);
        $after = encode_room($room);
        if ($after !== $before) {
            write_guarded($path, $after);
        }
        return $result;
    } finally {
        unlock_room($lock);
    }
}

const ROOM_FILES_HARD_CAP = 10000; // absolute cap on room files (protects the host's inodes)
const ACTIVE_WINDOW = 600;         // a room is "active" if it was written to in the last 10 minutes

/** Counts rooms: [active, total files]. Abandoned rooms do not count as active. */
function count_rooms(): array
{
    $now = time();
    $active = 0;
    $total = 0;
    foreach (glob(DATA_DIR . '/*.php') ?: [] as $file) {
        if (room_id_valid(basename($file, '.php'))) {
            $total++;
            if ($now - (int) @filemtime($file) < ACTIVE_WINDOW) {
                $active++;
            }
        }
    }
    return [$active, $total];
}

/**
 * Rate-limit keys for a client: [client, network].
 * - client: the full IPv4 address or the /64 prefix of an IPv6 address (an IPv6 connection usually
 *   gets a whole /64).
 * - network: the /48 prefix of an IPv6 address (one customer often gets a /56 or /48, i.e. many /64s);
 *   null for IPv4.
 * IPv4-mapped IPv6 addresses (::ffff:a.b.c.d) are treated as IPv4.
 * They are stored as HMACs with a random per-install salt so IPs are not kept in clear text (someone
 * holding the whole file, salt included, could still test IPs).
 */
function client_keys(string $ip, string $salt): array
{
    $hash = fn (string $s) => substr(hash_hmac('sha256', $s, $salt), 0, 20);
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return [$hash($ip), null];
    }
    if (strlen($bin) === 16 && strncmp($bin, str_repeat("\0", 10) . "\xff\xff", 12) === 0) {
        $bin = substr($bin, 12);
    }
    if (strlen($bin) === 4) {
        return [$hash($bin), null];
    }
    return [$hash(substr($bin, 0, 8)), 'n' . $hash(substr($bin, 0, 6))];
}

/**
 * Creates a room within the limits: MAX_ROOMS_PER_IP_HOUR per client, MAX_ROOMS_PER_NET_HOUR per
 * IPv6 /48 network, MAX_ROOMS active rooms and ROOM_FILES_HARD_CAP files in total.
 * Everything happens under one lock, so concurrent requests cannot exceed the limits.
 */
function create_room_limited(string $ip, array $cfg, callable $build): array
{
    $path = DATA_DIR . '/ratelimit.php';
    $lock = acquire_lock(DATA_DIR . '/ratelimit.lock');
    try {
        $now = time();
        $data = read_guarded($path) ?? [];
        $salt = is_string($data['salt'] ?? null) ? $data['salt'] : bin2hex(random_bytes(16));
        $hits = [];
        // Only creations from the last hour are kept.
        foreach ((array) ($data['hits'] ?? []) as $key => $times) {
            $recent = array_values(array_filter((array) $times, fn ($t) => is_int($t) && $t > $now - 3600));
            if ($recent !== []) {
                $hits[$key] = $recent;
            }
        }
        [$key, $netKey] = client_keys($ip, $salt);
        if (count($hits[$key] ?? []) >= $cfg['MAX_ROOMS_PER_IP_HOUR']
            || ($netKey !== null && count($hits[$netKey] ?? []) >= $cfg['MAX_ROOMS_PER_NET_HOUR'])) {
            throw new ApiError('rate_limit', 'You have created too many rooms. Please wait a while and try again.', 429);
        }
        [$active, $total] = count_rooms();
        if ($active >= $cfg['MAX_ROOMS'] || $total >= ROOM_FILES_HARD_CAP) {
            throw new ApiError('busy', 'There are too many open rooms right now. Please try again later.', 503);
        }
        // The attempt is recorded before creating the room: if that write fails, no orphan room is left.
        $hits[$key][] = $now;
        if ($netKey !== null) {
            $hits[$netKey][] = $now;
        }
        write_guarded($path, json_encode(['salt' => $salt, 'hits' => $hits], JSON_HEX_TAG | JSON_THROW_ON_ERROR));
        return create_room($build);
    } finally {
        unlock_room($lock);
    }
}

/**
 * Deletes expired rooms and leftovers (orphan locks or temporary files). It runs at most once every
 * $interval seconds (marker file, no cron) and for a bounded amount of time.
 */
function cleanup_expired(int $interval): void
{
    if (!is_dir(DATA_DIR) || !is_writable(DATA_DIR)) {
        return;
    }
    $marker = DATA_DIR . '/.last-cleanup';
    $now = time();
    clearstatcache(true, $marker);
    if (is_file($marker) && $now - (int) @filemtime($marker) < $interval) {
        return;
    }
    @touch($marker);
    $deadline = microtime(true) + 2.0;

    // Random order: if a pass is cut short by the deadline, the next one checks other files.
    $files = glob(DATA_DIR . '/*.php') ?: [];
    shuffle($files);
    foreach ($files as $file) {
        if (microtime(true) > $deadline) {
            return; // The rest is cleaned up in the next pass.
        }
        $name = basename($file);
        $age = $now - (int) @filemtime($file);
        if (substr($name, -8) === '.tmp.php') {
            if ($age > 60) {
                @unlink($file); // Temporary file left behind by an interrupted write.
            }
            continue;
        }
        $id = basename($file, '.php');
        if (!room_id_valid($id)) {
            continue;
        }
        if (!is_readable($file)) {
            continue; // Permission problem: not deleted; with_room reports it as a storage error.
        }
        $lock = @fopen(lock_path($id), 'c');
        if ($lock === false) {
            continue;
        }
        $expired = false;
        if (flock($lock, LOCK_EX | LOCK_NB)) {
            $room = read_guarded($file);
            // A file with invalid content is only deleted if it is old (it could be being created).
            $expired = is_valid_room($room) ? $room['expiresAt'] <= $now : $age > 60;
            if ($expired) {
                @unlink($file);
            }
            flock($lock, LOCK_UN);
        }
        fclose($lock);
        if ($expired) {
            // Anyone waiting for this lock will see that the room is gone and answer 404.
            @unlink(lock_path($id));
        }
    }

    // Locks whose room no longer exists.
    foreach (glob(DATA_DIR . '/*.lock') ?: [] as $file) {
        $id = basename($file, '.lock');
        if (room_id_valid($id) && !is_file(room_path($id)) && $now - (int) @filemtime($file) > 60) {
            @unlink($file);
        }
    }
}
