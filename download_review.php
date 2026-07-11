<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (!isset($_GET['id'])) {
    header("Location: assignments.php");
    exit();
}

$review_id = intval($_GET['id']);

/* Verify this review belongs to the logged-in user */
$checkStmt = $conn->prepare("
    SELECT r.review_id, r.reviewed_file
    FROM assignment_reviews r
    JOIN assignments a ON r.assignment_id = a.assignment_id
    WHERE r.review_id = ? AND a.user_id = ?
");
$checkStmt->bind_param("ii", $review_id, $_SESSION['user_id']);
$checkStmt->execute();
$row = $checkStmt->get_result()->fetch_assoc();
$checkStmt->close();

if (!$row || empty($row['reviewed_file'])) {
    header("Location: assignments.php");
    exit();
}

$filePath = __DIR__ . '/uploads/reviews/' . $row['reviewed_file'];

if (!file_exists($filePath)) {
    header("Location: assignments.php");
    exit();
}

/* Force download — handles spaces/special chars in filename */
$fileName = $row['reviewed_file'];
$mimeTypes = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'txt'  => 'text/plain',
];

$ext  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
$mime = $mimeTypes[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . addslashes($fileName) . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

readfile($filePath);
exit;
