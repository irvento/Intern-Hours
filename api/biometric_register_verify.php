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
    
    // Get JSON body payload
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    
    if (!$data || !isset($data['clientDataJSON']) || !isset($data['attestationObject'])) {
        throw new Exception("Missing biometric registration payload.");
    }
    
    // Fetch registered challenge from session
    if (!isset($_SESSION['biometric_reg_challenge'])) {
        throw new Exception("Registration challenge expired or missing.");
    }
    
    $expectedChallenge = $_SESSION['biometric_reg_challenge'];
    
    // Cryptographically parse and verify WebAuthn credential
    $verified = WebAuthnHelper::verifyRegistration(
        $data['clientDataJSON'],
        $data['attestationObject'],
        $expectedChallenge
    );
    
    // Unset the temporary challenge to prevent replay attacks
    unset($_SESSION['biometric_reg_challenge']);
    
    $credentialId = $verified['credentialId'];
    $publicKey = $verified['publicKey'];
    
    // Save to biometric_credentials table
    $stmt = $pdo->prepare("
        INSERT INTO biometric_credentials (user_id, credential_id, public_key, sign_count)
        VALUES (?, ?, ?, 0)
    ");
    $stmt->execute([$user_id, $credentialId, $publicKey]);
    
    echo json_encode(['success' => true, 'message' => 'Fingerprint sensor enrolled successfully!']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
