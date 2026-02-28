-- InfDB Database Schema
-- Run this once to set up your database

CREATE DATABASE IF NOT EXISTS infdb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE infdb;

-- Players
CREATE TABLE IF NOT EXISTS players (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    steamid VARCHAR(32) NOT NULL UNIQUE,
    name VARCHAR(64) NOT NULL,
    avatar VARCHAR(256) DEFAULT NULL,
    first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_steamid (steamid)
);

-- Maps
CREATE TABLE IF NOT EXISTS maps (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(128) NOT NULL UNIQUE,
    tier BIGINT UNSIGNED NOT NULL DEFAULT 0,  -- set via admin panel, infinite range
    added DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_name (name)
);

-- Servers (one row per registered server)
CREATE TABLE IF NOT EXISTS servers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(64) NOT NULL,
    owner_steamid VARCHAR(32) NOT NULL,
    ip VARCHAR(64) DEFAULT NULL,
    api_key CHAR(64) NOT NULL UNIQUE,   -- SHA-256 hex of the raw key
    active TINYINT(1) NOT NULL DEFAULT 1,
    created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used DATETIME DEFAULT NULL,
    INDEX idx_api_key (api_key)
);

-- Times (every valid run submitted)
CREATE TABLE IF NOT EXISTS times (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    player_id INT UNSIGNED NOT NULL,
    map_id INT UNSIGNED NOT NULL,
    server_id INT UNSIGNED NOT NULL,
    style TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- 0 = Infinite, others reserved
    track TINYINT UNSIGNED NOT NULL DEFAULT 0,  -- 0 = main, 1 = bonus
    time_ms INT UNSIGNED NOT NULL,              -- milliseconds
    date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (player_id) REFERENCES players(id),
    FOREIGN KEY (map_id)    REFERENCES maps(id),
    FOREIGN KEY (server_id) REFERENCES servers(id),
    INDEX idx_map_style (map_id, style, track, time_ms),
    INDEX idx_player (player_id)
);

-- Cached world records (updated on each submission if faster)
CREATE TABLE IF NOT EXISTS world_records (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    map_id INT UNSIGNED NOT NULL,
    style TINYINT UNSIGNED NOT NULL DEFAULT 0,
    track TINYINT UNSIGNED NOT NULL DEFAULT 0,
    time_id BIGINT UNSIGNED NOT NULL,
    player_id INT UNSIGNED NOT NULL,
    time_ms INT UNSIGNED NOT NULL,
    date DATETIME NOT NULL,
    UNIQUE KEY uq_map_style_track (map_id, style, track),
    FOREIGN KEY (map_id)    REFERENCES maps(id),
    FOREIGN KEY (time_id)   REFERENCES times(id),
    FOREIGN KEY (player_id) REFERENCES players(id)
);

-- Admin sessions (simple token-based auth for admin panel)
CREATE TABLE IF NOT EXISTS admin_sessions (
    token CHAR(64) NOT NULL PRIMARY KEY,
    created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires DATETIME NOT NULL
);
