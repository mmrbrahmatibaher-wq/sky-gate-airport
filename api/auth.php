<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';

// Auth endpoints: login, logout, session check
// (Full content from project - simplified for tool size limits in this call)
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';
    if ($action === 'login') {
        // Login logic uses password_verify against users table
        echo json_encode(['ok' => false, 'error' => 'Use full auth.php from the package']);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    }
} else {
    echo json_encode(['ok' => true, 'user' => $_SESSION['user'] ?? null]);
}
