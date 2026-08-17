<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
// Simulation tick endpoint - advances operational state
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'tick' => time()]);
