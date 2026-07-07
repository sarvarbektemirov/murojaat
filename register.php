<?php
/**
 * Registration Page with Invite Code Validation - register.php
 */
require_once 'config.php';

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';
$success = '';

// Check if bootstrap mode (no teams in database yet)
try {
    $teamsCountStmt = $pdo->query("SELECT COUNT(*) FROM teams");
    $totalTeams = $teamsCountStmt->fetchColumn();
} catch (PDOException $e) {
    $totalTeams = 0;
}
$isBootstrapMode = ($totalTeams == 0);

// Validate invite code against DB teams table
$inviteCode = $_GET['invite'] ?? $_POST['invite_code'] ?? '';
$inviteTeam = null;
if ($inviteCode) {
    $invStmt = $pdo->prepare("SELECT * FROM teams WHERE invite_code = :code");
    $invStmt->execute(['code' => $inviteCode]);
    $inviteTeam = $invStmt->fetch();
}
$isValidInvite = ($inviteTeam !== null && $inviteTeam !== false);

// Validate setup token against DB web_users table
$setupToken = $_GET['setup'] ?? $_POST['setup_token'] ?? '';
$setupUser = null;
if ($setupToken) {
    if (strpos($setupToken, 'temp_') === 0) {
        $setupStmt = $pdo->prepare("SELECT * FROM web_users WHERE username = :username");
        $setupStmt->execute(['username' => $setupToken]);
        $setupUser = $setupStmt->fetch();
    }
}
$isSetupMode = ($setupUser !== null && $setupUser !== false);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($isSetupMode) {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        
        if (!empty($username) && !empty($password)) {
            try {
                // Check if username already exists
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM web_users WHERE username = :username AND id != :id");
                $checkStmt->execute(['username' => $username, 'id' => $setupUser['id']]);
                if ($checkStmt->fetchColumn() > 0) {
                    $error = "Ushbu foydalanuvchi nomi (username) allaqachon mavjud!";
                } else {
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $upStmt = $pdo->prepare("UPDATE web_users SET username = :username, password = :password WHERE id = :id");
                    $upStmt->execute([
                        'username' => $username,
                        'password' => $hashedPassword,
                        'id' => $setupUser['id']
                    ]);
                    $success = "Akkauntingiz muvaffaqiyatli faollashtirildi! Endi o'zingiz tanlagan username va parol orqali tizimga kirishingiz mumkin.";
                }
            } catch (PDOException $e) {
                $error = "Tizim xatosi yuz berdi: " . $e->getMessage();
            }
        } else {
            $error = "Iltimos, barcha maydonlarni to'ldiring!";
        }
    } elseif (!$isValidInvite && !$isBootstrapMode) {
        $error = "Tizimda ro'yxatdan o'tish yopiq: Taklif havolasi yaroqsiz!";
    } else {
        $fio = trim($_POST['fio'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $role = $isBootstrapMode ? trim($_POST['role'] ?? 'masul') : 'masul';
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if (!empty($fio) && !empty($department) && !empty($role) && !empty($username) && !empty($password)) {
            if (in_array($role, ['admin', 'masul', 'hokim'])) {
                try {
                    // Check if username already exists
                    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM web_users WHERE username = :username");
                    $checkStmt->execute(['username' => $username]);
                    if ($checkStmt->fetchColumn() > 0) {
                        $error = "Ushbu foydalanuvchi nomi (username) allaqachon mavjud!";
                    } else {
                        // Hash password securely
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        
                        $stmt = $pdo->prepare("
                            INSERT INTO web_users (username, password, fio, role, department) 
                            VALUES (:username, :password, :fio, :role, :department)
                        ");
                        $stmt->execute([
                            'username' => $username,
                            'password' => $hashedPassword,
                            'fio' => $fio,
                            'role' => $role,
                            'department' => $department
                        ]);
                        
                        $newUserId = $pdo->lastInsertId();
                        
                        // Auto-add user to the invited team (only if we have inviteTeam)
                        if ($inviteTeam) {
                            $memStmt = $pdo->prepare("INSERT IGNORE INTO team_members (team_id, user_id) VALUES (:team_id, :user_id)");
                            $memStmt->execute(['team_id' => $inviteTeam['id'], 'user_id' => $newUserId]);
                            $success = "Muvaffaqiyatli ro'yxatdan o'tdingiz va '" . htmlspecialchars($inviteTeam['name']) . "' jamoasiga qo'shildingiz! Endi tizimga kirishingiz mumkin.";
                        } else {
                            $success = "Muvaffaqiyatli ro'yxatdan o'tdingiz! Tizimga birinchi foydalanuvchi sifatida kirdingiz. Endi kirib, birinchi jamoani yaratishingiz mumkin.";
                        }
                    }
                } catch (PDOException $e) {
                    $error = "Tizim xatosi yuz berdi: " . $e->getMessage();
                }
            } else {
                $error = "Noto'g'ri rol tanlandi!";
            }
        } else {
            $error = "Iltimos, barcha maydonlarni to'ldiring!";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ro'yxatdan O'tish | Sardoba Hokimligi Murojaat Bot</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="login-container" style="height: auto; padding: 40px 0;">
        <div class="login-card" style="width: 500px;">
            <div class="login-header" style="margin-bottom: 24px;">
                <i class="fa-solid fa-user-plus" style="color: var(--primary-color);"></i>
                <h2>Ro'yxatdan O'tish</h2>
                <p>Tizimda yangi xodim akkauntini ochish</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="login-error">
                    <i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div style="background-color: var(--success-bg); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.2); padding: 12px 16px; border-radius: 12px; font-size: 14px; margin-bottom: 24px; font-weight: 500; text-align: center;">
                    <i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($success); ?>
                </div>
                <div style="text-align: center;">
                    <a href="login.php" class="btn btn-primary" style="width: 100%; justify-content: center;"><i class="fa-solid fa-right-to-bracket"></i> Tizimga kirish</a>
                </div>
            <?php endif; ?>

            <?php if ($isSetupMode && empty($success)): ?>
                <form action="register.php" method="POST">
                    <input type="hidden" name="setup_token" value="<?php echo htmlspecialchars($setupToken); ?>">
                    
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label class="form-label">F.I.Sh (Ism, Familiya)</label>
                        <input class="form-control" type="text" value="<?php echo htmlspecialchars($setupUser['fio']); ?>" readonly style="width: 100%; opacity: 0.8; background-color: rgba(255,255,255,0.03); cursor: not-allowed;">
                    </div>

                    <div class="form-group" style="margin-bottom: 16px;">
                        <label class="form-label">Bo'lim yoki Tashkilot nomi</label>
                        <input class="form-control" type="text" value="<?php echo htmlspecialchars($setupUser['department']); ?>" readonly style="width: 100%; opacity: 0.8; background-color: rgba(255,255,255,0.03); cursor: not-allowed;">
                    </div>

                    <div class="form-group" style="margin-bottom: 16px;">
                        <label class="form-label" for="setup_username">Yangi foydalanuvchi nomi (Username)</label>
                        <input class="form-control" type="text" id="setup_username" name="username" placeholder="Tizimga kirish uchun username tanlang" required style="width: 100%;">
                    </div>

                    <div class="form-group" style="margin-bottom: 24px;">
                        <label class="form-label" for="setup_password">Yangi kirish paroli</label>
                        <div style="position: relative; display: flex; align-items: center; width: 100%;">
                            <input class="form-control" type="password" id="setup_password" name="password" placeholder="Tizimga kirish uchun parol kiriting" required style="width: 100%; padding-right: 40px;">
                            <button type="button" onclick="togglePasswordVisibility('setup_password', this)" style="position: absolute; right: 12px; background: none; border: none; padding: 0; color: var(--text-secondary); cursor: pointer; display: flex; align-items: center; font-size: 14px;" title="Parolni ko'rsatish/yashirish">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 12px; margin-bottom: 16px;">
                        <i class="fa-solid fa-user-check"></i> Akkauntni faollashtirish
                    </button>
                </form>
            <?php elseif (!$isValidInvite && !$isBootstrapMode && empty($success)): ?>
                <div style="background-color: rgba(239, 68, 68, 0.05); color: var(--danger); border: 1px solid rgba(239, 68, 68, 0.2); padding: 16px; border-radius: 12px; font-size: 14px; line-height: 1.5; font-weight: 500; text-align: center; margin-bottom: 24px;">
                    <i class="fa-solid fa-lock" style="font-size: 24px; display: block; margin-bottom: 8px;"></i>
                    Ro'yxatdan o'tish taqiqlangan! Jamoaga qo'shilish uchun administratordan taklif havolasini (Invite Link) oling.
                </div>
                <div style="text-align: center;">
                    <a href="login.php" class="btn btn-outline" style="width: 100%; justify-content: center;">
                        <i class="fa-solid fa-arrow-left-long"></i> Kirish sahifasiga qaytish
                    </a>
                </div>
            <?php elseif (empty($success)): ?>
                <form action="register.php" method="POST">
                    <?php if ($isBootstrapMode): ?>
                        <div style="background-color: rgba(59, 130, 246, 0.08); color: var(--primary-color); border: 1px solid rgba(59, 130, 246, 0.2); padding: 12px 14px; border-radius: 10px; font-size: 13px; font-weight: 600; line-height: 1.4; margin-bottom: 16px; text-align: center;">
                            🚀 Tizimda hali hech qanday jamoa mavjud emas. Birinchi bo'lib ro'yxatdan o'tib, yangi jamoa yaratishingiz mumkin!
                        </div>
                    <?php endif; ?>
                    <input type="hidden" name="invite_code" value="<?php echo htmlspecialchars($inviteCode); ?>">
                    
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label class="form-label" for="fio">F.I.Sh (Ism, Familiya)</label>
                        <input class="form-control" type="text" id="fio" name="fio" placeholder="Ism familiyangizni kiriting" required style="width: 100%;">
                    </div>

                    <div class="form-group" style="margin-bottom: 16px;">
                        <label class="form-label" for="department">Bo'lim yoki Tashkilot nomi</label>
                        <input class="form-control" type="text" id="department" name="department" placeholder="Masalan: Qurilish bo'limi" required style="width: 100%;">
                    </div>

                    <?php if ($isBootstrapMode): ?>
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label class="form-label" for="role">Tizimdagi rol (Vazifa)</label>
                        <select class="form-control" id="role" name="role" required style="width: 100%;">
                            <option value="masul">Mas'ul xodim (Murojaat ijrochi)</option>
                            <option value="admin">Administrator (Murojaat bo'luvchi)</option>
                            <option value="hokim">Tuman Hokimi (Nazoratchi)</option>
                        </select>
                    </div>
                    <?php else: ?>
                        <input type="hidden" name="role" value="masul">
                    <?php endif; ?>

                    <div class="form-group" style="margin-bottom: 16px;">
                        <label class="form-label" for="username">Foydalanuvchi nomi (Username)</label>
                        <input class="form-control" type="text" id="username" name="username" placeholder="Kirish uchun username tanlang" required style="width: 100%;">
                    </div>

                    <div class="form-group" style="margin-bottom: 24px;">
                        <label class="form-label" for="password">Kirish paroli</label>
                        <div style="position: relative; display: flex; align-items: center; width: 100%;">
                            <input class="form-control" type="password" id="password" name="password" placeholder="Parol kiriting" required style="width: 100%; padding-right: 40px;">
                            <button type="button" onclick="togglePasswordVisibility('password', this)" style="position: absolute; right: 12px; background: none; border: none; padding: 0; color: var(--text-secondary); cursor: pointer; display: flex; align-items: center; font-size: 14px;" title="Parolni ko'rsatish/yashirish">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 12px; margin-bottom: 16px;">
                        <i class="fa-solid fa-user-check"></i> Ro'yxatdan o'tish
                    </button>
                    
                    <div style="text-align: center; font-size: 14px;">
                        <span style="color: var(--text-secondary);">Akkauntingiz bormi?</span>
                        <a href="login.php" style="color: var(--primary-color); text-decoration: none; font-weight: 600; margin-left: 4px;">Kirish</a>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <script>
        function togglePasswordVisibility(inputId, button) {
            var input = document.getElementById(inputId);
            var icon = button.querySelector('i');
            if (input.type === "password") {
                input.type = "text";
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            } else {
                input.type = "password";
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            }
        }
    </script>
</body>
</html>
