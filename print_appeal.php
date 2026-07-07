<?php
/**
 * Print Appeal - print_appeal.php
 */
require_once 'config.php';
check_auth();

$appealId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$appealId) {
    die("Noto'g'ri murojaat ID raqami.");
}

try {
    $stmt = $pdo->prepare("SELECT * FROM appeals WHERE id = :id");
    $stmt->execute(['id' => $appealId]);
    $appeal = $stmt->fetch();

    if (!$appeal) {
        die("Murojaat topilmadi.");
    }
} catch (PDOException $e) {
    die("Xatolik yuz berdi: " . $e->getMessage());
}

// Prepare field 6
$info_6 = [];
if (!empty($appeal['gender'])) $info_6[] = $appeal['gender'];
if (!empty($appeal['birth_date'])) $info_6[] = $appeal['birth_date'];
if (!empty($appeal['employment'])) $info_6[] = $appeal['employment'];
$info_6[] = !empty($appeal['company_name']) ? "Yuridik shaxs" : "Jismoniy shaxs";
$info_6_str = implode(', ', $info_6);
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <title>Murojaat Hati #<?php echo htmlspecialchars($appeal['appeal_number']); ?></title>
    <!-- Normal stylesheet for previews, print.css overrides for printing -->
    <link rel="stylesheet" href="assets/css/print.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="print-body">

    <!-- Action Toolbar (Hidden during printing) -->
    <div class="no-print" style="margin-bottom: 20px; padding: 12px; background-color: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; font-family: sans-serif;">
        <span style="font-size: 14px; font-weight: bold; color: #334155;">Hujjatni chop etish oynasi</span>
        <div style="display: flex; gap: 10px;">
            <button onclick="window.print();" style="padding: 8px 16px; background-color: #3b82f6; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: bold;">
                <i class="fa-solid fa-print"></i> Chop etish / PDF saqlash
            </button>
            <button onclick="window.close();" style="padding: 8px 16px; background-color: #e2e8f0; color: #334155; border: 1px solid #cbd5e1; border-radius: 6px; cursor: pointer; font-weight: bold;">
                Yopish
            </button>
        </div>
    </div>

    <!-- Official Document Container -->
    <div class="print-container">
        <div class="document-header">
            <div class="header-text">
                Сирдарё вилояти ҳокимлигига<br>
                murojaat.gov.uz орқали келиб<br>
                тушган мурожаат
            </div>
            
            <!-- RAHBARGA MUROJAAT Logo badge -->
            <div class="logo-container">
                <i class="fa-solid fa-envelope-open-text logo-icon" style="color: #3b82f6;"></i>
                <div>
                    <div style="font-size: 8pt; color: #64748b; line-height: 1;">Rahbarga</div>
                    <div style="font-size: 11pt; color: #1e3a8a; line-height: 1;">Murojaat</div>
                </div>
            </div>
        </div>

        <table class="doc-table">
            <tbody>
                <tr>
                    <td class="num-col">1</td>
                    <td class="label-col">Murojaat tartib raqami</td>
                    <td class="value-col"><strong><?php echo htmlspecialchars($appeal['appeal_number']); ?></strong></td>
                </tr>
                <tr>
                    <td class="num-col">2</td>
                    <td class="label-col">Operator raqami</td>
                    <td class="value-col">murojaat-gov</td>
                </tr>
                <tr>
                    <td class="num-col">3.1</td>
                    <td class="label-col">Murojaat tushgan sana va vaqt</td>
                    <td class="value-col"><?php echo date('d.m.Y H:i:s', strtotime($appeal['submitted_at'])); ?></td>
                </tr>
                <tr>
                    <td class="num-col">3.2</td>
                    <td class="label-col">Tashkilotga kelib tushgan sana va vaqt</td>
                    <td class="value-col"><?php echo date('d.m.Y H:i:s', strtotime($appeal['received_at'])); ?></td>
                </tr>
                <tr>
                    <td class="num-col">4</td>
                    <td class="label-col">Murojaat etuvchi F.I.O.</td>
                    <td class="value-col" style="text-transform: uppercase; font-weight: bold;"><?php echo htmlspecialchars($appeal['fio']); ?></td>
                </tr>
                <tr>
                    <td class="num-col">5</td>
                    <td class="label-col">Murojaat etuvchining manzili</td>
                    <td class="value-col"><?php echo htmlspecialchars($appeal['address']); ?></td>
                </tr>
                <tr>
                    <td class="num-col">6</td>
                    <td class="label-col">Murojaat etuvchi jinsi, tug'ilgan yili va bandligi</td>
                    <td class="value-col"><?php echo htmlspecialchars($info_6_str); ?></td>
                </tr>
                <tr>
                    <td class="num-col">7</td>
                    <td class="label-col">Yuridik shaxs (tadbirkorlik subyekti) nomi</td>
                    <td class="value-col"><?php echo $appeal['company_name'] ? htmlspecialchars($appeal['company_name']) : '—'; ?></td>
                </tr>
                <tr>
                    <td class="num-col">8.1</td>
                    <td class="label-col">Telefon raqami</td>
                    <td class="value-col"><?php echo htmlspecialchars($appeal['phone_1']); ?></td>
                </tr>
                <tr>
                    <td class="num-col">8.2</td>
                    <td class="label-col">Qo'shimcha telefon raqami</td>
                    <td class="value-col"><?php echo $appeal['phone_2'] ? htmlspecialchars($appeal['phone_2']) : '—'; ?></td>
                </tr>
                <tr>
                    <td class="num-col">9</td>
                    <td class="label-col">Elektron manzili</td>
                    <td class="value-col">
                        <?php 
                        if ($appeal['email']) echo htmlspecialchars($appeal['email']);
                        elseif ($appeal['telegram_profile']) echo htmlspecialchars($appeal['telegram_profile']);
                        else echo '—';
                        ?>
                    </td>
                </tr>
                <tr>
                    <td class="num-col">10</td>
                    <td class="label-col" colspan="2" style="text-align: center; font-weight: bold; text-transform: uppercase;">Murojaatning qisqacha mazmuni</td>
                </tr>
                <tr>
                    <td colspan="3" style="padding: 15px 20px;">
                        <div class="content-block"><?php echo htmlspecialchars($appeal['content']); ?></div>
                    </td>
                </tr>
                <?php if ($appeal['response_text']): ?>
                    <tr>
                        <td class="num-col">11.1</td>
                        <td class="label-col">Javob berilgan sana va vaqt</td>
                        <td class="value-col"><?php echo date('d.m.Y H:i:s', strtotime($appeal['responded_at'])); ?></td>
                    </tr>
                    <tr>
                        <td class="num-col">11.2</td>
                        <td class="label-col" colspan="2" style="text-align: center; font-weight: bold; text-transform: uppercase;">Murojaatga yuborilgan javob matni</td>
                    </tr>
                    <tr>
                        <td colspan="3" style="padding: 15px 20px;">
                            <div class="content-block"><?php echo htmlspecialchars($appeal['response_text']); ?></div>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</body>
</html>
