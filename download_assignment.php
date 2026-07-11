<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$assignment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($assignment_id <= 0) {
    http_response_code(400);
    exit('Invalid assignment ID.');
}

// Check current user type
$current_user_id = (int)$_SESSION['user_id'];
$user_type = '';
$stmt_user = $conn->prepare("SELECT user_type FROM users WHERE user_id = ? LIMIT 1");
$stmt_user->bind_param("i", $current_user_id);
$stmt_user->execute();
$user_row = $stmt_user->get_result()->fetch_assoc();
$stmt_user->close();
$user_type = $user_row['user_type'] ?? '';

$stmt = $conn->prepare("SELECT assignment_id, user_id, upload_file, file_path FROM assignments WHERE assignment_id = ? LIMIT 1");
$stmt->bind_param("i", $assignment_id);
$stmt->execute();
$assignment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$assignment) {
    http_response_code(404);
    exit('Assignment not found.');
}

// Admin can download all files. Normal users can only download their own files.
if ($user_type !== 'admin' && (int)$assignment['user_id'] !== $current_user_id) {
    http_response_code(403);
    exit('You are not allowed to download this file.');
}

function assignment_relative_path($assignment) {
    if (!empty($assignment['file_path'])) {
        return str_replace('\\', '/', $assignment['file_path']);
    }

    $file = $assignment['upload_file'] ?? '';
    $file = str_replace('\\', '/', $file);

    if ($file === '' || strpos($file, '..') !== false) {
        return '';
    }

    if (strpos($file, 'uploads/assignments/') === 0) {
        return $file;
    }

    return 'uploads/assignments/' . ltrim($file, '/');
}

$relative_path = assignment_relative_path($assignment);
$absolute_path = realpath(__DIR__ . '/' . $relative_path);
$allowed_base  = realpath(__DIR__ . '/uploads/assignments');

if ($absolute_path === false || $allowed_base === false || strpos($absolute_path, $allowed_base) !== 0 || !is_file($absolute_path)) {
    http_response_code(404);
    exit('File not found on server. Check that the file exists in uploads/assignments/.');
}

$download_name = basename($assignment['upload_file'] ?: $absolute_path);
$mime = function_exists('mime_content_type') ? mime_content_type($absolute_path) : 'application/octet-stream';
if (!$mime) $mime = 'application/octet-stream';

while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Description: File Transfer');
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $download_name) . '"');
header('Content-Length: ' . filesize($absolute_path));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

readfile($absolute_path);
exit;
?>
