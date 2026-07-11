<?php
session_start();

$servername = "localhost";
$username   = "root";
$password   = "";               // Default XAMPP password
$dbname     = "assignment_db";  // Your local database name

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}


/* ── Fetch avatar for header ── */
 $userAvatarSeed = "Felix";
if (isset($_SESSION['user_id'])) {
    $uid = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT avatar FROM users WHERE user_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $r = $stmt->get_result();
        if ($row = $r->fetch_assoc()) {
            if (!empty($row['avatar'])) $userAvatarSeed = $row['avatar'];
        }
        $stmt->close();
    }
}

include 'header.php';
?>

<style>
    .pp-area {
        padding: 120px 0 80px;
        min-height: 100vh;
    }

    /* ── Breadcrumb ── */
    .pp-breadcrumb {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.82rem;
        margin-bottom: 8px;
    }
    .pp-breadcrumb a {
        color: var(--text-muted);
        text-decoration: none;
        transition: color 0.3s;
    }
    .pp-breadcrumb a:hover { color: var(--accent); }
    .pp-breadcrumb .sep { color: var(--text-muted); opacity: 0.4; }
    .pp-breadcrumb .current { color: var(--accent); font-weight: 600; }

    .pp-page-title {
        font-weight: 900;
        color: var(--text-bright);
        font-size: clamp(1.6rem, 3.5vw, 2.2rem);
        letter-spacing: -0.03em;
        margin-bottom: 4px;
    }
    .pp-page-title span {
        background: linear-gradient(135deg, var(--accent), var(--accent-rose));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }
    .pp-page-sub {
        color: var(--text-muted);
        font-size: 0.92rem;
        margin-bottom: 40px;
    }

    /* ── Glass Card ── */
    .pp-card {
        background: var(--bg-card);
        backdrop-filter: blur(16px);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius-lg);
        overflow: hidden;
        position: relative;
        transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .pp-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 2px;
        background: linear-gradient(90deg, transparent, var(--accent), var(--accent-rose), transparent);
        opacity: 0.6;
    }

    /* ── Card Header ── */
    .pp-card-hdr {
        padding: 30px 36px;
        border-bottom: 1px solid var(--border-subtle);
        display: flex;
        align-items: center;
        gap: 20px;
    }
    .pp-card-hdr-icon {
        width: 56px; height: 56px; min-width: 56px;
        background: rgba(216,143,255,0.1);
        border: 1px solid rgba(216,143,255,0.15);
        border-radius: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.3rem;
        color: var(--accent);
    }
    .pp-card-hdr-title {
        font-weight: 800;
        color: var(--text-bright);
        font-size: 1.2rem;
        margin-bottom: 2px;
    }
    .pp-card-hdr-sub {
        font-size: 0.78rem;
        color: var(--text-muted);
    }

    /* ── Card Body ── */
    .pp-card-body {
        padding: 36px;
    }

    /* ── Last Updated ── */
    .pp-updated {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 18px;
        border-radius: 40px;
        font-size: 0.74rem;
        font-weight: 500;
        background: rgba(216,143,255,0.06);
        border: 1px solid rgba(216,143,255,0.1);
        color: var(--accent);
        margin-bottom: 36px;
    }
    .pp-updated i { font-size: 0.68rem; }

    /* ── Policy Sections ── */
    .pp-section {
        margin-bottom: 36px;
        position: relative;
    }
    .pp-section:last-child { margin-bottom: 0; }

    .pp-section-title {
        font-weight: 700;
        color: var(--text-bright);
        font-size: 1.08rem;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 14px;
        letter-spacing: -0.01em;
    }
    .pp-section-num {
        width: 34px; height: 34px; min-width: 34px;
        background: linear-gradient(135deg, var(--primary-mid), var(--accent));
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-family: 'Space Grotesk', sans-serif;
        font-weight: 700;
        font-size: 0.78rem;
        color: white;
        box-shadow: 0 4px 14px rgba(216,143,255,0.25);
    }
    .pp-section-title::after {
        content: '';
        flex: 1;
        height: 1px;
        background: linear-gradient(90deg, var(--border-subtle), transparent);
        margin-left: 8px;
    }

    .pp-section p {
        color: var(--text-body);
        font-size: 0.9rem;
        line-height: 1.85;
        margin-bottom: 14px;
    }
    .pp-section p:last-child { margin-bottom: 0; }

    /* ── Highlighted boxes ── */
    .pp-highlight {
        background: rgba(244,209,255,0.04);
        border: 1px solid var(--border-subtle);
        border-left: 3px solid var(--accent);
        border-radius: var(--radius-sm);
        padding: 18px 22px;
        margin: 16px 0;
    }
    .pp-highlight p {
        margin-bottom: 0 !important;
        font-size: 0.88rem;
    }

    .pp-highlight-rose {
        border-left-color: var(--accent-rose);
    }
    .pp-highlight-gold {
        border-left-color: var(--accent-gold);
    }

    /* ── Lists ── */
    .pp-list {
        list-style: none;
        padding: 0;
        margin: 14px 0;
    }
    .pp-list li {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 10px 0;
        font-size: 0.88rem;
        color: var(--text-body);
        line-height: 1.7;
        border-bottom: 1px solid rgba(244,209,255,0.04);
    }
    .pp-list li:last-child { border-bottom: none; }
    .pp-list li i {
        color: var(--accent);
        font-size: 0.55rem;
        margin-top: 8px;
        min-width: 18px;
        text-align: center;
    }
    .pp-list-numbered {
        counter-reset: pp-counter;
    }
    .pp-list-numbered li {
        counter-increment: pp-counter;
    }
    .pp-list-numbered li::before {
        content: counter(pp-counter, decimal-leading-zero);
        font-family: 'Space Grotesk', sans-serif;
        font-weight: 700;
        font-size: 0.72rem;
        color: var(--accent);
        min-width: 28px;
        height: 28px;
        background: rgba(216,143,255,0.08);
        border: 1px solid rgba(216,143,255,0.1);
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-top: 1px;
        margin-right: 0;
    }

    /* ── Inline bold + color ── */
    .pp-b { color: var(--text-light); font-weight: 600; }
    .pp-accent { color: var(--accent); font-weight: 600; }
    .pp-rose { color: var(--accent-rose); font-weight: 600; }

    /* ── Contact box ── */
    .pp-contact-box {
        background: linear-gradient(135deg, rgba(216,143,255,0.06), rgba(255,142,196,0.03));
        border: 1px solid rgba(216,143,255,0.12);
        border-radius: var(--radius-md);
        padding: 28px 32px;
        display: flex;
        align-items: center;
        gap: 20px;
        margin-top: 36px;
        transition: all 0.4s ease;
    }
    .pp-contact-box:hover {
        border-color: rgba(216,143,255,0.25);
        box-shadow: 0 8px 30px rgba(0,0,0,0.15), 0 0 20px rgba(216,143,255,0.05);
    }
    .pp-contact-icon {
        width: 56px; height: 56px; min-width: 56px;
        background: linear-gradient(135deg, var(--primary-mid), var(--accent));
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.3rem;
        color: white;
        box-shadow: 0 4px 20px rgba(216,143,255,0.3);
    }
    .pp-contact-info { flex: 1; }
    .pp-contact-title {
        font-weight: 700;
        color: var(--text-light);
        font-size: 0.95rem;
        margin-bottom: 4px;
    }
    .pp-contact-detail {
        font-size: 0.84rem;
        color: var(--text-muted);
        line-height: 1.6;
    }
    .pp-contact-detail a {
        color: var(--accent);
        text-decoration: none;
        transition: color 0.3s;
    }
    .pp-contact-detail a:hover { color: var(--primary-light); text-decoration: underline; }

    .btn-contact {
        background: linear-gradient(135deg, var(--accent), var(--accent-rose));
        color: white;
        border: none;
        padding: 12px 28px;
        border-radius: 40px;
        font-weight: 600;
        font-size: 0.85rem;
        font-family: 'Poppins', sans-serif;
        transition: all 0.4s ease;
        cursor: pointer;
        box-shadow: 0 4px 20px rgba(216,143,255,0.3);
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        white-space: nowrap;
    }
    .btn-contact:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 32px rgba(216,143,255,0.45);
        color: white;
        text-decoration: none;
    }

    /* ── Divider ── */
    .pp-divider {
        height: 1px;
        margin: 32px 0;
        background: linear-gradient(90deg, transparent, var(--border-subtle), rgba(216,143,255,0.1), var(--border-subtle), transparent);
    }

    /* ── Sidebar TOC (Desktop) ── */
    .pp-toc {
        position: sticky;
        top: 100px;
    }
    .pp-toc-card {
        background: var(--bg-card);
        backdrop-filter: blur(16px);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius-lg);
        overflow: hidden;
        position: relative;
    }
    .pp-toc-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 2px;
        background: linear-gradient(90deg, transparent, var(--accent-gold), var(--accent), transparent);
        opacity: 0.5;
    }
    .pp-toc-hdr {
        padding: 20px 24px;
        border-bottom: 1px solid var(--border-subtle);
        font-weight: 700;
        color: var(--text-bright);
        font-size: 0.88rem;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .pp-toc-hdr i { color: var(--accent-gold); font-size: 0.8rem; }
    .pp-toc-list {
        list-style: none;
        padding: 12px;
        margin: 0;
    }
    .pp-toc-list li a {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 14px;
        border-radius: 10px;
        font-size: 0.8rem;
        font-weight: 500;
        color: var(--text-muted);
        text-decoration: none;
        transition: all 0.3s ease;
    }
    .pp-toc-list li a:hover,
    .pp-toc-list li a.active {
        color: var(--primary-light);
        background: rgba(244,209,255,0.06);
    }
    .pp-toc-list li a.active {
        color: var(--accent);
        background: rgba(216,143,255,0.08);
        font-weight: 600;
    }
    .pp-toc-list li a i {
        font-size: 0.55rem;
        color: var(--accent);
        opacity: 0.5;
        transition: opacity 0.3s;
    }
    .pp-toc-list li a:hover i,
    .pp-toc-list li a.active i { opacity: 1; }

    /* ── Back Button ── */
    .btn-back {
        background: transparent;
        border: 1.5px solid var(--border-subtle);
        color: var(--text-light);
        padding: 12px 28px;
        border-radius: 40px;
        font-weight: 600;
        font-size: 0.88rem;
        font-family: 'Poppins', sans-serif;
        transition: all 0.35s ease;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        text-decoration: none;
        cursor: pointer;
    }
    .btn-back:hover {
        border-color: var(--accent);
        color: var(--accent);
        background: rgba(244,209,255,0.04);
        transform: translateX(-4px);
        text-decoration: none;
    }

    /* ── Responsive ── */
    @media (max-width: 991px) {
        .pp-toc { display: none; }
    }
    @media (max-width: 767px) {
        .pp-area { padding: 100px 0 60px; }
        .pp-card-hdr { padding: 22px 20px; }
        .pp-card-body { padding: 24px 20px; }
        .pp-contact-box {
            flex-direction: column;
            text-align: center;
        }
        .btn-contact { width: 100%; justify-content: center; }
        .btn-back { width: 100%; justify-content: center; }
    }
    @media (prefers-reduced-motion: reduce) {
        *, *::before, *::after {
            animation-duration: 0.01ms !important;
            transition-duration: 0.01ms !important;
        }
    }
</style>

<section class="pp-area">
    <div class="container">

        <!-- Breadcrumb & Title -->
        <div class="pp-breadcrumb" data-aos="fade-up">
            <a href="index.php"><i class="fa-solid fa-house" style="font-size:0.72rem;"></i> Home</a>
            <span class="sep">/</span>
            <span class="current">Privacy Policy</span>
        </div>
        <h1 class="pp-page-title" data-aos="fade-up">Privacy <span>Policy</span></h1>
        <p class="pp-page-sub" data-aos="fade-up">How we collect, use, and protect your personal information.</p>

        <div class="row g-4">
            <!-- SIDEBAR: Table of Contents -->
            <div class="col-lg-3 d-none d-lg-block" data-aos="fade-right" data-aos-delay="0">
                <div class="pp-toc">
                    <div class="pp-toc-card">
                        <div class="pp-toc-hdr">
                            <i class="fa-solid fa-list-ol"></i> Table of Contents
                        </div>
                        <ul class="pp-toc-list" id="tocList">
                            <li><a href="#pp-intro" class="active"><i class="fa-solid fa-circle"></i> Introduction</a></li>
                            <li><a href="#pp-collect"><i class="fa-solid fa-circle"></i> Information We Collect</a></li>
                            <li><a href="#pp-how"><i class="fa-solid fa-circle"></i> How We Use Your Data</a></li>
                            <li><a href="#pp-assignment"><i class="fa-solid fa-circle"></i> Assignment Data & AI Analysis</a></li>
                            <li><a href="#pp-storage"><i class="fa-solid fa-circle"></i> Data Storage & Security</a></li>
                            <li><a href="#pp-share"><i class="fa-solid fa-circle"></i> Data Sharing</a></li>
                            <li><a href="#pp-cookies"><i class="fa-solid fa-circle"></i> Cookies & Tracking</a></li>
                            <li><a href="#pp-rights"><i class="fa-solid fa-circle"></i> Your Rights</a></li>
                            <li><a href="#pp-children"><i class="fa-solid fa-circle"></i> Children's Privacy</a></li>
                            <li><a href="#pp-changes"><i class="fa-solid fa-circle"></i> Changes to This Policy</a></li>
                            <li><a href="#pp-contact"><i class="fa-solid fa-circle"></i> Contact Us</a></li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- MAIN CONTENT -->
            <div class="col-lg-9" data-aos="fade-up" data-aos-delay="100">
                <div class="pp-card">
                    <!-- Header -->
                    <div class="pp-card-hdr">
                        <div class="pp-card-hdr-icon">
                            <i class="fa-solid fa-shield-halved"></i>
                        </div>
                        <div>
                            <div class="pp-card-hdr-title">Privacy Policy</div>
                            <div class="pp-card-hdr-sub">Your privacy matters to us</div>
                        </div>
                    </div>

                    <!-- Body -->
                    <div class="pp-card-body">

                        <div class="pp-updated">
                            <i class="fa-regular fa-calendar"></i>
                            Last Updated: January 1, 2025
                        </div>

                        <!-- ═══ 1. INTRODUCTION ═══ -->
                        <div class="pp-section" id="pp-intro">
                            <div class="pp-section-title">
                                <div class="pp-section-num">1</div>
                                Introduction
                            </div>
                            <p>
                                Welcome to <span class="pp-accent">AI Assignment Checker</span>. We are committed to protecting and respecting your privacy. This Privacy Policy explains how we collect, use, disclose, and safeguard your information when you visit our website and use our services.
                            </p>
                            <p>
                                By accessing or using our platform, you agree to the terms outlined in this policy. If you do not agree with any part of this policy, please discontinue use of our services immediately.
                            </p>
                            <div class="pp-highlight">
                                <p>
                                    <i class="fa-solid fa-info-circle pp-accent" style="margin-right:6px;font-size:0.8rem;"></i>
                                    This policy applies to all users of our platform, including registered members, guests, and any third parties who interact with our services.
                                </p>
                            </div>
                        </div>

                        <div class="pp-divider"></div>

                        <!-- ═══ 2. INFORMATION WE COLLECT ═══ -->
                        <div class="pp-section" id="pp-collect">
                            <div class="pp-section-title">
                                <div class="pp-section-num">2</div>
                                Information We Collect
                            </div>
                            <p>We collect information that you provide directly to us, as well as information that is gathered automatically when you use our platform.</p>

                            <p class="pp-b" style="margin-top:20px;margin-bottom:12px;">Personal Information:</p>
                            <ul class="pp-list">
                                <li><i class="fa-solid fa-circle"></i> Full name and display name</li>
                                <li><i class="fa-solid fa-circle"></i> Email address</li>
                                <li><i class="fa-solid fa-circle"></i> Password (encrypted and hashed using industry-standard algorithms)</li>
                                <li><i class="fa-solid fa-circle"></i> Profile avatar selection</li>
                                <li><i class="fa-solid fa-circle"></i> Account type and registration details</li>
                            </ul>

                            <p class="pp-b" style="margin-top:20px;margin-bottom:12px;">Assignment-Related Information:</p>
                            <ul class="pp-list">
                                <li><i class="fa-solid fa-circle"></i> Uploaded assignment files (documents, PDFs, text files)</li>
                                <li><i class="fa-solid fa-circle"></i> Assignment titles and descriptions</li>
                                <li><i class="fa-solid fa-circle"></i> AI detection scores and similarity reports</li>
                                <li><i class="fa-solid fa-circle"></i> Admin feedback and revision notes</li>
                            </ul>

                            <p class="pp-b" style="margin-top:20px;margin-bottom:12px;">Automatically Collected Information:</p>
                            <ul class="pp-list">
                                <li><i class="fa-solid fa-circle"></i> IP address and browser type</li>
                                <li><i class="fa-solid fa-circle"></i> Operating system and device information</li>
                                <li><i class="fa-solid fa-circle"></i> Pages visited, time spent, and navigation patterns</li>
                                <li><i class="fa-solid fa-circle"></i> Referring URL and exit pages</li>
                            </ul>
                        </div>

                        <div class="pp-divider"></div>

                        <!-- ═══ 3. HOW WE USE YOUR DATA ═══ -->
                        <div class="pp-section" id="pp-how">
                            <div class="pp-section-title">
                                <div class="pp-section-num">3</div>
                                How We Use Your Data
                            </div>
                            <p>We use the information we collect for the following purposes:</p>
                            <ul class="pp-list pp-list-numbered">
                                <li style="display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-bottom:1px solid rgba(244,209,255,0.04);">
                                    <span></span>
                                    <span>To provide, operate, and maintain our AI assignment checking services</span>
                                </li>
                                <li style="display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-bottom:1px solid rgba(244,209,255,0.04);">
                                    <span></span>
                                    <span>To process and analyze your submitted assignments for AI-generated content and plagiarism</span>
                                </li>
                                <li style="display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-bottom:1px solid rgba(244,209,255,0.04);">
                                    <span></span>
                                    <span>To generate detailed analysis reports and provide improvement suggestions</span>
                                </li>
                                <li style="display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-bottom:1px solid rgba(244,209,255,0.04);">
                                    <span></span>
                                    <span>To manage your account, authenticate your identity, and provide customer support</span>
                                </li>
                                <li style="display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-bottom:1px solid rgba(244,209,255,0.04);">
                                    <span></span>
                                    <span>To communicate with you regarding your assignments, account updates, and service notifications</span>
                                </li>
                                <li style="display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-bottom:1px solid rgba(244,209,255,0.04);">
                                    <span></span>
                                    <span>To monitor usage patterns, detect abuse, and improve our platform's performance and features</span>
                                </li>
                                <li style="display:flex;gap:12px;align-items:flex-start;padding:10px 0;">
                                    <span></span>
                                    <span>To comply with legal obligations and enforce our terms of service</span>
                                </li>
                            </ul>
                        </div>

                        <div class="pp-divider"></div>

                        <!-- ═══ 4. ASSIGNMENT DATA & AI ANALYSIS ═══ -->
                        <div class="pp-section" id="pp-assignment">
                            <div class="pp-section-title">
                                <div class="pp-section-num">4</div>
                                Assignment Data & AI Analysis
                            </div>
                            <p>
                                When you submit an assignment through our platform, the content is processed by our AI detection and plagiarism checking systems. We want to be transparent about how this works:
                            </p>
                            <div class="pp-highlight">
                                <p>
                                    <i class="fa-solid fa-robot pp-accent" style="margin-right:6px;font-size:0.8rem;"></i>
                                    Your assignment content is analyzed in real-time by our AI models. The text is compared against known AI-generated patterns and external databases to produce similarity and AI detection scores.
                                </p>
                            </div>
                            <ul class="pp-list">
                                <li><i class="fa-solid fa-circle"></i> Assignment files are stored securely on our servers and are <span class="pp-b">not shared with third parties</span> for any purpose other than analysis</li>
                                <li><i class="fa-solid fa-circle"></i> Generated reports are saved to your account and can be downloaded by you at any time</li>
                                <li><i class="fa-solid fa-circle"></i> Admin reviewers may access your assignments solely to provide feedback and quality assurance</li>
                                <li><i class="fa-solid fa-circle"></i> We do <span class="pp-b">not</span> use your assignment content to train our AI models</li>
                                <li><i class="fa-solid fa-circle"></i> You retain full ownership of all content you submit to our platform</li>
                            </ul>
                        </div>

                        <div class="pp-divider"></div>

                        <!-- ═══ 5. DATA STORAGE & SECURITY ═══ -->
                        <div class="pp-section" id="pp-storage">
                            <div class="pp-section-title">
                                <div class="pp-section-num">5</div>
                                Data Storage & Security
                            </div>
                            <p>
                                We implement industry-standard security measures to protect your personal information and assignment data from unauthorized access, alteration, disclosure, or destruction.
                            </p>
                            <p class="pp-b" style="margin-bottom:12px;">Security Measures Include:</p>
                            <ul class="pp-list">
                                <li><i class="fa-solid fa-circle"></i> <span class="pp-b">Encryption:</span> All passwords are hashed using bcrypt (PASSWORD_DEFAULT). Data transmission is encrypted via SSL/TLS</li>
                                <li><i class="fa-solid fa-circle"></i> <span class="pp-b">Prepared Statements:</span> All database queries use parameterized prepared statements to prevent SQL injection</li>
                                <li><i class="fa-solid fa-circle"></i> <span class="pp-b">Access Control:</span> User data is partitioned by user ID; members can only access their own assignments and results</li>
                                <li><i class="fa-solid fa-circle"></i> <span class="pp-b">Server Security:</span> Firewalls, intrusion detection systems, and regular security audits</li>
                                <li><i class="fa-solid fa-circle"></i> <span class="pp-b">Session Management:</span> Secure session handling with server-side validation and expiration controls</li>
                            </ul>
                            <div class="pp-highlight pp-highlight-rose">
                                <p>
                                    <i class="fa-solid fa-triangle-exclamation pp-rose" style="margin-right:6px;font-size:0.8rem;"></i>
                                    While we strive to use commercially acceptable means to protect your data, no method of transmission over the Internet or electronic storage is 100% secure. We cannot guarantee absolute security.
                                </p>
                            </div>
                        </div>

                        <div class="pp-divider"></div>

                        <!-- ═══ 6. DATA SHARING ═══ -->
                        <div class="pp-section" id="pp-share">
                            <div class="pp-section-title">
                                <div class="pp-section-num">6</div>
                                Data Sharing
                            </div>
                            <p>
                                We do <span class="pp-b">not</span> sell, trade, or rent your personal information to third parties. We may share your information only in the following circumstances:
                            </p>
                            <ul class="pp-list">
                                <li><i class="fa-solid fa-circle"></i> <span class="pp-b">Service Providers:</span> Trusted third parties that assist us in operating our platform (e.g., hosting providers) under strict confidentiality agreements</li>
                                <li><i class="fa-solid fa-circle"></i> <span class="pp-b">Legal Requirements:</span> When required by law, regulation, legal process, or governmental request</li>
                                <li><i class="fa-solid fa-circle"></i> <span class="pp-b">Protection of Rights:</span> To protect the rights, property, or safety of our company, users, or the public</li>
                                <li><i class="fa-solid fa-circle"></i> <span class="pp-b">Business Transfers:</span> In connection with a merger, acquisition, or sale of assets, your data may be transferred as part of that transaction</li>
                            </ul>
                            <div class="pp-highlight pp-highlight-gold">
                                <p>
                                    <i class="fa-solid fa-handshake" style="margin-right:6px;font-size:0.8rem;color:var(--accent-gold);"></i>
                                    Any third-party service providers we engage are bound by contractual obligations to maintain the confidentiality and security of your data.
                                </p>
                            </div>
                        </div>

                        <div class="pp-divider"></div>

                        <!-- ═══ 7. COOKIES & TRACKING ═══ -->
                        <div class="pp-section" id="pp-cookies">
                            <div class="pp-section-title">
                                <div class="pp-section-num">7</div>
                                Cookies & Tracking
                            </div>
                            <p>
                                Our platform uses cookies and similar tracking technologies to enhance your browsing experience. Cookies are small data files stored on your device that help us remember your preferences and session information.
                            </p>
                            <p class="pp-b" style="margin-bottom:12px;">Types of Cookies We Use:</p>
                            <ul class="pp-list">
                                <li><i class="fa-solid fa-circle"></i> <span class="pp-b">Essential Cookies:</span> Required for login authentication, session management, and security. These cannot be disabled</li>
                                <li><i class="fa-solid fa-circle"></i> <span class="pp-b">Preference Cookies:</span> Remember your avatar selection, display settings, and other personalization options</li>
                                <li><i class="fa-solid fa-circle"></i> <span class="pp-b">Analytics Cookies:</span> Help us understand how users interact with our platform so we can improve the experience</li>
                            </ul>
                            <p>
                                You can control cookie settings through your browser preferences. However, disabling essential cookies may prevent you from using certain features of our platform.
                            </p>
                        </div>

                        <div class="pp-divider"></div>

                        <!-- ═══ 8. YOUR RIGHTS ═══ -->
                        <div class="pp-section" id="pp-rights">
                            <div class="pp-section-title">
                                <div class="pp-section-num">8</div>
                                Your Rights
                            </div>
                            <p>
                                Depending on your jurisdiction, you may have the following rights regarding your personal data:
                            </p>
                            <ul class="pp-list">
                                <li><i class="fa-solid fa-circle"></i> <span class="pp-b">Access:</span> Request a copy of the personal data we hold about you</li>
                                <li><i class="fa-solid fa-circle"></i> <span class="pp-b">Correction:</span> Request correction of inaccurate or incomplete data</li>
                                <li><i class="fa-solid fa-circle"></i> <span class="pp-b">Deletion:</span> Request deletion of your personal data and assignment files, subject to legal retention requirements</li>
                                <li><i class="fa-solid fa-circle"></i> <span class="pp-b">Portability:</span> Request your data in a structured, machine-readable format</li>
                                <li><i class="fa-solid fa-circle"></i> <span class="pp-b">Objection:</span> Object to the processing of your data for specific purposes</li>
                                <li><i class="fa-solid fa-circle"></i> <span class="pp-b">Withdrawal of Consent:</span> Withdraw consent at any time where processing is based on consent</li>
                            </ul>
                            <div class="pp-highlight">
                                <p>
                                    <i class="fa-solid fa-user-shield pp-accent" style="margin-right:6px;font-size:0.8rem;"></i>
                                    To exercise any of these rights, please contact us using the information provided at the bottom of this page. We will respond to your request within 30 days.
                                </p>
                            </div>
                        </div>

                        <div class="pp-divider"></div>

                        <!-- ═══ 9. CHILDREN'S PRIVACY ═══ -->
                        <div class="pp-section" id="pp-children">
                            <div class="pp-section-title">
                                <div class="pp-section-num">9</div>
                                Children's Privacy
                            </div>
                            <p>
                                Our services are <span class="pp-b">not intended for individuals under the age of 13</span>. We do not knowingly collect personal information from children under 13. If we become aware that we have collected data from a child under 13, we will take immediate steps to delete that information.
                            </p>
                            <p>
                                If you believe a child under 13 has provided us with personal information, please contact us immediately so we can take appropriate action.
                            </p>
                        </div>

                        <div class="pp-divider"></div>

                        <!-- ═══ 10. CHANGES TO THIS POLICY ═══ -->
                        <div class="pp-section" id="pp-changes">
                            <div class="pp-section-title">
                                <div class="pp-section-num">10</div>
                                Changes to This Policy
                            </div>
                            <p>
                                We may update this Privacy Policy from time to time to reflect changes in our practices, technologies, legal requirements, or other factors. When we make changes:
                            </p>
                            <ul class="pp-list">
                                <li><i class="fa-solid fa-circle"></i> We will revise the "Last Updated" date at the top of this page</li>
                                <li><i class="fa-solid fa-circle"></i> We may notify you via email or a prominent notice on our platform for significant changes</li>
                                <li><i class="fa-solid fa-circle"></i> Your continued use of the platform after changes constitute acceptance of the updated policy</li>
                            </ul>
                            <p>
                                We encourage you to review this page periodically to stay informed about how we protect your information.
                            </p>
                        </div>

                        <div class="pp-divider"></div>

                        <!-- ═══ 11. CONTACT US ═══ -->
                        <div class="pp-section" id="pp-contact">
                            <div class="pp-section-title">
                                <div class="pp-section-num">11</div>
                                Contact Us
                            </div>
                            <p>
                                If you have any questions, concerns, or requests regarding this Privacy Policy or our data practices, please don't hesitate to reach out to us.
                            </p>

                            <div class="pp-contact-box">
                                <div class="pp-contact-icon">
                                    <i class="fa-solid fa-envelope"></i>
                                </div>
                                <div class="pp-contact-info">
                                    <div class="pp-contact-title">Privacy & Data Protection Team</div>
                                    <div class="pp-contact-detail">
                                        Email us at <a href="mailto:privacy@aichecker.com">privacy@aichecker.com</a><br>
                                        Or visit our <a href="contacts.php">Contact Page</a> for more options
                                    </div>
                                </div>
                                <a href="contacts.php" class="btn-contact">
                                    <i class="fa-solid fa-paper-plane"></i> Contact
                                </a>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- Back Button -->
                <div style="margin-top: 28px;" data-aos="fade-up" data-aos-delay="100">
                    <a href="javascript:history.back()" class="btn-back">
                        <i class="fa-solid fa-arrow-left"></i> Go Back
                    </a>
                </div>
            </div>
        </div>

    </div>
</section>

<div style="height:40px;"></div>

<?php include 'footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {

    /* ── Sticky TOC Active State on Scroll ── */
    var tocLinks = document.querySelectorAll('#tocList a');
    var sections = [];

    tocLinks.forEach(function(link) {
        var targetId = link.getAttribute('href').substring(1);
        var section = document.getElementById(targetId);
        if (section) {
            sections.push({ id: targetId, el: section, link: link });
        }
    });

    function updateActiveToc() {
        var scrollTop = window.scrollY || window.pageYOffset;
        var current = null;

        for (var i = sections.length - 1; i >= 0; i--) {
            var rect = sections[i].el.getBoundingClientRect();
            if (rect.top <= 140) {
                current = sections[i];
                break;
            }
        }

        tocLinks.forEach(function(l) { l.classList.remove('active'); });
        if (current) {
            current.link.classList.add('active');
        }
    }

    var tocTimer = null;
    window.addEventListener('scroll', function() {
        if (tocTimer) cancelAnimationFrame(tocTimer);
        tocTimer = requestAnimationFrame(updateActiveToc);
    }, { passive: true });

    /* ── Smooth Scroll for TOC Links ── */
    tocLinks.forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            var targetId = this.getAttribute('href').substring(1);
            var target = document.getElementById(targetId);
            if (target) {
                var offset = target.getBoundingClientRect().top + window.pageYOffset - 100;
                window.scrollTo({ top: offset, behavior: 'smooth' });
            }
        });
    });

    /* ── Animate sections on scroll ── */
    var ppSections = document.querySelectorAll('.pp-section');
    var observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });

    ppSections.forEach(function(sec) {
        sec.style.opacity = '0';
        sec.style.transform = 'translateY(20px)';
        sec.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(sec);
    });

});
</script>