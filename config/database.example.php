<?php
/**
 * SkyGate ATL — Database config (MySQL + SQLite)
 *
 * driver:
 *   - 'mysql'  → Laragon / phpMyAdmin
 *   - 'sqlite' → local file data/skygate_atl.sqlite (zero server setup)
 *
 * Schema:
 *   MySQL  → import sql/schema.sql in phpMyAdmin
 *   SQLite → data/skygate_atl.sqlite (pre-built) or re-import sql/schema_sqlite.sql
 *
 * 1. Copy this file to config/database.php
 * 2. Choose the driver you want and edit values if needed
 */
return [
    // 'mysql' | 'sqlite'
    'driver'  => 'sqlite',

    // --- MySQL (when driver = mysql) ---
    'host'    => '127.0.0.1',
    'port'    => 3306,
    'dbname'  => 'skygate_atl',
    'user'    => 'root',
    'pass'    => '',
    'charset' => 'utf8mb4',

    // --- SQLite (when driver = sqlite) ---
    'sqlite_path' => __DIR__ . '/../data/skygate_atl.sqlite',
];
