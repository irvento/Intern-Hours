<?php
session_start();
require_once '../config.php';
require_once 'WebAuthnHelper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized session. Please log in first.']);
    exit;
}

try {
    $user_id = $_SESSION['user_id'];
    
    // Query all registered biometrics for this user
    $stmt = $pdo->prepare("SELECT credential_id FROM biometric_credentials WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $credentials = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($credentials)) {
        throw new Exception("No enrolled fingerprint scanner found on this account. Please enroll your device first.");
    }
    
    // Generate auth challenge and save to session
    $challengeBin = random_bytes(32);
    $_SESSION['biometric_auth_challenge'] = bin2hex($challengeBin);
    
    $allowCredentials = [];
    foreach ($credentials as $cred) {
        $allowCredentials[] = [
            'type' => 'public-key',
            'id' => $cred['credential_id']
        ];
    }
    
    $rpId = $_SERVER['SERVER_NAME'];
    if (strpos($rpId, ':') !== false) {
        $parts = explode(':', $rpId);
        $rpId = $parts[0];
    }
    
    $options = [
        'challenge' => WebAuthnHelper::base64url_encode($challengeBin),
        'rpId' => $rpId,
        'allowCredentials' => $allowCredentials,
        'userVerification' => 'required',
        'timeout' => 60000
    ];
    
    echo json_encode(['success' => true, 'options' => $options]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
