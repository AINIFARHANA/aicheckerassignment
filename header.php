<?php
session_start();

// DATABASE CONNECTION
 mysqli_report(MYSQLI_REPORT_OFF); // classic error handling — see config.php for why
$servername = "localhost";
$username   = "root";
$password   = "";               // Default XAMPP password
$dbname     = "assignment_db";  // Your local database name

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

 $isLoggedIn    = isset($_SESSION['user_id']);
 $userName      = isset($_SESSION['username']) ? $_SESSION['username'] : "User";
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

// USER NOTIFICATIONS
$userNotifications = [];
$userUnreadCount = 0;

if ($isLoggedIn && $conn) {
    $stmt = $conn->prepare("SELECT notification_id, message, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($result && ($notification = $result->fetch_assoc())) {
            $userNotifications[] = $notification;
            if ((int)$notification['is_read'] === 0) {
                $userUnreadCount++;
            }
        }
        $stmt->close();
    }
}

if (!function_exists('user_notification_time_ago')) {
    function user_notification_time_ago($datetime) {
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
    <title><?php echo isset($pageTitle) ? $pageTitle : 'AI Assignment Checker'; ?></title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AOS (if pages use it) -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        /* ========== DESIGN TOKENS ========== */
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
            --radius-sm: 12px;
            --radius-md: 20px;
            --radius-lg: 28px;
            --radius-xl: 40px;
        }

        /* ========== RESET & BASE ========== */
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Poppins', sans-serif;
            color: var(--text-body);
            overflow-x: hidden;
            background: var(--bg-deep);
            -webkit-font-smoothing: antialiased;
        }

        ::selection { background: var(--primary-mid); color: white; }

        /* ========== BUTTONS (shared across pages) ========== */
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

        /* ========== STARFIELD CANVAS ========== */
        #starfield {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            z-index: 0;
            pointer-events: none;
        }

        /* ========== AURORA BACKGROUND BLOBS ========== */
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
            25%  { transform: translate(40px, -30px) scale(1.08); }
            50%  { transform: translate(-20px, 20px) scale(0.95); }
            75%  { transform: translate(30px, 15px) scale(1.05); }
        }

        /* ★ FIX #1: Removed 'nav' from this rule.
           Before: nav, section, footer, .cta-section { z-index: 2 }
           This fought with Bootstrap's .fixed-top { z-index: 1030 }
           and created a broken stacking context that trapped the dropdown
           underneath the cursor-glow and body::after layers. */
        section, footer, .cta-section {
            position: relative;
            z-index: 2;
        }

        /* ========== CURSOR GLOW ========== */
        .cursor-glow {
            position: fixed;
            width: 300px; height: 300px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(244,209,255,0.03), transparent 60%);
            pointer-events: none;
            /* ★ FIX #2: Lowered from z-index: 1 to z-index: 0 so it
               stays strictly behind all content layers */
            z-index: 0;
            transform: translate(-50%, -50%);
            transition: opacity 0.3s ease;
        }

        /* ========== NOISE TEXTURE OVERLAY ========== */
        body::after {
            content: '';
            position: fixed;
            inset: 0;
            /* ★ FIX #3: Lowered from z-index: 1 to z-index: 0 */
            z-index: 0;
            pointer-events: none;
            opacity: 0.015;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
            background-repeat: repeat;
            background-size: 256px;
        }

        /* ========== NAVBAR ========== */
        .navbar {
            background: rgba(13, 6, 18, 0.6);
            backdrop-filter: blur(24px) saturate(180%);
            -webkit-backdrop-filter: blur(24px) saturate(180%);
            border-bottom: 1px solid var(--border-subtle);
            padding: 14px 0;
            transition: all 0.4s ease;
            /* ★ FIX #4: Explicit high z-index ensures navbar (and everything
               inside it, including dropdowns) sits above ALL background layers.
               Bootstrap's .fixed-top sets z-index:1030, but we override to be safe */
            z-index: 1060;
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
        .nav-link:hover,
        .nav-link.active {
            color: var(--primary-light) !important;
            background: rgba(244, 209, 255, 0.08);
            text-shadow: 0 0 12px rgba(244,209,255,0.15);
        }

        /* Hamburger icon (purple tint) */
        .navbar-toggler { border: none; padding: 6px; }
        .navbar-toggler:focus { box-shadow: none; }
        .navbar-toggler-icon {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba(244,209,255,0.75)' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
        }

        /* ========== DROPDOWN MENU ========== */
        .dropdown-menu {
            background: rgba(21, 10, 30, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border-glow);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-lg), 0 0 40px rgba(244,209,255,0.05);
            padding: 8px;
            font-size: 0.88rem;
            min-width: 200px;
            /* ★ FIX #5: Explicit z-index so the dropdown menu is guaranteed
               to render on top within the navbar's stacking context */
            z-index: 1060;
        }
        .dropdown-item {
            border-radius: 10px;
            padding: 10px 16px;
            transition: all 0.2s ease;
            color: var(--text-body);
        }
        .dropdown-item:hover {
            background: rgba(244, 209, 255, 0.08);
            color: var(--primary-light);
        }
        .dropdown-item i {
            width: 18px;
            text-align: center;
            margin-right: 8px;
            font-size: 0.82rem;
        }
        .dropdown-divider {
            border-color: var(--border-subtle) !important;
            margin: 6px 0;
        }
        .dropdown-toggle::after {
            margin-left: 6px;
            vertical-align: middle;
            transition: transform 0.3s ease;
        }
        .dropdown-toggle[aria-expanded="true"]::after {
            transform: rotate(180deg);
        }


        /* ========== USER NOTIFICATIONS ========== */
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
            padding: 0;
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
        .user-notification-copy {
            min-width: 0;
        }
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

        /* User avatar in dropdown trigger */
        .nav-avatar {
            border: 2px solid var(--border-glow);
            transition: border-color 0.3s ease;
        }
        .dropdown:hover .nav-avatar {
            border-color: var(--border-active);
        }

        /* ========== SCROLLBAR ========== */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: var(--bg-deep); }
        ::-webkit-scrollbar-thumb { background: var(--primary-mid); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--accent); }

        /* ========== RESPONSIVE ========== */
        @media (max-width: 991px) {
            .navbar-collapse {
                background: rgba(13, 6, 18, 0.95);
                backdrop-filter: blur(20px);
                border-radius: var(--radius-md);
                padding: 16px;
                margin-top: 12px;
                border: 1px solid var(--border-subtle);
            }
            .dropdown-menu {
                background: rgba(13, 6, 18, 0.8);
                border: 1px solid var(--border-subtle);
                box-shadow: var(--shadow-md);
                padding: 6px;
            }
        }
        @media (max-width: 767px) {
            .cursor-glow { display: none; }
        }

        /* ========== REDUCED MOTION ========== */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
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

    <!-- ===================== NAVIGATION BAR ===================== -->
    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container">
            <!-- Brand -->
            <a class="navbar-brand d-flex align-items-center fw-bold" href="index.php">
                <img src="image/logo.png"
                     onerror="this.style.display='none';"
                     alt="Logo">
                AI Checker
            </a>

            <!-- Mobile Toggle -->
            <button class="navbar-toggler" type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#navbarNav"
                    aria-controls="navbarNav"
                    aria-expanded="false"
                    aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <!-- Collapsible Nav -->
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">

                    <li class="nav-item">
                        <a class="nav-link" href="index.php">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="services.php">Services</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="index.php#how-it-works">How It Works</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="plan.php">Plan</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="assignments.php">Assignments</a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link" href="contacts.php">Contacts</a>
                    </li>

                    <?php if ($isLoggedIn): ?>

                        <!-- ===== USER NOTIFICATIONS ===== -->
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
                            <div class="dropdown-menu dropdown-menu-end user-notification-menu" aria-labelledby="userNotificationDropdown">
                                <div class="user-notification-header">
                                    <strong>Notifications <?php if ($userUnreadCount > 0): ?>(<?php echo $userUnreadCount; ?>)<?php endif; ?></strong>
                                    <?php if ($userUnreadCount > 0): ?>
                                        <form action="mark_user_notifications_read.php" method="post" class="m-0">
                                            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'index.php'); ?>">
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
                                            <a href="assignments.php" class="user-notification-item <?php echo (int)$notification['is_read'] === 0 ? 'unread' : ''; ?>">
                                                <span class="user-notification-icon"><i class="fa-solid fa-file-circle-check"></i></span>
                                                <span class="user-notification-copy">
                                                    <p><?php echo htmlspecialchars($notification['message'] ?? 'Assignment update'); ?></p>
                                                    <small><?php echo htmlspecialchars(user_notification_time_ago($notification['created_at'] ?? '')); ?></small>
                                                </span>
                                            </a>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </li>

                        <!-- ===== LOGGED-IN: Avatar + Dropdown ===== -->
                        <li class="nav-item dropdown ms-lg-3">
                            <a class="nav-link dropdown-toggle d-flex align-items-center gap-2"
                               href="#"
                               id="userDropdown"
                               role="button"
                               data-bs-toggle="dropdown"
                               aria-expanded="false">
                                <img src="https://api.dicebear.com/7.x/avataaars/svg?seed=<?php echo htmlspecialchars($userAvatarSeed); ?>"
                                     class="rounded-circle nav-avatar"
                                     width="35"
                                     alt="User Avatar">
                                <span><?php echo htmlspecialchars($userName); ?></span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                                <li>
                                    <a class="dropdown-item" href="profile.php">
                                        <i class="fa-solid fa-user"></i> Profile
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="assignments.php">
                                        <i class="fa-solid fa-list"></i> My Assignments
                                    </a>
                                </li>
                                <li class="nav-item"><a class="nav-link" href="verify_certificate.php"><i class="fa-solid fa-certificate"></i> Verify Certificate</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item text-danger" href="logout.php">
                                        <i class="fa-solid fa-right-from-bracket"></i> Logout
                                    </a>
                                </li>
                            </ul>
                        </li>

                    <?php else: ?>

                        <!-- ===== GUEST: Login + Register ===== -->
                        <li class="nav-item ms-lg-3">
                            <a class="nav-link" href="login.php">Login</a>
                        </li>
                        <li class="nav-item">
                            <a class="btn btn-purple text-white px-4 ms-2" href="register.php">Register</a>
                        </li>

                    <?php endif; ?>

                </ul>
            </div>
        </div>
    </nav>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Navbar scroll behaviour -->
    <script>
        window.addEventListener('scroll', function () {
            const nav = document.querySelector('.navbar');
            if (window.scrollY > 50) {
                nav.classList.add('scrolled');
            } else {
                nav.classList.remove('scrolled');
            }
        });
    </script>

    <!-- Starfield animation -->
    <script>
        (function () {
            const canvas = document.getElementById('starfield');
            if (!canvas) return;
            const ctx = canvas.getContext('2d');
            let stars = [];
            const COUNT = 120;

            function resize() {
                canvas.width  = window.innerWidth;
                canvas.height = window.innerHeight;
            }

            function createStars() {
                stars = [];
                for (let i = 0; i < COUNT; i++) {
                    stars.push({
                        x: Math.random() * canvas.width,
                        y: Math.random() * canvas.height,
                        r: Math.random() * 1.2 + 0.3,
                        a: Math.random() * 0.6 + 0.2,
                        speed: Math.random() * 0.15 + 0.02,
                        drift: (Math.random() - 0.5) * 0.08,
                        pulse: Math.random() * Math.PI * 2
                    });
                }
            }

            function draw() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                for (const s of stars) {
                    s.y -= s.speed;
                    s.x += s.drift;
                    s.pulse += 0.01;
                    const alpha = s.a * (0.6 + 0.4 * Math.sin(s.pulse));

                    if (s.y < -5) { s.y = canvas.height + 5; s.x = Math.random() * canvas.width; }
                    if (s.x < -5)  s.x = canvas.width + 5;
                    if (s.x > canvas.width + 5) s.x = -5;

                    ctx.beginPath();
                    ctx.arc(s.x, s.y, s.r, 0, Math.PI * 2);
                    ctx.fillStyle = 'rgba(244,209,255,' + alpha + ')';
                    ctx.fill();
                }
                requestAnimationFrame(draw);
            }

            resize();
            createStars();
            draw();
            window.addEventListener('resize', function () { resize(); createStars(); });
        })();
    </script>

    <!-- Cursor glow follow -->
    <script>
        (function () {
            const glow = document.getElementById('cursorGlow');
            if (!glow || window.innerWidth < 768) return;
            document.addEventListener('mousemove', function (e) {
                glow.style.left = e.clientX + 'px';
                glow.style.top  = e.clientY + 'px';
            });
        })();
    </script>