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
    
    if (
        !$data || 
        !isset($data['clientDataJSON']) || 
        !isset($data['authenticatorData']) || 
        !isset($data['signature']) || 
        !isset($data['id'])
    ) {
        throw new Exception("Missing biometric authentication payload.");
    }
    
    $credentialId = $data['id'];
    
    // Fetch registered public key for this credential and active user
    $stmt = $pdo->prepare("SELECT public_key, sign_count FROM biometric_credentials WHERE credential_id = ? AND user_id = ?");
    $stmt->execute([$credentialId, $user_id]);
    $cred = $stmt->fetch();
    
    if (!$cred) {
        throw new Exception("This fingerprint credential is not registered to your account.");
    }
    
    // Fetch auth challenge from session
    if (!isset($_SESSION['biometric_auth_challenge'])) {
        throw new Exception("Authentication challenge expired or missing.");
    }
    
    $expectedChallenge = $_SESSION['biometric_auth_challenge'];
    
    // Cryptographically verify signature using public key
    WebAuthnHelper::verifyAuthentication(
        $data['clientDataJSON'],
        $data['authenticatorData'],
        $data['signature'],
        $cred['public_key'],
        $expectedChallenge
    );
    
    // Unset the temporary challenge to prevent replay attacks
    unset($_SESSION['biometric_auth_challenge']);
    
    // Fetch Geolocation inputs (GPS coordinates)
    $latitude = isset($data['gps_latitude']) ? (float)$data['gps_latitude'] : null;
    $longitude = isset($data['gps_longitude']) ? (float)$data['gps_longitude'] : null;
    
    // ----------------------------------------------------
    // AUTOMATIC DTR SLOT ALLOCATION & HOUR SYNC LOGIC
    // ----------------------------------------------------
    $today = date('Y-m-d');
    $nowTime = date('H:i');
    $nowDateTime = date('Y-m-d H:i:s');
    
    // Fetch existing check_in row for today
    $stmt = $pdo->prepare("SELECT * FROM check_in WHERE user_id = ? AND date = ?");
    $stmt->execute([$user_id, $today]);
    $checkInRow = $stmt->fetch();
    
    $slotToStamp = '';
    $actionType = ''; // 'clock_in' or 'clock_out'
    
    if (!$checkInRow) {
        // No stamps yet today -> stamp morning_in
        $slotToStamp = 'morning_in';
        $actionType = 'clock_in';
    } elseif (empty($checkInRow['morning_in'])) {
        $slotToStamp = 'morning_in';
        $actionType = 'clock_in';
    } elseif (empty($checkInRow['morning_out'])) {
        $slotToStamp = 'morning_out';
        $actionType = 'clock_out';
    } elseif (empty($checkInRow['afternoon_in'])) {
        $slotToStamp = 'afternoon_in';
        $actionType = 'clock_in';
    } elseif (empty($checkInRow['afternoon_out'])) {
        $slotToStamp = 'afternoon_out';
        $actionType = 'clock_out';
    } else {
        throw new Exception("All DTR check-in slots for today are already complete!");
    }
    
    // Perform database operations inside a secure Transaction to guarantee atomic logs
    $pdo->beginTransaction();
    
    // 1. Log actual biometric event to attendance_logs
    $stmt = $pdo->prepare("
        INSERT INTO attendance_logs (employee_id, device_token, action_type, gps_latitude, gps_longitude)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$user_id, $credentialId, $actionType, $latitude, $longitude]);
    
    // 2. Stamp target DTR check-in slot
    $morning_in = $checkInRow['morning_in'] ?? null;
    $morning_out = $checkInRow['morning_out'] ?? null;
    $afternoon_in = $checkInRow['afternoon_in'] ?? null;
    $afternoon_out = $checkInRow['afternoon_out'] ?? null;
    
    // Update local variable for hours computation
    if ($slotToStamp === 'morning_in') $morning_in = $nowDateTime;
    elseif ($slotToStamp === 'morning_out') $morning_out = $nowDateTime;
    elseif ($slotToStamp === 'afternoon_in') $afternoon_in = $nowDateTime;
    elseif ($slotToStamp === 'afternoon_out') $afternoon_out = $nowDateTime;
    
    if (!$checkInRow) {
        $stmt = $pdo->prepare("
            INSERT INTO check_in (user_id, date, morning_in, morning_out, afternoon_in, afternoon_out)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$user_id, $today, $morning_in, $morning_out, $afternoon_in, $afternoon_out]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE check_in 
            SET morning_in = ?, morning_out = ?, afternoon_in = ?, afternoon_out = ?, updated_at = NOW()
            WHERE check_in_id = ?
        ");
        $stmt->execute([$morning_in, $morning_out, $afternoon_in, $afternoon_out, $checkInRow['check_in_id']]);
    }
    
    // 3. Compute active logged decimal hours
    $morning_hours = 0.0;
    if ($morning_in && $morning_out) {
        $m_in = strtotime($morning_in);
        $m_out = strtotime($morning_out);
        if ($m_out > $m_in) {
            $morning_hours = ($m_out - $m_in) / 3600.0;
        }
    }
    
    $afternoon_hours = 0.0;
    if ($afternoon_in && $afternoon_out) {
        $a_in = strtotime($afternoon_in);
        $a_out = strtotime($afternoon_out);
        if ($a_out > $a_in) {
            $afternoon_hours = ($a_out - $a_in) / 3600.0;
        }
    }
    
    $total_hours = round($morning_hours + $afternoon_hours, 2);
    
    // 4. Synchronize with hours_log table
    if ($total_hours > 0) {
        try {
            $hoursStmt = $pdo->prepare("
                INSERT INTO hours_log (user_id, date, hours) 
                VALUES (?, ?, ?)
            ");
            $hoursStmt->execute([$user_id, $today, $total_hours]);
        } catch (PDOException $e) {
            $hoursStmt = $pdo->prepare("
                UPDATE hours_log 
                SET hours = ?, updated_at = NOW() 
                WHERE user_id = ? AND date = ?
            ");
            $hoursStmt->execute([$total_hours, $user_id, $today]);
        }
    } else {
        $delHoursStmt = $pdo->prepare("DELETE FROM hours_log WHERE user_id = ? AND date = ?");
        $delHoursStmt->execute([$user_id, $today]);
    }
    
    $pdo->commit();
    
    // Format dynamic slot name for friendly return message
    $friendlyName = str_replace('_', ' ', $slotToStamp);
    echo json_encode([
        'success' => true,
        'message' => "Fingerprint scanned successfully! Stamped " . strtoupper($friendlyName) . " at " . $nowTime,
        'hours' => $total_hours,
        'action_type' => $actionType,
        'slot' => $slotToStamp
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
