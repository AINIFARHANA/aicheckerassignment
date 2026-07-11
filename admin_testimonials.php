<?php
session_start();

include 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] != 'admin') {
    header("Location: login.php");
    exit();
}

 $admin_id = $_SESSION['user_id'];

// ─── FLASH ALERT FROM SESSION ───
 $alert_msg = $_SESSION['alert_msg'] ?? '';
 $alert_type = $_SESSION['alert_type'] ?? '';
unset($_SESSION['alert_msg'], $_SESSION['alert_type']);

// ─── MARK ALL NOTIFICATIONS AS READ ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_all_read') {
    mysqli_query($conn, "UPDATE notifications SET is_read = 1 WHERE user_id IS NULL AND is_read = 0");
    header("Location: " . basename($_SERVER['PHP_SELF']));
    exit;
}

// ─── FETCH ADMIN DATA ───
 $admin_query = mysqli_query($conn, "SELECT user_id, name, email, avatar, created_at FROM users WHERE user_id = '$admin_id' AND user_type = 'admin'") or die(mysqli_error($conn));
if (mysqli_num_rows($admin_query) === 0) { die("Admin account not found."); }
 $admin_data    = mysqli_fetch_assoc($admin_query);
 $admin_name    = $admin_data['name'] ?? 'Admin';
 $db_avatar     = $admin_data['avatar'] ?? 'default.png';

if (!empty($db_avatar) && $db_avatar !== 'default.png') {
    if (filter_var($db_avatar, FILTER_VALIDATE_URL)) { $avatar = $db_avatar; }
    else { $avatar = "https://api.dicebear.com/7.x/avataaars/svg?seed=" . urlencode($db_avatar) . "&backgroundColor=ede9fe"; }
} else { $avatar = "https://api.dicebear.com/7.x/avataaars/svg?seed=Admin&backgroundColor=ede9fe"; }

// ─── TIME AGO HELPER ───
function time_ago($datetime) {
    $now = new DateTime(); $ago = new DateTime($datetime); $diff = $now->diff($ago);
    if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    if ($diff->i > 0) return $diff->i . ' min ago';
    return 'Just now';
}

// ─── STAR RATING HELPER ───
function render_stars($rating, $size = '13px') {
    $html = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $rating) {
            $html .= '<i class="fas fa-star" style="color:#FFC107;font-size:' . $size . ';"></i>';
        } else {
            $html .= '<i class="far fa-star" style="color:#D4C5A9;font-size:' . $size . ';"></i>';
        }
    }
    return $html;
}

// ─── TESTIMONIAL AVATAR HELPER ───
function get_testimonial_avatar($row) {
    if (!empty($row['avatar']) && $row['avatar'] !== 'default.png') {
        if (filter_var($row['avatar'], FILTER_VALIDATE_URL)) return $row['avatar'];
        if (file_exists($row['avatar'])) return $row['avatar'];
        return "https://api.dicebear.com/7.x/avataaars/svg?seed=" . urlencode($row['avatar']) . "&backgroundColor=ede9fe";
    }
    return "https://api.dicebear.com/7.x/avataaars/svg?seed=" . urlencode($row['name'] ?? 'User') . "&backgroundColor=ede9fe";
}

// ─── NOTIFICATIONS ───
 $unread_count  = 0; $notifications = [];
 $noti_query = mysqli_query($conn, "SELECT * FROM notifications WHERE user_id IS NULL ORDER BY created_at DESC LIMIT 30");
if (mysqli_num_rows($noti_query) > 0) {
    while ($row = mysqli_fetch_assoc($noti_query)) {
        $notifications[] = $row;
        if ($row['is_read'] == 0) $unread_count++;
    }
}

// ═══════════════════════════════════════════════════
// CRUD OPERATIONS
// ═══════════════════════════════════════════════════

// ─── DELETE TESTIMONIAL ───
if (isset($_GET['delete_id'])) {
    $del_id = (int)$_GET['delete_id'];
    $del = mysqli_query($conn, "DELETE FROM testimonials WHERE testimonial_id = $del_id");
    if ($del) { $_SESSION['alert_msg'] = 'Testimonial Deleted Successfully!'; $_SESSION['alert_type'] = 'success'; }
    else { $_SESSION['alert_msg'] = 'Failed to delete testimonial.'; $_SESSION['alert_type'] = 'danger'; }
    header("Location: admin_testimonials.php");
    exit;
}

// ─── EXPORT CSV ───
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $export_query = mysqli_query($conn, "SELECT * FROM testimonials ORDER BY created_at DESC");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=testimonials_export_' . date('Y-m-d_His') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Name', 'Feedback', 'Rating', 'Avatar', 'Created At']);
    while ($row = mysqli_fetch_assoc($export_query)) {
        fputcsv($output, [$row['testimonial_id'], $row['name'], $row['feedback'], $row['rating'], $row['avatar'], $row['created_at']]);
    }
    fclose($output);
    exit;
}

// ═══════════════════════════════════════════════════
// SEARCH + FILTER + PAGINATION
// ═══════════════════════════════════════════════════
 $search = isset($_GET['search']) ? trim($_GET['search']) : '';
 $rating_filter = isset($_GET['rating']) ? (int)$_GET['rating'] : 0;
 $per_page = 10;
 $page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

 $where = "WHERE 1=1";
if (!empty($search)) {
    $safe_search = mysqli_real_escape_string($conn, $search);
    $where .= " AND name LIKE '%$safe_search%'";
}
if ($rating_filter > 0) {
    $where .= " AND rating = $rating_filter";
}

 $count_query = mysqli_query($conn, "SELECT COUNT(*) AS total FROM testimonials $where");
 $total_records = mysqli_fetch_assoc($count_query)['total'];
 $total_pages = ceil($total_records / $per_page);
 $offset = ($page - 1) * $per_page;

 $testimonials_query = mysqli_query($conn, "SELECT * FROM testimonials $where ORDER BY created_at DESC LIMIT $offset, $per_page");

// ─── STATISTICS ───
 $total_testimonials = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM testimonials"));
 $avg_result = mysqli_fetch_assoc(mysqli_query($conn, "SELECT AVG(rating) as avg_r FROM testimonials"));
 $avg_rating = $avg_result['avg_r'] ? round($avg_result['avg_r'], 1) : 0;
 $five_star_count = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM testimonials WHERE rating = 5"));
 $this_month_count = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM testimonials WHERE MONTH(created_at) = MONTH(CURRENT_DATE) AND YEAR(created_at) = YEAR(CURRENT_DATE)"));

// ─── BUILD PAGINATION URL HELPER ───
function build_page_url($p) {
    $params = [];
    if (!empty($GLOBALS['search'])) $params['search'] = $GLOBALS['search'];
    if (!empty($GLOBALS['rating_filter'])) $params['rating'] = $GLOBALS['rating_filter'];
    $params['page'] = $p;
    return 'admin_testimonials.php?' . http_build_query($params);
}

 $conn->close();
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Testimonials — AI Assignment Checker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap">
    <style>
        :root {
            --primary: #6A0DAD; --primary-light: #9C27B0; --primary-dark: #4A0072;
            --primary-rgb: 106, 13, 173; --secondary-rgb: 156, 39, 176;
            --bg: #F3F0F7; --card-bg: rgba(255, 255, 255, 0.78);
            --sidebar-width: 260px; --header-height: 70px;
            --text-dark: #2D1B4E; --text-muted: #7B6B8D;
            --border-color: rgba(106, 13, 173, 0.08);
            --input-bg: #FFFFFF;
            --shadow-sm: 0 2px 8px rgba(106, 13, 173, 0.06);
            --shadow-md: 0 4px 20px rgba(106, 13, 173, 0.1);
            --shadow-lg: 0 8px 40px rgba(106, 13, 173, 0.15);
            --radius: 16px; --radius-sm: 10px;
        }
        [data-theme="dark"] {
            --bg: #110B18; --card-bg: rgba(32, 18, 52, 0.82);
            --text-dark: #E8E0F0; --text-muted: #9B8DB5;
            --border-color: rgba(156, 39, 176, 0.12);
            --input-bg: rgba(45, 27, 78, 0.6);
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.25);
            --shadow-md: 0 4px 20px rgba(0,0,0,0.35);
            --shadow-lg: 0 8px 40px rgba(0,0,0,0.45);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Poppins', sans-serif; background: var(--bg); color: var(--text-dark);
            overflow-x: hidden; min-height: 100vh; transition: background 0.35s ease, color 0.35s ease;
        }

        /* ═══ SIDEBAR ═══ */
        .sidebar {
            position: fixed; top: 0; left: 0; width: var(--sidebar-width); height: 100vh;
            background: linear-gradient(180deg, var(--primary-dark) 0%, var(--primary) 50%, var(--primary-light) 100%);
            z-index: 1050; transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex; flex-direction: column; box-shadow: 4px 0 30px rgba(106, 13, 173, 0.3);
        }
        .sidebar-brand { padding: 24px 20px; border-bottom: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; gap: 12px; }
        .sidebar-brand .brand-icon { width: 52px; height: 60px; background: rgba(255,255,255,0.15); border-radius: 12px; overflow: hidden; backdrop-filter: blur(10px); flex-shrink: 0; }
        .sidebar-brand .brand-icon img { width: 100%; height: 100%; object-fit: cover; }
        .sidebar-brand h5 { color: #fff; font-weight: 700; font-size: 15px; margin: 0; line-height: 1.3; }
        .sidebar-brand small { color: rgba(255,255,255,0.6); font-size: 11px; }
        .sidebar-menu { flex: 1; padding: 16px 12px; overflow-y: auto; }
        .sidebar-menu .menu-label { color: rgba(255,255,255,0.4); font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 1.5px; padding: 12px 14px 8px; }
        .sidebar-menu a { display: flex; align-items: center; gap: 12px; padding: 11px 14px; color: rgba(255,255,255,0.7); text-decoration: none; border-radius: var(--radius-sm); font-size: 13.5px; font-weight: 500; transition: all 0.25s ease; margin-bottom: 2px; position: relative; }
        .sidebar-menu a i.fa-icon { width: 20px; text-align: center; font-size: 15px; }
        .sidebar-menu a:hover { background: rgba(255,255,255,0.1); color: #d0a5a5; transform: translateX(4px); }
        .sidebar-menu a.active { background: rgba(255,255,255,0.18); color: #fff; box-shadow: 0 4px 15px rgba(0,0,0,0.15); }
        .sidebar-menu a.active::before { content: ''; position: absolute; left: 0; top: 50%; transform: translateY(-50%); width: 4px; height: 60%; background: #fff; border-radius: 0 4px 4px 0; }
        .sidebar-menu a .sidebar-noti-badge { margin-left: auto; background: #FF4757; color: #fff; font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 10px; min-width: 20px; text-align: center; line-height: 1.4; }
        .sidebar-menu a.logout-btn { color: #FF6B8A; margin-top: 20px; border-top: 1px solid rgba(255,255,255,0.08); padding-top: 16px; }
        .sidebar-menu a.logout-btn:hover { background: rgba(255,107,138,0.12); color: #FF6B8A; transform: translateX(4px); }
        .sidebar-footer { padding: 16px 20px; border-top: 1px solid rgba(255,255,255,0.1); }
        .sidebar-footer .admin-info { display: flex; align-items: center; gap: 10px; }
        .sidebar-footer .admin-avatar-img { width: 38px; height: 38px; border-radius: 10px; border: 2px solid rgba(255,255,255,0.2); background: rgba(255,255,255,0.08); object-fit: cover; flex-shrink: 0; }
        .sidebar-footer .admin-name { color: #fff; font-size: 13px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 150px; }
        .sidebar-footer .admin-role { color: rgba(255,255,255,0.5); font-size: 11px; }

        /* ═══ MAIN ═══ */
        .main-content { margin-left: var(--sidebar-width); min-height: 100vh; transition: margin-left 0.35s cubic-bezier(0.4, 0, 0.2, 1); }
        .top-header { height: var(--header-height); background: rgba(255,255,255,0.8); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; padding: 0 30px; position: sticky; top: 0; z-index: 1000; transition: background 0.35s ease; }
        [data-theme="dark"] .top-header { background: rgba(17,11,24,0.88); }
        .top-header .left-section { display: flex; align-items: center; gap: 16px; }
        .sidebar-toggle { display: none; background: none; border: none; font-size: 20px; color: var(--primary); cursor: pointer; padding: 6px; border-radius: 8px; transition: background 0.2s; }
        .sidebar-toggle:hover { background: rgba(var(--primary-rgb), 0.08); }
        .top-header .page-title { font-size: 18px; font-weight: 700; color: var(--text-dark); transition: color 0.35s ease; }
        .top-header .page-title span { color: var(--primary); }
        .top-header .right-section { display: flex; align-items: center; gap: 10px; }
        .header-btn { width: 40px; height: 40px; border-radius: 12px; border: 1px solid var(--border-color); background: #fff; display: flex; align-items: center; justify-content: center; color: var(--text-muted); font-size: 16px; cursor: pointer; transition: all 0.25s ease; position: relative; }
        [data-theme="dark"] .header-btn { background: rgba(45,27,78,0.5); border-color: var(--border-color); color: var(--text-muted); }
        .header-btn:hover { border-color: var(--primary); color: var(--primary); box-shadow: var(--shadow-sm); }
        .header-time { font-size: 12.5px; color: var(--text-muted); font-weight: 500; background: rgba(var(--primary-rgb), 0.05); padding: 6px 14px; border-radius: 8px; }
        [data-theme="dark"] .header-time { background: rgba(156,39,176,0.08); }

        /* ═══ NOTIFICATIONS ═══ */
        .notification-wrapper { position: relative; }
        .noti-badge { position: absolute; top: 6px; right: 6px; min-width: 18px; height: 18px; background: #FF4757; color: #fff; font-size: 10px; font-weight: 700; border-radius: 50%; display: flex; align-items: center; justify-content: center; border: 2px solid #fff; padding: 0 3px; line-height: 1; animation: notiPulse 2s ease-in-out infinite; }
        [data-theme="dark"] .noti-badge { border-color: #1A1025; }
        @keyframes notiPulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.15); } }
        .notification-dropdown { position: absolute; top: calc(100% + 12px); right: -8px; width: 360px; max-height: 440px; background: #fff; border: 1px solid var(--border-color); border-radius: var(--radius); box-shadow: 0 20px 60px rgba(0,0,0,0.15); opacity: 0; visibility: hidden; transform: translateY(-8px) scale(0.97); transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); z-index: 9999; overflow: hidden; display: flex; flex-direction: column; }
        [data-theme="dark"] .notification-dropdown { background: #1F1333; border-color: rgba(156,39,176,0.15); }
        .notification-dropdown.show { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
        .notification-dropdown .noti-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-bottom: 1px solid var(--border-color); flex-shrink: 0; }
        .notification-dropdown .noti-header h6 { font-size: 14px; font-weight: 700; color: var(--text-dark); margin: 0; display: flex; align-items: center; gap: 8px; }
        .notification-dropdown .noti-header h6 .count { background: var(--primary); color: #fff; font-size: 10px; padding: 2px 7px; border-radius: 8px; }
        .mark-read-btn { background: none; border: none; color: var(--primary); font-size: 11.5px; font-weight: 600; cursor: pointer; font-family: inherit; padding: 4px 8px; border-radius: 6px; transition: background 0.2s; }
        .mark-read-btn:hover { background: rgba(var(--primary-rgb), 0.08); }
        .notification-dropdown .noti-list { overflow-y: auto; flex: 1; }
        .notification-dropdown .noti-item { display: flex; align-items: flex-start; gap: 12px; padding: 13px 18px; border-bottom: 1px solid var(--border-color); transition: background 0.2s; }
        .notification-dropdown .noti-item:last-child { border-bottom: none; }
        .notification-dropdown .noti-item:hover { background: rgba(var(--primary-rgb), 0.03); }
        .notification-dropdown .noti-item.unread { background: rgba(var(--primary-rgb), 0.04); }
        .notification-dropdown .noti-dot { width: 8px; height: 8px; border-radius: 50%; background: #E0D4ED; flex-shrink: 0; margin-top: 6px; }
        .notification-dropdown .noti-dot.active { background: var(--primary); box-shadow: 0 0 0 3px rgba(var(--primary-rgb), 0.15); }
        .notification-dropdown .noti-content { flex: 1; min-width: 0; }
        .notification-dropdown .noti-content p { font-size: 12.5px; color: var(--text-dark); margin: 0 0 3px; line-height: 1.45; }
        .notification-dropdown .noti-content span { font-size: 11px; color: var(--text-muted); }
        .notification-dropdown .noti-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 13px; flex-shrink: 0; margin-top: 1px; }
        .notification-dropdown .noti-icon.assignment { background: rgba(33,150,243,0.1); color: #2196F3; }
        .notification-dropdown .noti-icon.register { background: rgba(76,175,80,0.1); color: #4CAF50; }
        .notification-dropdown .noti-icon.default { background: rgba(var(--primary-rgb), 0.1); color: var(--primary); }
        .noti-empty { padding: 40px 20px; text-align: center; color: var(--text-muted); font-size: 13px; }
        .noti-empty i { font-size: 28px; margin-bottom: 8px; display: block; opacity: 0.3; }

        /* ═══ DASHBOARD BODY ═══ */
        .dashboard-body { padding: 28px 30px 40px; }

        /* ═══ GLASS STAT CARDS ═══ */
        .stat-card { background: var(--card-bg); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid var(--border-color); border-radius: var(--radius); padding: 24px 22px; position: relative; overflow: hidden; box-shadow: var(--shadow-sm); transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1); }
        .stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; border-radius: var(--radius) var(--radius) 0 0; opacity: 0; transition: opacity 0.3s ease; }
        .stat-card:hover { transform: translateY(-6px); box-shadow: var(--shadow-lg); border-color: rgba(var(--primary-rgb), 0.15); }
        .stat-card:hover::before { opacity: 1; }
        .stat-card .s-icon { width: 52px; height: 52px; border-radius: 15px; display: flex; align-items: center; justify-content: center; font-size: 20px; margin-bottom: 18px; transition: transform 0.3s ease; }
        .stat-card:hover .s-icon { transform: scale(1.1) rotate(-5deg); }
        .stat-card .s-value { font-size: 28px; font-weight: 800; color: var(--text-dark); line-height: 1; margin-bottom: 4px; letter-spacing: -0.5px; }
        .stat-card .s-label { font-size: 12.5px; color: var(--text-muted); font-weight: 500; }
        .stat-card .s-bg { position: absolute; right: -10px; bottom: -14px; font-size: 86px; opacity: 0.022; color: var(--primary); pointer-events: none; transition: opacity 0.3s ease; }
        .stat-card:hover .s-bg { opacity: 0.05; }
        .c-purple::before { background: linear-gradient(90deg, #6A0DAD, #9C27B0); }
        .c-purple .s-icon { background: rgba(var(--primary-rgb), 0.1); color: var(--primary); }
        .c-amber::before { background: linear-gradient(90deg, #E65100, #FF9800); }
        .c-amber .s-icon { background: rgba(255,152,0,0.1); color: #F57C00; }
        .c-green::before { background: linear-gradient(90deg, #2E7D32, #66BB6A); }
        .c-green .s-icon { background: rgba(76,175,80,0.1); color: #388E3C; }
        .c-blue::before { background: linear-gradient(90deg, #1565C0, #42A5F5); }
        .c-blue .s-icon { background: rgba(33,150,243,0.1); color: #1976D2; }

        /* ═══ TABLE CARD ═══ */
        .table-card { background: var(--card-bg); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid var(--border-color); border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }
        .table-card .table-header { padding: 20px 24px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
        .table-card .table-title { font-size: 16px; font-weight: 700; color: var(--text-dark); display: flex; align-items: center; gap: 10px; }
        .table-card .table-title i { color: var(--primary); font-size: 18px; }
        .search-box { position: relative; }
        .search-box input { border: 1px solid var(--border-color); border-radius: 10px; padding: 9px 14px 9px 38px; font-size: 13px; font-family: 'Poppins', sans-serif; background: var(--input-bg); width: 240px; transition: all 0.25s ease; color: var(--text-dark); }
        .search-box input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(var(--primary-rgb), 0.1); }
        .search-box input::placeholder { color: var(--text-muted); }
        .search-box i { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 13px; }
        /* Override Bootstrap table variables so the table follows this page's light/dark theme. */
        .table-card .table {
            margin: 0;
            font-size: 13px;
            --bs-table-bg: transparent;
            --bs-table-color: var(--text-dark);
            --bs-table-border-color: var(--border-color);
            --bs-table-hover-bg: rgba(var(--primary-rgb), 0.03);
            --bs-table-hover-color: var(--text-dark);
        }
        .table-card table thead th { background: rgba(var(--primary-rgb), 0.03); color: var(--text-muted); font-weight: 600; font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.8px; border: none; padding: 13px 20px; white-space: nowrap; }
        .table-card table tbody td { padding: 14px 20px; border: none; vertical-align: middle; color: var(--text-dark); background-color: transparent; }
        [data-theme="dark"] .table-card .table {
            --bs-table-bg: transparent;
            --bs-table-color: #E8E0F0;
            --bs-table-hover-color: #FFFFFF;
        }
        .table-card table tbody tr { transition: all 0.2s ease; }
        .table-card table tbody tr:hover { background: rgba(var(--primary-rgb), 0.03); }
        .table-card table tbody tr:last-child td { border-bottom: none; }
        .table-card table tbody tr.hidden-row { opacity: 0.45; }
        .table-card table tbody tr.featured-row { background: rgba(var(--primary-rgb), 0.04); }
        .table-card table tbody tr.featured-row:hover { background: rgba(var(--primary-rgb), 0.07); }
        .user-avatar { width: 38px; height: 38px; border-radius: 10px; object-fit: cover; border: 2px solid rgba(var(--primary-rgb), 0.1); }
        .feedback-preview { max-width: 280px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; font-size: 12.5px; line-height: 1.4; }
        .btn-action { width: 34px; height: 34px; border-radius: 8px; border: none; display: inline-flex; align-items: center; justify-content: center; font-size: 13px; cursor: pointer; transition: all 0.2s ease; }
        .btn-view { background: rgba(var(--primary-rgb), 0.1); color: var(--primary); }
        .btn-view:hover { background: rgba(var(--primary-rgb), 0.2); transform: translateY(-2px); }
        .btn-feature { background: rgba(255,152,0,0.1); color: #F57C00; }
        .btn-feature:hover { background: rgba(255,152,0,0.2); transform: translateY(-2px); }
        .btn-feature.is-featured { background: rgba(255,152,0,0.25); color: #E65100; }
        .btn-approve { background: rgba(76,175,80,0.1); color: #388E3C; }
        .btn-approve:hover { background: rgba(76,175,80,0.2); transform: translateY(-2px); }
        .btn-approve.is-hidden { background: rgba(244,67,54,0.1); color: #E53935; }
        .btn-approve.is-hidden:hover { background: rgba(244,67,54,0.2); }
        .btn-delete { background: rgba(244,67,54,0.1); color: #E53935; }
        .btn-delete:hover { background: rgba(244,67,54,0.2); transform: translateY(-2px); }

        /* ═══ STATUS BADGES ═══ */
        .badge-approved { background: rgba(76,175,80,0.12); color: #388E3C; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-hidden { background: rgba(244,67,54,0.12); color: #E53935; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-featured { background: rgba(255,152,0,0.12); color: #F57C00; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }

        /* ═══ TOOLBAR ═══ */
        .toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .filter-select { border: 1px solid var(--border-color); border-radius: 10px; padding: 9px 14px; font-size: 13px; font-family: 'Poppins', sans-serif; background: var(--input-bg); color: var(--text-dark); transition: all 0.25s ease; cursor: pointer; min-width: 140px; appearance: auto; }
        .filter-select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(var(--primary-rgb), 0.1); }
        [data-theme="dark"] .filter-select option { background: #1F1333; color: #E8E0F0; }
        .view-toggle { display: flex; border: 1px solid var(--border-color); border-radius: 10px; overflow: hidden; }
        .view-toggle-btn { width: 38px; height: 38px; border: none; background: transparent; color: var(--text-muted); font-size: 14px; cursor: pointer; transition: all 0.2s ease; display: flex; align-items: center; justify-content: center; }
        .view-toggle-btn.active { background: var(--primary); color: #fff; }
        .view-toggle-btn:not(:last-child) { border-right: 1px solid var(--border-color); }
        .btn-export { background: transparent; color: var(--text-muted); border: 1px solid var(--border-color); padding: 9px 16px; border-radius: 10px; font-size: 13px; font-weight: 500; font-family: 'Poppins', sans-serif; cursor: pointer; transition: all 0.25s ease; display: inline-flex; align-items: center; gap: 6px; }
        .btn-export:hover { border-color: var(--primary); color: var(--primary); background: rgba(var(--primary-rgb), 0.04); }

        /* ═══ CARDS VIEW ═══ */
        .cards-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 20px; padding: 24px; }
        .testimonial-card { background: var(--card-bg); backdrop-filter: blur(20px); border: 1px solid var(--border-color); border-radius: var(--radius); padding: 24px; box-shadow: var(--shadow-sm); transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1); position: relative; overflow: hidden; }
        .testimonial-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, var(--primary), var(--primary-light)); opacity: 0; transition: opacity 0.3s ease; }
        .testimonial-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); border-color: rgba(var(--primary-rgb), 0.15); }
        .testimonial-card:hover::before { opacity: 1; }
        .testimonial-card.hidden-card { opacity: 0.4; filter: grayscale(0.5); }
        .testimonial-card.featured-card { border-color: rgba(255,152,0,0.3); }
        .testimonial-card.featured-card::before { background: linear-gradient(90deg, #E65100, #FF9800); opacity: 1; }
        .card-top { display: flex; align-items: center; gap: 14px; margin-bottom: 14px; }
        .card-avatar { width: 50px; height: 50px; border-radius: 14px; object-fit: cover; border: 2px solid rgba(var(--primary-rgb), 0.12); flex-shrink: 0; }
        .card-name { font-size: 15px; font-weight: 700; color: var(--text-dark); margin-bottom: 2px; }
        .card-date { font-size: 11.5px; color: var(--text-muted); }
        .card-stars { margin-bottom: 12px; display: flex; gap: 2px; }
        .card-feedback { font-size: 13px; color: var(--text-dark); line-height: 1.65; margin-bottom: 16px; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; min-height: 64px; }
        .card-badges { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 16px; }
        .card-actions { display: flex; align-items: center; gap: 6px; padding-top: 14px; border-top: 1px solid var(--border-color); }
        .card-actions .btn-action { width: 32px; height: 32px; font-size: 12px; }
        .card-actions .card-action-label { font-size: 11px; color: var(--text-muted); margin-left: auto; }
        .featured-badge-card { position: absolute; top: 14px; right: 14px; background: linear-gradient(135deg, #E65100, #FF9800); color: #fff; font-size: 10px; font-weight: 700; padding: 3px 10px; border-radius: 20px; display: none; }
        .featured-card .featured-badge-card { display: inline-flex; align-items: center; gap: 4px; }

        /* ═══ PAGINATION ═══ */
        .pagination-wrapper { padding: 16px 24px; border-top: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
        .pagination-info { font-size: 12.5px; color: var(--text-muted); }
        .pagination .page-link { border: 1px solid var(--border-color); color: var(--text-dark); font-size: 13px; padding: 6px 12px; margin: 0 2px; border-radius: 8px; transition: all 0.2s ease; font-family: 'Poppins', sans-serif; background: var(--card-bg); }
        .pagination .page-link:hover { background: rgba(var(--primary-rgb), 0.08); border-color: var(--primary); color: var(--primary); }
        .pagination .page-item.active .page-link { background: var(--primary); border-color: var(--primary); color: #fff; }
        .pagination .page-item.disabled .page-link { background: transparent; color: var(--text-muted); }

        /* ═══ ALERT DARK MODE ═══ */
        [data-theme="dark"] .alert { border: 1px solid rgba(156,39,176,0.15); }
        [data-theme="dark"] .alert-success { background: rgba(76,175,80,0.1); color: #A5D6A7; border-color: rgba(76,175,80,0.2); }
        [data-theme="dark"] .alert-danger { background: rgba(244,67,54,0.1); color: #EF9A9A; border-color: rgba(244,67,54,0.2); }

        /* ═══ MODAL STYLING ═══ */
        .modal-content { border: 1px solid var(--border-color); border-radius: var(--radius); box-shadow: var(--shadow-lg); background: #fff; }
        [data-theme="dark"] .modal-content { background: #1F1333; border-color: rgba(156,39,176,0.15); }
        [data-theme="dark"] .modal-header { border-bottom-color: rgba(156,39,176,0.12); }
        [data-theme="dark"] .modal-footer { border-top-color: rgba(156,39,176,0.12); }
        [data-theme="dark"] .modal-backdrop { background: rgba(0,0,0,0.6); }
        .modal-header { border-bottom: 1px solid var(--border-color); padding: 20px 24px; }
        .modal-header .modal-title { font-size: 17px; font-weight: 700; color: var(--text-dark); display: flex; align-items: center; gap: 10px; }
        .modal-header .modal-title i { color: var(--primary); }
        .modal-header .btn-close { filter: invert(1) grayscale(100%) brightness(200%); }
        [data-theme="dark"] .modal-header .btn-close { filter: invert(0); }
        .modal-body { padding: 24px; }
        .modal-footer { border-top: 1px solid var(--border-color); padding: 16px 24px; }

        /* ═══ VIEW MODAL BODY ═══ */
        .view-modal-avatar { width: 80px; height: 80px; border-radius: 20px; object-fit: cover; border: 3px solid rgba(var(--primary-rgb), 0.15); margin-bottom: 16px; }
        .view-modal-name { font-size: 20px; font-weight: 700; color: var(--text-dark); margin-bottom: 4px; }
        .view-modal-date { font-size: 12.5px; color: var(--text-muted); margin-bottom: 16px; }
        .view-modal-stars { margin-bottom: 20px; display: flex; gap: 3px; }
        .view-modal-feedback { font-size: 14px; color: var(--text-dark); line-height: 1.75; padding: 20px; background: rgba(var(--primary-rgb), 0.03); border-radius: var(--radius-sm); border-left: 4px solid var(--primary); }
        .view-modal-divider { height: 1px; background: var(--border-color); margin: 20px 0; }
        .view-modal-meta { display: flex; gap: 20px; flex-wrap: wrap; }
        .view-modal-meta-item { display: flex; align-items: center; gap: 8px; font-size: 12.5px; color: var(--text-muted); }
        .view-modal-meta-item i { color: var(--primary); font-size: 14px; width: 18px; text-align: center; }

        /* ═══ SETTINGS PANEL ═══ */
        .settings-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 1060; backdrop-filter: blur(4px); opacity: 0; transition: opacity 0.3s ease; }
        .settings-overlay.show { display: block; opacity: 1; }
        .settings-panel { position: fixed; top: 0; right: 0; width: 340px; max-width: 90vw; height: 100vh; background: #fff; border-left: 1px solid var(--border-color); z-index: 1070; transform: translateX(100%); transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1); display: flex; flex-direction: column; box-shadow: -8px 0 40px rgba(0,0,0,0.1); }
        [data-theme="dark"] .settings-panel { background: #1A1025; border-left-color: rgba(156,39,176,0.15); }
        .settings-panel.show { transform: translateX(0); }
        .settings-panel-header { display: flex; align-items: center; justify-content: space-between; padding: 22px 24px; border-bottom: 1px solid var(--border-color); }
        .settings-panel-header h5 { font-size: 17px; font-weight: 700; color: var(--text-dark); margin: 0; display: flex; align-items: center; gap: 10px; }
        .settings-panel-header h5 i { color: var(--primary); }
        .settings-close-btn { width: 36px; height: 36px; border-radius: 10px; border: 1px solid var(--border-color); background: transparent; display: flex; align-items: center; justify-content: center; color: var(--text-muted); font-size: 14px; cursor: pointer; transition: all 0.2s; }
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
        .theme-switch .slider::before { content: ''; position: absolute; width: 22px; height: 22px; left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 2px 6px rgba(0,0,0,0.15); }
        .theme-switch input:checked + .slider { background: var(--primary); }
        .theme-switch input:checked + .slider::before { transform: translateX(24px); }

        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1040; backdrop-filter: blur(4px); }
        .sidebar-overlay.show { display: block; }

        /* ═══ EMPTY STATE ═══ */
        .empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
        .empty-state i { font-size: 56px; opacity: 0.15; margin-bottom: 16px; display: block; }
        .empty-state h5 { font-size: 18px; font-weight: 700; color: var(--text-dark); margin-bottom: 6px; }
        .empty-state p { font-size: 13px; max-width: 320px; margin: 0 auto; }

        /* ═══ DELETE CONFIRM MODAL ═══ */
        .delete-modal-icon { width: 64px; height: 64px; border-radius: 50%; background: rgba(244,67,54,0.1); display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; font-size: 28px; color: #E53935; }
        .delete-modal-title { font-size: 18px; font-weight: 700; color: var(--text-dark); text-align: center; margin-bottom: 8px; }
        .delete-modal-text { font-size: 13.5px; color: var(--text-muted); text-align: center; line-height: 1.6; }
        .delete-modal-name { font-weight: 600; color: var(--text-dark); }
        .btn-danger-custom { background: linear-gradient(135deg, #C62828, #E53935); color: #fff; border: none; padding: 10px 24px; border-radius: 10px; font-size: 13.5px; font-weight: 600; font-family: 'Poppins', sans-serif; cursor: pointer; transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 15px rgba(244,67,54,0.3); }
        .btn-danger-custom:hover { transform: translateY(-2px); box-shadow: 0 6px 25px rgba(244,67,54,0.4); color: #fff; }
        .btn-outline-custom { background: transparent; color: var(--text-muted); border: 1.5px solid var(--border-color); padding: 10px 24px; border-radius: 10px; font-size: 13.5px; font-weight: 600; font-family: 'Poppins', sans-serif; cursor: pointer; transition: all 0.3s ease; }
        .btn-outline-custom:hover { background: rgba(var(--primary-rgb), 0.06); color: var(--text-dark); }

        /* ═══ TOAST ═══ */
        .toast-container { position: fixed; top: 80px; right: 24px; z-index: 1100; display: flex; flex-direction: column; gap: 8px; }
        .custom-toast { padding: 14px 22px; border-radius: 12px; color: #fff; font-size: 13.5px; font-weight: 500; font-family: 'Poppins', sans-serif; display: flex; align-items: center; gap: 10px; box-shadow: 0 8px 30px rgba(0,0,0,0.25); animation: toastIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards; min-width: 280px; }
        .custom-toast.success { background: linear-gradient(135deg, #2E7D32, #43A047); }
        .custom-toast.danger { background: linear-gradient(135deg, #C62828, #E53935); }
        .custom-toast.info { background: linear-gradient(135deg, #6A0DAD, #9C27B0); }
        .custom-toast.removing { animation: toastOut 0.35s ease forwards; }
        @keyframes toastIn { from { opacity: 0; transform: translateX(60px) scale(0.95); } to { opacity: 1; transform: translateX(0) scale(1); } }
        @keyframes toastOut { from { opacity: 1; transform: translateX(0) scale(1); } to { opacity: 0; transform: translateX(60px) scale(0.95); } }

        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .animate-in { animation: fadeInUp 0.5s ease forwards; opacity: 0; }
        .animate-in:nth-child(1) { animation-delay: 0.05s; }
        .animate-in:nth-child(2) { animation-delay: 0.1s; }
        .animate-in:nth-child(3) { animation-delay: 0.15s; }
        .animate-in:nth-child(4) { animation-delay: 0.2s; }

        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(var(--primary-rgb), 0.2); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(var(--primary-rgb), 0.35); }

        @media (max-width: 991.98px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.show { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .sidebar-toggle { display: flex; }
            .dashboard-body { padding: 20px 16px 30px; }
            .top-header { padding: 0 16px; }
            .header-time { display: none; }
            .notification-dropdown { width: 320px; right: -40px; }
            .search-box input { width: 180px; }
        }
        @media (max-width: 575.98px) {
            .stat-card .s-value { font-size: 24px; }
            .search-box input { width: 100%; }
            .table-header { flex-direction: column; align-items: stretch; }
            .notification-dropdown { width: calc(100vw - 32px); right: -60px; max-height: 380px; }
            .settings-panel { width: 100vw; max-width: 100vw; }
            .cards-grid { grid-template-columns: 1fr; padding: 16px; }
            .toolbar { gap: 8px; }
            .filter-select { min-width: 120px; font-size: 12px; padding: 8px 10px; }
        }

        @media print {
            .sidebar, .top-header, .toolbar, .pagination-wrapper, .card-actions, .btn-action, .sidebar-overlay, .settings-overlay, .settings-panel, .toast-container { display: none !important; }
            .main-content { margin-left: 0 !important; }
            .dashboard-body { padding: 10px !important; }
            .stat-card { break-inside: avoid; }
            .table-card { box-shadow: none !important; border: 1px solid #ddd !important; background: #fff !important; }
            .table-card table thead th { background: #f5f5f5 !important; color: #333 !important; }
            .table-card table tbody td { color: #333 !important; }
            .testimonial-card { break-inside: avoid; box-shadow: none !important; border: 1px solid #ddd !important; background: #fff !important; }
        }
    </style>
</head>
<body>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <div class="settings-overlay" id="settingsOverlay"></div>
    <div class="toast-container" id="toastContainer"></div>

    <!-- ═══════ SIDEBAR ═══════ -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="brand-icon"><img src="image/logo.png" alt="Logo"></div>
            <div><h5>AI Checker</h5><small>Admin Panel</small></div>
        </div>
        <nav class="sidebar-menu">
            <div class="menu-label">Main</div>
            <a href="adminpage.php"><i class="fas fa-th-large fa-icon"></i> Dashboard</a>
            <a href="admin_users.php"><i class="fas fa-users fa-icon"></i> Users</a>
            <a href="admin_assignments.php"><i class="fas fa-file-alt fa-icon"></i> Assignments</a>        
            <a href="admin_reviews.php"><i class="fas fa-star"></i> Reviews</a>
            <a href="ai_analysis.php">
                <i class="fas fa-magnifying-glass-chart"></i> Analysis
            </a>
            <div class="menu-label">Management</div>
            <a href="admin_plans.php"><i class="fas fa-tags fa-icon"></i> Plans</a>
            <a href="admin_payments.php"><i class="fas fa-credit-card fa-icon"></i> Payments</a>
            <a href="admin_vouchers.php"><i class="fas fa-ticket-alt fa-icon"></i> Vouchers</a>
            <a href="admin_testimonials.php" class="active"><i class="fas fa-quote-right fa-icon"></i> Testimonials</a>          
            <a href="admin_contacts.php"><i class="fas fa-phone-alt"></i> Contacts</a>
            <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt fa-icon"></i> Logout</a>
        </nav>
        <div class="sidebar-footer">
            <div class="admin-info">
                <img src="<?php echo htmlspecialchars($avatar); ?>" class="admin-avatar-img" alt="<?php echo htmlspecialchars($admin_name); ?>" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=Admin&backgroundColor=ede9fe';">
                <div><div class="admin-name"><?php echo htmlspecialchars($admin_name); ?></div><div class="admin-role">Administrator</div></div>
            </div>
        </div>
    </aside>

    <!-- ═══════ SETTINGS PANEL ═══════ -->
    <div class="settings-panel" id="settingsPanel">
        <div class="settings-panel-header">
            <h5><i class="fas fa-cog"></i> Settings</h5>
            <button class="settings-close-btn" id="settingsCloseBtn"><i class="fas fa-times"></i></button>
        </div>
        <div class="settings-body">
            <div class="settings-section">
                <div class="settings-label">Appearance</div>
                <div class="settings-desc">Choose between light and dark mode.</div>
                <div class="theme-toggle-row">
                    <div class="theme-toggle-options">
                        <i class="fas fa-sun"></i>
                        <label class="theme-switch"><input type="checkbox" id="themeToggle" checked><span class="slider"></span></label>
                        <i class="fas fa-moon"></i>
                    </div>
                </div>
            </div>
            <div class="settings-section">
                <div class="settings-label">Data Management</div>
                <div class="settings-desc">Export all testimonials data for backup or analysis.</div>
                <a href="admin_testimonials.php?export=csv" class="btn-export w-100 justify-content-center" style="text-decoration:none;"><i class="fas fa-file-csv"></i> Export All as CSV</a>
            </div>
            <div class="settings-section">
                <div class="settings-label">Print View</div>
                <div class="settings-desc">Open print-friendly view to save as PDF.</div>
                <button class="btn-export w-100 justify-content-center" onclick="window.print()"><i class="fas fa-print"></i> Print / Save as PDF</button>
            </div>
            <div class="settings-section">
                <div class="settings-label">Reset UI States</div>
                <div class="settings-desc">Clear all approve/featured states stored in your browser.</div>
                <button class="btn-export w-100 justify-content-center" onclick="resetUIStates()" style="color:#E53935;border-color:rgba(244,67,54,0.2);"><i class="fas fa-undo"></i> Reset All States</button>
            </div>
        </div>
    </div>

    <!-- ═══════ MAIN CONTENT ═══════ -->
    <main class="main-content">
        <header class="top-header">
            <div class="left-section">
                <button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
                <h1 class="page-title"><span>Manage</span> Testimonials</h1>
            </div>
            <div class="right-section">
                <span class="header-time" id="headerTime"></span>
                <div class="notification-wrapper">
                    <button class="header-btn" id="notiBtn"><i class="fas fa-bell"></i>
                        <?php if ($unread_count > 0): ?><span class="noti-badge"><?php echo $unread_count; ?></span><?php endif; ?>
                    </button>
                    <div class="notification-dropdown" id="notiDropdown">
                        <div class="noti-header">
                            <h6>Notifications <span class="count"><?php echo $unread_count; ?></span></h6>
                            <form method="POST" style="display:inline;"><input type="hidden" name="action" value="mark_all_read"><button type="submit" class="mark-read-btn">Mark all read</button></form>
                        </div>
                        <div class="noti-list">
                            <?php if (empty($notifications)): ?>
                            <div class="noti-empty"><i class="fas fa-bell-slash"></i>No notifications yet</div>
                            <?php else: ?>
                                <?php foreach ($notifications as $n):
                                    $icon_class = 'default';
                                    if (stripos($n['message'], 'assignment') !== false) $icon_class = 'assignment';
                                    elseif (stripos($n['message'], 'registered') !== false || stripos($n['message'], 'user') !== false) $icon_class = 'register';
                                ?>
                                <div class="noti-item <?php echo $n['is_read'] == 0 ? 'unread' : ''; ?>">
                                    <div class="noti-dot <?php echo $n['is_read'] == 0 ? 'active' : ''; ?>"></div>
                                    <div class="noti-icon <?php echo $icon_class; ?>"><i class="fas fa-<?php echo $icon_class === 'assignment' ? 'file-alt' : ($icon_class === 'register' ? 'user-plus' : 'info-circle'); ?>"></i></div>
                                    <div class="noti-content"><p><?php echo htmlspecialchars($n['message']); ?></p><span><?php echo time_ago($n['created_at']); ?></span></div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <button class="header-btn" id="settingsBtn"><i class="fas fa-cog"></i></button>
            </div>
        </header>

        <div class="dashboard-body">

            <!-- ═══ ALERT ═══ -->
            <?php if (!empty($alert_msg)): ?>
            <div class="alert alert-<?php echo $alert_type; ?> d-flex align-items-center shadow-sm mb-4" style="border-radius:12px; padding:14px 20px;" id="alertBanner">
                <i class="fas fa-<?php echo $alert_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
                <div><?php echo $alert_msg; ?></div>
                <button type="button" class="btn-close ms-auto" style="font-size:12px;" onclick="this.closest('.alert').remove()"></button>
            </div>
            <?php endif; ?>

            <!-- ═══ STAT CARDS ═══ -->
            <div class="row g-3 mb-4">
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card c-purple animate-in">
                        <div class="s-icon"><i class="fas fa-quote-right"></i></div>
                        <div class="s-value"><?php echo number_format($total_testimonials); ?></div>
                        <div class="s-label">Total Testimonials</div>
                        <i class="fas fa-quote-right s-bg"></i>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card c-amber animate-in">
                        <div class="s-icon"><i class="fas fa-star"></i></div>
                        <div class="s-value"><?php echo $avg_rating; ?></div>
                        <div class="s-label">Average Rating</div>
                        <i class="fas fa-star s-bg"></i>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card c-green animate-in">
                        <div class="s-icon"><i class="fas fa-trophy"></i></div>
                        <div class="s-value"><?php echo number_format($five_star_count); ?></div>
                        <div class="s-label">5-Star Reviews</div>
                        <i class="fas fa-trophy s-bg"></i>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card c-blue animate-in">
                        <div class="s-icon"><i class="fas fa-calendar-plus"></i></div>
                        <div class="s-value"><?php echo number_format($this_month_count); ?></div>
                        <div class="s-label">This Month</div>
                        <i class="fas fa-calendar-plus s-bg"></i>
                    </div>
                </div>
            </div>

            <!-- ═══ TESTIMONIALS TABLE / CARDS ═══ -->
            <div class="table-card">
                <div class="table-header">
                    <div class="table-title"><i class="fas fa-quote-right"></i> All Testimonials</div>
                    <div class="toolbar">
                        <form method="GET" class="search-box" style="position:relative;">
                            <i class="fas fa-search"></i>
                            <input type="text" name="search" placeholder="Search by name..." value="<?php echo htmlspecialchars($search); ?>">
                            <?php if (!empty($search)): ?><a href="admin_testimonials.php" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);color:var(--text-muted);cursor:pointer;font-size:13px;"><i class="fas fa-times"></i></a><?php endif; ?>
                            <?php if ($rating_filter > 0): ?><input type="hidden" name="rating" value="<?php echo $rating_filter; ?>"><?php endif; ?>
                        </form>
                        <select class="filter-select" onchange="applyRatingFilter(this.value)">
                            <option value="0" <?php echo $rating_filter == 0 ? 'selected' : ''; ?>>All Ratings</option>
                            <option value="5" <?php echo $rating_filter == 5 ? 'selected' : ''; ?>>★★★★★ (5)</option>
                            <option value="4" <?php echo $rating_filter == 4 ? 'selected' : ''; ?>>★★★★☆ (4)</option>
                            <option value="3" <?php echo $rating_filter == 3 ? 'selected' : ''; ?>>★★★☆☆ (3)</option>
                            <option value="2" <?php echo $rating_filter == 2 ? 'selected' : ''; ?>>★★☆☆☆ (2)</option>
                            <option value="1" <?php echo $rating_filter == 1 ? 'selected' : ''; ?>>★☆☆☆☆ (1)</option>
                        </select>
                        <div class="view-toggle">
                            <button class="view-toggle-btn active" id="btnTableView" onclick="switchView('table')" title="Table View"><i class="fas fa-list"></i></button>
                            <button class="view-toggle-btn" id="btnCardView" onclick="switchView('cards')" title="Card View"><i class="fas fa-th-large"></i></button>
                        </div>
                        <a href="admin_testimonials.php?export=csv<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo $rating_filter > 0 ? '&rating=' . $rating_filter : ''; ?>" class="btn-export" title="Export CSV"><i class="fas fa-download"></i> CSV</a>
                        <button class="btn-export" onclick="window.print()" title="Print / PDF"><i class="fas fa-print"></i></button>
                    </div>
                </div>

                <!-- ═══ TABLE VIEW ═══ -->
                <div id="tableView">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Avatar</th>
                                    <th>Name</th>
                                    <th>Feedback</th>
                                    <th>Rating</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="tableBody">
                                <?php if (mysqli_num_rows($testimonials_query) > 0): ?>
                                    <?php while ($row = mysqli_fetch_assoc($testimonials_query)):
                                        $tid = $row['testimonial_id'];
                                        $preview = mb_strlen($row['feedback']) > 80 ? mb_substr($row['feedback'], 0, 80) . '...' : $row['feedback'];
                                    ?>
                                    <tr data-id="<?php echo $tid; ?>" class="testimonial-row">
                                        <td><strong>#<?php echo $tid; ?></strong></td>
                                        <td><img src="<?php echo get_testimonial_avatar($row); ?>" class="user-avatar" alt="<?php echo htmlspecialchars($row['name']); ?>" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=User&backgroundColor=ede9fe';"></td>
                                        <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                                        <td><div class="feedback-preview" title="<?php echo htmlspecialchars($row['feedback']); ?>"><?php echo htmlspecialchars($preview); ?></div></td>
                                        <td><?php echo render_stars($row['rating'], '12px'); ?> <small style="color:var(--text-muted);margin-left:4px;"><?php echo $row['rating']; ?>/5</small></td>
                                        <td>
                                            <span class="status-badge badge-approved" data-id="<?php echo $tid; ?>">Approved</span>
                                            <span class="featured-badge badge-featured" data-id="<?php echo $tid; ?>" style="display:none;margin-left:4px;"><i class="fas fa-star" style="font-size:9px;"></i> Featured</span>
                                        </td>
                                        <td style="white-space:nowrap;font-size:12px;color:var(--text-muted);"><?php echo date('d M Y', strtotime($row['created_at'])); ?></td>
                                        <td>
                                            <div class="d-flex gap-1">
                                                <button class="btn-action btn-view" onclick="viewTestimonial(<?php echo $tid; ?>, '<?php echo htmlspecialchars(addslashes($row['name'])); ?>', '<?php echo htmlspecialchars(addslashes($row['feedback'])); ?>', <?php echo $row['rating']; ?>, '<?php echo get_testimonial_avatar($row); ?>', '<?php echo $row['created_at']; ?>')" title="View"><i class="fas fa-eye"></i></button>
                                                <button class="btn-action btn-feature" data-id="<?php echo $tid; ?>" onclick="toggleFeatured(<?php echo $tid; ?>)" title="Toggle Featured"><i class="fas fa-star"></i></button>
                                                <button class="btn-action btn-approve" data-id="<?php echo $tid; ?>" onclick="toggleApprove(<?php echo $tid; ?>)" title="Toggle Approve/Hide"><i class="fas fa-check-circle"></i></button>
                                                <button class="btn-action btn-delete" onclick="confirmDelete(<?php echo $tid; ?>, '<?php echo htmlspecialchars(addslashes($row['name'])); ?>')" title="Delete"><i class="fas fa-trash"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr><td colspan="8">
                                        <div class="empty-state">
                                            <i class="fas fa-comment-slash"></i>
                                            <h5>No Testimonials Found</h5>
                                            <p><?php echo !empty($search) || $rating_filter > 0 ? 'Try adjusting your search or filter criteria.' : 'Testimonials submitted by users will appear here.'; ?></p>
                                        </div>
                                    </td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination-wrapper">
                        <div class="pagination-info">Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $per_page, $total_records); ?> of <?php echo number_format($total_records); ?> testimonials</div>
                        <nav>
                            <ul class="pagination mb-0">
                                <?php if ($page > 1): ?>
                                <li class="page-item"><a class="page-link" href="<?php echo build_page_url($page - 1); ?>"><i class="fas fa-chevron-left" style="font-size:11px;"></i></a></li>
                                <?php endif; ?>
                                <?php
                                $start = max(1, $page - 2);
                                $end = min($total_pages, $page + 2);
                                if ($start > 1) { echo '<li class="page-item"><a class="page-link" href="' . build_page_url(1) . '">1</a></li>'; if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; }
                                for ($p = $start; $p <= $end; $p++) {
                                    echo '<li class="page-item ' . ($p == $page ? 'active' : '') . '"><a class="page-link" href="' . build_page_url($p) . '">' . $p . '</a></li>';
                                }
                                if ($end < $total_pages) { if ($end < $total_pages - 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; echo '<li class="page-item"><a class="page-link" href="' . build_page_url($total_pages) . '">' . $total_pages . '</a></li>'; }
                                ?>
                                <?php if ($page < $total_pages): ?>
                                <li class="page-item"><a class="page-link" href="<?php echo build_page_url($page + 1); ?>"><i class="fas fa-chevron-right" style="font-size:11px;"></i></a></li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- ═══ CARDS VIEW ═══ -->
                <div id="cardsView" style="display:none;">
                    <?php if (mysqli_num_rows($testimonials_query) > 0):
                        mysqli_data_seek($testimonials_query, 0);
                        while ($row = mysqli_fetch_assoc($testimonials_query)):
                            $tid = $row['testimonial_id'];
                    ?>
                    <div class="cards-grid" style="padding:24px 24px 0;">
                    <?php endwhile; ?>
                    </div>
                    <?php
                        mysqli_data_seek($testimonials_query, 0);
                        echo '<div class="cards-grid" style="padding:24px;">';
                        while ($row = mysqli_fetch_assoc($testimonials_query)):
                            $tid = $row['testimonial_id'];
                            $avatarUrl = get_testimonial_avatar($row);
                    ?>
                    <div class="testimonial-card" data-id="<?php echo $tid; ?>">
                        <span class="featured-badge-card"><i class="fas fa-star" style="font-size:9px;"></i> Featured</span>
                        <div class="card-top">
                            <img src="<?php echo $avatarUrl; ?>" class="card-avatar" alt="<?php echo htmlspecialchars($row['name']); ?>" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=User&backgroundColor=ede9fe';">
                            <div>
                                <div class="card-name"><?php echo htmlspecialchars($row['name']); ?></div>
                                <div class="card-date"><i class="far fa-clock" style="margin-right:4px;"></i><?php echo time_ago($row['created_at']); ?></div>
                            </div>
                        </div>
                        <div class="card-stars"><?php echo render_stars($row['rating'], '14px'); ?></div>
                        <div class="card-feedback"><?php echo htmlspecialchars($row['feedback']); ?></div>
                        <div class="card-badges">
                            <span class="status-badge badge-approved" data-id="<?php echo $tid; ?>">Approved</span>
                            <span class="featured-badge badge-featured" data-id="<?php echo $tid; ?>" style="display:none;"><i class="fas fa-star" style="font-size:9px;"></i> Featured</span>
                        </div>
                        <div class="card-actions">
                            <button class="btn-action btn-view" onclick="viewTestimonial(<?php echo $tid; ?>, '<?php echo htmlspecialchars(addslashes($row['name'])); ?>', '<?php echo htmlspecialchars(addslashes($row['feedback'])); ?>', <?php echo $row['rating']; ?>, '<?php echo $avatarUrl; ?>', '<?php echo $row['created_at']; ?>')" title="View"><i class="fas fa-eye"></i></button>
                            <button class="btn-action btn-feature" data-id="<?php echo $tid; ?>" onclick="toggleFeatured(<?php echo $tid; ?>)" title="Toggle Featured"><i class="fas fa-star"></i></button>
                            <button class="btn-action btn-approve" data-id="<?php echo $tid; ?>" onclick="toggleApprove(<?php echo $tid; ?>)" title="Toggle Approve/Hide"><i class="fas fa-check-circle"></i></button>
                            <button class="btn-action btn-delete" onclick="confirmDelete(<?php echo $tid; ?>, '<?php echo htmlspecialchars(addslashes($row['name'])); ?>')" title="Delete"><i class="fas fa-trash"></i></button>
                            <span class="card-action-label"><?php echo date('d M Y', strtotime($row['created_at'])); ?></span>
                        </div>
                    </div>
                    <?php endwhile; echo '</div>';
                    else: ?>
                    <div class="empty-state" style="padding:60px 20px;">
                        <i class="fas fa-comment-slash"></i>
                        <h5>No Testimonials Found</h5>
                        <p><?php echo !empty($search) || $rating_filter > 0 ? 'Try adjusting your search or filter criteria.' : 'Testimonials submitted by users will appear here.'; ?></p>
                    </div>
                    <?php endif; ?>

                    <?php if ($total_pages > 1): ?>
                    <div class="pagination-wrapper">
                        <div class="pagination-info">Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $per_page, $total_records); ?> of <?php echo number_format($total_records); ?> testimonials</div>
                        <nav>
                            <ul class="pagination mb-0">
                                <?php if ($page > 1): ?>
                                <li class="page-item"><a class="page-link" href="<?php echo build_page_url($page - 1); ?>"><i class="fas fa-chevron-left" style="font-size:11px;"></i></a></li>
                                <?php endif; ?>
                                <?php
                                $start = max(1, $page - 2);
                                $end = min($total_pages, $page + 2);
                                if ($start > 1) { echo '<li class="page-item"><a class="page-link" href="' . build_page_url(1) . '">1</a></li>'; if ($start > 2) echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; }
                                for ($p = $start; $p <= $end; $p++) {
                                    echo '<li class="page-item ' . ($p == $page ? 'active' : '') . '"><a class="page-link" href="' . build_page_url($p) . '">' . $p . '</a></li>';
                                }
                                if ($end < $total_pages) { if ($end < $total_pages - 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; echo '<li class="page-item"><a class="page-link" href="' . build_page_url($total_pages) . '">' . $total_pages . '</a></li>'; }
                                ?>
                                <?php if ($page < $total_pages): ?>
                                <li class="page-item"><a class="page-link" href="<?php echo build_page_url($page + 1); ?>"><i class="fas fa-chevron-right" style="font-size:11px;"></i></a></li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- ═══════ VIEW MODAL ═══════ -->
    <div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-eye"></i> Testimonial Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="viewModalBody">
                    <!-- Filled by JS -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════ DELETE CONFIRM MODAL ═══════ -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content" style="border-radius:20px; overflow:hidden;">
                <div class="modal-body" style="padding:32px 28px; text-align:center;">
                    <div class="delete-modal-icon"><i class="fas fa-trash-alt"></i></div>
                    <div class="delete-modal-title">Delete Testimonial?</div>
                    <div class="delete-modal-text">Are you sure you want to delete the testimonial from <span class="delete-modal-name" id="deleteName"></span>? This action cannot be undone.</div>
                </div>
                <div class="modal-footer" style="justify-content:center; gap:10px; border-top:1px solid var(--border-color); padding:16px 28px 24px;">
                    <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">Cancel</button>
                    <a href="#" id="deleteConfirmBtn" class="btn-danger-custom"><i class="fas fa-trash"></i> Delete</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ═══ DARK MODE ═══
        (function() {
            const saved = localStorage.getItem('theme');
            const html = document.documentElement;
            const toggle = document.getElementById('themeToggle');
            if (saved === 'light') {
                html.setAttribute('data-theme', 'light');
                if (toggle) toggle.checked = false;
            } else {
                html.setAttribute('data-theme', 'dark');
                if (toggle) toggle.checked = true;
            }
            if (toggle) {
                toggle.addEventListener('change', function() {
                    const theme = this.checked ? 'dark' : 'light';
                    html.setAttribute('data-theme', theme);
                    localStorage.setItem('theme', theme);
                });
            }
        })();

        // ═══ SIDEBAR TOGGLE ═══
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const sidebarToggle = document.getElementById('sidebarToggle');
        sidebarToggle.addEventListener('click', () => { sidebar.classList.add('show'); sidebarOverlay.classList.add('show'); });
        sidebarOverlay.addEventListener('click', () => { sidebar.classList.remove('show'); sidebarOverlay.classList.remove('show'); });

        // ═══ SETTINGS PANEL ═══
        const settingsPanel = document.getElementById('settingsPanel');
        const settingsOverlay = document.getElementById('settingsOverlay');
        document.getElementById('settingsBtn').addEventListener('click', () => { settingsPanel.classList.add('show'); settingsOverlay.classList.add('show'); });
        function closeSettings() { settingsPanel.classList.remove('show'); settingsOverlay.classList.remove('show'); }
        document.getElementById('settingsCloseBtn').addEventListener('click', closeSettings);
        settingsOverlay.addEventListener('click', closeSettings);

        // ═══ NOTIFICATIONS DROPDOWN ═══
        const notiBtn = document.getElementById('notiBtn');
        const notiDropdown = document.getElementById('notiDropdown');
        notiBtn.addEventListener('click', (e) => { e.stopPropagation(); notiDropdown.classList.toggle('show'); });
        document.addEventListener('click', (e) => { if (!notiDropdown.contains(e.target) && !notiBtn.contains(e.target)) notiDropdown.classList.remove('show'); });

        // ═══ HEADER TIME ═══
        function updateTime() {
            const now = new Date();
            const opts = { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' };
            document.getElementById('headerTime').textContent = now.toLocaleDateString('en-US', opts);
        }
        updateTime();
        setInterval(updateTime, 30000);

        // ═══ TOAST SYSTEM ═══
        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `custom-toast ${type}`;
            const icon = type === 'success' ? 'check-circle' : type === 'danger' ? 'exclamation-circle' : 'info-circle';
            toast.innerHTML = `<i class="fas fa-${icon}"></i> ${message}`;
            container.appendChild(toast);
            setTimeout(() => { toast.classList.add('removing'); setTimeout(() => toast.remove(), 350); }, 3000);
        }

        // ═══ VIEW SWITCH (Table / Cards) ═══
        function switchView(view) {
            const tableView = document.getElementById('tableView');
            const cardsView = document.getElementById('cardsView');
            const btnTable = document.getElementById('btnTableView');
            const btnCard = document.getElementById('btnCardView');
            if (view === 'table') {
                tableView.style.display = 'block';
                cardsView.style.display = 'none';
                btnTable.classList.add('active');
                btnCard.classList.remove('active');
            } else {
                tableView.style.display = 'none';
                cardsView.style.display = 'block';
                btnTable.classList.remove('active');
                btnCard.classList.add('active');
            }
            localStorage.setItem('testimonials_view', view);
            applyUIStates();
        }
        // Restore saved view
        (function() {
            const saved = localStorage.getItem('testimonials_view');
            if (saved === 'cards') switchView('cards');
        })();

        // ═══ RATING FILTER ═══
        function applyRatingFilter(val) {
            const url = new URL(window.location.href);
            if (val && val !== '0') {
                url.searchParams.set('rating', val);
            } else {
                url.searchParams.delete('rating');
            }
            url.searchParams.delete('page');
            window.location.href = url.toString();
        }

        // ═══ VIEW MODAL ═══
        const viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
        function viewTestimonial(id, name, feedback, rating, avatar, date) {
            const dateObj = new Date(date);
            const formattedDate = dateObj.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
            const formattedTime = dateObj.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
            let starsHtml = '';
            for (let i = 1; i <= 5; i++) {
                starsHtml += i <= rating
                    ? '<i class="fas fa-star" style="color:#FFC107;font-size:18px;"></i>'
                    : '<i class="far fa-star" style="color:#D4C5A9;font-size:18px;"></i>';
            }
            const states = getTestimonialStates();
            const isFeatured = states[id]?.featured === true;
            const isApproved = states[id]?.approved !== false;

            document.getElementById('viewModalBody').innerHTML = `
                <div style="text-align:center; margin-bottom: 8px;">
                    <img src="${avatar}" class="view-modal-avatar" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=User&backgroundColor=ede9fe';" alt="${name}">
                </div>
                <div style="text-align:center;">
                    <div class="view-modal-name">${name}</div>
                    <div class="view-modal-date"><i class="far fa-calendar-alt" style="margin-right:5px;"></i>${formattedDate} at ${formattedTime}</div>
                </div>
                <div style="text-align:center;">
                    <div class="view-modal-stars">${starsHtml} <span style="font-size:14px;color:var(--text-muted);margin-left:6px;font-weight:600;">${rating}.0 / 5.0</span></div>
                </div>
                <div class="view-modal-feedback">"${feedback}"</div>
                <div class="view-modal-divider"></div>
                <div class="view-modal-meta">
                    <div class="view-modal-meta-item"><i class="fas fa-hashtag"></i> ID: #${id}</div>
                    <div class="view-modal-meta-item"><i class="fas fa-${isApproved ? 'check-circle' : 'eye-slash'}"></i> ${isApproved ? 'Approved' : 'Hidden'}</div>
                    <div class="view-modal-meta-item"><i class="fas fa-${isFeatured ? 'star' : 'star'}" style="${isFeatured ? 'color:#F57C00;' : ''}"></i> ${isFeatured ? 'Featured' : 'Not Featured'}</div>
                    <div class="view-modal-meta-item"><i class="fas fa-clock"></i> ${timeAgoJS(date)}</div>
                </div>
            `;
            viewModal.show();
        }

        // ═══ TIME AGO JS ═══
        function timeAgoJS(datetime) {
            const now = new Date();
            const ago = new Date(datetime);
            const diffMs = now - ago;
            const diffSec = Math.floor(diffMs / 1000);
            const diffMin = Math.floor(diffSec / 60);
            const diffHr = Math.floor(diffMin / 60);
            const diffDay = Math.floor(diffHr / 24);
            const diffMonth = Math.floor(diffDay / 30);
            const diffYear = Math.floor(diffDay / 365);
            if (diffYear > 0) return diffYear + ' year' + (diffYear > 1 ? 's' : '') + ' ago';
            if (diffMonth > 0) return diffMonth + ' month' + (diffMonth > 1 ? 's' : '') + ' ago';
            if (diffDay > 0) return diffDay + ' day' + (diffDay > 1 ? 's' : '') + ' ago';
            if (diffHr > 0) return diffHr + ' hour' + (diffHr > 1 ? 's' : '') + ' ago';
            if (diffMin > 0) return diffMin + ' min ago';
            return 'Just now';
        }

        // ═══ DELETE CONFIRM ═══
        const deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
        function confirmDelete(id, name) {
            document.getElementById('deleteName').textContent = name;
            document.getElementById('deleteConfirmBtn').href = `admin_testimonials.php?delete_id=${id}`;
            deleteModal.show();
        }

        // ═══ UI STATE MANAGEMENT (Approve / Featured) ═══
        function getTestimonialStates() {
            try { return JSON.parse(localStorage.getItem('testimonial_states') || '{}'); } catch(e) { return {}; }
        }
        function saveTestimonialState(id, key, value) {
            const states = getTestimonialStates();
            if (!states[id]) states[id] = {};
            states[id][key] = value;
            localStorage.setItem('testimonial_states', JSON.stringify(states));
        }

        function toggleFeatured(id) {
            const states = getTestimonialStates();
            const current = states[id]?.featured === true;
            saveTestimonialState(id, 'featured', !current);
            applyUIStates();
            showToast(!current ? 'Testimonial marked as featured' : 'Testimonial removed from featured', 'info');
        }

        function toggleApprove(id) {
            const states = getTestimonialStates();
            const current = states[id]?.approved !== false;
            saveTestimonialState(id, 'approved', !current);
            applyUIStates();
            showToast(!current ? 'Testimonial approved' : 'Testimonial hidden', !current ? 'success' : 'danger');
        }

        function applyUIStates() {
            const states = getTestimonialStates();

            // Table rows
            document.querySelectorAll('#tableBody .testimonial-row').forEach(row => {
                const id = row.dataset.id;
                const s = states[id] || {};
                const isHidden = s.approved === false;
                const isFeatured = s.featured === true;

                row.classList.toggle('hidden-row', isHidden);
                row.classList.toggle('featured-row', isFeatured && !isHidden);

                const statusBadge = row.querySelector('.status-badge');
                const featuredBadge = row.querySelector('.featured-badge');
                const featureBtn = row.querySelector('.btn-feature');
                const approveBtn = row.querySelector('.btn-approve');

                if (statusBadge) {
                    statusBadge.textContent = isHidden ? 'Hidden' : 'Approved';
                    statusBadge.className = `status-badge ${isHidden ? 'badge-hidden' : 'badge-approved'}`;
                }
                if (featuredBadge) featuredBadge.style.display = isFeatured ? 'inline' : 'none';
                if (featureBtn) featureBtn.classList.toggle('is-featured', isFeatured);
                if (approveBtn) approveBtn.classList.toggle('is-hidden', isHidden);
            });

            // Cards
            document.querySelectorAll('.testimonial-card').forEach(card => {
                const id = card.dataset.id;
                if (!id) return;
                const s = states[id] || {};
                const isHidden = s.approved === false;
                const isFeatured = s.featured === true;

                card.classList.toggle('hidden-card', isHidden);
                card.classList.toggle('featured-card', isFeatured && !isHidden);

                const statusBadge = card.querySelector('.status-badge');
                const featuredBadge = card.querySelector('.featured-badge');
                const featureBtn = card.querySelector('.btn-feature');
                const approveBtn = card.querySelector('.btn-approve');

                if (statusBadge) {
                    statusBadge.textContent = isHidden ? 'Hidden' : 'Approved';
                    statusBadge.className = `status-badge ${isHidden ? 'badge-hidden' : 'badge-approved'}`;
                }
                if (featuredBadge) featuredBadge.style.display = isFeatured ? 'inline' : 'none';
                if (featureBtn) featureBtn.classList.toggle('is-featured', isFeatured);
                if (approveBtn) approveBtn.classList.toggle('is-hidden', isHidden);
            });
        }

        function resetUIStates() {
            localStorage.removeItem('testimonial_states');
            applyUIStates();
            showToast('All UI states have been reset', 'info');
        }

        // Apply states on page load
        applyUIStates();

        // ═══ AUTO DISMISS ALERT ═══
        setTimeout(() => {
            const alert = document.getElementById('alertBanner');
            if (alert) { alert.style.transition = 'opacity 0.4s ease'; alert.style.opacity = '0'; setTimeout(() => alert.remove(), 400); }
        }, 5000);
    </script>
</body>
</html>