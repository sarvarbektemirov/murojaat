<?php
/**
 * Public Landing Page - landing.php
 */
require_once 'config.php';

// Dynamically fetch Telegram Bot Username using BOT_TOKEN if available
$botUsername = '';
if (defined('BOT_TOKEN') && !empty(BOT_TOKEN)) {
    if (!isset($_SESSION['bot_username'])) {
        $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/getMe");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        $res = curl_exec($ch);
        curl_close($ch);
        if ($res) {
            $json = json_decode($res, true);
            if (isset($json['ok']) && $json['ok']) {
                $_SESSION['bot_username'] = $json['result']['username'];
            }
        }
    }
    if (isset($_SESSION['bot_username'])) {
        $botUsername = $_SESSION['bot_username'];
    }
}
$botUrl = !empty($botUsername) ? "https://t.me/" . $botUsername : "#";
?>
<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Murojaatlar Portali | Sardoba Tumani Hokimligi</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Landing Page specific premium overrides and glassmorphism styling */
        body.landing-body {
            display: block;
            background: radial-gradient(circle at 10% 20%, rgba(15, 23, 42, 1) 0%, rgba(9, 15, 30, 1) 90%);
            color: #f8fafc;
            min-height: 100vh;
            overflow-x: hidden;
            position: relative;
        }

        /* Ambient background glow shapes */
        .glow-orb-1 {
            position: absolute;
            top: -10%;
            right: -10%;
            width: 50vw;
            height: 50vw;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.08) 0%, rgba(0, 0, 0, 0) 70%);
            z-index: 0;
            pointer-events: none;
        }
        
        .glow-orb-2 {
            position: absolute;
            bottom: -10%;
            left: -10%;
            width: 40vw;
            height: 40vw;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(16, 185, 129, 0.05) 0%, rgba(0, 0, 0, 0) 70%);
            z-index: 0;
            pointer-events: none;
        }

        .landing-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 24px 8%;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .landing-logo {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: #fff;
        }

        .landing-logo i {
            font-size: 26px;
            color: var(--primary-color);
            text-shadow: 0 0 15px rgba(59, 130, 246, 0.5);
        }

        .landing-logo span {
            font-size: 20px;
            font-weight: 800;
            letter-spacing: -0.5px;
            background: linear-gradient(to right, #ffffff, #94a3b8);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 32px;
        }

        .nav-link {
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 15px;
            font-weight: 600;
            transition: color 0.2s ease;
        }

        .nav-link:hover {
            color: #fff;
        }

        .hero-section {
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            align-items: center;
            padding: 80px 8%;
            gap: 60px;
            z-index: 10;
            position: relative;
        }

        .hero-content {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }

        .badge-welcome {
            background: rgba(59, 130, 246, 0.1);
            border: 1px solid rgba(59, 130, 246, 0.2);
            color: #60a5fa;
            padding: 8px 16px;
            border-radius: 100px;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 24px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            letter-spacing: 0.5px;
        }

        .hero-title {
            font-size: 48px;
            font-weight: 800;
            line-height: 1.15;
            letter-spacing: -1.5px;
            margin-bottom: 24px;
            background: linear-gradient(135deg, #ffffff 30%, #94a3b8 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero-title span {
            background: linear-gradient(135deg, #60a5fa, #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero-desc {
            font-size: 17px;
            line-height: 1.7;
            color: var(--text-secondary);
            margin-bottom: 40px;
            max-width: 600px;
        }

        .hero-actions {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }

        .btn-telegram {
            background: linear-gradient(135deg, #38bdf8, #0284c7);
            color: #fff;
            box-shadow: 0 4px 20px rgba(56, 189, 248, 0.25);
            font-size: 15px;
            padding: 12px 24px;
        }

        .btn-telegram:hover {
            background: linear-gradient(135deg, #0ea5e9, #0369a1);
            transform: translateY(-2px);
            box-shadow: 0 6px 24px rgba(56, 189, 248, 0.4);
        }

        .hero-visual {
            display: flex;
            justify-content: center;
            position: relative;
        }

        /* Glassmorphic floating card animation */
        .floating-card {
            background: rgba(30, 41, 59, 0.45);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 28px;
            padding: 32px;
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            box-shadow: 0 30px 50px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 360px;
            animation: floatUpDown 6s ease-in-out infinite;
            position: relative;
        }

        @keyframes floatUpDown {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-15px); }
        }

        .bot-status-indicator {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            font-size: 13px;
            font-weight: 700;
            color: var(--success);
        }

        .bot-status-dot {
            width: 8px;
            height: 8px;
            background-color: var(--success);
            border-radius: 50%;
            box-shadow: 0 0 10px var(--success);
            animation: pulseDot 2s infinite;
        }

        @keyframes pulseDot {
            0%, 100% { opacity: 0.5; }
            50% { opacity: 1; }
        }

        /* Features Section */
        .features-section {
            padding: 80px 8%;
            text-align: center;
            background: rgba(15, 23, 42, 0.3);
            border-top: 1px solid rgba(255, 255, 255, 0.03);
            z-index: 10;
            position: relative;
        }

        .section-header {
            margin-bottom: 60px;
        }

        .section-header h2 {
            font-size: 32px;
            font-weight: 800;
            margin-bottom: 12px;
            letter-spacing: -0.5px;
        }

        .section-header p {
            color: var(--text-secondary);
            font-size: 15px;
            max-width: 600px;
            margin: 0 auto;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 28px;
        }

        .feature-card {
            background: rgba(30, 41, 59, 0.3);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 20px;
            padding: 32px;
            text-align: left;
            transition: all 0.3s ease;
        }

        .feature-card:hover {
            transform: translateY(-8px);
            background: rgba(30, 41, 59, 0.55);
            border-color: rgba(59, 130, 246, 0.25);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.2);
        }

        .feature-card i {
            font-size: 32px;
            color: var(--primary-color);
            margin-bottom: 24px;
            background: rgba(59, 130, 246, 0.1);
            padding: 14px;
            border-radius: 14px;
            display: inline-block;
        }

        .feature-card h3 {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 12px;
            color: #fff;
        }

        .feature-card p {
            color: var(--text-secondary);
            font-size: 14px;
            line-height: 1.6;
        }

        .landing-footer {
            text-align: center;
            padding: 40px 8%;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
            color: var(--text-secondary);
            font-size: 13px;
            z-index: 10;
            position: relative;
        }

        /* Responsive styling */
        @media (max-width: 992px) {
            .hero-section {
                grid-template-columns: 1fr;
                text-align: center;
                padding-top: 40px;
            }
            .hero-content {
                align-items: center;
            }
            .hero-actions {
                justify-content: center;
            }
            .hero-visual {
                margin-top: 40px;
            }
        }
    </style>
</head>
<body class="landing-body">

    <!-- Glowing effect backgrounds -->
    <div class="glow-orb-1"></div>
    <div class="glow-orb-2"></div>

    <!-- Header Navigation -->
    <header class="landing-header">
        <a href="index.php" class="landing-logo">
            <i class="fa-solid fa-envelope-open-text"></i>
            <span>Sardoba Hokimligi</span>
        </a>
        <nav class="nav-links">
            <a href="login.php" class="btn btn-outline" style="border-radius: 12px; padding: 10px 20px;">
                <i class="fa-solid fa-right-to-bracket"></i> Xodimlar kirishi
            </a>
        </nav>
    </header>

    <!-- Hero Area -->
    <section class="hero-section">
        <div class="hero-content">
            <div class="badge-welcome">
                <i class="fa-solid fa-circle-check"></i> Rasmiy murojaatlar portali
            </div>
            <h1 class="hero-title">
                Sardoba tumani hokimligi <span>murojaatlar boshqaruv portali</span>
            </h1>
            <p class="hero-desc">
                Tuman aholisi tomonidan yuborilayotgan murojaatlarni samarali qabul qilish, mas'ul xodimlarga yo'naltirish, ijrosini nazorat qilish va javob xatlarini fuqarolarga Telegram orqali yuborish uchun yagona elektron tizim.
            </p>
            <div class="hero-actions">
                <a href="<?php echo htmlspecialchars($botUrl); ?>" target="_blank" class="btn btn-telegram">
                    <i class="fa-brands fa-telegram" style="font-size: 18px;"></i> Telegram botni ochish
                </a>
                <a href="login.php" class="btn btn-primary" style="padding: 12px 24px; font-size: 15px;">
                    <i class="fa-solid fa-user-shield"></i> Tizimga kirish
                </a>
                <a href="downloads/murojaatbot-setup.exe" class="btn btn-outline" style="padding: 12px 24px; font-size: 15px; border-radius: 12px; display: inline-flex; align-items: center; gap: 8px; border: 1px solid rgba(255,255,255,0.15);" download>
                    <i class="fa-solid fa-desktop"></i> Desktop yuklash
                </a>
            </div>
        </div>

        <div class="hero-visual">
            <div class="floating-card">
                <div class="bot-status-indicator">
                    <span class="bot-status-dot"></span>
                    <span>Tizim faol: @<?php echo htmlspecialchars($botUsername); ?></span>
                </div>
                <h3 style="font-size: 20px; font-weight: 700; margin-bottom: 12px; color: #fff;">Fuqarolar uchun qulaylik</h3>
                <p style="font-size: 14px; line-height: 1.6; color: var(--text-secondary); margin-bottom: 20px;">
                    Uyingizdan chiqmasdan turib hokimlikka rasmiy murojaat yo'llang, mas'ul xodimlar tayinlanishi va javoblarni to'g'ridan-to'g'ri Telegram orqali oling.
                </p>
                <div style="background-color: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.05); padding: 12px 16px; border-radius: 12px; font-size: 13px; color: var(--text-secondary);">
                    <i class="fa-solid fa-shield-halved" style="color: var(--primary-color); margin-right: 8px;"></i>
                    Hujjatlar qat'iy nazorat ostida
                </div>
            </div>
        </div>
    </section>

    <!-- Features Area -->
    <section class="features-section">
        <div class="section-header">
            <h2>Portalning imkoniyatlari</h2>
            <p>Murojaatlar bilan ishlash jarayonini to'liq raqamlashtirish va shaffoflikni ta'minlash</p>
        </div>
        <div class="features-grid">
            <div class="feature-card">
                <i class="fa-solid fa-robot"></i>
                <h3>Tezkor Telegram Bot</h3>
                <p>Fuqarolar 24/7 rejimda rasm, audio, video va hujjatlar ilova qilgan holda murojaatlarni tez yuborishadi.</p>
            </div>
            <div class="feature-card">
                <i class="fa-solid fa-arrows-split-up-and-left"></i>
                <h3>Avtomatik yo'naltirish</h3>
                <p>Kelgan murojaatlar kantselyariya admini tomonidan tegishli bo'lim mas'ul xodimlariga zudlik bilan yo'naltiriladi.</p>
            </div>
            <div class="feature-card">
                <i class="fa-solid fa-chart-line"></i>
                <h3>Nazorat va Tahlil</h3>
                <p>Tuman hokimi va mas'ullar ishlar samaradorligini, muddatlarni hamda jami bajarilgan murojaatlar tahlilini kuzatishadi.</p>
            </div>
            <div class="feature-card">
                <i class="fa-solid fa-file-shield"></i>
                <h3>Qonuniy kafolat</h3>
                <p>Tasdiqlangan javob xatlari tizimda muhrlanib, bot orqali murojaatchiga yetkaziladi va uning ijro tarixi to'liq saqlanadi.</p>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="landing-footer">
        <p>&copy; <?php echo date('Y'); ?> Sardoba tumani hokimligi. Barcha huquqlar himoyalangan.</p>
        <p style="margin-top: 8px; font-size: 11px; opacity: 0.6;">Tizim loyihasi aholi murojaatlari bilan ishlash samaradorligini oshirish maqsadida ishlab chiqilgan.</p>
    </footer>

</body>
</html>
