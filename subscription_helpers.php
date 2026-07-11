<?php
/* ============================================================
   subscription_helpers.php

   Shared helper that grants/extends plan access for a PAID
   `payment_transactions` row of type 'plan'.

   Why this exists as its own idempotent function (instead of only
   living inline in payment_callback.php):

   1. It's safe to call over and over — if a `user_subscriptions` row
      already references this exact payment, it does nothing. That
      means we can call it both the moment a payment is confirmed
      AND again on every later page load/refresh, so a transient
      failure (e.g. a DB error) doesn't permanently leave the user
      "paid but not subscribed".

   2. profile.php can call it too, to self-heal any older payments
      that were marked 'paid' before this fix but never actually got
      a subscription row (e.g. because of the payment_id foreign key
      bug — see patch_fixes.sql).
   ============================================================ */

if (!function_exists('ensureSubscriptionForPaidPlanTxn')) {
    function ensureSubscriptionForPaidPlanTxn($conn, $txn) {
        if (!$conn || !$txn) return false;
        if (($txn['type'] ?? '') !== 'plan')   return false;
        if (($txn['status'] ?? '') !== 'paid') return false;

        $paymentTxnId = intval($txn['id'] ?? 0);
        $userId       = intval($txn['user_id'] ?? 0);
        $planId       = intval($txn['reference_id'] ?? 0);
        if ($paymentTxnId <= 0 || $userId <= 0 || $planId <= 0) return false;

        /* Already processed for this exact payment — nothing to do. */
        $chk = $conn->prepare("SELECT subscription_id FROM user_subscriptions WHERE payment_id = ? LIMIT 1");
        if ($chk) {
            $chk->bind_param("i", $paymentTxnId);
            $chk->execute();
            $already = $chk->get_result()->fetch_assoc();
            $chk->close();
            if ($already) return true;
        }

        /* Work out plan duration in months */
        $intervalMonths = 1;
        $planStmt = $conn->prepare("SELECT duration FROM plans WHERE plan_id = ?");
        if ($planStmt) {
            $planStmt->bind_param("i", $planId);
            $planStmt->execute();
            $planRow = $planStmt->get_result()->fetch_assoc();
            $planStmt->close();
            if ($planRow) {
                $dur = strtolower($planRow['duration'] ?? '');
                if (strpos($dur, 'lifetime') !== false) {
                    $intervalMonths = 1200;
                } elseif (strpos($dur, 'year') !== false) {
                    $intervalMonths = 12;
                } elseif (strpos($dur, '6') !== false || strpos($dur, 'six') !== false) {
                    $intervalMonths = 6;
                } elseif (strpos($dur, '3') !== false || strpos($dur, 'three') !== false) {
                    $intervalMonths = 3;
                }
            }
        }

        /* Extend an existing active subscription for this plan, or create a new one */
        $existing = null;
        $subCheck = $conn->prepare("SELECT subscription_id, end_date FROM user_subscriptions WHERE user_id = ? AND plan_id = ? AND status = 'Active' ORDER BY end_date DESC LIMIT 1");
        if ($subCheck) {
            $subCheck->bind_param("ii", $userId, $planId);
            $subCheck->execute();
            $existing = $subCheck->get_result()->fetch_assoc();
            $subCheck->close();
        }

        if ($existing) {
            $baseDate    = max(strtotime($existing['end_date']), time());
            $extendedEnd = date('Y-m-d H:i:s', strtotime("+{$intervalMonths} months", $baseDate));
            $upd = $conn->prepare("UPDATE user_subscriptions SET end_date = ?, payment_id = ? WHERE subscription_id = ?");
            if (!$upd) return false;
            $upd->bind_param("sii", $extendedEnd, $paymentTxnId, $existing['subscription_id']);
            $ok = $upd->execute();
            $upd->close();
            return (bool)$ok;
        }

        $newEndDate = date('Y-m-d H:i:s', strtotime("+{$intervalMonths} months"));
        $ins = $conn->prepare("INSERT INTO user_subscriptions (user_id, plan_id, payment_id, status, start_date, end_date, created_at) VALUES (?, ?, ?, 'Active', NOW(), ?, NOW())");
        if (!$ins) return false;
        $ins->bind_param("iiis", $userId, $planId, $paymentTxnId, $newEndDate);
        $ok = $ins->execute();
        $ins->close();
        return (bool)$ok;
    }
}

/* Self-heal helper: for a given user, find any PAID plan payments that
   still have no matching user_subscriptions row (e.g. left over from
   before this fix was applied) and activate them now. Cheap to run —
   only does work when something is actually missing. */
if (!function_exists('healMissingSubscriptionsForUser')) {
    function healMissingSubscriptionsForUser($conn, $userId) {
        if (!$conn || $userId <= 0) return;
        $sql = "SELECT pt.* FROM payment_transactions pt
                WHERE pt.user_id = ? AND pt.type = 'plan' AND pt.status = 'paid'
                AND NOT EXISTS (
                    SELECT 1 FROM user_subscriptions us WHERE us.payment_id = pt.id
                )";
        $stmt = $conn->prepare($sql);
        if (!$stmt) return;
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $orphans = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $stmt->close();

        foreach ($orphans as $txn) {
            ensureSubscriptionForPaidPlanTxn($conn, $txn);
        }
    }
}
