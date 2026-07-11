<?php
/**
 * view_receipt.php
 *
 * Securely displays a saved payment receipt for the logged-in user.
 * Called as: view_receipt.php?id=<payment_transactions.id>
 */

session_start();
require_once 'config.php';

/* ── IMPORTANT: Set this to match your database timezone ── */
date_default_timezone_set('Asia/Kuala_Lumpur');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

 $payment_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($payment_id <= 0) {
    http_response_code(400);
    die('Invalid receipt request.');
}

 $stmt = $conn->prepare("SELECT * FROM payment_transactions WHERE id = ? LIMIT 1");
 $stmt->bind_param("i", $payment_id);
 $stmt->execute();
 $payment = $stmt->get_result()->fetch_assoc();
 $stmt->close();

if (!$payment) {
    http_response_code(404);
    die('Receipt not found.');
}

/* ── Ownership check: only the paying user (or an admin) may view it ── */
 $isOwner = ((int) $_SESSION['user_id'] === (int) $payment['user_id']);
 $isAdmin = false;
if (!$isOwner) {
    $aStmt = $conn->prepare("SELECT user_type FROM users WHERE user_id = ?");
    $aStmt->bind_param("i", $_SESSION['user_id']);
    $aStmt->execute();
    $u = $aStmt->get_result()->fetch_assoc();
    $aStmt->close();
    $isAdmin = ($u && $u['user_type'] === 'admin');
}
if (!$isOwner && !$isAdmin) {
    http_response_code(403);
    die('You do not have permission to view this receipt.');
}

if ($payment['status'] !== 'paid') {
    http_response_code(403);
    die('This payment has not been completed yet.');
}

/* ═══════════════════════════════════════════════════════════════
   REMOVED: the saved receipt_file check.
   Previously, if an old HTML file existed on disk it would output
   that OLD design instead of this new one. Now ALL receipts
   always render through this template so the design is consistent.
   ═══════════════════════════════════════════════════════════════ */

/* ── Helper: check if a datetime string is valid and not zero ── */
function is_valid_datetime($str) {
    if (empty($str)) return false;
    if (in_array($str, ['0000-00-00 00:00:00', '0000-00-00', '00:00:00'])) return false;
    $ts = strtotime($str);
    return ($ts !== false && $ts > 0);
}

/* ── Build receipt number ── */
 $rawDateForRcpt = is_valid_datetime($payment['paid_at']) ? $payment['paid_at']
                : (is_valid_datetime($payment['created_at']) ? $payment['created_at'] : 'now');
 $receiptNumber = 'RCP-' . date('Ymd', strtotime($rawDateForRcpt)) . '-' . strtoupper(substr(md5($payment['order_id'] . $payment['id']), 0, 6));

/* ── Resolve the correct paid-at timestamp ── */
 $paidAt = null;
if (is_valid_datetime($payment['paid_at'])) {
    $paidAt = $payment['paid_at'];
} elseif (is_valid_datetime($payment['created_at'])) {
    $paidAt = $payment['created_at'];
} else {
    $paidAt = date('Y-m-d H:i:s');
}

 $paidDate = date('d F Y', strtotime($paidAt));
 $paidTime = date('h:i A', strtotime($paidAt));

/* ── Fetch user info ── */
 $uStmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
 $uStmt->bind_param("i", $payment['user_id']);
 $uStmt->execute();
 $user = $uStmt->get_result()->fetch_assoc();
 $uStmt->close();

 $userName = 'User';
if ($user) {
    foreach (['name', 'fullname', 'full_name', 'display_name', 'username', 'first_name'] as $col) {
        if (!empty($user[$col])) { $userName = trim($user[$col]); break; }
    }
}
 $userEmail = $user['email'] ?? '';

 $discount = floatval($payment['discount_amount']);
 $hasDiscountOrVoucher = ($discount > 0 || !empty($payment['voucher_code']));

 $originalAmount = floatval($payment['original_amount']);
 $finalAmount    = floatval($payment['final_amount']);

 $toyyibTxId  = $payment['toyyibpay_transaction_id'] ?? '—';
 $isPlanPayment = ($payment['type'] ?? '') === 'plan';
 $orderItem   = $payment['item_name'] ?: ($isPlanPayment ? 'Subscription Plan' : 'Assignment Review');
 $voucherCode = !empty($payment['voucher_code']) ? strtoupper($payment['voucher_code']) : '';

/* ── Category / brand labeling differs for plan vs assignment receipts ── */
 $receiptCategory = $isPlanPayment ? 'Subscription Payment' : 'Assignment Payment';
 $receiptBrand    = $isPlanPayment ? 'AI Checker Subscription' : 'AI Checker Assignment';
 $backLink        = $isPlanPayment ? 'plan.php' : 'assignments.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Receipt - <?php echo htmlspecialchars($receiptNumber); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    *, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }

    @page {
        size: landscape;
        margin: 0;
    }

    body {
        font-family: 'Inter', sans-serif;
        color: #1a1a2e;
        background: #e8eaf0;
        background-image:
            radial-gradient(ellipse at 30% 40%, rgba(91,33,182,0.06) 0%, transparent 60%),
            radial-gradient(ellipse at 80% 70%, rgba(124,58,237,0.04) 0%, transparent 50%);
        min-height: 100vh;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 24px 20px 40px;
    }

    /* ── Landscape receipt paper ── */
    .receipt-paper {
        width: 100%;
        max-width: 820px;
        background: #ffffff;
        position: relative;
        border-radius: 6px;
        box-shadow:
            0 1px 3px rgba(0,0,0,0.05),
            0 8px 30px rgba(0,0,0,0.07),
            0 30px 60px rgba(91,33,182,0.05);
        overflow: hidden;
    }

    /* ── Zigzag tear edges ── */
    .receipt-paper::before {
        content: '';
        position: absolute;
        top: -8px;
        left: 0;
        right: 0;
        height: 16px;
        background: radial-gradient(circle at 8px -0px, transparent 8px, #fff 8px);
        background-size: 16px 16px;
        z-index: 2;
    }
    .receipt-paper::after {
        content: '';
        position: absolute;
        bottom: -8px;
        left: 0;
        right: 0;
        height: 16px;
        background: radial-gradient(circle at 8px 16px, transparent 8px, #fff 8px);
        background-size: 16px 16px;
        z-index: 2;
    }

    .receipt-inner {
        padding: 24px 32px 20px;
    }

    /* ── TOP HEADER BAR ── */
    .rcpt-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 18px;
        padding-bottom: 16px;
        border-bottom: 2px dashed #d1d5db;
    }
    .rcpt-header-left {
        display: flex;
        align-items: center;
        gap: 14px;
    }
    .rcpt-logo {
        width: 48px;
        height: 48px;
        border-radius: 12px;
        object-fit: cover;
        box-shadow: 0 2px 8px rgba(91,33,182,0.15);
        flex-shrink: 0;
    }
    .rcpt-brand {
        font-size: 1.05rem;
        font-weight: 800;
        color: #1a1a2e;
        letter-spacing: -0.02em;
        line-height: 1.15;
    }
    .rcpt-brand-sub {
        font-size: 0.58rem;
        font-weight: 600;
        color: #7c3aed;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        margin-top: 1px;
    }
    .rcpt-header-right {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 6px;
    }
    .rcpt-receipt-no {
        font-family: 'JetBrains Mono', monospace;
        font-size: 0.7rem;
        font-weight: 600;
        color: #5b21b6;
        background: #f5f3ff;
        padding: 3px 10px;
        border-radius: 4px;
        border: 1px solid #ede9fe;
    }
    .rcpt-status {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 12px;
        border-radius: 100px;
        background: #ecfdf5;
        border: 1px solid #a7f3d0;
        font-size: 0.62rem;
        font-weight: 700;
        color: #059669;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }
    .rcpt-status .dot {
        width: 5px; height: 5px;
        border-radius: 50%;
        background: #10b981;
        animation: pulse-dot 2s ease-in-out infinite;
    }
    @keyframes pulse-dot {
        0%, 100% { opacity: 1; transform: scale(1); }
        50% { opacity: 0.5; transform: scale(0.8); }
    }

    /* ── MAIN BODY: 2-column grid ── */
    .rcpt-body {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 0 32px;
        margin-bottom: 16px;
    }

    /* ── Left column: details ── */
    .rcpt-col-left {}
    .rcpt-section-title {
        font-size: 0.58rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.14em;
        color: #9ca3af;
        margin-bottom: 8px;
        padding-bottom: 5px;
        border-bottom: 1px solid #f3f4f6;
    }
    .rcpt-row {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        padding: 4px 0;
    }
    .rcpt-category-badge {
        display: inline-block;
        margin-left: 6px;
        padding: 2px 8px;
        border-radius: 4px;
        background: #f5f3ff;
        border: 1px solid #ede9fe;
        color: #7c3aed;
        font-size: 0.55rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        vertical-align: middle;
    }
    .rcpt-row .lbl {
        font-size: 0.68rem;
        font-weight: 500;
        color: #9ca3af;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        flex-shrink: 0;
        min-width: 90px;
    }
    .rcpt-row .val {
        font-size: 0.76rem;
        font-weight: 600;
        color: #1a1a2e;
        text-align: right;
        word-break: break-all;
    }
    .rcpt-row .val.mono {
        font-family: 'JetBrains Mono', monospace;
        font-size: 0.64rem;
        color: #6b7280;
    }

    /* ── Right column: line items ── */
    .rcpt-col-right {
        border-left: 1px dashed #e5e7eb;
        padding-left: 32px;
    }
    .rcpt-line-header {
        display: flex;
        justify-content: space-between;
        padding-bottom: 5px;
        border-bottom: 1px solid #e5e7eb;
        margin-bottom: 4px;
    }
    .rcpt-line-header span {
        font-size: 0.58rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        color: #9ca3af;
    }
    .rcpt-line-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 6px 0;
    }
    .rcpt-line-row .item-name {
        font-size: 0.78rem;
        font-weight: 600;
        color: #1a1a2e;
    }
    .rcpt-line-row .item-qty {
        font-size: 0.6rem;
        color: #9ca3af;
        font-weight: 500;
    }
    .rcpt-line-row .item-price {
        font-family: 'JetBrains Mono', monospace;
        font-size: 0.78rem;
        font-weight: 600;
        color: #1a1a2e;
    }

    /* ── Adjustment rows ── */
    .rcpt-adjust-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 4px 0;
    }
    .rcpt-adjust-row .adj-label {
        font-size: 0.68rem;
        font-weight: 500;
        color: #6b7280;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .rcpt-adjust-row .adj-label .badge {
        font-family: 'JetBrains Mono', monospace;
        font-size: 0.56rem;
        font-weight: 700;
        background: #ecfdf5;
        color: #059669;
        padding: 1px 6px;
        border-radius: 3px;
        border: 1px solid #a7f3d0;
    }
    .rcpt-adjust-row .adj-value {
        font-family: 'JetBrains Mono', monospace;
        font-size: 0.74rem;
        font-weight: 600;
        color: #059669;
    }

    /* ── Bottom bar: total + footer ── */
    .rcpt-bottom {
        display: flex;
        align-items: stretch;
        gap: 24px;
        border-top: 2px dashed #d1d5db;
        padding-top: 16px;
    }

    /* ── Total card ── */
    .rcpt-total-section {
        background: linear-gradient(135deg, #5b21b6 0%, #7c3aed 100%);
        border-radius: 10px;
        padding: 14px 22px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: flex-start;
        min-width: 220px;
        position: relative;
        overflow: hidden;
        flex-shrink: 0;
    }
    .rcpt-total-section::before {
        content: '';
        position: absolute;
        top: -16px;
        right: -16px;
        width: 60px;
        height: 60px;
        border-radius: 50%;
        background: rgba(255,255,255,0.08);
    }
    .rcpt-total-section::after {
        content: '';
        position: absolute;
        bottom: -20px;
        left: 20px;
        width: 44px;
        height: 44px;
        border-radius: 50%;
        background: rgba(255,255,255,0.05);
    }
    .rcpt-total-label {
        font-size: 0.62rem;
        font-weight: 700;
        color: rgba(255,255,255,0.65);
        text-transform: uppercase;
        letter-spacing: 0.12em;
        position: relative;
        z-index: 1;
        margin-bottom: 2px;
    }
    .rcpt-total-amount {
        font-family: 'JetBrains Mono', monospace;
        font-size: 1.45rem;
        font-weight: 700;
        color: #ffffff;
        position: relative;
        z-index: 1;
    }
    .rcpt-total-amount .currency {
        font-size: 0.8rem;
        font-weight: 500;
        opacity: 0.65;
        margin-right: 2px;
    }

    /* ── Footer area (right of total) ── */
    .rcpt-footer {
        flex: 1;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        align-items: flex-end;
        text-align: right;
        padding: 2px 0;
    }
    .rcpt-footer-msg {
        font-size: 0.72rem;
        font-weight: 600;
        color: #6b7280;
        margin-bottom: 2px;
    }
    .rcpt-footer-sub {
        font-size: 0.58rem;
        color: #9ca3af;
        line-height: 1.5;
    }
    .rcpt-footer-bottom {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-top: 6px;
    }
    .rcpt-barcode {
        display: flex;
        align-items: flex-end;
        gap: 1.2px;
        height: 26px;
        opacity: 0.18;
    }
    .rcpt-barcode span {
        display: block;
        width: 2px;
        background: #1a1a2e;
        border-radius: 1px;
    }
    .rcpt-footer-brand {
        font-size: 0.52rem;
        font-weight: 700;
        color: #c4b5fd;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        white-space: nowrap;
    }

    /* ── Action buttons ── */
    .no-print {
        margin-top: 24px;
        display: flex;
        gap: 10px;
        justify-content: center;
        flex-wrap: wrap;
    }
    .btn-print {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 11px 26px;
        border: none;
        border-radius: 10px;
        background: linear-gradient(135deg, #5b21b6, #7c3aed);
        color: #fff;
        font-family: 'Inter', sans-serif;
        font-size: 0.8rem;
        font-weight: 700;
        cursor: pointer;
        box-shadow: 0 4px 14px rgba(91,33,182,0.3);
        transition: all 0.2s ease;
    }
    .btn-print:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 20px rgba(91,33,182,0.4);
    }
    .btn-print:active { transform: translateY(0); }
    .btn-print svg { width: 15px; height: 15px; }

    .btn-download {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 11px 26px;
        border: 2px solid #e5e7eb;
        border-radius: 10px;
        background: #fff;
        color: #374151;
        font-family: 'Inter', sans-serif;
        font-size: 0.8rem;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .btn-download:hover {
        border-color: #c4b5fd;
        color: #5b21b6;
        background: #faf5ff;
    }
    .btn-download svg { width: 15px; height: 15px; }

    /* ── Print ── */
    @media print {
        body {
            background: #fff !important;
            padding: 0 !important;
            background-image: none !important;
            justify-content: flex-start !important;
        }
        .receipt-paper {
            box-shadow: none !important;
            max-width: 100% !important;
            border-radius: 0 !important;
        }
        .receipt-paper::before,
        .receipt-paper::after {
            display: none !important;
        }
        .no-print { display: none !important; }
        .rcpt-status .dot { animation: none !important; }
    }

    /* ── Responsive: stack on small screens ── */
    @media (max-width: 700px) {
        body { padding: 20px 8px 32px; }
        .receipt-inner { padding: 20px 16px 16px; }
        .rcpt-body {
            grid-template-columns: 1fr;
            gap: 16px;
        }
        .rcpt-col-right {
            border-left: none;
            border-top: 1px dashed #e5e7eb;
            padding-left: 0;
            padding-top: 14px;
        }
        .rcpt-bottom {
            flex-direction: column;
            gap: 12px;
        }
        .rcpt-total-section {
            width: 100%;
            min-width: unset;
        }
        .rcpt-footer {
            align-items: flex-start;
            text-align: left;
        }
        .rcpt-footer-bottom {
            justify-content: flex-start;
        }
    }
</style>
</head>
<body>

<div class="receipt-paper">
<div class="receipt-inner">

    <!-- ═══ HEADER BAR ═══ -->
    <div class="rcpt-header">
        <div class="rcpt-header-left">
            <img src="image/logo.png" alt="<?php echo htmlspecialchars($receiptBrand); ?>" class="rcpt-logo" onerror="this.style.display='none'">
            <div>
                <div class="rcpt-brand"><?php echo htmlspecialchars($receiptBrand); ?></div>
                <div class="rcpt-brand-sub"><?php echo $isPlanPayment ? 'Subscription Confirmation' : 'Intelligent Academic Review'; ?></div>
            </div>
        </div>
        <div class="rcpt-header-right">
            <span class="rcpt-receipt-no"><?php echo htmlspecialchars($receiptNumber); ?></span>
            <span class="rcpt-status"><span class="dot"></span> Payment Confirmed</span>
        </div>
    </div>

    <!-- ═══ TWO-COLUMN BODY ═══ -->
    <div class="rcpt-body">

        <!-- Left: Customer & Transaction -->
        <div class="rcpt-col-left">
            <div class="rcpt-section-title">Customer Details</div>
            <div class="rcpt-row">
                <span class="lbl">Name</span>
                <span class="val"><?php echo htmlspecialchars($userName); ?></span>
            </div>
            <div class="rcpt-row">
                <span class="lbl">Email</span>
                <span class="val"><?php echo htmlspecialchars($userEmail); ?></span>
            </div>

            <div class="rcpt-section-title" style="margin-top:14px;">Transaction Details</div>
            <div class="rcpt-row">
                <span class="lbl">Date</span>
                <span class="val"><?php echo $paidDate; ?></span>
            </div>
            <div class="rcpt-row">
                <span class="lbl">Time</span>
                <span class="val"><?php echo $paidTime; ?></span>
            </div>
            <div class="rcpt-row">
                <span class="lbl">Order ID</span>
                <span class="val mono"><?php echo htmlspecialchars($payment['order_id']); ?></span>
            </div>
            <div class="rcpt-row">
                <span class="lbl">Txn ID</span>
                <span class="val mono"><?php echo htmlspecialchars($toyyibTxId); ?></span>
            </div>
        </div>

        <!-- Right: Line Items -->
        <div class="rcpt-col-right">
            <div class="rcpt-section-title">Order Summary <span class="rcpt-category-badge"><?php echo htmlspecialchars($receiptCategory); ?></span></div>
            <div class="rcpt-line-header">
                <span>Description</span>
                <span>Amount</span>
            </div>
            <div class="rcpt-line-row">
                <div>
                    <div class="item-name"><?php echo htmlspecialchars($orderItem); ?></div>
                    <div class="item-qty">Qty: 1</div>
                </div>
                <div class="item-price">RM <?php echo number_format($originalAmount, 2); ?></div>
            </div>

            <?php if ($hasDiscountOrVoucher): ?>
                <?php if ($discount > 0): ?>
                <div class="rcpt-adjust-row">
                    <span class="adj-label">Discount<?php if ($voucherCode): ?> <span class="badge"><?php echo htmlspecialchars($voucherCode); ?></span><?php endif; ?></span>
                    <span class="adj-value">− RM <?php echo number_format($discount, 2); ?></span>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

    </div>

    <!-- ═══ BOTTOM: TOTAL + FOOTER ═══ -->
    <div class="rcpt-bottom">
        <div class="rcpt-total-section">
            <span class="rcpt-total-label">Total Paid</span>
            <span class="rcpt-total-amount"><span class="currency">RM</span> <?php echo number_format($finalAmount, 2); ?></span>
        </div>
        <div class="rcpt-footer">
            <div>
                <div class="rcpt-footer-msg">Thank you for your payment!</div>
                <div class="rcpt-footer-sub">This receipt serves as proof of purchase. Please keep it for your records.</div>
            </div>
            <div class="rcpt-footer-bottom">
                <div class="rcpt-barcode">
                    <?php
                    $barcodePattern = [3,1,2,1,3,2,1,1,3,1,2,3,1,2,1,1,3,2,1,3,1,2,1,1,3,1,2,3,2,1,1,3,2,1,3,1,2,1,3,2,1,1,3,2,1,3,1,2];
                    foreach ($barcodePattern as $h) {
                        echo '<span style="height:' . $h . 'px"></span>';
                    }
                    ?>
                </div>
                <div class="rcpt-footer-brand"><?php echo htmlspecialchars($receiptBrand); ?> &copy; <?php echo date('Y'); ?></div>
            </div>
        </div>
    </div>

</div>
</div>

<!-- ═══ ACTION BUTTONS ═══ -->
<div class="no-print">
    <button class="btn-print" onclick="window.print()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Print Receipt
    </button>
    <button class="btn-download" onclick="window.print()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Save as PDF
    </button>
</div>
<div class="no-print" style="margin-top:14px;">
    <a href="<?php echo htmlspecialchars($backLink); ?>" style="font-size:0.8rem;color:#7c3aed;text-decoration:none;font-weight:600;">
        &larr; <?php echo $isPlanPayment ? 'Back to Plans' : 'Back to Assignments'; ?>
    </a>
</div>

</body>
</html>