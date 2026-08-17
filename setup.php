<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

// Simple first-time setup helper page for SkyGate ATL
$driver = function_exists('db_driver') ? db_driver() : 'unknown';
$isSqlite = function_exists('is_sqlite') ? is_sqlite() : false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SkyGate ATL — Setup</title>
<style>
body{font-family:system-ui,-apple-system,sans-serif;max-width:720px;margin:40px auto;padding:0 20px;line-height:1.5;color:#1a1a1a}
h1{font-size:1.6rem;margin-bottom:8px}
.card{border:1px solid #e5e7eb;border-radius:12px;padding:20px;margin:16px 0;background:#fafafa}
code{background:#f3f4f6;padding:2px 6px;border-radius:4px;font-size:0.9em}
.ok{color:#059669}.warn{color:#d97706}
a.btn{display:inline-block;margin-top:12px;padding:10px 18px;background:#2563eb;color:#fff;text-decoration:none;border-radius:8px;font-weight:600}
a.btn:hover{background:#1d4ed8}
</style>
</head>
<body>
<h1>SkyGate ATL — Setup</h1>
<p>First-time setup helper for the Airport Operations Dashboard.</p>

<div class="card">
  <strong>Current database driver:</strong>
  <code><?= htmlspecialchars($driver) ?></code>
  <?php if ($isSqlite): ?>
    <span class="ok">(SQLite — zero server setup)</span>
  <?php else: ?>
    <span class="warn">(MySQL)</span>
  <?php endif; ?>
</div>

<div class="card">
  <h3>1. Configuration</h3>
  <p>Copy the example config files if you have not already:</p>
  <pre>cp config/database.example.php config/database.php
cp config/fr24.example.php     config/fr24.php</pre>
  <p>Edit <code>config/database.php</code> (choose <code>sqlite</code> or <code>mysql</code>) and put your Flightradar24 token in <code>config/fr24.php</code>.</p>
</div>

<div class="card">
  <h3>2. Database</h3>
  <?php if ($isSqlite): ?>
    <p class="ok">SQLite database is already present at <code>data/skygate_atl.sqlite</code>. You can start immediately.</p>
  <?php else: ?>
    <p>Create the database and import <code>sql/schema.sql</code> in phpMyAdmin (or any MySQL client).</p>
  <?php endif; ?>
</div>

<div class="card">
  <h3>3. Seed data</h3>
  <p>Open the seeder to create the admin user and optional demo data:</p>
  <a class="btn" href="seed/seed.php">Open Seed Page →</a>
  <p style="margin-top:12px;font-size:0.9em">After seeding, login with:</p>
  <p>Username <code>admin</code> · Password <code>admin123456</code></p>
</div>

<div class="card">
  <h3>4. Open the dashboard</h3>
  <a class="btn" href="index.php">Go to Dashboard →</a>
</div>

</body>
</html>
