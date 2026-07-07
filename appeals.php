<?php
/**
 * Appeals List Page - appeals.php
 */
require_once 'config.php';
check_auth();

$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

// Filters
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? '');

$query = "SELECT a.*, w.fio as masul_name FROM appeals a LEFT JOIN web_users w ON a.assigned_to = w.id WHERE 1=1";
$params = [];

// Role restriction
if ($userRole === 'masul') {
    $query .= " AND a.assigned_to = :userId";
    $params['userId'] = $userId;
}

// Search filter
if ($search !== '') {
    $query .= " AND (a.appeal_number LIKE :search OR a.fio LIKE :search OR a.phone_1 LIKE :search OR a.phone_2 LIKE :search)";
    $params['search'] = "%$search%";
}

// Status filter
if ($status !== '') {
    $query .= " AND a.status = :status";
    $params['status'] = $status;
}

// Ordering
$query .= " ORDER BY a.id DESC";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $appeals = $stmt->fetchAll();
} catch (PDOException $e) {
    die("Xatolik yuz berdi: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Murojaatlar Ro'yxati | Sardoba Hokimligi Murojaat Bot</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Sidebar navigation -->
    <?php include_once 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="page-header">
            <div>
                <h1 class="page-title">Murojaatlar ro'yxati</h1>
                <div class="page-subtitle">Tizimga kelib tushgan barcha murojaatlar ustida qidiruv va boshqaruv</div>
            </div>
        </div>

        <div class="data-card">
            <!-- Filter Controls -->
            <form action="appeals.php" method="GET" class="filter-form">
                <div class="form-group">
                    <label class="form-label" for="search">Qidiruv (F.I.Sh, Raqam, Telefon)</label>
                    <input class="form-control" type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Kiriting...">
                </div>

                <div class="form-group">
                    <label class="form-label" for="status">Murojaat holati (Status)</label>
                    <select class="form-control" id="status" name="status">
                        <option value="">Barchasi</option>
                        <option value="yangi" <?php echo $status === 'yangi' ? 'selected' : ''; ?>>Yangi</option>
                        <option value="masul_tayinlandi" <?php echo $status === 'masul_tayinlandi' ? 'selected' : ''; ?>>Ko'rib chiqilmoqda (Jarayonda)</option>
                        <option value="tasdiqlash_kutilmoqda" <?php echo $status === 'tasdiqlash_kutilmoqda' ? 'selected' : ''; ?>>Tasdiqlash kutilmoqda</option>
                        <option value="qaytarildi" <?php echo $status === 'qaytarildi' ? 'selected' : ''; ?>>Qayta ishlashda</option>
                        <option value="korib_chiqildi" <?php echo $status === 'korib_chiqildi' ? 'selected' : ''; ?>>Bajarildi</option>
                        <option value="rad_etildi" <?php echo $status === 'rad_etildi' ? 'selected' : ''; ?>>Rad etildi</option>
                    </select>
                </div>

                <div class="form-group" style="justify-content: flex-end;">
                    <button type="submit" class="btn btn-primary" style="padding: 10px 24px;">
                        <i class="fa-solid fa-filter"></i> Filtrlash
                    </button>
                    <?php if ($search !== '' || $status !== ''): ?>
                        <a href="appeals.php" class="btn btn-outline" style="padding: 10px 18px;" title="Filtrlarni tozalash">
                            <i class="fa-solid fa-arrow-rotate-left"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Table of Appeals -->
            <div class="custom-table-container">
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>Tartib raqam</th>
                            <th>Murojaatchi F.I.Sh</th>
                            <th>Manzili</th>
                            <th>Telefon raqamlari</th>
                            <th>Kelib tushgan sana</th>
                            <th>Javob sanasi</th>
                            <th>Status</th>
                            <th>Mas'ul shaxs</th>
                            <th style="text-align: center;">Amallar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($appeals)): ?>
                            <tr>
                                <td colspan="9" style="text-align: center; color: var(--text-secondary); padding: 30px;">
                                    Filtrlarga mos keluvchi murojaatlar topilmadi.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($appeals as $appeal): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($appeal['appeal_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($appeal['fio']); ?></td>
                                    <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        <?php echo htmlspecialchars($appeal['address']); ?>
                                    </td>
                                    <td>
                                        <?php 
                                        echo htmlspecialchars($appeal['phone_1']); 
                                        if ($appeal['phone_2'] && $appeal['phone_2'] !== $appeal['phone_1']) {
                                            echo "<br><span style='font-size: 12px; color: var(--text-secondary);'>" . htmlspecialchars($appeal['phone_2']) . "</span>";
                                        }
                                        ?>
                                    </td>
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
                                    <td style="text-align: center;">
                                        <div style="display: flex; gap: 8px; justify-content: center;">
                                            <a href="view_appeal.php?id=<?php echo $appeal['id']; ?>" class="btn btn-outline btn-sm" title="Ochish">
                                                <i class="fa-solid fa-eye"></i>
                                            </a>
                                            <a href="print_appeal.php?id=<?php echo $appeal['id']; ?>" target="_blank" class="btn btn-outline btn-sm" title="Chop etish andozasi">
                                                <i class="fa-solid fa-print"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
