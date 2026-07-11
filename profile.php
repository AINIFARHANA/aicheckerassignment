<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: register.php");
    exit();
}

$servername = "localhost";
$username   = "root";
$password   = "";               // Default XAMPP password
$dbname     = "assignment_db";  // Your local database name

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}


 $user_id = $_SESSION['user_id'];

require_once __DIR__ . '/subscription_helpers.php';
healMissingSubscriptionsForUser($conn, $user_id);

/* ── Fetch user data ── */
 $userData = [
    'name' => '',
    'email' => '',
    'user_type' => 'user',
    'avatar' => 'Felix',
    'created_at' => ''
];

 $stmt = $conn->prepare("SELECT name, email, user_type, avatar, created_at FROM users WHERE user_id = ?");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $userData = $row;
        $userData['avatar'] = !empty($row['avatar']) ? $row['avatar'] : 'Felix';
    }
    $stmt->close();
}

 $userName = $userData['name'] ?: "User";
 $userAvatarSeed = $userData['avatar'];

/* ════════════════════════════════════════════════════════
   FETCH USER'S ACTIVE PLAN
   (Sourced from user_subscriptions — the real source of truth —
   instead of guessing from the last paid_transaction's item_name,
   which broke as soon as an assignment payment became the most
   recent paid row.)
   ════════════════════════════════════════════════════════ */
 $activePlan = null;
 $planPurchaseDate = null;
 $planPaidAmount = 0;
 $planReceiptId = null;

 $subStmt = $conn->prepare("
    SELECT p.*, us.start_date, us.end_date, us.plan_id AS sub_plan_id
    FROM user_subscriptions us
    JOIN plans p ON us.plan_id = p.plan_id
    WHERE us.user_id = ? AND us.status = 'active' AND us.end_date > NOW()
    ORDER BY us.end_date DESC
    LIMIT 1
");
if ($subStmt) {
    $subStmt->bind_param("i", $user_id);
    $subStmt->execute();
    $activePlan = $subStmt->get_result()->fetch_assoc();
    $subStmt->close();
}

if ($activePlan) {
    /* Find the matching paid transaction for this plan, for the
       "Paid / Activated" meta info and the View Receipt link. */
    $ptStmt = $conn->prepare("
        SELECT id, paid_at, final_amount
        FROM payment_transactions
        WHERE user_id = ? AND type = 'plan' AND reference_id = ? AND status = 'paid'
        ORDER BY paid_at DESC
        LIMIT 1
    ");
    if ($ptStmt) {
        $ptStmt->bind_param("ii", $user_id, $activePlan['sub_plan_id']);
        $ptStmt->execute();
        $ptRow = $ptStmt->get_result()->fetch_assoc();
        $ptStmt->close();

        if ($ptRow) {
            $planReceiptId    = (int) $ptRow['id'];
            $planPurchaseDate = $ptRow['paid_at'];
            $planPaidAmount   = floatval($ptRow['final_amount']);
        }
    }

    if (empty($planPurchaseDate) && !empty($activePlan['start_date'])) {
        $planPurchaseDate = $activePlan['start_date'];
    }
}

/* Parse features into array */
 $planFeatures = [];
if ($activePlan && !empty($activePlan['features'])) {
    $raw = $activePlan['features'];
    $planFeatures = preg_split('/[\n\r]+/', $raw);
    $planFeatures = array_map('trim', $planFeatures);
    $planFeatures = array_filter($planFeatures, function($f) { return strlen($f) > 0; });
    $planFeatures = array_values($planFeatures);
}

 $planPurchaseFormatted = "N/A";
if (!empty($planPurchaseDate) && strtotime($planPurchaseDate) > 0) {
    $planPurchaseFormatted = date('d M Y, g:i A', strtotime($planPurchaseDate));
}

/* ── Max upload size for the active plan (Basic 10MB / Standard 30MB / Premium 100MB) ──
   Same keyword-matching logic used in assignments.php, kept in sync here so the
   profile page can show the limit that actually applies to the user right now. */
 $planMaxUploadMB = 10; // Basic / no active plan default
if ($activePlan) {
    $tiers = ['premium' => 100, 'standard' => 30, 'basic' => 10];
    $haystack = strtolower(($activePlan['plan_name'] ?? '') . ' ' . ($activePlan['badge'] ?? ''));
    foreach ($tiers as $keyword => $mb) {
        if (strpos($haystack, $keyword) !== false) {
            $planMaxUploadMB = $mb;
            break;
        }
    }
}

/* ── Handle POST update ── */
 $msgStatus = "";
 $msgText = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $avatar = trim($_POST['selected_avatar'] ?? '');

    if (empty($name)) {
        $msgStatus = "error"; $msgText = "Name is required.";
    } elseif (empty($email)) {
        $msgStatus = "error"; $msgText = "Email is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msgStatus = "error"; $msgText = "Invalid email format.";
    } elseif (strlen($name) > 100 || strlen($email) > 100) {
        $msgStatus = "error"; $msgText = "Name or Email too long.";
    } elseif (!empty($password) && strlen($password) < 6) {
        $msgStatus = "error"; $msgText = "Password must be at least 6 characters.";
    } else {
        $allowedAvatars = ['Felix', 'Annie', 'Bob', 'Cathy'];
        $hashedPassword = !empty($password) ? password_hash($password, PASSWORD_DEFAULT) : null;

        $_SESSION['username'] = $name;
        if (in_array($avatar, $allowedAvatars)) {
            $_SESSION['avatar'] = $avatar;
        }

        $sql = "UPDATE users SET name = ?, email = ?";
        $params = [$name, $email];
        $types = "ss";

        if ($hashedPassword) {
            $sql .= ", password = ?";
            $params[] = $hashedPassword;
            $types .= "s";
        }
        if (in_array($avatar, $allowedAvatars)) {
            $sql .= ", avatar = ?";
            $params[] = $avatar;
            $types .= "s";
        }

        $sql .= " WHERE user_id = ?";
        $params[] = $user_id;
        $types .= "i";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param($types, ...$params);
            if ($stmt->execute()) {
                $stmt->close();
                $stmt2 = $conn->prepare("SELECT name, email, user_type, avatar, created_at FROM users WHERE user_id = ?");
                $stmt2->bind_param("i", $user_id);
                $stmt2->execute();
                $result = $stmt2->get_result();
                if ($row = $result->fetch_assoc()) {
                    $userData = $row;
                    $userData['avatar'] = !empty($row['avatar']) ? $row['avatar'] : 'Felix';
                }
                $stmt2->close();
                $userName = $userData['name'];
                $userAvatarSeed = $userData['avatar'];
                $msgStatus = "success";
                $msgText = "Profile updated successfully!";
            } else {
                $msgStatus = "error"; $msgText = "Update failed: " . $stmt->error;
            }
        } else {
            $msgStatus = "error"; $msgText = "SQL prepare failed: " . $conn->error;
        }
    }
}

 $formattedDate = "N/A";
if (!empty($userData['created_at'])) {
    $formattedDate = date('F d, Y \a\t g:i A', strtotime($userData['created_at']));
}

function getAvatarUrl($seed) {
    return "https://api.dicebear.com/7.x/avataaars/svg?seed=" . urlencode($seed);
}

/* ── Now include header (uses existing $conn) ── */
include 'header.php';
?>

<style>
    .profile-area { padding: 40px 0 80px; min-height: 100vh; }

    .profile-breadcrumb {
        display: flex; align-items: center; gap: 8px;
        font-size: 0.82rem; margin-bottom: 8px;
    }
    .profile-breadcrumb a {
        color: var(--text-muted); text-decoration: none; transition: color 0.3s;
    }
    .profile-breadcrumb a:hover { color: var(--accent); }
    .profile-breadcrumb .sep { color: var(--text-muted); opacity: 0.4; }
    .profile-breadcrumb .current { color: var(--accent); font-weight: 600; }

    .profile-page-title {
        font-weight: 900; color: var(--text-bright);
        font-size: clamp(1.6rem, 3.5vw, 2.2rem);
        letter-spacing: -0.03em; margin-bottom: 4px;
    }
    .profile-page-title span {
        background: linear-gradient(135deg, var(--accent), var(--accent-rose));
        -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
    }
    .profile-page-sub { color: var(--text-muted); font-size: 0.92rem; margin-bottom: 40px; }

    .p-card {
        background: var(--bg-card); backdrop-filter: blur(16px);
        border: 1px solid var(--border-subtle); border-radius: var(--radius-lg);
        overflow: hidden; transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
    }
    .p-card::before {
        content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
        background: linear-gradient(90deg, transparent, var(--accent), var(--accent-rose), transparent);
        opacity: 0.6;
    }
    .p-card:hover {
        transform: translateY(-4px); border-color: var(--border-glow);
        box-shadow: var(--shadow-md), 0 0 30px rgba(244,209,255,0.04);
    }

    .avatar-ring-wrap {
        position: relative; width: 150px; height: 150px;
        margin: 0 auto 24px;
    }
    .avatar-ring-spin {
        position: absolute; inset: -6px; border-radius: 50%;
        background: conic-gradient(var(--accent), var(--accent-rose), var(--accent-gold), var(--accent));
        animation: ringRotate 6s linear infinite; opacity: 0.6;
    }
    @keyframes ringRotate { to { transform: rotate(360deg); } }
    .avatar-ring-pulse {
        position: absolute; inset: -14px; border-radius: 50%;
        border: 1px solid rgba(216,143,255,0.12);
        animation: ringPulse 3s ease-in-out infinite;
    }
    @keyframes ringPulse {
        0%, 100% { transform: scale(1); opacity: 0.3; }
        50% { transform: scale(1.06); opacity: 0.6; }
    }
    .avatar-circle {
        position: relative; z-index: 2;
        width: 150px; height: 150px; border-radius: 50%;
        overflow: hidden; border: 4px solid var(--bg-deep);
        background: var(--primary); transition: all 0.5s ease; cursor: pointer;
    }
    .avatar-circle:hover { transform: scale(1.06); box-shadow: 0 0 30px rgba(216,143,255,0.3); }
    .avatar-circle img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.5s ease; }
    .avatar-circle:hover img { transform: scale(1.1); }
    .status-dot {
        position: absolute; bottom: 8px; right: 8px;
        width: 18px; height: 18px; background: #22c55e;
        border: 3px solid var(--bg-deep); border-radius: 50%;
        z-index: 3; animation: statusPulse 2s ease-in-out infinite;
    }
    @keyframes statusPulse {
        0%, 100% { box-shadow: 0 0 0 0 rgba(34,197,94,0.4); }
        50% { box-shadow: 0 0 0 6px rgba(34,197,94,0); }
    }

    .p-name {
        font-weight: 800; color: var(--text-bright);
        font-size: 1.35rem; text-align: center; margin-bottom: 2px;
    }
    .p-email { color: var(--text-muted); font-size: 0.85rem; text-align: center; margin-bottom: 16px; }
    .type-badge {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 7px 18px; border-radius: 40px;
        font-size: 0.75rem; font-weight: 600;
        background: rgba(216,143,255,0.08); color: var(--accent);
        border: 1px solid rgba(216,143,255,0.15);
        letter-spacing: 0.06em; text-transform: uppercase;
    }
    .type-badge i { font-size: 0.7rem; }

    .p-divider {
        height: 1px; margin: 24px 0;
        background: linear-gradient(90deg, transparent, var(--border-subtle), rgba(216,143,255,0.1), var(--border-subtle), transparent);
    }

    .p-info {
        display: flex; align-items: center; gap: 14px;
        padding: 12px 14px; border-radius: var(--radius-sm);
        transition: all 0.35s ease; cursor: default;
    }
    .p-info:hover { background: rgba(244,209,255,0.04); }
    .p-info-icon {
        width: 40px; height: 40px; min-width: 40px;
        background: rgba(216,143,255,0.08); border: 1px solid rgba(216,143,255,0.1);
        border-radius: 12px; display: flex; align-items: center; justify-content: center;
        color: var(--accent); font-size: 0.85rem; transition: all 0.35s ease;
    }
    .p-info:hover .p-info-icon {
        background: linear-gradient(135deg, var(--primary-mid), var(--accent));
        border-color: transparent; color: white;
        transform: scale(1.08) rotate(-5deg);
        box-shadow: 0 0 16px rgba(216,143,255,0.3);
    }
    .p-info-label {
        font-size: 0.68rem; color: var(--text-muted);
        font-weight: 600; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 1px;
    }
    .p-info-val { font-weight: 600; color: var(--text-light); font-size: 0.88rem; }

    .edit-hdr {
        padding: 22px 28px; border-bottom: 1px solid var(--border-subtle);
        display: flex; align-items: center; gap: 16px;
    }
    .edit-hdr-icon {
        width: 48px; height: 48px; min-width: 48px;
        background: rgba(216,143,255,0.1); border: 1px solid rgba(216,143,255,0.15);
        border-radius: 14px; display: flex; align-items: center; justify-content: center;
        font-size: 1.1rem; color: var(--accent);
    }
    .edit-hdr-title { font-weight: 800; color: var(--text-bright); font-size: 1.12rem; margin-bottom: 1px; }
    .edit-hdr-sub { font-size: 0.78rem; color: var(--text-muted); }

    .p-alert {
        border: none; border-radius: var(--radius-sm);
        font-size: 0.86rem; font-weight: 500; padding: 14px 18px;
        display: flex; align-items: center; gap: 10px; margin-bottom: 22px;
        animation: alertSlide 0.5s ease; position: relative; overflow: hidden;
    }
    @keyframes alertSlide {
        0% { opacity: 0; transform: translateY(-10px); }
        100% { opacity: 1; transform: translateY(0); }
    }
    .p-alert-success {
        background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.2); color: #6ee7b7;
    }
    .p-alert-success::before {
        content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: #10b981;
    }
    .p-alert-error {
        background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.2); color: #fca5a5;
    }
    .p-alert-error::before {
        content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: #ef4444;
    }
    .p-alert .close-alert {
        margin-left: auto; background: none; border: none;
        cursor: pointer; font-size: 1.2rem; opacity: 0.4;
        transition: opacity 0.2s; color: inherit; padding: 0 0 0 8px;
    }
    .p-alert .close-alert:hover { opacity: 1; }

    .p-form-group { margin-bottom: 20px; }
    .p-label {
        font-size: 0.78rem; color: var(--text-light); font-weight: 600;
        margin-bottom: 8px; display: flex; align-items: center; gap: 6px;
    }
    .p-label .req { color: var(--accent-rose); }
    .p-label .opt { color: var(--text-muted); font-weight: 400; font-size: 0.72rem; }
    .p-label i { font-size: 0.7rem; color: var(--accent); }
    .p-input-wrap { position: relative; }
    .p-input-wrap .ico-left {
        position: absolute; left: 16px; top: 50%; transform: translateY(-50%);
        color: var(--text-muted); font-size: 0.88rem; pointer-events: none; transition: color 0.3s;
    }
    .p-input {
        width: 100%; background: rgba(244,209,255,0.03);
        border: 1.5px solid var(--border-subtle); border-radius: 12px;
        padding: 13px 16px 13px 44px; color: var(--text-bright);
        font-size: 0.9rem; font-family: 'Poppins', sans-serif;
        outline: none; transition: all 0.35s ease;
    }
    .p-input::placeholder { color: var(--text-muted); opacity: 0.5; }
    .p-input:focus {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(216,143,255,0.1), 0 0 20px rgba(216,143,255,0.05);
        background: rgba(244,209,255,0.05);
    }
    .p-input:focus ~ .ico-left { color: var(--accent); }
    .p-input.has-right { padding-right: 48px; }
    .p-input.input-err { border-color: rgba(239,68,68,0.5); box-shadow: 0 0 0 3px rgba(239,68,68,0.08); }
    .toggle-pw {
        position: absolute; right: 14px; top: 50%; transform: translateY(-50%);
        background: none; border: none; color: var(--text-muted);
        cursor: pointer; padding: 4px 6px; font-size: 0.92rem; transition: color 0.3s;
    }
    .toggle-pw:hover { color: var(--accent); }
    .p-field-err {
        font-size: 0.74rem; color: #f87171; margin-top: 5px;
        display: none; align-items: center; gap: 5px;
    }
    .p-field-err.show { display: flex; }
    .p-field-err i { font-size: 0.68rem; }

    .avatar-sel-label {
        font-size: 0.78rem; color: var(--text-light); font-weight: 600;
        margin-bottom: 16px; display: flex; align-items: center; gap: 8px;
    }
    .avatar-sel-label i { color: var(--accent); }
    .avatar-sel-preview {
        display: flex; flex-direction: column; align-items: center; margin-bottom: 20px;
    }
    .avatar-sel-preview-img {
        width: 84px; height: 84px; border-radius: 50%; overflow: hidden;
        border: 2px solid rgba(216,143,255,0.25); background: var(--primary);
        box-shadow: 0 0 20px rgba(216,143,255,0.1); margin-bottom: 6px;
        transition: all 0.4s ease;
    }
    .avatar-sel-preview-img img { width: 100%; height: 100%; object-fit: cover; }
    .avatar-sel-preview-label {
        font-size: 0.68rem; color: var(--text-muted);
        text-transform: uppercase; letter-spacing: 0.1em; font-weight: 500;
    }
    .avatar-grid {
        display: flex; gap: 16px; flex-wrap: wrap; justify-content: center;
    }
    .avatar-opt-wrap { display: flex; flex-direction: column; align-items: center; }
    .avatar-opt {
        width: 76px; height: 76px; border-radius: 50%;
        border: 3px solid var(--border-subtle); cursor: pointer;
        transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        background: var(--primary); padding: 3px; position: relative;
        z-index: 1;
    }
    .avatar-opt::after {
        content: ''; position: absolute; inset: -6px;
        border-radius: 50%; border: 2px solid transparent;
        transition: all 0.35s ease;
        pointer-events: none;
    }
    .avatar-opt img {
        width: 100%; height: 100%; border-radius: 50%; object-fit: cover;
        pointer-events: none;
    }
    .avatar-opt:hover {
        transform: translateY(-5px) scale(1.08); border-color: var(--accent-rose);
        box-shadow: 0 8px 24px rgba(216,143,255,0.2);
    }
    .avatar-opt.picked {
        border-color: var(--accent);
        box-shadow: 0 0 0 4px rgba(216,143,255,0.15), 0 0 24px rgba(216,143,255,0.25);
        transform: scale(1.1);
    }
    .avatar-opt.picked::after { border-color: rgba(216,143,255,0.2); }
    .avatar-opt-wrap.active .avatar-opt-name {
        color: var(--accent) !important;
        font-weight: 600;
    }
    .avatar-opt-name {
        display: block; text-align: center; font-size: 0.68rem;
        color: var(--text-muted); margin-top: 8px; font-weight: 500; transition: color 0.3s;
    }
    .avatar-opt-wrap:hover .avatar-opt-name { color: var(--primary-light); }

    .btn-save-profile {
        background: linear-gradient(135deg, var(--accent), var(--accent-rose));
        color: white; border: none; padding: 14px 36px; border-radius: 40px;
        font-weight: 700; font-size: 0.92rem; font-family: 'Poppins', sans-serif;
        transition: all 0.4s ease; cursor: pointer;
        box-shadow: 0 4px 24px rgba(216,143,255,0.3);
        position: relative; overflow: hidden;
        display: inline-flex; align-items: center; gap: 10px;
    }
    .btn-save-profile::before {
        content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent);
        transition: left 0.6s;
    }
    .btn-save-profile:hover::before { left: 100%; }
    .btn-save-profile:hover {
        transform: translateY(-3px);
        box-shadow: 0 8px 36px rgba(216,143,255,0.45);
    }
    .btn-save-profile:active { transform: translateY(-1px); }
    .btn-save-profile .spin {
        display: none; width: 18px; height: 18px;
        border: 2.5px solid rgba(255,255,255,0.3); border-top-color: white;
        border-radius: 50%; animation: spin 0.7s linear infinite;
    }
    .btn-save-profile.loading .spin { display: block; }
    .btn-save-profile.loading .btn-txt { display: none; }
    @keyframes spin { to { transform: rotate(360deg); } }

    .btn-reset-profile {
        background: transparent; border: 1.5px solid var(--border-subtle);
        color: var(--text-light); padding: 14px 30px; border-radius: 40px;
        font-weight: 600; font-size: 0.92rem; font-family: 'Poppins', sans-serif;
        transition: all 0.35s ease; display: inline-flex; align-items: center; gap: 10px;
        text-decoration: none; cursor: pointer;
    }
    .btn-reset-profile:hover {
        border-color: var(--accent); color: var(--accent);
        background: rgba(244,209,255,0.04); transform: translateY(-2px);
    }

    /* ══════════════════════════════════════════
       PLAN CARD STYLES
       ══════════════════════════════════════════ */
    .plan-card {
        background: var(--bg-card);
        backdrop-filter: blur(16px);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius-lg);
        overflow: hidden;
        position: relative;
        transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .plan-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0; height: 2px;
        background: linear-gradient(90deg, transparent, #22c55e, #10b981, transparent);
        opacity: 0.7;
    }
    .plan-card:hover {
        transform: translateY(-4px);
        border-color: rgba(34,197,94,0.3);
        box-shadow: var(--shadow-md), 0 0 30px rgba(34,197,94,0.06);
    }

    .plan-card-banner {
        position: relative;
        padding: 20px 24px 16px;
        background: linear-gradient(135deg, rgba(34,197,94,0.08) 0%, rgba(16,185,129,0.04) 100%);
        border-bottom: 1px solid rgba(34,197,94,0.1);
        display: flex;
        align-items: center;
        gap: 14px;
    }
    .plan-card-banner::after {
        content: '';
        position: absolute;
        top: -30px; right: -30px;
        width: 100px; height: 100px;
        border-radius: 50%;
        background: rgba(34,197,94,0.06);
        pointer-events: none;
    }
    .plan-card-img {
        width: 52px; height: 52px;
        border-radius: 14px;
        object-fit: cover;
        border: 2px solid rgba(34,197,94,0.2);
        flex-shrink: 0;
        background: rgba(34,197,94,0.08);
        display: flex; align-items: center; justify-content: center;
        overflow: hidden;
    }
    .plan-card-img img {
        width: 100%; height: 100%; object-fit: cover;
    }
    .plan-card-img .plan-icon-fallback {
        font-size: 1.4rem; color: #22c55e;
    }
    .plan-card-banner-text { flex: 1; min-width: 0; position: relative; z-index: 1; }
    .plan-card-banner-label {
        font-size: 0.6rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: 0.14em; color: #22c55e; margin-bottom: 2px;
        display: flex; align-items: center; gap: 6px;
    }
    .plan-card-banner-label .live-dot {
        width: 6px; height: 6px; border-radius: 50%;
        background: #22c55e;
        animation: statusPulse 2s ease-in-out infinite;
        flex-shrink: 0;
    }
    .plan-card-banner-name {
        font-weight: 800; color: var(--text-bright);
        font-size: 1.05rem; letter-spacing: -0.01em;
        display: flex; align-items: center; gap: 8px;
        flex-wrap: wrap;
    }
    .plan-badge-tag {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 2px 10px; border-radius: 100px;
        font-size: 0.55rem; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.06em;
        background: linear-gradient(135deg, rgba(251,191,36,0.15), rgba(245,158,11,0.1));
        color: #fbbf24;
        border: 1px solid rgba(251,191,36,0.2);
    }
    .plan-badge-tag.popular {
        background: linear-gradient(135deg, rgba(216,143,255,0.15), rgba(244,63,94,0.1));
        color: var(--accent);
        border-color: rgba(216,143,255,0.2);
    }

    .plan-card-body { padding: 18px 24px; }

    .plan-price-row {
        display: flex; align-items: baseline; gap: 6px;
        margin-bottom: 14px;
    }
    .plan-currency {
        font-size: 0.85rem; font-weight: 600; color: var(--text-muted);
    }
    .plan-price-amount {
        font-size: 1.8rem; font-weight: 900; color: var(--text-bright);
        letter-spacing: -0.03em; line-height: 1;
        font-family: 'Space Grotesk', sans-serif;
    }
    .plan-duration {
        font-size: 0.72rem; font-weight: 500; color: var(--text-muted);
        background: rgba(244,209,255,0.04);
        padding: 3px 10px; border-radius: 6px;
        border: 1px solid var(--border-subtle);
    }

    .plan-features-title {
        font-size: 0.6rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: 0.12em; color: var(--text-muted);
        margin-bottom: 8px; padding-bottom: 6px;
        border-bottom: 1px solid var(--border-subtle);
    }
    .plan-features-list {
        list-style: none; padding: 0; margin: 0 0 16px;
    }
    .plan-features-list li {
        display: flex; align-items: flex-start; gap: 10px;
        padding: 5px 0; font-size: 0.78rem; color: var(--text-light);
        font-weight: 500;
    }
    .plan-features-list li .feat-icon {
        width: 18px; height: 18px; min-width: 18px;
        border-radius: 50%; margin-top: 1px;
        background: rgba(34,197,94,0.1);
        border: 1px solid rgba(34,197,94,0.2);
        display: flex; align-items: center; justify-content: center;
        color: #22c55e; font-size: 0.5rem;
    }

    .plan-card-footer {
        padding: 14px 24px;
        border-top: 1px solid var(--border-subtle);
        background: rgba(244,209,255,0.02);
        display: flex; flex-direction: column; gap: 6px;
    }
    .plan-meta-row {
        display: flex; align-items: center; gap: 8px;
        font-size: 0.68rem; color: var(--text-muted);
    }
    .plan-meta-row i {
        font-size: 0.62rem; color: var(--accent); width: 14px;
        text-align: center; flex-shrink: 0;
    }
    .plan-meta-row span { font-weight: 500; }
    .plan-view-receipt-btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 6px;
        margin-top: 6px; padding: 8px 14px;
        background: rgba(216,143,255,0.1); border: 1px solid rgba(216,143,255,0.3);
        border-radius: 8px; color: var(--accent);
        font-size: 0.74rem; font-weight: 600; text-decoration: none;
        transition: all 0.25s ease;
    }
    .plan-view-receipt-btn:hover {
        background: var(--accent); color: #fff; border-color: var(--accent);
    }
    .plan-view-receipt-btn i { font-size: 0.7rem; }

    /* No plan state */
    .no-plan-card {
        background: var(--bg-card);
        backdrop-filter: blur(16px);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius-lg);
        padding: 32px 24px;
        text-align: center;
        position: relative;
        overflow: hidden;
        transition: all 0.5s ease;
    }
    .no-plan-card::before {
        content: '';
        position: absolute; top: 0; left: 0; right: 0; height: 2px;
        background: linear-gradient(90deg, transparent, var(--text-muted), transparent);
        opacity: 0.3;
    }
    .no-plan-icon {
        width: 60px; height: 60px; margin: 0 auto 16px;
        border-radius: 16px;
        background: rgba(244,209,255,0.04);
        border: 1px dashed var(--border-subtle);
        display: flex; align-items: center; justify-content: center;
        font-size: 1.4rem; color: var(--text-muted);
    }
    .no-plan-title {
        font-weight: 700; color: var(--text-light);
        font-size: 0.95rem; margin-bottom: 4px;
    }
    .no-plan-sub {
        font-size: 0.78rem; color: var(--text-muted);
        margin-bottom: 18px; line-height: 1.5;
    }
    .btn-view-plans {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 10px 24px; border-radius: 40px;
        background: rgba(216,143,255,0.08);
        border: 1px solid rgba(216,143,255,0.15);
        color: var(--accent); font-weight: 600;
        font-size: 0.78rem; text-decoration: none;
        transition: all 0.35s ease; cursor: pointer;
        font-family: 'Poppins', sans-serif;
    }
    .btn-view-plans:hover {
        background: rgba(216,143,255,0.14);
        border-color: rgba(216,143,255,0.3);
        transform: translateY(-2px);
        box-shadow: 0 4px 16px rgba(216,143,255,0.15);
    }
    .btn-view-plans i { font-size: 0.7rem; }

    .plan-discount-badge {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 3px 10px; border-radius: 6px;
        font-size: 0.65rem; font-weight: 700;
        background: rgba(34,197,94,0.08);
        color: #22c55e;
        border: 1px solid rgba(34,197,94,0.15);
        margin-left: 4px;
    }

    @media (max-width: 991px) {
        .profile-area { padding: 30px 0 60px; }
    }
    @media (max-width: 767px) {
        .profile-area { padding: 20px 0 50px; }
        .avatar-ring-wrap { width: 120px; height: 120px; }
        .avatar-circle { width: 120px; height: 120px; }
        .p-name { font-size: 1.15rem; }
        .avatar-opt { width: 64px; height: 64px; }
        .avatar-sel-preview-img { width: 70px; height: 70px; }
        .p-card, .plan-card, .no-plan-card { border-radius: var(--radius-md); }
        .btn-save-profile, .btn-reset-profile { width: 100%; justify-content: center; }
        .btn-row { flex-direction: column; }
        .plan-price-amount { font-size: 1.5rem; }
        .plan-card-banner { padding: 16px 18px 14px; }
        .plan-card-body { padding: 14px 18px; }
        .plan-card-footer { padding: 12px 18px; }
    }
    @media (prefers-reduced-motion: reduce) {
        .avatar-ring-spin { animation: none; }
        .avatar-ring-pulse { animation: none; }
        .status-dot { animation: none; }
        .btn-save-profile::before { display: none; }
        .plan-card-banner-label .live-dot { animation: none; }
    }
</style>

<section class="profile-area">
    <div class="container">
        <div class="profile-breadcrumb" data-aos="fade-up">
            <a href="index.php"><i class="fa-solid fa-house" style="font-size:0.72rem;"></i> Home</a>
            <span class="sep">/</span>
            <span class="current">My Profile</span>
        </div>
        <h1 class="profile-page-title" data-aos="fade-up">My <span>Profile</span></h1>
        <p class="profile-page-sub" data-aos="fade-up">Manage your account settings and personalize your experience.</p>

        <div class="row g-4 justify-content-center">
            <!-- LEFT: Profile Display -->
            <div class="col-lg-5 col-xl-4" data-aos="fade-up" data-aos-delay="0">
                <div class="p-card" style="padding: 36px 28px 28px;">
                    <div class="avatar-ring-wrap">
                        <div class="avatar-ring-spin"></div>
                        <div class="avatar-ring-pulse"></div>
                        <div class="avatar-circle" title="Your Avatar">
                            <img src="<?php echo getAvatarUrl($userAvatarSeed); ?>&t=<?php echo time(); ?>" alt="Avatar" id="sideAvatarImg">
                        </div>
                        <div class="status-dot" title="Online"></div>
                    </div>

                    <div class="p-name" id="sideName"><?php echo htmlspecialchars($userData['name']); ?></div>
                    <div class="p-email" id="sideEmail"><?php echo htmlspecialchars($userData['email']); ?></div>

                    <div style="display:flex;justify-content:center;margin-bottom:4px;">
                        <span class="type-badge"><i class="fa-solid fa-shield-halved"></i> <?php echo ucfirst($userData['user_type']); ?></span>
                    </div>

                    <div class="p-divider"></div>

                    <div class="p-info">
                        <div class="p-info-icon"><i class="fa-solid fa-user"></i></div>
                        <div>
                            <div class="p-info-label">Full Name</div>
                            <div class="p-info-val" id="infoName"><?php echo htmlspecialchars($userData['name']); ?></div>
                        </div>
                    </div>
                    <div class="p-info">
                        <div class="p-info-icon"><i class="fa-solid fa-envelope"></i></div>
                        <div>
                            <div class="p-info-label">Email Address</div>
                            <div class="p-info-val" id="infoEmail"><?php echo htmlspecialchars($userData['email']); ?></div>
                        </div>
                    </div>
                    <div class="p-info">
                        <div class="p-info-icon"><i class="fa-solid fa-user-tag"></i></div>
                        <div>
                            <div class="p-info-label">Account Type</div>
                            <div class="p-info-val"><?php echo ucfirst($userData['user_type']); ?></div>
                        </div>
                    </div>
                    <div class="p-info">
                        <div class="p-info-icon"><i class="fa-solid fa-calendar-check"></i></div>
                        <div>
                            <div class="p-info-label">Member Since</div>
                            <div class="p-info-val"><?php echo $formattedDate; ?></div>
                        </div>
                    </div>
                    <div class="p-info">
                        <div class="p-info-icon"><i class="fa-solid fa-fingerprint"></i></div>
                        <div>
                            <div class="p-info-label">User ID</div>
                            <div class="p-info-val" style="font-family:'Space Grotesk',monospace;letter-spacing:0.03em;">#<?php echo str_pad($user_id, 5, '0', STR_PAD_LEFT); ?></div>
                        </div>
                    </div>
                </div>

                <!-- ════════════════════════════════════════
                     PLAN CARD (below profile card)
                     ════════════════════════════════════════ -->
                <div style="margin-top: 20px;" data-aos="fade-up" data-aos-delay="100">
                    <?php if ($activePlan): ?>
                        <div class="plan-card">
                            <!-- Banner -->
                            <div class="plan-card-banner">
                                <div class="plan-card-img">
                                <?php 
                                $planImgSrc = '';
                                $hasValidImage = false;
                                
                                if (!empty($activePlan['plan_image'])) {
                                    $rawImg = trim($activePlan['plan_image']);
                                    
                                    // Build proper path if it's a relative filename (no http/https and no leading /)
                                    if (!preg_match('/^https?:\/\//i', $rawImg) && !str_starts_with($rawImg, '/')) {
                                        // Adjust this folder path to match where your plan images are stored
                                        $planImgSrc = 'uploads/plans/' . $rawImg;
                                    } elseif (str_starts_with($rawImg, '/')) {
                                        $planImgSrc = $rawImg;
                                    } else {
                                        $planImgSrc = $rawImg; // Full URL
                                    }
                                    
                                    // Verify local file exists
                                    $localPath = __DIR__ . '/' . $planImgSrc;
                                    if (preg_match('/^https?:\/\//i', $planImgSrc)) {
                                        $hasValidImage = true; // External URL - can't verify easily, show it
                                    } elseif (file_exists($localPath)) {
                                        $hasValidImage = true;
                                    }
                                }
                                
                                if ($hasValidImage): ?>
                                    <img src="<?php echo htmlspecialchars($planImgSrc); ?>" 
                                        alt="<?php echo htmlspecialchars($activePlan['plan_name']); ?>"
                                        onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                    <i class="fa-solid fa-crown plan-icon-fallback" style="display:none;"></i>
                                <?php else: ?>
                                    <i class="fa-solid fa-crown plan-icon-fallback"></i>
                                <?php endif; ?>
                            </div>
                                <div class="plan-card-banner-text">
                                    <div class="plan-card-banner-label">
                                        <span class="live-dot"></span> Active Plan
                                    </div>
                                    <div class="plan-card-banner-name">
                                        <?php echo htmlspecialchars($activePlan['plan_name']); ?>
                                        <?php if (!empty($activePlan['badge'])): ?>
                                            <span class="plan-badge-tag <?php echo strtolower($activePlan['badge']) === 'popular' ? 'popular' : ''; ?>">
                                                <?php echo htmlspecialchars($activePlan['badge']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Body -->
                            <div class="plan-card-body">
                                <div class="plan-price-row">
                                    <span class="plan-currency">RM</span>
                                    <span class="plan-price-amount"><?php echo number_format(floatval($activePlan['price']), 2); ?></span>
                                    <?php if (!empty($activePlan['duration'])): ?>
                                        <span class="plan-duration">
                                            <i class="fa-regular fa-clock" style="margin-right:3px;font-size:0.62rem;"></i>
                                            <?php echo htmlspecialchars($activePlan['duration']); ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if (floatval($activePlan['assignment_discount']) > 0): ?>
                                        <span class="plan-discount-badge">
                                            <i class="fa-solid fa-tag" style="font-size:0.55rem;"></i>
                                            <?php echo floatval($activePlan['assignment_discount']); ?>% off
                                        </span>
                                    <?php endif; ?>
                                    <span class="plan-discount-badge" style="background:rgba(96,165,250,0.12);border-color:rgba(96,165,250,0.3);color:#60a5fa;">
                                        <i class="fa-solid fa-file-arrow-up" style="font-size:0.55rem;"></i>
                                        Up to <?php echo (int) $planMaxUploadMB; ?>MB per file
                                    </span>
                                </div>

                                <?php if (!empty($planFeatures)): ?>
                                    <div class="plan-features-title">What's Included</div>
                                    <ul class="plan-features-list">
                                        <?php foreach ($planFeatures as $feat): ?>
                                            <li>
                                                <span class="feat-icon"><i class="fa-solid fa-check"></i></span>
                                                <span><?php echo htmlspecialchars($feat); ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                            <?php 
                            // DEBUG - Remove after fixing
                            if ($activePlan && !empty($activePlan['plan_image'])) {
                                $rawPath = $activePlan['plan_image'];
                                $fullPath = __DIR__ . '/' . $rawPath;
                                $altPath = __DIR__ . '/uploads/plans/' . $rawPath;
                                echo '<!-- DEBUG plan_image: raw="' . $rawPath . '" exists=' . (file_exists($fullPath) ? 'YES' : 'NO') . ' alt_exists=' . (file_exists($altPath) ? 'YES' : 'NO') . ' -->';
                            }
                            ?>

                            <!-- Footer meta -->
                            <div class="plan-card-footer">
                                <div class="plan-meta-row">
                                    <i class="fa-solid fa-receipt"></i>
                                    <span>Paid: RM <?php echo number_format($planPaidAmount, 2); ?></span>
                                </div>
                                <div class="plan-meta-row">
                                    <i class="fa-regular fa-calendar"></i>
                                    <span>Activated: <?php echo $planPurchaseFormatted; ?></span>
                                </div>
                                <?php if (!empty($planReceiptId)): ?>
                                <a href="view_receipt.php?id=<?php echo (int) $planReceiptId; ?>" target="_blank" class="plan-view-receipt-btn">
                                    <i class="fa-solid fa-file-invoice"></i> View Receipt
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- No active plan -->
                        <div class="no-plan-card">
                            <div class="no-plan-icon">
                                <i class="fa-solid fa-box-open"></i>
                            </div>
                            <div class="no-plan-title">No Active Plan</div>
                            <div class="no-plan-sub">You haven't subscribed to any plan yet. Uploads are limited to <?php echo (int) $planMaxUploadMB; ?>MB per file until you subscribe. Browse our plans to get started.</div>
                            <a href="plan.php" class="btn-view-plans">
                                <i class="fa-solid fa-arrow-right"></i> View Plans
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- RIGHT: Edit Form -->
            <div class="col-lg-7 col-xl-8" data-aos="fade-up" data-aos-delay="100">
                <div class="p-card">
                    <div class="edit-hdr">
                        <div class="edit-hdr-icon"><i class="fa-solid fa-pen-to-square"></i></div>
                        <div>
                            <div class="edit-hdr-title">Edit Profile</div>
                            <div class="edit-hdr-sub">Update your personal information and avatar</div>
                        </div>
                    </div>

                    <div style="padding: 28px;">

                        <?php if ($msgStatus === "success"): ?>
                            <div class="p-alert p-alert-success" id="alertBox">
                                <i class="fa-solid fa-circle-check" style="font-size:1rem;"></i>
                                <span><?php echo htmlspecialchars($msgText); ?></span>
                                <button class="close-alert" onclick="this.parentElement.style.display='none'">&times;</button>
                            </div>
                        <?php elseif ($msgStatus === "error"): ?>
                            <div class="p-alert p-alert-error" id="alertBox">
                                <i class="fa-solid fa-circle-exclamation" style="font-size:1rem;"></i>
                                <span><?php echo htmlspecialchars($msgText); ?></span>
                                <button class="close-alert" onclick="this.parentElement.style.display='none'">&times;</button>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="profile.php" id="profileForm" novalidate>

                            <input type="hidden" name="update_profile" value="1">

                            <!-- Name -->
                            <div class="p-form-group">
                                <label class="p-label">
                                    <i class="fa-solid fa-user"></i> Full Name <span class="req">*</span>
                                </label>
                                <div class="p-input-wrap">
                                    <input type="text" name="name" class="p-input" id="nameInput"
                                           placeholder="Enter your full name"
                                           value="<?php echo htmlspecialchars($userData['name']); ?>"
                                           required maxlength="100" autocomplete="name">
                                    <i class="fa-solid fa-user ico-left"></i>
                                </div>
                                <div class="p-field-err" id="nameErr"><i class="fa-solid fa-triangle-exclamation"></i><span>Please enter your name.</span></div>
                            </div>

                            <!-- Email -->
                            <div class="p-form-group">
                                <label class="p-label">
                                    <i class="fa-solid fa-envelope"></i> Email Address <span class="req">*</span>
                                </label>
                                <div class="p-input-wrap">
                                    <input type="email" name="email" class="p-input" id="emailInput"
                                           placeholder="Enter your email address"
                                           value="<?php echo htmlspecialchars($userData['email']); ?>"
                                           required maxlength="100" autocomplete="email">
                                    <i class="fa-solid fa-envelope ico-left"></i>
                                </div>
                                <div class="p-field-err" id="emailErr"><i class="fa-solid fa-triangle-exclamation"></i><span>Please enter a valid email.</span></div>
                            </div>

                            <!-- Password -->
                            <div class="p-form-group">
                                <label class="p-label">
                                    <i class="fa-solid fa-lock"></i> New Password
                                    <span class="opt">(leave blank to keep current)</span>
                                </label>
                                <div class="p-input-wrap">
                                    <input type="password" name="password" class="p-input has-right" id="passwordInput"
                                           placeholder="Min 6 characters" minlength="6" autocomplete="new-password">
                                    <i class="fa-solid fa-lock ico-left"></i>
                                    <button type="button" class="toggle-pw" id="togglePw" aria-label="Toggle password">
                                        <i class="fa-solid fa-eye" id="eyeIcon"></i>
                                    </button>
                                </div>
                                <div class="p-field-err" id="passwordErr"><i class="fa-solid fa-triangle-exclamation"></i><span>Password must be at least 6 characters.</span></div>
                            </div>

                            <div class="p-divider"></div>

                            <!-- Avatar Selection -->
                            <div class="p-form-group">
                                <div class="avatar-sel-label"><i class="fa-solid fa-palette"></i> Choose Your Avatar</div>

                                <div class="avatar-sel-preview">
                                    <div class="avatar-sel-preview-img">
                                        <img src="<?php echo getAvatarUrl($userAvatarSeed); ?>&t=<?php echo time(); ?>" alt="Preview" id="avatarPreviewImg">
                                    </div>
                                    <span class="avatar-sel-preview-label">Preview</span>
                                </div>

                                <input type="hidden" name="selected_avatar" id="selectedAvatar" value="<?php echo htmlspecialchars($userAvatarSeed); ?>">

                                <div class="avatar-grid" id="avatarGrid">
                                    <?php
                                    $avatars = ['Felix', 'Annie', 'Bob', 'Cathy'];
                                    foreach ($avatars as $av):
                                        $isSelected = ($av === $userAvatarSeed) ? ' picked' : '';
                                        $isActive  = ($av === $userAvatarSeed) ? ' active' : '';
                                    ?>
                                        <div class="avatar-opt-wrap<?php echo $isActive; ?>">
                                            <div class="avatar-opt<?php echo $isSelected; ?>"
                                                 data-seed="<?php echo $av; ?>">
                                                <img src="<?php echo getAvatarUrl($av); ?>" alt="<?php echo $av; ?>">
                                            </div>
                                            <span class="avatar-opt-name"><?php echo $av; ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <div class="p-divider"></div>

                            <!-- Buttons -->
                            <div class="d-flex gap-3 flex-wrap btn-row">
                                <button type="submit" class="btn-save-profile" id="saveBtn">
                                    <span class="btn-txt"><i class="fa-solid fa-check"></i> Save Changes</span>
                                    <div class="spin"></div>
                                </button>
                                <button type="button" class="btn-reset-profile" id="resetBtn">
                                    <i class="fa-solid fa-rotate-left"></i> Reset
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<div style="height:40px;"></div>

<?php include 'footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {

    var form        = document.getElementById('profileForm');
    var saveBtn     = document.getElementById('saveBtn');
    var resetBtn    = document.getElementById('resetBtn');
    var avatarGrid  = document.getElementById('avatarGrid');
    var hiddenInput = document.getElementById('selectedAvatar');
    var previewImg  = document.getElementById('avatarPreviewImg');
    var sideImg     = document.getElementById('sideAvatarImg');
    var togglePw    = document.getElementById('togglePw');
    var pwInput     = document.getElementById('passwordInput');
    var eyeIcon     = document.getElementById('eyeIcon');

    /* ══════════════════════════════
       AVATAR SELECTION
       ══════════════════════════════ */
    if (avatarGrid) {
        avatarGrid.addEventListener('click', function(e) {
            var opt = e.target.closest('.avatar-opt');
            if (!opt) return;

            var seed = opt.getAttribute('data-seed');
            if (!seed) return;

            var allOpts = avatarGrid.querySelectorAll('.avatar-opt');
            var allWraps = avatarGrid.querySelectorAll('.avatar-opt-wrap');
            for (var i = 0; i < allOpts.length; i++)  allOpts[i].classList.remove('picked');
            for (var j = 0; j < allWraps.length; j++) allWraps[j].classList.remove('active');

            opt.classList.add('picked');
            var wrap = opt.closest('.avatar-opt-wrap');
            if (wrap) wrap.classList.add('active');

            hiddenInput.value = seed;

            var url = 'https://api.dicebear.com/7.x/avataaars/svg?seed=' + encodeURIComponent(seed) + '&t=' + Date.now();
            previewImg.src = url;
            sideImg.src = url;

            console.log('[Avatar] Selected:', seed, '| Hidden value:', hiddenInput.value);
        });
    }

    /* ── Password Toggle ── */
    if (togglePw && pwInput && eyeIcon) {
        togglePw.addEventListener('click', function() {
            if (pwInput.type === 'password') {
                pwInput.type = 'text';
                eyeIcon.classList.remove('fa-eye');
                eyeIcon.classList.add('fa-eye-slash');
            } else {
                pwInput.type = 'password';
                eyeIcon.classList.remove('fa-eye-slash');
                eyeIcon.classList.add('fa-eye');
            }
        });
    }

    /* ── Clear field errors on typing ── */
    if (form) {
        var inputs = form.querySelectorAll('.p-input');
        for (var i = 0; i < inputs.length; i++) {
            (function(inp) {
                inp.addEventListener('input', function() {
                    inp.classList.remove('input-err');
                    var group = inp.closest('.p-form-group');
                    if (group) {
                        var errEl = group.querySelector('.p-field-err');
                        if (errEl) errEl.classList.remove('show');
                    }
                });
            })(inputs[i]);
        }

        /* ══════════════════════════════
           FORM SUBMIT
           ══════════════════════════════ */
        form.addEventListener('submit', function(e) {
            var nameVal     = document.getElementById('nameInput').value.trim();
            var emailVal    = document.getElementById('emailInput').value.trim();
            var passwordVal = document.getElementById('passwordInput').value.trim();
            var hasErr = false;

            for (var i = 0; i < inputs.length; i++) inputs[i].classList.remove('input-err');
            var errs = form.querySelectorAll('.p-field-err');
            for (var j = 0; j < errs.length; j++) errs[j].classList.remove('show');

            if (nameVal.length < 1) {
                document.getElementById('nameInput').classList.add('input-err');
                document.getElementById('nameErr').classList.add('show');
                hasErr = true;
            }
            if (!emailVal || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
                document.getElementById('emailInput').classList.add('input-err');
                document.getElementById('emailErr').classList.add('show');
                hasErr = true;
            }
            if (passwordVal.length > 0 && passwordVal.length < 6) {
                document.getElementById('passwordInput').classList.add('input-err');
                document.getElementById('passwordErr').classList.add('show');
                hasErr = true;
            }

            if (hasErr) {
                e.preventDefault();
                return;
            }

            console.log('[Submit] Avatar value being sent:', hiddenInput.value);
            saveBtn.classList.add('loading');
        });
    }

    /* ── Reset Button ── */
    if (resetBtn && form) {
        resetBtn.addEventListener('click', function() {
            var origName  = '<?php echo addslashes($userData["name"]); ?>';
            var origEmail = '<?php echo addslashes($userData["email"]); ?>';
            var origSeed  = '<?php echo addslashes($userAvatarSeed); ?>';

            document.getElementById('nameInput').value = origName;
            document.getElementById('emailInput').value = origEmail;
            document.getElementById('passwordInput').value = '';

            var inputs = form.querySelectorAll('.p-input');
            for (var i = 0; i < inputs.length; i++) inputs[i].classList.remove('input-err');
            var errs = form.querySelectorAll('.p-field-err');
            for (var j = 0; j < errs.length; j++) errs[j].classList.remove('show');

            hiddenInput.value = origSeed;
            var url = 'https://api.dicebear.com/7.x/avataaars/svg?seed=' + encodeURIComponent(origSeed) + '&t=' + Date.now();
            previewImg.src = url;
            sideImg.src = url;

            var allOpts  = avatarGrid.querySelectorAll('.avatar-opt');
            var allWraps = avatarGrid.querySelectorAll('.avatar-opt-wrap');
            for (var k = 0; k < allOpts.length; k++) {
                allOpts[k].classList.remove('picked');
                if (allOpts[k].getAttribute('data-seed') === origSeed) {
                    allOpts[k].classList.add('picked');
                }
            }
            for (var m = 0; m < allWraps.length; m++) {
                allWraps[m].classList.remove('active');
                var optInside = allWraps[m].querySelector('.avatar-opt');
                if (optInside && optInside.getAttribute('data-seed') === origSeed) {
                    allWraps[m].classList.add('active');
                }
            }
        });
    }

});
</script>