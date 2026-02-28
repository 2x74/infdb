<?php
require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}


function json_response(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function validate_api_key(string $key): ?array {
    $hash = hash('sha256', $key);
    $stmt = db()->prepare('SELECT * FROM servers WHERE api_key = ? AND active = 1');
    $stmt->execute([$hash]);
    $server = $stmt->fetch();
    if ($server) {
        db()->prepare('UPDATE servers SET last_used = NOW() WHERE id = ?')
             ->execute([$server['id']]);
    }
    return $server ?: null;
}

function get_or_create_player(string $steamid, string $name): int {
    $db = db();
    $stmt = $db->prepare('SELECT id FROM players WHERE steamid = ?');
    $stmt->execute([$steamid]);
    $row = $stmt->fetch();
    if ($row) {
        $db->prepare('UPDATE players SET name = ?, last_seen = NOW() WHERE id = ?')
           ->execute([$name, $row['id']]);
        return (int)$row['id'];
    }
    $db->prepare('INSERT INTO players (steamid, name) VALUES (?, ?)')
       ->execute([$steamid, $name]);
    return (int)$db->lastInsertId();
}

function get_or_create_map(string $name): int {
    $db = db();
    $stmt = $db->prepare('SELECT id FROM maps WHERE name = ?');
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    if ($row) return (int)$row['id'];
    $db->prepare('INSERT INTO maps (name) VALUES (?)')->execute([$name]);
    return (int)$db->lastInsertId();
}

function ms_to_time(int $ms): string {
    $s  = intdiv($ms, 1000);
    $ms = $ms % 1000;
    $m  = intdiv($s, 60);
    $s  = $s % 60;
    if ($m > 0) {
        return sprintf('%d:%02d.%03d', $m, $s, $ms);
    }
    return sprintf('%d.%03d', $s, $ms);
}
