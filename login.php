<?php
/**
 * Login Page for MurojaatBot Admin Panel
 */
require_once 'config.php';

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($username) && !empty($password)) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM web_users WHERE username = :username");
            $stmt->execute(['username' => $username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // Password is correct, start session
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['fio'] = $user['fio'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['department'] = $user['department'];

                header("Location: index.php");
                exit;
            } else {
                $error = "Foydalanuvchi nomi yoki parol noto'g'ri!";
            }
        } catch (PDOException $e) {
            $error = "Xatolik yuz berdi: " . $e->getMessage();
        }
    } else {
        $error = "Iltimos, barcha maydonlarni to'ldiring!";
    }
}
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tizimga Kirish | Sardoba Hokimligi Murojaat Bot</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <!-- FontAwesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <i class="fa-solid fa-square-poll-vertical"></i>
                <h2>Tizimga Kirish</h2>
                <p>Sardoba tumani hokimligi murojaat boshqaruvi paneli</p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="login-error">
                    <i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST">
                <div class="form-group" style="margin-bottom: 20px;">
                    <label class="form-label" for="username">Foydalanuvchi nomi</label>
                    <input class="form-control" type="text" id="username" name="username" placeholder="Foydalanuvchi nomini kiriting" required style="width: 100%;">
                </div>

                <div class="form-group" style="margin-bottom: 30px;">
                    <label class="form-label" for="password">Parol</label>
                    <div style="position: relative; display: flex; align-items: center; width: 100%;">
                        <input class="form-control" type="password" id="password" name="password" placeholder="Parolni kiriting" required style="width: 100%; padding-right: 40px;">
                        <button type="button" onclick="togglePasswordVisibility('password', this)" style="position: absolute; right: 12px; background: none; border: none; padding: 0; color: var(--text-secondary); cursor: pointer; display: flex; align-items: center; font-size: 14px;" title="Parolni ko'rsatish/yashirish">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; justify-content: center; padding: 12px; margin-bottom: 16px;">
                    <i class="fa-solid fa-right-to-bracket"></i> Kirish
                </button>

                <div style="text-align: center; font-size: 14px;">
                    <span style="color: var(--text-secondary);">Akkauntingiz yo'qmi?</span>
                    <a href="register.php" style="color: var(--primary-color); text-decoration: none; font-weight: 600; margin-left: 4px;">Ro'yxatdan o'tish</a>
                </div>
            </form>
            
            <div style="margin-top: 24px; text-align: center; font-size: 12px; color: var(--text-secondary);">
                Tizim parollari: <code>password</code> (barcha rollar uchun)
            </div>
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
