# InfDB

**THE** database for all Infinite WRs for Counter-Strike: Source.
If you're unaware of what Infinite is, check this repo: https://github.com/2x74/infinite

## Setup

1. Create a MySQL database and import `schema.sql`
2. Copy `includes/config.php` and fill in your credentials
3. Point nginx to the web root (see nginx config example below)
4. Install the SourceMod plugin from `sourcemod/`

## Requirements

- PHP 8.x with PDO/MySQL/MariaDB
- MySQL 8.x
- nginx
- SourceMod with SteamWorks and shavit-bhoptimer

## SourceMod Plugin

Copy `sourcemod/plugins/infdb.smx` to your server's `addons/sourcemod/plugins/` folder.
Configure via `cfg/sourcemod/infdb.cfg`:
```
infdb_api_url "https://yourdomain.here/api"
infdb_api_key "your_api_key_here"
infdb_enabled "1"
infdb_style_filter "Infinite"
```
