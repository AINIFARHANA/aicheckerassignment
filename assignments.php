<?php
ob_start();

/* ══════════════════════════════════════════════════════════════
   SAFETY NET: guarantee the AJAX submit handler always gets valid
   JSON back, even if a fatal error happens somewhere in the script.
   Without this, any uncaught error/exception dumps raw PHP error
   HTML into the response, which breaks JSON.parse() on the client
   and shows "Invalid server response".
   ══════════════════════════════════════════════════════════════ */
register_shutdown_function(function () {
    $error = error_get_last();
    $isFatal = $error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true);
    $isPost  = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

    if ($isFatal && $isPost) {
        if (ob_get_level() > 0) { @ob_end_clean(); }
        if (!headers_sent()) { header('Content-Type: application/json'); }
        echo json_encode([
            'success' => false,
            'error'   => 'Server error: ' . $error['message'] . ' (line ' . $error['line'] . ')'
        ]);
    }
});

 $pageTitle = "Submit Assignment - AI Assignment Checker";
 $activePage = "assignments";

require_once 'header.php';
require_once __DIR__ . '/notification_helpers.php';

if (!$isLoggedIn || empty($_SESSION['user_id'])) {
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Session expired. Please log in again.']);
        exit();
    }
    ob_end_clean();
    header("Location: register.php");
    exit();
}

 $user_id = intval($_SESSION['user_id']);
 $uploadDir = "uploads/assignments/";

if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
}

 $htaccessPath = $uploadDir . ".htaccess";
if (!file_exists($htaccessPath)) {
    @file_put_contents($htaccessPath, "Deny from all\nOptions -Indexes");
}

/* ══════════════════════════════════════════════════════════════
   PLAN-BASED UPLOAD LIMIT
   Basic (or no active plan)  -> 10MB per file
   Standard                   -> 30MB per file
   Premium                    -> 100MB per file
   Matched by keyword against the active plan's name/badge so it
   works regardless of how the admin has worded the plan.
   ══════════════════════════════════════════════════════════════ */
function getUserMaxUploadMB($conn, $user_id) {
    $tiers = [
        'premium'  => 100,
        'standard' => 30,
        'basic'    => 10,
    ];

    /* Defensive: never let a missing table/column, or a PHP 8.1+ mysqli
       exception, break assignment submission. If anything goes wrong
       here we just fall back to the Basic default below. */
    try {
        if ($conn && ($conn instanceof mysqli)) {
            $stmt = @$conn->prepare("
                SELECT p.plan_name, IFNULL(p.badge,'') as badge
                FROM user_subscriptions us
                JOIN plans p ON us.plan_id = p.plan_id
                WHERE us.user_id = ? AND us.status = 'active' AND us.end_date > NOW()
                ORDER BY us.end_date DESC
                LIMIT 1
            ");
            if ($stmt) {
                $stmt->bind_param("i", $user_id);
                if (@$stmt->execute()) {
                    $result = @$stmt->get_result();
                    $plan = $result ? $result->fetch_assoc() : null;
                    $stmt->close();

                    if ($plan) {
                        $haystack = strtolower($plan['plan_name'] . ' ' . $plan['badge']);
                        foreach ($tiers as $keyword => $mb) {
                            if (strpos($haystack, $keyword) !== false) {
                                return $mb;
                            }
                        }
                    }
                } else {
                    $stmt->close();
                }
            }
        }
    } catch (\Throwable $e) {
        /* Swallow any DB error (e.g. missing table/column) — fall through to default */
    }

    /* No active plan / plan name didn't match a known tier -> Basic default */
    return $tiers['basic'];
}

/* Self-heal any paid plan payments that never got a subscription row
   (see subscription_helpers.php / patch_fixes.sql) before we read the
   plan below — cheap no-op once everything is already in sync. */
require_once __DIR__ . '/subscription_helpers.php';
healMissingSubscriptionsForUser($conn, $user_id);

 $userMaxUploadMB    = getUserMaxUploadMB($conn, $user_id);
 $userMaxUploadBytes = $userMaxUploadMB * 1024 * 1024;

/* ── Stats ── */
 $userStats = [
    'total'     => 0,
    'pending'   => 0,
    'checking'  => 0,
    'completed' => 0
];

function getUserStat($conn, $user_id, $status) {
    if (!$conn || !($conn instanceof mysqli)) return 0;
    $sql = "SELECT COUNT(*) FROM assignments WHERE user_id = ? AND status = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) return 0;
    $stmt->bind_param("is", $user_id, $status);
    $stmt->execute();
    $result = $stmt->get_result();
    $count = 0;
    if ($row = $result->fetch_row()) $count = intval($row[0]);
    $stmt->close();
    return $count;
}

if ($conn && ($conn instanceof mysqli)) {
    $userStats['total']     = getUserStat($conn, $user_id, 'Pending') + getUserStat($conn, $user_id, 'Checking') + getUserStat($conn, $user_id, 'Completed');
    $userStats['pending']   = getUserStat($conn, $user_id, 'Pending');
    $userStats['checking']  = getUserStat($conn, $user_id, 'Checking');
    $userStats['completed'] = getUserStat($conn, $user_id, 'Completed');
}

/* ── Handle AJAX form submission ── */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $title       = trim($_POST['title'] ?? '');
    $subject     = trim($_POST['subject'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $filePath    = "";

    /* Validation: empty fields */
    if (empty($title) || empty($subject) || empty($description)) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'All fields are required.']);
        exit();
    }

    /* Validation: file upload */
    if (!isset($_FILES['assignment_file']) || $_FILES['assignment_file']['error'] !== UPLOAD_ERR_OK) {
        $uploadError = 'No file uploaded.';
        if (isset($_FILES['assignment_file'])) {
            $errCode = $_FILES['assignment_file']['error'];
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload_max_filesize.',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds form MAX_FILE_SIZE.',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing temp folder on server.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION  => 'Upload blocked by a PHP extension.'
            ];
            $uploadError = $errorMessages[$errCode] ?? "Upload error code: $errCode";
        }
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $uploadError]);
        exit();
    }

    $file     = $_FILES['assignment_file'];
    $fileName = $file['name'];
    $fileSize = $file['size'];
    $fileTmp  = $file['tmp_name'];
    $fileExt  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    $allowedExt = ['pdf', 'doc', 'docx'];
    $maxSize    = $userMaxUploadBytes;

    /* Validation: file type */
    if (!in_array($fileExt, $allowedExt)) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid file type. Only PDF, DOC, and DOCX are allowed.']);
        exit();
    }

    /* Validation: file size */
    if ($fileSize > $maxSize) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'File size exceeds ' . $userMaxUploadMB . 'MB limit for your current plan.']);
        exit();
    }

    $newFileName = uniqid('assign_' . $user_id . '_', true) . '.' . $fileExt;
    $filePath    = $uploadDir . $newFileName; // e.g. uploads/assignments/assign_2_xxx.pdf

    /* Move uploaded file */
    if (!move_uploaded_file($fileTmp, $filePath)) {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Failed to save uploaded file. Check folder permissions for: ' . $uploadDir]);
        exit();
    }

    /* ── Make sure the "file_path" column exists (used by ai_analysis.php) ──
       "upload_file" (bare filename) is kept too, since admin_assignments.php,
       view_assignment.php and verification.php all read that column. */
    @mysqli_query($conn, "ALTER TABLE assignments ADD COLUMN file_path VARCHAR(500) NULL AFTER upload_file");

    /* Insert into database */
    $insertSql = "INSERT INTO assignments (user_id, title, subject, assignment_content, upload_file, file_path, status) 
                  VALUES (?, ?, ?, ?, ?, ?, 'Pending')";
    $insertStmt = $conn->prepare($insertSql);

    if (!$insertStmt) {
        @unlink($filePath); // Clean up uploaded file
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Database prepare failed: ' . $conn->error]);
        exit();
    }

    /* upload_file = bare filename, file_path = full relative path from site root */
    $insertStmt->bind_param("isssss", $user_id, $title, $subject, $description, $newFileName, $filePath);

    if (!$insertStmt->execute()) {
        $dbError = $insertStmt->error;
        $insertStmt->close();
        @unlink($filePath); // Clean up uploaded file
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $dbError]);
        exit();
    }

    $newAssignmentId = $insertStmt->insert_id;
    $insertStmt->close();

    // Create one notification for the user and one admin-wide notification.
    createUserNotification($conn, $user_id, 'Your assignment "' . $title . '" was submitted successfully and is now Pending.');
    createAdminNotification($conn, 'New assignment "' . $title . '" was submitted and is waiting for checking.');

    /* SUCCESS */
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success'       => true,
        'assignment_id' => $newAssignmentId,
        'title'         => htmlspecialchars($title)
    ]);
    exit();
}

/* ── Flush buffer to display normal page (GET request) ── */
ob_end_flush();

/* ── Fetch recent assignments ── */
 $assignments = [];
if ($conn && ($conn instanceof mysqli)) {
    $fetchSql = "SELECT a.assignment_id, a.title, a.subject, a.status, 
                        DATE_FORMAT(a.submission_date, '%b %d, %Y %h:%i %p') as formatted_date,
                        IFNULL(a.payment_status,'unpaid') as payment_status,
                        IFNULL(a.payment_date,'') as payment_date,
                        IFNULL(a.payment_amount,0) as payment_amount,
                        IFNULL(a.transaction_id,'') as transaction_id,
                        IFNULL(a.payment_method,'') as payment_method,
                        IFNULL(pt.id,0) as pt_id
                 FROM assignments a
                 LEFT JOIN payment_transactions pt ON pt.reference_id = a.assignment_id AND pt.type = 'assignment' AND pt.user_id = a.user_id AND pt.status = 'paid'
                 WHERE a.user_id = ? 
                 GROUP BY a.assignment_id
                 ORDER BY a.submission_date DESC";
    $fetchStmt = $conn->prepare($fetchSql);
    if ($fetchStmt) {
        $fetchStmt->bind_param("i", $user_id);
        $fetchStmt->execute();
        $fetchResult = $fetchStmt->get_result();
        while ($row = $fetchResult->fetch_assoc()) {
            $assignments[] = $row;
        }
        $fetchStmt->close();
    }
}

function getStatusBadge($status) {
    switch ($status) {
        case 'Pending':
            return '<span class="status-badge status-pending"><i class="fa-solid fa-clock me-1"></i>Pending</span>';
        case 'Checking':
            return '<span class="status-badge status-checking"><i class="fa-solid fa-magnifying-glass me-1"></i>Checking</span>';
        case 'Completed':
            return '<span class="status-badge status-completed"><i class="fa-solid fa-circle-check me-1"></i>Completed</span>';
        default:
            return '<span class="status-badge">' . htmlspecialchars($status) . '</span>';
    }
}
?>

<style>
    .assign-hero {
        padding: 140px 0 60px;
        position: relative; overflow: hidden;
        background: transparent;
    }
    .assign-hero::before {
        content: ''; position: absolute;
        top: -20%; right: -15%;
        width: 700px; height: 700px;
        background: radial-gradient(circle, rgba(123,63,145,0.18) 0%, transparent 55%);
        border-radius: 50%; animation: heroFloat 12s ease-in-out infinite;
    }
    @keyframes heroFloat {
        0%, 100% { transform: translate(0, 0) scale(1); }
        33% { transform: translate(30px, -20px) scale(1.06); }
        66% { transform: translate(-20px, 15px) scale(0.94); }
    }
    .assign-hero-title {
        font-weight: 900; color: var(--text-bright);
        line-height: 1.1; letter-spacing: -0.04em;
        font-size: clamp(2rem, 4.5vw, 3rem);
    }
    .assign-hero-title span {
        background: linear-gradient(135deg, var(--primary-light), var(--accent), var(--accent-rose));
        -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
    }
    .assign-hero .lead {
        color: var(--text-body); font-size: 1rem; line-height: 1.8; max-width: 540px;
    }

    .astat-card {
        background: var(--bg-card);
        backdrop-filter: blur(16px);
        border-radius: var(--radius-lg);
        padding: 28px 24px;
        border: 1px solid var(--border-subtle);
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative; overflow: hidden;
        height: 100%;
    }
    .astat-card::before {
        content: ''; position: absolute;
        top: 0; left: 0; right: 0; height: 3px;
        background: var(--stat-color, var(--accent));
        transform: scaleX(0); transform-origin: left;
        transition: transform 0.5s ease;
    }
    .astat-card:hover::before { transform: scaleX(1); }
    .astat-card:hover {
        transform: translateY(-8px);
        box-shadow: var(--shadow-md), 0 0 30px rgba(244,209,255,0.05);
        border-color: var(--border-glow);
    }
    .astat-icon {
        width: 56px; height: 56px;
        background: rgba(244, 209, 255, 0.06);
        border: 1px solid var(--border-subtle);
        border-radius: 16px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.3rem; margin-bottom: 16px;
        transition: all 0.4s ease;
    }
    .astat-card:hover .astat-icon {
        transform: scale(1.1) rotate(-5deg);
        box-shadow: 0 0 20px rgba(244,209,255,0.1);
    }
    .astat-number {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 2.4rem; font-weight: 700;
        letter-spacing: -0.03em; line-height: 1; margin-bottom: 4px;
        background: linear-gradient(135deg, var(--primary-light), white);
        -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
    }
    .astat-label { font-size: 0.82rem; color: var(--text-muted); font-weight: 500; }

    .form-card {
        background: var(--bg-card);
        backdrop-filter: blur(16px);
        border-radius: var(--radius-xl);
        border: 1px solid var(--border-subtle);
        overflow: hidden;
        position: relative;
    }
    .form-card::before {
        content: ''; position: absolute;
        top: 0; left: 0; right: 0; height: 2px;
        background: linear-gradient(90deg, transparent, var(--accent), var(--primary-light), var(--accent), transparent);
        opacity: 0.6;
    }
    .form-card-header { padding: 32px 36px 0; }
    .form-card-body { padding: 28px 36px 36px; }
    .form-group { margin-bottom: 22px; }
    .form-group label {
        display: block; font-size: 0.82rem; font-weight: 600;
        color: var(--primary-light); margin-bottom: 8px;
        letter-spacing: 0.02em;
    }
    .form-group label .required { color: var(--accent-rose); margin-left: 2px; }
    .form-input {
        width: 100%;
        background: rgba(244, 209, 255, 0.04);
        border: 1.5px solid var(--border-subtle);
        border-radius: var(--radius-sm);
        padding: 14px 18px;
        font-size: 0.9rem;
        color: var(--text-bright);
        font-family: 'Poppins', sans-serif;
        transition: all 0.3s ease;
        outline: none;
    }
    .form-input::placeholder { color: var(--text-muted); }
    .form-input:focus {
        border-color: var(--accent);
        box-shadow: 0 0 0 4px rgba(216, 143, 255, 0.1), 0 0 20px rgba(216, 143, 255, 0.05);
        background: rgba(244, 209, 255, 0.06);
    }
    textarea.form-input { resize: vertical; min-height: 110px; }

    .drop-zone {
        border: 2px dashed var(--border-glow);
        border-radius: var(--radius-lg);
        padding: 40px 24px;
        text-align: center;
        transition: all 0.4s ease;
        cursor: pointer;
        position: relative;
        background: rgba(244, 209, 255, 0.02);
    }
    .drop-zone:hover, .drop-zone.drag-over {
        border-color: var(--accent);
        background: rgba(244, 209, 255, 0.06);
        box-shadow: 0 0 40px rgba(216, 143, 255, 0.08);
    }
    .drop-zone.drag-over { transform: scale(1.01); }
    .drop-zone-icon {
        width: 72px; height: 72px;
        background: rgba(244, 209, 255, 0.06);
        border: 1px solid var(--border-subtle);
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        margin: 0 auto 18px;
        font-size: 1.6rem; color: var(--accent);
        transition: all 0.4s ease;
    }
    .drop-zone:hover .drop-zone-icon {
        transform: scale(1.1);
        background: rgba(216, 143, 255, 0.1);
        box-shadow: 0 0 25px rgba(216, 143, 255, 0.15);
    }
    .drop-zone h6 { color: var(--text-light); font-weight: 600; margin-bottom: 6px; }
    .drop-zone p { color: var(--text-muted); font-size: 0.82rem; margin: 0; }
    .file-types { display: flex; justify-content: center; gap: 12px; margin-top: 18px; }
    .file-type-badge {
        display: flex; align-items: center; gap: 6px;
        padding: 6px 14px;
        background: rgba(244, 209, 255, 0.04);
        border: 1px solid var(--border-subtle);
        border-radius: 8px;
        font-size: 0.75rem; font-weight: 600;
        color: var(--text-body);
    }
    .file-type-badge i { font-size: 0.9rem; }
    .file-type-badge.pdf i { color: #ef4444; }
    .file-type-badge.doc i { color: #3b82f6; }
    .file-type-badge.docx i { color: #3b82f6; }

    .drop-zone.has-file {
        border-color: #22c55e; border-style: solid;
        background: rgba(34, 197, 94, 0.04);
    }
    .selected-file {
        display: none; align-items: center; gap: 14px;
        padding: 14px 20px;
        background: rgba(34, 197, 94, 0.06);
        border: 1px solid rgba(34, 197, 94, 0.2);
        border-radius: var(--radius-sm);
        margin-top: 16px;
    }
    .selected-file.show { display: flex; }
    .selected-file-icon {
        width: 44px; height: 44px; min-width: 44px;
        background: rgba(34, 197, 94, 0.1);
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        color: #22c55e; font-size: 1.2rem;
    }
    .selected-file-info { flex: 1; text-align: left; }
    .selected-file-info .name {
        font-size: 0.85rem; font-weight: 600; color: var(--text-bright);
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 300px;
    }
    .selected-file-info .size { font-size: 0.75rem; color: var(--text-muted); }
    .selected-file-remove {
        width: 32px; height: 32px;
        background: rgba(239, 68, 68, 0.1);
        border: 1px solid rgba(239, 68, 68, 0.2);
        border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        color: #ef4444; cursor: pointer;
        transition: all 0.2s ease;
    }
    .selected-file-remove:hover { background: rgba(239, 68, 68, 0.2); transform: scale(1.1); }

    .btn-submit {
        width: 100%; padding: 16px; border: none;
        border-radius: var(--radius-md);
        font-weight: 700; font-size: 0.95rem;
        letter-spacing: 0.02em; cursor: pointer;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative; overflow: hidden;
        background: linear-gradient(135deg, var(--primary-mid), var(--accent));
        color: white;
        box-shadow: 0 4px 24px rgba(123,63,145,0.4);
        display: flex; align-items: center; justify-content: center; gap: 10px;
    }
    .btn-submit::before {
        content: ''; position: absolute;
        top: 0; left: -100%; width: 100%; height: 100%;
        background: linear-gradient(90deg, transparent, rgba(244,209,255,0.2), transparent);
        transition: left 0.6s;
    }
    .btn-submit:hover::before { left: 100%; }
    .btn-submit:hover { transform: translateY(-3px); box-shadow: 0 8px 40px rgba(123,63,145,0.6); }
    .btn-submit:disabled { opacity: 0.6; cursor: not-allowed; transform: none !important; }
    .btn-submit .spinner {
        display: none; width: 20px; height: 20px;
        border: 2.5px solid rgba(255,255,255,0.3);
        border-top-color: white; border-radius: 50%;
        animation: spin 0.7s linear infinite;
    }
    .btn-submit.loading .spinner { display: block; }
    .btn-submit.loading .btn-text { display: none; }
    @keyframes spin { to { transform: rotate(360deg); } }

    .table-card {
        background: var(--bg-card);
        backdrop-filter: blur(16px);
        border-radius: var(--radius-xl);
        border: 1px solid var(--border-subtle);
        overflow: hidden; position: relative;
    }
    .table-card::before {
        content: ''; position: absolute;
        top: 0; left: 0; right: 0; height: 2px;
        background: linear-gradient(90deg, transparent, var(--accent), var(--primary-light), var(--accent), transparent);
        opacity: 0.6;
    }
    .table-card-header { padding: 28px 32px 0; }
    .table-card-body { padding: 20px 0 0; overflow-x: auto; }
    .assign-table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 600px; }
    .assign-table thead th {
        padding: 14px 20px; font-size: 0.75rem; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.1em;
        color: var(--text-muted); border-bottom: 1px solid var(--border-subtle);
        white-space: nowrap; background: rgba(244, 209, 255, 0.02);
    }
    .assign-table tbody tr { transition: background 0.2s ease; }
    .assign-table tbody tr:hover { background: rgba(244, 209, 255, 0.03); }
    .assign-table tbody td {
        padding: 16px 20px; font-size: 0.85rem; color: var(--text-body);
        border-bottom: 1px solid var(--border-subtle);
        vertical-align: middle; white-space: nowrap;
    }
    .assign-table tbody tr:last-child td { border-bottom: none; }
    .assign-table .title-cell {
        font-weight: 600; color: var(--text-bright);
        max-width: 200px; white-space: nowrap;
        overflow: hidden; text-overflow: ellipsis;
    }

    .status-badge {
        display: inline-flex; align-items: center;
        padding: 5px 12px; border-radius: 8px;
        font-size: 0.75rem; font-weight: 600; white-space: nowrap;
    }
    .status-pending { background: rgba(255,165,0,0.1); color: #ffa500; border: 1px solid rgba(255,165,0,0.2); }
    .status-checking { background: rgba(59,130,246,0.1); color: #60a5fa; border: 1px solid rgba(59,130,246,0.2); }
    .status-completed { background: rgba(34,197,94,0.1); color: #22c55e; border: 1px solid rgba(34,197,94,0.2); }

    .action-btn {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 7px 14px; border-radius: 8px;
        font-size: 0.75rem; font-weight: 600;
        text-decoration: none; transition: all 0.25s ease;
        border: 1px solid var(--border-subtle);
        background: rgba(244, 209, 255, 0.04);
        color: var(--text-body); white-space: nowrap;
    }
    .action-btn:hover {
        background: rgba(244, 209, 255, 0.08);
        color: var(--primary-light); border-color: var(--border-glow);
        transform: translateY(-1px);
    }

    .empty-state { text-align: center; padding: 60px 24px; }
    .empty-state-icon {
        width: 120px; height: 120px;
        background: rgba(244, 209, 255, 0.04);
        border: 2px dashed var(--border-glow);
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        margin: 0 auto 24px;
        font-size: 2.8rem; color: var(--text-muted);
        animation: emptyFloat 4s ease-in-out infinite;
    }
    @keyframes emptyFloat { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-10px); } }
    .empty-state h5 { color: var(--text-bright); font-weight: 700; margin-bottom: 8px; }
    .empty-state p { color: var(--text-muted); font-size: 0.9rem; margin-bottom: 24px; }

    @media (max-width: 991px) {
        .form-card-header, .form-card-body { padding-left: 24px; padding-right: 24px; }
        .table-card-header { padding-left: 24px; padding-right: 24px; }
    }
    @media (max-width: 767px) {
        .assign-hero { padding: 120px 0 40px; }
        .astat-number { font-size: 1.8rem; }
        .form-card-header, .form-card-body { padding-left: 18px; padding-right: 18px; }
        .table-card-header { padding-left: 18px; padding-right: 18px; }
        .selected-file-info .name { max-width: 160px; }
    }

    /* ── Payment & Receipt Buttons ── */
    .actions-cell { display:flex; align-items:center; gap:8px; }
    .action-btn.payment-btn {
        background: linear-gradient(135deg, rgba(123,63,145,0.15), rgba(216,143,255,0.08));
        border-color: rgba(216,143,255,0.3);
        color: var(--accent);
    }
    .action-btn.payment-btn:hover {
        background: linear-gradient(135deg, rgba(123,63,145,0.3), rgba(216,143,255,0.18));
        border-color: var(--accent);
        color: #fff;
        box-shadow: 0 4px 20px rgba(123,63,145,0.35);
        transform: translateY(-2px);
    }
    .action-btn.receipt-btn {
        background: rgba(34,197,94,0.08);
        border-color: rgba(34,197,94,0.25);
        color: #22c55e;
    }
    .action-btn.receipt-btn:hover {
        background: rgba(34,197,94,0.18);
        border-color: rgba(34,197,94,0.5);
        color: #4ade80;
        box-shadow: 0 4px 20px rgba(34,197,94,0.2);
        transform: translateY(-2px);
    }
    .action-btn.receipt-btn.unpaid {
        background: rgba(255,165,0,0.06);
        border-color: rgba(255,165,0,0.2);
        color: #ffa500;
    }
    .action-btn.receipt-btn.unpaid:hover {
        background: rgba(255,165,0,0.14);
        border-color: rgba(255,165,0,0.4);
        color: #ffb733;
        box-shadow: 0 4px 20px rgba(255,165,0,0.15);
        transform: translateY(-2px);
    }

    @media (max-width:767px) {
        .actions-cell { gap:6px; }
        .action-btn { padding:6px 10px; font-size:0.7rem; }
    }
</style>

<style>
    .alert-flash{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:16px 22px;border-radius:14px;margin-bottom:24px;font-size:0.92rem;font-weight:500;backdrop-filter:blur(10px)}
    .alert-flash i{font-size:1.2rem}
    .alert-flash-success{background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);color:#86efac}
    .alert-flash-error{background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);color:#fca5a5}
    .alert-flash-btn{margin-left:auto;display:inline-flex;align-items:center;gap:6px;padding:8px 18px;background:#22c55e;color:#062b13;border-radius:8px;font-weight:700;font-size:0.82rem;text-decoration:none;transition:opacity 0.2s}
    .alert-flash-btn:hover{opacity:0.85;color:#062b13}
</style>

<section class="assign-hero">
    <div class="container position-relative" style="z-index:2;">
        <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="alert-flash alert-flash-success" data-aos="fade-up">
            <i class="fa-solid fa-circle-check"></i>
            <span><?php echo htmlspecialchars($_SESSION['flash_success']); ?></span>
            <?php if (!empty($_SESSION['flash_receipt_id'])): ?>
                <a href="view_receipt.php?id=<?php echo intval($_SESSION['flash_receipt_id']); ?>" class="alert-flash-btn" target="_blank">
                    <i class="fa-solid fa-receipt"></i> View Receipt
                </a>
            <?php endif; ?>
        </div>
        <?php unset($_SESSION['flash_success']); unset($_SESSION['flash_receipt']); unset($_SESSION['flash_receipt_id']); endif; ?>
        <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="alert-flash alert-flash-error" data-aos="fade-up">
            <i class="fa-solid fa-circle-exclamation"></i>
            <span><?php echo htmlspecialchars($_SESSION['flash_error']); ?></span>
        </div>
        <?php unset($_SESSION['flash_error']); endif; ?>
        <div data-aos="fade-right">
            <span class="badge rounded-pill px-3 py-2 mb-3 d-inline-block" style="background:var(--bg-glass-strong)!important; border:1px solid var(--border-glow); color:var(--accent); font-size:0.78rem; font-weight:600; backdrop-filter:blur(10px);">
                <i class="fa-solid fa-cloud-arrow-up me-1"></i> Assignment Portal
            </span>
            <h1 class="assign-hero-title mb-3">Submit Your<br><span>Assignment</span></h1>
            <p class="lead">
                Upload your work and let our advanced AI analyze it for originality, accuracy, and quality. Get detailed reports in seconds.
            </p>
        </div>
    </div>
</section>

<section class="pb-4" style="position:relative; z-index:2;">
    <div class="container">
        <div class="row g-3">
            <div class="col-6 col-lg-3" data-aos="fade-up" data-aos-delay="0">
                <div class="astat-card" style="--stat-color: var(--accent);">
                    <div class="astat-icon" style="color: var(--accent);"><i class="fa-solid fa-file-lines"></i></div>
                    <div class="astat-number"><?php echo $userStats['total']; ?></div>
                    <div class="astat-label">Total</div>
                </div>
            </div>
            <div class="col-6 col-lg-3" data-aos="fade-up" data-aos-delay="80">
                <div class="astat-card" style="--stat-color: #ffa500;">
                    <div class="astat-icon" style="color: #ffa500;"><i class="fa-solid fa-clock"></i></div>
                    <div class="astat-number"><?php echo $userStats['pending']; ?></div>
                    <div class="astat-label">Pending</div>
                </div>
            </div>
            <div class="col-6 col-lg-3" data-aos="fade-up" data-aos-delay="160">
                <div class="astat-card" style="--stat-color: #60a5fa;">
                    <div class="astat-icon" style="color: #60a5fa;"><i class="fa-solid fa-magnifying-glass"></i></div>
                    <div class="astat-number"><?php echo $userStats['checking']; ?></div>
                    <div class="astat-label">Checking</div>
                </div>
            </div>
            <div class="col-6 col-lg-3" data-aos="fade-up" data-aos-delay="240">
                <div class="astat-card" style="--stat-color: #22c55e;">
                    <div class="astat-icon" style="color: #22c55e;"><i class="fa-solid fa-circle-check"></i></div>
                    <div class="astat-number"><?php echo $userStats['completed']; ?></div>
                    <div class="astat-label">Completed</div>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="section-divider my-2"></div>

<section class="py-5" style="position:relative; z-index:2;">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8" data-aos="fade-up">
                <div class="form-card">
                    <div class="form-card-header">
                        <div class="d-flex align-items-center gap-3 mb-1">
                            <div style="width:44px;height:44px;background:rgba(216,143,255,0.1);border:1px solid var(--border-subtle);border-radius:14px;display:flex;align-items:center;justify-content:center;color:var(--accent);font-size:1.1rem;">
                                <i class="fa-solid fa-pen-to-square"></i>
                            </div>
                            <div>
                                <h3 style="font-weight:700;color:var(--text-bright);font-size:1.15rem;margin:0;">New Assignment</h3>
                                <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">Fill in the details and upload your file</p>
                            </div>
                        </div>
                    </div>
                    <div class="form-card-body">
                        <form id="assignmentForm" enctype="multipart/form-data" novalidate>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Assignment Title <span class="required">*</span></label>
                                        <input type="text" name="title" class="form-input" placeholder="e.g., Final Year Research Paper" required id="titleInput">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Subject / Course <span class="required">*</span></label>
                                        <input type="text" name="subject" class="form-input" placeholder="e.g., Computer Science 101" required id="subjectInput">
                                    </div>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Description <span class="required">*</span></label>
                                <textarea name="description" class="form-input" placeholder="Briefly describe your assignment, topic, and any specific requirements..." required id="descInput"></textarea>
                            </div>
                            <div class="form-group">
                                <label>Upload File <span class="required">*</span></label>
                                <div class="drop-zone" id="dropZone">
                                    <div class="drop-zone-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                                    <h6>Drag & Drop your file here</h6>
                                    <p>or click to browse from your computer</p>
                                    <div class="file-types">
                                        <div class="file-type-badge doc"><i class="fa-solid fa-file-word"></i> DOC</div>
                                        <div class="file-type-badge docx"><i class="fa-solid fa-file-word"></i> DOCX</div>
                                    </div>
                                    <input type="file" name="assignment_file" id="fileInput" accept=".pdf,.doc,.docx" style="display:none;" required>
                                </div>
                                <div class="selected-file" id="selectedFile">
                                    <div class="selected-file-icon"><i class="fa-solid fa-file-check"></i></div>
                                    <div class="selected-file-info">
                                        <div class="name" id="fileName">--</div>
                                        <div class="size" id="fileSize">--</div>
                                    </div>
                                    <div class="selected-file-remove" id="removeFile" title="Remove file"><i class="fa-solid fa-xmark"></i></div>
                                </div>
                                <p style="font-size:0.72rem;color:var(--text-muted);margin-top:8px;">
                                    <i class="fa-solid fa-info-circle me-1"></i> Maximum file size: <?php echo $userMaxUploadMB; ?>MB
                                    <?php if ($userMaxUploadMB <= 10): ?>
                                        <a href="plan.php" style="color:var(--accent);text-decoration:underline;margin-left:4px;">Upgrade for larger uploads</a>
                                    <?php endif; ?>
                                </p>
                            </div>
                            <button type="submit" class="btn-submit" id="submitBtn">
                                <div class="spinner"></div>
                                <span class="btn-text"><i class="fa-solid fa-paper-plane me-2"></i>Submit Assignment</span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="section-divider my-2"></div>

<section class="py-5" style="position:relative; z-index:2;">
    <div class="container">
        <div class="table-card" data-aos="fade-up">
            <div class="table-card-header">
                <div class="d-flex align-items-center gap-3">
                    <div style="width:44px;height:44px;background:rgba(216,143,255,0.1);border:1px solid var(--border-subtle);border-radius:14px;display:flex;align-items:center;justify-content:center;color:var(--accent);font-size:1.1rem;">
                        <i class="fa-solid fa-clock-rotate-left"></i>
                    </div>
                    <div>
                        <h3 style="font-weight:700;color:var(--text-bright);font-size:1.15rem;margin:0;">Recent Submissions</h3>
                        <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">
                            <?php echo count($assignments); ?> assignment<?php echo count($assignments) !== 1 ? 's' : ''; ?> submitted
                        </p>
                    </div>
                </div>
            </div>
            <div class="table-card-body">
                <?php if (count($assignments) > 0): ?>
                <table class="assign-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Subject</th>
                            <th>Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assignments as $a): ?>
                        <tr>
                            <td class="title-cell"><?php echo htmlspecialchars($a['title']); ?></td>
                            <td><?php echo htmlspecialchars($a['subject']); ?></td>
                            <td style="color:var(--text-muted);"><?php echo $a['formatted_date']; ?></td>
                            <td><?php echo getStatusBadge($a['status']); ?></td>
                            <td>
                                <div class="actions-cell">
                                    <?php if ($a['payment_status'] === 'paid' && intval($a['pt_id']) > 0): ?>
                                    <a href="view_receipt.php?id=<?php echo intval($a['pt_id']); ?>" class="action-btn receipt-btn" title="View Receipt">
                                        <i class="fa-solid fa-receipt"></i> Receipt
                                    </a>
                                    <?php else: ?>
                                    <button type="button" class="action-btn receipt-btn unpaid" onclick="showPaymentRequired()" title="View Receipt">
                                        <i class="fa-solid fa-receipt"></i> Receipt
                                    </button>
                                    <?php endif; ?>
                                    <?php if ($a['payment_status'] !== 'paid'): ?>
                                    <a href="payments_assignment.php?id=<?php echo $a['assignment_id']; ?>" class="action-btn payment-btn" title="Make Payment">
                                        <i class="fa-solid fa-credit-card"></i> Payment
                                    </a>
                                    <?php endif; ?>
                                    <a href="view_assignment.php?id=<?php echo $a['assignment_id']; ?>" class="action-btn" title="View">
                                        <i class="fa-solid fa-eye"></i> View
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fa-solid fa-inbox"></i></div>
                    <h5>No assignments submitted yet</h5>
                    <p>Start by submitting your first assignment above. Our AI will analyze it and provide a detailed report.</p>
                    <a href="#assignmentForm" class="btn btn-outline-purple" style="padding:12px 32px; font-size:0.88rem;">
                        <i class="fa-solid fa-plus me-2"></i>Submit First Assignment
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {

    var dropZone = document.getElementById('dropZone');
    var fileInput = document.getElementById('fileInput');
    var selectedFile = document.getElementById('selectedFile');
    var fileNameEl = document.getElementById('fileName');
    var fileSizeEl = document.getElementById('fileSize');
    var removeFileBtn = document.getElementById('removeFile');

    if (dropZone) {
        dropZone.addEventListener('click', function() { fileInput.click(); });
        dropZone.addEventListener('dragover', function(e) { e.preventDefault(); dropZone.classList.add('drag-over'); });
        dropZone.addEventListener('dragleave', function(e) { e.preventDefault(); dropZone.classList.remove('drag-over'); });
        dropZone.addEventListener('drop', function(e) {
            e.preventDefault(); dropZone.classList.remove('drag-over');
            if (e.dataTransfer.files.length > 0) { fileInput.files = e.dataTransfer.files; handleFileSelect(e.dataTransfer.files[0]); }
        });
        fileInput.addEventListener('change', function() { if (this.files.length > 0) handleFileSelect(this.files[0]); });
    }

    function handleFileSelect(file) {
        var allowedExt = ['pdf', 'doc', 'docx'];
        var ext = file.name.split('.').pop().toLowerCase();
        var maxSize = <?php echo (int) $userMaxUploadBytes; ?>;
        var maxSizeLabel = '<?php echo $userMaxUploadMB; ?>MB';
        if (!allowedExt.includes(ext)) {
            Swal.fire({ icon: 'error', title: 'Invalid File Type', text: 'Only PDF, DOC, and DOCX files are allowed.', background: '#150A1E', color: '#F4D1FF', confirmButtonColor: '#7B3F91', iconColor: '#ef4444' });
            fileInput.value = ''; return;
        }
        if (file.size > maxSize) {
            Swal.fire({ icon: 'error', title: 'File Too Large', text: 'Maximum file size for your current plan is ' + maxSizeLabel + '.', background: '#150A1E', color: '#F4D1FF', confirmButtonColor: '#7B3F91', iconColor: '#ef4444' });
            fileInput.value = ''; return;
        }
        fileNameEl.textContent = file.name;
        fileSizeEl.textContent = formatFileSize(file.size);
        selectedFile.classList.add('show');
        dropZone.classList.add('has-file');
    }

    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        var k = 1024, sizes = ['Bytes', 'KB', 'MB', 'GB'];
        var i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    if (removeFileBtn) {
        removeFileBtn.addEventListener('click', function(e) {
            e.stopPropagation(); fileInput.value = '';
            selectedFile.classList.remove('show'); dropZone.classList.remove('has-file');
        });
    }

    var form = document.getElementById('assignmentForm');
    var submitBtn = document.getElementById('submitBtn');

    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var title = document.getElementById('titleInput').value.trim();
            var subject = document.getElementById('subjectInput').value.trim();
            var desc = document.getElementById('descInput').value.trim();

            if (!title || !subject || !desc) {
                Swal.fire({ icon: 'warning', title: 'Missing Fields', text: 'Please fill in all required fields.', background: '#150A1E', color: '#F4D1FF', confirmButtonColor: '#7B3F91', iconColor: '#ffa500' });
                return;
            }
            if (!fileInput.files || fileInput.files.length === 0) {
                Swal.fire({ icon: 'warning', title: 'No File Selected', text: 'Please upload your assignment file.', background: '#150A1E', color: '#F4D1FF', confirmButtonColor: '#7B3F91', iconColor: '#ffa500' });
                return;
            }

            submitBtn.classList.add('loading'); submitBtn.disabled = true;
            var formData = new FormData(form);
            formData.append('submit_assignment', '1');

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(function(response) {
                return response.text().then(function(raw) {
                    console.log('=== RAW RESPONSE ===', raw.substring(0, 500));
                    try { return JSON.parse(raw); }
                    catch(e) {
                        console.error('JSON parse failed. Full response:', raw);
                        return { success: false, error: 'Invalid server response. Open browser console (F12) for details.' };
                    }
                });
            })
            .then(function(data) {
                submitBtn.classList.remove('loading'); submitBtn.disabled = false;
                if (data.success) {
                    Swal.fire({
                        icon: 'success', title: 'Assignment Submitted!',
                        html: '<p style="color:rgba(244,209,255,0.7);font-size:0.9rem;">Your assignment <strong style="color:#F4D1FF;">' + data.title + '</strong> has been submitted successfully.</p>',
                        background: '#150A1E', color: '#F4D1FF',
                        confirmButtonColor: '#7B3F91',
                        confirmButtonText: 'OK',
                        iconColor: '#22c55e'
                    }).then(function() {
                        window.location.href = window.location.pathname;
                    });
                } else {
                    Swal.fire({ icon: 'error', title: 'Submission Failed', text: data.error || 'Something went wrong.', background: '#150A1E', color: '#F4D1FF', confirmButtonColor: '#7B3F91', iconColor: '#ef4444' });
                }
            })
            .catch(function(err) {
                submitBtn.classList.remove('loading'); submitBtn.disabled = false;
                console.error('Fetch error:', err);
                Swal.fire({ icon: 'error', title: 'Network Error', text: 'Please check your connection and try again.', background: '#150A1E', color: '#F4D1FF', confirmButtonColor: '#7B3F91', iconColor: '#ef4444' });
            });
        });
    }

    document.querySelectorAll('.astat-card, .form-card, .table-card').forEach(function(card) {
        card.addEventListener('mousemove', function(e) {
            var rect = card.getBoundingClientRect();
            card.style.setProperty('--mouse-x', ((e.clientX - rect.left) / rect.width * 100) + '%');
            card.style.setProperty('--mouse-y', ((e.clientY - rect.top) / rect.height * 100) + '%');
        });
    });

});

/* ── Receipt: show notification if no payment ── */
function showPaymentRequired() {
    Swal.fire({
        icon: 'warning',
        title: 'Payment Required',
        text: 'Payment has not been completed yet. Please make the payment first to view the receipt.',
        background: '#150A1E',
        color: '#F4D1FF',
        confirmButtonColor: '#7B3F91',
        confirmButtonText: 'OK',
        iconColor: '#ffa500'
    });
}
</script>

<?php require_once 'footer.php'; ?>