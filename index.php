<?php
require_once __DIR__ . '/includes/db.php';

$page = $_GET['p'] ?? 'home';
$db = db();

function steamid_to_64(string $steamid): string {
    if (preg_match('/^U:1:(\d+)$/', $steamid, $m)) {
        return bcadd($m[1], '76561197960265728');
    }
    if (preg_match('/^STEAM_\d:(\d):(\d+)$/', $steamid, $m)) {
        return bcadd(bcadd(bcmul($m[2], '2'), $m[1]), '76561197960265728');
    }
    return '';
}

function tier_color(int $tier): string {
    if ($tier <= 0)  return '#cccccc';
    if ($tier >= 10) return '#ff2222';
    $stops = [
        0  => [204, 204, 204],
        3  => [255, 220,  50],
        6  => [255, 140,   0],
        10 => [255,  34,  34],
    ];
    $keys = array_keys($stops);
    for ($i = 0; $i < count($keys) - 1; $i++) {
        $a = $keys[$i]; $b = $keys[$i+1];
        if ($tier >= $a && $tier <= $b) {
            $t = ($tier - $a) / ($b - $a);
            $r = round($stops[$a][0] + ($stops[$b][0] - $stops[$a][0]) * $t);
            $g = round($stops[$a][1] + ($stops[$b][1] - $stops[$a][1]) * $t);
            $bl= round($stops[$a][2] + ($stops[$b][2] - $stops[$a][2]) * $t);
            return "rgb($r,$g,$bl)";
        }
    }
    return '#ff2222';
}


$data = [];
if ($page === 'home') {
    // Get WRs: best time per map+track, no style filter
    $stmt = $db->query('
        SELECT t.time_ms, t.date, p.name AS player, p.steamid,
               m.name AS map, m.tier, t.track,
               s.name AS server_name
        FROM times t
        JOIN players p ON p.id = t.player_id
        JOIN maps m    ON m.id = t.map_id
        LEFT JOIN servers s ON s.id = t.server_id
        WHERE t.time_ms = (
            SELECT MIN(t2.time_ms) FROM times t2
            WHERE t2.map_id = t.map_id AND t2.track = t.track
        )
        ORDER BY t.date DESC LIMIT 20
    ');
    $data['recent_wrs']   = $stmt->fetchAll();
    $data['map_count']    = (int)$db->query('SELECT COUNT(*) FROM maps')->fetchColumn();
    $data['player_count'] = (int)$db->query('SELECT COUNT(*) FROM players')->fetchColumn();
    $data['time_count']   = (int)$db->query('SELECT COUNT(*) FROM times')->fetchColumn();

} elseif ($page === 'maps') {
    $stmt = $db->query('
        SELECT m.name, m.added, m.tier,
               COUNT(DISTINCT t.player_id) AS players,
               MIN(t.time_ms) AS best_time
        FROM maps m
        LEFT JOIN times t ON t.map_id = m.id AND t.track = 0
        GROUP BY m.id ORDER BY m.name
    ');
    $data['maps'] = $stmt->fetchAll();

} elseif ($page === 'map') {
    $map_name = strtolower(preg_replace('/[^a-z0-9_\-]/', '', $_GET['name'] ?? ''));
    $track    = (int)($_GET['track'] ?? 0);
    $map = $db->prepare('SELECT * FROM maps WHERE name = ?');
    $map->execute([$map_name]);
    $data['map'] = $map->fetch();
    if ($data['map']) {
        $stmt = $db->prepare('
            SELECT p.name AS player, p.steamid, MIN(t.time_ms) AS best, MAX(t.date) AS date
            FROM times t JOIN players p ON p.id = t.player_id
            WHERE t.map_id = ? AND t.track = ?
            GROUP BY t.player_id ORDER BY best LIMIT 100
        ');
        $stmt->execute([$data['map']['id'], $track]);
        $data['times'] = $stmt->fetchAll();
        $data['track'] = $track;
    }

} elseif ($page === 'top100') {
    $points_lb = $db->query('
        SELECT pl.name, pl.steamid, pp.total_points,
               (SELECT COUNT(*) FROM world_records wr WHERE wr.player_id = pl.id) AS wr_count
        FROM player_points pp
        JOIN players pl ON pl.id = pp.player_id
        ORDER BY pp.total_points DESC
        LIMIT 100
    ')->fetchAll();
    $wr_lb = $db->query('
        SELECT pl.name, pl.steamid,
               COUNT(*) AS wr_count,
               COALESCE((SELECT pp.total_points FROM player_points pp WHERE pp.player_id = pl.id), 0) AS total_points
        FROM world_records wr
        JOIN players pl ON pl.id = wr.player_id
        GROUP BY wr.player_id
        ORDER BY wr_count DESC
        LIMIT 100
    ')->fetchAll();
    $data['points_lb'] = $points_lb;
    $data['wr_lb'] = $wr_lb;

} elseif ($page === 'notable') {
    $stmt = $db->query('
        SELECT nr.note, nr.ordering, t.time_ms, t.date,
               p.name AS player, p.steamid,
               m.name AS map, m.tier
        FROM notable_records nr
        JOIN times t ON t.id = nr.time_id
        JOIN players p ON p.id = t.player_id
        JOIN maps m ON m.id = t.map_id
        ORDER BY nr.ordering ASC, nr.added ASC
    ');
    $data['notable'] = $stmt->fetchAll();

} elseif ($page === 'player') {
    $steamid = preg_replace('/[^A-Za-z0-9:_]/', '', $_GET['steamid'] ?? '');
    $player  = $db->prepare('SELECT * FROM players WHERE steamid = ?');
    $player->execute([$steamid]);
    $data['player'] = $player->fetch();
    if ($data['player']) {
        $pid = $data['player']['id'];
        // WRs: maps where this player has the best time
        $wrs = $db->prepare('
            SELECT m.name AS map, m.tier, MIN(t.time_ms) AS time_ms, MAX(t.date) AS date, t.track
            FROM times t JOIN maps m ON m.id = t.map_id
            WHERE t.player_id = ?
            AND t.time_ms = (SELECT MIN(t2.time_ms) FROM times t2 WHERE t2.map_id = t.map_id AND t2.track = t.track)
            GROUP BY t.map_id, t.track
            ORDER BY date DESC
        ');
        $wrs->execute([$pid]);
        $data['wrs'] = $wrs->fetchAll();
        $recent = $db->prepare('
            SELECT m.name AS map, m.tier, t.track, t.time_ms, t.date
            FROM times t JOIN maps m ON m.id = t.map_id
            WHERE t.player_id = ? ORDER BY t.date DESC LIMIT 20
        ');
        $recent->execute([$pid]);
        $data['recent'] = $recent->fetchAll();
        $data['wr_count'] = count($data['wrs']);
        $pts_stmt = $db->prepare('SELECT total_points FROM player_points WHERE player_id = ?');
        $pts_stmt->execute([$pid]);
        $data['total_points'] = (int)($pts_stmt->fetchColumn() ?: 0);
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
        $breakdown->execute([$pid]);
        $data['breakdown'] = $breakdown->fetchAll();
        $map_c = $db->prepare('SELECT COUNT(DISTINCT map_id) FROM times WHERE player_id = ?');
        $map_c->execute([$pid]);
        $data['map_count'] = (int)$map_c->fetchColumn();
    }
}

function e($s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta property="og:title" content="InfiniDB">
<meta property="og:description" content="Infinite WRDB for Counter-Strike: Source">
<meta property="og:url" content="https://yourdomain.here">
<meta property="og:type" content="website">
<meta name="theme-color" content="#0a0a0a">
<title>InfDB<?php
if ($page === 'map' && !empty($data['map']))       echo ' - ' . e($data['map']['name']);
if ($page === 'player' && !empty($data['player'])) echo ' - ' . e($data['player']['name']);
if ($page === 'maps') echo ' - Maps';
?></title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #000000; color: #ccc; line-height: 1.6; }
.container { max-width: 1400px; margin: 0 auto; padding: 20px; }
.header { background: #0a0a0a; padding: 20px 0; border-bottom: 1px solid #222; margin-bottom: 30px; }
.header-content { max-width: 1400px; margin: 0 auto; padding: 0 20px; display: flex; justify-content: space-between; align-items: center; }
.logo { font-size: 24px; font-weight: bold; color: #aaa; text-decoration: none; }
.nav { display: flex; gap: 30px; }
.nav a { color: #888; text-decoration: none; transition: color 0.2s; }
.nav a:hover { color: #aaa; }
.nav a.active { color: #aaa; border-bottom: 2px solid #444; }
.card { background: #0a0a0a; border: 1px solid #1a1a1a; border-radius: 4px; padding: 20px; margin-bottom: 20px; }
.card-header { color: #aaa; font-size: 18px; font-weight: bold; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #222; }
.grid { display: grid; gap: 20px; }
.grid-3 { grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); }
.stat-box { background: #111; padding: 20px; border-radius: 4px; border-left: 3px solid #444; text-align: center; }
.stat-value { font-size: 32px; font-weight: bold; color: #aaa; margin-bottom: 5px; }
.stat-label { color: #666; font-size: 14px; text-transform: uppercase; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 12px; text-align: left; border-bottom: 1px solid #1a1a1a; }
th { background: #111; color: #aaa; font-weight: bold; text-transform: uppercase; font-size: 12px; }
tbody tr { transition: background 0.2s; }
tbody tr:hover { background: #111; }
.rank { color: #666; font-weight: bold; }
.rank-1 { color: #FFD700; }
.rank-2 { color: #C0C0C0; }
.rank-3 { color: #CD7F32; }
.time { color: #aaa; font-family: monospace; }
.wr-tag { display: inline-block; font-size: 10px; text-transform: uppercase; letter-spacing: .06em; padding: 1px 5px; border: 1px solid #444; color: #888; font-family: monospace; }
.tier-badge { display: inline-block; font-size: 11px; font-weight: bold; font-family: monospace; padding: 1px 6px; border-radius: 3px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); }
tbody tr.clickable { cursor: pointer; }
.btn { display: inline-block; padding: 10px 20px; background: #222; color: #aaa; text-decoration: none; border: 1px solid #333; border-radius: 3px; transition: all 0.2s; cursor: pointer; font-size: 14px; font-family: inherit; }
.btn:hover { background: #2a2a2a; border-color: #444; }
.btn-sm { padding: 6px 14px; font-size: 13px; }
select, input[type=text] { background: #111; color: #ccc; border: 1px solid #333; padding: 10px; border-radius: 3px; font-size: 14px; font-family: inherit; }
select:focus, input[type=text]:focus { outline: none; border-color: #444; }
.map-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 10px; }
.map-card { background: #111; border: 1px solid #1a1a1a; border-radius: 4px; padding: 14px 16px; text-decoration: none; display: block; transition: background 0.2s, border-color 0.2s; }
.map-card:hover { background: #161616; border-color: #333; }
.map-card-name { color: #aaa; font-weight: bold; font-size: 14px; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.map-card-meta { color: #555; font-size: 12px; display: flex; gap: 12px; align-items: center; }
.search-wrap { margin-bottom: 16px; }
.search-wrap input[type=text] { width: 280px; }
.profile-card { background: #0a0a0a; border: 1px solid #1a1a1a; border-radius: 4px; padding: 20px; margin-bottom: 20px; }
.profile-name { font-size: 24px; font-weight: bold; color: #aaa; margin-bottom: 4px; }
.profile-steamid { color: #555; font-size: 13px; margin-bottom: 16px; font-family: monospace; }
.footer { text-align: center; padding: 30px 20px; margin-top: 50px; border-top: 1px solid #1a1a1a; color: #555; font-size: 13px; }
.footer a { color: #666; text-decoration: none; }
.footer a:hover { color: #888; }
.not-found { text-align: center; padding: 80px 0; }
.about-section { margin-bottom: 32px; }
.about-section p { color: #888; line-height: 1.8; }
.about-title { color: #aaa; font-size: 18px; font-weight: bold; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid #1a1a1a; }
.not-found h2 { font-size: 64px; color: #1a1a1a; margin-bottom: 10px; }
.not-found p { color: #555; text-transform: uppercase; font-size: 13px; letter-spacing: .1em; }
.text-center { text-align: center; }
.mb-20 { margin-bottom: 20px; }
.mt-20 { margin-top: 20px; }
.text-muted { color: #666; }
.popup-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.75); z-index: 1000; align-items: center; justify-content: center; }
.popup-overlay.active { display: flex; }
.popup { background: #0d0d0d; border: 1px solid #2a2a2a; border-radius: 6px; padding: 24px; min-width: 320px; max-width: 480px; width: 90%; position: relative; }
.popup-close { position: absolute; top: 12px; right: 14px; color: #555; font-size: 20px; cursor: pointer; line-height: 1; background: none; border: none; transition: color 0.2s; }
.popup-close:hover { color: #aaa; }
.popup-map { font-size: 20px; font-weight: bold; color: #aaa; margin-bottom: 4px; }
.popup-tier { font-size: 13px; font-family: monospace; margin-bottom: 16px; }
.popup-divider { border: none; border-top: 1px solid #1a1a1a; margin: 14px 0; }
.popup-row { display: flex; justify-content: space-between; align-items: center; font-size: 14px; margin-bottom: 8px; }
.popup-label { color: #555; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; }
.popup-val { color: #ccc; }
.popup-val a { color: #aaa; text-decoration: none; }
.popup-val a:hover { text-decoration: underline; color: #ccc; }
.popup-actions { margin-top: 18px; }
</style>
</head>
<body>

<div class="header">
  <div class="header-content">
    <a class="logo" href="/">InfDB</a>
    <nav class="nav">
      <a href="/?p=home" <?= $page==='home'?'class="active"':'' ?>>Home</a>
      <a href="/?p=maps" <?= $page==='maps'?'class="active"':'' ?>>Maps</a>
      <a href="/?p=top100" <?= $page==='top100'?'class="active"':''  ?>>Top 100</a>
      <a href="/?p=tiers" <?= $page==='tiers'?'class="active"':''  ?>>Tiers</a>
      <a href="/?p=notable" <?= $page==='notable'?'class="active"':''  ?>>Notable</a>
      <a href="/?p=about" <?= $page==='about'?'class="active"':''  ?>>About</a>
      <a href="/admin/">Admin</a>
    </nav>
  </div>
</div>

<div class="popup-overlay" id="popupOverlay" onclick="closePopupOutside(event)">
  <div class="popup" id="popup">
    <button class="popup-close" onclick="closePopup()">&times;</button>
    <div class="popup-map" id="popupMap"></div>
    
    <hr class="popup-divider">
    <div class="popup-row"><span class="popup-label">Record Holder</span><span class="popup-val" id="popupPlayer"></span></div>
    <div class="popup-row"><span class="popup-label">Time</span><span class="popup-val" id="popupTime"></span></div>
    <div class="popup-row"><span class="popup-label">Server</span><span class="popup-val" id="popupServer"></span></div>
    <div class="popup-row"><span class="popup-label">Date</span><span class="popup-val" id="popupDate"></span></div>
    <div class="popup-actions"><a id="popupMapLink" href="#" class="btn btn-sm">View map leaderboard</a></div>
  </div>
</div>

<?php if ($page === 'home'): ?>
<div class="container">
  <h1 class="text-center mb-20" style="color:#aaa; font-size:36px;">InfDB</h1>
  <p class="text-center mb-20" style="color:#555; font-size:13px; text-transform:uppercase; letter-spacing:.1em; margin-top:-12px;">Infinite Style — World Records</p>
  <div class="grid grid-3 mb-20">
    <div class="stat-box"><div class="stat-value"><?= number_format($data['map_count']) ?></div><div class="stat-label">Maps</div></div>
    <div class="stat-box"><div class="stat-value"><?= number_format($data['player_count']) ?></div><div class="stat-label">Players</div></div>
    <div class="stat-box"><div class="stat-value"><?= number_format($data['time_count']) ?></div><div class="stat-label">Times</div></div>
  </div>
  <div class="card">
    <div class="card-header">Recent World Records</div>
    <?php if (empty($data['recent_wrs'])): ?>
      <p style="text-align:center; color:#555; padding:20px;">No records yet.</p>
    <?php else: ?>
    <table>
      <thead><tr><th>Map</th><th>Player</th><th>Time</th><th>Date</th></tr></thead>
      <tbody>
        <?php foreach ($data['recent_wrs'] as $wr):
            $tier   = (int)($wr['tier'] ?? 0);
            $tcolor = tier_color($tier);
            $sid64  = steamid_to_64($wr['steamid']);
            $steam_url = $sid64 ? 'https://steamcommunity.com/profiles/' . $sid64 : '';
        ?>
        <tr class="clickable" onclick='openPopup(<?= json_encode($wr["map"]) ?>,<?= $tier ?>,<?= json_encode($wr["player"]) ?>,<?= json_encode($steam_url) ?>,<?= json_encode(ms_to_time($wr["time_ms"])) ?>,<?= json_encode($wr["server_name"] ?? "Unknown") ?>,<?= json_encode(substr($wr["date"], 0, 10)) ?>)'>
          <td>
            <span style="color:<?= $tcolor ?>; font-weight:bold;"><?= e($wr['map']) ?></span>
            <?php if ($tier > 0): ?><span class="tier-badge" style="color:<?= $tcolor ?>; border-color:<?= $tcolor ?>22;">T<?= $tier ?></span><?php endif; ?>
          </td>
          <td style="color:#aaa"><?= e($wr['player']) ?></td>
          <td class="time"><?= ms_to_time($wr['time_ms']) ?></td>
          <td style="color:#555"><?= substr($wr['date'], 0, 10) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php elseif ($page === 'maps'): ?>
<div class="container">
  <div class="card">
    <div class="card-header">Maps</div>
    <div class="search-wrap">
      <input type="text" id="map-search" placeholder="Search maps..." oninput="filterMaps(this.value)">
    </div>
    <?php if (empty($data['maps'])): ?>
      <p style="text-align:center; color:#555; padding:20px;">No maps yet.</p>
    <?php else: ?>
    <div class="map-grid" id="map-grid">
      <?php foreach ($data['maps'] as $m):
          $tier = (int)($m['tier'] ?? 0);
          $tcolor = tier_color($tier);
      ?>
      <a class="map-card" href="/?p=map&name=<?= e($m['name']) ?>" data-name="<?= e($m['name']) ?>">
        <div class="map-card-name" style="color:<?= $tcolor ?>"><?= e($m['name']) ?></div>
        <div class="map-card-meta">
          <span class="tier-badge" style="color:<?= $tcolor ?>; border-color:<?= $tcolor ?>33;">T<?= $tier ?></span>
          <?php if ($m['players']): ?><span><?= $m['players'] ?> players</span><?php endif; ?>
          <?php if ($m['best_time']): ?><span>WR: <?= ms_to_time((int)$m['best_time']) ?></span><?php endif; ?>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <script>
      function filterMaps(q) {
        q = q.toLowerCase();
        document.querySelectorAll('#map-grid .map-card').forEach(c => {
          c.style.display = c.dataset.name.includes(q) ? '' : 'none';
        });
      }
    </script>
    <?php endif; ?>
  </div>
</div>

<?php elseif ($page === 'map'): ?>
<div class="container">
  <?php if (empty($data['map'])): ?>
    <div class="not-found"><h2>404</h2><p>Map not found</p></div>
  <?php else:
      $tier = (int)($data['map']['tier'] ?? 0);
      $tcolor = tier_color($tier);
  ?>
  <div class="card">
    <div class="card-header">
      <span style="color:<?= $tcolor ?>"><?= e($data['map']['name']) ?></span>
      <span class="tier-badge" style="color:<?= $tcolor ?>; border-color:<?= $tcolor ?>44; margin-left:8px;">T<?= $tier ?></span>
      <span style="color:#555; font-size:13px; font-weight:normal; margin-left:12px;">Infinite &middot; <?= $data['track'] ? 'Bonus '.$data['track'] : 'Main Track' ?></span>
    </div>
    <?php if (empty($data['times'])): ?>
      <p style="text-align:center; color:#555; padding:20px;">No times yet.</p>
    <?php else: ?>
    <table>
      <thead><tr><th>#</th><th>Player</th><th>Time</th><th>Date</th></tr></thead>
      <tbody>
        <?php foreach ($data['times'] as $i => $t):
            $sid64 = steamid_to_64($t['steamid']);
        ?>
        <tr>
          <td class="rank <?= $i===0?'rank-1':($i===1?'rank-2':($i===2?'rank-3':'rank')) ?>">
            <?= $i===0 ? '<span class="wr-tag">WR</span>' : $i+1 ?>
          </td>
          <td>
            <a href="/?p=player&steamid=<?= e($t['steamid']) ?>" style="color:#aaa; text-decoration:none;"><?= e($t['player']) ?></a>
            <?php if ($sid64): ?>
              <a href="https://steamcommunity.com/profiles/<?= $sid64 ?>" target="_blank" style="color:#555; font-size:11px; margin-left:6px; text-decoration:none;" title="Steam Profile">↗</a>
            <?php endif; ?>
          </td>
          <td class="time"><?= ms_to_time((int)$t['best']) ?></td>
          <td style="color:#555"><?= substr($t['date'], 0, 10) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php elseif ($page === 'player'): ?>
<div class="container">
  <?php if (empty($data['player'])): ?>
    <div class="not-found"><h2>404</h2><p>Player not found</p></div>
  <?php else:
      $p = $data['player'];
      $sid64 = steamid_to_64($p['steamid']);
  ?>
  <div class="profile-card">
    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:4px;">
      <div class="profile-name"><?= e($p['name']) ?></div>
      <?php if ($sid64): ?>
        <a href="https://steamcommunity.com/profiles/<?= $sid64 ?>" target="_blank" class="btn btn-sm">Steam Profile ↗</a>
      <?php endif; ?>
    </div>
    <div class="profile-steamid"><?= e($p['steamid']) ?></div>
    <div class="grid grid-3">
      <div class="stat-box"><div class="stat-value"><?= $data['wr_count'] ?></div><div class="stat-label">World Records</div></div>
      <div class="stat-box"><div class="stat-value"><?= $data['map_count'] ?></div><div class="stat-label">Maps Run</div></div>
      <div class="stat-box" style="cursor:pointer;" onclick="document.getElementById('pts-modal').style.display='flex'">
        <div class="stat-value"><?= number_format($data['total_points']) ?></div>
        <div class="stat-label">Points <span style="color:#444;font-size:10px;">▼ breakdown</span></div>
      </div>
    </div>
  </div>
  <?php if (!empty($data['wrs'])): ?>
  <div class="card">
    <div class="card-header">World Records</div>
    <table>
      <thead><tr><th>Map</th><th>Tier</th><th>Time</th><th>Date</th></tr></thead>
      <tbody>
        <?php foreach ($data['wrs'] as $wr):
            $t = (int)($wr['tier'] ?? 0);
            $tc = tier_color($t);
        ?>
        <tr>
          <td><a href="/?p=map&name=<?= e($wr['map']) ?>" style="color:<?= $tc ?>; text-decoration:none;"><?= e($wr['map']) ?></a></td>
          <td><span class="tier-badge" style="color:<?= $tc ?>; border-color:<?= $tc ?>44;">T<?= $t ?></span></td>
          <td class="time"><?= ms_to_time((int)$wr['time_ms']) ?></td>
          <td style="color:#555"><?= substr($wr['date'], 0, 10) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <div class="card">
    <div class="card-header">Recent Times</div>
    <table>
      <thead><tr><th>Map</th><th>Tier</th><th>Time</th><th>Date</th></tr></thead>
      <tbody>
        <?php foreach ($data['recent'] as $t):
            $tier = (int)($t['tier'] ?? 0);
            $tc = tier_color($tier);
        ?>
        <tr>
          <td><a href="/?p=map&name=<?= e($t['map']) ?>" style="color:<?= $tc ?>; text-decoration:none;"><?= e($t['map']) ?></a></td>
          <td><span class="tier-badge" style="color:<?= $tc ?>; border-color:<?= $tc ?>44;">T<?= $tier ?></span></td>
          <td class="time"><?= ms_to_time((int)$t['time_ms']) ?></td>
          <td style="color:#555"><?= substr($t['date'], 0, 10) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div id="breakdown"></div><!-- Points breakdown modal -->
  <div id="pts-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.85); z-index:1000; align-items:center; justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:#0a0a0a; border:1px solid #222; border-radius:4px; padding:24px; max-width:700px; width:90%; max-height:80vh; overflow-y:auto;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <span style="color:#aaa; font-size:16px; font-weight:bold;">Points Breakdown</span>
        <button onclick="document.getElementById('pts-modal').style.display='none'" style="background:none; border:none; color:#555; font-size:20px; cursor:pointer;">✕</button>
      </div>
      <table>
        <thead><tr><th>Map</th><th>Tier</th><th>Rank</th><th>Time</th><th>Points</th></tr></thead>
        <tbody>
          <?php foreach ($data['breakdown'] as $b):
              $bt = (int)($b['tier'] ?? 0);
              $btc = tier_color($bt);
          ?>
          <?php if ((int)$b['pts'] === 0) continue; ?>
          <tr>
            <td><a href="/?p=map&name=<?= e($b['map']) ?>" style="color:<?= $btc ?>; text-decoration:none;"><?= e($b['map']) ?></a></td>
            <td><span class="tier-badge" style="color:<?= $btc ?>; border-color:<?= $btc ?>44;">T<?= $bt ?></span></td>
            <td class="rank <?= $b['map_rank']==1?'rank-1':($b['map_rank']==2?'rank-2':($b['map_rank']==3?'rank-3':'')) ?>">#<?= $b['map_rank'] ?></td>
            <td class="time"><?= ms_to_time((int)$b['best_time']) ?></td>
            <td style="color:#aaa; font-weight:bold;"><?= $b['pts'] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php elseif ($page === 'top100'): ?>
<div class="container">
  <div class="card">
    <div class="card-header">Top 100</div>
    <div style="display:flex; gap:0; margin-bottom:20px; border-bottom:1px solid #1a1a1a;">
      <button onclick="switchTab('points')" id="tab-points" style="padding:10px 24px; background:none; border:none; border-bottom:2px solid #aaa; color:#aaa; cursor:pointer; font-size:14px; font-family:inherit;">Points</button>
      <button onclick="switchTab('wrs')" id="tab-wrs" style="padding:10px 24px; background:none; border:none; border-bottom:2px solid transparent; color:#555; cursor:pointer; font-size:14px; font-family:inherit;">World Records</button>
    </div>

    <div id="lb-points">
      <?php if (empty($data['points_lb'])): ?>
        <p style="text-align:center; color:#555; padding:20px;">No data yet.</p>
      <?php else: ?>
      <table>
        <thead><tr><th>#</th><th>Player</th><th>Points</th><th>WRs</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($data['points_lb'] as $i => $row):
              $sid64 = steamid_to_64($row['steamid']);
          ?>
          <tr>
            <td class="rank <?= $i===0?'rank-1':($i===1?'rank-2':($i===2?'rank-3':'rank')) ?>"><?= $i+1 ?></td>
            <td>
              <?php if ($sid64): ?>
                <a href="/?p=player&steamid=<?= e($row['steamid']) ?>" style="color:#aaa; text-decoration:none;"><?= e($row['name']) ?></a>
              <?php else: ?>
                <?= e($row['name']) ?>
              <?php endif; ?>
            </td>
            <td style="color:#aaa; font-weight:bold;"><?= number_format($row['total_points']) ?></td>
            <td style="color:#666;"><?= $row['wr_count'] ?></td>
            <td><button onclick="openBreakdown('<?= e($row['steamid']) ?>')" style="background:none; border:none; color:#444; font-size:11px; cursor:pointer; font-family:inherit;">breakdown ↗</button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <div id="lb-wrs" style="display:none;">
      <?php if (empty($data['wr_lb'])): ?>
        <p style="text-align:center; color:#555; padding:20px;">No data yet.</p>
      <?php else: ?>
      <table>
        <thead><tr><th>#</th><th>Player</th><th>WRs</th><th>Points</th></tr></thead>
        <tbody>
          <?php foreach ($data['wr_lb'] as $i => $row):
              $sid64 = steamid_to_64($row['steamid']);
          ?>
          <tr>
            <td class="rank <?= $i===0?'rank-1':($i===1?'rank-2':($i===2?'rank-3':'rank')) ?>"><?= $i+1 ?></td>
            <td>
              <?php if ($sid64): ?>
                <a href="/?p=player&steamid=<?= e($row['steamid']) ?>" style="color:#aaa; text-decoration:none;"><?= e($row['name']) ?></a>
              <?php else: ?>
                <?= e($row['name']) ?>
              <?php endif; ?>
            </td>
            <td style="color:#aaa; font-weight:bold;"><?= $row['wr_count'] ?></td>
            <td style="color:#666;"><?= number_format($row['total_points']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>
<script>
function switchTab(tab) {
    document.getElementById('lb-points').style.display = tab === 'points' ? '' : 'none';
    document.getElementById('lb-wrs').style.display = tab === 'wrs' ? '' : 'none';
    document.getElementById('tab-points').style.borderBottomColor = tab === 'points' ? '#aaa' : 'transparent';
    document.getElementById('tab-points').style.color = tab === 'points' ? '#aaa' : '#555';
    document.getElementById('tab-wrs').style.borderBottomColor = tab === 'wrs' ? '#aaa' : 'transparent';
    document.getElementById('tab-wrs').style.color = tab === 'wrs' ? '#aaa' : '#555';
}
</script>


  <div id="bd-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.85); z-index:1000; align-items:center; justify-content:center;" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:#0a0a0a; border:1px solid #222; border-radius:4px; padding:24px; max-width:700px; width:90%; max-height:80vh; overflow-y:auto;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
        <span id="bd-title" style="color:#aaa; font-size:16px; font-weight:bold;">Points Breakdown</span>
        <button onclick="document.getElementById('bd-modal').style.display='none'" style="background:none; border:none; color:#555; font-size:20px; cursor:pointer;">&#x2715;</button>
      </div>
      <div id="bd-content" style="color:#666;overflow-x:hidden;">Loading...</div>
    </div>
  </div>
<script>
const TIER_COLORS = ["#cccccc","#d4d4a0","#c8c850","#ffdc32","#ffb400","#ff8c00","#ff5711","#ff2e00","#ff1500","#ee0000","#cc0000"];
function msToTime(ms) {
    ms = parseInt(ms);
    const h = Math.floor(ms/3600000), m = Math.floor((ms%3600000)/60000), s = Math.floor((ms%60000)/1000), c = Math.floor((ms%1000)/10);
    if (h > 0) return h+":"+String(m).padStart(2,"0")+":"+String(s).padStart(2,"0")+"."+String(c).padStart(2,"0");
    if (m > 0) return m+":"+String(s).padStart(2,"0")+"."+String(c).padStart(2,"0");
    return s+"."+String(c).padStart(2,"0");
}
async function openBreakdown(steamid) {
    const modal = document.getElementById("bd-modal");
    const content = document.getElementById("bd-content");
    const title = document.getElementById("bd-title");
    content.innerHTML = "Loading...";
    modal.style.display = "flex";
    const res = await fetch("/api/breakdown.php?steamid=" + encodeURIComponent(steamid));
    const data = await res.json();
    title.textContent = data.player + " — Points Breakdown";
    if (!data.rows || !data.rows.length) { content.innerHTML = "<p style=\'color:#555\'>No points yet.</p>"; return; }
    let html = "<table style=\'width:100%;table-layout:fixed\'><colgroup><col style=\'width:36px\'><col><col style=\'width:48px\'><col style=\'width:48px\'><col style=\'width:75px\'><col style=\'width:55px\'></colgroup><thead><tr><th>#</th><th>Map</th><th>Tier</th><th>Rank</th><th>Time</th><th>Points</th></tr></thead><tbody>";
    data.rows.forEach((r,i) => {
        const c = TIER_COLORS[Math.min(r.tier,10)] || "#aaa";
        const rankClass = r.map_rank==1?"rank-1":r.map_rank==2?"rank-2":r.map_rank==3?"rank-3":"rank";
        html += `<tr><td class="rank">${i+1}</td><td><a href="/?p=map&name=${encodeURIComponent(r.map)}" style="color:${c};text-decoration:none">${r.map}</a></td><td><span class="tier-badge" style="color:${c};border-color:${c}44">T${r.tier}</span></td><td class="${rankClass}">#${r.map_rank}</td><td class="time">${msToTime(r.best_time)}</td><td style="color:#aaa;font-weight:bold">${r.pts}</td></tr>`;
    });
    html += "</tbody></table>";
    content.innerHTML = html;
}
</script>
<?php elseif ($page === 'tiers'): ?>
<div class="container" style="max-width:860px;">
  <div class="card">
    <div class="card-header">IDB Tier System</div>
    <p style="color:#888; line-height:1.8; margin-bottom:24px;">IDB tiers don't go by the actual beatability — hence every map would be T0. Instead, IDB tiers are calculated by how easy/hard a fast route would be on a map and difficulty mixed together.</p>
    <?php
    $tiers = [
        [0,  '#cccccc', 'Maps in this tier quite literally do not have any / have very few obstacles in your way.',
         ['WR'=>1, '#2'=>0, '#3'=>0, '#4-10'=>0, '#11+'=>0]],
        [1,  '#d4d4a0', 'Very easy. You can learn a map of this tier in about 3 minutes.',
         ['WR'=>7, '#2'=>5, '#3'=>2, '#4-10'=>1, '#11+'=>0]],
        [2,  '#c8c850', 'Mild obstructions, still easy, though.',
         ['WR'=>15, '#2'=>13, '#3'=>9, '#4-10'=>6, '#11+'=>1]],
        [3,  '#ffdc32', 'A little difficult, might take you 15-30 minutes to learn maps of this tier.',
         ['WR'=>21, '#2'=>19, '#3'=>16, '#4-10'=>11, '#11+'=>1]],
        [4,  '#ffb400', 'This is where some people may struggle on maps, and high speed is harder to handle (think bhop_arcane).',
         ['WR'=>30, '#2'=>27, '#3'=>24, '#4-10'=>20, '#11+'=>16]],
        [5,  '#ff8c00', 'At this point, maps require at least some precision, or good routing skills.',
         ['WR'=>41, '#2'=>38, '#3'=>35, '#4-10'=>30, '#11+'=>24]],
        [6,  '#ff5711', 'Maps in this range often take some hours to beat, props to you if you beat any maps from here on out.',
         ['WR'=>77, '#2'=>65, '#3'=>53, '#4-10'=>46, '#11+'=>37]],
        [7,  '#ff2e00', 'A lot of tech maps go into this range, most maps here are also linear.',
         ['WR'=>108, '#2'=>98, '#3'=>84, '#4-10'=>72, '#11+'=>63]],
        [8,  '#ff1500', 'You are either very passionate about infinite, or mentally insane. Either way, a good map to think of in this tier is bhop_4loshadka.',
         ['WR'=>150, '#2'=>136, '#3'=>110, '#4-10'=>100, '#11+'=>84]],
        [9,  '#ee0000', 'You are too good at infinite...',
         ['WR'=>245, '#2'=>213, '#3'=>186, '#4-10'=>158, '#11+'=>120]],
        [10, '#cc0000', 'You are not human.',
         ['WR'=>362, '#2'=>274, '#3'=>233, '#4-10'=>170, '#11+'=>124]],
    ];
    ?>
    <div style="display:flex; flex-direction:column; gap:8px;">
    <?php foreach ($tiers as [$t, $color, $desc, $points]): ?>
    <div class="tier-dropdown" style="border:1px solid #1a1a1a; border-radius:4px; overflow:hidden;">
      <button onclick="toggleTier(this)" style="width:100%; background:#111; border:none; padding:14px 16px; cursor:pointer; display:flex; align-items:center; gap:12px; text-align:left;">
        <span style="font-family:monospace; font-weight:bold; font-size:16px; color:<?= $color ?>; min-width:32px;">T<?= $t ?></span>
        <span style="color:#666; font-size:13px; flex:1;"><?= $desc ?></span>
        <span style="color:#444; font-size:12px;">▼</span>
      </button>
      <div class="tier-content" style="display:none; padding:16px; background:#0d0d0d; border-top:1px solid #1a1a1a;">
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
          <?php foreach ($points as $rank => $pts): ?>
          <div style="background:#111; border:1px solid #1a1a1a; border-radius:4px; padding:8px 14px; text-align:center; min-width:80px;">
            <div style="color:#555; font-size:11px; text-transform:uppercase; margin-bottom:4px;"><?= $rank ?></div>
            <div style="color:<?= $pts > 0 ? $color : '#333' ?>; font-weight:bold; font-size:18px;"><?= $pts ?></div>
            <div style="color:#444; font-size:10px;">pts</div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
  </div>
</div>
<script>
function toggleTier(btn) {
    const content = btn.nextElementSibling;
    const arrow = btn.querySelector('span:last-child');
    const open = content.style.display !== 'none';
    content.style.display = open ? 'none' : 'block';
    arrow.textContent = open ? '▼' : '▲';
}
</script>

<?php elseif ($page === 'about'): ?>
<div class="container" style="max-width:860px;">
  <div class="card">
    <div style="text-align:center; margin-bottom:24px;">
      <p style="color:#666; font-size:13px; margin-bottom:12px;">
        Looking for an API key? <a href="#api-key" style="color:#aaa;">Click here!</a>
      </p>
      <p style="color:#888; line-height:1.8;">Hello! Thank you for taking interest in the InfDB project! I appreciate your enthusiasm.</p>
    </div>
    <hr style="border:none; border-top:1px solid #1a1a1a; margin:24px 0;">
    <div class="about-section">
      <h2 class="about-title">What is InfDB?</h2>
      <p>InfDB (aka InfiniDB or IDB) is a database consisting of all the best times in the style <a href="https://github.com/2x74/infinite" target="_blank" style="color:#aaa;">Infinite</a>. InfDB was made as a passion project, being heavily inspired by SourceJump. All code was made from scratch (except the templates used from css.lunae.pw... but that was written from scratch... so....).</p>
    </div>
    <div class="about-section">
      <h2 class="about-title">How do I get my name on the IDB?</h2>
      <p>Get a WR! It is important to note, however, that the higher the tier map you get a WR on, the better it looks on your profile (i.e. getting a WR on bhop_620s means nothing, but getting a WR on bhop_3muddz means you're the best infhopper alive.)</p>
    </div>
    <div class="about-section" id="api-key">
      <h2 class="about-title">How do I get an API key?</h2>
      <p style="margin-bottom:16px;">To get an API key, you can ask in the discord, or just DM me personally (<span style="color:#aaa;">@i95x</span>)!</p>
      <p style="margin-bottom:16px;">Obviously, not all servers are able to get an API key instantly<span style="color:#555;">*</span>. Here are the requirements for an IDB API key:</p>
      <div style="background:#111; border:1px solid #1a1a1a; border-radius:4px; padding:16px 20px; margin-bottom:12px;">
        <div style="color:#aaa; font-size:11px; text-transform:uppercase; letter-spacing:.1em; font-weight:bold; margin-bottom:12px;">Requirements</div>
        <ol style="color:#888; padding-left:20px; line-height:2;">
          <li>Must have at least a T3 WR.</li>
          <li>Must be an actual server for more than 14 days. This is to prevent spam, and to make sure you are actually going to use the IDB.</li>
          <li>Must have Infinite as a style on your server.</li>
        </ol>
      </div>
      <p style="color:#555; font-size:12px;">*If I know you, or at least trust you to a degree, these requirements are skipped. Requirements can change at any time.</p>
    </div>
    <div class="about-section">
      <h2 class="about-title">Why is my server blacklisted from the IDB?</h2>
      <p style="margin-bottom:12px;">If a server is blacklisted from the IDB, this is often due to numerous reasons, some are listed here:</p>
      <ol style="color:#888; padding-left:20px; line-height:2; margin-bottom:12px;">
        <li>You made a map specifically to farm Infinite times/to spam the record list (e.g. making a map which is 270ms long just to get the WR on said map over and over).</li>
        <li>You are a known cheater.</li>
        <li>Your server is known for known cheaters playing on it.<span style="color:#555;">*</span></li>
      </ol>
      <p style="color:#555; font-size:12px; margin-bottom:12px;">*This is purely a safety feature.</p>
      <p>If you want to appeal your server's (or servers') IDB blacklist, you can use the contacts listed above.</p>
    </div>
    <div class="about-section">
      <h2 class="about-title">What are the rules that an IDB server has to comply with?</h2>
      <p style="margin-bottom:12px;">The ruleset is quite simple:</p>
      <ol style="color:#888; padding-left:20px; line-height:2;">
        <li>Max prespeed should be capped at 1700 (<code style="background:#111; padding:2px 6px; border-radius:3px; font-size:12px; color:#aaa;">sv_noclipspeed 6.45</code>).</li>
        <li>Your zones must be correct.</li>
        <li>Macros are not allowed (this excludes things like using mwheeldown and mwheelup to jump Infinite, as that's not considered a macro).</li>
      </ol>
    </div>
    <hr style="border:none; border-top:1px solid #1a1a1a; margin:24px 0;">
    <p style="color:#444; font-size:12px; text-align:center; font-style:italic;">And to believe this all started from a stupid idea a 15-year-old (me) had back in July 2025...</p>
  </div>
</div>

<?php elseif ($page === 'notable'): ?>
<div class="container">
  <div class="card">
    <div class="card-header">Notable Records</div>
    <?php if (empty($data['notable'])): ?>
      <p style="text-align:center; color:#555; padding:20px;">No notable records yet.</p>
    <?php else: ?>
    <table>
      <thead><tr><th>#</th><th>Map</th><th>Player</th><th>Time</th><th>Date</th><th>Note</th></tr></thead>
      <tbody>
        <?php foreach ($data['notable'] as $i => $n):
            $tier = (int)($n['tier'] ?? 0);
            $tc = tier_color($tier);
            $sid64 = steamid_to_64($n['steamid']);
        ?>
        <tr>
          <td class="rank"><?= $i+1 ?></td>
          <td>
            <a href="/?p=map&name=<?= e($n['map']) ?>" style="color:<?= $tc ?>; text-decoration:none; font-weight:bold;"><?= e($n['map']) ?></a>
            <?php if ($tier > 0): ?><span class="tier-badge" style="color:<?= $tc ?>; border-color:<?= $tc ?>44; margin-left:4px;">T<?= $tier ?></span><?php endif; ?>
          </td>
          <td>
            <?php if ($sid64): ?>
              <a href="https://steamcommunity.com/profiles/<?= $sid64 ?>" target="_blank" style="color:#aaa; text-decoration:none;"><?= e($n['player']) ?></a>
            <?php else: ?>
              <?= e($n['player']) ?>
            <?php endif; ?>
          </td>
          <td class="time"><?= ms_to_time((int)$n['time_ms']) ?></td>
          <td style="color:#555"><?= substr($n['date'], 0, 10) ?></td>
          <td style="color:#666; font-style:italic; font-size:13px;"><?= $n['note'] ? e($n['note']) : '' ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php endif; ?>

<div class="footer">
  <a href="https://infdb.lunae.pw">InfDB</a> &mdash; Infinite Style World Records &mdash; <a href="https://github.com/2x74/infinite">github.com/2x74/infinite</a>
</div>

<script>
function openPopup(map, tier, player, steamUrl, time, server, date) {
    function tierColor(t) {
        if (t <= 0)  return '#cccccc';
        if (t >= 10) return '#ff2222';
        const stops = [[0,[204,204,204]],[3,[255,220,50]],[6,[255,140,0]],[10,[255,34,34]]];
        for (let i = 0; i < stops.length - 1; i++) {
            const [a, ca] = stops[i], [b, cb] = stops[i+1];
            if (t >= a && t <= b) {
                const r = ca.map((v,j) => Math.round(v + (cb[j]-v)*((t-a)/(b-a))));
                return `rgb(${r[0]},${r[1]},${r[2]})`;
            }
        }
        return '#ff2222';
    }
    const color = tierColor(tier);
    document.getElementById('popupMap').textContent = map;
    document.getElementById('popupMap').style.color = color;
    document.getElementById('popupTime').textContent   = time;
    document.getElementById('popupServer').textContent = server;
    document.getElementById('popupDate').textContent   = date;
    const playerEl = document.getElementById('popupPlayer');
    if (steamUrl) {
        playerEl.innerHTML = `<a href="${steamUrl}" target="_blank" style="color:#aaa; text-decoration:none;">${player} <span style="color:#555; font-size:11px;">↗</span></a>`;
    } else {
        playerEl.textContent = player;
    }
    document.getElementById('popupMapLink').href = `/?p=map&name=${encodeURIComponent(map)}`;
    document.getElementById('popupOverlay').classList.add('active');
}
function closePopup() { document.getElementById('popupOverlay').classList.remove('active'); }
function closePopupOutside(e) { if (e.target === document.getElementById('popupOverlay')) closePopup(); }
document.addEventListener('keydown', e => { if (e.key === 'Escape') closePopup(); });
</script>
</body>
</html>
