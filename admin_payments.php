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
if (!isset($admin_id)) { header('location: login.php'); exit; }

 $alert_msg = $_SESSION['alert_msg'] ?? '';
 $alert_type = $_SESSION['alert_type'] ?? '';
unset($_SESSION['alert_msg'], $_SESSION['alert_type']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_all_read') {
    mysqli_query($conn, "UPDATE notifications SET is_read = 1 WHERE user_id IS NULL AND is_read = 0");
    header("Location: " . basename($_SERVER['PHP_SELF']));
    exit;
}

 $admin_query = mysqli_query($conn, "SELECT user_id, name, email, avatar, created_at FROM users WHERE user_id = '$admin_id' AND user_type = 'admin'") or die(mysqli_error($conn));
if (mysqli_num_rows($admin_query) === 0) { die("Admin account not found."); }
 $admin_data = mysqli_fetch_assoc($admin_query);
 $admin_name = $admin_data['name'] ?? 'Admin';
 $db_avatar = $admin_data['avatar'] ?? 'default.png';

if (!empty($db_avatar) && $db_avatar !== 'default.png') {
    if (filter_var($db_avatar, FILTER_VALIDATE_URL)) { $avatar = $db_avatar; }
    else { $avatar = "https://api.dicebear.com/7.x/avataaars/svg?seed=" . urlencode($db_avatar) . "&backgroundColor=ede9fe"; }
} else { $avatar = "https://api.dicebear.com/7.x/avataaars/svg?seed=Admin&backgroundColor=ede9fe"; }

function time_ago($datetime) {
    $now = new DateTime(); $ago = new DateTime($datetime); $diff = $now->diff($ago);
    if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    if ($diff->i > 0) return $diff->i . ' min ago';
    return 'Just now';
}

 $unread_count = 0; $notifications = [];
 $noti_query = mysqli_query($conn, "SELECT * FROM notifications WHERE user_id IS NULL ORDER BY created_at DESC LIMIT 30");
if (mysqli_num_rows($noti_query) > 0) {
    while ($row = mysqli_fetch_assoc($noti_query)) {
        $notifications[] = $row;
        if ($row['is_read'] == 0) $unread_count++;
    }
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csv_where = "";
    if (!empty($_GET['search'])) {
        $s = mysqli_real_escape_string($conn, trim($_GET['search']));
        $csv_where = "WHERE pt.id LIKE '%$s%' OR pt.order_id LIKE '%$s%' OR pt.user_id LIKE '%$s%' OR pt.voucher_code LIKE '%$s%' OR pt.toyyibpay_payment_method LIKE '%$s%' OR pt.status LIKE '%$s%' OR u.name LIKE '%$s%' OR pt.item_name LIKE '%$s%'";
    }
    if (!empty($_GET['status']) && $_GET['status'] !== 'All') {
        $st = mysqli_real_escape_string($conn, $_GET['status']);
        $csv_where .= ($csv_where ? " AND " : "WHERE ") . "pt.status = '$st'";
    }
    $csv_query = mysqli_query($conn, "SELECT pt.id, pt.order_id, COALESCE(u.name,'Unknown') AS user_name, pt.type, pt.item_name, pt.original_amount, pt.discount_amount, pt.final_amount, pt.voucher_code, pt.toyyibpay_payment_method, pt.status, pt.created_at FROM payment_transactions pt LEFT JOIN users u ON pt.user_id = u.user_id $csv_where ORDER BY pt.id DESC");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=payment_transactions_' . date('Y-m-d_His') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Order ID', 'User Name', 'Type', 'Item', 'Original Amount', 'Discount', 'Final Amount', 'Voucher Code', 'Payment Method', 'Status', 'Date']);
    while ($r = mysqli_fetch_assoc($csv_query)) {
        fputcsv($output, [$r['id'], $r['order_id'], $r['user_name'], $r['type'], $r['item_name'], $r['original_amount'], $r['discount_amount'], $r['final_amount'], $r['voucher_code'], $r['toyyibpay_payment_method'], $r['status'], $r['created_at']]);
    }
    fclose($output);
    exit;
}

if (isset($_POST['update_status'])) {
    $upd_id = (int)$_POST['upd_payment_id'];
    $new_status = trim($_POST['new_status']);
    $allowed = ['paid', 'pending', 'failed', 'expired'];
    if ($upd_id > 0 && in_array($new_status, $allowed)) {
        $u = mysqli_query($conn, "UPDATE payment_transactions SET status = '$new_status' WHERE id = $upd_id");
        if ($u && mysqli_affected_rows($conn) > 0) {
            $_SESSION['alert_msg'] = "Transaction #$upd_id marked as " . ucfirst($new_status) . ".";
            $_SESSION['alert_type'] = 'success';
        } elseif ($u) {
            $_SESSION['alert_msg'] = "Status was already " . ucfirst($new_status) . ". No changes made.";
            $_SESSION['alert_type'] = 'danger';
        } else {
            $_SESSION['alert_msg'] = 'Failed to update status. DB error.';
            $_SESSION['alert_type'] = 'danger';
        }
    } else {
        $_SESSION['alert_msg'] = 'Invalid status or payment ID.';
        $_SESSION['alert_type'] = 'danger';
    }
    header("Location: admin_payments.php");
    exit;
}

if (isset($_POST['delete_id'])) {
    $del_id = (int)$_POST['delete_id'];
    $file_q = mysqli_query($conn, "SELECT receipt_file FROM payment_transactions WHERE id = $del_id");
    if ($file_q && mysqli_num_rows($file_q) > 0) {
        $file_row = mysqli_fetch_assoc($file_q);
        if (!empty($file_row['receipt_file']) && file_exists($file_row['receipt_file'])) {
            unlink($file_row['receipt_file']);
        }
    }
    $d = mysqli_query($conn, "DELETE FROM payment_transactions WHERE id = $del_id");
    if ($d) { $_SESSION['alert_msg'] = 'Transaction deleted successfully.'; $_SESSION['alert_type'] = 'success'; }
    else { $_SESSION['alert_msg'] = 'Failed to delete transaction.'; $_SESSION['alert_type'] = 'danger'; }
    header("Location: admin_payments.php"); exit;
}

 $total_payments = mysqli_num_rows(mysqli_query($conn, "SELECT 1 FROM payment_transactions"));
 $total_paid = mysqli_num_rows(mysqli_query($conn, "SELECT 1 FROM payment_transactions WHERE status = 'paid'"));
 $total_pending = mysqli_num_rows(mysqli_query($conn, "SELECT 1 FROM payment_transactions WHERE status = 'pending'"));
 $total_failed = mysqli_num_rows(mysqli_query($conn, "SELECT 1 FROM payment_transactions WHERE status = 'failed'"));
 $total_expired = mysqli_num_rows(mysqli_query($conn, "SELECT 1 FROM payment_transactions WHERE status = 'expired'"));
 $rev_res = mysqli_query($conn, "SELECT COALESCE(SUM(final_amount), 0) AS tr FROM payment_transactions WHERE status = 'paid'");
 $total_revenue = $rev_res ? $rev_res->fetch_assoc()['tr'] : 0;

 $dist = ['paid' => 0, 'pending' => 0, 'failed' => 0, 'expired' => 0];
 $dr = mysqli_query($conn, "SELECT status, COUNT(*) AS c FROM payment_transactions GROUP BY status");
while ($drr = mysqli_fetch_assoc($dr)) { if (isset($dist[$drr['status']])) $dist[$drr['status']] = (int)$drr['c']; }

 $search = isset($_GET['search']) ? trim($_GET['search']) : '';
 $status_filter = isset($_GET['status']) ? $_GET['status'] : 'All';
 $per_page = 10;
 $page = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

 $where = "";
if (!empty($search)) {
    $s = mysqli_real_escape_string($conn, $search);
    $where = "WHERE pt.id LIKE '%$s%' OR pt.order_id LIKE '%$s%' OR pt.user_id LIKE '%$s%' OR pt.voucher_code LIKE '%$s%' OR pt.toyyibpay_payment_method LIKE '%$s%' OR pt.status LIKE '%$s%' OR u.name LIKE '%$s%' OR pt.item_name LIKE '%$s%' OR pt.toyyibpay_billcode LIKE '%$s%'";
}
if ($status_filter !== 'All') {
    $sf = mysqli_real_escape_string($conn, $status_filter);
    $where .= ($where ? " AND " : "WHERE ") . "pt.status = '$sf'";
}

 $count_q = mysqli_query($conn, "SELECT COUNT(*) AS total FROM payment_transactions pt LEFT JOIN users u ON pt.user_id = u.user_id $where");
 $total_records = $count_q->fetch_assoc()['total'];
 $total_pages = ceil($total_records / $per_page);
 $offset = ($page - 1) * $per_page;

 $payments_query = mysqli_query($conn, "SELECT pt.*, COALESCE(u.name, 'Unknown User') AS user_name, COALESCE(u.email, '') AS user_email FROM payment_transactions pt LEFT JOIN users u ON pt.user_id = u.user_id $where ORDER BY pt.id DESC LIMIT $offset, $per_page");

 $view_payment = null;
if (isset($_GET['view_id'])) {
    $vid = (int)$_GET['view_id'];
    $vr = mysqli_query($conn, "SELECT pt.*, COALESCE(u.name, 'Unknown User') AS user_name, COALESCE(u.email, '') AS user_email FROM payment_transactions pt LEFT JOIN users u ON pt.user_id = u.user_id WHERE pt.id = $vid");
    if (mysqli_num_rows($vr) > 0) $view_payment = mysqli_fetch_assoc($vr);
}

 $edit_payment = null;
if (isset($_GET['status_id'])) {
    $eid = (int)$_GET['status_id'];
    $er = mysqli_query($conn, "SELECT * FROM payment_transactions WHERE id = $eid");
    if (mysqli_num_rows($er) > 0) $edit_payment = mysqli_fetch_assoc($er);
}

 $all_payments_js = [];
 $all_js_q = mysqli_query($conn, "SELECT pt.*, COALESCE(u.name, 'Unknown User') AS user_name, COALESCE(u.email, '') AS user_email FROM payment_transactions pt LEFT JOIN users u ON pt.user_id = u.user_id ORDER BY pt.id DESC");
while ($aj = mysqli_fetch_assoc($all_js_q)) { $all_payments_js[] = $aj; }

 $conn->close();

function pay_method_icon($m) {
    $ml = strtolower($m ?? '');
    if (strpos($ml, 'credit') !== false || strpos($ml, 'card') !== false) return 'fa-credit-card';
    if (strpos($ml, 'bank') !== false) return 'fa-university';
    if (strpos($ml, 'wallet') !== false) return 'fa-wallet';
    if (strpos($ml, 'cash') !== false) return 'fa-money-bill';
    if (strpos($ml, 'fps') !== false) return 'fa-qrcode';
    return 'fa-money-check';
}
function status_badge_class($s) {
    if ($s === 'paid') return 'status-paid';
    if ($s === 'pending') return 'status-pending';
    if ($s === 'failed') return 'status-failed';
    if ($s === 'expired') return 'status-expired';
    return '';
}
function status_label($s) { return ucfirst($s ?? 'unknown'); }
function build_url($overrides = []) {
    $params = [];
    if (!empty($search) || isset($overrides['search'])) $params[] = 'search=' . urlencode($overrides['search'] ?? $search);
    if ($status_filter !== 'All' || isset($overrides['status'])) $params[] = 'status=' . urlencode($overrides['status'] ?? $status_filter);
    if (isset($overrides['page'])) $params[] = 'page=' . (int)$overrides['page'];
    elseif ($page > 1 && !isset($overrides['page'])) $params[] = 'page=' . $page;
    return 'admin_payments.php' . ($params ? '?' . implode('&', $params) : '');
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Payments — AI Assignment Checker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>window.Chart||document.write('<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"><\/script>')</script>
    <style>
        :root{--primary:#6A0DAD;--primary-light:#9C27B0;--primary-dark:#4A0072;--primary-rgb:106,13,173;--secondary-rgb:156,39,176;--bg:#F3F0F7;--card-bg:rgba(255,255,255,0.78);--sidebar-width:260px;--header-height:70px;--text-dark:#2D1B4E;--text-muted:#7B6B8D;--border-color:rgba(106,13,173,0.08);--input-bg:#FFFFFF;--shadow-sm:0 2px 8px rgba(106,13,173,0.06);--shadow-md:0 4px 20px rgba(106,13,173,0.1);--shadow-lg:0 8px 40px rgba(106,13,173,0.15);--radius:16px;--radius-sm:10px}
        [data-theme="dark"]{--bg:#110B18;--card-bg:rgba(32,18,52,0.82);--text-dark:#E8E0F0;--text-muted:#9B8DB5;--border-color:rgba(156,39,176,0.12);--input-bg:rgba(45,27,78,0.6);--shadow-sm:0 2px 8px rgba(0,0,0,0.25);--shadow-md:0 4px 20px rgba(0,0,0,0.35);--shadow-lg:0 8px 40px rgba(0,0,0,0.45)}
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Poppins',sans-serif;background:var(--bg);color:var(--text-dark);overflow-x:hidden;min-height:100vh;transition:background .35s ease,color .35s ease}

        .sidebar{position:fixed;top:0;left:0;width:var(--sidebar-width);height:100vh;background:linear-gradient(180deg,var(--primary-dark) 0%,var(--primary) 50%,var(--primary-light) 100%);z-index:1050;transition:transform .35s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;box-shadow:4px 0 30px rgba(106,13,173,0.3)}
        .sidebar-brand{padding:24px 20px;border-bottom:1px solid rgba(255,255,255,0.1);display:flex;align-items:center;gap:12px}
        .sidebar-brand .brand-icon{width:52px;height:60px;background:rgba(255,255,255,0.15);border-radius:12px;overflow:hidden;backdrop-filter:blur(10px);flex-shrink:0}
        .sidebar-brand .brand-icon img{width:100%;height:100%;object-fit:cover}
        .sidebar-brand h5{color:#fff;font-weight:700;font-size:15px;margin:0;line-height:1.3}
        .sidebar-brand small{color:rgba(255,255,255,0.6);font-size:11px}
        .sidebar-menu{flex:1;padding:16px 12px;overflow-y:auto}
        .sidebar-menu .menu-label{color:rgba(255,255,255,0.4);font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:1.5px;padding:12px 14px 8px}
        .sidebar-menu a{display:flex;align-items:center;gap:12px;padding:11px 14px;color:rgba(255,255,255,0.7);text-decoration:none;border-radius:var(--radius-sm);font-size:13.5px;font-weight:500;transition:all .25s ease;margin-bottom:2px;position:relative}
        .sidebar-menu a i.fa-icon{width:20px;text-align:center;font-size:15px}
        .sidebar-menu a:hover{background:rgba(255,255,255,0.1);color:#fff;transform:translateX(4px)}
        .sidebar-menu a.active{background:rgba(255,255,255,0.18);color:#fff;box-shadow:0 4px 15px rgba(0,0,0,0.15)}
        .sidebar-menu a.active::before{content:'';position:absolute;left:0;top:50%;transform:translateY(-50%);width:4px;height:60%;background:#fff;border-radius:0 4px 4px 0}
        .sidebar-menu a.logout-btn{color:#FF6B8A;margin-top:20px;border-top:1px solid rgba(255,255,255,0.08);padding-top:16px}
        .sidebar-menu a.logout-btn:hover{background:rgba(255,107,138,0.12);color:#FF6B8A;transform:translateX(4px)}
        .sidebar-footer{padding:16px 20px;border-top:1px solid rgba(255,255,255,0.1)}
        .sidebar-footer .admin-info{display:flex;align-items:center;gap:10px}
        .sidebar-footer .admin-avatar-img{width:38px;height:38px;border-radius:10px;border:2px solid rgba(255,255,255,0.2);background:rgba(255,255,255,0.08);object-fit:cover;flex-shrink:0}
        .sidebar-footer .admin-name{color:#fff;font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:150px}
        .sidebar-footer .admin-role{color:rgba(255,255,255,0.5);font-size:11px}

        .main-content{margin-left:var(--sidebar-width);min-height:100vh;transition:margin-left .35s cubic-bezier(.4,0,.2,1)}
        .top-header{height:var(--header-height);background:rgba(255,255,255,0.8);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border-bottom:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;padding:0 30px;position:sticky;top:0;z-index:1000;transition:background .35s ease}
        [data-theme="dark"] .top-header{background:rgba(17,11,24,0.88)}
        .top-header .left-section{display:flex;align-items:center;gap:16px}
        .sidebar-toggle{display:none;background:none;border:none;font-size:20px;color:var(--primary);cursor:pointer;padding:6px;border-radius:8px;transition:background .2s}
        .sidebar-toggle:hover{background:rgba(var(--primary-rgb),0.08)}
        .top-header .page-title{font-size:18px;font-weight:700;color:var(--text-dark);transition:color .35s ease}
        .top-header .page-title span{color:var(--primary)}
        .top-header .right-section{display:flex;align-items:center;gap:10px}
        .header-btn{width:40px;height:40px;border-radius:12px;border:1px solid var(--border-color);background:#fff;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:16px;cursor:pointer;transition:all .25s ease;position:relative}
        [data-theme="dark"] .header-btn{background:rgba(45,27,78,0.5);border-color:var(--border-color);color:var(--text-muted)}
        .header-btn:hover{border-color:var(--primary);color:var(--primary);box-shadow:var(--shadow-sm)}
        .header-time{font-size:12.5px;color:var(--text-muted);font-weight:500;background:rgba(var(--primary-rgb),0.05);padding:6px 14px;border-radius:8px}
        [data-theme="dark"] .header-time{background:rgba(156,39,176,0.08)}

        .notification-wrapper{position:relative}
        .noti-badge{position:absolute;top:6px;right:6px;min-width:18px;height:18px;background:#FF4757;color:#fff;font-size:10px;font-weight:700;border-radius:50%;display:flex;align-items:center;justify-content:center;border:2px solid #fff;padding:0 3px;line-height:1;animation:notiPulse 2s ease-in-out infinite}
        [data-theme="dark"] .noti-badge{border-color:#1A1025}
        @keyframes notiPulse{0%,100%{transform:scale(1)}50%{transform:scale(1.15)}}
        .notification-dropdown{position:absolute;top:calc(100% + 12px);right:-8px;width:360px;max-height:440px;background:#fff;border:1px solid var(--border-color);border-radius:var(--radius);box-shadow:0 20px 60px rgba(0,0,0,0.15);opacity:0;visibility:hidden;transform:translateY(-8px) scale(0.97);transition:all .3s cubic-bezier(.16,1,.3,1);z-index:9999;overflow:hidden;display:flex;flex-direction:column}
        [data-theme="dark"] .notification-dropdown{background:#1F1333;border-color:rgba(156,39,176,0.15)}
        .notification-dropdown.show{opacity:1;visibility:visible;transform:translateY(0) scale(1)}
        .notification-dropdown .noti-header{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border-color);flex-shrink:0}
        .notification-dropdown .noti-header h6{font-size:14px;font-weight:700;color:var(--text-dark);margin:0;display:flex;align-items:center;gap:8px}
        .notification-dropdown .noti-header h6 .count{background:var(--primary);color:#fff;font-size:10px;padding:2px 7px;border-radius:8px}
        .mark-read-btn{background:none;border:none;color:var(--primary);font-size:11.5px;font-weight:600;cursor:pointer;font-family:inherit;padding:4px 8px;border-radius:6px;transition:background .2s}
        .mark-read-btn:hover{background:rgba(var(--primary-rgb),0.08)}
        .notification-dropdown .noti-list{overflow-y:auto;flex:1}
        .notification-dropdown .noti-item{display:flex;align-items:flex-start;gap:12px;padding:13px 18px;border-bottom:1px solid var(--border-color);transition:background .2s}
        .notification-dropdown .noti-item:last-child{border-bottom:none}
        .notification-dropdown .noti-item:hover{background:rgba(var(--primary-rgb),0.03)}
        .notification-dropdown .noti-item.unread{background:rgba(var(--primary-rgb),0.04)}
        .notification-dropdown .noti-dot{width:8px;height:8px;border-radius:50%;background:#E0D4ED;flex-shrink:0;margin-top:6px}
        .notification-dropdown .noti-dot.active{background:var(--primary);box-shadow:0 0 0 3px rgba(var(--primary-rgb),0.15)}
        .notification-dropdown .noti-content{flex:1;min-width:0}
        .notification-dropdown .noti-content p{font-size:12.5px;color:var(--text-dark);margin:0 0 3px;line-height:1.45}
        .notification-dropdown .noti-content span{font-size:11px;color:var(--text-muted)}
        .notification-dropdown .noti-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;margin-top:1px}
        .notification-dropdown .noti-icon.assignment{background:rgba(33,150,243,0.1);color:#2196F3}
        .notification-dropdown .noti-icon.register{background:rgba(76,175,80,0.1);color:#4CAF50}
        .notification-dropdown .noti-icon.default{background:rgba(var(--primary-rgb),0.1);color:var(--primary)}
        .noti-empty{padding:40px 20px;text-align:center;color:var(--text-muted);font-size:13px}
        .noti-empty i{font-size:28px;margin-bottom:8px;display:block;opacity:0.3}

        .dashboard-body{padding:28px 30px 40px}

        .stat-card{background:var(--card-bg);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border:1px solid var(--border-color);border-radius:var(--radius);padding:24px 22px;position:relative;overflow:hidden;box-shadow:var(--shadow-sm);transition:all .35s cubic-bezier(.4,0,.2,1)}
        .stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;border-radius:var(--radius) var(--radius) 0 0;opacity:0;transition:opacity .3s ease}
        .stat-card:hover{transform:translateY(-6px);box-shadow:var(--shadow-lg);border-color:rgba(var(--primary-rgb),0.15)}
        .stat-card:hover::before{opacity:1}
        .stat-card .s-icon{width:52px;height:52px;border-radius:15px;display:flex;align-items:center;justify-content:center;font-size:20px;margin-bottom:18px;transition:transform .3s ease}
        .stat-card:hover .s-icon{transform:scale(1.1) rotate(-5deg)}
        .stat-card .s-value{font-size:26px;font-weight:800;color:var(--text-dark);line-height:1;margin-bottom:4px;letter-spacing:-0.5px}
        .stat-card .s-label{font-size:12px;color:var(--text-muted);font-weight:500}
        .stat-card .s-bg{position:absolute;right:-10px;bottom:-14px;font-size:80px;opacity:0.022;color:var(--primary);pointer-events:none;transition:opacity .3s ease}
        .stat-card:hover .s-bg{opacity:0.05}
        .c-purple::before{background:linear-gradient(90deg,#6A0DAD,#9C27B0)}
        .c-purple .s-icon{background:rgba(var(--primary-rgb),0.1);color:var(--primary)}
        .c-green::before{background:linear-gradient(90deg,#2E7D32,#66BB6A)}
        .c-green .s-icon{background:rgba(76,175,80,0.1);color:#388E3C}
        .c-orange::before{background:linear-gradient(90deg,#E65100,#FFA726)}
        .c-orange .s-icon{background:rgba(255,152,0,0.1);color:#F57C00}
        .c-red::before{background:linear-gradient(90deg,#C62828,#EF5350)}
        .c-red .s-icon{background:rgba(244,67,54,0.1);color:#E53935}
        .c-blue::before{background:linear-gradient(90deg,#1565C0,#42A5F5)}
        .c-blue .s-icon{background:rgba(33,150,243,0.1);color:#1976D2}
        .c-teal::before{background:linear-gradient(90deg,#00695C,#4DB6AC)}
        .c-teal .s-icon{background:rgba(0,150,136,0.1);color:#00897B}

        .chart-card{background:var(--card-bg);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border:1px solid var(--border-color);border-radius:var(--radius);padding:24px;box-shadow:var(--shadow-sm);transition:box-shadow .35s ease;height:100%;display:flex;flex-direction:column}
        .chart-card:hover{box-shadow:var(--shadow-md)}
        .ch-head{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:8px}
        .ch-title{font-size:16px;font-weight:700;color:var(--text-dark);margin-bottom:2px}
        .ch-sub{font-size:12px;color:var(--text-muted);font-weight:400}
        .ch-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(var(--primary-rgb),0.07);color:var(--primary);padding:5px 14px;border-radius:20px;font-size:11px;font-weight:600;white-space:nowrap}
        .ch-body{height:320px;min-height:320px;flex:1;display:flex;align-items:center;justify-content:center;position:relative}.ch-body canvas{width:100%!important;height:100%!important}

        .table-card{background:var(--card-bg);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border:1px solid var(--border-color);border-radius:var(--radius);box-shadow:var(--shadow-sm);overflow:hidden}
        .table-card .table-header{padding:20px 24px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
        .table-card .table-title{font-size:16px;font-weight:700;color:var(--text-dark);display:flex;align-items:center;gap:10px}
        .table-card .table-title i{color:var(--primary);font-size:18px}
        .search-box{position:relative}
        .search-box input{border:1px solid var(--border-color);border-radius:10px;padding:9px 14px 9px 38px;font-size:13px;font-family:'Poppins',sans-serif;background:var(--input-bg);width:240px;transition:all .25s ease;color:var(--text-dark)}
        .search-box input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(var(--primary-rgb),0.1)}
        .search-box input::placeholder{color:var(--text-muted)}
        .search-box i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:13px}
        .filter-select{border:1px solid var(--border-color);border-radius:10px;padding:9px 14px;font-size:13px;font-family:'Poppins',sans-serif;background:var(--input-bg);color:var(--text-dark);transition:all .25s ease;cursor:pointer}
        .filter-select:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(var(--primary-rgb),0.1)}
        [data-theme="dark"] .filter-select option{background:#1F1333;color:#E8E0F0}
        .table-card table{margin:0;font-size:13px}
        .table-card table thead th{background:rgba(var(--primary-rgb),0.03);color:var(--text-muted);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:0.8px;border:none;padding:13px 16px;white-space:nowrap}
        .table-card table tbody td{padding:13px 16px;border:none;border-bottom:1px solid var(--border-color);vertical-align:middle;color:#000000}
        .table-card table tbody td strong{color:#000000}
        [data-theme="dark"] .table-card table tbody td{color:#FFFFFF !important}
        [data-theme="dark"] .table-card table tbody td strong{color:#FFFFFF !important}
        .table-card table tbody tr{transition:background .2s ease}
        .table-card table tbody tr:hover{background:rgba(var(--primary-rgb),0.03)}
        .table-card table tbody tr:last-child td{border-bottom:none}
        .status-paid{background:rgba(76,175,80,0.12);color:#388E3C;padding:4px 12px;border-radius:20px;font-size:11.5px;font-weight:600;display:inline-block}
        .status-pending{background:rgba(255,152,0,0.12);color:#F57C00;padding:4px 12px;border-radius:20px;font-size:11.5px;font-weight:600;display:inline-block}
        .status-failed{background:rgba(244,67,54,0.12);color:#E53935;padding:4px 12px;border-radius:20px;font-size:11.5px;font-weight:600;display:inline-block}
        .status-expired{background:rgba(158,158,158,0.15);color:#757575;padding:4px 12px;border-radius:20px;font-size:11.5px;font-weight:600;display:inline-block}
        [data-theme="dark"] .status-paid{color:#66BB6A}
        [data-theme="dark"] .status-pending{color:#FFA726}
        [data-theme="dark"] .status-failed{color:#EF5350}
        [data-theme="dark"] .status-expired{color:#BDBDBD}
        .type-badge{padding:3px 10px;border-radius:6px;font-size:11px;font-weight:600;display:inline-block}
        .type-plan{background:rgba(var(--primary-rgb),0.1);color:var(--primary)}
        .type-assignment{background:rgba(33,150,243,0.1);color:#1976D2}
        .btn-action{width:32px;height:32px;border-radius:8px;border:none;display:inline-flex;align-items:center;justify-content:center;font-size:12px;cursor:pointer;transition:all .2s ease}
        .btn-view{background:rgba(var(--primary-rgb),0.1);color:var(--primary)}
        .btn-view:hover{background:rgba(var(--primary-rgb),0.2);transform:translateY(-2px)}
        .btn-edit{background:rgba(255,193,7,0.12);color:#F9A825}
        .btn-edit:hover{background:rgba(255,193,7,0.25);transform:translateY(-2px)}
        .btn-delete{background:rgba(244,67,54,0.1);color:#E53935}
        .btn-delete:hover{background:rgba(244,67,54,0.2);transform:translateY(-2px)}
        .btn-export{background:rgba(33,150,243,0.1);color:#1976D2;border:1px solid rgba(33,150,243,0.2);padding:7px 14px;border-radius:10px;font-size:12px;font-weight:600;font-family:'Poppins',sans-serif;cursor:pointer;transition:all .25s ease;display:inline-flex;align-items:center;gap:6px}
        .btn-export:hover{background:rgba(33,150,243,0.2);transform:translateY(-1px)}
        .btn-print{background:rgba(var(--primary-rgb),0.1);color:var(--primary);border:1px solid rgba(var(--primary-rgb),0.2);padding:7px 14px;border-radius:10px;font-size:12px;font-weight:600;font-family:'Poppins',sans-serif;cursor:pointer;transition:all .25s ease;display:inline-flex;align-items:center;gap:6px}
        .btn-print:hover{background:rgba(var(--primary-rgb),0.2);transform:translateY(-1px)}

        .pagination-wrapper{padding:16px 24px;border-top:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
        .pagination-info{font-size:12.5px;color:var(--text-muted)}
        .pagination .page-link{border:1px solid var(--border-color);color:var(--text-dark);font-size:13px;padding:6px 12px;margin:0 2px;border-radius:8px;transition:all .2s ease;font-family:'Poppins',sans-serif;background:var(--card-bg)}
        .pagination .page-link:hover{background:rgba(var(--primary-rgb),0.08);border-color:var(--primary);color:var(--primary)}
        .pagination .page-item.active .page-link{background:var(--primary);border-color:var(--primary);color:#fff}
        .pagination .page-item.disabled .page-link{background:transparent;color:var(--text-muted)}

        .modal-content{border:1px solid var(--border-color);border-radius:var(--radius);box-shadow:var(--shadow-lg);background:#fff}
        [data-theme="dark"] .modal-content{background:#1F1333;border-color:rgba(156,39,176,0.15)}
        [data-theme="dark"] .modal-header{border-bottom-color:rgba(156,39,176,0.12)}
        [data-theme="dark"] .modal-footer{border-top-color:rgba(156,39,176,0.12)}
        .modal-header{border-bottom:1px solid var(--border-color);padding:20px 24px}
        .modal-header .modal-title{font-size:17px;font-weight:700;color:var(--text-dark);display:flex;align-items:center;gap:10px}
        .modal-header .modal-title i{color:var(--primary)}
        .modal-header .btn-close{filter:invert(1) grayscale(100%) brightness(200%)}
        [data-theme="dark"] .modal-header .btn-close{filter:invert(0)}
        .modal-body{padding:24px}
        .modal-footer{border-top:1px solid var(--border-color);padding:16px 24px}
        .detail-row{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border-color);font-size:13.5px}
        .detail-row:last-child{border-bottom:none}
        .detail-label{color:var(--text-muted);font-weight:500}
        .detail-value{color:var(--text-dark);font-weight:600;text-align:right;max-width:60%;word-break:break-all}
        .form-control,.form-select{border:1.5px solid var(--border-color);border-radius:10px;padding:10px 14px;font-size:13.5px;font-family:'Poppins',sans-serif;background:var(--input-bg);color:var(--text-dark);transition:all .25s ease}
        .form-control:focus,.form-select:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(var(--primary-rgb),0.1);background:var(--input-bg);color:var(--text-dark)}
        .btn-primary-custom{background:linear-gradient(135deg,var(--primary),var(--primary-light));color:#fff;border:none;padding:10px 24px;border-radius:10px;font-size:13.5px;font-weight:600;font-family:'Poppins',sans-serif;cursor:pointer;transition:all .3s ease;display:inline-flex;align-items:center;gap:8px;box-shadow:0 4px 15px rgba(var(--primary-rgb),0.3)}
        .btn-primary-custom:hover{transform:translateY(-2px);box-shadow:0 6px 25px rgba(var(--primary-rgb),0.4);color:#fff}
        .btn-outline-custom{background:transparent;color:var(--text-muted);border:1.5px solid var(--border-color);padding:10px 24px;border-radius:10px;font-size:13.5px;font-weight:600;font-family:'Poppins',sans-serif;cursor:pointer;transition:all .3s ease}
        .btn-outline-custom:hover{background:rgba(var(--primary-rgb),0.06);color:var(--text-dark)}

        /* ─── Receipt Viewer (uploaded image) ─── */
        .receipt-viewer{position:relative;border-radius:var(--radius-sm);overflow:hidden;background:rgba(0,0,0,0.03);min-height:200px;display:flex;align-items:center;justify-content:center}
        [data-theme="dark"] .receipt-viewer{background:rgba(0,0,0,0.2)}
        .receipt-viewer img{max-width:100%;max-height:400px;object-fit:contain;display:block}

        /* ─── Generated Receipt Card ─── */
        .gen-receipt{background:#fff;border-radius:12px;border:1px solid #e0e0e0;overflow:hidden;font-family:'Poppins',sans-serif;color:#2D1B4E;box-shadow:0 4px 24px rgba(0,0,0,0.08)}
        .gen-receipt-header{background:linear-gradient(135deg,#4A0072,#6A0DAD 50%,#9C27B0);padding:24px 28px;display:flex;align-items:center;justify-content:space-between}
        .gen-receipt-logo{display:flex;align-items:center;gap:14px}
        .gen-receipt-logo img{width:48px;height:48px;border-radius:10px;object-fit:cover;background:rgba(255,255,255,0.2);border:2px solid rgba(255,255,255,0.3)}
        .gen-receipt-company h3{color:#fff;font-size:18px;font-weight:700;margin:0;line-height:1.2}
        .gen-receipt-company small{color:rgba(255,255,255,0.7);font-size:11px;font-weight:400}
        .gen-receipt-badge{background:rgba(255,255,255,0.18);color:#fff;padding:8px 16px;border-radius:8px;text-align:center}
        .gen-receipt-badge .rb-label{font-size:9px;text-transform:uppercase;letter-spacing:1.5px;opacity:0.7;font-weight:500}
        .gen-receipt-badge .rb-status{font-size:15px;font-weight:800;letter-spacing:1px}
        .gen-receipt-body{padding:24px 28px}
        .gen-receipt-row{display:flex;justify-content:space-between;padding:8px 0;font-size:13px;border-bottom:1px dashed #e8e0f0}
        .gen-receipt-row:last-child{border-bottom:none}
        .gen-receipt-row .gr-label{color:#7B6B8D;font-weight:500}
        .gen-receipt-row .gr-value{font-weight:600;color:#2D1B4E;text-align:right;max-width:60%;word-break:break-all}
        .gen-receipt-divider{height:2px;background:linear-gradient(90deg,transparent,#6A0DAD,transparent);margin:16px 0;opacity:0.3}
        .gen-receipt-amount{text-align:center;padding:16px 0 8px}
        .gen-receipt-amount .ra-label{font-size:11px;color:#7B6B8D;text-transform:uppercase;letter-spacing:1px;font-weight:600}
        .gen-receipt-amount .ra-value{font-size:32px;font-weight:800;color:#4A0072;line-height:1.2}
        .gen-receipt-footer{padding:16px 28px;background:#f8f5fc;border-top:1px dashed #e8e0f0;display:flex;align-items:center;justify-content:space-between}
        .gen-receipt-footer .rf-text{font-size:11px;color:#9B8DB5;line-height:1.5}
        .gen-receipt-footer .rf-ref{font-size:11px;color:#7B6B8D;font-weight:600;text-align:right}
        .gen-receipt-orig-img{margin:20px 28px 0;padding-top:16px;border-top:1px dashed #e8e0f0}
        .gen-receipt-orig-img .roi-label{font-size:11px;color:#7B6B8D;font-weight:600;text-transform:uppercase;letter-spacing:0.8px;margin-bottom:10px}
        .gen-receipt-orig-img .roi-viewer{border-radius:8px;overflow:hidden;background:#f8f5fc;display:flex;align-items:center;justify-content:center;min-height:120px}
        .gen-receipt-orig-img .roi-viewer img{max-width:100%;max-height:350px;object-fit:contain;display:block}

        .view-overlay{position:fixed;inset:0;z-index:1090;display:none;align-items:center;justify-content:center;background:rgba(42,20,70,0.55);padding:24px;opacity:0;transition:opacity .35s ease}
        .view-overlay.show{display:flex;opacity:1}
        .view-modal{background:var(--card-bg);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);border:1px solid rgba(var(--primary-rgb),0.15);border-radius:var(--radius);box-shadow:0 24px 80px rgba(0,0,0,0.35);width:100%;max-width:800px;max-height:90vh;display:flex;flex-direction:column;overflow:hidden;animation:vmIn .4s cubic-bezier(.16,1,.3,1)}
        @keyframes vmIn{from{opacity:0;transform:translateY(30px) scale(.96)}to{opacity:1;transform:translateY(0) scale(1)}}
        .view-modal-header{padding:22px 28px;background:linear-gradient(135deg,var(--primary-dark),var(--primary),var(--primary-light));color:#fff;display:flex;align-items:center;justify-content:space-between;flex-shrink:0}
        .view-modal-header h5{font-size:16px;font-weight:700;margin:0;display:flex;align-items:center;gap:10px}
        .vm-close{width:34px;height:34px;border-radius:8px;border:none;background:rgba(255,255,255,0.15);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:14px;transition:all .25s}
        .vm-close:hover{background:rgba(255,255,255,0.3);transform:rotate(90deg)}
        .view-modal-body{padding:28px;overflow-y:auto;flex:1}
        .view-modal-footer{padding:16px 28px;border-top:1px solid var(--border-color);display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-shrink:0}
        .vm-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:0}
        .vm-item{padding:14px 0;border-bottom:1px solid var(--border-color)}
        .vm-item.full{grid-column:1/-1}
        .vm-label{font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted);margin-bottom:5px}
        .vm-value{font-size:14px;color:var(--text-dark);font-weight:500;word-break:break-all}
        .vm-value a{color:var(--primary);text-decoration:none}
        .vm-value a:hover{text-decoration:underline}
        .vm-btn-close{padding:9px 22px;border-radius:var(--radius-sm);border:1.5px solid var(--border-color);background:transparent;color:var(--text-muted);font-size:13px;font-weight:600;font-family:'Poppins',sans-serif;cursor:pointer;transition:all .25s ease;display:inline-flex;align-items:center;gap:6px}
        .vm-btn-close:hover{border-color:var(--primary);color:var(--primary)}

        .pending-notice{background:rgba(255,152,0,0.08);border:1px solid rgba(255,152,0,0.2);border-radius:var(--radius-sm);padding:28px 24px;text-align:center}
        .pending-notice i{font-size:42px;color:#F57C00;margin-bottom:14px;display:block}
        .pending-notice h6{font-size:16px;font-weight:700;color:var(--text-dark);margin-bottom:6px}
        .pending-notice p{font-size:13px;color:var(--text-muted);margin:0;line-height:1.6}

        .edit-overlay{position:fixed;inset:0;z-index:1095;display:none;align-items:center;justify-content:center;background:rgba(42,20,70,0.55);padding:24px;opacity:0;transition:opacity .35s ease}
        .edit-overlay.show{display:flex;opacity:1}
        .edit-modal{background:var(--card-bg);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);border:1px solid rgba(var(--primary-rgb),0.15);border-radius:var(--radius);box-shadow:0 24px 80px rgba(0,0,0,0.35);width:100%;max-width:480px;overflow:hidden;animation:vmIn .4s cubic-bezier(.16,1,.3,1)}
        .edit-modal-header{padding:22px 28px;background:linear-gradient(135deg,#E65100,#FFA726,#FFB74D);color:#fff;display:flex;align-items:center;justify-content:space-between}
        .edit-modal-header h5{font-size:16px;font-weight:700;margin:0;display:flex;align-items:center;gap:10px}
        .edit-modal-body{padding:28px}
        .edit-modal-footer{padding:16px 28px;border-top:1px solid var(--border-color);display:flex;align-items:center;justify-content:flex-end;gap:10px}
        .edit-info-row{display:flex;justify-content:space-between;padding:8px 0;font-size:13px;border-bottom:1px solid var(--border-color)}
        .edit-info-row:last-child{border-bottom:none}
        .edit-info-label{color:var(--text-muted);font-weight:500}
        .edit-info-value{color:var(--text-dark);font-weight:600}
        .btn-save-status{background:linear-gradient(135deg,#E65100,#FFA726);color:#fff;border:none;padding:10px 24px;border-radius:10px;font-size:13.5px;font-weight:600;font-family:'Poppins',sans-serif;cursor:pointer;transition:all .3s ease;display:inline-flex;align-items:center;gap:8px;box-shadow:0 4px 15px rgba(255,152,0,0.3)}
        .btn-save-status:hover{transform:translateY(-2px);box-shadow:0 6px 25px rgba(255,152,0,0.4);color:#fff}
        .btn-cancel-edit{background:transparent;color:var(--text-muted);border:1.5px solid var(--border-color);padding:10px 24px;border-radius:10px;font-size:13.5px;font-weight:600;font-family:'Poppins',sans-serif;cursor:pointer;transition:all .3s ease}
        .btn-cancel-edit:hover{background:rgba(var(--primary-rgb),0.06);color:var(--text-dark)}

        [data-theme="dark"] .alert{border:1px solid rgba(156,39,176,0.15)}
        [data-theme="dark"] .alert-success{background:rgba(76,175,80,0.1);color:#A5D6A7;border-color:rgba(76,175,80,0.2)}
        [data-theme="dark"] .alert-danger{background:rgba(244,67,54,0.1);color:#EF9A9A;border-color:rgba(244,67,54,0.2)}

        .settings-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:1060;backdrop-filter:blur(4px);opacity:0;transition:opacity .3s ease}
        .settings-overlay.show{display:block;opacity:1}
        .settings-panel{position:fixed;top:0;right:0;width:340px;max-width:90vw;height:100vh;background:#fff;border-left:1px solid var(--border-color);z-index:1070;transform:translateX(100%);transition:transform .35s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;box-shadow:-8px 0 40px rgba(0,0,0,0.1)}
        [data-theme="dark"] .settings-panel{background:#1A1025;border-left-color:rgba(156,39,176,0.15)}
        .settings-panel.show{transform:translateX(0)}
        .settings-panel-header{display:flex;align-items:center;justify-content:space-between;padding:22px 24px;border-bottom:1px solid var(--border-color)}
        .settings-panel-header h5{font-size:17px;font-weight:700;color:var(--text-dark);margin:0;display:flex;align-items:center;gap:10px}
        .settings-panel-header h5 i{color:var(--primary)}
        .settings-close-btn{width:36px;height:36px;border-radius:10px;border:1px solid var(--border-color);background:transparent;display:flex;align-items:center;justify-content:center;color:var(--text-muted);font-size:14px;cursor:pointer;transition:all .2s}
        .settings-close-btn:hover{background:rgba(244,67,54,0.08);border-color:rgba(244,67,54,0.2);color:#F44336}
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
        .theme-switch .slider::before{content:'';position:absolute;width:22px;height:22px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:all .35s cubic-bezier(.4,0,.2,1);box-shadow:0 2px 6px rgba(0,0,0,0.15)}
        .theme-switch input:checked + .slider{background:var(--primary)}
        .theme-switch input:checked + .slider::before{transform:translateX(24px)}

        .sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:1040;backdrop-filter:blur(4px)}
        .sidebar-overlay.show{display:block}

        /* Delete Confirmation Modal */
        .delete-overlay{position:fixed;inset:0;z-index:9999;display:none;align-items:center;justify-content:center;background:rgba(42,20,70,0.6);padding:24px;opacity:0;transition:opacity .3s ease}
        .delete-overlay.show{display:flex;opacity:1}
        .delete-modal{background:#fff;border-radius:var(--radius);box-shadow:0 24px 80px rgba(0,0,0,0.35);width:100%;max-width:420px;padding:36px 32px 28px;text-align:center;animation:vmIn .4s cubic-bezier(.16,1,.3,1)}
        [data-theme="dark"] .delete-modal{background:#1F1333;border:1px solid rgba(156,39,176,0.15)}
        .delete-modal-icon{width:68px;height:68px;border-radius:50%;background:rgba(244,67,54,0.1);display:flex;align-items:center;justify-content:center;margin:0 auto 20px}
        .delete-modal-icon i{font-size:28px;color:#E53935;animation:delShake .5s ease .3s}
        @keyframes delShake{0%,100%{transform:rotate(0)}15%{transform:rotate(-12deg)}30%{transform:rotate(10deg)}45%{transform:rotate(-8deg)}60%{transform:rotate(6deg)}75%{transform:rotate(-3deg)}}
        .delete-modal h5{font-size:18px;font-weight:700;color:var(--text-dark);margin:0 0 10px}
        .delete-modal p{font-size:13.5px;color:var(--text-muted);margin:0 0 24px;line-height:1.6}
        .delete-modal p strong{color:#E53935}
        .delete-modal-actions{display:flex;gap:10px;justify-content:center}
        .btn-cancel-delete{background:transparent;color:var(--text-muted);border:1.5px solid var(--border-color);padding:10px 28px;border-radius:10px;font-size:13.5px;font-weight:600;font-family:'Poppins',sans-serif;cursor:pointer;transition:all .25s ease}
        .btn-cancel-delete:hover{background:rgba(var(--primary-rgb),0.06);color:var(--text-dark);border-color:var(--primary)}
        .btn-confirm-delete{background:linear-gradient(135deg,#C62828,#E53935);color:#fff;border:none;padding:10px 28px;border-radius:10px;font-size:13.5px;font-weight:600;font-family:'Poppins',sans-serif;cursor:pointer;transition:all .3s ease;display:inline-flex;align-items:center;gap:8px;box-shadow:0 4px 15px rgba(244,67,54,0.3)}
        .btn-confirm-delete:hover{transform:translateY(-2px);box-shadow:0 6px 25px rgba(244,67,54,0.45);color:#fff}
        .btn-confirm-delete:disabled{opacity:0.6;cursor:not-allowed;transform:none;box-shadow:none}

        @keyframes fadeInUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
        .animate-in{animation:fadeInUp .5s ease forwards;opacity:0}
        .animate-in:nth-child(1){animation-delay:.05s}
        .animate-in:nth-child(2){animation-delay:.1s}
        .animate-in:nth-child(3){animation-delay:.15s}
        .animate-in:nth-child(4){animation-delay:.2s}
        .animate-in:nth-child(5){animation-delay:.25s}
        .animate-in:nth-child(6){animation-delay:.3s}
        .chart-animate{animation:fadeInUp .6s ease .35s forwards;opacity:0}

        ::-webkit-scrollbar{width:6px}
        ::-webkit-scrollbar-track{background:transparent}
        ::-webkit-scrollbar-thumb{background:rgba(var(--primary-rgb),0.2);border-radius:10px}
        ::-webkit-scrollbar-thumb:hover{background:rgba(var(--primary-rgb),0.35)}

        @media print{
            .sidebar,.sidebar-overlay,.top-header,.settings-panel,.settings-overlay,.stat-card,.chart-card,.table-header .d-flex:last-child,.pagination-wrapper,.btn-action,.view-overlay,.edit-overlay{display:none!important}
            .main-content{margin-left:0!important}
            .table-card{border:none!important;box-shadow:none!important;background:#fff!important}
            .dashboard-body{padding:0!important}
            .table-card table tbody td,.table-card table tbody td strong{color:#000!important}
            .table-card table thead th{color:#333!important}
        }

        @media(max-width:991.98px){
            .sidebar{transform:translateX(-100%)}
            .sidebar.show{transform:translateX(0)}
            .main-content{margin-left:0}
            .sidebar-toggle{display:flex}
            .dashboard-body{padding:20px 16px 30px}
            .top-header{padding:0 16px}
            .header-time{display:none}
            .notification-dropdown{width:320px;right:-40px}
            .search-box input{width:180px}
            .vm-detail-grid{grid-template-columns:1fr}
            .gen-receipt-header{flex-direction:column;gap:12px;text-align:center}
            .gen-receipt-logo{justify-content:center}
            .gen-receipt-badge{align-self:center}
        }
        @media(max-width:575.98px){
            .stat-card .s-value{font-size:22px}
            .stat-card .s-icon{width:44px;height:44px;font-size:17px;border-radius:12px}
            .search-box input{width:100%}
            .table-header{flex-direction:column;align-items:stretch}
            .notification-dropdown{width:calc(100vw - 32px);right:-60px;max-height:380px}
            .settings-panel{width:100vw;max-width:100vw}
            .view-overlay,.edit-overlay{padding:12px}
            .view-modal-header,.edit-modal-header{padding:18px 20px}
            .view-modal-body,.edit-modal-body{padding:20px}
            .view-modal-footer,.edit-modal-footer{padding:14px 20px;flex-direction:column}
            .vm-btn-close,.btn-save-status,.btn-cancel-edit{width:100%;justify-content:center}
            .gen-receipt-body,.gen-receipt-footer,.gen-receipt-orig-img{padding-left:20px;padding-right:20px}
            .gen-receipt-header{padding:20px}
        }
    </style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <div class="settings-overlay" id="settingsOverlay"></div>

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
            <a href="admin_plans.php"><i class="fas fa-tags fa-icon"></i> Plans</a>
            <a href="admin_payments.php" class="active"><i class="fas fa-credit-card fa-icon"></i> Payments</a>
            <a href="admin_vouchers.php"><i class="fas fa-ticket-alt fa-icon"></i> Vouchers</a>
            <a href="admin_testimonials.php"><i class="fas fa-quote-right fa-icon"></i> Testimonials</a>
            <a href="admin_contacts.php"><i class="fas fa-phone-alt"></i> Contacts</a>
            <a href="login.php" class="logout-btn"><i class="fas fa-sign-out-alt fa-icon"></i> Logout</a>
        </nav>
        <div class="sidebar-footer">
            <div class="admin-info">
                <img src="<?php echo htmlspecialchars($avatar); ?>" class="admin-avatar-img" alt="<?php echo htmlspecialchars($admin_name); ?>" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=Admin&backgroundColor=ede9fe'">
                <div><div class="admin-name"><?php echo htmlspecialchars($admin_name); ?></div><div class="admin-role">Administrator</div></div>
            </div>
        </div>
    </aside>

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

    <!-- VIEW OVERLAY -->
    <div class="view-overlay" id="viewOverlay">
        <div class="view-modal">
            <div class="view-modal-header">
                <h5><i class="fas fa-receipt"></i> Payment Details</h5>
                <button class="vm-close" id="viewCloseBtn"><i class="fas fa-times"></i></button>
            </div>
            <div class="view-modal-body">
                <div class="vm-detail-grid">
                    <div class="vm-item"><div class="vm-label">Transaction ID</div><div class="vm-value" id="vId"></div></div>
                    <div class="vm-item"><div class="vm-label">Order ID</div><div class="vm-value" id="vOrderId"></div></div>
                    <div class="vm-item"><div class="vm-label">User Name</div><div class="vm-value" id="vUserName"></div></div>
                    <div class="vm-item"><div class="vm-label">User Email</div><div class="vm-value" id="vUserEmail"></div></div>
                    <div class="vm-item"><div class="vm-label">Type</div><div class="vm-value" id="vType"></div></div>
                    <div class="vm-item"><div class="vm-label">Item</div><div class="vm-value" id="vItem"></div></div>
                    <div class="vm-item"><div class="vm-label">Original Amount</div><div class="vm-value" id="vOrigAmt"></div></div>
                    <div class="vm-item"><div class="vm-label">Discount</div><div class="vm-value" id="vDiscount"></div></div>
                    <div class="vm-item"><div class="vm-label">Final Amount</div><div class="vm-value" id="vFinalAmt" style="font-weight:700;font-size:16px;color:var(--primary)"></div></div>
                    <div class="vm-item"><div class="vm-label">Status</div><div class="vm-value" id="vStatus"></div></div>
                    <div class="vm-item"><div class="vm-label">Payment Method</div><div class="vm-value" id="vMethod"></div></div>
                    <div class="vm-item"><div class="vm-label">Bill Code</div><div class="vm-value" id="vBillcode"></div></div>
                    <div class="vm-item"><div class="vm-label">Voucher Code</div><div class="vm-value" id="vVoucher"></div></div>
                    <div class="vm-item"><div class="vm-label">Date</div><div class="vm-value" id="vDate"></div></div>
                    <!-- Generated receipt: shown ONLY if paid -->
                    <div class="vm-item full" id="vReceiptWrap" style="display:none">
                        <div class="vm-label" style="margin-bottom:14px">Official Payment Receipt</div>
                        <div class="gen-receipt" id="genReceipt">
                            <div class="gen-receipt-header">
                                <div class="gen-receipt-logo">
                                    <img src="image/logo.png" alt="Logo" onerror="this.style.display='none'">
                                    <div class="gen-receipt-company">
                                        <h3>AI Assignment Checker</h3>
                                        <small>Professional AI-Powered Assignment Checking</small>
                                    </div>
                                </div>
                                <div class="gen-receipt-badge">
                                    <div class="rb-label">Status</div>
                                    <div class="rb-status">PAID</div>
                                </div>
                            </div>
                            <div class="gen-receipt-body">
                                <div class="gen-receipt-row"><span class="gr-label">Receipt No.</span><span class="gr-value" id="grReceiptNo"></span></div>
                                <div class="gen-receipt-row"><span class="gr-label">Transaction ID</span><span class="gr-value" id="grTxnId"></span></div>
                                <div class="gen-receipt-row"><span class="gr-label">Order ID</span><span class="gr-value" id="grOrderId"></span></div>
                                <div class="gen-receipt-row"><span class="gr-label">Date & Time</span><span class="gr-value" id="grDate"></span></div>
                                <div class="gen-receipt-divider"></div>
                                <div class="gen-receipt-row"><span class="gr-label">Customer</span><span class="gr-value" id="grCustomer"></span></div>
                                <div class="gen-receipt-row"><span class="gr-label">Email</span><span class="gr-value" id="grEmail"></span></div>
                                <div class="gen-receipt-row"><span class="gr-label">Purchase Type</span><span class="gr-value" id="grType"></span></div>
                                <div class="gen-receipt-row"><span class="gr-label">Item</span><span class="gr-value" id="grItem"></span></div>
                                <div class="gen-receipt-divider"></div>
                                <div class="gen-receipt-row"><span class="gr-label">Original Amount</span><span class="gr-value" id="grOrigAmt"></span></div>
                                <div class="gen-receipt-row"><span class="gr-label">Discount</span><span class="gr-value" id="grDiscount" style="color:#388E3C"></span></div>
                                <div class="gen-receipt-row"><span class="gr-label">Payment Method</span><span class="gr-value" id="grMethod"></span></div>
                                <div class="gen-receipt-row" id="grVoucherRow" style="display:none"><span class="gr-label">Voucher Code</span><span class="gr-value" id="grVoucher" style="color:#388E3C"></span></div>
                                <div class="gen-receipt-divider"></div>
                                <div class="gen-receipt-amount">
                                    <div class="ra-label">Total Paid</div>
                                    <div class="ra-value" id="grTotal"></div>
                                </div>
                            </div>
                            <div class="gen-receipt-footer">
                                <div class="rf-text">Thank you for your purchase!<br>AI Assignment Checker — All rights reserved.</div>
                                <div class="rf-ref" id="grRef"></div>
                            </div>
                            <!-- Original uploaded receipt image if exists -->
                            <div class="gen-receipt-orig-img" id="grOrigImgWrap" style="display:none">
                                <div class="roi-label"><i class="fas fa-image me-1"></i> Original Payment Screenshot</div>
                                <div class="roi-viewer" id="grOrigViewer"></div>
                            </div>
                        </div>
                    </div>
                    <!-- Pending/failed/expired notice -->
                    <div class="vm-item full" id="vNoticeWrap" style="display:none">
                        <div class="pending-notice">
                            <i class="fas fa-clock"></i>
                            <h6>Payment Not Completed</h6>
                            <p>This transaction is currently <strong id="vNoticeStatus"></strong>. No receipt is available yet. Use the Edit button to update the status once payment is confirmed.</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="view-modal-footer">
                <button class="vm-btn-close" id="viewCloseBtn2"><i class="fas fa-times"></i> Close</button>
            </div>
        </div>
    </div>

        <!-- Delete Confirmation Modal -->
    <div class="delete-overlay" id="deleteOverlay">
        <div class="delete-modal">
            <div class="delete-modal-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h5>Delete Transaction</h5>
            <p>Are you sure you want to permanently delete transaction <strong id="deleteTxId">#—</strong>? This action cannot be undone and the receipt file will also be removed.</p>
            <div class="delete-modal-actions">
                <button class="btn-cancel-delete" id="cancelDeleteBtn">Cancel</button>
                <button class="btn-confirm-delete" id="confirmDeleteBtn">
                    <i class="fas fa-trash-alt"></i> Yes, Delete
                </button>
            </div>
        </div>
    </div>

    <!-- EDIT OVERLAY -->
    <div class="edit-overlay" id="editOverlay">
        <div class="edit-modal">
            <div class="edit-modal-header">
                <h5><i class="fas fa-pen-to-square"></i> Update Payment Status</h5>
                <button class="vm-close" id="editCloseBtn"><i class="fas fa-times"></i></button>
            </div>
            <div class="edit-modal-body">
                <div class="edit-info-row"><span class="edit-info-label">Transaction ID</span><span class="edit-info-value" id="eId"></span></div>
                <div class="edit-info-row"><span class="edit-info-label">Order ID</span><span class="edit-info-value" id="eOrderId"></span></div>
                <div class="edit-info-row"><span class="edit-info-label">Current Status</span><span class="edit-info-value" id="eCurStatus"></span></div>
                <div class="edit-info-row"><span class="edit-info-label">Amount</span><span class="edit-info-value" id="eAmount"></span></div>
                <form method="POST" action="admin_payments.php" id="editForm" style="margin-top:20px">
                    <input type="hidden" name="update_status" value="1">
                    <input type="hidden" name="upd_payment_id" id="ePayId" value="">
                    <label style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted);display:block;margin-bottom:8px">New Status</label>
                    <select name="new_status" class="form-select" id="eNewStatus" required>
                        <option value="">— Select Status —</option>
                        <option value="paid">Paid</option>
                        <option value="pending">Pending</option>
                        <option value="failed">Failed</option>
                        <option value="expired">Expired</option>
                    </select>
                </form>
            </div>
            <div class="edit-modal-footer">
                <button class="btn-cancel-edit" type="button" id="editCancelBtn"><i class="fas fa-times"></i> Cancel</button>
                <button class="btn-save-status" type="button" id="editSaveBtn"><i class="fas fa-check"></i> Update Status</button>
            </div>
        </div>
    </div>

    <main class="main-content">
        <header class="top-header">
            <div class="left-section">
                <button class="sidebar-toggle" id="sidebarToggle"><i class="fas fa-bars"></i></button>
                <h1 class="page-title"><span>Manage</span> Payments</h1>
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
                            <form method="POST" style="display:inline"><input type="hidden" name="action" value="mark_all_read"><button type="submit" class="mark-read-btn">Mark all read</button></form>
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
            <?php if (!empty($alert_msg)): ?>
            <div class="alert alert-<?php echo $alert_type; ?> d-flex align-items-center shadow-sm mb-4" style="border-radius:12px;padding:14px 20px" id="alertBanner">
                <i class="fas fa-<?php echo $alert_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
                <div><?php echo $alert_msg; ?></div>
                <button type="button" class="btn-close ms-auto" style="font-size:12px" onclick="this.closest('.alert').remove()"></button>
            </div>
            <?php endif; ?>

            <div class="row g-3 mb-4">
                <div class="col-xl col-lg-4 col-md-6">
                    <div class="stat-card c-purple animate-in">
                        <div class="s-icon"><i class="fas fa-credit-card"></i></div>
                        <div class="s-value"><?php echo number_format($total_payments); ?></div>
                        <div class="s-label">Total Transactions</div>
                        <i class="fas fa-credit-card s-bg"></i>
                    </div>
                </div>
                <div class="col-xl col-lg-4 col-md-6">
                    <div class="stat-card c-green animate-in">
                        <div class="s-icon"><i class="fas fa-check-circle"></i></div>
                        <div class="s-value"><?php echo number_format($total_paid); ?></div>
                        <div class="s-label">Paid</div>
                        <i class="fas fa-check-circle s-bg"></i>
                    </div>
                </div>
                <div class="col-xl col-lg-4 col-md-6">
                    <div class="stat-card c-orange animate-in">
                        <div class="s-icon"><i class="fas fa-clock"></i></div>
                        <div class="s-value"><?php echo number_format($total_pending); ?></div>
                        <div class="s-label">Pending</div>
                        <i class="fas fa-clock s-bg"></i>
                    </div>
                </div>
                <div class="col-xl col-lg-3 col-md-6">
                    <div class="stat-card c-red animate-in">
                        <div class="s-icon"><i class="fas fa-times-circle"></i></div>
                        <div class="s-value"><?php echo number_format($total_failed); ?></div>
                        <div class="s-label">Failed</div>
                        <i class="fas fa-times-circle s-bg"></i>
                    </div>
                </div>
                <div class="col-xl col-lg-3 col-md-6">
                    <div class="stat-card c-teal animate-in">
                        <div class="s-icon"><i class="fas fa-hourglass-end"></i></div>
                        <div class="s-value"><?php echo number_format($total_expired); ?></div>
                        <div class="s-label">Expired</div>
                        <i class="fas fa-hourglass-end s-bg"></i>
                    </div>
                </div>
                <div class="col-xl col-lg-6 col-md-12">
                    <div class="stat-card c-blue animate-in">
                        <div class="s-icon"><i class="fas fa-coins"></i></div>
                        <div class="s-value">RM <?php echo number_format($total_revenue, 2); ?></div>
                        <div class="s-label">Total Revenue (Paid)</div>
                        <i class="fas fa-coins s-bg"></i>
                    </div>
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-lg-5">
                    <div class="chart-card chart-animate">
                        <div class="ch-head">
                            <div><div class="ch-title">Status Distribution</div><div class="ch-sub">All transactions breakdown</div></div>
                            <div class="ch-badge"><i class="fas fa-chart-pie"></i> Live</div>
                        </div>
                        <div class="ch-body"><canvas id="statusChart"></canvas></div>
                    </div>
                </div>
                <div class="col-lg-7">
                    <div class="chart-card chart-animate">
                        <div class="ch-head">
                            <div><div class="ch-title">Revenue Overview</div><div class="ch-sub">Paid vs Unpaid amounts</div></div>
                            <div class="ch-badge"><i class="fas fa-chart-bar"></i> Summary</div>
                        </div>
                        <div class="ch-body"><canvas id="revenueChart"></canvas></div>
                    </div>
                </div>
            </div>

            <div class="table-card">
                <div class="table-header">
                    <div class="table-title"><i class="fas fa-list"></i> All Transactions</div>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <form method="GET" class="search-box" style="position:relative">
                            <i class="fas fa-search"></i>
                            <?php if ($status_filter !== 'All'): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>"><?php endif; ?>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search transactions...">
                        </form>
                        <form method="GET" style="display:flex;gap:8px;align-items:center">
                            <?php if (!empty($search)): ?><input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>"><?php endif; ?>
                            <select name="status" class="filter-select" onchange="this.form.submit()">
                                <option value="All" <?php echo $status_filter === 'All' ? 'selected' : ''; ?>>All Status</option>
                                <option value="paid" <?php echo $status_filter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="failed" <?php echo $status_filter === 'failed' ? 'selected' : ''; ?>>Failed</option>
                                <option value="expired" <?php echo $status_filter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                            </select>
                        </form>
                        <?php if (!empty($search) || $status_filter !== 'All'): ?>
                        <a href="admin_payments.php" class="btn-export" style="background:rgba(244,67,54,0.1);color:#E53935;border-color:rgba(244,67,54,0.2)"><i class="fas fa-times"></i> Clear</a>
                        <?php endif; ?>
                        <a href="<?php echo build_url(['export' => 'csv']); ?>&export=csv" class="btn-export"><i class="fas fa-download"></i> CSV</a>
                        <button class="btn-print" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                    </div>
                </div>

                <div style="overflow-x:auto">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th><th>Order ID</th><th>User</th><th>Type</th><th>Item</th><th>Amount</th><th>Method</th><th>Status</th><th>Date</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (mysqli_num_rows($payments_query) === 0): ?>
                        <tr><td colspan="10" style="text-align:center;padding:50px 20px;color:var(--text-muted)">
                            <i class="fas fa-inbox" style="font-size:32px;display:block;margin-bottom:10px;opacity:0.25"></i>No transactions found.
                        </td></tr>
                        <?php else: ?>
                        <?php while ($p = mysqli_fetch_assoc($payments_query)):
                            $type_class = ($p['type'] === 'assignment') ? 'type-assignment' : 'type-plan';
                        ?>
                        <tr>
                            <td><strong>#<?php echo $p['id']; ?></strong></td>
                            <td style="font-size:12px;font-family:monospace"><?php echo htmlspecialchars($p['order_id'] ?? '-'); ?></td>
                            <td>
                                <div style="font-weight:600;font-size:13px"><?php echo htmlspecialchars($p['user_name']); ?></div>
                                <div style="font-size:11px;color:var(--text-muted)"><?php echo htmlspecialchars($p['user_email']); ?></div>
                            </td>
                            <td><span class="type-badge <?php echo $type_class; ?>"><?php echo ucfirst($p['type'] ?? 'N/A'); ?></span></td>
                            <td style="max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?php echo htmlspecialchars($p['item_name'] ?? ''); ?>"><?php echo htmlspecialchars($p['item_name'] ?? '-'); ?></td>
                            <td><strong>RM <?php echo number_format($p['final_amount'] ?? 0, 2); ?></strong></td>
                            <td><i class="fas <?php echo pay_method_icon($p['toyyibpay_payment_method']); ?> me-1" style="font-size:11px;opacity:0.6"></i><span style="font-size:12px"><?php echo htmlspecialchars($p['toyyibpay_payment_method'] ?? '-'); ?></span></td>
                            <td><span class="<?php echo status_badge_class($p['status']); ?>"><?php echo status_label($p['status']); ?></span></td>
                            <td style="font-size:12px;color:var(--text-muted);white-space:nowrap"><?php echo time_ago($p['created_at']); ?></td>
                            <td>
                                <div style="display:flex;gap:5px;align-items:center">
                                    <button class="btn-action btn-view" onclick="viewPayment(<?php echo $p['id']; ?>)" title="View details"><i class="fas fa-eye"></i></button>
                                    <button class="btn-action btn-edit" onclick="editPayment(<?php echo $p['id']; ?>)" title="Edit status"><i class="fas fa-pen"></i></button>
                                    <button class="btn-action btn-delete btn-delete-trigger" data-id="<?php echo $p['id']; ?>" title="Delete"><i class="fas fa-trash-alt"></i></button>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>

                <?php if ($total_pages > 1): ?>
                <div class="pagination-wrapper">
                    <div class="pagination-info">Showing <?php echo ($offset + 1) . '–' . min($offset + $per_page, $total_records); ?> of <?php echo $total_records; ?> transactions</div>
                    <nav>
                        <ul class="pagination mb-0">
                            <?php
                            $prev = $page > 1 ? build_url(['page' => $page - 1]) : '#';
                            $next = $page < $total_pages ? build_url(['page' => $page + 1]) : '#';
                            ?>
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo $prev; ?>"><i class="fas fa-chevron-left"></i></a></li>
                            <?php for ($i = 1; $i <= $total_pages; $i++):
                                if ($total_pages > 7 && $i > 3 && $i < $total_pages - 1 && abs($i - $page) > 1) {
                                    if ($i == 4 || $i == $total_pages - 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                                    continue;
                                }
                            ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>"><a class="page-link" href="<?php echo build_url(['page' => $i]); ?>"><?php echo $i; ?></a></li>
                            <?php endfor; ?>
                            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo $next; ?>"><i class="fas fa-chevron-right"></i></a></li>
                        </ul>
                    </nav>
                </div>
                <?php elseif ($total_records > 0): ?>
                <div class="pagination-wrapper">
                    <div class="pagination-info">Showing all <?php echo $total_records; ?> transactions</div>
                    <div></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const allPayments = <?php echo json_encode($all_payments_js, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]'; ?>;

        function ucfirst(s){return s?s.charAt(0).toUpperCase()+s.slice(1):''}
        function sBadge(s){if(s==='paid')return'status-paid';if(s==='pending')return'status-pending';if(s==='failed')return'status-failed';if(s==='expired')return'status-expired';return''}
        function sLabel(s){return ucfirst(s||'unknown')}

        // ═══ VIEW PAYMENT ═══
        const viewOverlay = document.getElementById('viewOverlay');

        function viewPayment(id){
            const p = allPayments.find(x=>x.id==id);
            if(!p) return;

            document.getElementById('vId').textContent = '#'+p.id;
            document.getElementById('vOrderId').textContent = p.order_id||'-';
            document.getElementById('vUserName').textContent = p.user_name||'-';
            document.getElementById('vUserEmail').innerHTML = p.user_email?'<a href="mailto:'+p.user_email+'">'+p.user_email+'</a>':'-';
            document.getElementById('vType').innerHTML = '<span class="type-badge '+(p.type==='assignment'?'type-assignment':'type-plan')+'">'+ucfirst(p.type||'N/A')+'</span>';
            document.getElementById('vItem').textContent = p.item_name||'-';
            document.getElementById('vOrigAmt').textContent = 'RM '+parseFloat(p.original_amount||0).toFixed(2);
            document.getElementById('vDiscount').textContent = p.discount_amount>0?'RM '+parseFloat(p.discount_amount).toFixed(2):'RM 0.00';
            document.getElementById('vFinalAmt').textContent = 'RM '+parseFloat(p.final_amount||0).toFixed(2);
            document.getElementById('vStatus').innerHTML = '<span class="'+sBadge(p.status)+'">'+sLabel(p.status)+'</span>';
            document.getElementById('vMethod').textContent = p.toyyibpay_payment_method||'-';
            document.getElementById('vBillcode').textContent = p.toyyibpay_billcode||'-';
            document.getElementById('vVoucher').textContent = p.voucher_code||'None';
            document.getElementById('vDate').textContent = p.created_at||'-';

            const receiptWrap = document.getElementById('vReceiptWrap');
            const noticeWrap = document.getElementById('vNoticeWrap');

            if(p.status === 'paid'){
                // Populate generated receipt
                document.getElementById('grReceiptNo').textContent = 'RCP-'+String(p.id).padStart(5,'0');
                document.getElementById('grTxnId').textContent = '#'+p.id;
                document.getElementById('grOrderId').textContent = p.order_id||'-';
                document.getElementById('grDate').textContent = p.created_at||'-';
                document.getElementById('grCustomer').textContent = p.user_name||'Unknown';
                document.getElementById('grEmail').textContent = p.user_email||'-';
                document.getElementById('grType').textContent = ucfirst(p.type||'N/A');
                document.getElementById('grItem').textContent = p.item_name||'-';
                document.getElementById('grOrigAmt').textContent = 'RM '+parseFloat(p.original_amount||0).toFixed(2);
                const discAmt = parseFloat(p.discount_amount||0);
                document.getElementById('grDiscount').textContent = discAmt>0?'- RM '+discAmt.toFixed(2):'RM 0.00';
                document.getElementById('grMethod').textContent = p.toyyibpay_payment_method||'-';
                document.getElementById('grTotal').textContent = 'RM '+parseFloat(p.final_amount||0).toFixed(2);
                document.getElementById('grRef').textContent = 'Ref: '+p.order_id+' | #'+p.id;

                // Voucher row
                const vRow = document.getElementById('grVoucherRow');
                if(p.voucher_code && p.voucher_code.trim()!==''){
                    document.getElementById('grVoucher').textContent = p.voucher_code;
                    vRow.style.display = '';
                } else {
                    vRow.style.display = 'none';
                }

                // Original uploaded image
                const origWrap = document.getElementById('grOrigImgWrap');
                if(p.receipt_file && p.receipt_file.trim()!==''){
                    document.getElementById('grOrigViewer').innerHTML = '<img src="'+p.receipt_file+'" alt="Original receipt">';
                    origWrap.style.display = '';
                } else {
                    origWrap.style.display = 'none';
                }

                receiptWrap.style.display = '';
                noticeWrap.style.display = 'none';
            } else {
                receiptWrap.style.display = 'none';
                noticeWrap.style.display = '';
                document.getElementById('vNoticeStatus').textContent = sLabel(p.status);
            }

            viewOverlay.classList.add('show');
            document.body.style.overflow='hidden';
        }

        function closeView(){viewOverlay.classList.remove('show');document.body.style.overflow=''}
        document.getElementById('viewCloseBtn').addEventListener('click',closeView);
        document.getElementById('viewCloseBtn2').addEventListener('click',closeView);
        viewOverlay.addEventListener('click',function(e){if(e.target===viewOverlay)closeView()});

        // Delete Confirmation Modal
        const deleteOverlay = document.getElementById('deleteOverlay');
        const deleteTxIdSpan = document.getElementById('deleteTxId');
        const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
        const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
        let pendingDeleteId = null;

        document.querySelectorAll('.btn-delete-trigger').forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                pendingDeleteId = this.getAttribute('data-id');
                deleteTxIdSpan.textContent = '#' + pendingDeleteId;
                deleteOverlay.classList.add('show');
            });
        });

        function closeDeleteModal() {
            deleteOverlay.classList.remove('show');
            pendingDeleteId = null;
        }

        cancelDeleteBtn.addEventListener('click', closeDeleteModal);
        deleteOverlay.addEventListener('click', function(e) {
            if (e.target === deleteOverlay) closeDeleteModal();
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && deleteOverlay.classList.contains('show')) closeDeleteModal();
        });

        confirmDeleteBtn.addEventListener('click', function() {
            if (!pendingDeleteId) return;
            confirmDeleteBtn.disabled = true;
            confirmDeleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...';

            var formData = new FormData();
            formData.append('delete_id', pendingDeleteId);

            fetch('admin_payments.php', {
                method: 'POST',
                body: formData
            }).then(function() {
                window.location.href = 'admin_payments.php';
            }).catch(function() {
                confirmDeleteBtn.disabled = false;
                confirmDeleteBtn.innerHTML = '<i class="fas fa-trash-alt"></i> Yes, Delete';
            });
        });

        // ═══ EDIT PAYMENT ═══
        const editOverlay = document.getElementById('editOverlay');

        function editPayment(id){
            const p = allPayments.find(x=>x.id==id);
            if(!p) return;
            document.getElementById('eId').textContent = '#'+p.id;
            document.getElementById('eOrderId').textContent = p.order_id||'-';
            document.getElementById('eCurStatus').innerHTML = '<span class="'+sBadge(p.status)+'">'+sLabel(p.status)+'</span>';
            document.getElementById('eAmount').textContent = 'RM '+parseFloat(p.final_amount||0).toFixed(2);
            document.getElementById('ePayId').value = p.id;
            document.getElementById('eNewStatus').value = '';
            editOverlay.classList.add('show');
            document.body.style.overflow='hidden';
        }

        function closeEdit(){editOverlay.classList.remove('show');document.body.style.overflow=''}
        document.getElementById('editCloseBtn').addEventListener('click',closeEdit);
        document.getElementById('editCancelBtn').addEventListener('click',closeEdit);
        editOverlay.addEventListener('click',function(e){if(e.target===editOverlay)closeEdit()});

        document.getElementById('editSaveBtn').addEventListener('click',function(){
            const newSt = document.getElementById('eNewStatus').value;
            if(!newSt){
                document.getElementById('eNewStatus').style.borderColor='#F44336';
                document.getElementById('eNewStatus').focus();
                setTimeout(()=>{document.getElementById('eNewStatus').style.borderColor=''},2000);
                return;
            }
            document.getElementById('editForm').submit();
        });

        document.addEventListener('keydown',function(e){
            if(e.key==='Escape'){
                if(editOverlay.classList.contains('show'))closeEdit();
                else if(viewOverlay.classList.contains('show'))closeView();
            }
        });

        const ab=document.getElementById('alertBanner');
        if(ab){setTimeout(()=>{ab.style.opacity='0';ab.style.transition='opacity .5s'},4000);setTimeout(()=>{ab.remove()},4600)}

        const sidebar=document.getElementById('sidebar'),so=document.getElementById('sidebarOverlay');
        document.getElementById('sidebarToggle').addEventListener('click',()=>{sidebar.classList.toggle('show');so.classList.toggle('show')});
        so.addEventListener('click',()=>{sidebar.classList.remove('show');so.classList.remove('show')});

        const nw=document.querySelector('.notification-wrapper'),nd=document.getElementById('notiDropdown');
        if(nw&&nd){nw.addEventListener('click',function(e){e.stopPropagation();nd.classList.toggle('show')});document.addEventListener('click',function(){nd.classList.remove('show')})}

        const sp=document.getElementById('settingsPanel'),sov=document.getElementById('settingsOverlay');
        document.querySelectorAll('#settingsBtn,#settingsOverlay,#settingsCloseBtn').forEach(el=>{el.addEventListener('click',()=>{sp.classList.toggle('show');sov.classList.toggle('show')})});

        const tt=document.getElementById('themeToggle'),ht=document.documentElement;
        if(localStorage.getItem('theme')==='light'){ht.setAttribute('data-theme','light');if(tt)tt.checked=false}
        if(tt){tt.addEventListener('change',function(){const t=this.checked?'dark':'light';ht.setAttribute('data-theme',t);localStorage.setItem('theme',t)})}

        function ut(){const n=new Date(),o={weekday:'short',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'},el=document.getElementById('headerTime');if(el)el.textContent=n.toLocaleDateString('en-US',o)}
        ut();setInterval(ut,30000);

        const cc={paid:'#4CAF50',pending:'#FF9800',failed:'#F44336',expired:'#9E9E9E'};
        const dk=ht.getAttribute('data-theme')==='dark';
        const gc=dk?'rgba(255,255,255,0.06)':'rgba(0,0,0,0.06)';
        const tc=dk?'#9B8DB5':'#7B6B8D';

        if (typeof Chart === 'undefined') {
            document.querySelectorAll('.ch-body').forEach(function(el){
                el.innerHTML = '<div style="text-align:center;color:#E53935;font-size:13px;padding:20px"><i class="fas fa-triangle-exclamation"></i><br>Chart.js failed to load. Please check internet/CDN access.</div>';
            });
        } else {
            new Chart(document.getElementById('statusChart'),{type:'doughnut',data:{labels:['Paid','Pending','Failed','Expired'],datasets:[{data:[<?php echo (int)$dist['paid'];?>,<?php echo (int)$dist['pending'];?>,<?php echo (int)$dist['failed'];?>,<?php echo (int)$dist['expired'];?>],backgroundColor:[cc.paid,cc.pending,cc.failed,cc.expired],borderWidth:0,hoverOffset:8}]},options:{responsive:true,maintainAspectRatio:false,cutout:'65%',plugins:{legend:{position:'bottom',labels:{color:tc,padding:16,usePointStyle:true,pointStyleWidth:10,font:{family:'Poppins',size:12}}}}}});

            new Chart(document.getElementById('revenueChart'), {
    type: 'bar',
    data: {
        labels: ['Paid Revenue', 'Unpaid Amount'],
        datasets: [{
            label: 'RM',
            data: [<?php echo json_encode((float)$total_revenue); ?>, 0],
            backgroundColor: ['rgba(76,175,80,0.7)', 'rgba(244,67,54,0.5)'],
            borderRadius: 10,
            barThickness: 60
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false }
        },
        scales: {
            y: {
                beginAtZero: true,
                grid: { color: gc },
                ticks: {
                    color: tc,
                    font: { family: 'Poppins', size: 11 },
                    callback: v => 'RM ' + v
                }
            },
            x: {
                grid: { display: false },
                ticks: {
                    color: tc,
                    font: { family: 'Poppins', size: 12 }
                }
            }
        }
    }
});
        }
    </script>
</body>
</html>