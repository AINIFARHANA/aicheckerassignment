<?php
/* ============================================================
   PDF INVOICE DOWNLOAD
   Uses Dompdf to generate and stream a professional PDF
   ============================================================ */
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized access.');
}

 $user_id = $_SESSION['user_id'];
 $isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

/* Validate payment_id */
if (!isset($_GET['payment_id']) || !ctype_digit((string)$_GET['payment_id'])) {
    die('Invalid payment ID.');
}
 $payment_id = intval($_GET['payment_id']);

/* Fetch payment */
 $stmt = $conn->prepare(
    "SELECT p.*, pl.plan_name, pl.duration AS plan_duration,
            u.username, u.email
     FROM payments p
     LEFT JOIN plans pl ON p.plan_id = pl.plan_id
     LEFT JOIN users u ON p.user_id = u.user_id
     WHERE p.payment_id = ?"
);
 $stmt->bind_param("i", $payment_id);
 $stmt->execute();
 $payment = $stmt->get_result()->fetch_assoc();
 $stmt->close();

if (!$payment) {
    die('Payment record not found.');
}

/* Ownership check (admin can download any) */
if (!$isAdmin && intval($payment['user_id']) !== $user_id) {
    die('Unauthorized access.');
}

/* ==========================================================
   Generate PDF with Dompdf
   ========================================================== */
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

 $options = new Options();
 $options->set('defaultFont', 'Helvetica');
 $options->set('isRemoteEnabled', true);
 $options->set('isHtml5ParserEnabled', true);
 $dompdf = new Dompdf($options);

 $discount = floatval($payment['discount_amount']);
 $original = floatval($payment['amount']);
 $total    = floatval($payment['total_paid']);
 $voucher  = htmlspecialchars($payment['voucher_code'] ?? '—');

 $status = $payment['payment_status'];
if ($status === 'Paid') $statusColor = '#16a34a';
elseif ($status === 'Pending') $statusColor = '#d97706';
else $statusColor = '#dc2626';

 $html = '
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"></head>
<body style="margin:0; padding:0; font-family:Helvetica, Arial, sans-serif;">
<div style="width:100%; max-width:800px; margin:0 auto; background:#fff;">

    <!-- Header -->
    <div style="background:linear-gradient(135deg,#7B3F91,#9B59B6); padding:36px 40px; display:flex; justify-content:space-between; align-items:center;">
        <div>
            <h2 style="color:#fff; font-weight:800; font-size:1.4rem; margin:0 0 2px 0;">AI Checker</h2>
            <p style="color:rgba(255,255,255,0.7); font-size:0.82rem; margin:0;">AI-Powered Assignment Checking System</p>
        </div>
        <div style="text-align:right;">
            <h3 style="color:#fff; font-weight:700; font-size:1.6rem; margin:0 0 4px 0;">INVOICE</h3>
            <p style="color:rgba(255,255,255,0.7); font-size:0.85rem; margin:0;">' . htmlspecialchars($payment['invoice_number']) . '</p>
        </div>
    </div>

    <!-- Body -->
    <div style="padding:36px 40px;">
        <!-- Meta -->
        <table style="width:100%; border-collapse:collapse; margin-bottom:32px; padding-bottom:24px; border-bottom:2px solid #f3f0f6;">
            <tr>
                <td style="vertical-align:top; width:50%; padding-right:24px;">
                    <p style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.08em; color:#8B7A96; font-weight:700; margin:0 0 10px 0;">Bill To</p>
                    <p style="color:#8B7A96; font-size:0.85rem; margin:2px 0;">Customer Name</p>
                    <p style="color:#2d1f3d; font-size:0.9rem; font-weight:500; margin:2px 0;">' . htmlspecialchars($payment['username']) . '</p>
                    <p style="color:#8B7A96; font-size:0.85rem; margin:10px 0 2px 0;">Email</p>
                    <p style="color:#2d1f3d; font-size:0.9rem; font-weight:500; margin:2px 0;">' . htmlspecialchars($payment['email']) . '</p>
                </td>
                <td style="vertical-align:top; text-align:right;">
                    <p style="font-size:0.72rem; text-transform:uppercase; letter-spacing:0.08em; color:#8B7A96; font-weight:700; margin:0 0 10px 0;">Invoice Details</p>
                    <p style="color:#8B7A96; font-size:0.85rem; margin:2px 0;">Invoice Number</p>
                    <p style="color:#2d1f3d; font-size:0.9rem; font-weight:500; margin:2px 0;">' . htmlspecialchars($payment['invoice_number']) . '</p>
                    <p style="color:#8B7A96; font-size:0.85rem; margin:10px 0 2px 0;">Payment Date</p>
                    <p style="color:#2d1f3d; font-size:0.9rem; font-weight:500; margin:2px 0;">' . date('d F Y, h:i A', strtotime($payment['created_at'])) . '</p>
                </td>
            </tr>
        </table>

        <!-- Items Table -->
        <table style="width:100%; border-collapse:collapse; margin-bottom:24px;">
            <thead>
                <tr>
                    <th style="background:#f9f7fc; padding:12px 16px; text-align:left; font-size:0.78rem; text-transform:uppercase; letter-spacing:0.06em; color:#8B7A96; font-weight:700; border-bottom:2px solid #f3f0f6;">Description</th>
                    <th style="background:#f9f7fc; padding:12px 16px; text-align:left; font-size:0.78rem; text-transform:uppercase; letter-spacing:0.06em; color:#8B7A96; font-weight:700; border-bottom:2px solid #f3f0f6;">Duration</th>
                    <th style="background:#f9f7fc; padding:12px 16px; text-align:left; font-size:0.78rem; text-transform:uppercase; letter-spacing:0.06em; color:#8B7A96; font-weight:700; border-bottom:2px solid #f3f0f6;">Method</th>
                    <th style="background:#f9f7fc; padding:12px 16px; text-align:right; font-size:0.78rem; text-transform:uppercase; letter-spacing:0.06em; color:#8B7A96; font-weight:700; border-bottom:2px solid #f3f0f6;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="padding:16px; border-bottom:1px solid #f3f0f6; color:#2d1f3d; font-size:0.9rem; font-weight:600;">
                        ' . htmlspecialchars($payment['plan_name'] ?? 'Subscription Plan') . '<br>
                        <span style="font-size:0.82rem; color:#8B7A96; font-weight:400;">Premium AI Assignment Checker</span>
                    </td>
                    <td style="padding:16px; border-bottom:1px solid #f3f0f6; color:#2d1f3d; font-size:0.9rem;">' . htmlspecialchars($payment['plan_duration'] ?? 'N/A') . '</td>
                    <td style="padding:16px; border-bottom:1px solid #f3f0f6; color:#2d1f3d; font-size:0.9rem;">' . htmlspecialchars($payment['payment_method']) . '</td>
                    <td style="padding:16px; border-bottom:1px solid #f3f0f6; color:#2d1f3d; font-size:0.9rem; text-align:right; font-weight:600;">RM ' . number_format($original, 2) . '</td>
                </tr>
            </tbody>
        </table>

        <!-- Totals -->
        <table style="width:280px; margin-left:auto; border-collapse:collapse;">
            <tr>
                <td style="padding:8px 0; font-size:0.88rem; color:#5a4668;">Subtotal</td>
                <td style="padding:8px 0; font-size:0.88rem; color:#5a4668; text-align:right; font-weight:500;">RM ' . number_format($original, 2) . '</td>
            </tr>';

if ($discount > 0) {
    $html .= '
            <tr>
                <td style="padding:8px 0; font-size:0.88rem; color:#22c55e;">Discount (' . $voucher . ')</td>
                <td style="padding:8px 0; font-size:0.88rem; color:#22c55e; text-align:right; font-weight:500;">-RM ' . number_format($discount, 2) . '</td>
            </tr>';
}

 $html .= '
            <tr>
                <td style="padding:12px 0 0 0; font-size:1.1rem; font-weight:700; color:#2d1f3d; border-top:2px solid #7B3F91;">Total Paid</td>
                <td style="padding:12px 0 0 0; font-size:1.1rem; font-weight:700; color:#7B3F91; text-align:right; border-top:2px solid #7B3F91;">RM ' . number_format($total, 2) . '</td>
            </tr>
        </table>
    </div>

    <!-- Status Bar -->
    <div style="display:flex; justify-content:space-between; align-items:center; padding:20px 40px; background:#f9f7fc; border-top:1px solid #f3f0f6;">
        <span style="display:inline-flex; align-items:center; gap:6px; padding:6px 16px; border-radius:60px; font-size:0.82rem; font-weight:700; color:' . $statusColor . '; background:' . $statusColor . '18;">
            ' . htmlspecialchars($status) . '
        </span>
        <span style="color:#8B7A96; font-size:0.85rem; font-style:italic;">Thank you for your purchase!</span>
    </div>
</div>
</body>
</html>';

 $dompdf->loadHtml($html);
 $dompdf->setPaper('A4', 'portrait');
 $dompdf->render();

/* Stream download */
 $dompdf->stream(
    $payment['invoice_number'] . '.pdf',
    ['Attachment' => true]
);