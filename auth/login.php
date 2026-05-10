<?php
require_once '../includes/functions.php';

if (isLoggedIn()) {
    redirect('/user/dashboard.php');
}

$pageTitle = 'Login';
?>
<!DOCTYPE html>
<html lang="ta" class="<?php echo isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? 'dark' : ''; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> | AkkuApps</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: { primary: { 500: '#0ea5e9', 600: '#0284c7' } },
                    boxShadow: {
                        'neu-light': '8px 8px 16px #d1d5db, -8px -8px 16px #ffffff',
                        'neu-dark': '8px 8px 16px #1f2937, -8px -8px 16px #374151',
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .neu-card {
            background: linear-gradient(145deg, #f3f4f6, #ffffff);
            border-radius: 1.5rem;
            box-shadow: 8px 8px 16px #d1d5db, -8px -8px 16px #ffffff;
        }
        .dark .neu-card {
            background: linear-gradient(145deg, #1f2937, #111827);
            box-shadow: 8px 8px 16px #0f172a, -8px -8px 16px #1e293b;
        }
        .google-btn {
            background: #ffffff;
            border: 1px solid #dadce0;
            color: #3c4043;
            transition: all 0.2s;
            padding: 12px 24px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
        }
        .google-btn:hover {
            box-shadow: 0 1px 2px 0 rgba(60,64,67,0.3), 0 1px 3px 1px rgba(60,64,67,0.15);
        }
    </style>
</head>
<body class="bg-gray-100 dark:bg-gray-900 min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <h1 class="text-4xl font-bold bg-gradient-to-r from-primary-600 to-purple-600 bg-clip-text text-transparent mb-2">
                AkkuApps
            </h1>
            <p class="text-gray-600 dark:text-gray-400">Welcome! Sign in to continue</p>
        </div>

        <div class="neu-card p-8 text-center">
            <?php showFlashMessage(); ?>
            
            <div class="mb-6">
                <p class="text-gray-600 dark:text-gray-400 mb-6">
                    Sign in with your Google account to access your dashboard
                </p>
                
                <a href="google-auth.php" class="google-btn">
                    <svg class="w-5 h-5" viewBox="0 0 24 24">
                        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                        <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                    </svg>
                    Continue with Google
                </a>
            </div>

            <div class="text-sm text-gray-500 dark:text-gray-400">
                By signing in, you agree to our Terms of Service and Privacy Policy
            </div>
        </div>

        <div class="text-center mt-6">
            <a href="/" class="text-gray-600 dark:text-gray-400 hover:text-primary-600 transition text-sm">
                ← Back to Home
            </a>
        </div>
    </div>
</body>
</html>
