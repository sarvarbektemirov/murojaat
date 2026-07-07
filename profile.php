<?php
/**
 * User Profile Page - profile.php
 */
require_once 'config.php';
check_auth();

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

// Determine which user's profile to show
$viewUserId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$viewUserId) {
    $viewUserId = $userId;
}

$isOwnProfile = ($viewUserId === $userId);

$errorMsg = '';
$successMsg = '';

// Fetch profile user details
try {
    $stmt = $pdo->prepare("SELECT * FROM web_users WHERE id = :id");
    $stmt->execute(['id' => $viewUserId]);
    $user = $stmt->fetch();
    
    if (!$user) {
        die("Foydalanuvchi topilmadi.");
    }
} catch (PDOException $e) {
    die("Xatolik yuz berdi: " . $e->getMessage());
}

// Fetch assigned appeals if viewing a mas'ul's profile
$assignedAppeals = [];
$stats = ['total' => 0, 'in_progress' => 0, 'completed' => 0, 'rejected' => 0];
if ($user['role'] === 'masul') {
    try {
        $appStmt = $pdo->prepare("
            SELECT id, appeal_number, status, submitted_at, responded_at 
            FROM appeals 
            WHERE assigned_to = :assigned_to 
            ORDER BY id DESC 
            LIMIT 10
        ");
        $appStmt->execute(['assigned_to' => $viewUserId]);
        $assignedAppeals = $appStmt->fetchAll();

        $statsStmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                COALESCE(SUM(CASE WHEN status IN ('yangi', 'masul_tayinlandi', 'tasdiqlash_kutilmoqda', 'qaytarildi') THEN 1 ELSE 0 END), 0) as in_progress,
                COALESCE(SUM(CASE WHEN status = 'korib_chiqildi' THEN 1 ELSE 0 END), 0) as completed,
                COALESCE(SUM(CASE WHEN status = 'rad_etildi' THEN 1 ELSE 0 END), 0) as rejected
            FROM appeals 
            WHERE assigned_to = :assigned_to
        ");
        $statsStmt->execute(['assigned_to' => $viewUserId]);
        $stats = $statsStmt->fetch() ?: $stats;
    } catch (PDOException $e) {
        // Ignore or handle
    }
}

// Handle Profile Updates (Only if it's own profile!)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isOwnProfile) {
    // 1. Shaxsiy ma'lumotlarni tahrirlash va Avatar yuklash
    if (isset($_POST['update_profile'])) {
        $fio = trim($_POST['fio'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $avatarPath = $user['avatar']; // keep existing by default
        
        if (!empty($fio) && !empty($department) && !empty($username)) {
            // Check avatar upload
            if (isset($_FILES['avatar_image']) && $_FILES['avatar_image']['error'] === UPLOAD_ERR_OK) {
                $fileTmp = $_FILES['avatar_image']['tmp_name'];
                $fileName = $_FILES['avatar_image']['name'];
                $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                
                $allowedExts = ['jpg', 'jpeg', 'png', 'webp'];
                if (in_array($fileExt, $allowedExts)) {
                    $avatarDir = __DIR__ . '/uploads/avatars/';
                    if (!file_exists($avatarDir)) {
                        mkdir($avatarDir, 0777, true);
                    }
                    
                    $newAvatarName = 'avatar_' . $userId . '_' . time() . '.' . $fileExt;
                    $destPath = $avatarDir . $newAvatarName;
                    
                    if (move_uploaded_file($fileTmp, $destPath)) {
                        // Delete old file if exists
                        if ($user['avatar'] && file_exists(__DIR__ . '/' . $user['avatar'])) {
                            unlink(__DIR__ . '/' . $user['avatar']);
                        }
                        $avatarPath = 'uploads/avatars/' . $newAvatarName;
                    } else {
                        $errorMsg = "Rasm yuklashda xatolik yuz berdi.";
                    }
                } else {
                    $errorMsg = "Noto'g'ri rasm formati! Faqat JPG, PNG, WEBP ruxsat etiladi.";
                }
            }
            
            if (empty($errorMsg)) {
                try {
                    // Check if username is taken
                    $chkStmt = $pdo->prepare("SELECT COUNT(*) FROM web_users WHERE username = :username AND id != :id");
                    $chkStmt->execute(['username' => $username, 'id' => $userId]);
                    
                    if ($chkStmt->fetchColumn() > 0) {
                        $errorMsg = "Ushbu foydalanuvchi nomi (username) band, boshqasini kiriting.";
                    } else {
                        $upStmt = $pdo->prepare("
                            UPDATE web_users 
                            SET fio = :fio, department = :department, username = :username, avatar = :avatar
                            WHERE id = :id
                        ");
                        $upStmt->execute([
                            'fio' => $fio,
                            'department' => $department,
                            'username' => $username,
                            'avatar' => $avatarPath,
                            'id' => $userId
                        ]);
                        
                        // Update session variables
                        $_SESSION['fio'] = $fio;
                        $_SESSION['username'] = $username;
                        $_SESSION['department'] = $department;
                        
                        $successMsg = "Profilingiz muvaffaqiyatli yangilandi!";
                        
                        // Refresh local variables
                        $user['fio'] = $fio;
                        $user['department'] = $department;
                        $user['username'] = $username;
                        $user['avatar'] = $avatarPath;
                    }
                } catch (PDOException $e) {
                    $errorMsg = "Ma'lumotlarni saqlashda xatolik: " . $e->getMessage();
                }
            }
        } else {
            $errorMsg = "Iltimos, barcha maydonlarni to'ldiring.";
        }
    }
    
    // 2. Parolni o'zgartirish
    if (isset($_POST['change_password'])) {
        $currentPass = $_POST['current_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';
        
        if (!empty($currentPass) && !empty($newPass) && !empty($confirmPass)) {
            if (password_verify($currentPass, $user['password'])) {
                if ($newPass === $confirmPass) {
                    try {
                        $hashedPass = password_hash($newPass, PASSWORD_DEFAULT);
                        $upStmt = $pdo->prepare("UPDATE web_users SET password = :password WHERE id = :id");
                        $upStmt->execute(['password' => $hashedPass, 'id' => $userId]);
                        
                        $user['password'] = $hashedPass;
                        $successMsg = "Parolingiz muvaffaqiyatli o'zgartirildi!";
                    } catch (PDOException $e) {
                        $errorMsg = "Parolni saqlashda xatolik yuz berdi.";
                    }
                } else {
                    $errorMsg = "Yangi parollar bir-biriga mos kelmadi!";
                }
            } else {
                $errorMsg = "Hozirgi parolingiz noto'g'ri kiritildi!";
            }
        } else {
            $errorMsg = "Parol maydonlarini to'ldiring.";
        }
    }

    // 3. Profilni o'chirish (faqat o'z profili yoki admin)
    if (isset($_POST['delete_account'])) {
        $confirmText = trim($_POST['confirm_delete_text'] ?? '');
        if ($confirmText === $user['username']) {
            try {
                // Delete avatar file if exists
                if ($user['avatar'] && file_exists(__DIR__ . '/' . $user['avatar'])) {
                    unlink(__DIR__ . '/' . $user['avatar']);
                }
                // Delete from team_members
                $pdo->prepare("DELETE FROM team_members WHERE user_id = :uid")->execute(['uid' => $userId]);
                // Delete user
                $pdo->prepare("DELETE FROM web_users WHERE id = :id")->execute(['id' => $userId]);
                // Destroy session
                session_destroy();
                header("Location: login.php?success=" . urlencode("Akkauntingiz o'chirildi."));
                exit;
            } catch (PDOException $e) {
                $errorMsg = "O'chirishda xatolik: " . $e->getMessage();
            }
        } else {
            $errorMsg = "Tasdiqlash matnini noto'g'ri kiritdingiz! Username to'g'ri yozing.";
        }
    }
}

// Generate User Initials for Profile Card
$fParts = explode(' ', $user['fio']);
$userInitials = mb_substr($fParts[0] ?? '', 0, 1) . mb_substr($fParts[1] ?? '', 0, 1);
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isOwnProfile ? "Mening Profilim" : htmlspecialchars($user['fio']) . " - Profil"; ?> | Sardoba Hokimligi Murojaat Bot</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Sidebar navigation -->
    <?php include_once 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header" style="margin-bottom: 24px;">
            <div>
                <?php if (!$isOwnProfile): ?>
                    <a href="team.php" class="btn btn-outline btn-sm" style="margin-bottom: 12px;">
                        <i class="fa-solid fa-arrow-left"></i> Jamoa chatiga qaytish
                    </a>
                <?php endif; ?>
                <h1 class="page-title"><?php echo $isOwnProfile ? "Mening Profilim" : "Xodim Profili"; ?></h1>
                <div class="page-subtitle"><?php echo $isOwnProfile ? "Shaxsiy ma'lumotlaringizni boshqarish va xavfsizlik sozlamalari" : htmlspecialchars($user['fio']) . " haqida batafsil ma'lumotlar"; ?></div>
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

        <div class="detail-grid" style="grid-template-columns: 320px 1fr; gap: 32px;">
            <!-- Left Side: Profile Information Card -->
            <div>
                <div class="detail-section" style="display: flex; flex-direction: column; align-items: center; text-align: center; padding: 40px 24px;">
                    <?php if ($user['avatar'] && file_exists(__DIR__ . '/' . $user['avatar'])): ?>
                        <div style="width: 100px; height: 100px; border-radius: 50%; overflow: hidden; border: 2px solid var(--primary-color); margin-bottom: 20px;">
                            <img src="<?php echo htmlspecialchars($user['avatar']); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                        </div>
                    <?php else: ?>
                        <div style="width: 100px; height: 100px; border-radius: 50%; background-color: var(--primary-glow); color: var(--primary-color); display: flex; align-items: center; justify-content: center; font-size: 36px; font-weight: 800; border: 2px solid var(--primary-color); margin-bottom: 20px;">
                            <?php echo htmlspecialchars(strtoupper($userInitials)); ?>
                        </div>
                    <?php endif; ?>
                    
                    <h2 style="font-size: 18px; font-weight: 700; color: var(--text-primary); margin-bottom: 4px;"><?php echo htmlspecialchars($user['fio']); ?></h2>
                    <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 16px;">@<?php echo htmlspecialchars($user['username']); ?></p>
                    
                    <span class="badge <?php echo htmlspecialchars($user['role']); ?>" style="font-size: 12px; padding: 6px 12px; margin-bottom: 24px;">
                        <?php 
                        if ($user['role'] === 'admin') echo "Administrator";
                        elseif ($user['role'] === 'hokim') echo "Tuman Hokimi";
                        elseif ($user['role'] === 'masul') echo "Mas'ul xodim";
                        ?>
                    </span>
                    
                    <div style="width: 100%; border-top: 1px solid var(--border-color); padding-top: 20px; text-align: left;">
                        <span class="info-label">Bo'lim / Tashkilot:</span>
                        <p style="font-size: 14px; font-weight: 600; color: var(--text-primary); margin-top: 4px;"><?php echo htmlspecialchars($user['department']); ?></p>
                    </div>

                    <?php if ($user['role'] === 'masul'): ?>
                    <div style="width: 100%; border-top: 1px solid var(--border-color); padding-top: 16px; margin-top: 16px; text-align: left;">
                        <span class="info-label" style="margin-bottom: 12px; display: block;">Murojaatlar statistikasi:</span>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 13px;">
                            <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); padding: 8px; border-radius: 8px; text-align: center;">
                                <div style="color: var(--text-secondary); font-size: 11px;">Jami</div>
                                <div style="font-weight: 700; color: var(--text-primary); font-size: 16px; margin-top: 2px;"><?php echo $stats['total']; ?></div>
                            </div>
                            <div style="background: rgba(245, 158, 11, 0.05); border: 1px solid rgba(245, 158, 11, 0.15); padding: 8px; border-radius: 8px; text-align: center;">
                                <div style="color: #f59e0b; font-size: 11px;">Jarayonda</div>
                                <div style="font-weight: 700; color: #f59e0b; font-size: 16px; margin-top: 2px;"><?php echo $stats['in_progress']; ?></div>
                            </div>
                            <div style="background: rgba(16, 185, 129, 0.05); border: 1px solid rgba(16, 185, 129, 0.15); padding: 8px; border-radius: 8px; text-align: center;">
                                <div style="color: #10b981; font-size: 11px;">Bajarilgan</div>
                                <div style="font-weight: 700; color: #10b981; font-size: 16px; margin-top: 2px;"><?php echo $stats['completed']; ?></div>
                            </div>
                            <div style="background: rgba(239, 68, 68, 0.05); border: 1px solid rgba(239, 68, 68, 0.15); padding: 8px; border-radius: 8px; text-align: center;">
                                <div style="color: #ef4444; font-size: 11px;">Rad etilgan</div>
                                <div style="font-weight: 700; color: #ef4444; font-size: 16px; margin-top: 2px;"><?php echo $stats['rejected']; ?></div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Side: Edit Form OR Read-only Info -->
            <div style="display: flex; flex-direction: column; gap: 32px;">
                <?php if ($isOwnProfile): ?>
                    <!-- OWN PROFILE: Editable Forms -->
                    
                    <!-- 1. Edit Profile Form -->
                    <div class="detail-section" style="margin-bottom: 0;">
                        <h3 class="section-title"><i class="fa-solid fa-user-pen"></i> Shaxsiy ma'lumotlarni tahrirlash</h3>
                        
                        <form action="profile.php" method="POST" enctype="multipart/form-data">
                            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 24px;">
                                <div class="form-group">
                                    <label class="form-label" for="fio">To'liq F.I.Sh</label>
                                    <input class="form-control" type="text" id="fio" name="fio" value="<?php echo htmlspecialchars($user['fio']); ?>" required style="width: 100%;">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="department">Bo'lim / Lavozim</label>
                                    <input class="form-control" type="text" id="department" name="department" value="<?php echo htmlspecialchars($user['department']); ?>" required style="width: 100%;">
                                </div>
                            </div>
                            
                            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 24px;">
                                <div class="form-group">
                                    <label class="form-label" for="username">Username (Kirish logini)</label>
                                    <input class="form-control" type="text" id="username" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required style="width: 100%;">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="avatar_image">Profil rasmi (Rasm fayli, JPG/PNG)</label>
                                    <input class="form-control" type="file" id="avatar_image" name="avatar_image" style="width: 100%;">
                                </div>
                            </div>
                            
                            <button type="submit" name="update_profile" class="btn btn-primary">
                                <i class="fa-solid fa-floppy-disk"></i> O'zgarishlarni saqlash
                            </button>
                        </form>
                    </div>

                    <!-- 2. Change Password Form -->
                    <div class="detail-section" style="margin-bottom: 0;">
                        <h3 class="section-title"><i class="fa-solid fa-key"></i> Xavfsizlik (Parolni o'zgartirish)</h3>
                        
                        <form action="profile.php" method="POST">
                            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 24px;">
                                <div class="form-group">
                                    <label class="form-label" for="current_password">Hozirgi parol</label>
                                    <input class="form-control" type="password" id="current_password" name="current_password" placeholder="Eski parolingiz" required style="width: 100%;">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="new_password">Yangi parol</label>
                                    <input class="form-control" type="password" id="new_password" name="new_password" placeholder="Yangi parol kiriting" required style="width: 100%;">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="confirm_password">Yangi parolni tasdiqlang</label>
                                    <input class="form-control" type="password" id="confirm_password" name="confirm_password" placeholder="Qayta kiriting" required style="width: 100%;">
                                </div>
                            </div>
                            
                            <button type="submit" name="change_password" class="btn btn-outline" style="border-color: var(--primary-color); color: var(--primary-color);">
                                <i class="fa-solid fa-lock-open"></i> Parolni yangilash
                            </button>
                        </form>
                    </div>

                    <!-- 3. Danger Zone: Delete Account -->
                    <div class="detail-section" style="margin-bottom: 0; border: 1px solid rgba(239,68,68,0.3); background: rgba(239,68,68,0.04);">
                        <h3 class="section-title" style="color: var(--danger);"><i class="fa-solid fa-triangle-exclamation"></i> Xavfli hudud</h3>
                        <p style="font-size: 13px; color: var(--text-secondary); margin-bottom: 16px; line-height: 1.6;">
                            Akkauntingizni o'chirish <strong style="color: var(--danger);">qaytarib bo'lmaydigan</strong> amal. Profilingiz, rasm va barcha ma'lumotlar butunlay o'chiriladi.
                        </p>
                        <form action="profile.php" method="POST" id="deleteAccountForm">
                            <div class="form-group" style="margin-bottom: 12px;">
                                <label class="form-label" style="color: var(--danger);">Tasdiqlash uchun username'ingizni kiriting: <strong><?php echo htmlspecialchars($user['username']); ?></strong></label>
                                <input class="form-control" type="text" name="confirm_delete_text" placeholder="<?php echo htmlspecialchars($user['username']); ?>" required style="width: 100%; border-color: rgba(239,68,68,0.4);">
                            </div>
                            <button type="submit" name="delete_account"
                                onclick="return confirm('DIQQAT! Akkauntingiz butunlay o\'chiriladi. Davom etasizmi?')"
                                class="btn btn-danger" style="">
                                <i class="fa-solid fa-skull-crossbones"></i> Akkauntni o'chirish
                            </button>
                        </form>
                    </div>
                <?php else: ?>
                    <!-- OTHER MEMBER PROFILE: Read-only Views -->
                    
                    <div class="detail-section" style="margin-bottom: 0;">
                        <h3 class="section-title"><i class="fa-solid fa-circle-info"></i> Xodim haqida ma'lumotlar</h3>
                        <div class="info-list">
                            <div class="info-item">
                                <span class="info-label">To'liq ismi</span>
                                <span class="info-value"><?php echo htmlspecialchars($user['fio']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Bo'limi / Lavozimi</span>
                                <span class="info-value"><?php echo htmlspecialchars($user['department']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Roli (Vazifasi)</span>
                                <span class="info-value" style="text-transform: uppercase; font-weight: bold; color: var(--primary-color);">
                                    <?php echo htmlspecialchars($user['role']); ?>
                                </span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">Tizimdagi username</span>
                                <span class="info-value">@<?php echo htmlspecialchars($user['username']); ?></span>
                            </div>
                        </div>
                    </div>

                    <?php if ($user['role'] === 'masul' && ($userRole === 'admin' || $userRole === 'hokim' || $isOwnProfile)): ?>
                        <div class="detail-section" style="margin-bottom: 0;">
                            <h3 class="section-title"><i class="fa-solid fa-chart-pie"></i> Murojaatlar statistikasi (Diagramma)</h3>
                            
                            <div style="display: flex; align-items: center; justify-content: space-around; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); padding: 24px; border-radius: 16px; flex-wrap: wrap; gap: 20px;">
                                <!-- Circular Donut Chart -->
                                <div style="position: relative; width: 150px; height: 150px; flex-shrink: 0;">
                                    <?php
                                    $total = $stats['total'];
                                    if ($total > 0) {
                                        $p_progress = ($stats['in_progress'] / $total) * 100;
                                        $p_completed = ($stats['completed'] / $total) * 100;
                                        $p_rejected = ($stats['rejected'] / $total) * 100;
                                        
                                        $val1 = $p_progress;
                                        $val2 = $p_progress + $p_completed;
                                        
                                        $gradient = "conic-gradient(#f59e0b 0% {$val1}%, #10b981 {$val1}% {$val2}%, #ef4444 {$val2}% 100%)";
                                    } else {
                                        $gradient = "conic-gradient(var(--border-color) 0% 100%)";
                                    }
                                    ?>
                                    <div style="width: 100%; height: 100%; border-radius: 50%; background: <?php echo $gradient; ?>; display: flex; align-items: center; justify-content: center;">
                                        <div style="width: 105px; height: 105px; border-radius: 50%; background: var(--card-bg, #111827); display: flex; flex-direction: column; align-items: center; justify-content: center; box-shadow: inset 0 0 10px rgba(0,0,0,0.5);">
                                            <span style="font-size: 26px; font-weight: 800; color: var(--text-primary); line-height: 1;"><?php echo $stats['total']; ?></span>
                                            <span style="font-size: 10px; color: var(--text-secondary); margin-top: 4px;">Jami</span>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Legend / Stats list -->
                                <div style="display: flex; flex-direction: column; gap: 14px; min-width: 160px;">
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <span style="width: 12px; height: 12px; border-radius: 3px; background-color: #f59e0b; display: inline-block; flex-shrink: 0;"></span>
                                        <div style="flex: 1;">
                                            <div style="font-size: 11px; color: var(--text-secondary);">Jarayonda</div>
                                            <div style="font-size: 14px; font-weight: 700; color: var(--text-primary);"><?php echo $stats['in_progress']; ?> ta (<?php echo $total > 0 ? round($p_progress) : 0; ?>%)</div>
                                        </div>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <span style="width: 12px; height: 12px; border-radius: 3px; background-color: #10b981; display: inline-block; flex-shrink: 0;"></span>
                                        <div style="flex: 1;">
                                            <div style="font-size: 11px; color: var(--text-secondary);">Bajarilgan</div>
                                            <div style="font-size: 14px; font-weight: 700; color: var(--text-primary);"><?php echo $stats['completed']; ?> ta (<?php echo $total > 0 ? round($p_completed) : 0; ?>%)</div>
                                        </div>
                                    </div>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <span style="width: 12px; height: 12px; border-radius: 3px; background-color: #ef4444; display: inline-block; flex-shrink: 0;"></span>
                                        <div style="flex: 1;">
                                            <div style="font-size: 11px; color: var(--text-secondary);">Rad etilgan</div>
                                            <div style="font-size: 14px; font-weight: 700; color: var(--text-primary);"><?php echo $stats['rejected']; ?> ta (<?php echo $total > 0 ? round($p_rejected) : 0; ?>%)</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($user['role'] === 'masul'): ?>
                        <div class="detail-section" style="margin-bottom: 0;">
                            <h3 class="section-title"><i class="fa-solid fa-folder-open"></i> Xodimga biriktirilgan murojaatlar (Oxirgi 10 ta)</h3>
                            <?php if (empty($assignedAppeals)): ?>
                                <p style="color: var(--text-secondary); font-size: 14px;">Ushbu xodimga hozirda biriktirilgan murojaatlar mavjud emas.</p>
                            <?php else: ?>
                                        <div class="custom-table-container">
                                            <table class="custom-table">
                                                <thead>
                                                    <tr>
                                                        <th>Murojaat raqami</th>
                                                        <th>Kelib tushgan sana</th>
                                                        <th>Javob sanasi</th>
                                                        <th>Holati</th>
                                                        <th>Amal</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($assignedAppeals as $app): ?>
                                                        <tr>
                                                            <td><strong><?php echo htmlspecialchars($app['appeal_number']); ?></strong></td>
                                                            <td><?php echo date('d.m.Y H:i', strtotime($app['submitted_at'])); ?></td>
                                                            <td>
                                                                <?php 
                                                                echo $app['responded_at'] ? date('d.m.Y H:i', strtotime($app['responded_at'])) : '<em style="color: var(--text-secondary);">—</em>'; 
                                                                ?>
                                                            </td>
                                                            <td>
                                                                <span class="badge <?php echo htmlspecialchars($app['status']); ?>">
                                                                    <?php 
                                                                    if ($app['status'] === 'yangi') echo "Yangi";
                                                                    elseif ($app['status'] === 'masul_tayinlandi') echo "Jarayonda";
                                                                    elseif ($app['status'] === 'tasdiqlash_kutilmoqda') echo "Kutilmoqda";
                                                                    elseif ($app['status'] === 'qaytarildi') echo "Qayta ishlashda";
                                                                    elseif ($app['status'] === 'korib_chiqildi') echo "Bajarildi";
                                                                    elseif ($app['status'] === 'rad_etildi') echo "Rad etildi";
                                                                    ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <a href="view_appeal.php?id=<?php echo $app['id']; ?>" class="btn btn-outline btn-sm">
                                                                    <i class="fa-solid fa-eye"></i> Ko'rish
                                                                </a>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
