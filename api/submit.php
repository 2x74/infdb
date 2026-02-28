<?php
// POST /api/submit
// Required POST fields: api_key, steamid, name, map, style, track, time_ms
require_once __DIR__ . '/../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$required = ['api_key', 'steamid', 'name', 'map', 'style', 'track', 'time_ms'];
foreach ($required as $field) {
    if (empty($input[$field]) && $input[$field] !== '0' && $input[$field] !== 0) {
        json_response(['error' => "Missing field: $field"], 400);
    }
}

$server = validate_api_key($input['api_key']);
if (!$server) {
    json_response(['error' => 'Invalid or revoked API key'], 403);
}

$steamid = preg_replace('/[^A-Za-z0-9:_]/', '', $input['steamid']);
$steamid = preg_replace('/^STEAM_0:/i', 'STEAM_1:', $steamid);
$name     = substr(strip_tags($input['name']), 0, 64);
$map      = substr(preg_replace('/[^a-z0-9_\-]/', '', strtolower($input['map'])), 0, 128);
$style    = (int)$input['style'];
$track    = (int)$input['track'];
$time_ms  = (int)$input['time_ms'];

if ($time_ms <= 0 || $time_ms > 99 * 60 * 60 * 1000) {
    json_response(['error' => 'Invalid time'], 400);
}

$db         = db();
$player_id  = get_or_create_player($steamid, $name);
$map_id     = get_or_create_map($map);

// Insert the time
$db->prepare('INSERT INTO times (player_id, map_id, server_id, style, track, time_ms) VALUES (?,?,?,?,?,?)')
   ->execute([$player_id, $map_id, $server['id'], $style, $track, $time_ms]);
$time_id = (int)$db->lastInsertId();

// Update world record if faster (or first)
$wr_stmt = $db->prepare('SELECT time_ms FROM world_records WHERE map_id=? AND style=? AND track=?');
$wr_stmt->execute([$map_id, $style, $track]);
$wr = $wr_stmt->fetch();

$is_wr = false;
if (!$wr) {
    $db->prepare('INSERT INTO world_records (map_id, style, track, time_id, player_id, time_ms, date) VALUES (?,?,?,?,?,?,NOW())')
       ->execute([$map_id, $style, $track, $time_id, $player_id, $time_ms]);
    $is_wr = true;
} elseif ($time_ms < $wr['time_ms']) {
    $db->prepare('UPDATE world_records SET time_id=?, player_id=?, time_ms=?, date=NOW() WHERE map_id=? AND style=? AND track=?')
       ->execute([$time_id, $player_id, $time_ms, $map_id, $style, $track]);
    $is_wr = true;
}

// Get rank for this player on this map/style/track
$rank_stmt = $db->prepare('
    SELECT COUNT(*) + 1 AS rank FROM (
        SELECT MIN(time_ms) AS best FROM times
        WHERE map_id=? AND style=? AND track=?
        GROUP BY player_id
        HAVING best < ?
    ) sub
');
$rank_stmt->execute([$map_id, $style, $track, $time_ms]);
$rank = (int)$rank_stmt->fetchColumn();

json_response([
    'success' => true,
    'is_wr'   => $is_wr,
    'rank'    => $rank,
    'time'    => ms_to_time($time_ms),
]);
