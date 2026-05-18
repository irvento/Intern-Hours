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
    
    // Fetch active user details
    $stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if (!$user) {
        throw new Exception("User account not found.");
    }

    // Generate random binary challenge and save to session
    $challengeBin = random_bytes(32);
    $_SESSION['biometric_reg_challenge'] = bin2hex($challengeBin);

    // Determine RP Domain ID dynamically
    $rpId = $_SERVER['SERVER_NAME'];
    // Strip port if active (e.g. localhost:8080)
    if (strpos($rpId, ':') !== false) {
        $parts = explode(':', $rpId);
        $rpId = $parts[0];
    }

    $options = [
        'challenge' => WebAuthnHelper::base64url_encode($challengeBin),
        'rp' => [
            'name' => 'OJT Intern Hours Tracker',
            'id' => $rpId
        ],
        'user' => [
            'id' => WebAuthnHelper::base64url_encode((string)$user['id']),
            'name' => $user['email'],
            'displayName' => $user['name']
        ],
        'pubKeyCredParams' => [
            [
                'type' => 'public-key',
                'alg' => -7 // ES256 (standard EC biometrics)
            ],
            [
                'type' => 'public-key',
                'alg' => -257 // RS256 (standard fallback)
            ]
        ],
        'authenticatorSelection' => [
            'authenticatorAttachment' => 'platform', // Enforce native fingerprint/face biometric sensor only
            'userVerification' => 'required',
            'requireResidentKey' => false
        ],
        'timeout' => 60000,
        'attestation' => 'none'
    ];

    echo json_encode(['success' => true, 'options' => $options]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
