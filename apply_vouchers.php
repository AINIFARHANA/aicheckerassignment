<?php
ob_start();

session_start();
require_once 'config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

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

/* ── Look up voucher ── */
if (!$conn || !($conn instanceof mysqli)) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Database connection error.']);
    exit;
}

 $stmt = $conn->prepare("
    SELECT voucher_id, code, discount_amount, min_amount, expiry_date, status 
    FROM vouchers 
    WHERE code = ? 
    LIMIT 1
");
if (!$stmt) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    exit;
}
 $stmt->bind_param("s", $code);
 $stmt->execute();
 $voucher = $stmt->get_result()->fetch_assoc();
 $stmt->close();

/* Not found */
if (!$voucher) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Voucher code not found.']);
    exit;
}

/* Inactive */
if ($voucher['status'] !== 'Active') {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'This voucher is no longer active.']);
    exit;
}

/* Expired */
if (!empty($voucher['expiry_date'])) {
    $today = date('Y-m-d');
    if ($voucher['expiry_date'] < $today) {
        ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'This voucher expired on ' . date('d M Y', strtotime($voucher['expiry_date'])) . '.']);
        exit;
    }
}

/* Minimum amount not met */
 $minAmount = floatval($voucher['min_amount']);
if ($minAmount > 0 && $amount < $minAmount) {
    ob_end_clean();
    echo json_encode([
        'success' => false, 
        'message' => 'Minimum order of RM ' . number_format($minAmount, 2) . ' required for this voucher.'
    ]);
    exit;
}

/* ── Calculate discount ── */
 $discount = floatval($voucher['discount_amount']);

if ($discount > $amount) {
    $discount = $amount;
}
if ($discount <= 0) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'This voucher has no valid discount amount.']);
    exit;
}

 $total = round($amount - $discount, 2);

ob_end_clean();
echo json_encode([
    'success'  => true,
    'discount' => $discount,
    'total'    => $total,
    'message'  => 'Voucher applied! You save RM ' . number_format($discount, 2) . '.'
]);