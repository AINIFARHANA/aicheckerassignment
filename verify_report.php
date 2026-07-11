<?php
// ═══════════════════════════════════════════════════════════════
// verify_report.php
//
// Public page — no login required. This is what opens when someone
// scans the QR code printed on an AI Analysis report.
//
// IMPORTANT: this page does NOT query the database. Every detail it
// shows (report ID, file name, student, score, risk, date) comes
// straight from the URL's query string, and authenticity is proven
// by re-deriving the HMAC signature from those same values and
// comparing it to the "sig" parameter — see verify_lib.php. If a
// single character of the link is altered, the signature check
// fails and the page shows "Invalid / Tampered Link" instead of a
// certificate.
// ═══════════════════════════════════════════════════════════════

require_once __DIR__ . '/verify_lib.php';

function vesc($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

$fields = verifyCheckRequest($_GET);
$isValid = ($fields !== false);

$company_name = VERIFY_COMPANY_NAME;
$company_tagline = VERIFY_COMPANY_TAGLINE;

$logo_path = __DIR__ . '/image/logo.png';
$logo_url = file_exists($logo_path) ? 'image/logo.png' : '';
$stamp_path = __DIR__ . '/image/stamp.png';
$stamp_url = file_exists($stamp_path) ? 'image/stamp.png' : '';

if ($isValid) {
    $report_id = $fields['r'];
    $file_name = $fields['f'];
    $student_name = $fields['n'];
    $score = is_numeric($fields['s']) ? (float)$fields['s'] : null;
    $risk = $fields['k'];
    $date_str = $fields['d'];

    if ($score !== null && $score <= 20) {
        $verdictColor = '#16a34a'; $verdictBg = 'rgba(22,163,74,0.08)'; $verdictBorder = 'rgba(22,163,74,0.25)';
        $verdictLabel = 'APPROVED';
        $verdictText = "This submission has been analyzed by {$company_name} and verified as predominantly human-authored content. It is approved based on our AI-content assessment.";
    } elseif ($score !== null && $score <= 50) {
        $verdictColor = '#d97706'; $verdictBg = 'rgba(217,119,6,0.08)'; $verdictBorder = 'rgba(217,119,6,0.25)';
        $verdictLabel = 'REVIEWED — MODERATE RISK';
        $verdictText = "This submission has been analyzed by {$company_name} and shows a moderate probability of AI-assisted content. We recommend instructor review before final approval.";
    } elseif ($score !== null) {
        $verdictColor = '#dc2626'; $verdictBg = 'rgba(220,38,38,0.08)'; $verdictBorder = 'rgba(220,38,38,0.25)';
        $verdictLabel = 'FLAGGED — HIGH RISK';
        $verdictText = "This submission has been analyzed by {$company_name} and shows a high probability of AI-generated content. It has not been approved and requires instructor review.";
    } else {
        $verdictColor = '#7B6B8D'; $verdictBg = 'rgba(123,107,141,0.08)'; $verdictBorder = 'rgba(123,107,141,0.25)';
        $verdictLabel = 'SCORE UNAVAILABLE';
        $verdictText = "This link was signed by {$company_name}, but no numeric AI score was recorded for it.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo $isValid ? 'Verified Certificate — ' . vesc($company_name) : 'Invalid Verification Link — ' . vesc($company_name); ?></title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:linear-gradient(160deg,#F3F0F7,#E8E0F0);color:#2D1B4E;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:30px 16px;}
.cert-card{background:#fff;max-width:560px;width:100%;border-radius:22px;box-shadow:0 20px 60px rgba(106,13,173,0.18);overflow:hidden;position:relative;}
.cert-top{padding:34px 34px 0;text-align:center;}
.cert-logo{width:84px;height:84px;object-fit:contain;margin:0 auto 12px;display:block;}
.cert-company{font-size:20px;font-weight:800;color:#6A0DAD;letter-spacing:0.5px;}
.cert-tagline{font-size:12px;color:#7B6B8D;margin-top:2px;margin-bottom:20px;}
.cert-divider{height:3px;background:linear-gradient(90deg,#6A0DAD,#9C27B0);}
.cert-body{padding:28px 34px 34px;}
.verdict-badge{display:inline-flex;align-items:center;gap:8px;padding:10px 22px;border-radius:24px;font-size:13.5px;font-weight:800;letter-spacing:0.6px;margin-bottom:16px;}
.verdict-text{font-size:13.5px;line-height:1.7;color:#444;margin-bottom:22px;}
.stamp-block{text-align:center;margin-bottom:22px;}
.stamp-block img{width:120px;height:120px;object-fit:contain;}
.detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:8px;}
.detail-item{background:#F9F7FC;border:1px solid #E8E0F0;border-radius:10px;padding:12px 14px;}
.detail-item .dl{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.6px;color:#9C8DAE;margin-bottom:3px;}
.detail-item .dv{font-size:13.5px;font-weight:700;color:#2D1B4E;word-break:break-word;}
.detail-item.full{grid-column:1 / -1;}
.score-row{display:flex;align-items:center;gap:14px;background:#F9F7FC;border:1px solid #E8E0F0;border-radius:12px;padding:16px 18px;margin:18px 0;}
.score-circle{width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:900;flex-shrink:0;}
.score-meta .sl{font-size:11px;color:#7B6B8D;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;}
.score-meta .sr{font-size:15px;font-weight:800;margin-top:2px;}
.auth-note{font-size:11px;color:#9C8DAE;text-align:center;line-height:1.6;margin-top:20px;border-top:1px dashed #E8E0F0;padding-top:16px;}
.auth-note strong{color:#6A0DAD;}
.print-btn{display:inline-flex;align-items:center;gap:8px;margin-top:18px;padding:11px 24px;border:none;border-radius:10px;background:linear-gradient(135deg,#6A0DAD,#9C27B0);color:#fff;font-family:inherit;font-size:13px;font-weight:700;cursor:pointer;}
.invalid-icon{width:74px;height:74px;border-radius:50%;background:rgba(220,38,38,0.1);color:#dc2626;display:flex;align-items:center;justify-content:center;font-size:34px;font-weight:900;margin:0 auto 18px;}
.invalid-title{font-size:19px;font-weight:800;color:#2D1B4E;text-align:center;margin-bottom:10px;}
.invalid-text{font-size:13.5px;color:#7B6B8D;text-align:center;line-height:1.7;}
@media print{ body{background:#fff;padding:0;} .cert-card{box-shadow:none;} .print-btn{display:none;} }
</style>
</head>
<body>
<div class="cert-card">
<?php if ($isValid): ?>
    <div class="cert-top">
        <?php if ($logo_url): ?><img src="<?php echo vesc($logo_url); ?>" class="cert-logo" alt="<?php echo vesc($company_name); ?> Logo"><?php endif; ?>
        <div class="cert-company"><?php echo vesc($company_name); ?></div>
        <div class="cert-tagline"><?php echo vesc($company_tagline); ?></div>
    </div>
    <div class="cert-divider"></div>
    <div class="cert-body">
        <div style="text-align:center;">
            <span class="verdict-badge" style="color:<?php echo $verdictColor; ?>;background:<?php echo $verdictBg; ?>;border:1px solid <?php echo $verdictBorder; ?>;">&#10003; <?php echo vesc($verdictLabel); ?></span>
        </div>
        <p class="verdict-text" style="text-align:center;"><?php echo vesc($verdictText); ?></p>

        <?php if ($stamp_url): ?>
        <div class="stamp-block"><img src="<?php echo vesc($stamp_url); ?>" alt="Official Stamp"></div>
        <?php endif; ?>

        <div class="score-row">
            <div class="score-circle" style="background:<?php echo $verdictBg; ?>;color:<?php echo $verdictColor; ?>;"><?php echo $score !== null ? (int)round($score) : '—'; ?></div>
            <div class="score-meta">
                <div class="sl">AI Probability Score / 100</div>
                <div class="sr" style="color:<?php echo $verdictColor; ?>;">Risk Level: <?php echo vesc($risk); ?></div>
            </div>
        </div>

        <div class="detail-grid">
            <div class="detail-item full"><div class="dl">File Name</div><div class="dv"><?php echo vesc($file_name); ?></div></div>
            <div class="detail-item"><div class="dl">Student</div><div class="dv"><?php echo vesc($student_name); ?></div></div>
            <div class="detail-item"><div class="dl">Report ID</div><div class="dv"><?php echo vesc($report_id); ?></div></div>
            <div class="detail-item full"><div class="dl">Date Processed</div><div class="dv"><?php echo vesc($date_str); ?></div></div>
        </div>

        <div style="text-align:center;">
            <button class="print-btn" onclick="window.print()">&#128190; Save / Print Certificate</button>
        </div>

        <div class="auth-note"><strong>Cryptographically verified.</strong> This certificate's authenticity was confirmed directly from the signed link you scanned — no database lookup or internet service was contacted to check it.</div>
    </div>
<?php else: ?>
    <div class="cert-body" style="padding-top:40px;">
        <div class="invalid-icon">&#33;</div>
        <div class="invalid-title">Invalid or Tampered Verification Link</div>
        <p class="invalid-text">This link is missing required details or its signature does not match — it may have been altered, corrupted, or is not a genuine <?php echo vesc($company_name); ?> verification link. Please re-scan the QR code directly from the original report.</p>
    </div>
<?php endif; ?>
</div>
</body>
</html>
