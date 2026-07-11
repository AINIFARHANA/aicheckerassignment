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

// ─── NOTIFICATIONS ───
 $unread_count  = 0; $notifications = [];
 $noti_query = mysqli_query($conn, "SELECT * FROM notifications WHERE user_id IS NULL ORDER BY created_at DESC LIMIT 30");
if (mysqli_num_rows($noti_query) > 0) {
    while ($row = mysqli_fetch_assoc($noti_query)) {
        $notifications[] = $row;
        if ($row['is_read'] == 0) $unread_count++;
    }
}

// ─── STATISTICS ───
 $total_users = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM users WHERE user_type = 'user'"));
 $total_admins = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM users WHERE user_type = 'admin'"));
 $new_this_month = mysqli_num_rows(mysqli_query($conn, "SELECT * FROM users WHERE MONTH(created_at) = MONTH(CURRENT_DATE) AND YEAR(created_at) = YEAR(CURRENT_DATE)"));

// ═══════════════════════════════════════════════════
// CRUD OPERATIONS
// ═══════════════════════════════════════════════════

// ─── ADD USER ───
if (isset($_POST['add_user'])) {
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    $user_type = $_POST['user_type'];
    $avatar_input = trim($_POST['avatar_input'] ?? '');

    if (empty($name) || empty($email) || empty($password)) {
        $_SESSION['alert_msg'] = 'Name, email, and password are required.'; $_SESSION['alert_type'] = 'danger';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['alert_msg'] = 'Please enter a valid email address.'; $_SESSION['alert_type'] = 'danger';
    } elseif (strlen($password) < 6) {
        $_SESSION['alert_msg'] = 'Password must be at least 6 characters.'; $_SESSION['alert_type'] = 'danger';
    } else {
        $check = mysqli_query($conn, "SELECT user_id FROM users WHERE email = '$email'");
        if (mysqli_num_rows($check) > 0) {
            $_SESSION['alert_msg'] = 'Email already exists.'; $_SESSION['alert_type'] = 'danger';
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $avatar_val = !empty($avatar_input) ? $avatar_input : 'Felix';
            $ins = mysqli_query($conn, "INSERT INTO users (name, email, password, user_type, avatar) VALUES ('$name', '$email', '$hashed', '$user_type', '$avatar_val')");
            if ($ins) { $_SESSION['alert_msg'] = 'User Added Successfully!'; $_SESSION['alert_type'] = 'success'; }
            else { $_SESSION['alert_msg'] = 'Failed to add user.'; $_SESSION['alert_type'] = 'danger'; }
        }
    }
    header("Location: admin_users.php");
    exit;
}

// ─── EDIT USER ───
if (isset($_POST['edit_user'])) {
    $edit_id = (int)$_POST['edit_id'];
    $name = trim($_POST['edit_name']);
    $email = trim($_POST['edit_email']);
    $user_type = $_POST['edit_user_type'];
    $avatar_input = trim($_POST['edit_avatar_input'] ?? '');
    $new_pass = trim($_POST['edit_password'] ?? '');

    if (empty($name) || empty($email)) {
        $_SESSION['alert_msg'] = 'Name and email are required.'; $_SESSION['alert_type'] = 'danger';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['alert_msg'] = 'Please enter a valid email address.'; $_SESSION['alert_type'] = 'danger';
    } else {
        $dup = mysqli_query($conn, "SELECT user_id FROM users WHERE email = '$email' AND user_id != $edit_id");
        if (mysqli_num_rows($dup) > 0) {
            $_SESSION['alert_msg'] = 'Email already used by another user.'; $_SESSION['alert_type'] = 'danger';
        } else {
            $fields = "name = '$name', email = '$email', user_type = '$user_type'";
            if (!empty($avatar_input)) { $fields .= ", avatar = '$avatar_input'"; }
            if (!empty($new_pass) && strlen($new_pass) >= 6) {
                $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
                $fields .= ", password = '$hashed'";
            } elseif (!empty($new_pass) && strlen($new_pass) < 6) {
                $_SESSION['alert_msg'] = 'New password must be at least 6 characters.'; $_SESSION['alert_type'] = 'danger';
            }
            if (empty($_SESSION['alert_msg'])) {
                $upd = mysqli_query($conn, "UPDATE users SET $fields WHERE user_id = $edit_id");
                if ($upd) { $_SESSION['alert_msg'] = 'User Updated Successfully!'; $_SESSION['alert_type'] = 'success'; }
                else { $_SESSION['alert_msg'] = 'Failed to update user.'; $_SESSION['alert_type'] = 'danger'; }
            }
        }
    }
    header("Location: admin_users.php");
    exit;
}

// ─── DELETE USER ───
if (isset($_POST['delete_id'])) {
    $del_id = (int)$_POST['delete_id'];
    if ($del_id !== $admin_id) {
        $del = mysqli_query($conn, "DELETE FROM users WHERE user_id = $del_id");
        if ($del) { $_SESSION['alert_msg'] = 'User Deleted Successfully!'; $_SESSION['alert_type'] = 'success'; }
        else { $_SESSION['alert_msg'] = 'Failed to delete user.'; $_SESSION['alert_type'] = 'danger'; }
    } else {
        $_SESSION['alert_msg'] = 'You cannot delete your own account.'; $_SESSION['alert_type'] = 'danger';
    }
    header("Location: admin_users.php");
    exit;
}

// ═══════════════════════════════════════════════════
// SEARCH + PAGINATION
// ═══════════════════════════════════════════════════
 $search = isset($_GET['search']) ? trim($_GET['search']) : '';
 $per_page = 10;
 $page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

 $where = "";
if (!empty($search)) {
    $safe_search = mysqli_real_escape_string($conn, $search);
    $where = "WHERE name LIKE '%$safe_search%' OR email LIKE '%$safe_search%'";
}

 $count_query = mysqli_query($conn, "SELECT COUNT(*) AS total FROM users $where");
 $total_records = mysqli_fetch_assoc($count_query)['total'];
 $total_pages = ceil($total_records / $per_page);
 $offset = ($page - 1) * $per_page;

 $users_query = mysqli_query($conn, "SELECT * FROM users $where ORDER BY user_id DESC LIMIT $offset, $per_page");

// ─── FETCH SINGLE USER FOR EDIT MODAL ───
 $edit_user_data = null;
 $edit_selected_avatar = '';
 $allowed_avatars = ['Felix', 'Annie', 'Bob', 'Cathy'];
if (isset($_GET['edit_id'])) {
    $eid = (int)$_GET['edit_id'];
    $edit_res = mysqli_query($conn, "SELECT * FROM users WHERE user_id = $eid");
    if (mysqli_num_rows($edit_res) > 0) {
        $edit_user_data = mysqli_fetch_assoc($edit_res);
        $current_seed = $edit_user_data['avatar'] ?? '';
        foreach ($allowed_avatars as $seed) {
            if (strtolower($current_seed) === strtolower($seed)) {
                $edit_selected_avatar = $seed;
                break;
            }
        }
    }
}

 $conn->close();

// ─── HELPER: Get avatar URL ───
function get_avatar($row) {
    if (!empty($row['avatar']) && $row['avatar'] !== 'default.png') {
        if (filter_var($row['avatar'], FILTER_VALIDATE_URL)) return $row['avatar'];
        return "https://api.dicebear.com/7.x/avataaars/svg?seed=" . urlencode($row['avatar']) . "&backgroundColor=ede9fe";
    }
    return "https://api.dicebear.com/7.x/avataaars/svg?seed=" . urlencode($row['name']) . "&backgroundColor=ede9fe";
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users — AI Assignment Checker</title>
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
        .sidebar-menu a:hover { background: rgba(255,255,255,0.1); color: #fff; transform: translateX(4px); }
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
        .c-blue::before { background: linear-gradient(90deg, #1565C0, #42A5F5); }
        .c-blue .s-icon { background: rgba(33,150,243,0.1); color: #1976D2; }
        .c-green::before { background: linear-gradient(90deg, #2E7D32, #66BB6A); }
        .c-green .s-icon { background: rgba(76,175,80,0.1); color: #388E3C; }

        /* ═══ TABLE CARD ═══ */
        .table-card { background: var(--card-bg); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); border: 1px solid var(--border-color); border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }
        .table-card .table-header { padding: 20px 24px; border-bottom: 1px solid var(--border-color); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
        .table-card .table-title { font-size: 16px; font-weight: 700; color: var(--text-dark); display: flex; align-items: center; gap: 10px; }
        .table-card .table-title i { color: var(--primary); font-size: 18px; }
        .search-box { position: relative; }
        .search-box input { border: 1px solid var(--border-color); border-radius: 10px; padding: 9px 14px 9px 38px; font-size: 13px; font-family: 'Poppins', sans-serif; background: var(--input-bg); width: 260px; transition: all 0.25s ease; color: var(--text-dark); }
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
        .table-card table tbody tr { transition: background 0.2s ease; }
        .table-card table tbody tr:hover { background: rgba(var(--primary-rgb), 0.03); }
        .table-card table tbody tr:last-child td { border-bottom: none; }
        .user-avatar { width: 38px; height: 38px; border-radius: 10px; object-fit: cover; border: 2px solid rgba(var(--primary-rgb), 0.1); }
        .badge-admin { background: rgba(var(--primary-rgb), 0.12); color: var(--primary); padding: 4px 12px; border-radius: 20px; font-size: 11.5px; font-weight: 600; }
        .badge-user { background: rgba(33,150,243,0.12); color: #1976D2; padding: 4px 12px; border-radius: 20px; font-size: 11.5px; font-weight: 600; }
        .btn-action { width: 34px; height: 34px; border-radius: 8px; border: none; display: inline-flex; align-items: center; justify-content: center; font-size: 13px; cursor: pointer; transition: all 0.2s ease; }
        .btn-edit { background: rgba(255,193,7,0.12); color: #F9A825; }
        .btn-edit:hover { background: rgba(255,193,7,0.25); transform: translateY(-2px); }
        .btn-delete { background: rgba(244,67,54,0.1); color: #E53935; }
        .btn-delete:hover { background: rgba(244,67,54,0.2); transform: translateY(-2px); }

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
        .form-label { font-size: 13px; font-weight: 600; color: var(--text-dark); margin-bottom: 6px; }
        .form-control, .form-select { border: 1.5px solid var(--border-color); border-radius: 10px; padding: 10px 14px; font-size: 13.5px; font-family: 'Poppins', sans-serif; background: var(--input-bg); color: var(--text-dark); transition: all 0.25s ease; }
        .form-control:focus, .form-select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(var(--primary-rgb), 0.1); background: var(--input-bg); color: var(--text-dark); }
        .form-control::placeholder { color: var(--text-muted); }
        [data-theme="dark"] .form-select option { background: #1F1333; color: #E8E0F0; }
        .btn-primary-custom { background: linear-gradient(135deg, var(--primary), var(--primary-light)); color: #fff; border: none; padding: 10px 24px; border-radius: 10px; font-size: 13.5px; font-weight: 600; font-family: 'Poppins', sans-serif; cursor: pointer; transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 15px rgba(var(--primary-rgb), 0.3); }
        .btn-primary-custom:hover { transform: translateY(-2px); box-shadow: 0 6px 25px rgba(var(--primary-rgb), 0.4); color: #fff; }
        .btn-outline-custom { background: transparent; color: var(--text-muted); border: 1.5px solid var(--border-color); padding: 10px 24px; border-radius: 10px; font-size: 13.5px; font-weight: 600; font-family: 'Poppins', sans-serif; cursor: pointer; transition: all 0.3s ease; }
        .btn-outline-custom:hover { background: rgba(var(--primary-rgb), 0.06); color: var(--text-dark); }
        .btn-add { background: linear-gradient(135deg, var(--primary), var(--primary-light)); color: #fff; border: none; padding: 9px 20px; border-radius: 10px; font-size: 13px; font-weight: 600; font-family: 'Poppins', sans-serif; cursor: pointer; transition: all 0.3s ease; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 12px rgba(var(--primary-rgb), 0.25); }
        .btn-add:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(var(--primary-rgb), 0.35); color: #fff; }

        /* ═══ AVATAR SELECTION ═══ */
        .avatar-selection { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 8px; }
        .avatar-option {
            width: 68px; height: 68px; border-radius: 14px; cursor: pointer;
            border: 3px solid var(--border-color); object-fit: cover;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            background: var(--input-bg); padding: 4px; position: relative;
        }
        .avatar-option:hover { border-color: var(--primary); transform: translateY(-3px); box-shadow: var(--shadow-md); }
        .avatar-option.selected { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(var(--primary-rgb), 0.25), var(--shadow-md); transform: translateY(-3px); }
        .avatar-option.selected::after { content: '\f00c'; font-family: 'Font Awesome 6 Free'; font-weight: 900; position: absolute; bottom: 2px; right: 2px; width: 20px; height: 20px; background: var(--primary); color: #fff; border-radius: 50%; font-size: 10px; display: flex; align-items: center; justify-content: center; }
        .avatar-label-hint { font-size: 11px; color: var(--text-muted); margin-top: 8px; }

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

        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .animate-in { animation: fadeInUp 0.5s ease forwards; opacity: 0; }
        .animate-in:nth-child(1) { animation-delay: 0.05s; }
        .animate-in:nth-child(2) { animation-delay: 0.1s; }
        .animate-in:nth-child(3) { animation-delay: 0.15s; }

        /* ═══ TOAST NOTIFICATION ═══ */
        .toast-container { position: fixed; top: 80px; right: 24px; z-index: 1100; display: flex; flex-direction: column; gap: 8px; }
        .custom-toast { padding: 14px 22px; border-radius: 12px; color: #fff; font-size: 13.5px; font-weight: 500; font-family: 'Poppins', sans-serif; display: flex; align-items: center; gap: 10px; box-shadow: 0 8px 30px rgba(0,0,0,0.25); animation: toastIn 0.4s cubic-bezier(0.16, 1, 0.3, 1) forwards; min-width: 280px; }
        .custom-toast.success { background: linear-gradient(135deg, #2E7D32, #43A047); }
        .custom-toast.danger { background: linear-gradient(135deg, #C62828, #E53935); }
        .custom-toast.removing { animation: toastOut 0.35s ease forwards; }
        @keyframes toastIn { from { opacity: 0; transform: translateX(60px) scale(0.95); } to { opacity: 1; transform: translateX(0) scale(1); } }
        @keyframes toastOut { from { opacity: 1; transform: translateX(0) scale(1); } to { opacity: 0; transform: translateX(60px) scale(0.95); } }

        /* ═══ DELETE CONFIRMATION MODAL ═══ */
        .delete-confirm-overlay{position:fixed;inset:0;z-index:9999;display:none;align-items:center;justify-content:center;background:rgba(42,20,70,0.55);padding:24px;opacity:0;transition:opacity .3s ease}
        .delete-confirm-overlay.show{display:flex;opacity:1}
        .delete-confirm-modal{background:#fff;border-radius:var(--radius);box-shadow:0 24px 80px rgba(0,0,0,0.35);width:100%;max-width:400px;padding:36px 32px 28px;text-align:center;animation:fadeInUp .4s cubic-bezier(.16,1,.3,1)}
        [data-theme="dark"] .delete-confirm-modal{background:#1F1333;border:1px solid rgba(156,39,176,0.15)}
        .delete-confirm-icon{width:64px;height:64px;border-radius:50%;background:rgba(244,67,54,0.1);display:flex;align-items:center;justify-content:center;margin:0 auto 18px}
        .delete-confirm-icon i{font-size:28px;color:#E53935;animation:delShake .5s ease .3s}
        @keyframes delShake{0%,100%{transform:rotate(0)}15%{transform:rotate(-12deg)}30%{transform:rotate(10deg)}45%{transform:rotate(-8deg)}60%{transform:rotate(6deg)}75%{transform:rotate(-3deg)}}
        .delete-confirm-modal h5{font-size:17px;font-weight:700;color:var(--text-dark);margin:0 0 8px}
        .delete-confirm-modal p{font-size:13.5px;color:var(--text-muted);margin:0 0 24px;line-height:1.6}
        .delete-confirm-modal p strong{color:#E53935}
        .delete-confirm-actions{display:flex;gap:10px;justify-content:center}
        .btn-cancel-delete{background:transparent;color:var(--text-muted);border:1.5px solid var(--border-color);padding:10px 28px;border-radius:10px;font-size:13.5px;font-weight:600;font-family:'Poppins',sans-serif;cursor:pointer;transition:all .25s ease}
        .btn-cancel-delete:hover{border-color:var(--primary);color:var(--text-dark)}
        .btn-confirm-delete{background:linear-gradient(135deg,#C62828,#E53935);color:#fff;border:none;padding:10px 28px;border-radius:10px;font-size:13.5px;font-weight:600;font-family:'Poppins',sans-serif;cursor:pointer;transition:all .3s ease;display:inline-flex;align-items:center;gap:8px;box-shadow:0 4px 15px rgba(244,67,54,0.3)}
        .btn-confirm-delete:hover{transform:translateY(-2px);box-shadow:0 6px 25px rgba(244,67,54,0.45);color:#fff}
        .btn-confirm-delete:disabled{opacity:.6;cursor:not-allowed;transform:none;box-shadow:none}

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
            .search-box input { width: 200px; }
        }
        @media (max-width: 575.98px) {
            .stat-card .s-value { font-size: 24px; }
            .search-box input { width: 100%; }
            .table-header { flex-direction: column; align-items: stretch; }
            .notification-dropdown { width: calc(100vw - 32px); right: -60px; max-height: 380px; }
            .settings-panel { width: 100vw; max-width: 100vw; }
            .avatar-option { width: 58px; height: 58px; }
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
            <a href="admin_users.php" class="active"><i class="fas fa-users fa-icon"></i> Users</a>
            <a href="admin_assignments.php"><i class="fas fa-file-alt fa-icon"></i> Assignments</a>
            <a href="admin_reviews.php"><i class="fas fa-star"></i> Reviews</a>
            <a href="ai_analysis.php"><i class="fas fa-magnifying-glass-chart"></i> Analysis</a>
            <div class="menu-label">Management</div>
            <a href="admin_plans.php"><i class="fas fa-tags fa-icon"></i> Plans</a>
            <a href="admin_payments.php"><i class="fas fa-credit-card fa-icon"></i> Payments</a>
            <a href="admin_vouchers.php"><i class="fas fa-ticket-alt fa-icon"></i> Vouchers</a>
            <a href="admin_testimonials.php"><i class="fas fa-quote-right fa-icon"></i> Testimonials</a>
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
        </div>
    </div>

    <!-- ═══════ DELETE CONFIRMATION MODAL ═══════ -->
    <div class="delete-confirm-overlay" id="deleteConfirmOverlay">
        <div class="delete-confirm-modal">
            <div class="delete-confirm-icon"><i class="fas fa-trash-alt"></i></div>
            <h5>Delete User</h5>
            <p>Sure u want to delete <strong id="deleteUserName">—</strong>?</p>
            <div class="delete-confirm-actions">
                <button class="btn-cancel-delete" id="cancelDeleteBtn">No</button>
                <button class="btn-confirm-delete" id="confirmDeleteBtn"><i class="fas fa-trash-alt"></i> Yes</button>
            </div>
        </div>
    </div>

    <!-- ═══════ MAIN CONTENT ═══════ -->
    <main class="main-content">
        <header class="top-header">
            <div class="left-section">
                <button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
                <h1 class="page-title"><span>Manage</span> Users</h1>
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

            <!-- ═══ ALERT (from session flash) ═══ -->
            <?php if (!empty($alert_msg)): ?>
            <div class="alert alert-<?php echo $alert_type; ?> d-flex align-items-center shadow-sm mb-4" style="border-radius:12px; padding:14px 20px;" id="alertBanner">
                <i class="fas fa-<?php echo $alert_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
                <div><?php echo $alert_msg; ?></div>
                <button type="button" class="btn-close ms-auto" style="font-size:12px;" onclick="this.closest('.alert').remove()"></button>
            </div>
            <?php endif; ?>

            <!-- ═══ STAT CARDS ═══ -->
            <div class="row g-3 mb-4">
                <div class="col-xl-4 col-md-6">
                    <div class="stat-card c-purple animate-in">
                        <div class="s-icon"><i class="fas fa-users"></i></div>
                        <div class="s-value"><?php echo number_format($total_users); ?></div>
                        <div class="s-label">Total Users</div>
                        <i class="fas fa-users s-bg"></i>
                    </div>
                </div>
                <div class="col-xl-4 col-md-6">
                    <div class="stat-card c-blue animate-in">
                        <div class="s-icon"><i class="fas fa-user-shield"></i></div>
                        <div class="s-value"><?php echo number_format($total_admins); ?></div>
                        <div class="s-label">Total Admins</div>
                        <i class="fas fa-user-shield s-bg"></i>
                    </div>
                </div>
                <div class="col-xl-4 col-md-6">
                    <div class="stat-card c-green animate-in">
                        <div class="s-icon"><i class="fas fa-user-plus"></i></div>
                        <div class="s-value"><?php echo number_format($new_this_month); ?></div>
                        <div class="s-label">New Users This Month</div>
                        <i class="fas fa-user-plus s-bg"></i>
                    </div>
                </div>
            </div>

            <!-- ═══ USERS TABLE ═══ -->
            <div class="table-card">
                <div class="table-header">
                    <div class="table-title"><i class="fas fa-users"></i> All Users</div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <form method="GET" class="search-box" style="position:relative;">
                            <i class="fas fa-search"></i>
                            <input type="text" name="search" placeholder="Search name or email..." value="<?php echo htmlspecialchars($search); ?>">
                            <?php if (!empty($search)): ?><a href="admin_users.php" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);color:var(--text-muted);cursor:pointer;font-size:13px;"><i class="fas fa-times"></i></a><?php endif; ?>
                        </form>
                        <button class="btn-add" data-bs-toggle="modal" data-bs-target="#addUserModal"><i class="fas fa-plus"></i> Add User</button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Avatar</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>User Type</th>
                                <th>Created Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (mysqli_num_rows($users_query) > 0): ?>
                                <?php while ($row = mysqli_fetch_assoc($users_query)): ?>
                                <tr>
                                    <td><strong>#<?php echo $row['user_id']; ?></strong></td>
                                    <td><img src="<?php echo get_avatar($row); ?>" class="user-avatar" alt="<?php echo htmlspecialchars($row['name']); ?>"></td>
                                    <td><?php echo htmlspecialchars($row['name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                                    <td><span class="badge-<?php echo $row['user_type']; ?>"><?php echo ucfirst($row['user_type']); ?></span></td>
                                    <td><?php echo date('d M Y, g:i A', strtotime($row['created_at'])); ?></td>
                                    <td>
                                        <a href="admin_users.php?edit_id=<?php echo $row['user_id']; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?><?php echo isset($_GET['page']) ? '&page=' . (int)$_GET['page'] : ''; ?>" class="btn-action btn-edit me-1" title="Edit"><i class="fas fa-pencil"></i></a>
                                        <button class="btn-action btn-delete" onclick="confirmDeleteUser(<?php echo $row['user_id']; ?>,'<?php echo addslashes($row['name']); ?>')" title="Delete"><i class="fas fa-trash"></i></button>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5">
                                        <i class="fas fa-users" style="font-size:40px;color:var(--text-muted);opacity:0.2;display:block;margin-bottom:12px;"></i>
                                        <p style="color:var(--text-muted);font-size:14px;margin:0;">No users found.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($total_records > 0): ?>
                <div class="pagination-wrapper">
                    <div class="pagination-info">Showing <?php echo ($offset + 1); ?>–<?php echo min($offset + $per_page, $total_records); ?> of <?php echo number_format($total_records); ?></div>
                    <nav>
                        <ul class="pagination mb-0">
                            <?php if ($page > 1): ?>
                            <li class="page-item"><a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>"><i class="fas fa-chevron-left" style="font-size:11px;"></i></a></li>
                            <?php else: ?>
                            <li class="page-item disabled"><span class="page-link"><i class="fas fa-chevron-left" style="font-size:11px;"></i></span></li>
                            <?php endif; ?>

                            <?php
                            $sp = max(1, $page - 2);
                            $ep = min($total_pages, $page + 2);
                            if ($sp > 1) { echo '<li class="page-item"><a class="page-link" href="?page=1">1</a></li>'; if ($sp > 2) echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; }
                            for ($pg = $sp; $pg <= $ep; $pg++) {
                                $active = $pg === $page ? ' active' : '';
                                echo '<li class="page-item' . $active . '"><a class="page-link" href="?page=' . $pg . (!empty($search) ? '&search=' . urlencode($search) : '') . '">' . $pg . '</a></li>';
                            }
                            if ($ep < $total_pages) { if ($ep < $total_pages - 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>'; echo '<li class="page-item"><a class="page-link" href="?page=' . $total_pages . '"> ' . $total_pages . '</a></li>'; }
                            ?>

                            <?php if ($page < $total_pages): ?>
                            <li class="page-item"><a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>"><i class="fas fa-chevron-right" style="font-size:11px;"></i></a></li>
                            <?php else: ?>
                            <li class="page-item disabled"><span class="page-link"><i class="fas fa-chevron-right" style="font-size:11px;"></i></span></li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- ═══════ ADD USER MODAL ═══════ -->
    <div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-plus"></i> Add New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Full Name *</label>
                            <input type="text" name="name" class="form-control" required placeholder="Enter full name">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email Address *</label>
                            <input type="email" name="email" class="form-control" required placeholder="Enter email address">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Password * <small style="color:var(--text-muted);font-weight:400;">(min 6 chars)</small></label>
                            <input type="password" name="password" class="form-control" required minlength="6" placeholder="Enter password">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">User Type *</label>
                            <select name="user_type" class="form-select" required>
                                <option value="user">User</option>
                                <option value="admin">Admin</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Choose Avatar</label>
                            <div class="avatar-selection">
                                <?php foreach ($allowed_avatars as $seed): ?>
                                <img src="https://api.dicebear.com/7.x/avataaars/svg?seed=<?php echo urlencode($seed); ?>&backgroundColor=ede9fe" class="avatar-option" data-seed="<?php echo $seed; ?>" onclick="selectAvatar(this,'add')" alt="<?php echo $seed; ?>">
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="avatar_input" id="addAvatarInput" value="">
                            <div class="avatar-label-hint">Click to select an avatar (default: Felix)</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-outline-custom" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_user" class="btn-primary-custom"><i class="fas fa-plus"></i> Add User</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ═══════ EDIT USER MODAL ═══════ -->
    <?php if ($edit_user_data): ?>
    <div class="modal fade show" id="editUserModal" tabindex="-1" aria-modal="true" role="dialog" style="display:block;padding-right:17px;">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-edit"></i> Edit User #<?php echo $edit_user_data['user_id']; ?></h5>
                    <a href="admin_users.php" class="btn-close" style="text-decoration:none;"></a>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="edit_id" value="<?php echo $edit_user_data['user_id']; ?>">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Full Name *</label>
                            <input type="text" name="edit_name" class="form-control" required value="<?php echo htmlspecialchars($edit_user_data['name']); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email Address *</label>
                            <input type="email" name="edit_email" class="form-control" required value="<?php echo htmlspecialchars($edit_user_data['email']); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">New Password <small style="color:var(--text-muted);font-weight:400;">(leave blank to keep current)</small></label>
                            <input type="password" name="edit_password" class="form-control" minlength="6" placeholder="Enter new password">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">User Type *</label>
                            <select name="edit_user_type" class="form-select" required>
                                <option value="user" <?php echo $edit_user_data['user_type'] === 'user' ? 'selected' : ''; ?>>User</option>
                                <option value="admin" <?php echo $edit_user_data['user_type'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Change Avatar</label>
                            <div class="avatar-selection">
                                <?php foreach ($allowed_avatars as $seed): ?>
                                <img src="https://api.dicebear.com/7.x/avataaars/svg?seed=<?php echo urlencode($seed); ?>&backgroundColor=ede9fe" class="avatar-option <?php echo $edit_selected_avatar === $seed ? 'selected' : ''; ?>" data-seed="<?php echo $seed; ?>" onclick="selectAvatar(this,'edit')" alt="<?php echo $seed; ?>">
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="edit_avatar_input" id="editAvatarInput" value="<?php echo htmlspecialchars($edit_selected_avatar); ?>">
                            <div class="avatar-label-hint">Click to change avatar</div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <a href="admin_users.php" class="btn-outline-custom" style="text-decoration:none;">Cancel</a>
                        <button type="submit" name="edit_user" class="btn-primary-custom"><i class="fas fa-save"></i> Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="modal-backdrop fade show"></div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // ═══ DELETE CONFIRMATION ═══
    var deleteOverlay = document.getElementById('deleteConfirmOverlay');
    var deleteUserNameSpan = document.getElementById('deleteUserName');
    var cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
    var confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
    var pendingDeleteId = null;

    function confirmDeleteUser(id, name) {
        pendingDeleteId = id;
        deleteUserNameSpan.textContent = name;
        deleteOverlay.classList.add('show');
    }
    function closeDeleteModal() {
        deleteOverlay.classList.remove('show');
        pendingDeleteId = null;
        confirmDeleteBtn.disabled = false;
        confirmDeleteBtn.innerHTML = '<i class="fas fa-trash-alt"></i> Yes';
    }
    cancelDeleteBtn.addEventListener('click', closeDeleteModal);
    deleteOverlay.addEventListener('click', function(e) { if (e.target === deleteOverlay) closeDeleteModal(); });
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && deleteOverlay.classList.contains('show')) closeDeleteModal(); });
    confirmDeleteBtn.addEventListener('click', function() {
        if (!pendingDeleteId) return;
        confirmDeleteBtn.disabled = true;
        confirmDeleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';
        var fd = new FormData();
        fd.append('delete_id', pendingDeleteId);
        fetch('admin_users.php', { method: 'POST', body: fd }).then(function() {
            window.location.href = 'admin_users.php';
        }).catch(function() {
            confirmDeleteBtn.disabled = false;
            confirmDeleteBtn.innerHTML = '<i class="fas fa-trash-alt"></i> Yes';
        });
    });

    // ═══ AVATAR SELECTION ═══
    function selectAvatar(el, mode) {
        var container = el.parentElement;
        container.querySelectorAll('.avatar-option').forEach(function(opt) { opt.classList.remove('selected'); });
        el.classList.add('selected');
        var inputId = mode === 'add' ? 'addAvatarInput' : 'editAvatarInput';
        document.getElementById(inputId).value = el.getAttribute('data-seed');
    }

    // ═══ SIDEBAR TOGGLE ═══
    var sidebar = document.getElementById('sidebar');
    var sidebarOverlay = document.getElementById('sidebarOverlay');
    var sidebarToggle = document.getElementById('sidebarToggle');
    sidebarToggle.addEventListener('click', function() { sidebar.classList.toggle('show'); sidebarOverlay.classList.toggle('show'); });
    sidebarOverlay.addEventListener('click', function() { sidebar.classList.remove('show'); sidebarOverlay.classList.remove('show'); });

    // ═══ NOTIFICATION DROPDOWN ═══
    var notiBtn = document.getElementById('notiBtn');
    var notiDropdown = document.getElementById('notiDropdown');
    notiBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        notiDropdown.classList.toggle('show');
    });
    document.addEventListener('click', function(e) {
        if (!notiDropdown.contains(e.target) && !notiBtn.contains(e.target)) notiDropdown.classList.remove('show');
    });

    // ═══ SETTINGS PANEL ═══
    var settingsBtn = document.getElementById('settingsBtn');
    var settingsPanel = document.getElementById('settingsPanel');
    var settingsOverlay = document.getElementById('settingsOverlay');
    var settingsCloseBtn = document.getElementById('settingsCloseBtn');
    settingsBtn.addEventListener('click', function() { settingsPanel.classList.add('show'); settingsOverlay.classList.add('show'); });
    settingsCloseBtn.addEventListener('click', function() { settingsPanel.classList.remove('show'); settingsOverlay.classList.remove('show'); });
    settingsOverlay.addEventListener('click', function() { settingsPanel.classList.remove('show'); settingsOverlay.classList.remove('show'); });

    // ═══ THEME TOGGLE ═══
    var themeToggle = document.getElementById('themeToggle');
    var savedTheme = localStorage.getItem('admin_theme') || 'dark';
    document.documentElement.setAttribute('data-theme', savedTheme);
    themeToggle.checked = savedTheme === 'dark';
    themeToggle.addEventListener('change', function() {
        var t = this.checked ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', t);
        localStorage.setItem('admin_theme', t);
    });

    // ═══ HEADER TIME ═══
    function updateTime() {
        var now = new Date();
        var opts = { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' };
        document.getElementById('headerTime').textContent = now.toLocaleDateString('en-US', opts);
    }
    updateTime();
    setInterval(updateTime, 1000);

    // ═══ ALERT AUTO-DISMISS ═══
    var alertBanner = document.getElementById('alertBanner');
    if (alertBanner) {
        setTimeout(function() { alertBanner.style.transition = 'opacity 0.4s ease'; alertBanner.style.opacity = '0'; setTimeout(function() { alertBanner.remove(); }, 400); }, 5000);
    }
    </script>
</body>
</html>