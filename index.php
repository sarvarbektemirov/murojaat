<?php
/**
 * Dashboard Page - index.php
 */
require_once 'config.php';

// If not logged in, show the beautiful landing page
if (!isset($_SESSION['user_id'])) {
    include_once 'landing.php';
    exit;
}

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

// Initialize counts
$totalCount = 0;
$newCount = 0;
$inProgressCount = 0;
$completedCount = 0;
$rejectedCount = 0;

try {
    // Queries adjust dynamically based on user role
    if ($userRole === 'admin' || $userRole === 'hokim') {
        // Hokim and Admin can see all stats
        $stmtTotal = $pdo->query("SELECT COUNT(*) FROM appeals");
        $stmtNew = $pdo->query("SELECT COUNT(*) FROM appeals WHERE status = 'yangi'");
        $stmtProgress = $pdo->query("SELECT COUNT(*) FROM appeals WHERE status IN ('masul_tayinlandi', 'tasdiqlash_kutilmoqda', 'qaytarildi')");
        $stmtCompleted = $pdo->query("SELECT COUNT(*) FROM appeals WHERE status = 'korib_chiqildi'");
        $stmtRejected = $pdo->query("SELECT COUNT(*) FROM appeals WHERE status = 'rad_etildi'");
        
        $recentStmt = $pdo->query("SELECT a.*, w.fio as masul_name FROM appeals a LEFT JOIN web_users w ON a.assigned_to = w.id ORDER BY a.id DESC LIMIT 5");
    } else {
        // Mas'ul can only see stats assigned to them
        $stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM appeals WHERE assigned_to = :userId");
        $stmtTotal->execute(['userId' => $userId]);
        
        $stmtNew = $pdo->prepare("SELECT COUNT(*) FROM appeals WHERE assigned_to = :userId AND status = 'yangi'");
        $stmtNew->execute(['userId' => $userId]);
        
        $stmtProgress = $pdo->prepare("SELECT COUNT(*) FROM appeals WHERE assigned_to = :userId AND status IN ('masul_tayinlandi', 'tasdiqlash_kutilmoqda', 'qaytarildi')");
        $stmtProgress->execute(['userId' => $userId]);
        
        $stmtCompleted = $pdo->prepare("SELECT COUNT(*) FROM appeals WHERE assigned_to = :userId AND status = 'korib_chiqildi'");
        $stmtCompleted->execute(['userId' => $userId]);
        
        $stmtRejected = $pdo->prepare("SELECT COUNT(*) FROM appeals WHERE assigned_to = :userId AND status = 'rad_etildi'");
        $stmtRejected->execute(['userId' => $userId]);
        
        $recentStmt = $pdo->prepare("SELECT a.*, w.fio as masul_name FROM appeals a LEFT JOIN web_users w ON a.assigned_to = w.id WHERE a.assigned_to = :userId ORDER BY a.id DESC LIMIT 5");
        $recentStmt->execute(['userId' => $userId]);
    }

    $totalCount = $stmtTotal->fetchColumn();
    $newCount = $stmtNew->fetchColumn();
    $inProgressCount = $stmtProgress->fetchColumn();
    $completedCount = $stmtCompleted->fetchColumn();
    $rejectedCount = $stmtRejected->fetchColumn();
    
    $recentAppeals = $recentStmt->fetchAll();
} catch (PDOException $e) {
    die("Xatolik yuz berdi: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bosh sahifa | Sardoba Hokimligi Murojaat Bot</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Sidebar navigation -->
    <?php include_once 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <div>
                <h1 class="page-title">Bosh sahifa</h1>
                <div class="page-subtitle">
                    <?php if ($userRole === 'hokim'): ?>
                        Tuman bo'yicha kelib tushgan barcha murojaatlarning umumiy tahlili va nazorati
                    <?php elseif ($userRole === 'admin'): ?>
                        Murojaatlarni ro'yxatga olish, yo'naltirish va umumiy boshqaruv paneli
                    <?php else: ?>
                        Sizga biriktirilgan murojaatlar va ularning ijro holati
                    <?php endif; ?>
                </div>
            </div>
            <div style="display: flex; gap: 12px; align-items: center;">
                <a href="downloads/murojaatbot-setup.exe" class="btn btn-outline" download>
                    <i class="fa-solid fa-desktop"></i> Desktop ilova
                </a>
                <a href="appeals.php" class="btn btn-primary">
                    <i class="fa-solid fa-list"></i> Barcha murojaatlar
                </a>
            </div>
        </div>

        <!-- Metric Cards -->
        <div class="metrics-grid">
            <div class="metric-card">
                <div class="metric-icon blue">
                    <i class="fa-solid fa-inbox"></i>
                </div>
                <div class="metric-data">
                    <span class="metric-value"><?php echo $totalCount; ?></span>
                    <span class="metric-label">Jami murojaatlar</span>
                </div>
            </div>

            <div class="metric-card">
                <div class="metric-icon info">
                    <i class="fa-solid fa-envelope-open-text"></i>
                </div>
                <div class="metric-data">
                    <span class="metric-value"><?php echo $newCount; ?></span>
                    <span class="metric-label">Yangi kelganlar</span>
                </div>
            </div>

            <div class="metric-card">
                <div class="metric-icon yellow">
                    <i class="fa-solid fa-spinner"></i>
                </div>
                <div class="metric-data">
                    <span class="metric-value"><?php echo $inProgressCount; ?></span>
                    <span class="metric-label">Ko'rib chiqilmoqda</span>
                </div>
            </div>

            <div class="metric-card">
                <div class="metric-icon emerald">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div class="metric-data">
                    <span class="metric-value"><?php echo $completedCount; ?></span>
                    <span class="metric-label">Bajarilganlar</span>
                </div>
            </div>
        </div>

        <!-- Visual Analytics Block -->
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 32px; margin-bottom: 40px;">
            <!-- Recent Appeals Card -->
            <div class="data-card" style="margin-bottom: 0;">
                <div class="card-header">
                    <h3 class="card-title">Oxirgi murojaatlar</h3>
                    <a href="appeals.php" class="btn btn-outline btn-sm">Barchasini ko'rish</a>
                </div>
                
                <div class="custom-table-container">
                    <table class="custom-table">
                        <thead>
                            <tr>
                                <th>Tartib raqam</th>
                                <th>Murojaatchi F.I.Sh</th>
                                <th>Kelib tushgan sana</th>
                                <th>Javob sanasi</th>
                                <th>Status</th>
                                <th>Mas'ul shaxs</th>
                                <th>Amal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentAppeals)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; color: var(--text-secondary);">Murojaatlar mavjud emas.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentAppeals as $appeal): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($appeal['appeal_number']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($appeal['fio']); ?></td>
                                        <td><?php echo date('d.m.Y H:i', strtotime($appeal['submitted_at'])); ?></td>
                                        <td>
                                            <?php 
                                            echo $appeal['responded_at'] ? date('d.m.Y H:i', strtotime($appeal['responded_at'])) : '<em style="color: var(--text-secondary);">—</em>'; 
                                            ?>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo htmlspecialchars($appeal['status']); ?>">
                                                <?php 
                                                if ($appeal['status'] === 'yangi') echo "Yangi";
                                                elseif ($appeal['status'] === 'masul_tayinlandi') echo "Jarayonda";
                                                elseif ($appeal['status'] === 'tasdiqlash_kutilmoqda') echo "Kutilmoqda";
                                                elseif ($appeal['status'] === 'qaytarildi') echo "Qayta ishlashda";
                                                elseif ($appeal['status'] === 'korib_chiqildi') echo "Bajarildi";
                                                elseif ($appeal['status'] === 'rad_etildi') echo "Rad etildi";
                                                ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo $appeal['masul_name'] ? htmlspecialchars($appeal['masul_name']) : '<em style="color: var(--text-secondary);">Tayinlanmagan</em>'; ?>
                                        </td>
                                        <td>
                                            <a href="view_appeal.php?id=<?php echo $appeal['id']; ?>" class="btn btn-outline btn-sm">
                                                <i class="fa-solid fa-eye"></i> Ochish
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Monitoring Stat Card -->
            <div class="data-card" style="margin-bottom: 0; display: flex; flex-direction: column;">
                <div class="card-header">
                    <h3 class="card-title">Ijro samaradorligi</h3>
                </div>
                <div style="flex: 1; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; padding: 20px;">
                    <?php 
                    $efficiency = $totalCount > 0 ? round(($completedCount / $totalCount) * 100) : 0;
                    ?>
                    <div style="position: relative; width: 140px; height: 140px; border-radius: 50%; background: radial-gradient(closest-side, var(--card-bg) 79%, transparent 80% 100%), conic-gradient(var(--success) <?php echo $efficiency; ?>%, var(--border-color) 0); display: flex; align-items: center; justify-content: center; margin-bottom: 20px;">
                        <span style="font-size: 32px; font-weight: 800; color: var(--text-primary);"><?php echo $efficiency; ?>%</span>
                    </div>
                    <p style="font-size: 14px; color: var(--text-secondary); line-height: 1.5;">
                        Aholi tomonidan yuborilgan murojaatlarning umumiy bajarilish ko'rsatkichi.
                    </p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
