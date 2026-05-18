<?php
require_once __DIR__ . '/../config.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$is_darkmode = isset($_POST['is_darkmode']) && $_POST['is_darkmode'] === '1' ? '1' : '0';

try {
    $stmt = $pdo->prepare("UPDATE users SET is_darkmode = ? WHERE id = ?");
    $stmt->execute([$is_darkmode, $user_id]);
    
    $_SESSION['is_darkmode'] = ($is_darkmode === '1');
    
    echo json_encode(['success' => true, 'is_darkmode' => $_SESSION['is_darkmode']]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
