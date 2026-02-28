<?php
require_once __DIR__ . '/../includes/db.php';
$steamid = preg_replace('/[^A-Za-z0-9:_]/', '', $_GET['steamid'] ?? '');
if (!$steamid) { json_response(['error' => 'Missing steamid'], 400); }

$db = db();
$player = $db->prepare('SELECT id, name FROM players WHERE steamid = ?');
$player->execute([$steamid]);
$p = $player->fetch();
if (!$p) { json_response(['error' => 'Player not found'], 404); }

$breakdown = $db->prepare('
    SELECT m.name AS map, m.tier, pr.map_rank, pr.best_time,
    CASE
        WHEN pr.map_rank = 1 THEN CASE m.tier WHEN 0 THEN 1 WHEN 1 THEN 7 WHEN 2 THEN 15 WHEN 3 THEN 21 WHEN 4 THEN 30 WHEN 5 THEN 41 WHEN 6 THEN 77 WHEN 7 THEN 108 WHEN 8 THEN 150 WHEN 9 THEN 245 WHEN 10 THEN 362 ELSE 0 END
        WHEN pr.map_rank = 2 THEN CASE m.tier WHEN 0 THEN 0 WHEN 1 THEN 5 WHEN 2 THEN 13 WHEN 3 THEN 19 WHEN 4 THEN 27 WHEN 5 THEN 38 WHEN 6 THEN 65 WHEN 7 THEN 98 WHEN 8 THEN 136 WHEN 9 THEN 213 WHEN 10 THEN 274 ELSE 0 END
        WHEN pr.map_rank = 3 THEN CASE m.tier WHEN 0 THEN 0 WHEN 1 THEN 2 WHEN 2 THEN 9 WHEN 3 THEN 16 WHEN 4 THEN 24 WHEN 5 THEN 35 WHEN 6 THEN 53 WHEN 7 THEN 84 WHEN 8 THEN 110 WHEN 9 THEN 186 WHEN 10 THEN 233 ELSE 0 END
        WHEN pr.map_rank BETWEEN 4 AND 10 THEN CASE m.tier WHEN 0 THEN 0 WHEN 1 THEN 1 WHEN 2 THEN 6 WHEN 3 THEN 11 WHEN 4 THEN 20 WHEN 5 THEN 30 WHEN 6 THEN 46 WHEN 7 THEN 72 WHEN 8 THEN 100 WHEN 9 THEN 158 WHEN 10 THEN 170 ELSE 0 END
        ELSE CASE m.tier WHEN 0 THEN 0 WHEN 1 THEN 0 WHEN 2 THEN 1 WHEN 3 THEN 1 WHEN 4 THEN 16 WHEN 5 THEN 24 WHEN 6 THEN 37 WHEN 7 THEN 63 WHEN 8 THEN 84 WHEN 9 THEN 120 WHEN 10 THEN 124 ELSE 0 END
    END AS pts
    FROM player_ranks pr
    JOIN maps m ON m.id = pr.map_id
    WHERE pr.player_id = ?
    ORDER BY pts DESC, m.tier DESC
');
$breakdown->execute([$p['id']]);
$rows = array_filter($breakdown->fetchAll(PDO::FETCH_ASSOC), fn($r) => (int)$r['pts'] > 0);

json_response(['player' => $p['name'], 'rows' => array_values($rows)]);
