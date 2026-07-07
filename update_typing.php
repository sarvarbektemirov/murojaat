<?php
/**
 * AJAX Endpoint - Update typing status
 */
require_once 'config.php';

if (isset($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];
    try {
        $stmt = $pdo->prepare("UPDATE web_users SET last_typing_activity = NOW() WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        echo json_encode(['status' => 'success']);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'unauthorized']);
}
?>
