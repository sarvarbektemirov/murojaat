<?php
/**
 * Team List & Direct Chat Page - team.php
 */
require_once 'config.php';
check_auth();

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

$errorMsg = '';
$successMsg = '';

// Handle GET: Delete message (Owner or Admin)
if (isset($_GET['delete_msg'])) {
    $deleteId = filter_input(INPUT_GET, 'delete_msg', FILTER_VALIDATE_INT);
    if ($deleteId) {
        try {
            // Check ownership or admin status
            $chkStmt = $pdo->prepare("SELECT user_id, file_path FROM team_messages WHERE id = :id");
            $chkStmt->execute(['id' => $deleteId]);
            $msg = $chkStmt->fetch();
            
            if ($msg) {
                if ($msg['user_id'] == $userId || $userRole === 'admin') {
                    // Delete file if exists
                    if ($msg['file_path'] && file_exists(__DIR__ . '/' . $msg['file_path'])) {
                        unlink(__DIR__ . '/' . $msg['file_path']);
                    }
                    
                    $delStmt = $pdo->prepare("DELETE FROM team_messages WHERE id = :id");
                    $delStmt->execute(['id' => $deleteId]);
                    
                    header("Location: team.php");
                    exit;
                } else {
                    $errorMsg = "Ruxsat etilmagan amal! Faqat o'z xabarlaringizni o'chira olasiz.";
                }
            }
        } catch (PDOException $e) {
            $errorMsg = "Xabarni o'chirishda xatolik: " . $e->getMessage();
        }
    }
}

// Handle POST: Create a new team
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_team'])) {
    $newTeamName = trim($_POST['team_name'] ?? '');
    if (!empty($newTeamName)) {
        try {
            $invCode = strtolower(preg_replace('/[^a-zA-Z0-9]/', '_', $newTeamName)) . '_' . bin2hex(random_bytes(3));
            $ctStmt = $pdo->prepare("INSERT INTO teams (name, invite_code, creator_id) VALUES (:name, :code, :creator)");
            $ctStmt->execute(['name' => $newTeamName, 'code' => $invCode, 'creator' => $userId]);
            $newTeamId = $pdo->lastInsertId();
            // Add creator to team
            $pdo->prepare("INSERT IGNORE INTO team_members (team_id, user_id) VALUES (:tid, :uid)")->execute(['tid' => $newTeamId, 'uid' => $userId]);
            $_SESSION['active_team_id'] = $newTeamId;
            header("Location: team.php?success=" . urlencode("'$newTeamName' jamoasi yaratildi!"));
            exit;
        } catch (PDOException $e) {
            $errorMsg = "Jamoa yaratishda xatolik: " . $e->getMessage();
        }
    }
}

// Handle POST: Switch active team
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['switch_team'])) {
    $switchTeamId = filter_input(INPUT_POST, 'team_id', FILTER_VALIDATE_INT);
    if ($switchTeamId) {
        // Verify user is a member
        $memCheck = $pdo->prepare("SELECT 1 FROM team_members WHERE team_id = :tid AND user_id = :uid");
        $memCheck->execute(['tid' => $switchTeamId, 'uid' => $userId]);
        if ($memCheck->fetchColumn()) {
            $_SESSION['active_team_id'] = $switchTeamId;
        }
    }
    header("Location: team.php");
    exit;
}

// Handle POST: Kick member from team
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['kick_member'])) {
    $kickUserId = filter_input(INPUT_POST, 'kick_user_id', FILTER_VALIDATE_INT);
    $kickTeamId = filter_input(INPUT_POST, 'kick_team_id', FILTER_VALIDATE_INT);
    if ($kickUserId && $kickTeamId) {
        // Only team creator or admin can kick
        $teamOwnerStmt = $pdo->prepare("SELECT creator_id FROM teams WHERE id = :tid");
        $teamOwnerStmt->execute(['tid' => $kickTeamId]);
        $teamCreatorId = $teamOwnerStmt->fetchColumn();
        
        if ($teamCreatorId == $userId || $userRole === 'admin') {
            // Can't kick yourself
            if ($kickUserId != $userId) {
                $kickStmt = $pdo->prepare("DELETE FROM team_members WHERE team_id = :tid AND user_id = :uid");
                $kickStmt->execute(['tid' => $kickTeamId, 'uid' => $kickUserId]);
                header("Location: team.php?success=" . urlencode("A'zo jamoadan chiqarib tashlandi!"));
                exit;
            }
        } else {
            $errorMsg = "Faqat jamoa egasi yoki admin a'zoni chiqara oladi!";
        }
    }
    header("Location: team.php");
    exit;
}

// Handle POST: Change member role (only team creator can do this)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_member_role'])) {
    $targetUserId = filter_input(INPUT_POST, 'target_user_id', FILTER_VALIDATE_INT);
    $newRole = trim($_POST['new_role'] ?? '');
    $activeTeamId = $_SESSION['active_team_id'] ?? 1;
    
    if ($targetUserId && in_array($newRole, ['admin', 'masul'])) {
        // Check if current user is team creator
        $teamOwnerStmt = $pdo->prepare("SELECT creator_id FROM teams WHERE id = :tid");
        $teamOwnerStmt->execute(['tid' => $activeTeamId]);
        $teamCreatorId = $teamOwnerStmt->fetchColumn();
        
        if ($teamCreatorId == $userId) {
            try {
                $upRoleStmt = $pdo->prepare("UPDATE web_users SET role = :role WHERE id = :id");
                $upRoleStmt->execute(['role' => $newRole, 'id' => $targetUserId]);
                header("Location: team.php?success=" . urlencode("Foydalanuvchi roli muvaffaqiyatli yangilandi!"));
                exit;
            } catch (PDOException $e) {
                $errorMsg = "Rolni o'zgartirishda xatolik: " . $e->getMessage();
            }
        } else {
            $errorMsg = "Faqat jamoa yaratuvchisi boshqa a'zolarga admin lavozimini bera oladi!";
        }
    }
    header("Location: team.php");
    exit;
}

// Handle POST: Rename team
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rename_team'])) {
    $newTeamName = trim($_POST['new_team_name'] ?? '');
    $renameTeamId = filter_input(INPUT_POST, 'rename_team_id', FILTER_VALIDATE_INT);
    
    if ($renameTeamId && !empty($newTeamName)) {
        // Verify current user is creator of the team or admin
        $chkStmt = $pdo->prepare("SELECT creator_id FROM teams WHERE id = :tid");
        $chkStmt->execute(['tid' => $renameTeamId]);
        $creatorId = $chkStmt->fetchColumn();
        
        if ($creatorId == $userId || $userRole === 'admin') {
            try {
                $upStmt = $pdo->prepare("UPDATE teams SET name = :name WHERE id = :tid");
                $upStmt->execute(['name' => $newTeamName, 'id' => $renameTeamId]);
                header("Location: team.php?success=" . urlencode("Jamoa nomi muvaffaqiyatli o'zgartirildi!"));
                exit;
            } catch (PDOException $e) {
                $errorMsg = "Jamoa nomini o'zgartirishda xatolik: " . $e->getMessage();
            }
        } else {
            $errorMsg = "Faqat jamoa yaratuvchisi yoki admin nomni o'zgartira oladi!";
        }
    }
    header("Location: team.php");
    exit;
}

// Handle POST: Save, Edit, Reply or Upload file in chat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_message'])) {
    $messageText = trim($_POST['message_text'] ?? '');
    $editId = filter_input(INPUT_POST, 'edit_id', FILTER_VALIDATE_INT);
    $replyToId = filter_input(INPUT_POST, 'reply_to_id', FILTER_VALIDATE_INT);
    $activeTeamId = $_SESSION['active_team_id'] ?? 1;
    
    if ($editId) {
        // --- EDIT MODE ---
        if (!empty($messageText)) {
            try {
                // Check ownership
                $chkStmt = $pdo->prepare("SELECT user_id FROM team_messages WHERE id = :id");
                $chkStmt->execute(['id' => $editId]);
                $msgOwnerId = $chkStmt->fetchColumn();
                
                if ($msgOwnerId == $userId) {
                    $upStmt = $pdo->prepare("UPDATE team_messages SET message = :message WHERE id = :id");
                    $upStmt->execute(['message' => $messageText, 'id' => $editId]);
                    
                    header("Location: team.php");
                    exit;
                } else {
                    $errorMsg = "Xabarni tahrirlashga ruxsat yo'q!";
                }
            } catch (PDOException $e) {
                $errorMsg = "Tahrirlashda xatolik: " . $e->getMessage();
            }
        }
    } else {
        // --- NEW MESSAGE / REPLY MODE ---
        $filePath = null;
        $fileType = null;
        
        // Check if a file is uploaded
        if (isset($_FILES['chat_file']) && $_FILES['chat_file']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $_FILES['chat_file']['tmp_name'];
            $fileName = $_FILES['chat_file']['name'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            
            $uploadDir = __DIR__ . '/uploads/chat_media/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $newFileName = 'chat_' . time() . '_' . rand(1000, 9999) . '.' . $fileExtension;
            $destPath = $uploadDir . $newFileName;
            
            // Determine file type
            $photoExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $videoExts = ['mp4', 'webm', 'avi', 'mov', 'mkv'];
            $audioExts = ['mp3', 'wav', 'ogg', 'm4a', 'flac'];
            
            if (in_array($fileExtension, $photoExts)) {
                $fileType = 'photo';
            } elseif (in_array($fileExtension, $videoExts)) {
                $fileType = 'video';
            } elseif (in_array($fileExtension, $audioExts)) {
                $fileType = 'voice';
            } else {
                $fileType = 'document';
            }
            
            if (move_uploaded_file($fileTmpPath, $destPath)) {
                $filePath = 'uploads/chat_media/' . $newFileName;
                if (empty($messageText)) {
                    $messageText = "Fayl biriktirildi: " . $fileName;
                }
            } else {
                $errorMsg = "Faylni yuklashda xatolik yuz berdi.";
            }
        }
        
        // Save to database
        if (empty($errorMsg) && (!empty($messageText) || $filePath !== null)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO team_messages (user_id, message, file_path, file_type, reply_to_id, team_id) 
                    VALUES (:user_id, :message, :file_path, :file_type, :reply_to_id, :team_id)
                ");
                $stmt->execute([
                    'user_id' => $userId,
                    'message' => $messageText,
                    'file_path' => $filePath,
                    'file_type' => $fileType,
                    'reply_to_id' => $replyToId ? $replyToId : null,
                    'team_id' => $activeTeamId
                ]);
                
                header("Location: team.php");
                exit;
            } catch (PDOException $e) {
                $errorMsg = "Xabarni yuborishda xatolik: " . $e->getMessage();
            }
        }
    }
}

// Fetch all teams the current user belongs to
try {
    $myTeamsStmt = $pdo->prepare("
        SELECT t.* FROM teams t 
        JOIN team_members tm ON t.id = tm.team_id 
        WHERE tm.user_id = :uid 
        ORDER BY t.created_at ASC
    ");
    $myTeamsStmt->execute(['uid' => $userId]);
    $myTeams = $myTeamsStmt->fetchAll();
} catch (PDOException $e) {
    $myTeams = [];
}

// If no teams found, fallback to default team (id=1) and add user
if (empty($myTeams)) {
    $pdo->prepare("INSERT IGNORE INTO team_members (team_id, user_id) VALUES (1, :uid)")->execute(['uid' => $userId]);
    $fallbackTeam = $pdo->query("SELECT * FROM teams WHERE id = 1")->fetch();
    $myTeams = $fallbackTeam ? [$fallbackTeam] : [];
}

// Determine active team
$activeTeamId = $_SESSION['active_team_id'] ?? ($myTeams[0]['id'] ?? 1);
// Ensure active team is one user belongs to
$validTeamIds = array_column($myTeams, 'id');
if (!in_array($activeTeamId, $validTeamIds)) {
    $activeTeamId = $validTeamIds[0] ?? 1;
}
$_SESSION['active_team_id'] = $activeTeamId;

// Fetch active team info
try {
    $activeTeamStmt = $pdo->prepare("SELECT * FROM teams WHERE id = :id");
    $activeTeamStmt->execute(['id' => $activeTeamId]);
    $activeTeam = $activeTeamStmt->fetch();
} catch (PDOException $e) {
    $activeTeam = null;
}

// Fetch members of the active team
try {
    $teamStmt = $pdo->prepare("
        SELECT wu.id, wu.username, wu.fio, wu.role, wu.department, wu.avatar 
        FROM web_users wu 
        JOIN team_members tm ON wu.id = tm.user_id 
        WHERE tm.team_id = :tid 
        ORDER BY wu.fio ASC
    ");
    $teamStmt->execute(['tid' => $activeTeamId]);
    $teamMembers = $teamStmt->fetchAll();
    $memberCount = count($teamMembers);
} catch (PDOException $e) {
    $teamMembers = [];
    $memberCount = 0;
}

// Prepare mention lists for Javascript
$mentionsList = [];
foreach ($teamMembers as $m) {
    $cleanUsername = str_replace(' ', '_', $m['fio']);
    $mentionsList[] = [
        'username' => $cleanUsername,
        'fio' => $m['fio'],
        'role' => $m['role'] === 'admin' ? 'Admin' : ($m['role'] === 'hokim' ? 'Hokim' : $m['department'])
    ];
}

// Fetch last 100 chat messages for the active team
try {
    $chatStmt = $pdo->prepare("
        SELECT tm.*, w.fio, w.role, w.department, w.avatar,
               orig.message as orig_message, orig_user.fio as orig_fio
        FROM team_messages tm 
        JOIN web_users w ON tm.user_id = w.id 
        LEFT JOIN team_messages orig ON tm.reply_to_id = orig.id
        LEFT JOIN web_users orig_user ON orig.user_id = orig_user.id
        WHERE tm.team_id = :team_id
        ORDER BY tm.created_at ASC 
        LIMIT 100
    ");
    $chatStmt->execute(['team_id' => $activeTeamId]);
    $chatMessages = $chatStmt->fetchAll();
} catch (PDOException $e) {
    die("Xatolik yuz berdi: " . $e->getMessage());
}

if (isset($_GET['success'])) {
    $successMsg = $_GET['success'];
}
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jamoa va Chat | Sardoba Hokimligi Murojaat Bot</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Custom Chat UI overrides */
        .chat-container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 24px;
            height: calc(100vh - 150px);
        }

        /* Top Navigation Dropdowns */
        .top-dropdown {
            position: relative;
            display: inline-block;
        }
        .top-dropdown-content {
            display: none;
            position: absolute;
            right: 0;
            top: calc(100% + 8px);
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            min-width: 300px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.4);
            z-index: 200;
            padding: 16px;
        }
        .top-dropdown-content.show {
            display: block;
        }
        .top-dropdown-btn {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s ease;
            user-select: none;
        }
        .top-dropdown-btn:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--primary-color);
        }
        
        .team-list-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .team-list-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            font-weight: 700;
            font-size: 16px;
        }

        .team-list-body {
            flex: 1;
            overflow-y: auto;
            padding: 12px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .team-member-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px;
            border-radius: 12px;
            transition: background-color 0.2s ease;
        }

        .team-member-item:hover {
            background-color: rgba(255, 255, 255, 0.03);
        }

        .team-member-item:hover .kick-form {
            opacity: 1 !important;
        }

        .member-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background-color: var(--primary-glow);
            color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 14px;
        }

        .member-details {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-width: 0;
        }

        .member-name {
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .member-role {
            font-size: 11px;
            color: var(--text-secondary);
        }

        /* Chat area styles */
        .chat-box-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .chat-messages-area {
            flex: 1;
            overflow-y: auto;
            padding: 16px 18px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            user-select: none;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        
        .chat-messages-area::-webkit-scrollbar {
            display: none; /* Chrome, Safari, Opera */
        }

        .chat-bubble {
            display: flow-root;
            max-width: 72%;
            padding: 6px 10px;
            border-radius: 12px;
            position: relative;
            transform: translateX(0px);
            transition: transform 0.1s ease;
            cursor: grab;
            width: fit-content;
            min-width: 80px;
            text-align: left !important;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.15);
            box-sizing: border-box;
        }
        
        .chat-bubble:active {
            cursor: grabbing;
        }

        .chat-bubble.other {
            background-color: rgba(255, 255, 255, 0.04);
            color: var(--text-primary);
            border-bottom-left-radius: 4px;
            border: 1px solid var(--border-color);
        }
        body.light-mode .chat-bubble.other {
            background-color: #f1f5f9;
        }

        .chat-bubble.self {
            background-color: var(--primary-color);
            color: white;
            border-bottom-right-radius: 4px;
        }

        .chat-bubble-sender {
            display: block;
            font-size: 11px;
            font-weight: 700;
            margin-bottom: 3px;
            color: #f59e0b;
        }

        .chat-bubble-text {
            font-size: 13px;
            line-height: 1.45;
            white-space: pre-wrap;
            word-break: break-word;
            display: inline;
        }

        .chat-bubble-time {
            font-size: 9px;
            color: rgba(255, 255, 255, 0.55);
            float: right;
            margin-top: 5px;
            margin-left: 8px;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            user-select: none;
        }
        .chat-bubble.other .chat-bubble-time {
            color: var(--text-secondary);
        }

        .chat-input-area {
            padding: 10px 16px;
            border-top: 1px solid var(--border-color);
            background-color: rgba(255, 255, 255, 0.01);
            position: relative;
        }

        .chat-form {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .chat-input {
            flex: 1;
            background-color: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 9px 14px;
            border-radius: 10px;
            font-size: 13px;
            outline: none;
            transition: border-color 0.2s ease;
        }

        .chat-input:focus {
            border-color: var(--primary-color);
        }
        
        .file-label {
            cursor: pointer;
            font-size: 18px;
            color: var(--text-secondary);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
        }
        
        .file-label:hover {
            background-color: rgba(255,255,255,0.05);
            color: var(--text-primary);
        }

        .chat-media-preview {
            max-width: 300px;
            margin-bottom: 8px;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--border-color);
        }

        .chat-media-preview img, 
        .chat-media-preview video {
            width: 100%;
            display: block;
        }
        
        .chat-doc-card {
            display: flex;
            align-items: center;
            gap: 12px;
            background-color: rgba(255,255,255,0.03);
            border: 1px solid var(--border-color);
            padding: 10px 14px;
            border-radius: 12px;
            margin-bottom: 8px;
            font-size: 13px;
        }
        .chat-bubble.self .chat-doc-card {
            background-color: rgba(255,255,255,0.15);
            border-color: rgba(255,255,255,0.2);
        }

        /* Reply Quote Style */
        .chat-reply-quote {
            border-left: 3px solid var(--primary-color);
            background-color: rgba(255,255,255,0.03);
            padding: 4px 8px;
            border-radius: 5px;
            margin-bottom: 5px;
            font-size: 11px;
            cursor: pointer;
            max-width: 260px;
            width: 100%;
            box-sizing: border-box;
            display: block;
        }

        .chat-bubble.self .chat-reply-quote {
            border-left-color: rgba(255,255,255,0.8);
            background-color: rgba(255,255,255,0.1);
        }

        .reply-quote-sender {
            font-weight: 700;
            display: block;
            margin-bottom: 2px;
        }
        
        .reply-quote-text {
            color: var(--text-secondary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
        }
        .chat-bubble.self .reply-quote-text {
            color: rgba(255,255,255,0.8);
        }

        /* 3-Dot Dropdown Style */
        .bubble-menu-trigger {
            opacity: 0;
            transition: opacity 0.2s ease;
            padding: 4px;
            color: var(--text-secondary);
        }
        .chat-bubble:hover .bubble-menu-trigger {
            opacity: 1;
        }
        .chat-bubble.self .bubble-menu-trigger {
            color: rgba(255, 255, 255, 0.8);
        }
        .bubble-menu-dropdown a:hover {
            background-color: rgba(255,255,255,0.05);
        }
        body.light-mode .bubble-menu-dropdown a:hover {
            background-color: rgba(0,0,0,0.05);
        }

        /* Mention suggestions popup styling */
        .mention-dropdown {
            position: absolute;
            bottom: 100%;
            left: 24px;
            right: 24px;
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            box-shadow: 0 -10px 15px -3px rgba(0,0,0,0.2), 0 -4px 6px -2px rgba(0,0,0,0.1);
            max-height: 200px;
            overflow-y: auto;
            z-index: 150;
            display: none;
            padding: 8px;
            margin-bottom: 8px;
        }

        .mention-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 12px;
            border-radius: 8px;
            cursor: pointer;
            transition: background-color 0.2s ease;
        }

        .mention-item:hover {
            background-color: rgba(255,255,255,0.05);
        }
        body.light-mode .mention-item:hover {
            background-color: rgba(0,0,0,0.05);
        }

        .mention-username {
            font-size: 13px;
            font-weight: 600;
            color: var(--primary-color);
        }

        .mention-fio {
            font-size: 13px;
            color: var(--text-primary);
        }
        
        .mention-role {
            font-size: 11px;
            color: var(--text-secondary);
            margin-left: auto;
        }
    </style>
</head>
<body>
    <!-- Sidebar navigation -->
    <?php include_once 'includes/sidebar.php'; ?>

    <div class="main-content" style="display: flex; flex-direction: column;">
        <div class="page-header" style="margin-bottom: 24px;">
            <div>
                <h1 class="page-title">Jamoaviy Chat</h1>
                <div class="page-subtitle">Hokimlik xodimlari o'rtasida tezkor aloqa va muhokamalar maydoni</div>
            </div>
        </div>

        <?php if (!empty($errorMsg)): ?>
            <div style="background-color: var(--danger-bg); color: var(--danger); padding: 12px 18px; border-radius: 12px; margin-bottom: 16px; font-size: 14px; font-weight: 500;">
                <i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($errorMsg); ?>
            </div>
        <?php endif; ?>

        <?php if (!$activeTeam): ?>
            <!-- Empty state when no teams exist -->
            <div style="background-color: var(--card-bg); border: 1px solid var(--border-color); border-radius: 20px; padding: 40px; text-align: center; max-width: 500px; margin: 40px auto; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
                <div style="font-size: 48px; margin-bottom: 20px; display: inline-block;">👥</div>
                <h2 style="font-size: 20px; font-weight: 700; margin-bottom: 12px; color: var(--text-primary);">Jamoalar mavjud emas</h2>
                <p style="font-size: 14px; color: var(--text-secondary); line-height: 1.5; margin-bottom: 24px;">
                    Jamoaviy chatdan foydalanish uchun birinchi bo'lib yangi jamoa yaratishingiz kerak. Jamoani yaratgandan so'ng unga boshqa xodimlarni taklif qilishingiz mumkin.
                </p>
                <form method="POST" action="team.php" style="display: flex; flex-direction: column; gap: 12px;">
                    <div style="text-align: left;">
                        <label class="form-label" style="font-size: 12px; font-weight: 600; margin-bottom: 6px; display: block;">Jamoa nomi</label>
                        <input type="text" name="team_name" placeholder="Masalan: Sardoba Hokimligi" required style="width: 100%; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); color: var(--text-primary); padding: 10px 14px; border-radius: 10px; font-size: 14px; outline: none;">
                    </div>
                    <button type="submit" name="create_team" class="btn btn-primary" style="padding: 12px; border-radius: 10px; font-weight: 600; justify-content: center; display: flex; align-items: center; gap: 6px; width: 100%;">
                        <i class="fa-solid fa-plus"></i> Yangi jamoa yaratish
                    </button>
                </form>
            </div>
        <?php else: ?>
        <div class="chat-container">
            <!-- Chat box (takes full width) -->
            <div class="chat-box-card">
                <!-- Chat Header with team name and member count + Dropdown toolbar -->
                <div style="padding: 16px 24px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; gap: 14px; flex-shrink: 0; position: relative;">
                    <div style="width: 40px; height: 40px; border-radius: 12px; background: linear-gradient(135deg, var(--primary-color), #7c3aed); display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0;">
                        👥
                    </div>
                    <div style="flex: 1; min-width: 0;">
                        <div id="teamNameContainer" style="display: flex; align-items: center; gap: 8px;">
                            <div style="font-size: 16px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                <?php echo htmlspecialchars($activeTeam['name'] ?? 'Jamoa'); ?>
                            </div>
                            <?php if ($activeTeam['creator_id'] == $userId || $userRole === 'admin'): ?>
                                <button onclick="showRenameInput()" style="background: none; border: none; padding: 2px; color: var(--text-secondary); cursor: pointer; font-size: 12px; display: flex; align-items: center; justify-content: center;" onmouseover="this.style.color='var(--primary-color)'" onmouseout="this.style.color='var(--text-secondary)'" title="Jamoa nomini o'zgartirish">
                                    <i class="fa-solid fa-pen"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($activeTeam['creator_id'] == $userId || $userRole === 'admin'): ?>
                        <form id="renameTeamForm" method="POST" action="team.php" style="display: none; align-items: center; gap: 6px; margin-bottom: 2px;">
                            <input type="hidden" name="rename_team" value="1">
                            <input type="hidden" name="rename_team_id" value="<?php echo $activeTeam['id']; ?>">
                            <input type="text" name="new_team_name" value="<?php echo htmlspecialchars($activeTeam['name']); ?>" required style="background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); color: var(--text-primary); padding: 4px 8px; border-radius: 8px; font-size: 13px; font-weight: 600; outline: none; width: 180px;">
                            <button type="submit" class="btn btn-primary btn-sm" style="padding: 5px 10px; border-radius: 8px; font-size: 12px; display: inline-flex; align-items: center; justify-content: center;">
                                <i class="fa-solid fa-check"></i>
                            </button>
                            <button type="button" onclick="cancelRename()" class="btn btn-outline btn-sm" style="padding: 5px 10px; border-radius: 8px; font-size: 12px; border-color: var(--border-color); color: var(--text-secondary); display: inline-flex; align-items: center; justify-content: center;">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </form>
                        <?php endif; ?>

                        <div style="font-size: 12px; color: var(--text-secondary); margin-top: 2px;">
                            <i class="fa-solid fa-users" style="font-size: 10px;"></i> <?php echo $memberCount; ?> nafar a'zo
                        </div>
                    </div>
                    
                    <?php if (!empty($successMsg)): ?>
                        <div style="font-size: 12px; color: var(--success); font-weight: 600; background: var(--success-bg); padding: 6px 12px; border-radius: 8px; flex-shrink: 0; margin-right: 8px;">
                            <i class="fa-solid fa-check"></i> <?php echo htmlspecialchars($successMsg); ?>
                        </div>
                    <?php endif; ?>

                    <!-- Dropdown Toolbar -->
                    <div style="display: flex; gap: 8px; align-items: center; flex-shrink: 0;">
                        
                        <!-- 1. Mening Jamoalarim Dropdown -->
                        <div class="top-dropdown">
                            <button type="button" onclick="toggleTopDropdown('dropdownTeams')" class="top-dropdown-btn">
                                <i class="fa-solid fa-layer-group"></i> Jamoalar <i class="fa-solid fa-chevron-down" style="font-size: 10px; opacity: 0.7;"></i>
                            </button>
                            <div id="dropdownTeams" class="top-dropdown-content">
                                <div style="font-size: 11px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Jamoani almashtirish</div>
                                <form method="POST" action="team.php" style="margin-bottom: 16px;">
                                    <input type="hidden" name="switch_team" value="1">
                                    <select name="team_id" onchange="this.form.submit()" style="width: 100%; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); color: var(--text-primary); padding: 8px 12px; border-radius: 10px; font-size: 13px; font-weight: 600; outline: none; cursor: pointer;">
                                        <?php foreach ($myTeams as $t): ?>
                                            <option value="<?php echo $t['id']; ?>" <?php echo $t['id'] == $activeTeamId ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($t['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                                <div style="font-size: 11px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Yangi jamoa yaratish</div>
                                <form method="POST" action="team.php" style="display: flex; gap: 8px; align-items: center;">
                                    <input type="text" name="team_name" placeholder="Jamoa nomi..." required style="flex: 1; background: rgba(255,255,255,0.04); border: 1px solid var(--border-color); color: var(--text-primary); padding: 8px 12px; border-radius: 10px; font-size: 12px; outline: none;">
                                    <button type="submit" name="create_team" class="btn btn-primary btn-sm" style="padding: 8px 12px; border-radius: 10px;">
                                        <i class="fa-solid fa-plus"></i>
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- 2. Jamoa A'zolari Dropdown -->
                        <div class="top-dropdown">
                            <button type="button" onclick="toggleTopDropdown('dropdownMembers')" class="top-dropdown-btn">
                                <i class="fa-solid fa-users"></i> A'zolar <i class="fa-solid fa-chevron-down" style="font-size: 10px; opacity: 0.7;"></i>
                            </button>
                            <div id="dropdownMembers" class="top-dropdown-content" style="min-width: 320px;">
                                <div style="font-size: 11px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 10px;">Jamoa a'zolari (<?php echo $memberCount; ?>)</div>
                                <div style="max-height: 280px; overflow-y: auto; display: flex; flex-direction: column; gap: 8px; padding-right: 4px;">
                                    <?php foreach ($teamMembers as $member): ?>
                                        <?php 
                                        $mParts = explode(' ', $member['fio']);
                                        $mInitials = mb_substr($mParts[0] ?? '', 0, 1) . mb_substr($mParts[1] ?? '', 0, 1);
                                        $canKick = ($member['id'] != $userId) && ($activeTeam['creator_id'] == $userId || $userRole === 'admin');
                                        ?>
                                        <div class="team-member-item" style="position: relative; display: flex; align-items: center; justify-content: space-between; padding: 6px 10px; border-radius: 10px; background: rgba(255,255,255,0.02);">
                                            <a href="profile.php?id=<?php echo $member['id']; ?>" style="text-decoration: none; color: inherit; display: flex; align-items: center; gap: 10px; flex: 1; min-width: 0;">
                                                <div class="member-avatar" style="width: 32px; height: 32px; font-size: 12px; position: relative; overflow: hidden; display: flex; align-items: center; justify-content: center; flex-shrink: 0; border-radius: 50%; background-color: var(--primary-glow); color: var(--primary-color); font-weight: 700;">
                                                    <?php if ($member['avatar'] && file_exists(__DIR__ . '/' . $member['avatar'])): ?>
                                                        <img src="<?php echo htmlspecialchars($member['avatar']); ?>" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                                                    <?php else: ?>
                                                        <?php echo htmlspecialchars(strtoupper($mInitials)); ?>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="member-details" style="min-width: 0; flex: 1;">
                                                    <div class="member-name" style="font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--text-primary);"><?php echo htmlspecialchars($member['fio']); ?></div>
                                                    <div class="member-role" style="font-size: 11px; color: var(--text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                        <?php 
                                                        if ($member['role'] === 'admin') echo "Admin / Kantselyariya";
                                                        elseif ($member['role'] === 'hokim') echo "Tuman Hokimi";
                                                        elseif ($member['role'] === 'masul') echo htmlspecialchars($member['department']);
                                                        ?>
                                                    </div>
                                                </div>
                                            </a>
                                            
                                            <!-- Actions on hover/right side -->
                                            <div style="display: flex; gap: 4px; align-items: center; flex-shrink: 0; margin-left: 6px;">
                                                <?php if ($activeTeam['creator_id'] == $userId && $member['id'] != $userId && ($member['role'] === 'admin' || $member['role'] === 'masul')): ?>
                                                <form method="POST" action="team.php" style="margin: 0;">
                                                    <input type="hidden" name="change_member_role" value="1">
                                                    <input type="hidden" name="target_user_id" value="<?php echo $member['id']; ?>">
                                                    <input type="hidden" name="new_role" value="<?php echo $member['role'] === 'admin' ? 'masul' : 'admin'; ?>">
                                                    <button type="submit" 
                                                        onclick="return confirm('Haqiqatan ham <?php echo htmlspecialchars($member['fio']); ?>ning rolini o\'zgartirmoqchimisiz?')"
                                                        title="<?php echo $member['role'] === 'admin' ? 'Mas\'ul xodim qilish' : 'Admin qilish'; ?>"
                                                        style="background: rgba(59,130,246,0.12); border: 1px solid rgba(59,130,246,0.3); color: var(--primary-color); width: 26px; height: 26px; border-radius: 6px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 10px; transition: all 0.2s; padding: 0;">
                                                        <i class="fa-solid <?php echo $member['role'] === 'admin' ? 'fa-user-minus' : 'fa-user-shield'; ?>"></i>
                                                    </button>
                                                </form>
                                                <?php endif; ?>
                                                
                                                <?php if ($canKick): ?>
                                                <form method="POST" action="team.php" style="margin: 0;">
                                                    <input type="hidden" name="kick_member" value="1">
                                                    <input type="hidden" name="kick_user_id" value="<?php echo $member['id']; ?>">
                                                    <input type="hidden" name="kick_team_id" value="<?php echo $activeTeamId; ?>">
                                                    <button type="submit" 
                                                        onclick="return confirm('<?php echo htmlspecialchars($member['fio']); ?>ni jamoadan chiqarishni tasdiqlaysizmi?')"
                                                        title="Jamoadan chiqarish"
                                                        style="background: rgba(239,68,68,0.12); border: 1px solid rgba(239,68,68,0.3); color: var(--danger); width: 26px; height: 26px; border-radius: 6px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 10px; transition: all 0.2s; padding: 0;">
                                                        <i class="fa-solid fa-user-xmark"></i>
                                                    </button>
                                                </form>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <!-- 3. Jamoaga Taklif Qilish Dropdown -->
                        <div class="top-dropdown">
                            <button type="button" onclick="toggleTopDropdown('dropdownInvite')" class="top-dropdown-btn">
                                <i class="fa-solid fa-user-plus"></i> Taklif qilish <i class="fa-solid fa-chevron-down" style="font-size: 10px; opacity: 0.7;"></i>
                            </button>
                            <div id="dropdownInvite" class="top-dropdown-content" style="min-width: 320px;">
                                <div style="font-size: 11px; font-weight: 700; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">Taklif havolasi</div>
                                <div style="display: flex; gap: 8px; align-items: center;">
                                    <input type="text" id="inviteLinkInput" readonly value="http://murojatbot.local/join.php?invite=<?php echo htmlspecialchars($activeTeam['invite_code'] ?? 'sardoba_team'); ?>" style="flex: 1; font-size: 11px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); color: var(--text-secondary); padding: 8px 12px; border-radius: 10px; outline: none; min-width: 0;">
                                    <button onclick="copyInviteLink()" class="btn btn-primary btn-sm" style="padding: 8px 12px; border-radius: 10px;" title="Havolani nusxalash">
                                        <i class="fa-solid fa-copy"></i>
                                    </button>
                                </div>
                                <span id="copySuccessMsg" style="font-size: 11px; color: var(--success); display: none; font-weight: 600; margin-top: 6px;"><i class="fa-solid fa-check"></i> Havola nusxalandi!</span>
                            </div>
                        </div>

                    </div>
                </div>
                <!-- Chat Message History -->
                <div class="chat-messages-area" id="chatMessagesArea">
                    <?php if (empty($chatMessages)): ?>
                        <div style="flex: 1; display: flex; align-items: center; justify-content: center; color: var(--text-secondary); font-size: 14px;">
                            Xabarlar mavjud emas. Birinchi xabarni yozing!
                        </div>
                    <?php else: ?>
                        <?php 
                        $lastDate = null;
                        foreach ($chatMessages as $msg): 
                            $isSelf = ($msg['user_id'] == $userId);
                            $roleLabel = '';
                            if ($msg['role'] === 'admin') $roleLabel = ' (Admin)';
                            elseif ($msg['role'] === 'hokim') $roleLabel = ' (Hokim)';
                            
                            // Render day divider if day changes
                            $msgDate = date('Y-m-d', strtotime($msg['created_at']));
                            if ($lastDate !== $msgDate) {
                                $lastDate = $msgDate;
                                $formattedDate = date('d.m.Y', strtotime($msg['created_at']));
                                if ($msgDate === date('Y-m-d')) {
                                    $formattedDate = "Bugun";
                                } elseif ($msgDate === date('Y-m-d', strtotime('-1 day'))) {
                                    $formattedDate = "Kecha";
                                }
                                ?>
                                <div class="chat-date-divider" style="text-align: center; margin: 16px 0; display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%;">
                                    <div style="flex: 1; height: 1px; background-color: var(--border-color); opacity: 0.3;"></div>
                                    <span style="font-size: 11px; font-weight: 700; color: var(--text-secondary); background-color: var(--card-bg); padding: 4px 12px; border-radius: 20px; border: 1px solid var(--border-color); text-transform: uppercase; letter-spacing: 0.5px;">
                                        <?php echo $formattedDate; ?>
                                    </span>
                                    <div style="flex: 1; height: 1px; background-color: var(--border-color); opacity: 0.3;"></div>
                                </div>
                                <?php
                            }
                            ?>
                            <?php
                            $prevUserId = $prevUserId ?? null;
                            $isFirstInGroup = ($msg['user_id'] != $prevUserId);
                            $prevUserId = $msg['user_id'];
                            $groupMarginTop = $isFirstInGroup ? '10px' : '2px';
                            ?>
                            <div class="chat-message-row <?php echo $isSelf ? 'self' : 'other'; ?>" style="display: flex; justify-content: <?php echo $isSelf ? 'flex-end' : 'flex-start'; ?>; width: 100%; margin-top: <?php echo $groupMarginTop; ?>; align-items: flex-end;">
                                <?php if (!$isSelf): ?>
                                    <div class="chat-avatar-container" style="width: 28px; height: 28px; margin-right: 8px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; margin-bottom: 2px;">
                                        <?php if ($isFirstInGroup): ?>
                                            <?php if ($msg['avatar'] && file_exists(__DIR__ . '/' . $msg['avatar'])): ?>
                                                <img src="<?php echo htmlspecialchars($msg['avatar']); ?>" style="width: 28px; height: 28px; border-radius: 50%; object-fit: cover;" title="<?php echo htmlspecialchars($msg['fio']); ?>">
                                            <?php else: ?>
                                                <div class="member-avatar" style="width: 28px; height: 28px; border-radius: 50%; background-color: var(--primary-glow); color: var(--primary-color); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 11px;" title="<?php echo htmlspecialchars($msg['fio']); ?>">
                                                    <?php 
                                                    $fParts = explode(' ', $msg['fio']);
                                                    echo mb_substr($fParts[0] ?? '', 0, 1) . mb_substr($fParts[1] ?? '', 0, 1);
                                                    ?>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <div class="chat-bubble <?php echo $isSelf ? 'self' : 'other'; ?>" 
                                     id="msg_<?php echo $msg['id']; ?>"
                                     data-id="<?php echo $msg['id']; ?>"
                                     data-sender="<?php echo htmlspecialchars($msg['fio']); ?>"
                                     data-message="<?php echo htmlspecialchars(strip_tags($msg['message'])); ?>">
                                    
                                    <!-- 3-dot dropdown menu container -->
                                    <div class="chat-bubble-menu-container" style="position: absolute; top: 4px; right: 4px; z-index: 10;">
                                        <a href="javascript:void(0);" onclick="toggleBubbleMenu(event, <?php echo $msg['id']; ?>)" class="bubble-menu-trigger" style="padding: 2px 4px; font-size: 11px;">
                                            <i class="fa-solid fa-ellipsis-vertical"></i>
                                        </a>
                                        <div id="bubble_menu_<?php echo $msg['id']; ?>" class="bubble-menu-dropdown" style="display: none; position: absolute; right: 0; top: 18px; background-color: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 6px; z-index: 100; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.3); min-width: 140px;">
                                            <a href="javascript:void(0);" onclick="replyToMessage(<?php echo $msg['id']; ?>, <?php echo htmlspecialchars(json_encode($msg['fio'])); ?>, <?php echo htmlspecialchars(json_encode(strip_tags($msg['message']))); ?>)" style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; color: var(--text-primary); text-decoration: none; font-size: 13px; border-radius: 8px; transition: background-color 0.2s;"><i class="fa-solid fa-reply"></i> Javob berish</a>
                                            <?php if ($isSelf && !$msg['file_path']): ?>
                                                <a href="javascript:void(0);" onclick="editMessage(<?php echo $msg['id']; ?>, <?php echo htmlspecialchars(json_encode($msg['message'])); ?>)" style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; color: var(--text-primary); text-decoration: none; font-size: 13px; border-radius: 8px; transition: background-color 0.2s;"><i class="fa-solid fa-pen"></i> Tahrirlash</a>
                                            <?php endif; ?>
                                            <?php if ($isSelf || $userRole === 'admin'): ?>
                                                <a href="team.php?delete_msg=<?php echo $msg['id']; ?>" onclick="return confirm('Ushbu xabarni o\'chirishni tasdiqlaysizmi?')" style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; color: var(--danger); text-decoration: none; font-size: 13px; border-radius: 8px; transition: background-color 0.2s;"><i class="fa-solid fa-trash-can"></i> O'chirish</a>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Show sender name only for first in group of other people -->
                                    <?php if ($isFirstInGroup && !$isSelf): ?>
                                    <span class="chat-bubble-sender" style="display: block; font-weight: 700; font-size: 11px; color: #f59e0b; margin-bottom: 3px; cursor: pointer;" onclick="window.location.href='profile.php?id=<?php echo $msg['user_id']; ?>'">
                                        <?php echo htmlspecialchars($msg['fio']) . $roleLabel; ?>
                                    </span>
                                    <?php endif; ?>
                                    
                                    <!-- Reply Quote Block Rendering -->
                                    <?php if ($msg['orig_message'] !== null): ?>
                                        <?php 
                                        $origIsAppeal = (strpos($msg['orig_message'], 'Murojaat muhokamasi:') !== false);
                                        preg_match('/#([A-Z0-9\-\/]+)/', $msg['orig_message'], $origNum);
                                        $quoteText = $origIsAppeal 
                                            ? '📢 Murojaat #' . ($origNum[1] ?? '???')
                                            : mb_strimwidth(strip_tags($msg['orig_message']), 0, 60, '...');
                                        ?>
                                        <div class="chat-reply-quote" onclick="scrollToMessage(<?php echo $msg['reply_to_id']; ?>)">
                                            <span class="reply-quote-sender" style="color: #60a5fa;"><?php echo htmlspecialchars($msg['orig_fio']); ?></span>
                                            <div class="reply-quote-text">
                                                <?php echo htmlspecialchars($quoteText); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- File Rendering -->
                                    <?php if ($msg['file_path']): ?>
                                        <?php if ($msg['file_type'] === 'photo'): ?>
                                            <div class="chat-media-preview" style="display: block;">
                                                <a href="<?php echo htmlspecialchars($msg['file_path']); ?>" target="_blank">
                                                    <img src="<?php echo htmlspecialchars($msg['file_path']); ?>" alt="Fayl">
                                                </a>
                                            </div>
                                        <?php elseif ($msg['file_type'] === 'video'): ?>
                                            <div class="chat-media-preview" style="display: block;">
                                                <video controls>
                                                    <source src="<?php echo htmlspecialchars($msg['file_path']); ?>">
                                                </video>
                                            </div>
                                        <?php elseif ($msg['file_type'] === 'voice'): ?>
                                            <div style="margin-bottom: 8px; display: block;">
                                                <audio controls style="max-width: 100%;">
                                                    <source src="<?php echo htmlspecialchars($msg['file_path']); ?>">
                                                </audio>
                                            </div>
                                        <?php elseif ($msg['file_type'] === 'document'): ?>
                                            <div class="chat-doc-card" style="display: flex;">
                                                <i class="fa-solid fa-file-arrow-down" style="font-size: 20px;"></i>
                                                <div style="flex: 1; min-width: 0;">
                                                    <div style="font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                        <?php echo htmlspecialchars(basename($msg['file_path'])); ?>
                                                    </div>
                                                </div>
                                                <a href="<?php echo htmlspecialchars($msg['file_path']); ?>" download style="color: inherit; font-size: 16px;"><i class="fa-solid fa-circle-down"></i></a>
                                            </div>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <?php
                                    $isAppealMsg = (strpos($msg['message'], 'Murojaat muhokamasi:') !== false);
                                    if ($isAppealMsg):
                                        // Extract appeal info from the message
                                        preg_match('/#([A-Z0-9\-\/]+)/', $msg['message'], $numM);
                                        preg_match('/Murojaatchi:<\/b>\s*(.+?)\n/', $msg['message'], $fioM);
                                        preg_match('/Qisqacha mazmuni:<\/b>\s*(.+?)\n/', $msg['message'], $contM);
                                        preg_match('/href=[\'\"]([^\'\"]+)[\'\"]/', $msg['message'], $linkM);
                                        $appealNum = $numM[1] ?? '';
                                        $appealFio = trim($fioM[1] ?? '', '"“” ');
                                        $appealContent = trim($contM[1] ?? '', '"“” ');
                                        $appealLink = $linkM[1] ?? '#';
                                        $cardBg = $isSelf ? 'rgba(255,255,255,0.12)' : 'rgba(59,130,246,0.08)';
                                        $cardBorder = $isSelf ? 'rgba(255,255,255,0.25)' : 'rgba(59,130,246,0.3)';
                                        $accentColor = $isSelf ? '#fff' : 'var(--primary-color)';
                                    ?>
                                    <div style="background: <?php echo $cardBg; ?>; border: 1px solid <?php echo $cardBorder; ?>; border-radius: 12px; padding: 14px; width: 280px; max-width: 100%; margin-top: 4px; display: block;">
                                        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
                                            <div style="width: 32px; height: 32px; border-radius: 8px; background: linear-gradient(135deg, #3b82f6, #7c3aed); display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0;">📢</div>
                                            <div>
                                                <div style="font-size: 11px; font-weight: 700; opacity: 0.7; text-transform: uppercase; letter-spacing: 0.5px;">Murojaat muhokamasi</div>
                                                <div style="font-size: 13px; font-weight: 800; color: <?php echo $accentColor; ?>;">#<?php echo htmlspecialchars($appealNum); ?></div>
                                            </div>
                                        </div>
                                        <?php if ($appealFio): ?>
                                        <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 6px; font-size: 12px; opacity: 0.85;">
                                            <span style="font-size: 11px;">👤</span>
                                            <span style="font-weight: 600;"><?php echo htmlspecialchars($appealFio); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($appealContent): ?>
                                        <div style="font-size: 12px; opacity: 0.75; line-height: 1.5; margin-bottom: 10px; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;">
                                            "<?php echo htmlspecialchars($appealContent); ?>"
                                        </div>
                                        <?php endif; ?>
                                        <a href="<?php echo htmlspecialchars($appealLink); ?>" style="display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 700; color: <?php echo $isSelf ? '#fff' : 'var(--primary-color)'; ?>; text-decoration: none; background: <?php echo $isSelf ? 'rgba(255,255,255,0.15)' : 'rgba(59,130,246,0.12)'; ?>; padding: 6px 12px; border-radius: 8px; transition: opacity 0.2s;" onmouseover="this.style.opacity='0.8'" onmouseout="this.style.opacity='1'">
                                            <i class="fa-solid fa-arrow-up-right-from-square" style="font-size: 10px;"></i> Murojaatni batafsil ochish
                                        </a>
                                    </div>
                                    <?php else: ?>
                                    <?php 
                                    // Highlight and link mentions @First_Last
                                    $rawText = strip_tags($msg['message'], '<a><b><i><code>');
                                    // Build lookup map: lowercase_username_style -> user_id
                                    $mentionMap = [];
                                    foreach ($teamMembers as $tm_member) {
                                        $key = mb_strtolower(str_replace(' ', '_', $tm_member['fio']));
                                        $mentionMap[$key] = $tm_member['id'];
                                    }
                                    $highlightedText = preg_replace_callback('/@([A-Za-z0-9_]+)/', function($m) use ($mentionMap, $isSelf) {
                                        $tag = $m[1];
                                        $lookupKey = mb_strtolower($tag);
                                        $uid = $mentionMap[$lookupKey] ?? null;
                                        $name = str_replace('_', ' ', $tag);
                                        if ($uid) {
                                            $color = $isSelf ? '#ffffff' : 'var(--primary-color)';
                                            $bg    = $isSelf ? 'rgba(255,255,255,0.25)' : 'var(--primary-glow)';
                                            $border = $isSelf ? 'rgba(255,255,255,0.4)' : 'rgba(59,130,246,0.3)';
                                            return '<a href="profile.php?id=' . $uid . '" style="color:' . $color . '; font-weight:700; background:' . $bg . '; border:1px solid ' . $border . '; padding:1px 6px; border-radius:6px; text-decoration:none; display:inline-flex; align-items:center; gap:4px; font-size:12px;"><i class="fa-solid fa-at" style="font-size:9px;"></i>' . htmlspecialchars($name) . '</a>';
                                        }
                                        $color = $isSelf ? '#ffffff' : 'var(--text-primary)';
                                        $bg    = $isSelf ? 'rgba(255,255,255,0.15)' : 'rgba(255,255,255,0.05)';
                                        return '<span style="color:' . $color . '; font-weight:700; background:' . $bg . '; padding:1px 6px; border-radius:6px; font-size:12px;">@' . htmlspecialchars($name) . '</span>';
                                    }, $rawText);
                                    ?>
                                    <span class="chat-bubble-text"><?php echo nl2br($highlightedText); ?></span>
                                    <?php endif; ?>
                                    
                                    <span class="chat-bubble-time" title="<?php echo date('d.m.Y H:i:s', strtotime($msg['created_at'])); ?>">
                                        <?php echo date('H:i', strtotime($msg['created_at'])); ?>
                                        <?php if ($isSelf): ?>
                                            <i class="fa-solid fa-check-double" style="color: #4ade80; font-size: 8px;"></i>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Typing indicator banner -->
                <div id="typingIndicator" style="font-size: 12px; font-style: italic; color: var(--text-secondary); padding: 4px 24px; min-height: 20px;"></div>

                <!-- Message input form -->
                <div class="chat-input-area">
                    <!-- Mention Autocomplete Dropdown popup -->
                    <div id="mentionDropdown" class="mention-dropdown"></div>

                    <form action="team.php" method="POST" enctype="multipart/form-data" class="chat-form" id="chatForm">
                        <input type="hidden" name="edit_id" id="edit_id" value="">
                        <input type="hidden" name="reply_to_id" id="reply_to_id" value="">
                        
                        <!-- Paperclip icon input -->
                        <input type="file" name="chat_file" id="chat_file" style="display: none;" onchange="updateFileLabel()">
                        <label class="file-label" for="chat_file" id="file_btn" title="Fayl biriktirish">
                            <i class="fa-solid fa-paperclip"></i>
                        </label>
                        
                        <input class="chat-input" type="text" name="message_text" id="message_text" placeholder="Xabaringizni yozing (A'zoni belgilash uchun @ yozing)..." autocomplete="off" oninput="handleInput(this)">
                        
                        <button type="submit" name="submit_message" id="submit_btn" class="btn btn-primary" style="padding: 12px 20px;">
                            <i class="fa-solid fa-paper-plane"></i> Yuborish
                        </button>
                    </form>
                    
                    <!-- Display selected file label -->
                    <div id="file_info" style="font-size: 12px; color: var(--text-secondary); margin-top: 8px; display: none;">
                        <i class="fa-solid fa-file"></i> Biriktirilgan fayl: <span id="file_name" style="font-weight: 600;"></span>
                        <a href="javascript:void(0);" onclick="cancelFileSelection()" style="color: var(--danger); margin-left: 8px; text-decoration: none;"><i class="fa-solid fa-circle-xmark"></i></a>
                    </div>

                    <!-- Editing info bar -->
                    <div id="edit_info" style="font-size: 12px; color: var(--warning); margin-top: 8px; display: none; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-pen-to-square"></i> Xabarni tahrirlash rejasi...
                        <a href="javascript:void(0);" onclick="cancelEditMode()" style="color: var(--danger); text-decoration: none; font-weight: 600;"><i class="fa-solid fa-circle-xmark"></i> Bekor qilish</a>
                    </div>

                    <!-- Replying info bar -->
                    <div id="reply_info" style="font-size: 12px; color: var(--info); margin-top: 8px; display: none; align-items: center; gap: 8px; justify-content: space-between; width: 100%;">
                        <div style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 80%;">
                            <i class="fa-solid fa-reply"></i> <strong><span id="reply_sender"></span></strong>ga javob yozilmoqda: <span id="reply_text" style="font-style: italic;"></span>
                        </div>
                        <a href="javascript:void(0);" onclick="cancelReplyMode()" style="color: var(--danger); text-decoration: none; font-weight: 600;"><i class="fa-solid fa-circle-xmark"></i> Bekor qilish</a>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Render mention users list to JS -->
    <script>
        var teamMentions = <?php echo json_encode($mentionsList); ?>;
    </script>

    <!-- JavaScript to auto-scroll, handle file name label, message editing, replying, swiping and typing signals -->
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            var chatBox = document.getElementById("chatMessagesArea");
            chatBox.scrollTop = chatBox.scrollHeight;
            
            // Poll for typing users every 2 seconds
            setInterval(checkTypingStatus, 2000);

            // Close bubble menus on click outside
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.chat-bubble-menu-container')) {
                    document.querySelectorAll('.bubble-menu-dropdown').forEach(m => m.style.display = 'none');
                }
            });

            // Initialize Swipe-to-Reply mechanics on all chat bubbles
            setupSwipeToReply();
        });

        // 1. Toggle 3-dot dropdown menu
        function toggleBubbleMenu(e, msgId) {
            e.stopPropagation();
            var menu = document.getElementById('bubble_menu_' + msgId);
            var wasOpen = (menu.style.display === 'block');
            
            // Hide all other menus first
            document.querySelectorAll('.bubble-menu-dropdown').forEach(m => m.style.display = 'none');
            
            if (!wasOpen) {
                menu.style.display = 'block';
                
                // Get the viewport boundaries of the chat box
                var chatBox = document.getElementById("chatMessagesArea");
                var chatBoxRect = chatBox.getBoundingClientRect();
                
                // Temporarily show to read bounds accurately
                menu.style.visibility = 'hidden';
                menu.style.display = 'block';
                var menuRect = menu.getBoundingClientRect();
                menu.style.visibility = 'visible';
                
                // If the bottom of the menu would overflow the chat box, open it upwards!
                if (menuRect.bottom > chatBoxRect.bottom - 10) {
                    menu.style.top = 'auto';
                    menu.style.bottom = '18px';
                } else {
                    menu.style.top = '18px';
                    menu.style.bottom = 'auto';
                }
            }
        }

        // 2. Telegram-like Swipe/Drag-to-Reply Mechanics
        function setupSwipeToReply() {
            var bubbles = document.querySelectorAll('.chat-bubble');
            
            bubbles.forEach(function(bubble) {
                var startX = 0;
                var currentX = 0;
                var isDragging = false;
                var msgId = bubble.getAttribute('data-id');
                var sender = bubble.getAttribute('data-sender');
                var message = bubble.getAttribute('data-message');
                
                // MOUSE EVENTS
                bubble.addEventListener('mousedown', function(e) {
                    if (e.target.closest('.chat-bubble-menu-container') || e.target.closest('.chat-reply-quote') || e.target.closest('.chat-media-preview') || e.target.closest('a')) {
                        return; // Ignore if menu or links clicked
                    }
                    startX = e.clientX;
                    isDragging = true;
                    bubble.style.transition = 'none';
                });
                
                document.addEventListener('mousemove', function(e) {
                    if (!isDragging) return;
                    currentX = e.clientX - startX;
                    
                    // Allow swipe-left (negative currentX) and swipe-right (positive currentX)
                    // Capped at -70px and +70px
                    if (currentX < -70) currentX = -70;
                    if (currentX > 70) currentX = 70;
                    
                    bubble.style.transform = `translateX(${currentX}px)`;
                });
                
                document.addEventListener('mouseup', function(e) {
                    if (!isDragging) return;
                    isDragging = false;
                    bubble.style.transition = 'transform 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275)';
                    bubble.style.transform = 'translateX(0px)';
                    
                    // Trigger reply if swiped beyond 50px (either left or right)
                    if (Math.abs(currentX) > 50) {
                        replyToMessage(msgId, sender, message);
                        
                        // Tiny haptic visual flash
                        bubble.style.boxShadow = '0 0 10px rgba(59, 130, 246, 0.5)';
                        setTimeout(() => { bubble.style.boxShadow = ''; }, 300);
                    }
                    currentX = 0;
                });

                // TOUCH EVENTS (Mobile)
                bubble.addEventListener('touchstart', function(e) {
                    if (e.target.closest('.chat-bubble-menu-container') || e.target.closest('.chat-reply-quote') || e.target.closest('.chat-media-preview') || e.target.closest('a')) {
                        return;
                    }
                    startX = e.touches[0].clientX;
                    isDragging = true;
                    bubble.style.transition = 'none';
                });
                
                bubble.addEventListener('touchmove', function(e) {
                    if (!isDragging) return;
                    currentX = e.touches[0].clientX - startX;
                    if (currentX < -70) currentX = -70;
                    if (currentX > 70) currentX = 70;
                    bubble.style.transform = `translateX(${currentX}px)`;
                });
                
                bubble.addEventListener('touchend', function(e) {
                    if (!isDragging) return;
                    isDragging = false;
                    bubble.style.transition = 'transform 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275)';
                    bubble.style.transform = 'translateX(0px)';
                    
                    if (Math.abs(currentX) > 50) {
                        replyToMessage(msgId, sender, message);
                    }
                    currentX = 0;
                });
            });
        }

        // 3. File Upload Preview UI
        function updateFileLabel() {
            var input = document.getElementById('chat_file');
            var info = document.getElementById('file_info');
            var nameSpan = document.getElementById('file_name');
            var btn = document.getElementById('file_btn');
            
            if (input.files && input.files[0]) {
                nameSpan.textContent = input.files[0].name;
                info.style.display = 'block';
                btn.style.color = 'var(--success)';
                
                cancelEditMode();
                cancelReplyMode();
            }
        }

        function cancelFileSelection() {
            var input = document.getElementById('chat_file');
            var info = document.getElementById('file_info');
            var btn = document.getElementById('file_btn');
            
            input.value = '';
            info.style.display = 'none';
            btn.style.color = 'var(--text-secondary)';
        }

        // 4. Message Edit Mode UI
        function editMessage(id, text) {
            cancelFileSelection();
            cancelReplyMode();
            
            document.getElementById('edit_id').value = id;
            document.getElementById('message_text').value = text;
            document.getElementById('message_text').placeholder = "Tahrirlangan matnni kiriting...";
            document.getElementById('submit_btn').innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Saqlash';
            document.getElementById('edit_info').style.display = 'flex';
            document.getElementById('message_text').focus();
        }

        function cancelEditMode() {
            document.getElementById('edit_id').value = '';
            document.getElementById('message_text').value = '';
            document.getElementById('message_text').placeholder = "Xabaringizni yozing (A'zoni belgilash uchun @ yozing)...";
            document.getElementById('submit_btn').innerHTML = '<i class="fa-solid fa-paper-plane"></i> Yuborish';
            document.getElementById('edit_info').style.display = 'none';
        }

        // 5. Message Reply Mode UI
        function replyToMessage(id, sender, text) {
            cancelFileSelection();
            cancelEditMode();
            
            var shortText = text.length > 50 ? text.substring(0, 50) + "..." : text;
            
            document.getElementById('reply_to_id').value = id;
            document.getElementById('reply_sender').textContent = sender;
            document.getElementById('reply_text').textContent = shortText;
            document.getElementById('reply_info').style.display = 'flex';
            document.getElementById('message_text').focus();
        }

        function cancelReplyMode() {
            document.getElementById('reply_to_id').value = '';
            document.getElementById('reply_sender').textContent = '';
            document.getElementById('reply_text').textContent = '';
            document.getElementById('reply_info').style.display = 'none';
        }

        function scrollToMessage(id) {
            var el = document.getElementById("msg_" + id);
            if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                el.style.boxShadow = '0 0 15px var(--primary-color)';
                setTimeout(function() {
                    el.style.boxShadow = '';
                }, 2000);
            }
        }

        // 6. Mention Autocomplete Logic
        function handleInput(input) {
            sendTypingSignal();
            
            var text = input.value;
            var cursorPosition = input.selectionStart;
            var textBeforeCursor = text.substring(0, cursorPosition);
            
            // Find if there is an active '@' search term
            var match = textBeforeCursor.match(/@([a-zA-Z0-9_]*)$/);
            var dropdown = document.getElementById('mentionDropdown');
            
            if (match) {
                var query = match[1].toLowerCase();
                // Filter users matching query
                var matches = teamMentions.filter(m => m.username.toLowerCase().includes(query) || m.fio.toLowerCase().includes(query));
                
                if (matches.length > 0) {
                    renderMentionDropdown(matches, query, match.index, cursorPosition);
                } else {
                    dropdown.style.display = 'none';
                }
            } else {
                dropdown.style.display = 'none';
            }
        }

        function renderMentionDropdown(users, query, startIndex, cursorPosition) {
            var dropdown = document.getElementById('mentionDropdown');
            dropdown.innerHTML = '';
            dropdown.style.display = 'block';
            
            users.forEach(function(u) {
                var el = document.createElement('div');
                el.className = 'mention-item';
                el.innerHTML = `
                    <span class="mention-username">@${u.username}</span>
                    <span class="mention-fio">${u.fio}</span>
                    <span class="mention-role">${u.role}</span>
                `;
                
                el.onclick = function() {
                    insertMention(u.username, startIndex, cursorPosition);
                };
                
                dropdown.appendChild(el);
            });
        }

        function insertMention(username, startIndex, cursorPosition) {
            var input = document.getElementById('message_text');
            var text = input.value;
            
            var before = text.substring(0, startIndex);
            var after = text.substring(cursorPosition);
            
            input.value = before + '@' + username + ' ' + after;
            input.focus();
            
            // Set cursor position after the inserted mention + space
            var newCursorPos = startIndex + username.length + 2; 
            input.setSelectionRange(newCursorPos, newCursorPos);
            
            document.getElementById('mentionDropdown').style.display = 'none';
        }

        // 7. Typing Indicator Ajax Logic
        var lastSignalTime = 0;
        function sendTypingSignal() {
            var now = Date.now();
            if (now - lastSignalTime > 2000) {
                lastSignalTime = now;
                fetch('update_typing.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.status !== 'success') {
                            console.error("Typing status error:", data);
                        }
                    })
                    .catch(err => console.error("Typing signal failed:", err));
            }
        }

        function checkTypingStatus() {
            fetch('chat_typing_status.php')
                .then(response => response.json())
                .then(data => {
                    var indicator = document.getElementById('typingIndicator');
                    if (data.status === 'success' && data.users.length > 0) {
                        var names = data.users.map(u => u.fio).join(', ');
                        indicator.textContent = names + " yozmoqda...";
                    } else {
                        indicator.textContent = "";
                    }
                })
                .catch(err => console.error("Typing status fetch failed:", err));
        }

        // 8. Copy Invite Link functionality
        function copyInviteLink() {
            var copyText = document.getElementById("inviteLinkInput");
            copyText.select();
            copyText.setSelectionRange(0, 99999); // For mobile devices
            navigator.clipboard.writeText(copyText.value);
            
            var successMsg = document.getElementById("copySuccessMsg");
            if (successMsg) {
                successMsg.style.display = "block";
                setTimeout(function() {
                    successMsg.style.display = "none";
                }, 2500);
            }
        }

        // 9. Top Toolbar Dropdowns Toggle Logic
        function toggleTopDropdown(id) {
            var dropdowns = document.querySelectorAll('.top-dropdown-content');
            dropdowns.forEach(function(d) {
                if (d.id !== id) {
                    d.classList.remove('show');
                }
            });
            var active = document.getElementById(id);
            if (active) {
                active.classList.toggle('show');
            }
        }

        window.addEventListener('click', function(e) {
            if (!e.target.closest('.top-dropdown')) {
                var dropdowns = document.querySelectorAll('.top-dropdown-content');
                dropdowns.forEach(function(d) {
                    d.classList.remove('show');
                });
            }
        });

        // 10. Rename Team UI helpers
        function showRenameInput() {
            var container = document.getElementById('teamNameContainer');
            var form = document.getElementById('renameTeamForm');
            if (container && form) {
                container.style.setProperty('display', 'none', 'important');
                form.style.setProperty('display', 'flex', 'important');
                form.querySelector('input[type=\"text\"]').focus();
            }
        }
        function cancelRename() {
            var container = document.getElementById('teamNameContainer');
            var form = document.getElementById('renameTeamForm');
            if (container && form) {
                container.style.setProperty('display', 'flex', 'important');
                form.style.setProperty('display', 'none', 'important');
            }
        }
    </script>
</body>
</html>
