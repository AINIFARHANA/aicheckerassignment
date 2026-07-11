<?php
session_start();
require_once __DIR__ . '/config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int)$_SESSION['user_id'];
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }
}

$redirect = $_POST['redirect'] ?? 'index.php';
if (!is_string($redirect)
    || preg_match('/[\r\n]/', $redirect)
    || preg_match('#^https?://#i', $redirect)
    || str_starts_with($redirect, '//')) {
    $redirect = 'index.php';
}

header('Location: ' . $redirect);
exit;
