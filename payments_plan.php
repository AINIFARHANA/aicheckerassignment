<?php
session_start();
 $pageTitle = 'Subscribe Plan — AI Checker';
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
 $user_id = $_SESSION['user_id'];

/* ── ToyyibPay Config ── */
 $TP_SECRET_KEY    = isset($toyyibPaySecretKey)      ? $toyyibPaySecretKey      : 'ewn3nxcp-bsmp-l05y-po0z-gicm19764afx';
 $TP_CATEGORY_CODE = isset($subscriptionCategoryCode)  ? $subscriptionCategoryCode : 'dsxvw9mu';
 $TP_BASE_URL      = isset($toyyibPayBaseUrl)         ? $toyyibPayBaseUrl         : 'https://dev.toyyibpay.com';

 $siteUrl     = rtrim(
    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST']
    . dirname($_SERVER['SCRIPT_NAME']),
    '/\\'
 );
 $returnUrl   = $siteUrl . '/payment_callback.php';
 $callbackUrl = $siteUrl . '/payment_callback.php?server_callback=1';

/* ══════════════════════════════════════════════════════════════
   INLINE AJAX: Apply Voucher
   ══════════════════════════════════════════════════════════════ */
if (isset($_GET['action']) && $_GET['action'] === 'apply_voucher') {
    header('Content-Type: application/json');
    ob_start();

    $code   = trim($_POST['voucher_code'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);

    if (empty($code)) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Please enter a voucher code.']);
        exit;
    }
    if ($amount <= 0) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid order amount.']);
        exit;
    }

    $stmt = $conn->prepare("SELECT voucher_id, code, IFNULL(discount_amount,0) as discount_amount, IFNULL(min_amount,0) as min_amount, expiry_date, status FROM vouchers WHERE code = ? LIMIT 1");
    if (!$stmt) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
        exit;
    }
    $stmt->bind_param("s", $code);
    $stmt->execute();
    $voucher = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$voucher) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Voucher code not found.']);
        exit;
    }
    if ($voucher['status'] !== 'Active') {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'This voucher is no longer active.']);
        exit;
    }
    if (!empty($voucher['expiry_date']) && $voucher['expiry_date'] < date('Y-m-d')) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'This voucher expired on ' . date('d M Y', strtotime($voucher['expiry_date'])) . '.']);
        exit;
    }
    $minAmt = floatval($voucher['min_amount']);
    if ($minAmt > 0 && $amount < $minAmt) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'Minimum order of RM ' . number_format($minAmt, 2) . ' required.']);
        exit;
    }

    $discount = floatval($voucher['discount_amount']);
    if ($discount > $amount) $discount = $amount;
    if ($discount <= 0) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'This voucher has no valid discount.']);
        exit;
    }

    ob_end_clean();
    echo json_encode([
        'success'  => true,
        'discount' => $discount,
        'total'    => round($amount - $discount, 2),
        'message'  => 'Voucher applied! You save RM ' . number_format($discount, 2) . '.'
    ]);
    exit;
}

/* ══════════════════════════════════════════════════════════════
   INLINE AJAX: Get available vouchers for display
   ══════════════════════════════════════════════════════════════ */
if (isset($_GET['action']) && $_GET['action'] === 'get_vouchers') {
    header('Content-Type: application/json');
    ob_start();

    $vList = [];
    $vStmt = $conn->prepare("SELECT code, IFNULL(discount_amount,0) as discount_amount, IFNULL(min_amount,0) as min_amount, expiry_date FROM vouchers WHERE status = 'Active' AND (expiry_date IS NULL OR expiry_date >= CURDATE()) ORDER BY discount_amount DESC");
    if ($vStmt) {
        $vStmt->execute();
        $vResult = $vStmt->get_result();
        if ($vResult) {
            while ($v = $vResult->fetch_assoc()) {
                $vList[] = [
                    'code'            => $v['code'],
                    'discount_amount' => floatval($v['discount_amount']),
                    'min_amount'      => floatval($v['min_amount']),
                    'expiry_date'     => $v['expiry_date']
                ];
            }
        }
        $vStmt->close();
    } else {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'DB error: ' . $conn->error]);
        exit;
    }

    ob_end_clean();
    echo json_encode(['success' => true, 'vouchers' => $vList]);
    exit;
}

/* ── Validate plan_id ── */
if (!isset($_GET['plan_id']) || !ctype_digit((string)$_GET['plan_id'])) {
    $_SESSION['flash_error'] = 'Invalid plan selected.';
    header('Location: plan.php');
    exit;
}
 $plan_id = intval($_GET['plan_id']);

 $stmt = $conn->prepare("SELECT * FROM plans WHERE plan_id = ? AND status = 'Active'");
 $stmt->bind_param("i", $plan_id);
 $stmt->execute();
 $plan = $stmt->get_result()->fetch_assoc();
 $stmt->close();

if (!$plan) {
    $_SESSION['flash_error'] = 'Plan not found or unavailable.';
    header('Location: plan.php');
    exit;
}

 $paymentType  = 'plan';
 $referenceId  = $plan_id;
 $itemName     = htmlspecialchars($plan['plan_name']);
 /* NOTE: ToyyibPay's billDescription field has a hard 100-character limit.
    The plan's `description` column is a full marketing paragraph (often
    300+ characters), so sending it straight through as billDescription
    made every plan payment fail with "billDescription exceed limit".
    Use a short, guaranteed-safe description for the bill instead. */
 $itemDesc     = 'Subscription — ' . $plan['plan_name'] . ' (' . $plan['duration'] . ')';
if (mb_strlen($itemDesc) > 100) {
    $itemDesc = mb_substr($itemDesc, 0, 100);
}
 $itemDesc     = htmlspecialchars($itemDesc);
 $itemDuration = htmlspecialchars($plan['duration']);
 $itemImage    = htmlspecialchars($plan['plan_image'] ?? '');
 $itemBadge    = htmlspecialchars($plan['badge'] ?? '');
 $originalAmount = floatval($plan['price']);
 $features     = !empty($plan['features']) ? json_decode($plan['features'], true) : [];
if (!is_array($features)) $features = explode("\n", $plan['features']);

/* ── Fetch user info ── */
 $userName  = 'User';
 $userEmail = '';
 $userPhone = '';
 $uStmt = $conn->prepare("SELECT * FROM users WHERE user_id = ?");
 $uStmt->bind_param("i", $user_id);
 $uStmt->execute();
 $user = $uStmt->get_result()->fetch_assoc();
 $uStmt->close();

if ($user) {
    $userEmail = $user['email'] ?? '';
    $userPhone = $user['phone'] ?? $user['phone_number'] ?? '';
    foreach (['name','fullname','full_name','display_name','username','first_name'] as $col) {
        if (isset($user[$col]) && trim($user[$col]) !== '') {
            $userName = trim($user[$col]);
            break;
        }
    }
}

/* ── Handle AJAX POST — Create ToyyibPay Bill ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    ob_start();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) $input = $_POST;

    $voucherCode = trim($input['voucher_code'] ?? '');
    $discountAmt = floatval($input['discount_amount'] ?? 0);
    $finalAmount = floatval($input['final_amount'] ?? $originalAmount);
    $csrfToken   = $input['csrf_token'] ?? '';

    if ($csrfToken !== ($_SESSION['csrf_token'] ?? '')) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'Invalid request.']);
        exit;
    }
    if ($finalAmount <= 0) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'Invalid amount.']);
        exit;
    }

    $orderId = 'PLN_' . $user_id . '_' . $referenceId . '_' . time();

    /* ToyyibPay also caps billName at ~30 characters — cap defensively so a
       longer future plan name doesn't reproduce the same "exceed limit" error. */
    $billNameSafe = mb_strlen($itemName) > 30 ? mb_substr($itemName, 0, 30) : $itemName;

    $billData = [
        'userSecretKey'        => $TP_SECRET_KEY,
        'categoryCode'         => $TP_CATEGORY_CODE,
        'billName'             => $billNameSafe,
        'billDescription'      => $itemDesc,
        'billPriceSetting'     => 1, // 1 = fixed amount (uses billAmount below)
        'billPayorInfo'        => 0,
        'billAmount'           => (string) intval(round($finalAmount * 100)), // ToyyibPay expects amount in cents
        'billReturnUrl'        => $returnUrl . '?order_id=' . urlencode($orderId),
        'billCallbackUrl'      => $callbackUrl,
        'billExpiryMinutes'    => 60,
        'billTo'               => $userName,
        'billEmail'            => $userEmail,
        'billPhone'            => $userPhone,
        'billSplitPayment'     => 0,
        'billSplitPaymentArgs' => '',
        'billPaymentChannel'   => 0,
        'billContentEmail'     => '',
        'billChargeToCustomer' => 1
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $TP_BASE_URL . '/index.php/api/createBill');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($billData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded'
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => 'Gateway connection failed: ' . $curlErr]);
        exit;
    }

    $billResult = json_decode($response, true);

    /* ToyyibPay's createBill returns a JSON ARRAY containing one object,
       e.g. [{"BillCode":"uc5adhns"}], not a plain object. Normalize it. */
    if (is_array($billResult) && isset($billResult[0]) && is_array($billResult[0])) {
        $billResult = $billResult[0];
    }

    if (!isset($billResult['BillCode']) || empty($billResult['BillCode'])) {
        $debugParts = [];
        $debugParts[] = 'HTTP ' . $httpCode;
        if ($billResult === null) {
            $debugParts[] = 'Non-JSON response';
            $debugParts[] = 'Raw: ' . substr(strip_tags($response), 0, 300);
        } else {
            if (isset($billResult['msg']))     $debugParts[] = 'Msg: ' . $billResult['msg'];
            if (isset($billResult['error']))   $debugParts[] = 'Err: ' . $billResult['error'];
            if (isset($billResult['message'])) $debugParts[] = 'Message: ' . $billResult['message'];
            $debugParts[] = 'Full: ' . json_encode($billResult);
        }
        $errMsg = 'Failed to create bill [' . implode(' | ', $debugParts) . ']';
        ob_end_clean();
        echo json_encode(['success' => false, 'error' => $errMsg]);
        exit;
    }

    $billCode   = $billResult['BillCode'];
    $paymentUrl = $TP_BASE_URL . '/' . $billCode;

    $insSql = "INSERT INTO payment_transactions
               (user_id, order_id, type, reference_id, item_name, item_desc,
                original_amount, discount_amount, final_amount, voucher_code,
                toyyibpay_billcode, status)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";
    $insStmt = $conn->prepare($insSql);
    if ($insStmt) {
        $insStmt->bind_param("ississdddss",
            $user_id, $orderId, $paymentType, $referenceId, $itemName, $itemDesc,
            $originalAmount, $discountAmt, $finalAmount, $voucherCode, $billCode
        );
        $insStmt->execute();
        $insStmt->close();
    }

    ob_end_clean();
    echo json_encode(['success' => true, 'payment_url' => $paymentUrl, 'bill_code' => $billCode]);
    exit;
}

/* ── CSRF Token ── */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
 $csrfToken = $_SESSION['csrf_token'];

 $fmtPrice       = 'RM ' . number_format($originalAmount, 2);
 $autoDiscount   = 0;
 $effectiveTotal = $originalAmount;

/* Preserve full query string (?plan_id=X) for AJAX */
 $rawUri    = $_SERVER['REQUEST_URI'] ?? '';
 $basePart  = strtok($rawUri, '?');
 $queryPart = $_SERVER['QUERY_STRING'] ?? '';
 $pageUrl   = $basePart . ($queryPart ? '?' . $queryPart : '');

require_once 'header.php';
?>

<style>
    .payment-section{padding-top:120px;padding-bottom:80px;min-height:100vh}
    .progress-indicator{display:flex;align-items:center;justify-content:center;gap:0;margin-bottom:48px}
    .progress-step{display:flex;align-items:center;gap:10px;position:relative}
    .progress-step .step-circle{width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.9rem;border:2px solid rgba(216,143,255,0.2);color:#8B7A96;background:rgba(244,209,255,0.04);transition:all 0.4s ease}
    .progress-step.active .step-circle{background:linear-gradient(135deg,#7B3F91,#D88FFF);border-color:#D88FFF;color:#fff;box-shadow:0 0 24px rgba(216,143,255,0.4)}
    .progress-step.completed .step-circle{background:#22c55e;border-color:#22c55e;color:#fff}
    .progress-step .step-label{font-size:0.82rem;font-weight:600;color:#8B7A96;position:absolute;top:50px;white-space:nowrap}
    .progress-step.active .step-label,.progress-step.completed .step-label{color:#E0D4E8}
    .progress-connector{width:80px;height:2px;background:rgba(216,143,255,0.15);margin:0 8px}
    .progress-connector.filled{background:linear-gradient(90deg,#22c55e,#D88FFF)}
    .secure-badge{display:inline-flex;align-items:center;padding:8px 20px;background:rgba(244,209,255,0.06);border:1px solid rgba(216,143,255,0.2);border-radius:60px;color:#D88FFF;font-size:0.85rem;font-weight:600}
    .payment-title{font-weight:800;color:#fff;font-size:clamp(1.6rem,3vw,2.2rem);letter-spacing:-0.03em;margin-bottom:8px}
    .payment-subtitle{color:#8B7A96;font-size:0.95rem}
    .glass-card{background:rgba(244,209,255,0.03);backdrop-filter:blur(16px);border-radius:20px;border:1px solid rgba(255,255,255,0.06);box-shadow:0 4px 24px rgba(0,0,0,0.2);transition:border-color 0.4s,box-shadow 0.4s}
    .glass-card:hover{border-color:rgba(216,143,255,0.2)}
    .order-summary-card{padding:32px;position:sticky;top:100px}
    .os-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px}
    .os-header h5{font-weight:700;color:#fff;margin:0;font-size:1.05rem}
    .os-type-badge{padding:4px 14px;background:rgba(216,143,255,0.15);border:1px solid rgba(216,143,255,0.2);border-radius:60px;font-size:0.75rem;font-weight:600;color:#D88FFF}
    .os-item{display:flex;align-items:flex-start;gap:16px;margin-bottom:20px}
    .os-item-img{width:72px;height:72px;min-width:72px;border-radius:16px;overflow:hidden;border:2px solid rgba(216,143,255,0.2);box-shadow:0 4px 16px rgba(123,63,145,0.3)}
    .os-item-img img{width:100%;height:100%;object-fit:cover}
    .os-item-img-placeholder{width:100%;height:100%;background:linear-gradient(135deg,#7B3F91,#D88FFF);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.4rem}
    .os-item-info h6{font-weight:700;color:#fff;margin-bottom:2px;font-size:1rem}
    .os-item-info p{color:#8B7A96;font-size:0.85rem;margin:0 0 6px 0}
    .os-item-info .badge-text{display:inline-block;padding:2px 10px;border-radius:60px;font-size:0.7rem;font-weight:700;text-transform:uppercase;background:linear-gradient(135deg,#f59e0b,#fbbf24);color:#1a1126}
    .os-features{margin-bottom:16px;list-style:none;padding:0}
    .os-features li{color:#B8A5C4;font-size:0.82rem;padding:2px 0;display:flex;align-items:center;gap:8px}
    .os-features li i{color:#4ade80;font-size:0.72rem}
    .os-divider{height:1px;margin:16px 0;background:linear-gradient(90deg,transparent,rgba(216,143,255,0.2),transparent)}
    .os-price-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;font-size:0.9rem}
    .os-price-row span:first-child{color:#B8A5C4}
    .os-price-row span:last-child{color:#E0D4E8;font-weight:500}
    .discount-row span:last-child{color:#4ade80!important}
    .os-total{display:flex;justify-content:space-between;align-items:center;padding:4px 0;font-size:1.15rem;font-weight:700}
    .os-total span:first-child{color:#E0D4E8}
    .os-total span:last-child{color:#fff;font-family:'Space Grotesk',sans-serif;font-size:1.5rem}
    .os-secure-note{display:flex;align-items:center;justify-content:center;gap:6px;margin-top:20px;padding-top:16px;border-top:1px solid rgba(255,255,255,0.06);font-size:0.78rem;color:#8B7A96}
    .payment-form-card{padding:32px}
    .pf-section{margin-bottom:4px}
    .pf-section h5{font-weight:700;color:#fff;margin-bottom:16px;font-size:1rem}
    .pf-section h5 small{font-weight:400;color:#8B7A96}
    .pf-divider{height:1px;margin:20px 0;background:linear-gradient(90deg,transparent,rgba(216,143,255,0.2),transparent)}
    .pf-user-info{background:rgba(244,209,255,0.04);border:1px solid rgba(255,255,255,0.06);border-radius:12px;padding:16px 20px}
    .pf-user-row{display:flex;justify-content:space-between;align-items:center;padding:6px 0}
    .pf-user-row+.pf-user-row{border-top:1px solid rgba(255,255,255,0.06);padding-top:12px;margin-top:6px}
    .pf-label{color:#8B7A96;font-size:0.85rem}
    .pf-value{color:#E0D4E8;font-weight:500;font-size:0.9rem}
    .pm-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
    .pm-card{cursor:pointer;margin-bottom:0}
    .pm-radio{display:none}
    .pm-card-inner{display:flex;flex-direction:column;align-items:center;gap:6px;padding:16px 8px;background:rgba(244,209,255,0.04);border:1.5px solid rgba(255,255,255,0.06);border-radius:12px;transition:all 0.3s ease;text-align:center}
    .pm-card-inner i{font-size:1.3rem;color:#8B7A96;transition:all 0.3s ease}
    .pm-card-inner span{font-weight:600;color:#B8A5C4;font-size:0.78rem;transition:color 0.3s ease;line-height:1.2}
    .pm-radio:checked+.pm-card-inner{background:rgba(216,143,255,0.1);border-color:#D88FFF;box-shadow:0 0 20px rgba(216,143,255,0.15)}
    .pm-radio:checked+.pm-card-inner i{color:#D88FFF}
    .pm-radio:checked+.pm-card-inner span{color:#fff}
    .pm-card:hover .pm-card-inner{border-color:rgba(216,143,255,0.3);background:rgba(244,209,255,0.06)}

    /* ── Voucher Section (same as assignment) ── */
    .voucher-list{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;min-height:0}
    .voucher-chip{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:10px;cursor:pointer;background:rgba(244,209,255,0.04);border:1.5px solid rgba(255,255,255,0.06);font-size:0.8rem;font-weight:600;color:#B8A5C4;transition:all 0.25s ease;user-select:none}
    .voucher-chip:hover{border-color:rgba(216,143,255,0.4);background:rgba(244,209,255,0.08);color:#E0D4E8;transform:translateY(-1px)}
    .voucher-chip.selected{border-color:#D88FFF;background:rgba(216,143,255,0.15);color:#fff;box-shadow:0 0 16px rgba(216,143,255,0.2)}
    .voucher-chip .chip-code{font-family:monospace;letter-spacing:0.05em;font-size:0.78rem}
    .voucher-chip .chip-amount{color:#4ade80;font-weight:700;font-size:0.78rem}
    .voucher-chip .chip-req{color:#666;font-size:0.68rem;font-weight:400}
    .voucher-chip.selected .chip-req{color:rgba(255,255,255,0.5)}
    .voucher-loading-chips{display:flex;gap:8px;padding:8px 0}
    .voucher-loading-chip{width:120px;height:36px;border-radius:10px;background:rgba(244,209,255,0.04);border:1px solid rgba(255,255,255,0.06);animation:vchipPulse 1.2s ease infinite}
    @keyframes vchipPulse{0%,100%{opacity:0.4}50%{opacity:0.8}}
    .voucher-no-chips{text-align:center;padding:12px;font-size:0.78rem;color:#666;font-style:italic}
    .voucher-custom-row{display:flex;gap:10px;align-items:center}
    .voucher-or{color:#555;font-size:0.78rem;font-weight:500;white-space:nowrap;padding:0 2px}
    .voucher-input{background:rgba(244,209,255,0.04)!important;border:1.5px solid rgba(255,255,255,0.06)!important;border-radius:10px!important;color:#E0D4E8!important;padding:10px 14px!important;font-size:0.85rem!important;transition:all 0.3s ease!important;flex:1}
    .voucher-input::placeholder{color:#8B7A96!important}
    .voucher-input:focus{border-color:#D88FFF!important;box-shadow:0 0 0 3px rgba(216,143,255,0.15)!important}
    .voucher-input:disabled{opacity:0.6;cursor:not-allowed}
    .voucher-apply-btn{background:rgba(244,209,255,0.08);border:1.5px solid rgba(216,143,255,0.2);color:#D88FFF;border-radius:10px;padding:10px 20px;font-weight:600;font-size:0.85rem;white-space:nowrap;transition:all 0.3s ease}
    .voucher-apply-btn:hover{background:#7B3F91;color:#fff;border-color:#7B3F91}
    .voucher-apply-btn:disabled{opacity:0.6;cursor:not-allowed}
    .voucher-remove-btn{background:rgba(248,113,113,0.08);border:1.5px solid rgba(248,113,113,0.25);color:#f87171;border-radius:10px;padding:10px 16px;font-weight:600;font-size:0.82rem;white-space:nowrap;transition:all 0.3s ease;cursor:pointer}
    .voucher-remove-btn:hover{background:rgba(248,113,113,0.2);border-color:#f87171}
    .voucher-message{font-size:0.82rem;margin-top:8px;min-height:20px}
    .voucher-message.success{color:#4ade80}
    .voucher-message.error{color:#f87171}
    .voucher-applied-bar{display:flex;align-items:center;justify-content:space-between;padding:10px 16px;margin-bottom:12px;background:rgba(34,197,94,0.08);border:1px solid rgba(34,197,94,0.2);border-radius:10px;font-size:0.85rem;color:#4ade80;font-weight:600}
    .voucher-applied-bar i{margin-right:6px}

    .form-check-input{background-color:rgba(244,209,255,0.06);border:1.5px solid rgba(216,143,255,0.3);cursor:pointer;width:1.15em;height:1.15em}
    .form-check-input:checked{background-color:#7B3F91;border-color:#D88FFF}
    .form-check-input:focus{box-shadow:0 0 0 3px rgba(216,143,255,0.15);border-color:#D88FFF}
    .form-check-label{color:#B8A5C4;font-size:0.85rem}
    .form-check-label a{color:#D88FFF;text-decoration:none}
    .form-check-label a:hover{text-decoration:underline}
    .confirm-payment-btn{width:100%;padding:18px 32px;background:linear-gradient(135deg,#7B3F91,#D88FFF);color:#fff;border:none;border-radius:12px;font-weight:700;font-size:1.05rem;display:flex;justify-content:center;align-items:center;gap:12px;transition:all 0.4s cubic-bezier(0.4,0,0.2,1);box-shadow:0 4px 24px rgba(123,63,145,0.4);margin-top:24px;position:relative;overflow:hidden;cursor:pointer}
    .confirm-payment-btn::before{content:'';position:absolute;top:0;left:-100%;width:100%;height:100%;background:linear-gradient(90deg,transparent,rgba(244,209,255,0.2),transparent);transition:left 0.6s}
    .confirm-payment-btn:hover::before{left:100%}
    .confirm-payment-btn:hover{transform:translateY(-2px);box-shadow:0 8px 40px rgba(123,63,145,0.6),0 0 20px rgba(244,209,255,0.1);color:#fff}
    .confirm-payment-btn:disabled{opacity:0.6;cursor:not-allowed;transform:none!important}
    .btn-amount{font-family:'Space Grotesk',sans-serif;background:rgba(255,255,255,0.15);padding:4px 14px;border-radius:60px;font-size:0.88rem}
    @keyframes totalPulse{0%{transform:scale(1)}50%{transform:scale(1.08)}100%{transform:scale(1)}}
    .total-updated{animation:totalPulse 0.4s ease;color:#4ade80!important}
    .flash-error{background:rgba(248,113,113,0.1);border:1px solid rgba(248,113,113,0.3);color:#f87171;padding:12px 20px;border-radius:12px;margin-bottom:24px;font-size:0.9rem}
    .channel-note{display:flex;align-items:center;gap:6px;margin-top:10px;font-size:0.75rem;color:#8B7A96}
    .channel-note i{color:#D88FFF;font-size:0.7rem}
    @media(max-width:991px){.order-summary-card{position:static;margin-bottom:24px}}
    @media(max-width:767px){.pm-grid{grid-template-columns:repeat(2,1fr)}}
    @media(max-width:575px){.payment-section{padding-top:110px}.order-summary-card,.payment-form-card{padding:24px 20px}.pm-grid{grid-template-columns:1fr 1fr;gap:8px}.pm-card-inner{padding:12px 6px}.pm-card-inner span{font-size:0.72rem}.confirm-payment-btn{flex-direction:column;gap:4px;padding:16px 24px}.progress-connector{width:40px}.progress-step .step-label{font-size:0.7rem}.voucher-custom-row{flex-direction:column}.voucher-or{display:none}}
</style>

<section class="payment-section">
    <div class="container">

        <?php if (isset($_SESSION['flash_error'])):
            $err = htmlspecialchars($_SESSION['flash_error']);
            unset($_SESSION['flash_error']);
        ?>
            <div class="flash-error"><i class="fa-solid fa-circle-exclamation me-2"></i><?php echo $err; ?></div>
        <?php endif; ?>

        <div class="progress-indicator" data-aos="fade-down">
            <div class="progress-step completed">
                <div class="step-circle"><i class="fa-solid fa-check" style="font-size:0.8rem"></i></div>
                <span class="step-label">Plan Selection</span>
            </div>
            <div class="progress-connector filled"></div>
            <div class="progress-step active">
                <div class="step-circle">2</div>
                <span class="step-label">Payment</span>
            </div>
            <div class="progress-connector"></div>
            <div class="progress-step">
                <div class="step-circle">3</div>
                <span class="step-label">Success</span>
            </div>
            <div class="progress-connector"></div>
            <div class="progress-step">
                <div class="step-circle">4</div>
                <span class="step-label">Receipt</span>
            </div>
        </div>

        <div class="text-center mb-5" data-aos="fade-up">
            <div class="secure-badge mb-3"><i class="fa-solid fa-shield-halved me-2"></i>Secure Payment via ToyyibPay</div>
            <h1 class="payment-title">Subscribe to <?php echo $itemName; ?></h1>
            <p class="payment-subtitle">Review your plan and proceed to the secure payment gateway</p>
        </div>

        <div class="row g-4 justify-content-center">

            <!-- ORDER SUMMARY -->
            <div class="col-lg-5" data-aos="fade-right" data-aos-delay="100">
                <div class="glass-card order-summary-card">
                    <div class="os-header">
                        <h5><i class="fa-solid fa-receipt me-2"></i>Order Summary</h5>
                        <span class="os-type-badge">Subscription</span>
                    </div>

                    <div class="os-item">
                        <div class="os-item-img">
                            <?php if (!empty($itemImage)): ?>
                                <img src="<?php echo $itemImage; ?>" alt="<?php echo $itemName; ?>"
                                     onerror="this.parentElement.innerHTML='<div class=\'os-item-img-placeholder\'><i class=\'fa-solid fa-crown\'></i></div>'">
                            <?php else: ?>
                                <div class="os-item-img-placeholder"><i class="fa-solid fa-crown"></i></div>
                            <?php endif; ?>
                        </div>
                        <div class="os-item-info">
                            <h6><?php echo $itemName; ?></h6>
                            <p><?php echo $itemDuration; ?></p>
                            <?php if (!empty($itemBadge)): ?>
                                <span class="badge-text"><?php echo $itemBadge; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($features)): ?>
                        <ul class="os-features">
                            <?php foreach (array_slice($features, 0, 5) as $f): ?>
                                <?php if (trim($f) !== ''): ?>
                                    <li><i class="fa-solid fa-check"></i><span><?php echo htmlspecialchars(trim($f)); ?></span></li>
                                <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (count($features) > 5): ?>
                                <li style="color:#D88FFF;font-size:0.78rem;">+<?php echo count($features) - 5; ?> more features</li>
                            <?php endif; ?>
                        </ul>
                    <?php endif; ?>

                    <div class="os-divider"></div>

                    <div class="os-pricing">
                        <div class="os-price-row">
                            <span>Plan Price</span>
                            <span id="originalDisplay"><?php echo $fmtPrice; ?></span>
                        </div>
                        <div class="os-price-row discount-row" id="discountRow" style="display:none;">
                            <span>Voucher Discount</span>
                            <span id="discountDisplay">-RM 0.00</span>
                        </div>
                    </div>

                    <div class="os-divider"></div>

                    <div class="os-total">
                        <span>Total Payment</span>
                        <span id="totalDisplay"><?php echo $fmtPrice; ?></span>
                    </div>

                    <div class="os-secure-note">
                        <i class="fa-solid fa-lock"></i>
                        <span>256-bit SSL Encrypted · Powered by ToyyibPay</span>
                    </div>
                </div>
            </div>

            <!-- PAYMENT FORM -->
            <div class="col-lg-6" data-aos="fade-left" data-aos-delay="200">
                <div class="glass-card payment-form-card">

                    <div class="pf-section">
                        <h5><i class="fa-solid fa-user me-2"></i>Account Information</h5>
                        <div class="pf-user-info">
                            <div class="pf-user-row">
                                <span class="pf-label">Name</span>
                                <span class="pf-value"><?php echo htmlspecialchars($userName); ?></span>
                            </div>
                            <div class="pf-user-row">
                                <span class="pf-label">Email</span>
                                <span class="pf-value"><?php echo htmlspecialchars($userEmail); ?></span>
                            </div>
                            <?php if (!empty($userPhone)): ?>
                            <div class="pf-user-row">
                                <span class="pf-label">Phone</span>
                                <span class="pf-value"><?php echo htmlspecialchars($userPhone); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="pf-divider"></div>

                    <div class="pf-section">
                        <h5><i class="fa-solid fa-credit-card me-2"></i>Available Payment Methods</h5>
                        <div class="pm-grid">
                            <label class="pm-card"><input type="radio" name="payment_method" value="Online Banking" class="pm-radio" checked><div class="pm-card-inner"><i class="fa-solid fa-building-columns"></i><span>Online Banking</span></div></label>
                            <label class="pm-card"><input type="radio" name="payment_method" value="FPX" class="pm-radio"><div class="pm-card-inner"><i class="fa-solid fa-right-left"></i><span>FPX</span></div></label>
                            <label class="pm-card"><input type="radio" name="payment_method" value="Credit Card" class="pm-radio"><div class="pm-card-inner"><i class="fa-solid fa-credit-card"></i><span>Credit Card</span></div></label>
                            <label class="pm-card"><input type="radio" name="payment_method" value="Debit Card" class="pm-radio"><div class="pm-card-inner"><i class="fa-regular fa-credit-card"></i><span>Debit Card</span></div></label>
                            <label class="pm-card"><input type="radio" name="payment_method" value="Touch 'n Go" class="pm-radio"><div class="pm-card-inner"><i class="fa-solid fa-hand-pointer"></i><span>Touch 'n Go</span></div></label>
                            <label class="pm-card"><input type="radio" name="payment_method" value="GrabPay" class="pm-radio"><div class="pm-card-inner"><i class="fa-solid fa-taxi"></i><span>GrabPay</span></div></label>
                            <label class="pm-card"><input type="radio" name="payment_method" value="Boost" class="pm-radio"><div class="pm-card-inner"><i class="fa-solid fa-bolt"></i><span>Boost</span></div></label>
                            <label class="pm-card"><input type="radio" name="payment_method" value="DuitNow QR" class="pm-radio"><div class="pm-card-inner"><i class="fa-solid fa-qrcode"></i><span>DuitNow QR</span></div></label>
                        </div>
                        <div class="channel-note"><i class="fa-solid fa-circle-info"></i><span>Final channel selection on the secure ToyyibPay page.</span></div>
                    </div>

                    <div class="pf-divider"></div>

                    <!-- ── VOUCHER SECTION (same as assignment) ── -->
                    <div class="pf-section">
                        <h5><i class="fa-solid fa-ticket me-2"></i>Voucher <small class="text-muted fw-normal">(Optional — click a voucher or type a code)</small></h5>

                        <div class="voucher-applied-bar" id="voucherAppliedBar" style="display:none;">
                            <span><i class="fa-solid fa-circle-check"></i><span id="appliedCodeText"></span></span>
                            <button type="button" class="voucher-remove-btn" id="removeVoucherBtn"><i class="fa-solid fa-xmark me-1"></i>Remove</button>
                        </div>

                        <div class="voucher-list" id="voucherList">
                            <div class="voucher-loading-chips"><div class="voucher-loading-chip"></div><div class="voucher-loading-chip"></div><div class="voucher-loading-chip"></div></div>
                        </div>

                        <div class="voucher-custom-row" id="voucherEntryRow">
                            <span class="voucher-or">or enter code:</span>
                            <input type="text" id="voucherCode" class="form-control voucher-input" placeholder="Type voucher code..." maxlength="50" autocomplete="off">
                            <button type="button" class="btn voucher-apply-btn" id="applyVoucherBtn">Apply</button>
                        </div>
                        <div id="voucherMessage" class="voucher-message" aria-live="polite"></div>
                    </div>

                    <div class="pf-divider"></div>

                    <div class="pf-section">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="agreeTerms" required>
                            <label class="form-check-label" for="agreeTerms">
                                I agree to the <a href="#">Terms of Service</a> and <a href="#">Privacy Policy</a>
                            </label>
                            <div class="invalid-feedback" style="color:#f87171;">You must agree before submitting.</div>
                        </div>
                    </div>

                    <button type="button" class="btn confirm-payment-btn" id="confirmBtn">
                        <span class="btn-text"><i class="fa-solid fa-lock me-2"></i>Pay with ToyyibPay</span>
                        <span class="btn-amount" id="btnAmount"><?php echo $fmtPrice; ?></span>
                        <span class="btn-loading" style="display:none;">
                            <span class="spinner-border spinner-border-sm me-2" role="status"></span>Creating Bill...
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</section>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
(function(){
    'use strict';

    var applyBtn          = document.getElementById('applyVoucherBtn'),
        removeBtn         = document.getElementById('removeVoucherBtn'),
        voucherInput      = document.getElementById('voucherCode'),
        voucherMsg        = document.getElementById('voucherMessage'),
        discountRow       = document.getElementById('discountRow'),
        discountDisp      = document.getElementById('discountDisplay'),
        totalDisp         = document.getElementById('totalDisplay'),
        btnAmount         = document.getElementById('btnAmount'),
        confirmBtn        = document.getElementById('confirmBtn'),
        agreeTerms        = document.getElementById('agreeTerms'),
        voucherList       = document.getElementById('voucherList'),
        voucherAppliedBar = document.getElementById('voucherAppliedBar'),
        appliedCodeText   = document.getElementById('appliedCodeText'),
        voucherEntryRow   = document.getElementById('voucherEntryRow');

    var originalPrice         = parseFloat('<?php echo $originalAmount; ?>'),
        currentVoucherDiscount = 0,
        appliedVoucherCode    = '',
        pageUrl               = '<?php echo addslashes($pageUrl); ?>';

    function getCurrentTotal() { return Math.max(0, originalPrice - currentVoucherDiscount); }
    function fmtRM(v)          { return 'RM ' + v.toFixed(2); }
    function addAction(action) {
        var sep = pageUrl.indexOf('?') !== -1 ? '&' : '?';
        return pageUrl + sep + 'action=' + action;
    }

    /* ── Load available vouchers ── */
    function loadVouchers() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', addAction('get_vouchers'), true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.timeout = 15000;
        xhr.onload = function() {
            console.log('[Vouchers] Status:', xhr.status, 'Response:', xhr.responseText.substring(0, 500));
            try {
                var data = JSON.parse(xhr.responseText);
            } catch(e) {
                console.error('[Vouchers] JSON parse failed. Raw:', xhr.responseText.substring(0, 300));
                voucherList.innerHTML = '<div class="voucher-no-chips">Could not load vouchers (parse error).</div>';
                return;
            }
            if (data.error) {
                voucherList.innerHTML = '<div class="voucher-no-chips" style="color:#f87171;">DB Error: ' + data.error + '</div>';
                return;
            }
            if (!data.success || !data.vouchers || data.vouchers.length === 0) {
                voucherList.innerHTML = '<div class="voucher-no-chips"><i class="fa-solid fa-ticket me-1"></i>No vouchers available at this time.</div>';
                return;
            }
            var html = '';
            data.vouchers.forEach(function(v) {
                var reqText = v.min_amount > 0 ? ' <span class="chip-req">min RM' + v.min_amount.toFixed(0) + '</span>' : '';
                html += '<div class="voucher-chip" data-code="' + v.code + '" data-amount="' + v.discount_amount + '" title="Click to apply this voucher">'
                    + '<i class="fa-solid fa-tag" style="font-size:0.7rem;color:#D88FFF;"></i>'
                    + '<span class="chip-code">' + v.code + '</span>'
                    + '<span class="chip-amount">-' + fmtRM(v.discount_amount) + '</span>'
                    + reqText
                    + '</div>';
            });
            voucherList.innerHTML = html;

            voucherList.querySelectorAll('.voucher-chip').forEach(function(chip) {
                chip.addEventListener('click', function() {
                    var code = this.dataset.code;
                    if (appliedVoucherCode === code) return;
                    applyVoucher(code);
                });
            });
        };
        xhr.onerror = function() {
            console.error('[Vouchers] Network error');
            voucherList.innerHTML = '<div class="voucher-no-chips">Could not load vouchers (network error).</div>';
        };
        xhr.ontimeout = function() {
            console.error('[Vouchers] Request timed out');
            voucherList.innerHTML = '<div class="voucher-no-chips">Could not load vouchers (timeout).</div>';
        };
        xhr.send();
    }
    loadVouchers();

    /* ── Apply voucher ── */
    function applyVoucher(code) {
        if (!code) { code = voucherInput.value.trim(); }
        if (!code) { voucherMsg.className='voucher-message error'; voucherMsg.textContent='Please enter a voucher code.'; return; }
        if (appliedVoucherCode === code) return;
        if (appliedVoucherCode && appliedVoucherCode !== code) { resetVoucher(); }

        applyBtn.disabled = true;
        applyBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        voucherList.querySelectorAll('.voucher-chip').forEach(function(c) { c.classList.toggle('selected', c.dataset.code === code); });

        var body = 'voucher_code=' + encodeURIComponent(code) + '&amount=' + originalPrice.toFixed(2);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', addAction('apply_voucher'), true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.timeout = 15000;
        xhr.onload = function() {
            console.log('[Voucher] Status:', xhr.status, 'Response:', xhr.responseText);
            try {
                var data = JSON.parse(xhr.responseText);
            } catch(e) {
                console.error('[Voucher] Parse error:', xhr.responseText);
                data = {success: false, message: 'Server error. Try again.'};
            }
            applyBtn.disabled = false;
            if (data.success) {
                voucherMsg.className = 'voucher-message success';
                voucherMsg.textContent = data.message;
                currentVoucherDiscount = parseFloat(data.discount);
                appliedVoucherCode = code;
                discountRow.style.display = 'flex';
                discountDisp.textContent = '-' + fmtRM(currentVoucherDiscount);
                var t = getCurrentTotal();
                totalDisp.textContent = fmtRM(t);
                totalDisp.classList.add('total-updated');
                setTimeout(function() { totalDisp.classList.remove('total-updated'); }, 500);
                btnAmount.textContent = fmtRM(t);
                appliedCodeText.textContent = code + ' (-' + fmtRM(currentVoucherDiscount) + ')';
                voucherAppliedBar.style.display = 'flex';
                voucherEntryRow.style.display = 'none';
                voucherList.querySelectorAll('.voucher-chip').forEach(function(c) {
                    if (c.dataset.code !== code) c.style.opacity = '0.4';
                    else c.classList.add('selected');
                });
            } else {
                voucherMsg.className = 'voucher-message error';
                voucherMsg.textContent = data.message || 'Invalid voucher.';
                applyBtn.textContent = 'Apply';
                voucherList.querySelectorAll('.voucher-chip').forEach(function(c) { c.classList.remove('selected'); });
            }
        };
        xhr.onerror = function() {
            console.error('[Voucher] XHR error');
            applyBtn.disabled = false;
            applyBtn.textContent = 'Apply';
            voucherMsg.className = 'voucher-message error';
            voucherMsg.textContent = 'Network error. Check your connection.';
        };
        xhr.send(body);
    }

    function resetVoucher() {
        currentVoucherDiscount = 0;
        appliedVoucherCode = '';
        discountRow.style.display = 'none';
        discountDisp.textContent = '-RM 0.00';
        totalDisp.textContent = fmtRM(getCurrentTotal());
        btnAmount.textContent = fmtRM(getCurrentTotal());
        voucherInput.value = '';
        voucherInput.disabled = false;
        applyBtn.disabled = false;
        applyBtn.textContent = 'Apply';
        voucherList.querySelectorAll('.voucher-chip').forEach(function(c) { c.classList.remove('selected'); c.style.opacity = ''; });
        voucherMsg.textContent = '';
        voucherMsg.className = 'voucher-message';
        voucherAppliedBar.style.display = 'none';
        voucherEntryRow.style.display = 'flex';
    }

    applyBtn.addEventListener('click', function() {
        if (voucherInput.disabled) return;
        applyVoucher(voucherInput.value.trim());
    });

    removeBtn.addEventListener('click', function() { resetVoucher(); });

    voucherInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); if (!voucherInput.disabled) applyVoucher(); }
    });

    /* ── Pay Now ── */
    confirmBtn.addEventListener('click', function() {
        if (!agreeTerms.checked) {
            agreeTerms.classList.add('is-invalid');
            agreeTerms.focus();
            Swal.fire({icon:'warning',title:'Terms Required',text:'Please agree to the Terms of Service.',background:'#150A1E',color:'#F4D1FF',confirmButtonColor:'#7B3F91',iconColor:'#ffa500'});
            return;
        }
        agreeTerms.classList.remove('is-invalid');

        var ft = getCurrentTotal();
        if (ft <= 0) {
            Swal.fire({icon:'info',title:'No Payment Needed',text:'Total is RM 0.00.',background:'#150A1E',color:'#F4D1FF',confirmButtonColor:'#7B3F91',iconColor:'#60a5fa'});
            return;
        }

        confirmBtn.disabled = true;
        document.querySelector('.btn-text').style.display = 'none';
        document.querySelector('.btn-amount').style.display = 'none';
        document.querySelector('.btn-loading').style.display = 'inline-flex';

        var payload = JSON.stringify({
            csrf_token: '<?php echo $csrfToken; ?>',
            voucher_code: appliedVoucherCode,
            discount_amount: currentVoucherDiscount.toFixed(2),
            final_amount: ft.toFixed(2)
        });

        var xhr = new XMLHttpRequest();
        xhr.open('POST', window.location.href, true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.timeout = 45000;
        xhr.onload = function() {
            console.log('[Payment] Status:', xhr.status, 'Response:', xhr.responseText.substring(0, 500));
            try {
                var data = JSON.parse(xhr.responseText);
            } catch(e) {
                console.error('[Payment] Parse error:', xhr.responseText.substring(0, 300));
                restorePayBtn();
                Swal.fire({icon:'error',title:'Server Error',text:'Invalid response from server. Check browser console.',background:'#150A1E',color:'#F4D1FF',confirmButtonColor:'#7B3F91',iconColor:'#ef4444'});
                return;
            }
            if (data.success && data.payment_url) {
                window.location.href = data.payment_url;
            } else {
                restorePayBtn();
                Swal.fire({icon:'error',title:'Payment Error',text:data.error||'Failed to create payment.',background:'#150A1E',color:'#F4D1FF',confirmButtonColor:'#7B3F91',iconColor:'#ef4444'});
            }
        };
        xhr.onerror = function() {
            console.error('[Payment] XHR error');
            restorePayBtn();
            Swal.fire({icon:'error',title:'Connection Error',text:'Could not reach payment gateway.',background:'#150A1E',color:'#F4D1FF',confirmButtonColor:'#7B3F91',iconColor:'#ef4444'});
        };
        xhr.ontimeout = function() {
            console.error('[Payment] Timeout');
            restorePayBtn();
            Swal.fire({icon:'error',title:'Timeout',text:'Payment gateway took too long to respond.',background:'#150A1E',color:'#F4D1FF',confirmButtonColor:'#7B3F91',iconColor:'#ef4444'});
        };
        xhr.send(payload);
    });

    function restorePayBtn() {
        confirmBtn.disabled = false;
        document.querySelector('.btn-text').style.display = 'inline-flex';
        document.querySelector('.btn-amount').style.display = 'inline-flex';
        document.querySelector('.btn-loading').style.display = 'none';
    }

    agreeTerms.addEventListener('change', function() {
        if (this.checked) this.classList.remove('is-invalid');
    });
})();
</script>

<?php require_once 'footer.php'; ?>