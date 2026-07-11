<?php
/* ============================================================
   PRINT-ONLY INVOICE PAGE
   Minimal layout that auto-triggers browser print dialog
   ============================================================ */
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    die('Unauthorized.');
}

 $user_id = $_SESSION['user_id'];
 $isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

if (!isset($_GET['payment_id']) || !ctype_digit((string)$_GET['payment_id'])) {
    die('Invalid payment ID.');
}
 $payment_id = intval($_GET['payment_id']);

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

if (!$payment) { die('Not found.'); }
if (!$isAdmin && intval($payment['user_id']) !== $user_id) { die('Unauthorized.'); }

 $discount = floatval($payment['discount_amount']);
 $original = floatval($payment['amount']);
 $total    = floatval($payment['total_paid']);
 $voucher  = htmlspecialchars($payment['voucher_code'] ?? '—');

 $status = $payment['payment_status'];
if ($status === 'Paid') $statusColor = '#16a34a'; elseif ($status === 'Pending') $statusColor = '#d97706'; else $statusColor = '#dc2626';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Invoice — <?php echo htmlspecialchars($payment['invoice_number']); ?></title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Helvetica Neue',Helvetica,Arial,sans-serif; background:#fff; color:#2d1f3d; }

        .invoice { max-width:800px; margin:0 auto; }

        .inv-header {
            background:linear-gradient(135deg,#7B3F91,#9B59B6);
            padding:36px 40px; display:flex; justify-content:space-between; align-items:center;
            -webkit-print-color-adjust:exact; print-color-adjust:exact;
        }
        .inv-brand h2 { color:#fff; font-weight:800; font-size:1.4rem; }
        .inv-brand p { color:rgba(255,255,255,0.7); font-size:0.82rem; }
        .inv-title { text-align:right; }
        .inv-title h3 { color:#fff; font-weight:700; font-size:1.6rem; }
        .inv-title p { color:rgba(255,255,255,0.7); font-size:0.85rem; }

        .inv-body { padding:36px 40px; }

        .inv-meta { display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-bottom:32px; padding-bottom:24px; border-bottom:2px solid #f3f0f6; }
        .inv-meta h6 { font-size:0.72rem; text-transform:uppercase; letter-spacing:0.08em; color:#8B7A96; font-weight:700; margin:0 0 10px 0; }
        .inv-meta .lbl { color:#8B7A96; font-size:0.85rem; margin:2px 0; }
        .inv-meta .val { color:#2d1f3d; font-size:0.9rem; font-weight:500; margin:2px 0; }
        .inv-meta .right { text-align:right; }

        table.inv-tbl { width:100%; border-collapse:collapse; margin-bottom:24px; }
        table.inv-tbl th { background:#f9f7fc; padding:12px 16px; text-align:left; font-size:0.78rem; text-transform:uppercase; letter-spacing:0.06em; color:#8B7A96; font-weight:700; border-bottom:2px solid #f3f0f6; }
        table.inv-tbl th:last-child { text-align:right; }
        table.inv-tbl td { padding:16px; border-bottom:1px solid #f3f0f6; color:#2d1f3d; font-size:0.9rem; }
        table.inv-tbl td:last-child { text-align:right; font-weight:600; }
        .sub-detail { font-size:0.82rem; color:#8B7A96; font-weight:400 !important; }

        .inv-totals { width:280px; margin-left:auto; border-collapse:collapse; }
        .inv-totals td { padding:8px 0; font-size:0.88rem; color:#5a4668; }
        .inv-totals td:last-child { text-align:right; font-weight:500; }
        .inv-totals .disc { color:#22c55e; }
        .inv-totals .tot { border-top:2px solid #7B3F91; padding-top:12px; font-size:1.1rem; font-weight:700; color:#2d1f3d; }
        .inv-totals .tot td:last-child { color:#7B3F91; }

        .inv-footer { display:flex; justify-content:space-between; align-items:center; padding:20px 40px; background:#f9f7fc; border-top:1px solid #f3f0f6; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        .inv-status { padding:6px 16px; border-radius:60px; font-size:0.82rem; font-weight:700; color:<?php echo $statusColor; ?>; }
        .inv-thanks { color:#8B7A96; font-size:0.85rem; font-style:italic; }

        @media print {
            body { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
            @page { margin:15mm; }
        }
    </style>
</head>
<body>

<div class="invoice">
    <div class="inv-header">
        <div class="inv-brand">
            <h2>AI Checker</h2>
            <p>AI-Powered Assignment Checking System</p>
        </div>
        <div class="inv-title">
            <h3>INVOICE</h3>
            <p><?php echo htmlspecialchars($payment['invoice_number']); ?></p>
        </div>
    </div>

    <div class="inv-body">
        <div class="inv-meta">
            <div>
                <h6>Bill To</h6>
                <p class="lbl">Customer Name</p>
                <p class="val"><?php echo htmlspecialchars($payment['username']); ?></p>
                <p class="lbl" style="margin-top:10px;">Email</p>
                <p class="val"><?php echo htmlspecialchars($payment['email']); ?></p>
            </div>
            <div class="right">
                <h6>Invoice Details</h6>
                <p class="lbl">Invoice Number</p>
                <p class="val"><?php echo htmlspecialchars($payment['invoice_number']); ?></p>
                <p class="lbl" style="margin-top:10px;">Payment Date</p>
                <p class="val"><?php echo date('d F Y, h:i A', strtotime($payment['created_at'])); ?></p>
            </div>
        </div>

        <table class="inv-tbl">
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Duration</th>
                    <th>Method</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <?php echo htmlspecialchars($payment['plan_name'] ?? 'Subscription Plan'); ?>
                        <br><span class="sub-detail">Premium AI Assignment Checker</span>
                    </td>
                    <td><?php echo htmlspecialchars($payment['plan_duration'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($payment['payment_method']); ?></td>
                    <td>RM <?php echo number_format($original, 2); ?></td>
                </tr>
            </tbody>
        </table>

        <table class="inv-totals">
            <tr><td>Subtotal</td><td>RM <?php echo number_format($original, 2); ?></td></tr>
            <?php if ($discount > 0): ?>
            <tr class="disc"><td>Discount (<?php echo $voucher; ?>)</td><td>-RM <?php echo number_format($discount, 2); ?></td></tr>
            <?php endif; ?>
            <tr class="tot"><td>Total Paid</td><td>RM <?php echo number_format($total, 2); ?></td></tr>
        </table>
    </div>

    <div class="inv-footer">
        <span class="inv-status"><?php echo htmlspecialchars($status); ?></span>
        <span class="inv-thanks">Thank you for your purchase!</span>
    </div>
</div>

<script>
    /* Auto-trigger print dialog when page loads */
    window.onload = function() {
        setTimeout(function() { window.print(); }, 300);
    };
</script>

</body>
</html>