<?php
/**
 * AJAX Endpoint - Get list of typing users
 */
require_once 'config.php';

if (isset($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];
    try {
        // Fetch users who typed in the last 4 seconds (excluding the current user)
        $stmt = $pdo->prepare("
            SELECT fio, role 
            FROM web_users 
            WHERE last_typing_activity >= NOW() - INTERVAL 4 SECOND 
              AND id != :userId
        ");
        $stmt->execute(['userId' => $userId]);
        $typingUsers = $stmt->fetchAll();
        
        echo json_encode(['status' => 'success', 'users' => $typingUsers]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'unauthorized']);
}
?>
