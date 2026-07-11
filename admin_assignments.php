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

require_once __DIR__ . '/notification_helpers.php';

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

// ─── ASSIGNMENT FILE PATH HELPER ───
// New uploads are saved in uploads/assignments/, while older rows may already
// store the full path. This helper makes the admin buttons use the correct path.
function get_assignment_file_url($assignment) {
    $path = $assignment['file_path'] ?? '';
    if (!empty($path)) {
        return str_replace('\\', '/', $path);
    }

    $file = $assignment['upload_file'] ?? '';
    if (empty($file)) {
        return '';
    }

    $file = str_replace('\\', '/', $file);
    if (strpos($file, 'uploads/') === 0) {
        return $file;
    }

    return 'uploads/assignments/' . ltrim($file, '/');
}

// ─── SAFE JSON HELPER FOR JAVASCRIPT BUTTONS ───
function safe_json_for_js($data) {
    $json = json_encode(
        $data,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    return $json !== false ? $json : '[]';
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
// ASSIGNMENT STATISTICS — PREPARED STATEMENTS
// ═══════════════════════════════════════════════════════════════
 $total_assignments = 0;
 $pending_count     = 0;
 $checking_count    = 0;
 $completed_count   = 0;

 $stmt_total = $conn->prepare("SELECT COUNT(*) AS total FROM assignments");
 $stmt_total->execute();
 $total_assignments = (int)$stmt_total->get_result()->fetch_assoc()['total'];
 $stmt_total->close();

 $stmt_pending = $conn->prepare("SELECT COUNT(*) AS total FROM assignments WHERE status = ?");
 $status_p = 'Pending';
 $stmt_pending->bind_param("s", $status_p);
 $stmt_pending->execute();
 $pending_count = (int)$stmt_pending->get_result()->fetch_assoc()['total'];
 $stmt_pending->close();

 $stmt_checking = $conn->prepare("SELECT COUNT(*) AS total FROM assignments WHERE status = ?");
 $status_c = 'Checking';
 $stmt_checking->bind_param("s", $status_c);
 $stmt_checking->execute();
 $checking_count = (int)$stmt_checking->get_result()->fetch_assoc()['total'];
 $stmt_checking->close();

 $stmt_completed = $conn->prepare("SELECT COUNT(*) AS total FROM assignments WHERE status = ?");
 $status_cm = 'Completed';
 $stmt_completed->bind_param("s", $status_cm);
 $stmt_completed->execute();
 $completed_count = (int)$stmt_completed->get_result()->fetch_assoc()['total'];
 $stmt_completed->close();

// ═══════════════════════════════════════════════════════════════
// HANDLE DELETE
// ═══════════════════════════════════════════════════════════════
 $delete_msg = '';
 $delete_type = '';
if (isset($_POST['delete_assignment']) && isset($_POST['delete_id'])) {
    $del_id = (int)$_POST['delete_id'];
    if ($del_id > 0) {
        // Fetch file to delete from server
        $stmt_file = $conn->prepare("SELECT upload_file, file_path FROM assignments WHERE assignment_id = ?");
        $stmt_file->bind_param("i", $del_id);
        $stmt_file->execute();
        $file_result = $stmt_file->get_result()->fetch_assoc();
        $stmt_file->close();

        if ($file_result && (!empty($file_result['upload_file']) || !empty($file_result['file_path']))) {
            $file_path = get_assignment_file_url($file_result);
            if (!empty($file_path) && file_exists($file_path)) {
                unlink($file_path);
            }
        }

        $stmt_del = $conn->prepare("DELETE FROM assignments WHERE assignment_id = ?");
        $stmt_del->bind_param("i", $del_id);
        if ($stmt_del->execute()) {
            $delete_msg = "Assignment deleted successfully!";
            $delete_type = "success";
            // Recount
            $stmt_total = $conn->prepare("SELECT COUNT(*) AS total FROM assignments");
            $stmt_total->execute();
            $total_assignments = (int)$stmt_total->get_result()->fetch_assoc()['total'];
            $stmt_total->close();

            $stmt_pending = $conn->prepare("SELECT COUNT(*) AS total FROM assignments WHERE status = ?");
            $stmt_pending->bind_param("s", $status_p);
            $stmt_pending->execute();
            $pending_count = (int)$stmt_pending->get_result()->fetch_assoc()['total'];
            $stmt_pending->close();

            $stmt_checking = $conn->prepare("SELECT COUNT(*) AS total FROM assignments WHERE status = ?");
            $stmt_checking->bind_param("s", $status_c);
            $stmt_checking->execute();
            $checking_count = (int)$stmt_checking->get_result()->fetch_assoc()['total'];
            $stmt_checking->close();

            $stmt_completed = $conn->prepare("SELECT COUNT(*) AS total FROM assignments WHERE status = ?");
            $stmt_completed->bind_param("s", $status_cm);
            $stmt_completed->execute();
            $completed_count = (int)$stmt_completed->get_result()->fetch_assoc()['total'];
            $stmt_completed->close();
        } else {
            $delete_msg = "Error deleting assignment.";
            $delete_type = "danger";
        }
        $stmt_del->close();
    }
}

// ═══════════════════════════════════════════════════════════════
// HANDLE EDIT
// ═══════════════════════════════════════════════════════════════
 $edit_msg = '';
 $edit_type = '';
if (isset($_POST['edit_assignment']) && isset($_POST['edit_id'])) {
    $edit_id    = (int)$_POST['edit_id'];
    $edit_title = trim($_POST['edit_title'] ?? '');
    $edit_subj  = trim($_POST['edit_subject'] ?? '');
    $edit_cont  = trim($_POST['edit_content'] ?? '');
    $edit_stat  = trim($_POST['edit_status'] ?? 'Pending');

    if ($edit_id > 0 && !empty($edit_title) && !empty($edit_subj)) {
        $allowed_statuses = ['Pending', 'Checking', 'Completed'];
        if (!in_array($edit_stat, $allowed_statuses)) {
            $edit_stat = 'Pending';
        }

        $assignment_before = null;
        $stmt_before = $conn->prepare("SELECT user_id, title, status FROM assignments WHERE assignment_id = ? LIMIT 1");
        if ($stmt_before) {
            $stmt_before->bind_param("i", $edit_id);
            $stmt_before->execute();
            $assignment_before = $stmt_before->get_result()->fetch_assoc();
            $stmt_before->close();
        }

        $stmt_edit = $conn->prepare("UPDATE assignments SET title = ?, subject = ?, assignment_content = ?, status = ? WHERE assignment_id = ?");
        $stmt_edit->bind_param("ssssi", $edit_title, $edit_subj, $edit_cont, $edit_stat, $edit_id);
        if ($stmt_edit->execute()) {
            if ($assignment_before && ($assignment_before['status'] ?? '') !== $edit_stat) {
                createAssignmentNotification($conn, $edit_id, assignmentStatusNotificationTemplate($edit_stat));
            }
            $edit_msg = "Assignment updated successfully!";
            $edit_type = "success";
        } else {
            $edit_msg = "Error updating assignment.";
            $edit_type = "danger";
        }
        $stmt_edit->close();
    } else {
        $edit_msg = "Title and Subject are required.";
        $edit_type = "warning";
    }
}

// ═══════════════════════════════════════════════════════════════
// SEARCH, FILTER & PAGINATION
// ═══════════════════════════════════════════════════════════════
 $per_page     = 10;
 $current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
 $search_term  = isset($_GET['search']) ? trim($_GET['search']) : '';
 $filter_status = isset($_GET['filter']) ? trim($_GET['filter']) : '';

// Build WHERE clause
 $where_parts = [];
 $params      = [];
 $types       = '';

if (!empty($search_term)) {
    $where_parts[] = "(title LIKE ? OR subject LIKE ? OR status LIKE ?)";
    $search_wild = "%" . $search_term . "%";
    $params[] = $search_wild;
    $params[] = $search_wild;
    $params[] = $search_wild;
    $types .= "sss";
}

if (!empty($filter_status) && in_array($filter_status, ['Pending', 'Checking', 'Completed'])) {
    $where_parts[] = "status = ?";
    $params[] = $filter_status;
    $types .= "s";
}

 $where_sql = "";
if (!empty($where_parts)) {
    $where_sql = "WHERE " . implode(" AND ", $where_parts);
}

// Count total filtered records
 $count_sql = "SELECT COUNT(*) AS total FROM assignments $where_sql";
 $stmt_count = $conn->prepare($count_sql);
if (!empty($params)) {
    $stmt_count->bind_param($types, ...$params);
}
 $stmt_count->execute();
 $total_filtered = (int)$stmt_count->get_result()->fetch_assoc()['total'];
 $stmt_count->close();

 $total_pages = max(1, ceil($total_filtered / $per_page));
if ($current_page > $total_pages) $current_page = $total_pages;
 $offset = ($current_page - 1) * $per_page;

// Fetch records
 $fetch_sql = "SELECT * FROM assignments $where_sql ORDER BY assignment_id DESC LIMIT ? OFFSET ?";
 $types .= "ii";
 $params[] = $per_page;
 $params[] = $offset;

 $stmt_fetch = $conn->prepare($fetch_sql);
if (!empty($params)) {
    $stmt_fetch->bind_param($types, ...$params);
}
 $stmt_fetch->execute();
 $result = $stmt_fetch->get_result();
 $assignments = [];
while ($row = $result->fetch_assoc()) {
    $row['_file_url'] = get_assignment_file_url($row);
    $row['_download_url'] = 'download_assignment.php?id=' . (int)$row['assignment_id'];
    $assignments[] = $row;
}
 $stmt_fetch->close();

// Build pagination URL helper
function build_pagination_url($page, $search, $filter) {
    $params = [];
    if ($page > 1) $params[] = "page=" . $page;
    if (!empty($search)) $params[] = "search=" . urlencode($search);
    if (!empty($filter)) $params[] = "filter=" . urlencode($filter);
    $base = basename($_SERVER['PHP_SELF']);
    return $params ? $base . "?" . implode("&", $params) : $base;
}

 $conn->close();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Assignments — AI Assignment Checker</title>
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

        .c-purple::before { background: linear-gradient(90deg, #6A0DAD, #9C27B0); }
        .c-purple .s-icon { background: rgba(var(--primary-rgb), 0.1); color: var(--primary); }
        .c-orange::before { background: linear-gradient(90deg, #E65100, #FFA726); }
        .c-orange .s-icon { background: rgba(255,152,0,0.1); color: #F57C00; }
        .c-blue::before { background: linear-gradient(90deg, #1565C0, #42A5F5); }
        .c-blue .s-icon { background: rgba(33,150,243,0.1); color: #1976D2; }
        .c-green::before { background: linear-gradient(90deg, #2E7D32, #66BB6A); }
        .c-green .s-icon { background: rgba(76,175,80,0.1); color: #388E3C; }

        /* ═══ TABLE CARD ═══ */
        .table-card {
            background: var(--card-bg); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color); border-radius: var(--radius);
            box-shadow: var(--shadow-sm); overflow: hidden;
            transition: box-shadow 0.35s ease;
        }
        .table-card:hover { box-shadow: var(--shadow-md); }
        .table-card-header {
            padding: 20px 24px; border-bottom: 1px solid var(--border-color);
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 14px;
        }
        .table-card-header .tch-left { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .table-card-header .tch-title { font-size: 17px; font-weight: 700; color: var(--text-dark); white-space: nowrap; }
        .table-card-header .tch-title i { color: var(--primary); margin-right: 8px; }
        .table-card-header .tch-count {
            background: rgba(var(--primary-rgb), 0.08); color: var(--primary);
            font-size: 11px; font-weight: 700; padding: 4px 12px;
            border-radius: 20px; white-space: nowrap;
        }
        .table-card-header .tch-right { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

        /* ═══ SEARCH & FILTER ═══ */
        .search-box {
            position: relative; width: 260px;
        }
        .search-box input {
            width: 100%; height: 40px; border: 1px solid var(--border-color);
            border-radius: var(--radius-sm); padding: 0 14px 0 38px;
            background: var(--input-bg); color: var(--text-dark);
            font-family: inherit; font-size: 13px; font-weight: 400;
            transition: all 0.25s ease; outline: none;
        }
        .search-box input::placeholder { color: var(--text-muted); }
        .search-box input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(var(--primary-rgb), 0.1); }
        .search-box i {
            position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
            color: var(--text-muted); font-size: 13px; pointer-events: none;
        }
        .filter-select {
            height: 40px; border: 1px solid var(--border-color);
            border-radius: var(--radius-sm); padding: 0 32px 0 14px;
            background: var(--input-bg); color: var(--text-dark);
            font-family: inherit; font-size: 13px; font-weight: 500;
            transition: all 0.25s ease; outline: none;
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%237B6B8D' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 12px center;
            cursor: pointer;
        }
        .filter-select:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(var(--primary-rgb), 0.1); }

        /* ═══ ACTION BUTTONS ═══ */
        .action-btn {
            height: 40px; padding: 0 14px; border: 1px solid var(--border-color);
            border-radius: var(--radius-sm); background: var(--input-bg);
            color: var(--text-muted); font-family: inherit; font-size: 12.5px;
            font-weight: 500; cursor: pointer; display: inline-flex;
            align-items: center; gap: 6px; transition: all 0.25s ease; white-space: nowrap;
        }
        .action-btn:hover { border-color: var(--primary); color: var(--primary); background: rgba(var(--primary-rgb), 0.04); }
        .action-btn.btn-refresh:hover { border-color: #1976D2; color: #1976D2; background: rgba(33,150,243,0.04); }
        .action-btn.btn-pdf:hover { border-color: #D32F2F; color: #D32F2F; background: rgba(211,47,47,0.04); }
        .action-btn.btn-excel:hover { border-color: #2E7D32; color: #2E7D32; background: rgba(46,125,50,0.04); }
        .action-btn.btn-print:hover { border-color: #F57C00; color: #F57C00; background: rgba(245,124,0,0.04); }

        /* ═══ TABLE ═══ */
        .table-responsive { overflow-x: auto; }
        .table-responsive table {
            width: 100%; border-collapse: collapse;
            font-size: 13px;
        }
        .table-responsive thead th {
            background: rgba(var(--primary-rgb), 0.04);
            color: var(--text-muted); font-weight: 600;
            font-size: 11.5px; text-transform: uppercase;
            letter-spacing: 0.8px; padding: 14px 16px;
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap; position: sticky; top: 0;
        }
        .table-responsive tbody tr {
            transition: background 0.2s ease;
            border-bottom: 1px solid var(--border-color);
        }
        .table-responsive tbody tr:last-child { border-bottom: none; }
        .table-responsive tbody tr:hover { background: rgba(var(--primary-rgb), 0.03); }
        .table-responsive tbody td {
            padding: 14px 16px; vertical-align: middle;
            color: var(--text-dark); font-weight: 400;
        }
        .table-responsive tbody td.text-muted-cell { color: var(--text-muted); font-size: 12px; }

        /* ═══ STATUS BADGES ═══ */
        .status-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 12px; border-radius: 20px;
            font-size: 11.5px; font-weight: 600; white-space: nowrap;
        }
        .status-badge.pending { background: rgba(255,152,0,0.1); color: #E65100; }
        .status-badge.checking { background: rgba(33,150,243,0.1); color: #1565C0; }
        .status-badge.completed { background: rgba(76,175,80,0.1); color: #2E7D32; }
        .status-badge i { font-size: 7px; }

        /* ═══ FILE & ACTION BUTTONS ═══ */
        .download-btn {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 5px 12px; border-radius: 8px; border: 1px solid var(--border-color);
            background: rgba(var(--primary-rgb), 0.04); color: var(--primary);
            font-size: 11.5px; font-weight: 600; text-decoration: none;
            transition: all 0.2s ease; font-family: inherit;
        }
        .download-btn:hover { background: var(--primary); color: #fff; border-color: var(--primary); }
        .no-file { color: var(--text-muted); font-size: 12px; font-style: italic; }
        .tbl-action-btn {
            width: 32px; height: 32px; border-radius: 8px; border: 1px solid var(--border-color);
            background: transparent; display: inline-flex; align-items: center; justify-content: center;
            font-size: 13px; cursor: pointer; transition: all 0.2s ease; color: var(--text-muted);
        }
        .tbl-action-btn.view-btn:hover { background: rgba(33,150,243,0.08); border-color: rgba(33,150,243,0.3); color: #1976D2; }
        .tbl-action-btn.edit-btn:hover { background: rgba(var(--primary-rgb), 0.08); border-color: rgba(var(--primary-rgb), 0.3); color: var(--primary); }
        .tbl-action-btn.delete-btn:hover { background: rgba(244,67,54,0.08); border-color: rgba(244,67,54,0.3); color: #F44336; }

        /* ═══ PAGINATION ═══ */
        .table-card-footer {
            padding: 16px 24px; border-top: 1px solid var(--border-color);
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 12px;
        }
        .table-card-footer .tcf-info { font-size: 12.5px; color: var(--text-muted); font-weight: 500; }
        .pagination-wrapper { display: flex; align-items: center; gap: 4px; }
        .pagination-wrapper .pg-btn {
            min-width: 36px; height: 36px; border: 1px solid var(--border-color);
            border-radius: var(--radius-sm); background: transparent;
            color: var(--text-muted); font-family: inherit; font-size: 13px;
            font-weight: 500; cursor: pointer; display: inline-flex;
            align-items: center; justify-content: center;
            transition: all 0.2s ease; padding: 0 4px;
        }
        .pagination-wrapper .pg-btn:hover:not(:disabled) { border-color: var(--primary); color: var(--primary); background: rgba(var(--primary-rgb), 0.04); }
        .pagination-wrapper .pg-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); }
        .pagination-wrapper .pg-btn:disabled { opacity: 0.35; cursor: not-allowed; }
        .pagination-wrapper .pg-ellipsis { color: var(--text-muted); font-size: 14px; padding: 0 4px; }

        /* ═══ MODALS ═══ */
        .modal-content {
            background: var(--card-bg); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color); border-radius: var(--radius);
            box-shadow: var(--shadow-lg); color: var(--text-dark);
        }
        .modal-header {
            border-bottom: 1px solid var(--border-color); padding: 20px 24px;
        }
        .modal-header .modal-title { font-size: 17px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
        .modal-header .modal-title i { color: var(--primary); }
        .modal-header .btn-close { filter: invert(0.3); }
        [data-theme="dark"] .modal-header .btn-close { filter: invert(0.8); }
        .modal-body { padding: 24px; }
        .modal-footer { border-top: 1px solid var(--border-color); padding: 16px 24px; }

        .view-detail-row {
            display: flex; padding: 10px 0; border-bottom: 1px solid var(--border-color);
        }
        .view-detail-row:last-child { border-bottom: none; }
        .view-detail-label {
            width: 140px; flex-shrink: 0; font-size: 12.5px;
            font-weight: 600; color: var(--text-muted); text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .view-detail-value { font-size: 13.5px; font-weight: 500; color: var(--text-dark); line-height: 1.6; word-break: break-word; flex: 1; }
        .view-detail-value.content-box {
            background: rgba(var(--primary-rgb), 0.03); padding: 14px 16px;
            border-radius: var(--radius-sm); border: 1px solid var(--border-color);
            max-height: 250px; overflow-y: auto; white-space: pre-wrap;
        }

        .form-label-custom {
            font-size: 12.5px; font-weight: 600; color: var(--text-muted);
            margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.3px;
        }
        .form-control-custom, .form-select-custom {
            width: 100%; border: 1px solid var(--border-color);
            border-radius: var(--radius-sm); padding: 10px 14px;
            background: var(--input-bg); color: var(--text-dark);
            font-family: inherit; font-size: 13.5px; font-weight: 400;
            transition: all 0.25s ease; outline: none;
        }
        .form-control-custom:focus, .form-select-custom:focus {
            border-color: var(--primary); box-shadow: 0 0 0 3px rgba(var(--primary-rgb), 0.1);
        }
        textarea.form-control-custom { resize: vertical; min-height: 120px; }

        .btn-primary-custom {
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            border: none; color: #fff; padding: 10px 24px; border-radius: var(--radius-sm);
            font-family: inherit; font-size: 13.5px; font-weight: 600;
            cursor: pointer; transition: all 0.25s ease;
            display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-primary-custom:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(var(--primary-rgb), 0.35); color: #fff; }
        .btn-secondary-custom {
            background: transparent; border: 1px solid var(--border-color);
            color: var(--text-muted); padding: 10px 24px; border-radius: var(--radius-sm);
            font-family: inherit; font-size: 13.5px; font-weight: 500;
            cursor: pointer; transition: all 0.2s ease;
        }
        .btn-secondary-custom:hover { border-color: var(--text-muted); color: var(--text-dark); }
        .btn-danger-custom {
            background: linear-gradient(135deg, #D32F2F, #F44336);
            border: none; color: #fff; padding: 10px 24px; border-radius: var(--radius-sm);
            font-family: inherit; font-size: 13.5px; font-weight: 600;
            cursor: pointer; transition: all 0.25s ease;
            display: inline-flex; align-items: center; gap: 8px;
        }
        .btn-danger-custom:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(244,67,54,0.35); color: #fff; }

        .delete-icon-wrapper {
            width: 64px; height: 64px; border-radius: 50%;
            background: rgba(244,67,54,0.08); display: flex;
            align-items: center; justify-content: center;
            margin: 0 auto 16px;
        }
        .delete-icon-wrapper i { font-size: 28px; color: #F44336; }
        .delete-modal-text { text-align: center; }
        .delete-modal-text h6 { font-size: 17px; font-weight: 700; margin-bottom: 6px; }
        .delete-modal-text p { color: var(--text-muted); font-size: 13.5px; margin: 0; }

        /* ═══ ALERT TOAST ═══ */
        .page-alert {
            position: fixed; top: 84px; right: 30px; z-index: 9999;
            padding: 14px 22px; border-radius: var(--radius-sm);
            font-size: 13.5px; font-weight: 500; color: #fff;
            box-shadow: 0 8px 30px rgba(0,0,0,0.2);
            transform: translateX(120%); transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            display: flex; align-items: center; gap: 10px;
        }
        .page-alert.show { transform: translateX(0); }
        .page-alert.alert-success { background: linear-gradient(135deg, #2E7D32, #43A047); }
        .page-alert.alert-danger { background: linear-gradient(135deg, #C62828, #E53935); }
        .page-alert.alert-warning { background: linear-gradient(135deg, #E65100, #FB8C00); }

        /* ═══ EMPTY STATE ═══ */
        .empty-state {
            padding: 60px 20px; text-align: center;
        }
        .empty-state i { font-size: 48px; color: var(--text-muted); opacity: 0.2; margin-bottom: 16px; }
        .empty-state h6 { font-size: 16px; font-weight: 600; color: var(--text-muted); margin-bottom: 4px; }
        .empty-state p { font-size: 13px; color: var(--text-muted); opacity: 0.7; margin: 0; }

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
        .table-animate { animation: fadeInUp 0.6s ease 0.3s forwards; opacity: 0; }

        /* ═══ SCROLLBAR ═══ */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(var(--primary-rgb), 0.2); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(var(--primary-rgb), 0.35); }

        /* ═══ PRINT STYLES ═══ */
        @media print {
            .sidebar, .top-header, .table-card-header, .table-card-footer,
            .tbl-action-btn, .page-alert, .settings-panel, .settings-overlay,
            .sidebar-overlay { display: none !important; }
            .main-content { margin-left: 0 !important; }
            .dashboard-body { padding: 10px !important; }
            .table-card { border: 1px solid #ccc !important; box-shadow: none !important; }
            .stat-card { border: 1px solid #ccc !important; box-shadow: none !important; }
            body { background: #fff !important; color: #000 !important; }
        }

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
            .search-box { width: 200px; }
        }
        @media (max-width: 767.98px) {
            .table-card-header { flex-direction: column; align-items: stretch; }
            .table-card-header .tch-left, .table-card-header .tch-right { width: 100%; }
            .search-box { width: 100%; }
            .table-card-footer { flex-direction: column; align-items: center; text-align: center; }
            .notification-dropdown { width: calc(100vw - 32px); right: -60px; max-height: 380px; }
            .settings-panel { width: 100vw; max-width: 100vw; }
        }
        @media (max-width: 575.98px) {
            .stat-card .s-value { font-size: 24px; }
            .stat-card .s-icon { width: 46px; height: 46px; font-size: 18px; border-radius: 13px; }
            .view-detail-row { flex-direction: column; gap: 4px; }
            .view-detail-label { width: auto; }
        }
    </style>
</head>
<body>

    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Settings Overlay -->
    <div class="settings-overlay" id="settingsOverlay"></div>

    <!-- Alert Toast -->
    <?php if (!empty($delete_msg)): ?>
    <div class="page-alert alert-<?php echo $delete_type; ?>" id="pageAlert">
        <i class="fas fa-<?php echo $delete_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <?php echo htmlspecialchars($delete_msg); ?>
    </div>
    <?php endif; ?>
    <?php if (!empty($edit_msg)): ?>
    <div class="page-alert alert-<?php echo $edit_type; ?>" id="pageAlertEdit">
        <i class="fas fa-<?php echo $edit_type === 'success' ? 'check-circle' : 'exclamation-triangle'; ?>"></i>
        <?php echo htmlspecialchars($edit_msg); ?>
    </div>
    <?php endif; ?>

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
            <a href="admin_assignments.php" class="active">
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
            <a href="admin_contacts.php">
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

    <!-- ═══════ MAIN CONTENT ═══════ -->
    <main class="main-content">

        <!-- Top Header -->
        <header class="top-header">
            <div class="left-section">
                <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
                <h1 class="page-title"><span>Admin</span> Assignments</h1>
            </div>
            <div class="right-section">
                <span class="header-time" id="headerTime"></span>
                <div class="notification-wrapper">
                    <button class="header-btn" id="notiBtn" aria-label="Notifications">
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
                                <?php foreach ($notifications as $n):
                                    $icon_class = 'default';
                                    if (stripos($n['message'], 'assignment') !== false) $icon_class = 'assignment';
                                    elseif (stripos($n['message'], 'registered') !== false || stripos($n['message'], 'user') !== false) $icon_class = 'register';
                                ?>
                                <div class="noti-item <?php echo $n['is_read'] == 0 ? 'unread' : ''; ?>">
                                    <div class="noti-dot <?php echo $n['is_read'] == 0 ? 'active' : ''; ?>"></div>
                                    <div class="noti-icon <?php echo $icon_class; ?>">
                                        <i class="fas fa-<?php echo $icon_class === 'assignment' ? 'file-alt' : ($icon_class === 'register' ? 'user-plus' : 'info-circle'); ?>"></i>
                                    </div>
                                    <div class="noti-content">
                                        <p><?php echo htmlspecialchars($n['message']); ?></p>
                                        <span><?php echo time_ago($n['created_at']); ?></span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <button class="header-btn" id="settingsBtn" aria-label="Settings"><i class="fas fa-cog"></i></button>
            </div>
        </header>

        <!-- Dashboard Body -->
        <div class="dashboard-body">

            <!-- ═══ STATISTICS CARDS ═══ -->
            <div class="row g-3 mb-4">
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card c-purple animate-in">
                        <div class="s-icon"><i class="fas fa-file-alt"></i></div>
                        <div class="s-value"><?php echo number_format($total_assignments); ?></div>
                        <div class="s-label">Total Assignments</div>
                        <i class="fas fa-file-alt s-bg"></i>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card c-orange animate-in">
                        <div class="s-icon"><i class="fas fa-clock"></i></div>
                        <div class="s-value"><?php echo number_format($pending_count); ?></div>
                        <div class="s-label">Pending Assignments</div>
                        <i class="fas fa-clock s-bg"></i>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card c-blue animate-in">
                        <div class="s-icon"><i class="fas fa-spinner"></i></div>
                        <div class="s-value"><?php echo number_format($checking_count); ?></div>
                        <div class="s-label">Checking Assignments</div>
                        <i class="fas fa-spinner s-bg"></i>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card c-green animate-in">
                        <div class="s-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="s-value"><?php echo number_format($completed_count); ?></div>
                        <div class="s-label">Completed Assignments</div>
                        <i class="fas fa-check-circle s-bg"></i>
                    </div>
                </div>
            </div>

            <!-- ═══ ASSIGNMENTS TABLE ═══ -->
            <div class="table-card table-animate" id="assignmentTableCard">
                <div class="table-card-header">
                    <div class="tch-left">
                        <div class="tch-title"><i class="fas fa-list"></i> All Assignments</div>
                        <div class="tch-count"><?php echo number_format($total_filtered); ?> record<?php echo $total_filtered !== 1 ? 's' : ''; ?></div>
                    </div>
                    <div class="tch-right">
                        <form method="GET" action="" id="searchForm" class="d-flex align-items-center gap-2 flex-wrap">
                            <div class="search-box">
                                <i class="fas fa-search"></i>
                                <input type="text" name="search" placeholder="Search title, subject, status..." value="<?php echo htmlspecialchars($search_term); ?>" id="searchInput">
                            </div>
                            <select name="filter" class="filter-select" id="filterSelect">
                                <option value="" <?php echo empty($filter_status) ? 'selected' : ''; ?>>All Status</option>
                                <option value="Pending" <?php echo $filter_status === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="Checking" <?php echo $filter_status === 'Checking' ? 'selected' : ''; ?>>Checking</option>
                                <option value="Completed" <?php echo $filter_status === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                            </select>
                            <input type="hidden" name="page" value="1" id="hiddenPage">
                        </form>
                        <button class="action-btn btn-refresh" onclick="location.reload()" title="Refresh">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                        <button class="action-btn btn-pdf" onclick="exportPDF()" title="Export PDF">
                            <i class="fas fa-file-pdf"></i> PDF
                        </button>
                        <button class="action-btn btn-excel" onclick="exportExcel()" title="Export Excel">
                            <i class="fas fa-file-excel"></i> Excel
                        </button>
                        <button class="action-btn btn-print" onclick="window.print()" title="Print">
                            <i class="fas fa-print"></i> Print
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="assignmentsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User ID</th>
                                <th>Title</th>
                                <th>Subject</th>
                                <th>Content</th>
                                <th>File</th>
                                <th>Submitted</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($assignments)): ?>
                            <tr>
                                <td colspan="9">
                                    <div class="empty-state">
                                        <i class="fas fa-inbox"></i>
                                        <h6>No Assignments Found</h6>
                                        <p>Try adjusting your search or filter criteria.</p>
                                    </div>
                                </td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($assignments as $a):
                                    $aid    = (int)$a['assignment_id'];
                                    $uid    = (int)$a['user_id'];
                                    $title  = htmlspecialchars($a['title']);
                                    $subj   = htmlspecialchars($a['subject']);
                                    $content = htmlspecialchars($a['assignment_content']);
                                    $file   = htmlspecialchars($a['upload_file'] ?? '');
                                    $file_url = htmlspecialchars($a['_file_url'] ?? get_assignment_file_url($a));
                                    $download_url = htmlspecialchars($a['_download_url'] ?? ('download_assignment.php?id=' . $aid));
                                    $file_label = htmlspecialchars(basename($a['upload_file'] ?? $a['_file_url'] ?? 'Assignment File'));
                                    $date   = $a['submission_date'];
                                    $status = htmlspecialchars($a['status']);
                                    $truncated = mb_strlen($content) > 100 ? mb_substr($content, 0, 100) . '...' : $content;

                                    $badge_class = 'pending';
                                    $badge_icon  = 'circle';
                                    if ($status === 'Checking') { $badge_class = 'checking'; $badge_icon = 'sync-alt'; }
                                    elseif ($status === 'Completed') { $badge_class = 'completed'; $badge_icon = 'check'; }

                                    $formatted_date = date('M d, Y h:i A', strtotime($date));
                                ?>
                                <tr>
                                    <td class="text-muted-cell">#<?php echo $aid; ?></td>
                                    <td class="text-muted-cell"><?php echo $uid; ?></td>
                                    <td><strong><?php echo $title; ?></strong></td>
                                    <td><?php echo $subj; ?></td>
                                    <td class="text-muted-cell" style="max-width:200px;white-space:pre-wrap;"><?php echo $truncated; ?></td>
                                    <td>
                                        <?php if (!empty($file_url)): ?>
                                        <a href="<?php echo $download_url; ?>" class="download-btn">
                                            <i class="fas fa-download"></i> Download
                                        </a>
                                        <?php else: ?>
                                        <span class="no-file">No File Uploaded</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted-cell" style="white-space:nowrap;"><?php echo $formatted_date; ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $badge_class; ?>">
                                            <i class="fas fa-<?php echo $badge_icon; ?>"></i>
                                            <?php echo $status; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-1">
                                            <button class="tbl-action-btn view-btn" onclick="viewAssignment(<?php echo $aid; ?>)" title="View">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="tbl-action-btn edit-btn" onclick="editAssignment(<?php echo $aid; ?>)" title="Edit">
                                                <i class="fas fa-pen"></i>
                                            </button>
                                            <button class="tbl-action-btn delete-btn" onclick="confirmDelete(<?php echo $aid; ?>, '<?php echo addslashes($title); ?>')" title="Delete">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_filtered > 0): ?>
                <div class="table-card-footer">
                    <div class="tcf-info">
                        Showing <?php echo ($offset + 1); ?>–<?php echo min($offset + $per_page, $total_filtered); ?> of <?php echo number_format($total_filtered); ?>
                    </div>
                    <div class="pagination-wrapper">
                        <?php
                        // Previous button
                        if ($current_page > 1) {
                            echo '<button class="pg-btn" onclick="goToPage(' . ($current_page - 1) . ')"><i class="fas fa-chevron-left" style="font-size:11px;"></i></button>';
                        } else {
                            echo '<button class="pg-btn" disabled><i class="fas fa-chevron-left" style="font-size:11px;"></i></button>';
                        }

                        // Page numbers with ellipsis
                        $start_page = max(1, $current_page - 2);
                        $end_page   = min($total_pages, $current_page + 2);

                        if ($start_page > 1) {
                            echo '<button class="pg-btn" onclick="goToPage(1)">1</button>';
                            if ($start_page > 2) echo '<span class="pg-ellipsis">...</span>';
                        }

                        for ($p = $start_page; $p <= $end_page; $p++) {
                            $active = $p === $current_page ? ' active' : '';
                            echo '<button class="pg-btn' . $active . '" onclick="goToPage(' . $p . ')">' . $p . '</button>';
                        }

                        if ($end_page < $total_pages) {
                            if ($end_page < $total_pages - 1) echo '<span class="pg-ellipsis">...</span>';
                            echo '<button class="pg-btn" onclick="goToPage(' . $total_pages . ')">' . $total_pages . '</button>';
                        }

                        // Next button
                        if ($current_page < $total_pages) {
                            echo '<button class="pg-btn" onclick="goToPage(' . ($current_page + 1) . ')"><i class="fas fa-chevron-right" style="font-size:11px;"></i></button>';
                        } else {
                            echo '<button class="pg-btn" disabled><i class="fas fa-chevron-right" style="font-size:11px;"></i></button>';
                        }
                        ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </main>

    <!-- ═══════ VIEW MODAL ═══════ -->
    <div class="modal fade" id="viewModal" tabindex="-1" aria-labelledby="viewModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewModalLabel"><i class="fas fa-eye"></i> Assignment Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="viewModalBody">
                    <!-- Filled by JS -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════ EDIT MODAL ═══════ -->
    <form method="POST" action="" id="editForm">
        <input type="hidden" name="edit_assignment" value="1">
        <input type="hidden" name="edit_id" id="editId" value="">
        <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editModalLabel"><i class="fas fa-pen"></i> Edit Assignment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label-custom">Title *</label>
                                <input type="text" name="edit_title" id="editTitle" class="form-control-custom" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-custom">Subject *</label>
                                <input type="text" name="edit_subject" id="editSubject" class="form-control-custom" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label-custom">Assignment Content</label>
                                <textarea name="edit_content" id="editContent" class="form-control-custom" rows="8"></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-custom">Status</label>
                                <select name="edit_status" id="editStatus" class="form-select-custom">
                                    <option value="Pending">Pending</option>
                                    <option value="Checking">Checking</option>
                                    <option value="Completed">Completed</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-primary-custom"><i class="fas fa-save"></i> Save Changes</button>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <!-- ═══════ DELETE MODAL ═══════ -->
    <form method="POST" action="" id="deleteForm">
        <input type="hidden" name="delete_assignment" value="1">
        <input type="hidden" name="delete_id" id="deleteId" value="">
        <div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-sm">
                <div class="modal-content">
                    <div class="modal-body" style="padding:32px 28px;">
                        <div class="delete-icon-wrapper">
                            <i class="fas fa-trash-alt"></i>
                        </div>
                        <div class="delete-modal-text">
                            <h6>Delete Assignment?</h6>
                            <p id="deleteModalText">Are you sure you want to delete this assignment? This action cannot be undone.</p>
                        </div>
                    </div>
                    <div class="modal-footer" style="justify-content:center; gap:10px; border:none; padding-top:0;">
                        <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-danger-custom"><i class="fas fa-trash-alt"></i> Delete</button>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // ─── ASSIGNMENT DATA (passed from PHP) ───
        const assignmentsData = <?php echo safe_json_for_js($assignments); ?>;

        // ─── SIDEBAR TOGGLE ───
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        const toggleBtn = document.getElementById('sidebarToggle');

        toggleBtn.addEventListener('click', () => {
            sidebar.classList.toggle('show');
            overlay.classList.toggle('show');
        });
        overlay.addEventListener('click', () => {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
        });

        // ─── NOTIFICATION DROPDOWN ───
        const notiBtn = document.getElementById('notiBtn');
        const notiDropdown = document.getElementById('notiDropdown');

        notiBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            notiDropdown.classList.toggle('show');
        });
        document.addEventListener('click', () => notiDropdown.classList.remove('show'));
        notiDropdown.addEventListener('click', (e) => e.stopPropagation());

        // ─── SETTINGS PANEL ───
        const settingsBtn = document.getElementById('settingsBtn');
        const settingsPanel = document.getElementById('settingsPanel');
        const settingsOverlay = document.getElementById('settingsOverlay');
        const settingsCloseBtn = document.getElementById('settingsCloseBtn');

        function openSettings() {
            settingsPanel.classList.add('show');
            settingsOverlay.classList.add('show');
        }
        function closeSettings() {
            settingsPanel.classList.remove('show');
            settingsOverlay.classList.remove('show');
        }

        settingsBtn.addEventListener('click', openSettings);
        settingsCloseBtn.addEventListener('click', closeSettings);
        settingsOverlay.addEventListener('click', closeSettings);

        // ─── THEME TOGGLE ───
        const themeToggle = document.getElementById('themeToggle');
        const html = document.documentElement;

        if (localStorage.getItem('theme') === 'dark') {
            html.setAttribute('data-theme', 'dark');
            themeToggle.checked = true;
        }

        themeToggle.addEventListener('change', () => {
            if (themeToggle.checked) {
                html.setAttribute('data-theme', 'dark');
                localStorage.setItem('theme', 'dark');
            } else {
                html.setAttribute('data-theme', 'light');
                localStorage.setItem('theme', 'light');
            }
        });

        // ─── LIVE CLOCK ───
        function updateClock() {
            const now = new Date();
            const opts = { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' };
            document.getElementById('headerTime').textContent = now.toLocaleDateString('en-GB', opts);
        }
        updateClock();
        setInterval(updateClock, 30000);

        // ─── ALERT AUTO-DISMISS ───
        setTimeout(() => {
            const a1 = document.getElementById('pageAlert');
            const a2 = document.getElementById('pageAlertEdit');
            if (a1) a1.classList.remove('show');
            if (a2) a2.classList.remove('show');
        }, 4000);
        setTimeout(() => {
            const a1 = document.getElementById('pageAlert');
            const a2 = document.getElementById('pageAlertEdit');
            if (a1) a1.style.display = 'none';
            if (a2) a2.style.display = 'none';
        }, 4500);
        // Trigger show
        requestAnimationFrame(() => {
            const a1 = document.getElementById('pageAlert');
            const a2 = document.getElementById('pageAlertEdit');
            if (a1) a1.classList.add('show');
            if (a2) a2.classList.add('show');
        });

        // ─── SEARCH & FILTER ───
        const searchInput  = document.getElementById('searchInput');
        const filterSelect = document.getElementById('filterSelect');
        let searchTimeout;

        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                document.getElementById('hiddenPage').value = '1';
                document.getElementById('searchForm').submit();
            }, 500);
        });

        filterSelect.addEventListener('change', () => {
            document.getElementById('hiddenPage').value = '1';
            document.getElementById('searchForm').submit();
        });

        // ─── PAGINATION ───
        function goToPage(page) {
            const base = new URL(window.location.href);
            base.searchParams.set('page', page);
            if (searchInput.value) base.searchParams.set('search', searchInput.value);
            else base.searchParams.delete('search');
            if (filterSelect.value) base.searchParams.set('filter', filterSelect.value);
            else base.searchParams.delete('filter');
            window.location.href = base.toString();
        }

        // ─── VIEW ASSIGNMENT ───
        function viewAssignment(id) {
            const a = assignmentsData.find(item => item.assignment_id == id);
            if (!a) return;

            const statusClass = a.status === 'Checking' ? 'checking' : (a.status === 'Completed' ? 'completed' : 'pending');
            const statusIcon  = a.status === 'Checking' ? 'sync-alt' : (a.status === 'Completed' ? 'check' : 'circle');
            const dateStr = new Date(a.submission_date).toLocaleString('en-GB', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });

            let fileHtml = '<span class="no-file">No File Uploaded</span>';
            if (a.upload_file && a.upload_file.trim() !== '') {
                const fileUrl = a._download_url || ('download_assignment.php?id=' + a.assignment_id);
                fileHtml = '<a href="' + fileUrl + '" class="download-btn"><i class="fas fa-download"></i> ' + a.upload_file + '</a>';
            }

            document.getElementById('viewModalBody').innerHTML = `
                <div class="view-detail-row">
                    <div class="view-detail-label">Assignment ID</div>
                    <div class="view-detail-value">#${a.assignment_id}</div>
                </div>
                <div class="view-detail-row">
                    <div class="view-detail-label">User ID</div>
                    <div class="view-detail-value">${a.user_id}</div>
                </div>
                <div class="view-detail-row">
                    <div class="view-detail-label">Title</div>
                    <div class="view-detail-value"><strong>${a.title}</strong></div>
                </div>
                <div class="view-detail-row">
                    <div class="view-detail-label">Subject</div>
                    <div class="view-detail-value">${a.subject}</div>
                </div>
                <div class="view-detail-row">
                    <div class="view-detail-label">Content</div>
                    <div class="view-detail-value content-box">${a.assignment_content || '<em style="color:var(--text-muted);">No content provided.</em>'}</div>
                </div>
                <div class="view-detail-row">
                    <div class="view-detail-label">Uploaded File</div>
                    <div class="view-detail-value">${fileHtml}</div>
                </div>
                <div class="view-detail-row">
                    <div class="view-detail-label">Submission Date</div>
                    <div class="view-detail-value">${dateStr}</div>
                </div>
                <div class="view-detail-row">
                    <div class="view-detail-label">Status</div>
                    <div class="view-detail-value">
                        <span class="status-badge ${statusClass}">
                            <i class="fas fa-${statusIcon}"></i> ${a.status}
                        </span>
                    </div>
                </div>
            `;

            new bootstrap.Modal(document.getElementById('viewModal')).show();
        }

        // ─── EDIT ASSIGNMENT ───
        function editAssignment(id) {
            const a = assignmentsData.find(item => item.assignment_id == id);
            if (!a) return;

            document.getElementById('editId').value      = a.assignment_id;
            document.getElementById('editTitle').value    = a.title;
            document.getElementById('editSubject').value  = a.subject;
            document.getElementById('editContent').value  = a.assignment_content || '';
            document.getElementById('editStatus').value   = a.status;

            new bootstrap.Modal(document.getElementById('editModal')).show();
        }

        // ─── DELETE ASSIGNMENT ───
        function confirmDelete(id, title) {
            document.getElementById('deleteId').value = id;
            document.getElementById('deleteModalText').textContent =
                'Are you sure you want to delete "' + title + '"? This action cannot be undone.';
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        }

        // ─── EXPORT PDF ───
        function exportPDF() {
            const table = document.getElementById('assignmentsTable');
            if (!table) return;

            const style = `
                <style>
                    body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 20px; color: #2D1B4E; }
                    h2 { color: #6A0DAD; margin-bottom: 4px; font-size: 20px; }
                    p.sub { color: #7B6B8D; font-size: 12px; margin-bottom: 16px; }
                    table { width: 100%; border-collapse: collapse; font-size: 11px; }
                    th { background: #6A0DAD; color: #fff; padding: 10px 8px; text-align: left; font-weight: 600; }
                    td { padding: 8px; border-bottom: 1px solid #E0D4ED; vertical-align: top; }
                    tr:nth-child(even) { background: #F8F5FC; }
                    .badge-p { color: #E65100; font-weight: 600; }
                    .badge-c { color: #1565C0; font-weight: 600; }
                    .badge-d { color: #2E7D32; font-weight: 600; }
                    .footer { margin-top: 16px; font-size: 10px; color: #999; }
                </style>
            `;

            const rows = table.querySelectorAll('tbody tr');
            let tableHTML = '<table><thead><tr>';
            table.querySelectorAll('thead th').forEach((th, i) => {
                if (i < 8) tableHTML += '<th>' + th.textContent.trim() + '</th>';
            });
            tableHTML += '</tr></thead><tbody>';

            rows.forEach(row => {
                if (row.querySelector('.empty-state')) return;
                const cells = row.querySelectorAll('td');
                if (cells.length < 8) return;
                tableHTML += '<tr>';
                for (let i = 0; i < 8; i++) {
                    let cellText = cells[i].textContent.trim();
                    // Replace status with styled text
                    if (i === 7) {
                        const status = cells[i].querySelector('.status-badge');
                        if (status) {
                            const txt = status.textContent.trim();
                            let cls = 'badge-p';
                            if (txt === 'Checking') cls = 'badge-c';
                            else if (txt === 'Completed') cls = 'badge-d';
                            tableHTML += '<td class="' + cls + '">' + txt + '</td>';
                            continue;
                        }
                    }
                    if (i === 4 && cellText.length > 60) cellText = cellText.substring(0, 60) + '...';
                    tableHTML += '<td>' + cellText + '</td>';
                }
                tableHTML += '</tr>';
            });
            tableHTML += '</tbody></table>';

            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <!DOCTYPE html><html><head><title>Assignments Report</title>${style}</head><body>
                <h2>AI Assignment Checker — Assignments Report</h2>
                <p class="sub">Generated on ${new Date().toLocaleString('en-GB', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })} | Total: ${assignmentsData.length} records</p>
                ${tableHTML}
                <div class="footer">AI Assignment Checker Admin Panel — Confidential</div>
                </body></html>
            `);
            printWindow.document.close();
            printWindow.focus();
            setTimeout(() => { printWindow.print(); }, 500);
        }

        // ─── EXPORT EXCEL (CSV) ───
        function exportExcel() {
            if (assignmentsData.length === 0) { alert('No data to export.'); return; }

            let csv = 'Assignment ID,User ID,Title,Subject,Content,File,Submission Date,Status\n';
            assignmentsData.forEach(a => {
                const content = (a.assignment_content || '').replace(/"/g, '""').replace(/\n/g, ' ');
                const title   = (a.title || '').replace(/"/g, '""');
                const subject = (a.subject || '').replace(/"/g, '""');
                const file    = a.upload_file || 'No File';
                const date    = a.submission_date || '';
                csv += `${a.assignment_id},${a.user_id},"${title}","${subject}","${content}","${file}","${date}","${a.status}"\n`;
            });

            const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'assignments_export_' + new Date().toISOString().slice(0, 10) + '.csv';
            link.click();
            URL.revokeObjectURL(link.href);
        }
    </script>
</body>
</html>