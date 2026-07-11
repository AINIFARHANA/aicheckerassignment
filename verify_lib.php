<?php
// ═══════════════════════════════════════════════════════════════
// verify_lib.php
//
// Shared, database-free signing & verification logic for the
// "Scan to Verify" QR code printed on every AI Analysis report.
//
// WHY THIS FILE EXISTS
// The QR code used to encode a plain block of text. Most phone
// camera apps just DISPLAY raw text like that — they don't open
// anything — so scanning it from another device did nothing useful.
//
// Now the QR encodes a normal https:// link to verify_report.php
// with the report's details plus a signed HMAC in the query string.
// Any camera app on any device can scan a normal link and open it
// in the browser. verify_report.php then re-derives the same HMAC
// from those exact query values and compares it — so the check
// never touches the database and never calls any external service.
// The only requirement is that verify_report.php itself is reachable
// on the same site the report was generated from (exactly like
// scanning a QR code on a diploma, ticket, or invoice).
//
// Include this file from BOTH ai_analysis.php (to build the link)
// and verify_report.php (to check it) so the signing logic can
// never drift out of sync between the two.
// ═══════════════════════════════════════════════════════════════

if (!defined('VERIFY_SECRET_KEY')) {
    // Change this to your own long random string in production.
    // Anyone who has this key could forge a "verified" certificate,
    // so keep it private and never commit the real value publicly.
    define('VERIFY_SECRET_KEY', 'CHANGE-THIS-TO-A-LONG-RANDOM-SECRET-STRING');
}

if (!defined('VERIFY_COMPANY_NAME'))     define('VERIFY_COMPANY_NAME', 'AI Checker');
if (!defined('VERIFY_COMPANY_TAGLINE'))  define('VERIFY_COMPANY_TAGLINE', 'AI-Powered Assignment Checking System');

/**
 * Canonical, order-fixed field set used for signing. Both the QR
 * generator and the verifier build this exact same array so the
 * signatures always match.
 */
function verifyBuildFields($report_id, $file_name, $student_name, $score, $risk, $date_str) {
    return [
        'r' => (string)$report_id,
        'f' => (string)$file_name,
        'n' => (string)$student_name,
        's' => (string)$score,
        'k' => (string)$risk,
        'd' => (string)$date_str,
    ];
}

/** Deterministic signature over a canonical field set. */
function verifySign(array $fields) {
    $line = '';
    foreach ($fields as $k => $v) { $line .= $k . ':' . $v . '|'; }
    return substr(hash_hmac('sha256', $line, VERIFY_SECRET_KEY), 0, 20);
}

/**
 * Builds the absolute, scannable verification URL for a report.
 * This is the exact string that gets embedded in the QR code.
 */
function verifyBuildUrl($report_id, $file_name, $student_name, $score, $risk, $date_str) {
    $fields = verifyBuildFields($report_id, $file_name, $student_name, $score, $risk, $date_str);
    $sig = verifySign($fields);

    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'sql300.infinityfree.com';
    $selfDir = isset($_SERVER['PHP_SELF']) ? str_replace('\\', '/', dirname($_SERVER['PHP_SELF'])) : '';
    $dir = rtrim($selfDir, '/');
    $base = $scheme . '://' . $host . $dir . '/verify_report.php';

    $query = http_build_query(array_merge($fields, ['sig' => $sig]));
    return $base . '?' . $query;
}

/**
 * Re-derives the signature from $_GET (or any array of the same
 * shape) and confirms it matches — with zero database queries.
 * Returns the validated fields array on success, or false if the
 * link is missing fields, malformed, or has been tampered with.
 */
function verifyCheckRequest(array $get) {
    foreach (['r', 'f', 'n', 's', 'k', 'd', 'sig'] as $key) {
        if (!isset($get[$key]) || $get[$key] === '') return false;
    }
    $fields = verifyBuildFields($get['r'], $get['f'], $get['n'], $get['s'], $get['k'], $get['d']);
    $expected = verifySign($fields);
    if (!hash_equals($expected, (string)$get['sig'])) return false;
    return $fields;
}
