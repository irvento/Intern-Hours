<?php
require_once __DIR__ . '/../config.php';

session_start();

// Verify state to prevent CSRF attacks
if (!isset($_GET['state']) || !isset($_SESSION['oauth_state']) || $_GET['state'] !== $_SESSION['oauth_state']) {
    die('Invalid state parameter. Possible CSRF attack.');
}

unset($_SESSION['oauth_state']);

if (isset($_GET['error'])) {
    header('Location: ../views/feed.php?page=login&error=google_auth_failed');
    exit;
}

$code = $_GET['code'] ?? '';
if (empty($code)) {
    header('Location: ../views/feed.php?page=login&error=no_code');
    exit;
}

$google_client_id = get_config('GOOGLE_CLIENT_ID');
$google_client_secret = get_config('GOOGLE_CLIENT_SECRET');
$google_redirect_uri = get_config('GOOGLE_REDIRECT_URI', 'http://localhost/Intern-Hours/api/google-callback.php');

// Exchange authorization code for access token
$token_url = 'https://oauth2.googleapis.com/token';
$token_data = [
    'code' => $code,
    'client_id' => $google_client_id,
    'client_secret' => $google_client_secret,
    'redirect_uri' => $google_redirect_uri,
    'grant_type' => 'authorization_code',
];

$ch = curl_init($token_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($token_data));
$response = curl_exec($ch);
curl_close($ch);

$token_data = json_decode($response, true);

if (!isset($token_data['access_token'])) {
    header('Location: ../views/feed.php?page=login&error=token_exchange_failed');
    exit;
}

// Get user info from Google
$user_info_url = 'https://www.googleapis.com/oauth2/v2/userinfo';
$ch = curl_init($user_info_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token_data['access_token']
]);
$user_info_response = curl_exec($ch);
curl_close($ch);

$user_info = json_decode($user_info_response, true);

if (!isset($user_info['email'])) {
    header('Location: ../views/feed.php?page=login&error=failed_to_get_user_info');
    exit;
}

$google_id = $user_info['id'];
$email = $user_info['email'];
$name = $user_info['name'] ?? '';

// Check if user exists by Google ID
$stmt = $pdo->prepare("SELECT * FROM users WHERE google_id = ?");
$stmt->execute([$google_id]);
$user = $stmt->fetch();

if ($user) {
    // User exists, log them in
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['is_darkmode'] = (int)($user['is_darkmode'] ?? 0);
    $_SESSION['office_id'] = $user['office_id'] ?? null;
    $_SESSION['organization_id'] = $user['organization_id'] ?? null;
    
    // Update tokens
    $stmt = $pdo->prepare("UPDATE users SET google_access_token = ?, google_refresh_token = ? WHERE id = ?");
    $stmt->execute([$token_data['access_token'], $token_data['refresh_token'] ?? '', $user['id']]);
    
    // Log login to login_logs table
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $browser = get_browser_from_ua($ua);
    $device = get_device_from_ua($ua);

    $log_stmt = $pdo->prepare("INSERT INTO login_logs (user_id, ip_address, browser, device) VALUES (?, ?, ?, ?)");
    $log_stmt->execute([$user['id'], $ip_address, $browser, $device]);

    // Redirect based on role
    // Redirect to routed dashboard
    header("Location: ../views/feed.php?page=dashboard");
    exit;
} else {
    // Check if user exists by email (account linking)
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $existing_user = $stmt->fetch();
    
    if ($existing_user) {
        // Link Google account to existing user
        $stmt = $pdo->prepare("UPDATE users SET google_id = ?, google_access_token = ?, google_refresh_token = ? WHERE id = ?");
        $stmt->execute([$google_id, $token_data['access_token'], $token_data['refresh_token'] ?? '', $existing_user['id']]);
        
        $_SESSION['user_id'] = $existing_user['id'];
        $_SESSION['user_name'] = $existing_user['name'];
        $_SESSION['user_email'] = $existing_user['email'];
        $_SESSION['user_role'] = $existing_user['role'];
        $_SESSION['is_darkmode'] = (int)($existing_user['is_darkmode'] ?? 0);
        $_SESSION['office_id'] = $existing_user['office_id'] ?? null;
        $_SESSION['organization_id'] = $existing_user['organization_id'] ?? null;
        
        // Log login to login_logs table
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $browser = get_browser_from_ua($ua);
        $device = get_device_from_ua($ua);

        $log_stmt = $pdo->prepare("INSERT INTO login_logs (user_id, ip_address, browser, device) VALUES (?, ?, ?, ?)");
        $log_stmt->execute([$existing_user['id'], $ip_address, $browser, $device]);

        header("Location: ../views/feed.php?page=dashboard");
        exit;
    } else {
        // Create new user with Google account
        $stmt = $pdo->prepare("INSERT INTO users (google_id, google_access_token, google_refresh_token, name, email, role) VALUES (?, ?, ?, ?, ?, 'Intern')");
        $stmt->execute([$google_id, $token_data['access_token'], $token_data['refresh_token'] ?? '', $name, $email]);
        
        $user_id = $pdo->lastInsertId();
        
        $_SESSION['user_id'] = $user_id;
        $_SESSION['user_name'] = $name;
        $_SESSION['user_email'] = $email;
        $_SESSION['user_role'] = 'Intern';
        $_SESSION['is_darkmode'] = 0;
        
        // Log login to login_logs table
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $browser = get_browser_from_ua($ua);
        $device = get_device_from_ua($ua);

        $log_stmt = $pdo->prepare("INSERT INTO login_logs (user_id, ip_address, browser, device) VALUES (?, ?, ?, ?)");
        $log_stmt->execute([$user_id, $ip_address, $browser, $device]);

        header("Location: ../views/feed.php?page=dashboard");
        exit;
    }
}
?>
