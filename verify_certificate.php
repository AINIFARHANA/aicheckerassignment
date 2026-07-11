<?php
// ═══════════════════════════════════════════════════════════════
// verify_certificate.php
// Public Certificate ID lookup — no login required.
// PHP + MySQL only — no Composer, no external API calls.
// ═══════════════════════════════════════════════════════════════
$servername = "localhost";
$username   = "root";
$password   = "";               // Default XAMPP password
$dbname     = "assignment_db";  // Your local database name

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}


function esc($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

 $searched     = false;
 $certificate  = null;
 $codeInput    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['certificate_code'])) {
    $searched  = true;
    $codeInput = trim($_POST['certificate_code']);
    if ($codeInput !== '') {
        $stmt = $conn->prepare("SELECT * FROM certificates WHERE certificate_code = ? LIMIT 1");
        $stmt->bind_param("s", $codeInput);
        $stmt->execute();
        $certificate = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

if (!$searched && isset($_GET['code']) && trim($_GET['code']) !== '') {
    $searched  = true;
    $codeInput = trim($_GET['code']);
    $stmt = $conn->prepare("SELECT * FROM certificates WHERE certificate_code = ? LIMIT 1");
    $stmt->bind_param("s", $codeInput);
    $stmt->execute();
    $certificate = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

 $certificateFileUrl = ($certificate && !empty($certificate['certificate_file']) && file_exists(__DIR__ . '/' . $certificate['certificate_file']))
    ? $certificate['certificate_file']
    : '';

 $conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify Certificate - AI Assignment Checker</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&family=Playfair+Display:ital,wght@0,600;0,700;0,800;1,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
    :root {
        --bg-deep: #150a1e;
        --bg-card: rgba(255,255,255,0.04);
        --primary-dark: #4A0E6B;
        --primary-mid: #7B3F91;
        --primary-light: #D88FFF;
        --accent: #F4D1FF;
        --text-bright: #F5EEFB;
        --text-body: #C9BBD8;
        --text-muted: #9C8DAE;
        --border-subtle: rgba(244,209,255,0.10);
        --border-glow: rgba(244,209,255,0.18);
        --radius-lg: 22px;
        --success: #4ade80;
        --danger: #f87171;
        --gold: #F0C040;
        --gold-light: #FDE68A;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        min-height: 100vh;
        font-family: 'Poppins', sans-serif;
        color: var(--text-body);
        background: radial-gradient(circle at 20% 0%, #2c1440 0%, var(--bg-deep) 55%);
        display: flex;
        flex-direction: column;
        align-items: center;
        padding: 50px 20px 80px;
    }

    /* ── Brand ── */
    .vc-brand {
        display: flex; align-items: center; gap: 12px;
        margin-bottom: 32px;
    }
    .vc-brand-icon {
        width: 46px; height: 46px; border-radius: 14px;
        background: linear-gradient(135deg, #6A0DAD, #9C27B0);
        display: flex; align-items: center; justify-content: center;
        color: #fff; font-weight: 800; font-size: 1.1rem;
        box-shadow: 0 4px 16px rgba(106,13,173,0.4);
    }
    .vc-brand-name {
        font-weight: 800; color: var(--text-bright); font-size: 1.15rem;
        letter-spacing: -0.01em;
    }

    /* ── Search Card ── */
    .vc-card {
        width: 100%; max-width: 520px;
        background: var(--bg-card);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius-lg);
        padding: 36px 32px;
        backdrop-filter: blur(20px);
        box-shadow: 0 20px 60px rgba(0,0,0,0.35);
    }
    .vc-card h1 {
        font-size: 1.35rem; font-weight: 800; color: var(--text-bright);
        margin-bottom: 6px;
        display: flex; align-items: center; gap: 10px;
    }
    .vc-card h1 i { color: var(--primary-light); }
    .vc-sub {
        font-size: 0.86rem; color: var(--text-muted); margin-bottom: 24px; line-height: 1.6;
    }
    .vc-form label {
        display: block; font-size: 0.75rem; font-weight: 600;
        text-transform: uppercase; letter-spacing: 0.06em;
        color: var(--text-muted); margin-bottom: 8px;
    }
    .vc-input-row { display: flex; gap: 10px; flex-wrap: wrap; }
    .vc-input-row input[type="text"] {
        flex: 1 1 200px;
        background: rgba(255,255,255,0.05);
        border: 1px solid var(--border-glow);
        border-radius: 12px;
        padding: 13px 16px;
        color: var(--text-bright);
        font-family: 'Space Grotesk', monospace;
        font-size: 0.95rem;
        letter-spacing: 0.06em;
        outline: none;
        transition: border-color 0.2s;
    }
    .vc-input-row input[type="text"]::placeholder { color: var(--text-muted); letter-spacing: 0.02em; }
    .vc-input-row input[type="text"]:focus { border-color: var(--primary-light); }
    .vc-btn {
        background: var(--primary-mid);
        border: 1px solid var(--primary-mid);
        color: #fff; font-weight: 700; font-size: 0.9rem;
        padding: 13px 22px; border-radius: 12px; cursor: pointer;
        transition: all 0.25s ease;
        display: inline-flex; align-items: center; gap: 8px;
        white-space: nowrap;
    }
    .vc-btn:hover { background: var(--primary-light); border-color: var(--primary-light); box-shadow: 0 8px 26px rgba(123,63,145,0.4); }

    /* ── Invalid Result ── */
    .vc-result { margin-top: 26px; border-top: 1px solid var(--border-subtle); padding-top: 24px; }
    .vc-result-badge {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 8px 18px; border-radius: 60px;
        font-weight: 700; font-size: 0.88rem; margin-bottom: 14px;
    }
    .vc-result-badge.invalid {
        background: rgba(248,113,113,0.14); border: 1px solid rgba(248,113,113,0.35); color: var(--danger);
    }
    .vc-back {
        margin-top: 24px; font-size: 0.82rem; color: var(--text-muted);
        text-decoration: none; display: inline-flex; align-items: center; gap: 6px;
    }
    .vc-back:hover { color: var(--primary-light); }

    /* ════════════════════════════════════════════════════════════
       CERTIFICATE DOCUMENT
       ════════════════════════════════════════════════════════════ */
    .cert-wrapper {
        margin-top: 40px;
        width: 100%;
        max-width: 780px;
        animation: certFadeIn 0.8s cubic-bezier(0.22, 1, 0.36, 1) forwards;
        opacity: 0;
        transform: translateY(30px);
    }
    @keyframes certFadeIn {
        to { opacity: 1; transform: translateY(0); }
    }

    /* Certificate toolbar */
    .cert-toolbar {
        display: flex; justify-content: flex-end; gap: 10px; margin-bottom: 14px;
    }
    .cert-toolbar button {
        background: rgba(255,255,255,0.06);
        border: 1px solid var(--border-subtle);
        color: var(--text-body);
        padding: 8px 16px;
        border-radius: 10px;
        font-size: 0.8rem;
        font-weight: 600;
        cursor: pointer;
        display: inline-flex; align-items: center; gap: 6px;
        transition: all 0.2s;
        font-family: 'Poppins', sans-serif;
    }
    .cert-toolbar button:hover {
        background: var(--primary-mid);
        border-color: var(--primary-mid);
        color: #fff;
    }

    /* The certificate itself */
    .cert-document {
        position: relative;
        background: linear-gradient(160deg, #1e0e2e 0%, #170a24 40%, #1a0c28 100%);
        border-radius: 6px;
        padding: 6px;
        box-shadow:
            0 30px 80px rgba(0,0,0,0.5),
            0 0 0 1px rgba(212,175,255,0.08),
            inset 0 0 80px rgba(123,63,145,0.04);
    }

    /* Outer decorative border */
    .cert-border-outer {
        border: 2px solid rgba(212,175,255,0.18);
        border-radius: 4px;
        padding: 5px;
    }

    /* Inner decorative border */
    .cert-border-inner {
        border: 1px solid rgba(212,175,255,0.12);
        border-radius: 2px;
        padding: 44px 48px 50px;
        position: relative;
        overflow: hidden;
    }

    /* Corner ornaments */
    .cert-corner {
        position: absolute;
        width: 60px; height: 60px;
        opacity: 0.35;
    }
    .cert-corner svg { width: 100%; height: 100%; }
    .cert-corner.tl { top: 12px; left: 12px; }
    .cert-corner.tr { top: 12px; right: 12px; transform: scaleX(-1); }
    .cert-corner.bl { bottom: 12px; left: 12px; transform: scaleY(-1); }
    .cert-corner.br { bottom: 12px; right: 12px; transform: scale(-1, -1); }

    /* Subtle watermark pattern */
    .cert-watermark {
        position: absolute;
        top: 50%; left: 50%;
        transform: translate(-50%, -50%);
        font-family: 'Playfair Display', serif;
        font-size: 160px;
        font-weight: 800;
        color: rgba(123, 63, 145, 0.04);
        letter-spacing: 0.08em;
        white-space: nowrap;
        pointer-events: none;
        user-select: none;
        z-index: 0;
    }

    /* Diagonal pattern overlay */
    .cert-border-inner::before {
        content: '';
        position: absolute;
        inset: 0;
        background: repeating-linear-gradient(
            -45deg,
            transparent,
            transparent 40px,
            rgba(212,175,255,0.012) 40px,
            rgba(212,175,255,0.012) 41px
        );
        pointer-events: none;
        z-index: 0;
    }

    /* Content layer */
    .cert-content {
        position: relative;
        z-index: 1;
        text-align: center;
    }

    /* Logo row */
    .cert-logo-row {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 14px;
        margin-bottom: 28px;
    }
    .cert-logo-icon {
        width: 52px; height: 52px; border-radius: 16px;
        background: linear-gradient(135deg, #6A0DAD, #9C27B0);
        display: flex; align-items: center; justify-content: center;
        color: #fff; font-weight: 800; font-size: 1.2rem;
        box-shadow: 0 4px 20px rgba(106,13,173,0.5);
        flex-shrink: 0;
    }
    .cert-logo-text {
        font-weight: 800; color: var(--accent); font-size: 1.05rem;
        letter-spacing: 0.02em; text-align: left;
    }
    .cert-logo-text span {
        display: block; font-size: 0.68rem; font-weight: 500;
        color: var(--text-muted); letter-spacing: 0.12em;
        text-transform: uppercase; margin-top: 2px;
    }

    /* Divider */
    .cert-divider {
        width: 80px; height: 2px; margin: 0 auto 24px;
        background: linear-gradient(90deg, transparent, var(--primary-light), transparent);
        border-radius: 2px;
    }

    /* Title */
    .cert-title {
        font-family: 'Playfair Display', serif;
        font-size: 2.1rem;
        font-weight: 800;
        color: var(--accent);
        letter-spacing: 0.04em;
        text-transform: uppercase;
        line-height: 1.2;
        margin-bottom: 6px;
    }
    .cert-subtitle {
        font-size: 0.8rem;
        font-weight: 500;
        color: var(--text-muted);
        text-transform: uppercase;
        letter-spacing: 0.18em;
        margin-bottom: 32px;
    }

    /* Body text */
    .cert-body-text {
        font-size: 0.92rem;
        color: var(--text-body);
        line-height: 1.7;
        margin-bottom: 8px;
    }

    /* Student name */
    .cert-name {
        font-family: 'Playfair Display', serif;
        font-size: 2rem;
        font-weight: 800;
        color: var(--text-bright);
        margin: 10px 0 6px;
        line-height: 1.2;
        position: relative;
        display: inline-block;
    }
    .cert-name::after {
        content: '';
        position: absolute;
        bottom: -4px; left: -10%; right: -10%;
        height: 2px;
        background: linear-gradient(90deg, transparent, var(--primary-light), transparent);
        border-radius: 2px;
    }

    /* Details grid */
    .cert-details {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px 40px;
        margin: 30px auto 0;
        max-width: 480px;
        text-align: left;
    }
    .cert-detail-item {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }
    .cert-detail-label {
        font-size: 0.68rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: var(--text-muted);
    }
    .cert-detail-value {
        font-size: 0.95rem;
        font-weight: 600;
        color: var(--text-bright);
    }
    .cert-detail-value.mono {
        font-family: 'Space Grotesk', monospace;
        letter-spacing: 0.05em;
        font-size: 0.88rem;
        color: var(--primary-light);
    }
    .cert-detail-value.score {
        font-family: 'Space Grotesk', monospace;
        font-size: 1.1rem;
        color: var(--success);
    }

    /* Bottom section */
    .cert-bottom {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        margin-top: 44px;
        padding-top: 0;
    }

    /* Signature area */
    .cert-signature {
        text-align: center;
        min-width: 160px;
    }
    .cert-sig-line {
        width: 140px; height: 1px;
        background: linear-gradient(90deg, transparent, rgba(212,175,255,0.3), transparent);
        margin: 0 auto 8px;
    }
    .cert-sig-label {
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: var(--text-muted);
    }
    .cert-sig-name {
        font-size: 0.82rem;
        font-weight: 700;
        color: var(--text-body);
        margin-top: 2px;
    }

    /* Stamp */
    .cert-stamp {
        position: relative;
        width: 130px; height: 130px;
        flex-shrink: 0;
        animation: stampIn 0.5s 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
        opacity: 0;
        transform: scale(0.5) rotate(-20deg);
        filter: drop-shadow(0 4px 20px rgba(123,63,145,0.4));
    }
    @keyframes stampIn {
        to { opacity: 1; transform: scale(1) rotate(-8deg); }
    }
    .cert-stamp img {
        width: 100%; height: 100%;
        object-fit: contain;
    }

    /* Verified ribbon */
    .cert-verified-ribbon {
        position: absolute;
        top: -1px; right: 48px;
        background: linear-gradient(135deg, #059669, #10b981);
        color: #fff;
        font-size: 0.65rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.12em;
        padding: 6px 14px 8px;
        border-radius: 0 0 8px 8px;
        box-shadow: 0 4px 14px rgba(16,185,129,0.35);
        display: flex; align-items: center; gap: 5px;
        z-index: 2;
    }

    /* Certificate code footer */
    .cert-code-footer {
        margin-top: 32px;
        padding-top: 16px;
        border-top: 1px solid rgba(212,175,255,0.08);
        font-family: 'Space Grotesk', monospace;
        font-size: 0.75rem;
        color: var(--text-muted);
        letter-spacing: 0.08em;
    }
    .cert-code-footer i { margin-right: 4px; }

    /* ── Print styles ── */
    @media print {
        body {
            background: #1a0c28 !important;
            padding: 0 !important;
        }
        .vc-brand, .vc-card, .cert-toolbar, .vc-back { display: none !important; }
        .cert-wrapper {
            margin: 0 !important;
            max-width: 100% !important;
            animation: none !important;
            opacity: 1 !important;
            transform: none !important;
        }
        .cert-document {
            box-shadow: none !important;
            border-radius: 0 !important;
        }
        .cert-stamp {
            animation: none !important;
            opacity: 1 !important;
            transform: scale(1) rotate(-8deg) !important;
        }
    }

    /* ── Responsive ── */
    @media (max-width: 600px) {
        .cert-border-inner { padding: 32px 20px 36px; }
        .cert-title { font-size: 1.5rem; }
        .cert-name { font-size: 1.5rem; }
        .cert-details { grid-template-columns: 1fr; gap: 10px; }
        .cert-bottom { flex-direction: column-reverse; align-items: center; gap: 24px; }
        .cert-stamp { width: 100px; height: 100px; }
        .cert-corner { width: 40px; height: 40px; }
        .cert-watermark { font-size: 90px; }
        body { padding: 30px 12px 60px; }
    }
</style>
</head>
<body>

    <div class="vc-brand">
        <div class="vc-brand-icon">AI</div>
        <div class="vc-brand-name">AI Assignment Checker</div>
    </div>

    <div class="vc-card">
        <h1><i class="fa-solid fa-certificate"></i> Verify Certificate</h1>
        <p class="vc-sub">Enter a Certificate ID to confirm it was genuinely issued by AI Assignment Checker.</p>

        <form class="vc-form" method="POST" action="verify_certificate.php">
            <label for="certificate_code">Enter Certificate ID</label>
            <div class="vc-input-row">
                <input type="text" id="certificate_code" name="certificate_code"
                       placeholder="CERT-2026-00001"
                       value="<?php echo esc($codeInput); ?>" autocomplete="off" required>
                <button type="submit" class="vc-btn"><i class="fa-solid fa-magnifying-glass"></i> Verify</button>
            </div>
        </form>

        <?php if ($searched && !$certificate): ?>
            <div class="vc-result">
                <div class="vc-result-badge invalid"><i class="fa-solid fa-circle-xmark"></i> Invalid Certificate ID</div>
                <p style="font-size:0.86rem;color:var(--text-muted);line-height:1.6;">
                    We couldn't find a certificate matching "<?php echo esc($codeInput); ?>". Double-check the ID and try again.
                </p>
            </div>
        <?php endif; ?>

        <a href="index.php" class="vc-back"><i class="fa-solid fa-arrow-left"></i> Back to Home</a>
    </div>

    <?php if ($searched && $certificate): ?>

    <!-- ════════════ CERTIFICATE DOCUMENT ════════════ -->
    <div class="cert-wrapper" id="certWrapper">

        <!-- Toolbar -->
        <div class="cert-toolbar">
            <button onclick="window.print()" title="Print Certificate">
                <i class="fa-solid fa-print"></i> Print
            </button>
            <?php if ($certificateFileUrl): ?>
            <a href="<?php echo esc($certificateFileUrl); ?>" download style="text-decoration:none;">
                <button type="button" title="Download Original">
                    <i class="fa-solid fa-download"></i> Download
                </button>
            </a>
            <?php endif; ?>
        </div>

        <!-- Document -->
        <div class="cert-document">
            <div class="cert-border-outer">
                <div class="cert-border-inner">

                    <!-- Verified ribbon -->
                    <div class="cert-verified-ribbon">
                        <i class="fa-solid fa-shield-halved"></i> Verified
                    </div>

                    <!-- Corner ornaments -->
                    <div class="cert-corner tl">
                        <svg viewBox="0 0 60 60" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M4 4 L4 28 Q4 4 28 4 Z" stroke="#D4AFFF" stroke-width="1.2" fill="none"/>
                            <path d="M8 8 L8 22 Q8 8 22 8 Z" stroke="#D4AFFF" stroke-width="0.6" fill="none" opacity="0.5"/>
                            <circle cx="4" cy="4" r="2" fill="#D4AFFF" opacity="0.6"/>
                        </svg>
                    </div>
                    <div class="cert-corner tr">
                        <svg viewBox="0 0 60 60" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M4 4 L4 28 Q4 4 28 4 Z" stroke="#D4AFFF" stroke-width="1.2" fill="none"/>
                            <path d="M8 8 L8 22 Q8 8 22 8 Z" stroke="#D4AFFF" stroke-width="0.6" fill="none" opacity="0.5"/>
                            <circle cx="4" cy="4" r="2" fill="#D4AFFF" opacity="0.6"/>
                        </svg>
                    </div>
                    <div class="cert-corner bl">
                        <svg viewBox="0 0 60 60" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M4 4 L4 28 Q4 4 28 4 Z" stroke="#D4AFFF" stroke-width="1.2" fill="none"/>
                            <path d="M8 8 L8 22 Q8 8 22 8 Z" stroke="#D4AFFF" stroke-width="0.6" fill="none" opacity="0.5"/>
                            <circle cx="4" cy="4" r="2" fill="#D4AFFF" opacity="0.6"/>
                        </svg>
                    </div>
                    <div class="cert-corner br">
                        <svg viewBox="0 0 60 60" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M4 4 L4 28 Q4 4 28 4 Z" stroke="#D4AFFF" stroke-width="1.2" fill="none"/>
                            <path d="M8 8 L8 22 Q8 8 22 8 Z" stroke="#D4AFFF" stroke-width="0.6" fill="none" opacity="0.5"/>
                            <circle cx="4" cy="4" r="2" fill="#D4AFFF" opacity="0.6"/>
                        </svg>
                    </div>

                    <!-- Watermark -->
                    <div class="cert-watermark">AI CHECKED</div>

                    <!-- Content -->
                    <div class="cert-content">

                        <!-- Logo -->
                        <div class="cert-logo-row">
                            <div class="cert-logo-icon" img src="image/logo.png"></div>
                            <div class="cert-logo-text">
                                AI Assignment Checker
                                <span>Integrity Verification System</span>
                            </div>
                        </div>

                        <div class="cert-divider"></div>

                        <!-- Title -->
                        <div class="cert-title">Certificate of Verification</div>
                        <div class="cert-subtitle">Authenticity Confirmation</div>

                        <!-- Body -->
                        <p class="cert-body-text">
                            This is to certify that the assignment submitted by
                        </p>
                        <div class="cert-name"><?php echo esc($certificate['student_name']); ?></div>

                        <p class="cert-body-text" style="margin-top:18px;">
                            has been successfully analyzed and the following certificate
                            has been genuinely issued by our system.
                        </p>

                        <!-- Details -->
                        <div class="cert-details">
                            <div class="cert-detail-item">
                                <div class="cert-detail-label">Assignment Title</div>
                                <div class="cert-detail-value"><?php echo esc($certificate['assignment_title']); ?></div>
                            </div>
                            <div class="cert-detail-item">
                                <div class="cert-detail-label">AI Detection Score</div>
                                <div class="cert-detail-value score">
                                    <i class="fa-solid fa-chart-simple" style="font-size:0.85rem;margin-right:3px;"></i>
                                    <?php echo $certificate['ai_score'] !== null ? esc(number_format((float)$certificate['ai_score'], 1)) . '%' : 'N/A'; ?>
                                </div>
                            </div>
                            <div class="cert-detail-item">
                                <div class="cert-detail-label">Date Issued</div>
                                <div class="cert-detail-value"><?php echo esc(date('F j, Y', strtotime($certificate['issued_date']))); ?></div>
                            </div>
                            <div class="cert-detail-item">
                                <div class="cert-detail-label">Certificate ID</div>
                                <div class="cert-detail-value mono"><?php echo esc($certificate['certificate_code']); ?></div>
                            </div>
                        </div>

                        <!-- Bottom: Signature + Stamp -->
                        <div class="cert-bottom">
                            <div class="cert-signature">
                                <div class="cert-sig-line"></div>
                                <div class="cert-sig-label">Authorized By</div>
                                <div class="cert-sig-name">AI Assignment Checker</div>
                            </div>

                            <!-- Stamp image -->
                            <div class="cert-stamp">
                                <img src="image/stamp.png"
                                     alt="AI Assignment Checked Stamp">
                            </div>
                        </div>

                        <!-- Footer code -->
                        <div class="cert-code-footer">
                            <i class="fa-solid fa-fingerprint"></i>
                            Verify at: aiassignmentchecker.com/verify?id=<?php echo esc($certificate['certificate_code']); ?>
                        </div>

                    </div><!-- /cert-content -->
                </div><!-- /cert-border-inner -->
            </div><!-- /cert-border-outer -->
        </div><!-- /cert-document -->
    </div>
    <!-- ════════════ END CERTIFICATE ════════════ -->

    <?php endif; ?>

</body>
</html>