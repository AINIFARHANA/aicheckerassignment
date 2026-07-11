<?php

session_start();

// DATABASE CONNECTION
$servername = "localhost";
$username   = "root";
$password   = "";               // Default XAMPP password
$dbname     = "assignment_db";  // Your local database name

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// FETCH STATISTICS
 $stats = [
    'users' => 0,
    'assignments' => 0,
    'reports' => 0,
    'vouchers' => 0
];

function getSafeCount($conn, $sql) {
    if (!$conn) return 0;
    $result = $conn->query($sql);
    if ($result && $row = $result->fetch_row()) {
        return $row[0];
    }
    return 0;
}

 $stats['users'] = getSafeCount($conn, "SELECT COUNT(*) FROM users");
 $stats['assignments'] = getSafeCount($conn, "SELECT COUNT(*) FROM assignments");
 $stats['reports'] = getSafeCount($conn, "SELECT COUNT(*) FROM plag_ai WHERE status='Approved'");
 $stats['vouchers'] = getSafeCount($conn, "SELECT COUNT(*) FROM vouchers WHERE status='Active'");

// Check if user is logged in and fetch Avatar
 $isLoggedIn = isset($_SESSION['user_id']);
 $userName = isset($_SESSION['username']) ? $_SESSION['username'] : "User";
 $userAvatarSeed = "Felix";

if ($isLoggedIn && $conn) {
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT avatar FROM users WHERE user_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            if (!empty($row['avatar'])) {
                $userAvatarSeed = $row['avatar'];
            }
        }
        $stmt->close();
    }
}


// USER NOTIFICATIONS FOR LANDING-PAGE NAVBAR
$userNotifications = [];
$userUnreadCount = 0;

if ($isLoggedIn && $conn) {
    $notificationStmt = $conn->prepare(
        "SELECT notification_id, message, is_read, created_at
         FROM notifications
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 20"
    );

    if ($notificationStmt) {
        $notificationStmt->bind_param("i", $user_id);
        $notificationStmt->execute();
        $notificationResult = $notificationStmt->get_result();

        while ($notificationResult && ($notification = $notificationResult->fetch_assoc())) {
            $userNotifications[] = $notification;
            if ((int)$notification['is_read'] === 0) {
                $userUnreadCount++;
            }
        }
        $notificationStmt->close();
    }
}

if (!function_exists('landing_notification_time_ago')) {
    function landing_notification_time_ago($datetime) {
        if (empty($datetime)) return '';

        try {
            $now = new DateTime();
            $ago = new DateTime($datetime);
            $diff = $now->diff($ago);

            if ($diff->y > 0) return $diff->y . 'y ago';
            if ($diff->m > 0) return $diff->m . 'mo ago';
            if ($diff->d > 0) return $diff->d . 'd ago';
            if ($diff->h > 0) return $diff->h . 'h ago';
            if ($diff->i > 0) return $diff->i . 'm ago';
            return 'Just now';
        } catch (Throwable $e) {
            return '';
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Our Services - AI Assignment Checker</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #3B1347;
            --primary-rgb: 59, 19, 71;
            --primary-light: #F4D1FF;
            --primary-light-rgb: 244, 209, 255;
            --primary-mid: #7B3F91;
            --primary-soft: #5A1F6B;
            --accent: #D88FFF;
            --accent-rose: #FF8EC4;
            --accent-gold: #FFD6A5;
            --bg-deep: #0D0612;
            --bg-dark: #150A1E;
            --bg-card: rgba(59, 19, 71, 0.35);
            --bg-card-hover: rgba(59, 19, 71, 0.55);
            --bg-glass: rgba(244, 209, 255, 0.04);
            --bg-glass-strong: rgba(244, 209, 255, 0.08);
            --text-bright: #FFFFFF;
            --text-light: #F4D1FF;
            --text-body: rgba(244, 209, 255, 0.72);
            --text-muted: rgba(244, 209, 255, 0.4);
            --border-subtle: rgba(244, 209, 255, 0.08);
            --border-glow: rgba(244, 209, 255, 0.15);
            --border-active: rgba(216, 143, 255, 0.4);
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.3);
            --shadow-md: 0 8px 32px rgba(0, 0, 0, 0.4);
            --shadow-lg: 0 20px 60px rgba(0, 0, 0, 0.5);
            --shadow-glow: 0 0 40px rgba(244, 209, 255, 0.08);
            --shadow-glow-strong: 0 0 60px rgba(216, 143, 255, 0.15);
            --radius-sm: 12px;
            --radius-md: 20px;
            --radius-lg: 28px;
            --radius-xl: 40px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Poppins', sans-serif;
            color: var(--text-body);
            overflow-x: hidden;
            background: var(--bg-deep);
            -webkit-font-smoothing: antialiased;
        }

        ::selection { background: var(--primary-mid); color: white; }
        .text-purple { color: var(--accent) !important; }
        .bg-purple { background-color: var(--primary-mid) !important; }
        .bg-gradient-purple {
            background: linear-gradient(135deg, #3B1347 0%, #5A1F6B 30%, #7B3F91 60%, #3B1347 100%);
        }

        /* --- Animated Starfield Canvas --- */
        #starfield {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            z-index: 0;
            pointer-events: none;
        }

        /* --- Aurora Background Blobs --- */
        .aurora-wrap {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            z-index: 0;
            pointer-events: none;
            overflow: hidden;
        }
        .aurora-blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(120px);
            opacity: 0.3;
            animation: auroraDrift 20s ease-in-out infinite;
        }
        .aurora-blob:nth-child(1) {
            width: 600px; height: 600px;
            background: radial-gradient(circle, rgba(59,19,71,0.8), transparent 70%);
            top: -10%; left: -10%;
            animation-duration: 25s;
        }
        .aurora-blob:nth-child(2) {
            width: 500px; height: 500px;
            background: radial-gradient(circle, rgba(123,63,145,0.5), transparent 70%);
            top: 30%; right: -15%;
            animation-duration: 30s;
            animation-delay: -5s;
        }
        .aurora-blob:nth-child(3) {
            width: 400px; height: 400px;
            background: radial-gradient(circle, rgba(244,209,255,0.15), transparent 70%);
            bottom: -5%; left: 30%;
            animation-duration: 22s;
            animation-delay: -10s;
        }
        .aurora-blob:nth-child(4) {
            width: 350px; height: 350px;
            background: radial-gradient(circle, rgba(216,143,255,0.12), transparent 70%);
            top: 60%; left: -5%;
            animation-duration: 28s;
            animation-delay: -8s;
        }
        @keyframes auroraDrift {
            0%, 100% { transform: translate(0, 0) scale(1); }
            25% { transform: translate(40px, -30px) scale(1.08); }
            50% { transform: translate(-20px, 20px) scale(0.95); }
            75% { transform: translate(30px, 15px) scale(1.05); }
        }

        /* All sections must be above the fixed bg */
        nav, section, footer, .cta-section {
            position: relative;
            z-index: 2;
        }

        /* --- Buttons --- */
        .btn-purple {
            background: linear-gradient(135deg, var(--primary-mid), var(--accent));
            color: white; border: none;
            padding: 14px 36px; border-radius: 60px;
            font-weight: 600; font-size: 0.95rem;
            letter-spacing: 0.02em;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 24px rgba(123,63,145,0.4), inset 0 1px 0 rgba(255,255,255,0.1);
            position: relative; overflow: hidden;
            text-decoration: none; display: inline-block;
        }
        .btn-purple::before {
            content: ''; position: absolute;
            top: 0; left: -100%; width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(244,209,255,0.2), transparent);
            transition: left 0.6s;
        }
        .btn-purple:hover::before { left: 100%; }
        .btn-purple:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 8px 40px rgba(123,63,145,0.6), 0 0 20px rgba(244,209,255,0.1);
            color: white;
        }

        .btn-outline-purple {
            border: 1.5px solid var(--border-glow);
            color: var(--primary-light);
            padding: 12px 36px; border-radius: 60px;
            font-weight: 600; font-size: 0.95rem;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none; display: inline-block;
            background: rgba(244, 209, 255, 0.04);
            backdrop-filter: blur(8px);
        }
        .btn-outline-purple:hover {
            background: var(--primary-mid); color: white; text-decoration: none;
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(123,63,145,0.4), 0 0 15px rgba(244,209,255,0.08);
            border-color: var(--primary-mid);
        }

        /* --- Navbar --- */
        .navbar {
            background: rgba(13, 6, 18, 0.6);
            backdrop-filter: blur(24px) saturate(180%);
            -webkit-backdrop-filter: blur(24px) saturate(180%);
            border-bottom: 1px solid var(--border-subtle);
            padding: 14px 0; transition: all 0.4s ease;
        }
        .navbar.scrolled {
            padding: 8px 0;
            background: rgba(13, 6, 18, 0.88);
            box-shadow: 0 4px 40px rgba(0, 0, 0, 0.4), 0 0 30px rgba(244,209,255,0.03);
            border-bottom-color: var(--border-glow);
        }
        .navbar-brand {
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 700; font-size: 1.35rem;
            color: var(--primary-light) !important;
            letter-spacing: -0.02em;
            text-shadow: 0 0 20px rgba(244,209,255,0.2);
        }
        .navbar-brand img { height: 38px; }
        .nav-link {
            font-weight: 500; color: var(--text-body) !important;
            margin: 0 4px; text-decoration: none; font-size: 0.88rem;
            padding: 8px 14px !important; border-radius: 10px;
            transition: all 0.3s ease;
        }
        .nav-link:hover, .nav-link.active {
            color: var(--primary-light) !important;
            background: rgba(244, 209, 255, 0.08);
            text-shadow: 0 0 12px rgba(244,209,255,0.15);
        }
        .navbar-toggler { border: none; padding: 6px; }
        .navbar-toggler:focus { box-shadow: none; }
        .navbar-toggler-icon {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba(244,209,255,0.75)' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
        }

        .dropdown-menu {
            background: rgba(21, 10, 30, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border-glow);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-lg), 0 0 40px rgba(244,209,255,0.05);
            padding: 8px; font-size: 0.88rem;
        }
        .dropdown-item {
            border-radius: 10px; padding: 10px 16px;
            transition: all 0.2s ease;
            color: var(--text-body);
        }
        .dropdown-item:hover {
            background: rgba(244, 209, 255, 0.08);
            color: var(--primary-light);
        }

        /* --- Section Headings --- */
        .section-label {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 0.78rem; letter-spacing: 0.18em;
            text-transform: uppercase; color: var(--accent); font-weight: 600;
        }
        .section-title {
            font-weight: 800; color: var(--text-bright);
            letter-spacing: -0.03em;
            font-size: clamp(1.6rem, 3vw, 2.4rem);
        }
        .section-subtitle {
            color: var(--text-muted) !important; font-size: 0.95rem; max-width: 560px;
        }

        /* ============================================================
           HERO SECTION
           ============================================================ */
        .svc-hero {
            padding: 160px 0 100px;
            position: relative; overflow: hidden;
            background: transparent;
        }
        .svc-hero::before {
            content: ''; position: absolute;
            top: -30%; right: -20%;
            width: 900px; height: 900px;
            background: radial-gradient(circle, rgba(123,63,145,0.2) 0%, transparent 55%);
            border-radius: 50%; animation: heroFloat 12s ease-in-out infinite;
        }
        .svc-hero::after {
            content: ''; position: absolute;
            bottom: -25%; left: -15%;
            width: 700px; height: 700px;
            background: radial-gradient(circle, rgba(244,209,255,0.06) 0%, transparent 55%);
            border-radius: 50%; animation: heroFloat 15s ease-in-out infinite reverse;
        }
        @keyframes heroFloat {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(30px, -20px) scale(1.06); }
            66% { transform: translate(-20px, 15px) scale(0.94); }
        }
        .svc-hero-title {
            font-weight: 900; color: var(--text-bright);
            line-height: 1.08; letter-spacing: -0.04em;
            font-size: clamp(2.2rem, 5.5vw, 3.6rem);
        }
        .svc-hero-title span {
            background: linear-gradient(135deg, var(--primary-light), var(--accent), var(--accent-rose));
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .svc-hero .lead {
            color: var(--text-body); font-size: 1.05rem;
            line-height: 1.8; max-width: 520px;
        }

        /* Floating AI icons */
        .floating-icon {
            position: absolute; color: var(--primary-light);
            opacity: 0.04; font-size: 2rem; pointer-events: none;
            animation: iconDrift 6s ease-in-out infinite;
        }
        .floating-icon:nth-child(1) { top: 15%; left: 8%; font-size: 2.5rem; animation-delay: 0s; animation-duration: 7s; }
        .floating-icon:nth-child(2) { top: 25%; right: 12%; font-size: 1.8rem; animation-delay: 1s; animation-duration: 8s; }
        .floating-icon:nth-child(3) { bottom: 20%; left: 15%; font-size: 3rem; animation-delay: 2s; animation-duration: 9s; }
        .floating-icon:nth-child(4) { top: 40%; right: 6%; font-size: 2.2rem; animation-delay: 0.5s; animation-duration: 6.5s; }
        .floating-icon:nth-child(5) { bottom: 30%; right: 18%; font-size: 1.6rem; animation-delay: 3s; animation-duration: 7.5s; }
        .floating-icon:nth-child(6) { top: 60%; left: 5%; font-size: 2rem; animation-delay: 1.5s; animation-duration: 8.5s; }
        @keyframes iconDrift {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            25% { transform: translateY(-20px) rotate(8deg); }
            50% { transform: translateY(-10px) rotate(-5deg); }
            75% { transform: translateY(-25px) rotate(3deg); }
        }

        /* Hero decorative shapes */
        .hero-shape { position: absolute; border-radius: 50%; pointer-events: none; }
        .hero-shape-1 {
            width: 350px; height: 350px; top: -100px; right: 8%;
            border: 1px solid rgba(244, 209, 255, 0.05);
            animation: shapeSpin 40s linear infinite;
        }
        .hero-shape-2 {
            width: 250px; height: 250px; bottom: -60px; left: 5%;
            border: 1px dashed rgba(244, 209, 255, 0.04);
            animation: shapeSpin 30s linear infinite reverse;
        }
        @keyframes shapeSpin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

        /* Hero badge */
        .svc-hero .badge {
            background: rgba(244, 209, 255, 0.06) !important;
            border: 1px solid var(--border-glow);
            color: var(--accent) !important;
            backdrop-filter: blur(10px);
        }

        /* Hero right visual */
        .svc-hero .col-lg-5 > div > div:first-child {
            background: radial-gradient(circle, rgba(123,63,145,0.12) 0%, rgba(59,19,71,0.05) 60%, transparent 70%) !important;
            border: 1px solid var(--border-subtle);
        }
        .svc-hero .col-lg-5 > div > div:first-child > div:first-child {
            background: radial-gradient(circle, rgba(244,209,255,0.06) 0%, rgba(216,143,255,0.04) 60%) !important;
            border: 1px solid rgba(244,209,255,0.05);
        }
        .svc-hero .col-lg-5 .fa-brain {
            color: var(--primary-light) !important;
            opacity: 0.5 !important;
            filter: drop-shadow(0 0 30px rgba(244,209,255,0.3));
        }

        /* ============================================================
           SERVICE CARDS
           ============================================================ */
        .svc-card {
            background: var(--bg-card);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-subtle);
            padding: 0;
            height: 100%;
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative; overflow: hidden;
        }
        .svc-card::before {
            content: ''; position: absolute;
            top: 0; left: 0; right: 0; height: 2px;
            background: linear-gradient(90deg, transparent, var(--accent), var(--primary-light), var(--accent), transparent);
            transform: scaleX(0); transform-origin: center;
            transition: transform 0.6s ease;
            opacity: 0.8;
        }
        .svc-card::after {
            content: ''; position: absolute;
            inset: 0;
            background: radial-gradient(600px circle at var(--mouse-x, 50%) var(--mouse-y, 50%), rgba(244,209,255,0.04), transparent 40%);
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.4s ease;
        }
        .svc-card:hover::before { transform: scaleX(1); }
        .svc-card:hover::after { opacity: 1; }
        .svc-card:hover {
            transform: translateY(-12px);
            box-shadow: var(--shadow-lg), 0 0 40px rgba(244,209,255,0.06);
            border-color: var(--border-glow);
            background: var(--bg-card-hover);
        }
        .svc-card-header {
            padding: 36px 32px 20px;
            display: flex; align-items: flex-start; gap: 20px;
        }
        .svc-icon-wrap {
            width: 80px; height: 80px; min-width: 80px;
            background: rgba(244, 209, 255, 0.06);
            border: 1px solid var(--border-subtle);
            border-radius: 22px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.8rem; color: var(--accent);
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }
        .svc-icon-wrap::after {
            content: ''; position: absolute;
            inset: -1px; border-radius: 22px;
            background: linear-gradient(135deg, var(--accent), var(--primary-light));
            opacity: 0; z-index: -1;
            transition: opacity 0.5s ease;
        }
        .svc-card:hover .svc-icon-wrap::after { opacity: 1; }
        .svc-card:hover .svc-icon-wrap {
            background: linear-gradient(135deg, var(--primary-mid), var(--accent));
            color: white; border-radius: 50%;
            border-color: transparent;
            transform: rotateY(180deg) scale(1.05);
            box-shadow: 0 0 30px rgba(216,143,255,0.3);
        }
        .svc-card-header h3 {
            font-weight: 700; color: var(--text-bright);
            font-size: 1.2rem; margin: 0 0 6px; letter-spacing: -0.01em;
        }
        .svc-card-header p {
            font-size: 0.88rem; color: var(--text-muted);
            line-height: 1.6; margin: 0;
        }
        .svc-card-body { padding: 0 32px 32px; }
        .svc-feature-list { list-style: none; padding: 0; margin: 0; }
        .svc-feature-list li {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 0; font-size: 0.88rem;
            color: var(--text-body); font-weight: 500;
            border-bottom: 1px solid var(--border-subtle);
            transition: all 0.3s ease;
        }
        .svc-feature-list li:last-child { border-bottom: none; }
        .svc-feature-list li:hover {
            padding-left: 8px; color: var(--primary-light);
        }
        .svc-feature-list li i {
            color: var(--accent); font-size: 0.7rem;
            width: 24px; height: 24px; min-width: 24px;
            background: rgba(244, 209, 255, 0.06);
            border: 1px solid var(--border-subtle);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            transition: all 0.3s ease;
        }
        .svc-card:hover .svc-feature-list li i {
            background: linear-gradient(135deg, var(--primary-mid), var(--accent));
            color: white; border-color: transparent;
            box-shadow: 0 0 12px rgba(216,143,255,0.3);
        }
        .svc-card-footer { padding: 0 32px 28px; }
        .svc-card-footer .btn-outline-purple {
            padding: 10px 28px; font-size: 0.85rem; border-width: 1.5px;
        }

        /* Services section bg */
        #services-grid {
            background: transparent !important;
        }
        /* Divider line */
        #services-grid::before {
            content: ''; position: absolute;
            top: 0; left: 10%; right: 10%; height: 1px;
            background: linear-gradient(90deg, transparent, var(--border-glow), transparent);
        }

        /* ============================================================
           WHY CHOOSE US
           ============================================================ */
        .why-card {
            background: var(--bg-card);
            backdrop-filter: blur(16px);
            border-radius: var(--radius-lg);
            padding: 36px 24px; text-align: center;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-subtle);
            height: 100%;
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative; overflow: hidden;
        }
        .why-card::before {
            content: ''; position: absolute;
            top: -2px; left: 20%; right: 20%; height: 2px;
            background: linear-gradient(90deg, transparent, var(--accent), transparent);
            opacity: 0; transition: opacity 0.5s ease;
        }
        .why-card:hover::before { opacity: 1; }
        .why-card::after {
            content: ''; position: absolute;
            bottom: -60px; left: 50%; transform: translateX(-50%);
            width: 120px; height: 120px;
            background: radial-gradient(circle, rgba(244,209,255,0.04), transparent 70%);
            border-radius: 50%; transition: all 0.5s ease;
        }
        .why-card:hover::after { bottom: -30px; width: 160px; height: 160px; }
        .why-card:hover {
            transform: translateY(-10px);
            box-shadow: var(--shadow-md), 0 0 30px rgba(244,209,255,0.06);
            border-color: var(--border-glow);
            background: var(--bg-card-hover);
        }
        .why-icon {
            width: 72px; height: 72px;
            background: rgba(244, 209, 255, 0.06);
            border: 1px solid var(--border-subtle);
            border-radius: 20px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.6rem; color: var(--accent);
            margin: 0 auto 20px;
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .why-card:hover .why-icon {
            background: linear-gradient(135deg, var(--primary-mid), var(--accent));
            color: white; border-radius: 50%;
            border-color: transparent;
            transform: scale(1.1) rotate(-8deg);
            box-shadow: 0 0 30px rgba(216,143,255,0.35);
        }
        .why-card h5 {
            font-weight: 700; color: var(--text-bright);
            font-size: 1.05rem; margin-bottom: 8px;
        }
        .why-card p {
            font-size: 0.85rem; color: var(--text-muted); line-height: 1.6;
        }

        /* Why section bg */
        section[style*="bg-soft"], .py-5:nth-of-type(3) {
            background: transparent !important;
        }

        /* ============================================================
           PROCESS TIMELINE
           ============================================================ */
        .timeline-section {
            background: transparent;
        }
        .timeline-section::before {
            content: ''; position: absolute;
            top: 0; left: 10%; right: 10%; height: 1px;
            background: linear-gradient(90deg, transparent, var(--border-glow), transparent);
        }
        .timeline-track {
            position: relative;
            max-width: 800px;
            margin: 0 auto;
        }
        .timeline-track::before {
            content: ''; position: absolute;
            top: 44px; left: 40px; right: 40px;
            height: 2px;
            background: linear-gradient(90deg, transparent, var(--primary-mid), var(--accent), var(--primary-mid), transparent);
            border-radius: 10px;
            z-index: 0;
            box-shadow: 0 0 12px rgba(244,209,255,0.1);
        }
        .tl-step {
            position: relative; z-index: 1;
            text-align: center; padding: 0 8px; cursor: default;
        }
        .tl-circle {
            width: 88px; height: 88px;
            background: var(--bg-dark);
            border: 2px solid var(--border-subtle);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 20px;
            font-size: 1.8rem; color: var(--accent);
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
        }
        .tl-circle::after {
            content: ''; position: absolute;
            inset: -8px; border-radius: 50%;
            border: 1px dashed rgba(244,209,255,0.08);
            animation: tlPulse 3s ease-in-out infinite;
            animation-delay: var(--delay, 0s);
        }
        @keyframes tlPulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.2); opacity: 0; }
        }
        .tl-step:hover .tl-circle {
            background: linear-gradient(135deg, var(--primary-mid), var(--accent));
            color: white; border-color: transparent;
            box-shadow: 0 0 0 8px rgba(244,209,255,0.06), 0 0 40px rgba(216,143,255,0.3);
            transform: scale(1.1);
        }
        .tl-step:hover .tl-circle::after { border-color: rgba(244,209,255,0.15); }
        .tl-step h5 {
            font-weight: 700; color: var(--text-bright);
            font-size: 1rem; margin-bottom: 4px;
            transition: color 0.3s ease;
        }
        .tl-step:hover h5 { color: var(--primary-light); }
        .tl-step p {
            font-size: 0.82rem; color: var(--text-muted); margin: 0;
        }
        .tl-number {
            position: absolute; top: -8px; right: -4px;
            width: 26px; height: 26px;
            background: linear-gradient(135deg, var(--accent), var(--accent-rose));
            color: white; border-radius: 50%;
            font-size: 0.72rem; font-weight: 700;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 2px 12px rgba(216,143,255,0.4);
        }

        /* ============================================================
           STATISTICS
           ============================================================ */
        .svc-stats {
            position: relative; overflow: hidden;
        }
        .svc-stats::before {
            content: ''; position: absolute;
            top: 0; left: 10%; right: 10%; height: 1px;
            background: linear-gradient(90deg, transparent, var(--border-glow), transparent);
        }
        .svc-stats::after {
            content: ''; position: absolute;
            bottom: 0; left: 10%; right: 10%; height: 1px;
            background: linear-gradient(90deg, transparent, var(--border-glow), transparent);
        }
        .bg-gradient-purple {
            background: linear-gradient(135deg, rgba(59,19,71,0.6) 0%, rgba(90,31,107,0.4) 40%, rgba(123,63,145,0.3) 70%, rgba(59,19,71,0.6) 100%) !important;
            backdrop-filter: blur(10px);
        }
        .svc-stat-box {
            background: rgba(244, 209, 255, 0.04);
            backdrop-filter: blur(12px);
            padding: 36px 20px; border-radius: var(--radius-md);
            text-align: center;
            border: 1px solid rgba(244, 209, 255, 0.1);
            transition: all 0.4s ease;
            position: relative; z-index: 1;
        }
        .svc-stat-box::before {
            content: ''; position: absolute;
            inset: 0; border-radius: var(--radius-md);
            background: radial-gradient(400px circle at 50% 0%, rgba(244,209,255,0.06), transparent 60%);
            opacity: 0; transition: opacity 0.4s ease;
        }
        .svc-stat-box:hover::before { opacity: 1; }
        .svc-stat-box:hover {
            transform: translateY(-6px) scale(1.03);
            background: rgba(244, 209, 255, 0.08);
            border-color: rgba(244, 209, 255, 0.2);
            box-shadow: 0 8px 30px rgba(0,0,0,0.3), 0 0 20px rgba(244,209,255,0.05);
        }
        .svc-stat-number {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 2.8rem; font-weight: 700;
            letter-spacing: -0.03em; line-height: 1; margin: 8px 0;
            background: linear-gradient(135deg, var(--primary-light), white);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .svc-stat-box small {
            font-size: 0.82rem; opacity: 0.6; font-weight: 400;
            color: var(--primary-light);
        }
        .svc-stat-box i { opacity: 0.5; font-size: 1.4rem; color: var(--primary-light); }

        /* ============================================================
           CTA SECTION
           ============================================================ */
        .cta-section {
            position: relative; overflow: hidden;
            background: transparent;
        }
        .cta-section::before {
            content: ''; position: absolute;
            top: 0; left: 10%; right: 10%; height: 1px;
            background: linear-gradient(90deg, transparent, var(--border-glow), transparent);
        }
        .cta-box {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-soft) 25%, var(--primary-mid) 50%, var(--accent) 100%);
            background-size: 300% 300%;
            animation: ctaGradient 10s ease infinite;
            border-radius: var(--radius-xl);
            padding: 70px 50px;
            position: relative; overflow: hidden;
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.5), 0 0 60px rgba(216,143,255,0.1);
            border: 1px solid rgba(244,209,255,0.1);
        }
        @keyframes ctaGradient {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        .cta-box::before {
            content: ''; position: absolute;
            top: -40%; right: -20%;
            width: 500px; height: 500px;
            background: radial-gradient(circle, rgba(244,209,255,0.08), transparent 60%);
            border-radius: 50%; animation: heroFloat 10s ease-in-out infinite;
        }
        .cta-box::after {
            content: ''; position: absolute;
            bottom: -30%; left: -10%;
            width: 350px; height: 350px;
            background: radial-gradient(circle, rgba(255,142,196,0.06), transparent 60%);
            border-radius: 50%; animation: heroFloat 12s ease-in-out infinite reverse;
        }
        .cta-box h2 {
            font-weight: 900; color: white;
            letter-spacing: -0.03em;
            font-size: clamp(1.6rem, 3.5vw, 2.4rem);
            margin-bottom: 16px; position: relative; z-index: 1;
            text-shadow: 0 2px 20px rgba(0,0,0,0.2);
        }
        .cta-box p {
            color: rgba(244, 209, 255, 0.7); font-size: 1rem;
            line-height: 1.7; max-width: 520px;
            position: relative; z-index: 1;
        }
        .cta-box .btn-outline-purple {
            border-color: rgba(244,209,255,0.4); color: var(--primary-light);
            position: relative; z-index: 1;
            background: rgba(244,209,255,0.08);
        }
        .cta-box .btn-outline-purple:hover {
            background: white; color: var(--primary);
            border-color: white;
        }
        .cta-box .btn-purple {
            position: relative; z-index: 1;
            background: white; color: var(--primary);
            box-shadow: 0 4px 24px rgba(0,0,0,0.2);
        }
        .cta-box .btn-purple::before { display: none; }
        .cta-box .btn-purple:hover {
            background: var(--primary-light);
            box-shadow: 0 8px 30px rgba(0,0,0,0.3);
            color: var(--primary);
        }

        /* CTA floating particles */
        .cta-particle {
            position: absolute; border-radius: 50%;
            background: rgba(244, 209, 255, 0.06); pointer-events: none;
        }
        .cta-particle:nth-child(1) { width: 80px; height: 80px; top: 10%; left: 5%; animation: particleFloat 6s ease-in-out infinite; }
        .cta-particle:nth-child(2) { width: 50px; height: 50px; top: 60%; right: 8%; animation: particleFloat 8s ease-in-out infinite 1s; }
        .cta-particle:nth-child(3) { width: 30px; height: 30px; bottom: 15%; left: 20%; animation: particleFloat 7s ease-in-out infinite 2s; }
        .cta-particle:nth-child(4) { width: 60px; height: 60px; top: 20%; right: 25%; animation: particleFloat 9s ease-in-out infinite 0.5s; }
        @keyframes particleFloat {
            0%, 100% { transform: translateY(0) scale(1); opacity: 0.06; }
            50% { transform: translateY(-20px) scale(1.2); opacity: 0.15; }
        }

        /* ============================================================
           FOOTER
           ============================================================ */
        footer {
            background: var(--bg-dark);
            border-top: 1px solid var(--border-subtle);
            color: var(--text-muted);
            padding: 28px 0;
        }
        footer a {
            color: var(--text-muted);
            text-decoration: none; transition: all 0.3s ease; font-size: 0.85rem;
        }
        footer a:hover { color: var(--primary-light); padding-left: 0; }
        footer h5 {
            font-weight: 700; color: var(--text-bright); font-size: 1rem; margin-bottom: 8px;
        }
        footer h6 {
            font-weight: 600; color: rgba(244, 209, 255, 0.7);
            font-size: 0.85rem; margin-bottom: 12px;
        }
        footer p.small { font-size: 0.82rem; line-height: 1.7; margin-bottom: 0; }
        footer ul li { margin-bottom: 6px; }
        footer .border-top {
            border-color: var(--border-subtle) !important;
            margin-top: 20px; padding-top: 18px; font-size: 0.78rem;
        }

        /* --- Scrollbar --- */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: var(--bg-deep); }
        ::-webkit-scrollbar-thumb { background: var(--primary-mid); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--accent); }

        /* --- Custom cursor glow (subtle) --- */
        .cursor-glow {
            position: fixed;
            width: 300px; height: 300px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(244,209,255,0.03), transparent 60%);
            pointer-events: none;
            z-index: 1;
            transform: translate(-50%, -50%);
            transition: opacity 0.3s ease;
        }

        /* --- Noise overlay for texture --- */
        body::after {
            content: '';
            position: fixed;
            inset: 0;
            z-index: 1;
            pointer-events: none;
            opacity: 0.015;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
            background-repeat: repeat;
            background-size: 256px;
        }

        /* --- Responsive --- */
        @media (max-width: 991px) {
            .timeline-track::before { left: 20px; right: 20px; }
            .tl-circle { width: 72px; height: 72px; font-size: 1.5rem; }
            .navbar-collapse {
                background: rgba(13, 6, 18, 0.95);
                backdrop-filter: blur(20px);
                border-radius: var(--radius-md);
                padding: 16px;
                margin-top: 12px;
                border: 1px solid var(--border-subtle);
            }
        }
        @media (max-width: 767px) {
            .svc-hero { padding: 130px 0 70px; }
            .svc-card-header {
                padding: 28px 24px 16px;
                flex-direction: column; text-align: center; align-items: center;
            }
            .svc-card-body { padding: 0 24px 24px; }
            .svc-card-footer { padding: 0 24px 24px; text-align: center; }
            .svc-stat-number { font-size: 2rem; }
            .timeline-track::before { display: none; }
            .tl-step { margin-bottom: 32px; }
            .tl-circle { width: 72px; height: 72px; font-size: 1.5rem; }
            .cta-box { padding: 50px 28px; text-align: center; }
            .cta-box p { max-width: 100%; }
            .floating-icon { display: none; }
            .hero-shape { display: none; }
            footer .row > div { margin-bottom: 16px; }
            .cursor-glow { display: none; }
        }

        /* --- Reduced motion --- */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
    
        /* ===== USER NOTIFICATIONS ON HOME / SERVICES NAVBAR ===== */
        .user-notification-toggle {
            position: relative;
            width: 42px;
            height: 42px;
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border-subtle);
            border-radius: 50%;
            background: rgba(244, 209, 255, 0.05);
            color: var(--text-light) !important;
            padding: 0 !important;
        }
        .user-notification-toggle::after { display: none !important; }
        .user-notification-toggle:hover {
            background: rgba(244, 209, 255, 0.1);
            border-color: var(--border-glow);
        }
        .user-notification-badge {
            position: absolute;
            top: -4px;
            right: -5px;
            min-width: 19px;
            height: 19px;
            padding: 0 5px;
            border-radius: 999px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #ff4d7d;
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            border: 2px solid var(--bg-deep);
        }
        .user-notification-menu {
            width: min(380px, calc(100vw - 24px));
            max-height: 460px;
            overflow: hidden;
            padding: 0 !important;
            z-index: 1100;
        }
        .user-notification-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 16px;
            border-bottom: 1px solid var(--border-subtle);
        }
        .user-notification-header strong {
            color: var(--text-light);
            font-size: 14px;
        }
        .mark-read-btn {
            border: 0;
            background: transparent;
            color: var(--accent);
            font-size: 11px;
            font-weight: 600;
            padding: 0;
        }
        .user-notification-list {
            max-height: 350px;
            overflow-y: auto;
        }
        .user-notification-item {
            display: flex;
            gap: 11px;
            padding: 13px 16px;
            text-decoration: none;
            border-bottom: 1px solid var(--border-subtle);
            background: transparent;
        }
        .user-notification-item:hover {
            background: rgba(244, 209, 255, 0.06);
        }
        .user-notification-item.unread {
            background: rgba(216, 143, 255, 0.08);
        }
        .user-notification-icon {
            width: 34px;
            height: 34px;
            flex: 0 0 34px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(216, 143, 255, 0.12);
            color: var(--accent);
        }
        .user-notification-copy { min-width: 0; }
        .user-notification-copy p {
            margin: 0 0 3px;
            color: var(--text-light);
            font-size: 12px;
            line-height: 1.45;
            white-space: normal;
        }
        .user-notification-copy small {
            color: var(--text-muted);
            font-size: 10px;
        }
        .user-notification-empty {
            padding: 32px 18px;
            text-align: center;
            color: var(--text-muted);
            font-size: 12px;
        }
        .user-notification-empty i {
            display: block;
            font-size: 24px;
            margin-bottom: 8px;
        }

        @media (max-width: 991.98px) {
            .user-notification-toggle {
                margin: 8px 0;
            }
            .user-notification-menu {
                position: absolute !important;
                right: 0 !important;
                left: auto !important;
            }
        }

    </style>
</head>
<body>

    <!-- Animated Starfield Background -->
    <canvas id="starfield"></canvas>

    <!-- Aurora Background Blobs -->
    <div class="aurora-wrap">
        <div class="aurora-blob"></div>
        <div class="aurora-blob"></div>
        <div class="aurora-blob"></div>
        <div class="aurora-blob"></div>
    </div>

    <!-- Cursor Glow -->
    <div class="cursor-glow" id="cursorGlow"></div>

    <!-- NAVIGATION BAR -->
    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center fw-bold" href="index.php">
                <img src="image/logo.png" onerror="this.style.display='none'; document.getElementById('fallback-logo').style.display='inline-block';" alt="Logo">
                AI Checker
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item"><a class="nav-link" href="index.php">Home</a></li>
                    <li class="nav-item"><a class="nav-link active" href="services.php">Services</a></li>
                    <li class="nav-item"><a class="nav-link" href="index.php#how-it-works">How It Works</a></li>
                    <li class="nav-item"><a class="nav-link" href="plan.php">Plan</a></li>
                    <li class="nav-item"><a class="nav-link" href="assignments.php">Assignments</a></li>
                    <li class="nav-item"><a class="nav-link" href="contacts.php">Contacts</a></li>
                    
                    <?php if ($isLoggedIn): ?>

                        <!-- USER NOTIFICATIONS -->
                        <li class="nav-item dropdown ms-lg-2">
                            <a class="nav-link dropdown-toggle user-notification-toggle"
                               href="#"
                               id="userNotificationDropdown"
                               role="button"
                               data-bs-toggle="dropdown"
                               data-bs-auto-close="outside"
                               aria-expanded="false"
                               aria-label="Notifications">
                                <i class="fa-solid fa-bell"></i>
                                <?php if ($userUnreadCount > 0): ?>
                                    <span class="user-notification-badge"><?php echo $userUnreadCount > 99 ? '99+' : $userUnreadCount; ?></span>
                                <?php endif; ?>
                            </a>

                            <div class="dropdown-menu dropdown-menu-end user-notification-menu"
                                 aria-labelledby="userNotificationDropdown">
                                <div class="user-notification-header">
                                    <strong>
                                        Notifications
                                        <?php if ($userUnreadCount > 0): ?>
                                            (<?php echo $userUnreadCount; ?>)
                                        <?php endif; ?>
                                    </strong>

                                    <?php if ($userUnreadCount > 0): ?>
                                        <form action="mark_user_notifications_read.php" method="post" class="m-0">
                                            <input type="hidden" name="redirect"
                                                   value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'index.php'); ?>">
                                            <button type="submit" class="mark-read-btn">Mark all read</button>
                                        </form>
                                    <?php endif; ?>
                                </div>

                                <div class="user-notification-list">
                                    <?php if (empty($userNotifications)): ?>
                                        <div class="user-notification-empty">
                                            <i class="fa-regular fa-bell-slash"></i>
                                            No notifications yet
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($userNotifications as $notification): ?>
                                            <a href="assignments.php"
                                               class="user-notification-item <?php echo (int)$notification['is_read'] === 0 ? 'unread' : ''; ?>">
                                                <span class="user-notification-icon">
                                                    <i class="fa-solid fa-file-circle-check"></i>
                                                </span>
                                                <span class="user-notification-copy">
                                                    <p><?php echo htmlspecialchars($notification['message'] ?? 'Assignment update'); ?></p>
                                                    <small><?php echo htmlspecialchars(landing_notification_time_ago($notification['created_at'] ?? '')); ?></small>
                                                </span>
                                            </a>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </li>

                        <li class="nav-item dropdown ms-lg-3">
                            <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                                <img src="https://api.dicebear.com/7.x/avataaars/svg?seed=<?php echo $userAvatarSeed; ?>" class="rounded-circle" width="35" alt="User" style="border: 2px solid var(--border-glow);">
                                <?php echo $userName; ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end border-0 shadow">
                                <li><a class="dropdown-item" href="profile.php"><i class="fa-solid fa-user me-2"></i> Profile</a></li>
                                <li><a class="dropdown-item" href="assignments.php"><i class="fa-solid fa-list me-2"></i> My Assignments</a></li>
                                <li><hr class="dropdown-divider" style="border-color: var(--border-subtle);"></li>
                                <li><a class="dropdown-item text-danger" href="login.php"><i class="fa-solid fa-sign-out-alt me-2"></i> Logout</a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item ms-lg-3"><a class="nav-link" href="logout.php">Login</a></li>
                        <li class="nav-item"><a class="btn btn-purple text-white px-4 ms-2" href="register.php">Register</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <!-- HERO SECTION -->
    <section class="svc-hero">
        <i class="floating-icon fa-solid fa-brain"></i>
        <i class="floating-icon fa-solid fa-microchip"></i>
        <i class="floating-icon fa-solid fa-robot"></i>
        <i class="floating-icon fa-solid fa-wand-magic-sparkles"></i>
        <i class="floating-icon fa-solid fa-code"></i>
        <i class="floating-icon fa-solid fa-atom"></i>

        <div class="hero-shape hero-shape-1"></div>
        <div class="hero-shape hero-shape-2"></div>

        <div class="container position-relative" style="z-index:2;">
            <div class="row align-items-center">
                <div class="col-lg-7" data-aos="fade-right">
                    <span class="badge bg-purple bg-opacity-10 text-purple px-3 py-2 rounded-pill mb-4 d-inline-block" style="background:var(--bg-glass-strong)!important; border:1px solid var(--border-glow);">
                        <i class="fa-solid fa-sparkles me-1"></i> Premium AI Services
                    </span>
                    <h1 class="svc-hero-title mb-4">AI-Powered<br><span>Academic Assistance</span></h1>
                    <p class="<p class="lead mb-4 style="color: #d8b4fe;">
                        Enhance your assignments with advanced AI technology designed to improve originality, accuracy, and academic quality.
                    </p>
                    <div class="d-flex gap-3 flex-wrap">
                        <a href="#services-grid" class="btn btn-purple">
                            <i class="fa-solid fa-compass me-2"></i>Explore Services
                        </a>
                        <a href="assignments.php" class="btn btn-outline-purple">
                            <i class="fa-solid fa-cloud-arrow-up me-2"></i>Upload Assignment
                        </a>
                    </div>
                </div>
                <div class="col-lg-5 text-center mt-5 mt-lg-0" data-aos="fade-left" data-aos-delay="200">
                    <div style="position:relative; display:inline-block;">
                        <div style="width:320px; height:320px; margin:0 auto; background:linear-gradient(135deg, rgba(123,63,145,0.1), rgba(59,19,71,0.05)); border-radius:50%; display:flex; align-items:center; justify-content:center; position:relative;">
                            <div style="width:220px; height:220px; background:linear-gradient(135deg, rgba(244,209,255,0.05), rgba(216,143,255,0.03)); border-radius:50%; display:flex; align-items:center; justify-content:center; animation: heroFloat 6s ease-in-out infinite;">
                                <i class="fa-solid fa-brain" style="font-size:5rem; color:var(--primary-light); opacity:0.5; filter: drop-shadow(0 0 30px rgba(244,209,255,0.3));"></i>
                            </div>
                            <div style="position:absolute; top:10%; left:10%; width:12px; height:12px; background:var(--accent-rose); border-radius:50%; animation: heroFloat 4s ease-in-out infinite; box-shadow: 0 0 15px rgba(255,142,196,0.5);"></div>
                            <div style="position:absolute; bottom:15%; right:8%; width:8px; height:8px; background:var(--accent); border-radius:50%; animation: heroFloat 5s ease-in-out infinite 1s; box-shadow: 0 0 12px rgba(216,143,255,0.5);"></div>
                            <div style="position:absolute; top:50%; right:0; width:10px; height:10px; background:var(--accent-gold); border-radius:50%; animation: heroFloat 7s ease-in-out infinite 2s; box-shadow: 0 0 12px rgba(255,214,165,0.5);"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- SERVICES SECTION -->
    <section id="services-grid" class="py-5" style="background:var(--surface); position:relative;">
        <div class="container">
            <div class="text-center mb-5" data-aos="fade-up">
                <p class="section-label mb-2">Our Services</p>
                <h2 class="section-title mb-3">What We Offer</h2>
                <p class="section-subtitle mx-auto">Comprehensive AI-powered tools designed to elevate your academic work to the highest standards.</p>
            </div>

            <div class="row g-4">
                <div class="col-lg-6" data-aos="fade-up" data-aos-delay="100">
                    <div class="svc-card">
                        <div class="svc-card-header">
                            <div class="svc-icon-wrap">
                                <i class="fa-solid fa-robot"></i>
                            </div>
                            <div>
                                <h3>AI Content Detection</h3>
                                <p>Identify AI-generated text instantly with high accuracy.</p>
                            </div>
                        </div>
                        <div class="svc-card-body">
                            <ul class="svc-feature-list">
                                <li><i class="fa-solid fa-check"></i> Smart AI Analysis</li>
                                <li><i class="fa-solid fa-check"></i> Instant Detection</li>
                                <li><i class="fa-solid fa-check"></i> Detailed Results</li>
                                <li><i class="fa-solid fa-check"></i> High Accuracy</li>
                            </ul>
                        </div>
                        <div class="svc-card-footer">
                            <a href="assignments.php" class="btn btn-outline-purple">
                                <i class="fa-solid fa-arrow-right me-2"></i>Get Started
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6" data-aos="fade-up" data-aos-delay="200">
                    <div class="svc-card">
                        <div class="svc-card-header">
                            <div class="svc-icon-wrap">
                                <i class="fa-solid fa-magnifying-glass"></i>
                            </div>
                            <div>
                                <h3>Plagiarism Checking</h3>
                                <p>Scan databases and web for duplicate content.</p>
                            </div>
                        </div>
                        <div class="svc-card-body">
                            <ul class="svc-feature-list">
                                <li><i class="fa-solid fa-check"></i> Similarity Detection</li>
                                <li><i class="fa-solid fa-check"></i> Source Identification</li>
                                <li><i class="fa-solid fa-check"></i> Originality Verification</li>
                                <li><i class="fa-solid fa-check"></i> Fast Scanning</li>
                            </ul>
                        </div>
                        <div class="svc-card-footer">
                            <a href="assignments.php" class="btn btn-outline-purple">
                                <i class="fa-solid fa-arrow-right me-2"></i>Get Started
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6" data-aos="fade-up" data-aos-delay="300">
                    <div class="svc-card">
                        <div class="svc-card-header">
                            <div class="svc-icon-wrap">
                                <i class="fa-solid fa-chart-line"></i>
                            </div>
                            <div>
                                <h3>Assignment Tracking</h3>
                                <p>Monitor the status of your submissions in real-time.</p>
                            </div>
                        </div>
                        <div class="svc-card-body">
                            <ul class="svc-feature-list">
                                <li><i class="fa-solid fa-check"></i> Live Status Updates</li>
                                <li><i class="fa-solid fa-check"></i> Submission History</li>
                                <li><i class="fa-solid fa-check"></i> Progress Monitoring</li>
                                <li><i class="fa-solid fa-check"></i> Easy Tracking</li>
                            </ul>
                        </div>
                        <div class="svc-card-footer">
                            <a href="assignment.php" class="btn btn-outline-purple">
                                <i class="fa-solid fa-arrow-right me-2"></i>Get Started
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6" data-aos="fade-up" data-aos-delay="400">
                    <div class="svc-card">
                        <div class="svc-card-header">
                            <div class="svc-icon-wrap">
                                <i class="fa-solid fa-shield-halved"></i>
                            </div>
                            <div>
                                <h3>Secure Payment</h3>
                                <p>Safe encrypted transactions for premium features.</p>
                            </div>
                        </div>
                        <div class="svc-card-body">
                            <ul class="svc-feature-list">
                                <li><i class="fa-solid fa-check"></i> Secure Gateway</li>
                                <li><i class="fa-solid fa-check"></i> Encrypted Transactions</li>
                                <li><i class="fa-solid fa-check"></i> Fast Processing</li>
                                <li><i class="fa-solid fa-check"></i> Trusted Payments</li>
                            </ul>
                        </div>
                        <div class="svc-card-footer">
                            <a href="plan.php" class="btn btn-outline-purple">
                                <i class="fa-solid fa-arrow-right me-2"></i>Get Started
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- WHY CHOOSE US -->
    <section class="py-5" style="background:var(--bg-soft);">
        <div class="container">
            <div class="text-center mb-5" data-aos="fade-up">
                <p class="section-label mb-2">Why Choose Us</p>
                <h2 class="section-title mb-3">Built for Excellence</h2>
                <p class="section-subtitle mx-auto">Every feature is crafted to deliver the best experience for students and educators.</p>
            </div>
            <div class="row g-4">
                <div class="col-md-6 col-lg-3" data-aos="fade-up" data-aos-delay="0">
                    <div class="why-card">
                        <div class="why-icon"><i class="fa-solid fa-bolt"></i></div>
                        <h5>Fast Performance</h5>
                        <p>Lightning-fast AI processing delivers results in seconds, not minutes.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3" data-aos="fade-up" data-aos-delay="100">
                    <div class="why-card">
                        <div class="why-icon"><i class="fa-solid fa-crosshairs"></i></div>
                        <h5>Accurate Results</h5>
                        <p>Precision-driven algorithms ensure reliable and detailed analysis every time.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3" data-aos="fade-up" data-aos-delay="200">
                    <div class="why-card">
                        <div class="why-icon"><i class="fa-solid fa-hand-pointer"></i></div>
                        <h5>User Friendly System</h5>
                        <p>Intuitive interface designed so anyone can use it without a learning curve.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3" data-aos="fade-up" data-aos-delay="300">
                    <div class="why-card">
                        <div class="why-icon"><i class="fa-solid fa-lock"></i></div>
                        <h5>Secure Platform</h5>
                        <p>Enterprise-grade encryption protects your data and assignments at all times.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- PROCESS TIMELINE -->
    <section class="timeline-section py-5">
        <div class="container">
            <div class="text-center mb-5" data-aos="fade-up">
                <p class="section-label mb-2">Our Process</p>
                <h2 class="section-title mb-3">How It Works</h2>
                <p class="section-subtitle mx-auto">Four simple steps to get your assignment checked and improved.</p>
            </div>

            <div class="timeline-track" data-aos="fade-up" data-aos-delay="100">
                <div class="row g-0">
                    <div class="col-6 col-md-3">
                        <div class="tl-step">
                            <div class="tl-circle" style="--delay:0s;">
                                <span class="tl-number">1</span>
                                <i class="fa-solid fa-cloud-arrow-up"></i>
                            </div>
                            <h5>Upload Assignment</h5>
                            <p>Submit your file easily</p>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="tl-step">
                            <div class="tl-circle" style="--delay:0.8s;">
                                <span class="tl-number">2</span>
                                <i class="fa-solid fa-brain"></i>
                            </div>
                            <h5>AI Analysis</h5>
                            <p>Advanced scanning begins</p>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="tl-step">
                            <div class="tl-circle" style="--delay:1.6s;">
                                <span class="tl-number">3</span>
                                <i class="fa-solid fa-file-lines"></i>
                            </div>
                            <h5>Generate Report</h5>
                            <p>Detailed report created</p>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="tl-step">
                            <div class="tl-circle" style="--delay:2.4s;">
                                <span class="tl-number">4</span>
                                <i class="fa-solid fa-circle-check"></i>
                            </div>
                            <h5>Receive Results</h5>
                            <p>Download your report</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- STATISTICS SECTION -->
    <section class="svc-stats bg-gradient-purple text-white py-5">
        <div class="container">
            <div class="text-center mb-5" data-aos="fade-up">
                <p class="mb-2" style="font-size:0.78rem; letter-spacing:0.15em; text-transform:uppercase; opacity:0.6; font-weight:600;">Our Impact</p>
                <h2 style="font-weight:800; letter-spacing:-0.03em; font-size:clamp(1.6rem,3vw,2.2rem); color: var(--text-bright);">Trusted by Thousands</h2>
            </div>
            <div class="row g-4">
                <div class="col-lg-4 col-md-6" data-aos="fade-down" data-aos-delay="0">
                    <div class="svc-stat-box">
                        <i class="fa-solid fa-file-circle-check d-block mb-2"></i>
                        <div class="svc-stat-number" data-target="<?php echo $stats['assignments']; ?>">0</div>
                        <small>Assignments Checked</small>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6" data-aos="fade-down" data-aos-delay="100">
                    <div class="svc-stat-box">
                        <i class="fa-solid fa-users d-block mb-2"></i>
                        <div class="svc-stat-number" data-target="<?php echo $stats['users']; ?>">0</div>
                        <small>Active Users</small>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6" data-aos="fade-down" data-aos-delay="300">
                    <div class="svc-stat-box">
                        <i class="fa-solid fa-face-smile d-block mb-2"></i>
                        <div class="svc-stat-number" data-target="98" data-suffix="%">0</div>
                        <small>Customer Satisfaction</small>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA SECTION -->
    <section class="cta-section py-5">
        <div class="container">
            <div class="cta-box text-center" data-aos="zoom-in">
                <div class="cta-particle"></div>
                <div class="cta-particle"></div>
                <div class="cta-particle"></div>
                <div class="cta-particle"></div>
                <h2>Ready to Check Your Assignment?</h2>
                <p class="mx-auto mb-4">Join thousands of students who trust our AI-powered platform to ensure academic integrity and improve their work quality.</p>
                <div class="d-flex gap-3 justify-content-center flex-wrap">
                    <a href="assignments.php" class="btn btn-purple">
                        <i class="fa-solid fa-rocket me-2"></i>Start Now
                    </a>
                    <a href="plan.php" class="btn btn-outline-purple">
                        <i class="fa-solid fa-tags me-2"></i>View Plans
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer>
        <div class="container">
            <div class="row">
                <div class="col-lg-4">
                    <h5>AI Assignment Checker</h5>
                    <p class="small">Empowering academic integrity with cutting-edge AI technology. Fast, accurate, and secure.</p>
                </div>
                <div class="col-lg-2 col-md-4">
                    <h6>Quick Links</h6>
                    <ul class="list-unstyled">
                        <li><a href="index.php">Home</a></li>
                        <li><a href="services.php">Services</a></li>
                        <li><a href="plan.php">Plans</a></li>
                        <li><a href="contacts.php">Contact</a></li>
                    </ul>
                </div>
                <div class="col-lg-2 col-md-4">
                    <h6>Resources</h6>
                    <ul class="list-unstyled">
                        <li><a href="contacts.php">FAQ</a></li>
                        <li><a href="privacypolicy.php">Privacy Policy</a></li>
                        <li><a href="terms.php">Terms of Service</a></li>
                    </ul>
                </div>
                <div class="col-lg-4 col-md-4">
                    <h6>Stay Updated</h6>
                    <p class="small">Get the latest updates on our AI tools and features.</p>
                </div>
            </div>
            <div class="border-top text-center">
                <p class="mb-0">&copy; 2025 AI Assignment Checker. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>

    <script>
        // Initialize AOS
        AOS.init({
            duration: 800,
            easing: 'ease-out-cubic',
            once: true,
            offset: 60
        });

        // Navbar scroll effect
        window.addEventListener('scroll', function() {
            const nav = document.querySelector('.navbar');
            if (window.scrollY > 40) {
                nav.classList.add('scrolled');
            } else {
                nav.classList.remove('scrolled');
            }
        });

        // Cursor glow follow
        const cursorGlow = document.getElementById('cursorGlow');
        document.addEventListener('mousemove', function(e) {
            if (cursorGlow) {
                cursorGlow.style.left = e.clientX + 'px';
                cursorGlow.style.top = e.clientY + 'px';
            }
        });

        // Card mouse tracking for spotlight effect
        document.querySelectorAll('.svc-card').forEach(card => {
            card.addEventListener('mousemove', function(e) {
                const rect = card.getBoundingClientRect();
                const x = ((e.clientX - rect.left) / rect.width * 100);
                const y = ((e.clientY - rect.top) / rect.height * 100);
                card.style.setProperty('--mouse-x', x + '%');
                card.style.setProperty('--mouse-y', y + '%');
            });
        });

        // Animated starfield canvas
        (function() {
            const canvas = document.getElementById('starfield');
            if (!canvas) return;
            const ctx = canvas.getContext('2d');
            let stars = [];
            const STAR_COUNT = 120;

            function resize() {
                canvas.width = window.innerWidth;
                canvas.height = window.innerHeight;
            }
            resize();
            window.addEventListener('resize', resize);

            // Create stars
            for (let i = 0; i < STAR_COUNT; i++) {
                stars.push({
                    x: Math.random() * canvas.width,
                    y: Math.random() * canvas.height,
                    radius: Math.random() * 1.2 + 0.3,
                    alpha: Math.random() * 0.5 + 0.1,
                    alphaSpeed: Math.random() * 0.008 + 0.002,
                    alphaDir: 1,
                    color: Math.random() > 0.7 ? '#F4D1FF' : (Math.random() > 0.5 ? '#D88FFF' : '#ffffff')
                });
            }

            function animate() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                stars.forEach(star => {
                    // Twinkle
                    star.alpha += star.alphaSpeed * star.alphaDir;
                    if (star.alpha >= 0.6) star.alphaDir = -1;
                    if (star.alpha <= 0.05) star.alphaDir = 1;

                    ctx.beginPath();
                    ctx.arc(star.x, star.y, Math.max(0.1, star.radius), 0, Math.PI * 2);
                    ctx.fillStyle = star.color;
                    ctx.globalAlpha = star.alpha;
                    ctx.fill();

                    // Small glow for bigger stars
                    if (star.radius > 0.8) {
                        ctx.beginPath();
                        ctx.arc(star.x, star.y, Math.max(0.1, star.radius * 3), 0, Math.PI * 2);
                        const grad = ctx.createRadialGradient(star.x, star.y, 0, star.x, star.y, Math.max(0.1, star.radius * 3));
                        grad.addColorStop(0, star.color);
                        grad.addColorStop(1, 'rgba(0,0,0,0)');
                        ctx.fillStyle = grad;
                        ctx.globalAlpha = star.alpha * 0.3;
                        ctx.fill();
                    }
                });
                ctx.globalAlpha = 1;
                requestAnimationFrame(animate);
            }
            animate();

            // Reposition stars on resize
            window.addEventListener('resize', function() {
                stars.forEach(star => {
                    star.x = Math.random() * canvas.width;
                    star.y = Math.random() * canvas.height;
                });
            });
        })();

        // Counter animation for stats
        function animateCounters() {
            const counters = document.querySelectorAll('.svc-stat-number');
            counters.forEach(counter => {
                if (counter.dataset.animated) return;
                const rect = counter.getBoundingClientRect();
                if (rect.top < window.innerHeight && rect.bottom > 0) {
                    counter.dataset.animated = 'true';
                    const target = parseInt(counter.dataset.target) || 0;
                    const suffix = counter.dataset.suffix || '';
                    const duration = 2000;
                    const start = performance.now();

                    function update(now) {
                        const elapsed = now - start;
                        const progress = Math.min(elapsed / duration, 1);
                        // Ease out cubic
                        const ease = 1 - Math.pow(1 - progress, 3);
                        const current = Math.round(ease * target);
                        counter.textContent = current + suffix;
                        if (progress < 1) requestAnimationFrame(update);
                    }
                    requestAnimationFrame(update);
                }
            });
        }
        window.addEventListener('scroll', animateCounters);
        window.addEventListener('load', animateCounters);
    </script>

</body>
</html>