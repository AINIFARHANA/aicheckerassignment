<?php
// ── verification.php ──
// Public page — no login required
// Lecturers scan QR code → this page opens with full verification details

 $pageTitle = 'Verification - AI Checker';

// Only load header if you want site nav. For a clean verification document,
// we build a standalone layout. Comment out the next line if you prefer no header.
// require_once 'header.php';

// ── DB Connection (adjust path if needed) ──
require_once 'config.php'; // or wherever your $conn is defined
// If $conn isn't available after requiring config, uncomment:
// $conn = new mysqli('localhost', 'root', '', 'your_db_name');

 $verificationCode = isset($_GET['code']) ? trim($_GET['code']) : '';

// ── Fetch verification data ──
 $verification = null;
 $isValid = false;

if ($verificationCode !== '') {
    $stmt = $conn->prepare("
        SELECT ar.review_id, ar.assignment_id, ar.admin_id, ar.marks,
               ar.comment AS review_comment,
               ar.reviewed_file, ar.reviewed_at,
               ar.ai_score, ar.similarity,
               ar.verification_code,
               a.title, a.subject, a.submission_date,
               a.upload_file, a.user_id,
               u.name AS student_name, u.email AS student_email, u.matrix_no,
               rv.name AS reviewer_name
        FROM assignment_reviews ar
        JOIN assignments a ON ar.assignment_id = a.assignment_id
        LEFT JOIN users u ON a.user_id = u.user_id
        LEFT JOIN users rv ON ar.admin_id = rv.user_id
        WHERE ar.verification_code = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("s", $verificationCode);
        $stmt->execute();
        $result = $stmt->get_result();
        $verification = $result->fetch_assoc();
        $stmt->close();
    }
    $isValid = ($verification !== null);
}

// ── Derived values ──
if ($isValid) {
    $aiScore  = $verification['ai_score'] !== null ? round((float)$verification['ai_score'], 1) : null;
    $simScore = $verification['similarity'] !== null ? round((float)$verification['similarity'], 1) : null;
    $marks    = $verification['marks'] !== null ? (int)$verification['marks'] : null;

    if ($aiScore !== null) {
        if ($aiScore <= 20) { $aiColor = '#16a34a'; $aiLabel = 'Low AI Content'; }
        elseif ($aiScore <= 50) { $aiColor = '#d97706'; $aiLabel = 'Moderate AI Content'; }
        else { $aiColor = '#dc2626'; $aiLabel = 'High AI Content'; }
    }
    if ($simScore !== null) {
        if ($simScore <= 15) { $simColor = '#16a34a'; $simLabel = 'Original Work'; }
        elseif ($simScore <= 35) { $simColor = '#d97706'; $simLabel = 'Some Matches Found'; }
        else { $simColor = '#dc2626'; $simLabel = 'High Similarity'; }
    }
    if ($marks !== null) {
        if ($marks >= 70) $marksLabel = 'Excellent';
        elseif ($marks >= 50) $marksLabel = 'Good';
        else $marksLabel = 'Needs Improvement';
    }

    // QR code pointing to this exact page
    $baseDomain = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    $thisPageUrl = $baseDomain . '/verification.php?code=' . urlencode($verificationCode);
    $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($thisPageUrl) . '&margin=10';
}

// ── Logo paths (use the real "image/" folder shipped with the app) ──
 $localLogo  = 'image/logo.png';
 $logoUrl    = file_exists($localLogo) ? $localLogo : 'image/logo1.png';
 $localStamp = 'image/stamp.png';
// We'll use a CSS stamp instead for reliability
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isValid ? 'Verified Assignment - AI Checker' : 'Invalid Verification - AI Checker'; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        /* ── Reset & Base ── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --brand-primary: #7B3F91;
            --brand-mid: #9B59B6;
            --brand-light: #D88FFF;
            --brand-accent: #FF8EC4;
            --dark-bg: #0D0612;
            --dark-card: #150A1E;
            --text-bright: #F0E6F6;
            --text-body: #B8A0C8;
            --text-muted: #7A6588;
            --border-subtle: rgba(244,209,255,0.08);
            --border-glow: rgba(216,143,255,0.25);
            --green: #16a34a;
            --green-bg: rgba(22,163,74,0.08);
            --green-border: rgba(22,163,74,0.2);
            --amber: #d97706;
            --red: #dc2626;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--dark-bg);
            color: var(--text-body);
            min-height: 100vh;
            line-height: 1.6;
        }

        /* ── Top Bar ── */
        .top-bar {
            background: var(--dark-card);
            border-bottom: 1px solid var(--border-subtle);
            padding: 12px 0;
            position: sticky; top: 0; z-index: 100;
            backdrop-filter: blur(16px);
        }
        .top-bar .inner {
            max-width: 1100px; margin: 0 auto; padding: 0 24px;
            display: flex; align-items: center; justify-content: space-between;
        }
        .top-bar .brand {
            display: flex; align-items: center; gap: 10px;
            text-decoration: none;
        }
        .top-bar .brand img {
            height: 32px; width: auto;
        }
        .top-bar .brand-text {
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 700; font-size: 1rem;
            color: var(--text-bright);
        }
        .top-bar .brand-sub {
            font-size: 0.7rem; color: var(--text-muted);
            font-weight: 500; letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .top-bar .back-link {
            color: var(--text-muted); text-decoration: none;
            font-size: 0.82rem; font-weight: 500;
            transition: color 0.2s;
        }
        .top-bar .back-link:hover { color: var(--brand-light); }

        /* ── Main Container ── */
        .verify-container {
            max-width: 900px; margin: 0 auto;
            padding: 40px 24px 80px;
        }

        /* ── Invalid State ── */
        .invalid-card {
            text-align: center; padding: 80px 30px;
            background: var(--dark-card);
            border: 1px solid var(--border-subtle);
            border-radius: 20px;
            margin-top: 20px;
        }
        .invalid-card .inv-icon {
            width: 100px; height: 100px;
            background: rgba(220, 38, 38, 0.08);
            border: 2px solid rgba(220, 38, 38, 0.15);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 28px; font-size: 2.5rem; color: var(--red);
        }
        .invalid-card h2 {
            font-weight: 800; color: var(--text-bright);
            font-size: 1.6rem; margin-bottom: 12px;
        }
        .invalid-card p {
            color: var(--text-muted); font-size: 0.95rem;
            max-width: 400px; margin: 0 auto;
        }
        .invalid-card .inv-code {
            font-family: 'Space Grotesk', monospace;
            font-size: 0.85rem; color: var(--text-muted);
            background: rgba(0,0,0,0.3); padding: 6px 16px;
            border-radius: 8px; display: inline-block; margin-top: 20px;
            opacity: 0.6;
        }

        /* ── Verification Document ── */
        .verify-doc {
            background: var(--dark-card);
            border: 1px solid var(--border-subtle);
            border-radius: 20px;
            overflow: hidden;
            position: relative;
        }

        /* ── Doc Header ── */
        .doc-header {
            background: linear-gradient(135deg, rgba(123,63,145,0.15), rgba(216,143,255,0.08));
            border-bottom: 1px solid var(--border-glow);
            padding: 40px 48px 32px;
            position: relative;
            overflow: hidden;
        }
        .doc-header::before {
            content: '';
            position: absolute; top: -60px; right: -60px;
            width: 250px; height: 250px;
            background: radial-gradient(circle, rgba(216,143,255,0.08), transparent 70%);
            pointer-events: none;
        }
        .doc-header .dh-top {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 28px; flex-wrap: wrap; gap: 16px;
        }
        .doc-header .dh-brand {
            display: flex; align-items: center; gap: 14px;
        }
        .doc-header .dh-brand img {
            height: 52px; width: auto;
            filter: drop-shadow(0 2px 8px rgba(123,63,145,0.3));
        }
        .doc-header .dh-company {
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 800; font-size: 1.4rem;
            color: var(--text-bright);
            letter-spacing: -0.01em;
        }
        .doc-header .dh-company small {
            display: block; font-size: 0.72rem; font-weight: 500;
            color: var(--text-muted); letter-spacing: 0.08em;
            text-transform: uppercase; margin-top: 2px;
        }

        /* VERIFIED Stamp */
        .verified-stamp {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 28px;
            border: 3px solid var(--green);
            border-radius: 50px;
            color: var(--green);
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 800; font-size: 1.1rem;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            position: relative;
            animation: stampIn 0.5s ease-out both;
            animation-delay: 0.3s;
        }
        @keyframes stampIn {
            from { opacity: 0; transform: scale(1.15) rotate(-2deg); }
            to { opacity: 1; transform: scale(1) rotate(0deg); }
        }
        .verified-stamp i { font-size: 1.2rem; }
        .verified-stamp::after {
            content: '';
            position: absolute; inset: -1px;
            border-radius: 50px;
            box-shadow: 0 0 20px rgba(22,163,74,0.15);
            pointer-events: none;
        }

        .doc-header h1 {
            font-weight: 800; color: var(--text-bright);
            font-size: 1.8rem; letter-spacing: -0.02em;
        }
        .doc-header .dh-sub {
            color: var(--text-muted); font-size: 0.9rem; margin-top: 4px;
        }

        /* ── Doc Body ── */
        .doc-body { padding: 36px 48px 48px; }

        /* ── Verification Code Bar ── */
        .vcode-bar {
            display: flex; align-items: center; justify-content: center;
            gap: 24px; flex-wrap: wrap;
            background: rgba(244,209,255,0.04);
            border: 1px solid var(--border-subtle);
            border-radius: 14px;
            padding: 24px 32px;
            margin-bottom: 32px;
        }
        .vcode-bar .vcb-code {
            font-family: 'Space Grotesk', monospace;
            font-size: 1.3rem; font-weight: 700;
            color: var(--brand-light);
            letter-spacing: 0.14em;
        }
        .vcode-bar .vcb-divider {
            width: 1px; height: 40px;
            background: var(--border-subtle);
        }
        .vcode-bar .vcb-qr {
            width: 70px; height: 70px;
            border-radius: 10px;
            border: 2px solid var(--border-glow);
            background: white; padding: 4px;
        }
        .vcode-bar .vcb-qr img {
            width: 100%; height: 100%; border-radius: 6px;
        }
        .vcode-bar .vcb-label {
            font-size: 0.72rem; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 0.08em;
            font-weight: 600;
        }

        /* ── Section ── */
        .v-section {
            margin-bottom: 28px;
        }
        .v-section:last-child { margin-bottom: 0; }
        .v-section-title {
            font-size: 0.72rem; font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.12em;
            margin-bottom: 14px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border-subtle);
            display: flex; align-items: center; gap: 8px;
        }
        .v-section-title i {
            color: var(--brand-light); font-size: 0.8rem;
        }

        /* ── Detail Grid ── */
        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
        }
        .detail-item {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border-subtle);
        }
        .detail-item:nth-child(odd) {
            border-right: 1px solid var(--border-subtle);
        }
        .detail-item .di-label {
            font-size: 0.7rem; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 0.08em;
            font-weight: 600; margin-bottom: 4px;
        }
        .detail-item .di-value {
            font-size: 0.95rem; color: var(--text-bright);
            font-weight: 600;
        }

        /* ── Score Cards ── */
        .score-cards {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px;
        }
        .score-card {
            background: rgba(244,209,255,0.03);
            border: 1px solid var(--border-subtle);
            border-radius: 14px;
            padding: 24px 16px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        .score-card::before {
            content: '';
            position: absolute; top: 0; left: 0; right: 0;
            height: 3px;
        }
        .score-card.sc-ai::before { background: linear-gradient(90deg, var(--brand-light), var(--brand-accent)); }
        .score-card.sc-sim::before { background: linear-gradient(90deg, #3B1347, var(--brand-light)); }
        .score-card.sc-marks::before { background: linear-gradient(90deg, var(--green), #4ade80); }

        .score-card .sc-value {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 2.2rem; font-weight: 700;
            line-height: 1; margin-bottom: 6px;
        }
        .score-card .sc-label {
            font-size: 0.78rem; color: var(--text-muted);
            font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.06em;
        }
        .score-card .sc-sub {
            font-size: 0.75rem; font-weight: 600;
            margin-top: 4px;
        }

        /* ── Comment Block ── */
        .comment-block {
            background: rgba(244,209,255,0.03);
            border: 1px solid var(--border-subtle);
            border-radius: 12px;
            padding: 20px 24px;
            font-size: 0.92rem; line-height: 1.8;
            color: var(--text-body);
            white-space: pre-wrap; word-break: break-word;
        }
        .no-comment {
            color: var(--text-muted); font-style: italic; font-size: 0.9rem;
        }

        /* ── QR Section (larger, below content) ── */
        .qr-section {
            display: flex; align-items: center; gap: 32px;
            flex-wrap: wrap;
            margin-top: 32px;
            padding-top: 28px;
            border-top: 1px solid var(--border-subtle);
        }
        .qr-section .qs-img {
            width: 140px; height: 140px; min-width: 140px;
            border-radius: 16px;
            border: 3px solid var(--border-glow);
            background: white; padding: 8px;
            box-shadow: 0 4px 20px rgba(123,63,145,0.12);
        }
        .qr-section .qs-img img {
            width: 100%; height: 100%; border-radius: 10px;
        }
        .qr-section .qs-info h4 {
            font-weight: 700; color: var(--text-bright);
            font-size: 1rem; margin-bottom: 6px;
        }
        .qr-section .qs-info p {
            font-size: 0.85rem; color: var(--text-muted);
            line-height: 1.7; margin-bottom: 10px;
        }
        .qr-section .qs-link {
            font-size: 0.75rem; color: var(--brand-light);
            word-break: break-all; line-height: 1.6;
        }

        /* ── Action Buttons ── */
        .action-bar {
            display: flex; gap: 12px; justify-content: center;
            margin-top: 36px; flex-wrap: wrap;
        }
        .action-btn {
            display: inline-flex; align-items: center; gap: 10px;
            padding: 14px 32px; border-radius: 14px;
            font-weight: 700; font-size: 0.92rem;
            text-decoration: none; cursor: pointer;
            transition: all 0.3s ease; border: none;
        }
        .action-btn.print-btn {
            background: rgba(244,209,255,0.08);
            border: 1px solid var(--border-glow);
            color: var(--text-bright);
        }
        .action-btn.print-btn:hover {
            background: rgba(244,209,255,0.15);
            transform: translateY(-2px);
            box-shadow: 0 6px 24px rgba(123,63,145,0.2);
        }
        .action-btn.download-btn {
            background: var(--brand-primary);
            color: white;
        }
        .action-btn.download-btn:hover {
            background: var(--brand-mid);
            transform: translateY(-2px);
            box-shadow: 0 6px 24px rgba(123,63,145,0.4);
        }
        .action-btn:disabled {
            opacity: 0.6; cursor: not-allowed;
            transform: none !important; box-shadow: none !important;
        }

        /* ── Footer ── */
        .doc-footer {
            border-top: 1px solid var(--border-subtle);
            padding: 24px 48px;
            display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: 12px;
        }
        .doc-footer .df-left {
            font-size: 0.78rem; color: var(--text-muted);
        }
        .doc-footer .df-left strong { color: var(--text-body); }
        .doc-footer .df-right {
            font-size: 0.72rem; color: var(--text-muted);
            opacity: 0.6;
        }

        /* ── Toast ── */
        .toast-msg {
            position: fixed; bottom: 30px; left: 50%;
            transform: translateX(-50%) translateY(20px);
            background: var(--dark-card);
            backdrop-filter: blur(16px);
            border: 1px solid var(--border-glow);
            border-radius: 14px;
            padding: 14px 28px;
            color: var(--text-bright);
            font-size: 0.88rem; font-weight: 600;
            z-index: 9999; opacity: 0;
            transition: all 0.4s ease;
            pointer-events: none;
            box-shadow: 0 8px 32px rgba(0,0,0,0.4);
        }
        .toast-msg.show {
            opacity: 1; transform: translateX(-50%) translateY(0);
        }

        /* ── PRINT STYLES ── */
        @media print {
            body { background: white !important; color: #1a1a1a !important; }
            .top-bar, .action-bar, .toast-msg { display: none !important; }
            .verify-container { padding: 0 !important; max-width: 100% !important; }

            .verify-doc {
                border: 2px solid #ddd !important;
                border-radius: 0 !important;
                box-shadow: none !important;
                background: white !important;
            }

            .doc-header {
                background: #f8f5fa !important;
                border-bottom: 2px solid #ddd !important;
                padding: 30px 36px 24px !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .doc-header::before { display: none; }
            .doc-header .dh-company { color: #1a1a1a !important; }
            .doc-header .dh-company small { color: #666 !important; }
            .doc-header h1 { color: #1a1a1a !important; }
            .doc-header .dh-sub { color: #666 !important; }

            .verified-stamp {
                color: var(--green) !important;
                border-color: var(--green) !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .doc-body { padding: 28px 36px 36px !important; }

            .vcode-bar {
                background: #f5f5f5 !important;
                border-color: #ddd !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .vcode-bar .vcb-code { color: var(--brand-primary) !important; }
            .vcode-bar .vcb-label { color: #888 !important; }
            .vcode-bar .vcb-divider { background: #ddd !important; }

            .v-section-title { color: #666 !important; border-bottom-color: #ddd !important; }
            .v-section-title i { color: var(--brand-primary) !important; }

            .detail-item {
                border-bottom-color: #eee !important;
                border-right-color: #eee !important;
            }
            .detail-item .di-label { color: #888 !important; }
            .detail-item .di-value { color: #1a1a1a !important; }

            .score-card {
                background: #f9f9f9 !important;
                border-color: #ddd !important;
            }
            .score-card .sc-value { color: #1a1a1a !important; }
            .score-card .sc-label { color: #666 !important; }
            .score-card::before {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .comment-block {
                background: #f9f9f9 !important;
                border-color: #ddd !important;
                color: #333 !important;
            }

            .qr-section { border-top-color: #ddd !important; }
            .qr-section .qs-info h4 { color: #1a1a1a !important; }
            .qr-section .qs-info p { color: #666 !important; }
            .qr-section .qs-link { color: var(--brand-primary) !important; }

            .doc-footer {
                border-top-color: #ddd !important;
                padding: 16px 36px !important;
            }
            .doc-footer .df-left { color: #666 !important; }
            .doc-footer .df-left strong { color: #333 !important; }
            .doc-footer .df-right { color: #999 !important; }
        }

        /* ── Responsive ── */
        @media (max-width: 768px) {
            .doc-header { padding: 28px 24px 24px; }
            .doc-header h1 { font-size: 1.4rem; }
            .doc-header .dh-brand img { height: 40px; }
            .doc-header .dh-company { font-size: 1.1rem; }
            .verified-stamp { font-size: 0.85rem; padding: 8px 18px; }
            .doc-body { padding: 24px; }
            .detail-grid { grid-template-columns: 1fr; }
            .detail-item:nth-child(odd) { border-right: none; }
            .score-cards { grid-template-columns: 1fr; gap: 12px; }
            .score-card .sc-value { font-size: 1.8rem; }
            .vcode-bar { flex-direction: column; text-align: center; gap: 16px; }
            .vcode-bar .vcb-divider { width: 60px; height: 1px; }
            .qr-section { flex-direction: column; text-align: center; }
            .qr-section .qs-img { width: 120px; height: 120px; min-width: 120px; }
            .doc-footer { padding: 20px 24px; flex-direction: column; text-align: center; }
            .action-bar { flex-direction: column; align-items: stretch; }
            .action-btn { justify-content: center; }
        }
    </style>
</head>
<body>

    <!-- Top Bar (hidden in print) -->
    <div class="top-bar">
        <div class="inner">
            <a href="/" class="brand">
                <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="AI Checker Logo" onerror="this.style.display='none'">
                <div>
                    <div class="brand-text">AI Checker</div>
                    <div class="brand-sub">Assignment Verification</div>
                </div>
            </a>
            <a href="javascript:history.back()" class="back-link">
                <i class="fa-solid fa-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>

    <div class="verify-container">

        <?php if (!$isValid): ?>
        <!-- ── INVALID / NO CODE ── -->
        <div class="invalid-card">
            <div class="inv-icon">
                <i class="fa-solid fa-circle-xmark"></i>
            </div>
            <h2><?php echo $verificationCode === '' ? 'No Verification Code' : 'Invalid Verification Code'; ?></h2>
            <p>
                <?php if ($verificationCode === ''): ?>
                    Please scan the QR code or use the verification link provided with the reviewed assignment.
                <?php else: ?>
                    The verification code you entered does not match any record in our system. Please check the code and try again.
                <?php endif; ?>
            </p>
            <?php if ($verificationCode !== ''): ?>
            <div class="inv-code">
                <i class="fa-solid fa-barcode me-2"></i><?php echo htmlspecialchars($verificationCode); ?>
            </div>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <!-- ── VERIFIED DOCUMENT ── -->
        <div class="verify-doc" id="verifyDoc">

            <!-- Header -->
            <div class="doc-header">
                <div class="dh-top">
                    <div class="dh-brand">
                        <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="AI Checker" onerror="this.style.display='none'">
                        <div>
                            <div class="dh-company">
                                AI Checker
                                <small>Assignment Verification System</small>
                            </div>
                        </div>
                    </div>
                    <div class="verified-stamp">
                        <i class="fa-solid fa-circle-check"></i> Verified
                    </div>
                </div>
                <h1>Assignment Verification Report</h1>
                <p class="dh-sub">
                    This document confirms that the assignment below has been reviewed and verified by AI Checker.
                </p>
            </div>

            <!-- Body -->
            <div class="doc-body">

                <!-- Verification Code Bar -->
                <div class="vcode-bar">
                    <div>
                        <div class="vcb-label">Verification Code</div>
                        <div class="vcb-code"><?php echo htmlspecialchars($verification['verification_code']); ?></div>
                    </div>
                    <div class="vcb-divider"></div>
                    <div>
                        <div class="vcb-label">Quick Scan</div>
                        <div class="vcb-qr">
                            <img src="<?php echo htmlspecialchars($qrCodeUrl); ?>" alt="QR Code">
                        </div>
                    </div>
                </div>

                <!-- Assignment Details -->
                <div class="v-section">
                    <div class="v-section-title">
                        <i class="fa-solid fa-file-lines"></i> Assignment Details
                    </div>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <div class="di-label">Assignment Title</div>
                            <div class="di-value"><?php echo htmlspecialchars($verification['title']); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="di-label">Subject</div>
                            <div class="di-value"><?php echo htmlspecialchars($verification['subject']); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="di-label">Student Name</div>
                            <div class="di-value"><?php echo $verification['student_name'] ? htmlspecialchars($verification['student_name']) : '—'; ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="di-label">Student Email</div>
                            <div class="di-value"><?php echo $verification['student_email'] ? htmlspecialchars($verification['student_email']) : '—'; ?></div>
                        </div>
                        <?php if (!empty($verification['matrix_no'])): ?>
                        <div class="detail-item">
                            <div class="di-label">Matrix No.</div>
                            <div class="di-value"><?php echo htmlspecialchars($verification['matrix_no']); ?></div>
                        </div>
                        <?php endif; ?>
                        <div class="detail-item">
                            <div class="di-label">Submitted On</div>
                            <div class="di-value"><?php echo date('M d, Y h:i A', strtotime($verification['submission_date'])); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="di-label">Reviewed On</div>
                            <div class="di-value"><?php echo $verification['reviewed_at'] ? date('M d, Y h:i A', strtotime($verification['reviewed_at'])) : '—'; ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="di-label">Reviewed By</div>
                            <div class="di-value"><?php echo $verification['reviewer_name'] ? htmlspecialchars($verification['reviewer_name']) : 'AI Checker System'; ?></div>
                        </div>
                    </div>
                </div>

                <!-- Review Results -->
                <div class="v-section">
                    <div class="v-section-title">
                        <i class="fa-solid fa-chart-column"></i> Review Results
                    </div>
                    <div class="score-cards">
                        <?php if ($aiScore !== null): ?>
                        <div class="score-card sc-ai">
                            <div class="sc-value" style="color:<?php echo $aiColor; ?>;"><?php echo $aiScore; ?>%</div>
                            <div class="sc-label">AI Content</div>
                            <div class="sc-sub" style="color:<?php echo $aiColor; ?>;"><?php echo $aiLabel; ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if ($simScore !== null): ?>
                        <div class="score-card sc-sim">
                            <div class="sc-value" style="color:<?php echo $simColor; ?>;"><?php echo $simScore; ?>%</div>
                            <div class="sc-label">Similarity</div>
                            <div class="sc-sub" style="color:<?php echo $simColor; ?>;"><?php echo $simLabel; ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if ($marks !== null): ?>
                        <div class="score-card sc-marks">
                            <div class="sc-value" style="color:#16a34a;"><?php echo $marks; ?>/<small style="font-size:0.6em;opacity:0.6;">100</small></div>
                            <div class="sc-label">Marks</div>
                            <div class="sc-sub" style="color:#16a34a;"><?php echo $marksLabel; ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Reviewer Comments -->
                <div class="v-section">
                    <div class="v-section-title">
                        <i class="fa-solid fa-comment-dots"></i> Reviewer Comments
                    </div>
                    <?php if ($verification['review_comment']): ?>
                        <div class="comment-block"><?php echo htmlspecialchars($verification['review_comment']); ?></div>
                    <?php else: ?>
                        <p class="no-comment">No additional comments provided by the reviewer.</p>
                    <?php endif; ?>
                </div>

                <!-- QR Code Section -->
                <div class="qr-section">
                    <div class="qs-img">
                        <img src="<?php echo htmlspecialchars($qrCodeUrl); ?>" alt="Verification QR Code">
                    </div>
                    <div class="qs-info">
                        <h4><i class="fa-solid fa-qrcode me-2" style="color:var(--brand-light);"></i>Scan to Re-verify</h4>
                        <p>Anyone can scan this QR code to verify the authenticity of this review report. It will open this exact verification page.</p>
                        <div class="qs-link">
                            <i class="fa-solid fa-link me-1"></i>
                            <?php echo htmlspecialchars($thisPageUrl); ?>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Footer -->
            <div class="doc-footer">
                <div class="df-left">
                    <strong>AI Checker</strong> — Assignment Verification System<br>
                    This document is digitally verifiable. Scan the QR code to confirm authenticity.
                </div>
                <div class="df-right">
                    Generated on <?php echo date('M d, Y h:i A'); ?><br>
                    &copy; <?php echo date('Y'); ?> AI Checker. All rights reserved.
                </div>
            </div>
        </div>

        <!-- Action Buttons (hidden in print) -->
        <div class="action-bar">
            <button class="action-btn print-btn" onclick="window.print()">
                <i class="fa-solid fa-print"></i> Print Report
            </button>
            <button class="action-btn download-btn" id="btnDownloadPdf" onclick="downloadPdf()">
                <i class="fa-solid fa-file-pdf"></i> Download PDF
            </button>
        </div>

        <?php endif; ?>

    </div>

    <!-- Toast -->
    <div class="toast-msg" id="toastMsg"></div>

    <?php if ($isValid): ?>
    <!-- html2pdf.js for PDF download -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>
        function showToast(msg, duration) {
            duration = duration || 3000;
            const t = document.getElementById('toastMsg');
            t.textContent = msg;
            t.classList.add('show');
            setTimeout(() => t.classList.remove('show'), duration);
        }

        async function downloadPdf() {
            const btn = document.getElementById('btnDownloadPdf');
            if (!btn || btn.disabled) return;
            const orig = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Generating PDF...';
            btn.disabled = true;

            try {
                const element = document.getElementById('verifyDoc');

                const opt = {
                    margin:       [10, 10, 10, 10],
                    filename:     'AI Checker Verification - <?php echo addslashes($verification['verification_code']); ?>.pdf',
                    image:        { type: 'jpeg', quality: 0.98 },
                    html2canvas:  { scale: 2, useCORS: true, allowTaint: true, logging: false },
                    jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' },
                    pagebreak:    { mode: ['avoid-all', 'css', 'legacy'] }
                };

                await html2pdf().set(opt).from(element).save();
                showToast('PDF downloaded successfully!');
            } catch (e) {
                console.error('PDF generation error:', e);
                showToast('PDF generation failed. Please use Print > Save as PDF instead.');
            }

            btn.innerHTML = orig;
            btn.disabled = false;
        }
    </script>
    <?php endif; ?>

</body>
</html>