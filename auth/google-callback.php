<?php
require_once '../includes/functions.php';

// Error handling
if (isset($_GET['error'])) {
    $error_desc = $_GET['error_description'] ?? 'Unknown error';
    redirect('/auth/login.php', 'Google error: ' . $error_desc, 'danger');
}

// Validate state
if (!isset($_GET['state']) || !hash_equals($_SESSION['csrf_token'], $_GET['state'])) {
    redirect('/auth/login.php', 'Security check failed', 'danger');
}

if (!isset($_GET['code'])) {
    redirect('/auth/login.php', 'Authorization failed', 'danger');
}

// Exchange code for token
$tokenUrl = 'https://oauth2.googleapis.com/token';
$data = [
    'code' => $_GET['code'],
    'client_id' => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri' => GOOGLE_REDIRECT_URI,
    'grant_type' => 'authorization_code'
];

$ch = curl_init($tokenUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
$response = curl_exec($ch);
curl_close($ch);

$tokenData = json_decode($response, true);

if (!isset($tokenData['access_token'])) {
    error_log("Token error: " . $response);
    redirect('/auth/login.php', 'Authentication failed', 'danger');
}

$accessToken = $tokenData['access_token'];

// Get user info
$ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$userInfo = curl_exec($ch);
curl_close($ch);

$userData = json_decode($userInfo, true);

if (!isset($userData['email'])) {
    redirect('/auth/login.php', 'Email not found', 'danger');
}

// 🟢 FETCH CONTACTS if requested
$contactsImported = 0;
if (!empty($_SESSION['fetch_contacts_after_login']) && isset($tokenData['access_token'])) {
    $contactsUrl = 'https://people.googleapis.com/v1/people/me/connections?personFields=names,emailAddresses,phoneNumbers&pageSize=1000';
    
    $ch = curl_init($contactsUrl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $contactsResult = curl_exec($ch);
    curl_close($ch);
    
    $contactsData = json_decode($contactsResult, true);
    
    if (isset($contactsData['connections']) && count($contactsData['connections']) > 0) {
        $_SESSION['pending_contacts'] = $contactsData['connections'];
        $contactsImported = count($contactsData['connections']);
        error_log("Fetched " . $contactsImported . " contacts");
    }
    
    unset($_SESSION['fetch_contacts_after_login']);
}

// Check admin
$isAdmin = ($userData['email'] === 'leoinfotech.chinnamanur@gmail.com');
$userRole = $isAdmin ? 'admin' : 'user';

try {
    $db = getDB();
    
    // Check existing user
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ? OR google_id = ?");
    $stmt->execute([$userData['email'], $userData['id']]);
    $user = $stmt->fetch();
    
    if ($user) {
        $userId = $user['id'];
        if ($isAdmin && $user['role'] !== 'admin') {
            $db->prepare("UPDATE users SET role = 'admin' WHERE id = ?")->execute([$userId]);
        }
    } else {
        // Create new user
        $stmt = $db->prepare("
            INSERT INTO users (google_id, email, name, avatar, role, coin_balance, created_at) 
            VALUES (?, ?, ?, ?, ?, 0.0000, NOW())
        ");
        $stmt->execute([
            $userData['id'],
            $userData['email'],
            $userData['name'] ?? 'User',
            $userData['picture'] ?? null,
            $userRole
        ]);
        $userId = $db->lastInsertId();
        
        // Welcome bonus
        if (!$isAdmin) {
            addCoins($userId, 0.050, 'admin_add', $userId, 'Welcome bonus');
        }
    }
    
    // 🟢 SAVE CONTACTS if fetched
    if (!empty($_SESSION['pending_contacts'])) {
        $imported = 0;
        foreach ($_SESSION['pending_contacts'] as $person) {
            $name = $person['names'][0]['displayName'] ?? 'Unknown';
            $email = $person['emailAddresses'][0]['value'] ?? null;
            $phone = $person['phoneNumbers'][0]['value'] ?? null;
            
            if ($email) {
                try {
                    $stmt = $db->prepare("
                        INSERT INTO user_contacts (user_id, name, email, phone, source) 
                        VALUES (?, ?, ?, ?, 'google')
                        ON DUPLICATE KEY UPDATE name = VALUES(name), phone = VALUES(phone)
                    ");
                    $stmt->execute([$userId, $name, $email, $phone]);
                    $imported++;
                } catch (Exception $e) {
                    error_log("Contact save error: " . $e->getMessage());
                }
            }
        }
        $contactsImported = $imported;
        unset($_SESSION['pending_contacts']);
        error_log("Saved " . $imported . " contacts to database");
    }
    
    // Create session
    $sessionToken = bin2hex(random_bytes(32));
    $deviceType = (strpos($_SERVER['HTTP_USER_AGENT'], 'Mobile') !== false) ? 'mobile' : 'desktop';
    
    $stmt = $db->prepare("
        INSERT INTO user_sessions 
        (user_id, session_token, device_type, device_info, ip_address, expires_at, is_valid) 
        VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE), 1)
    ");
    $stmt->execute([
        $userId,
        $sessionToken,
        $deviceType,
        substr($_SERVER['HTTP_USER_AGENT'], 0, 255),
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    
    // Set session
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_email'] = $userData['email'];
    $_SESSION['user_name'] = $userData['name'] ?? 'User';
    $_SESSION['user_avatar'] = $userData['picture'] ?? null;
    $_SESSION['session_token'] = $sessionToken;
    $_SESSION['user_role'] = $userRole;
    
    // ═══════════════════════════════════════════════════
    // DEFINE VARIABLES FIRST (for both web and mobile)
    // ═══════════════════════════════════════════════════
    $redirectUrl = $isAdmin ? '/admin/dashboard.php' : '/user/dashboard.php';
    $message = $contactsImported > 0 
        ? "Welcome! $contactsImported contacts imported from Google." 
        : 'Welcome back!';
    
    // ═══════════════════════════════════════════════════
    // MAUI App detection (AFTER variables are defined)
    // ═══════════════════════════════════════════════════
    if (isset($_GET['mobile']) || strpos($_SERVER['HTTP_USER_AGENT'], 'MAUI') !== false) {
        // Output JavaScript for MAUI WebView to capture
        echo "<!DOCTYPE html><html><head><title>Login Success</title></head><body>";
        echo "<script>window.userData = " . json_encode([
            'email' => $userData['email'],
            'google_id' => $userData['id'],
            'name' => $userData['name'],
            'avatar' => $userData['picture']
        ]) . ";</script>";
        echo "<h2>Login Successful!</h2><p>Redirecting to app...</p>";
        echo "<script>setTimeout(function() { window.location.href = '" . $redirectUrl . "'; }, 2000);</script>";
        echo "</body></html>";
        exit();
    }

    // ═══════════════════════════════════════════════════
    // Normal web redirect (if not mobile)
    // ═══════════════════════════════════════════════════
    redirect($redirectUrl, $message, 'success');
    
} catch (PDOException $e) {
    error_log("Login error: " . $e->getMessage());
    redirect('/auth/login.php', 'System error', 'danger');
}
?>
