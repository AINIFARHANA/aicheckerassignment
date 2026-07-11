<?php
session_start();

// ─── DATABASE CONNECTION ───
include 'config.php';

 $admin_id = $_SESSION['user_id'] ?? null;
if (!isset($admin_id)) {
    header('location: login.php');
    exit;
}

// ─── ENSURE UPLOAD DIRECTORY EXISTS ───
 $upload_dir = 'uploads/plans/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
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

if (!empty($db_avatar) && $db_avatar !== 'default.png') {
    if (filter_var($db_avatar, FILTER_VALIDATE_URL)) {
        $avatar = $db_avatar;
    } else {
        $avatar = "https://api.dicebear.com/7.x/avataaars/svg?seed=" . urlencode($db_avatar) . "&backgroundColor=ede9fe";
    }
} else {
    $avatar = "https://api.dicebear.com/7.x/avataaars/svg?seed=Admin&backgroundColor=ede9fe";
}

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

 $unread_count  = 0;
 $notifications = [];
// FIX: Filter out localhost notifications
 $noti_query = mysqli_query($conn, "SELECT * FROM notifications WHERE user_id IS NULL AND message NOT LIKE '%localhost%' ORDER BY created_at DESC LIMIT 30");
if (mysqli_num_rows($noti_query) > 0) {
    while ($row = mysqli_fetch_assoc($noti_query)) {
        $notifications[] = $row;
        if ($row['is_read'] == 0) $unread_count++;
    }
}

// ═══════════════════════════════════════════════════════════════
// IMAGE UPLOAD HELPER
// ═══════════════════════════════════════════════════════════════
function handle_image_upload($file_input, $old_image = '', $upload_dir = 'uploads/plans/') {
    if (!isset($file_input) || $file_input['error'] === UPLOAD_ERR_NO_FILE) {
        return ['success' => true, 'filename' => $old_image];
    }
    if ($file_input['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Upload failed with error code ' . $file_input['error']];
    }

    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
    $max_size = 5 * 1024 * 1024;

    if ($file_input['size'] > $max_size) {
        return ['success' => false, 'message' => 'File size exceeds 5MB limit.'];
    }
    if (!in_array($file_input['type'], $allowed_types)) {
        return ['success' => false, 'message' => 'Only JPG, JPEG, PNG, and WEBP files are allowed.'];
    }

    $ext = pathinfo($file_input['name'], PATHINFO_EXTENSION);
    $new_name = 'plan_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
    $destination = $upload_dir . $new_name;

    if (move_uploaded_file($file_input['tmp_name'], $destination)) {
        if (!empty($old_image) && file_exists($upload_dir . $old_image)) {
            unlink($upload_dir . $old_image);
        }
        return ['success' => true, 'filename' => $new_name];
    }
    return ['success' => false, 'message' => 'Failed to move uploaded file.'];
}

// ═══════════════════════════════════════════════════════════════
// RECOUNT HELPER
// ═══════════════════════════════════════════════════════════════
function recount_stats($conn) {
    $s1 = $conn->prepare("SELECT COUNT(*) AS t FROM plans");
    $s1->execute(); $total = (int)$s1->get_result()->fetch_assoc()['t']; $s1->close();
    $s2 = $conn->prepare("SELECT COUNT(*) AS t FROM plans WHERE status=?");
    $a='Active'; $s2->bind_param("s",$a); $s2->execute(); $active=(int)$s2->get_result()->fetch_assoc()['t']; $s2->close();
    $s3 = $conn->prepare("SELECT COUNT(*) AS t FROM plans WHERE status=?");
    $in='Inactive'; $s3->bind_param("s",$in); $s3->execute(); $inactive=(int)$s3->get_result()->fetch_assoc()['t']; $s3->close();
    $s4 = $conn->prepare("SELECT COALESCE(SUM(price),0) AS t FROM plans");
    $s4->execute(); $rev=(float)$s4->get_result()->fetch_assoc()['t']; $s4->close();
    return [$total, $active, $inactive, $rev];
}

// ═══════════════════════════════════════════════════════════════
// STATISTICS
// ═══════════════════════════════════════════════════════════════
list($total_plans, $active_plans, $inactive_plans, $revenue_potential) = recount_stats($conn);

// ═══════════════════════════════════════════════════════════════
// FLASH MESSAGE
// ═══════════════════════════════════════════════════════════════
 $flash_msg = '';
 $flash_type = '';

// ═══════════════════════════════════════════════════════════════
// HANDLE ADD PLAN
// ═══════════════════════════════════════════════════════════════
if (isset($_POST['add_plan'])) {
    $a_name  = trim($_POST['plan_name'] ?? '');
    $a_desc  = trim($_POST['description'] ?? '');
    $a_price = trim($_POST['price'] ?? '0');
    $a_dur   = trim($_POST['duration'] ?? '');
    $a_feat  = trim($_POST['features'] ?? '');
    $a_badge = trim($_POST['badge'] ?? '');
    $a_btn   = trim($_POST['button_text'] ?? 'Subscribe');
    $a_stat  = trim($_POST['status'] ?? 'Active');
    $a_disc  = trim($_POST['assignment_discount'] ?? '0');

    if (!empty($a_name) && !empty($a_desc)) {
        $allowed = ['Active','Inactive'];
        if (!in_array($a_stat, $allowed)) $a_stat = 'Active';
        $pv = filter_var($a_price, FILTER_VALIDATE_FLOAT);
        if ($pv === false || $pv < 0) $pv = 0;

        /* Assignment discount is a % (0-100) applied to assignment checks
           for subscribers of this plan — e.g. 50 = half price. */
        $dv = filter_var($a_disc, FILTER_VALIDATE_FLOAT);
        if ($dv === false || $dv < 0) $dv = 0;
        if ($dv > 100) $dv = 100;

        $img_result = handle_image_upload($_FILES['plan_image'] ?? null, '', $upload_dir);
        if (!$img_result['success']) {
            $flash_msg = $img_result['message'];
            $flash_type = 'danger';
        } else {
            $img_name = $img_result['filename'];
            $stmt = $conn->prepare("INSERT INTO plans (plan_name, description, price, assignment_discount, duration, features, badge, button_text, plan_image, status, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())");
            $stmt->bind_param("ssddssssss", $a_name, $a_desc, $pv, $dv, $a_dur, $a_feat, $a_badge, $a_btn, $img_name, $a_stat);
            if ($stmt->execute()) {
                $flash_msg = "Plan added successfully!";
                $flash_type = "success";
                list($total_plans, $active_plans, $inactive_plans, $revenue_potential) = recount_stats($conn);
            } else {
                $flash_msg = "Error adding plan.";
                $flash_type = "danger";
            }
            $stmt->close();
        }
    } else {
        $flash_msg = "Plan Name and Description are required.";
        $flash_type = "warning";
    }
}

// ═══════════════════════════════════════════════════════════════
// HANDLE EDIT PLAN
// ═══════════════════════════════════════════════════════════════
if (isset($_POST['edit_plan']) && isset($_POST['edit_id'])) {
    $e_id    = (int)$_POST['edit_id'];
    $e_name  = trim($_POST['plan_name'] ?? '');
    $e_desc  = trim($_POST['description'] ?? '');
    $e_price = trim($_POST['price'] ?? '0');
    $e_dur   = trim($_POST['duration'] ?? '');
    $e_feat  = trim($_POST['features'] ?? '');
    $e_badge = trim($_POST['badge'] ?? '');
    $e_btn   = trim($_POST['button_text'] ?? 'Subscribe');
    $e_stat  = trim($_POST['status'] ?? 'Active');
    $e_disc  = trim($_POST['assignment_discount'] ?? '0');

    if ($e_id > 0 && !empty($e_name) && !empty($e_desc)) {
        $allowed = ['Active','Inactive'];
        if (!in_array($e_stat, $allowed)) $e_stat = 'Active';
        $pv = filter_var($e_price, FILTER_VALIDATE_FLOAT);
        if ($pv === false || $pv < 0) $pv = 0;

        /* Assignment discount is a % (0-100) applied to assignment checks
           for subscribers of this plan — e.g. 50 = half price. */
        $dv = filter_var($e_disc, FILTER_VALIDATE_FLOAT);
        if ($dv === false || $dv < 0) $dv = 0;
        if ($dv > 100) $dv = 100;

        $stmt_old = $conn->prepare("SELECT plan_image FROM plans WHERE plan_id=?");
        $stmt_old->bind_param("i", $e_id);
        $stmt_old->execute();
        $old_img = $stmt_old->get_result()->fetch_assoc()['plan_image'] ?? '';
        $stmt_old->close();

        $img_result = handle_image_upload($_FILES['plan_image'] ?? null, $old_img, $upload_dir);
        if (!$img_result['success']) {
            $flash_msg = $img_result['message'];
            $flash_type = 'danger';
        } else {
            $img_name = $img_result['filename'];
            $stmt = $conn->prepare("UPDATE plans SET plan_name=?, description=?, price=?, assignment_discount=?, duration=?, features=?, badge=?, button_text=?, plan_image=?, status=? WHERE plan_id=?");
            $stmt->bind_param("ssddssssssi", $e_name, $e_desc, $pv, $dv, $e_dur, $e_feat, $e_badge, $e_btn, $img_name, $e_stat, $e_id);
            if ($stmt->execute()) {
                $flash_msg = "Plan updated successfully!";
                $flash_type = "success";
                list($total_plans, $active_plans, $inactive_plans, $revenue_potential) = recount_stats($conn);
            } else {
                $flash_msg = "Error updating plan.";
                $flash_type = "danger";
            }
            $stmt->close();
        }
    } else {
        $flash_msg = "Plan Name and Description are required.";
        $flash_type = "warning";
    }
}

// ═══════════════════════════════════════════════════════════════
// HANDLE DELETE PLAN
// ═══════════════════════════════════════════════════════════════
if (isset($_POST['delete_plan']) && isset($_POST['delete_id'])) {
    $d_id = (int)$_POST['delete_id'];
    if ($d_id > 0) {
        $stmt_img = $conn->prepare("SELECT plan_image FROM plans WHERE plan_id=?");
        $stmt_img->bind_param("i", $d_id);
        $stmt_img->execute();
        $d_img = $stmt_img->get_result()->fetch_assoc()['plan_image'] ?? '';
        $stmt_img->close();

        $stmt = $conn->prepare("DELETE FROM plans WHERE plan_id=?");
        $stmt->bind_param("i", $d_id);
        if ($stmt->execute()) {
            if (!empty($d_img) && file_exists($upload_dir . $d_img)) {
                unlink($upload_dir . $d_img);
            }
            $flash_msg = "Plan deleted successfully!";
            $flash_type = "success";
            list($total_plans, $active_plans, $inactive_plans, $revenue_potential) = recount_stats($conn);
        } else {
            $flash_msg = "Error deleting plan.";
            $flash_type = "danger";
        }
        $stmt->close();
    }
}

// ═══════════════════════════════════════════════════════════════
// HANDLE TOGGLE STATUS (activate only now, no deactivate button)
// ═══════════════════════════════════════════════════════════════
if (isset($_POST['toggle_status']) && isset($_POST['toggle_id']) && isset($_POST['toggle_to'])) {
    $t_id = (int)$_POST['toggle_id'];
    $t_to = $_POST['toggle_to'];
    if ($t_id > 0 && in_array($t_to, ['Active','Inactive'])) {
        $stmt = $conn->prepare("UPDATE plans SET status=? WHERE plan_id=?");
        $stmt->bind_param("si", $t_to, $t_id);
        if ($stmt->execute()) {
            $flash_msg = "Plan " . strtolower($t_to) . "d successfully!";
            $flash_type = "success";
            list($total_plans, $active_plans, $inactive_plans, $revenue_potential) = recount_stats($conn);
        } else {
            $flash_msg = "Error updating status.";
            $flash_type = "danger";
        }
        $stmt->close();
    }
}

// ═══════════════════════════════════════════════════════════════
// SEARCH, FILTER & PAGINATION
// ═══════════════════════════════════════════════════════════════
 $per_page     = 10;
 $current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
 $search_term  = isset($_GET['search']) ? trim($_GET['search']) : '';
 $filter_status = isset($_GET['filter']) ? trim($_GET['filter']) : '';

 $where_parts = [];
 $params = [];
 $types = '';

if (!empty($search_term)) {
    $where_parts[] = "(plan_name LIKE ? OR badge LIKE ? OR duration LIKE ?)";
    $sw = "%" . $search_term . "%";
    $params[] = $sw; $params[] = $sw; $params[] = $sw;
    $types .= "sss";
}
if (!empty($filter_status) && in_array($filter_status, ['Active','Inactive'])) {
    $where_parts[] = "status = ?";
    $params[] = $filter_status;
    $types .= "s";
}

 $where_sql = !empty($where_parts) ? "WHERE " . implode(" AND ", $where_parts) : "";

 $stmt_c = $conn->prepare("SELECT COUNT(*) AS total FROM plans $where_sql");
if (!empty($params)) $stmt_c->bind_param($types, ...$params);
 $stmt_c->execute();
 $total_filtered = (int)$stmt_c->get_result()->fetch_assoc()['total'];
 $stmt_c->close();

 $total_pages = max(1, ceil($total_filtered / $per_page));
if ($current_page > $total_pages) $current_page = $total_pages;
 $offset = ($current_page - 1) * $per_page;

 $fetch_sql = "SELECT * FROM plans $where_sql ORDER BY plan_id DESC LIMIT ? OFFSET ?";
 $types .= "ii";
 $params[] = $per_page; $params[] = $offset;

 $stmt_f = $conn->prepare($fetch_sql);
if (!empty($params)) $stmt_f->bind_param($types, ...$params);
 $stmt_f->execute();
 $result = $stmt_f->get_result();
 $plans = [];
while ($row = $result->fetch_assoc()) { $plans[] = $row; }
 $stmt_f->close();

 $conn->close();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Plans — AI Assignment Checker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap">
    <style>
        :root{--primary:#6A0DAD;--primary-light:#9C27B0;--primary-dark:#4A0072;--primary-rgb:106,13,173;--secondary-rgb:156,39,176;--bg:#F3F0F7;--card-bg:rgba(255,255,255,0.78);--sidebar-width:260px;--header-height:70px;--text-dark:#2D1B4E;--text-muted:#7B6B8D;--border-color:rgba(106,13,173,0.08);--input-bg:#FFFFFF;--shadow-sm:0 2px 8px rgba(106,13,173,0.06);--shadow-md:0 4px 20px rgba(106,13,173,0.1);--shadow-lg:0 8px 40px rgba(106,13,173,0.15);--radius:16px;--radius-sm:10px}
        [data-theme="dark"]{--bg:#110B18;--card-bg:rgba(32,18,52,0.82);--text-dark:#E8E0F0;--text-muted:#9B8DB5;--border-color:rgba(156,39,176,0.12);--input-bg:rgba(45,27,78,0.6);--shadow-sm:0 2px 8px rgba(0,0,0,0.25);--shadow-md:0 4px 20px rgba(0,0,0,0.35);--shadow-lg:0 8px 40px rgba(0,0,0,0.45)}
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Poppins',sans-serif;background:var(--bg);color:var(--text-dark);overflow-x:hidden;min-height:100vh;transition:background .35s ease,color .35s ease}
        .sidebar{position:fixed;top:0;left:0;width:var(--sidebar-width);height:100vh;background:linear-gradient(180deg,var(--primary-dark) 0%,var(--primary) 50%,var(--primary-light) 100%);z-index:1050;transition:transform .35s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;box-shadow:4px 0 30px rgba(106,13,173,.3)}
        .sidebar-brand{padding:24px 20px;border-bottom:1px solid rgba(255,255,255,.1);display:flex;align-items:center;gap:12px}
        .sidebar-brand .brand-icon{width:52px;height:60px;background:rgba(255,255,255,.15);border-radius:12px;overflow:hidden;backdrop-filter:blur(10px);flex-shrink:0}
        .sidebar-brand .brand-icon img{width:100%;height:100%;object-fit:cover}
        .sidebar-brand h5{color:#fff;font-weight:700;font-size:15px;margin:0;line-height:1.3}
        .sidebar-brand small{color:rgba(255,255,255,.6);font-size:11px}
        .sidebar-menu{flex:1;padding:16px 12px;overflow-y:auto}
        .sidebar-menu .menu-label{color:rgba(255,255,255,.4);font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:1.5px;padding:12px 14px 8px}
        .sidebar-menu a{display:flex;align-items:center;gap:12px;padding:11px 14px;color:rgba(255,255,255,.7);text-decoration:none;border-radius:var(--radius-sm);font-size:13.5px;font-weight:500;transition:all .25s ease;margin-bottom:2px;position:relative}
        .sidebar-menu a i.fa-icon{width:20px;text-align:center;font-size:15px}
        .sidebar-menu a:hover{background:rgba(255,255,255,.1);color:#fff;transform:translateX(4px)}
        .sidebar-menu a.active{background:rgba(255,255,255,.18);color:#fff;box-shadow:0 4px 15px rgba(0,0,0,.15)}
        .sidebar-menu a.active::before{content:'';position:absolute;left:0;top:50%;transform:translateY(-50%);width:4px;height:60%;background:#fff;border-radius:0 4px 4px 0}
        .sidebar-menu a.logout-btn{color:#FF6B8A;margin-top:20px;border-top:1px solid rgba(255,255,255,.08);padding-top:16px}
        .sidebar-menu a.logout-btn:hover{background:rgba(255,107,138,.12);color:#FF6B8A;transform:translateX(4px)}
        .sidebar-footer{padding:16px 20px;border-top:1px solid rgba(255,255,255,.1)}
        .sidebar-footer .admin-info{display:flex;align-items:center;gap:10px}
        .sidebar-footer .admin-avatar-img{width:38px;height:38px;border-radius:10px;border:2px solid rgba(255,255,255,.2);background:rgba(255,255,255,.08);object-fit:cover;flex-shrink:0}
        .sidebar-footer .admin-name{color:#fff;font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:150px}
        .sidebar-footer .admin-role{color:rgba(255,255,255,.5);font-size:11px}
        .main-content{margin-left:var(--sidebar-width);min-height:100vh;transition:margin-left .35s cubic-bezier(.4,0,.2,1)}
        .top-header{height:var(--header-height);background:rgba(255,255,255,.8);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border-bottom:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;padding:0 30px;position:sticky;top:0;z-index:1000;transition:background .35s ease}
        [data-theme="dark"] .top-header{background:rgba(17,11,24,.88)}
        .top-header .left-section{display:flex;align-items:center;gap:16px}
        .sidebar-toggle{display:none;background:none;border:none;font-size:20px;color:var(--primary);cursor:pointer;padding:6px;border-radius:8px;transition:background .2s}
        .sidebar-toggle:hover{background:rgba(var(--primary-rgb),.08)}
        .top-header .page-title{font-size:18px;font-weight:700;color:var(--text-dark);transition:color .35s ease}
        .top-header .page-title span{color:var(--primary)}
        .top-header .right-section{display:flex;align-items:center;gap:10px}
        .header-btn{width:40px;height:40px;border-radius:12px;border:1px solid var(--border-color);background:#fff;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:16px;cursor:pointer;transition:all .25s ease;position:relative}
        [data-theme="dark"] .header-btn{background:rgba(45,27,78,.5);border-color:var(--border-color);color:var(--text-muted)}
        .header-btn:hover{border-color:var(--primary);color:var(--primary);box-shadow:var(--shadow-sm)}
        .header-time{font-size:12.5px;color:var(--text-muted);font-weight:500;background:rgba(var(--primary-rgb),.05);padding:6px 14px;border-radius:8px}
        [data-theme="dark"] .header-time{background:rgba(156,39,176,.08)}
        .notification-wrapper{position:relative}
        .noti-badge{position:absolute;top:6px;right:6px;min-width:18px;height:18px;background:#FF4757;color:#fff;font-size:10px;font-weight:700;border-radius:50%;display:flex;align-items:center;justify-content:center;border:2px solid #fff;padding:0 3px;line-height:1;animation:notiPulse 2s ease-in-out infinite}
        [data-theme="dark"] .noti-badge{border-color:#1A1025}
        @keyframes notiPulse{0%,100%{transform:scale(1)}50%{transform:scale(1.15)}}
        .notification-dropdown{position:absolute;top:calc(100% + 12px);right:-8px;width:360px;max-height:440px;background:#fff;border:1px solid var(--border-color);border-radius:var(--radius);box-shadow:0 20px 60px rgba(0,0,0,.15);opacity:0;visibility:hidden;transform:translateY(-8px) scale(.97);transition:all .3s cubic-bezier(.16,1,.3,1);z-index:9999;overflow:hidden;display:flex;flex-direction:column}
        [data-theme="dark"] .notification-dropdown{background:#1F1333;border-color:rgba(156,39,176,.15)}
        .notification-dropdown.show{opacity:1;visibility:visible;transform:translateY(0) scale(1)}
        .notification-dropdown .noti-header{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border-color);flex-shrink:0}
        .notification-dropdown .noti-header h6{font-size:14px;font-weight:700;color:var(--text-dark);margin:0;display:flex;align-items:center;gap:8px}
        .notification-dropdown .noti-header h6 .count{background:var(--primary);color:#fff;font-size:10px;padding:2px 7px;border-radius:8px}
        .mark-read-btn{background:none;border:none;color:var(--primary);font-size:11.5px;font-weight:600;cursor:pointer;font-family:inherit;padding:4px 8px;border-radius:6px;transition:background .2s}
        .mark-read-btn:hover{background:rgba(var(--primary-rgb),.08)}
        .notification-dropdown .noti-list{overflow-y:auto;flex:1}
        .notification-dropdown .noti-item{display:flex;align-items:flex-start;gap:12px;padding:13px 18px;border-bottom:1px solid var(--border-color);transition:background .2s}
        .notification-dropdown .noti-item:last-child{border-bottom:none}
        .notification-dropdown .noti-item:hover{background:rgba(var(--primary-rgb),.03)}
        .notification-dropdown .noti-item.unread{background:rgba(var(--primary-rgb),.04)}
        .notification-dropdown .noti-dot{width:8px;height:8px;border-radius:50%;background:#E0D4ED;flex-shrink:0;margin-top:6px}
        .notification-dropdown .noti-dot.active{background:var(--primary);box-shadow:0 0 0 3px rgba(var(--primary-rgb),.15)}
        .notification-dropdown .noti-content{flex:1;min-width:0}
        .notification-dropdown .noti-content p{font-size:12.5px;color:var(--text-dark);margin:0 0 3px;line-height:1.45}
        .notification-dropdown .noti-content span{font-size:11px;color:var(--text-muted)}
        .notification-dropdown .noti-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;margin-top:1px}
        .notification-dropdown .noti-icon.assignment{background:rgba(33,150,243,.1);color:#2196F3}
        .notification-dropdown .noti-icon.register{background:rgba(76,175,80,.1);color:#4CAF50}
        .notification-dropdown .noti-icon.default{background:rgba(var(--primary-rgb),.1);color:var(--primary)}
        .noti-empty{padding:40px 20px;text-align:center;color:var(--text-muted);font-size:13px}
        .noti-empty i{font-size:28px;margin-bottom:8px;display:block;opacity:.3}
        .dashboard-body{padding:28px 30px 40px}
        .stat-card{background:var(--card-bg);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border:1px solid var(--border-color);border-radius:var(--radius);padding:24px 22px;position:relative;overflow:hidden;box-shadow:var(--shadow-sm);transition:all .35s cubic-bezier(.4,0,.2,1)}
        .stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;border-radius:var(--radius) var(--radius) 0 0;opacity:0;transition:opacity .3s ease}
        .stat-card:hover{transform:translateY(-6px);box-shadow:var(--shadow-lg);border-color:rgba(var(--primary-rgb),.15)}
        .stat-card:hover::before{opacity:1}
        .stat-card .s-icon{width:52px;height:52px;border-radius:15px;display:flex;align-items:center;justify-content:center;font-size:20px;margin-bottom:18px;transition:transform .3s ease}
        .stat-card:hover .s-icon{transform:scale(1.1) rotate(-5deg)}
        .stat-card .s-value{font-size:28px;font-weight:800;color:var(--text-dark);line-height:1;margin-bottom:4px;letter-spacing:-.5px}
        .stat-card .s-label{font-size:12.5px;color:var(--text-muted);font-weight:500}
        .stat-card .s-bg{position:absolute;right:-10px;bottom:-14px;font-size:86px;opacity:.022;color:var(--primary);pointer-events:none;transition:opacity .3s ease}
        .stat-card:hover .s-bg{opacity:.05}
        .c-purple::before{background:linear-gradient(90deg,#6A0DAD,#9C27B0)}
        .c-purple .s-icon{background:rgba(var(--primary-rgb),.1);color:var(--primary)}
        .c-green::before{background:linear-gradient(90deg,#2E7D32,#66BB6A)}
        .c-green .s-icon{background:rgba(76,175,80,.1);color:#388E3C}
        .c-red::before{background:linear-gradient(90deg,#C62828,#EF5350)}
        .c-red .s-icon{background:rgba(244,67,54,.1);color:#D32F2F}
        .c-blue::before{background:linear-gradient(90deg,#1565C0,#42A5F5)}
        .c-blue .s-icon{background:rgba(33,150,243,.1);color:#1976D2}
        .table-card{background:var(--card-bg);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border:1px solid var(--border-color);border-radius:var(--radius);box-shadow:var(--shadow-sm);overflow:hidden;transition:box-shadow .35s ease}
        .table-card:hover{box-shadow:var(--shadow-md)}
        .table-card-header{padding:20px 24px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px}
        .table-card-header .tch-left{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
        .table-card-header .tch-title{font-size:17px;font-weight:700;color:var(--text-dark);white-space:nowrap}
        .table-card-header .tch-title i{color:var(--primary);margin-right:8px}
        .table-card-header .tch-count{background:rgba(var(--primary-rgb),.08);color:var(--primary);font-size:11px;font-weight:700;padding:4px 12px;border-radius:20px;white-space:nowrap}
        .table-card-header .tch-right{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
        .search-box{position:relative;width:250px}
        .search-box input{width:100%;height:40px;border:1px solid var(--border-color);border-radius:var(--radius-sm);padding:0 14px 0 38px;background:var(--input-bg);color:var(--text-dark);font-family:inherit;font-size:13px;transition:all .25s ease;outline:none}
        .search-box input::placeholder{color:var(--text-muted)}
        .search-box input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(var(--primary-rgb),.1)}
        .search-box i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:13px;pointer-events:none}
        .filter-select{height:40px;border:1px solid var(--border-color);border-radius:var(--radius-sm);padding:0 32px 0 14px;background:var(--input-bg);color:var(--text-dark);font-family:inherit;font-size:13px;font-weight:500;transition:all .25s ease;outline:none;appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%237B6B8D' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;cursor:pointer}
        .filter-select:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(var(--primary-rgb),.1)}
        .action-btn{height:40px;padding:0 14px;border:1px solid var(--border-color);border-radius:var(--radius-sm);background:var(--input-bg);color:var(--text-muted);font-family:inherit;font-size:12.5px;font-weight:500;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .25s ease;white-space:nowrap}
        .action-btn:hover{border-color:var(--primary);color:var(--primary);background:rgba(var(--primary-rgb),.04)}
        .action-btn.btn-add{background:linear-gradient(135deg,var(--primary),var(--primary-light));color:#fff;border-color:transparent}
        .action-btn.btn-add:hover{transform:translateY(-1px);box-shadow:0 4px 16px rgba(var(--primary-rgb),.35);color:#fff}
        .action-btn.btn-refresh:hover{border-color:#1976D2;color:#1976D2}
        .action-btn.btn-pdf:hover{border-color:#D32F2F;color:#D32F2F}
        .action-btn.btn-excel:hover{border-color:#2E7D32;color:#2E7D32}
        .action-btn.btn-print:hover{border-color:#F57C00;color:#F57C00}
        .table-responsive{overflow-x:auto}
        .table-responsive table{width:100%;border-collapse:collapse;font-size:13px}
        .table-responsive thead th{background:rgba(var(--primary-rgb),.04);color:var(--text-muted);font-weight:600;font-size:11.5px;text-transform:uppercase;letter-spacing:.8px;padding:14px 16px;border-bottom:1px solid var(--border-color);white-space:nowrap;position:sticky;top:0}
        .table-responsive tbody tr{transition:background .2s ease;border-bottom:1px solid var(--border-color)}
        .table-responsive tbody tr:last-child{border-bottom:none}
        .table-responsive tbody tr:hover{background:rgba(var(--primary-rgb),.03)}
        .table-responsive tbody td{padding:14px 16px;vertical-align:middle;color:var(--text-dark);font-weight:400}
        .table-responsive tbody td.text-muted-cell{color:var(--text-muted);font-size:12px}
        .plan-thumb{width:52px;height:52px;border-radius:10px;object-fit:cover;border:2px solid var(--border-color);transition:transform .2s ease}
        .plan-thumb:hover{transform:scale(1.15)}
        .no-img-thumb{width:52px;height:52px;border-radius:10px;background:rgba(var(--primary-rgb),.06);display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:18px;border:2px dashed var(--border-color)}
        .status-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:20px;font-size:11.5px;font-weight:600;white-space:nowrap}
        .status-badge.active-badge{background:rgba(76,175,80,.1);color:#2E7D32}
        .status-badge.inactive-badge{background:rgba(244,67,54,.1);color:#C62828}
        .status-badge i{font-size:7px}
        .badge-tag{display:inline-flex;align-items:center;gap:4px;background:linear-gradient(135deg,var(--primary),var(--primary-light));color:#fff;padding:3px 10px;border-radius:6px;font-size:10.5px;font-weight:600;white-space:nowrap}
        .badge-tag.empty{background:rgba(var(--primary-rgb),.08);color:var(--text-muted)}
        .tbl-action-btn{width:32px;height:32px;border-radius:8px;border:1px solid var(--border-color);background:transparent;display:inline-flex;align-items:center;justify-content:center;font-size:13px;cursor:pointer;transition:all .2s ease;color:var(--text-muted)}
        .tbl-action-btn.edit-btn:hover{background:rgba(var(--primary-rgb),.08);border-color:rgba(var(--primary-rgb),.3);color:var(--primary)}
        .tbl-action-btn.delete-btn:hover{background:rgba(244,67,54,.08);border-color:rgba(244,67,54,.3);color:#F44336}
        .tbl-action-btn.activate-btn:hover{background:rgba(76,175,80,.08);border-color:rgba(76,175,80,.3);color:#388E3C}
        .table-card-footer{padding:16px 24px;border-top:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
        .table-card-footer .tcf-info{font-size:12.5px;color:var(--text-muted);font-weight:500}
        .pagination-wrapper{display:flex;align-items:center;gap:4px}
        .pagination-wrapper .pg-btn{min-width:36px;height:36px;border:1px solid var(--border-color);border-radius:var(--radius-sm);background:transparent;color:var(--text-muted);font-family:inherit;font-size:13px;font-weight:500;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:all .2s ease;padding:0 4px}
        .pagination-wrapper .pg-btn:hover:not(:disabled){border-color:var(--primary);color:var(--primary);background:rgba(var(--primary-rgb),.04)}
        .pagination-wrapper .pg-btn.active{background:var(--primary);color:#fff;border-color:var(--primary)}
        .pagination-wrapper .pg-btn:disabled{opacity:.35;cursor:not-allowed}
        .pagination-wrapper .pg-ellipsis{color:var(--text-muted);font-size:14px;padding:0 4px}
        .modal-content{background:var(--card-bg);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border:1px solid var(--border-color);border-radius:var(--radius);box-shadow:var(--shadow-lg);color:var(--text-dark)}
        .modal-header{border-bottom:1px solid var(--border-color);padding:20px 24px}
        .modal-header .modal-title{font-size:17px;font-weight:700;display:flex;align-items:center;gap:10px}
        .modal-header .modal-title i{color:var(--primary)}
        .modal-header .btn-close{filter:invert(.3)}
        [data-theme="dark"] .modal-header .btn-close{filter:invert(.8)}
        .modal-body{padding:24px}
        .modal-footer{border-top:1px solid var(--border-color);padding:16px 24px}
        .form-label-custom{font-size:12.5px;font-weight:600;color:var(--text-muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.3px}
        .form-control-custom,.form-select-custom{width:100%;border:1px solid var(--border-color);border-radius:var(--radius-sm);padding:10px 14px;background:var(--input-bg);color:var(--text-dark);font-family:inherit;font-size:13.5px;transition:all .25s ease;outline:none}
        .form-control-custom:focus,.form-select-custom:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(var(--primary-rgb),.1)}
        textarea.form-control-custom{resize:vertical;min-height:90px}
        .img-upload-area{border:2px dashed var(--border-color);border-radius:var(--radius-sm);padding:20px;text-align:center;cursor:pointer;transition:all .25s ease;position:relative;overflow:hidden;min-height:140px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px}
        .img-upload-area:hover{border-color:var(--primary);background:rgba(var(--primary-rgb),.03)}
        .img-upload-area i{font-size:28px;color:var(--text-muted);opacity:.4}
        .img-upload-area p{font-size:12px;color:var(--text-muted);margin:0}
        .img-upload-area input[type="file"]{position:absolute;inset:0;opacity:0;cursor:pointer}
        .img-preview-box{width:100%;max-height:180px;border-radius:var(--radius-sm);overflow:hidden;display:flex;align-items:center;justify-content:center;background:rgba(var(--primary-rgb),.03);border:1px solid var(--border-color)}
        .img-preview-box img{max-width:100%;max-height:176px;object-fit:contain}
        .img-upload-area.has-preview{padding:8px}
        .btn-primary-custom{background:linear-gradient(135deg,var(--primary),var(--primary-light));border:none;color:#fff;padding:10px 24px;border-radius:var(--radius-sm);font-family:inherit;font-size:13.5px;font-weight:600;cursor:pointer;transition:all .25s ease;display:inline-flex;align-items:center;gap:8px}
        .btn-primary-custom:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(var(--primary-rgb),.35);color:#fff}
        .btn-secondary-custom{background:transparent;border:1px solid var(--border-color);color:var(--text-muted);padding:10px 24px;border-radius:var(--radius-sm);font-family:inherit;font-size:13.5px;font-weight:500;cursor:pointer;transition:all .2s ease}
        .btn-secondary-custom:hover{border-color:var(--text-muted);color:var(--text-dark)}
        .btn-danger-custom{background:linear-gradient(135deg,#C62828,#F44336);border:none;color:#fff;padding:10px 24px;border-radius:var(--radius-sm);font-family:inherit;font-size:13.5px;font-weight:600;cursor:pointer;transition:all .25s ease;display:inline-flex;align-items:center;gap:8px}
        .btn-danger-custom:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(244,67,54,.35);color:#fff}
        .delete-icon-wrapper{width:64px;height:64px;border-radius:50%;background:rgba(244,67,54,.08);display:flex;align-items:center;justify-content:center;margin:0 auto 16px}
        .delete-icon-wrapper i{font-size:28px;color:#F44336}
        .delete-modal-text{text-align:center}
        .delete-modal-text h6{font-size:17px;font-weight:700;margin-bottom:6px}
        .delete-modal-text p{color:var(--text-muted);font-size:13.5px;margin:0}
        .page-alert{position:fixed;top:84px;right:30px;z-index:9999;padding:14px 22px;border-radius:var(--radius-sm);font-size:13.5px;font-weight:500;color:#fff;box-shadow:0 8px 30px rgba(0,0,0,.2);transform:translateX(120%);transition:transform .4s cubic-bezier(.16,1,.3,1);display:flex;align-items:center;gap:10px}
        .page-alert.show{transform:translateX(0)}
        .page-alert.alert-success{background:linear-gradient(135deg,#2E7D32,#43A047)}
        .page-alert.alert-danger{background:linear-gradient(135deg,#C62828,#E53935)}
        .page-alert.alert-warning{background:linear-gradient(135deg,#E65100,#FB8C00)}
        .empty-state{padding:60px 20px;text-align:center}
        .empty-state i{font-size:48px;color:var(--text-muted);opacity:.2;margin-bottom:16px}
        .empty-state h6{font-size:16px;font-weight:600;color:var(--text-muted);margin-bottom:4px}
        .empty-state p{font-size:13px;color:var(--text-muted);opacity:.7;margin:0}
        .settings-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1060;backdrop-filter:blur(4px);opacity:0;transition:opacity .3s ease}
        .settings-overlay.show{display:block;opacity:1}
        .settings-panel{position:fixed;top:0;right:0;width:340px;max-width:90vw;height:100vh;background:#fff;border-left:1px solid var(--border-color);z-index:1070;transform:translateX(100%);transition:transform .35s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;box-shadow:-8px 0 40px rgba(0,0,0,.1)}
        [data-theme="dark"] .settings-panel{background:#1A1025;border-left-color:rgba(156,39,176,.15)}
        .settings-panel.show{transform:translateX(0)}
        .settings-panel-header{display:flex;align-items:center;justify-content:space-between;padding:22px 24px;border-bottom:1px solid var(--border-color)}
        .settings-panel-header h5{font-size:17px;font-weight:700;color:var(--text-dark);margin:0;display:flex;align-items:center;gap:10px}
        .settings-panel-header h5 i{color:var(--primary)}
        .settings-close-btn{width:36px;height:36px;border-radius:10px;border:1px solid var(--border-color);background:transparent;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:14px;cursor:pointer;transition:all .2s}
        .settings-close-btn:hover{background:rgba(244,67,54,.08);border-color:rgba(244,67,54,.2);color:#F44336}
        .settings-body{flex:1;overflow-y:auto;padding:8px 0}
        .settings-section{padding:20px 24px;border-bottom:1px solid var(--border-color)}
        .settings-label{font-size:14px;font-weight:600;color:var(--text-dark);margin-bottom:4px}
        .settings-desc{font-size:12px;color:var(--text-muted);margin-bottom:14px;line-height:1.5}
        .theme-toggle-row{display:flex;align-items:center;justify-content:space-between}
        .theme-toggle-options{display:flex;align-items:center;gap:10px}
        .theme-toggle-options i{font-size:15px}
        .theme-toggle-options .fa-sun{color:#FF9800}
        .theme-toggle-options .fa-moon{color:#5C6BC0}
        .theme-switch{position:relative;width:52px;height:28px;display:inline-block;cursor:pointer}
        .theme-switch input{opacity:0;width:0;height:0;position:absolute}
        .theme-switch .slider{position:absolute;inset:0;background:#E0D4ED;border-radius:28px;transition:all .35s cubic-bezier(.4,0,.2,1)}
        .theme-switch .slider::before{content:'';position:absolute;width:22px;height:22px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:all .35s cubic-bezier(.4,0,.2,1);box-shadow:0 2px 6px rgba(0,0,0,.15)}
        .theme-switch input:checked + .slider{background:var(--primary)}
        .theme-switch input:checked + .slider::before{transform:translateX(24px)}
        .sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1040;backdrop-filter:blur(4px)}
        .sidebar-overlay.show{display:block}
        .activate-overlay{position:fixed;inset:0;z-index:9999;display:none;align-items:center;justify-content:center;background:rgba(30,60,42,0.5);padding:24px;opacity:0;transition:opacity .3s ease}
        .activate-overlay.show{display:flex;opacity:1}
        .activate-modal{background:#fff;border-radius:var(--radius);box-shadow:0 24px 80px rgba(0,0,0,0.35);width:100%;max-width:400px;padding:36px 32px 28px;text-align:center;animation:fadeInUp .4s cubic-bezier(.16,1,.3,1)}
        [data-theme="dark"] .activate-modal{background:#1F1333;border:1px solid rgba(76,175,80,.2)}
        .activate-modal-icon{width:64px;height:64px;border-radius:50%;background:rgba(76,175,80,0.1);display:flex;align-items:center;justify-content:center;margin:0 auto 18px}
        .activate-modal-icon i{font-size:26px;color:#388E3C;animation:actPulse 1s ease-in-out infinite}
        @keyframes actPulse{0%,100%{transform:scale(1)}50%{transform:scale(1.12)}}
        .activate-modal h5{font-size:17px;font-weight:700;color:var(--text-dark);margin:0 0 8px}
        .activate-modal p{font-size:13.5px;color:var(--text-muted);margin:0 0 24px;line-height:1.6}
        .activate-modal p strong{color:#388E3C}
        .activate-modal-actions{display:flex;gap:10px;justify-content:center}
        .btn-cancel-activate{background:transparent;color:var(--text-muted);border:1.5px solid var(--border-color);padding:10px 28px;border-radius:10px;font-size:13.5px;font-weight:600;font-family:'Poppins',sans-serif;cursor:pointer;transition:all .25s ease}
        .btn-cancel-activate:hover{border-color:var(--primary);color:var(--text-dark)}
        .btn-confirm-activate{background:linear-gradient(135deg,#2E7D32,#66BB6A);color:#fff;border:none;padding:10px 28px;border-radius:10px;font-size:13.5px;font-weight:600;font-family:'Poppins',sans-serif;cursor:pointer;transition:all .3s ease;display:inline-flex;align-items:center;gap:8px;box-shadow:0 4px 15px rgba(76,175,80,0.3)}
        .btn-confirm-activate:hover{transform:translateY(-2px);box-shadow:0 6px 25px rgba(76,175,80,0.4);color:#fff}
        @keyframes fadeInUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
        .animate-in{animation:fadeInUp .5s ease forwards;opacity:0}
        .animate-in:nth-child(1){animation-delay:.05s}
        .animate-in:nth-child(2){animation-delay:.1s}
        .animate-in:nth-child(3){animation-delay:.15s}
        .animate-in:nth-child(4){animation-delay:.2s}
        .table-animate{animation:fadeInUp .6s ease .3s forwards;opacity:0}
        ::-webkit-scrollbar{width:6px}
        ::-webkit-scrollbar-track{background:transparent}
        ::-webkit-scrollbar-thumb{background:rgba(var(--primary-rgb),.2);border-radius:10px}
        ::-webkit-scrollbar-thumb:hover{background:rgba(var(--primary-rgb),.35)}
        @media print{.sidebar,.top-header,.table-card-header,.table-card-footer,.tbl-action-btn,.page-alert,.settings-panel,.settings-overlay,.sidebar-overlay,.activate-overlay{display:none!important}.main-content{margin-left:0!important}.dashboard-body{padding:10px!important}.table-card{border:1px solid #ccc!important;box-shadow:none!important}.stat-card{border:1px solid #ccc!important;box-shadow:none!important}body{background:#fff!important;color:#000!important}}
        @media(max-width:991.98px){.sidebar{transform:translateX(-100%)}.sidebar.show{transform:translateX(0)}.main-content{margin-left:0}.sidebar-toggle{display:flex}.dashboard-body{padding:20px 16px 30px}.top-header{padding:0 16px}.header-time{display:none}.notification-dropdown{width:320px;right:-40px}.search-box{width:200px}}
        @media(max-width:767.98px){.table-card-header{flex-direction:column;align-items:stretch}.table-card-header .tch-left,.table-card-header .tch-right{width:100%}.search-box{width:100%}.table-card-footer{flex-direction:column;align-items:center;text-align:center}.notification-dropdown{width:calc(100vw - 32px);right:-60px;max-height:380px}.settings-panel{width:100vw;max-width:100vw}}
        @media(max-width:575.98px){.stat-card .s-value{font-size:24px}.stat-card .s-icon{width:46px;height:46px;font-size:18px;border-radius:13px}.notification-dropdown{width:calc(100vw - 32px);right:-60px;max-height:380px}.settings-panel{width:100vw;max-width:100vw}}
    </style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <div class="settings-overlay" id="settingsOverlay"></div>

    <!-- Hidden form for activate only -->
    <form id="toggleStatusForm" method="POST" style="display:none;">
        <input type="hidden" name="toggle_status" value="1">
        <input type="hidden" name="toggle_id" id="toggleIdInput" value="">
        <input type="hidden" name="toggle_to" id="toggleToInput" value="">
    </form>

    <?php if (!empty($flash_msg)): ?>
    <div class="page-alert alert-<?php echo $flash_type; ?>" id="pageAlert">
        <i class="fas fa-<?php echo $flash_type==='success'?'check-circle':($flash_type==='warning'?'exclamation-triangle':'times-circle'); ?>"></i>
        <?php echo htmlspecialchars($flash_msg); ?>
    </div>
    <?php endif; ?>

    <!-- Activate confirmation only (no deactivate) -->
    <div class="activate-overlay" id="activateOverlay">
        <div class="activate-modal">
            <div class="activate-modal-icon"><i class="fas fa-check-circle"></i></div>
            <h5>Activate Plan</h5>
            <p>Are you sure you want to activate <strong id="actPlanName">—</strong>? It will become visible to users again.</p>
            <div class="activate-modal-actions">
                <button class="btn-cancel-activate" onclick="closeActivateModal()">No, Cancel</button>
                <button class="btn-confirm-activate" onclick="submitActivate()"><i class="fas fa-check"></i> Yes, Activate</button>
            </div>
        </div>
    </div>

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
            <a href="ai_analysis.php"><i class="fas fa-magnifying-glass-chart"></i> Analysis</a>
            <div class="menu-label">Management</div>
            <a href="admin_plans.php" class="active"><i class="fas fa-tags fa-icon"></i> Plans</a>
            <a href="admin_payments.php"><i class="fas fa-credit-card fa-icon"></i> Payments</a>
            <a href="admin_vouchers.php"><i class="fas fa-ticket-alt fa-icon"></i> Vouchers</a>
            <a href="admin_testimonials.php"><i class="fas fa-quote-right fa-icon"></i> Testimonials</a>
            <a href="admin_contacts.php"><i class="fas fa-phone-alt"></i> Contacts</a>
            <a href="login.php" class="logout-btn"><i class="fas fa-sign-out-alt fa-icon"></i> Logout</a>
        </nav>
        <div class="sidebar-footer">
            <div class="admin-info">
                <img src="<?php echo htmlspecialchars($avatar); ?>" class="admin-avatar-img" alt="<?php echo htmlspecialchars($admin_name); ?>" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=Admin&backgroundColor=ede9fe';">
                <div><div class="admin-name"><?php echo htmlspecialchars($admin_name); ?></div><div class="admin-role">Administrator</div></div>
            </div>
        </div>
    </aside>

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
                        <label class="theme-switch"><input type="checkbox" id="themeToggle"><span class="slider"></span></label>
                        <i class="fas fa-moon"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <main class="main-content">
        <header class="top-header">
            <div class="left-section">
                <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
                <h1 class="page-title"><span>Plans</span> Management</h1>
            </div>
            <div class="right-section">
                <span class="header-time" id="headerTime"></span>
                <div class="notification-wrapper">
                    <button class="header-btn" id="notiBtn" aria-label="Notifications">
                        <i class="fas fa-bell"></i>
                        <?php if($unread_count>0):?><span class="noti-badge"><?php echo $unread_count; ?></span><?php endif; ?>
                    </button>
                    <div class="notification-dropdown" id="notiDropdown">
                        <div class="noti-header">
                            <h6>Notifications <span class="count"><?php echo $unread_count; ?></span></h6>
                            <form method="POST" style="display:inline;"><input type="hidden" name="action" value="mark_all_read"><button type="submit" class="mark-read-btn">Mark all read</button></form>
                        </div>
                        <div class="noti-list">
                            <?php if(empty($notifications)):?>
                                <div class="noti-empty"><i class="fas fa-bell-slash"></i>No notifications yet</div>
                            <?php else:?>
                                <?php foreach($notifications as $n):
                                    $ic='default';
                                    if(stripos($n['message'],'assignment')!==false) $ic='assignment';
                                    elseif(stripos($n['message'],'registered')!==false || stripos($n['message'],'user')!==false) $ic='register';
                                ?>
                                <div class="noti-item <?php echo $n['is_read']==0?'unread':''; ?>">
                                    <div class="noti-dot <?php echo $n['is_read']==0?'active':''; ?>"></div>
                                    <div class="noti-icon <?php echo $ic; ?>"><i class="fas fa-<?php echo $ic==='assignment'?'file-alt':($ic==='register'?'user-plus':'info-circle'); ?>"></i></div>
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

        <div class="dashboard-body">
            <div class="row g-3 mb-4">
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card c-purple animate-in">
                        <div class="s-icon"><i class="fas fa-tags"></i></div>
                        <div class="s-value"><?php echo number_format($total_plans); ?></div>
                        <div class="s-label">Total Plans</div>
                        <i class="fas fa-tags s-bg"></i>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card c-green animate-in">
                        <div class="s-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="s-value"><?php echo number_format($active_plans); ?></div>
                        <div class="s-label">Active Plans</div>
                        <i class="fas fa-check-circle s-bg"></i>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card c-red animate-in">
                        <div class="s-icon"><i class="fas fa-times-circle"></i></div>
                        <div class="s-value"><?php echo number_format($inactive_plans); ?></div>
                        <div class="s-label">Inactive Plans</div>
                        <i class="fas fa-times-circle s-bg"></i>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card c-blue animate-in">
                        <div class="s-icon"><i class="fas fa-dollar-sign"></i></div>
                        <div class="s-value"><?php echo 'RM ' . number_format($revenue_potential, 2); ?></div>
                        <div class="s-label">Revenue Potential</div>
                        <i class="fas fa-dollar-sign s-bg"></i>
                    </div>
                </div>
            </div>

            <div class="table-card table-animate">
                <div class="table-card-header">
                    <div class="tch-left">
                        <div class="tch-title"><i class="fas fa-list"></i> All Plans</div>
                        <div class="tch-count"><?php echo number_format($total_filtered); ?> record<?php echo $total_filtered!==1?'s':''; ?></div>
                    </div>
                    <div class="tch-right">
                        <button class="action-btn btn-add" onclick="openAddModal()"><i class="fas fa-plus"></i> Add Plan</button>
                        <form method="GET" action="" id="searchForm" class="d-flex align-items-center gap-2 flex-wrap">
                            <div class="search-box"><i class="fas fa-search"></i><input type="text" name="search" placeholder="Search plans..." value="<?php echo htmlspecialchars($search_term); ?>" id="searchInput"></div>
                            <select name="filter" class="filter-select" id="filterSelect">
                                <option value="" <?php echo empty($filter_status)?'selected':''; ?>>All Status</option>
                                <option value="Active" <?php echo $filter_status==='Active'?'selected':''; ?>>Active</option>
                                <option value="Inactive" <?php echo $filter_status==='Inactive'?'selected':''; ?>>Inactive</option>
                            </select>
                            <input type="hidden" name="page" value="1" id="hiddenPage">
                        </form>
                        <button class="action-btn btn-refresh" onclick="location.reload()"><i class="fas fa-sync-alt"></i></button>
                        <button class="action-btn btn-pdf" onclick="exportPDF()"><i class="fas fa-file-pdf"></i> PDF</button>
                        <button class="action-btn btn-excel" onclick="exportExcel()"><i class="fas fa-file-excel"></i> Excel</button>
                        <button class="action-btn btn-print" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="plansTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Image</th>
                                <th>Plan Name</th>
                                <th>Price</th>
                                <th>Assignment Discount</th>
                                <th>Duration</th>
                                <th>Badge</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if(empty($plans)):?>
                            <tr><td colspan="9"><div class="empty-state"><i class="fas fa-tags"></i><h6>No Plans Found</h6><p>Adjust your search or add a new plan.</p></div></td></tr>
                        <?php else:
                            foreach($plans as $p):
                                $pid = (int)$p['plan_id'];
                                $pname = htmlspecialchars($p['plan_name']);
                                $pprice = number_format((float)$p['price'], 2);
                                $pdisc = number_format((float)($p['assignment_discount'] ?? 0), 0);
                                $pdur = htmlspecialchars($p['duration'] ?? '—');
                                $pbadge = htmlspecialchars($p['badge'] ?? '');
                                $pstat = $p['status'];
                                $pimg = $p['plan_image'] ?? '';
                                $bc = $pstat==='Active' ? 'active-badge' : 'inactive-badge';
                                $bi = $pstat==='Active' ? 'check' : 'times';
                                $sd = $pstat;
                                $thumb_html = '';
                                if(!empty($pimg) && file_exists($upload_dir . $pimg)){
                                    $thumb_html = '<img src="'.$upload_dir.htmlspecialchars($pimg).'" class="plan-thumb" alt="'.htmlspecialchars($pname).'">';
                                } else {
                                    $thumb_html = '<div class="no-img-thumb"><i class="fas fa-image"></i></div>';
                                }
                                $badge_html = !empty($pbadge) ? '<span class="badge-tag">'.$pbadge.'</span>' : '<span class="badge-tag empty">None</span>';
                        ?>
                            <tr>
                                <td class="text-muted-cell">#<?php echo $pid; ?></td>
                                <td><?php echo $thumb_html; ?></td>
                                <td><strong><?php echo $pname; ?></strong></td>
                                <td><strong style="color:var(--primary);">RM <?php echo $pprice; ?></strong></td>
                                <td><?php echo $pdisc > 0 ? '<span class="badge-tag" style="background:linear-gradient(135deg,#22c55e,#4ade80);">'.$pdisc.'% off</span>' : '<span class="badge-tag empty">None</span>'; ?></td>
                                <td><?php echo $pdur; ?></td>
                                <td><?php echo $badge_html; ?></td>
                                <td><span class="status-badge <?php echo $bc; ?>"><i class="fas fa-<?php echo $bi; ?>"></i> <?php echo $sd; ?></span></td>
                                <td>
                                    <div class="d-flex align-items-center gap-1">
                                        <button class="tbl-action-btn edit-btn" onclick="editPlan(<?php echo $pid; ?>)" title="Edit"><i class="fas fa-pen"></i></button>
                                        <?php if($pstat==='Inactive'):?>
                                        <button class="tbl-action-btn activate-btn" onclick="confirmActivate(<?php echo $pid; ?>,'<?php echo addslashes($pname); ?>')" title="Activate"><i class="fas fa-check"></i></button>
                                        <?php endif; ?>
                                        <button class="tbl-action-btn delete-btn" onclick="confirmDelete(<?php echo $pid; ?>,'<?php echo addslashes($pname); ?>')" title="Delete"><i class="fas fa-trash-alt"></i></button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if($total_filtered>0):?>
                <div class="table-card-footer">
                    <div class="tcf-info">Showing <?php echo ($offset+1); ?>–<?php echo min($offset+$per_page,$total_filtered); ?> of <?php echo number_format($total_filtered); ?></div>
                    <div class="pagination-wrapper">
                        <?php
                        if($current_page>1) echo '<button class="pg-btn" onclick="goToPage('.($current_page-1).')"><i class="fas fa-chevron-left" style="font-size:11px;"></i></button>';
                        else echo '<button class="pg-btn" disabled><i class="fas fa-chevron-left" style="font-size:11px;"></i></button>';
                        $start_p = max(1, $current_page-2);
                        $end_p = min($total_pages, $current_page+2);
                        if($start_p > 1) echo '<span class="pg-ellipsis">...</span>';
                        for($pg=$start_p; $pg<=$end_p; $pg++){
                            echo '<button class="pg-btn '.($pg==$current_page?'active':'').'" onclick="goToPage('.$pg.')">'.$pg.'</button>';
                        }
                        if($end_p < $total_pages) echo '<span class="pg-ellipsis">...</span>';
                        if($current_page < $total_pages) echo '<button class="pg-btn" onclick="goToPage('.($current_page+1).')"><i class="fas fa-chevron-right" style="font-size:11px;"></i></button>';
                        else echo '<button class="pg-btn" disabled><i class="fas fa-chevron-right" style="font-size:11px;"></i></button>';
                        ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Add Plan Modal -->
    <div class="modal fade" id="addModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Add New Plan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label-custom">Plan Name *</label>
                                <input type="text" name="plan_name" class="form-control-custom" placeholder="e.g. Premium Plan" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-custom">Price (RM) *</label>
                                <input type="number" name="price" class="form-control-custom" value="0" min="0" step="0.01" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-custom">Duration</label>
                                <input type="text" name="duration" class="form-control-custom" placeholder="e.g. 30 Days, 1 Year">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-custom">Assignment Discount (%)</label>
                                <input type="number" name="assignment_discount" class="form-control-custom" value="0" min="0" max="100" step="1" placeholder="e.g. 50 for half price">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-custom">Badge</label>
                                <input type="text" name="badge" class="form-control-custom" placeholder="e.g. Popular, Best Value">
                            </div>
                            <div class="col-12">
                                <label class="form-label-custom">Description *</label>
                                <textarea name="description" class="form-control-custom" placeholder="Plan description..." required></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label-custom">Features</label>
                                <textarea name="features" class="form-control-custom" placeholder="One feature per line..." style="min-height:70px;"></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-custom">Button Text</label>
                                <input type="text" name="button_text" class="form-control-custom" value="Subscribe">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-custom">Status</label>
                                <select name="status" class="form-select-custom">
                                    <option value="Active" selected>Active</option>
                                    <option value="Inactive">Inactive</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label-custom">Plan Image</label>
                                <div class="img-upload-area" id="addImgArea">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <p>Click or drag to upload (JPG, PNG, WEBP — max 5MB)</p>
                                    <input type="file" name="plan_image" accept="image/jpeg,image/png,image/webp" onchange="previewImg(this,'addImgPreview','addImgArea')">
                                </div>
                                <div class="img-preview-box mt-2" id="addImgPreview" style="display:none;"></div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="add_plan" class="btn-primary-custom"><i class="fas fa-plus"></i> Add Plan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Plan Modal -->
    <div class="modal fade" id="editModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Plan</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="edit_id" id="editId">
                    <input type="hidden" name="edit_plan" value="1">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label-custom">Plan Name *</label>
                                <input type="text" name="plan_name" id="editName" class="form-control-custom" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-custom">Price (RM) *</label>
                                <input type="number" name="price" id="editPrice" class="form-control-custom" min="0" step="0.01" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-custom">Duration</label>
                                <input type="text" name="duration" id="editDuration" class="form-control-custom">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-custom">Assignment Discount (%)</label>
                                <input type="number" name="assignment_discount" id="editDiscount" class="form-control-custom" min="0" max="100" step="1" placeholder="e.g. 50 for half price">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-custom">Badge</label>
                                <input type="text" name="badge" id="editBadge" class="form-control-custom">
                            </div>
                            <div class="col-12">
                                <label class="form-label-custom">Description *</label>
                                <textarea name="description" id="editDesc" class="form-control-custom" required></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label-custom">Features</label>
                                <textarea name="features" id="editFeatures" class="form-control-custom" style="min-height:70px;"></textarea>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-custom">Button Text</label>
                                <input type="text" name="button_text" id="editBtnText" class="form-control-custom">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-custom">Status</label>
                                <select name="status" id="editStatus" class="form-select-custom">
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label-custom">Plan Image</label>
                                <div class="img-upload-area" id="editImgArea">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <p>Click or drag to upload new image (leave empty to keep current)</p>
                                    <input type="file" name="plan_image" accept="image/jpeg,image/png,image/webp" onchange="previewImg(this,'editImgPreview','editImgArea')">
                                </div>
                                <div class="img-preview-box mt-2" id="editImgPreview" style="display:none;"></div>
                                <input type="hidden" name="current_image" id="editCurrentImg" value="">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-primary-custom"><i class="fas fa-save"></i> Update Plan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-body" style="padding:32px 24px;">
                    <div class="delete-icon-wrapper"><i class="fas fa-trash-alt"></i></div>
                    <div class="delete-modal-text">
                        <h6>Delete Plan?</h6>
                        <p>This will permanently delete <strong id="deletePlanName"></strong>.</p>
                    </div>
                </div>
                <div class="modal-footer justify-content-center" style="border:none;padding-top:0;">
                    <form method="POST">
                        <input type="hidden" name="delete_id" id="deleteIdInput">
                        <input type="hidden" name="delete_plan" value="1">
                        <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-danger-custom"><i class="fas fa-trash-alt"></i> Delete</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    (function(){
        const planData = <?php echo json_encode(array_column($plans, null, 'plan_id')); ?>;
        const uploadDir = '<?php echo $upload_dir; ?>';

        // Flash alert
        const alertEl = document.getElementById('pageAlert');
        if(alertEl){ setTimeout(()=>{ alertEl.classList.add('show'); }, 300); setTimeout(()=>{ alertEl.classList.remove('show'); }, 4000); }

        // Header time
        function updateTime(){
            const now = new Date();
            const opts = {weekday:'short',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'};
            document.getElementById('headerTime').textContent = now.toLocaleDateString('en-US',opts);
        }
        updateTime(); setInterval(updateTime, 30000);

        // Theme
        const html = document.documentElement;
        const toggle = document.getElementById('themeToggle');
        const saved = localStorage.getItem('theme');
        if(saved){ html.setAttribute('data-theme', saved); if(saved==='dark') toggle.checked = true; }
        toggle.addEventListener('change', function(){
            const t = this.checked ? 'dark' : 'light';
            html.setAttribute('data-theme', t);
            localStorage.setItem('theme', t);
        });

        // Settings panel
        const settingsPanel = document.getElementById('settingsPanel');
        const settingsOverlay = document.getElementById('settingsOverlay');
        document.getElementById('settingsBtn').addEventListener('click', ()=>{ settingsPanel.classList.add('show'); settingsOverlay.classList.add('show'); });
        function closeSettings(){ settingsPanel.classList.remove('show'); settingsOverlay.classList.remove('show'); }
        document.getElementById('settingsCloseBtn').addEventListener('click', closeSettings);
        settingsOverlay.addEventListener('click', closeSettings);

        // Sidebar
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        document.getElementById('sidebarToggle').addEventListener('click', ()=>{ sidebar.classList.toggle('show'); sidebarOverlay.classList.toggle('show'); });
        sidebarOverlay.addEventListener('click', ()=>{ sidebar.classList.remove('show'); sidebarOverlay.classList.remove('show'); });

        // Notifications
        const notiBtn = document.getElementById('notiBtn');
        const notiDropdown = document.getElementById('notiDropdown');
        notiBtn.addEventListener('click', (e)=>{ e.stopPropagation(); notiDropdown.classList.toggle('show'); });
        document.addEventListener('click', (e)=>{ if(!notiDropdown.contains(e.target) && e.target !== notiBtn){ notiDropdown.classList.remove('show'); }});
        notiDropdown.addEventListener('click', (e)=>{ e.stopPropagation(); });

        // Search & Filter
        const searchInput = document.getElementById('searchInput');
        const filterSelect = document.getElementById('filterSelect');
        let searchTimer;
        searchInput.addEventListener('input', ()=>{
            clearTimeout(searchTimer);
            searchTimer = setTimeout(()=>{ document.getElementById('hiddenPage').value = '1'; document.getElementById('searchForm').submit(); }, 600);
        });
        filterSelect.addEventListener('change', ()=>{ document.getElementById('hiddenPage').value = '1'; document.getElementById('searchForm').submit(); });

        // Pagination
        window.goToPage = function(p){
            const url = new URL(window.location.href);
            url.searchParams.set('page', p);
            window.location.href = url.toString();
        };

        // Image preview
        window.previewImg = function(input, previewId, areaId){
            const preview = document.getElementById(previewId);
            const area = document.getElementById(areaId);
            if(input.files && input.files[0]){
                const reader = new FileReader();
                reader.onload = function(e){
                    preview.innerHTML = '<img src="'+e.target.result+'" alt="Preview">';
                    preview.style.display = 'flex';
                    area.classList.add('has-preview');
                };
                reader.readAsDataURL(input.files[0]);
            } else {
                preview.innerHTML = '';
                preview.style.display = 'none';
                area.classList.remove('has-preview');
            }
        };

        // Add Modal
        window.openAddModal = function(){
            const form = document.querySelector('#addModal form');
            form.reset();
            document.getElementById('addImgPreview').style.display = 'none';
            document.getElementById('addImgPreview').innerHTML = '';
            document.getElementById('addImgArea').classList.remove('has-preview');
            const m = new bootstrap.Modal(document.getElementById('addModal'));
            m.show();
        };

        // Edit Modal
        window.editPlan = function(id){
            const p = planData[id];
            if(!p) return;
            document.getElementById('editId').value = p.plan_id;
            document.getElementById('editName').value = p.plan_name;
            document.getElementById('editPrice').value = p.price;
            document.getElementById('editDuration').value = p.duration || '';
            document.getElementById('editDiscount').value = p.assignment_discount || 0;
            document.getElementById('editBadge').value = p.badge || '';
            document.getElementById('editDesc').value = p.description || '';
            document.getElementById('editFeatures').value = p.features || '';
            document.getElementById('editBtnText').value = p.button_text || 'Subscribe';
            document.getElementById('editStatus').value = p.status;
            document.getElementById('editCurrentImg').value = p.plan_image || '';
            const preview = document.getElementById('editImgPreview');
            const area = document.getElementById('editImgArea');
            if(p.plan_image){
                preview.innerHTML = '<img src="'+uploadDir+p.plan_image+'" alt="Current">';
                preview.style.display = 'flex';
                area.classList.add('has-preview');
            } else {
                preview.innerHTML = '';
                preview.style.display = 'none';
                area.classList.remove('has-preview');
            }
            const m = new bootstrap.Modal(document.getElementById('editModal'));
            m.show();
        };

        // Delete Modal
        window.confirmDelete = function(id, name){
            document.getElementById('deleteIdInput').value = id;
            document.getElementById('deletePlanName').textContent = name;
            const m = new bootstrap.Modal(document.getElementById('deleteModal'));
            m.show();
        };

        // Activate only — no deactivate
        let pendingActivateId = null;

        window.confirmActivate = function(id, name){
            pendingActivateId = id;
            document.getElementById('actPlanName').textContent = name;
            const overlay = document.getElementById('activateOverlay');
            overlay.style.display = 'flex';
            requestAnimationFrame(()=>{ overlay.classList.add('show'); });
        };

        window.closeActivateModal = function(){
            const overlay = document.getElementById('activateOverlay');
            overlay.classList.remove('show');
            setTimeout(()=>{ overlay.style.display = 'none'; }, 300);
            pendingActivateId = null;
        };

        window.submitActivate = function(){
            if(pendingActivateId === null) return;
            document.getElementById('toggleIdInput').value = pendingActivateId;
            document.getElementById('toggleToInput').value = 'Active';
            document.getElementById('toggleStatusForm').submit();
        };

        document.getElementById('activateOverlay').addEventListener('click', function(e){
            if(e.target === this) closeActivateModal();
        });

        // Export
        window.exportPDF = function(){
            const table = document.getElementById('plansTable');
            const win = window.open('','_blank');
            win.document.write('<html><head><title>Plans Report</title><style>body{font-family:Arial,sans-serif;padding:20px}table{width:100%;border-collapse:collapse;margin-top:20px}th,td{border:1px solid #ddd;padding:10px;text-align:left}th{background:#6A0DAD;color:#fff}h2{color:#6A0DAD}img{max-width:50px;max-height:50px;border-radius:6px}</style></head><body><h2>Plans Management Report</h2><p>Generated: '+new Date().toLocaleString()+'</p>');
            win.document.write(table.outerHTML);
            win.document.write('</body></html>');
            win.document.close();
            win.print();
        };

        window.exportExcel = function(){
            const table = document.getElementById('plansTable');
            let html = '<table border="1"><tr><th>ID</th><th>Plan Name</th><th>Price (RM)</th><th>Duration</th><th>Badge</th><th>Status</th></tr>';
            const rows = table.querySelectorAll('tbody tr');
            rows.forEach(row=>{
                if(row.querySelector('.empty-state')) return;
                const cells = row.querySelectorAll('td');
                if(cells.length < 7) return;
                html += '<tr>';
                html += '<td>'+cells[0].innerText.trim()+'</td>';
                html += '<td>'+cells[2].innerText.trim()+'</td>';
                html += '<td>'+cells[3].innerText.trim()+'</td>';
                html += '<td>'+cells[4].innerText.trim()+'</td>';
                html += '<td>'+cells[5].innerText.trim()+'</td>';
                html += '<td>'+cells[6].innerText.trim()+'</td>';
                html += '</tr>';
            });
            html += '</table>';
            const blob = new Blob([html], {type:'application/vnd.ms-excel'});
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'plans_'+new Date().toISOString().slice(0,10)+'.xls';
            link.click();
        };

        // Escape key
        document.addEventListener('keydown', function(e){
            if(e.key === 'Escape'){
                closeActivateModal();
                closeSettings();
                notiDropdown.classList.remove('show');
            }
        });
    })();
    </script>
</body>
</html>