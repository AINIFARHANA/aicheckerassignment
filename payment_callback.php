<?php
/* ============================================================
   CONFIG — AI Checker
   ============================================================ */
session_start();

 $base_url = rtrim(
    ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'sql300.infinityfree.com')
    . dirname($_SERVER['SCRIPT_NAME']),
    '/\\'
);

/* ── Derived URLs ── */
 $callback_url   = $base_url . '/payment_callback.php';
 $callback_server = $base_url . '/payment_callback.php?server_callback=1';

/* ── Database & ToyyibPay Credentials (shared config) ── */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/subscription_helpers.php';
// config.php provides: $conn, $toyyibPaySecretKey, $toyyibPayBaseUrl,
// $subscriptionCategoryCode, $assignmentCategoryCode
$toyyibPayCategoryCode = $subscriptionCategoryCode ?? '';

/* ── App Settings ── */
 $appName = 'AI Assignment Checker';

/* ── Debug array - visible by default ── */
 $debugInfo = [];
 $debugInfo[] = 'Raw Query: ' . ($_SERVER['QUERY_STRING'] ?? 'NONE');

/* ── Parse query string to handle duplicate parameters ── */
function getFirstNonEmptyParam($name) {
    global $debugInfo;
    $queryString = $_SERVER['QUERY_STRING'] ?? '';
    $pairs = explode('&', $queryString);
    $allValues = [];
    
    foreach ($pairs as $pair) {
        $parts = explode('=', $pair, 2);
        if (count($parts) >= 1) {
            $key = urldecode($parts[0]);
            $val = isset($parts[1]) ? urldecode($parts[1]) : '';
            if ($key === $name) {
                $allValues[] = $val;
            }
        }
    }
    
    $debugInfo[] = "Param '{$name}' all values: " . json_encode($allValues);
    
    foreach ($allValues as $v) {
        if ($v !== '') {
            return trim($v);
        }
    }
    return '';
}

 $isServerCallback = isset($_GET['server_callback']) && $_GET['server_callback'] == '1';
 $billCode    = getFirstNonEmptyParam('billcode');
 $statusId    = isset($_GET['status_id']) ? intval($_GET['status_id']) : 0;
 $orderId     = getFirstNonEmptyParam('order_id');
 $txnId       = getFirstNonEmptyParam('transaction_id');
 $paidAmount  = isset($_GET['amount']) ? floatval($_GET['amount']) : 0;

 $debugInfo[] = "Parsed -> billCode: '{$billCode}', statusId: {$statusId}, orderId: '{$orderId}', txnId: '{$txnId}'";

 $receiptData = null;
 $paymentSuccess = false;
 $errorMsg = '';

/* ── Helper: Check ToyyibPay bill status ── */
function checkToyyibPayStatus($billCode) {
    global $toyyibPaySecretKey, $toyyibPayBaseUrl, $debugInfo;
    
    if (empty($billCode)) {
        $debugInfo[] = 'checkToyyibPayStatus: Empty billcode';
        return 0;
    }
    
    $url = $toyyibPayBaseUrl . '/index.php/api/getBillTransactions?billCode=' . urlencode($billCode);
    $debugInfo[] = 'API URL: ' . $url;
    
    $ch = curl_init();
    if ($ch === false) {
        $debugInfo[] = 'cURL init failed';
        return -1;
    }
    
    // Always disable SSL verify for simplicity (ToyyibPay API)
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json']
    ]);
    
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    $debugInfo[] = "API Response: HTTP {$httpCode}" . ($curlError ? " Error: {$curlError}" : "");
    $debugInfo[] = "API Body: " . substr($resp ?? '', 0, 200);
    
    if ($curlError) {
        return -1;
    }
    
    $data = json_decode($resp, true);
    
    if (is_array($data) && count($data) > 0) {
        $status = intval($data[0]['billpaymentStatus'] ?? 0);
        $debugInfo[] = "API Parsed Status: {$status}";
        return $status;
    }
    
    return 0;
}

/* ── Helper: Update transaction + related tables ── */
function updateTransaction($conn, $txn, $toyyibpayTxnId, $paidAmount) {
    global $debugInfo;
    
    if (!$conn || !$txn) {
        $debugInfo[] = 'updateTransaction: Missing conn or txn';
        return false;
    }
    
    $now = date('Y-m-d H:i:s');
    $payMethod = 'Online Payment';
    $status = 'paid';
    $txnId = $txn['id'];
    
    $debugInfo[] = "Updating transaction ID {$txnId} to paid";
    
    // Update main transaction
    $sql = "UPDATE payment_transactions SET 
        status = ?, 
        toyyibpay_transaction_id = ?, 
        paid_at = ?, 
        toyyibpay_payment_method = ?
        WHERE id = ?";
    
    $debugInfo[] = "SQL: {$sql}";
    
    $stmt2 = $conn->prepare($sql);
    
    if (!$stmt2) {
        $debugInfo[] = 'Prepare failed: ' . $conn->error;
        return false;
    }
    
    $stmt2->bind_param("ssssi", $status, $toyyibpayTxnId, $now, $payMethod, $txnId);
    $result = $stmt2->execute();
    
    if (!$result) {
        $debugInfo[] = 'Execute failed: ' . $stmt2->error;
        $stmt2->close();
        return false;
    }
    
    $debugInfo[] = 'Transaction updated successfully, rows affected: ' . $stmt2->affected_rows;
    $stmt2->close();
    
    // Update related tables based on type
    $txnType = $txn['type'] ?? '';
    $referenceId = intval($txn['reference_id'] ?? 0);
    $userId = intval($txn['user_id'] ?? 0);
    
    $debugInfo[] = "Type: {$txnType}, RefID: {$referenceId}, UserID: {$userId}";
    
    if ($txnType === 'assignment' && $referenceId > 0 && $userId > 0) {
        $stmt3 = $conn->prepare("UPDATE assignments SET 
            payment_status = 'paid', 
            payment_date = NOW(), 
            payment_amount = ?, 
            transaction_id = ?, 
            payment_method = ? 
            WHERE assignment_id = ? AND user_id = ?");
        
        if ($stmt3) {
            $stmt3->bind_param("dssii", $paidAmount, $toyyibpayTxnId, $payMethod, $referenceId, $userId);
            $stmt3->execute();
            $debugInfo[] = 'Assignment updated, rows: ' . $stmt3->affected_rows;
            $stmt3->close();
        } else {
            $debugInfo[] = 'Assignment update prepare failed: ' . $conn->error;
        }
    } elseif ($txnType === 'plan' && $referenceId > 0 && $userId > 0) {
        /* Re-fetch the fresh, now-'paid' transaction row and hand it to the
           shared idempotent helper (subscription_helpers.php). Using the
           helper here (instead of inline INSERT/UPDATE) means:
           - it's safe to call again on refresh without duplicating rows
           - the same logic self-heals old broken payments from profile.php
           - it won't fatally crash this page if the insert fails for any
             reason (mysqli_report is OFF — see config.php), it just logs it */
        $freshStmt = $conn->prepare("SELECT * FROM payment_transactions WHERE id = ?");
        if ($freshStmt) {
            $freshStmt->bind_param("i", $txnId);
            $freshStmt->execute();
            $freshTxn = $freshStmt->get_result()->fetch_assoc();
            $freshStmt->close();

            if ($freshTxn) {
                $granted = ensureSubscriptionForPaidPlanTxn($conn, $freshTxn);
                $debugInfo[] = $granted
                    ? 'Subscription granted/extended via ensureSubscriptionForPaidPlanTxn()'
                    : 'ensureSubscriptionForPaidPlanTxn() returned false — check DB error above';
            } else {
                $debugInfo[] = 'Could not re-fetch transaction for subscription activation';
            }
        } else {
            $debugInfo[] = 'Re-fetch prepare failed: ' . $conn->error;
        }
    }
    
    return true;
}

/* ── Server callback: silent update ── */
if ($isServerCallback && $billCode) {
    header('Content-Type: application/json');
    
    if ($statusId == 1) {
        $stmt = $conn->prepare("SELECT * FROM payment_transactions WHERE toyyibpay_billcode = ? AND status = 'pending'");
        if ($stmt) {
            $stmt->bind_param("s", $billCode);
            $stmt->execute();
            $txn = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($txn) {
                updateTransaction($conn, $txn, $txnId, $paidAmount);
            }
        }
    }
    
    echo json_encode(['status' => 'ok']);
    exit;
}

/* ── User return: show receipt ── */
if ($billCode) {
    $debugInfo[] = '=== Processing by BillCode ===';
    
    // Try API check (non-blocking, may fail)
    $realStatus = checkToyyibPayStatus($billCode);
    $debugInfo[] = "API realStatus: {$realStatus}";
    
    // Fetch transaction from database
    $debugInfo[] = "Querying DB for billcode: '{$billCode}'";
    $stmt = $conn->prepare("SELECT * FROM payment_transactions WHERE toyyibpay_billcode = ?");
    
    if (!$stmt) {
        $debugInfo[] = 'DB Prepare FAILED: ' . $conn->error;
        $txn = false;
    } else {
        $stmt->bind_param("s", $billCode);
        if (!$stmt->execute()) {
            $debugInfo[] = 'DB Execute FAILED: ' . $stmt->error;
            $txn = false;
        } else {
            $result = $stmt->get_result();
            $txn = $result->fetch_assoc();
            $debugInfo[] = 'DB rows returned: ' . $result->num_rows;
            $stmt->close();
        }
    }

    if (!$txn) {
        // Try to find by order_id as fallback
        $debugInfo[] = "Not found by billcode, trying order_id: '{$orderId}'";
        if ($orderId) {
            $stmt2 = $conn->prepare("SELECT * FROM payment_transactions WHERE order_id = ?");
            if ($stmt2) {
                $stmt2->bind_param("s", $orderId);
                $stmt2->execute();
                $txn = $stmt2->get_result()->fetch_assoc();
                $debugInfo[] = 'Fallback query rows: ' . $stmt2->get_result()->num_rows;
                $stmt2->close();
            }
        }
        
        if (!$txn) {
            $errorMsg = 'Transaction not found. Bill Code: ' . htmlspecialchars($billCode);
            $debugInfo[] = 'FATAL: Transaction not found anywhere';
        }
    }
    
    if ($txn) {
        $debugInfo[] = "Found TXN ID: {$txn['id']}, Status: {$txn['status']}, Type: {$txn['type']}";
        
        // Determine if paid
        $isPaid = false;
        $paidSource = '';
        
        if ($txn['status'] === 'paid') {
            $isPaid = true;
            $paidSource = 'DB status';
        } elseif ($statusId === 1) {
            $isPaid = true;
            $paidSource = 'URL status_id=1';
        } elseif ($realStatus === 1) {
            $isPaid = true;
            $paidSource = 'API status=1';
        }
        
        $debugInfo[] = "isPaid: " . ($isPaid ? 'YES' : 'NO') . " (source: {$paidSource})";
        
        if ($isPaid) {
            if ($txn['status'] === 'pending') {
                $effectiveTxnId = !empty($txnId) ? $txnId : ($txn['toyyibpay_transaction_id'] ?? '');
                $effectiveAmount = $paidAmount > 0 ? $paidAmount : floatval($txn['final_amount'] ?? 0);
                
                $debugInfo[] = "Calling updateTransaction with txnId: '{$effectiveTxnId}', amount: {$effectiveAmount}";
                $updateResult = updateTransaction($conn, $txn, $effectiveTxnId, $effectiveAmount);
                
                if (!$updateResult) {
                    $errorMsg = 'Failed to update transaction. Bill Code: ' . htmlspecialchars($billCode);
                } else {
                    // Re-fetch
                    $stmt = $conn->prepare("SELECT * FROM payment_transactions WHERE id = ?");
                    $stmt->bind_param("i", $txn['id']);
                    $stmt->execute();
                    $txn = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    $debugInfo[] = "Re-fetched status: " . ($txn['status'] ?? 'NULL');
                }
            }
            
            if (empty($errorMsg) && $txn && $txn['status'] === 'paid') {
                /* Always (re-)ensure plan access here too — covers the case
                   where this exact transaction was already 'paid' from an
                   earlier visit (e.g. a previous request updated the status
                   but then failed before granting the subscription). Safe
                   to call every time: it's a no-op once already granted. */
                ensureSubscriptionForPaidPlanTxn($conn, $txn);
                $paymentSuccess = true;
                $receiptData = $txn;
            } elseif (empty($errorMsg)) {
                $errorMsg = 'Payment received but verification pending. Please refresh.';
            }
        } else {
            $statusText = 'Pending';
            if ($statusId === 3) $statusText = 'Failed';
            elseif ($realStatus === 3) $statusText = 'Failed';
            $errorMsg = "Payment not completed. Status: {$statusText}.";
        }
    }
    
} elseif ($orderId) {
    $debugInfo[] = '=== Processing by OrderID ===';
    $debugInfo[] = "Querying for order_id: '{$orderId}'";
    
    $stmt = $conn->prepare("SELECT * FROM payment_transactions WHERE order_id = ?");
    if ($stmt) {
        $stmt->bind_param("s", $orderId);
        $stmt->execute();
        $txn = $stmt->get_result()->fetch_assoc();
        $debugInfo[] = 'Rows found: ' . $stmt->get_result()->num_rows;
        $stmt->close();
        
        if ($txn) {
            $debugInfo[] = "Found TXN ID: {$txn['id']}, Status: {$txn['status']}";
            
            if ($txn['status'] === 'paid') {
                ensureSubscriptionForPaidPlanTxn($conn, $txn);
                $paymentSuccess = true;
                $receiptData = $txn;
            } elseif ($statusId === 1) {
                // Update and show receipt
                $effectiveTxnId = !empty($txnId) ? $txnId : ($txn['toyyibpay_transaction_id'] ?? '');
                $effectiveAmount = $paidAmount > 0 ? $paidAmount : floatval($txn['final_amount'] ?? 0);
                updateTransaction($conn, $txn, $effectiveTxnId, $effectiveAmount);
                
                // Re-fetch
                $stmt = $conn->prepare("SELECT * FROM payment_transactions WHERE id = ?");
                $stmt->bind_param("i", $txn['id']);
                $stmt->execute();
                $txn = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                
                if ($txn && $txn['status'] === 'paid') {
                    $paymentSuccess = true;
                    $receiptData = $txn;
                } else {
                    $errorMsg = 'Payment processing. Please refresh.';
                }
            } else {
                $errorMsg = 'Payment is still being processed.';
            }
        } else {
            $errorMsg = 'Transaction not found for order: ' . htmlspecialchars($orderId);
        }
    } else {
        $debugInfo[] = 'Prepare failed: ' . $conn->error;
        $errorMsg = 'Database error occurred.';
    }
} else {
    $debugInfo[] = 'FATAL: No billcode AND no order_id in URL';
    $errorMsg = 'Invalid callback parameters. No payment reference found.';
}

 $debugInfo[] = "FINAL: paymentSuccess=" . ($paymentSuccess ? 'YES' : 'NO');
if ($errorMsg) $debugInfo[] = "FINAL errorMsg: {$errorMsg}";

require_once 'header.php';

/* ── Format receipt values ── */
 $rNo = $rItem = $rDesc = $rType = $rMethod = $rBillCode = $rTxnId = $rPaidFormatted = '';
 $rOriginal = $rDiscount = $rFinal = 0;
 $rVoucher = '';

if ($receiptData) {
    $rNo         = 'RCP-' . str_pad($receiptData['id'], 6, '0', STR_PAD_LEFT);
    $rItem       = htmlspecialchars($receiptData['item_name'] ?? 'N/A');
    $rDesc       = htmlspecialchars($receiptData['item_desc'] ?? '');
    $rType       = ($receiptData['type'] ?? '') === 'plan' ? 'Plan Subscription' : 'Assignment Check';
    $rOriginal   = floatval($receiptData['original_amount'] ?? 0);
    $rDiscount   = floatval($receiptData['discount_amount'] ?? 0);
    $rFinal      = floatval($receiptData['final_amount'] ?? 0);
    $rVoucher    = htmlspecialchars($receiptData['voucher_code'] ?? '');
    $rBillCode   = htmlspecialchars($receiptData['toyyibpay_billcode'] ?? '');
    $rTxnId      = htmlspecialchars($receiptData['toyyibpay_transaction_id'] ?? '');
    $rMethod     = htmlspecialchars(!empty($receiptData['toyyibpay_payment_method']) ? $receiptData['toyyibpay_payment_method'] : 'Online Payment');
    $rPaidAt     = $receiptData['paid_at'] ?? '';

    if (!empty($rPaidAt)) {
        try {
            $dt = new DateTime($rPaidAt);
            $rPaidFormatted = $dt->format('d M Y, h:i A');
        } catch (Exception $e) { 
            $rPaidFormatted = $rPaidAt; 
        }
    } else {
        $rPaidFormatted = 'Just now';
    }
}
?>

<style>
    .callback-section {
        padding-top: 130px; padding-bottom: 80px; min-height: 100vh;
        display:flex; align-items:flex-start; justify-content:center;
    }

    .callback-error {
        text-align:center; max-width:520px; margin:0 auto;
        animation: fadeUp 0.5s ease;
    }
    .callback-error .err-icon {
        width:100px; height:100px; border-radius:50%;
        background:rgba(239,68,68,0.1); border:2px solid rgba(239,68,68,0.3);
        display:flex; align-items:center; justify-content:center;
        margin:0 auto 24px; font-size:2.5rem; color:#ef4444;
    }
    .callback-error h2 { color:#fff; font-weight:800; margin-bottom:8px; font-size:1.5rem; }
    .callback-error p { color:#8B7A96; font-size:0.95rem; margin-bottom:28px; line-height:1.6; }
    .callback-error .btn { padding:14px 36px; font-size:0.9rem; }
    
    /* DEBUG INFO - NOW VISIBLE BY DEFAULT */
    .debug-info {
        margin-top:24px; padding:20px; 
        background:rgba(0,0,0,0.4);
        border:1px solid rgba(255,165,0,0.4); border-radius:12px;
        text-align:left;
    }
    .debug-info h4 { 
        color:#ffa500; font-size:0.8rem; margin-bottom:12px; 
        text-transform:uppercase; letter-spacing:0.1em;
        display:flex; align-items:center; gap:8px;
    }
    .debug-info h4::before {
        content:'🔧';
    }
    .debug-info ul { list-style:none; padding:0; margin:0; }
    .debug-info li { 
        color:#ccc; font-size:0.72rem; font-family:'Courier New',monospace; 
        padding:4px 0; border-bottom:1px solid rgba(255,255,255,0.05);
        word-break:break-all;
    }
    .debug-info li:last-child { border-bottom:none; }
    .debug-info li:contains('FATAL') { color:#ff4444; }
    .debug-info li span.label { color:#888; }

    @keyframes fadeUp { from { opacity:0; transform:translateY(30px); } to { opacity:1; transform:translateY(0); } }
    @keyframes checkDraw { to { stroke-dashoffset:0; } }
    @keyframes circleDraw { to { stroke-dashoffset:0; } }
    @keyframes stampIn { from { opacity:0; transform:rotate(-25deg) scale(0.5); } to { opacity:0.45; transform:rotate(-18deg) scale(1); } }
    @keyframes confettiFall {
        0% { transform:translateY(-10px) rotate(0deg); opacity:1; }
        100% { transform:translateY(100vh) rotate(720deg); opacity:0; }
    }

    .receipt-wrapper {
        width:100%; max-width:500px; margin:0 auto;
        animation: fadeUp 0.6s cubic-bezier(0.34,1.56,0.64,1);
    }

    .success-check-wrap {
        text-align:center; margin-bottom:32px;
    }
    .success-check-svg {
        width:90px; height:90px; margin:0 auto 16px;
    }
    .success-check-svg .circle-anim {
        fill:none; stroke:#22c55e; stroke-width:3;
        stroke-dasharray:260; stroke-dashoffset:260;
        animation: circleDraw 0.6s 0.2s ease forwards;
    }
    .success-check-svg .check-anim {
        fill:none; stroke:#22c55e; stroke-width:3.5; stroke-linecap:round; stroke-linejoin:round;
        stroke-dasharray:50; stroke-dashoffset:50;
        animation: checkDraw 0.4s 0.7s ease forwards;
    }
    .success-check-wrap h2 {
        color:#fff; font-weight:800; font-size:1.6rem; margin-bottom:4px;
        animation: fadeUp 0.5s 0.8s ease both;
    }
    .success-check-wrap p {
        color:#8B7A96; font-size:0.9rem;
        animation: fadeUp 0.5s 0.9s ease both;
    }

    .receipt-paper {
        position:relative; background:#fdfcff; border-radius:20px;
        padding:40px 36px 32px; color:#1a1a2e;
        font-family:'Poppins',sans-serif;
        box-shadow:0 30px 80px rgba(0,0,0,0.4), 0 0 0 1px rgba(255,255,255,0.06);
        overflow:hidden;
        animation: fadeUp 0.6s 1s ease both;
    }
    .receipt-paper::before {
        content:''; position:absolute; top:0; left:0; right:0; height:4px;
        background:linear-gradient(90deg,#7B3F91,#D88FFF,#7B3F91);
    }

    .receipt-stamp {
        position:absolute; top:24px; right:24px;
        width:120px; height:120px;
        transform:rotate(-18deg); opacity:0; pointer-events:none;
        animation: stampIn 0.5s 1.4s ease forwards;
    }
    .receipt-stamp svg { width:100%; height:100%; }

    .r-head { text-align:center; margin-bottom:8px; padding-right:70px; }
    .r-brand { font-size:0.72rem; font-weight:700; color:#7B3F91; letter-spacing:0.18em; text-transform:uppercase; margin-bottom:4px; }
    .r-title { font-size:1.2rem; font-weight:800; color:#1a1a2e; margin-bottom:2px; }
    .r-no { font-size:0.7rem; color:#999; font-weight:500; letter-spacing:0.05em; }
    .r-type-badge {
        display:inline-block; padding:3px 14px; border-radius:60px;
        background:linear-gradient(135deg,#7B3F91,#D88FFF);
        color:#fff; font-size:0.7rem; font-weight:700; text-transform:uppercase;
        letter-spacing:0.05em; margin-bottom:16px;
    }

    .r-dash { border:none; border-top:2px dashed #e0d8ec; margin:16px 0; }

    .r-row { display:flex; justify-content:space-between; align-items:flex-start; padding:8px 0; }
    .r-row .rl { font-size:0.78rem; color:#888; font-weight:500; display:flex; align-items:center; gap:6px; flex-shrink:0; }
    .r-row .rl i { font-size:0.7rem; width:16px; text-align:center; color:#bbb; }
    .r-row .rv { font-size:0.8rem; color:#1a1a2e; font-weight:600; text-align:right; max-width:60%; word-break:break-word; }

    .r-pricing { margin:0; }
    .r-pricing .r-row .rv.discount { color:#22c55e; }

    .r-total-row {
        display:flex; justify-content:space-between; align-items:center;
        padding:12px 0 4px; margin-top:8px; border-top:2.5px solid #e0d8ec;
    }
    .r-total-row .rl { font-size:0.9rem; color:#7B3F91; font-weight:700; }
    .r-total-row .rv {
        font-size:1.5rem; color:#22c55e; font-weight:800;
        font-family:'Space Grotesk',sans-serif;
    }

    .r-footer {
        text-align:center; margin-top:20px; font-size:0.65rem; color:#bbb;
    }
    .r-footer i { margin-right:3px; }

    .receipt-actions {
        display:flex; gap:12px; margin-top:24px;
        animation: fadeUp 0.5s 1.5s ease both;
    }
    .r-action-btn {
        flex:1; display:flex; align-items:center; justify-content:center; gap:8px;
        padding:15px 20px; border:none; border-radius:14px;
        font-family:'Poppins',sans-serif; font-weight:700; font-size:0.88rem;
        cursor:pointer; transition:all 0.3s ease; letter-spacing:0.01em;
    }
    .r-action-btn:hover { transform:translateY(-2px); }
    .r-action-btn.print-btn {
        background:linear-gradient(135deg,#7B3F91,#D88FFF);
        color:#fff; box-shadow:0 4px 20px rgba(123,63,145,0.4);
    }
    .r-action-btn.print-btn:hover { box-shadow:0 8px 32px rgba(123,63,145,0.6); }
    .r-action-btn.download-btn {
        background:rgba(244,209,255,0.08); border:1.5px solid rgba(216,143,255,0.25);
        color:#D88FFF;
    }
    .r-action-btn.download-btn:hover { background:rgba(244,209,255,0.15); border-color:#D88FFF; }

    .r-nav-link {
        display:block; text-align:center; margin-top:20px;
        color:#8B7A96; font-size:0.85rem; text-decoration:none;
        transition:color 0.2s;
        animation: fadeUp 0.5s 1.6s ease both;
    }
    .r-nav-link:hover { color:#D88FFF; }
    .r-nav-link i { margin-right:4px; }

    .confetti-container {
        position:fixed; top:0; left:0; width:100%; height:100%;
        pointer-events:none; z-index:99999; overflow:hidden;
    }
    .confetti-piece {
        position:absolute; top:-10px;
        width:10px; height:10px; border-radius:2px;
        animation: confettiFall linear forwards;
    }

    @media(max-width:575px) {
        .callback-section { padding-top:110px; }
        .receipt-paper { padding:28px 22px 24px; border-radius:16px; }
        .receipt-stamp { width:90px; height:90px; top:16px; right:16px; }
        .r-head { padding-right:50px; }
        .receipt-actions { flex-direction:column; }
        .r-action-btn { padding:14px 16px; }
    }
</style>

<div class="confetti-container" id="confettiContainer"></div>

<section class="callback-section">
    <div class="container">

        <?php if (!$paymentSuccess): ?>
        <!-- ERROR STATE -->
        <div class="callback-error">
            <div class="err-icon"><i class="fa-solid fa-xmark"></i></div>
            <h2>Payment Issue</h2>
            <p><?php echo htmlspecialchars(!empty($errorMsg) ? $errorMsg : 'Unknown error occurred.'); ?></p>
            <a href="plan.php" class="btn btn-outline-purple" style="padding:14px 40px;font-size:0.9rem;">
                <i class="fa-solid fa-arrow-left me-2"></i>Back to Plans
            </a>
            
            <!-- Debug info: only shown when explicitly requested via ?debug=1 -->
            <?php if (isset($_GET['debug'])): ?>
            <div class="debug-info">
                <h4>Debug Information</h4>
                <ul>
                    <?php foreach ($debugInfo as $info): ?>
                    <li><?php echo htmlspecialchars($info); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <!-- SUCCESS STATE -->
        <div class="receipt-wrapper">

            <div class="success-check-wrap">
                <svg class="success-check-svg" viewBox="0 0 90 90">
                    <circle class="circle-anim" cx="45" cy="45" r="41"/>
                    <polyline class="check-anim" points="28,47 40,59 63,34"/>
                </svg>
                <h2>Payment Successful!</h2>
                <p>Your transaction has been confirmed and recorded.</p>
            </div>

            <div class="receipt-paper" id="receiptPaper">

                <div class="receipt-stamp">
                    <svg viewBox="0 0 200 200">
                        <defs>
                            <path id="scp" d="M 100,100 m -74,0 a 74,74 0 1,1 148,0 a 74,74 0 1,1 -148,0"/>
                        </defs>
                        <circle cx="100" cy="100" r="78" fill="none" stroke="rgba(123,63,145,0.5)" stroke-width="4"/>
                        <circle cx="100" cy="100" r="71" fill="none" stroke="rgba(123,63,145,0.2)" stroke-width="1"/>
                        <text font-size="10.5" font-weight="800" fill="rgba(123,63,145,0.55)" letter-spacing="3" font-family="sans-serif">
                            <textPath href="#scp">PAYMENT CONFIRMED &#10022; AI ASSIGNMENT CHECKER &#10022;</textPath>
                        </text>
                        <text x="100" y="94" text-anchor="middle" font-size="32" font-weight="900" fill="rgba(123,63,145,0.5)" font-family="sans-serif">PAID</text>
                        <text x="100" y="116" text-anchor="middle" font-size="9" font-weight="700" fill="rgba(123,63,145,0.35)" font-family="sans-serif">&#10003; VERIFIED</text>
                    </svg>
                </div>

                <div class="r-head">
                    <div class="r-brand">AI Assignment Checker</div>
                    <div class="r-title">Payment Receipt</div>
                    <div class="r-no"><?php echo $rNo; ?></div>
                </div>
                <div style="text-align:center;"><span class="r-type-badge"><?php echo $rType; ?></span></div>

                <hr class="r-dash">

                <div class="r-row"><span class="rl"><i class="fa-solid fa-box"></i> Item</span><span class="rv"><?php echo $rItem; ?></span></div>
                <?php if (!empty($rDesc)): ?>
                <div class="r-row"><span class="rl"><i class="fa-solid fa-align-left"></i> Description</span><span class="rv"><?php echo $rDesc; ?></span></div>
                <?php endif; ?>

                <hr class="r-dash">

                <div class="r-pricing">
                    <div class="r-row"><span class="rl"><i class="fa-solid fa-tag"></i> Original</span><span class="rv">RM <?php echo number_format($rOriginal, 2); ?></span></div>
                    <?php if ($rDiscount > 0): ?>
                    <div class="r-row"><span class="rl"><i class="fa-solid fa-percent"></i> Discount<?php if (!empty($rVoucher)) echo ' (' . $rVoucher . ')'; ?></span><span class="rv discount">-RM <?php echo number_format($rDiscount, 2); ?></span></div>
                    <?php endif; ?>
                </div>

                <div class="r-total-row">
                    <span class="rl">Total Paid</span>
                    <span class="rv">RM <?php echo number_format($rFinal, 2); ?></span>
                </div>

                <hr class="r-dash">

                <div class="r-row"><span class="rl"><i class="fa-solid fa-calendar"></i> Paid On</span><span class="rv"><?php echo $rPaidFormatted; ?></span></div>
                <div class="r-row"><span class="rl"><i class="fa-solid fa-wallet"></i> Method</span><span class="rv"><?php echo $rMethod; ?></span></div>
                <div class="r-row"><span class="rl"><i class="fa-solid fa-hashtag"></i> Bill Code</span><span class="rv" style="font-family:monospace;font-size:0.75rem;"><?php echo $rBillCode; ?></span></div>
                <?php if (!empty($rTxnId)): ?>
                <div class="r-row"><span class="rl"><i class="fa-solid fa-receipt"></i> Transaction ID</span><span class="rv" style="font-family:monospace;font-size:0.72rem;letter-spacing:0.02em;"><?php echo $rTxnId; ?></span></div>
                <?php endif; ?>

                <div class="r-footer">
                    <i class="fa-solid fa-shield-halved"></i>
                    This receipt is auto-generated. Keep it for your records.
                </div>
            </div>

            <div class="receipt-actions">
                <button class="r-action-btn print-btn" onclick="printReceipt()">
                    <i class="fa-solid fa-print"></i> Print Receipt
                </button>
                <button class="r-action-btn download-btn" onclick="downloadReceipt()">
                    <i class="fa-solid fa-download"></i> Download PDF
                </button>
            </div>

            <a href="<?php echo ($receiptData['type'] ?? '') === 'plan' ? 'plan.php' : 'assignments.php'; ?>" class="r-nav-link">
                <i class="fa-solid fa-arrow-left"></i>
                <?php echo ($receiptData['type'] ?? '') === 'plan' ? 'Back to Plans' : 'Back to Assignments'; ?>
            </a>
        </div>
        <?php endif; ?>

    </div>
</section>

<script>
(function() {
    'use strict';

    function playSuccessSound() {
        try {
            var ctx = new (window.AudioContext || window.webkitAudioContext)();
            var notes = [523.25, 659.25, 783.99, 1046.50];
            notes.forEach(function(freq, i) {
                var osc = ctx.createOscillator();
                var gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.frequency.value = freq;
                osc.type = 'sine';
                var startTime = ctx.currentTime + i * 0.16;
                gain.gain.setValueAtTime(0.25, startTime);
                gain.gain.exponentialRampToValueAtTime(0.001, startTime + 0.5);
                osc.start(startTime);
                osc.stop(startTime + 0.5);
            });
        } catch(e) {}
    }

    function launchConfetti() {
        var container = document.getElementById('confettiContainer');
        if (!container) return;
        var colors = ['#7B3F91','#D88FFF','#22c55e','#fbbf24','#60a5fa','#f472b6','#fff'];
        for (var i = 0; i < 60; i++) {
            var piece = document.createElement('div');
            piece.className = 'confetti-piece';
            piece.style.left = Math.random() * 100 + '%';
            piece.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
            piece.style.width = (Math.random() * 8 + 5) + 'px';
            piece.style.height = (Math.random() * 8 + 5) + 'px';
            piece.style.borderRadius = Math.random() > 0.5 ? '50%' : '2px';
            piece.style.animationDuration = (Math.random() * 2 + 2) + 's';
            piece.style.animationDelay = (Math.random() * 1.5) + 's';
            piece.style.opacity = '0';
            container.appendChild(piece);
        }
        setTimeout(function() { container.innerHTML = ''; }, 5000);
    }

    <?php if ($paymentSuccess): ?>
    window.addEventListener('load', function() {
        setTimeout(playSuccessSound, 1200);
        setTimeout(launchConfetti, 800);
    });
    <?php endif; ?>

    window.printReceipt = function() {
        var paper = document.getElementById('receiptPaper');
        if (!paper) return;
        var html = paper.innerHTML;
        var w = window.open('', '_blank', 'width=600,height=800');
        w.document.write('<!DOCTYPE html><html><head><title>Payment Receipt</title>');
        w.document.write('<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">');
        w.document.write('<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&family=Space+Grotesk:wght@700;800&display=swap" rel="stylesheet">');
        w.document.write('<style>*{margin:0;padding:0;box-sizing:border-box;}body{display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f0f0f0;padding:20px;font-family:Poppins,sans-serif;}.receipt-paper{position:relative;width:100%;max-width:460px;background:#fdfcff;border-radius:20px;padding:40px 36px 32px;color:#1a1a2e;box-shadow:0 4px 30px rgba(0,0,0,0.1);overflow:hidden;}.receipt-paper::before{content:"";position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,#7B3F91,#D88FFF,#7B3F91);}.receipt-stamp{position:absolute;top:24px;right:24px;width:120px;height:120px;transform:rotate(-18deg);opacity:0.45;pointer-events:none;}.receipt-stamp svg{width:100%;height:100%;}.r-head{text-align:center;margin-bottom:8px;padding-right:70px;}.r-brand{font-size:0.72rem;font-weight:700;color:#7B3F91;letter-spacing:0.18em;text-transform:uppercase;margin-bottom:4px;}.r-title{font-size:1.2rem;font-weight:800;color:#1a1a2e;margin-bottom:2px;}.r-no{font-size:0.7rem;color:#999;font-weight:500;letter-spacing:0.05em;}.r-type-badge{display:inline-block;padding:3px 14px;border-radius:60px;background:linear-gradient(135deg,#7B3F91,#D88FFF);color:#fff;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:16px;}.r-dash{border:none;border-top:2px dashed #e0d8ec;margin:16px 0;}.r-row{display:flex;justify-content:space-between;align-items:flex-start;padding:8px 0;}.r-row .rl{font-size:0.78rem;color:#888;font-weight:500;display:flex;align-items:center;gap:6px;flex-shrink:0;}.r-row .rl i{font-size:0.7rem;width:16px;text-align:center;color:#bbb;}.r-row .rv{font-size:0.8rem;color:#1a1a2e;font-weight:600;text-align:right;max-width:60%;word-break:break-word;}.r-row .rv.discount{color:#22c55e;}.r-total-row{display:flex;justify-content:space-between;align-items:center;padding:12px 0 4px;margin-top:8px;border-top:2.5px solid #e0d8ec;}.r-total-row .rl{font-size:0.9rem;color:#7B3F91;font-weight:700;}.r-total-row .rv{font-size:1.5rem;color:#22c55e;font-weight:800;font-family:"Space Grotesk",sans-serif;}.r-footer{text-align:center;margin-top:20px;font-size:0.65rem;color:#bbb;}.r-footer i{margin-right:3px;}@media print{body{background:#fff;padding:0;}.receipt-paper{box-shadow:none;border-radius:0;max-width:100%;}}</style></head><body>');
        w.document.write('<div class="receipt-paper">' + html + '</div>');
        w.document.write('</body></html>');
        w.document.close();
        setTimeout(function() { w.print(); }, 500);
    };

    window.downloadReceipt = function() {
        var paper = document.getElementById('receiptPaper');
        if (!paper) return;
        var html = paper.innerHTML;
        var w = window.open('', '_blank', 'width=600,height=800');
        w.document.write('<!DOCTYPE html><html><head><title>Payment Receipt</title>');
        w.document.write('<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">');
        w.document.write('<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800;900&family=Space+Grotesk:wght@700;800&display=swap" rel="stylesheet">');
        w.document.write('<style>*{margin:0;padding:0;box-sizing:border-box;}body{display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f0f0f0;padding:20px;font-family:Poppins,sans-serif;}.receipt-paper{position:relative;width:100%;max-width:460px;background:#fdfcff;border-radius:20px;padding:40px 36px 32px;color:#1a1a2e;box-shadow:0 4px 30px rgba(0,0,0,0.1);overflow:hidden;}.receipt-paper::before{content:"";position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,#7B3F91,#D88FFF,#7B3F91);}.receipt-stamp{position:absolute;top:24px;right:24px;width:120px;height:120px;transform:rotate(-18deg);opacity:0.45;pointer-events:none;}.receipt-stamp svg{width:100%;height:100%;}.r-head{text-align:center;margin-bottom:8px;padding-right:70px;}.r-brand{font-size:0.72rem;font-weight:700;color:#7B3F91;letter-spacing:0.18em;text-transform:uppercase;margin-bottom:4px;}.r-title{font-size:1.2rem;font-weight:800;color:#1a1a2e;margin-bottom:2px;}.r-no{font-size:0.7rem;color:#999;font-weight:500;letter-spacing:0.05em;}.r-type-badge{display:inline-block;padding:3px 14px;border-radius:60px;background:linear-gradient(135deg,#7B3F91,#D88FFF);color:#fff;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:16px;}.r-dash{border:none;border-top:2px dashed #e0d8ec;margin:16px 0;}.r-row{display:flex;justify-content:space-between;align-items:flex-start;padding:8px 0;}.r-row .rl{font-size:0.78rem;color:#888;font-weight:500;display:flex;align-items:center;gap:6px;flex-shrink:0;}.r-row .rl i{font-size:0.7rem;width:16px;text-align:center;color:#bbb;}.r-row .rv{font-size:0.8rem;color:#1a1a2e;font-weight:600;text-align:right;max-width:60%;word-break:break-word;}.r-row .rv.discount{color:#22c55e;}.r-total-row{display:flex;justify-content:space-between;align-items:center;padding:12px 0 4px;margin-top:8px;border-top:2.5px solid #e0d8ec;}.r-total-row .rl{font-size:0.9rem;color:#7B3F91;font-weight:700;}.r-total-row .rv{font-size:1.5rem;color:#22c55e;font-weight:800;font-family:"Space Grotesk",sans-serif;}.r-footer{text-align:center;margin-top:20px;font-size:0.65rem;color:#bbb;}.r-footer i{margin-right:3px;}@media print{body{background:#fff;padding:0;}.receipt-paper{box-shadow:none;border-radius:0;max-width:100%;}}</style></head><body>');
        w.document.write('<div class="receipt-paper">' + html + '</div>');
        w.document.write('<script>setTimeout(function(){ window.print(); },600);<\/script>');
        w.document.write('</body></html>');
        w.document.close();
    };
})();
</script>

<?php require_once 'footer.php'; ?>