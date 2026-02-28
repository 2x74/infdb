<?php
require_once __DIR__ . '/../includes/db.php';

$map   = isset($_GET['map'])   ? strtolower(preg_replace('/[^a-z0-9_\-]/', '', $_GET['map'])) : '';
$track = isset($_GET['track']) ? (int)$_GET['track'] : 0;

if (!$map) json_response(['error' => 'Missing map'], 400);

$stmt = db()->prepare('
    SELECT t.time_ms, p.name, p.steamid, t.date
    FROM times t
    JOIN maps m    ON m.id = t.map_id
    JOIN players p ON p.id = t.player_id
    WHERE m.name = ? AND t.track = ?
    ORDER BY t.time_ms ASC LIMIT 1
');
$stmt->execute([$map, $track]);
$row = $stmt->fetch();

if (!$row) json_response(['error' => 'No record found'], 404);

json_response([
    'map'     => $map,
    'track'   => $track,
    'time_ms' => $row['time_ms'],
    'time'    => ms_to_time($row['time_ms']),
    'player'  => $row['name'],
    'steamid' => $row['steamid'],
    'date'    => $row['date'],
]);
