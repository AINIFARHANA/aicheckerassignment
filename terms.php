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
    .ts-area {
        padding: 120px 0 80px;
        min-height: 100vh;
    }

    /* ── Breadcrumb ── */
    .ts-breadcrumb {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.82rem;
        margin-bottom: 8px;
    }
    .ts-breadcrumb a {
        color: var(--text-muted);
        text-decoration: none;
        transition: color 0.3s;
    }
    .ts-breadcrumb a:hover { color: var(--accent); }
    .ts-breadcrumb .sep { color: var(--text-muted); opacity: 0.4; }
    .ts-breadcrumb .current { color: var(--accent); font-weight: 600; }

    .ts-page-title {
        font-weight: 900;
        color: var(--text-bright);
        font-size: clamp(1.6rem, 3.5vw, 2.2rem);
        letter-spacing: -0.03em;
        margin-bottom: 4px;
    }
    .ts-page-title span {
        background: linear-gradient(135deg, var(--accent), var(--accent-rose));
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }
    .ts-page-sub {
        color: var(--text-muted);
        font-size: 0.92rem;
        margin-bottom: 40px;
    }

    /* ── Glass Card ── */
    .ts-card {
        background: var(--bg-card);
        backdrop-filter: blur(16px);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius-lg);
        overflow: hidden;
        position: relative;
        transition: all 0.5s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .ts-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 2px;
        background: linear-gradient(90deg, transparent, var(--accent-rose), var(--accent), transparent);
        opacity: 0.6;
    }

    /* ── Card Header ── */
    .ts-card-hdr {
        padding: 30px 36px;
        border-bottom: 1px solid var(--border-subtle);
        display: flex;
        align-items: center;
        gap: 20px;
    }
    .ts-card-hdr-icon {
        width: 56px; height: 56px; min-width: 56px;
        background: rgba(255,142,196,0.1);
        border: 1px solid rgba(255,142,196,0.15);
        border-radius: 18px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.3rem;
        color: var(--accent-rose);
    }
    .ts-card-hdr-title {
        font-weight: 800;
        color: var(--text-bright);
        font-size: 1.2rem;
        margin-bottom: 2px;
    }
    .ts-card-hdr-sub {
        font-size: 0.78rem;
        color: var(--text-muted);
    }

    /* ── Card Body ── */
    .ts-card-body {
        padding: 36px;
    }

    /* ── Last Updated ── */
    .ts-updated {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 18px;
        border-radius: 40px;
        font-size: 0.74rem;
        font-weight: 500;
        background: rgba(255,142,196,0.06);
        border: 1px solid rgba(255,142,196,0.1);
        color: var(--accent-rose);
        margin-bottom: 36px;
    }
    .ts-updated i { font-size: 0.68rem; }

    /* ── Policy Sections ── */
    .ts-section {
        margin-bottom: 36px;
        position: relative;
    }
    .ts-section:last-child { margin-bottom: 0; }

    .ts-section-title {
        font-weight: 700;
        color: var(--text-bright);
        font-size: 1.08rem;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 14px;
        letter-spacing: -0.01em;
    }
    .ts-section-num {
        width: 34px; height: 34px; min-width: 34px;
        background: linear-gradient(135deg, var(--accent-rose), var(--accent));
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-family: 'Space Grotesk', sans-serif;
        font-weight: 700;
        font-size: 0.78rem;
        color: white;
        box-shadow: 0 4px 14px rgba(255,142,196,0.25);
    }
    .ts-section-title::after {
        content: '';
        flex: 1;
        height: 1px;
        background: linear-gradient(90deg, var(--border-subtle), transparent);
        margin-left: 8px;
    }

    .ts-section p {
        color: var(--text-body);
        font-size: 0.9rem;
        line-height: 1.85;
        margin-bottom: 14px;
    }
    .ts-section p:last-child { margin-bottom: 0; }

    /* ── Highlighted boxes ── */
    .ts-highlight {
        background: rgba(244,209,255,0.04);
        border: 1px solid var(--border-subtle);
        border-left: 3px solid var(--accent-rose);
        border-radius: var(--radius-sm);
        padding: 18px 22px;
        margin: 16px 0;
    }
    .ts-highlight p {
        margin-bottom: 0 !important;
        font-size: 0.88rem;
    }

    .ts-highlight-accent {
        border-left-color: var(--accent);
    }
    .ts-highlight-gold {
        border-left-color: var(--accent-gold);
    }
    .ts-highlight-red {
        border-left-color: #ef4444;
        background: rgba(239,68,68,0.04);
    }

    /* ── Lists ── */
    .ts-list {
        list-style: none;
        padding: 0;
        margin: 14px 0;
    }
    .ts-list li {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 10px 0;
        font-size: 0.88rem;
        color: var(--text-body);
        line-height: 1.7;
        border-bottom: 1px solid rgba(244,209,255,0.04);
    }
    .ts-list li:last-child { border-bottom: none; }
    .ts-list li i {
        color: var(--accent-rose);
        font-size: 0.55rem;
        margin-top: 8px;
        min-width: 18px;
        text-align: center;
    }
    .ts-list-check li i {
        color: var(--accent);
    }

    /* ── Inline styling ── */
    .ts-b { color: var(--text-light); font-weight: 600; }
    .ts-accent { color: var(--accent); font-weight: 600; }
    .ts-rose { color: var(--accent-rose); font-weight: 600; }
    .ts-red { color: #f87171; font-weight: 600; }
    .ts-gold { color: var(--accent-gold); font-weight: 600; }

    /* ── Contact box ── */
    .ts-contact-box {
        background: linear-gradient(135deg, rgba(255,142,196,0.06), rgba(216,143,255,0.03));
        border: 1px solid rgba(255,142,196,0.12);
        border-radius: var(--radius-md);
        padding: 28px 32px;
        display: flex;
        align-items: center;
        gap: 20px;
        margin-top: 36px;
        transition: all 0.4s ease;
    }
    .ts-contact-box:hover {
        border-color: rgba(255,142,196,0.25);
        box-shadow: 0 8px 30px rgba(0,0,0,0.15), 0 0 20px rgba(255,142,196,0.05);
    }
    .ts-contact-icon {
        width: 56px; height: 56px; min-width: 56px;
        background: linear-gradient(135deg, var(--accent-rose), var(--accent));
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.3rem;
        color: white;
        box-shadow: 0 4px 20px rgba(255,142,196,0.3);
    }
    .ts-contact-info { flex: 1; }
    .ts-contact-title {
        font-weight: 700;
        color: var(--text-light);
        font-size: 0.95rem;
        margin-bottom: 4px;
    }
    .ts-contact-detail {
        font-size: 0.84rem;
        color: var(--text-muted);
        line-height: 1.6;
    }
    .ts-contact-detail a {
        color: var(--accent-rose);
        text-decoration: none;
        transition: color 0.3s;
    }
    .ts-contact-detail a:hover { color: var(--primary-light); text-decoration: underline; }

    .btn-contact {
        background: linear-gradient(135deg, var(--accent-rose), var(--accent));
        color: white;
        border: none;
        padding: 12px 28px;
        border-radius: 40px;
        font-weight: 600;
        font-size: 0.85rem;
        font-family: 'Poppins', sans-serif;
        transition: all 0.4s ease;
        cursor: pointer;
        box-shadow: 0 4px 20px rgba(255,142,196,0.3);
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        white-space: nowrap;
    }
    .btn-contact:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 32px rgba(255,142,196,0.45);
        color: white;
        text-decoration: none;
    }

    /* ── Divider ── */
    .ts-divider {
        height: 1px;
        margin: 32px 0;
        background: linear-gradient(90deg, transparent, var(--border-subtle), rgba(255,142,196,0.1), var(--border-subtle), transparent);
    }

    /* ── Sidebar TOC ── */
    .ts-toc {
        position: sticky;
        top: 100px;
    }
    .ts-toc-card {
        background: var(--bg-card);
        backdrop-filter: blur(16px);
        border: 1px solid var(--border-subtle);
        border-radius: var(--radius-lg);
        overflow: hidden;
        position: relative;
    }
    .ts-toc-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 2px;
        background: linear-gradient(90deg, transparent, var(--accent), var(--accent-rose), transparent);
        opacity: 0.5;
    }
    .ts-toc-hdr {
        padding: 20px 24px;
        border-bottom: 1px solid var(--border-subtle);
        font-weight: 700;
        color: var(--text-bright);
        font-size: 0.88rem;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .ts-toc-hdr i { color: var(--accent-rose); font-size: 0.8rem; }
    .ts-toc-list {
        list-style: none;
        padding: 12px;
        margin: 0;
    }
    .ts-toc-list li a {
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
    .ts-toc-list li a:hover,
    .ts-toc-list li a.active {
        color: var(--primary-light);
        background: rgba(244,209,255,0.06);
    }
    .ts-toc-list li a.active {
        color: var(--accent-rose);
        background: rgba(255,142,196,0.08);
        font-weight: 600;
    }
    .ts-toc-list li a i {
        font-size: 0.55rem;
        color: var(--accent-rose);
        opacity: 0.5;
        transition: opacity 0.3s;
    }
    .ts-toc-list li a:hover i,
    .ts-toc-list li a.active i { opacity: 1; }

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
        border-color: var(--accent-rose);
        color: var(--accent-rose);
        background: rgba(255,142,196,0.04);
        transform: translateX(-4px);
        text-decoration: none;
    }

    /* ── Responsive ── */
    @media (max-width: 991px) {
        .ts-toc { display: none; }
    }
    @media (max-width: 767px) {
        .ts-area { padding: 100px 0 60px; }
        .ts-card-hdr { padding: 22px 20px; }
        .ts-card-body { padding: 24px 20px; }
        .ts-contact-box {
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

<section class="ts-area">
    <div class="container">

        <!-- Breadcrumb & Title -->
        <div class="ts-breadcrumb" data-aos="fade-up">
            <a href="index.php"><i class="fa-solid fa-house" style="font-size:0.72rem;"></i> Home</a>
            <span class="sep">/</span>
            <span class="current">Terms of Service</span>
        </div>
        <h1 class="ts-page-title" data-aos="fade-up">Terms of <span>Service</span></h1>
        <p class="ts-page-sub" data-aos="fade-up">Please read these terms carefully before using our platform.</p>

        <div class="row g-4">
            <!-- SIDEBAR: Table of Contents -->
            <div class="col-lg-3 d-none d-lg-block" data-aos="fade-right" data-aos-delay="0">
                <div class="ts-toc">
                    <div class="ts-toc-card">
                        <div class="ts-toc-hdr">
                            <i class="fa-solid fa-list-ol"></i> Table of Contents
                        </div>
                        <ul class="ts-toc-list" id="tocList">
                            <li><a href="#ts-intro" class="active"><i class="fa-solid fa-circle"></i> Introduction</a></li>
                            <li><a href="#ts-accept"><i class="fa-solid fa-circle"></i> Acceptance of Terms</a></li>
                            <li><a href="#ts-purpose"><i class="fa-solid fa-circle"></i> Purpose of the System</a></li>
                            <li><a href="#ts-responsibilities"><i class="fa-solid fa-circle"></i> User Responsibilities</a></li>
                            <li><a href="#ts-data"><i class="fa-solid fa-circle"></i> Data and Privacy</a></li>
                            <li><a href="#ts-ai-disclaimer"><i class="fa-solid fa-circle"></i> AI Evaluation Disclaimer</a></li>
                            <li><a href="#ts-uploads"><i class="fa-solid fa-circle"></i> File Uploads</a></li>
                            <li><a href="#ts-usage"><i class="fa-solid fa-circle"></i> System Usage</a></li>
                            <li><a href="#ts-modifications"><i class="fa-solid fa-circle"></i> Modifications</a></li>
                            <li><a href="#ts-contact"><i class="fa-solid fa-circle"></i> Contact</a></li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- MAIN CONTENT -->
            <div class="col-lg-9" data-aos="fade-up" data-aos-delay="100">
                <div class="ts-card">
                    <!-- Header -->
                    <div class="ts-card-hdr">
                        <div class="ts-card-hdr-icon">
                            <i class="fa-solid fa-file-contract"></i>
                        </div>
                        <div>
                            <div class="ts-card-hdr-title">Terms of Service</div>
                            <div class="ts-card-hdr-sub">Rules and guidelines for using our platform</div>
                        </div>
                    </div>

                    <!-- Body -->
                    <div class="ts-card-body">

                        <div class="ts-updated">
                            <i class="fa-regular fa-calendar"></i>
                            Last Updated: January 1, 2025
                        </div>

                        <!-- ═══ 0. INTRODUCTION ═══ -->
                        <div class="ts-section" id="ts-intro">
                            <div class="ts-section-title">
                                <div class="ts-section-num"><i class="fa-solid fa-gavel" style="font-size:0.7rem;"></i></div>
                                Introduction
                            </div>
                            <p>
                                Welcome to our <span class="ts-accent">Assignment Submission and AI Evaluation System</span>. By accessing or using this system, you agree to comply with and be bound by the following Terms of Service. Please read them carefully.
                            </p>
                            <div class="ts-highlight">
                                <p>
                                    <i class="fa-solid fa-circle-info ts-accent" style="margin-right:6px;font-size:0.8rem;"></i>
                                    These terms constitute a legally binding agreement between you ("User") and the AI Assignment Checker platform ("System"). By proceeding to use any part of the system, you acknowledge that you have read and understood these terms in their entirety.
                                </p>
                            </div>
                        </div>

                        <div class="ts-divider"></div>

                        <!-- ═══ 1. ACCEPTANCE OF TERMS ═══ -->
                        <div class="ts-section" id="ts-accept">
                            <div class="ts-section-title">
                                <div class="ts-section-num">1</div>
                                Acceptance of Terms
                            </div>
                            <p>
                                By using this system, you agree that you have read, understood, and accepted these Terms of Service. If you do not agree, you should not use this system.
                            </p>
                            <p>
                                Your continued use of the platform following the posting of any changes to these terms constitutes acceptance of those changes. We encourage you to review these terms periodically to stay informed of any updates.
                            </p>
                            <div class="ts-highlight ts-highlight-gold">
                                <p>
                                    <i class="fa-solid fa-hand-point-right ts-gold" style="margin-right:6px;font-size:0.8rem;"></i>
                                    Creating an account, submitting an assignment, or accessing any feature of this system serves as explicit acceptance of these Terms of Service.
                                </p>
                            </div>
                        </div>

                        <div class="ts-divider"></div>

                        <!-- ═══ 2. PURPOSE OF THE SYSTEM ═══ -->
                        <div class="ts-section" id="ts-purpose">
                            <div class="ts-section-title">
                                <div class="ts-section-num">2</div>
                                Purpose of the System
                            </div>
                            <p>
                                This system is designed for <span class="ts-b">academic use only</span>. It allows students to submit assignments and receive automated AI-based analysis, including similarity checking, AI scoring, and feedback generation for educational improvement purposes.
                            </p>
                            <p class="ts-b" style="margin-top:18px;margin-bottom:12px;">The system provides the following core functionalities:</p>
                            <ul class="ts-list ts-list-check">
                                <li><i class="fa-solid fa-circle"></i> <span class="ts-b">AI Content Detection</span> — Identifies text that may have been generated by AI tools</li>
                                <li><i class="fa-solid fa-circle"></i> <span class="ts-b">Similarity Checking</span> — Compares submitted work against databases and online sources</li>
                                <li><i class="fa-solid fa-circle"></i> <span class="ts-b">Scoring & Reports</span> — Generates detailed analysis reports with actionable scores</li>
                                <li><i class="fa-solid fa-circle"></i> <span class="ts-b">Feedback Generation</span> — Provides improvement suggestions based on analysis results</li>
                            </ul>
                            <div class="ts-highlight ts-highlight-accent">
                                <p>
                                    <i class="fa-solid fa-graduation-cap ts-accent" style="margin-right:6px;font-size:0.8rem;"></i>
                                    This system is intended as an <span class="ts-b">educational tool</span> to help students improve the originality and quality of their work before formal submission.
                                </p>
                            </div>
                        </div>

                        <div class="ts-divider"></div>

                        <!-- ═══ 3. USER RESPONSIBILITIES ═══ -->
                        <div class="ts-section" id="ts-responsibilities">
                            <div class="ts-section-title">
                                <div class="ts-section-num">3</div>
                                User Responsibilities
                            </div>
                            <p>
                                Users are responsible for ensuring that all submitted content is original and does not violate academic integrity policies. Any form of plagiarism, cheating, or misuse of the system is <span class="ts-rose">strictly discouraged</span>.
                            </p>
                            <p class="ts-b" style="margin-top:18px;margin-bottom:12px;">As a user, you are expected to:</p>
                            <ul class="ts-list">
                                <li><i class="fa-solid fa-circle"></i> Submit only your own original work for analysis</li>
                                <li><i class="fa-solid fa-circle"></i> Provide accurate information during registration and account setup</li>
                                <li><i class="fa-solid fa-circle"></i> Keep your account credentials secure and confidential at all times</li>
                                <li><i class="fa-solid fa-circle"></i> Not attempt to bypass, disable, or interfere with the system's analysis mechanisms</li>
                                <li><i class="fa-solid fa-circle"></i> Not use the system to evaluate content that is illegal, harmful, or violates any third-party rights</li>
                                <li><i class="fa-solid fa-circle"></i> Report any bugs, vulnerabilities, or issues to the system administrators</li>
                            </ul>
                            <div class="ts-highlight ts-highlight-red">
                                <p>
                                    <i class="fa-solid fa-ban ts-red" style="margin-right:6px;font-size:0.8rem;"></i>
                                    <span class="ts-red">Warning:</span> Violation of academic integrity policies may result in immediate account suspension, removal of submitted content, and reporting to the relevant academic institution.
                                </p>
                            </div>
                        </div>

                        <div class="ts-divider"></div>

                        <!-- ═══ 4. DATA AND PRIVACY ═══ -->
                        <div class="ts-section" id="ts-data">
                            <div class="ts-section-title">
                                <div class="ts-section-num">4</div>
                                Data and Privacy
                            </div>
                            <p>
                                All submitted assignments and related data will be stored securely in the database. The system may generate reports and feedback based on submitted content. Personal data will <span class="ts-b">not be shared with third parties</span> without permission.
                            </p>
                            <p class="ts-b" style="margin-top:18px;margin-bottom:12px;">How we handle your data:</p>
                            <ul class="ts-list ts-list-check">
                                <li><i class="fa-solid fa-circle"></i> Assignment files are stored in a secure directory with restricted access</li>
                                <li><i class="fa-solid fa-circle"></i> Analysis results (scores, reports) are linked to your user account and protected by authentication</li>
                                <li><i class="fa-solid fa-circle"></i> Passwords are encrypted using industry-standard hashing algorithms (bcrypt)</li>
                                <li><i class="fa-solid fa-circle"></i> All database queries use prepared statements to prevent unauthorized data access</li>
                                <li><i class="fa-solid fa-circle"></i> You retain full ownership of all content you submit to the platform</li>
                            </ul>
                            <p style="margin-top:16px;">
                                For a comprehensive overview of our data practices, please refer to our <a href="privacypolicy.php" style="color:var(--accent);text-decoration:none;font-weight:600;transition:color 0.3s;" onmouseover="this.style.color='var(--primary-light)'" onmouseout="this.style.color='var(--accent)'">Privacy Policy</a>.
                            </p>
                        </div>

                        <div class="ts-divider"></div>

                        <!-- ═══ 5. AI EVALUATION DISCLAIMER ═══ -->
                        <div class="ts-section" id="ts-ai-disclaimer">
                            <div class="ts-section-title">
                                <div class="ts-section-num">5</div>
                                AI Evaluation Disclaimer
                            </div>
                            <p>
                                The AI-generated scores and suggestions are <span class="ts-rose">for reference only</span>. They should not be considered as final academic grading. Final evaluation decisions are made by instructors or administrators.
                            </p>
                            <div class="ts-highlight ts-highlight-gold">
                                <p>
                                    <i class="fa-solid fa-triangle-exclamation ts-gold" style="margin-right:6px;font-size:0.8rem;"></i>
                                    <span class="ts-gold">Important:</span> AI detection technology, while advanced, is not infallible. False positives and false negatives may occur. Results should be interpreted as indicators, not definitive judgments.
                                </p>
                            </div>
                            <p style="margin-top:16px;">
                                The system's analysis is based on pattern recognition and statistical models. It does not:
                            </p>
                            <ul class="ts-list">
                                <li><i class="fa-solid fa-circle"></i> Replace human judgment or professional academic evaluation</li>
                                <li><i class="fa-solid fa-circle"></i> Guarantee 100% accuracy in detecting AI-generated or plagiarized content</li>
                                <li><i class="fa-solid fa-circle"></i> Serve as the sole basis for academic disciplinary actions</li>
                                <li><i class="fa-solid fa-circle"></i> Account for all forms of AI assistance or paraphrasing techniques</li>
                            </ul>
                        </div>

                        <div class="ts-divider"></div>

                        <!-- ═══ 6. FILE UPLOADS ═══ -->
                        <div class="ts-section" id="ts-uploads">
                            <div class="ts-section-title">
                                <div class="ts-section-num">6</div>
                                File Uploads
                            </div>
                            <p>
                                Users are responsible for ensuring that uploaded files are safe, appropriate, and free from viruses or malicious content. The system is <span class="ts-red">not responsible</span> for damage caused by improper uploads.
                            </p>
                            <p class="ts-b" style="margin-top:18px;margin-bottom:12px;">Upload guidelines:</p>
                            <ul class="ts-list">
                                <li><i class="fa-solid fa-circle"></i> Only upload files in supported formats (PDF, DOCX, TXT, etc.)</li>
                                <li><i class="fa-solid fa-circle"></i> Ensure files do not exceed the maximum allowed file size</li>
                                <li><i class="fa-solid fa-circle"></i> Files must not contain executable code, scripts, or malware</li>
                                <li><i class="fa-solid fa-circle"></i> Do not upload files that infringe on copyrights or intellectual property rights</li>
                                <li><i class="fa-solid fa-circle"></i> The system reserves the right to quarantine or reject suspicious files without notice</li>
                            </ul>
                            <div class="ts-highlight ts-highlight-red">
                                <p>
                                    <i class="fa-solid fa-shield-virus ts-red" style="margin-right:6px;font-size:0.8rem;"></i>
                                    <span class="ts-red">Liability Notice:</span> Users bear full responsibility for the content of their uploads. The system administrators are not liable for any data loss, corruption, or system issues arising from malicious or improper file uploads.
                                </p>
                            </div>
                        </div>

                        <div class="ts-divider"></div>

                        <!-- ═══ 7. SYSTEM USAGE ═══ -->
                        <div class="ts-section" id="ts-usage">
                            <div class="ts-section-title">
                                <div class="ts-section-num">7</div>
                                System Usage
                            </div>
                            <p>
                                Misuse of the system, including attempts to hack, overload, or manipulate results, may result in access being <span class="ts-red">restricted or revoked</span>.
                            </p>
                            <p class="ts-b" style="margin-top:18px;margin-bottom:12px;">The following activities are strictly prohibited:</p>
                            <ul class="ts-list">
                                <li><i class="fa-solid fa-circle"></i> Attempting to gain unauthorized access to other users' accounts or data</li>
                                <li><i class="fa-solid fa-circle"></i> Submitting automated or scripted requests to overwhelm the system (DDoS, brute force, etc.)</li>
                                <li><i class="fa-solid fa-circle"></i> Manipulating input content specifically to trick or bypass AI detection algorithms</li>
                                <li><i class="fa-solid fa-circle"></i> Reverse-engineering, decompiling, or extracting any part of the system's source code</li>
                                <li><i class="fa-solid fa-circle"></i> Using the system for any commercial purpose without explicit authorization</li>
                                <li><i class="fa-solid fa-circle"></i> Sharing account credentials or allowing third-party access to your account</li>
                            </ul>
                            <div class="ts-highlight">
                                <p>
                                    <i class="fa-solid fa-user-lock ts-accent" style="margin-right:6px;font-size:0.8rem;"></i>
                                    The system monitors usage patterns for suspicious activity. Any detected abuse will be investigated and may result in immediate account termination and, where applicable, legal action.
                                </p>
                            </div>
                        </div>

                        <div class="ts-divider"></div>

                        <!-- ═══ 8. MODIFICATIONS ═══ -->
                        <div class="ts-section" id="ts-modifications">
                            <div class="ts-section-title">
                                <div class="ts-section-num">8</div>
                                Modifications
                            </div>
                            <p>
                                The system administrators reserve the right to update or modify these Terms of Service at any time without prior notice.
                            </p>
                            <p>
                                Changes may include, but are not limited to:
                            </p>
                            <ul class="ts-list ts-list-check">
                                <li><i class="fa-solid fa-circle"></i> Adding, modifying, or removing features and services</li>
                                <li><i class="fa-solid fa-circle"></i> Adjusting usage limits, quotas, or pricing (if applicable)</li>
                                <li><i class="fa-solid fa-circle"></i> Updating data handling and privacy practices</li>
                                <li><i class="fa-solid fa-circle"></i> Revising acceptable use policies and guidelines</li>
                            </ul>
                            <div class="ts-highlight ts-highlight-accent">
                                <p>
                                    <i class="fa-solid fa-bell ts-accent" style="margin-right:6px;font-size:0.8rem;"></i>
                                    We will make reasonable efforts to notify users of significant changes via email or platform notifications. However, the absence of notification does not invalidate any updates to these terms.
                                </p>
                            </div>
                        </div>

                        <div class="ts-divider"></div>

                        <!-- ═══ 9. CONTACT ═══ -->
                        <div class="ts-section" id="ts-contact">
                            <div class="ts-section-title">
                                <div class="ts-section-num">9</div>
                                Contact
                            </div>
                            <p>
                                For any questions or issues regarding these Terms of Service, users may contact the system administrator. We are committed to addressing your concerns promptly and transparently.
                            </p>

                            <div class="ts-contact-box">
                                <div class="ts-contact-icon">
                                    <i class="fa-solid fa-headset"></i>
                                </div>
                                <div class="ts-contact-info">
                                    <div class="ts-contact-title">System Administration</div>
                                    <div class="ts-contact-detail">
                                        Email us at <a href="mailto:admin@aichecker.com">admin@aichecker.com</a><br>
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
    var tsSections = document.querySelectorAll('.ts-section');
    var observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });

    tsSections.forEach(function(sec) {
        sec.style.opacity = '0';
        sec.style.transform = 'translateY(20px)';
        sec.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(sec);
    });

});
</script>