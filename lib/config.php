<?php
// Planning Poker configuration. All times are in seconds unless stated otherwise.
declare(strict_types=1);

return [
    'ROOM_TTL'         => 10800, // Maximum lifetime of a room since it was created (3 h)
    'EXPIRY_WARNING'   => 600,   // How long before expiry the warning is shown (10 min)
    'OFFLINE_AFTER'    => 30,    // Inactivity after which a user is shown as "offline"
    'ADMIN_TIMEOUT'    => 120,   // Admin inactivity after which the role is handed over
    'POLL_INTERVAL_MS' => 1500,  // Browser polling interval (milliseconds)
    'CLEANUP_INTERVAL' => 60,    // Minimum time between two cleanups of expired rooms
    'MAX_NAME'         => 30,    // Maximum name length
    'MAX_STORY'        => 120,   // Maximum User Story title length
    'MAX_USERS'        => 30,    // Maximum people per room
    'MAX_ROOMS'        => 500,   // Active rooms at the same time (written to in the last 10 min)
    'MAX_ROOMS_PER_IP_HOUR' => 20, // Rooms a single IP (or IPv6 /64 network) can create per hour
    'MAX_ROOMS_PER_NET_HOUR' => 60, // Rooms a whole IPv6 /48 network can create per hour
];
