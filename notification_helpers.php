<?php
/**
 * Shared notification helpers.
 * User notifications use a real users.user_id value.
 * Admin-wide notifications use NULL as user_id.
 */

if (!function_exists('createUserNotification')) {
    function createUserNotification(mysqli $conn, int $userId, string $message): bool
    {
        if ($userId <= 0 || trim($message) === '') {
            return false;
        }

        $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (?, ?, 0, NOW())");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('is', $userId, $message);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('createAssignmentNotification')) {
    function createAssignmentNotification(mysqli $conn, int $assignmentId, string $messageTemplate): bool
    {
        if ($assignmentId <= 0) {
            return false;
        }

        $stmt = $conn->prepare("SELECT user_id, title FROM assignments WHERE assignment_id = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('i', $assignmentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $assignment = $result ? $result->fetch_assoc() : null;
        $stmt->close();

        if (!$assignment || empty($assignment['user_id'])) {
            return false;
        }

        $title = trim((string)($assignment['title'] ?? 'Assignment'));
        $message = str_replace('{title}', $title, $messageTemplate);
        return createUserNotification($conn, (int)$assignment['user_id'], $message);
    }
}

if (!function_exists('createAdminNotification')) {
    function createAdminNotification(mysqli $conn, string $message): bool
    {
        if (trim($message) === '') {
            return false;
        }

        $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, is_read, created_at) VALUES (NULL, ?, 0, NOW())");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('s', $message);
        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('assignmentStatusNotificationTemplate')) {
    function assignmentStatusNotificationTemplate(string $status): string
    {
        switch (strtolower(trim($status))) {
            case 'checking':
            case 'processing':
                return 'Your assignment "{title}" is now being checked.';
            case 'completed':
                return 'Your assignment "{title}" has been completed. You can view the result now.';
            case 'pending':
            default:
                return 'Your assignment "{title}" status is now Pending.';
        }
    }
}
