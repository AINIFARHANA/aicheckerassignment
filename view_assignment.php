<?php
 $pageTitle = 'View Assignment - AI Checker';
require_once 'header.php';

// ── Access Control ──
if (!$isLoggedIn) {
    header("Location: login.php");
    exit();
}

 $assignment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($assignment_id <= 0) {
    header("Location: assignments.php");
    exit();
}

// ── Folder paths ──
 $FOLDER_REVIEWS     = 'uploads/reviews/';
 $FOLDER_ASSIGNMENTS = 'uploads/assignments/';

// ── Subscription Check ──
function hasActiveSubscription($conn, $userId) {
    try {
        $stmt = $conn->prepare("
            SELECT us.subscription_id FROM user_subscriptions us
            WHERE us.user_id = ? AND us.status = 'Active'
            AND us.end_date > NOW()
            LIMIT 1
        ");
        if (!$stmt) return false;
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $active = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $active;
    } catch (Exception $e) {
        return false;
    }
}

require_once __DIR__ . '/subscription_helpers.php';
healMissingSubscriptionsForUser($conn, $_SESSION['user_id']);

 $hasSubscription = hasActiveSubscription($conn, $_SESSION['user_id']);

// ── PDF Page Count (no external library) ──
function getPdfPageCount($filepath) {
    if (!file_exists($filepath)) return 0;
    $fp = fopen($filepath, 'rb');
    if (!$fp) return 0;
    $chunk = '';
    while (!feof($fp) && strlen($chunk) < 65536) {
        $chunk .= fread($fp, 8192);
    }
    fclose($fp);
    if (strlen($chunk) < 100) return 0;
    if (preg_match('/\/Type\s*\/Pages\b[^>]*\/Count\s+(\d+)/is', $chunk, $m)) {
        return (int)$m[1];
    }
    $count = preg_match_all('/\/Type\s*\/Page(?!\s*s)/i', $chunk);
    return max($count, 1);
}

function isPdfFile($filename) {
    if (!$filename) return false;
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'pdf';
}

// ── Fetch Assignment ──
 $stmt = $conn->prepare("
    SELECT a.*,
           ar.review_id, ar.admin_id, ar.marks,
           ar.comment AS review_comment,
           ar.reviewed_file, ar.reviewed_at,
           ar.ai_score, ar.similarity,
           ar.verification_code
    FROM assignments a
    LEFT JOIN assignment_reviews ar ON a.assignment_id = ar.assignment_id
    WHERE a.assignment_id = ? AND a.user_id = ?
");
 $stmt->bind_param("ii", $assignment_id, $_SESSION['user_id']);
 $stmt->execute();
 $result = $stmt->get_result();
 $assignment = $result->fetch_assoc();
 $stmt->close();

if (!$assignment) {
    header("Location: assignments.php");
    exit();
}

 $isReviewed = ($assignment['status'] === 'Completed');

// ── Fetch Payment Transaction for Receipt ──
 $payment = null;
try {
    $stmtPay = $conn->prepare("
        SELECT * FROM payment_transactions
        WHERE user_id = ? AND type = 'assignment' AND reference_id = ? AND status = 'paid'
        ORDER BY paid_at DESC LIMIT 1
    ");
    $stmtPay->bind_param("ii", $_SESSION['user_id'], $assignment_id);
    $stmtPay->execute();
    $payResult = $stmtPay->get_result();
    if ($payResult->num_rows > 0) {
        $payment = $payResult->fetch_assoc();
    }
    $stmtPay->close();
} catch (Exception $e) {
    $payment = null;
}

// ── ★ PAYMENT REQUIRED CHECK ★ ──
// If no active subscription AND no paid transaction for this assignment → block
 $needsPayment = !$hasSubscription && !$payment;

// ── Determine which file to show ──
 $displayFile      = null;
 $displayPath      = '';
 $displayIsPdf     = false;
 $displayPages     = 0;
 $fileSourceLabel  = '';

if (!empty($assignment['reviewed_file'])) {
    $displayFile     = $assignment['reviewed_file'];
    $displayPath     = $FOLDER_REVIEWS . $displayFile;
    $fileSourceLabel = 'Reviewed Document';
} elseif (!empty($assignment['upload_file'])) {
    $displayFile     = $assignment['upload_file'];
    $displayPath     = $FOLDER_ASSIGNMENTS . $displayFile;
    $fileSourceLabel = 'Submitted Document';
}

if ($displayFile) {
    $displayIsPdf = isPdfFile($displayFile);
    if ($displayIsPdf && file_exists($displayPath)) {
        $displayPages = getPdfPageCount($displayPath);
    }
}

 $fileExistsOnDisk = $displayFile && file_exists($displayPath);

 $FREE_PAGE_LIMIT = 10;
 $displayLocked   = !$needsPayment && !$hasSubscription && $displayIsPdf && $displayPages > $FREE_PAGE_LIMIT;

// ── Certificate Key ──
// Replaces the old QR-code verification. Once the admin completes
// the review, admin_reviews.php issues a Certificate ID (e.g.
// CERT-2026-00001) via issueCertificate(), which is stored both in
// assignment_reviews.verification_code (read below, kept for
// compatibility) and in the new `certificates` table together with
// the personalized certificate image generated from
// image/certificate.png.
 $baseDomain     = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
 $certificateKey = $assignment['verification_code'] ?: '';

// ── Look up the generated certificate image + issue date, if any ──
 $certificateRecord  = null;
 $certificateFileUrl = '';
if ($certificateKey !== '') {
    $certStmt = $conn->prepare("SELECT certificate_file, issued_date FROM certificates WHERE certificate_code = ? LIMIT 1");
    $certStmt->bind_param("s", $certificateKey);
    $certStmt->execute();
    $certificateRecord = $certStmt->get_result()->fetch_assoc();
    $certStmt->close();
    if ($certificateRecord && !empty($certificateRecord['certificate_file']) && file_exists(__DIR__ . '/' . $certificateRecord['certificate_file'])) {
        $certificateFileUrl = $certificateRecord['certificate_file'];
    }
}
 $verifyCertificateUrl = $certificateKey ? ('verify_certificate.php?code=' . urlencode($certificateKey)) : 'verify_certificate.php';

// ── Stamp Image ──
 $localStamp    = 'image/stamp.png';
 $stampImageUrl = file_exists($localStamp) ? $localStamp : '';
?>

<!-- Page-specific styles -->
<style>
    .view-page { padding: 130px 0 80px; min-height: 100vh; }

    .breadcrumb-custom {
        display: flex; align-items: center; gap: 8px;
        font-size: 0.85rem; margin-bottom: 32px;
    }
    .breadcrumb-custom a {
        color: var(--text-muted); text-decoration: none;
        transition: color 0.3s ease;
    }
    .breadcrumb-custom a:hover { color: var(--primary-light); }
    .breadcrumb-custom .separator { color: var(--text-muted); opacity: 0.4; }
    .breadcrumb-custom .current { color: var(--primary-light); font-weight: 600; }

    .info-row {
        display: flex; align-items: flex-start; gap: 14px;
        padding: 16px 0;
        border-bottom: 1px solid var(--border-subtle);
    }
    .info-row:last-child { border-bottom: none; }
    .info-row .info-icon {
        width: 40px; height: 40px; min-width: 40px;
        background: rgba(244, 209, 255, 0.06);
        border: 1px solid var(--border-subtle);
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        color: var(--accent); font-size: 0.9rem;
    }
    .info-row .info-label {
        font-size: 0.75rem; color: var(--text-muted);
        text-transform: uppercase; letter-spacing: 0.08em; font-weight: 500;
    }
    .info-row .info-value {
        font-size: 0.95rem; color: var(--text-bright); font-weight: 600;
    }

    /* Score Circles */
    .score-circle-wrap { text-align: center; padding: 24px 16px; }
    .score-circle {
        position: relative; width: 140px; height: 140px; margin: 0 auto 16px;
    }
    .score-circle svg { width: 100%; height: 100%; transform: rotate(-90deg); }
    .score-circle .bg-ring { fill: none; stroke: rgba(244, 209, 255, 0.06); stroke-width: 8; }
    .score-circle .score-ring-progress {
        fill: none; stroke-width: 8; stroke-linecap: round;
        transition: stroke-dashoffset 1s ease;
    }
    .score-circle .score-value {
        position: absolute; top: 50%; left: 50%;
        transform: translate(-50%, -50%);
        font-family: 'Space Grotesk', sans-serif;
        font-size: 2rem; font-weight: 700; line-height: 1;
    }
    .score-circle .score-value small {
        font-size: 0.7rem; font-weight: 500; opacity: 0.6;
        display: block; margin-top: 2px;
    }
    .score-circle-wrap .score-label {
        font-size: 0.82rem; font-weight: 600; color: var(--text-light);
    }
    .score-circle-wrap .score-sublabel {
        font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;
    }
    .ring-ai    { stroke: url(#gradAi); }
    .ring-sim   { stroke: url(#gradSim); }
    .ring-marks { stroke: url(#gradMarks); }

    .report-block {
        background: rgba(244, 209, 255, 0.03);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius-sm);
        padding: 20px; margin-top: 16px;
        font-size: 0.9rem; line-height: 1.8;
        color: var(--text-body);
        white-space: pre-wrap; word-break: break-word;
    }

    /* ── Document Viewer ── */
    .doc-viewer-section { margin-bottom: 24px; }
    .doc-viewer-header {
        display: flex; align-items: center; justify-content: space-between;
        flex-wrap: wrap; gap: 12px; margin-bottom: 16px;
    }
    .doc-viewer-header .dv-title {
        display: flex; align-items: center; gap: 12px;
    }
    .doc-viewer-header .dv-title-icon {
        width: 44px; height: 44px; min-width: 44px;
        background: linear-gradient(135deg, var(--primary-mid), var(--accent));
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        color: white; font-size: 1.1rem;
        box-shadow: 0 4px 16px rgba(123,63,145,0.35);
    }
    .doc-viewer-header .dv-title h4 {
        font-weight: 700; color: var(--text-bright); margin: 0; font-size: 1.1rem;
    }
    .doc-viewer-header .dv-title span {
        font-size: 0.8rem; color: var(--text-muted);
    }
    .doc-viewer-actions {
        display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
    }
    .dv-action-btn {
        display: inline-flex; align-items: center; gap: 7px;
        padding: 9px 18px; border-radius: 10px;
        background: rgba(244, 209, 255, 0.06);
        border: 1px solid var(--border-subtle);
        color: var(--text-body); font-weight: 600;
        font-size: 0.82rem; text-decoration: none;
        transition: all 0.3s ease; cursor: pointer;
    }
    .dv-action-btn:hover {
        background: rgba(244, 209, 255, 0.12);
        color: var(--primary-light); border-color: var(--border-glow);
    }
    .dv-action-btn.primary-action {
        background: var(--primary-mid); border-color: var(--primary-mid); color: white;
    }
    .dv-action-btn.primary-action:hover {
        background: var(--primary-light);
        box-shadow: 0 4px 20px rgba(123,63,145,0.4);
        transform: translateY(-1px);
    }
    .dv-action-btn:disabled {
        opacity: 0.6; cursor: not-allowed; transform: none !important;
        box-shadow: none !important;
    }

    /* ── PDF iframe with overlays ── */
    .pdf-overlay-wrap {
        position: relative;
        border-radius: var(--radius-sm);
        overflow: hidden;
        border: 1px solid var(--border-subtle);
        background: #111;
    }
    .pdf-overlay-wrap iframe {
        width: 100%; height: 850px; border: none; display: block;
    }

    .stamp-overlay {
        position: absolute;
        bottom: 50px; right: 40px;
        width: 150px; height: 150px;
        pointer-events: none;
        z-index: 10;
        opacity: 0.85;
        filter: drop-shadow(0 2px 8px rgba(0,0,0,0.3));
        animation: stampAppear 0.6s ease-out both;
        animation-delay: 0.5s;
    }
    @keyframes stampAppear {
        from { opacity: 0; transform: scale(1.3) rotate(-8deg); }
        to   { opacity: 0.85; transform: scale(1) rotate(-3deg); }
    }
    .stamp-overlay img {
        width: 100%; height: 100%; object-fit: contain;
        transform: rotate(-3deg);
    }

    .qr-overlay {
        position: absolute;
        bottom: 30px; left: 40px;
        z-index: 10;
        pointer-events: none;
        text-align: center;
        animation: qrAppear 0.5s ease-out both;
        animation-delay: 0.8s;
    }
    @keyframes qrAppear {
        from { opacity: 0; transform: translateY(10px); }
        to   { opacity: 1; transform: translateY(0); }
    }
    .qr-overlay img {
        width: 85px; height: 85px;
        border-radius: 8px;
        border: 3px solid rgba(255,255,255,0.9);
        box-shadow: 0 2px 12px rgba(0,0,0,0.4);
        background: white;
    }
    .cert-key-box {
        font-family: 'Space Grotesk', monospace;
        font-size: 0.72rem; font-weight: 700;
        letter-spacing: 0.06em;
        color: #2D1B4E;
        background: rgba(255,255,255,0.95);
        border: 3px solid rgba(255,255,255,0.9);
        border-radius: 8px;
        padding: 8px 12px;
        box-shadow: 0 2px 12px rgba(0,0,0,0.4);
        white-space: nowrap;
    }
    .qr-overlay .qr-label {
        font-size: 0.62rem; font-weight: 700;
        color: white; margin-top: 5px;
        text-shadow: 0 1px 4px rgba(0,0,0,0.6);
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    /* Page lock overlay */
    .page-lock-overlay {
        width: 100%; max-width: 680px;
        background: rgba(21, 10, 30, 0.95);
        backdrop-filter: blur(24px);
        border: 1px solid var(--border-glow);
        border-radius: var(--radius-lg);
        padding: 48px 36px; text-align: center;
        box-shadow: var(--shadow-lg), 0 0 60px rgba(244,209,255,0.06);
        animation: lockPulse 3s ease-in-out infinite;
        margin: 0 auto;
    }
    @keyframes lockPulse {
        0%, 100% { box-shadow: var(--shadow-lg), 0 0 40px rgba(244,209,255,0.04); }
        50% { box-shadow: var(--shadow-lg), 0 0 70px rgba(216,143,255,0.12); }
    }
    .page-lock-overlay .plo-icon {
        width: 76px; height: 76px;
        background: linear-gradient(135deg, var(--primary-mid), var(--accent));
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        margin: 0 auto 24px; font-size: 1.8rem; color: white;
        box-shadow: 0 8px 32px rgba(123,63,145,0.5);
    }
    .page-lock-overlay .plo-icon.payment-icon {
        background: linear-gradient(135deg, #d97706, #fbbf24);
        box-shadow: 0 8px 32px rgba(245,158,11,0.4);
    }
    .page-lock-overlay h3 {
        font-weight: 800; color: var(--text-bright);
        font-size: 1.3rem; margin-bottom: 12px;
    }
    .page-lock-overlay p {
        color: var(--text-muted); font-size: 0.9rem; line-height: 1.7;
        margin-bottom: 24px;
    }
    .page-lock-overlay .plo-pages-info {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 6px 16px; border-radius: 50px;
        font-size: 0.78rem; font-weight: 600;
        background: rgba(245, 158, 11, 0.08);
        border: 1px solid rgba(245, 158, 11, 0.15);
        color: #fbbf24; margin-bottom: 20px;
    }
    .go-to-plans-btn {
        display: inline-flex; align-items: center; gap: 10px;
        padding: 14px 32px; border-radius: 14px;
        background: var(--primary-mid); border: 1px solid var(--primary-mid);
        color: white; font-weight: 700; font-size: 0.95rem;
        text-decoration: none; transition: all 0.3s ease;
    }
    .go-to-plans-btn:hover {
        background: var(--primary-light); border-color: var(--primary-light);
        transform: translateY(-2px);
        box-shadow: 0 8px 30px rgba(123,63,145,0.45);
    }
    .go-to-plans-btn.payment-btn {
        background: linear-gradient(135deg, #d97706, #f59e0b);
        border-color: #d97706;
    }
    .go-to-plans-btn.payment-btn:hover {
        background: linear-gradient(135deg, #f59e0b, #fbbf24);
        border-color: #f59e0b;
        box-shadow: 0 8px 30px rgba(245,158,11,0.45);
    }

    /* ── QR Code Card ── */
    .qr-verify-card {
        display: flex; align-items: center; gap: 28px;
        flex-wrap: wrap;
    }
    .qr-verify-card .qr-img-box {
        width: 130px; height: 130px; min-width: 130px;
        border-radius: 16px;
        border: 3px solid var(--border-glow);
        background: white;
        padding: 8px;
        display: flex; align-items: center; justify-content: center;
        box-shadow: 0 4px 20px rgba(123,63,145,0.15);
    }
    .qr-verify-card .qr-img-box img {
        width: 100%; height: 100%; border-radius: 8px;
    }
    .qr-verify-card .qr-info h4 {
        font-weight: 700; color: var(--text-bright); margin: 0 0 6px;
        font-size: 1.1rem;
    }
    .qr-verify-card .qr-info p {
        color: var(--text-muted); font-size: 0.88rem; line-height: 1.7;
        margin: 0 0 14px;
    }
    .qr-verify-card .vcode-inline {
        display: inline-flex; align-items: center; gap: 10px;
        font-family: 'Space Grotesk', monospace;
        font-size: 1rem; font-weight: 700;
        color: var(--primary-light);
        letter-spacing: 0.1em;
        background: rgba(244, 209, 255, 0.06);
        border: 1px solid var(--border-subtle);
        padding: 8px 16px; border-radius: 10px;
    }
    .qr-verify-card .vcode-inline .vcode-copy {
        background: none; border: none; cursor: pointer;
        color: var(--text-muted); font-size: 0.9rem;
        transition: color 0.2s; padding: 0;
    }
    .qr-verify-card .vcode-inline .vcode-copy:hover { color: var(--primary-light); }
    .qr-verify-card .qr-link {
        display: inline-flex; align-items: center; gap: 6px;
        margin-top: 12px;
        font-size: 0.8rem; color: var(--accent);
        text-decoration: none; font-weight: 600;
        transition: color 0.2s;
        word-break: break-all;
    }
    .qr-verify-card .qr-link:hover { color: var(--primary-light); }

    /* ── Cover Page Card ── */
    .cover-page-card { background: linear-gradient(160deg, rgba(123,63,145,0.12), rgba(244,209,255,0.03)); }
    .cover-header { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:16px; }
    .cover-brand { display:flex; align-items:center; gap:14px; }
    .cover-logo { width:52px; height:52px; border-radius:14px; object-fit:cover; background:rgba(255,255,255,0.08); }
    .cover-company-name { font-weight:800; font-size:1.15rem; color:var(--text-bright); letter-spacing:-0.02em; }
    .cover-company-tagline { font-size:0.78rem; color:var(--text-muted); margin-top:2px; }
    .cover-status-badge { display:inline-flex; align-items:center; gap:8px; padding:8px 18px; border-radius:60px; background:rgba(34,197,94,0.12); border:1px solid rgba(34,197,94,0.3); color:#4ade80; font-weight:700; font-size:0.82rem; }
    .cover-divider { height:1px; margin:20px 0; background:linear-gradient(90deg,transparent,var(--border-glow),transparent); }
    .cover-body { display:grid; grid-template-columns:1fr 1fr; gap:4px 24px; margin-bottom:20px; }
    .cover-row { display:flex; justify-content:space-between; padding:8px 0; border-bottom:1px dashed var(--border-subtle); font-size:0.88rem; }
    .cover-label { color:var(--text-muted); }
    .cover-value { color:var(--text-bright); font-weight:700; text-align:right; }
    .cover-qr-wrap { display:flex; align-items:center; gap:16px; padding-top:16px; border-top:1px solid var(--border-subtle); }
    .cover-cert-icon {
        width:56px; height:56px; min-width:56px; border-radius:12px;
        background:rgba(255,255,255,0.95); display:flex; align-items:center; justify-content:center;
        font-size:1.5rem; color:#7B3F91;
    }
    .cover-qr-label { font-size:0.8rem; color:var(--text-muted); }
    .cover-qr-code { font-family:'Space Grotesk',monospace; font-weight:700; color:var(--primary-light); letter-spacing:0.08em; margin-top:2px; }
    @media (max-width:767px) { .cover-body { grid-template-columns:1fr; } }
    @media print { .cover-page-card { break-after: page; } }

    /* Subscription badge */
    .sub-badge {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 6px 14px; border-radius: 50px;
        font-size: 0.78rem; font-weight: 600;
    }
    .sub-badge.active {
        background: rgba(34,197,94,0.1);
        border: 1px solid rgba(34,197,94,0.2); color: #4ade80;
    }
    .sub-badge.free {
        background: rgba(216,143,255,0.1);
        border: 1px solid rgba(216,143,255,0.2); color: var(--accent);
    }

    /* Reviewed-at badge */
    .reviewed-at-badge {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 8px 16px; border-radius: 12px;
        background: rgba(244, 209, 255, 0.06);
        border: 1px solid var(--border-subtle);
        font-size: 0.82rem; color: var(--text-body);
    }
    .reviewed-at-badge i { color: var(--accent); }
    .reviewed-at-badge strong { color: var(--primary-light); }

    /* File not found warning */
    .file-warning {
        display: flex; align-items: flex-start; gap: 14px;
        padding: 20px; border-radius: var(--radius-sm);
        background: rgba(239, 68, 68, 0.06);
        border: 1px solid rgba(239, 68, 68, 0.15);
        color: #f87171; font-size: 0.88rem;
    }
    .file-warning i { font-size: 1.3rem; flex-shrink: 0; margin-top: 2px; }
    .file-warning code {
        font-size: 0.78rem; color: var(--text-muted);
        background: rgba(0,0,0,0.2); padding: 2px 8px;
        border-radius: 4px; display: block; margin-top: 8px;
        word-break: break-all;
    }

    /* Pending review card */
    .pending-card { text-align: center; padding: 60px 30px; }
    .pending-card .pending-icon {
        width: 100px; height: 100px;
        background: rgba(244, 209, 255, 0.06);
        border: 2px dashed var(--border-glow);
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        margin: 0 auto 28px; font-size: 2.5rem; color: var(--accent);
        animation: pendingBounce 2s ease-in-out infinite;
    }
    @keyframes pendingBounce {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-10px); }
    }
    .pending-card h3 {
        font-weight: 800; color: var(--text-bright);
        font-size: 1.5rem; margin-bottom: 12px;
    }
    .pending-card p { color: var(--text-muted); font-size: 0.92rem; }

    /* Toast notification */
    .toast-msg {
        position: fixed; bottom: 30px; left: 50%;
        transform: translateX(-50%) translateY(20px);
        background: rgba(21, 10, 30, 0.95);
        backdrop-filter: blur(16px);
        border: 1px solid var(--border-glow);
        border-radius: 14px;
        padding: 14px 28px;
        color: var(--text-bright);
        font-size: 0.88rem; font-weight: 600;
        z-index: 9999;
        opacity: 0;
        transition: all 0.4s ease;
        pointer-events: none;
        box-shadow: 0 8px 32px rgba(0,0,0,0.4);
    }
    .toast-msg.show {
        opacity: 1; transform: translateX(-50%) translateY(0);
    }

    /* ── ★ PAYMENT REQUIRED NOTIFICATION MODAL ★ ── */
    .pay-notif-overlay {
        position: fixed; inset: 0;
        background: rgba(0, 0, 0, 0.7);
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        z-index: 10001;
        display: flex; align-items: center; justify-content: center;
        padding: 20px;
        opacity: 0; visibility: hidden;
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .pay-notif-overlay.active {
        opacity: 1; visibility: visible;
    }
    .pay-notif-box {
        background: linear-gradient(150deg, #1a0d26, #130820, #150A1E);
        border: 1px solid var(--border-glow);
        border-radius: var(--radius-lg);
        padding: 48px 40px 40px;
        max-width: 460px; width: 100%;
        text-align: center;
        box-shadow: 0 30px 80px rgba(0,0,0,0.7), 0 0 80px rgba(244,209,255,0.04);
        transform: scale(0.8) translateY(30px);
        transition: transform 0.45s cubic-bezier(0.34, 1.56, 0.64, 1);
        position: relative; overflow: hidden;
    }
    .pay-notif-overlay.active .pay-notif-box {
        transform: scale(1) translateY(0);
    }
    .pay-notif-box::before {
        content: '';
        position: absolute;
        top: -60%; left: -30%;
        width: 160%; height: 160%;
        background: radial-gradient(circle at 40% 60%, rgba(245,158,11,0.06) 0%, transparent 50%);
        pointer-events: none;
    }
    .pay-notif-icon {
        width: 88px; height: 88px;
        border-radius: 50%;
        background: linear-gradient(135deg, rgba(245,158,11,0.12), rgba(245,158,11,0.06));
        border: 2px solid rgba(245,158,11,0.3);
        box-shadow: 0 0 40px rgba(245,158,11,0.12), 0 8px 32px rgba(245,158,11,0.08);
        display: flex; align-items: center; justify-content: center;
        margin: 0 auto 28px;
        position: relative; z-index: 1;
        animation: payIconPulse 2.5s ease-in-out infinite;
    }
    @keyframes payIconPulse {
        0%, 100% { box-shadow: 0 0 40px rgba(245,158,11,0.12), 0 8px 32px rgba(245,158,11,0.08); }
        50% { box-shadow: 0 0 60px rgba(245,158,11,0.2), 0 8px 40px rgba(245,158,11,0.15); }
    }
    .pay-notif-icon i {
        font-size: 2.2rem; color: #fbbf24;
        filter: drop-shadow(0 2px 6px rgba(251,191,36,0.3));
    }
    .pay-notif-box h4 {
        color: #FFFFFF; font-weight: 800;
        font-size: 1.25rem; margin-bottom: 12px;
        letter-spacing: -0.01em;
        position: relative; z-index: 1;
    }
    .pay-notif-box .pay-notif-desc {
        color: var(--text-body); font-size: 0.9rem; line-height: 1.75;
        margin-bottom: 8px;
        position: relative; z-index: 1;
    }
    .pay-notif-box .pay-notif-hint {
        color: var(--text-muted); font-size: 0.8rem; line-height: 1.6;
        margin-bottom: 32px;
        position: relative; z-index: 1;
    }
    .pay-notif-actions {
        display: flex; gap: 12px; justify-content: center; flex-wrap: wrap;
        position: relative; z-index: 1;
    }
    .pay-notif-btn {
        padding: 13px 30px; border-radius: 50px;
        font-weight: 700; font-size: 0.88rem;
        cursor: pointer; transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        text-decoration: none; display: inline-flex;
        align-items: center; gap: 8px;
        font-family: 'Poppins', sans-serif;
        border: none;
    }
    .pay-notif-btn-primary {
        background: linear-gradient(135deg, #d97706, #f59e0b);
        color: white;
        box-shadow: 0 4px 20px rgba(245,158,11,0.4);
    }
    .pay-notif-btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 32px rgba(245,158,11,0.6);
        color: white;
        background: linear-gradient(135deg, #f59e0b, #fbbf24);
    }
    .pay-notif-btn-secondary {
        background: rgba(244, 209, 255, 0.06);
        border: 1px solid var(--border-subtle) !important;
        color: var(--text-body);
    }
    .pay-notif-btn-secondary:hover {
        background: rgba(244, 209, 255, 0.12);
        color: var(--primary-light);
        transform: translateY(-2px);
    }
    /* Pulse ring animation on icon */
    @keyframes payNotifRing {
        0% { transform: scale(1); opacity: 0.4; }
        100% { transform: scale(1.5); opacity: 0; }
    }
    .pay-notif-icon::after {
        content: '';
        position: absolute; inset: -6px;
        border-radius: 50%;
        border: 2px solid rgba(245,158,11,0.3);
        opacity: 0;
    }
    .pay-notif-overlay.active .pay-notif-icon::after {
        animation: payNotifRing 1s 0.4s ease-out;
    }

    /* ── Receipt Modal ── */
    .receipt-modal-overlay {
        position: fixed; inset: 0;
        background: rgba(5, 2, 10, 0.8);
        backdrop-filter: blur(12px);
        z-index: 10000;
        display: flex; align-items: center; justify-content: center;
        padding: 20px;
        opacity: 0; pointer-events: none;
        transition: opacity 0.35s ease;
    }
    .receipt-modal-overlay.active {
        opacity: 1; pointer-events: all;
    }
    .receipt-modal-box {
        background: #110a1a;
        border: 1px solid var(--border-glow);
        border-radius: var(--radius-lg);
        width: 100%; max-width: 520px;
        max-height: 90vh; overflow-y: auto;
        box-shadow: 0 24px 80px rgba(0,0,0,0.6), 0 0 60px rgba(216,143,255,0.08);
        transform: translateY(20px) scale(0.97);
        transition: transform 0.35s ease;
    }
    .receipt-modal-overlay.active .receipt-modal-box {
        transform: translateY(0) scale(1);
    }
    .receipt-modal-box::-webkit-scrollbar { width: 5px; }
    .receipt-modal-box::-webkit-scrollbar-track { background: transparent; }
    .receipt-modal-box::-webkit-scrollbar-thumb { background: rgba(244,209,255,0.15); border-radius: 3px; }

    .receipt-modal-header {
        display: flex; align-items: center; justify-content: space-between;
        padding: 24px 28px 0;
    }
    .receipt-modal-header h3 {
        font-weight: 800; color: var(--text-bright);
        font-size: 1.2rem; margin: 0;
        display: flex; align-items: center; gap: 10px;
    }
    .receipt-modal-header h3 i { color: var(--accent); }
    .receipt-close-btn {
        width: 36px; height: 36px;
        background: rgba(244,209,255,0.06);
        border: 1px solid var(--border-subtle);
        border-radius: 10px;
        color: var(--text-muted); font-size: 0.9rem;
        cursor: pointer; display: flex; align-items: center; justify-content: center;
        transition: all 0.25s ease;
    }
    .receipt-close-btn:hover {
        background: rgba(239,68,68,0.1);
        border-color: rgba(239,68,68,0.25);
        color: #f87171;
    }

    .receipt-body { padding: 24px 28px 28px; }

    .receipt-brand {
        text-align: center; padding-bottom: 20px;
        border-bottom: 2px dashed rgba(244,209,255,0.1);
        margin-bottom: 20px;
    }
    .receipt-brand .rb-icon {
        width: 52px; height: 52px;
        background: linear-gradient(135deg, var(--primary-mid), var(--accent));
        border-radius: 16px;
        display: flex; align-items: center; justify-content: center;
        margin: 0 auto 12px; font-size: 1.3rem; color: white;
        box-shadow: 0 6px 24px rgba(123,63,145,0.4);
    }
    .receipt-brand h4 {
        font-weight: 800; color: var(--text-bright);
        font-size: 1.1rem; margin: 0 0 4px;
    }
    .receipt-brand span {
        font-size: 0.78rem; color: var(--text-muted);
    }

    .receipt-status {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 5px 14px; border-radius: 50px;
        font-size: 0.75rem; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.06em;
    }
    .receipt-status.paid {
        background: rgba(34,197,94,0.1);
        border: 1px solid rgba(34,197,94,0.2);
        color: #4ade80;
    }

    .receipt-table {
        width: 100%; border-collapse: collapse;
        margin-bottom: 20px;
    }
    .receipt-table tr { border-bottom: 1px solid rgba(244,209,255,0.05); }
    .receipt-table tr:last-child { border-bottom: none; }
    .receipt-table td {
        padding: 10px 0; font-size: 0.85rem; vertical-align: top;
    }
    .receipt-table td:first-child {
        color: var(--text-muted); font-weight: 500;
        width: 40%; padding-right: 12px;
    }
    .receipt-table td:last-child {
        color: var(--text-bright); font-weight: 600;
        text-align: right; word-break: break-word;
    }

    .receipt-amounts {
        background: rgba(244,209,255,0.03);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius-sm);
        padding: 16px 20px; margin-bottom: 20px;
    }
    .receipt-amounts .ra-row {
        display: flex; align-items: center; justify-content: space-between;
        padding: 6px 0; font-size: 0.85rem;
    }
    .receipt-amounts .ra-row .ra-label { color: var(--text-muted); font-weight: 500; }
    .receipt-amounts .ra-row .ra-value { color: var(--text-body); font-weight: 600; }
    .receipt-amounts .ra-row .ra-value.discount { color: #4ade80; }
    .receipt-amounts .ra-divider {
        height: 1px; background: rgba(244,209,255,0.08);
        margin: 8px 0;
    }
    .receipt-amounts .ra-row.total { padding: 8px 0 2px; }
    .receipt-amounts .ra-row.total .ra-label {
        font-size: 0.9rem; font-weight: 700; color: var(--text-light);
    }
    .receipt-amounts .ra-row.total .ra-value {
        font-family: 'Space Grotesk', sans-serif;
        font-size: 1.4rem; font-weight: 800; color: var(--primary-light);
    }

    .receipt-voucher {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 6px 14px; border-radius: 8px;
        background: rgba(34,197,94,0.06);
        border: 1px solid rgba(34,197,94,0.12);
        font-size: 0.8rem; font-weight: 600; color: #4ade80;
    }

    .receipt-footer {
        text-align: center; padding-top: 16px;
        border-top: 2px dashed rgba(244,209,255,0.1);
    }
    .receipt-footer p {
        font-size: 0.75rem; color: var(--text-muted);
        margin: 0; line-height: 1.6;
    }
    .receipt-footer .rf-txn {
        font-family: 'Space Grotesk', monospace;
        font-size: 0.72rem; color: rgba(244,209,255,0.3);
        margin-top: 6px; word-break: break-all;
    }

    .receipt-print-btn {
        display: flex; align-items: center; justify-content: center; gap: 8px;
        width: 100%; padding: 14px;
        background: var(--primary-mid);
        border: 1px solid var(--primary-mid);
        border-radius: 12px;
        color: white; font-weight: 700; font-size: 0.9rem;
        cursor: pointer; transition: all 0.3s ease;
        margin-top: 16px; font-family: 'Poppins', sans-serif;
    }
    .receipt-print-btn:hover {
        background: var(--primary-light);
        border-color: var(--primary-light);
        box-shadow: 0 6px 24px rgba(123,63,145,0.4);
        transform: translateY(-1px);
    }

    @media (max-width: 767px) {
        .view-page { padding: 110px 0 60px; }
        .score-circle { width: 120px; height: 120px; }
        .score-circle .score-value { font-size: 1.6rem; }
        .doc-viewer-header { flex-direction: column; align-items: flex-start; }
        .pdf-overlay-wrap iframe { height: 600px; }
        .page-lock-overlay { padding: 36px 24px; }
        .stamp-overlay { width: 110px; height: 110px; bottom: 30px; right: 20px; }
        .cert-key-box { font-size: 0.62rem; padding: 6px 9px; }
        .qr-verify-card { flex-direction: column; text-align: center; }
        .qr-verify-card .qr-img-box { width: 110px; height: 110px; min-width: 110px; }
        .qr-verify-card .vcode-inline { justify-content: center; }
        .pay-notif-box { padding: 36px 24px 32px; }
        .pay-notif-icon { width: 72px; height: 72px; }
        .pay-notif-icon i { font-size: 1.8rem; }
        .pay-notif-actions { flex-direction: column; }
        .pay-notif-btn { justify-content: center; }
    }

    @media (max-width: 576px) {
        .receipt-modal-header { padding: 20px 20px 0; }
        .receipt-body { padding: 20px; }
        .receipt-table td { font-size: 0.8rem; }
        .receipt-amounts .ra-row.total .ra-value { font-size: 1.2rem; }
    }
</style>

<!-- SVG Gradients (hidden) -->
<svg width="0" height="0" style="position:absolute;">
    <defs>
        <linearGradient id="gradAi" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="#D88FFF"/>
            <stop offset="100%" stop-color="#FF8EC4"/>
        </linearGradient>
        <linearGradient id="gradSim" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="#3B1347"/>
            <stop offset="100%" stop-color="#D88FFF"/>
        </linearGradient>
        <linearGradient id="gradMarks" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="#22c55e"/>
            <stop offset="100%" stop-color="#4ade80"/>
        </linearGradient>
    </defs>
</svg>

<!-- ★ PAYMENT REQUIRED NOTIFICATION MODAL ★ -->
<div class="pay-notif-overlay <?php echo ($needsPayment && $isReviewed) ? 'active' : ''; ?>" id="payNotifOverlay">
    <div class="pay-notif-box">
        <div class="pay-notif-icon">
            <i class="fa-solid fa-credit-card"></i>
        </div>
        <h4>Payment Required</h4>
        <p class="pay-notif-desc">
            Your assignment has been reviewed and is ready to view. Please complete the payment first to access your results, feedback, and reviewed document.
        </p>
        <p class="pay-notif-hint">
            <i class="fa-solid fa-circle-info me-1"></i>
            You only need to pay once per assignment. After payment, you can view it anytime.
        </p>
        <div class="pay-notif-actions">
            <button onclick="closePayNotif()" class="pay-notif-btn pay-notif-btn-secondary">
                <i class="fa-solid fa-arrow-left"></i> Back
            </button>
        </div>
    </div>
</div>

<!-- Receipt Modal -->
<div class="receipt-modal-overlay" id="receiptModal">
    <div class="receipt-modal-box">
        <div class="receipt-modal-header">
            <h3><i class="fa-solid fa-receipt"></i> Payment Receipt</h3>
            <button class="receipt-close-btn" onclick="closeReceiptModal()">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div class="receipt-body">
            <div class="receipt-brand">
                <div class="rb-icon"><i class="fa-solid fa-shield-halved"></i></div>
                <h4>AI Assignment Checker</h4>
                <span>Official Payment Receipt</span>
            </div>

            <div style="text-align:center;margin-bottom:20px;">
                <span class="receipt-status paid">
                    <i class="fa-solid fa-circle-check"></i> Paid
                </span>
            </div>

            <table class="receipt-table">
                <tr>
                    <td>Receipt No.</td>
                    <td>#RCP-<?php echo str_pad($payment['transaction_id'] ?? '000', 5, '0', STR_PAD_LEFT); ?></td>
                </tr>
                <tr>
                    <td>Date</td>
                    <td><?php echo $payment['paid_at'] ? date('M d, Y h:i A', strtotime($payment['paid_at'])) : '—'; ?></td>
                </tr>
                <tr>
                    <td>Customer</td>
                    <td><?php echo htmlspecialchars($userName ?? 'User'); ?></td>
                </tr>
                <tr>
                    <td>Assignment</td>
                    <td><?php echo htmlspecialchars($assignment['title']); ?></td>
                </tr>
                <tr>
                    <td>Payment Method</td>
                    <td><?php echo htmlspecialchars(ucfirst($payment['payment_method'] ?? 'Online')); ?></td>
                </tr>
                <?php if (!empty($payment['transaction_ref'])): ?>
                <tr>
                    <td>Transaction Ref</td>
                    <td style="font-family:'Space Grotesk',monospace;font-size:0.78rem;letter-spacing:0.05em;"><?php echo htmlspecialchars($payment['transaction_ref']); ?></td>
                </tr>
                <?php endif; ?>
            </table>

            <div class="receipt-amounts">
                <div class="ra-row">
                    <span class="ra-label">Subtotal</span>
                    <span class="ra-value">RM <?php echo number_format((float)($payment['amount'] ?? 0) + (float)($payment['discount_amount'] ?? 0), 2); ?></span>
                </div>
                <?php if (!empty($payment['discount_amount']) && (float)$payment['discount_amount'] > 0): ?>
                <div class="ra-row">
                    <span class="ra-label">Discount</span>
                    <span class="ra-value discount">- RM <?php echo number_format((float)$payment['discount_amount'], 2); ?></span>
                </div>
                <?php endif; ?>
                <div class="ra-divider"></div>
                <div class="ra-row total">
                    <span class="ra-label">Total Paid</span>
                    <span class="ra-value">RM <?php echo number_format((float)($payment['amount'] ?? 0), 2); ?></span>
                </div>
            </div>

            <?php if (!empty($payment['voucher_code'])): ?>
            <div style="margin-bottom:20px;">
                <span class="receipt-voucher">
                    <i class="fa-solid fa-ticket"></i>
                    Voucher: <?php echo htmlspecialchars($payment['voucher_code']); ?>
                </span>
            </div>
            <?php endif; ?>

            <div class="receipt-footer">
                <p>Thank you for using AI Assignment Checker.<br>This receipt serves as proof of payment.</p>
                <div class="rf-txn">TXN-<?php echo htmlspecialchars($payment['transaction_ref'] ?? md5($payment['transaction_id'] ?? 'x')); ?></div>
            </div>

            <button class="receipt-print-btn" onclick="printReceipt()">
                <i class="fa-solid fa-print"></i> Print Receipt
            </button>
        </div>
    </div>
</div>

<div class="toast-msg" id="toastMsg"></div>

<main class="main-content view-page">
    <div class="container">

        <!-- Breadcrumb -->
        <div class="breadcrumb-custom" data-aos="fade-right">
            <a href="assignments.php"><i class="fa-solid fa-arrow-left me-1"></i> My Assignments</a>
            <span class="separator"><i class="fa-solid fa-chevron-right" style="font-size:0.6rem;"></i></span>
            <span class="current"><?php echo htmlspecialchars($assignment['title']); ?></span>
        </div>

        <!-- Assignment Info Card -->
        <div class="glass-card p-4 p-md-5 mb-4" data-aos="fade-up">
            <div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
                <div>
                    <h1 style="font-weight:800;color:var(--text-bright);font-size:clamp(1.3rem,2.5vw,1.8rem);letter-spacing:-0.02em;margin-bottom:6px;">
                        <?php echo htmlspecialchars($assignment['title']); ?>
                    </h1>
                    <span style="font-size:0.85rem;color:var(--text-muted);">
                        <i class="fa-solid fa-book-open me-1"></i> <?php echo htmlspecialchars($assignment['subject']); ?>
                    </span>
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <span class="sub-badge <?php echo $hasSubscription ? 'active' : 'free'; ?>">
                        <i class="fa-solid <?php echo $hasSubscription ? 'fa-crown' : 'fa-user'; ?>"></i>
                        <?php echo $hasSubscription ? 'Subscriber' : 'Free Plan'; ?>
                    </span>
                    <?php if ($isReviewed && !$needsPayment && $assignment['reviewed_at']): ?>
                    <div class="reviewed-at-badge">
                        <i class="fa-solid fa-clock"></i>
                        Reviewed at <strong><?php echo date('M d, Y h:i A', strtotime($assignment['reviewed_at'])); ?></strong>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row g-0">
                <div class="col-md-6">
                    <div class="info-row">
                        <div class="info-icon"><i class="fa-solid fa-calendar"></i></div>
                        <div>
                            <div class="info-label">Submitted</div>
                            <div class="info-value"><?php echo date('M d, Y h:i A', strtotime($assignment['submission_date'])); ?></div>
                        </div>
                    </div>
                    <?php if ($displayIsPdf && $displayPages > 0 && !$needsPayment): ?>
                    <div class="info-row">
                        <div class="info-icon"><i class="fa-solid fa-file-pdf"></i></div>
                        <div>
                            <div class="info-label">Document Pages</div>
                            <div class="info-value">
                                <?php echo $displayPages; ?> page<?php echo $displayPages !== 1 ? 's' : ''; ?>
                                <?php if (!$hasSubscription && $displayPages > $FREE_PAGE_LIMIT): ?>
                                    <span style="font-size:0.75rem;color:#fbbf24;font-weight:500;margin-left:8px;">
                                        <i class="fa-solid fa-lock me-1"></i>First <?php echo $FREE_PAGE_LIMIT; ?> free
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <div class="info-row">
                        <div class="info-icon"><i class="fa-solid fa-user-shield"></i></div>
                        <div>
                            <div class="info-label">Access Level</div>
                            <div class="info-value">
                                <?php if ($needsPayment): ?>
                                    <span style="color:#fbbf24;"><i class="fa-solid fa-lock me-1"></i>Payment Required</span>
                                <?php elseif ($hasSubscription): ?>
                                    <span style="color:#4ade80;">Full Access</span>
                                <?php else: ?>
                                    <span style="color:var(--accent);">Free — <?php echo $FREE_PAGE_LIMIT; ?> pages per document</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php if ($isReviewed && !$needsPayment && $certificateKey): ?>
                    <div class="info-row">
                        <div class="info-icon"><i class="fa-solid fa-certificate"></i></div>
                        <div>
                            <div class="info-label">Certificate Key</div>
                            <div style="display:flex;align-items:center;gap:10px;margin-top:4px;">
                                <span style="font-family:'Space Grotesk',monospace;font-size:0.95rem;font-weight:700;color:var(--primary-light);letter-spacing:0.12em;background:rgba(244,209,255,0.06);border:1px solid var(--border-subtle);padding:4px 14px;border-radius:8px;">
                                    <?php echo htmlspecialchars($certificateKey); ?>
                                </span>
                                <button onclick="copyVCode()" style="background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:0.85rem;transition:color 0.2s;padding:4px;" title="Copy code">
                                    <i class="fa-regular fa-copy"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!$isReviewed): ?>
        <!-- ── PENDING REVIEW ── -->
        <div class="glass-card pending-card" data-aos="fade-up" data-aos-delay="100">
            <div class="pending-icon">
                <i class="fa-solid fa-magnifying-glass-chart"></i>
            </div>
            <h3>Your Assignment is Being Reviewed</h3>
            <p>Our AI system and expert reviewers are analyzing your work.<br>This usually takes 24–48 hours. You'll be notified once it's ready.</p>
            <a href="assignments.php" class="btn btn-outline-purple mt-3">
                <i class="fa-solid fa-list me-2"></i>Back to Assignments
            </a>
        </div>

        <?php elseif ($needsPayment): ?>
        <!-- ── ★ PAYMENT REQUIRED — BLOCKED VIEW ★ ── -->
        <div class="glass-card" style="padding:60px 30px;text-align:center;" data-aos="fade-up">
            <div style="width:110px;height:110px;border-radius:50%;background:linear-gradient(135deg,rgba(245,158,11,0.1),rgba(245,158,11,0.04));border:2px solid rgba(245,158,11,0.25);display:flex;align-items:center;justify-content:center;margin:0 auto 28px;font-size:2.4rem;color:#fbbf24;box-shadow:0 0 50px rgba(245,158,11,0.1);animation:payIconPulse 2.5s ease-in-out infinite;">
                <i class="fa-solid fa-credit-card"></i>
            </div>
            <h3 style="font-weight:800;color:var(--text-bright);font-size:1.6rem;margin-bottom:14px;">Payment Required</h3>
            <p style="color:var(--text-muted);font-size:0.95rem;max-width:480px;margin:0 auto 12px;line-height:1.8;">
                Your assignment <strong style="color:var(--primary-light);"><?php echo htmlspecialchars($assignment['title']); ?></strong> has been reviewed and is ready to view.
            </p>
            <p style="color:var(--text-muted);font-size:0.88rem;max-width:440px;margin:0 auto 32px;line-height:1.7;">
                Please complete the payment to unlock your AI score, plagiarism report, reviewer feedback, and the reviewed document.
            </p>
            <div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap;">
                <a href="payment.php?id=<?php echo $assignment_id; ?>" class="btn btn-purple" style="padding:16px 40px;font-size:0.95rem;background:linear-gradient(135deg,#d97706,#f59e0b);box-shadow:0 4px 24px rgba(245,158,11,0.4);">
                    <i class="fa-solid fa-credit-card me-2"></i> Make Payment Now
                </a>
            </div>
            <div style="margin-top:28px;">
                <a href="assignments.php" style="color:var(--text-muted);font-size:0.85rem;text-decoration:none;transition:color 0.3s;" onmouseover="this.style.color='var(--primary-light)'" onmouseout="this.style.color='var(--text-muted)'">
                    <i class="fa-solid fa-arrow-left me-1"></i> Back to My Assignments
                </a>
            </div>
        </div>

        <?php else: ?>
        <!-- ── REVIEWED — FULL RESULTS (payment done or has subscription) ── -->
        <?php
            $aiScore  = $assignment['ai_score'] !== null ? round((float)$assignment['ai_score'], 1) : 0;
            $simScore = $assignment['similarity'] !== null ? round((float)$assignment['similarity'], 1) : 0;
            $marks    = $assignment['marks'] !== null ? (int)$assignment['marks'] : 0;

            if ($aiScore <= 20) { $aiColor = '#22c55e'; $aiLabel = 'Low AI'; }
            elseif ($aiScore <= 50) { $aiColor = '#f59e0b'; $aiLabel = 'Moderate'; }
            else { $aiColor = '#ef4444'; $aiLabel = 'High AI'; }

            if ($simScore <= 15) { $simColor = '#22c55e'; $simLabel = 'Original'; }
            elseif ($simScore <= 35) { $simColor = '#f59e0b'; $simLabel = 'Some Matches'; }
            else { $simColor = '#ef4444'; $simLabel = 'High Similarity'; }
        ?>

        <!-- Cover Page -->
        <div class="glass-card cover-page-card p-4 p-md-5 mb-4" data-aos="fade-up">
            <div class="cover-page-inner">
                <div class="cover-header">
                    <div class="cover-brand">
                        <img src="image/logo.png" alt="AI Checker" class="cover-logo" onerror="this.style.display='none';">
                        <div>
                            <div class="cover-company-name">AI Assignment Checker</div>
                            <div class="cover-company-tagline">Automated AI &amp; Plagiarism Verification Report</div>
                        </div>
                    </div>
                    <div class="cover-status-badge">
                        <i class="fa-solid fa-circle-check"></i> Verified Result
                    </div>
                </div>
                <div class="cover-divider"></div>
                <div class="cover-body">
                    <div class="cover-row"><span class="cover-label">Student</span><span class="cover-value"><?php echo htmlspecialchars($userName ?? 'Student'); ?></span></div>
                    <div class="cover-row"><span class="cover-label">Assignment Title</span><span class="cover-value"><?php echo htmlspecialchars($assignment['title']); ?></span></div>
                    <div class="cover-row"><span class="cover-label">Subject</span><span class="cover-value"><?php echo htmlspecialchars($assignment['subject']); ?></span></div>
                    <div class="cover-row"><span class="cover-label">AI Content</span><span class="cover-value" style="color:<?php echo $aiColor; ?>;"><?php echo $aiScore; ?>% (<?php echo $aiLabel; ?>)</span></div>
                    <div class="cover-row"><span class="cover-label">Similarity</span><span class="cover-value" style="color:<?php echo $simColor; ?>;"><?php echo $simScore; ?>% (<?php echo $simLabel; ?>)</span></div>
                    <div class="cover-row"><span class="cover-label">Marks</span><span class="cover-value">/<?php echo $marks; ?> 100</span></div>
                </div>
                <?php if ($certificateKey): ?>
                <div class="cover-qr-wrap">
                    <div class="cover-cert-icon"><i class="fa-solid fa-certificate"></i></div>
                    <div>
                        <div class="cover-qr-label">Certificate Key — verify at verify_certificate.php</div>
                        <div class="cover-qr-code"><?php echo htmlspecialchars($certificateKey); ?></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Score Circles -->
        <div class="glass-card p-4 p-md-5 mb-4" data-aos="fade-up">
            <h3 style="font-weight:700;color:var(--text-bright);margin-bottom:28px;">
                <i class="fa-solid fa-chart-column me-2" style="color:var(--accent);"></i>Review Results
            </h3>
            <div class="row g-3 justify-content-center">
                <div class="col-6 col-md-4">
                    <div class="score-circle-wrap">
                        <div class="score-circle">
                            <svg viewBox="0 0 120 120">
                                <circle class="bg-ring" cx="60" cy="60" r="54"/>
                                <circle class="score-ring-progress ring-ai" cx="60" cy="60" r="54" data-percent="<?php echo $aiScore; ?>"/>
                            </svg>
                            <div class="score-value" style="color:<?php echo $aiColor; ?>;"><?php echo $aiScore; ?>%<small>AI Score</small></div>
                        </div>
                        <div class="score-label">AI Content Detected</div>
                        <div class="score-sublabel" style="color:<?php echo $aiColor; ?>;"><?php echo $aiLabel; ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="score-circle-wrap">
                        <div class="score-circle">
                            <svg viewBox="0 0 120 120">
                                <circle class="bg-ring" cx="60" cy="60" r="54"/>
                                <circle class="score-ring-progress ring-sim" cx="60" cy="60" r="54" data-percent="<?php echo $simScore; ?>"/>
                            </svg>
                            <div class="score-value" style="color:<?php echo $simColor; ?>;"><?php echo $simScore; ?>%<small>Similarity</small></div>
                        </div>
                        <div class="score-label">Plagiarism Check</div>
                        <div class="score-sublabel" style="color:<?php echo $simColor; ?>;"><?php echo $simLabel; ?></div>
                    </div>
                </div>
                <div class="col-6 col-md-4">
                    <div class="score-circle-wrap">
                        <div class="score-circle">
                            <svg viewBox="0 0 120 120">
                                <circle class="bg-ring" cx="60" cy="60" r="54"/>
                                <circle class="score-ring-progress ring-marks" cx="60" cy="60" r="54" data-percent="<?php echo $marks; ?>"/>
                            </svg>
                            <div class="score-value" style="color:#4ade80;"><?php echo $marks; ?><small>/ 100</small></div>
                        </div>
                        <div class="score-label">Your Marks</div>
                        <div class="score-sublabel"><?php echo $marks >= 70 ? 'Excellent' : ($marks >= 50 ? 'Good' : 'Needs Work'); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Reviewer Feedback -->
        <div class="glass-card p-4 p-md-5 mb-4" data-aos="fade-up" data-aos-delay="100">
            <div class="d-flex align-items-center gap-3 mb-4">
                <div style="width:48px;height:48px;min-width:48px;background:linear-gradient(135deg,#22c55e,#4ade80);border-radius:14px;display:flex;align-items:center;justify-content:center;color:white;font-size:1.2rem;box-shadow:0 4px 16px rgba(34,197,94,0.3);">
                    <i class="fa-solid fa-pen-fancy"></i>
                </div>
                <div>
                    <h4 style="font-weight:700;color:var(--text-bright);margin:0;font-size:1.15rem;">Reviewer Feedback</h4>
                    <span style="font-size:0.8rem;color:var(--text-muted);">
                        Reviewed at <?php echo $assignment['reviewed_at'] ? date('M d, Y h:i A', strtotime($assignment['reviewed_at'])) : '—'; ?>
                    </span>
                </div>
            </div>
            <?php if ($assignment['review_comment']): ?>
                <div class="report-block"><?php echo htmlspecialchars($assignment['review_comment']); ?></div>
            <?php else: ?>
                <p style="color:var(--text-muted);font-size:0.9rem;font-style:italic;">No additional comments from the reviewer.</p>
            <?php endif; ?>
        </div>

        <!-- Certificate Key Verification Card -->
        <?php if ($certificateKey): ?>
        <div class="glass-card p-4 p-md-5 mb-4" data-aos="fade-up" data-aos-delay="130">
            <div class="qr-verify-card">
                <div class="qr-img-box">
                    <i class="fa-solid fa-certificate" style="font-size:2.4rem;color:var(--primary-mid);"></i>
                </div>
                <div class="qr-info">
                    <h4><i class="fa-solid fa-shield-halved me-2" style="color:var(--accent);"></i>Certificate Verification</h4>
                    <p>This review has an official Certificate ID. Anyone can confirm it's genuine on the Verify Certificate page — no login required.</p>
                    <div class="vcode-inline">
                        <span><?php echo htmlspecialchars($certificateKey); ?></span>
                        <button class="vcode-copy" onclick="copyVCode()" title="Copy code">
                            <i class="fa-regular fa-copy"></i>
                        </button>
                    </div>
                    <a href="<?php echo htmlspecialchars($verifyCertificateUrl); ?>" target="_blank" class="qr-link">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                        Verify this Certificate ID
                    </a>
                    <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;">
                        <a href="<?php echo htmlspecialchars($verifyCertificateUrl); ?>" target="_blank" class="dv-action-btn primary-action" style="display:inline-flex;text-decoration:none;">
                            <i class="fa-solid fa-magnifying-glass"></i> Verify Certificate
                        </a>
                        <?php if ($certificateFileUrl): ?>
                        <a href="<?php echo htmlspecialchars($certificateFileUrl); ?>" target="_blank" class="dv-action-btn" style="display:inline-flex;text-decoration:none;">
                            <i class="fa-solid fa-download"></i> Download Certificate
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── Document Viewer ── -->
        <?php if ($displayFile): ?>
        <div class="glass-card p-4 p-md-5 mb-4 doc-viewer-section" data-aos="fade-up" data-aos-delay="150">
            <div class="doc-viewer-header">
                <div class="dv-title">
                    <div class="dv-title-icon">
                        <i class="fa-solid <?php echo $displayIsPdf ? 'fa-file-pdf' : 'fa-file-lines'; ?>"></i>
                    </div>
                    <div>
                        <h4><?php echo htmlspecialchars($fileSourceLabel); ?></h4>
                        <span>
                            <?php echo htmlspecialchars(basename($displayFile)); ?>
                            <?php if ($displayIsPdf && $displayPages > 0): ?>
                                &middot; <?php echo $displayPages; ?> page<?php echo $displayPages !== 1 ? 's' : ''; ?>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
                <div class="doc-viewer-actions">
                    <a href="<?php echo htmlspecialchars($displayPath); ?>" download class="dv-action-btn">
                        <i class="fa-solid fa-download"></i> Download
                    </a>
                    <?php if ($payment): ?>
                    <button onclick="openReceiptModal()" class="dv-action-btn">
                        <i class="fa-solid fa-receipt"></i> Receipt
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!$fileExistsOnDisk): ?>
            <!-- File not found -->
            <div class="file-warning">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    <strong>File not found on server</strong>
                    <p style="margin:4px 0 0;font-size:0.82rem;">The document file could not be located. Please contact support.</p>
                    <code><?php echo htmlspecialchars($displayPath); ?></code>
                </div>
            </div>

            <?php elseif ($displayLocked): ?>
            <!-- Free plan — page limit lock -->
            <div class="page-lock-overlay">
                <div class="plo-icon">
                    <i class="fa-solid fa-lock"></i>
                </div>
                <h3>Upgrade to View Full Document</h3>
                <div class="plo-pages-info">
                    <i class="fa-solid fa-file"></i>
                    <?php echo $displayPages; ?> pages — Free plan limited to <?php echo $FREE_PAGE_LIMIT; ?>
                </div>
                <p>This document has <?php echo $displayPages; ?> pages but your free plan only allows preview of the first <?php echo $FREE_PAGE_LIMIT; ?> pages. Subscribe to unlock the complete document.</p>
                <a href="plan.php" class="go-to-plans-btn">
                    <i class="fa-solid fa-crown"></i> View Plans
                </a>
            </div>

            <?php elseif ($displayIsPdf): ?>
            <!-- PDF Viewer -->
            <div class="pdf-overlay-wrap">
                <iframe src="<?php echo htmlspecialchars($displayPath); ?>" title="Document Viewer"></iframe>
                <?php if ($stampImageUrl): ?>
                <div class="stamp-overlay">
                    <img src="<?php echo htmlspecialchars($stampImageUrl); ?>" alt="Verified Stamp">
                </div>
                <?php endif; ?>
                <?php if ($certificateKey): ?>
                <div class="qr-overlay cert-key-overlay">
                    <div class="cert-key-box"><?php echo htmlspecialchars($certificateKey); ?></div>
                    <div class="qr-label">Certificate Key</div>
                </div>
                <?php endif; ?>
            </div>

            <?php else: ?>
            <!-- Non-PDF file -->
            <div style="text-align:center;padding:48px 20px;">
                <i class="fa-solid fa-file-arrow-down" style="font-size:3rem;color:var(--accent);margin-bottom:16px;display:block;"></i>
                <p style="color:var(--text-body);margin-bottom:20px;font-size:0.95rem;">This file type cannot be previewed inline. Please download it to view.</p>
                <a href="<?php echo htmlspecialchars($displayPath); ?>" download class="dv-action-btn primary-action" style="display:inline-flex;">
                    <i class="fa-solid fa-download"></i> Download File
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php endif; /* end: reviewed & paid */ ?>

    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {

    // ── Score Ring Animation ──
    document.querySelectorAll('.score-ring-progress').forEach(function(ring) {
        var percent = parseFloat(ring.getAttribute('data-percent')) || 0;
        var radius = 54;
        var circumference = 2 * Math.PI * radius;
        ring.style.strokeDasharray = circumference;
        ring.style.strokeDashoffset = circumference;

        setTimeout(function() {
            var offset = circumference - (percent / 100) * circumference;
            ring.style.strokeDashoffset = offset;
        }, 400);
    });

    // ── Auto-show payment notification if needed ──
    <?php if ($needsPayment && $isReviewed): ?>
    setTimeout(function() {
        var overlay = document.getElementById('payNotifOverlay');
        if (overlay && !overlay.classList.contains('active')) {
            overlay.classList.add('active');
        }
    }, 600);
    <?php endif; ?>

});

// ── Copy Certificate Key ──
function copyVCode() {
    var code = '<?php echo addslashes($certificateKey); ?>';
    if (!code) return;
    navigator.clipboard.writeText(code).then(function() {
        showToast('<i class="fa-solid fa-check me-2" style="color:#4ade80;"></i> Certificate key copied!');
    }).catch(function() {
        // Fallback
        var ta = document.createElement('textarea');
        ta.value = code;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        showToast('<i class="fa-solid fa-check me-2" style="color:#4ade80;"></i> Certificate key copied!');
    });
}

// ── Toast Notification ──
function showToast(html) {
    var toast = document.getElementById('toastMsg');
    if (!toast) return;
    toast.innerHTML = html;
    toast.classList.add('show');
    setTimeout(function() {
        toast.classList.remove('show');
    }, 2800);
}

// ── Payment Notification Modal ──
function closePayNotif() {
    window.location.href = 'assignments.php';
}

// Close on overlay click (but not box click)
document.addEventListener('click', function(e) {
    var overlay = document.getElementById('payNotifOverlay');
    if (overlay && e.target === overlay) {
        closePayNotif();
    }
});

// ── Receipt Modal ──
function openReceiptModal() {
    var modal = document.getElementById('receiptModal');
    if (modal) modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeReceiptModal() {
    var modal = document.getElementById('receiptModal');
    if (modal) modal.classList.remove('active');
    document.body.style.overflow = '';
}

// Close receipt on overlay click
document.addEventListener('click', function(e) {
    var modal = document.getElementById('receiptModal');
    if (modal && e.target === modal) {
        closeReceiptModal();
    }
});

// Close modals on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeReceiptModal();
        var payOverlay = document.getElementById('payNotifOverlay');
        if (payOverlay && payOverlay.classList.contains('active')) {
            closePayNotif();
        }
    }
});

// ── Print Receipt ──
function printReceipt() {
    var modalBox = document.querySelector('.receipt-modal-box');
    if (!modalBox) return;
    var printWin = window.open('', '_blank', 'width=600,height=800');
    printWin.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Receipt - AI Assignment Checker</title>
            <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body { font-family: 'Poppins', sans-serif; padding: 40px 30px; color: #1a1a2e; }
                .receipt-brand { text-align: center; padding-bottom: 20px; border-bottom: 2px dashed #ddd; margin-bottom: 20px; }
                .receipt-brand h2 { font-size: 1.2rem; margin-top: 10px; }
                .receipt-brand span { font-size: 0.78rem; color: #666; }
                .receipt-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
                .receipt-table tr { border-bottom: 1px solid #eee; }
                .receipt-table td { padding: 8px 0; font-size: 0.85rem; }
                .receipt-table td:first-child { color: #666; width: 40%; }
                .receipt-table td:last-child { text-align: right; font-weight: 600; }
                .receipt-amounts { border: 1px solid #eee; border-radius: 8px; padding: 16px 20px; margin-bottom: 20px; }
                .receipt-amounts .ra-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 0.85rem; }
                .receipt-amounts .ra-divider { height: 1px; background: #eee; margin: 6px 0; }
                .receipt-amounts .ra-row.total .ra-label { font-weight: 700; }
                .receipt-amounts .ra-row.total .ra-value { font-family: 'Space Grotesk', sans-serif; font-size: 1.3rem; font-weight: 800; color: #7B3F91; }
                .receipt-footer { text-align: center; padding-top: 16px; border-top: 2px dashed #ddd; }
                .receipt-footer p { font-size: 0.75rem; color: #999; }
                .status-paid { display: inline-block; padding: 4px 14px; border-radius: 50px; background: #dcfce7; color: #16a34a; font-size: 0.75rem; font-weight: 700; }
                @media print { body { padding: 20px; } }
            </style>
        </head>
        <body>
            ${modalBox.querySelector('.receipt-body').innerHTML}
            <script>window.onload = function() { window.print(); }<\/script>
        </body>
        </html>
    `);
    printWin.document.close();
}
</script>

<?php require_once 'footer.php'; ?>