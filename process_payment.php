<?php
/* ============================================================
   PAYMENT PROCESSOR
   Validates input, generates invoice number, inserts payment,
   activates subscription, redirects to success page
   ============================================================ */
session_start();
require_once 'config.php';

/* Must be logged in */
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

/* Must be POST */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: plan.php');
    exit;
}

 $user_id = $_SESSION['user_id'];

/* ==========================================================
   1. CSRF Token Validation
   ========================================================== */
 $submittedToken = $_POST['csrf_token'] ?? '';
 $sessionToken   = $_SESSION['csrf_token'] ?? '';
if (empty($submittedToken) || !hash_equals($sessionToken, $submittedToken)) {
    $_SESSION['flash_error'] = 'Invalid security token. Please try again.';
    header('Location: plan.php');
    exit;
}
/* Rotate token after use */
 $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

/* ==========================================================
   2. Validate Plan ID
   ========================================================== */
 $plan_id = intval($_POST['plan_id'] ?? 0);
if ($plan_id <= 0) {
    $_SESSION['flash_error'] = 'Invalid plan selected.';
    header('Location: plan.php');
    exit;
}

/* Fetch and verify plan */
 $stmt = $conn->prepare("SELECT * FROM plans WHERE plan_id = ? AND status = 'Active'");
 $stmt->bind_param("i", $plan_id);
 $stmt->execute();
 $plan = $stmt->get_result()->fetch_assoc();
 $stmt->close();

if (!$plan) {
    $_SESSION['flash_error'] = 'Plan not found or no longer available.';
    header('Location: plan.php');
    exit;
}

/* ==========================================================
   3. Validate Amounts
   ========================================================== */
 $originalAmount = floatval($_POST['original_amount'] ?? 0);
 $discountAmount = floatval($_POST['discount_amount'] ?? 0);
 $planPrice      = floatval($plan['price']);

/* Ensure the submitted original amount matches the DB price */
if (abs($originalAmount - $planPrice) > 0.01) {
    $_SESSION['flash_error'] = 'Price mismatch. Please refresh and try again.';
    header('Location: payments_plan.php?plan_id=' . $plan_id);
    exit;
}

/* Validate discount is non-negative and doesn't exceed price */
if ($discountAmount < 0) $discountAmount = 0;
if ($discountAmount > $originalAmount) $discountAmount = $originalAmount;

 $totalPaid = max(0, $originalAmount - $discountAmount);

/* ==========================================================
   4. Validate Payment Method
   ========================================================== */
 $allowedMethods = [
    'Online Banking', 'Credit Card', 'Debit Card',
    'FPX', "Touch 'n Go", 'GrabPay', 'Boost', 'DuitNow QR'
];
 $paymentMethod = $_POST['payment_method'] ?? '';
if (!in_array($paymentMethod, $allowedMethods)) {
    $_SESSION['flash_error'] = 'Invalid payment method selected.';
    header('Location: payments_plan.php?plan_id=' . $plan_id);
    exit;
}

/* ==========================================================
   5. Validate Voucher (if applied)
   ========================================================== */
 $voucherCode = trim($_POST['applied_voucher'] ?? '');
if ($voucherCode !== '' && $discountAmount > 0) {
    $vCode = preg_replace('/\s+/', '', strtoupper($voucherCode));
    $stmt = $conn->prepare(
        "SELECT voucher_id, discount_amount, min_amount, expiry_date, status
         FROM vouchers WHERE code = ?"
    );
    $stmt->bind_param("s", $vCode);
    $stmt->execute();
    $vCheck = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    /* Re-validate voucher server-side to prevent tampering */
    if (!$vCheck) {
        $discountAmount = 0;
        $voucherCode    = '';
        $totalPaid      = $originalAmount;
    } elseif ($vCheck['status'] !== 'Active') {
        $discountAmount = 0;
        $voucherCode    = '';
        $totalPaid      = $originalAmount;
    } elseif ($vCheck['expiry_date'] !== null && strtotime($vCheck['expiry_date']) < strtotime('today')) {
        $discountAmount = 0;
        $voucherCode    = '';
        $totalPaid      = $originalAmount;
    } elseif ($originalAmount < floatval($vCheck['min_amount'])) {
        $discountAmount = 0;
        $voucherCode    = '';
        $totalPaid      = $originalAmount;
    } else {
        /* Ensure discount wasn't inflated */
        $maxDiscount = floatval($vCheck['discount_amount']);
        if ($discountAmount > $maxDiscount) {
            $discountAmount = $maxDiscount;
        }
        $totalPaid = max(0, $originalAmount - $discountAmount);
    }
}

/* ==========================================================
   6. Generate Unique Invoice Number
   Format: INV-YYYYMMDDNNNN (year + 6-digit sequential)
   ========================================================== */
 $year = date('Y');
 $prefix = 'INV-' . $year;

 $stmt = $conn->prepare(
    "SELECT invoice_number FROM payments WHERE invoice_number LIKE ? ORDER BY invoice_number DESC LIMIT 1"
);
 $likePattern = $prefix . '%';
 $stmt->bind_param("s", $likePattern);
 $stmt->execute();
 $lastInvoice = $stmt->get_result()->fetch_assoc();
 $stmt->close();

 $nextNum = 1;
if ($lastInvoice) {
    /* Extract numeric part after INV-YYYY */
    $parts = explode('-', $lastInvoice['invoice_number']);
    if (isset($parts[1]) && is_numeric($parts[1])) {
        $nextNum = intval($parts[1]) + 1;
    }
}
 $invoiceNumber = $prefix . str_pad($nextNum, 6, '0', STR_PAD_LEFT);

/* ==========================================================
   7. Insert Payment Record
   ========================================================== */
 $paymentStatus = 'Paid'; /* Simulated successful payment */
 $now = date('Y-m-d H:i:s');

 $stmt = $conn->prepare(
    "INSERT INTO payments
        (invoice_number, user_id, plan_id, assignment_id, amount,
         payment_method, voucher_code, discount_amount, total_paid,
         payment_status, created_at)
     VALUES
        (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?)"
);
 $stmt->bind_param("siidsssdss",
    $invoiceNumber,
    $user_id,
    $plan_id,
    $originalAmount,
    $paymentMethod,
    $voucherCode,
    $discountAmount,
    $totalPaid,
    $paymentStatus,
    $now
);

if (!$stmt->execute()) {
    $stmt->close();
    $_SESSION['flash_error'] = 'Payment processing failed. Please try again.';
    header('Location: payments_plan.php?plan_id=' . $plan_id);
    exit;
}
 $payment_id = $stmt->insert_id;
 $stmt->close();

/* ==========================================================
   8. Activate Subscription
   Parse plan duration (e.g. "1 Month", "3 Months", "1 Year")
   ========================================================== */
 $durationStr = $plan['duration'];
preg_match('/(\d+)\s*(month|year|day|week)/i', $durationStr, $matches);

 $startDate = $now;
if (!empty($matches)) {
    $num  = intval($matches[1]);
    $unit = strtolower($matches[2]);
    if ($unit === 'month') {
        $endDate = date('Y-m-d H:i:s', strtotime("+$num months", strtotime($now)));
    } elseif ($unit === 'year') {
        $endDate = date('Y-m-d H:i:s', strtotime("+$num years", strtotime($now)));
    } elseif ($unit === 'day') {
        $endDate = date('Y-m-d H:i:s', strtotime("+$num days", strtotime($now)));
    } elseif ($unit === 'week') {
        $endDate = date('Y-m-d H:i:s', strtotime("+$num weeks", strtotime($now)));
    } else {
        $endDate = date('Y-m-d H:i:s', strtotime('+1 month', strtotime($now)));
    }
} else {
    /* Default to 1 month if parsing fails */
    $endDate = date('Y-m-d H:i:s', strtotime('+1 month', strtotime($now)));
}

/* Expire any existing active subscriptions for this user */
 $stmt = $conn->prepare(
    "UPDATE user_subscriptions SET status = 'Expired' WHERE user_id = ? AND status = 'Active'"
);
 $stmt->bind_param("i", $user_id);
 $stmt->execute();
 $stmt->close();

/* Insert new active subscription */
 $stmt = $conn->prepare(
    "INSERT INTO user_subscriptions
        (user_id, plan_id, payment_id, start_date, end_date, status, created_at)
     VALUES
        (?, ?, ?, ?, ?, 'Active', ?)"
);
 $stmt->bind_param("iiisss",
    $user_id,
    $plan_id,
    $payment_id,
    $startDate,
    $endDate,
    $now
);
 $stmt->execute();
 $stmt->close();

/* ==========================================================
   9. Redirect to Success Page
   ========================================================== */
header('Location: payment_success.php?payment_id=' . $payment_id);
exit;