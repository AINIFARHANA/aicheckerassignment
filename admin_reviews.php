<?php
session_start();
require_once 'config.php';

// ═══════════════════════════════════════════════════════════════
// CONNECTION
// ═══════════════════════════════════════════════════════════════
$servername = "localhost";
$username   = "root";
$password   = "";               // Default XAMPP password
$dbname     = "assignment_db";  // Your local database name

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

require_once __DIR__ . '/notification_helpers.php';

// ★ CERTIFICATE ID / CERTIFICATE ISSUANCE ★
// ═══════════════════════════════════════════════════════════════
// Replaces the old random "AIC-xxxxxx-xxxxxx-xxxxxx" verification
// code with a sequential, human-readable Certificate ID in the
// format CERT-YYYY-NNNNN (e.g. CERT-2026-00001), stored in a
// dedicated `certificates` table alongside a personalized
// certificate image generated from image/certificate.png.
//
// The generated code is ALSO written into the existing
// assignment_reviews.verification_code column (kept for backward
// compatibility with anything else that reads it) — so as far as
// the rest of the app is concerned, "verification code" and
// "certificate key" are now the same value.
// ═══════════════════════════════════════════════════════════════

// ── Generate the next sequential Certificate ID for the current year ──
function generateCertificateCode($conn) {
    $year = date('Y');
    $like = "CERT-{$year}-%";
    $stmt = $conn->prepare("SELECT certificate_code FROM certificates WHERE certificate_code LIKE ? ORDER BY certificate_id DESC LIMIT 1");
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $next = 1;
    if ($row && preg_match('/CERT-\d{4}-(\d+)$/', $row['certificate_code'], $m)) {
        $next = intval($m[1]) + 1;
    }
    return sprintf('CERT-%s-%05d', $year, $next);
}

// ── Draw a line of text onto the certificate image, centered ──
// horizontally around $centerX. Uses a TrueType font if one can be
// found on the server (nicer output); otherwise falls back to GD's
// built-in bitmap font so this still works with zero extra files
// and no Composer/external API.
function drawCertificateLine($im, $text, $centerX, $y, $size, $color) {
    static $ttfFont = null;
    static $ttfChecked = false;
    if (!$ttfChecked) {
        $ttfChecked = true;
        $candidates = [
            'C:\\Windows\\Fonts\\arialbd.ttf',
            'C:\\Windows\\Fonts\\arial.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
        ];
        foreach ($candidates as $c) {
            if (file_exists($c)) { $ttfFont = $c; break; }
        }
    }

    if ($ttfFont && function_exists('imagettftext')) {
        $box = imagettfbbox($size, 0, $ttfFont, $text);
        $textWidth = abs($box[2] - $box[0]);
        $x = (int)($centerX - ($textWidth / 2));
        imagettftext($im, $size, 0, $x, (int)$y, $color, $ttfFont, $text);
    } else {
        $font = 5; // largest built-in GD bitmap font
        $textWidth = imagefontwidth($font) * strlen($text);
        $x = (int)($centerX - ($textWidth / 2));
        imagestring($im, $font, $x, (int)($y - imagefontheight($font)), $text, $color);
    }
}

// ── Build the personalized certificate image from image/certificate.png ──
// NOTE: the Y-position percentages below (0.42 / 0.52 / 0.60 / 0.90)
// are a reasonable starting layout for a landscape certificate. If
// your certificate.png template places its text differently, adjust
// these percentages to match — they are the only thing you'd need
// to tune.
function generateCertificateImage($templatePath, $data, $outputPath) {
    if (!extension_loaded('gd') || !function_exists('imagecreatefrompng')) return false;
    $im = @imagecreatefrompng($templatePath);
    if (!$im) return false;

    imagesavealpha($im, true);
    $width  = imagesx($im);
    $height = imagesy($im);
    $ink    = imagecolorallocate($im, 45, 27, 78); // #2D1B4E — matches the app's brand purple

    drawCertificateLine($im, $data['student_name'],     $width * 0.5,  $height * 0.42, 26, $ink);
    drawCertificateLine($im, $data['assignment_title'], $width * 0.5,  $height * 0.52, 16, $ink);
    drawCertificateLine($im, 'AI Score: ' . $data['ai_score'], $width * 0.5, $height * 0.60, 14, $ink);
    drawCertificateLine($im, 'Issued: ' . $data['issued_date'], $width * 0.28, $height * 0.90, 12, $ink);
    drawCertificateLine($im, $data['certificate_code'], $width * 0.72, $height * 0.90, 12, $ink);

    $dir = dirname($outputPath);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $ok = imagepng($im, $outputPath);
    imagedestroy($im);
    return $ok;
}

// ── Issue (or fetch the already-issued) certificate for an assignment ──
// Idempotent: calling this again for the same assignment just
// returns the existing Certificate ID instead of creating a
// duplicate row/image.
function issueCertificate($conn, $assignment_id) {
    $existingStmt = $conn->prepare("SELECT certificate_code FROM certificates WHERE assignment_id = ? LIMIT 1");
    $existingStmt->bind_param("i", $assignment_id);
    $existingStmt->execute();
    $existing = $existingStmt->get_result()->fetch_assoc();
    $existingStmt->close();
    if ($existing && !empty($existing['certificate_code'])) {
        $code = $existing['certificate_code'];
        $conn->query("UPDATE assignment_reviews SET verification_code = '" . $conn->real_escape_string($code) . "' WHERE assignment_id = " . (int)$assignment_id . " AND (verification_code IS NULL OR verification_code = '')");
        return $code;
    }

    $infoStmt = $conn->prepare("
        SELECT a.title AS assignment_title, u.user_id, u.name AS student_name, ar.ai_score
        FROM assignments a
        JOIN users u ON a.user_id = u.user_id
        LEFT JOIN assignment_reviews ar ON ar.assignment_id = a.assignment_id
        WHERE a.assignment_id = ? LIMIT 1
    ");
    $infoStmt->bind_param("i", $assignment_id);
    $infoStmt->execute();
    $info = $infoStmt->get_result()->fetch_assoc();
    $infoStmt->close();
    if (!$info) return '';

    $code = generateCertificateCode($conn);
    $issuedDateSql  = date('Y-m-d');
    $issuedDateNice = date('F j, Y');
    $aiScoreRaw     = $info['ai_score'];
    $aiScoreDisplay = $aiScoreRaw !== null ? number_format((float)$aiScoreRaw, 1) . '%' : 'N/A';

    // ── Build the personalized certificate image ──
    $templatePath  = __DIR__ . '/image/certificate.png';
    $certFileName  = $code . '.png';
    $certFullPath  = __DIR__ . '/uploads/certificates/' . $certFileName;
    $certificateFile = null;
    if (file_exists($templatePath)) {
        $ok = generateCertificateImage($templatePath, [
            'student_name'     => $info['student_name'],
            'assignment_title' => $info['assignment_title'],
            'ai_score'         => $aiScoreDisplay,
            'issued_date'      => $issuedDateNice,
            'certificate_code' => $code,
        ], $certFullPath);
        if ($ok) $certificateFile = 'uploads/certificates/' . $certFileName;
    }

    $insert = $conn->prepare("INSERT INTO certificates (user_id, assignment_id, certificate_code, student_name, assignment_title, ai_score, issued_date, certificate_file, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $insert->bind_param("iisssdss", $info['user_id'], $assignment_id, $code, $info['student_name'], $info['assignment_title'], $aiScoreRaw, $issuedDateSql, $certificateFile);
    $insert->execute();
    $insert->close();

    // Keep the existing verification_code column in sync for compatibility
    $conn->query("UPDATE assignment_reviews SET verification_code = '" . $conn->real_escape_string($code) . "' WHERE assignment_id = " . (int)$assignment_id . " AND (verification_code IS NULL OR verification_code = '')");

    return $code;
}
// ★ END CERTIFICATE ID / CERTIFICATE ISSUANCE

// ★ CERTIFICATE EMAIL — sends image/certificate.png as an attachment
// using PHP's built-in mail() function. mail() has no native
// attachment support, so this builds a raw multipart/mixed MIME
// message by hand (an HTML body part + a base64-encoded image part).
// NOTE: this depends on the server's mail() being configured to
// actually deliver mail (a working sendmail/SMTP relay in php.ini).
// On a bare XAMPP install mail() typically returns true but nothing
// is actually delivered unless that's been set up — the caller
// checks the return value and reports it in the flash message.
function sendCertificateEmail($toEmail, $toName, $subject, $bodyHtml, $attachmentPath, $attachmentName) {
    if (empty($toEmail) || !file_exists($attachmentPath)) return false;

    $boundary = md5(uniqid((string)microtime(true), true));
    $fromHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $fromHost = preg_replace('/:\d+$/', '', $fromHost); // strip port, if any

    $headers  = "From: AI Checker <no-reply@{$fromHost}>\r\n";
    $headers .= "Reply-To: no-reply@{$fromHost}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";

    $fileData = file_get_contents($attachmentPath);
    if ($fileData === false) return false;
    $fileBase64 = chunk_split(base64_encode($fileData));

    $message  = "--{$boundary}\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n";
    $message .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $message .= $bodyHtml . "\r\n\r\n";

    $message .= "--{$boundary}\r\n";
    $message .= "Content-Type: image/png; name=\"{$attachmentName}\"\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n";
    $message .= "Content-Disposition: attachment; filename=\"{$attachmentName}\"\r\n\r\n";
    $message .= $fileBase64 . "\r\n";
    $message .= "--{$boundary}--";

    return @mail($toEmail, $subject, $message, $headers);
}
// ★ END CERTIFICATE EMAIL

 $admin_id = $_SESSION['user_id'] ?? null;
if (!isset($admin_id)) {
    header('location: login.php');
    exit;
}

 $flash_msg = '';
 $flash_type = '';

// ═══════════════════════════════════════════════════════════════
// CSV EXPORT
// ═══════════════════════════════════════════════════════════════
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=reviews_export_' . date('Y-m-d_His') . '.csv');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($output, ['Review ID', 'Assignment ID', 'User Name', 'Title', 'Subject', 'AI Score', 'Similarity', 'Marks', 'Comment', 'Reviewed File', 'Status', 'Certificate ID', 'Review Date']);
    $exp_sql = "SELECT ar.review_id, ar.assignment_id, ar.comment, ar.marks, ar.reviewed_file, ar.created_at,
                       a.title, a.subject, a.status, ar.ai_score, ar.similarity, u.name as user_name, ar.verification_code
                FROM assignment_reviews ar
                LEFT JOIN assignments a ON ar.assignment_id = a.assignment_id
                LEFT JOIN users u ON a.user_id = u.user_id
                ORDER BY ar.created_at DESC";
    $exp_res = $conn->query($exp_sql);
    while ($r = $exp_res->fetch_assoc()) {
        fputcsv($output, [
            $r['review_id'], $r['assignment_id'], $r['user_name'], $r['title'],
            $r['subject'], $r['ai_score'] !== null ? number_format((float)$r['ai_score'], 2) . '%' : 'N/A',
            $r['similarity'] !== null ? number_format((float)$r['similarity'], 2) . '%' : 'N/A',
            $r['marks'], $r['comment'], $r['reviewed_file'],
            $r['status'], $r['verification_code'] ?? 'N/A', $r['created_at']
        ]);
    }
    fclose($output);
    exit;
}

// ═══════════════════════════════════════════════════════════════
// MARK ALL NOTIFICATIONS AS READ
// ═══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_all_read') {
    $conn->query("UPDATE notifications SET is_read = 1 WHERE user_id IS NULL AND is_read = 0");
    header("Location: " . basename($_SERVER['PHP_SELF']) . (isset($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}

// ═══════════════════════════════════════════════════════════════
// HANDLE POST ACTIONS
// ═══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $post_action = $_POST['action'] ?? '';

    // ── ADD REVIEW ──
    if ($post_action === 'add_review') {
        $a_id = intval($_POST['assignment_id'] ?? 0);
        $ai_score = $_POST['ai_score'] !== '' ? floatval($_POST['ai_score']) : null;
        $similarity = $_POST['similarity'] !== '' ? floatval($_POST['similarity']) : null;
        $marks = $_POST['marks'] !== '' ? intval($_POST['marks']) : null;
        $comment = trim($_POST['comment'] ?? '');
        $reviewed_file = null;

        if ($a_id <= 0) {
            $flash_msg = 'Please select a valid assignment.';
            $flash_type = 'danger';
        } elseif ($ai_score !== null && ($ai_score < 0 || $ai_score > 100)) {
            $flash_msg = 'AI Score must be between 0 and 100.';
            $flash_type = 'danger';
        } elseif ($similarity !== null && ($similarity < 0 || $similarity > 100)) {
            $flash_msg = 'Similarity must be between 0 and 100.';
            $flash_type = 'danger';
        } elseif ($marks !== null && ($marks < 0 || $marks > 100)) {
            $flash_msg = 'Marks must be between 0 and 100.';
            $flash_type = 'danger';
        } else {
            $upload_dir = __DIR__ . '/uploads/reviews/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            if (isset($_FILES['reviewed_file']) && $_FILES['reviewed_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                $file = $_FILES['reviewed_file'];
                $allowed = ['pdf', 'doc', 'docx', 'txt'];
                $allowed_mimes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/plain'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                if (!in_array($ext, $allowed)) {
                    $flash_msg = 'Invalid file type. Only PDF, DOC, DOCX, TXT allowed.';
                    $flash_type = 'danger';
                } elseif ($file['size'] > 10 * 1024 * 1024) {
                    $flash_msg = 'File too large. Max 10MB.';
                    $flash_type = 'danger';
                } else {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file($file['tmp_name']);
                    if (!in_array($mime, $allowed_mimes)) {
                        $flash_msg = 'File content type mismatch.';
                        $flash_type = 'danger';
                    } else {
                        $safe_base = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
                        $safe_base = trim(preg_replace('/_+/', '_', $safe_base), '_') ?: 'file';
                        $new_name = date('Ymd_His') . '_' . $safe_base . '_' . uniqid() . '.' . $ext;
                        if (move_uploaded_file($file['tmp_name'], $upload_dir . $new_name)) {
                            $reviewed_file = $new_name;
                        } else {
                            $flash_msg = 'Failed to save uploaded file.';
                            $flash_type = 'danger';
                        }
                    }
                }
            }

            if (empty($flash_msg)) {
                $stmt = $conn->prepare("INSERT INTO assignment_reviews (assignment_id, admin_id, ai_score, similarity, comment, marks, reviewed_file, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->bind_param("iiddsis", $a_id, $admin_id, $ai_score, $similarity, $comment, $marks, $reviewed_file);
                if ($stmt->execute()) {
                    $new_status = ($marks !== null) ? 'Completed' : 'Checking';
                    $conn->query("UPDATE assignments SET status = '$new_status' WHERE assignment_id = $a_id");
                    createAssignmentNotification(
                        $conn,
                        $a_id,
                        $new_status === 'Completed'
                            ? 'A review has been added for your assignment "{title}". The assignment is completed.'
                            : 'A review has been added for your assignment "{title}". It is still being checked.'
                    );
                    $vcode = '';
                    if ($new_status === 'Completed' || !empty($reviewed_file)) {
                        $vcode = issueCertificate($conn, $a_id);
                    }
                    $flash_msg = 'Review added successfully. Assignment status updated to "' . htmlspecialchars($new_status) . '".';
                    if ($vcode) {
                        $flash_msg .= ' <br><strong style="color:#6A0DAD;">Certificate ID: ' . htmlspecialchars($vcode) . '</strong>';
                    }
                    $flash_type = 'success';
                } else {
                    $flash_msg = 'Database error: ' . htmlspecialchars($stmt->error);
                    $flash_type = 'danger';
                    if ($reviewed_file && file_exists($upload_dir . $reviewed_file)) unlink($upload_dir . $reviewed_file);
                }
                $stmt->close();
            }
        }
    }

    // ── UPDATE REVIEW ──
    if ($post_action === 'update_review') {
        $r_id = intval($_POST['review_id'] ?? 0);
        $ai_score = $_POST['ai_score'] !== '' ? floatval($_POST['ai_score']) : null;
        $similarity = $_POST['similarity'] !== '' ? floatval($_POST['similarity']) : null;
        $marks = $_POST['marks'] !== '' ? intval($_POST['marks']) : null;
        $comment = trim($_POST['comment'] ?? '');
        $a_id = intval($_POST['assignment_id'] ?? 0);
        $reviewed_file = $_POST['existing_file'] ?? null;

        if ($r_id <= 0) {
            $flash_msg = 'Invalid review ID.';
            $flash_type = 'danger';
        } elseif ($ai_score !== null && ($ai_score < 0 || $ai_score > 100)) {
            $flash_msg = 'AI Score must be between 0 and 100.';
            $flash_type = 'danger';
        } elseif ($similarity !== null && ($similarity < 0 || $similarity > 100)) {
            $flash_msg = 'Similarity must be between 0 and 100.';
            $flash_type = 'danger';
        } elseif ($marks !== null && ($marks < 0 || $marks > 100)) {
            $flash_msg = 'Marks must be between 0 and 100.';
            $flash_type = 'danger';
        } else {
            $upload_dir = __DIR__ . '/uploads/reviews/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

            if (isset($_FILES['reviewed_file']) && $_FILES['reviewed_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                $file = $_FILES['reviewed_file'];
                $allowed = ['pdf', 'doc', 'docx', 'txt'];
                $allowed_mimes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/plain'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                if (!in_array($ext, $allowed)) {
                    $flash_msg = 'Invalid file type. Only PDF, DOC, DOCX, TXT allowed.';
                    $flash_type = 'danger';
                } elseif ($file['size'] > 10 * 1024 * 1024) {
                    $flash_msg = 'File too large. Max 10MB.';
                    $flash_type = 'danger';
                } else {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file($file['tmp_name']);
                    if (!in_array($mime, $allowed_mimes)) {
                        $flash_msg = 'File content type mismatch.';
                        $flash_type = 'danger';
                    } else {
                        $safe_base = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
                        $safe_base = trim(preg_replace('/_+/', '_', $safe_base), '_') ?: 'file';
                        $new_name = date('Ymd_His') . '_' . $safe_base . '_' . uniqid() . '.' . $ext;
                        if (move_uploaded_file($file['tmp_name'], $upload_dir . $new_name)) {
                            if ($reviewed_file && file_exists($upload_dir . $reviewed_file)) unlink($upload_dir . $reviewed_file);
                            $reviewed_file = $new_name;
                        } else {
                            $flash_msg = 'Failed to save uploaded file.';
                            $flash_type = 'danger';
                        }
                    }
                }
            }

            if (empty($flash_msg)) {
                $stmt = $conn->prepare("UPDATE assignment_reviews SET ai_score = ?, similarity = ?, marks = ?, comment = ?, reviewed_file = ? WHERE review_id = ?");
                $stmt->bind_param("ddissi", $ai_score, $similarity, $marks, $comment, $reviewed_file, $r_id);
                if ($stmt->execute()) {
                    $new_status = ($marks !== null) ? 'Completed' : 'Checking';
                    $conn->query("UPDATE assignments SET status = '$new_status' WHERE assignment_id = $a_id");
                    createAssignmentNotification(
                        $conn,
                        $a_id,
                        $new_status === 'Completed'
                            ? 'The review for your assignment "{title}" has been updated. The assignment is completed.'
                            : 'The review for your assignment "{title}" has been updated. It is still being checked.'
                    );
                    $vcode = '';
                    if ($new_status === 'Completed' || !empty($reviewed_file)) {
                        $vcode = issueCertificate($conn, $a_id);
                    }
                    $flash_msg = 'Review updated successfully.';
                    if ($vcode) {
                        $flash_msg .= ' <strong style="color:#6A0DAD;">Certificate ID: ' . htmlspecialchars($vcode) . '</strong>';
                    }
                    $flash_type = 'success';
                } else {
                    $flash_msg = 'Database error: ' . htmlspecialchars($stmt->error);
                    $flash_type = 'danger';
                }
                $stmt->close();
            }
        }
    }

    // ── DELETE REVIEW ──
    if ($post_action === 'delete_review') {
        $r_id = intval($_POST['review_id'] ?? 0);
        $a_id = intval($_POST['assignment_id'] ?? 0);
        if ($r_id > 0) {
            $row = $conn->query("SELECT reviewed_file FROM assignment_reviews WHERE review_id = $r_id")->fetch_assoc();
            $stmt = $conn->prepare("DELETE FROM assignment_reviews WHERE review_id = ?");
            $stmt->bind_param("i", $r_id);
            if ($stmt->execute()) {
                if ($row && $row['reviewed_file']) {
                    $fpath = __DIR__ . '/uploads/reviews/' . $row['reviewed_file'];
                    if (file_exists($fpath)) unlink($fpath);
                }
                if ($a_id > 0) {
                    $conn->query("UPDATE assignments SET status = 'Pending' WHERE assignment_id = $a_id");
                    createAssignmentNotification($conn, $a_id, 'The review for your assignment "{title}" was removed. Its status is now Pending.');
                    // Revoke the issued certificate, if any, so it can no longer be verified
                    $certRow = $conn->query("SELECT certificate_file FROM certificates WHERE assignment_id = $a_id LIMIT 1")->fetch_assoc();
                    if ($certRow && !empty($certRow['certificate_file'])) {
                        $certAbsPath = __DIR__ . '/' . $certRow['certificate_file'];
                        if (file_exists($certAbsPath)) unlink($certAbsPath);
                    }
                    $conn->query("DELETE FROM certificates WHERE assignment_id = $a_id");
                }
                $flash_msg = 'Review deleted successfully. Assignment status reset to Pending.';
                $flash_type = 'success';
            } else {
                $flash_msg = 'Delete failed: ' . htmlspecialchars($stmt->error);
                $flash_type = 'danger';
            }
            $stmt->close();
        }
    }

    // ── UPDATE STATUS ──
    if ($post_action === 'update_status') {
        $a_id = intval($_POST['assignment_id'] ?? 0);
        $new_status = $_POST['new_status'] ?? '';
        $allowed_statuses = ['Pending', 'Checking', 'Completed'];
        if ($a_id > 0 && in_array($new_status, $allowed_statuses)) {
            $stmt = $conn->prepare("UPDATE assignments SET status = ? WHERE assignment_id = ?");
            $stmt->bind_param("si", $new_status, $a_id);
            if ($stmt->execute()) {
                createAssignmentNotification($conn, $a_id, assignmentStatusNotificationTemplate($new_status));
                $vcode = '';
                if ($new_status === 'Completed') {
                    $has_review = $conn->query("SELECT review_id FROM assignment_reviews WHERE assignment_id = $a_id LIMIT 1")->num_rows;
                    if ($has_review > 0) {
                        $vcode = issueCertificate($conn, $a_id);
                    }
                }
                $flash_msg = 'Status updated to "' . htmlspecialchars($new_status) . '".';
                if ($vcode) {
                    $flash_msg .= ' <strong style="color:#6A0DAD;">Certificate ID: ' . htmlspecialchars($vcode) . '</strong>';
                }
                $flash_type = 'success';
            } else {
                $flash_msg = 'Status update failed.';
                $flash_type = 'danger';
            }
            $stmt->close();
        }
    }
}

// ═══════════════════════════════════════════════════════════════
// FETCH ADMIN DATA
// ═══════════════════════════════════════════════════════════════
 $admin_query = $conn->query("SELECT user_id, name, email, avatar, created_at FROM users WHERE user_id = '$admin_id' AND user_type = 'admin'");
if (mysqli_num_rows($admin_query) === 0) die("Admin account not found.");
 $admin_data = mysqli_fetch_assoc($admin_query);
 $admin_name = $admin_data['name'] ?? 'Admin';
 $admin_email = $admin_data['email'] ?? '';
 $db_avatar = $admin_data['avatar'] ?? 'default.png';

if (!empty($db_avatar) && $db_avatar !== 'default.png') {
    $avatar = filter_var($db_avatar, FILTER_VALIDATE_URL) ? $db_avatar : "https://api.dicebear.com/7.x/avataaars/svg?seed=" . urlencode($db_avatar) . "&backgroundColor=ede9fe";
} else {
    $avatar = "https://api.dicebear.com/7.x/avataaars/svg?seed=Admin&backgroundColor=ede9fe";
}

function time_ago($datetime) {
    $now = new DateTime(); $ago = new DateTime($datetime); $diff = $now->diff($ago);
    if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    if ($diff->i > 0) return $diff->i . ' min ago';
    return 'Just now';
}

// ─── NOTIFICATIONS ───
 $unread_count = 0; $notifications = [];
 $noti_query = $conn->query("SELECT * FROM notifications WHERE user_id IS NULL ORDER BY created_at DESC LIMIT 30");
if (mysqli_num_rows($noti_query) > 0) {
    while ($row = mysqli_fetch_assoc($noti_query)) {
        $notifications[] = $row;
        if ($row['is_read'] == 0) $unread_count++;
    }
}

// ═══════════════════════════════════════════════════════════════
// STATISTICS
// ═══════════════════════════════════════════════════════════════
 $total_reviews = (int)($conn->query("SELECT COUNT(*) FROM assignment_reviews")->fetch_row()[0]);
 $total_marked = (int)($conn->query("SELECT COUNT(DISTINCT assignment_id) FROM assignment_reviews WHERE marks IS NOT NULL")->fetch_row()[0]);
 $avg_marks = $conn->query("SELECT AVG(marks) FROM assignment_reviews WHERE marks IS NOT NULL")->fetch_row()[0];
 $avg_marks = $avg_marks !== null ? round((float)$avg_marks, 1) : 0;
 $pending_count = (int)($conn->query("SELECT COUNT(*) FROM assignments WHERE status = 'Pending'")->fetch_row()[0]);
 $completed_count = (int)($conn->query("SELECT COUNT(*) FROM assignments WHERE status = 'Completed'")->fetch_row()[0]);

// ═══════════════════════════════════════════════════════════════
// PIE CHART DATA
// ═══════════════════════════════════════════════════════════════
 $total_assignments_all = (int)($conn->query("SELECT COUNT(*) FROM assignments")->fetch_row()[0]);
 $reviewed_assignments = (int)($conn->query("SELECT COUNT(DISTINCT assignment_id) FROM assignment_reviews")->fetch_row()[0]);
 $not_reviewed_count = max(0, $total_assignments_all - $reviewed_assignments);

// ═══════════════════════════════════════════════════════════════
// SEARCH, FILTER, PAGINATION
// ═══════════════════════════════════════════════════════════════
 $current_search = trim($_GET['search'] ?? '');
 $current_filter = $_GET['filter'] ?? 'all';
 $per_page = 10;
 $current_page = max(1, intval($_GET['page'] ?? 1));
 $offset = ($current_page - 1) * $per_page;
 $total_records = 0;
 $total_pages = 1;
 $rows = [];
 $is_not_reviewed = false;

if ($current_filter === 'not_reviewed') {
    $is_not_reviewed = true;
    $count_sql = "SELECT COUNT(*) FROM assignments a LEFT JOIN users u ON a.user_id = u.user_id LEFT JOIN assignment_reviews ar ON a.assignment_id = ar.assignment_id WHERE ar.review_id IS NULL";
    $data_sql = "SELECT a.assignment_id, a.title, a.subject, a.status, a.submission_date, a.user_id, ar.ai_score, ar.similarity, u.name as user_name, NULL as review_id, NULL as marks, NULL as comment, NULL as reviewed_file, NULL as created_at, NULL as verification_code FROM assignments a LEFT JOIN users u ON a.user_id = u.user_id LEFT JOIN assignment_reviews ar ON a.assignment_id = ar.assignment_id WHERE ar.review_id IS NULL";

    if ($current_search !== '') {
        $s = $conn->real_escape_string($current_search);
        $like = "%$s%";
        $count_sql .= " AND (a.assignment_id LIKE '$like' OR u.name LIKE '$like' OR a.title LIKE '$like')";
        $data_sql .= " AND (a.assignment_id LIKE '$like' OR u.name LIKE '$like' OR a.title LIKE '$like')";
    }
    $total_records = (int)($conn->query($count_sql)->fetch_row()[0]);
    $data_sql .= " ORDER BY a.submission_date DESC LIMIT $per_page OFFSET $offset";
    $res = $conn->query($data_sql);
    while ($r = $res->fetch_assoc()) $rows[] = $r;
} else {
    $data_sql = "SELECT ar.review_id, ar.assignment_id, ar.comment, ar.marks, ar.reviewed_file, ar.created_at, a.title, a.subject, a.status, a.user_id, a.submission_date, ar.ai_score, ar.similarity, u.name as user_name, ar.verification_code, c.certificate_file FROM assignment_reviews ar LEFT JOIN assignments a ON ar.assignment_id = a.assignment_id LEFT JOIN users u ON a.user_id = u.user_id LEFT JOIN certificates c ON c.assignment_id = ar.assignment_id WHERE 1=1";

    if ($current_filter === 'high_marks') $data_sql .= " AND ar.marks >= 80";
    elseif ($current_filter === 'low_marks') $data_sql .= " AND ar.marks < 50";

    if ($current_search !== '') {
        $s = $conn->real_escape_string($current_search);
        $like = "%$s%";
        $data_sql .= " AND (ar.review_id LIKE '$like' OR ar.assignment_id LIKE '$like' OR u.name LIKE '$like' OR a.title LIKE '$like' OR CAST(ar.marks AS CHAR) LIKE '$like')";
    }
    $total_records = (int)($conn->query(str_replace("SELECT ar.review_id", "SELECT COUNT(*)", $data_sql))->fetch_row()[0]);
    $data_sql .= " ORDER BY ar.created_at DESC LIMIT $per_page OFFSET $offset";
    $res = $conn->query($data_sql);
    while ($r = $res->fetch_assoc()) $rows[] = $r;
}
 $total_pages = max(1, ceil($total_records / $per_page));

// ─── PENDING ASSIGNMENTS FOR ADD REVIEW MODAL ───
 $pending_assignments = [];
 $pa_res = $conn->query("SELECT a.assignment_id, a.title, ar.ai_score, ar.similarity, u.name as user_name FROM assignments a LEFT JOIN users u ON a.user_id = u.user_id LEFT JOIN assignment_reviews ar ON a.assignment_id = ar.assignment_id WHERE ar.review_id IS NULL ORDER BY a.submission_date DESC");
while ($r = $pa_res->fetch_assoc()) $pending_assignments[] = $r;

 $conn->close();

// ─── HELPERS ───
function build_url($overrides = []) {
    $params = ['search' => $GLOBALS['current_search'], 'filter' => $GLOBALS['current_filter'], 'page' => $GLOBALS['current_page']];
    foreach ($overrides as $k => $v) $params[$k] = $v;
    $qs = http_build_query(array_filter($params, function($v) { return $v !== '' && $v !== null; }));
    return basename($_SERVER['PHP_SELF']) . ($qs ? "?$qs" : '');
}
function esc($v) { return htmlspecialchars($v ?? ''); }
function status_badge($s) {
    $s = $s ?? 'Pending';
    $c = ['Pending'=>'warning','Checking'=>'info','Completed'=>'success'];
    return '<span class="badge bg-' . ($c[$s] ?? 'secondary') . ' ' . ($s === 'Pending' ? 'text-dark' : '') . '">' . esc($s) . '</span>';
}
function marks_badge($m) {
    if ($m === null || $m === '') return '<span class="text-muted">N/A</span>';
    $m = (int)$m;
    $c = $m >= 80 ? 'success' : ($m >= 50 ? 'primary' : 'danger');
    return '<span class="badge bg-' . $c . '">' . $m . '/100</span>';
}
function ai_score_badge($v) {
    if ($v === null || $v === '') return '<span class="text-muted">N/A</span>';
    $v = (float)$v;
    $c = $v <= 20 ? 'success' : ($v <= 50 ? 'warning' : 'danger');
    return '<span class="badge bg-' . $c . '">' . number_format($v, 1) . '%</span>';
}
function similarity_badge($v) {
    if ($v === null || $v === '') return '<span class="text-muted">N/A</span>';
    $v = (float)$v;
    $c = $v <= 15 ? 'success' : ($v <= 35 ? 'warning' : 'danger');
    return '<span class="badge bg-' . $c . '">' . number_format($v, 1) . '%</span>';
}
function vcode_badge($v) {
    if ($v === null || $v === '') return '<span class="text-muted" style="font-size:11px;">—</span>';
    return '<span style="font-family:monospace;font-size:11px;font-weight:600;color:#6A0DAD;background:rgba(106,13,173,0.07);padding:3px 8px;border-radius:6px;cursor:pointer;white-space:nowrap;" title="Click to copy" onclick="copyCode(\'' . esc($v) . '\',this)">' . esc($v) . '</span>';
}

// Pie chart percentage
 $pie_reviewed_pct = $total_assignments_all > 0 ? round(($reviewed_assignments / $total_assignments_all) * 100) : 0;
 $pie_not_pct = 100 - $pie_reviewed_pct;

// ★ Certificate QR code — points straight at the certificate image
// Shown per-review in the "View" modal. Scanning it opens
// image/certificate.png directly on this server (built as an
// absolute URL for this XAMPP host) rather than any database-backed
// verification page. Requires this server to be reachable from
// whatever device scans it — a QR code can only ever hold a link,
// never the image itself.
 $adminBaseDomain    = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
 $adminSelfDir       = isset($_SERVER['PHP_SELF']) ? rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'])), '/') : '';
 $certificateExists  = file_exists(__DIR__ . '/image/certificate.png');
 $certificateAbsUrl  = $adminBaseDomain . $adminSelfDir . '/image/certificate.png';

// ★ JSON for View Modal
 $reviews_json = json_encode($rows, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Management — AI Assignment Checker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap">
    <style>
        :root {
            --primary: #6A0DAD; --primary-light: #9C27B0; --primary-dark: #4A0072;
            --primary-rgb: 106, 13, 173; --secondary-rgb: 156, 39, 176;
            --bg: #F3F0F7; --card-bg: rgba(255,255,255,0.78);
            --sidebar-width: 260px; --header-height: 70px;
            --text-dark: #2D1B4E; --text-muted: #7B6B8D;
            --border-color: rgba(106,13,173,0.08); --input-bg: #FFFFFF;
            --shadow-sm: 0 2px 8px rgba(106,13,173,0.06);
            --shadow-md: 0 4px 20px rgba(106,13,173,0.1);
            --shadow-lg: 0 8px 40px rgba(106,13,173,0.15);
            --radius: 16px; --radius-sm: 10px;
        }
        [data-theme="dark"] {
            --bg: #110B18; --card-bg: rgba(32,18,52,0.82);
            --text-dark: #E8E0F0; --text-muted: #9B8DB5;
            --border-color: rgba(156,39,176,0.12); --input-bg: rgba(45,27,78,0.6);
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.25);
            --shadow-md: 0 4px 20px rgba(0,0,0,0.35);
            --shadow-lg: 0 8px 40px rgba(0,0,0,0.45);
        }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Poppins',sans-serif; background:var(--bg); color:var(--text-dark); overflow-x:hidden; min-height:100vh; transition:background .35s ease,color .35s ease; }

        /* ═══ SIDEBAR ═══ */
        .sidebar { position:fixed; top:0; left:0; width:var(--sidebar-width); height:100vh; background:linear-gradient(180deg,var(--primary-dark) 0%,var(--primary) 50%,var(--primary-light) 100%); z-index:1050; transition:transform .35s cubic-bezier(.4,0,.2,1); display:flex; flex-direction:column; box-shadow:4px 0 30px rgba(106,13,173,0.3); }
        .sidebar-brand { padding:24px 20px; border-bottom:1px solid rgba(255,255,255,0.1); display:flex; align-items:center; gap:12px; }
        .sidebar-brand .brand-icon { width:52px; height:60px; background:rgba(255,255,255,0.15); border-radius:12px; overflow:hidden; backdrop-filter:blur(10px); flex-shrink:0; }
        .sidebar-brand .brand-icon img { width:100%; height:100%; object-fit:cover; }
        .sidebar-brand h5 { color:#fff; font-weight:700; font-size:15px; margin:0; line-height:1.3; }
        .sidebar-brand small { color:rgba(255,255,255,0.6); font-size:11px; }
        .sidebar-menu { flex:1; padding:16px 12px; overflow-y:auto; }
        .sidebar-menu .menu-label { color:rgba(255,255,255,0.4); font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:1.5px; padding:12px 14px 8px; }
        .sidebar-menu a { display:flex; align-items:center; gap:12px; padding:11px 14px; color:rgba(255,255,255,0.7); text-decoration:none; border-radius:var(--radius-sm); font-size:13.5px; font-weight:500; transition:all .25s ease; margin-bottom:2px; position:relative; }
        .sidebar-menu a i.fa-icon { width:20px; text-align:center; font-size:15px; }
        .sidebar-menu a:hover { background:rgba(255,255,255,0.1); color:#fff; transform:translateX(4px); }
        .sidebar-menu a.active { background:rgba(255,255,255,0.18); color:#fff; box-shadow:0 4px 15px rgba(0,0,0,0.15); }
        .sidebar-menu a.active::before { content:''; position:absolute; left:0; top:50%; transform:translateY(-50%); width:4px; height:60%; background:#fff; border-radius:0 4px 4px 0; }
        .sidebar-menu a .sidebar-noti-badge { margin-left:auto; background:#FF4757; color:#fff; font-size:10px; font-weight:700; padding:2px 7px; border-radius:10px; min-width:20px; text-align:center; line-height:1.4; }
        .sidebar-menu a.logout-btn { color:#FF6B8A; margin-top:20px; border-top:1px solid rgba(255,255,255,0.08); padding-top:16px; }
        .sidebar-menu a.logout-btn:hover { background:rgba(255,107,138,0.12); color:#FF6B8A; transform:translateX(4px); }
        .sidebar-footer { padding:16px 20px; border-top:1px solid rgba(255,255,255,0.1); }
        .sidebar-footer .admin-info { display:flex; align-items:center; gap:10px; }
        .sidebar-footer .admin-avatar-img { width:38px; height:38px; border-radius:10px; border:2px solid rgba(255,255,255,0.2); background:rgba(255,255,255,0.08); object-fit:cover; flex-shrink:0; }
        .sidebar-footer .admin-name { color:#fff; font-size:13px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:150px; }
        .sidebar-footer .admin-role { color:rgba(255,255,255,0.5); font-size:11px; }
        .sidebar-menu::-webkit-scrollbar { width: 4px; }

        /* ═══ MAIN ═══ */
        .main-content { margin-left:var(--sidebar-width); min-height:100vh; transition:margin-left .35s cubic-bezier(.4,0,.2,1); }
        .top-header { height:var(--header-height); background:rgba(255,255,255,0.8); backdrop-filter:blur(20px); -webkit-backdrop-filter:blur(20px); border-bottom:1px solid var(--border-color); display:flex; align-items:center; justify-content:space-between; padding:0 30px; position:sticky; top:0; z-index:1000; transition:background .35s ease; }
        [data-theme="dark"] .top-header { background:rgba(17,11,24,0.88); }
        .top-header .left-section { display:flex; align-items:center; gap:16px; }
        .sidebar-toggle { display:none; background:none; border:none; font-size:20px; color:var(--primary); cursor:pointer; padding:6px; border-radius:8px; transition:background .2s; }
        .sidebar-toggle:hover { background:rgba(var(--primary-rgb),0.08); }
        .top-header .page-title { font-size:18px; font-weight:700; color:var(--text-dark); transition:color .35s ease; }
        .top-header .page-title span { color:var(--primary); }
        .top-header .right-section { display:flex; align-items:center; gap:10px; }
        .header-btn { width:40px; height:40px; border-radius:12px; border:1px solid var(--border-color); background:#fff; display:flex; align-items:center; justify-content:center; color:var(--text-muted); font-size:16px; cursor:pointer; transition:all .25s ease; position:relative; }
        [data-theme="dark"] .header-btn { background:rgba(45,27,78,0.5); border-color:var(--border-color); color:var(--text-muted); }
        .header-btn:hover { border-color:var(--primary); color:var(--primary); box-shadow:var(--shadow-sm); }
        .header-time { font-size:12.5px; color:var(--text-muted); font-weight:500; background:rgba(var(--primary-rgb),0.05); padding:6px 14px; border-radius:8px; }
        [data-theme="dark"] .header-time { background:rgba(156,39,176,0.08); }

        /* ═══ NOTIFICATIONS ═══ */
        .notification-wrapper { position:relative; }
        .noti-badge { position:absolute; top:6px; right:6px; min-width:18px; height:18px; background:#FF4757; color:#fff; font-size:10px; font-weight:700; border-radius:50%; display:flex; align-items:center; justify-content:center; border:2px solid #fff; padding:0 3px; line-height:1; animation:notiPulse 2s ease-in-out infinite; }
        [data-theme="dark"] .noti-badge { border-color:#1A1025; }
        @keyframes notiPulse { 0%,100%{transform:scale(1);} 50%{transform:scale(1.15);} }
        .notification-dropdown { position:absolute; top:calc(100% + 12px); right:-8px; width:360px; max-height:440px; background:#fff; border:1px solid var(--border-color); border-radius:var(--radius); box-shadow:0 20px 60px rgba(0,0,0,0.15); opacity:0; visibility:hidden; transform:translateY(-8px) scale(0.97); transition:all .3s cubic-bezier(.16,1,.3,1); z-index:9999; overflow:hidden; display:flex; flex-direction:column; }
        [data-theme="dark"] .notification-dropdown { background:#1F1333; border-color:rgba(156,39,176,0.15); }
        .notification-dropdown.show { opacity:1; visibility:visible; transform:translateY(0) scale(1); }
        .notification-dropdown .noti-header { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid var(--border-color); flex-shrink:0; }
        .notification-dropdown .noti-header h6 { font-size:14px; font-weight:700; color:var(--text-dark); margin:0; display:flex; align-items:center; gap:8px; }
        .notification-dropdown .noti-header h6 .count { background:var(--primary); color:#fff; font-size:10px; padding:2px 7px; border-radius:8px; }
        .mark-read-btn { background:none; border:none; color:var(--primary); font-size:11.5px; font-weight:600; cursor:pointer; font-family:inherit; padding:4px 8px; border-radius:6px; transition:background .2s; }
        .mark-read-btn:hover { background:rgba(var(--primary-rgb),0.08); }
        .notification-dropdown .noti-list { overflow-y:auto; flex:1; }
        .notification-dropdown .noti-item { display:flex; align-items:flex-start; gap:12px; padding:13px 18px; border-bottom:1px solid var(--border-color); transition:background .2s; }
        .notification-dropdown .noti-item:last-child { border-bottom:none; }
        .notification-dropdown .noti-item:hover { background:rgba(var(--primary-rgb),0.03); }
        .notification-dropdown .noti-item.unread { background:rgba(var(--primary-rgb),0.04); }
        .notification-dropdown .noti-dot { width:8px; height:8px; border-radius:50%; background:#E0D4ED; flex-shrink:0; margin-top:6px; }
        .notification-dropdown .noti-dot.active { background:var(--primary); box-shadow:0 0 0 3px rgba(var(--primary-rgb),0.15); }
        .notification-dropdown .noti-content { flex:1; min-width:0; }
        .notification-dropdown .noti-content p { font-size:12.5px; color:var(--text-dark); margin:0 0 3px; line-height:1.45; }
        .notification-dropdown .noti-content span { font-size:11px; color:var(--text-muted); }
        .notification-dropdown .noti-icon { width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:13px; flex-shrink:0; margin-top:1px; }
        .notification-dropdown .noti-icon.assignment { background:rgba(33,150,243,0.1); color:#2196F3; }
        .notification-dropdown .noti-icon.register { background:rgba(76,175,80,0.1); color:#4CAF50; }
        .notification-dropdown .noti-icon.default { background:rgba(var(--primary-rgb),0.1); color:var(--primary); }
        .noti-empty { padding:40px 20px; text-align:center; color:var(--text-muted); font-size:13px; }
        .noti-empty i { font-size:28px; margin-bottom:8px; display:block; opacity:0.3; }

        .dashboard-body { padding:28px 30px 40px; }

        /* ═══ STAT CARDS ═══ */
        .stat-card { background:var(--card-bg); backdrop-filter:blur(20px); -webkit-backdrop-filter:blur(20px); border:1px solid var(--border-color); border-radius:var(--radius); padding:22px 20px; position:relative; overflow:hidden; box-shadow:var(--shadow-sm); transition:all .35s cubic-bezier(.4,0,.2,1); }
        .stat-card::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; border-radius:var(--radius) var(--radius) 0 0; opacity:0; transition:opacity .3s ease; }
        .stat-card:hover { transform:translateY(-6px); box-shadow:var(--shadow-lg); border-color:rgba(var(--primary-rgb),0.15); }
        .stat-card:hover::before { opacity:1; }
        .stat-card .s-icon { width:48px; height:48px; border-radius:14px; display:flex; align-items:center; justify-content:center; font-size:18px; margin-bottom:14px; transition:transform .3s ease; }
        .stat-card:hover .s-icon { transform:scale(1.1) rotate(-5deg); }
        .stat-card .s-value { font-size:26px; font-weight:800; color:var(--text-dark); line-height:1; margin-bottom:4px; letter-spacing:-0.5px; }
        .stat-card .s-label { font-size:12px; color:var(--text-muted); font-weight:500; }
        .stat-card .s-bg { position:absolute; right:-10px; bottom:-14px; font-size:80px; opacity:0.022; color:var(--primary); pointer-events:none; }
        .stat-card:hover .s-bg { opacity:0.05; }
        .c-purple::before { background:linear-gradient(90deg,#6A0DAD,#9C27B0); }
        .c-purple .s-icon { background:rgba(var(--primary-rgb),0.1); color:var(--primary); }
        .c-blue::before { background:linear-gradient(90deg,#1565C0,#42A5F5); }
        .c-blue .s-icon { background:rgba(33,150,243,0.1); color:#1976D2; }
        .c-green::before { background:linear-gradient(90deg,#2E7D32,#66BB6A); }
        .c-green .s-icon { background:rgba(76,175,80,0.1); color:#388E3C; }
        .c-orange::before { background:linear-gradient(90deg,#E65100,#FFA726); }
        .c-orange .s-icon { background:rgba(255,152,0,0.1); color:#F57C00; }
        .c-red::before { background:linear-gradient(90deg,#C62828,#EF5350); }
        .c-red .s-icon { background:rgba(244,67,54,0.1); color:#D32F2F; }

        /* ═══ TOOLBAR ═══ */
        .reviews-toolbar { background:var(--card-bg); backdrop-filter:blur(20px); border:1px solid var(--border-color); border-radius:var(--radius); padding:18px 22px; margin-bottom:20px; box-shadow:var(--shadow-sm); display:flex; flex-wrap:wrap; align-items:center; gap:12px; }
        .reviews-toolbar .search-box { position:relative; flex:1; min-width:220px; }
        .reviews-toolbar .search-box input { width:100%; padding:10px 14px 10px 40px; border:1px solid var(--border-color); border-radius:var(--radius-sm); background:var(--input-bg); color:var(--text-dark); font-family:inherit; font-size:13px; transition:all .25s ease; }
        .reviews-toolbar .search-box input:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(var(--primary-rgb),0.1); }
        .reviews-toolbar .search-box i { position:absolute; left:14px; top:50%; transform:translateY(-50%); color:var(--text-muted); font-size:14px; }
        .reviews-toolbar .filter-select { padding:10px 14px; border:1px solid var(--border-color); border-radius:var(--radius-sm); background:var(--input-bg); color:var(--text-dark); font-family:inherit; font-size:13px; min-width:170px; cursor:pointer; transition:all .25s ease; }
        .reviews-toolbar .filter-select:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(var(--primary-rgb),0.1); }
        .toolbar-btn { padding:10px 18px; border-radius:var(--radius-sm); border:none; font-family:inherit; font-size:13px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:8px; transition:all .25s ease; }
        .toolbar-btn-primary { background:var(--primary); color:#fff; }
        .toolbar-btn-primary:hover { background:var(--primary-dark); transform:translateY(-2px); box-shadow:0 4px 12px rgba(var(--primary-rgb),0.3); }
        .toolbar-btn-outline { background:transparent; color:var(--primary); border:1px solid var(--primary); }
        .toolbar-btn-outline:hover { background:var(--primary); color:#fff; transform:translateY(-2px); }
        .toolbar-btn-success { background:#388E3C; color:#fff; }
        .toolbar-btn-success:hover { background:#2E7D32; transform:translateY(-2px); box-shadow:0 4px 12px rgba(76,175,80,0.3); }

        /* ═══ TABLE ═══ */
        .table-card { background:var(--card-bg); backdrop-filter:blur(20px); border:1px solid var(--border-color); border-radius:var(--radius); box-shadow:var(--shadow-sm); overflow:hidden; }
        .table-card .table-header { padding:18px 22px; border-bottom:1px solid var(--border-color); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
        .table-card .table-header h6 { font-size:15px; font-weight:700; color:var(--text-dark); margin:0; display:flex; align-items:center; gap:8px; }
        .table-card .table-header h6 i { color:var(--primary); }
        .review-table { width:100%; margin:0; }
        .review-table thead th { background:rgba(var(--primary-rgb),0.04); color:var(--text-muted); font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.8px; padding:14px 16px; border-bottom:1px solid var(--border-color); white-space:nowrap; }
        [data-theme="dark"] .review-table thead th { background:rgba(45,27,78,0.5); }
        .review-table tbody td { padding:13px 16px; border-bottom:1px solid var(--border-color); font-size:13px; vertical-align:middle; }
        .review-table tbody tr { transition:background .2s ease; }
        .review-table tbody tr:hover { background:rgba(var(--primary-rgb),0.03); }
        [data-theme="dark"] .review-table tbody tr:hover { background:rgba(106,13,173,0.06); }
        .review-table .title-cell { max-width:180px; }
        .review-table .title-cell .t-main { font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:block; max-width:180px; }
        .review-table .comment-cell { max-width:150px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .action-btns { display:flex; gap:5px; flex-wrap:nowrap; }
        .act-btn { width:32px; height:32px; border-radius:8px; border:1px solid var(--border-color); background:var(--input-bg); display:inline-flex; align-items:center; justify-content:center; font-size:12px; cursor:pointer; transition:all .2s ease; color:var(--text-muted); text-decoration:none; }
        .act-btn:hover { transform:translateY(-2px); }
        .act-btn-view:hover { background:rgba(33,150,243,0.1); color:#1976D2; border-color:rgba(33,150,243,0.3); }
        .act-btn-edit:hover { background:rgba(var(--primary-rgb),0.1); color:var(--primary); border-color:rgba(var(--primary-rgb),0.3); }
        .act-btn-delete:hover { background:rgba(244,67,54,0.1); color:#D32F2F; border-color:rgba(244,67,54,0.3); }
        .act-btn-add:hover { background:rgba(76,175,80,0.1); color:#388E3C; border-color:rgba(76,175,80,0.3); }
        .status-select { padding:5px 8px; border:1px solid var(--border-color); border-radius:8px; background:var(--input-bg); color:var(--text-dark); font-family:inherit; font-size:12px; font-weight:500; cursor:pointer; min-width:100px; transition:all .2s; }
        .status-select:focus { outline:none; border-color:var(--primary); }
        .file-link { color:var(--primary); text-decoration:none; font-weight:500; font-size:12px; display:inline-flex; align-items:center; gap:4px; }
        .file-link:hover { text-decoration:underline; }
        .empty-table { padding:50px 20px; text-align:center; color:var(--text-muted); }
        .empty-table i { font-size:40px; margin-bottom:12px; display:block; opacity:0.25; }
        .empty-table p { font-size:14px; margin:0; }

        /* ═══ PAGINATION ═══ */
        .pagination-wrap { padding:16px 22px; border-top:1px solid var(--border-color); display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
        .pagination-info { font-size:12.5px; color:var(--text-muted); }
        .pagination .page-link { border:1px solid var(--border-color); color:var(--text-dark); background:var(--input-bg); font-size:13px; font-weight:500; padding:8px 14px; margin:0 2px; border-radius:var(--radius-sm) !important; transition:all .2s; }
        .pagination .page-link:hover { background:rgba(var(--primary-rgb),0.08); border-color:var(--primary); color:var(--primary); }
        .pagination .page-item.active .page-link { background:var(--primary); border-color:var(--primary); color:#fff; }
        .pagination .page-item.disabled .page-link { opacity:0.4; pointer-events:none; }

        /* ═══ CHART ═══ */
        .chart-card { background:var(--card-bg); backdrop-filter:blur(20px); border:1px solid var(--border-color); border-radius:var(--radius); padding:24px; box-shadow:var(--shadow-sm); transition:box-shadow .35s ease; display:flex; flex-direction:column; }
        .chart-card:hover { box-shadow:var(--shadow-md); }
        .ch-head { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:20px; flex-wrap:wrap; gap:8px; }
        .ch-title { font-size:16px; font-weight:700; color:var(--text-dark); margin-bottom:2px; }
        .ch-sub { font-size:12px; color:var(--text-muted); font-weight:400; }
        .ch-badge { display:inline-flex; align-items:center; gap:6px; background:rgba(var(--primary-rgb),0.07); color:var(--primary); padding:5px 14px; border-radius:20px; font-size:11px; font-weight:600; white-space:nowrap; }
        .ch-body { flex:1; display:flex; align-items:center; justify-content:center; position:relative; max-width:420px; margin:0 auto; width:100%; }
        .donut-chart { width:200px; height:200px; border-radius:50%; position:relative; display:flex; align-items:center; justify-content:center; }
        .donut-hole { width:120px; height:120px; border-radius:50%; background:var(--card-bg); display:flex; flex-direction:column; align-items:center; justify-content:center; position:relative; z-index:2; box-shadow:inset 0 2px 10px rgba(0,0,0,0.04); }
        .donut-hole .dh-value { font-size:28px; font-weight:800; color:var(--text-dark); line-height:1; }
        .donut-hole .dh-label { font-size:10px; color:var(--text-muted); font-weight:500; margin-top:2px; }
        .chart-legend { display:flex; flex-direction:column; gap:10px; margin-left:30px; }
        .legend-item { display:flex; align-items:center; gap:10px; font-size:13px; color:var(--text-dark); }
        .legend-dot { width:12px; height:12px; border-radius:4px; flex-shrink:0; }
        .legend-item .legend-val { font-weight:700; margin-left:auto; padding-left:16px; }

        /* ═══ MODALS ═══ */
        .modal-content { border:none; border-radius:var(--radius); box-shadow:var(--shadow-lg); }
        [data-theme="dark"] .modal-content { background:#1A1025; border:1px solid rgba(156,39,176,0.15); color:var(--text-dark); }
        [data-theme="dark"] .modal-header { border-color:var(--border-color); }
        [data-theme="dark"] .modal-footer { border-color:var(--border-color); }
        .modal-header { border-bottom:1px solid var(--border-color); padding:20px 24px; }
        .modal-header .modal-title { font-size:17px; font-weight:700; display:flex; align-items:center; gap:10px; }
        .modal-header .modal-title i { color:var(--primary); }
        .modal-body { padding:24px; }
        .modal-footer { border-top:1px solid var(--border-color); padding:16px 24px; }
        .detail-row { display:flex; padding:10px 0; border-bottom:1px solid var(--border-color); }
        .detail-row:last-child { border-bottom:none; }
        .detail-label { width:140px; flex-shrink:0; font-size:13px; font-weight:600; color:var(--text-muted); }
        .detail-value { font-size:13px; color:var(--text-dark); font-weight:500; word-break:break-word; }
        .form-label-custom { font-size:13px; font-weight:600; color:var(--text-dark); margin-bottom:6px; }
        .form-control-custom, .form-select-custom { border:1px solid var(--border-color); border-radius:var(--radius-sm); background:var(--input-bg); color:var(--text-dark); font-family:inherit; font-size:13px; padding:10px 14px; transition:all .25s ease; width:100%; }
        .form-control-custom:focus, .form-select-custom:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(var(--primary-rgb),0.1); }
        [data-theme="dark"] .form-control-custom, [data-theme="dark"] .form-select-custom { background:var(--input-bg); border-color:var(--border-color); color:var(--text-dark); }
        .form-hint { font-size:11px; color:var(--text-muted); margin-top:4px; }
        .btn-primary-custom { background:var(--primary); color:#fff; border:none; border-radius:var(--radius-sm); padding:10px 24px; font-family:inherit; font-size:13px; font-weight:600; cursor:pointer; transition:all .25s ease; display:inline-flex; align-items:center; gap:8px; }
        .btn-primary-custom:hover { background:var(--primary-dark); transform:translateY(-2px); box-shadow:0 4px 15px rgba(var(--primary-rgb),0.35); }
        .btn-secondary-custom { background:transparent; color:var(--text-muted); border:1px solid var(--border-color); border-radius:var(--radius-sm); padding:10px 24px; font-family:inherit; font-size:13px; font-weight:600; cursor:pointer; transition:all .25s ease; }
        .btn-secondary-custom:hover { background:rgba(var(--primary-rgb),0.05); color:var(--text-dark); }
        .btn-danger-custom { background:#D32F2F; color:#fff; border:none; border-radius:var(--radius-sm); padding:10px 24px; font-family:inherit; font-size:13px; font-weight:600; cursor:pointer; transition:all .25s ease; display:inline-flex; align-items:center; gap:8px; }
        .btn-danger-custom:hover { background:#C62828; transform:translateY(-2px); box-shadow:0 4px 15px rgba(244,67,54,0.35); }

        /* ═══ SETTINGS PANEL ═══ */
        .settings-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:1060; backdrop-filter:blur(4px); opacity:0; transition:opacity .3s ease; }
        .settings-overlay.show { display:block; opacity:1; }
        .settings-panel { position:fixed; top:0; right:0; width:340px; max-width:90vw; height:100vh; background:#fff; border-left:1px solid var(--border-color); z-index:1070; transform:translateX(100%); transition:transform .35s cubic-bezier(.4,0,.2,1); display:flex; flex-direction:column; box-shadow:-8px 0 40px rgba(0,0,0,0.1); }
        [data-theme="dark"] .settings-panel { background:#1A1025; border-left-color:rgba(156,39,176,0.15); }
        .settings-panel.show { transform:translateX(0); }
        .settings-panel-header { display:flex; align-items:center; justify-content:space-between; padding:22px 24px; border-bottom:1px solid var(--border-color); }
        .settings-panel-header h5 { font-size:17px; font-weight:700; color:var(--text-dark); margin:0; display:flex; align-items:center; gap:10px; }
        .settings-panel-header h5 i { color:var(--primary); }
        .settings-close-btn { width:36px; height:36px; border-radius:10px; border:1px solid var(--border-color); background:transparent; display:flex; align-items:center; justify-content:center; color:var(--text-muted); font-size:14px; cursor:pointer; transition:all .2s; }
        .settings-close-btn:hover { background:rgba(244,67,54,0.08); border-color:rgba(244,67,54,0.2); color:#F44336; }
        .settings-body { flex:1; overflow-y:auto; padding:8px 0; }
        .settings-section { padding:20px 24px; border-bottom:1px solid var(--border-color); }
        .settings-label { font-size:14px; font-weight:600; color:var(--text-dark); margin-bottom:4px; }
        .settings-desc { font-size:12px; color:var(--text-muted); margin-bottom:14px; line-height:1.5; }
        .theme-toggle-row { display:flex; align-items:center; justify-content:space-between; }
        .theme-toggle-options { display:flex; align-items:center; gap:10px; }
        .theme-toggle-options i { font-size:15px; }
        .theme-toggle-options .fa-sun { color:#FF9800; }
        .theme-toggle-options .fa-moon { color:#5C6BC0; }
        .theme-switch { position:relative; width:52px; height:28px; display:inline-block; cursor:pointer; }
        .theme-switch input { opacity:0; width:0; height:0; position:absolute; }
        .theme-switch .slider { position:absolute; inset:0; background:#E0D4ED; border-radius:28px; transition:all .35s cubic-bezier(.4,0,.2,1); }
        .theme-switch .slider::before { content:''; position:absolute; width:22px; height:22px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:all .35s cubic-bezier(.4,0,.2,1); box-shadow:0 2px 6px rgba(0,0,0,0.15); }
        .theme-switch input:checked + .slider { background:var(--primary); }
        .theme-switch input:checked + .slider::before { transform:translateX(24px); }
        .sidebar-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1040; backdrop-filter:blur(4px); }
        .sidebar-overlay.show { display:block; }

        /* ═══ FLASH MESSAGE ═══ */
        .flash-container { margin-bottom:20px; }
        .flash-msg { padding:14px 20px; border-radius:var(--radius-sm); font-size:13px; font-weight:500; display:flex; align-items:center; gap:10px; animation:flashIn .4s ease; }
        .flash-msg.flash-danger { background:rgba(244,67,54,0.08); border:1px solid rgba(244,67,54,0.2); color:#C62828; }
        .flash-msg.flash-success { background:rgba(76,175,80,0.08); border:1px solid rgba(76,175,80,0.2); color:#2E7D32; }
        .flash-msg .flash-close { margin-left:auto; background:none; border:none; font-size:16px; cursor:pointer; color:inherit; opacity:0.6; transition:opacity .2s; }
        .flash-msg .flash-close:hover { opacity:1; }
        @keyframes flashIn { from { opacity:0; transform:translateY(-10px); } to { opacity:1; transform:translateY(0); } }

        /* ═══ SEARCHABLE ASSIGNMENT DROPDOWN ═══ */
        .assignment-search-wrapper { position: relative; }
        .assignment-search-wrapper .form-control-custom { padding-right: 36px; }
        .assignment-search-wrapper .clear-search-btn {
            position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
            background: none; border: none; color: var(--text-muted); font-size: 13px;
            cursor: pointer; padding: 4px; display: none; transition: color .2s;
        }
        .assignment-search-wrapper .clear-search-btn:hover { color: #D32F2F; }
        .assignment-search-wrapper .clear-search-btn.visible { display: block; }
        .assignment-dropdown {
            position: absolute; top: calc(100% + 4px); left: 0; right: 0;
            max-height: 260px; overflow-y: auto; background: var(--input-bg);
            border: 1px solid var(--border-color); border-radius: var(--radius-sm);
            box-shadow: var(--shadow-md); z-index: 1060;
            display: none; padding: 6px 0;
        }
        .assignment-dropdown.show { display: block; animation: dropFadeIn .2s ease; }
        @keyframes dropFadeIn { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:translateY(0); } }
        .assignment-dropdown-item {
            padding: 10px 14px; cursor: pointer; transition: background .15s ease;
            border-bottom: 1px solid var(--border-color);
        }
        .assignment-dropdown-item:last-child { border-bottom: none; }
        .assignment-dropdown-item:hover { background: rgba(var(--primary-rgb),0.06); }
        .assignment-dropdown-item.selected { background: rgba(var(--primary-rgb),0.1); }
        .assignment-dropdown-item .addi-id { font-size: 11px; font-weight: 700; color: var(--primary); margin-bottom: 2px; }
        .assignment-dropdown-item .addi-title { font-size: 13px; font-weight: 600; color: var(--text-dark); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .assignment-dropdown-item .addi-user { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
        .assignment-dropdown-empty { padding: 20px; text-align: center; color: var(--text-muted); font-size: 13px; }
        .assignment-dropdown-empty i { display: block; font-size: 20px; margin-bottom: 6px; opacity: 0.3; }
        .assignment-dropdown::-webkit-scrollbar { width: 5px; }
        .assignment-dropdown::-webkit-scrollbar-thumb { background: rgba(var(--primary-rgb),0.2); border-radius: 10px; }

        /* ═══ VIEW MODAL WITH BLUR ═══ */
        .view-modal-overlay {
            position: fixed; inset: 0; z-index: 9999;
            background: rgba(45, 27, 78, 0.45);
            display: flex; align-items: center; justify-content: center;
            opacity: 0; visibility: hidden;
            transition: opacity .3s ease, visibility .3s ease;
            padding: 20px;
        }
        .view-modal-overlay.show { opacity: 1; visibility: visible; }
        .view-modal-card {
            background: var(--card-bg); backdrop-filter: blur(30px); -webkit-backdrop-filter: blur(30px);
            border: 1px solid var(--border-color); border-radius: var(--radius);
            box-shadow: 0 25px 80px rgba(0,0,0,0.25); width: 100%; max-width: 560px;
            max-height: 85vh; display: flex; flex-direction: column;
            transform: scale(0.92) translateY(20px);
            transition: transform .35s cubic-bezier(.16,1,.3,1);
        }
        .view-modal-overlay.show .view-modal-card { transform: scale(1) translateY(0); }
        .view-modal-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 20px 24px; border-bottom: 1px solid var(--border-color); flex-shrink: 0;
        }
        .view-modal-header h6 { font-size: 17px; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 10px; color: var(--text-dark); }
        .view-modal-header h6 i { color: var(--primary); }
        .view-modal-close {
            width: 36px; height: 36px; border-radius: 10px; border: 1px solid var(--border-color);
            background: transparent; display: flex; align-items: center; justify-content: center;
            color: var(--text-muted); font-size: 14px; cursor: pointer; transition: all .2s;
        }
        .view-modal-close:hover { background: rgba(244,67,54,0.08); border-color: rgba(244,67,54,0.2); color: #F44336; }
        .view-modal-body { padding: 24px; overflow-y: auto; flex: 1; }
        .view-detail-row { display: flex; padding: 11px 0; border-bottom: 1px solid var(--border-color); }
        .view-detail-row:last-child { border-bottom: none; }
        .view-detail-label { width: 145px; flex-shrink: 0; font-size: 12.5px; font-weight: 600; color: var(--text-muted); }
        .view-detail-value { font-size: 13px; color: var(--text-dark); font-weight: 500; word-break: break-word; flex: 1; }
        .view-detail-value.vd-comment { white-space: pre-wrap; line-height: 1.6; background: rgba(var(--primary-rgb),0.03); padding: 10px 14px; border-radius: 10px; margin: -2px 0; }
        .view-vcode-box {
            font-family: monospace; font-size: 13px; font-weight: 700; color: #6A0DAD;
            background: rgba(106,13,173,0.07); padding: 6px 12px; border-radius: 8px;
            display: inline-flex; align-items: center; gap: 8px; cursor: pointer;
            transition: background .2s;
        }
        .view-vcode-box:hover { background: rgba(106,13,173,0.13); }
        .view-vcode-box i { font-size: 11px; opacity: 0.6; }

        /* ═══ ANIMATIONS ═══ */
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .animate-in { animation: fadeInUp 0.5s ease forwards; opacity: 0; }
        .animate-in:nth-child(1) { animation-delay: 0.05s; }
        .animate-in:nth-child(2) { animation-delay: 0.1s; }
        .animate-in:nth-child(3) { animation-delay: 0.15s; }
        .animate-in:nth-child(4) { animation-delay: 0.2s; }
        .chart-animate { animation: fadeInUp 0.6s ease 0.3s forwards; opacity: 0; }

        /* ═══ SCROLLBAR ═══ */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(var(--primary-rgb),0.2); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: rgba(var(--primary-rgb),0.35); }

        /* ═══ RESPONSIVE ═══ */
        @media (max-width: 991.98px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.show { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .sidebar-toggle { display: flex; }
            .dashboard-body { padding: 20px 16px 30px; }
            .top-header { padding: 0 16px; }
            .header-time { display: none; }
            .notification-dropdown { width: 320px; right: -40px; }
            .reviews-toolbar { flex-direction: column; }
            .reviews-toolbar .search-box { min-width: 100%; }
            .review-table { font-size: 12px; }
            .review-table thead th, .review-table tbody td { padding: 10px 10px; }
        }
        @media (max-width: 575.98px) {
            .stat-card .s-value { font-size: 22px; }
            .stat-card .s-icon { width: 42px; height: 42px; font-size: 16px; }
            .notification-dropdown { width: calc(100vw - 32px); right: -60px; max-height: 380px; }
            .settings-panel { width: 100vw; max-width: 100vw; }
            .view-modal-card { max-width: 100%; }
            .ch-body { flex-direction: column; }
            .chart-legend { margin-left: 0; margin-top: 20px; }
        }
    </style>
</head>
<body>

    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <!-- Settings Overlay -->
    <div class="settings-overlay" id="settingsOverlay"></div>

    <!-- ═══════ SIDEBAR ═══════ -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="brand-icon"><img src="image/logo.png" alt="Logo"></div>
            <div>
                <h5>AI Checker</h5>
                <small>Admin Panel</small>
            </div>
        </div>
        <nav class="sidebar-menu">
            <div class="menu-label">Main</div>
            <a href="adminpage.php">
                <i class="fas fa-th-large fa-icon"></i> Dashboard
            </a>
            <a href="admin_users.php">
                <i class="fas fa-users fa-icon"></i> Users
            </a>
            <a href="admin_assignments.php">
                <i class="fas fa-file-alt fa-icon"></i> Assignments
            </a>
            <a href="admin_reviews.php" class="active">
                <i class="fas fa-star fa-icon"></i> Reviews
            </a>
            <a href="ai_analysis.php">
                <i class="fas fa-magnifying-glass-chart fa-icon"></i> Analysis
            </a>
            <div class="menu-label">Management</div>
            <a href="admin_plans.php">
                <i class="fas fa-tags fa-icon"></i> Plans
            </a>
            <a href="admin_payments.php">
                <i class="fas fa-credit-card fa-icon"></i> Payments
            </a>
            <a href="admin_vouchers.php">
                <i class="fas fa-ticket-alt fa-icon"></i> Vouchers
            </a>
            <a href="admin_testimonials.php">
                <i class="fas fa-quote-right fa-icon"></i> Testimonials
            </a>
            <a href="admin_contacts.php">
                <i class="fas fa-phone-alt fa-icon"></i> Contacts
            </a>
            <a href="login.php" class="logout-btn">
                <i class="fas fa-sign-out-alt fa-icon"></i> Logout
            </a>
        </nav>
        <div class="sidebar-footer">
            <div class="admin-info">
                <img src="<?php echo htmlspecialchars($avatar); ?>" class="admin-avatar-img" alt="<?php echo htmlspecialchars($admin_name); ?>" onerror="this.src='https://api.dicebear.com/7.x/avataaars/svg?seed=Admin&backgroundColor=ede9fe';">
                <div>
                    <div class="admin-name"><?php echo htmlspecialchars($admin_name); ?></div>
                    <div class="admin-role">Administrator</div>
                </div>
            </div>
        </div>
    </aside>

    <!-- ═══════ SETTINGS PANEL ═══════ -->
    <div class="settings-panel" id="settingsPanel">
        <div class="settings-panel-header">
            <h5><i class="fas fa-cog"></i> Settings</h5>
            <button class="settings-close-btn" id="settingsCloseBtn" aria-label="Close settings"><i class="fas fa-times"></i></button>
        </div>
        <div class="settings-body">
            <div class="settings-section">
                <div class="settings-label">Appearance</div>
                <div class="settings-desc">Choose between light and dark mode for your dashboard.</div>
                <div class="theme-toggle-row">
                    <div class="theme-toggle-options">
                        <i class="fas fa-sun"></i>
                        <label class="theme-switch">
                            <input type="checkbox" id="themeToggle">
                            <span class="slider"></span>
                        </label>
                        <i class="fas fa-moon"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════ MAIN CONTENT ═══════ -->
    <main class="main-content">

        <!-- Top Header -->
        <header class="top-header">
            <div class="left-section">
                <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
                <h1 class="page-title"><span>Review</span> Management</h1>
            </div>
            <div class="right-section">
                <span class="header-time" id="headerTime"></span>
                <div class="notification-wrapper">
                    <button class="header-btn" id="notiBtn" aria-label="Notifications">
                        <i class="fas fa-bell"></i>
                        <?php if ($unread_count > 0): ?>
                        <span class="noti-badge"><?php echo $unread_count; ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="notification-dropdown" id="notiDropdown">
                        <div class="noti-header">
                            <h6>Notifications <span class="count"><?php echo $unread_count; ?></span></h6>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="mark_all_read">
                                <button type="submit" class="mark-read-btn">Mark all read</button>
                            </form>
                        </div>
                        <div class="noti-list">
                            <?php if (empty($notifications)): ?>
                            <div class="noti-empty"><i class="fas fa-bell-slash"></i>No notifications yet</div>
                            <?php else: ?>
                                <?php foreach ($notifications as $n):
                                    $icon_class = 'default';
                                    if (stripos($n['message'], 'assignment') !== false) $icon_class = 'assignment';
                                    elseif (stripos($n['message'], 'registered') !== false || stripos($n['message'], 'user') !== false) $icon_class = 'register';
                                ?>
                                <div class="noti-item <?php echo $n['is_read'] == 0 ? 'unread' : ''; ?>">
                                    <div class="noti-dot <?php echo $n['is_read'] == 0 ? 'active' : ''; ?>"></div>
                                    <div class="noti-icon <?php echo $icon_class; ?>">
                                        <i class="fas fa-<?php echo $icon_class === 'assignment' ? 'file-alt' : ($icon_class === 'register' ? 'user-plus' : 'info-circle'); ?>"></i>
                                    </div>
                                    <div class="noti-content">
                                        <p><?php echo htmlspecialchars($n['message']); ?></p>
                                        <span><?php echo time_ago($n['created_at']); ?></span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <button class="header-btn" id="settingsBtn" aria-label="Settings"><i class="fas fa-cog"></i></button>
            </div>
        </header>

        <!-- Dashboard Body -->
        <div class="dashboard-body">

            <!-- ═══ FLASH MESSAGE ═══ -->
            <?php if (!empty($flash_msg)): ?>
            <div class="flash-container">
                <div class="flash-msg flash-<?php echo $flash_type; ?>">
                    <i class="fas fa-<?php echo $flash_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                    <span><?php echo $flash_msg; ?></span>
                    <button class="flash-close" onclick="this.parentElement.remove()"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <?php endif; ?>

            <!-- ═══ ROW 1 — STATISTICS CARDS ═══ -->
            <div class="row g-3 mb-4">
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card c-purple animate-in">
                        <div class="s-icon"><i class="fas fa-star"></i></div>
                        <div class="s-value"><?php echo number_format($total_reviews); ?></div>
                        <div class="s-label">Total Reviews</div>
                        <i class="fas fa-star s-bg"></i>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card c-blue animate-in">
                        <div class="s-icon"><i class="fas fa-check-double"></i></div>
                        <div class="s-value"><?php echo number_format($total_marked); ?></div>
                        <div class="s-label">Marked Assignments</div>
                        <i class="fas fa-check-double s-bg"></i>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card c-green animate-in">
                        <div class="s-icon"><i class="fas fa-chart-bar"></i></div>
                        <div class="s-value"><?php echo $avg_marks; ?></div>
                        <div class="s-label">Average Marks</div>
                        <i class="fas fa-chart-bar s-bg"></i>
                    </div>
                </div>
                <div class="col-xl-3 col-md-6">
                    <div class="stat-card c-orange animate-in">
                        <div class="s-icon"><i class="fas fa-hourglass-half"></i></div>
                        <div class="s-value"><?php echo number_format($pending_count); ?></div>
                        <div class="s-label">Pending Assignments</div>
                        <i class="fas fa-hourglass-half s-bg"></i>
                    </div>
                </div>
            </div>

            <!-- ═══ ROW 2 — TOOLBAR ═══ -->
            <div class="reviews-toolbar">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <form method="GET" style="display:flex;gap:0;" onsubmit="this.search.value = this.search.value.trim();">
                        <input type="text" name="search" placeholder="Search by ID, name, title..." value="<?php echo esc($current_search); ?>">
                        <input type="hidden" name="filter" value="<?php echo esc($current_filter); ?>">
                    </form>
                </div>
                <select class="filter-select" onchange="window.location.href='<?php echo basename($_SERVER['PHP_SELF']); ?>?search=<?php echo urlencode($current_search); ?>&filter=' + this.value">
                    <option value="all" <?php echo $current_filter === 'all' ? 'selected' : ''; ?>>All Reviews</option>
                    <option value="not_reviewed" <?php echo $current_filter === 'not_reviewed' ? 'selected' : ''; ?>>Not Reviewed</option>
                    <option value="high_marks" <?php echo $current_filter === 'high_marks' ? 'selected' : ''; ?>>High Marks (≥80)</option>
                    <option value="low_marks" <?php echo $current_filter === 'low_marks' ? 'selected' : ''; ?>>Low Marks (&lt;50)</option>
                </select>
                <button class="toolbar-btn toolbar-btn-primary" data-bs-toggle="modal" data-bs-target="#addReviewModal"><i class="fas fa-plus"></i> Add Review</button>
                <a href="?export=csv" class="toolbar-btn toolbar-btn-success"><i class="fas fa-file-csv"></i> Export CSV</a>
            </div>

            <!-- ═══ ROW 3 — TABLE + CHART ═══ -->
            <div class="row g-3">

                <!-- Table Card -->
                <div class="col-lg-8">
                    <div class="table-card">
                        <div class="table-header">
                            <h6><i class="fas fa-list"></i> <?php echo $is_not_reviewed ? 'Not Reviewed Assignments' : 'Review Records'; ?></h6>
                            <span style="font-size:12px;color:var(--text-muted);"><?php echo number_format($total_records); ?> records</span>
                        </div>
                        <div class="table-responsive">
                            <table class="review-table">
                                <thead>
                                    <tr>
                                        <?php if ($is_not_reviewed): ?>
                                        <th>ID</th>
                                        <th>Title</th>
                                        <th>Student</th>
                                        <th>Submitted</th>
                                        <th>Action</th>
                                        <?php else: ?>
                                        <th>Rev. ID</th>
                                        <th>Assign. ID</th>
                                        <th>Title</th>
                                        <th>AI Score</th>
                                        <th>Similarity</th>
                                        <th>Marks</th>
                                        <th>Cert. ID</th>
                                        <th>Actions</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($rows)): ?>
                                    <tr>
                                        <td colspan="<?php echo $is_not_reviewed ? 5 : 8; ?>">
                                            <div class="empty-table">
                                                <i class="fas fa-inbox"></i>
                                                <p>No records found</p>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php if ($is_not_reviewed): ?>
                                            <?php foreach ($rows as $r): ?>
                                            <tr>
                                                <td><strong>#<?php echo $r['assignment_id']; ?></strong></td>
                                                <td class="title-cell"><span class="t-main" title="<?php echo esc($r['title']); ?>"><?php echo esc($r['title']); ?></span></td>
                                                <td><?php echo esc($r['user_name']); ?></td>
                                                <td style="font-size:12px;color:var(--text-muted);"><?php echo $r['submission_date'] ? date('d M Y', strtotime($r['submission_date'])) : '—'; ?></td>
                                                <td>
                                                    <div class="action-btns">
                                                        <button class="act-btn act-btn-add" title="Add Review" onclick="openAddForAssignment(<?php echo $r['assignment_id']; ?>)"><i class="fas fa-plus"></i></button>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <?php foreach ($rows as $r): ?>
                                            <tr>
                                                <td><strong>#<?php echo $r['review_id']; ?></strong></td>
                                                <td>#<?php echo $r['assignment_id']; ?></td>
                                                <td class="title-cell"><span class="t-main" title="<?php echo esc($r['title']); ?>"><?php echo esc($r['title']); ?></span></td>
                                                <td><?php echo ai_score_badge($r['ai_score']); ?></td>
                                                <td><?php echo similarity_badge($r['similarity']); ?></td>
                                                <td><?php echo marks_badge($r['marks']); ?></td>
                                                <td><?php echo vcode_badge($r['verification_code']); ?></td>
                                                <td>
                                                    <div class="action-btns">
                                                        <a href="javascript:void(0)" class="act-btn act-btn-view" onclick="viewReview(<?php echo $r['review_id']; ?>)" title="View"><i class="fas fa-eye"></i></a>
                                                        <button class="act-btn act-btn-edit" title="Edit" onclick="openEditModal(<?php echo $r['review_id']; ?>)"><i class="fas fa-pen"></i></button>
                                                        <button class="act-btn act-btn-delete" title="Delete" data-bs-toggle="modal" data-bs-target="#deleteModal" onclick="setDeleteTarget(<?php echo $r['review_id']; ?>, <?php echo $r['assignment_id']; ?>)"><i class="fas fa-trash"></i></button>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($total_pages > 1): ?>
                        <div class="pagination-wrap">
                            <div class="pagination-info">Showing <?php echo ($offset + 1) . '–' . min($offset + $per_page, $total_records) . ' of ' . number_format($total_records); ?></div>
                            <nav>
                                <ul class="pagination mb-0">
                                    <?php if ($current_page > 1): ?>
                                    <li class="page-item"><a class="page-link" href="<?php echo build_url(['page' => $current_page - 1]); ?>"><i class="fas fa-chevron-left"></i></a></li>
                                    <?php endif; ?>
                                    <?php for ($p = 1; $p <= $total_pages; $p++):
                                        if ($total_pages > 7 && $p > 3 && $p < $total_pages - 1 && abs($p - $current_page) > 1) {
                                            if ($p === 4 || $p === $total_pages - 2) echo '<li class="page-item disabled"><span class="page-link">…</span></li>';
                                            continue;
                                        }
                                    ?>
                                    <li class="page-item <?php echo $p === $current_page ? 'active' : ''; ?>"><a class="page-link" href="<?php echo build_url(['page' => $p]); ?>"><?php echo $p; ?></a></li>
                                    <?php endfor; ?>
                                    <?php if ($current_page < $total_pages): ?>
                                    <li class="page-item"><a class="page-link" href="<?php echo build_url(['page' => $current_page + 1]); ?>"><i class="fas fa-chevron-right"></i></a></li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Chart Card -->
                <div class="col-lg-4">
                    <div class="chart-card chart-animate">
                        <div class="ch-head">
                            <div>
                                <div class="ch-title">Review Progress</div>
                                <div class="ch-sub">Assignment review coverage</div>
                            </div>
                            <span class="ch-badge"><i class="fas fa-chart-pie"></i> Donut</span>
                        </div>
                        <div class="ch-body">
                            <div class="donut-chart" style="background: conic-gradient(#6A0DAD 0% <?php echo $pie_reviewed_pct; ?>%, #E0D4ED <?php echo $pie_reviewed_pct; ?>% 100%);">
                                <div class="donut-hole">
                                    <div class="dh-value"><?php echo $pie_reviewed_pct; ?>%</div>
                                    <div class="dh-label">Reviewed</div>
                                </div>
                            </div>
                            <div class="chart-legend">
                                <div class="legend-item">
                                    <div class="legend-dot" style="background:#6A0DAD;"></div>
                                    <span>Reviewed</span>
                                    <span class="legend-val"><?php echo $reviewed_assignments; ?></span>
                                </div>
                                <div class="legend-item">
                                    <div class="legend-dot" style="background:#E0D4ED;"></div>
                                    <span>Not Reviewed</span>
                                    <span class="legend-val"><?php echo $not_reviewed_count; ?></span>
                                </div>
                                <div class="legend-item" style="margin-top:6px;padding-top:6px;border-top:1px solid var(--border-color);">
                                    <div class="legend-dot" style="background:var(--primary);border-radius:50%;"></div>
                                    <span>Total</span>
                                    <span class="legend-val"><?php echo $total_assignments_all; ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <!-- ═══════ ADD REVIEW MODAL ═══════ -->
    <div class="modal fade" id="addReviewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Add Review</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="addReviewForm">
                    <input type="hidden" name="action" value="add_review">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="form-label-custom">Assignment <span class="text-danger">*</span></label>
                                <div class="assignment-search-wrapper">
                                    <input type="text" id="assignmentSearch" class="form-control-custom" placeholder="Type or click to search assignment..." autocomplete="off" required>
                                    <button type="button" class="clear-search-btn" id="clearSearchBtn" title="Clear selection"><i class="fas fa-times-circle"></i></button>
                                    <input type="hidden" name="assignment_id" id="assignmentHiddenId" value="">
                                    <div class="assignment-dropdown" id="assignmentDropdown">
                                        <?php if (empty($pending_assignments)): ?>
                                        <div class="assignment-dropdown-empty"><i class="fas fa-inbox"></i>No pending assignments</div>
                                        <?php else: ?>
                                            <?php foreach ($pending_assignments as $pa): ?>
                                            <div class="assignment-dropdown-item" data-id="<?php echo $pa['assignment_id']; ?>">
                                                <div class="addi-id">#<?php echo $pa['assignment_id']; ?></div>
                                                <div class="addi-title"><?php echo esc($pa['title']); ?></div>
                                                <div class="addi-user"><i class="fas fa-user" style="font-size:10px;margin-right:3px;"></i><?php echo esc($pa['user_name']); ?></div>
                                            </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="form-hint">Click the field to see all pending assignments, or type to filter.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-custom">AI Score (%)</label>
                                <input type="number" name="ai_score" class="form-control-custom" min="0" max="100" step="0.01" placeholder="e.g. 15.5">
                                <div class="form-hint">Percentage of AI-generated content detected</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-custom">Similarity (%)</label>
                                <input type="number" name="similarity" class="form-control-custom" min="0" max="100" step="0.01" placeholder="e.g. 8.2">
                                <div class="form-hint">Plagiarism similarity percentage</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-custom">Marks (0–100)</label>
                                <input type="number" name="marks" class="form-control-custom" min="0" max="100" step="1" placeholder="e.g. 85">
                                <div class="form-hint">Leave empty to set status as "Checking"</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-custom">Reviewed File</label>
                                <input type="file" name="reviewed_file" class="form-control-custom" accept=".pdf,.doc,.docx,.txt">
                                <div class="form-hint">PDF, DOC, DOCX, TXT — Max 10MB</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label-custom">Comment</label>
                                <textarea name="comment" class="form-control-custom" rows="4" placeholder="Write your review comment here..."></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-primary-custom"><i class="fas fa-save"></i> Save Review</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ═══════ EDIT REVIEW MODAL ═══════ -->
    <div class="modal fade" id="editReviewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-pen-to-square"></i> Edit Review</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_review">
                    <input type="hidden" name="review_id" id="editReviewId">
                    <input type="hidden" name="assignment_id" id="editAssignmentId">
                    <input type="hidden" name="existing_file" id="editExistingFile">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label-custom">AI Score (%)</label>
                                <input type="number" name="ai_score" id="editAiScore" class="form-control-custom" min="0" max="100" step="0.01" placeholder="e.g. 15.5">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-custom">Similarity (%)</label>
                                <input type="number" name="similarity" id="editSimilarity" class="form-control-custom" min="0" max="100" step="0.01" placeholder="e.g. 8.2">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-custom">Marks (0–100)</label>
                                <input type="number" name="marks" id="editMarks" class="form-control-custom" min="0" max="100" step="1" placeholder="e.g. 85">
                                <div class="form-hint">Leave empty to set status as "Checking"</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-custom">Replace Reviewed File</label>
                                <input type="file" name="reviewed_file" class="form-control-custom" accept=".pdf,.doc,.docx,.txt">
                                <div class="form-hint" id="editFileHint">Current: <span id="editCurrentFile">—</span></div>
                            </div>
                            <div class="col-12">
                                <label class="form-label-custom">Comment</label>
                                <textarea name="comment" id="editComment" class="form-control-custom" rows="4" placeholder="Write your review comment here..."></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-primary-custom"><i class="fas fa-save"></i> Update Review</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ═══════ DELETE CONFIRMATION MODAL ═══════ -->
    <div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" style="color:#D32F2F;"><i class="fas fa-exclamation-triangle"></i> Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="delete_review">
                    <input type="hidden" name="review_id" id="deleteReviewId">
                    <input type="hidden" name="assignment_id" id="deleteAssignmentId">
                    <div class="modal-body">
                        <p style="font-size:13px;margin:0;">Are you sure you want to delete this review? The assignment status will be reset to <strong>Pending</strong>. This action cannot be undone.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn-danger-custom"><i class="fas fa-trash"></i> Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ═══════ VIEW DETAILS MODAL (BLUR BACKGROUND) ═══════ -->
    <div class="view-modal-overlay" id="viewModalOverlay">
        <div class="view-modal-card" id="viewModalCard">
            <div class="view-modal-header">
                <h6><i class="fas fa-eye"></i> Review Details</h6>
                <button class="view-modal-close" id="viewModalClose" aria-label="Close"><i class="fas fa-times"></i></button>
            </div>
            <div class="view-modal-body" id="viewModalBody"></div>
        </div>
    </div>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // ─── COPY VERIFICATION CODE ───
        function copyCode(code, el) {
            navigator.clipboard.writeText(code).then(function() {
                var orig = el.innerHTML;
                el.innerHTML = '<i class="fas fa-check" style="color:#388E3C;"></i> Copied!';
                el.style.color = '#388E3C';
                setTimeout(function() {
                    el.innerHTML = orig;
                    el.style.color = '';
                }, 1500);
            }).catch(function() {
                var ta = document.createElement('textarea');
                ta.value = code;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                var orig = el.innerHTML;
                el.innerHTML = '<i class="fas fa-check" style="color:#388E3C;"></i> Copied!';
                el.style.color = '#388E3C';
                setTimeout(function() {
                    el.innerHTML = orig;
                    el.style.color = '';
                }, 1500);
            });
        }

        // ─── SIDEBAR TOGGLE ───
        const sidebar = document.getElementById('sidebar');
        const sideOverlay = document.getElementById('sidebarOverlay');
        const toggleBtn = document.getElementById('sidebarToggle');
        toggleBtn.addEventListener('click', () => { sidebar.classList.toggle('show'); sideOverlay.classList.toggle('show'); });
        sideOverlay.addEventListener('click', () => { sidebar.classList.remove('show'); sideOverlay.classList.remove('show'); });

        // ─── NOTIFICATION DROPDOWN ───
        const notiBtn = document.getElementById('notiBtn');
        const notiDropdown = document.getElementById('notiDropdown');
        notiBtn.addEventListener('click', (e) => { e.stopPropagation(); notiDropdown.classList.toggle('show'); });
        document.addEventListener('click', () => notiDropdown.classList.remove('show'));
        notiDropdown.addEventListener('click', (e) => e.stopPropagation());

        // ─── SETTINGS PANEL ───
        const settingsBtn = document.getElementById('settingsBtn');
        const settingsPanel = document.getElementById('settingsPanel');
        const settingsOverlay = document.getElementById('settingsOverlay');
        const settingsCloseBtn = document.getElementById('settingsCloseBtn');
        function openSettings() { settingsPanel.classList.add('show'); settingsOverlay.classList.add('show'); }
        function closeSettings() { settingsPanel.classList.remove('show'); settingsOverlay.classList.remove('show'); }
        settingsBtn.addEventListener('click', openSettings);
        settingsCloseBtn.addEventListener('click', closeSettings);
        settingsOverlay.addEventListener('click', closeSettings);

        // ─── THEME TOGGLE ───
        const themeToggle = document.getElementById('themeToggle');
        const html = document.documentElement;
        if (localStorage.getItem('theme') === 'dark') { html.setAttribute('data-theme', 'dark'); themeToggle.checked = true; }
        themeToggle.addEventListener('change', () => {
            if (themeToggle.checked) { html.setAttribute('data-theme', 'dark'); localStorage.setItem('theme', 'dark'); }
            else { html.setAttribute('data-theme', 'light'); localStorage.setItem('theme', 'light'); }
        });

        // ─── LIVE CLOCK ───
        function updateClock() {
            const now = new Date();
            const opts = { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' };
            document.getElementById('headerTime').textContent = now.toLocaleDateString('en-GB', opts);
        }
        updateClock();
        setInterval(updateClock, 30000);

        // ═══════════════════════════════════════════
        // SEARCHABLE ASSIGNMENT DROPDOWN
        // ═══════════════════════════════════════════
        (function() {
            const input = document.getElementById('assignmentSearch');
            const hidden = document.getElementById('assignmentHiddenId');
            const dropdown = document.getElementById('assignmentDropdown');
            const clearBtn = document.getElementById('clearSearchBtn');
            const items = dropdown.querySelectorAll('.assignment-dropdown-item');
            let selectedId = null;

            function filterItems(query) {
                const q = query.toLowerCase().trim();
                let visibleCount = 0;
                items.forEach(function(item) {
                    const text = item.textContent.toLowerCase();
                    if (!q || text.indexOf(q) !== -1) { item.style.display = ''; visibleCount++; }
                    else { item.style.display = 'none'; }
                });
                let emptyMsg = dropdown.querySelector('.assignment-dropdown-empty');
                if (visibleCount === 0 && !emptyMsg) {
                    emptyMsg = document.createElement('div');
                    emptyMsg.className = 'assignment-dropdown-empty';
                    emptyMsg.innerHTML = '<i class="fas fa-search"></i>No assignments found';
                    dropdown.appendChild(emptyMsg);
                } else if (visibleCount > 0 && emptyMsg) {
                    emptyMsg.remove();
                } else if (visibleCount === 0 && emptyMsg) {
                    emptyMsg.innerHTML = '<i class="fas fa-search"></i>No assignments found';
                }
            }

            function openDropdown() { filterItems(input.value); dropdown.classList.add('show'); }

            function selectItem(item) {
                const id = item.getAttribute('data-id');
                const idLabel = item.querySelector('.addi-id').textContent;
                const title = item.querySelector('.addi-title').textContent;
                const user = item.querySelector('.addi-user').textContent.trim();
                input.value = '#' + id + ' — ' + title + ' (' + user + ')';
                hidden.value = id;
                selectedId = id;
                clearBtn.classList.add('visible');
                items.forEach(function(i) { i.classList.remove('selected'); });
                item.classList.add('selected');
                dropdown.classList.remove('show');
            }

            function clearSelection() {
                input.value = '';
                hidden.value = '';
                selectedId = null;
                clearBtn.classList.remove('visible');
                items.forEach(function(i) { i.classList.remove('selected'); });
                input.focus();
            }

            input.addEventListener('focus', openDropdown);
            input.addEventListener('click', function(e) { e.stopPropagation(); openDropdown(); });
            input.addEventListener('input', function() {
                if (this.value === '') { clearBtn.classList.remove('visible'); }
                else { clearBtn.classList.add('visible'); }
                filterItems(this.value);
                if (!dropdown.classList.contains('show')) dropdown.classList.add('show');
            });
            items.forEach(function(item) {
                item.addEventListener('click', function(e) { e.stopPropagation(); selectItem(this); });
            });
            clearBtn.addEventListener('click', function(e) { e.stopPropagation(); clearSelection(); });
            document.addEventListener('click', function(e) {
                if (!dropdown.contains(e.target) && e.target !== input) dropdown.classList.remove('show');
            });
            input.addEventListener('keydown', function(e) {
                const visible = Array.from(items).filter(function(i) { return i.style.display !== 'none'; });
                const currentIdx = visible.findIndex(function(i) { return i.classList.contains('selected'); });
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (visible.length === 0) return;
                    visible.forEach(function(i) { i.classList.remove('selected'); });
                    const next = currentIdx < visible.length - 1 ? currentIdx + 1 : 0;
                    visible[next].classList.add('selected');
                    visible[next].scrollIntoView({ block: 'nearest' });
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (visible.length === 0) return;
                    visible.forEach(function(i) { i.classList.remove('selected'); });
                    const prev = currentIdx > 0 ? currentIdx - 1 : visible.length - 1;
                    visible[prev].classList.add('selected');
                    visible[prev].scrollIntoView({ block: 'nearest' });
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    const sel = visible.find(function(i) { return i.classList.contains('selected'); });
                    if (sel) selectItem(sel);
                } else if (e.key === 'Escape') {
                    dropdown.classList.remove('show');
                    input.blur();
                }
            });

            // Expose for external use
            window.selectAssignmentById = function(id) {
                const item = dropdown.querySelector('[data-id="' + id + '"]');
                if (item) selectItem(item);
            };
            window.clearAssignmentSearch = function() { clearSelection(); };
        })();

        // ─── OPEN ADD MODAL FOR SPECIFIC ASSIGNMENT ───
        function openAddForAssignment(assignmentId) {
            var addModal = new bootstrap.Modal(document.getElementById('addReviewModal'));
            addModal.show();
            setTimeout(function() {
                window.selectAssignmentById(assignmentId);
            }, 300);
        }

                // ─── EDIT MODAL ───
        function openEditModal(reviewId) {
            var r = reviewsData.find(function(item) { return String(item.review_id) === String(reviewId); });
            if (!r) return;
            document.getElementById('editReviewId').value = r.review_id;
            document.getElementById('editAssignmentId').value = r.assignment_id;
            document.getElementById('editAiScore').value = (r.ai_score !== null && r.ai_score !== '') ? r.ai_score : '';
            document.getElementById('editSimilarity').value = (r.similarity !== null && r.similarity !== '') ? r.similarity : '';
            document.getElementById('editMarks').value = (r.marks !== null && r.marks !== '') ? r.marks : '';
            document.getElementById('editComment').value = r.comment || '';
            document.getElementById('editExistingFile').value = r.reviewed_file || '';
            document.getElementById('editCurrentFile').textContent = r.reviewed_file || 'No file';
            var editModal = new bootstrap.Modal(document.getElementById('editReviewModal'));
            editModal.show();
        }

        // ─── DELETE MODAL ───
        function setDeleteTarget(reviewId, assignmentId) {
            document.getElementById('deleteReviewId').value = reviewId;
            document.getElementById('deleteAssignmentId').value = assignmentId;
        }

        // ─── RESET ADD FORM ON MODAL CLOSE ───
        document.getElementById('addReviewModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('addReviewForm').reset();
            document.getElementById('assignmentHiddenId').value = '';
            window.clearAssignmentSearch();
        });

        // ═══════════════════════════════════════════
        // VIEW REVIEW MODAL WITH BLUR BACKGROUND
        // ═══════════════════════════════════════════
        const reviewsData = <?php echo $reviews_json; ?>;
        const certificateQrUrl = <?php echo $certificateExists ? json_encode($certificateAbsUrl) : 'null'; ?>;

        const viewOverlay = document.getElementById('viewModalOverlay');
        const viewBody = document.getElementById('viewModalBody');
        const viewClose = document.getElementById('viewModalClose');

        function viewReview(reviewId) {
            const r = reviewsData.find(function(item) { return String(item.review_id) === String(reviewId); });
            if (!r) return;

            const aiScore = r.ai_score !== null && r.ai_score !== ''
                ? '<span style="font-weight:700;color:' + ((parseFloat(r.ai_score) <= 20) ? '#388E3C' : (parseFloat(r.ai_score) <= 50) ? '#F57C00' : '#D32F2F') + ';">' + parseFloat(r.ai_score).toFixed(1) + '%</span>'
                : '<span style="color:var(--text-muted);">N/A</span>';

            const similarity = r.similarity !== null && r.similarity !== ''
                ? '<span style="font-weight:700;color:' + ((parseFloat(r.similarity) <= 15) ? '#388E3C' : (parseFloat(r.similarity) <= 35) ? '#F57C00' : '#D32F2F') + ';">' + parseFloat(r.similarity).toFixed(1) + '%</span>'
                : '<span style="color:var(--text-muted);">N/A</span>';

            const marks = r.marks !== null && r.marks !== ''
                ? '<span style="font-weight:700;font-size:15px;color:' + ((parseInt(r.marks) >= 80) ? '#388E3C' : (parseInt(r.marks) >= 50) ? '#1976D2' : '#D32F2F') + ';">' + parseInt(r.marks) + ' / 100</span>'
                : '<span style="color:var(--text-muted);">N/A</span>';

            const statusColors = { 'Pending': '#F57C00', 'Checking': '#1976D2', 'Completed': '#388E3C' };
            const statusColor = statusColors[r.status] || '#7B6B8D';

            const vcode = r.verification_code && r.verification_code !== ''
                ? '<div class="view-vcode-box" onclick="copyCode(\'' + r.verification_code.replace(/'/g, "\\'") + '\', this)" title="Click to copy">' + r.verification_code + ' <i class="fas fa-copy"></i></div>'
                : '<span style="color:var(--text-muted);">—</span>';

            const certQr = certificateQrUrl
                ? '<div class="view-detail-row"><div class="view-detail-label">Certificate QR</div><div class="view-detail-value"><img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' + encodeURIComponent(certificateQrUrl) + '" alt="Certificate QR code" title="Scan to open the certificate image" style="width:100px;height:100px;border-radius:8px;background:#fff;padding:4px;border:1px solid var(--border-color);"></div></div>'
                : '';

            const certFileLink = r.certificate_file
                ? '<div class="view-detail-row"><div class="view-detail-label">Generated Certificate</div><div class="view-detail-value"><a href="' + r.certificate_file + '" target="_blank" class="file-link"><i class="fas fa-award"></i> View / Download</a></div></div>'
                : '';

            const fileLink = r.reviewed_file
                ? '<a href="uploads/reviews/' + r.reviewed_file + '" target="_blank" class="file-link"><i class="fas fa-file-alt"></i> ' + r.reviewed_file + '</a>'
                : '<span style="color:var(--text-muted);">No file</span>';

            viewBody.innerHTML =
                '<div class="view-detail-row"><div class="view-detail-label">Review ID</div><div class="view-detail-value">#' + (r.review_id || '—') + '</div></div>' +
                '<div class="view-detail-row"><div class="view-detail-label">Assignment ID</div><div class="view-detail-value">#' + (r.assignment_id || '—') + '</div></div>' +
                '<div class="view-detail-row"><div class="view-detail-label">Title</div><div class="view-detail-value" style="font-weight:600;">' + (r.title || '—') + '</div></div>' +
                '<div class="view-detail-row"><div class="view-detail-label">Subject</div><div class="view-detail-value">' + (r.subject || '—') + '</div></div>' +
                '<div class="view-detail-row"><div class="view-detail-label">Student</div><div class="view-detail-value">' + (r.user_name || '—') + '</div></div>' +
                '<div class="view-detail-row"><div class="view-detail-label">Submission Date</div><div class="view-detail-value">' + (r.submission_date || '—') + '</div></div>' +
                '<div class="view-detail-row"><div class="view-detail-label">Status</div><div class="view-detail-value"><span style="display:inline-block;padding:3px 12px;border-radius:6px;font-size:12px;font-weight:600;color:#fff;background:' + statusColor + ';">' + (r.status || 'Pending') + '</span></div></div>' +
                '<div class="view-detail-row"><div class="view-detail-label">AI Score</div><div class="view-detail-value">' + aiScore + '</div></div>' +
                '<div class="view-detail-row"><div class="view-detail-label">Similarity</div><div class="view-detail-value">' + similarity + '</div></div>' +
                '<div class="view-detail-row"><div class="view-detail-label">Marks</div><div class="view-detail-value">' + marks + '</div></div>' +
                '<div class="view-detail-row"><div class="view-detail-label">Comment</div><div class="view-detail-value vd-comment">' + (r.comment || '<span style="color:var(--text-muted);">No comment</span>') + '</div></div>' +
                '<div class="view-detail-row"><div class="view-detail-label">Reviewed File</div><div class="view-detail-value">' + fileLink + '</div></div>' +
                '<div class="view-detail-row"><div class="view-detail-label">Certificate ID</div><div class="view-detail-value">' + vcode + '</div></div>' +
                certFileLink +
                certQr +
                '<div class="view-detail-row"><div class="view-detail-label">Review Date</div><div class="view-detail-value">' + (r.created_at || '—') + '</div></div>';

            viewOverlay.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeViewModal() {
            viewOverlay.classList.remove('show');
            document.body.style.overflow = '';
        }

        viewClose.addEventListener('click', closeViewModal);
        viewOverlay.addEventListener('click', function(e) { if (e.target === viewOverlay) closeViewModal(); });
        document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && viewOverlay.classList.contains('show')) closeViewModal(); });
    </script>
</body>
</html>