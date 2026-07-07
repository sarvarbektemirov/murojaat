<?php
/**
 * View Appeal Details - view_appeal.php
 */
require_once 'config.php';
check_auth();

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];
$appealId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$appealId) {
    die("Noto'g'ri murojaat ID raqami.");
}

// Fetch appeal details
try {
    $stmt = $pdo->prepare("
        SELECT a.*, w.fio as masul_name, w.department as masul_dept, bu.username as telegram_user
        FROM appeals a 
        LEFT JOIN web_users w ON a.assigned_to = w.id 
        LEFT JOIN bot_users bu ON a.user_id = bu.id
        WHERE a.id = :id
    ");
    $stmt->execute(['id' => $appealId]);
    $appeal = $stmt->fetch();

    if (!$appeal) {
        die("Murojaat topilmadi.");
    }

    // Mas'ul can only view appeals assigned to them
    if ($userRole === 'masul' && $appeal['assigned_to'] != $userId) {
        die("Ruxsat etilmagan sahifa. Ushbu murojaat sizga biriktirilmagan.");
    }

    // Fetch appeal media files
    $filesStmt = $pdo->prepare("SELECT * FROM appeal_files WHERE appeal_id = :appeal_id");
    $filesStmt->execute(['appeal_id' => $appealId]);
    $appealFiles = $filesStmt->fetchAll();

    // Fetch list of mas'ullar for admin assignment
    $masullar = [];
    if ($userRole === 'admin') {
        $masulStmt = $pdo->query("SELECT id, fio, department, role FROM web_users ORDER BY id DESC");
        $masullar = $masulStmt->fetchAll();
    }
} catch (PDOException $e) {
    die("Xatolik yuz berdi: " . $e->getMessage());
}

/**
 * Downloads and caches a media file from Telegram Bot API to local storage
 */
function get_cached_telegram_file($file_id, $file_type) {
    $dir = __DIR__ . '/uploads/telegram_media/';
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
    
    // Check cache
    $files = glob($dir . $file_id . '.*');
    if (!empty($files)) {
        return 'uploads/telegram_media/' . basename($files[0]);
    }
    
    // Download
    $token = BOT_TOKEN;
    $url = "https://api.telegram.org/bot{$token}/getFile?file_id={$file_id}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $res = curl_exec($ch);
    curl_close($ch);
    
    if (!$res) return null;
    
    $json = json_decode($res, true);
    if (!isset($json['ok']) || !$json['ok']) return null;
    
    $file_path = $json['result']['file_path'];
    $ext = pathinfo($file_path, PATHINFO_EXTENSION);
    if (empty($ext)) {
        if ($file_type === 'photo') $ext = 'jpg';
        elseif ($file_type === 'voice') $ext = 'ogg';
        elseif ($file_type === 'video') $ext = 'mp4';
        else $ext = 'bin';
    }
    
    $local_name = $file_id . '.' . $ext;
    $local_path = $dir . $local_name;
    
    $download_url = "https://api.telegram.org/file/bot{$token}/{$file_path}";
    
    $fp = fopen($local_path, 'w+');
    $ch = curl_init($download_url);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_exec($ch);
    curl_close($ch);
    fclose($fp);
    
    return 'uploads/telegram_media/' . $local_name;
}

$successMsg = '';
$errorMsg = '';

// Handle Post Requests (Assignment, Status Change, Response Submission)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Handle Assignment (Admin Only)
    if (isset($_POST['assign_masul']) && $userRole === 'admin') {
        $assignedTo = filter_input(INPUT_POST, 'assigned_to', FILTER_VALIDATE_INT);
        $deadline = trim($_POST['deadline'] ?? '');
        $deadline = (!empty($deadline)) ? $deadline : null;
        if ($assignedTo) {
            try {
                $upStmt = $pdo->prepare("UPDATE appeals SET assigned_to = :assigned_to, deadline = :deadline, status = 'masul_tayinlandi' WHERE id = :id");
                $upStmt->execute(['assigned_to' => $assignedTo, 'deadline' => $deadline, 'id' => $appealId]);
                
                header("Location: view_appeal.php?id=$appealId&success=Murojaat mas'ul xodimga muvaffaqiyatli yo'naltirildi!");
                exit;
            } catch (PDOException $e) {
                $errorMsg = "Yo'naltirishda xatolik: " . $e->getMessage();
            }
        }
    }

    // 2. Handle Status Change (Admin and Mas'ul)
    if (isset($_POST['change_status']) && ($userRole === 'admin' || $userRole === 'masul')) {
        $newStatus = trim($_POST['status'] ?? '');
        if (in_array($newStatus, ['yangi', 'masul_tayinlandi', 'korib_chiqildi', 'rad_etildi'])) {
            try {
                $upStmt = $pdo->prepare("UPDATE appeals SET status = :status WHERE id = :id");
                $upStmt->execute(['status' => $newStatus, 'id' => $appealId]);
                
                header("Location: view_appeal.php?id=$appealId&success=Murojaat holati yangilandi!");
                exit;
            } catch (PDOException $e) {
                $errorMsg = "Status yangilashda xatolik: " . $e->getMessage();
            }
        }
    }

    // 3. Handle Response Submission (Admin and Mas'ul)
    if (isset($_POST['submit_response']) && ($userRole === 'admin' || $userRole === 'masul')) {
        $responseText = trim($_POST['response_text'] ?? '');
        
        if (empty($responseText)) {
            $errorMsg = "Iltimos, javob matnini kiriting.";
        } else {
            $responseFileName = ($userRole === 'admin') ? $appeal['response_file'] : $appeal['draft_response_file'];
            
            // Check file upload
            if (isset($_FILES['response_file']) && $_FILES['response_file']['error'] === UPLOAD_ERR_OK) {
                $fileTmpPath = $_FILES['response_file']['tmp_name'];
                $fileName = $_FILES['response_file']['name'];
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                
                // Allow only pdf, png, jpg, docx
                $allowedExtensions = ['pdf', 'png', 'jpg', 'jpeg', 'docx'];
                if (in_array($fileExtension, $allowedExtensions)) {
                    $uploadDir = __DIR__ . '/uploads/responses/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    
                    $newFileName = 'javob_' . $appeal['appeal_number'] . '_' . time() . '.' . $fileExtension;
                    $newFileName = str_replace('/', '_', $newFileName);
                    $destPath = $uploadDir . $newFileName;
                    
                    if (move_uploaded_file($fileTmpPath, $destPath)) {
                        $responseFileName = 'uploads/responses/' . $newFileName;
                    } else {
                        $errorMsg = "Javob faylini yuklashda xatolik yuz berdi.";
                    }
                } else {
                    $errorMsg = "Noto'g'ri fayl formati. Faqat PDF, PNG, JPG va DOCX ruxsat etiladi.";
                }
            }
            
            if (empty($errorMsg)) {
                try {
                    if ($userRole === 'admin') {
                        // Admin submits directly
                        $upStmt = $pdo->prepare("
                            UPDATE appeals 
                            SET response_text = :text, response_file = :file, responded_at = NOW(), status = 'korib_chiqildi',
                                draft_response_text = NULL, draft_response_file = NULL, admin_feedback = NULL
                            WHERE id = :id
                        ");
                        $upStmt->execute([
                            'text' => $responseText,
                            'file' => $responseFileName,
                            'id' => $appealId
                        ]);
                        
                        // Send Telegram notification
                        $message = (
                            "🔔 <b>Sizning murojaatingiz ko'rib chiqildi!</b>\n\n" .
                            "🆔 <b>Murojaat tartib raqami:</b> <code>" . htmlspecialchars($appeal['appeal_number']) . "</code>\n" .
                            "📅 <b>Ko'rib chiqilgan sana:</b> " . date('d.m.Y H:i') . "\n\n" .
                            "💬 <b>Hokimlik javobi:</b>\n" .
                            "<i>" . htmlspecialchars($responseText) . "</i>"
                        );
                        $fullFilePath = $responseFileName ? __DIR__ . '/' . $responseFileName : null;
                        send_telegram_notification($appeal['user_id'], $message, $fullFilePath);
                        
                        header("Location: view_appeal.php?id=$appealId&success=Javob muvaffaqiyatli saqlandi va foydalanuvchiga Telegram orqali yuborildi!");
                        exit;
                    } else {
                        // Mas'ul submits a DRAFT
                        $upStmt = $pdo->prepare("
                            UPDATE appeals 
                            SET draft_response_text = :text, draft_response_file = :file, status = 'tasdiqlash_kutilmoqda', admin_feedback = NULL
                            WHERE id = :id
                        ");
                        $upStmt->execute([
                            'text' => $responseText,
                            'file' => $responseFileName,
                            'id' => $appealId
                        ]);
                        
                        header("Location: view_appeal.php?id=$appealId&success=Javob loyihasi (qoralama) saqlandi va tasdiqlash uchun Adminga yuborildi!");
                        exit;
                    }
                } catch (PDOException $e) {
                    $errorMsg = "Javobni saqlashda xatolik: " . $e->getMessage();
                }
            }
        }
    }

    // 5. Handle Admin Approval
    if (isset($_POST['approve_draft']) && $userRole === 'admin') {
        try {
            $upStmt = $pdo->prepare("
                UPDATE appeals 
                SET response_text = draft_response_text, response_file = draft_response_file, responded_at = NOW(), status = 'korib_chiqildi',
                    draft_response_text = NULL, draft_response_file = NULL, admin_feedback = NULL
                WHERE id = :id
            ");
            $upStmt->execute(['id' => $appealId]);
            
            // Re-fetch to get the copied response details
            $reStmt = $pdo->prepare("SELECT * FROM appeals WHERE id = :id");
            $reStmt->execute(['id' => $appealId]);
            $updatedAppeal = $reStmt->fetch();
            
            // Send Telegram notification
            $message = (
                "🔔 <b>Sizning murojaatingiz ko'rib chiqildi!</b>\n\n" .
                "🆔 <b>Murojaat tartib raqami:</b> <code>" . htmlspecialchars($updatedAppeal['appeal_number']) . "</code>\n" .
                "📅 <b>Ko'rib chiqilgan sana:</b> " . date('d.m.Y H:i') . "\n\n" .
                "💬 <b>Hokimlik javobi:</b>\n" .
                "<i>" . htmlspecialchars($updatedAppeal['response_text']) . "</i>"
            );
            $fullFilePath = $updatedAppeal['response_file'] ? __DIR__ . '/' . $updatedAppeal['response_file'] : null;
            send_telegram_notification($updatedAppeal['user_id'], $message, $fullFilePath);
            
            header("Location: view_appeal.php?id=$appealId&success=Javob loyihasi muvaffaqiyatli tasdiqlandi va Telegram orqali yuborildi!");
            exit;
        } catch (PDOException $e) {
            $errorMsg = "Tasdiqlashda xatolik: " . $e->getMessage();
        }
    }

    // 6. Handle Admin Rejection (Return for Rework)
    if (isset($_POST['reject_draft']) && $userRole === 'admin') {
        $feedback = trim($_POST['admin_feedback'] ?? '');
        if (empty($feedback)) {
            $errorMsg = "Iltimos, qaytarish sababini kiriting.";
        } else {
            try {
                $upStmt = $pdo->prepare("
                    UPDATE appeals 
                    SET status = 'qaytarildi', admin_feedback = :feedback 
                    WHERE id = :id
                ");
                $upStmt->execute(['feedback' => $feedback, 'id' => $appealId]);
                
                header("Location: view_appeal.php?id=$appealId&success=Javob loyihasi tuzatish uchun mas'ulga qaytarildi!");
                exit;
            } catch (PDOException $e) {
                $errorMsg = "Qaytarishda xatolik: " . $e->getMessage();
            }
        }
    }

    // 4. Handle Sharing to Team Chat
    if (isset($_POST['share_chat'])) {
        try {
            $short_content = mb_strimwidth($appeal['content'], 0, 180, "...");
            $share_message = "📢 <b>Murojaat muhokamasi:</b> #" . $appeal['appeal_number'] . "\n\n" .
                             "👤 <b>Murojaatchi:</b> " . $appeal['fio'] . "\n\n" .
                             "📝 <b>Qisqacha mazmuni:</b> \"" . $short_content . "\"\n\n" .
                             "<a href='view_appeal.php?id=" . $appealId . "'>🔍 Murojaatni batafsil ochish</a>";
            
            $activeTeamId = $_SESSION['active_team_id'] ?? 1;
            
            $stmt = $pdo->prepare("INSERT INTO team_messages (user_id, message, team_id) VALUES (:user_id, :message, :team_id)");
            $stmt->execute([
                'user_id' => $userId,
                'message' => $share_message,
                'team_id' => $activeTeamId
            ]);
            
            header("Location: view_appeal.php?id=$appealId&success=Murojaat jamoa chatiga muhokama uchun yuborildi!");
            exit;
        } catch (PDOException $e) {
            $errorMsg = "Chatga yuborishda xatolik: " . $e->getMessage();
        }
    }
}

// Read success msg from redirect URL
if (isset($_GET['success'])) {
    $successMsg = $_GET['success'];
}
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Murojaat Tafsilotlari #<?php echo htmlspecialchars($appeal['appeal_number']); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Sidebar navigation -->
    <?php include_once 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <div>
                <a href="appeals.php" class="btn btn-outline btn-sm" style="margin-bottom: 12px;">
                    <i class="fa-solid fa-arrow-left"></i> Ro'yxatga qaytish
                </a>
                <h1 class="page-title">Murojaat Tafsilotlari</h1>
                <div class="page-subtitle">Tartib raqam: <strong><?php echo htmlspecialchars($appeal['appeal_number']); ?></strong></div>
            </div>
            <div style="display: flex; gap: 12px; align-items: center;">
                <form action="view_appeal.php?id=<?php echo $appeal['id']; ?>" method="POST" style="margin: 0;">
                    <button type="submit" name="share_chat" class="btn btn-outline">
                        <i class="fa-solid fa-comments"></i> Jamoa chatiga yuborish
                    </button>
                </form>
                <a href="print_appeal.php?id=<?php echo $appeal['id']; ?>" target="_blank" class="btn btn-primary">
                    <i class="fa-solid fa-print"></i> Murojaat xatini yaratish (Print)
                </a>
            </div>
        </div>

        <?php if (!empty($successMsg)): ?>
            <div style="background-color: var(--success-bg); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.2); padding: 16px; border-radius: 12px; margin-bottom: 24px; font-weight: 500;">
                <i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($successMsg); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errorMsg)): ?>
            <div style="background-color: var(--danger-bg); color: var(--danger); border: 1px solid rgba(239, 68, 68, 0.2); padding: 16px; border-radius: 12px; margin-bottom: 24px; font-weight: 500;">
                <i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($errorMsg); ?>
            </div>
        <?php endif; ?>

        <div class="detail-grid">
            <!-- Left Side: Appeal Details -->
            <div>
                <!-- 1. General Info -->
                <div class="detail-section">
                    <h3 class="section-title"><i class="fa-solid fa-user"></i> Murojaatchi ma'lumotlari</h3>
                    <div class="info-list">
                        <div class="info-item">
                            <span class="info-label">Murojaat etuvchi F.I.Sh</span>
                            <span class="info-value"><?php echo htmlspecialchars($appeal['fio']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Telefon raqami</span>
                            <span class="info-value"><?php echo htmlspecialchars($appeal['phone_1']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Qo'shimcha telefon</span>
                            <span class="info-value"><?php echo $appeal['phone_2'] ? htmlspecialchars($appeal['phone_2']) : '<em style="color: var(--text-secondary);">Kiritilmagan</em>'; ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Tashkilot/Yuridik shaxs nomi</span>
                            <span class="info-value"><?php echo $appeal['company_name'] ? htmlspecialchars($appeal['company_name']) : '<em style="color: var(--text-secondary);">Fuqaro (Jismoniy shaxs)</em>'; ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Telegram Profil</span>
                            <span class="info-value">
                                <?php if ($appeal['telegram_profile']): ?>
                                    <a href="https://t.me/<?php echo str_replace('@', '', $appeal['telegram_profile']); ?>" target="_blank" style="color: var(--primary-color); text-decoration: none;">
                                        <?php echo htmlspecialchars($appeal['telegram_profile']); ?>
                                    </a>
                                <?php else: ?>
                                    <em style="color: var(--text-secondary);">Kiritilmagan</em>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Yashash Manzili</span>
                            <span class="info-value"><?php echo htmlspecialchars($appeal['address']); ?></span>
                        </div>
                    </div>
                </div>

                <!-- 2. Content and Attachments -->
                <div class="detail-section">
                    <h3 class="section-title"><i class="fa-solid fa-comment-dots"></i> Murojaat mazmuni va Fayllar</h3>
                    <div class="info-item" style="margin-bottom: 24px;">
                        <span class="info-label">Murojaat matni</span>
                        <div class="info-value-block">
                            <?php echo nl2br(htmlspecialchars($appeal['content'])); ?>
                        </div>
                    </div>

                    <span class="info-label">Biriktirilgan media fayllar</span>
                    <?php if (empty($appealFiles)): ?>
                        <p style="color: var(--text-secondary); font-size: 14px; margin-top: 8px;">Fayllar yuklanmagan.</p>
                    <?php else: ?>
                        <div style="margin-top: 10px; display: flex; flex-direction: column; gap: 16px;">
                            <?php foreach ($appealFiles as $file): ?>
                                <?php 
                                $localFile = get_cached_telegram_file($file['file_id'], $file['file_type']); 
                                ?>
                                <?php if ($localFile): ?>
                                    <?php if ($file['file_type'] === 'photo'): ?>
                                        <div style="max-width: 300px;">
                                            <a href="<?php echo $localFile; ?>" target="_blank">
                                                <img src="<?php echo $localFile; ?>" alt="Murojaat fayli" style="width: 100%; border-radius: 12px; border: 1px solid var(--border-color);">
                                            </a>
                                        </div>
                                    <?php elseif ($file['file_type'] === 'voice'): ?>
                                        <div class="audio-player">
                                            <i class="fa-solid fa-microphone" style="color: var(--info); font-size: 20px;"></i>
                                            <audio controls>
                                                <source src="<?php echo $localFile; ?>" type="audio/ogg">
                                                Brauzeringiz ovoz tinglashni qo'llab-quvvatlamaydi.
                                            </audio>
                                        </div>
                                    <?php elseif ($file['file_type'] === 'video'): ?>
                                        <div style="max-width: 400px;">
                                            <video controls style="width: 100%; border-radius: 12px; border: 1px solid var(--border-color);">
                                                <source src="<?php echo $localFile; ?>" type="video/mp4">
                                                Brauzeringiz video qo'llab-quvvatlamaydi.
                                            </video>
                                        </div>
                                    <?php elseif ($file['file_type'] === 'document'): ?>
                                        <div style="background-color: rgba(255,255,255,0.03); border: 1px solid var(--border-color); padding: 12px 18px; border-radius: 12px; display: flex; align-items: center; justify-content: space-between;">
                                            <span style="font-size: 14px; font-weight: 500;">
                                                <i class="fa-solid fa-file-pdf" style="color: var(--danger); margin-right: 8px;"></i>
                                                Biriktirilgan Hujjat (Fayl)
                                            </span>
                                            <a href="<?php echo $localFile; ?>" download class="btn btn-outline btn-sm">
                                                <i class="fa-solid fa-download"></i> Yuklab olish
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <p style="color: var(--danger); font-size: 13px;">Faylni Telegramdan yuklash amalga oshmadi (API File ID: <?php echo htmlspecialchars(substr($file['file_id'], 0, 15)); ?>...)</p>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 3. Output Response (Admin & Mas'ul view / Hokim read-only) -->
                <div class="detail-section">
                    <h3 class="section-title"><i class="fa-solid fa-reply"></i> Murojaatga javob xati tayyorlash</h3>
                    
                    <?php if ($appeal['response_text']): ?>
                        <div style="background-color: rgba(16, 185, 129, 0.05); border: 1px solid rgba(16, 185, 129, 0.2); padding: 20px; border-radius: 16px; margin-bottom: 24px;">
                            <span class="info-label" style="color: var(--success); font-weight: 700; margin-bottom: 8px; display: block;">YUBORILGAN JAVOB MATNI:</span>
                            <p style="font-size: 15px; line-height: 1.6; color: var(--text-primary);"><?php echo nl2br(htmlspecialchars($appeal['response_text'])); ?></p>
                            <?php if ($appeal['responded_at']): ?>
                                <span style="font-size: 12px; color: var(--text-secondary); margin-top: 8px; display: block;">Javob berilgan sana: <?php echo date('d.m.Y H:i', strtotime($appeal['responded_at'])); ?></span>
                            <?php endif; ?>
                            
                            <?php if ($appeal['response_file']): ?>
                                <div style="margin-top: 16px; background-color: rgba(255,255,255,0.03); border: 1px solid var(--border-color); padding: 12px; border-radius: 12px; display: flex; align-items: center; justify-content: space-between;">
                                    <span style="font-size: 14px;">
                                        <i class="fa-solid fa-paperclip"></i> Biriktirilgan javob xati 
                                        <?php if ($appeal['responded_at']): ?>
                                            <span style="font-size: 12px; color: var(--text-secondary); margin-left: 6px;">(<?php echo date('d.m.Y H:i', strtotime($appeal['responded_at'])); ?>)</span>
                                        <?php endif; ?>
                                    </span>
                                    <a href="<?php echo htmlspecialchars($appeal['response_file']); ?>" download class="btn btn-outline btn-sm"><i class="fa-solid fa-download"></i> Yuklab olish</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($userRole !== 'hokim'): ?>
                        <!-- 1. Display draft if awaiting approval -->
                        <?php if ($appeal['status'] === 'tasdiqlash_kutilmoqda'): ?>
                            <div style="background-color: rgba(6, 182, 212, 0.05); border: 1px solid rgba(6, 182, 212, 0.2); padding: 20px; border-radius: 16px; margin-bottom: 24px;">
                                <span class="info-label" style="color: var(--info); font-weight: 700; margin-bottom: 8px; display: block;">TASDIQLASH KUTILAYOTGAN JAVOB LOYIHASI:</span>
                                <p style="font-size: 15px; line-height: 1.6; color: var(--text-primary);"><?php echo nl2br(htmlspecialchars($appeal['draft_response_text'])); ?></p>
                                
                                <?php if ($appeal['draft_response_file']): ?>
                                    <div style="margin-top: 16px; background-color: rgba(255,255,255,0.03); border: 1px solid var(--border-color); padding: 12px; border-radius: 12px; display: flex; align-items: center; justify-content: space-between;">
                                        <span style="font-size: 14px;"><i class="fa-solid fa-paperclip"></i> Biriktirilgan javob xati</span>
                                        <a href="<?php echo htmlspecialchars($appeal['draft_response_file']); ?>" download class="btn btn-outline btn-sm"><i class="fa-solid fa-download"></i> Yuklab olish</a>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if ($userRole === 'admin'): ?>
                                <!-- Admin Approval Form -->
                                <form action="view_appeal.php?id=<?php echo $appealId; ?>" method="POST" style="margin-bottom: 24px;">
                                    <button type="submit" name="approve_draft" class="btn btn-primary" style="background-color: var(--success); box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3); width: 100%; justify-content: center; padding: 12px;">
                                        <i class="fa-solid fa-circle-check"></i> Tasdiqlash va Telegramga yuborish
                                    </button>
                                </form>

                                <form action="view_appeal.php?id=<?php echo $appealId; ?>" method="POST" style="border-top: 1px solid var(--border-color); padding-top: 20px;">
                                    <div class="form-group" style="margin-bottom: 16px;">
                                        <label class="form-label" for="admin_feedback">Qayta ishlashga qaytarish sababi / Izoh</label>
                                        <textarea class="form-control" id="admin_feedback" name="admin_feedback" rows="3" placeholder="Tuzatilishi kerak bo'lgan joylar bo'yicha mas'ulga izoh qoldiring..." required style="width: 100%; resize: vertical;"></textarea>
                                    </div>
                                    <button type="submit" name="reject_draft" class="btn btn-danger" style="width: 100%; justify-content: center; padding: 12px;">
                                        <i class="fa-solid fa-circle-xmark"></i> Tuzatishga qaytarish
                                    </button>
                                </form>
                            <?php else: ?>
                                <div style="color: var(--info); font-weight: 500; font-size: 14px; text-align: center; padding: 16px; background: rgba(6, 182, 212, 0.05); border-radius: 12px; border: 1px solid rgba(6, 182, 212, 0.2);">
                                    <i class="fa-solid fa-clock"></i> Javob loyihasi saqlandi. Admin tasdiqlagandan so'ng bot orqali yuboriladi.
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>

                        <!-- 2. Display return feedback if returned -->
                        <?php if ($appeal['status'] === 'qaytarildi'): ?>
                            <div style="background-color: rgba(239, 68, 68, 0.05); border: 1px solid rgba(239, 68, 68, 0.2); padding: 20px; border-radius: 16px; margin-bottom: 24px; color: var(--danger);">
                                <span class="info-label" style="color: var(--danger); font-weight: 700; margin-bottom: 8px; display: block;"><i class="fa-solid fa-triangle-exclamation"></i> ADMIN TOMONIDAN RAD ETILDI (QAYTA ISHLASHGA):</span>
                                <p style="font-size: 15px; line-height: 1.6; font-style: italic;">"<?php echo htmlspecialchars($appeal['admin_feedback']); ?>"</p>
                            </div>
                        <?php endif; ?>

                        <!-- 3. Show edit/response form -->
                        <?php if ($appeal['status'] !== 'tasdiqlash_kutilmoqda' && !$appeal['response_text']): ?>
                            <form action="view_appeal.php?id=<?php echo $appealId; ?>" method="POST" enctype="multipart/form-data">
                                <div class="form-group" style="margin-bottom: 20px;">
                                    <label class="form-label" for="response_text">Javob matni <?php echo ($userRole === 'masul') ? '(Tasdiqlash uchun yuboriladi)' : '(Bot orqali ketadi)'; ?></label>
                                    <textarea class="form-control" id="response_text" name="response_text" rows="6" placeholder="Murojaat bo'yicha javob xulosasini bu yerga yozing..." required style="width: 100%; resize: vertical;"><?php echo $appeal['status'] === 'qaytarildi' ? htmlspecialchars($appeal['draft_response_text']) : ''; ?></textarea>
                                </div>
                                
                                <div class="form-group" style="margin-bottom: 24px;">
                                    <label class="form-label" for="response_file">Rasmiy tasdiqlangan javob xati (Fayl, PDF/PNG/JPG/DOCX, Majburiy emas)</label>
                                    <input class="form-control" type="file" id="response_file" name="response_file" style="width: 100%;">
                                    <?php if ($appeal['status'] === 'qaytarildi' && $appeal['draft_response_file']): ?>
                                        <span style="font-size: 12px; color: var(--text-secondary); margin-top: 4px; display: block;">Avvalgi yuklangan: <code><?php echo basename($appeal['draft_response_file']); ?></code></span>
                                    <?php endif; ?>
                                </div>
                                
                                <button type="submit" name="submit_response" class="btn btn-primary">
                                    <i class="fa-solid fa-paper-plane"></i> <?php echo ($userRole === 'masul') ? "Tasdiqlashga yuborish" : "Javobni yuborish"; ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <p style="color: var(--text-secondary); font-size: 14px;">Hokim faqat monitoring rejimida ishlaydi, javob yozish huquqi yo'q.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Side: Workflow Management (Status & Assignment) -->
            <div>
                <!-- 1. Metadata and Status -->
                <div class="detail-section">
                    <h3 class="section-title"><i class="fa-solid fa-gears"></i> Jarayon holati</h3>
                    <div style="margin-bottom: 24px;">
                        <span class="info-label">Murojaat holati:</span>
                        <div style="margin-top: 8px;">
                            <span class="badge <?php echo htmlspecialchars($appeal['status']); ?>" style="font-size: 14px; padding: 8px 16px;">
                                <?php 
                                if ($appeal['status'] === 'yangi') echo "Yangi (Kutilmoqda)";
                                elseif ($appeal['status'] === 'masul_tayinlandi') echo "Jarayonda";
                                elseif ($appeal['status'] === 'tasdiqlash_kutilmoqda') echo "Tasdiqlash kutilmoqda";
                                elseif ($appeal['status'] === 'qaytarildi') echo "Qayta ishlashda";
                                elseif ($appeal['status'] === 'korib_chiqildi') echo "Bajarildi";
                                elseif ($appeal['status'] === 'rad_etildi') echo "Rad etildi";
                                ?>
                            </span>
                        </div>
                    </div>

                    <div style="margin-bottom: 24px; display: flex; flex-direction: column; gap: 8px;">
                        <div>
                            <span class="info-label">Kelib tushgan sana va vaqt:</span>
                            <p style="font-size: 14px; font-weight: 600; margin-top: 2px;"><?php echo date('d.m.Y H:i:s', strtotime($appeal['submitted_at'])); ?></p>
                        </div>
                        <div style="margin-top: 10px;">
                            <span class="info-label">Tashkilotda qayd etilgan vaqt (avtomatik):</span>
                            <p style="font-size: 14px; font-weight: 600; margin-top: 2px; color: var(--success);"><?php echo date('d.m.Y H:i:s', strtotime($appeal['received_at'])); ?></p>
                        </div>
                    </div>

                    <!-- Change status form (Admin/Mas'ul only) -->
                    <?php if ($userRole !== 'hokim'): ?>
                        <form action="view_appeal.php?id=<?php echo $appealId; ?>" method="POST" style="border-top: 1px solid var(--border-color); padding-top: 20px;">
                            <div class="form-group" style="margin-bottom: 16px;">
                                <label class="form-label" for="status">Holatni o'zgartirish</label>
                                <select class="form-control" id="status" name="status" style="width: 100%;">
                                    <option value="yangi" <?php echo $appeal['status'] === 'yangi' ? 'selected' : ''; ?>>Yangi</option>
                                    <option value="masul_tayinlandi" <?php echo $appeal['status'] === 'masul_tayinlandi' ? 'selected' : ''; ?>>Jarayonda</option>
                                    <option value="tasdiqlash_kutilmoqda" <?php echo $appeal['status'] === 'tasdiqlash_kutilmoqda' ? 'selected' : ''; ?>>Tasdiqlash kutilmoqda</option>
                                    <option value="qaytarildi" <?php echo $appeal['status'] === 'qaytarildi' ? 'selected' : ''; ?>>Qayta ishlashda</option>
                                    <option value="korib_chiqildi" <?php echo $appeal['status'] === 'korib_chiqildi' ? 'selected' : ''; ?>>Bajarildi</option>
                                    <option value="rad_etildi" <?php echo $appeal['status'] === 'rad_etildi' ? 'selected' : ''; ?>>Rad etildi</option>
                                </select>
                            </div>
                            <button type="submit" name="change_status" class="btn btn-outline btn-sm" style="width: 100%; justify-content: center;">
                                <i class="fa-solid fa-floppy-disk"></i> Saqlash
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- 2. Assignment Section -->
                <div class="detail-section">
                    <h3 class="section-title"><i class="fa-solid fa-user-tie"></i> Mas'ul xodim</h3>
                    
                    <div style="margin-bottom: 20px;">
                        <span class="info-label">Biriktirilgan mas'ul:</span>
                        <p style="margin-top: 6px;">
                            <?php if ($appeal['masul_name']): ?>
                                <a href="profile.php?id=<?php echo $appeal['assigned_to']; ?>" style="display: inline-flex; align-items: center; gap: 8px; text-decoration: none; color: var(--primary-color); background: rgba(59, 130, 246, 0.08); padding: 8px 14px; border-radius: 12px; border: 1px solid rgba(59, 130, 246, 0.15); font-weight: 700; transition: all 0.2s ease;" onmouseover="this.style.background='rgba(59, 130, 246, 0.15)'" onmouseout="this.style.background='rgba(59, 130, 246, 0.08)'">
                                    <i class="fa-solid fa-user-shield"></i>
                                    <span><?php echo htmlspecialchars($appeal['masul_name']); ?></span>
                                </a>
                            <?php else: ?>
                                <em style="color: var(--text-secondary); font-weight: 500;">Hali belgilanmagan</em>
                            <?php endif; ?>
                        </p>
                        <?php if ($appeal['masul_name'] && $appeal['masul_dept']): ?>
                            <div style="font-size: 12px; color: var(--text-secondary); margin-top: 6px; padding-left: 4px;">
                                <i class="fa-solid fa-building" style="font-size: 10px; margin-right: 4px;"></i> <?php echo htmlspecialchars($appeal['masul_dept']); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Admin Assignment Form -->
                    <?php if ($userRole === 'admin'): ?>
                        <form action="view_appeal.php?id=<?php echo $appealId; ?>" method="POST" style="border-top: 1px solid var(--border-color); padding-top: 20px;">
                            <div class="form-group" style="margin-bottom: 12px;">
                                <label class="form-label" for="assigned_to">Mas'ulni tanlang</label>
                                <select class="form-control" id="assigned_to" name="assigned_to" style="width: 100%;">
                                    <option value="">Mas'ulni tanlang...</option>
                                    <?php 
                                    $index = 0;
                                    foreach ($masullar as $m): 
                                        $isNew = ($index < 3); // Mark top 3 newest users
                                        $index++;
                                    ?>
                                        <option value="<?php echo $m['id']; ?>" <?php echo $appeal['assigned_to'] == $m['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($m['fio']); ?> <?php echo $isNew ? '🔥 (Yangi)' : ''; ?> (<?php echo htmlspecialchars($m['department']); ?> - <?php echo htmlspecialchars($m['role']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" style="margin-bottom: 16px;">
                                <label class="form-label" for="deadline">⏰ Muddati (Deadline)</label>
                                <input type="date" class="form-control" id="deadline" name="deadline" 
                                    value="<?php echo htmlspecialchars($appeal['deadline'] ?? ''); ?>"
                                    min="<?php echo date('Y-m-d'); ?>"
                                    style="width: 100%; cursor: pointer;">
                                <span style="font-size: 11px; color: var(--text-secondary); margin-top: 4px; display: block;">Bo'sh qoldirilsa muddatsiz hisoblanadi</span>
                            </div>
                            <button type="submit" name="assign_masul" class="btn btn-primary btn-sm" style="width: 100%; justify-content: center;">
                                <i class="fa-solid fa-share-nodes"></i> Mas'ul biriktirish / Saqlash
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
