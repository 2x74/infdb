<?php
require_once __DIR__ . '/../includes/db.php';

session_start();

if (isset($_POST['password'])) {
    if ($_POST['password'] === ADMIN_PASSWORD) {
        $_SESSION['infdb_admin'] = true;
    } else {
        $login_error = 'Wrong password.';
    }
}
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /admin/');
    exit;
}
$authed = !empty($_SESSION['infdb_admin']);

$message = ''; if (!empty($_SESSION['infdb_message'])) { $message = $_SESSION['infdb_message']; unset($_SESSION['infdb_message']); }
if ($authed) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $action = $_POST['action'];

        if ($action === 'generate') {
            $server_name   = substr(strip_tags($_POST['server_name'] ?? ''), 0, 64);
            $owner_steamid = preg_replace('/[^a-zA-Z0-9:_]/', '', $_POST['owner_steamid'] ?? '');
            $ip            = substr(strip_tags($_POST['ip'] ?? ''), 0, 64);
            if ($server_name && $owner_steamid) {
                $raw_key = bin2hex(random_bytes(32));
                $hash    = hash('sha256', $raw_key);
                db()->prepare('INSERT INTO servers (name, owner_steamid, ip, api_key, raw_key) VALUES (?,?,?,?,?)')
                    ->execute([$server_name, $owner_steamid, $ip ?: null, $hash, $raw_key]);
                $message = "Key generated for <strong>$server_name</strong>: <code class='new-key'>$raw_key</code>";
            $_SESSION['infdb_message'] = $message; header('Location: /admin/'); exit;
            } else {
                $message = 'Server name and owner SteamID are required.';
            }
        } elseif ($action === 'revoke') {
            db()->prepare('UPDATE servers SET active = 0 WHERE id = ?')->execute([(int)$_POST['server_id']]);
            $message = 'Key revoked.';
        } elseif ($action === 'activate') {
            db()->prepare('UPDATE servers SET active = 1 WHERE id = ?')->execute([(int)$_POST['server_id']]);
            $message = 'Key re-activated.';
        } elseif ($action === 'delete') {
            db()->prepare('UPDATE times SET server_id = NULL WHERE server_id = ?')->execute([(int)$_POST['server_id']]);
            db()->prepare('DELETE FROM servers WHERE id = ?')->execute([(int)$_POST['server_id']]);
            $message = 'Server deleted.';
        } elseif ($action === 'rename_server') {
            $sid = (int)$_POST['server_id'];
            $newname = substr(strip_tags($_POST['new_name'] ?? ''), 0, 64);
            if ($newname) {
                db()->prepare('UPDATE servers SET name = ? WHERE id = ?')->execute([$newname, $sid]);
                $message = 'Server renamed.';
            }
        } elseif ($action === 'add_notable') {
            $time_id = (int)$_POST['time_id'];
            $note = substr(strip_tags($_POST['note'] ?? ''), 0, 256);
            $order = (int)($_POST['ordering'] ?? 0);
            db()->prepare('INSERT IGNORE INTO notable_records (time_id, note, ordering) VALUES (?,?,?)')->execute([$time_id, $note, $order]);
            $message = 'Added to notable records.';
        } elseif ($action === 'remove_notable') {
            $nid = (int)$_POST['notable_id'];
            db()->prepare('DELETE FROM notable_records WHERE id = ?')->execute([$nid]);
            $message = 'Removed from notable records.';
        } elseif ($action === 'delete_time') {
            $time_id = (int)$_POST['time_id'];
            db()->prepare('DELETE FROM world_records WHERE time_id = ?')->execute([$time_id]);
            db()->prepare('DELETE FROM times WHERE id = ?')->execute([$time_id]);
            $message = 'Time deleted.';
        } elseif ($action === 'set_tier') {
            $map_id     = (int)$_POST['map_id'];
            $tier_input = trim($_POST['tier'] ?? '');
            if (preg_match('/^T(\d+)$/i', $tier_input, $m)) {
                $tier = (int)$m[1];
                db()->prepare('UPDATE maps SET tier = ? WHERE id = ?')->execute([$tier, $map_id]);
                $message = "Tier updated to <strong>T$tier</strong>.";
            } else {
                $message = 'Invalid tier format. Use T0, T1, T9, T100, etc.';
            }
        }
    }

    $servers = db()->query('SELECT * FROM servers ORDER BY created DESC')->fetchAll();
    $maps    = db()->query('SELECT id, name, tier FROM maps ORDER BY name ASC')->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>InfDB Admin</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #000; color: #ccc; line-height: 1.6; }
.header { background: #0a0a0a; padding: 20px 0; border-bottom: 1px solid #222; margin-bottom: 30px; }
.header-content { max-width: 1100px; margin: 0 auto; padding: 0 20px; display: flex; justify-content: space-between; align-items: center; }
.logo { font-size: 24px; font-weight: bold; color: #aaa; text-decoration: none; }
.header-content a.logout { color: #666; text-decoration: none; font-size: 14px; }
.header-content a.logout:hover { color: #aaa; }
.container { max-width: 1100px; margin: 0 auto; padding: 20px; }
.login-wrap { display: flex; justify-content: center; margin-top: 100px; }
.login-box { background: #0a0a0a; border: 1px solid #1a1a1a; border-radius: 4px; padding: 30px; width: 320px; }
.login-box h2 { color: #aaa; font-size: 16px; margin-bottom: 20px; font-weight: bold; }
.login-box input { width: 100%; background: #111; border: 1px solid #333; color: #ccc; padding: 10px; border-radius: 3px; font-size: 14px; font-family: inherit; margin-bottom: 12px; }
.login-box input:focus { outline: none; border-color: #444; }
.error { color: #c0392b; font-size: 13px; margin-top: 8px; }
.card { background: #0a0a0a; border: 1px solid #1a1a1a; border-radius: 4px; padding: 20px; margin-bottom: 20px; }
.card-header { color: #aaa; font-size: 16px; font-weight: bold; margin-bottom: 16px; padding-bottom: 10px; border-bottom: 1px solid #222; }
.message { background: #0a0a0a; border: 1px solid #333; border-radius: 4px; padding: 14px 16px; margin-bottom: 20px; font-size: 14px; line-height: 1.7; }
.message code.new-key { display: block; margin-top: 8px; background: #111; padding: 8px 12px; border-radius: 3px; word-break: break-all; color: #aaa; font-family: monospace; font-size: 13px; border: 1px solid #2a2a2a; }
.gen-form { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.gen-form label { font-size: 12px; color: #666; display: block; margin-bottom: 4px; text-transform: uppercase; letter-spacing: .05em; }
.gen-form input { width: 100%; background: #111; border: 1px solid #333; color: #ccc; padding: 9px 12px; border-radius: 3px; font-size: 14px; font-family: inherit; }
.gen-form input:focus { outline: none; border-color: #444; }
.gen-form .submit-row { grid-column: 1/-1; text-align: right; }
table { width: 100%; border-collapse: collapse; }
th, td { padding: 11px 12px; text-align: left; border-bottom: 1px solid #1a1a1a; font-size: 13px; }
th { background: #111; color: #aaa; font-weight: bold; text-transform: uppercase; font-size: 11px; }
tbody tr:hover td { background: #111; }
.badge { display: inline-block; font-size: 11px; padding: 2px 8px; border-radius: 3px; font-weight: bold; }
.badge-active  { background: #0d2b0d; color: #4caf50; border: 1px solid #1a4a1a; }
.badge-revoked { background: #2b0d0d; color: #c0392b; border: 1px solid #4a1a1a; }
.btn { display: inline-block; padding: 8px 16px; background: #222; color: #aaa; border: 1px solid #333; border-radius: 3px; cursor: pointer; font-size: 13px; font-family: inherit; text-decoration: none; transition: all 0.2s; }
.btn:hover { background: #2a2a2a; border-color: #444; }
.btn-danger  { background: #2b0d0d; color: #c0392b; border-color: #4a1a1a; }
.btn-danger:hover  { background: #3a1010; }
.btn-success { background: #0d2b0d; color: #4caf50; border-color: #1a4a1a; }
.btn-success:hover { background: #103a10; }
.btn-sm { padding: 5px 10px; font-size: 12px; }
.actions { display: flex; gap: 6px; flex-wrap: wrap; }
.actions form { display: inline; }
.tier-form { display: flex; gap: 8px; align-items: center; }
.tier-form input { background: #111; border: 1px solid #333; color: #ccc; padding: 5px 8px; border-radius: 3px; font-size: 13px; font-family: monospace; width: 80px; }
.tier-form input:focus { outline: none; border-color: #444; }
select { background: #111; border: 1px solid #333; color: #ccc; padding: 9px 12px; border-radius: 3px; font-size: 14px; font-family: inherit; width: 100%; }
select:focus { outline: none; border-color: #444; }
.tier-row { display: grid; grid-template-columns: 1fr 1fr auto; gap: 12px; align-items: end; }
.tier-row label { font-size: 12px; color: #666; display: block; margin-bottom: 4px; text-transform: uppercase; letter-spacing: .05em; }
.tier-row input { width: 100%; background: #111; border: 1px solid #333; color: #ccc; padding: 9px 12px; border-radius: 3px; font-size: 14px; font-family: monospace; }
.tier-row input:focus { outline: none; border-color: #444; }
</style>
</head>
<body>

<div class="header">
  <div class="header-content">
    <a class="logo" href="/">InfDB — Admin</a>
    <?php if ($authed): ?>
      <a class="logout" href="?logout=1">Logout</a>
    <?php endif; ?>
  </div>
</div>

<?php if (!$authed): ?>
<div class="login-wrap">
  <div class="login-box">
    <h2>Admin Login</h2>
    <form method="POST">
      <input type="password" name="password" placeholder="Password" autofocus>
      <button type="submit" class="btn" style="width:100%">Enter</button>
      <?php if (isset($login_error)): ?>
        <p class="error"><?= $login_error ?></p>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php else: ?>
<div class="container">

  <?php if ($message): ?>
    <div class="message"><?= $message ?></div>
  <?php endif; ?>

  <!-- Generate API Key -->
  <div class="card">
    <div class="card-header">Generate New API Key</div>
    <form class="gen-form" method="POST">
      <input type="hidden" name="action" value="generate">
      <div>
        <label>Server Name</label>
        <input type="text" name="server_name" placeholder="My Surf Server" required>
      </div>
      <div>
        <label>Owner SteamID</label>
        <input type="text" name="owner_steamid" placeholder="STEAM_0:0:12345678" required>
      </div>
      <div>
        <label>Server IP (optional)</label>
        <input type="text" name="ip" placeholder="123.45.67.89:27015">
      </div>
      <div class="submit-row">
        <button type="submit" class="btn">Generate Key</button>
      </div>
    </form>
  </div>

  <!-- Set Map Tier -->
  <div class="card">
    <div class="card-header">Set Map Tier</div>
    <form method="POST">
      <input type="hidden" name="action" value="set_tier">
      <div class="tier-row">
        <div>
          <label>Map</label>
          <select name="map_id" required>
            <option value="">— select a map —</option>
            <?php foreach ($maps as $m): ?>
              <option value="<?= $m['id'] ?>">
                <?= htmlspecialchars($m['name']) ?> (T<?= $m['tier'] ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Tier (e.g. T3, T10)</label>
          <input type="text" name="tier" placeholder="T3" pattern="[Tt]\d+" title="Format: T followed by a number, e.g. T3">
        </div>
        <div>
          <button type="submit" class="btn">Set Tier</button>
        </div>
      </div>
    </form>
  </div>

  <!-- Registered Servers -->
  <div class="card">
    <div class="card-header">Registered Servers</div>
    <?php if (empty($servers)): ?>
      <p style="color:#666; font-size:13px">No servers registered yet.</p>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th><th>Server</th><th>Owner SteamID</th><th>IP</th><th>API Key</th><th>Last Used</th><th>Status</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($servers as $s): ?>
        <tr>
          <td style="color:#666"><?= $s['id'] ?></td>
          <td><?= htmlspecialchars($s['name']) ?></td>
          <td style="color:#666"><?= htmlspecialchars($s['owner_steamid']) ?></td>
          <td style="color:#666"><?= htmlspecialchars($s['ip'] ?? '—') ?></td>
          <td style="max-width:200px;word-break:break-all"><code style="font-size:11px;color:#666"><?= htmlspecialchars($s['raw_key'] ?? '—') ?></code></td>
          <td style="color:#666"><?= $s['last_used'] ?? 'Never' ?></td>
          <td>
            <?php if ($s['active']): ?>
              <span class="badge badge-active">active</span>
            <?php else: ?>
              <span class="badge badge-revoked">revoked</span>
            <?php endif; ?>
          </td>
          <td class="actions">
            <?php if ($s['active']): ?>
              <form method="POST">
                <input type="hidden" name="action" value="revoke">
                <input type="hidden" name="server_id" value="<?= $s['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">Revoke</button>
              </form>
            <?php else: ?>
              <form method="POST">
                <input type="hidden" name="action" value="activate">
                <input type="hidden" name="server_id" value="<?= $s['id'] ?>">
                <button type="submit" class="btn btn-success btn-sm">Re-activate</button>
              </form>
            <?php endif; ?>
            <form method="POST" style="display:flex;gap:4px;margin-top:4px;">
              <input type="hidden" name="action" value="rename_server">
              <input type="hidden" name="server_id" value="<?= $s['id'] ?>">
              <input type="text" name="new_name" placeholder="New name" value="<?= htmlspecialchars($s['name']) ?>" style="width:120px;padding:4px 6px;font-size:12px;">
              <button type="submit" class="btn btn-sm">Rename</button>
            </form>
            <form method="POST" onsubmit="return confirm('Delete this server and its key permanently?')">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="server_id" value="<?= $s['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm">Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>



  <!-- Notable Records -->
  <div class="card">
    <div class="card-header">Notable Records</div>
    <?php
    $notable = db()->query('
        SELECT nr.id, nr.note, nr.ordering, t.id AS time_id, t.time_ms, t.date,
               p.name AS player, m.name AS map, m.tier
        FROM notable_records nr
        JOIN times t ON t.id = nr.time_id
        JOIN players p ON p.id = t.player_id
        JOIN maps m ON m.id = t.map_id
        ORDER BY nr.ordering ASC, nr.added ASC
    ')->fetchAll();
    $all_times_notable = db()->query('
        SELECT t.id, p.name AS player, m.name AS map, t.time_ms
        FROM times t
        JOIN players p ON p.id = t.player_id
        JOIN maps m ON m.id = t.map_id
        ORDER BY m.name, t.time_ms
    ')->fetchAll();
    ?>
    <form method="POST" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:flex-end;">
      <input type="hidden" name="action" value="add_notable">
      <div>
        <label style="display:block;color:#666;font-size:11px;margin-bottom:4px;">Time</label>
        <select name="time_id" style="min-width:300px;">
          <?php foreach ($all_times_notable as $t): ?>
          <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['map'] . ' — ' . $t['player'] . ' — ' . ms_to_time((int)$t['time_ms'])) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label style="display:block;color:#666;font-size:11px;margin-bottom:4px;">Note (optional)</label>
        <input type="text" name="note" placeholder="e.g. First sub-10 on T5" style="width:240px;">
      </div>
      <div>
        <label style="display:block;color:#666;font-size:11px;margin-bottom:4px;">Order</label>
        <input type="number" name="ordering" value="0" style="width:60px;">
      </div>
      <button type="submit" class="btn btn-sm">Add</button>
    </form>
    <?php if ($notable): ?>
    <table>
      <thead><tr><th>Map</th><th>Player</th><th>Time</th><th>Date</th><th>Note</th><th>Order</th><th>Action</th></tr></thead>
      <tbody>
        <?php foreach ($notable as $n): ?>
        <tr>
          <td><?= htmlspecialchars($n['map']) ?></td>
          <td><?= htmlspecialchars($n['player']) ?></td>
          <td class="time"><?= ms_to_time((int)$n['time_ms']) ?></td>
          <td style="color:#666"><?= substr($n['date'],0,10) ?></td>
          <td style="color:#666;font-style:italic"><?= htmlspecialchars($n['note'] ?? '') ?></td>
          <td style="color:#666"><?= $n['ordering'] ?></td>
          <td>
            <form method="POST" onsubmit="return confirm('Remove from notable records?')">
              <input type="hidden" name="action" value="remove_notable">
              <input type="hidden" name="notable_id" value="<?= $n['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm">Remove</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- Delete Times -->
  <div class="card">
    <div class="card-header">Delete Time</div>
    <?php
    $all_times = db()->query('SELECT t.id, p.name AS player, m.name AS map, t.time_ms, t.date FROM times t JOIN players p ON p.id = t.player_id JOIN maps m ON m.id = t.map_id ORDER BY m.name, t.time_ms')->fetchAll();
    ?>
    <table>
      <thead><tr><th>#</th><th>Player</th><th>Map</th><th>Time</th><th>Date</th><th>Action</th></tr></thead>
      <tbody>
        <?php foreach ($all_times as $row): ?>
        <tr>
          <td style="color:#666"><?= $row['id'] ?></td>
          <td><?= htmlspecialchars($row['player']) ?></td>
          <td><?= htmlspecialchars($row['map']) ?></td>
          <td class="time"><?= ms_to_time((int)$row['time_ms']) ?></td>
          <td style="color:#666"><?= substr($row['date'],0,10) ?></td>
          <td><form method="POST" onsubmit="return confirm('Delete this time?')"><input type="hidden" name="action" value="delete_time"><input type="hidden" name="time_id" value="<?= $row['id'] ?>"><button type="submit" class="btn btn-danger btn-sm">Delete</button></form></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

</body>
</html>
