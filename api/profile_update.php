<?php
require_once __DIR__ . '/../config.php';

session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access. Please log in.']);
    exit;
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // We support updating the is_darkmode value:
    // 0 = Light Mode
    // 1 = Dark Mode
    // 2 = System Adaptive Mode
    if (isset($_POST['is_darkmode'])) {
        $is_darkmode = (int)$_POST['is_darkmode'];
        if (!in_array($is_darkmode, [0, 1, 2])) {
            echo json_encode(['success' => false, 'error' => 'Invalid theme preference value.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("UPDATE users SET is_darkmode = ? WHERE id = ?");
            $stmt->execute([$is_darkmode, $user_id]);

            $_SESSION['is_darkmode'] = $is_darkmode;

            echo json_encode(['success' => true, 'message' => 'Theme preference saved successfully.']);
            exit;
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
            exit;
        }
    }
}

echo json_encode(['success' => false, 'error' => 'Invalid request method or missing parameters.']);
exit;
