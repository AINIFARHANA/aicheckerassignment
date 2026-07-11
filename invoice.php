<?php
/* ============================================================
   INVOICE VIEW PAGE
   Professional invoice display with download/print buttons
   ============================================================ */
session_start();
 $pageTitle = 'Invoice — AI Checker';
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

 $user_id = $_SESSION['user_id'];

/* Validate payment_id */
if (!isset($_GET['payment_id']) || !ctype_digit((string)$_GET['payment_id'])) {
    header('Location: payment_history.php');
    exit;
}
 $payment_id = intval($_GET['payment_id']);

/* Fetch payment with joins — ownership check */
 $stmt = $conn->prepare(
    "SELECT p.*, pl.plan_name, pl.duration AS plan_duration,
            u.username, u.email
     FROM payments p
     LEFT JOIN plans pl ON p.plan_id = pl.plan_id
     LEFT JOIN users u ON p.user_id = u.user_id
     WHERE p.payment_id = ? AND p.user_id = ?"
);
 $stmt->bind_param("ii", $payment_id, $user_id);
 $stmt->execute();
 $payment = $stmt->get_result()->fetch_assoc();
 $stmt->close();

if (!$payment) {
    $_SESSION['flash_error'] = 'Invoice not found.';
    header('Location: payment_history.php');
    exit;
}

/* Admin override: allow admins to view any invoice */
 $isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
if (!$isAdmin && intval($payment['user_id']) !== $user_id) {
    header('Location: payment_history.php');
    exit;
}

require_once 'header.php';
?>

<style>
    .invoice-section { padding-top:100px; padding-bottom:60px; min-height:100vh; }

    .progress-indicator { display:flex; align-items:center; justify-content:center; gap:0; margin-bottom:40px; }
    .progress-step { display:flex; align-items:center; gap:10px; position:relative; }
    .progress-step .step-circle {
        width:42px; height:42px; border-radius:50%;
        display:flex; align-items:center; justify-content:center;
        font-weight:700; font-size:0.9rem;
        border:2px solid rgba(216,143,255,0.2);
        color:#8B7A96; background:rgba(244,209,255,0.04);
    }
    .progress-step.active .step-circle {
        background:linear-gradient(135deg,#7B3F91,#D88FFF);
        border-color:#D88FFF; color:#fff;
        box-shadow:0 0 24px rgba(216,143,255,0.4);
    }
    .progress-step.completed .step-circle { background:#22c55e; border-color:#22c55e; color:#fff; }
    .progress-step .step-label {
        font-size:0.82rem; font-weight:600; color:#8B7A96;
        position:absolute; top:50px; white-space:nowrap;
    }
    .progress-step.active .step-label,
    .progress-step.completed .step-label { color:#E0D4E8; }
    .progress-connector { width:80px; height:2px; background:rgba(216,143,255,0.15); margin:0 8px; }
    .progress-connector.filled { background:linear-gradient(90deg,#22c55e,#D88FFF); }

    .invoice-actions-bar {
        display:flex; justify-content:flex-end; gap:12px; margin-bottom:24px; flex-wrap:wrap;
    }
    .ia-btn {
        display:inline-flex; align-items:center; gap:8px;
        padding:10px 20px; border-radius:10px; font-weight:600; font-size:0.85rem;
        text-decoration:none; transition:all 0.3s ease; border:none; cursor:pointer;
    }
    .ia-btn-primary {
        background:linear-gradient(135deg,#7B3F91,#D88FFF); color:#fff;
        box-shadow:0 4px 16px rgba(123,63,145,0.3);
    }
    .ia-btn-primary:hover { transform:translateY(-1px); color:#fff; }
    .ia-btn-outline {
        background:transparent; border:1.5px solid rgba(216,143,255,0.3); color:#D88FFF;
    }
    .ia-btn-outline:hover { background:rgba(216,143,255,0.1); color:#D88FFF; }

    /* Invoice Card */
    .invoice-card {
        background:#fff; border-radius:20px; overflow:hidden;
        box-shadow:0 8px 48px rgba(0,0,0,0.3);
        max-width:800px; margin:0 auto;
    }

    /* Invoice Header */
    .inv-header {
        background:linear-gradient(135deg,#7B3F91,#9B59B6);
        padding:36px 40px; display:flex; justify-content:space-between; align-items:center;
    }
    .inv-brand h2 { color:#fff; font-weight:800; font-size:1.4rem; margin:0 0 2px 0; }
    .inv-brand p { color:rgba(255,255,255,0.7); font-size:0.82rem; margin:0; }
    .inv-title { text-align:right; }
    .inv-title h3 { color:#fff; font-weight:700; font-size:1.6rem; margin:0 0 4px 0; }
    .inv-title p { color:rgba(255,255,255,0.7); font-size:0.85rem; margin:0; }

    /* Invoice Body */
    .inv-body { padding:36px 40px; }

    .inv-meta {
        display:grid; grid-template-columns:1fr 1fr; gap:24px;
        margin-bottom:32px; padding-bottom:24px;
        border-bottom:2px solid #f3f0f6;
    }
    .inv-meta-block h6 {
        font-size:0.72rem; text-transform:uppercase; letter-spacing:0.08em;
        color:#8B7A96; font-weight:700; margin:0 0 10px 0;
    }
    .inv-meta-block p { color:#2d1f3d; font-size:0.88rem; margin:2px 0; font-weight:500; }
    .inv-meta-block p.label-text { color:#8B7A96; font-weight:400; }

    .inv-table { width:100%; border-collapse:collapse; margin-bottom:24px; }
    .inv-table thead th {
        background:#f9f7fc; padding:12px 16px; text-align:left;
        font-size:0.78rem; text-transform:uppercase; letter-spacing:0.06em;
        color:#8B7A96; font-weight:700; border-bottom:2px solid #f3f0f6;
    }
    .inv-table thead th:last-child { text-align:right; }
    .inv-table tbody td {
        padding:16px; border-bottom:1px solid #f3f0f6;
        color:#2d1f3d; font-size:0.9rem;
    }
    .inv-table tbody td:last-child { text-align:right; font-weight:600; }
    .inv-table .item-name { font-weight:600; }
    .inv-table .item-detail { font-size:0.82rem; color:#8B7A96; margin-top:2px; }

    .inv-totals { display:flex; justify-content:flex-end; }
    .inv-totals-table { width:280px; }
    .inv-totals-row {
        display:flex; justify-content:space-between; padding:8px 0;
        font-size:0.88rem; color:#5a4668;
    }
    .inv-totals-row.discount { color:#22c55e; }
    .inv-totals-row.total {
        border-top:2px solid #7B3F91; padding-top:12px; margin-top:4px;
        font-size:1.1rem; font-weight:700; color:#2d1f3d;
    }
    .inv-totals-row.total span:last-child {
        font-family:'Space Grotesk',sans-serif; color:#7B3F91;
    }

    /* Status & Footer */
    .inv-status-bar {
        display:flex; justify-content:space-between; align-items:center;
        padding:20px 40px; background:#f9f7fc; border-top:1px solid #f3f0f6;
    }
    .inv-status-badge {
        display:inline-flex; align-items:center; gap:6px;
        padding:6px 16px; border-radius:60px; font-size:0.82rem; font-weight:700;
    }
    .status-paid { background:#dcfce7; color:#16a34a; }
    .status-pending { background:#fef3c7; color:#d97706; }
    .status-failed { background:#fee2e2; color:#dc2626; }
    .inv-thankyou { color:#8B7A96; font-size:0.85rem; font-style:italic; }

    @media(max-width:767px) {
        .inv-header { flex-direction:column; gap:16px; text-align:center; padding:24px; }
        .inv-title { text-align:center; }
        .inv-body { padding:24px; }
        .inv-meta { grid-template-columns:1fr; }
        .inv-status-bar { flex-direction:column; gap:12px; padding:16px 24px; text-align:center; }
        .invoice-actions-bar { justify-content:center; }
    }

    @media print {
        .invoice-section { padding:0 !important; }
        .progress-indicator, .invoice-actions-bar, header, footer, nav { display:none !important; }
        .invoice-card { box-shadow:none !important; border-radius:0 !important; }
        .inv-header { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        .inv-status-bar { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    }
</style>

<section class="invoice-section">
    <div class="container">

        <!-- Progress - Step 4 Active -->
        <div class="progress-indicator" data-aos="fade-down">
            <div class="progress-step completed">
                <div class="step-circle"><i class="fa-solid fa-check" style="font-size:0.8rem"></i></div>
                <span class="step-label">Plan Selection</span>
            </div>
            <div class="progress-connector filled"></div>
            <div class="progress-step completed">
                <div class="step-circle"><i class="fa-solid fa-check" style="font-size:0.8rem"></i></div>
                <span class="step-label">Payment</span>
            </div>
            <div class="progress-connector filled"></div>
            <div class="progress-step completed">
                <div class="step-circle"><i class="fa-solid fa-check" style="font-size:0.8rem"></i></div>
                <span class="step-label">Success</span>
            </div>
            <div class="progress-connector filled"></div>
            <div class="progress-step active">
                <div class="step-circle">4</div>
                <span class="step-label">Invoice</span>
            </div>
        </div>

        <!-- Action Buttons (hidden in print) -->
        <div class="invoice-actions-bar">
            <a href="download_invoice.php?payment_id=<?php echo $payment_id; ?>" class="ia-btn ia-btn-primary">
                <i class="fa-solid fa-download"></i>Download PDF
            </a>
            <a href="print_invoice.php?payment_id=<?php echo $payment_id; ?>" class="ia-btn ia-btn-outline" target="_blank">
                <i class="fa-solid fa-print"></i>Print Receipt
            </a>
            <a href="payment_history.php" class="ia-btn ia-btn-outline">
                <i class="fa-solid fa-arrow-left"></i>Back to History
            </a>
        </div>

        <!-- Invoice Card -->
        <div class="invoice-card" id="invoiceCard" data-aos="fade-up">
            <!-- Header -->
            <div class="inv-header">
                <div class="inv-brand">
                    <h2><i class="fa-solid fa-brain me-2"></i>AI Checker</h2>
                    <p>AI-Powered Assignment Checking System</p>
                </div>
                <div class="inv-title">
                    <h3>INVOICE</h3>
                    <p><?php echo htmlspecialchars($payment['invoice_number']); ?></p>
                </div>
            </div>

            <!-- Body -->
            <div class="inv-body">
                <!-- Meta Info -->
                <div class="inv-meta">
                    <div class="inv-meta-block">
                        <h6>Bill To</h6>
                        <p class="label-text">Customer Name</p>
                        <p><?php echo htmlspecialchars($payment['username']); ?></p>
                        <p class="label-text" style="margin-top:10px;">Email</p>
                        <p><?php echo htmlspecialchars($payment['email']); ?></p>
                    </div>
                    <div class="inv-meta-block" style="text-align:right;">
                        <h6>Invoice Details</h6>
                        <p class="label-text">Invoice Number</p>
                        <p><?php echo htmlspecialchars($payment['invoice_number']); ?></p>
                        <p class="label-text" style="margin-top:10px;">Payment Date</p>
                        <p><?php echo date('d F Y, h:i A', strtotime($payment['created_at'])); ?></p>
                    </div>
                </div>

                <!-- Items Table -->
                <table class="inv-table">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th>Duration</th>
                            <th>Payment Method</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>
                                <div class="item-name"><?php echo htmlspecialchars($payment['plan_name'] ?? 'Subscription Plan'); ?></div>
                                <div class="item-detail">Premium AI Assignment Checker Subscription</div>
                            </td>
                            <td><?php echo htmlspecialchars($payment['plan_duration'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($payment['payment_method']); ?></td>
                            <td>RM <?php echo number_format(floatval($payment['amount']), 2); ?></td>
                        </tr>
                    </tbody>
                </table>

                <!-- Totals -->
                <?php
                $discount = floatval($payment['discount_amount']);
                $original = floatval($payment['amount']);
                $total    = floatval($payment['total_paid']);
                $voucher  = htmlspecialchars($payment['voucher_code'] ?? '—');
                ?>
                <div class="inv-totals">
                    <div class="inv-totals-table">
                        <div class="inv-totals-row">
                            <span>Subtotal</span>
                            <span>RM <?php echo number_format($original, 2); ?></span>
                        </div>
                        <?php if ($discount > 0): ?>
                        <div class="inv-totals-row discount">
                            <span>Discount (<?php echo $voucher; ?>)</span>
                            <span>-RM <?php echo number_format($discount, 2); ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="inv-totals-row total">
                            <span>Total Paid</span>
                            <span>RM <?php echo number_format($total, 2); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Status Bar -->
            <div class="inv-status-bar">
                <?php
                $status = $payment['payment_status'];
                $statusClass = 'status-paid';
                if ($status === 'Pending') $statusClass = 'status-pending';
                elseif ($status === 'Failed') $statusClass = 'status-failed';
                ?>
                <span class="inv-status-badge <?php echo $statusClass; ?>">
                    <i class="fa-solid fa-circle" style="font-size:0.5rem"></i>
                    <?php echo htmlspecialchars($status); ?>
                </span>
                <span class="inv-thankyou">Thank you for your purchase!</span>
            </div>
        </div>
    </div>
</section>

<?php require_once 'footer.php'; ?>