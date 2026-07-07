<?php
/**
 * Manage Mas'ullar - masullar.php
 * Admin Only
 */
require_once 'config.php';
check_role(['admin']);

$successMsg = '';
$errorMsg = '';

// Handle POST: Add new Mas'ul
$createdLink = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_masul'])) {
    $fio = trim($_POST['fio'] ?? '');
    $department = trim($_POST['department'] ?? '');

    if (!empty($fio) && !empty($department)) {
        try {
            // Generate temporary unique username and password
            $tempUsername = 'temp_' . bin2hex(random_bytes(6));
            $tempPassword = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
            
            $stmt = $pdo->prepare("
                INSERT INTO web_users (username, password, fio, role, department) 
                VALUES (:username, :password, :fio, 'masul', :department)
            ");
            $stmt->execute([
                'username' => $tempUsername,
                'password' => $tempPassword,
                'fio' => $fio,
                'department' => $department
            ]);
            
            // Generate setup link
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
            $domain = $_SERVER['HTTP_HOST'];
            $setupUrl = $protocol . $domain . "/register.php?setup=" . urlencode($tempUsername);
            
            $createdLink = $setupUrl;
            $successMsg = "Yangi mas'ul xodim tizimga kiritildi! Quyidagi akkauntni faollashtirish havolasini nusxalab, xodimga yuboring:";
        } catch (PDOException $e) {
            $errorMsg = "Xatolik yuz berdi: " . $e->getMessage();
        }
    } else {
        $errorMsg = "Iltimos, barcha maydonlarni to'ldiring!";
    }
}

// Handle GET: Delete Mas'ul
if (isset($_GET['delete'])) {
    $deleteId = filter_input(INPUT_GET, 'delete', FILTER_VALIDATE_INT);
    if ($deleteId) {
        try {
            // Check that we aren't deleting an admin or hokim
            $checkStmt = $pdo->prepare("SELECT role FROM web_users WHERE id = :id");
            $checkStmt->execute(['id' => $deleteId]);
            $role = $checkStmt->fetchColumn();
            
            if ($role === 'masul') {
                $delStmt = $pdo->prepare("DELETE FROM web_users WHERE id = :id");
                $delStmt->execute(['id' => $deleteId]);
                $successMsg = "Mas'ul xodim o'chirildi.";
            } else {
                $errorMsg = "Ruxsat etilmagan amal. Faqat mas'ul xodimlarni o'chirish mumkin!";
            }
        } catch (PDOException $e) {
            $errorMsg = "Xatolik yuz berdi: " . $e->getMessage();
        }
    }
}

// Fetch all mas'ullar
try {
    $stmt = $pdo->query("
        SELECT w.*, 
            COUNT(a.id) as assigned_count,
            COALESCE(SUM(CASE WHEN a.status IN ('yangi', 'masul_tayinlandi', 'tasdiqlash_kutilmoqda', 'qaytarildi') THEN 1 ELSE 0 END), 0) as in_progress_count,
            COALESCE(SUM(CASE WHEN a.status = 'korib_chiqildi' THEN 1 ELSE 0 END), 0) as completed_count,
            COALESCE(SUM(CASE WHEN a.status = 'rad_etildi' THEN 1 ELSE 0 END), 0) as rejected_count
        FROM web_users w 
        LEFT JOIN appeals a ON w.id = a.assigned_to 
        WHERE w.role = 'masul' 
        GROUP BY w.id 
        ORDER BY w.fio ASC
    ");
    $masullarList = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Xatolik yuz berdi: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mas'ul Xodimlarni Boshqarish | Sardoba Hokimligi Murojaat Bot</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Sidebar navigation -->
    <?php include_once 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <div>
                <h1 class="page-title">Mas'ul xodimlarni boshqarish</h1>
                <div class="page-subtitle">Murojaatlar yo'naltiriladigan xodimlar va bo'limlar ro'yxati</div>
            </div>
        </div>

        <?php if (!empty($successMsg)): ?>
            <div style="background-color: var(--success-bg); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.2); padding: 16px; border-radius: 12px; margin-bottom: 24px; font-weight: 500;">
                <i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($successMsg); ?>
                <?php if (!empty($createdLink)): ?>
                    <div style="margin-top: 10px; display: flex; gap: 8px; align-items: center;">
                        <input type="text" id="setupLinkInput" readonly value="<?php echo htmlspecialchars($createdLink); ?>" style="flex: 1; font-size: 13px; background: rgba(0,0,0,0.2); border: 1px solid rgba(16, 185, 129, 0.3); color: var(--text-primary); padding: 8px 12px; border-radius: 8px; outline: none;">
                        <button onclick="copySetupLink()" class="btn btn-primary btn-sm" style="padding: 8px 14px; border-radius: 8px; background-color: var(--success); border-color: var(--success);" title="Havolani nusxalash">
                            <i class="fa-solid fa-copy"></i> Nusxalash
                        </button>
                    </div>
                    <span id="setupCopySuccess" style="font-size: 12px; color: var(--success); display: none; font-weight: 600; margin-top: 6px;"><i class="fa-solid fa-check"></i> Havola nusxalandi!</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errorMsg)): ?>
            <div style="background-color: var(--danger-bg); color: var(--danger); border: 1px solid rgba(239, 68, 68, 0.2); padding: 16px; border-radius: 12px; margin-bottom: 24px; font-weight: 500;">
                <i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($errorMsg); ?>
            </div>
        <?php endif; ?>

        <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 32px;">
            <!-- Left Column: Add Form -->
            <div class="data-card" style="height: fit-content;">
                <h3 class="section-title" style="border: none; margin-bottom: 24px; padding: 0;"><i class="fa-solid fa-user-plus"></i> Yangi mas'ul qo'shish</h3>
                
                <form action="masullar.php" method="POST">
                    <div class="form-group" style="margin-bottom: 16px;">
                        <label class="form-label" for="fio">F.I.Sh (Ism, Familiya)</label>
                        <input class="form-control" type="text" id="fio" name="fio" placeholder="Masalan: Sardorbek Temirov" required style="width: 100%;">
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 24px;">
                        <label class="form-label" for="department">Bo'lim yoki Tashkilot nomi</label>
                        <input class="form-control" type="text" id="department" name="department" placeholder="Masalan: Qurilish bo'limi" required style="width: 100%;">
                    </div>

                    <button type="submit" name="add_masul" class="btn btn-primary" style="width: 100%; justify-content: center;">
                        <i class="fa-solid fa-user-plus"></i> Havola yaratish va qo'shish
                    </button>
                </form>
            </div>

            <!-- Right Column: List of Mas'ullar -->
            <div class="data-card">
                <h3 class="section-title" style="border: none; margin-bottom: 24px; padding: 0;"><i class="fa-solid fa-users"></i> Mas'ullar ro'yxati</h3>
                
                <div class="custom-table-container">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>F.I.Sh</th>
                                <th>Bo'lim/Tashkilot</th>
                                <th>Foydalanuvchi nomi</th>
                                <th style="text-align: center;">Murojaatlar statistikasi</th>
                                <th style="text-align: center;">Amal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($masullarList)): ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; color: var(--text-secondary); padding: 24px;">
                                        Hali mas'ul xodimlar qo'shilmagan.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($masullarList as $m): ?>
                                    <tr>
                                        <td>
                                            <a href="profile.php?id=<?php echo $m['id']; ?>" style="color: var(--primary-color); text-decoration: none; font-weight: 700;" onmouseover="this.style.textDecoration='underline'" onmouseout="this.style.textDecoration='none'">
                                                <?php echo htmlspecialchars($m['fio']); ?>
                                            </a>
                                        </td>
                                        <td><?php echo htmlspecialchars($m['department']); ?></td>
                                        <td><code><?php echo htmlspecialchars($m['username']); ?></code></td>
                                        <td style="text-align: center; white-space: nowrap;">
                                            <span style="font-weight: 700; background-color: rgba(255,255,255,0.05); padding: 4px 8px; border-radius: 6px; font-size: 11px; margin-right: 4px; display: inline-block;" title="Jami">
                                                Jami: <?php echo $m['assigned_count']; ?>
                                            </span>
                                            <span style="font-weight: 700; background-color: rgba(245, 158, 11, 0.15); color: #f59e0b; padding: 4px 8px; border-radius: 6px; font-size: 11px; margin-right: 4px; display: inline-block;" title="Jarayonda">
                                                Jarayonda: <?php echo $m['in_progress_count']; ?>
                                            </span>
                                            <span style="font-weight: 700; background-color: rgba(16, 185, 129, 0.15); color: #10b981; padding: 4px 8px; border-radius: 6px; font-size: 11px; margin-right: 4px; display: inline-block;" title="Bajarilgan">
                                                Bajarilgan: <?php echo $m['completed_count']; ?>
                                            </span>
                                            <span style="font-weight: 700; background-color: rgba(239, 68, 68, 0.15); color: #ef4444; padding: 4px 8px; border-radius: 6px; font-size: 11px; display: inline-block;" title="Rad etilgan">
                                                Rad: <?php echo $m['rejected_count']; ?>
                                            </span>
                                        </td>
                                        <td style="text-align: center;">
                                            <a href="masullar.php?delete=<?php echo $m['id']; ?>" 
                                               onclick="return confirm('Haqiqatan ham ushbu mas\'ul xodimni o\'chirmoqchimisiz? Uning biriktirilgan murojaatlari ochiq qoladi.')" 
                                               class="btn btn-danger btn-sm" 
                                               title="O'chirish">
                                                <i class="fa-solid fa-trash-can"></i> O'chirish
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <script>
        function copySetupLink() {
            var copyText = document.getElementById("setupLinkInput");
            copyText.select();
            copyText.setSelectionRange(0, 99999);
            navigator.clipboard.writeText(copyText.value);
            
            var successMsg = document.getElementById("setupCopySuccess");
            if (successMsg) {
                successMsg.style.display = "block";
                setTimeout(function() {
                    successMsg.style.display = "none";
                }, 2500);
            }
        }
    </script>
</body>
</html>
