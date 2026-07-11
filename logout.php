<?php
session_start();

// Unset all session variables
session_unset();

// Delete the session cookie if it exists
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy the session completely
session_destroy();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logged Out | AI Assignment Checker</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-purple: #6A0DAD;
            --primary-dark: #550a8c;
            --primary-light: #9b59b6;
            --gradient-bg: linear-gradient(135deg, #4a0a7a 0%, #6A0DAD 30%, #8e44ad 60%, #a855c7 100%);
            --success-green: #10b981;
            --success-light: #ecfdf5;
            --success-border: #a7f3d0;
            --card-bg: rgba(255, 255, 255, 0.97);
            --text-dark: #1a1a2e;
            --text-muted: #6b7280;
            --text-light: #9ca3af;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--gradient-bg);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            overflow: hidden;
            position: relative;
        }

        /* === ANIMATED BACKGROUND === */
        .bg-layer {
            position: fixed;
            inset: 0;
            z-index: 0;
            overflow: hidden;
            pointer-events: none;
        }

        .bg-orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.15;
            animation: orbFloat 12s ease-in-out infinite;
        }

        .bg-orb:nth-child(1) {
            width: 500px;
            height: 500px;
            background: #c084fc;
            top: -15%;
            left: -10%;
            animation-delay: 0s;
        }

        .bg-orb:nth-child(2) {
            width: 400px;
            height: 400px;
            background: #f0abfc;
            bottom: -10%;
            right: -8%;
            animation-delay: -4s;
            animation-duration: 15s;
        }

        .bg-orb:nth-child(3) {
            width: 300px;
            height: 300px;
            background: #818cf8;
            top: 50%;
            left: 60%;
            animation-delay: -8s;
            animation-duration: 18s;
        }

        @keyframes orbFloat {
            0%, 100% { transform: translate(0, 0) scale(1); }
            25% { transform: translate(40px, -50px) scale(1.1); }
            50% { transform: translate(-30px, 30px) scale(0.9); }
            75% { transform: translate(20px, 40px) scale(1.05); }
        }

        /* Floating particles canvas */
        #particleCanvas {
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
        }

        /* Grid pattern overlay */
        .bg-grid {
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
            background-image:
                linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px);
            background-size: 60px 60px;
        }

        /* === CARD === */
        .logout-card {
            background: var(--card-bg);
            border-radius: 24px;
            box-shadow:
                0 25px 60px rgba(0, 0, 0, 0.15),
                0 8px 20px rgba(106, 13, 173, 0.08),
                inset 0 1px 0 rgba(255, 255, 255, 0.8);
            overflow: hidden;
            width: 100%;
            max-width: 480px;
            position: relative;
            z-index: 1;
            animation: cardReveal 0.8s cubic-bezier(0.16, 1, 0.3, 1);
            border: 1px solid rgba(255, 255, 255, 0.6);
        }

        @keyframes cardReveal {
            from {
                opacity: 0;
                transform: translateY(40px) scale(0.94);
                filter: blur(4px);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
                filter: blur(0);
            }
        }

        /* Top gradient accent */
        .card-accent {
            height: 6px;
            background: linear-gradient(90deg,
                var(--primary-purple),
                var(--primary-light),
                #c084fc,
                var(--primary-light),
                var(--primary-purple));
            background-size: 300% 100%;
            animation: accentFlow 4s ease-in-out infinite;
        }

        @keyframes accentFlow {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }

        .card-body-inner {
            padding: 44px 42px 36px;
        }

        /* === SECURITY BADGE === */
        .security-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--success-light);
            border: 1px solid var(--success-border);
            color: #065f46;
            padding: 7px 16px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            animation: fadeSlideDown 0.5s ease-out 0.3s both;
        }

        .security-badge i {
            font-size: 0.7rem;
            color: var(--success-green);
        }

        @keyframes fadeSlideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* === SUCCESS ICON === */
        .icon-wrapper {
            margin: 30px auto 26px;
            width: 110px;
            height: 110px;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .icon-ring-outer {
            position: absolute;
            inset: 0;
            border-radius: 50%;
            border: 2px dashed rgba(16, 185, 129, 0.2);
            animation: spinSlow 20s linear infinite;
        }

        @keyframes spinSlow {
            to { transform: rotate(360deg); }
        }

        .icon-ring-pulse {
            position: absolute;
            inset: -8px;
            border-radius: 50%;
            border: 2px solid rgba(16, 185, 129, 0.25);
            animation: rippleOut 2.5s ease-out 0.8s infinite;
        }

        .icon-ring-pulse:nth-child(2) {
            animation-delay: 1.6s;
        }

        @keyframes rippleOut {
            0% { transform: scale(0.85); opacity: 0.8; }
            100% { transform: scale(1.3); opacity: 0; }
        }

        .icon-circle {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: linear-gradient(145deg, #10b981, #34d399);
            display: flex;
            justify-content: center;
            align-items: center;
            box-shadow:
                0 10px 30px rgba(16, 185, 129, 0.35),
                inset 0 -3px 8px rgba(0, 0, 0, 0.1),
                inset 0 3px 8px rgba(255, 255, 255, 0.2);
            animation: iconBounce 0.7s cubic-bezier(0.175, 0.885, 0.32, 1.275) 0.4s both;
            position: relative;
            z-index: 1;
        }

        .icon-circle i {
            font-size: 44px;
            color: #fff;
            filter: drop-shadow(0 2px 4px rgba(0,0,0,0.15));
            animation: checkPop 0.4s ease-out 0.9s both;
        }

        @keyframes iconBounce {
            0% { transform: scale(0) rotate(-20deg); opacity: 0; }
            50% { transform: scale(1.2) rotate(5deg); }
            70% { transform: scale(0.95) rotate(-2deg); }
            100% { transform: scale(1) rotate(0deg); opacity: 1; }
        }

        @keyframes checkPop {
            0% { opacity: 0; transform: scale(0.3) rotate(-15deg); }
            60% { transform: scale(1.1) rotate(3deg); }
            100% { opacity: 1; transform: scale(1) rotate(0deg); }
        }

        /* === TEXT === */
        .heading-text {
            font-weight: 700;
            font-size: 1.45rem;
            color: var(--text-dark);
            text-align: center;
            margin-bottom: 10px;
            line-height: 1.4;
            animation: fadeUp 0.5s ease-out 0.65s both;
        }

        .message-text {
            color: var(--text-muted);
            text-align: center;
            font-size: 0.9rem;
            line-height: 1.75;
            margin-bottom: 34px;
            animation: fadeUp 0.5s ease-out 0.8s both;
            max-width: 360px;
            margin-left: auto;
            margin-right: auto;
        }

        .message-text .highlight {
            color: var(--primary-purple);
            font-weight: 500;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* === BUTTONS === */
        .btn-group-actions {
            display: flex;
            gap: 14px;
            animation: fadeUp 0.5s ease-out 0.95s both;
        }

        .btn-action {
            flex: 1;
            padding: 15px 20px;
            border-radius: 14px;
            font-weight: 600;
            font-size: 0.95rem;
            letter-spacing: 0.2px;
            transition: all 0.35s cubic-bezier(0.25, 0.8, 0.25, 1);
            border: 2px solid transparent;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            position: relative;
            overflow: hidden;
            cursor: pointer;
        }

        .btn-action::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.2), transparent);
            opacity: 0;
            transition: opacity 0.35s ease;
        }

        .btn-action:hover::before {
            opacity: 1;
        }

        .btn-login-action {
            background: linear-gradient(135deg, var(--primary-purple), var(--primary-light));
            color: #fff;
            border-color: transparent;
            box-shadow: 0 4px 15px rgba(106, 13, 173, 0.3);
        }

        .btn-login-action:hover {
            transform: translateY(-3px);
            box-shadow:
                0 12px 28px rgba(106, 13, 173, 0.4),
                0 4px 10px rgba(106, 13, 173, 0.2);
            color: #fff;
        }

        .btn-login-action:active {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(106, 13, 173, 0.3);
        }

        .btn-register-action {
            background: #fff;
            color: var(--primary-purple);
            border-color: var(--primary-purple);
            box-shadow: 0 4px 15px rgba(106, 13, 173, 0.08);
        }

        .btn-register-action:hover {
            background: var(--primary-purple);
            color: #fff;
            transform: translateY(-3px);
            box-shadow:
                0 12px 28px rgba(106, 13, 173, 0.35),
                0 4px 10px rgba(106, 13, 173, 0.15);
        }

        .btn-register-action:active {
            transform: translateY(-1px);
        }

        /* === DIVIDER === */
        .divider {
            display: flex;
            align-items: center;
            margin: 30px 0 24px;
            animation: fadeUp 0.5s ease-out 1.1s both;
        }

        .divider-line {
            flex: 1;
            height: 1px;
            background: linear-gradient(90deg, transparent, #e5e7eb, transparent);
        }

        .divider-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 14px;
            color: #d1d5db;
            font-size: 0.7rem;
        }

        /* === FOOTER === */
        .footer-note {
            text-align: center;
            color: var(--text-light);
            font-size: 0.82rem;
            animation: fadeUp 0.5s ease-out 1.2s both;
            line-height: 1.7;
        }

        .footer-note .heart {
            color: #f472b6;
            display: inline-block;
            animation: heartbeat 1.5s ease-in-out infinite;
            margin: 0 3px;
        }

        @keyframes heartbeat {
            0%, 100% { transform: scale(1); }
            15% { transform: scale(1.25); }
            30% { transform: scale(1); }
            45% { transform: scale(1.15); }
            60% { transform: scale(1); }
        }

        /* === SOUND TOGGLE === */
        .sound-toggle {
            position: fixed;
            top: 20px;
            right: 20px;
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.18);
            color: rgba(255, 255, 255, 0.85);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
            z-index: 10;
            font-size: 0.88rem;
        }

        .sound-toggle:hover {
            background: rgba(255, 255, 255, 0.22);
            transform: scale(1.08);
            border-color: rgba(255, 255, 255, 0.3);
        }

        .sound-toggle.muted {
            opacity: 0.45;
        }

        .sound-toggle .sound-wave {
            display: flex;
            align-items: flex-end;
            gap: 2px;
            height: 16px;
        }

        .sound-toggle .sound-wave span {
            width: 3px;
            border-radius: 2px;
            background: currentColor;
            animation: waveBar 0.8s ease-in-out infinite alternate;
        }

        .sound-toggle .sound-wave span:nth-child(1) { height: 6px; animation-delay: 0s; }
        .sound-toggle .sound-wave span:nth-child(2) { height: 12px; animation-delay: 0.15s; }
        .sound-toggle .sound-wave span:nth-child(3) { height: 8px; animation-delay: 0.3s; }
        .sound-toggle .sound-wave span:nth-child(4) { height: 14px; animation-delay: 0.1s; }

        @keyframes waveBar {
            to { height: 4px; }
        }

        .sound-toggle.muted .sound-wave span {
            animation: none !important;
            height: 3px !important;
            opacity: 0.5;
        }

        /* === CONFETTI BURST === */
        .confetti-piece {
            position: fixed;
            z-index: 5;
            pointer-events: none;
            border-radius: 2px;
            animation: confettiFall linear forwards;
        }

        @keyframes confettiFall {
            0% {
                opacity: 1;
                transform: translateY(0) rotate(0deg) scale(1);
            }
            80% {
                opacity: 1;
            }
            100% {
                opacity: 0;
                transform: translateY(100vh) rotate(720deg) scale(0.5);
            }
        }

        /* === RESPONSIVE === */
        @media (max-width: 576px) {
            .card-body-inner {
                padding: 36px 28px 30px;
            }

            .logout-card {
                border-radius: 20px;
            }

            .heading-text {
                font-size: 1.25rem;
            }

            .message-text {
                font-size: 0.85rem;
                margin-bottom: 28px;
            }

            .btn-action {
                padding: 13px 16px;
                font-size: 0.88rem;
                border-radius: 12px;
            }

            .icon-wrapper {
                width: 95px;
                height: 95px;
                margin: 24px auto 22px;
            }

            .icon-circle {
                width: 88px;
                height: 88px;
            }

            .icon-circle i {
                font-size: 38px;
            }
        }

        @media (max-width: 380px) {
            .card-body-inner {
                padding: 28px 20px 24px;
            }

            .btn-group-actions {
                flex-direction: column;
                gap: 10px;
            }

            .heading-text {
                font-size: 1.1rem;
            }

            .icon-wrapper {
                width: 85px;
                height: 85px;
            }

            .icon-circle {
                width: 78px;
                height: 78px;
            }

            .icon-circle i {
                font-size: 34px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
            .confetti-piece { display: none; }
            #particleCanvas { display: none; }
        }
    </style>
</head>
<body>

    <!-- Animated Background Layers -->
    <div class="bg-layer">
        <div class="bg-orb"></div>
        <div class="bg-orb"></div>
        <div class="bg-orb"></div>
    </div>
    <div class="bg-grid"></div>
    <canvas id="particleCanvas"></canvas>

    <!-- Sound Toggle -->
    <button class="sound-toggle" id="soundToggle" title="Toggle Sound" aria-label="Toggle sound effect">
        <div class="sound-wave" id="soundWave">
            <span></span><span></span><span></span><span></span>
        </div>
    </button>

    <!-- Main Card -->
    <div class="logout-card">
        <div class="card-accent"></div>
        <div class="card-body-inner">

            <!-- Security Badge -->
            <div class="text-center">
                <span class="security-badge">
                    <i class="fas fa-shield-halved"></i>
                    Session Secured & Cleared
                </span>
            </div>

            <!-- Success Icon -->
            <div class="icon-wrapper">
                <div class="icon-ring-outer"></div>
                <div class="icon-ring-pulse"></div>
                <div class="icon-ring-pulse"></div>
                <div class="icon-circle">
                    <i class="fas fa-check"></i>
                </div>
            </div>

            <!-- Heading -->
            <h2 class="heading-text">You have been logged out successfully.</h2>

            <!-- Message -->
            <p class="message-text">
                Your session has been <span class="highlight">ended safely</span> and all
                temporary data has been cleared from our servers.
            </p>

            <!-- Action Buttons -->
            <div class="btn-group-actions">
                <a href="login.php" class="btn-action btn-login-action">
                    <i class="fas fa-right-to-bracket"></i>
                    Login
                </a>
                <a href="register.php" class="btn-action btn-register-action">
                    <i class="fas fa-user-plus"></i>
                    Register
                </a>
            </div>

            <!-- Divider -->
            <div class="divider">
                <div class="divider-line"></div>
                <div class="divider-icon">
                    <i class="fas fa-ellipsis"></i>
                </div>
                <div class="divider-line"></div>
            </div>

            <!-- Footer Note -->
            <p class="footer-note">
                <span class="heart"><i class="fas fa-heart"></i></span>
                Thank you for visiting. We hope to see you again soon!
            </p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // =============================================
        // 1. SOUND EFFECT — Auto-plays on page load
        //    Uses Web Audio API, no external files
        // =============================================
        let soundEnabled = true;

        function playSuccessSound() {
            if (!soundEnabled) return;
            try {
                const AC = window.AudioContext || window.webkitAudioContext;
                const ctx = new AC();
                const now = ctx.currentTime;

                // Richer chime: C5 → E5 → G5 → C6 with reverb-like tail
                const notes = [
                    { freq: 523.25, start: 0,    dur: 0.5  },
                    { freq: 659.25, start: 0.1,  dur: 0.5  },
                    { freq: 783.99, start: 0.2,  dur: 0.5  },
                    { freq: 1046.50,start: 0.32, dur: 0.8  }
                ];

                notes.forEach(n => {
                    // Fundamental
                    const osc = ctx.createOscillator();
                    const g = ctx.createGain();
                    osc.type = 'sine';
                    osc.frequency.setValueAtTime(n.freq, now + n.start);
                    g.gain.setValueAtTime(0, now + n.start);
                    g.gain.linearRampToValueAtTime(0.15, now + n.start + 0.03);
                    g.gain.exponentialRampToValueAtTime(0.001, now + n.start + n.dur);
                    osc.connect(g).connect(ctx.destination);
                    osc.start(now + n.start);
                    osc.stop(now + n.start + n.dur + 0.05);

                    // Warm harmonic (octave up, triangle)
                    const o2 = ctx.createOscillator();
                    const g2 = ctx.createGain();
                    o2.type = 'triangle';
                    o2.frequency.setValueAtTime(n.freq * 2, now + n.start);
                    g2.gain.setValueAtTime(0, now + n.start);
                    g2.gain.linearRampToValueAtTime(0.035, now + n.start + 0.03);
                    g2.gain.exponentialRampToValueAtTime(0.001, now + n.start + n.dur * 0.6);
                    o2.connect(g2).connect(ctx.destination);
                    o2.start(now + n.start);
                    o2.stop(now + n.start + n.dur + 0.05);

                    // Sub shimmer (fifth harmonic, very quiet)
                    const o3 = ctx.createOscillator();
                    const g3 = ctx.createGain();
                    o3.type = 'sine';
                    o3.frequency.setValueAtTime(n.freq * 3, now + n.start);
                    g3.gain.setValueAtTime(0, now + n.start);
                    g3.gain.linearRampToValueAtTime(0.012, now + n.start + 0.05);
                    g3.gain.exponentialRampToValueAtTime(0.001, now + n.start + n.dur * 0.4);
                    o3.connect(g3).connect(ctx.destination);
                    o3.start(now + n.start);
                    o3.stop(now + n.start + n.dur + 0.05);
                });

                setTimeout(() => ctx.close(), 2500);
            } catch (e) {
                console.warn('Audio unavailable:', e);
            }
        }

        // Auto-play sound immediately when page loads
        // (no delay — fires the moment DOM is ready)
        document.addEventListener('DOMContentLoaded', () => {
            playSuccessSound();
            launchConfetti();
        });

        // Fallback: also try on window load in case DOMContentLoaded already fired
        window.addEventListener('load', () => {
            if (soundEnabled) playSuccessSound();
        });

        // =============================================
        // 2. SOUND TOGGLE
        // =============================================
        const soundToggle = document.getElementById('soundToggle');
        soundToggle.addEventListener('click', function () {
            soundEnabled = !soundEnabled;
            this.classList.toggle('muted', !soundEnabled);
            if (soundEnabled) playSuccessSound();
        });

        // =============================================
        // 3. CONFETTI BURST on logout
        // =============================================
        function launchConfetti() {
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

            const colors = ['#6A0DAD', '#9b59b6', '#10b981', '#34d399', '#c084fc', '#f472b6', '#fbbf24'];
            const count = 50;

            for (let i = 0; i < count; i++) {
                const piece = document.createElement('div');
                piece.classList.add('confetti-piece');

                const size = Math.random() * 8 + 4;
                const isCircle = Math.random() > 0.5;

                piece.style.width = size + 'px';
                piece.style.height = isCircle ? size + 'px' : size * 2.5 + 'px';
                piece.style.borderRadius = isCircle ? '50%' : '2px';
                piece.style.background = colors[Math.floor(Math.random() * colors.length)];
                piece.style.left = (Math.random() * 100) + 'vw';
                piece.style.top = '-20px';
                piece.style.animationDuration = (Math.random() * 2 + 2) + 's';
                piece.style.animationDelay = (Math.random() * 0.8) + 's';
                piece.style.opacity = '0';

                document.body.appendChild(piece);

                // Clean up after animation
                setTimeout(() => piece.remove(), 5000);
            }
        }

        // =============================================
        // 4. FLOATING PARTICLES (Canvas)
        // =============================================
        (function initParticles() {
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

            const canvas = document.getElementById('particleCanvas');
            const c = canvas.getContext('2d');
            let w, h, particles = [];

            function resize() {
                w = canvas.width = window.innerWidth;
                h = canvas.height = window.innerHeight;
            }
            resize();
            window.addEventListener('resize', resize);

            // Create particles
            for (let i = 0; i < 40; i++) {
                particles.push({
                    x: Math.random() * w,
                    y: Math.random() * h,
                    r: Math.random() * 2 + 0.5,
                    dx: (Math.random() - 0.5) * 0.4,
                    dy: (Math.random() - 0.5) * 0.4,
                    alpha: Math.random() * 0.3 + 0.05
                });
            }

            function draw() {
                c.clearRect(0, 0, w, h);
                particles.forEach(p => {
                    p.x += p.dx;
                    p.y += p.dy;

                    // Wrap around edges
                    if (p.x < -10) p.x = w + 10;
                    if (p.x > w + 10) p.x = -10;
                    if (p.y < -10) p.y = h + 10;
                    if (p.y > h + 10) p.y = -10;

                    c.beginPath();
                    c.arc(p.x, p.y, Math.max(0.1, p.r), 0, Math.PI * 2);
                    c.fillStyle = 'rgba(255, 255, 255, ' + p.alpha + ')';
                    c.fill();
                });

                // Draw subtle connections
                for (let i = 0; i < particles.length; i++) {
                    for (let j = i + 1; j < particles.length; j++) {
                        const dx = particles[i].x - particles[j].x;
                        const dy = particles[i].y - particles[j].y;
                        const dist = Math.sqrt(dx * dx + dy * dy);
                        if (dist < 150) {
                            c.beginPath();
                            c.moveTo(particles[i].x, particles[i].y);
                            c.lineTo(particles[j].x, particles[j].y);
                            c.strokeStyle = 'rgba(255, 255, 255, ' + (0.04 * (1 - dist / 150)) + ')';
                            c.lineWidth = 0.5;
                            c.stroke();
                        }
                    }
                }

                requestAnimationFrame(draw);
            }
            draw();
        })();
    </script>
</body>
</html>