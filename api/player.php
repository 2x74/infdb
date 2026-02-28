<?php
// GET /api/player?steamid=STEAM_0:0:12345
require_once __DIR__ . '/../includes/db.php';

$steamid = isset($_GET['steamid']) ? preg_replace('/[^0-9:_]/', '', $_GET['steamid']) : '';
if (!$steamid) json_response(['error' => 'Missing steamid'], 400);

$db = db();

$player = $db->prepare('SELECT * FROM players WHERE steamid = ?');
$player->execute([$steamid]);
$p = $player->fetch();
if (!$p) json_response(['error' => 'Player not found'], 404);

// Count WRs
$wrs = $db->prepare('SELECT COUNT(*) FROM world_records WHERE player_id = ?');
$wrs->execute([$p['id']]);
$wr_count = (int)$wrs->fetchColumn();

// Total completions
$comps = $db->prepare('SELECT COUNT(DISTINCT map_id) FROM times WHERE player_id = ?');
$comps->execute([$p['id']]);
$map_count = (int)$comps->fetchColumn();

// Recent times
$recent = $db->prepare('
    SELECT m.name AS map, t.style, t.track, t.time_ms, t.date
    FROM times t JOIN maps m ON m.id = t.map_id
    WHERE t.player_id = ?
    ORDER BY t.date DESC LIMIT 10
');
$recent->execute([$p['id']]);
$recent_times = $recent->fetchAll();

foreach ($recent_times as &$rt) {
    $rt['time'] = ms_to_time($rt['time_ms']);
}

json_response([
    'steamid'    => $p['steamid'],
    'name'       => $p['name'],
    'first_seen' => $p['first_seen'],
    'last_seen'  => $p['last_seen'],
    'wrs'        => $wr_count,
    'maps'       => $map_count,
    'recent'     => $recent_times,
]);
