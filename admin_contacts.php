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
// DELETE CONTACT MESSAGE
// ═══════════════════════════════════════════════════════════════
 $deleteMsg = '';
 $deleteType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_contact'])) {
    $del_id = (int)$_POST['delete_id'];
    if ($del_id > 0) {
        $del_stmt = $conn->prepare("DELETE FROM contacts WHERE contact_id = ?");
        $del_stmt->bind_param("i", $del_id);
        if ($del_stmt->execute()) {
            $deleteMsg = 'Message deleted successfully.';
            $deleteType = 'success';
        } else {
            $deleteMsg = 'Failed to delete message. Please try again.';
            $deleteType = 'error';
        }
        $del_stmt->close();
    }
}

// ═══════════════════════════════════════════════════════════════
// SEARCH & FILTER PARAMETERS
// ═══════════════════════════════════════════════════════════════
 $searchQuery = trim($_GET['search'] ?? '');
 $filterPeriod = trim($_GET['filter'] ?? '');

// ═══════════════════════════════════════════════════════════════
// FETCH CONTACT MESSAGES — ALL PREPARED STATEMENTS
// ═══════════════════════════════════════════════════════════════
 $contacts = [];
 $totalContacts = 0;

 $whereClause = "";
 $params = [];
 $types = "";

if (!empty($searchQuery)) {
    $whereClause .= " (name LIKE ? OR email LIKE ? OR message LIKE ?)";
    $searchWild = "%" . $searchQuery . "%";
    $params[] = $searchWild;
    $params[] = $searchWild;
    $params[] = $searchWild;
    $types .= "sss";
}

if ($filterPeriod === 'today') {
    if (!empty($whereClause)) $whereClause .= " AND";
    $whereClause .= " DATE(created_at) = CURDATE()";
} elseif ($filterPeriod === 'week') {
    if (!empty($whereClause)) $whereClause .= " AND";
    $whereClause .= " YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)";
} elseif ($filterPeriod === 'month') {
    if (!empty($whereClause)) $whereClause .= " AND";
    $whereClause .= " MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())";
}

 $sql = "SELECT * FROM contacts";
if (!empty($whereClause)) $sql .= " WHERE" . $whereClause;
 $sql .= " ORDER BY created_at DESC";

 $stmt = $conn->prepare($sql);
if (!empty($types)) {
    $stmt->bind_param($types, ...$params);
}
 $stmt->execute();
 $result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $contacts[] = $row;
}
 $stmt->close();

 $totalStmt = $conn->prepare("SELECT COUNT(*) AS total FROM contacts");
 $totalStmt->execute();
 $totalContacts = $totalStmt->get_result()->fetch_assoc()['total'];
 $totalStmt->close();

// Count today's messages
 $todayCount = 0;
 $todayStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM contacts WHERE DATE(created_at) = CURDATE()");
 $todayStmt->execute();
 $todayCount = $todayStmt->get_result()->fetch_assoc()['cnt'];
 $todayStmt->close();

// Count this week's messages
 $weekCount = 0;
 $weekStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM contacts WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)");
 $weekStmt->execute();
 $weekCount = $weekStmt->get_result()->fetch_assoc()['cnt'];
 $weekStmt->close();

 $conn->close();
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Messages — AI Assignment Checker</title>
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
            width: 52px; height: 60px; background: rgba(255,255,255,0.15);
            border-radius: 12px; overflow: hidden; backdrop-filter: blur(10px);
            flex-shrink: 0;
        }
        .sidebar-brand .brand-icon img { width: 100%; height: 100%; object-fit: cover; }
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

        /* ═══ STAT CARDS ═══ */
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
        .stat-card:hover { transform: translateY(-6px); box-shadow: var(--shadow-lg); border-color: rgba(var(--primary-rgb), 0.15); }
        .stat-card:hover::before { opacity: 1; }
        .stat-card .s-icon {
            width: 52px; height: 52px; border-radius: 15px;
            display: flex; align-items: center; justify-content: center;
            font-size: 20px; margin-bottom: 18px; transition: transform 0.3s ease;
        }
        .stat-card:hover .s-icon { transform: scale(1.1) rotate(-5deg); }
        .stat-card .s-value { font-size: 28px; font-weight: 800; color: var(--text-dark); line-height: 1; margin-bottom: 4px; letter-spacing: -0.5px; }
        .stat-card .s-label { font-size: 12.5px; color: var(--text-muted); font-weight: 500; }
        .stat-card .s-bg { position: absolute; right: -10px; bottom: -14px; font-size: 86px; opacity: 0.022; color: var(--primary); pointer-events: none; transition: opacity 0.3s ease; }
        .stat-card:hover .s-bg { opacity: 0.05; }
        .c-purple::before { background: linear-gradient(90deg, #6A0DAD, #9C27B0); }
        .c-purple .s-icon { background: rgba(var(--primary-rgb), 0.1); color: var(--primary); }
        .c-blue::before { background: linear-gradient(90deg, #1565C0, #42A5F5); }
        .c-blue .s-icon { background: rgba(33,150,243,0.1); color: #1976D2; }
        .c-green::before { background: linear-gradient(90deg, #2E7D32, #66BB6A); }
        .c-green .s-icon { background: rgba(76,175,80,0.1); color: #388E3C; }
        .c-orange::before { background: linear-gradient(90deg, #E65100, #FFA726); }
        .c-orange .s-icon { background: rgba(255,152,0,0.1); color: #F57C00; }

        /* ═══ CONTACT TABLE CARD ═══ */
        .contact-table-card {
            background: var(--card-bg); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color); border-radius: var(--radius);
            box-shadow: var(--shadow-sm); overflow: hidden;
            transition: box-shadow 0.35s ease;
        }
        .contact-table-card:hover { box-shadow: var(--shadow-md); }
        .contact-table-header {
            padding: 22px 26px; border-bottom: 1px solid var(--border-color);
            display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;
        }
        .contact-table-header h5 {
            font-size: 16px; font-weight: 700; color: var(--text-dark); margin: 0;
            display: flex; align-items: center; gap: 10px;
        }
        .contact-table-header h5 i { color: var(--primary); }

        /* ═══ SEARCH & FILTER BAR ═══ */
        .search-filter-bar {
            padding: 18px 26px; border-bottom: 1px solid var(--border-color);
            display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
            background: rgba(var(--primary-rgb), 0.015);
        }
        .search-input-wrap {
            flex: 1; min-width: 220px; position: relative;
        }
        .search-input-wrap i {
            position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
            color: var(--text-muted); font-size: 14px;
        }
        .search-input-wrap input {
            width: 100%; padding: 10px 14px 10px 40px;
            border: 1.5px solid var(--border-color); border-radius: var(--radius-sm);
            background: var(--input-bg); color: var(--text-dark);
            font-size: 13px; font-family: 'Poppins', sans-serif;
            transition: all 0.25s ease;
        }
        .search-input-wrap input:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(var(--primary-rgb), 0.08);
        }
        .search-input-wrap input::placeholder { color: var(--text-muted); }
        .filter-select {
            padding: 10px 36px 10px 14px; min-width: 160px;
            border: 1.5px solid var(--border-color); border-radius: var(--radius-sm);
            background: var(--input-bg); color: var(--text-dark);
            font-size: 13px; font-family: 'Poppins', sans-serif;
            transition: all 0.25s ease; cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%237B6B8D' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 12px center;
        }
        .filter-select:focus {
            outline: none; border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(var(--primary-rgb), 0.08);
        }
        .clear-filters-btn {
            padding: 10px 16px; border: 1.5px solid var(--border-color);
            border-radius: var(--radius-sm); background: transparent;
            color: var(--text-muted); font-size: 13px; font-family: 'Poppins', sans-serif;
            font-weight: 500; cursor: pointer; transition: all 0.25s ease;
            display: inline-flex; align-items: center; gap: 6px;
        }
        .clear-filters-btn:hover { border-color: #F44336; color: #F44336; background: rgba(244,67,54,0.04); }

        /* ═══ TABLE ═══ */
        .contacts-table-wrapper { overflow-x: auto; }
        .contacts-table {
            width: 100%; border-collapse: collapse; font-size: 13px;
        }
        .contacts-table thead th {
            padding: 14px 20px; text-align: left;
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.8px; color: var(--text-muted);
            background: rgba(var(--primary-rgb), 0.025);
            border-bottom: 1.5px solid var(--border-color);
            white-space: nowrap;
        }
        .contacts-table tbody td {
            padding: 16px 20px; vertical-align: middle;
            border-bottom: 1px solid var(--border-color);
            transition: background 0.2s ease;
        }
        .contacts-table tbody tr { transition: all 0.2s ease; }
        .contacts-table tbody tr:hover { background: rgba(var(--primary-rgb), 0.03); }
        .contacts-table tbody tr:last-child td { border-bottom: none; }
        .contacts-table tbody tr.is-new { background: rgba(var(--primary-rgb), 0.04); }
        .contacts-table tbody tr.is-new:hover { background: rgba(var(--primary-rgb), 0.06); }

        .contact-id {
            font-weight: 700; color: var(--primary); font-size: 13px;
            background: rgba(var(--primary-rgb), 0.08);
            padding: 3px 10px; border-radius: 6px; display: inline-block;
        }
        .contact-name {
            font-weight: 600; color: var(--text-dark); font-size: 13.5px;
            display: flex; align-items: center; gap: 10px;
        }
        .contact-avatar {
            width: 34px; height: 34px; border-radius: 10px;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: #fff; display: flex; align-items: center; justify-content: center;
            font-size: 13px; font-weight: 700; flex-shrink: 0;
        }
        .contact-email a {
            color: var(--primary); text-decoration: none; font-size: 12.5px;
            transition: color 0.2s ease;
        }
        .contact-email a:hover { color: var(--primary-light); text-decoration: underline; }
        .contact-message-preview {
            color: var(--text-muted); font-size: 12.5px;
            max-width: 280px; white-space: nowrap; overflow: hidden;
            text-overflow: ellipsis; line-height: 1.4;
        }
        .contact-date {
            font-size: 12px; color: var(--text-muted); white-space: nowrap;
            display: flex; align-items: center; gap: 6px;
        }
        .contact-date i { font-size: 11px; opacity: 0.6; }

        .new-badge {
            display: inline-flex; align-items: center; gap: 4px;
            background: linear-gradient(135deg, #FF6B6B, #EE5A24);
            color: #fff; font-size: 9.5px; font-weight: 700;
            padding: 2px 8px; border-radius: 6px;
            text-transform: uppercase; letter-spacing: 0.5px;
            animation: newPulse 2.5s ease-in-out infinite;
        }
        @keyframes newPulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.7; } }

        /* ═══ ACTION BUTTONS ═══ */
        .action-btns { display: flex; align-items: center; gap: 6px; }
        .btn-view-msg {
            width: 34px; height: 34px; border-radius: 8px;
            border: 1px solid rgba(33,150,243,0.2); background: rgba(33,150,243,0.06);
            color: #1976D2; display: inline-flex; align-items: center; justify-content: center;
            font-size: 13px; cursor: pointer; transition: all 0.25s ease;
        }
        .btn-view-msg:hover { background: #1976D2; color: #fff; border-color: #1976D2; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(25,118,210,0.3); }
        .btn-delete-msg {
            width: 34px; height: 34px; border-radius: 8px;
            border: 1px solid rgba(244,67,54,0.2); background: rgba(244,67,54,0.06);
            color: #F44336; display: inline-flex; align-items: center; justify-content: center;
            font-size: 13px; cursor: pointer; transition: all 0.25s ease;
        }
        .btn-delete-msg:hover { background: #F44336; color: #fff; border-color: #F44336; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(244,67,54,0.3); }

        /* ═══ TABLE FOOTER ═══ */
        .table-footer {
            padding: 14px 26px; border-top: 1px solid var(--border-color);
            display: flex; align-items: center; justify-content: space-between;
            font-size: 12px; color: var(--text-muted);
        }

        /* ═══ EMPTY STATE ═══ */
        .empty-contacts { padding: 60px 20px; text-align: center; }
        .empty-contacts-icon {
            width: 90px; height: 90px; border-radius: 50%;
            background: rgba(var(--primary-rgb), 0.06);
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 20px; font-size: 36px; color: var(--primary-light);
        }
        .empty-contacts h6 { font-weight: 700; color: var(--text-dark); margin-bottom: 6px; font-size: 16px; }
        .empty-contacts p { color: var(--text-muted); font-size: 13px; max-width: 360px; margin: 0 auto; }

        /* ═══ VIEW MODAL — CUSTOM BLUR OVERLAY ═══ */
        .view-overlay {
            position: fixed; inset: 0; z-index: 1090;
            display: none; align-items: center; justify-content: center;
            background: rgba(42, 20, 70, 0.55);
            padding: 24px;
            opacity: 0; transition: opacity 0.35s ease;
        }
        .view-overlay.show { display: flex; opacity: 1; }
        .view-modal {
            background: var(--card-bg);
            backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px);
            border: 1px solid rgba(var(--primary-rgb), 0.15);
            border-radius: var(--radius);
            box-shadow: 0 24px 80px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(255,255,255,0.05) inset;
            width: 100%; max-width: 720px;
            max-height: 90vh;
            display: flex; flex-direction: column;
            overflow: hidden;
            animation: viewModalIn 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes viewModalIn {
            from { opacity: 0; transform: translateY(30px) scale(0.96); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .view-modal-header {
            padding: 22px 28px;
            background: linear-gradient(135deg, var(--primary-dark), var(--primary), var(--primary-light));
            color: #fff; display: flex; align-items: center; justify-content: space-between;
            flex-shrink: 0;
        }
        .view-modal-header h5 { font-size: 16px; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 10px; }
        .view-close-btn {
            width: 34px; height: 34px; border-radius: 8px; border: none;
            background: rgba(255,255,255,0.15); color: #fff;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; font-size: 14px; transition: all 0.25s;
        }
        .view-close-btn:hover { background: rgba(255,255,255,0.3); transform: rotate(90deg); }
        .view-modal-body {
            padding: 28px;
            overflow-y: auto;
            flex: 1;
        }
        .view-detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0; }
        .view-detail-item {
            padding: 16px 0; border-bottom: 1px solid var(--border-color);
        }
        .view-detail-item.full-width { grid-column: 1 / -1; }
        .view-detail-label {
            font-size: 10.5px; font-weight: 700; text-transform: uppercase;
            letter-spacing: 0.8px; color: var(--text-muted); margin-bottom: 6px;
        }
        .view-detail-value { font-size: 14px; color: var(--text-dark); font-weight: 500; }
        .view-detail-value a { color: var(--primary); text-decoration: none; }
        .view-detail-value a:hover { text-decoration: underline; }
        .view-message-box {
            background: rgba(var(--primary-rgb), 0.04);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 20px 22px;
            font-size: 14px;
            line-height: 1.8;
            color: var(--text-dark);
            white-space: pre-wrap;
            word-break: break-word;
        }
        .view-modal-footer {
            padding: 16px 28px; border-top: 1px solid var(--border-color);
            display: flex; align-items: center; justify-content: flex-end; gap: 10px;
            flex-shrink: 0;
        }
        .btn-view-close {
            padding: 9px 22px; border-radius: var(--radius-sm);
            border: 1.5px solid var(--border-color); background: transparent;
            color: var(--text-muted); font-size: 13px; font-weight: 600;
            font-family: 'Poppins', sans-serif; cursor: pointer;
            transition: all 0.25s ease; display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-view-close:hover { border-color: var(--primary); color: var(--primary); }
        .btn-view-delete {
            padding: 9px 22px; border-radius: var(--radius-sm);
            border: none; background: linear-gradient(135deg, #F44336, #E53935);
            color: #fff; font-size: 13px; font-weight: 600;
            font-family: 'Poppins', sans-serif; cursor: pointer;
            transition: all 0.25s ease; display: inline-flex; align-items: center; gap: 6px;
            box-shadow: 0 4px 14px rgba(244,67,54,0.3);
        }
        .btn-view-delete:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(244,67,54,0.4); color: #fff; }

        /* ═══ TOAST ALERT ═══ */
        .toast-alert {
            position: fixed; top: 90px; right: 30px;
            padding: 14px 22px; border-radius: var(--radius-sm);
            font-size: 13px; font-weight: 500; z-index: 9999;
            display: flex; align-items: center; gap: 10px;
            box-shadow: var(--shadow-lg); min-width: 280px;
            animation: toastSlide 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            transition: all 0.35s ease;
        }
        .toast-alert.success {
            background: #fff; border-left: 4px solid #4CAF50; color: #2E7D32;
        }
        [data-theme="dark"] .toast-alert.success { background: #1A1025; }
        .toast-alert.error {
            background: #fff; border-left: 4px solid #F44336; color: #C62828;
        }
        [data-theme="dark"] .toast-alert.error { background: #1A1025; }
        .toast-alert.hide { opacity: 0; transform: translateX(40px); }
        @keyframes toastSlide { from { opacity: 0; transform: translateX(40px); } to { opacity: 1; transform: translateX(0); } }

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

        /* ═══ DELETE CONFIRM MODAL ═══ */
        .confirm-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,0.5);
            z-index: 1080; display: none; align-items: center; justify-content: center;
            backdrop-filter: blur(4px);
        }
        .confirm-overlay.show { display: flex; }
        .confirm-box {
            background: #fff; border-radius: var(--radius);
            padding: 32px; max-width: 400px; width: 90%; text-align: center;
            box-shadow: var(--shadow-lg); animation: confirmPop 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }
        [data-theme="dark"] .confirm-box { background: #1F1333; }
        @keyframes confirmPop { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }
        .confirm-icon {
            width: 64px; height: 64px; border-radius: 50%;
            background: rgba(244,67,54,0.1); color: #F44336;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 18px; font-size: 26px;
        }
        .confirm-box h6 { font-size: 17px; font-weight: 700; color: var(--text-dark); margin-bottom: 8px; }
        .confirm-box p { font-size: 13px; color: var(--text-muted); margin-bottom: 24px; line-height: 1.5; }
        .confirm-btns { display: flex; gap: 10px; justify-content: center; }
        .confirm-btn-cancel {
            padding: 10px 24px; border-radius: var(--radius-sm);
            border: 1.5px solid var(--border-color); background: transparent;
            color: var(--text-muted); font-size: 13px; font-weight: 600;
            font-family: 'Poppins', sans-serif; cursor: pointer; transition: all 0.2s;
        }
        .confirm-btn-cancel:hover { border-color: var(--text-dark); color: var(--text-dark); }
        .confirm-btn-delete {
            padding: 10px 24px; border-radius: var(--radius-sm);
            border: none; background: linear-gradient(135deg, #F44336, #E53935);
            color: #fff; font-size: 13px; font-weight: 600;
            font-family: 'Poppins', sans-serif; cursor: pointer;
            transition: all 0.25s ease; box-shadow: 0 4px 14px rgba(244,67,54,0.3);
            display: inline-flex; align-items: center; gap: 6px;
        }
        .confirm-btn-delete:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(244,67,54,0.4); color: #fff; }

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
            .search-filter-bar { flex-direction: column; align-items: stretch; }
            .search-input-wrap { min-width: unset; }
            .filter-select { min-width: unset; }
            .view-detail-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 575.98px) {
            .stat-card .s-value { font-size: 24px; }
            .stat-card .s-icon { width: 46px; height: 46px; font-size: 18px; border-radius: 13px; }
            .notification-dropdown { width: calc(100vw - 32px); right: -60px; max-height: 380px; }
            .settings-panel { width: 100vw; max-width: 100vw; }
            .contact-table-header { padding: 16px 18px; }
            .search-filter-bar { padding: 14px 18px; }
            .table-footer { padding: 12px 18px; flex-direction: column; gap: 6px; }
            .toast-alert { right: 16px; left: 16px; min-width: unset; }
            .view-overlay { padding: 12px; }
            .view-modal { max-height: 94vh; }
            .view-modal-header { padding: 18px 20px; }
            .view-modal-body { padding: 20px; }
            .view-modal-footer { padding: 14px 20px; flex-direction: column; }
            .btn-view-close, .btn-view-delete { width: 100%; justify-content: center; }
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
            <div class="brand-icon"><img src="image/logo.png" alt="Logo"></div>
            <div>
                <h5>AI Checker</h5>
                <small>Admin Panel</small>
            </div>
        </div>
        <nav class="sidebar-menu">
            <div class="menu-label">Main</div>
            <a href="adminpage.php">
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
            <a href="ai_analysis.php">
                <i class="fas fa-magnifying-glass-chart"></i> Analysis
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
            <a href="admin_contacts.php" class="active">
                <i class="fas fa-phone-alt"></i> Contacts
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

    <!-- ═══════ SETTINGS PANEL ═══════ -->
    <div class="settings-panel" id="settingsPanel">
        <div class="settings-panel-header">
            <h5><i class="fas fa-cog"></i> Settings</h5>
            <button class="settings-close-btn" id="settingsCloseBtn" aria-label="Close settings"><i class="fas fa-times"></i></button>
        </div>
        <div class="settings-body">
            <div class="settings-section">
                <div class="settings-label">Appearance</div>
                <div class="settings-desc">Choose between light and dark mode for your dashboard.</div>
                <div class="theme-toggle-row">
                    <div class="theme-toggle-options">
                        <i class="fas fa-sun"></i>
                        <label class="theme-switch">
                            <input type="checkbox" id="themeToggle">
                            <span class="slider"></span>
                        </label>
                        <i class="fas fa-moon"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════ DELETE CONFIRM MODAL ═══════ -->
    <div class="confirm-overlay" id="confirmOverlay">
        <div class="confirm-box">
            <div class="confirm-icon"><i class="fas fa-trash-alt"></i></div>
            <h6>Delete Message?</h6>
            <p>Are you sure you want to delete this message? This action cannot be undone.</p>
            <form method="POST" id="confirmDeleteForm">
                <input type="hidden" name="delete_id" id="confirmDeleteId">
                <input type="hidden" name="delete_contact" value="1">
                <div class="confirm-btns">
                    <button type="button" class="confirm-btn-cancel" onclick="closeConfirm()">Cancel</button>
                    <button type="submit" class="confirm-btn-delete"><i class="fas fa-trash-alt"></i> Delete</button>
                </div>
            </form>
        </div>
    </div>

    <!-- ═══════ VIEW MESSAGE MODAL — BLUR OVERLAY ═══════ -->
    <div class="view-overlay" id="viewOverlay">
        <div class="view-modal" id="viewModal">
            <div class="view-modal-header">
                <h5><i class="fas fa-envelope-open-text"></i> Message Details</h5>
                <button class="view-close-btn" id="viewCloseBtn" aria-label="Close"><i class="fas fa-times"></i></button>
            </div>
            <div class="view-modal-body">
                <div class="view-detail-grid">
                    <div class="view-detail-item">
                        <div class="view-detail-label">Contact ID</div>
                        <div class="view-detail-value" id="modalContactId"></div>
                    </div>
                    <div class="view-detail-item">
                        <div class="view-detail-label">Date Sent</div>
                        <div class="view-detail-value" id="modalDate"></div>
                    </div>
                    <div class="view-detail-item">
                        <div class="view-detail-label">Name</div>
                        <div class="view-detail-value" id="modalName"></div>
                    </div>
                    <div class="view-detail-item">
                        <div class="view-detail-label">Email</div>
                        <div class="view-detail-value" id="modalEmail"></div>
                    </div>
                    <div class="view-detail-item full-width">
                        <div class="view-detail-label">Message</div>
                        <div class="view-message-box" id="modalMessage"></div>
                    </div>
                </div>
            </div>
            <div class="view-modal-footer">
                <button class="btn-view-delete" id="modalDeleteBtn">
                    <i class="fas fa-trash-alt"></i> Delete
                </button>
                <button class="btn-view-close" id="viewCloseBtn2">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
        </div>
    </div>

    <!-- ═══════ TOAST ALERT ═══════ -->
    <?php if (!empty($deleteMsg)): ?>
    <div class="toast-alert <?php echo $deleteType; ?>" id="toastAlert">
        <i class="fas fa-<?php echo $deleteType === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <span><?php echo htmlspecialchars($deleteMsg); ?></span>
    </div>
    <?php endif; ?>

    <!-- ═══════ MAIN CONTENT ═══════ -->
    <main class="main-content">

        <!-- Top Header -->
        <header class="top-header">
            <div class="left-section">
                <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
                <div class="page-title">Contact <span>Messages</span></div>
            </div>
            <div class="right-section">
                <span class="header-time" id="headerTime"></span>
                <div class="notification-wrapper">
                    <button class="header-btn" aria-label="Notifications">
                        <i class="fas fa-bell"></i>
                        <?php if ($unread_count > 0): ?>
                        <span class="noti-badge"><?php echo $unread_count; ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="notification-dropdown" id="notiDropdown">
                        <div class="noti-header">
                            <h6>Notifications <span class="count"><?php echo $unread_count; ?></span></h6>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="mark_all_read">
                                <button type="submit" class="mark-read-btn">Mark all read</button>
                            </form>
                        </div>
                        <div class="noti-list">
                            <?php if (empty($notifications)): ?>
                            <div class="noti-empty"><i class="fas fa-bell-slash"></i>No notifications yet</div>
                            <?php else: ?>
                                <?php foreach ($notifications as $n): ?>
                                <div class="noti-item <?php echo $n['is_read'] == 0 ? 'unread' : ''; ?>">
                                    <div class="noti-dot <?php echo $n['is_read'] == 0 ? 'active' : ''; ?>"></div>
                                    <div class="noti-content">
                                        <p><?php echo htmlspecialchars($n['message'] ?? 'New notification'); ?></p>
                                        <span><?php echo time_ago($n['created_at']); ?></span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <button class="header-btn settings-trigger" aria-label="Settings">
                    <i class="fas fa-cog"></i>
                </button>
            </div>
        </header>

        <!-- Dashboard Body -->
        <section class="dashboard-body">

            <!-- Stat Cards -->
            <div class="row g-3 mb-4">
                <div class="col-xl-3 col-sm-6">
                    <div class="stat-card c-purple animate-in">
                        <div class="s-icon"><i class="fas fa-envelope"></i></div>
                        <div class="s-value"><?php echo $totalContacts; ?></div>
                        <div class="s-label">Total Messages</div>
                        <div class="s-bg"><i class="fas fa-envelope"></i></div>
                    </div>
                </div>
                <div class="col-xl-3 col-sm-6">
                    <div class="stat-card c-blue animate-in">
                        <div class="s-icon"><i class="fas fa-calendar-day"></i></div>
                        <div class="s-value"><?php echo $todayCount; ?></div>
                        <div class="s-label">Today's Messages</div>
                        <div class="s-bg"><i class="fas fa-calendar-day"></i></div>
                    </div>
                </div>
                <div class="col-xl-3 col-sm-6">
                    <div class="stat-card c-green animate-in">
                        <div class="s-icon"><i class="fas fa-calendar-week"></i></div>
                        <div class="s-value"><?php echo $weekCount; ?></div>
                        <div class="s-label">This Week</div>
                        <div class="s-bg"><i class="fas fa-calendar-week"></i></div>
                    </div>
                </div>
                <div class="col-xl-3 col-sm-6">
                    <div class="stat-card c-orange animate-in">
                        <div class="s-icon"><i class="fas fa-filter"></i></div>
                        <div class="s-value"><?php echo count($contacts); ?></div>
                        <div class="s-label">Showing Results</div>
                        <div class="s-bg"><i class="fas fa-filter"></i></div>
                    </div>
                </div>
            </div>

            <!-- Contact Table Card -->
            <div class="contact-table-card animate-in">
                <div class="contact-table-header">
                    <h5><i class="fas fa-inbox"></i> All Contact Messages</h5>
                </div>

                <!-- Search & Filter -->
                <div class="search-filter-bar">
                    <div class="search-input-wrap">
                        <i class="fas fa-search"></i>
                        <form method="GET" style="display:flex;width:100%;">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($searchQuery); ?>" placeholder="Search by name, email or message...">
                        </form>
                    </div>
                    <form method="GET" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <?php if (!empty($searchQuery)): ?>
                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($searchQuery); ?>">
                        <?php endif; ?>
                        <select name="filter" class="filter-select" onchange="this.form.submit()">
                            <option value="">All Time</option>
                            <option value="today" <?php echo $filterPeriod === 'today' ? 'selected' : ''; ?>>Today</option>
                            <option value="week" <?php echo $filterPeriod === 'week' ? 'selected' : ''; ?>>This Week</option>
                            <option value="month" <?php echo $filterPeriod === 'month' ? 'selected' : ''; ?>>This Month</option>
                        </select>
                        <?php if (!empty($searchQuery) || !empty($filterPeriod)): ?>
                        <a href="admin_contacts.php" class="clear-filters-btn"><i class="fas fa-times"></i> Clear</a>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- Table -->
                <?php if (empty($contacts)): ?>
                <div class="empty-contacts">
                    <div class="empty-contacts-icon"><i class="fas fa-inbox"></i></div>
                    <h6>No Messages Found</h6>
                    <p><?php echo !empty($searchQuery) || !empty($filterPeriod) ? 'Try adjusting your search or filter criteria.' : 'No contact messages have been received yet.'; ?></p>
                </div>
                <?php else: ?>
                <div class="contacts-table-wrapper">
                    <table class="contacts-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Message</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contacts as $c):
                                $initials = strtoupper(substr($c['name'] ?? 'U', 0, 1));
                                $isNew = (time() - strtotime($c['created_at'])) < 86400;
                            ?>
                            <tr class="<?php echo $isNew ? 'is-new' : ''; ?>">
                                <td>
                                    <span class="contact-id">#<?php echo $c['contact_id']; ?></span>
                                    <?php if ($isNew): ?><br><span class="new-badge" style="margin-top:4px;"><i class="fas fa-circle" style="font-size:5px;"></i> New</span><?php endif; ?>
                                </td>
                                <td>
                                    <div class="contact-name">
                                        <div class="contact-avatar"><?php echo $initials; ?></div>
                                        <?php echo htmlspecialchars($c['name']); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="contact-email">
                                        <a href="mailto:<?php echo htmlspecialchars($c['email']); ?>"><?php echo htmlspecialchars($c['email']); ?></a>
                                    </div>
                                </td>
                                <td>
                                    <div class="contact-message-preview"><?php echo htmlspecialchars($c['message']); ?></div>
                                </td>
                                <td>
                                    <div class="contact-date">
                                        <i class="fas fa-clock"></i>
                                        <?php echo time_ago($c['created_at']); ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="action-btns">
                                        <button class="btn-view-msg" onclick="viewMessage(<?php echo $c['contact_id']; ?>)" title="View message">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn-delete-msg" onclick="openConfirm(<?php echo $c['contact_id']; ?>)" title="Delete message">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="table-footer">
                    <span>Showing <strong><?php echo count($contacts); ?></strong> of <strong><?php echo $totalContacts; ?></strong> messages</span>
                    <span>Last updated: <?php echo date('M d, Y h:i A'); ?></span>
                </div>
                <?php endif; ?>
            </div>

        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ═══ CONTACTS DATA ═══
        const contactsData = <?php echo json_encode($contacts); ?>;

        // ═══ VIEW MESSAGE MODAL ═══
        const viewOverlay = document.getElementById('viewOverlay');
        const viewCloseBtn = document.getElementById('viewCloseBtn');
        const viewCloseBtn2 = document.getElementById('viewCloseBtn2');
        const modalDeleteBtn = document.getElementById('modalDeleteBtn');

        function viewMessage(id) {
            const contact = contactsData.find(c => c.contact_id == id);
            if (!contact) return;

            document.getElementById('modalContactId').textContent = '#' + contact.contact_id;
            document.getElementById('modalDate').textContent = contact.created_at;
            document.getElementById('modalName').textContent = contact.name;
            document.getElementById('modalEmail').innerHTML = '<a href="mailto:' + contact.email + '">' + contact.email + '</a>';
            document.getElementById('modalMessage').textContent = contact.message;

            viewOverlay.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeViewModal() {
            viewOverlay.classList.remove('show');
            document.body.style.overflow = '';
        }

        viewCloseBtn.addEventListener('click', closeViewModal);
        viewCloseBtn2.addEventListener('click', closeViewModal);
        viewOverlay.addEventListener('click', function(e) {
            if (e.target === viewOverlay) closeViewModal();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && viewOverlay.classList.contains('show')) closeViewModal();
        });

        // Delete from inside view modal
        modalDeleteBtn.addEventListener('click', function() {
            const idText = document.getElementById('modalContactId').textContent;
            const id = idText.replace('#', '');
            document.getElementById('confirmDeleteId').value = id;
            document.getElementById('confirmOverlay').classList.add('show');
        });

        // ═══ DELETE CONFIRM ═══
        function openConfirm(id) {
            document.getElementById('confirmDeleteId').value = id;
            document.getElementById('confirmOverlay').classList.add('show');
        }
        function closeConfirm() {
            document.getElementById('confirmOverlay').classList.remove('show');
        }
        document.getElementById('confirmOverlay').addEventListener('click', function(e) {
            if (e.target === this) closeConfirm();
        });

        // ═══ TOAST ═══
        const toast = document.getElementById('toastAlert');
        if (toast) {
            setTimeout(() => { toast.classList.add('hide'); }, 3500);
            setTimeout(() => { toast.remove(); }, 4000);
        }

        // ═══ SIDEBAR TOGGLE ═══
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        document.getElementById('sidebarToggle').addEventListener('click', () => {
            sidebar.classList.toggle('show');
            sidebarOverlay.classList.toggle('show');
        });
        sidebarOverlay.addEventListener('click', () => {
            sidebar.classList.remove('show');
            sidebarOverlay.classList.remove('show');
        });

        // ═══ NOTIFICATIONS DROPDOWN ═══
        const notiWrapper = document.querySelector('.notification-wrapper');
        const notiDropdown = document.querySelector('.notification-dropdown');
        if (notiWrapper && notiDropdown) {
            notiWrapper.addEventListener('click', function(e) {
                e.stopPropagation();
                notiDropdown.classList.toggle('show');
            });
            document.addEventListener('click', function() {
                notiDropdown.classList.remove('show');
            });
        }

        // ═══ SETTINGS PANEL ═══
        const settingsPanel = document.getElementById('settingsPanel');
        const settingsOverlay = document.getElementById('settingsOverlay');
        document.querySelectorAll('.header-btn.settings-trigger, #settingsOverlay, #settingsCloseBtn').forEach(el => {
            el.addEventListener('click', () => {
                settingsPanel.classList.toggle('show');
                settingsOverlay.classList.toggle('show');
            });
        });

        // ═══ THEME TOGGLE ═══
        const themeToggle = document.getElementById('themeToggle');
        const html = document.documentElement;
        if (localStorage.getItem('theme') === 'dark') {
            html.setAttribute('data-theme', 'dark');
            if (themeToggle) themeToggle.checked = true;
        }
        if (themeToggle) {
            themeToggle.addEventListener('change', function() {
                const theme = this.checked ? 'dark' : 'light';
                html.setAttribute('data-theme', theme);
                localStorage.setItem('theme', theme);
            });
        }

        // ═══ HEADER TIME ═══
        function updateTime() {
            const now = new Date();
            const opts = { weekday: 'short', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' };
            const el = document.getElementById('headerTime');
            if (el) el.textContent = now.toLocaleDateString('en-US', opts);
        }
        updateTime();
        setInterval(updateTime, 30000);
    </script>
</body>
</html>