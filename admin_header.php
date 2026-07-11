<?php

session_start();

$servername = "localhost";
$username   = "root";
$password   = "";               // Default XAMPP password
$dbname     = "assignment_db";  // Your local database name

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

 $admin_id = $_SESSION['user_id'] ?? null;
if (!isset($admin_id)) {
    header('location: login.php');
    exit;
}

// ─── MARK ALL NOTIFICATIONS AS READ ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_all_read') {
    mysqli_query($conn, "UPDATE notifications SET is_read = 1 WHERE user_id IS NULL AND is_read = 0");
    header("Location: " . basename($_SERVER['PHP_SELF']));
    exit;
}

// ─── FETCH ADMIN DATA ───
 $admin_query = mysqli_query(
    $conn,
    "SELECT user_id, name, email, avatar, created_at FROM users WHERE user_id = '$admin_id' AND user_type = 'admin'"
) or die(mysqli_error($conn));

if (mysqli_num_rows($admin_query) === 0) {
    die("Admin account not found.");
}

 $admin_data    = mysqli_fetch_assoc($admin_query);
 $admin_name    = $admin_data['name'] ?? 'Admin';
 $admin_email   = $admin_data['email'] ?? '';
 $admin_created = $admin_data['created_at'] ?? '';
 $db_avatar     = $admin_data['avatar'] ?? 'default.png';

// ─── BUILD DICEBEAR AVATAR URL ───
if (!empty($db_avatar) && $db_avatar !== 'default.png') {
    if (filter_var($db_avatar, FILTER_VALIDATE_URL)) {
        $avatar = $db_avatar;
    } else {
        $avatar = "https://api.dicebear.com/7.x/avataaars/svg?seed=" . urlencode($db_avatar) . "&backgroundColor=ede9fe";
    }
} else {
    $avatar = "https://api.dicebear.com/7.x/avataaars/svg?seed=Admin&backgroundColor=ede9fe";
}

// ─── TIME AGO HELPER ───
function time_ago($datetime) {
    $now = new DateTime();
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);
    if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    if ($diff->i > 0) return $diff->i . ' min ago';
    return 'Just now';
}

// ─── NOTIFICATIONS ───
 $unread_count  = 0;
 $notifications = [];
 $noti_query = mysqli_query($conn, "SELECT * FROM notifications WHERE user_id IS NULL ORDER BY created_at DESC LIMIT 30");
if (mysqli_num_rows($noti_query) > 0) {
    while ($row = mysqli_fetch_assoc($noti_query)) {
        $notifications[] = $row;
        if ($row['is_read'] == 0) $unread_count++;
    }
}

// ═══════════════════════════════════════════════════════════════
// DASHBOARD QUERIES — ALL PREPARED STATEMENTS
// ═══════════════════════════════════════════════════════════════

// ─── Total Users ───
 $stmt_users = $conn->prepare("SELECT COUNT(*) AS total FROM users WHERE user_type = ?");
 $type_user = 'user';
 $stmt_users->bind_param("s", $type_user);
 $stmt_users->execute();
 $total_users = $stmt_users->get_result()->fetch_assoc()['total'];
 $stmt_users->close();

// ─── Total Assignments ───
 $stmt_assign = $conn->prepare("SELECT COUNT(*) AS total FROM assignments");
 $stmt_assign->execute();
 $total_assignments = $stmt_assign->get_result()->fetch_assoc()['total'];
 $stmt_assign->close();

// ─── Total Payments ───
 $stmt_payments = $conn->prepare("SELECT COUNT(*) AS total FROM payments");
 $stmt_payments->execute();
 $total_payments = $stmt_payments->get_result()->fetch_assoc()['total'];
 $stmt_payments->close();

// ─── Total Revenue (Paid only) ───
 $stmt_revenue = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS total_revenue FROM payments WHERE payment_status = ?");
 $status_paid = 'Paid';
 $stmt_revenue->bind_param("s", $status_paid);
 $stmt_revenue->execute();
 $total_revenue = $stmt_revenue->get_result()->fetch_assoc()['total_revenue'];
 $stmt_revenue->close();

// ─── Monthly Revenue (Jan–Dec, current year, Paid) ───
 $monthly_revenue = array_fill(0, 12, 0);

 $stmt_monthly = $conn->prepare("
    SELECT MONTH(created_at) AS month_num, COALESCE(SUM(amount), 0) AS month_total
    FROM payments
    WHERE payment_status = ? AND YEAR(created_at) = YEAR(CURRENT_DATE)
    GROUP BY MONTH(created_at)
    ORDER BY MONTH(created_at)
");
 $stmt_monthly->bind_param("s", $status_paid);
 $stmt_monthly->execute();
 $result_monthly = $stmt_monthly->get_result();

while ($row = $result_monthly->fetch_assoc()) {
    $index = (int)$row['month_num'] - 1;
    $monthly_revenue[$index] = (float)$row['month_total'];
}
 $stmt_monthly->close();

 $conn->close();
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — AI Assignment Checker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap">
    <style>
        :root {
            --primary: #6A0DAD;
            --primary-light: #9C27B0;
            --primary-dark: #4A0072;
            --primary-rgb: 106, 13, 173;
            --secondary-rgb: 156, 39, 176;
            --bg: #F3F0F7;
            --card-bg: rgba(255, 255, 255, 0.78);
            --sidebar-width: 260px;
            --header-height: 70px;
            --text-dark: #2D1B4E;
            --text-muted: #7B6B8D;
            --border-color: rgba(106, 13, 173, 0.08);
            --input-bg: #FFFFFF;
            --shadow-sm: 0 2px 8px rgba(106, 13, 173, 0.06);
            --shadow-md: 0 4px 20px rgba(106, 13, 173, 0.1);
            --shadow-lg: 0 8px 40px rgba(106, 13, 173, 0.15);
            --radius: 16px;
            --radius-sm: 10px;
        }
        [data-theme="dark"] {
            --bg: #110B18;
            --card-bg: rgba(32, 18, 52, 0.82);
            --text-dark: #E8E0F0;
            --text-muted: #9B8DB5;
            --border-color: rgba(156, 39, 176, 0.12);
            --input-bg: rgba(45, 27, 78, 0.6);
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.25);
            --shadow-md: 0 4px 20px rgba(0, 0, 0, 0.35);
            --shadow-lg: 0 8px 40px rgba(0, 0, 0, 0.45);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Poppins', sans-serif;
            background: var(--bg); color: var(--text-dark);
            overflow-x: hidden; min-height: 100vh;
            transition: background 0.35s ease, color 0.35s ease;
        }

        /* ═══ SIDEBAR ═══ */
        .sidebar {
            position: fixed; top: 0; left: 0;
            width: var(--sidebar-width); height: 100vh;
            background: linear-gradient(180deg, var(--primary-dark) 0%, var(--primary) 50%, var(--primary-light) 100%);
            z-index: 1050; transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex; flex-direction: column;
            box-shadow: 4px 0 30px rgba(106, 13, 173, 0.3);
        }
        .sidebar-brand {
            padding: 24px 20px; border-bottom: 1px solid rgba(255,255,255,0.1);
            display: flex; align-items: center; gap: 12px;
        }
        .sidebar-brand .brand-icon {
            width: 122px; height: 60px; background: rgba(255,255,255,0.15);
            border-radius: 12px; overflow: hidden; backdrop-filter: blur(10px);
            flex-shrink: 0;
        }
        .sidebar-brand .brand-icon img {
            width: 100%; height: 100%; object-fit: cover;
        }
        .sidebar-brand h5 { color: #fff; font-weight: 700; font-size: 15px; margin: 0; line-height: 1.3; }
        .sidebar-brand small { color: rgba(255,255,255,0.6); font-size: 11px; }
        .sidebar-menu { flex: 1; padding: 16px 12px; overflow-y: auto; }
        .sidebar-menu .menu-label {
            color: rgba(255,255,255,0.4); font-size: 10px; font-weight: 600;
            text-transform: uppercase; letter-spacing: 1.5px; padding: 12px 14px 8px;
        }
        .sidebar-menu a {
            display: flex; align-items: center; gap: 12px;
            padding: 11px 14px; color: rgba(255,255,255,0.7);
            text-decoration: none; border-radius: var(--radius-sm);
            font-size: 13.5px; font-weight: 500;
            transition: all 0.25s ease; margin-bottom: 2px; position: relative;
        }
        .sidebar-menu a i.fa-icon { width: 20px; text-align: center; font-size: 15px; }
        .sidebar-menu a:hover { background: rgba(255,255,255,0.1); color: #fff; transform: translateX(4px); }
        .sidebar-menu a.active {
            background: rgba(255,255,255,0.18); color: #fff;
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }
        .sidebar-menu a.active::before {
            content: ''; position: absolute; left: 0; top: 50%; transform: translateY(-50%);
            width: 4px; height: 60%; background: #fff; border-radius: 0 4px 4px 0;
        }
        .sidebar-menu a .sidebar-noti-badge {
            margin-left: auto; background: #FF4757; color: #fff;
            font-size: 10px; font-weight: 700; padding: 2px 7px;
            border-radius: 10px; min-width: 20px; text-align: center; line-height: 1.4;
        }
        .sidebar-menu a.logout-btn {
            color: #FF6B8A; margin-top: 20px;
            border-top: 1px solid rgba(255,255,255,0.08); padding-top: 16px;
        }
        .sidebar-menu a.logout-btn:hover { background: rgba(255,107,138,0.12); color: #FF6B8A; transform: translateX(4px); }
        .sidebar-footer { padding: 16px 20px; border-top: 1px solid rgba(255,255,255,0.1); }
        .sidebar-footer .admin-info { display: flex; align-items: center; gap: 10px; }
        .sidebar-footer .admin-avatar-img {
            width: 38px; height: 38px; border-radius: 10px;
            border: 2px solid rgba(255,255,255,0.2);
            background: rgba(255,255,255,0.08); object-fit: cover; flex-shrink: 0;
        }
        .sidebar-footer .admin-name {
            color: #fff; font-size: 13px; font-weight: 600;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 150px;
        }
        .sidebar-footer .admin-role { color: rgba(255,255,255,0.5); font-size: 11px; }

        /* ═══ MAIN ═══ */
        .main-content {
            margin-left: var(--sidebar-width); min-height: 100vh;
            transition: margin-left 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .top-header {
            height: var(--header-height);
            background: rgba(255,255,255,0.8);
            backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            border-bottom: 1px solid var(--border-color);
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 30px; position: sticky; top: 0; z-index: 1000;
            transition: background 0.35s ease;
        }
        [data-theme="dark"] .top-header { background: rgba(17,11,24,0.88); }
        .top-header .left-section { display: flex; align-items: center; gap: 16px; }
        .sidebar-toggle {
            display: none; background: none; border: none;
            font-size: 20px; color: var(--primary); cursor: pointer;
            padding: 6px; border-radius: 8px; transition: background 0.2s;
        }
        .sidebar-toggle:hover { background: rgba(var(--primary-rgb), 0.08); }
        .top-header .page-title { font-size: 18px; font-weight: 700; color: var(--text-dark); transition: color 0.35s ease; }
        .top-header .page-title span { color: var(--primary); }
        .top-header .right-section { display: flex; align-items: center; gap: 10px; }
        .header-btn {
            width: 40px; height: 40px; border-radius: 12px;
            border: 1px solid var(--border-color); background: #fff;
            display: flex; align-items: center; justify-content: center;
            color: var(--text-muted); font-size: 16px; cursor: pointer;
            transition: all 0.25s ease; position: relative;
        }
        [data-theme="dark"] .header-btn { background: rgba(45,27,78,0.5); border-color: var(--border-color); color: var(--text-muted); }
        .header-btn:hover { border-color: var(--primary); color: var(--primary); box-shadow: var(--shadow-sm); }
        .header-time {
            font-size: 12.5px; color: var(--text-muted); font-weight: 500;
            background: rgba(var(--primary-rgb), 0.05); padding: 6px 14px; border-radius: 8px;
        }
        [data-theme="dark"] .header-time { background: rgba(156,39,176,0.08); }

        /* ═══ NOTIFICATIONS ═══ */
        .notification-wrapper { position: relative; }
        .noti-badge {
            position: absolute; top: 6px; right: 6px; min-width: 18px; height: 18px;
            background: #FF4757; color: #fff; font-size: 10px; font-weight: 700;
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            border: 2px solid #fff; padding: 0 3px; line-height: 1;
            animation: notiPulse 2s ease-in-out infinite;
        }
        [data-theme="dark"] .noti-badge { border-color: #1A1025; }
        @keyframes notiPulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.15); } }
        .notification-dropdown {
            position: absolute; top: calc(100% + 12px); right: -8px;
            width: 360px; max-height: 440px; background: #fff;
            border: 1px solid var(--border-color); border-radius: var(--radius);
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
            opacity: 0; visibility: hidden; transform: translateY(-8px) scale(0.97);
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            z-index: 9999; overflow: hidden; display: flex; flex-direction: column;
        }
        [data-theme="dark"] .notification-dropdown { background: #1F1333; border-color: rgba(156,39,176,0.15); }
        .notification-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
        .notification-dropdown .noti-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 14px 18px; border-bottom: 1px solid var(--border-color); flex-shrink: 0;
        }
        .notification-dropdown .noti-header h6 { font-size: 14px; font-weight: 700; color: var(--text-dark); margin: 0; display: flex; align-items: center; gap: 8px; }
        .notification-dropdown .noti-header h6 .count { background: var(--primary); color: #fff; font-size: 10px; padding: 2px 7px; border-radius: 8px; }
        .mark-read-btn {
            background: none; border: none; color: var(--primary);
            font-size: 11.5px; font-weight: 600; cursor: pointer;
            font-family: inherit; padding: 4px 8px; border-radius: 6px; transition: background 0.2s;
        }
        .mark-read-btn:hover { background: rgba(var(--primary-rgb), 0.08); }
        .notification-dropdown .noti-list { overflow-y: auto; flex: 1; }
        .notification-dropdown .noti-item {
            display: flex; align-items: flex-start; gap: 12px;
            padding: 13px 18px; border-bottom: 1px solid var(--border-color); transition: background 0.2s;
        }
        .notification-dropdown .noti-item:last-child { border-bottom: none; }
        .notification-dropdown .noti-item:hover { background: rgba(var(--primary-rgb), 0.03); }
        .notification-dropdown .noti-item.unread { background: rgba(var(--primary-rgb), 0.04); }
        .notification-dropdown .noti-dot { width: 8px; height: 8px; border-radius: 50%; background: #E0D4ED; flex-shrink: 0; margin-top: 6px; }
        .notification-dropdown .noti-dot.active { background: var(--primary); box-shadow: 0 0 0 3px rgba(var(--primary-rgb), 0.15); }
        .notification-dropdown .noti-content { flex: 1; min-width: 0; }
        .notification-dropdown .noti-content p { font-size: 12.5px; color: var(--text-dark); margin: 0 0 3px; line-height: 1.45; }
        .notification-dropdown .noti-content span { font-size: 11px; color: var(--text-muted); }
        .notification-dropdown .noti-icon {
            width: 32px; height: 32px; border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; flex-shrink: 0; margin-top: 1px;
        }
        .notification-dropdown .noti-icon.assignment { background: rgba(33,150,243,0.1); color: #2196F3; }
        .notification-dropdown .noti-icon.register { background: rgba(76,175,80,0.1); color: #4CAF50; }
        .notification-dropdown .noti-icon.default { background: rgba(var(--primary-rgb), 0.1); color: var(--primary); }
        .noti-empty { padding: 40px 20px; text-align: center; color: var(--text-muted); font-size: 13px; }
        .noti-empty i { font-size: 28px; margin-bottom: 8px; display: block; opacity: 0.3; }

        /* ═══ DASHBOARD BODY ═══ */
        .dashboard-body { padding: 28px 30px 40px; }

        /* ═══ WELCOME BANNER ═══ */
        .welcome-banner {
            position: relative; border-radius: var(--radius); overflow: hidden;
            margin-bottom: 28px; box-shadow: var(--shadow-lg);
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary) 40%, var(--primary-light) 80%, #CE93D8 100%);
            padding: 32px 36px; color: #fff;
        }
        .welcome-banner::before {
            content: ''; position: absolute; inset: 0;
            background:
                radial-gradient(ellipse 600px 400px at 20% 80%, rgba(206,147,216,0.3), transparent),
                radial-gradient(ellipse 500px 300px at 80% 20%, rgba(156,39,176,0.2), transparent);
        }
        .welcome-banner .cover-pattern {
            position: absolute; inset: 0; opacity: 0.06;
            background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='1'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
        }
        .welcome-banner h2 {
            font-size: 24px; font-weight: 800; margin: 0 0 6px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.15); position: relative; z-index: 1;
        }
        .welcome-banner p {
            margin: 0; opacity: 0.85; font-size: 14px; position: relative; z-index: 1;
        }
        .welcome-banner .welcome-icon {
            position: absolute; right: 36px; top: 50%; transform: translateY(-50%);
            font-size: 80px; opacity: 0.1; z-index: 0;
        }

        /* ═══ GLASS STAT CARDS ═══ */
        .stat-card {
            background: var(--card-bg); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color); border-radius: var(--radius);
            padding: 24px 22px; position: relative; overflow: hidden;
            box-shadow: var(--shadow-sm);
            transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .stat-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0;
            height: 4px; border-radius: var(--radius) var(--radius) 0 0;
            opacity: 0; transition: opacity 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-lg);
            border-color: rgba(var(--primary-rgb), 0.15);
        }
        .stat-card:hover::before { opacity: 1; }

        .stat-card .s-icon {
            width: 52px; height: 52px; border-radius: 15px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; margin-bottom: 18px;
            transition: transform 0.3s ease;
        }
        .stat-card:hover .s-icon { transform: scale(1.1) rotate(-5deg); }

        .stat-card .s-value {
            font-size: 28px; font-weight: 800; color: var(--text-dark);
            line-height: 1; margin-bottom: 4px; letter-spacing: -0.5px;
        }
        .stat-card .s-label { font-size: 12.5px; color: var(--text-muted); font-weight: 500; }

        .stat-card .s-bg {
            position: absolute; right: -10px; bottom: -14px;
            font-size: 86px; opacity: 0.022; color: var(--primary);
            pointer-events: none; transition: opacity 0.3s ease;
        }
        .stat-card:hover .s-bg { opacity: 0.05; }

        /* Card color variants */
        .c-purple::before { background: linear-gradient(90deg, #6A0DAD, #9C27B0); }
        .c-purple .s-icon { background: rgba(var(--primary-rgb), 0.1); color: var(--primary); }

        .c-blue::before { background: linear-gradient(90deg, #1565C0, #42A5F5); }
        .c-blue .s-icon { background: rgba(33,150,243,0.1); color: #1976D2; }

        .c-green::before { background: linear-gradient(90deg, #2E7D32, #66BB6A); }
        .c-green .s-icon { background: rgba(76,175,80,0.1); color: #388E3C; }

        .c-orange::before { background: linear-gradient(90deg, #E65100, #FFA726); }
        .c-orange .s-icon { background: rgba(255,152,0,0.1); color: #F57C00; }

        /* ═══ CHART CARDS ═══ */
        .chart-card {
            background: var(--card-bg); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color); border-radius: var(--radius);
            padding: 24px; box-shadow: var(--shadow-sm);
            transition: box-shadow 0.35s ease; height: 100%;
            display: flex; flex-direction: column;
        }
        .chart-card:hover { box-shadow: var(--shadow-md); }

        .ch-head {
            display: flex; align-items: flex-start; justify-content: space-between;
            margin-bottom: 20px; flex-wrap: wrap; gap: 8px;
        }
        .ch-title { font-size: 16px; font-weight: 700; color: var(--text-dark); margin-bottom: 2px; }
        .ch-sub { font-size: 12px; color: var(--text-muted); font-weight: 400; }
        .ch-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(var(--primary-rgb), 0.07); color: var(--primary);
            padding: 5px 14px; border-radius: 20px;
            font-size: 11px; font-weight: 600; white-space: nowrap;
        }
        .ch-body {
            flex: 1; display: flex; align-items: center; justify-content: center;
            position: relative;
        }

        /* ═══ SETTINGS PANEL ═══ */
        .settings-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 1060; backdrop-filter: blur(4px); opacity: 0; transition: opacity 0.3s ease; }
        .settings-overlay.show { display: block; opacity: 1; }
        .settings-panel {
            position: fixed; top: 0; right: 0; width: 340px; max-width: 90vw; height: 100vh;
            background: #fff; border-left: 1px solid var(--border-color); z-index: 1070;
            transform: translateX(100%); transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex; flex-direction: column; box-shadow: -8px 0 40px rgba(0,0,0,0.1);
        }
        [data-theme="dark"] .settings-panel { background: #1A1025; border-left-color: rgba(156,39,176,0.15); }
        .settings-panel.show { transform: translateX(0); }
        .settings-panel-header { display: flex; align-items: center; justify-content: space-between; padding: 22px 24px; border-bottom: 1px solid var(--border-color); }
        .settings-panel-header h5 { font-size: 17px; font-weight: 700; color: var(--text-dark); margin: 0; display: flex; align-items: center; gap: 10px; }
        .settings-panel-header h5 i { color: var(--primary); }
        .settings-close-btn {
            width: 36px; height: 36px; border-radius: 10px; border: 1px solid var(--border-color);
            background: transparent; display: flex; align-items: center; justify-content: center;
            color: var(--text-muted); font-size: 14px; cursor: pointer; transition: all 0.2s;
        }
        .settings-close-btn:hover { background: rgba(244,67,54,0.08); border-color: rgba(244,67,54,0.2); color: #F44336; }
        .settings-body { flex: 1; overflow-y: auto; padding: 8px 0; }
        .settings-section { padding: 20px 24px; border-bottom: 1px solid var(--border-color); }
        .settings-label { font-size: 14px; font-weight: 600; color: var(--text-dark); margin-bottom: 4px; }
        .settings-desc { font-size: 12px; color: var(--text-muted); margin-bottom: 14px; line-height: 1.5; }
        .theme-toggle-row { display: flex; align-items: center; justify-content: space-between; }
        .theme-toggle-options { display: flex; align-items: center; gap: 10px; }
        .theme-toggle-options i { font-size: 15px; }
        .theme-toggle-options .fa-sun { color: #FF9800; }
        .theme-toggle-options .fa-moon { color: #5C6BC0; }
        .theme-switch { position: relative; width: 52px; height: 28px; display: inline-block; cursor: pointer; }
        .theme-switch input { opacity: 0; width: 0; height: 0; position: absolute; }
        .theme-switch .slider { position: absolute; inset: 0; background: #E0D4ED; border-radius: 28px; transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1); }
        .theme-switch .slider::before {
            content: ''; position: absolute; width: 22px; height: 22px; left: 3px; bottom: 3px;
            background: #fff; border-radius: 50%; transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
        }
        .theme-switch input:checked + .slider { background: var(--primary); }
        .theme-switch input:checked + .slider::before { transform: translateX(24px); }

        /* ═══ SIDEBAR OVERLAY ═══ */
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1040; backdrop-filter: blur(4px); }
        .sidebar-overlay.show { display: block; }

        /* ═══ ANIMATIONS ═══ */
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .animate-in { animation: fadeInUp 0.5s ease forwards; opacity: 0; }
        .animate-in:nth-child(1) { animation-delay: 0.05s; }
        .animate-in:nth-child(2) { animation-delay: 0.1s; }
        .animate-in:nth-child(3) { animation-delay: 0.15s; }
        .animate-in:nth-child(4) { animation-delay: 0.2s; }
        .chart-animate { animation: fadeInUp 0.6s ease 0.3s forwards; opacity: 0; }

        /* ═══ SCROLLBAR ═══ */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(var(--primary-rgb), 0.2); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(var(--primary-rgb), 0.35); }

        /* ═══ RESPONSIVE ═══ */
        @media (max-width: 991.98px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.show { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .sidebar-toggle { display: flex; }
            .dashboard-body { padding: 20px 16px 30px; }
            .top-header { padding: 0 16px; }
            .header-time { display: none; }
            .notification-dropdown { width: 320px; right: -40px; }
            .welcome-banner { padding: 24px 22px; }
            .welcome-banner h2 { font-size: 20px; }
            .welcome-banner .welcome-icon { font-size: 50px; right: 20px; }
        }
        @media (max-width: 575.98px) {
            .stat-card .s-value { font-size: 24px; }
            .stat-card .s-icon { width: 46px; height: 46px; font-size: 18px; border-radius: 13px; }
            .chart-card { padding: 18px 14px; }
            .notification-dropdown { width: calc(100vw - 32px); right: -60px; max-height: 380px; }
            .settings-panel { width: 100vw; max-width: 100vw; }
        }
    </style>
</head>
<body>

    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Settings Overlay -->
    <div class="settings-overlay" id="settingsOverlay"></div>

    <!-- ═══════ SIDEBAR ═══════ -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="brand-icon"><img src="image/logo1.png" alt="Logo"></div>
            <div>
                <h5>AI Checker</h5>
                <small>Admin Panel</small>
            </div>
        </div>
        <nav class="sidebar-menu">
            <div class="menu-label">Main</div>
            <a href="adminpage.php" class="active">
                <i class="fas fa-th-large fa-icon"></i> Dashboard
            </a>
            <a href="admin_users.php">
                <i class="fas fa-users fa-icon"></i> Users
            </a>
            <a href="admin_assignments.php">
                <i class="fas fa-file-alt fa-icon"></i> Assignments
            </a>
            <a href="admin_reviews.php">
                <i class="fas fa-star"></i> Reviews
            </a>
            <div class="menu-label">Management</div>
            <a href="admin_plans.php">
                <i class="fas fa-tags fa-icon"></i> Plans
            </a>
            <a href="admin_payments.php">
                <i class="fas fa-credit-card fa-icon"></i> Payments
            </a>
            <a href="admin_vouchers.php">
                <i class="fas fa-ticket-alt fa-icon"></i> Vouchers
            </a>
            <a href="admin_testimonials.php">
                <i class="fas fa-quote-right fa-icon"></i> Testimonials
            </a>
            <a href="admin_profile.php">
                <i class="fas fa-user-circle fa-icon"></i> Profile
            </a>
            <a href="login.php" class="logout-btn">
                <i class="fas fa-sign-out-alt fa-icon"></i> Logout
            </a>
        </nav>
        <div class="sidebar-footer">
            <div class="admin-info">
                <img src="<?php echo htmlspecialchars($avatar); ?>" class="admin-avatar-img" alt="<?php echo htmlspecialchars($admin_name); ?>" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=Admin&backgroundColor=ede9fe';">
                <div>
                    <div class="admin-name"><?php echo htmlspecialchars($admin_name); ?></div>
                    <div class="admin-role">Administrator</div>
                </div>
            </div>
        </div>
    </aside>