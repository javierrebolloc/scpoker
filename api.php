<?php
// Planning Poker JSON API. Every request is a POST with a JSON body that includes "action".
declare(strict_types=1);

// A PHP warning must never be mixed into the JSON response: it is logged, not displayed.
ini_set('display_errors', '0');

require __DIR__ . '/lib/storage.php';
require __DIR__ . '/lib/room.php';

$cfg = require __DIR__ . '/lib/config.php';

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['HTTPS'] ?? '') !== '' && strtolower($_SERVER['HTTPS']) !== 'off') {
    header('Strict-Transport-Security: max-age=31536000');
}

function respond(array $data, int $status = 200): void
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $status = 500;
        $json = '{"ok":false,"error":"server","message":"Internal server error."}';
    }
    http_response_code($status);
    echo $json;
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        throw new ApiError('method', 'Method not allowed.', 405);
    }
    // Requiring JSON forces other origins through a CORS preflight, which the browser blocks.
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
        throw new ApiError('bad_request', 'The request must be JSON.', 415);
    }
    $raw = file_get_contents('php://input', false, null, 0, 16384);
    $in = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($in) || !is_string($in['action'] ?? null)) {
        throw new ApiError('bad_request', 'Invalid request.');
    }
    $action = $in['action'];

    cleanup_expired($cfg['CLEANUP_INTERVAL']);

    if ($action === 'create') {
        $name = require_name($in['name'] ?? null, $cfg);
        [$userId, $token, $user] = new_user($name);
        $room = create_room_limited($_SERVER['REMOTE_ADDR'] ?? '', $cfg,
            fn (string $id) => new_room($id, $userId, $user, $cfg));
        respond(['ok' => true, 'room' => $room['id'], 'userId' => $userId, 'token' => $token,
                 'state' => public_view($room, $userId, $cfg)]);
    }

    $roomId = $in['room'] ?? null;
    if (!room_id_valid($roomId)) {
        throw new ApiError('invalid_room', 'Invalid room ID.');
    }

    // Public existence check (join screen). It does not expose any room data.
    if ($action === 'info') {
        with_room($roomId, fn (array &$room) => null);
        respond(['ok' => true]);
    }

    if ($action === 'join') {
        $name = require_name($in['name'] ?? null, $cfg);
        $result = with_room($roomId, function (array &$room) use ($name, $cfg) {
            if (count($room['users']) >= $cfg['MAX_USERS']) {
                throw new ApiError('full', 'The room is full.', 409);
            }
            [$userId, $token, $user] = new_user(unique_name($room, $name, $cfg));
            $room['users'][$userId] = $user;
            ensure_admin($room, $cfg);
            return ['userId' => $userId, 'token' => $token, 'state' => public_view($room, $userId, $cfg)];
        });
        respond(['ok' => true, 'room' => $roomId] + $result);
    }

    $state = with_room($roomId, function (array &$room) use ($action, $in, $cfg) {
        $me = authenticate($room, $in['userId'] ?? null, $in['token'] ?? null);
        touch_user($room, $me, $cfg);

        switch ($action) {
            case 'state':
                break;

            case 'vote':
                $vote = $in['vote'] ?? null;
                if ($vote !== null && (!is_int($vote) || $vote < 1 || $vote > 10)) {
                    throw new ApiError('invalid_vote', 'The vote must be a whole number from 1 to 10.');
                }
                if ($room['revealed']) {
                    throw new ApiError('revealed', 'Votes are already revealed. Wait for the admin to reset the round.', 409);
                }
                $room['users'][$me]['vote'] = $vote;
                break;

            case 'leave':
                remove_user($room, $me, $cfg);
                return null;

            case 'story':
                require_admin($room, $me);
                // While votes are revealed the title is locked, so the history keeps the title of the voted round.
                if ($room['revealed']) {
                    throw new ApiError('revealed', 'Reset the round before changing the User Story.', 409);
                }
                if (!is_string($in['story'] ?? null)) {
                    throw new ApiError('bad_request', 'The title must be text.');
                }
                $room['story'] = clean_text($in['story'], $cfg['MAX_STORY']);
                break;

            case 'reveal':
                require_admin($room, $me);
                $room['revealed'] = true;
                break;

            case 'hide':
                require_admin($room, $me);
                $room['revealed'] = false;
                break;

            case 'reset':
                require_admin($room, $me);
                $results = $room['revealed'] ? compute_results($room) : null;
                if ($results !== null) {
                    add_history($room, $results, $room['story']);
                }
                foreach ($room['users'] as &$user) {
                    $user['vote'] = null;
                }
                unset($user);
                $room['revealed'] = false;
                break;

            case 'kick':
            case 'transfer':
                require_admin($room, $me);
                $target = $in['target'] ?? null;
                if (!is_string($target) || !isset($room['users'][$target])) {
                    throw new ApiError('invalid_target', 'That user is no longer in the room.');
                }
                if ($target === $me) {
                    throw new ApiError('invalid_target', 'You cannot do this to yourself.');
                }
                if ($action === 'kick') {
                    kick_user($room, $target, $cfg);
                } elseif (!is_online($room['users'][$target], $cfg, time())) {
                    // An offline admin would lose the role straight away due to inactivity.
                    throw new ApiError('invalid_target', 'You cannot make an offline user the admin.');
                } else {
                    $room['adminId'] = $target;
                }
                break;

            default:
                throw new ApiError('bad_request', 'Unknown action.');
        }

        ensure_admin($room, $cfg);
        return public_view($room, $me, $cfg);
    });

    respond($state === null ? ['ok' => true] : ['ok' => true, 'state' => $state]);
} catch (ApiError $e) {
    respond(['ok' => false, 'error' => $e->errorCode, 'message' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log('Planning Poker: ' . $e->getMessage());
    respond(['ok' => false, 'error' => 'server', 'message' => 'Internal server error.'], 500);
}
