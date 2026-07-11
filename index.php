<?php

session_start();

include 'chatbot.php';

// 1. DATABASE CONNECTION
$servername = "localhost";
$username   = "root";
$password   = "";               // Default XAMPP password
$dbname     = "assignment_db";  // Your local database name

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

 $stats = [
    'users' => 0,
    'assignments' => 0,
    'reports' => 0,
    'vouchers' => 0
];

function getSafeCount($conn, $sql) {
    if (!$conn) return 0;
    $result = $conn->query($sql);
    if ($result && $row = $result->fetch_row()) {
        return $row[0];
    }
    return 0;
}

 $stats['users'] = getSafeCount($conn, "SELECT COUNT(*) FROM users");
 $stats['assignments'] = getSafeCount($conn, "SELECT COUNT(*) FROM assignments");
 $stats['reports'] = getSafeCount($conn, "SELECT COUNT(*) FROM plag_ai WHERE status='Approved'");
 $stats['vouchers'] = getSafeCount($conn, "SELECT COUNT(*) FROM vouchers WHERE status='Active'");

// 3. FETCH ACTIVE VOUCHERS
 $vouchers = [];
if ($conn) {
    $voucher_sql = "SELECT code, discount_amount, expiry_date FROM vouchers WHERE status='Active' LIMIT 4";
    $v_result = $conn->query($voucher_sql);
    if ($v_result->num_rows > 0) {
        while($row = $v_result->fetch_assoc()) {
            $vouchers[] = $row;
        }
    }
}

// 4. FETCH TESTIMONIALS
 $testimonials = [];
if ($conn) {
    $t_sql = "SELECT name, feedback, rating, avatar, DATE_FORMAT(created_at, '%M %d, %Y') as formatted_date 
              FROM testimonials 
              ORDER BY created_at DESC 
              LIMIT 3";
    $t_result = $conn->query($t_sql);
    if ($t_result->num_rows > 0) {
        while($row = $t_result->fetch_assoc()) {
            $testimonials[] = $row;
        }
    }
}

// 5. HANDLE TESTIMONIAL FORM SUBMISSION
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_testimonial'])) {
    if ($conn) {
        $name = $conn->real_escape_string($_POST['t_name']);
        $feedback = $conn->real_escape_string($_POST['t_feedback']);
        $rating = intval($_POST['t_rating']);
        
        $seeds = ['Felix', 'Annie', 'Bob', 'Cathy', 'Jack'];
        $avatar = $seeds[array_rand($seeds)];

        if(!empty($name) && !empty($feedback) && $rating >= 1 && $rating <= 5) {
            $insert_sql = "INSERT INTO testimonials (name, feedback, rating, avatar) 
                           VALUES ('$name', '$feedback', '$rating', '$avatar')";

            if ($conn->query($insert_sql) === TRUE) {
                $_SESSION['review_msg'] = "Thank you! Your review has been submitted successfully.";
                $_SESSION['review_type'] = "success";
                header("Location: index.php#testimonials");
                exit();
            } else {
                $_SESSION['review_msg'] = "Something went wrong. Please try again.";
                $_SESSION['review_type'] = "error";
                header("Location: index.php#contact");
                exit();
            }
        } else {
            $_SESSION['review_msg'] = "Please fill in all fields correctly.";
            $_SESSION['review_type'] = "error";
            header("Location: index.php#contact");
            exit();
        }
    }
}

// Read and clear notification from session
 $reviewMsg = isset($_SESSION['review_msg']) ? $_SESSION['review_msg'] : "";
 $reviewType = isset($_SESSION['review_type']) ? $_SESSION['review_type'] : "";
if (isset($_SESSION['review_msg'])) {
    unset($_SESSION['review_msg']);
    unset($_SESSION['review_type']);
}

// Check if user is logged in and fetch Avatar
 $isLoggedIn = isset($_SESSION['user_id']);
 $userName = isset($_SESSION['username']) ? $_SESSION['username'] : "User";
 $userAvatarSeed = "Felix"; 

if ($isLoggedIn && $conn) {
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT avatar FROM users WHERE user_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            if (!empty($row['avatar'])) {
                $userAvatarSeed = $row['avatar'];
            }
        }
        $stmt->close();
    }
}


// USER NOTIFICATIONS FOR LANDING-PAGE NAVBAR
$userNotifications = [];
$userUnreadCount = 0;

if ($isLoggedIn && $conn) {
    $notificationStmt = $conn->prepare(
        "SELECT notification_id, message, is_read, created_at
         FROM notifications
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT 20"
    );

    if ($notificationStmt) {
        $notificationStmt->bind_param("i", $user_id);
        $notificationStmt->execute();
        $notificationResult = $notificationStmt->get_result();

        while ($notificationResult && ($notification = $notificationResult->fetch_assoc())) {
            $userNotifications[] = $notification;
            if ((int)$notification['is_read'] === 0) {
                $userUnreadCount++;
            }
        }
        $notificationStmt->close();
    }
}

if (!function_exists('landing_notification_time_ago')) {
    function landing_notification_time_ago($datetime) {
        if (empty($datetime)) return '';

        try {
            $now = new DateTime();
            $ago = new DateTime($datetime);
            $diff = $now->diff($ago);

            if ($diff->y > 0) return $diff->y . 'y ago';
            if ($diff->m > 0) return $diff->m . 'mo ago';
            if ($diff->d > 0) return $diff->d . 'd ago';
            if ($diff->h > 0) return $diff->h . 'h ago';
            if ($diff->i > 0) return $diff->i . 'm ago';
            return 'Just now';
        } catch (Throwable $e) {
            return '';
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Assignment Checker</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- AOS Animation CSS -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #7B3F91;
            --primary-light: #F4D1FF;
            --primary-dark: #3B1347;
            --accent: #D88FFF;
            --accent-rose: #FF8EC4;
            --accent-gold: #FFD6A5;
            --surface: rgba(59, 19, 71, 0.35);
            --surface-solid: #150A1E;
            --surface-glass: rgba(244, 209, 255, 0.04);
            --bg-soft: #0D0612;
            --bg-muted: rgba(244, 209, 255, 0.04);
            --text-dark: #FFFFFF;
            --text-body: rgba(244, 209, 255, 0.72);
            --text-muted: rgba(244, 209, 255, 0.4);
            --border-subtle: rgba(244, 209, 255, 0.08);
            --border-glow: rgba(244, 209, 255, 0.15);
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.3);
            --shadow-md: 0 8px 32px rgba(0, 0, 0, 0.4);
            --shadow-lg: 0 20px 60px rgba(0, 0, 0, 0.5);
            --shadow-glow: 0 0 40px rgba(244, 209, 255, 0.08);
            --radius-sm: 10px;
            --radius-md: 16px;
            --radius-lg: 24px;
            --radius-xl: 32px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Poppins', sans-serif;
            color: var(--text-body);
            overflow-x: hidden;
            background: var(--bg-soft);
            -webkit-font-smoothing: antialiased;
        }

        ::selection {
            background: var(--primary);
            color: white;
        }

        .text-purple { color: var(--accent) !important; }
        .bg-purple { background-color: var(--primary-mid, var(--primary)) !important; }

        .bg-gradient-purple {
            background: linear-gradient(135deg, rgba(59,19,71,0.6) 0%, rgba(90,31,107,0.4) 40%, rgba(123,63,145,0.3) 70%, rgba(59,19,71,0.6) 100%);
            backdrop-filter: blur(10px);
        }

        /* --- Buttons --- */
        .btn-purple {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            border: none;
            padding: 14px 36px;
            border-radius: 60px;
            font-weight: 600;
            font-size: 0.95rem;
            letter-spacing: 0.02em;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 24px rgba(123,63,145,0.4), inset 0 1px 0 rgba(255,255,255,0.1);
            position: relative;
            overflow: hidden;
        }
        .btn-purple::before {
            content: '';
            position: absolute;
            top: 0; left: -100%; width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(244,209,255,0.2), transparent);
            transition: left 0.5s;
        }
        .btn-purple:hover::before { left: 100%; }
        .btn-purple:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(123,63,145,0.6), 0 0 20px rgba(244,209,255,0.1);
            color: white;
        }

        .btn-outline-purple {
            border: 2px solid var(--border-glow);
            color: var(--primary-light);
            padding: 12px 36px;
            border-radius: 60px;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            display: inline-block;
            background: rgba(244, 209, 255, 0.04);
            backdrop-filter: blur(8px);
        }
        .btn-outline-purple:hover {
            background: var(--primary);
            color: white;
            text-decoration: none;
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(123,63,145,0.4);
            border-color: var(--primary);
        }

        /* --- Navbar --- */
        .navbar {
            background: rgba(13, 6, 18, 0.6);
            backdrop-filter: blur(24px) saturate(180%);
            -webkit-backdrop-filter: blur(24px) saturate(180%);
            border-bottom: 1px solid var(--border-subtle);
            padding: 12px 0;
            transition: all 0.4s ease;
        }
        .navbar.scrolled {
            padding: 8px 0;
            background: rgba(13, 6, 18, 0.88);
            box-shadow: 0 4px 40px rgba(0, 0, 0, 0.4), 0 0 30px rgba(244,209,255,0.03);
            border-bottom-color: var(--border-glow);
        }
        .navbar-brand {
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 700;
            font-size: 1.3rem;
            color: var(--primary-light) !important;
            letter-spacing: -0.02em;
            text-shadow: 0 0 20px rgba(244,209,255,0.2);
        }
        .navbar-brand img { height: 38px; }
        .nav-link {
            font-weight: 500;
            color: var(--text-body) !important;
            margin: 0 5px;
            position: relative;
            text-decoration: none;
            font-size: 0.9rem;
            padding: 8px 14px !important;
            border-radius: 10px;
            transition: all 0.3s ease;
        }
        .nav-link:hover, .nav-link.active {
            color: var(--primary-light) !important;
            background: rgba(244, 209, 255, 0.08);
            text-shadow: 0 0 12px rgba(244,209,255,0.15);
        }
        .navbar-toggler { border: none; padding: 6px; }
        .navbar-toggler:focus { box-shadow: none; }
        .navbar-toggler-icon {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba(244,209,255,0.75)' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
        }

        /* --- Hero Section --- */
        .hero-section {
            padding: 160px 0 120px;
            position: relative;
            overflow: hidden;
            background: transparent;
        }
        .hero-section::before {
            content: '';
            position: absolute;
            top: -20%; right: -10%;
            width: 700px; height: 700px;
            background: radial-gradient(circle, rgba(123,63,145,0.2) 0%, transparent 55%);
            border-radius: 50%;
            animation: floatBlob 8s ease-in-out infinite;
        }
        .hero-section::after {
            content: '';
            position: absolute;
            bottom: -15%; left: -5%;
            width: 500px; height: 500px;
            background: radial-gradient(circle, rgba(244,209,255,0.06) 0%, transparent 55%);
            border-radius: 50%;
            animation: floatBlob 10s ease-in-out infinite reverse;
        }
        @keyframes floatBlob {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(30px, -20px) scale(1.05); }
            66% { transform: translate(-20px, 15px) scale(0.95); }
        }
        .hero-title {
            font-weight: 900;
            color: var(--text-dark);
            line-height: 1.1;
            letter-spacing: -0.03em;
            font-size: clamp(2.2rem, 5vw, 3.5rem);
        }
        .hero-title span {
            background: linear-gradient(135deg, var(--primary-light), var(--accent), var(--accent-rose));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .hero-section .lead {
            color: var(--text-body);
            font-size: 1.1rem;
            line-height: 1.8;
            max-width: 480px;
        }
        .hero-section .badge {
            background: rgba(244, 209, 255, 0.06) !important;
            color: var(--accent) !important;
            font-weight: 600;
            font-size: 0.8rem;
            border: 1px solid var(--border-glow);
            backdrop-filter: blur(10px);
        }

        /* --- Checker Demo Animation --- */
        @keyframes pulseIcon {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(0.9); }
        }

        /* --- Plan Section --- */
        .plan-card-wrapper {
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            background: var(--surface);
            backdrop-filter: blur(16px);
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            color: inherit;
            display: block;
            height: 100%;
            border: 1px solid var(--border-subtle);
        }
        .plan-card-wrapper:hover {
            transform: translateY(-10px);
            box-shadow: var(--shadow-lg), 0 0 40px rgba(244,209,255,0.06);
            border-color: var(--border-glow);
        }
        .zoom-container {
            position: relative;
            overflow: hidden;
            height: 280px;
            background: linear-gradient(135deg, rgba(123,63,145,0.15), rgba(59,19,71,0.08));
        }
        .zoom-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.7s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .plan-card-wrapper:hover .zoom-container img {
            transform: scale(1.12);
        }
        .plan-overlay {
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background: linear-gradient(135deg, rgba(59,19,71,0.8), rgba(123,63,145,0.7));
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.4s ease;
        }
        .plan-card-wrapper:hover .plan-overlay { opacity: 1; }
        .plan-btn {
            background: var(--primary-light);
            color: var(--primary-dark);
            padding: 12px 28px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 0.9rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            transform: translateY(10px);
            transition: transform 0.4s ease;
        }
        .plan-card-wrapper:hover .plan-btn { transform: translateY(0); }
        .plan-title {
            padding: 20px 16px;
            text-align: center;
            font-weight: 700;
            color: var(--text-dark);
            background: rgba(244, 209, 255, 0.02);
            font-size: 1.05rem;
            letter-spacing: -0.01em;
            position: relative;
        }
        .plan-title::before {
            content: '';
            position: absolute;
            top: 0; left: 50%; transform: translateX(-50%);
            width: 40px; height: 2px;
            background: linear-gradient(90deg, var(--accent), var(--primary-light));
            border-radius: 10px;
        }

        /* --- Services Section --- */
        #services {
            background: transparent;
            position: relative;
        }
        #services::before {
            content: '';
            position: absolute;
            top: 0; left: 10%; right: 10%; height: 1px;
            background: linear-gradient(90deg, transparent, var(--border-glow), transparent);
        }
        .feature-card {
            background: var(--surface);
            backdrop-filter: blur(16px);
            padding: 36px 24px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid var(--border-subtle);
            height: 100%;
            position: relative;
            overflow: hidden;
        }
        .feature-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--primary), var(--accent), var(--primary-light));
            transform: scaleX(0);
            transform-origin: left;
            transition: transform 0.5s ease;
        }
        .feature-card:hover::before { transform: scaleX(1); }
        .feature-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-lg), 0 0 30px rgba(244,209,255,0.06);
            border-color: var(--border-glow);
            background: rgba(59, 19, 71, 0.55);
        }
        .icon-box {
            width: 72px;
            height: 72px;
            background: rgba(244, 209, 255, 0.06);
            border: 1px solid var(--border-subtle);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.6rem;
            color: var(--accent);
            margin-bottom: 20px;
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .feature-card:hover .icon-box {
            transform: rotateY(180deg);
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            border-radius: 50%;
            border-color: transparent;
            box-shadow: 0 0 30px rgba(216,143,255,0.3);
        }
        .feature-card h4 {
            font-weight: 700;
            color: var(--text-dark);
            font-size: 1.05rem;
            margin-bottom: 8px;
        }
        .feature-card .btn-outline-purple {
            padding: 8px 22px;
            font-size: 0.82rem;
            border-width: 1.5px;
        }

        /* --- Testimonials --- */
        .testimonial-card {
            background: var(--surface);
            backdrop-filter: blur(16px);
            padding: 32px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            height: 100%;
            transition: all 0.4s ease;
            border: 1px solid var(--border-subtle);
            position: relative;
        }
        .testimonial-card::after {
            content: '\201C';
            position: absolute;
            top: 16px; right: 24px;
            font-size: 4rem;
            color: rgba(244, 209, 255, 0.06);
            font-family: 'Space Grotesk', sans-serif;
            line-height: 1;
        }
        .testimonial-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-md), 0 0 30px rgba(244,209,255,0.05);
            border-color: var(--border-glow);
        }
        .avatar-img {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            object-fit: cover;
            border: 2px solid var(--border-glow);
            background: var(--bg-muted);
        }
        .testimonial-card .text-warning { font-size: 0.85rem; }
        .testimonial-card .fa-star { color: var(--accent-gold) !important; filter: drop-shadow(0 1px 3px rgba(255,214,165,0.3)); }
        .testimonial-card p.fst-italic {
            color: var(--text-body);
            line-height: 1.7;
            font-size: 0.92rem;
        }

        /* --- Key Features (Why Choose Us) --- */
        .feature-card i.fa-3x {
            font-size: 2.2rem;
            margin-bottom: 16px;
            transition: transform 0.4s ease;
            color: var(--accent);
        }
        .feature-card:hover i.fa-3x {
            transform: scale(1.2) rotate(-5deg);
            filter: drop-shadow(0 4px 8px rgba(216,143,255,0.2));
        }

        /* --- Statistics --- */
        #stats {
            position: relative;
            overflow: hidden;
        }
        #stats::before {
            content: '';
            position: absolute;
            top: 0; left: 10%; right: 10%; height: 1px;
            background: linear-gradient(90deg, transparent, var(--border-glow), transparent);
        }
        #stats::after {
            content: '';
            position: absolute;
            bottom: 0; left: 10%; right: 10%; height: 1px;
            background: linear-gradient(90deg, transparent, var(--border-glow), transparent);
        }
        .stat-box {
            background: rgba(244, 209, 255, 0.04);
            backdrop-filter: blur(12px);
            padding: 32px 20px;
            border-radius: var(--radius-md);
            text-align: center;
            border: 1px solid rgba(244, 209, 255, 0.1);
            transition: all 0.4s ease;
            position: relative;
            z-index: 1;
        }
        .stat-box:hover {
            transform: translateY(-6px) scale(1.03);
            background: rgba(244, 209, 255, 0.08);
            border-color: rgba(244, 209, 255, 0.2);
            box-shadow: 0 8px 30px rgba(0,0,0,0.3), 0 0 20px rgba(244,209,255,0.05);
        }
        .stat-number {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 2.8rem;
            font-weight: 700;
            letter-spacing: -0.03em;
            line-height: 1;
            margin: 8px 0;
            background: linear-gradient(135deg, var(--primary-light), white);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .stat-box small {
            font-size: 0.82rem;
            opacity: 0.6;
            font-weight: 400;
            color: var(--primary-light);
        }
        .stat-box i {
            opacity: 0.5;
            font-size: 1.4rem;
            color: var(--primary-light);
        }

        /* --- How It Works --- */
        #how-it-works {
            background: transparent;
            position: relative;
        }
        #how-it-works::before {
            content: '';
            position: absolute;
            top: 0; left: 10%; right: 10%; height: 1px;
            background: linear-gradient(90deg, transparent, var(--border-glow), transparent);
        }
        .step-item {
            position: relative;
            padding: 20px 10px;
            text-align: center;
            transition: all 0.4s ease;
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .step-item:hover { transform: translateY(-8px); }
        .step-circle {
            width: 64px;
            height: 64px;
            background: var(--surface-solid);
            color: var(--accent);
            border: 2px solid var(--border-subtle);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 1.2rem;
            margin: 0 auto 16px;
            box-shadow: 0 0 0 6px rgba(244,209,255,0.04);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .step-item:hover .step-circle {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            border-color: transparent;
            box-shadow: 0 0 0 6px rgba(244,209,255,0.06), 0 8px 25px rgba(123,63,145,0.3);
            transform: scale(1.1);
        }
        .step-item h5 {
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 4px;
        }
        .step-item p {
            font-size: 0.8rem;
            color: var(--text-muted);
        }

        /* --- Review / Contact Section --- */
        .contact-section {
            background: transparent;
            position: relative;
        }
        .contact-section::before {
            content: '';
            position: absolute;
            top: 0; left: 10%; right: 10%; height: 1px;
            background: linear-gradient(90deg, transparent, var(--border-glow), transparent);
        }
        .contact-section .card {
            border: 1px solid var(--border-subtle);
            overflow: hidden;
            background: var(--surface);
            backdrop-filter: blur(16px);
            box-shadow: var(--shadow-md);
        }
        .contact-section .bg-gradient-purple {
            background: linear-gradient(135deg, #3B1347 0%, #5A1F6B 40%, #7B3F8E 80%, #3B1347 100%) !important;
            background-size: 300% 300%;
            animation: gradientShift 10s ease infinite;
            position: relative;
            overflow: hidden;
        }
        @keyframes gradientShift {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        .contact-section .bg-gradient-purple::before {
            content: '';
            position: absolute;
            top: -30%; right: -30%;
            width: 200px; height: 200px;
            background: rgba(244, 209, 255, 0.06);
            border-radius: 50%;
        }
        .contact-section .bg-gradient-purple h3 {
            font-weight: 800;
            letter-spacing: -0.02em;
            color: white;
            position: relative; z-index: 1;
        }
        .contact-section .bg-gradient-purple p,
        .contact-section .bg-gradient-purple div { position: relative; z-index: 1; }
        .form-control {
            border: 1.5px solid var(--border-subtle);
            border-radius: var(--radius-sm);
            padding: 12px 16px;
            font-size: 0.9rem;
            background: rgba(244, 209, 255, 0.06);
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
            color: #FFFFFF !important;
        }
        .form-control:focus {
            box-shadow: 0 0 0 4px rgba(216,143,255,0.15);
            border-color: var(--accent);
            background: rgba(244, 209, 255, 0.1);
            color: #FFFFFF !important;
        }
        .form-control::placeholder { 
            color: rgba(244, 209, 255, 0.35); 
        }
        .form-label {
            font-size: 0.82rem;
            color: var(--primary-light);
            font-weight: 600;
        }

        /* --- Star Rating --- */
        .star-rating-input {
            font-size: 1.6rem;
            color: rgba(244, 209, 255, 0.12);
            cursor: pointer;
            display: inline-block;
            gap: 4px;
        }
        .star-rating-input i {
            transition: all 0.2s ease;
            margin: 0 2px;
        }
        .star-rating-input i.active {
            color: var(--accent-gold);
            filter: drop-shadow(0 2px 4px rgba(255,214,165,0.35));
        }
        .star-rating-input i:hover {
            transform: scale(1.3);
        }

        /* --- Section Headings --- */
        .text-center h6 {
            font-size: 0.78rem;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            color: var(--accent);
            font-weight: 600;
        }
        .text-center h2 {
            font-weight: 800;
            color: var(--text-dark);
            letter-spacing: -0.03em;
            font-size: clamp(1.6rem, 3vw, 2.2rem);
        }
        .text-center p.text-muted {
            color: var(--text-muted) !important;
            font-size: 0.95rem;
        }

        /* --- Aesthetic Notification Modal --- */
        .notif-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            transition: all 0.35s ease;
        }
        .notif-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        .notif-box {
            background: linear-gradient(145deg, #1a0d26, #150A1E);
            border: 1px solid var(--border-glow);
            border-radius: var(--radius-lg);
            padding: 40px 36px 32px;
            max-width: 400px;
            width: 90%;
            text-align: center;
            box-shadow: 0 30px 80px rgba(0,0,0,0.6), 0 0 60px rgba(244,209,255,0.04);
            transform: scale(0.85) translateY(20px);
            transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
            overflow: hidden;
        }
        .notif-overlay.active .notif-box {
            transform: scale(1) translateY(0);
        }
        .notif-box::before {
            content: '';
            position: absolute;
            top: -50%; left: -50%;
            width: 200%; height: 200%;
            background: radial-gradient(circle at 50% 80%, rgba(123,63,145,0.12) 0%, transparent 50%);
            pointer-events: none;
        }
        .notif-icon-wrap {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            position: relative;
            z-index: 1;
        }
        .notif-icon-wrap.success {
            background: rgba(34, 197, 94, 0.1);
            border: 2px solid rgba(34, 197, 94, 0.25);
            box-shadow: 0 0 30px rgba(34, 197, 94, 0.1);
        }
        .notif-icon-wrap.success i {
            color: #4ade80;
            font-size: 1.8rem;
        }
        .notif-icon-wrap.error {
            background: rgba(239, 68, 68, 0.1);
            border: 2px solid rgba(239, 68, 68, 0.25);
            box-shadow: 0 0 30px rgba(239, 68, 68, 0.1);
        }
        .notif-icon-wrap.error i {
            color: #f87171;
            font-size: 1.8rem;
        }
        .notif-box h4 {
            color: #FFFFFF;
            font-weight: 700;
            font-size: 1.15rem;
            margin-bottom: 8px;
            position: relative;
            z-index: 1;
        }
        .notif-box p {
            color: var(--text-body);
            font-size: 0.9rem;
            line-height: 1.6;
            margin-bottom: 24px;
            position: relative;
            z-index: 1;
        }
        .notif-btn {
            background: linear-gradient(135deg, var(--primary), var(--accent));
            color: white;
            border: none;
            padding: 12px 48px;
            border-radius: 50px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 20px rgba(123,63,145,0.4);
            position: relative;
            z-index: 1;
            font-family: 'Poppins', sans-serif;
        }
        .notif-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(123,63,145,0.6);
        }
        .notif-btn:active {
            transform: translateY(0);
        }
        /* Checkmark animation for success */
        @keyframes drawCheck {
            0% { stroke-dashoffset: 50; }
            100% { stroke-dashoffset: 0; }
        }
        .notif-icon-wrap.success svg .check-path {
            stroke-dasharray: 50;
            stroke-dashoffset: 50;
            animation: drawCheck 0.5s 0.3s ease forwards;
        }
        /* Pulse ring for icon */
        @keyframes notifPulse {
            0% { transform: scale(1); opacity: 0.4; }
            100% { transform: scale(1.6); opacity: 0; }
        }
        .notif-icon-wrap::after {
            content: '';
            position: absolute;
            inset: -4px;
            border-radius: 50%;
            border: 2px solid currentColor;
            opacity: 0;
        }
        .notif-overlay.active .notif-icon-wrap::after {
            animation: notifPulse 0.8s 0.2s ease-out;
        }

        /* --- Footer --- */
        footer {
            background: var(--surface-solid);
            border-top: 1px solid var(--border-subtle);
            color: var(--text-muted);
            padding: 28px 0;
        }
        footer a {
            color: var(--text-muted);
            text-decoration: none;
            transition: all 0.3s ease;
            font-size: 0.85rem;
        }
        footer a:hover {
            color: var(--primary-light);
            padding-left: 0;
        }
        footer h5 {
            font-weight: 700;
            color: white;
            font-size: 1rem;
            margin-bottom: 8px;
        }
        footer h6 {
            font-weight: 600;
            color: rgba(244, 209, 255, 0.7);
            font-size: 0.85rem;
            margin-bottom: 12px;
        }
        footer p.small {
            font-size: 0.82rem;
            line-height: 1.7;
            margin-bottom: 0;
        }
        footer ul li { margin-bottom: 6px; }
        footer .border-top {
            border-color: var(--border-subtle) !important;
            margin-top: 20px;
            padding-top: 18px;
            font-size: 0.78rem;
        }

        /* --- Voucher Cards --- */
        .voucher-card {
            background: linear-gradient(135deg, #3B1347, #5A1F6B, #7B3F8E);
            color: var(--primary-light);
            border-radius: var(--radius-md);
            position: relative;
            overflow: hidden;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.4);
            border: 1px solid var(--border-subtle);
            transition: all 0.4s ease;
        }
        .voucher-card::before {
            content: '';
            position: absolute;
            top: -50%; right: -50%;
            width: 100%; height: 200%;
            background: radial-gradient(circle, rgba(244,209,255,0.06) 0%, transparent 60%);
        }
        .voucher-card:hover {
            transform: translateY(-6px) rotate(0.5deg);
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.5), 0 0 30px rgba(244,209,255,0.05);
            border-color: var(--border-glow);
        }

        /* --- Dropdown --- */
        .dropdown-menu {
            background: rgba(21, 10, 30, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border-glow);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-lg), 0 0 40px rgba(244,209,255,0.05);
            padding: 8px;
            font-size: 0.88rem;
        }
        .dropdown-item {
            border-radius: 10px;
            padding: 8px 14px;
            transition: all 0.2s ease;
            color: var(--text-body);
        }
        .dropdown-item:hover {
            background: rgba(244, 209, 255, 0.08);
            color: var(--primary-light);
        }

        /* --- Scrollbar --- */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: var(--bg-soft); }
        ::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb:hover { background: var(--accent); }

        /* --- Responsive Tweaks --- */
        @media (max-width: 768px) {
            .hero-section { padding: 130px 0 80px; }
            .stat-number { font-size: 2rem; }
            .step-circle { width: 52px; height: 52px; font-size: 1rem; }
            .contact-section .col-md-5 { display: none; }
            .contact-section .col-md-7 { flex: 0 0 100% !important; max-width: 100% !important; }
            footer .row > div { margin-bottom: 16px; }
            .navbar-collapse {
                background: rgba(13, 6, 18, 0.95);
                backdrop-filter: blur(20px);
                border-radius: var(--radius-md);
                padding: 16px;
                margin-top: 12px;
                border: 1px solid var(--border-subtle);
            }
            .notif-box { padding: 32px 24px 28px; }
        }
    
        /* ===== USER NOTIFICATIONS ON HOME / SERVICES NAVBAR ===== */
        .user-notification-toggle {
            position: relative;
            width: 42px;
            height: 42px;
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--border-subtle);
            border-radius: 50%;
            background: rgba(244, 209, 255, 0.05);
            color: var(--text-light) !important;
            padding: 0 !important;
        }
        .user-notification-toggle::after { display: none !important; }
        .user-notification-toggle:hover {
            background: rgba(244, 209, 255, 0.1);
            border-color: var(--border-glow);
        }
        .user-notification-badge {
            position: absolute;
            top: -4px;
            right: -5px;
            min-width: 19px;
            height: 19px;
            padding: 0 5px;
            border-radius: 999px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #ff4d7d;
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            border: 2px solid var(--bg-deep);
        }
        .user-notification-menu {
            width: min(380px, calc(100vw - 24px));
            max-height: 460px;
            overflow: hidden;
            padding: 0 !important;
            z-index: 1100;
        }
        .user-notification-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 16px;
            border-bottom: 1px solid var(--border-subtle);
        }
        .user-notification-header strong {
            color: var(--text-light);
            font-size: 14px;
        }
        .mark-read-btn {
            border: 0;
            background: transparent;
            color: var(--accent);
            font-size: 11px;
            font-weight: 600;
            padding: 0;
        }
        .user-notification-list {
            max-height: 350px;
            overflow-y: auto;
        }
        .user-notification-item {
            display: flex;
            gap: 11px;
            padding: 13px 16px;
            text-decoration: none;
            border-bottom: 1px solid var(--border-subtle);
            background: transparent;
        }
        .user-notification-item:hover {
            background: rgba(244, 209, 255, 0.06);
        }
        .user-notification-item.unread {
            background: rgba(216, 143, 255, 0.08);
        }
        .user-notification-icon {
            width: 34px;
            height: 34px;
            flex: 0 0 34px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(216, 143, 255, 0.12);
            color: var(--accent);
        }
        .user-notification-copy { min-width: 0; }
        .user-notification-copy p {
            margin: 0 0 3px;
            color: var(--text-light);
            font-size: 12px;
            line-height: 1.45;
            white-space: normal;
        }
        .user-notification-copy small {
            color: var(--text-muted);
            font-size: 10px;
        }
        .user-notification-empty {
            padding: 32px 18px;
            text-align: center;
            color: var(--text-muted);
            font-size: 12px;
        }
        .user-notification-empty i {
            display: block;
            font-size: 24px;
            margin-bottom: 8px;
        }

        @media (max-width: 991.98px) {
            .user-notification-toggle {
                margin: 8px 0;
            }
            .user-notification-menu {
                position: absolute !important;
                right: 0 !important;
                left: auto !important;
            }
        }

    </style>
</head>
<body>

    <!-- AESTHETIC NOTIFICATION MODAL -->
    <div class="notif-overlay" id="notifOverlay">
        <div class="notif-box">
            <div class="notif-icon-wrap" id="notifIconWrap">
                <!-- Icon injected by JS -->
            </div>
            <h4 id="notifTitle">Success</h4>
            <p id="notifMessage">Message here</p>
            <button class="notif-btn" id="notifBtn">Okay</button>
        </div>
    </div>

    <!-- 1. NAVIGATION BAR -->
    <nav class="navbar navbar-expand-lg fixed-top">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center fw-bold" href="#">
                <img src="image/logo.png" onerror="this.style.display='none'; document.getElementById('fallback-logo').style.display='inline-block';" alt="Logo">
                AI Checker
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item"><a class="nav-link active" href="index.php">Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="services.php">Services</a></li>
                    <li class="nav-item"><a class="nav-link" href="#how-it-works">How It Works</a></li>
                    <li class="nav-item"><a class="nav-link" href="plan.php">Plan</a></li>
                    <li class="nav-item"><a class="nav-link" href="assignments.php">Assignments</a></li>
                    <li class="nav-item"><a class="nav-link" href="contacts.php">Contacts</a></li>
                    
                    <?php if ($isLoggedIn): ?>

                        <!-- USER NOTIFICATIONS -->
                        <li class="nav-item dropdown ms-lg-2">
                            <a class="nav-link dropdown-toggle user-notification-toggle"
                               href="#"
                               id="userNotificationDropdown"
                               role="button"
                               data-bs-toggle="dropdown"
                               data-bs-auto-close="outside"
                               aria-expanded="false"
                               aria-label="Notifications">
                                <i class="fa-solid fa-bell"></i>
                                <?php if ($userUnreadCount > 0): ?>
                                    <span class="user-notification-badge"><?php echo $userUnreadCount > 99 ? '99+' : $userUnreadCount; ?></span>
                                <?php endif; ?>
                            </a>

                            <div class="dropdown-menu dropdown-menu-end user-notification-menu"
                                 aria-labelledby="userNotificationDropdown">
                                <div class="user-notification-header">
                                    <strong>
                                        Notifications
                                        <?php if ($userUnreadCount > 0): ?>
                                            (<?php echo $userUnreadCount; ?>)
                                        <?php endif; ?>
                                    </strong>

                                    <?php if ($userUnreadCount > 0): ?>
                                        <form action="mark_user_notifications_read.php" method="post" class="m-0">
                                            <input type="hidden" name="redirect"
                                                   value="<?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'index.php'); ?>">
                                            <button type="submit" class="mark-read-btn">Mark all read</button>
                                        </form>
                                    <?php endif; ?>
                                </div>

                                <div class="user-notification-list">
                                    <?php if (empty($userNotifications)): ?>
                                        <div class="user-notification-empty">
                                            <i class="fa-regular fa-bell-slash"></i>
                                            No notifications yet
                                        </div>
                                    <?php else: ?>
                                        <?php foreach ($userNotifications as $notification): ?>
                                            <a href="assignments.php"
                                               class="user-notification-item <?php echo (int)$notification['is_read'] === 0 ? 'unread' : ''; ?>">
                                                <span class="user-notification-icon">
                                                    <i class="fa-solid fa-file-circle-check"></i>
                                                </span>
                                                <span class="user-notification-copy">
                                                    <p><?php echo htmlspecialchars($notification['message'] ?? 'Assignment update'); ?></p>
                                                    <small><?php echo htmlspecialchars(landing_notification_time_ago($notification['created_at'] ?? '')); ?></small>
                                                </span>
                                            </a>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </li>

                        <li class="nav-item dropdown ms-lg-3">
                            <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                                <img src="https://api.dicebear.com/7.x/avataaars/svg?seed=<?php echo $userAvatarSeed; ?>" class="rounded-circle" width="35" alt="User" style="border: 2px solid var(--border-glow);">
                                <?php echo $userName; ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end border-0 shadow">
                                <li><a class="dropdown-item" href="profile.php"><i class="fa-solid fa-user me-2"></i> Profile</a></li>
                                <li><a class="dropdown-item" href="assignments.php"><i class="fa-solid fa-list me-2"></i> My Assignments</a></li>
                                <li><hr class="dropdown-divider" style="border-color: var(--border-subtle);"></li>
                                <li><a class="dropdown-item text-danger" href="logout.php"><i class="fa-solid fa-sign-out-alt me-2"></i> Logout</a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item ms-lg-3"><a class="nav-link" href="login.php">Login</a></li>
                        <li class="nav-item"><a class="btn btn-purple text-white px-4 ms-2" href="register.php">Register</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <!-- 2. HERO SECTION -->
    <header class="hero-section d-flex align-items-center">
        <div class="container">
            <div class="row align-items-center">
                <div class="col-lg-6 mb-5 mb-lg-0" data-aos="fade-right">
                    <span class="badge rounded-pill px-3 py-2 mb-3">
                        <i class="fa-solid fa-bolt me-1"></i> #1 AI Checker Tool
                    </span>
                    <h1 class="hero-title display-4 mb-4">AI Assignment<br><span>Checker</span></h1>
                    <p class="lead mb-4">
                        Submit assignments and get AI plagiarism analysis and feedback. Ensure academic integrity today.
                    </p>
                    <div class="d-flex gap-3 flex-wrap">
                        <a href="register.php" class="btn btn-purple">Get Started</a>
                        <a href="assignments.php" class="btn btn-outline-purple">Submit Assignment</a>
                    </div>
                </div>
                <div class="col-lg-6 text-center" data-aos="fade-left">
                    <div class="checker-demo" style="border-radius: var(--radius-xl); box-shadow: var(--shadow-lg), 0 0 60px rgba(244,209,255,0.06); border: 2px solid var(--border-glow); background: var(--surface); backdrop-filter: blur(16px); overflow: hidden; position: relative; max-width: 520px; margin: 0 auto;">
                        <div style="position:absolute;inset:0;background-image:radial-gradient(rgba(244,209,255,0.03) 1px, transparent 1px);background-size:24px 24px;pointer-events:none;"></div>
                        <div style="display:flex;align-items:center;gap:8px;padding:16px 20px;border-bottom:1px solid var(--border-subtle);position:relative;z-index:2;">
                            <div style="width:10px;height:10px;border-radius:50%;background:#ff5f57;"></div>
                            <div style="width:10px;height:10px;border-radius:50%;background:#ffbd2e;"></div>
                            <div style="width:10px;height:10px;border-radius:50%;background:#28c840;"></div>
                            <span style="margin-left:auto;font-size:0.75rem;color:var(--text-muted);font-weight:500;">AI Checker — Analysis</span>
                        </div>
                        <div style="padding:24px 20px;position:relative;z-index:2;min-height:320px;display:flex;flex-direction:column;align-items:center;justify-content:center;">
                            <div class="demo-phase demo-phase-1" style="text-align:center;">
                                <div class="demo-doc" style="width:180px;height:220px;background:linear-gradient(145deg, rgba(244,209,255,0.06), rgba(244,209,255,0.02));border:1px solid var(--border-subtle);border-radius:12px;margin:0 auto 16px;position:relative;overflow:hidden;display:flex;flex-direction:column;padding:16px;">
                                    <div style="width:60%;height:8px;background:var(--accent);border-radius:4px;opacity:0.6;margin-bottom:12px;"></div>
                                    <div style="width:100%;height:5px;background:rgba(244,209,255,0.1);border-radius:3px;margin-bottom:6px;"></div>
                                    <div style="width:90%;height:5px;background:rgba(244,209,255,0.08);border-radius:3px;margin-bottom:6px;"></div>
                                    <div style="width:95%;height:5px;background:rgba(244,209,255,0.1);border-radius:3px;margin-bottom:6px;"></div>
                                    <div style="width:75%;height:5px;background:rgba(244,209,255,0.07);border-radius:3px;margin-bottom:6px;"></div>
                                    <div style="width:88%;height:5px;background:rgba(244,209,255,0.09);border-radius:3px;margin-bottom:6px;"></div>
                                    <div style="width:60%;height:5px;background:rgba(244,209,255,0.06);border-radius:3px;margin-bottom:14px;"></div>
                                    <div style="width:100%;height:5px;background:rgba(244,209,255,0.1);border-radius:3px;margin-bottom:6px;"></div>
                                    <div style="width:82%;height:5px;background:rgba(244,209,255,0.08);border-radius:3px;margin-bottom:6px;"></div>
                                    <div style="width:93%;height:5px;background:rgba(244,209,255,0.1);border-radius:3px;margin-bottom:6px;"></div>
                                    <div style="width:70%;height:5px;background:rgba(244,209,255,0.07);border-radius:3px;"></div>
                                    <div class="scan-line" style="position:absolute;left:0;right:0;height:3px;background:linear-gradient(90deg, transparent, var(--accent), transparent);top:-3px;box-shadow:0 0 12px var(--accent), 0 0 30px rgba(216,143,255,0.3);opacity:0;"></div>
                                </div>
                                <p style="font-size:0.85rem;color:var(--text-muted);font-weight:500;">
                                    <i class="fa-solid fa-file-lines me-1" style="color:var(--accent);"></i>
                                    assignment_research.pdf
                                </p>
                            </div>
                            <div class="demo-phase demo-phase-2" style="text-align:center;display:none;">
                                <div style="position:relative;width:120px;height:120px;margin:0 auto 20px;">
                                    <svg width="120" height="120" viewBox="0 0 120 120" style="transform:rotate(-90deg);">
                                        <circle cx="60" cy="60" r="52" fill="none" stroke="rgba(244,209,255,0.06)" stroke-width="6"/>
                                        <circle class="scan-progress-ring" cx="60" cy="60" r="52" fill="none" stroke="url(#scanGrad)" stroke-width="6" stroke-linecap="round" stroke-dasharray="326.73" stroke-dashoffset="326.73"/>
                                        <defs>
                                            <linearGradient id="scanGrad" x1="0%" y1="0%" x2="100%" y2="0%">
                                                <stop offset="0%" style="stop-color:#7B3F91"/>
                                                <stop offset="100%" style="stop-color:#D88FFF"/>
                                            </linearGradient>
                                        </defs>
                                    </svg>
                                    <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;flex-direction:column;">
                                        <i class="fa-solid fa-magnifying-glass-chart" style="font-size:1.4rem;color:var(--accent);animation:pulseIcon 1.2s ease-in-out infinite;"></i>
                                        <span class="scan-percent" style="font-family:'Space Grotesk',sans-serif;font-size:0.75rem;color:var(--text-body);margin-top:4px;font-weight:600;">0%</span>
                                    </div>
                                </div>
                                <p style="font-size:0.9rem;color:var(--text-body);font-weight:600;margin-bottom:4px;">Analyzing Content</p>
                                <div style="display:flex;gap:16px;justify-content:center;margin-top:8px;">
                                    <span class="scan-check" style="font-size:0.72rem;color:var(--text-muted);display:flex;align-items:center;gap:4px;opacity:0.4;"><i class="fa-solid fa-circle" style="font-size:5px;"></i> AI Patterns</span>
                                    <span class="scan-check" style="font-size:0.72rem;color:var(--text-muted);display:flex;align-items:center;gap:4px;opacity:0.4;"><i class="fa-solid fa-circle" style="font-size:5px;"></i> Plagiarism</span>
                                    <span class="scan-check" style="font-size:0.72rem;color:var(--text-muted);display:flex;align-items:center;gap:4px;opacity:0.4;"><i class="fa-solid fa-circle" style="font-size:5px;"></i> Readability</span>
                                </div>
                            </div>
                            <div class="demo-phase demo-phase-3" style="text-align:center;display:none;">
                                <div style="position:relative;width:140px;height:140px;margin:0 auto 16px;">
                                    <svg width="140" height="140" viewBox="0 0 140 140">
                                        <circle cx="70" cy="70" r="58" fill="none" stroke="rgba(244,209,255,0.06)" stroke-width="8"/>
                                        <circle class="result-ring" cx="70" cy="70" r="58" fill="none" stroke="url(#resultGrad)" stroke-width="8" stroke-linecap="round" stroke-dasharray="364.42" stroke-dashoffset="364.42" style="transform:rotate(-90deg);transform-origin:center;"/>
                                        <defs>
                                            <linearGradient id="resultGrad" x1="0%" y1="0%" x2="100%" y2="0%">
                                                <stop offset="0%" style="stop-color:#28c840"/>
                                                <stop offset="100%" style="stop-color:#4ade80"/>
                                            </linearGradient>
                                        </defs>
                                    </svg>
                                    <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;flex-direction:column;">
                                        <span class="result-score" style="font-family:'Space Grotesk',sans-serif;font-size:2.2rem;font-weight:700;background:linear-gradient(135deg,#4ade80,#28c840);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;line-height:1;">0</span>
                                        <span style="font-size:0.65rem;color:var(--text-muted);font-weight:500;letter-spacing:0.08em;text-transform:uppercase;">AI Score</span>
                                    </div>
                                </div>
                                <div class="result-badge" style="display:inline-flex;align-items:center;gap:6px;background:rgba(40,200,64,0.1);border:1px solid rgba(40,200,64,0.25);padding:8px 20px;border-radius:50px;margin-bottom:14px;opacity:0;transform:translateY(8px);">
                                    <i class="fa-solid fa-circle-check" style="color:#4ade80;"></i>
                                    <span style="font-size:0.82rem;color:#4ade80;font-weight:600;">Likely Human Written</span>
                                </div>
                                <div class="result-details" style="display:grid;grid-template-columns:1fr 1fr;gap:8px;width:100%;max-width:280px;opacity:0;transform:translateY(8px);">
                                    <div style="background:rgba(244,209,255,0.04);border:1px solid var(--border-subtle);border-radius:10px;padding:10px 12px;text-align:left;">
                                        <div style="font-size:0.65rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">AI Content</div>
                                        <div style="font-family:'Space Grotesk',sans-serif;font-size:1.1rem;font-weight:700;color:#4ade80;">12%</div>
                                    </div>
                                    <div style="background:rgba(244,209,255,0.04);border:1px solid var(--border-subtle);border-radius:10px;padding:10px 12px;text-align:left;">
                                        <div style="font-size:0.65rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">Plagiarism</div>
                                        <div style="font-family:'Space Grotesk',sans-serif;font-size:1.1rem;font-weight:700;color:#4ade80;">3%</div>
                                    </div>
                                    <div style="background:rgba(244,209,255,0.04);border:1px solid var(--border-subtle);border-radius:10px;padding:10px 12px;text-align:left;">
                                        <div style="font-size:0.65rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">Readability</div>
                                        <div style="font-family:'Space Grotesk',sans-serif;font-size:1.1rem;font-weight:700;color:var(--primary-light);">A+</div>
                                    </div>
                                    <div style="background:rgba(244,209,255,0.04);border:1px solid var(--border-subtle);border-radius:10px;padding:10px 12px;text-align:left;">
                                        <div style="font-size:0.65rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.05em;">Status</div>
                                        <div style="font-family:'Space Grotesk',sans-serif;font-size:1.1rem;font-weight:700;color:#4ade80;">Pass</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- 3. CHOOSE PLAN SECTION -->
    <section class="py-5" style="background: transparent; position: relative;">
        <div style="position:absolute;top:0;left:10%;right:10%;height:1px;background:linear-gradient(90deg,transparent,var(--border-glow),transparent);"></div>
        <div class="container">
            <div class="text-center mb-5" data-aos="fade-up">
                <h6 class="mb-2" style="font-size:0.78rem;letter-spacing:0.15em;text-transform:uppercase;color:var(--accent);font-weight:600;">Pricing</h6>
                <h2 class="fw-bold" style="color:var(--text-dark);">Choose Your Plan</h2>
                <p class="text-muted">Select a package that fits your needs</p>
            </div>
            <div class="row g-4">
                <div class="col-md-4" data-aos="zoom-in" data-aos-delay="100">
                    <a href="plan.php" class="plan-card-wrapper">
                        <div class="zoom-container">
                            <img src="image/bear1.png" alt="Basic Plan">
                            <div class="plan-overlay"><span class="plan-btn">View Basic Plan</span></div>
                        </div>
                        <div class="plan-title">Basic Plan</div>
                    </a>
                </div>
                <div class="col-md-4" data-aos="zoom-in" data-aos-delay="200">
                    <a href="plan.php" class="plan-card-wrapper">
                        <div class="zoom-container">
                            <img src="image/bear2.png" alt="Pro Plan">
                            <div class="plan-overlay"><span class="plan-btn">View Pro Plan</span></div>
                        </div>
                        <div class="plan-title">Pro Plan</div>
                    </a>
                </div>
                <div class="col-md-4" data-aos="zoom-in" data-aos-delay="300">
                    <a href="plan.php" class="plan-card-wrapper">
                        <div class="zoom-container">
                            <img src="image/bear3.png" alt="Enterprise Plan">
                            <div class="plan-overlay"><span class="plan-btn">View Enterprise Plan</span></div>
                        </div>
                        <div class="plan-title">Enterprise Plan</div>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- 4. SERVICES SECTION -->
    <section id="services" class="py-5" style="background: transparent;">
        <div class="container">
            <div class="text-center mb-5" data-aos="fade-up">
                <h6 class="text-uppercase fw-bold" style="color:var(--accent);">Our Services</h6>
                <h2 class="fw-bold" style="color:var(--text-dark);">What We Offer</h2>
            </div>
            <div class="row g-4">
                <div class="col-md-6 col-lg-3" data-aos="fade-up" data-aos-delay="100">
                    <div class="feature-card text-center">
                        <div class="icon-box mx-auto"><i class="fa-solid fa-robot"></i></div>
                        <h4>AI Content Detection</h4>
                        <p class="small" style="color:var(--text-muted);">Identify AI-generated text instantly with high accuracy.</p>
                        <a href="services.php" class="btn btn-sm btn-outline-purple mt-2">Learn More</a>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3" data-aos="fade-up" data-aos-delay="200">
                    <div class="feature-card text-center">
                        <div class="icon-box mx-auto"><i class="fa-solid fa-file-contract"></i></div>
                        <h4>Plagiarism Checking</h4>
                        <p class="small" style="color:var(--text-muted);">Scan databases and web for duplicate content.</p>
                        <a href="services.php" class="btn btn-sm btn-outline-purple mt-2">Learn More</a>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3" data-aos="fade-up" data-aos-delay="300">
                    <div class="feature-card text-center">
                        <div class="icon-box mx-auto"><i class="fa-solid fa-list-check"></i></div>
                        <h4>Assignment Tracking</h4>
                        <p class="small" style="color:var(--text-muted);">Monitor the status of your submissions in real-time.</p>
                        <a href="services.php" class="btn btn-sm btn-outline-purple mt-2">Learn More</a>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3" data-aos="fade-up" data-aos-delay="400">
                    <div class="feature-card text-center">
                        <div class="icon-box mx-auto"><i class="fa-solid fa-shield-halved"></i></div>
                        <h4>Secure Payment</h4>
                        <p class="small" style="color:var(--text-muted);">Safe encrypted transactions for premium features.</p>
                        <a href="services.php" class="btn btn-sm btn-outline-purple mt-2">Learn More</a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- 5. TESTIMONIALS SECTION -->
    <section id="testimonials" class="py-5" style="background: transparent; position: relative;">
        <div style="position:absolute;top:0;left:10%;right:10%;height:1px;background:linear-gradient(90deg,transparent,var(--border-glow),transparent);"></div>
        <div class="container">
            <div class="text-center mb-5" data-aos="fade-up">
                <h6 style="font-size:0.78rem;letter-spacing:0.15em;text-transform:uppercase;color:var(--accent);font-weight:600;" class="mb-2">Feedback</h6>
                <h2 class="fw-bold" style="color:var(--text-dark);">Student Testimonials</h2>
            </div>
            <div class="row g-4">
                <?php 
                if (empty($testimonials)) {
                    $testimonials = [
                        ["name" => "Alice Smith", "feedback" => "Great tool for checking my thesis!", "rating" => 5, "avatar" => "Alice", "formatted_date" => "Oct 10"],
                        ["name" => "John Doe", "feedback" => "Fast and accurate results.", "rating" => 4, "avatar" => "John", "formatted_date" => "Oct 12"],
                        ["name" => "Sara Lee", "feedback" => "Helped me improve my writing style.", "rating" => 5, "avatar" => "Sara", "formatted_date" => "Oct 15"]
                    ];
                }
                foreach($testimonials as $index => $t): 
                    $avatarUrl = (strpos($t['avatar'], 'http') === 0) 
                        ? $t['avatar'] 
                        : "https://api.dicebear.com/7.x/avataaars/svg?seed=" . $t['avatar'];
                ?>
                    <div class="col-md-4" data-aos="fade-up" data-aos-delay="<?php echo $index * 100; ?>">
                        <div class="testimonial-card">
                            <div class="d-flex align-items-center mb-3">
                                <img src="<?php echo $avatarUrl; ?>" class="avatar-img me-3" alt="User">
                                <div>
                                    <h6 class="fw-bold mb-0" style="color:var(--text-dark);"><?php echo htmlspecialchars($t['name']); ?></h6>
                                    <small style="color:var(--text-muted);"><?php echo $t['formatted_date'] ?? 'Recently'; ?></small>
                                </div>
                            </div>
                            <div class="small mb-2">
                                <?php for($i=0; $i<$t['rating']; $i++) echo '<i class="fa-solid fa-star"></i>'; ?>
                            </div>
                            <p class="fst-italic" style="color:var(--text-body);">"<?php echo htmlspecialchars($t['feedback']); ?>"</p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- 6. KEY FEATURES SECTION -->
    <section class="py-5" style="background: transparent; position: relative;">
        <div style="position:absolute;top:0;left:10%;right:10%;height:1px;background:linear-gradient(90deg,transparent,var(--border-glow),transparent);"></div>
        <div class="container">
            <div class="text-center mb-5" data-aos="fade-up">
                <h6 class="text-uppercase fw-bold" style="color:var(--accent);">Why Choose Us</h6>
                <h2 class="fw-bold" style="color:var(--text-dark);">Key Features</h2>
            </div>
            <div class="row g-4">
                <div class="col-md-6 col-lg-3" data-aos="fade-up" data-aos-delay="0">
                    <div class="feature-card text-center" style="padding:36px 24px;">
                        <i class="fa-solid fa-bolt fa-3x mb-3" style="color:var(--accent);"></i>
                        <h5 style="color:var(--text-dark);">Fast AI Detection</h5>
                        <p class="small" style="color:var(--text-muted);">Get results in seconds, not hours.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3" data-aos="fade-up" data-aos-delay="100">
                    <div class="feature-card text-center" style="padding:36px 24px;">
                        <i class="fa-solid fa-chart-pie fa-3x mb-3" style="color:var(--accent);"></i>
                        <h5 style="color:var(--text-dark);">Accurate Reports</h5>
                        <p class="small" style="color:var(--text-muted);">Detailed breakdown of your content.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3" data-aos="fade-up" data-aos-delay="200">
                    <div class="feature-card text-center" style="padding:36px 24px;">
                        <i class="fa-solid fa-lock fa-3x mb-3" style="color:var(--accent);"></i>
                        <h5 style="color:var(--text-dark);">Secure System</h5>
                        <p class="small" style="color:var(--text-muted);">Your data is encrypted and safe.</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3" data-aos="fade-up" data-aos-delay="300">
                    <div class="feature-card text-center" style="padding:36px 24px;">
                        <i class="fa-solid fa-gauge-high fa-3x mb-3" style="color:var(--accent);"></i>
                        <h5 style="color:var(--text-dark);">Easy Dashboard</h5>
                        <p class="small" style="color:var(--text-muted);">User-friendly interface for everyone.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- 7. STATISTICS SECTION -->
    <section id="stats" class="py-5 bg-gradient-purple text-white">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-4 col-md-6" data-aos="fade-down">
                    <div class="stat-box">
                        <i class="fa-solid fa-users fa-2x mb-2"></i>
                        <div class="stat-number" data-target="<?php echo $stats['users']; ?>">0</div>
                        <small>Total Users</small>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6" data-aos="fade-down" data-aos-delay="100">
                    <div class="stat-box">
                        <i class="fa-solid fa-file-alt fa-2x mb-2"></i>
                        <div class="stat-number" data-target="<?php echo $stats['assignments']; ?>">0</div>
                        <small>Assignments</small>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6" data-aos="fade-down" data-aos-delay="300">
                    <div class="stat-box">
                        <i class="fa-solid fa-ticket fa-2x mb-2"></i>
                        <div class="stat-number" data-target="<?php echo $stats['vouchers']; ?>">0</div>
                        <small>Vouchers</small>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- 8. HOW IT WORKS SECTION -->
    <section id="how-it-works" class="py-5" style="background: transparent;">
        <div class="container">
            <div class="text-center mb-5" data-aos="fade-up">
                <h6 class="text-uppercase fw-bold" style="color:var(--accent);">Process</h6>
                <h2 class="fw-bold" style="color:var(--text-dark);">How It Works</h2>
                <p class="text-muted">Simple 4-step process to check your assignment</p>
            </div>
            <div class="row g-4">
                <div class="col-6 col-md-3" data-aos="fade-up" data-aos-delay="0">
                    <a href="register.php" class="step-item">
                        <div class="step-circle">1</div>
                        <h5>Sign Up</h5>
                        <p>Create your free account in seconds</p>
                    </a>
                </div>
                <div class="col-6 col-md-3" data-aos="fade-up" data-aos-delay="100">
                    <a href="assignments.php" class="step-item">
                        <div class="step-circle">2</div>
                        <h5>Submit</h5>
                        <p>Upload your assignment file</p>
                    </a>
                </div>
                <div class="col-6 col-md-3" data-aos="fade-up" data-aos-delay="200">
                    <a href="assignments.php" class="step-item">
                        <div class="step-circle">3</div>
                        <h5>Analyze</h5>
                        <p>AI scans for plagiarism & AI content</p>
                    </a>
                </div>
                <div class="col-6 col-md-3" data-aos="fade-up" data-aos-delay="300">
                    <a href="assignments.php" class="step-item">
                        <div class="step-circle">4</div>
                        <h5>Get Report</h5>
                        <p>Download detailed analysis report</p>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- 9. VOUCHERS SECTION -->
    <?php if (!empty($vouchers)): ?>
    <section class="py-5" style="background: transparent; position: relative;">
        <div style="position:absolute;top:0;left:10%;right:10%;height:1px;background:linear-gradient(90deg,transparent,var(--border-glow),transparent);"></div>
        <div class="container">
            <div class="text-center mb-5" data-aos="fade-up">
                <h6 class="text-uppercase fw-bold" style="color:var(--accent);">Offers</h6>
                <h2 class="fw-bold" style="color:var(--text-dark);">Active Vouchers</h2>
                <p class="text-muted">Use these codes at checkout for discounts</p>
            </div>
            <div class="row g-4 justify-content-center">
                <?php foreach($vouchers as $v): ?>
                <div class="col-md-6 col-lg-3" data-aos="zoom-in" data-aos-delay="100">
                    <div class="voucher-card p-4 text-center">
                        <div style="position:relative;z-index:1;">
                            <small style="font-size:0.7rem;opacity:0.6;text-transform:uppercase;letter-spacing:0.1em;">Discount</small>
                            <div style="font-family:'Space Grotesk',sans-serif;font-size:2rem;font-weight:700;color:white;margin:4px 0;">
                                <?php echo $v['discount_amount']; ?>%
                            </div>
                            <div style="background:rgba(0,0,0,0.25);padding:8px 16px;border-radius:8px;font-family:'Space Grotesk',monospace;font-weight:600;font-size:0.95rem;letter-spacing:0.1em;color:white;margin:12px 0;">
                                <?php echo htmlspecialchars($v['code']); ?>
                            </div>
                            <small style="font-size:0.72rem;opacity:0.5;">Expires: <?php echo $v['expiry_date']; ?></small>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- 10. CONTACT / REVIEW SECTION -->
    <section id="contact" class="contact-section py-5" style="background: transparent;">
        <div class="container">
            <div class="text-center mb-5" data-aos="fade-up">
                <h6 class="text-uppercase fw-bold" style="color:var(--accent);">Get In Touch</h6>
                <h2 class="fw-bold" style="color:var(--text-dark);">Leave a Review</h2>
            </div>
            <div class="row g-4 justify-content-center">
                <div class="col-md-5" data-aos="fade-right">
                    <div class="bg-gradient-purple p-5 rounded-4 h-100 d-flex flex-column justify-content-center">
                        <h3 class="mb-3">Share Your Experience</h3>
                        <p style="color:rgba(244,209,255,0.7);font-size:0.95rem;line-height:1.7;">Your feedback helps us improve and helps other students find the right tool.</p>
                        <div class="mt-4">
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <div style="width:40px;height:40px;background:rgba(244,209,255,0.1);border-radius:12px;display:flex;align-items:center;justify-content:center;">
                                    <i class="fa-solid fa-envelope" style="color:var(--primary-light);font-size:0.9rem;"></i>
                                </div>
                                <div>
                                    <small style="color:rgba(244,209,255,0.5);font-size:0.72rem;">Email</small>
                                    <div style="color:white;font-size:0.88rem;font-weight:500;">support@aichecker.com</div>
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-3">
                                <div style="width:40px;height:40px;background:rgba(244,209,255,0.1);border-radius:12px;display:flex;align-items:center;justify-content:center;">
                                    <i class="fa-solid fa-clock" style="color:var(--primary-light);font-size:0.9rem;"></i>
                                </div>
                                <div>
                                    <small style="color:rgba(244,209,255,0.5);font-size:0.72rem;">Response Time</small>
                                    <div style="color:white;font-size:0.88rem;font-weight:500;">Within 24 hours</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-7" data-aos="fade-left">
                    <div class="card rounded-4 p-4 p-md-5 h-100">
                        <form method="POST" action="index.php#contact" id="reviewForm">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label">Your Name</label>
                                    <input type="text" name="t_name" class="form-control" placeholder="Enter your name" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Rating</label>
                                    <div class="star-rating-input" id="starRating">
                                        <i class="fa-solid fa-star" data-rating="1"></i>
                                        <i class="fa-solid fa-star" data-rating="2"></i>
                                        <i class="fa-solid fa-star" data-rating="3"></i>
                                        <i class="fa-solid fa-star" data-rating="4"></i>
                                        <i class="fa-solid fa-star" data-rating="5"></i>
                                    </div>
                                    <input type="hidden" name="t_rating" id="ratingInput" value="5">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Your Feedback</label>
                                    <textarea name="t_feedback" class="form-control" rows="4" placeholder="Tell us about your experience..." required></textarea>
                                </div>
                                <div class="col-12">
                                    <button type="submit" name="submit_testimonial" class="btn btn-purple w-100">
                                        <i class="fa-solid fa-paper-plane me-2"></i> Submit Review
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- 11. FOOTER -->
    <footer>
        <div class="container">
            <div class="row g-4">
                <div class="col-md-4">
                    <h5><i class="fa-solid fa-robot me-2" style="color:var(--accent);"></i> AI Checker</h5>
                    <p class="small">The most trusted AI assignment checking tool for students and educators worldwide. Ensure academic integrity with every submission.</p>
                </div>
                <div class="col-md-2">
                    <h6>Quick Links</h6>
                    <ul class="list-unstyled">
                        <li><a href="index.php">Home</a></li>
                        <li><a href="services.php">Services</a></li>
                        <li><a href="plan.php">Plans</a></li>
                        <li><a href="assignments.php">Assignments</a></li>
                    </ul>
                </div>
                <div class="col-md-2">
                    <h6>Support</h6>
                    <ul class="list-unstyled">
                        <li><a href="contacts.php">Contact Us</a></li>
                        <li><a href="#">FAQ</a></li>
                        <li><a href="#">Privacy Policy</a></li>
                        <li><a href="#">Terms of Service</a></li>
                    </ul>
                </div>
                <div class="col-md-4">
                    <h6>Stay Updated</h6>
                    <p class="small">Follow us for updates and tips on academic writing.</p>
                    <div class="d-flex gap-3 mt-2">
                        <a href="#" style="width:36px;height:36px;background:rgba(244,209,255,0.06);border:1px solid var(--border-subtle);border-radius:10px;display:flex;align-items:center;justify-content:center;color:var(--primary-light);transition:all 0.3s ease;" onmouseover="this.style.background='var(--primary)';this.style.color='white';this.style.borderColor='transparent';" onmouseout="this.style.background='rgba(244,209,255,0.06)';this.style.color='var(--primary-light)';this.style.borderColor='var(--border-subtle)';"><i class="fa-brands fa-facebook-f"></i></a>
                        <a href="#" style="width:36px;height:36px;background:rgba(244,209,255,0.06);border:1px solid var(--border-subtle);border-radius:10px;display:flex;align-items:center;justify-content:center;color:var(--primary-light);transition:all 0.3s ease;" onmouseover="this.style.background='var(--primary)';this.style.color='white';this.style.borderColor='transparent';" onmouseout="this.style.background='rgba(244,209,255,0.06)';this.style.color='var(--primary-light)';this.style.borderColor='var(--border-subtle)';"><i class="fa-brands fa-twitter"></i></a>
                        <a href="#" style="width:36px;height:36px;background:rgba(244,209,255,0.06);border:1px solid var(--border-subtle);border-radius:10px;display:flex;align-items:center;justify-content:center;color:var(--primary-light);transition:all 0.3s ease;" onmouseover="this.style.background='var(--primary)';this.style.color='white';this.style.borderColor='transparent';" onmouseout="this.style.background='rgba(244,209,255,0.06)';this.style.color='var(--primary-light)';this.style.borderColor='var(--border-subtle)';"><i class="fa-brands fa-instagram"></i></a>
                        <a href="#" style="width:36px;height:36px;background:rgba(244,209,255,0.06);border:1px solid var(--border-subtle);border-radius:10px;display:flex;align-items:center;justify-content:center;color:var(--primary-light);transition:all 0.3s ease;" onmouseover="this.style.background='var(--primary)';this.style.color='white';this.style.borderColor='transparent';" onmouseout="this.style.background='rgba(244,209,255,0.06)';this.style.color='var(--primary-light)';this.style.borderColor='var(--border-subtle)';"><i class="fa-brands fa-github"></i></a>
                    </div>
                </div>
            </div>
            <div class="border-top text-center mt-4 pt-3">
                <small>&copy; <?php echo date('Y'); ?> AI Checker. All rights reserved.</small>
            </div>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- AOS Animation JS -->
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    
    <script>
        // Initialize AOS
        AOS.init({
            duration: 800,
            easing: 'ease-out-cubic',
            once: true,
            offset: 60
        });

        // Navbar scroll effect
        window.addEventListener('scroll', function() {
            const navbar = document.querySelector('.navbar');
            if (window.scrollY > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });

        // Star rating interaction
        const starContainer = document.getElementById('starRating');
        const ratingInput = document.getElementById('ratingInput');
        const stars = starContainer ? starContainer.querySelectorAll('i') : [];

        if (starContainer) {
            function updateStars(rating) {
                stars.forEach((star, i) => {
                    if (i < rating) {
                        star.classList.add('active');
                    } else {
                        star.classList.remove('active');
                    }
                });
                ratingInput.value = rating;
            }

            stars.forEach((star, i) => {
                star.addEventListener('mouseenter', () => updateStars(i + 1));
                star.addEventListener('click', () => updateStars(i + 1));
            });

            starContainer.addEventListener('mouseleave', () => {
                updateStars(parseInt(ratingInput.value));
            });

            updateStars(5);
        }

        // Stat counter animation
        const statNumbers = document.querySelectorAll('.stat-number[data-target]');
        const statObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const el = entry.target;
                    const target = parseInt(el.getAttribute('data-target'));
                    let current = 0;
                    const increment = Math.max(1, Math.ceil(target / 60));
                    const timer = setInterval(() => {
                        current += increment;
                        if (current >= target) {
                            current = target;
                            clearInterval(timer);
                        }
                        el.textContent = current;
                    }, 30);
                    statObserver.unobserve(el);
                }
            });
        }, { threshold: 0.5 });

        statNumbers.forEach(el => statObserver.observe(el));

        // ===== AESTHETIC NOTIFICATION SYSTEM =====
        function showNotif(type, title, message) {
            const overlay = document.getElementById('notifOverlay');
            const iconWrap = document.getElementById('notifIconWrap');
            const titleEl = document.getElementById('notifTitle');
            const msgEl = document.getElementById('notifMessage');

            // Reset classes
            iconWrap.className = 'notif-icon-wrap';

            if (type === 'success') {
                iconWrap.classList.add('success');
                iconWrap.innerHTML = '<svg width="32" height="32" viewBox="0 0 32 32" fill="none"><path class="check-path" d="M8 16.5L13.5 22L24 11" stroke="#4ade80" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                titleEl.textContent = title || 'Review Submitted!';
            } else {
                iconWrap.classList.add('error');
                iconWrap.innerHTML = '<i class="fa-solid fa-xmark"></i>';
                titleEl.textContent = title || 'Something Went Wrong';
            }

            msgEl.textContent = message;

            // Show
            requestAnimationFrame(() => {
                overlay.classList.add('active');
            });

            // Prevent body scroll
            document.body.style.overflow = 'hidden';
        }

        function hideNotif() {
            const overlay = document.getElementById('notifOverlay');
            overlay.classList.remove('active');
            document.body.style.overflow = '';
        }

        // Okay button
        document.getElementById('notifBtn').addEventListener('click', hideNotif);

        // Close on overlay click (outside box)
        document.getElementById('notifOverlay').addEventListener('click', function(e) {
            if (e.target === this) hideNotif();
        });

        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') hideNotif();
        });

        // Show notification from PHP session if exists
        <?php if (!empty($reviewMsg)): ?>
            setTimeout(function() {
                showNotif(
                    '<?php echo $reviewType; ?>',
                    '<?php echo $reviewType === "success" ? "Review Submitted!" : "Oops!"; ?>',
                    '<?php echo addslashes($reviewMsg); ?>'
                );
            }, 600);
        <?php endif; ?>

        // ===== CHECKER DEMO ANIMATION =====
        (function(){
            const phases = document.querySelectorAll('.demo-phase');
            if (phases.length === 0) return;

            const scanLine = document.querySelector('.scan-line');
            const scanRing = document.querySelector('.scan-progress-ring');
            const scanPercent = document.querySelector('.scan-percent');
            const scanChecks = document.querySelectorAll('.scan-check');
            const resultRing = document.querySelector('.result-ring');
            const resultScore = document.querySelector('.result-score');
            const resultBadge = document.querySelector('.result-badge');
            const resultDetails = document.querySelector('.result-details');
            const circumference = 2 * Math.PI * 52;
            const resultCircumference = 2 * Math.PI * 58;
            const targetScore = 12;

            function showPhase(n) {
                phases.forEach((p, i) => {
                    p.style.display = i === n ? 'block' : 'none';
                });
            }

            function animateScanLine() {
                return new Promise(resolve => {
                    scanLine.style.opacity = '1';
                    scanLine.style.transition = 'none';
                    scanLine.style.top = '-3px';
                    requestAnimationFrame(() => {
                        scanLine.style.transition = 'top 1.5s ease-in-out';
                        scanLine.style.top = '100%';
                    });
                    setTimeout(() => {
                        scanLine.style.opacity = '0';
                        resolve();
                    }, 1600);
                });
            }

            function animateScanRing() {
                return new Promise(resolve => {
                    let progress = 0;
                    scanRing.style.strokeDashoffset = circumference;
                    const interval = setInterval(() => {
                        progress += 2;
                        if (progress > 100) { progress = 100; clearInterval(interval); }
                        const offset = circumference - (progress / 100) * circumference;
                        scanRing.style.strokeDashoffset = offset;
                        scanRing.style.transition = 'stroke-dashoffset 0.15s ease';
                        scanPercent.textContent = progress + '%';

                        if (progress >= 30) { scanChecks[0].style.opacity = '1'; scanChecks[0].querySelector('i').style.color = '#4ade80'; }
                        if (progress >= 60) { scanChecks[1].style.opacity = '1'; scanChecks[1].querySelector('i').style.color = '#4ade80'; }
                        if (progress >= 90) { scanChecks[2].style.opacity = '1'; scanChecks[2].querySelector('i').style.color = '#4ade80'; }

                        if (progress >= 100) setTimeout(resolve, 400);
                    }, 50);
                });
            }

            function animateResult() {
                return new Promise(resolve => {
                    resultRing.style.strokeDashoffset = resultCircumference;
                    resultRing.style.transition = 'none';
                    resultScore.textContent = '0';
                    resultBadge.style.opacity = '0';
                    resultBadge.style.transform = 'translateY(8px)';
                    resultDetails.style.opacity = '0';
                    resultDetails.style.transform = 'translateY(8px)';

                    requestAnimationFrame(() => {
                        const targetOffset = resultCircumference - ((100 - targetScore) / 100) * resultCircumference;
                        resultRing.style.transition = 'stroke-dashoffset 1.2s cubic-bezier(0.4, 0, 0.2, 1)';
                        resultRing.style.strokeDashoffset = targetOffset;

                        let current = 0;
                        const scoreInterval = setInterval(() => {
                            current++;
                            if (current > targetScore) { current = targetScore; clearInterval(scoreInterval); }
                            resultScore.textContent = current + '%';
                        }, 50);

                        setTimeout(() => {
                            resultBadge.style.transition = 'all 0.5s ease';
                            resultBadge.style.opacity = '1';
                            resultBadge.style.transform = 'translateY(0)';
                        }, 800);

                        setTimeout(() => {
                            resultDetails.style.transition = 'all 0.5s ease';
                            resultDetails.style.opacity = '1';
                            resultDetails.style.transform = 'translateY(0)';
                        }, 1100);

                        setTimeout(resolve, 3000);
                    });
                });
            }

            function resetScanPhase() {
                scanRing.style.transition = 'none';
                scanRing.style.strokeDashoffset = circumference;
                scanPercent.textContent = '0%';
                scanChecks.forEach(c => {
                    c.style.opacity = '0.4';
                    c.querySelector('i').style.color = '';
                });
            }

            async function runDemo() {
                while (true) {
                    showPhase(0);
                    await animateScanLine();
                    await new Promise(r => setTimeout(r, 300));

                    showPhase(1);
                    resetScanPhase();
                    await animateScanRing();
                    await new Promise(r => setTimeout(r, 400));

                    showPhase(2);
                    await animateResult();
                    await new Promise(r => setTimeout(r, 1200));
                }
            }

            setTimeout(runDemo, 800);
        })();
    </script>
</body>
</html>