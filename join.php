<?php
/**
 * Join Team Handler - join.php
 */
require_once 'config.php';

$inviteCode = filter_input(INPUT_GET, 'invite', FILTER_DEFAULT);
if (!$inviteCode) {
    header("Location: team.php");
    exit;
}

try {
    // 1. Fetch team by invite code
    $stmt = $pdo->prepare("SELECT * FROM teams WHERE invite_code = :code");
    $stmt->execute(['code' => $inviteCode]);
    $team = $stmt->fetch();
    
    if (!$team) {
        die("Xatolik: Noto'g'ri taklif havolasi yoki jamoa o'chirilgan.");
    }
    
    // 2. Check if user is logged in
    if (!isset($_SESSION['user_id'])) {
        // Redirect to register with the invite code
        header("Location: register.php?invite=" . urlencode($inviteCode));
        exit;
    }
    
    $userId = $_SESSION['user_id'];
    
    // 3. Add user to the team in team_members
    $insStmt = $pdo->prepare("INSERT IGNORE INTO team_members (team_id, user_id) VALUES (:team_id, :user_id)");
    $insStmt->execute([
        'team_id' => $team['id'],
        'user_id' => $userId
    ]);
    
    // 4. Set this team as the active team in the session
    $_SESSION['active_team_id'] = $team['id'];
    
    // Redirect to team.php
    header("Location: team.php?success=" . urlencode("Siz '" . $team['name'] . "' jamoasiga qo'shildingiz!"));
    exit;
    
} catch (PDOException $e) {
    die("Jamoaga qo'shilishda xatolik: " . $e->getMessage());
}
