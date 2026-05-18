<?php
require_once __DIR__ . '/../config.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];

$updates = [];
$params = [];

if (isset($_POST['is_darkmode'])) {
    $val = (int)$_POST['is_darkmode'];
    if ($val === 0 || $val === 1 || $val === 2) {
        $updates[] = "is_darkmode = ?";
        $params[] = $val;
        $_SESSION['is_darkmode'] = $val;
    }
}

if (isset($_POST['is_public'])) {
    $val = (int)$_POST['is_public'];
    if ($val === 0 || $val === 1) {
        $updates[] = "is_public = ?";
        $params[] = $val;
    }
}

if (empty($updates)) {
    echo json_encode(['success' => false, 'error' => 'No fields to update']);
    exit;
}

try {
    $params[] = $user_id;
    $sql = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    echo json_encode([
        'success' => true, 
        'is_darkmode' => $_SESSION['is_darkmode'] ?? null
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
