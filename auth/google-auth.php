<?php
require_once '../includes/functions.php';

// Check if this request is for contacts sync
$forContacts = isset($_GET['for']) && $_GET['for'] === 'contacts';

// Build scopes
$scopes = ['openid', 'email', 'profile'];

// Add contacts scope if syncing contacts
if ($forContacts) {
    $scopes[] = 'https://www.googleapis.com/auth/contacts.readonly';
    
    // Store in session that we need to fetch contacts after login
    $_SESSION['fetch_contacts_after_login'] = true;
}

// Check if configured
if (GOOGLE_CLIENT_ID === 'YOUR_CLIENT_ID' || empty(GOOGLE_CLIENT_ID)) {
    die('
        <div style="font-family: Inter, Arial; max-width: 600px; margin: 50px auto; padding: 40px; text-align: center; background: #fff; border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.1);">
            <h2 style="color: #e74c3c; margin-bottom: 10px;">⚠️ Google OAuth Not Configured</h2>
            <p style="color: #666; margin-bottom: 20px;">Please update GOOGLE_CLIENT_ID in /includes/config.php</p>
            <a href="/auth/login.php" style="color: #3498db; text-decoration: none;">← Back to Login</a>
        </div>
    ');
}

// Construct OAuth URL
$googleAuthUrl = 'https://accounts.google.com/o/oauth2/v2/auth';
$params = [
    'client_id' => GOOGLE_CLIENT_ID,
    'redirect_uri' => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope' => 'openid email profile', // BASIC ONLY - No contacts
    'access_type' => 'online',
    'prompt' => 'select_account',
    'state' => $_SESSION['csrf_token']
];


// Debug: log the request
error_log("Google Auth: forContacts=" . ($forContacts ? 'yes' : 'no') . ", scopes=" . implode(' ', $scopes));

$authUrl = $googleAuthUrl . '?' . http_build_query($params);
header('Location: ' . $authUrl);
exit();
