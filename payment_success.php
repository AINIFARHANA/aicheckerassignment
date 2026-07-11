<?php
session_start();
require_once 'config.php';

 $isServerCallback = (isset($_GET['server_callback']) && $_GET['server_callback'] == '1');

/* ── Extract Parameters ── */
if ($isServerCallback) {
    $billcode      = trim($_POST['billcode'] ?? '');
    $statusId      = trim($_POST['status_id'] ?? '');
    $transactionId = trim($_POST['transaction_id'] ?? '');
    $orderId       = trim($_POST['order_id'] ?? '');
    $msg           = trim($_POST['msg'] ?? '');
} else {
    $billcode      = trim($_GET['billcode'] ?? '');
    $statusId      = trim($_GET['status_id'] ?? '');
    $transactionId = trim($_GET['transaction_id'] ?? '');
    $orderId       = trim($_GET['order_id'] ?? '');
    $msg           = trim($_GET['msg'] ?? '');
}

/* ── Basic Validation ── */
if (empty($billcode) || $statusId === '') {
    if ($isServerCallback) { echo 'INVALID_PARAMS'; exit; }
    $_SESSION['flash_error'] = 'Invalid payment response. Missing required parameters.';
    header('Location: assignments.php');
    exit;
}

 $billcode      = preg_replace('/[^a-zA-Z0-9\-]/', '', $billcode);
 $transactionId = preg_replace('/[^a-zA-Z0-9\-_]/', '', $transactionId);
 $orderId       = preg_replace('/[^a-zA-Z0-9_\-]/', '', $orderId);
 $statusId      = intval($statusId);
 $msg           = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');

/* ── Find Payment Record ── */
 $stmt = $conn->prepare("SELECT * FROM payment_transactions WHERE toyyibpay_billcode = ? LIMIT 1");
if (!$stmt) {
    if ($isServerCallback) { echo 'DB_ERROR'; exit; }
    $_SESSION['flash_error'] = 'Database error occurred.';
    header('Location: assignments.php');
    exit;
}
 $stmt->bind_param("s", $billcode);
 $stmt->execute();
 $payment = $stmt->get_result()->fetch_assoc();
 $stmt->close();

if (!$payment) {
    if ($isServerCallback) { echo 'NOT_FOUND'; exit; }
    $_SESSION['flash_error'] = 'Payment record not found for bill code: ' . htmlspecialchars($billcode);
    header('Location: assignments.php');
    exit;
}

/* ── Prevent Duplicate Processing ── */
if ($payment['status'] === 'paid') {
    if ($isServerCallback) { echo 'OK'; exit; }
    /* Already processed — show success page and redirect */
    $_SESSION['flash_success'] = 'Payment has already been processed successfully.';
    header('Location: assignments.php');
    exit;
}

/* ── Logging Helper ── */
function logPayment($message) {
    $logDir = 'logs/';
    if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
    $logFile = $logDir . 'payment.log';
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

/* ══════════════════════════════════════════════════════════════
   PAYMENT SUCCESS (status_id = 1 → ToyyibPay: 1=success, 2=pending, 3=fail)
   ══════════════════════════════════════════════════════════════ */
if ($statusId === 1) {

    $now = date('Y-m-d H:i:s');
    $receiptNumber = 'RCP-' . date('Ymd') . '-' . strtoupper(substr(md5($payment['order_id'] . microtime(true)), 0, 6));

    /* ── 1. Update payment_transactions ── */
    $updPay = $conn->prepare("
        UPDATE payment_transactions
        SET status = 'paid',
            toyyibpay_transaction_id = ?,
            toyyibpay_payment_method = COALESCE(toyyibpay_payment_method, 'ToyyibPay'),
            paid_at = ?
        WHERE id = ? AND status != 'paid'
    ");
    if ($updPay) {
        $updPay->bind_param("ssi", $transactionId, $now, $payment['id']);
        $updPay->execute();
        $updPay->close();
    }

    /* ── 2. Update assignments ── */
    $refId    = $payment['reference_id'];
    $finalAmt = $payment['final_amount'];

    $updAssign = $conn->prepare("
        UPDATE assignments
        SET payment_status = 'paid',
            payment_date = ?,
            payment_amount = ?,
            transaction_id = ?,
            payment_method = 'ToyyibPay'
        WHERE assignment_id = ? AND user_id = ? AND payment_status = 'unpaid'
    ");
    if ($updAssign) {
        $updAssign->bind_param("sdsii", $now, $finalAmt, $transactionId, $refId, $payment['user_id']);
        $updAssign->execute();
        $updAssign->close();
    }

    /* ── 3. Get User Info ── */
    $userName  = 'User';
    $userEmail = '';
    $uStmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
    if ($uStmt) {
        $uStmt->bind_param("i", $payment['user_id']);
        $uStmt->execute();
        $user = $uStmt->get_result()->fetch_assoc();
        $uStmt->close();
        if ($user) {
            $userEmail = $user['email'] ?? '';
            foreach (['name', 'fullname', 'full_name', 'display_name', 'username', 'first_name'] as $col) {
                if (!empty($user[$col])) { $userName = trim($user[$col]); break; }
            }
        }
    }

    /* ── 4. Generate Receipt HTML ── */
    $receiptDir = 'receipts/';
    if (!is_dir($receiptDir)) { @mkdir($receiptDir, 0755, true); }

    $safeOrderId   = preg_replace('/[^a-zA-Z0-9_\-]/', '', $payment['order_id']);
    $receiptFile   = $receiptDir . 'receipt_' . $safeOrderId . '.html';

    $receiptData = [
        'receiptNumber'  => $receiptNumber,
        'userName'       => $userName,
        'userEmail'      => $userEmail,
        'itemName'       => $payment['item_name'] ?: 'Assignment Review',
        'itemDesc'       => $payment['item_desc'] ?: '',
        'originalAmount' => $payment['original_amount'],
        'discountAmount' => $payment['discount_amount'],
        'finalAmount'    => $payment['final_amount'],
        'voucherCode'    => $payment['voucher_code'],
        'transactionId'  => $transactionId,
        'billCode'       => $billcode,
        'paymentMethod'  => 'ToyyibPay',
        'paidAt'         => $now,
        'orderId'        => $payment['order_id'],
        'type'           => $payment['type'],
    ];

    $receiptHtml = buildReceiptHtml($receiptData);

    /* ── 5. Save Receipt File ── */
    @file_put_contents($receiptFile, $receiptHtml);

    /* ── 6. Save Receipt Path in DB ── */
    $updReceipt = $conn->prepare("UPDATE payment_transactions SET receipt_file = ? WHERE id = ?");
    if ($updReceipt) {
        $updReceipt->bind_param("si", $receiptFile, $payment['id']);
        $updReceipt->execute();
        $updReceipt->close();
    }

    /* ── 7. Send Receipt Email ── */
    $emailSent = false;
    $emailError = '';
    if (!empty($userEmail)) {
        $emailResult = sendReceiptEmail($userEmail, $userName, $receiptNumber, $receiptData, $receiptHtml);
        $emailSent = $emailResult['sent'];
        $emailError = $emailResult['error'];
        if (!$emailSent) {
            logPayment("EMAIL FAILED for order {$payment['order_id']}: $emailError");
        }
    }

    logPayment("PAYMENT SUCCESS: order={$payment['order_id']}, billcode=$billcode, txn=$transactionId, amount={$payment['final_amount']}, receipt=$receiptFile, email_sent=" . ($emailSent ? 'yes' : 'no'));

    /* ── Server Callback: return OK ── */
    if ($isServerCallback) { echo 'OK'; exit; }

    /* ── User Return: Show Success Page ── */
    $_SESSION['flash_success'] = 'Payment successful! Your receipt has been emailed to ' . htmlspecialchars($userEmail) . '.';
    $_SESSION['flash_receipt'] = $receiptFile;
    $_SESSION['flash_receipt_id'] = $payment['id'];
    header('Location: assignments.php');
    exit;

/* ══════════════════════════════════════════════════════════════
   PAYMENT FAILED / EXPIRED / PENDING (status_id = 3 or 2)
   ══════════════════════════════════════════════════════════════ */
} else {

    $failStatus = ($statusId === 3) ? 'failed' : 'pending';

    $updFail = $conn->prepare("UPDATE payment_transactions SET status = ? WHERE id = ? AND status = 'pending'");
    if ($updFail) {
        $updFail->bind_param("si", $failStatus, $payment['id']);
        $updFail->execute();
        $updFail->close();
    }

    logPayment("PAYMENT $failStatus: order={$payment['order_id']}, billcode=$billcode, status_id=$statusId, msg=$msg");

    if ($isServerCallback) { echo 'OK'; exit; }

    $_SESSION['flash_error'] = 'Payment ' . $failStatus . '. ' . ($msg ?: 'Your payment was not completed. Please try again.');
    header('Location: assignments.php');
    exit;
}


/* ══════════════════════════════════════════════════════════════
   RECEIPT HTML GENERATOR
   ══════════════════════════════════════════════════════════════ */
function buildReceiptHtml($d) {
    $paidDate  = date('d F Y', strtotime($d['paidAt']));
    $paidTime  = date('h:i A', strtotime($d['paidAt']));
    $discount  = floatval($d['discountAmount']);

    $voucherRow = '';
    if (!empty($d['voucherCode'])) {
        $voucherRow = '
            <tr>
                <td style="padding:10px 0;color:#6b7280;font-size:0.85rem;width:40%;">Voucher Code</td>
                <td style="padding:10px 0;text-align:right;font-weight:600;font-size:0.85rem;color:#059669;font-family:monospace;letter-spacing:0.05em;">' . htmlspecialchars(strtoupper($d['voucherCode'])) . '</td>
            </tr>';
    }

    $discountRow = '';
    if ($discount > 0) {
        $discountRow = '
            <tr>
                <td style="padding:10px 0;color:#6b7280;font-size:0.85rem;width:40%;">Discount</td>
                <td style="padding:10px 0;text-align:right;font-weight:600;font-size:0.85rem;color:#059669;">− RM ' . number_format($discount, 2) . '</td>
            </tr>';
    }

    return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Receipt - ' . htmlspecialchars($d['receiptNumber']) . '</title>
<style>
    @page { size: A4; margin: 15mm; }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        color: #1f2937; background: #f3f4f6;
        padding: 30px 20px;
        line-height: 1.5;
    }
    .receipt-wrap {
        max-width: 780px; margin: 0 auto;
        background: #ffffff;
        border-radius: 12px;
        box-shadow: 0 4px 24px rgba(0,0,0,0.08);
        overflow: hidden;
    }
    .receipt-header {
        background: linear-gradient(135deg, #5b21b6, #7c3aed, #a78bfa);
        padding: 36px 40px;
        color: #ffffff;
    }
    .receipt-header .rh-top {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 24px;
    }
    .receipt-header .rh-brand {
        display: flex; align-items: center; gap: 14px;
    }
    .receipt-header .rh-logo {
        width: 50px; height: 50px;
        background: rgba(255,255,255,0.2);
        border-radius: 14px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.4rem;
    }
    .receipt-header .rh-name { font-size: 1.3rem; font-weight: 800; letter-spacing: -0.02em; }
    .receipt-header .rh-tagline { font-size: 0.78rem; opacity: 0.8; margin-top: 2px; }
    .receipt-header .rh-badge {
        padding: 6px 18px; border-radius: 50px;
        background: rgba(255,255,255,0.2);
        font-size: 0.75rem; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.08em;
    }
    .receipt-header h2 {
        font-size: 1.6rem; font-weight: 800; margin-bottom: 4px;
    }
    .receipt-header .rh-number {
        font-family: "Courier New", monospace;
        font-size: 0.9rem; opacity: 0.85; letter-spacing: 0.05em;
    }
    .receipt-body { padding: 36px 40px; }
    .rb-section { margin-bottom: 28px; }
    .rb-section-title {
        font-size: 0.72rem; font-weight: 700;
        text-transform: uppercase; letter-spacing: 0.1em;
        color: #9ca3af; margin-bottom: 12px;
        padding-bottom: 8px;
        border-bottom: 2px solid #f3f4f6;
    }
    .rb-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0; }
    .rb-grid-item { padding: 8px 0; }
    .rb-grid-item .label { font-size: 0.78rem; color: #9ca3af; margin-bottom: 2px; }
    .rb-grid-item .value { font-size: 0.9rem; font-weight: 600; color: #1f2937; }
    .rb-item-card {
        display: flex; align-items: flex-start; gap: 16px;
        background: #f9fafb; border: 1px solid #f3f4f6;
        border-radius: 10px; padding: 16px 20px;
    }
    .rb-item-icon {
        width: 48px; height: 48px; min-width: 48px;
        background: linear-gradient(135deg, #7c3aed, #a78bfa);
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        color: #fff; font-size: 1.1rem;
    }
    .rb-item-info h4 { font-size: 0.95rem; font-weight: 700; color: #1f2937; margin-bottom: 2px; }
    .rb-item-info p { font-size: 0.8rem; color: #6b7280; }
    .rb-item-info .type-tag {
        display: inline-block; margin-top: 6px;
        padding: 2px 10px; border-radius: 50px;
        background: #ede9fe; color: #7c3aed;
        font-size: 0.7rem; font-weight: 600;
        text-transform: capitalize;
    }
    .rb-amounts {
        background: #f9fafb; border: 1px solid #f3f4f6;
        border-radius: 10px; padding: 20px 24px;
    }
    .rb-amounts table { width: 100%; border-collapse: collapse; }
    .rb-amounts table td { padding: 8px 0; font-size: 0.88rem; }
    .rb-amounts-divider { height: 1px; background: #e5e7eb; margin: 8px 0; }
    .rb-amounts-total td { padding: 12px 0 4px !important; }
    .rb-amounts-total .total-label { font-size: 0.95rem; font-weight: 700; color: #374151; }
    .rb-amounts-total .total-value {
        font-family: "Segoe UI", sans-serif;
        font-size: 1.5rem; font-weight: 800; color: #5b21b6;
    }
    .rb-footer {
        text-align: center; padding-top: 20px;
        border-top: 2px dashed #e5e7eb; margin-top: 8px;
    }
    .rb-footer p { font-size: 0.78rem; color: #9ca3af; }
    .rb-footer .footer-brand { font-weight: 700; color: #6b7280; }
    .no-print {
        text-align: center; margin-top: 24px;
        display: flex; align-items: center; justify-content: center; gap: 12px;
    }
    .no-print button {
        padding: 12px 28px; border-radius: 10px; border: none;
        font-weight: 700; font-size: 0.88rem; cursor: pointer;
        transition: all 0.3s ease;
    }
    .no-print .btn-print {
        background: #5b21b6; color: #fff;
    }
    .no-print .btn-print:hover { background: #4c1d95; transform: translateY(-1px); }
    .no-print .btn-download {
        background: #f3f4f6; color: #374151; border: 1px solid #e5e7eb !important;
    }
    .no-print .btn-download:hover { background: #e5e7eb; }
    @media print {
        body { background: #fff; padding: 0; }
        .receipt-wrap { box-shadow: none; border-radius: 0; }
        .no-print { display: none !important; }
    }
    @media (max-width: 600px) {
        body { padding: 10px; }
        .receipt-header { padding: 24px 20px; }
        .receipt-body { padding: 24px 20px; }
        .rb-grid { grid-template-columns: 1fr; }
        .receipt-header .rh-top { flex-direction: column; align-items: flex-start; gap: 12px; }
    }
</style>
</head>
<body>

<div class="receipt-wrap">
    <!-- Header -->
    <div class="receipt-header">
        <div class="rh-top">
            <div class="rh-brand">
                <div class="rh-logo">&#x1f6e1;&#xFE0F;</div>
                <div>
                    <div class="rh-name">AI Checker</div>
                    <div class="rh-tagline">Intelligent Assignment Analysis</div>
                </div>
            </div>
            <div class="rh-badge">&#x2713; Paid</div>
        </div>
        <h2>Payment Receipt</h2>
        <div class="rh-number">' . htmlspecialchars($d['receiptNumber']) . '</div>
    </div>

    <!-- Body -->
    <div class="receipt-body">

        <!-- Payment Details -->
        <div class="rb-section">
            <div class="rb-section-title">Payment Details</div>
            <div class="rb-grid">
                <div class="rb-grid-item">
                    <div class="label">Date</div>
                    <div class="value">' . $paidDate . '</div>
                </div>
                <div class="rb-grid-item">
                    <div class="label">Time</div>
                    <div class="value">' . $paidTime . '</div>
                </div>
                <div class="rb-grid-item">
                    <div class="label">Payment Method</div>
                    <div class="value">' . htmlspecialchars($d['paymentMethod']) . '</div>
                </div>
                <div class="rb-grid-item">
                    <div class="label">Order ID</div>
                    <div class="value" style="font-family:monospace;font-size:0.82rem;">' . htmlspecialchars($d['orderId']) . '</div>
                </div>
            </div>
        </div>

        <!-- Customer Info -->
        <div class="rb-section">
            <div class="rb-section-title">Customer Information</div>
            <div class="rb-grid">
                <div class="rb-grid-item">
                    <div class="label">Name</div>
                    <div class="value">' . htmlspecialchars($d['userName']) . '</div>
                </div>
                <div class="rb-grid-item">
                    <div class="label">Email</div>
                    <div class="value">' . htmlspecialchars($d['userEmail']) . '</div>
                </div>
            </div>
        </div>

        <!-- Item Purchased -->
        <div class="rb-section">
            <div class="rb-section-title">Item Purchased</div>
            <div class="rb-item-card">
                <div class="rb-item-icon">&#x128196;</div>
                <div class="rb-item-info">
                    <h4>' . htmlspecialchars($d['itemName']) . '</h4>
                    ' . ($d['itemDesc'] ? '<p>' . htmlspecialchars($d['itemDesc']) . '</p>' : '') . '
                    <span class="type-tag">' . htmlspecialchars($d['type']) . ' Check</span>
                </div>
            </div>
        </div>

        <!-- Amount Breakdown -->
        <div class="rb-section">
            <div class="rb-section-title">Amount Breakdown</div>
            <div class="rb-amounts">
                <table>
                    <tr>
                        <td style="padding:10px 0;color:#6b7280;font-size:0.85rem;width:40%;">Original Amount</td>
                        <td style="padding:10px 0;text-align:right;font-weight:600;font-size:0.85rem;">RM ' . number_format(floatval($d['originalAmount']), 2) . '</td>
                    </tr>
                    ' . $discountRow . '
                    ' . $voucherRow . '
                </table>
                <div class="rb-amounts-divider"></div>
                <table>
                    <tr class="rb-amounts-total">
                        <td class="total-label">Total Paid</td>
                        <td class="total-value" style="text-align:right;">RM ' . number_format(floatval($d['finalAmount']), 2) . '</td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Transaction Info -->
        <div class="rb-section">
            <div class="rb-section-title">Transaction Information</div>
            <div class="rb-grid">
                <div class="rb-grid-item">
                    <div class="label">Transaction ID</div>
                    <div class="value" style="font-family:monospace;font-size:0.82rem;word-break:break-all;">' . htmlspecialchars($d['transactionId']) . '</div>
                </div>
                <div class="rb-grid-item">
                    <div class="label">Bill Code</div>
                    <div class="value" style="font-family:monospace;font-size:0.82rem;">' . htmlspecialchars($d['billCode']) . '</div>
                </div>
                <div class="rb-grid-item">
                    <div class="label">Payment Status</div>
                    <div class="value" style="color:#059669;">Paid</div>
                </div>
                <div class="rb-grid-item">
                    <div class="label">Receipt Number</div>
                    <div class="value" style="font-family:monospace;font-size:0.82rem;">' . htmlspecialchars($d['receiptNumber']) . '</div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="rb-footer">
            <p>Thank you for using our <span class="footer-brand">AI Assignment Checker System</span>.</p>
            <p style="margin-top:4px;">This receipt was auto-generated on ' . $paidDate . ' at ' . $paidTime . '.</p>
            <p style="margin-top:8px;font-size:0.7rem;color:#d1d5db;">This is a system-generated receipt. No signature is required.</p>
        </div>

    </div>
</div>

<!-- Print & Download Buttons (hidden when printing) -->
<div class="no-print">
    <button class="btn-print" onclick="window.print()">&#x1f5a8; Print Receipt</button>
    <button class="btn-download" onclick="downloadReceipt()">&#x2b07; Download</button>
</div>

<script>
function downloadReceipt() {
    var content = document.querySelector(".receipt-wrap").outerHTML;
    var html = "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><title>Receipt - ' . htmlspecialchars($d['receiptNumber']) . '</title>" +
        document.querySelector("style").outerHTML +
        "</head><body>" + content + "</body></html>";
    var blob = new Blob([html], {type: "text/html"});
    var url = URL.createObjectURL(blob);
    var a = document.createElement("a");
    a.href = url;
    a.download = "' . htmlspecialchars($d['receiptNumber']) . '.html";
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}
</script>

</body>
</html>';
}


/* ══════════════════════════════════════════════════════════════
   EMAIL SENDER
   ══════════════════════════════════════════════════════════════ */
function sendReceiptEmail($toEmail, $toName, $receiptNumber, $receiptData, $receiptHtml) {
    $sent  = false;
    $error = '';

    $siteUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];

    /* Build a simpler email-friendly receipt */
    $paidDate = date('d F Y', strtotime($receiptData['paidAt']));
    $paidTime = date('h:i A', strtotime($receiptData['paidAt']));
    $discount = floatval($receiptData['discountAmount']);

    $voucherLine = '';
    if (!empty($receiptData['voucherCode'])) {
        $voucherLine = '<tr><td style="padding:8px 0;color:#6b7280;font-size:0.85rem;">Voucher</td><td style="padding:8px 0;text-align:right;font-weight:600;font-size:0.85rem;color:#059669;font-family:monospace;">' . htmlspecialchars(strtoupper($receiptData['voucherCode'])) . '</td></tr>';
    }

    $discountLine = '';
    if ($discount > 0) {
        $discountLine = '<tr><td style="padding:8px 0;color:#6b7280;font-size:0.85rem;">Discount</td><td style="padding:8px 0;text-align:right;font-weight:600;font-size:0.85rem;color:#059669;">− RM ' . number_format($discount, 2) . '</td></tr>';
    }

    $emailBody = '<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Segoe UI,Tahoma,Geneva,Verdana,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:30px 20px;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">

<!-- Email Header -->
<tr>
<td style="background:linear-gradient(135deg,#5b21b6,#7c3aed);padding:36px 40px;color:#ffffff;">
    <table width="100%" cellpadding="0" cellspacing="0">
    <tr>
        <td>
            <div style="font-size:1.3rem;font-weight:800;">AI Checker</div>
            <div style="font-size:0.78rem;opacity:0.8;margin-top:2px;">Payment Confirmation</div>
        </td>
        <td align="right">
            <div style="padding:6px 16px;border-radius:50px;background:rgba(255,255,255,0.2);font-size:0.75rem;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;display:inline-block;">&#10003; Paid</div>
        </td>
    </tr>
    </table>
</td>
</tr>

<!-- Greeting -->
<tr>
<td style="padding:32px 40px 16px;">
    <h2 style="margin:0 0 8px;font-size:1.3rem;font-weight:700;color:#1f2937;">Payment Successful!</h2>
    <p style="margin:0;color:#6b7280;font-size:0.9rem;line-height:1.6;">Hi <strong>' . htmlspecialchars($toName) . '</strong>, your payment has been processed successfully. Below is your receipt.</p>
</td>
</tr>

<!-- Receipt Summary -->
<tr>
<td style="padding:0 40px 24px;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border:1px solid #f3f4f6;border-radius:10px;overflow:hidden;">
    <tr>
        <td style="padding:16px 20px;border-bottom:1px solid #f3f4f6;">
            <div style="font-size:0.72rem;color:#9ca3af;text-transform:uppercase;letter-spacing:0.1em;font-weight:700;margin-bottom:10px;">Receipt Summary</div>
            <table width="100%" cellpadding="0" cellspacing="0">
            <tr><td style="padding:4px 0;color:#6b7280;font-size:0.82rem;width:40%;">Receipt No.</td><td style="padding:4px 0;text-align:right;font-weight:600;font-size:0.82rem;font-family:monospace;">' . htmlspecialchars($receiptNumber) . '</td></tr>
            <tr><td style="padding:4px 0;color:#6b7280;font-size:0.82rem;">Date &amp; Time</td><td style="padding:4px 0;text-align:right;font-weight:600;font-size:0.82rem;">' . $paidDate . ', ' . $paidTime . '</td></tr>
            <tr><td style="padding:4px 0;color:#6b7280;font-size:0.82rem;">Item</td><td style="padding:4px 0;text-align:right;font-weight:600;font-size:0.82rem;">' . htmlspecialchars($receiptData['itemName']) . '</td></tr>
            <tr><td style="padding:4px 0;color:#6b7280;font-size:0.82rem;">Transaction ID</td><td style="padding:4px 0;text-align:right;font-weight:600;font-size:0.82rem;font-family:monospace;word-break:break-all;">' . htmlspecialchars($receiptData['transactionId']) . '</td></tr>
            </table>
        </td>
    </tr>
    <tr>
        <td style="padding:16px 20px;">
            <table width="100%" cellpadding="0" cellspacing="0">
            <tr><td style="padding:6px 0;color:#6b7280;font-size:0.85rem;width:40%;">Original Amount</td><td style="padding:6px 0;text-align:right;font-weight:600;font-size:0.85rem;">RM ' . number_format(floatval($receiptData['originalAmount']), 2) . '</td></tr>
            ' . $discountLine . '
            ' . $voucherLine . '
            <tr><td colspan="2" style="padding:6px 0;"><div style="height:1px;background:#e5e7eb;"></div></td></tr>
            <tr><td style="padding:10px 0 0;font-weight:700;font-size:0.95rem;color:#374151;">Total Paid</td><td style="padding:10px 0 0;text-align:right;font-weight:800;font-size:1.4rem;color:#5b21b6;">RM ' . number_format(floatval($receiptData['finalAmount']), 2) . '</td></tr>
            </table>
        </td>
    </tr>
    </table>
</td>
</tr>

<!-- CTA Button -->
<tr>
<td style="padding:0 40px 32px;text-align:center;">
    <a href="' . $siteUrl . '/assignments.php" style="display:inline-block;padding:14px 36px;background:linear-gradient(135deg,#5b21b6,#7c3aed);color:#ffffff;text-decoration:none;border-radius:10px;font-weight:700;font-size:0.9rem;">View My Assignments</a>
</td>
</tr>

<!-- Footer -->
<tr>
<td style="padding:24px 40px;background:#f9fafb;border-top:1px solid #f3f4f6;text-align:center;">
    <p style="margin:0 0 4px;font-size:0.78rem;color:#9ca3af;">Thank you for using <strong style="color:#6b7280;">AI Assignment Checker</strong></p>
    <p style="margin:0;font-size:0.7rem;color:#d1d5db;">This is an automated email. Please do not reply.</p>
</td>
</tr>

</table>
</td></tr>
</table>
</body></html>';

    $subject = 'Payment Successful - AI Assignment Checker Receipt (' . $receiptNumber . ')';
    $headers  = "From: AI Checker <noreply@" . $_SERVER['HTTP_HOST'] . ">\r\n";
    $headers .= "Reply-To: no-reply@" . $_SERVER['HTTP_HOST'] . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    try {
        $sent = @mail($toEmail, $subject, $emailBody, $headers);
        if (!$sent) {
            $error = 'mail() returned false. Check SMTP configuration in php.ini.';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
        $sent = false;
    }

    return ['sent' => $sent, 'error' => $error];
}