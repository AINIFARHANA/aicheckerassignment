<?php
session_start();

// Include database connection
include 'config.php';

// Safe count helper
function getSafeVal($conn, $sql) {
    if (!$conn) return 0;
    $result = $conn->query($sql);
    if ($result && $row = $result->fetch_row()) {
        return $row[0];
    }
    return 0;
}

// Fetch plan statistics
 $planStats = [
    'total_active' => getSafeVal($conn, "SELECT COUNT(*) FROM plans WHERE status='Active'"),
    'cheapest'     => getSafeVal($conn, "SELECT MIN(price) FROM plans WHERE status='Active'"),
    'premium'      => getSafeVal($conn, "SELECT COUNT(*) FROM plans WHERE badge='Premium'"),
    'popular'      => getSafeVal($conn, "SELECT COUNT(*) FROM plans WHERE badge='Popular'")
];

// Fetch all active plans ordered by price
 $plans = [];
if ($conn) {
    $sql = "SELECT * FROM plans WHERE status='Active' ORDER BY price ASC";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $plans[] = $row;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choose Your Plan - AI Assignment Checker</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800;900&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: #3B1347;
            --primary-rgb: 59, 19, 71;
            --primary-light: #F4D1FF;
            --primary-light-rgb: 244, 209, 255;
            --primary-mid: #7B3F91;
            --primary-soft: #5A1F6B;
            --accent: #D88FFF;
            --accent-rose: #FF8EC4;
            --accent-gold: #FFD6A5;
            --accent-cyan: #67E8F9;
            --bg-deep: #0D0612;
            --bg-dark: #150A1E;
            --bg-card: rgba(59, 19, 71, 0.3);
            --bg-card-hover: rgba(59, 19, 71, 0.5);
            --bg-glass: rgba(244, 209, 255, 0.04);
            --bg-glass-strong: rgba(244, 209, 255, 0.08);
            --text-bright: #FFFFFF;
            --text-light: #F4D1FF;
            --text-body: rgba(244, 209, 255, 0.72);
            --text-muted: rgba(244, 209, 255, 0.4);
            --border-subtle: rgba(244, 209, 255, 0.08);
            --border-glow: rgba(244, 209, 255, 0.15);
            --border-active: rgba(216, 143, 255, 0.4);
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.3);
            --shadow-md: 0 8px 32px rgba(0, 0, 0, 0.4);
            --shadow-lg: 0 20px 60px rgba(0, 0, 0, 0.5);
            --shadow-glow: 0 0 40px rgba(244, 209, 255, 0.08);
            --shadow-glow-strong: 0 0 60px rgba(216, 143, 255, 0.15);
            --radius-sm: 12px;
            --radius-md: 20px;
            --radius-lg: 28px;
            --radius-xl: 40px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Poppins', sans-serif;
            color: var(--text-body);
            overflow-x: hidden;
            background: var(--bg-deep);
            -webkit-font-smoothing: antialiased;
        }

        ::selection { background: var(--primary-mid); color: white; }
        .text-purple { color: var(--accent) !important; }
        .bg-purple { background-color: var(--primary-mid) !important; }

        /* --- Animated Starfield Canvas --- */
        #starfield {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            z-index: 0;
            pointer-events: none;
        }

        /* --- Aurora Background Blobs --- */
        .aurora-wrap {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            z-index: 0;
            pointer-events: none;
            overflow: hidden;
        }
        .aurora-blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(120px);
            opacity: 0.25;
            animation: auroraDrift 20s ease-in-out infinite;
        }
        .aurora-blob:nth-child(1) {
            width: 600px; height: 600px;
            background: radial-gradient(circle, rgba(59,19,71,0.8), transparent 70%);
            top: -10%; left: -10%;
            animation-duration: 25s;
        }
        .aurora-blob:nth-child(2) {
            width: 500px; height: 500px;
            background: radial-gradient(circle, rgba(123,63,145,0.5), transparent 70%);
            top: 30%; right: -15%;
            animation-duration: 30s;
            animation-delay: -5s;
        }
        .aurora-blob:nth-child(3) {
            width: 400px; height: 400px;
            background: radial-gradient(circle, rgba(244,209,255,0.15), transparent 70%);
            bottom: -5%; left: 30%;
            animation-duration: 22s;
            animation-delay: -10s;
        }
        .aurora-blob:nth-child(4) {
            width: 350px; height: 350px;
            background: radial-gradient(circle, rgba(216,143,255,0.12), transparent 70%);
            top: 60%; left: -5%;
            animation-duration: 28s;
            animation-delay: -8s;
        }
        @keyframes auroraDrift {
            0%, 100% { transform: translate(0, 0) scale(1); }
            25% { transform: translate(40px, -30px) scale(1.08); }
            50% { transform: translate(-20px, 20px) scale(0.95); }
            75% { transform: translate(30px, 15px) scale(1.05); }
        }

        /* All sections above fixed bg */
        section, footer { position: relative; z-index: 2; }

        /* --- Noise overlay --- */
        body::after {
            content: '';
            position: fixed;
            inset: 0;
            z-index: 1;
            pointer-events: none;
            opacity: 0.015;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
            background-repeat: repeat;
            background-size: 256px;
        }

        /* --- Cursor Glow --- */
        .cursor-glow {
            position: fixed;
            width: 300px; height: 300px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(244,209,255,0.03), transparent 60%);
            pointer-events: none;
            z-index: 1;
            transform: translate(-50%, -50%);
            transition: opacity 0.3s ease;
        }

        /* --- Buttons --- */
        .btn-purple {
            background: linear-gradient(135deg, var(--primary-mid), var(--accent));
            color: white; border: none;
            padding: 14px 36px; border-radius: 60px;
            font-weight: 600; font-size: 0.95rem;
            letter-spacing: 0.02em;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 24px rgba(123,63,145,0.4), inset 0 1px 0 rgba(255,255,255,0.1);
            position: relative; overflow: hidden;
            text-decoration: none; display: inline-block;
        }
        .btn-purple::before {
            content: ''; position: absolute;
            top: 0; left: -100%; width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(244,209,255,0.2), transparent);
            transition: left 0.6s;
        }
        .btn-purple:hover::before { left: 100%; }
        .btn-purple:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 8px 40px rgba(123,63,145,0.6), 0 0 20px rgba(244,209,255,0.1);
            color: white;
        }

        .btn-outline-purple {
            border: 1.5px solid var(--border-glow);
            color: var(--primary-light);
            padding: 12px 36px; border-radius: 60px;
            font-weight: 600; font-size: 0.95rem;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none; display: inline-block;
            background: rgba(244, 209, 255, 0.04);
            backdrop-filter: blur(8px);
        }
        .btn-outline-purple:hover {
            background: var(--primary-mid); color: white; text-decoration: none;
            transform: translateY(-3px);
            box-shadow: 0 8px 30px rgba(123,63,145,0.4);
            border-color: var(--primary-mid);
        }

        /* --- Section Headings --- */
        .section-label {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 0.78rem; letter-spacing: 0.18em;
            text-transform: uppercase; color: var(--accent); font-weight: 600;
        }
        .section-title {
            font-weight: 800; color: var(--text-bright);
            letter-spacing: -0.03em;
            font-size: clamp(1.6rem, 3vw, 2.4rem);
        }
        .section-subtitle {
            color: var(--text-muted) !important; font-size: 0.95rem; max-width: 560px;
        }

        /* ============================================================
           PLAN HERO
           ============================================================ */
        .plan-hero {
            padding: 160px 0 100px;
            position: relative; overflow: hidden;
            background: transparent;
        }
        .plan-hero::before {
            content: ''; position: absolute;
            top: -30%; right: -20%;
            width: 800px; height: 800px;
            background: radial-gradient(circle, rgba(123,63,145,0.18) 0%, transparent 55%);
            border-radius: 50%; animation: heroFloat 12s ease-in-out infinite;
        }
        .plan-hero::after {
            content: ''; position: absolute;
            bottom: -25%; left: -15%;
            width: 600px; height: 600px;
            background: radial-gradient(circle, rgba(255,142,196,0.08) 0%, transparent 55%);
            border-radius: 50%; animation: heroFloat 15s ease-in-out infinite reverse;
        }
        @keyframes heroFloat {
            0%, 100% { transform: translate(0, 0) scale(1); }
            33% { transform: translate(30px, -20px) scale(1.06); }
            66% { transform: translate(-20px, 15px) scale(0.94); }
        }

        .plan-hero-title {
            font-weight: 900; color: var(--text-bright);
            line-height: 1.08; letter-spacing: -0.04em;
            font-size: clamp(2.2rem, 5.5vw, 3.6rem);
        }
        .plan-hero-title span {
            background: linear-gradient(135deg, var(--primary-light), var(--accent-gold), var(--accent-rose));
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .plan-hero .lead {
            color: var(--text-body); font-size: 1.05rem;
            line-height: 1.8; max-width: 540px;
        }

        /* Floating icons */
        .plan-floating-icon {
            position: absolute; color: var(--primary-light);
            opacity: 0.04; pointer-events: none;
            animation: iconDrift 7s ease-in-out infinite;
        }
        .plan-floating-icon:nth-child(1) { top: 12%; left: 6%; font-size: 2.5rem; animation-duration: 8s; }
        .plan-floating-icon:nth-child(2) { top: 22%; right: 10%; font-size: 1.8rem; animation-delay: 1s; animation-duration: 9s; }
        .plan-floating-icon:nth-child(3) { bottom: 18%; left: 12%; font-size: 3rem; animation-delay: 2s; animation-duration: 10s; }
        .plan-floating-icon:nth-child(4) { top: 45%; right: 5%; font-size: 2rem; animation-delay: 0.5s; animation-duration: 7.5s; }
        .plan-floating-icon:nth-child(5) { bottom: 28%; right: 18%; font-size: 1.6rem; animation-delay: 3s; animation-duration: 8.5s; }
        .plan-floating-icon:nth-child(6) { top: 65%; left: 8%; font-size: 2.2rem; animation-delay: 1.5s; animation-duration: 9.5s; }
        @keyframes iconDrift {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            25% { transform: translateY(-20px) rotate(8deg); }
            50% { transform: translateY(-10px) rotate(-5deg); }
            75% { transform: translateY(-25px) rotate(3deg); }
        }

        /* Hero decorative shapes */
        .hero-shape { position: absolute; border-radius: 50%; pointer-events: none; }
        .hero-shape-1 {
            width: 380px; height: 380px; top: -100px; right: 5%;
            border: 1px solid rgba(244, 209, 255, 0.04);
            animation: shapeSpin 45s linear infinite;
        }
        .hero-shape-2 {
            width: 260px; height: 260px; bottom: -60px; left: 8%;
            border: 1px dashed rgba(244, 209, 255, 0.03);
            animation: shapeSpin 35s linear infinite reverse;
        }
        .hero-shape-3 {
            width: 180px; height: 180px; top: 30%; right: 25%;
            border: 1px solid rgba(216, 143, 255, 0.04);
            animation: shapeSpin 25s linear infinite;
        }
        @keyframes shapeSpin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }

        /* Hero badge */
        .plan-hero .hero-badge {
            background: rgba(244, 209, 255, 0.06) !important;
            border: 1px solid var(--border-glow);
            color: var(--accent) !important;
            backdrop-filter: blur(10px);
        }

        /* Hero right visual - 3D card stack */
        .hero-visual-wrap {
            position: relative;
            width: 340px; height: 380px;
            margin: 0 auto;
            perspective: 1000px;
        }
        .hero-card-stack {
            position: absolute;
            width: 280px; height: 200px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border-subtle);
            backdrop-filter: blur(16px);
            transition: all 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .hero-card-stack:nth-child(1) {
            background: rgba(59, 19, 71, 0.5);
            top: 20px; left: 30px;
            transform: rotateY(-12deg) rotateX(5deg) translateZ(0);
            z-index: 3;
        }
        .hero-card-stack:nth-child(2) {
            background: rgba(59, 19, 71, 0.35);
            top: 50px; left: 20px;
            transform: rotateY(-8deg) rotateX(3deg) translateZ(-30px);
            z-index: 2;
        }
        .hero-card-stack:nth-child(3) {
            background: rgba(59, 19, 71, 0.2);
            top: 80px; left: 10px;
            transform: rotateY(-4deg) rotateX(1deg) translateZ(-60px);
            z-index: 1;
        }
        .hero-visual-wrap:hover .hero-card-stack:nth-child(1) {
            transform: rotateY(-5deg) rotateX(2deg) translateZ(20px) translateY(-10px);
        }
        .hero-visual-wrap:hover .hero-card-stack:nth-child(2) {
            transform: rotateY(-2deg) rotateX(1deg) translateZ(-10px) translateY(-5px);
        }
        .hero-visual-wrap:hover .hero-card-stack:nth-child(3) {
            transform: rotateY(1deg) rotateX(0deg) translateZ(-40px) translateY(0);
        }
        .hero-card-inner {
            padding: 24px;
        }
        .hero-card-inner .card-mini-badge {
            display: inline-block;
            background: rgba(244, 209, 255, 0.1);
            border: 1px solid var(--border-glow);
            color: var(--accent);
            font-size: 0.65rem; font-weight: 700;
            padding: 3px 10px; border-radius: 50px;
            letter-spacing: 0.06em; text-transform: uppercase;
            margin-bottom: 12px;
        }
        .hero-card-inner .card-mini-price {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 2rem; font-weight: 700;
            color: var(--text-bright);
            letter-spacing: -0.03em;
        }
        .hero-card-inner .card-mini-price small {
            font-size: 0.85rem; font-weight: 500;
            color: var(--text-muted);
        }
        .hero-card-inner .card-mini-line {
            height: 1px;
            background: linear-gradient(90deg, var(--border-glow), transparent);
            margin: 12px 0;
        }
        .hero-card-inner .card-mini-features {
            list-style: none; padding: 0; margin: 0;
        }
        .hero-card-inner .card-mini-features li {
            font-size: 0.75rem; color: var(--text-muted);
            padding: 3px 0;
            display: flex; align-items: center; gap: 8px;
        }
        .hero-card-inner .card-mini-features li i {
            color: var(--accent); font-size: 0.55rem;
        }

        /* Floating glow orbs around hero visual */
        .hero-glow-orb {
            position: absolute; border-radius: 50%; pointer-events: none;
        }
        .hero-glow-orb:nth-child(4) {
            width: 60px; height: 60px;
            top: 0; right: -10px;
            background: radial-gradient(circle, rgba(216,143,255,0.3), transparent 70%);
            animation: orbPulse 4s ease-in-out infinite;
        }
        .hero-glow-orb:nth-child(5) {
            width: 40px; height: 40px;
            bottom: 20px; left: -15px;
            background: radial-gradient(circle, rgba(255,142,196,0.25), transparent 70%);
            animation: orbPulse 5s ease-in-out infinite 1s;
        }
        .hero-glow-orb:nth-child(6) {
            width: 30px; height: 30px;
            top: 50%; right: -20px;
            background: radial-gradient(circle, rgba(255,214,165,0.2), transparent 70%);
            animation: orbPulse 6s ease-in-out infinite 2s;
        }
        @keyframes orbPulse {
            0%, 100% { transform: scale(1); opacity: 0.6; }
            50% { transform: scale(1.5); opacity: 1; }
        }

        /* ============================================================
           PLAN STATISTICS - Dark Glass Cards
           ============================================================ */
        .plan-stats-section {
            position: relative;
            margin-top: -40px;
            z-index: 3;
        }
        .plan-stats-section::before {
            content: ''; position: absolute;
            top: 0; left: 10%; right: 10%; height: 1px;
            background: linear-gradient(90deg, transparent, var(--border-glow), transparent);
        }
        .plan-stat-card {
            background: var(--bg-card);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-radius: var(--radius-md);
            padding: 28px 20px; text-align: center;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-subtle);
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            height: 100%;
            position: relative; overflow: hidden;
        }
        .plan-stat-card::before {
            content: ''; position: absolute;
            top: -2px; left: 20%; right: 20%; height: 2px;
            background: linear-gradient(90deg, transparent, var(--accent), transparent);
            opacity: 0; transition: opacity 0.5s ease;
        }
        .plan-stat-card:hover::before { opacity: 1; }
        .plan-stat-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-md), 0 0 30px rgba(244,209,255,0.06);
            border-color: var(--border-glow);
            background: var(--bg-card-hover);
        }
        .plan-stat-icon {
            width: 56px; height: 56px;
            background: rgba(244, 209, 255, 0.06);
            border: 1px solid var(--border-subtle);
            border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.3rem; color: var(--accent);
            margin: 0 auto 14px;
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .plan-stat-card:hover .plan-stat-icon {
            background: linear-gradient(135deg, var(--primary-mid), var(--accent));
            color: white; border-radius: 50%;
            transform: scale(1.15) rotate(-8deg);
            box-shadow: 0 0 25px rgba(216,143,255,0.35);
        }
        .plan-stat-value {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.8rem; font-weight: 700;
            color: var(--text-bright); letter-spacing: -0.02em;
            line-height: 1; margin-bottom: 4px;
        }
        .plan-stat-label {
            font-size: 0.8rem; color: var(--text-muted); font-weight: 500;
        }

        /* ============================================================
           SEARCH BAR - Dark Glass
           ============================================================ */
        .search-wrapper {
            max-width: 540px; margin: 0 auto;
            position: relative;
        }
        .search-wrapper i {
            position: absolute; left: 24px; top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted); font-size: 1rem;
            transition: color 0.3s ease;
        }
        .search-input {
            width: 100%; padding: 16px 24px 16px 56px;
            border: 1.5px solid var(--border-subtle);
            border-radius: 60px; font-size: 0.95rem;
            background: var(--bg-card);
            backdrop-filter: blur(16px);
            font-family: 'Poppins', sans-serif;
            color: var(--text-bright);
            transition: all 0.4s ease;
            box-shadow: var(--shadow-sm);
        }
        .search-input:focus {
            outline: none;
            border-color: var(--border-active);
            box-shadow: 0 0 0 4px rgba(216,143,255,0.1), var(--shadow-md), 0 0 30px rgba(244,209,255,0.04);
            background: var(--bg-card-hover);
        }
        .search-input::placeholder { color: var(--text-muted); }
        .search-wrapper:focus-within i { color: var(--accent); }

        /* ============================================================
           PLAN FILTER TABS - Unique addition
           ============================================================ */
        .plan-filter-tabs {
            display: flex; gap: 8px;
            justify-content: center;
            flex-wrap: wrap;
            margin-bottom: 40px;
        }
        .filter-tab {
            padding: 8px 20px;
            border-radius: 50px;
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--text-muted);
            background: var(--bg-glass);
            border: 1px solid var(--border-subtle);
            cursor: pointer;
            transition: all 0.3s ease;
            user-select: none;
        }
        .filter-tab:hover {
            color: var(--primary-light);
            border-color: var(--border-glow);
            background: var(--bg-glass-strong);
        }
        .filter-tab.active {
            background: linear-gradient(135deg, var(--primary-mid), var(--accent));
            color: white;
            border-color: transparent;
            box-shadow: 0 4px 20px rgba(123,63,145,0.4);
        }

        /* ============================================================
           PRICING CARDS - Dark Glassmorphism
           ============================================================ */
        .pricing-card {
            background: var(--bg-card);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-subtle);
            transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
            height: 100%;
            display: flex; flex-direction: column;
            position: relative;
        }
        .pricing-card::before {
            content: ''; position: absolute;
            top: 0; left: 0; right: 0; height: 2px;
            background: linear-gradient(90deg, transparent, var(--accent), var(--primary-light), var(--accent), transparent);
            transform: scaleX(0); transform-origin: center;
            transition: transform 0.6s ease;
            opacity: 0.8;
            z-index: 5;
        }
        .pricing-card::after {
            content: ''; position: absolute;
            inset: 0;
            background: radial-gradient(600px circle at var(--mouse-x, 50%) var(--mouse-y, 50%), rgba(244,209,255,0.04), transparent 40%);
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.4s ease;
            z-index: 0;
        }
        .pricing-card:hover::before { transform: scaleX(1); }
        .pricing-card:hover::after { opacity: 1; }
        .pricing-card:hover {
            transform: translateY(-14px);
            box-shadow: var(--shadow-lg), 0 0 50px rgba(244,209,255,0.06);
            border-color: var(--border-glow);
            background: var(--bg-card-hover);
        }

        /* Popular highlight */
        .pricing-card.is-popular {
            border-color: var(--border-active);
            box-shadow: var(--shadow-md), 0 0 0 1px var(--border-active), 0 0 40px rgba(216,143,255,0.08);
            transform: scale(1.03);
        }
        .pricing-card.is-popular::before {
            transform: scaleX(1);
            background: linear-gradient(90deg, var(--accent-rose), var(--accent), var(--primary-light), var(--accent), var(--accent-rose));
        }
        .pricing-card.is-popular:hover {
            transform: scale(1.03) translateY(-14px);
            box-shadow: var(--shadow-lg), 0 0 0 1px var(--border-active), var(--shadow-glow-strong);
        }

        /* Popular ribbon */
        .popular-ribbon {
            position: absolute; top: 20px; right: -8px;
            background: linear-gradient(135deg, var(--accent-rose), var(--accent));
            color: white; font-size: 0.65rem; font-weight: 700;
            padding: 6px 14px 6px 12px;
            letter-spacing: 0.08em; text-transform: uppercase;
            z-index: 10;
            border-radius: 4px 0 0 4px;
            box-shadow: 0 4px 16px rgba(255,142,196,0.4);
        }
        .popular-ribbon::after {
            content: ''; position: absolute;
            right: 0; bottom: -8px;
            border: 4px solid transparent;
            border-top-color: #c9408a;
            border-right-color: #c9408a;
        }

        /* Card image */
        .pricing-card-img {
            position: relative; overflow: hidden;
            height: 200px;
            background: linear-gradient(135deg, rgba(59,19,71,0.5), rgba(90,31,107,0.3));
        }
        .pricing-card-img img {
            width: 100%; height: 100%;
            object-fit: cover;
            transition: transform 0.7s cubic-bezier(0.4, 0, 0.2, 1);
            opacity: 0.85;
        }
        .pricing-card:hover .pricing-card-img img {
            transform: scale(1.1);
            opacity: 1;
        }
        /* Image overlay gradient */
        .pricing-card-img::after {
            content: ''; position: absolute;
            bottom: 0; left: 0; right: 0; height: 60%;
            background: linear-gradient(to top, var(--bg-card), transparent);
            pointer-events: none;
            z-index: 1;
        }

        /* Badge on image */
        .plan-badge-tag {
            position: absolute; top: 14px; left: 14px;
            background: rgba(59, 19, 71, 0.8);
            backdrop-filter: blur(10px);
            color: var(--accent);
            font-size: 0.68rem; font-weight: 700;
            padding: 5px 14px; border-radius: 50px;
            letter-spacing: 0.04em; text-transform: uppercase;
            box-shadow: 0 2px 12px rgba(0,0,0,0.3);
            z-index: 5;
            border: 1px solid var(--border-glow);
        }
        .is-popular .plan-badge-tag {
            background: linear-gradient(135deg, var(--primary-mid), var(--accent));
            color: white;
            border-color: transparent;
        }

        /* Card body */
        .pricing-card-body {
            padding: 24px 24px 24px;
            flex: 1; display: flex; flex-direction: column;
            position: relative; z-index: 2;
        }
        .pricing-card-body h3 {
            font-weight: 700; color: var(--text-bright);
            font-size: 1.2rem; margin-bottom: 6px;
            letter-spacing: -0.02em;
        }
        .pricing-card-body .plan-desc {
            font-size: 0.83rem; color: var(--text-muted);
            line-height: 1.6; margin-bottom: 18px;
        }

        /* Price */
        .price-block {
            text-align: center; padding: 18px 0;
            border-top: 1px solid var(--border-subtle);
            border-bottom: 1px solid var(--border-subtle);
            margin-bottom: 18px;
            position: relative;
        }
        .price-block::before {
            content: ''; position: absolute;
            top: 0; left: 20%; right: 20%; height: 1px;
            background: linear-gradient(90deg, transparent, var(--border-glow), transparent);
        }
        .price-block::after {
            content: ''; position: absolute;
            bottom: 0; left: 20%; right: 20%; height: 1px;
            background: linear-gradient(90deg, transparent, var(--border-glow), transparent);
        }
        .price-currency {
            font-size: 1.1rem; font-weight: 700;
            color: var(--text-muted); vertical-align: top;
            line-height: 2;
        }
        .price-amount {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 3rem; font-weight: 700;
            color: var(--text-bright); letter-spacing: -0.04em;
            line-height: 1;
        }
        .is-popular .price-amount {
            background: linear-gradient(135deg, var(--primary-light), var(--accent-gold));
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .price-duration {
            font-size: 0.82rem; color: var(--text-muted);
            font-weight: 500; margin-top: 2px;
        }

        /* Features list */
        .plan-features {
            list-style: none; padding: 0; margin: 0 0 24px;
            flex: 1;
        }
        .plan-features li {
            display: flex; align-items: center; gap: 12px;
            padding: 9px 0; font-size: 0.85rem;
            color: var(--text-body); font-weight: 500;
            border-bottom: 1px solid var(--border-subtle);
            transition: all 0.3s ease;
        }
        .plan-features li:last-child { border-bottom: none; }
        .plan-features li:hover { padding-left: 8px; color: var(--primary-light); }
        .plan-features li i {
            color: var(--accent); font-size: 0.65rem;
            width: 22px; height: 22px; min-width: 22px;
            background: rgba(244, 209, 255, 0.06);
            border: 1px solid var(--border-subtle);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            transition: all 0.3s ease;
        }
        .pricing-card:hover .plan-features li i {
            background: linear-gradient(135deg, var(--primary-mid), var(--accent));
            color: white; border-color: transparent;
            box-shadow: 0 0 10px rgba(216,143,255,0.3);
        }

        /* Card buttons */
        .pricing-card-actions {
            display: flex; flex-direction: column; gap: 10px;
        }
        .btn-subscribe {
            background: linear-gradient(135deg, var(--primary-mid), var(--accent));
            color: white; border: none;
            padding: 14px 24px; border-radius: 14px;
            font-weight: 700; font-size: 0.92rem;
            text-align: center; text-decoration: none;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 20px rgba(123,63,145,0.4);
            display: block; position: relative; overflow: hidden;
        }
        .btn-subscribe::before {
            content: ''; position: absolute;
            top: 0; left: -100%; width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(244,209,255,0.25), transparent);
            transition: left 0.5s;
        }
        .btn-subscribe:hover::before { left: 100%; }
        .btn-subscribe:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 32px rgba(123,63,145,0.6);
            color: white;
        }
        .is-popular .btn-subscribe {
            background: linear-gradient(135deg, var(--accent-rose), var(--accent));
            box-shadow: 0 4px 20px rgba(255,142,196,0.4);
        }
        .is-popular .btn-subscribe:hover {
            box-shadow: 0 8px 32px rgba(255,142,196,0.6);
        }

        .btn-view-details {
            border: 1.5px solid var(--border-subtle);
            color: var(--text-body); background: rgba(244,209,255,0.02);
            padding: 12px 24px; border-radius: 14px;
            font-weight: 600; font-size: 0.85rem;
            text-align: center; text-decoration: none;
            transition: all 0.3s ease; display: block;
        }
        .btn-view-details:hover {
            border-color: var(--border-active); color: var(--primary-light);
            background: rgba(244,209,255,0.06); text-decoration: none;
        }

        /* No plans state */
        .no-plans { text-align: center; padding: 80px 20px; }
        .no-plans-icon {
            width: 100px; height: 100px;
            background: rgba(244, 209, 255, 0.06);
            border: 1px solid var(--border-subtle);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 24px; font-size: 2.5rem; color: var(--accent);
        }

        .plan-hidden { display: none !important; }

        /* ============================================================
           COMPARISON TABLE - Unique addition
           ============================================================ */
        .comparison-section {
            position: relative;
        }
        .comparison-section::before {
            content: ''; position: absolute;
            top: 0; left: 10%; right: 10%; height: 1px;
            background: linear-gradient(90deg, transparent, var(--border-glow), transparent);
        }
        .comparison-toggle {
            text-align: center; margin-bottom: 32px;
        }
        .comparison-toggle button {
            background: var(--bg-card);
            border: 1px solid var(--border-glow);
            color: var(--primary-light);
            padding: 12px 32px;
            border-radius: 60px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.4s ease;
            font-family: 'Poppins', sans-serif;
            backdrop-filter: blur(10px);
        }
        .comparison-toggle button:hover {
            background: var(--primary-mid);
            border-color: var(--primary-mid);
            color: white;
            box-shadow: 0 4px 20px rgba(123,63,145,0.4);
        }
        .comparison-toggle button i { margin-right: 8px; }

        .comparison-table-wrap {
            overflow-x: auto;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-subtle);
            background: var(--bg-card);
            backdrop-filter: blur(16px);
            max-height: 0;
            opacity: 0;
            overflow: hidden;
            transition: all 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .comparison-table-wrap.open {
            max-height: 2000px;
            opacity: 1;
            overflow-x: auto;
            padding: 0;
        }
        .comparison-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }
        .comparison-table thead th {
            background: rgba(244, 209, 255, 0.04);
            color: var(--text-bright);
            font-weight: 700;
            font-size: 0.85rem;
            padding: 18px 20px;
            text-align: center;
            border-bottom: 1px solid var(--border-subtle);
            letter-spacing: -0.01em;
        }
        .comparison-table thead th:first-child {
            text-align: left;
            border-right: 1px solid var(--border-subtle);
        }
        .comparison-table tbody td {
            padding: 14px 20px;
            text-align: center;
            font-size: 0.85rem;
            color: var(--text-body);
            border-bottom: 1px solid var(--border-subtle);
            transition: all 0.3s ease;
        }
        .comparison-table tbody td:first-child {
            text-align: left;
            font-weight: 600;
            color: var(--text-light);
            border-right: 1px solid var(--border-subtle);
        }
        .comparison-table tbody tr:hover td {
            background: rgba(244, 209, 255, 0.03);
            color: var(--primary-light);
        }
        .comparison-table tbody tr:last-child td { border-bottom: none; }
        .comparison-table .check-yes { color: var(--accent); font-size: 1rem; }
        .comparison-table .check-no { color: var(--text-muted); opacity: 0.3; font-size: 0.9rem; }
        .comparison-table .highlight-col {
            background: rgba(216, 143, 255, 0.04);
        }
        .comparison-table thead .highlight-col {
            background: rgba(123, 63, 145, 0.2);
            color: var(--accent);
        }

        /* ============================================================
           FAQ ACCORDION - Unique addition
           ============================================================ */
        .faq-section {
            position: relative;
        }
        .faq-section::before {
            content: ''; position: absolute;
            top: 0; left: 10%; right: 10%; height: 1px;
            background: linear-gradient(90deg, transparent, var(--border-glow), transparent);
        }
        .faq-item {
            background: var(--bg-card);
            backdrop-filter: blur(16px);
            border: 1px solid var(--border-subtle);
            border-radius: var(--radius-md);
            margin-bottom: 12px;
            overflow: hidden;
            transition: all 0.4s ease;
        }
        .faq-item:hover {
            border-color: var(--border-glow);
        }
        .faq-item.active {
            border-color: var(--border-active);
            box-shadow: 0 0 20px rgba(244,209,255,0.04);
        }
        .faq-question {
            padding: 20px 24px;
            display: flex; align-items: center; justify-content: space-between;
            cursor: pointer;
            font-weight: 600; font-size: 0.92rem;
            color: var(--text-bright);
            transition: all 0.3s ease;
            user-select: none;
        }
        .faq-question:hover { color: var(--primary-light); }
        .faq-question i {
            color: var(--accent); font-size: 0.8rem;
            transition: transform 0.4s ease;
            min-width: 28px; height: 28px;
            display: flex; align-items: center; justify-content: center;
            background: rgba(244, 209, 255, 0.06);
            border-radius: 50%;
        }
        .faq-item.active .faq-question i { transform: rotate(180deg); }
        .faq-answer {
            max-height: 0; overflow: hidden;
            transition: max-height 0.5s cubic-bezier(0.4, 0, 0.2, 1), padding 0.4s ease;
            padding: 0 24px;
        }
        .faq-item.active .faq-answer {
            max-height: 300px;
            padding: 0 24px 20px;
        }
        .faq-answer p {
            font-size: 0.87rem; color: var(--text-muted);
            line-height: 1.7; margin: 0;
        }

        /* ============================================================
           CTA SECTION
           ============================================================ */
        .cta-section {
            position: relative; overflow: hidden;
            background: transparent;
        }
        .cta-section::before {
            content: ''; position: absolute;
            top: 0; left: 10%; right: 10%; height: 1px;
            background: linear-gradient(90deg, transparent, var(--border-glow), transparent);
        }
        .cta-box {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-soft) 25%, var(--primary-mid) 50%, var(--accent) 100%);
            background-size: 300% 300%;
            animation: ctaGradient 10s ease infinite;
            border-radius: var(--radius-xl);
            padding: 70px 50px;
            position: relative; overflow: hidden;
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.5), 0 0 60px rgba(216,143,255,0.1);
            border: 1px solid rgba(244,209,255,0.1);
        }
        @keyframes ctaGradient {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }
        .cta-box::before {
            content: ''; position: absolute;
            top: -40%; right: -20%;
            width: 500px; height: 500px;
            background: radial-gradient(circle, rgba(244,209,255,0.08), transparent 60%);
            border-radius: 50%; animation: heroFloat 10s ease-in-out infinite;
        }
        .cta-box::after {
            content: ''; position: absolute;
            bottom: -30%; left: -10%;
            width: 350px; height: 350px;
            background: radial-gradient(circle, rgba(255,142,196,0.06), transparent 60%);
            border-radius: 50%; animation: heroFloat 12s ease-in-out infinite reverse;
        }
        .cta-box h2 {
            font-weight: 900; color: white;
            letter-spacing: -0.03em;
            font-size: clamp(1.6rem, 3.5vw, 2.4rem);
            margin-bottom: 16px; position: relative; z-index: 1;
            text-shadow: 0 2px 20px rgba(0,0,0,0.2);
        }
        .cta-box p {
            color: rgba(244, 209, 255, 0.7); font-size: 1rem;
            line-height: 1.7; max-width: 520px;
            position: relative; z-index: 1;
        }
        .cta-box .btn-purple {
            position: relative; z-index: 1;
            background: white; color: var(--primary);
            box-shadow: 0 4px 24px rgba(0,0,0,0.2);
        }
        .cta-box .btn-purple::before { display: none; }
        .cta-box .btn-purple:hover {
            background: var(--primary-light);
            box-shadow: 0 8px 30px rgba(0,0,0,0.3);
            color: var(--primary);
        }

        /* CTA floating particles */
        .cta-particle {
            position: absolute; border-radius: 50%;
            background: rgba(244, 209, 255, 0.06); pointer-events: none;
        }
        .cta-particle:nth-child(1) { width: 80px; height: 80px; top: 10%; left: 5%; animation: particleFloat 6s ease-in-out infinite; }
        .cta-particle:nth-child(2) { width: 50px; height: 50px; top: 60%; right: 8%; animation: particleFloat 8s ease-in-out infinite 1s; }
        .cta-particle:nth-child(3) { width: 30px; height: 30px; bottom: 15%; left: 20%; animation: particleFloat 7s ease-in-out infinite 2s; }
        @keyframes particleFloat {
            0%, 100% { transform: translateY(0) scale(1); opacity: 0.06; }
            50% { transform: translateY(-20px) scale(1.2); opacity: 0.15; }
        }

        /* ============================================================
           FOOTER
           ============================================================ */
        footer {
            background: var(--bg-dark);
            border-top: 1px solid var(--border-subtle);
            color: var(--text-muted);
            padding: 28px 0;
        }
        footer a {
            color: var(--text-muted);
            text-decoration: none; transition: all 0.3s ease; font-size: 0.85rem;
        }
        footer a:hover { color: var(--primary-light); }
        footer h5 {
            font-weight: 700; color: var(--text-bright); font-size: 1rem; margin-bottom: 8px;
        }
        footer h6 {
            font-weight: 600; color: rgba(244, 209, 255, 0.7);
            font-size: 0.85rem; margin-bottom: 12px;
        }
        footer p.small { font-size: 0.82rem; line-height: 1.7; margin-bottom: 0; }
        footer ul li { margin-bottom: 6px; }
        footer .border-top {
            border-color: var(--border-subtle) !important;
            margin-top: 20px; padding-top: 18px; font-size: 0.78rem;
        }

        /* --- Scrollbar --- */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: var(--bg-deep); }
        ::-webkit-scrollbar-thumb { background: var(--primary-mid); border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--accent); }

        /* --- Responsive --- */
        @media (max-width: 991px) {
            .pricing-card.is-popular { transform: scale(1); }
            .pricing-card.is-popular:hover { transform: translateY(-14px); }
            .hero-visual-wrap { width: 280px; height: 320px; }
            .hero-card-stack { width: 240px; height: 170px; }
        }
        @media (max-width: 767px) {
            .plan-hero { padding: 130px 0 70px; }
            .plan-floating-icon { display: none; }
            .hero-shape { display: none; }
            .hero-visual-wrap { display: none; }
            .plan-stat-value { font-size: 1.5rem; }
            .price-amount { font-size: 2.4rem; }
            .pricing-card-img { height: 160px; }
            .cta-box { padding: 50px 28px; text-align: center; }
            .cta-box p { max-width: 100%; }
            footer .row > div { margin-bottom: 16px; }
            .cursor-glow { display: none; }
            .comparison-table-wrap.open { padding: 0; }
        }

        /* --- Reduced motion --- */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
    </style>
</head>
<body>

    <!-- Animated Starfield Background -->
    <canvas id="starfield"></canvas>

    <!-- Aurora Background Blobs -->
    <div class="aurora-wrap">
        <div class="aurora-blob"></div>
        <div class="aurora-blob"></div>
        <div class="aurora-blob"></div>
        <div class="aurora-blob"></div>
    </div>

    <!-- Cursor Glow -->
    <div class="cursor-glow" id="cursorGlow"></div>

    <!-- HEADER -->
    <?php include 'header.php'; ?>

    <section class="plan-hero">
        <i class="plan-floating-icon fa-solid fa-crown"></i>
        <i class="plan-floating-icon fa-solid fa-gem"></i>
        <i class="plan-floating-icon fa-solid fa-star"></i>
        <i class="plan-floating-icon fa-solid fa-tags"></i>
        <i class="plan-floating-icon fa-solid fa-bolt"></i>
        <i class="plan-floating-icon fa-solid fa-rocket"></i>

        <div class="hero-shape hero-shape-1"></div>
        <div class="hero-shape hero-shape-2"></div>
        <div class="hero-shape hero-shape-3"></div>

        <div class="container position-relative" style="z-index:2;">
            <div class="row align-items-center">
                <div class="col-lg-7" data-aos="fade-right">
                    <span class="hero-badge badge rounded-pill px-3 py-2 mb-4 d-inline-block">
                        <i class="fa-solid fa-crown me-1"></i> Pricing Plans
                    </span>
                    <h1 class="plan-hero-title mb-4">Choose Your<br><span>Perfect Plan</span></h1>
                    <p class="lead mb-4">
                        Select the best package for AI Assignment Checking and unlock premium features tailored to your academic needs.
                    </p>
                    <div class="d-flex gap-3 flex-wrap">
                        <a href="#plans-grid" class="btn btn-purple">
                            <i class="fa-solid fa-arrow-down me-2"></i>View Plans
                        </a>
                        <a href="#faq-section" class="btn btn-outline-purple">
                            <i class="fa-solid fa-circle-question me-2"></i>FAQ
                        </a>
                    </div>
                </div>
                <div class="col-lg-5 text-center mt-5 mt-lg-0" data-aos="fade-left" data-aos-delay="200">
                    <div class="hero-visual-wrap">
                        <!-- 3D Card Stack -->
                        <div class="hero-card-stack">
                            <div class="hero-card-inner">
                                <span class="card-mini-badge">Popular</span>
                                <div class="card-mini-price">RM 29.90 <small>/mo</small></div>
                                <div class="card-mini-line"></div>
                                <ul class="card-mini-features">
                                    <li><i class="fa-solid fa-check"></i> 50 Checks / Month</li>
                                    <li><i class="fa-solid fa-check"></i> AI + Plagiarism Scan</li>
                                    <li><i class="fa-solid fa-check"></i> Detailed Reports</li>
                                </ul>
                            </div>
                        </div>
                        <div class="hero-card-stack">
                            <div class="hero-card-inner">
                                <span class="card-mini-badge">Premium</span>
                                <div class="card-mini-price">RM 59.90 <small>/mo</small></div>
                                <div class="card-mini-line"></div>
                                <ul class="card-mini-features">
                                    <li><i class="fa-solid fa-check"></i> Unlimited Checks</li>
                                    <li><i class="fa-solid fa-check"></i> Priority Processing</li>
                                    <li><i class="fa-solid fa-check"></i> API Access</li>
                                </ul>
                            </div>
                        </div>
                        <div class="hero-card-stack">
                            <div class="hero-card-inner">
                                <span class="card-mini-badge">Starter</span>
                                <div class="card-mini-price">RM 9.90 <small>/mo</small></div>
                                <div class="card-mini-line"></div>
                                <ul class="card-mini-features">
                                    <li><i class="fa-solid fa-check"></i> 10 Checks / Month</li>
                                    <li><i class="fa-solid fa-check"></i> Basic Reports</li>
                                    <li><i class="fa-solid fa-check"></i> Email Support</li>
                                </ul>
                            </div>
                        </div>
                        <!-- Glow orbs -->
                        <div class="hero-glow-orb"></div>
                        <div class="hero-glow-orb"></div>
                        <div class="hero-glow-orb"></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================
         PLAN STATISTICS
         ============================================================ -->
    <section class="plan-stats-section py-5">
        <div class="container">
            <div class="row g-4 justify-content-center">
                <div class="col-6 col-lg-3" data-aos="fade-up" data-aos-delay="0">
                    <div class="plan-stat-card">
                        <div class="plan-stat-icon"><i class="fa-solid fa-layer-group"></i></div>
                        <div class="plan-stat-value"><?php echo $planStats['total_active']; ?></div>
                        <div class="plan-stat-label">Active Plans</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3" data-aos="fade-up" data-aos-delay="100">
                    <div class="plan-stat-card">
                        <div class="plan-stat-icon"><i class="fa-solid fa-tag"></i></div>
                        <div class="plan-stat-value">RM <?php echo number_format($planStats['cheapest'], 2); ?></div>
                        <div class="plan-stat-label">Starting From</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3" data-aos="fade-up" data-aos-delay="200">
                    <div class="plan-stat-card">
                        <div class="plan-stat-icon"><i class="fa-solid fa-gem"></i></div>
                        <div class="plan-stat-value"><?php echo $planStats['premium']; ?></div>
                        <div class="plan-stat-label">Premium Plans</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3" data-aos="fade-up" data-aos-delay="300">
                    <div class="plan-stat-card">
                        <div class="plan-stat-icon"><i class="fa-solid fa-fire"></i></div>
                        <div class="plan-stat-value"><?php echo $planStats['popular']; ?></div>
                        <div class="plan-stat-label">Popular Plans</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================
         SEARCH + FILTER TABS + PLAN CARDS
         ============================================================ -->
    <section id="plans-grid" class="py-5" style="background:transparent;">
        <div class="container">
            <div class="text-center mb-4" data-aos="fade-up">
                <p class="section-label mb-2">Pricing</p>
                <h2 class="section-title mb-3">Available Plans</h2>
                <p class="section-subtitle mx-auto">Find the perfect plan that matches your academic needs and budget.</p>
            </div>

            <!-- Search -->
            <div class="mb-4" data-aos="fade-up" data-aos-delay="100">
                <div class="search-wrapper">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="planSearch" class="search-input" placeholder="Search plans by name, description, or badge...">
                </div>
            </div>

            <!-- Filter Tabs -->
            <div class="plan-filter-tabs" data-aos="fade-up" data-aos-delay="150" id="filterTabs">
                <div class="filter-tab active" data-filter="all">All Plans</div>
                <?php
                $badges = [];
                foreach ($plans as $p) {
                    if (!empty($p['badge']) && !in_array(ucfirst(strtolower($p['badge'])), $badges)) {
                        $badges[] = ucfirst(strtolower($p['badge']));
                    }
                }
                foreach ($badges as $b): ?>
                    <div class="filter-tab" data-filter="<?php echo strtolower($b); ?>"><?php echo $b; ?></div>
                <?php endforeach; ?>
            </div>

            <!-- Plan Cards Grid -->
            <?php if (!empty($plans)): ?>
                <div class="row g-4" id="plansContainer">
                    <?php foreach ($plans as $index => $plan):
                        $isPopular = (strtolower($plan['badge']) === 'popular');
                        $planImage = !empty($plan['plan_image']) ? 'uploads/plans/' . $plan['plan_image'] : 'assets/no-image.png';
                        $features = !empty($plan['features']) ? explode(',', $plan['features']) : [];
                        $buttonText = !empty($plan['button_text']) ? $plan['button_text'] : 'Subscribe';
                        $durationLabel = !empty($plan['duration']) ? $plan['duration'] : 'Per Month';
                        $badgeLower = strtolower($plan['badge']);
                    ?>
                        <div class="col-lg-4 col-md-6 col-12 plan-col"
                             data-name="<?php echo strtolower(htmlspecialchars($plan['plan_name'])); ?>"
                             data-desc="<?php echo strtolower(htmlspecialchars($plan['description'])); ?>"
                             data-badge="<?php echo $badgeLower; ?>"
                             data-aos="fade-up"
                             data-aos-delay="<?php echo $index * 100; ?>">

                            <div class="pricing-card <?php echo $isPopular ? 'is-popular' : ''; ?>">

                                <?php if ($isPopular): ?>
                                    <div class="popular-ribbon">Most Popular</div>
                                <?php endif; ?>

                                <div class="pricing-card-img">
                                    <?php if (!empty($plan['badge'])): ?>
                                        <span class="plan-badge-tag"><?php echo htmlspecialchars($plan['badge']); ?></span>
                                    <?php endif; ?>
                                    <img src="<?php echo $planImage; ?>"
                                         alt="<?php echo htmlspecialchars($plan['plan_name']); ?>"
                                         onerror="this.src='assets/no-image.png'">
                                </div>

                                <div class="pricing-card-body">
                                    <h3><?php echo htmlspecialchars($plan['plan_name']); ?></h3>
                                    <p class="plan-desc"><?php echo htmlspecialchars($plan['description']); ?></p>

                                    <div class="price-block">
                                        <span class="price-currency">RM </span>
                                        <span class="price-amount"><?php echo number_format($plan['price'], 2); ?></span>
                                        <div class="price-duration"><?php echo htmlspecialchars($durationLabel); ?></div>
                                    </div>

                                    <?php $assignDiscount = floatval($plan['assignment_discount'] ?? 0); ?>
                                    <?php if ($assignDiscount > 0): ?>
                                        <div class="plan-discount-perk" style="display:flex;align-items:center;gap:8px;padding:8px 14px;margin-bottom:14px;background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.25);border-radius:10px;font-size:0.82rem;font-weight:700;color:#22c55e;">
                                            <i class="fa-solid fa-tag"></i>
                                            <?php echo (int)$assignDiscount; ?>% off every assignment check
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($features)): ?>
                                        <ul class="plan-features">
                                            <?php foreach ($features as $feature): ?>
                                                <li>
                                                    <i class="fa-solid fa-check"></i>
                                                    <?php echo htmlspecialchars(trim($feature)); ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>

                                    <div class="pricing-card-actions">
                                        <a href="payments_plan.php?plan_id=<?php echo $plan['plan_id']; ?>" class="btn-subscribe">
                                            <i class="fa-solid fa-arrow-right me-2"></i><?php echo htmlspecialchars($buttonText); ?>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div id="noResults" class="plan-hidden" style="text-align:center; padding:60px 20px;">
                    <div class="no-plans-icon" style="margin:0 auto 20px;">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </div>
                    <h4 style="font-weight:700; color:var(--text-bright); margin-bottom:8px;">No Plans Found</h4>
                    <p style="color:var(--text-muted); font-size:0.9rem;">Try searching with a different keyword or filter.</p>
                </div>

            <?php else: ?>
                <div class="no-plans" data-aos="fade-up">
                    <div class="no-plans-icon">
                        <i class="fa-solid fa-box-open"></i>
                    </div>
                    <h4 style="font-weight:700; color:var(--text-bright); margin-bottom:8px;">No Plans Available</h4>
                    <p style="color:var(--text-muted); font-size:0.95rem; max-width:400px; margin:0 auto 24px;">We're currently updating our plans. Please check back soon for exciting new packages.</p>
                    <a href="index.php" class="btn btn-purple">
                        <i class="fa-solid fa-home me-2"></i>Back to Home
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- ============================================================
         COMPARISON TABLE - Unique Section
         ============================================================ -->
    <?php if (!empty($plans) && count($plans) >= 2): ?>
    <section class="comparison-section py-5">
        <div class="container">
            <div class="text-center mb-4" data-aos="fade-up">
                <p class="section-label mb-2">Compare</p>
                <h2 class="section-title mb-3">Side-by-Side Comparison</h2>
                <p class="section-subtitle mx-auto">See all plans at a glance to make the best decision.</p>
            </div>

            <div class="comparison-toggle" data-aos="fade-up" data-aos-delay="100">
                <button id="toggleComparison">
                    <i class="fa-solid fa-table-columns"></i>
                    <span id="toggleText">Show Comparison Table</span>
                </button>
            </div>

            <div class="comparison-table-wrap" id="comparisonWrap">
                <table class="comparison-table">
                    <thead>
                        <tr>
                            <th>Features</th>
                            <?php foreach ($plans as $plan):
                                $isPop = (strtolower($plan['badge']) === 'popular');
                            ?>
                                <th class="<?php echo $isPop ? 'highlight-col' : ''; ?>">
                                    <?php echo htmlspecialchars($plan['plan_name']); ?>
                                    <div style="font-size:0.75rem; color:var(--text-muted); font-weight:400; margin-top:4px;">
                                        RM <?php echo number_format($plan['price'], 2); ?>
                                    </div>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Build feature union from all plans
                        $allFeatures = [];
                        foreach ($plans as $plan) {
                            if (!empty($plan['features'])) {
                                $feats = array_map('trim', explode(',', $plan['features']));
                                foreach ($feats as $f) {
                                    if (!empty($f) && !in_array($f, $allFeatures)) {
                                        $allFeatures[] = $f;
                                    }
                                }
                            }
                        }

                        foreach ($allFeatures as $feat):
                            // Clean feature for display
                            $featDisplay = htmlspecialchars($feat);
                            $featClean = strtolower(trim($feat));
                        ?>
                            <tr>
                                <td><?php echo $featDisplay; ?></td>
                                <?php foreach ($plans as $plan):
                                    $isPop = (strtolower($plan['badge']) === 'popular');
                                    $planFeats = !empty($plan['features']) ? array_map('trim', explode(',', $plan['features'])) : [];
                                    $hasFeature = in_array($feat, $planFeats);
                                ?>
                                    <td class="<?php echo $isPop ? 'highlight-col' : ''; ?>">
                                        <?php if ($hasFeature): ?>
                                            <i class="fa-solid fa-circle-check check-yes"></i>
                                        <?php else: ?>
                                            <i class="fa-solid fa-circle-xmark check-no"></i>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td><strong style="color:var(--text-bright);">Price</strong></td>
                            <?php foreach ($plans as $plan):
                                $isPop = (strtolower($plan['badge']) === 'popular');
                            ?>
                                <td class="<?php echo $isPop ? 'highlight-col' : ''; ?>" style="font-weight:700; color:var(--text-bright);">
                                    RM <?php echo number_format($plan['price'], 2); ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <!-- ============================================================
         FAQ SECTION - Unique Addition
         ============================================================ -->
    <section class="faq-section py-5" id="faq-section">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="text-center mb-4" data-aos="fade-up">
                        <p class="section-label mb-2">Support</p>
                        <h2 class="section-title mb-3">Frequently Asked Questions</h2>
                        <p class="section-subtitle mx-auto">Got questions? We've got answers about our plans and services.</p>
                    </div>

                    <div data-aos="fade-up" data-aos-delay="100">
                        <div class="faq-item">
                            <div class="faq-question" onclick="toggleFaq(this)">
                                Can I upgrade or downgrade my plan anytime?
                                <i class="fa-solid fa-chevron-down"></i>
                            </div>
                            <div class="faq-answer">
                                <p>Yes, you can upgrade or downgrade your plan at any time from your profile page. The price difference will be prorated automatically based on your remaining subscription period.</p>
                            </div>
                        </div>
                        <div class="faq-item">
                            <div class="faq-question" onclick="toggleFaq(this)">
                                What payment methods do you accept?
                                <i class="fa-solid fa-chevron-down"></i>
                            </div>
                            <div class="faq-answer">
                                <p>We accept various payment methods including online banking, credit/debit cards, and e-wallets. All transactions are secured with SSL encryption for your safety.</p>
                            </div>
                        </div>
                        <div class="faq-item">
                            <div class="faq-question" onclick="toggleFaq(this)">
                                Is there a free trial available?
                                <i class="fa-solid fa-chevron-down"></i>
                            </div>
                            <div class="faq-answer">
                                <p>We offer a limited free tier that allows you to try our basic features. For full access to all tools and unlimited checks, you'll need to subscribe to one of our paid plans.</p>
                            </div>
                        </div>
                        <div class="faq-item">
                            <div class="faq-question" onclick="toggleFaq(this)">
                                How accurate is the AI detection?
                                <i class="fa-solid fa-chevron-down"></i>
                            </div>
                            <div class="faq-answer">
                                <p>Our AI detection system uses advanced machine learning models trained on millions of documents, achieving over 95% accuracy. Results include detailed confidence scores and highlighted sections.</p>
                            </div>
                        </div>
                        <div class="faq-item">
                            <div class="faq-question" onclick="toggleFaq(this)">
                                Can I get a refund if I'm not satisfied?
                                <i class="fa-solid fa-chevron-down"></i>
                            </div>
                            <div class="faq-answer">
                                <p>We offer a 7-day money-back guarantee on all plans. If you're not satisfied with our service, contact our support team within 7 days of purchase for a full refund.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ============================================================
         CTA SECTION
         ============================================================ -->
    <section class="cta-section py-5">
        <div class="container">
            <div class="cta-box text-center" data-aos="zoom-in">
                <div class="cta-particle"></div>
                <div class="cta-particle"></div>
                <div class="cta-particle"></div>
                <h2>Need a Custom Plan?</h2>
                <p class="mx-auto mb-4">Contact us for tailored solutions that fit your institution's specific requirements and budget.</p>
                <a href="contacts.php" class="btn btn-purple">
                    <i class="fa-solid fa-envelope me-2"></i>Contact Us
                </a>
            </div>
        </div>
    </section>


    <!-- Scripts -->
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>

    <script>
        // Initialize AOS
        AOS.init({
            duration: 800,
            once: true,
            offset: 80
        });

        // Navbar scroll effect
        window.addEventListener('scroll', function() {
            const navbar = document.querySelector('.navbar');
            if (navbar) {
                if (window.scrollY > 50) {
                    navbar.classList.add('scrolled');
                } else {
                    navbar.classList.remove('scrolled');
                }
            }
        });

        // Smooth scroll for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });

        // Cursor glow follow
        const cursorGlow = document.getElementById('cursorGlow');
        if (cursorGlow) {
            document.addEventListener('mousemove', function(e) {
                cursorGlow.style.left = e.clientX + 'px';
                cursorGlow.style.top = e.clientY + 'px';
            });
        }

        // Card mouse tracking for radial glow
        document.querySelectorAll('.pricing-card').forEach(card => {
            card.addEventListener('mousemove', function(e) {
                const rect = card.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;
                card.style.setProperty('--mouse-x', x + 'px');
                card.style.setProperty('--mouse-y', y + 'px');
            });
        });

        // Live search for plans
        const searchInput = document.getElementById('planSearch');
        const planCols = document.querySelectorAll('.plan-col');
        const noResults = document.getElementById('noResults');
        let activeFilter = 'all';

        function filterPlans() {
            const query = searchInput ? searchInput.value.toLowerCase().trim() : '';
            let visibleCount = 0;

            planCols.forEach(col => {
                const name = col.getAttribute('data-name') || '';
                const desc = col.getAttribute('data-desc') || '';
                const badge = col.getAttribute('data-badge') || '';
                const searchText = name + ' ' + desc + ' ' + badge;
                const matchesSearch = query === '' || searchText.includes(query);
                const matchesFilter = activeFilter === 'all' || badge === activeFilter;

                if (matchesSearch && matchesFilter) {
                    col.classList.remove('plan-hidden');
                    visibleCount++;
                } else {
                    col.classList.add('plan-hidden');
                }
            });

            if (noResults) {
                if (visibleCount === 0 && (query !== '' || activeFilter !== 'all')) {
                    noResults.classList.remove('plan-hidden');
                } else {
                    noResults.classList.add('plan-hidden');
                }
            }
        }

        if (searchInput) {
            searchInput.addEventListener('input', filterPlans);
        }

        // Filter tabs
        document.querySelectorAll('.filter-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                activeFilter = this.getAttribute('data-filter');
                filterPlans();
            });
        });

        // Comparison table toggle
        const toggleBtn = document.getElementById('toggleComparison');
        const compWrap = document.getElementById('comparisonWrap');
        const toggleText = document.getElementById('toggleText');

        if (toggleBtn && compWrap) {
            toggleBtn.addEventListener('click', function() {
                compWrap.classList.toggle('open');
                if (compWrap.classList.contains('open')) {
                    toggleText.textContent = 'Hide Comparison Table';
                } else {
                    toggleText.textContent = 'Show Comparison Table';
                }
            });
        }

        // FAQ accordion
        function toggleFaq(element) {
            const item = element.parentElement;
            const wasActive = item.classList.contains('active');

            // Close all
            document.querySelectorAll('.faq-item').forEach(faq => {
                faq.classList.remove('active');
            });

            // Toggle clicked
            if (!wasActive) {
                item.classList.add('active');
            }
        }

        // ============================================================
        // STARFIELD CANVAS
        // ============================================================
        (function() {
            const canvas = document.getElementById('starfield');
            if (!canvas) return;
            const ctx = canvas.getContext('2d');
            let stars = [];
            const STAR_COUNT = 120;

            function resize() {
                canvas.width = window.innerWidth;
                canvas.height = window.innerHeight;
            }

            function createStars() {
                stars = [];
                for (let i = 0; i < STAR_COUNT; i++) {
                    stars.push({
                        x: Math.random() * canvas.width,
                        y: Math.random() * canvas.height,
                        size: Math.random() * 1.5 + 0.3,
                        speed: Math.random() * 0.3 + 0.05,
                        opacity: Math.random() * 0.6 + 0.1,
                        twinkleSpeed: Math.random() * 0.02 + 0.005,
                        twinkleOffset: Math.random() * Math.PI * 2
                    });
                }
            }

            function draw() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                const time = Date.now() * 0.001;

                stars.forEach(star => {
                    star.y -= star.speed;
                    if (star.y < -5) {
                        star.y = canvas.height + 5;
                        star.x = Math.random() * canvas.width;
                    }
                    const twinkle = Math.sin(time * star.twinkleSpeed * 10 + star.twinkleOffset) * 0.3 + 0.7;
                    const alpha = star.opacity * twinkle;

                    ctx.beginPath();
                    ctx.arc(star.x, star.y, star.size, 0, Math.PI * 2);
                    ctx.fillStyle = 'rgba(244, 209, 255, ' + alpha + ')';
                    ctx.fill();

                    // Subtle glow for larger stars
                    if (star.size > 1) {
                        ctx.beginPath();
                        ctx.arc(star.x, star.y, star.size * 3, 0, Math.PI * 2);
                        ctx.fillStyle = 'rgba(244, 209, 255, ' + (alpha * 0.1) + ')';
                        ctx.fill();
                    }
                });

                requestAnimationFrame(draw);
            }

            resize();
            createStars();
            draw();
            window.addEventListener('resize', function() {
                resize();
                createStars();
            });
        })();
    </script>
<?php include 'footer.php'; ?>
</body>
</html>