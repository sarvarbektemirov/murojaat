<?php
/**
 * Global Configuration for MurojaatBot Web Panel
 */

// Start session with persistence if not already started
if (session_status() == PHP_SESSION_NONE) {
    // Set session cookie lifetime to 30 days (in seconds)
    $session_lifetime = 30 * 24 * 60 * 60;
    
    // Set garbage collection and cookie lifetime in php ini
    ini_set('session.gc_maxlifetime', $session_lifetime);
    ini_set('session.cookie_lifetime', $session_lifetime);
    
    // Set cookie parameters
    if (version_compare(PHP_VERSION, '7.3.0', '>=')) {
        session_set_cookie_params([
            'lifetime' => $session_lifetime,
            'path' => '/',
            'secure' => false,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    } else {
        session_set_cookie_params($session_lifetime, '/; SameSite=Lax', '', false, true);
    }
    
    session_start();
}

// Database Credentials
define('DB_HOST', 'mysql-8.4');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'murojatbot_db');
define('BOT_TOKEN', '8921400111:AAHxeZGOpXifWycGS1wSq0up4Kk4U_Dfz5o');

// Establish PDO connection
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    die("Ma'lumotlar bazasiga ulanishda xatolik yuz berdi: " . $e->getMessage());
}

/**
 * Send notification to user via Telegram Bot API
 *
 * @param int $chat_id Telegram user ID
 * @param string $text Notification message text (HTML enabled)
 * @param string|null $file_path Local path to response file (optional)
 * @return string|bool API response or false on failure
 */
function send_telegram_notification($chat_id, $text, $file_path = null) {
    // Send message text
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendMessage";
    $post_fields = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);
    
    // If there is a file attached, send it as a document
    if ($file_path && file_exists($file_path)) {
        $file_url = "https://api.telegram.org/bot" . BOT_TOKEN . "/sendDocument";
        
        // Use CURLFile for safe uploading
        $cfile = new CURLFile($file_path);
        
        $post_fields_file = [
            'chat_id' => $chat_id,
            'document' => $cfile,
            'caption' => 'Murojaatingiz bo\'yicha rasmiy javob xati.'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $file_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields_file);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response_file = curl_exec($ch);
        curl_close($ch);
    }
    
    return $response;
}

/**
 * Check if the user is logged in
 */
function check_auth() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit;
    }
}

/**
 * Check if the user has a specific role
 */
function check_role($allowed_roles = []) {
    check_auth();
    if (!in_array($_SESSION['role'], $allowed_roles)) {
        die("Ruxsat etilmagan sahifa. Ushbu sahifaga kirish huquqingiz yo'q.");
    }
}
?>
