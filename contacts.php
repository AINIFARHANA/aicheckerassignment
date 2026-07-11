<?php
error_reporting(E_ALL);
// Jangan display PHP warning/error dalam AJAX response sebab ia akan rosakkan JSON.
ini_set('display_errors', 0);

$servername = "localhost";
$username   = "root";
$password   = "";              
$dbname     = "assignment_db";  // Your local database name

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

function contact_json_response($data) {
    if (ob_get_length()) {
        ob_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// IMPORTANT: Proses AJAX POST sebelum include header.php.
// Kalau header.php keluar HTML dahulu, fetch() tidak boleh parse JSON dan akan tunjuk "Server error occurred".
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    if ($conn->connect_error) {
        contact_json_response(['success' => false, 'errors' => ['Database connection failed.']]);
    }

    $name    = trim($_POST['name'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    $errors = [];

    if ($name === '' || strlen($name) < 2) {
        $errors[] = 'Please enter your name (at least 2 characters).';
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($subject === '' || strlen($subject) < 3) {
        $errors[] = 'Please enter a subject (at least 3 characters).';
    }
    if ($message === '' || strlen($message) < 10) {
        $errors[] = 'Please enter a message (at least 10 characters).';
    }

    if (!empty($errors)) {
        contact_json_response(['success' => false, 'errors' => $errors]);
    }

    $fullMessage = "Subject: {$subject}\n\n{$message}";

    $stmt = $conn->prepare("INSERT INTO contacts (name, email, message, created_at) VALUES (?, ?, ?, NOW())");
    if (!$stmt) {
        contact_json_response(['success' => false, 'errors' => ['Database query error. Please check contacts table.']]);
    }

    $stmt->bind_param("sss", $name, $email, $fullMessage);
    $ok = $stmt->execute();
    $stmt->close();

    if ($ok) {
        contact_json_response(['success' => true]);
    }

    contact_json_response(['success' => false, 'errors' => ['Database error. Please try again.']]);
}

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

require_once 'header.php';
?>

<style>
    .contact-page { padding: 120px 0 80px; min-height: 100vh; }
    .contact-hero { text-align: center; margin-bottom: 64px; }
    .contact-hero-badge {
        display: inline-flex; align-items: center; gap: 8px;
        background: rgba(216,143,255,0.08); border: 1px solid rgba(216,143,255,0.15);
        border-radius: 40px; padding: 8px 20px; font-size: 0.78rem;
        font-weight: 500; color: var(--accent); margin-bottom: 24px; letter-spacing: 0.04em;
    }
    .contact-hero-badge i { font-size: 0.7rem; }
    .contact-hero h1 {
        font-weight: 900; color: var(--text-bright);
        font-size: clamp(2rem, 4.5vw, 3.2rem);
        letter-spacing: -0.04em; line-height: 1.1; margin-bottom: 16px;
    }
    .contact-hero h1 span {
        background: linear-gradient(135deg, var(--accent), var(--accent-rose));
        -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
    }
    .contact-hero p {
        color: var(--text-muted); font-size: 1.05rem;
        max-width: 540px; margin: 0 auto; line-height: 1.7;
    }
    .contact-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; align-items: start; }
    .contact-info-col { display: flex; flex-direction: column; gap: 20px; }
    .contact-info-card {
        background: var(--bg-card); backdrop-filter: blur(16px);
        border: 1px solid var(--border-subtle); border-radius: var(--radius-lg);
        padding: 28px 24px; display: flex; align-items: flex-start; gap: 18px;
        transition: all 0.4s ease; position: relative; overflow: hidden;
    }
    .contact-info-card::before {
        content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
        opacity: 0; transition: opacity 0.4s ease;
        background: linear-gradient(90deg, transparent, var(--accent), transparent);
    }
    .contact-info-card:hover::before { opacity: 1; }
    .contact-info-card:hover {
        transform: translateY(-4px); border-color: var(--border-glow);
        box-shadow: var(--shadow-md), 0 0 24px rgba(244,209,255,0.04);
    }
    .contact-info-icon {
        width: 52px; height: 52px; min-width: 52px; border-radius: 14px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.15rem; transition: all 0.4s ease;
    }
    .contact-info-card:nth-child(1) .contact-info-icon {
        background: rgba(216,143,255,0.1); color: var(--accent); border: 1px solid rgba(216,143,255,0.15);
    }
    .contact-info-card:nth-child(2) .contact-info-icon {
        background: rgba(255,142,196,0.1); color: var(--accent-rose); border: 1px solid rgba(255,142,196,0.15);
    }
    .contact-info-card:nth-child(3) .contact-info-icon {
        background: rgba(96,165,250,0.1); color: #60a5fa; border: 1px solid rgba(96,165,250,0.15);
    }
    .contact-info-card:hover .contact-info-icon { transform: scale(1.1) rotate(-5deg); }
    .contact-info-label {
        font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.1em;
        color: var(--text-muted); font-weight: 600; margin-bottom: 4px;
    }
    .contact-info-value { font-size: 0.95rem; color: var(--text-light); font-weight: 500; }
    .contact-info-value a { color: var(--text-light); text-decoration: none; transition: color 0.3s ease; }
    .contact-info-value a:hover { color: var(--accent); }
    .response-banner {
        background: linear-gradient(135deg, rgba(216,143,255,0.08), rgba(255,142,196,0.08));
        border: 1px solid rgba(216,143,255,0.12); border-radius: var(--radius-lg);
        padding: 24px; text-align: center;
    }
    .response-banner .rb-icon { font-size: 1.6rem; margin-bottom: 10px; display: block; color: var(--accent); }
    .response-banner h6 { font-weight: 700; color: var(--text-bright); font-size: 0.92rem; margin-bottom: 4px; }
    .response-banner p { color: var(--text-muted); font-size: 0.82rem; margin: 0; }
    .contact-form-card {
        background: var(--bg-card); backdrop-filter: blur(16px);
        border: 1px solid var(--border-subtle); border-radius: var(--radius-lg);
        padding: 36px 32px; box-shadow: var(--shadow-sm); position: relative; overflow: hidden;
    }
    .contact-form-card::before {
        content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
        background: linear-gradient(90deg, var(--accent), var(--accent-rose), var(--accent));
    }
    .contact-form-card h3 { font-weight: 800; color: var(--text-bright); font-size: 1.25rem; margin-bottom: 4px; }
    .contact-form-card .form-subtitle { color: var(--text-muted); font-size: 0.85rem; margin-bottom: 28px; }
    .cf-group { margin-bottom: 20px; }
    .cf-label {
        display: block; font-size: 0.78rem; font-weight: 600;
        color: var(--text-light); margin-bottom: 8px; letter-spacing: 0.02em;
    }
    .cf-label .required { color: var(--accent-rose); margin-left: 2px; }
    .cf-input {
        width: 100%; background: rgba(244,209,255,0.03);
        border: 1.5px solid var(--border-subtle); border-radius: 12px;
        padding: 13px 16px; color: var(--text-bright); font-size: 0.9rem;
        font-family: 'Poppins', sans-serif; outline: none; transition: all 0.3s ease;
    }
    .cf-input::placeholder { color: var(--text-muted); opacity: 0.6; }
    .cf-input:focus {
        border-color: var(--accent); box-shadow: 0 0 0 3px rgba(216,143,255,0.1);
        background: rgba(244,209,255,0.05);
    }
    .cf-input.error { border-color: rgba(239,68,68,0.5); box-shadow: 0 0 0 3px rgba(239,68,68,0.08); }
    textarea.cf-input { resize: vertical; min-height: 130px; line-height: 1.7; }
    .cf-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .cf-error-msg {
        font-size: 0.75rem; color: #f87171; margin-top: 6px;
        display: none; align-items: center; gap: 5px;
    }
    .cf-error-msg.show { display: flex; }
    .cf-error-msg i { font-size: 0.7rem; }
    .cf-char-count { font-size: 0.72rem; color: var(--text-muted); text-align: right; margin-top: 4px; opacity: 0.6; }
    .cf-submit {
        width: 100%; padding: 15px 28px; border: none; border-radius: 14px;
        background: linear-gradient(135deg, var(--accent), var(--accent-rose));
        color: white; font-family: 'Poppins', sans-serif; font-size: 0.95rem;
        font-weight: 700; cursor: pointer; transition: all 0.4s ease;
        display: flex; align-items: center; justify-content: center; gap: 10px;
        position: relative; overflow: hidden; margin-top: 8px;
    }
    .cf-submit::before {
        content: ''; position: absolute; top: 0; left: -100%; right: 0; bottom: 0; width: 200%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent);
        transition: left 0.6s ease;
    }
    .cf-submit:hover::before { left: 100%; }
    .cf-submit:hover { transform: translateY(-3px); box-shadow: 0 8px 32px rgba(216,143,255,0.35); }
    .cf-submit:active { transform: translateY(-1px); }
    .cf-submit:disabled { opacity: 0.6; cursor: not-allowed; transform: none !important; box-shadow: none !important; }
    .cf-submit .spinner {
        display: none; width: 20px; height: 20px;
        border: 2.5px solid rgba(255,255,255,0.3); border-top-color: white;
        border-radius: 50%; animation: cfSpin 0.7s linear infinite;
    }
    .cf-submit.loading .spinner { display: block; }
    .cf-submit.loading .btn-text { display: none; }
    @keyframes cfSpin { to { transform: rotate(360deg); } }
    .section-divider { margin: 80px 0 56px; display: flex; align-items: center; gap: 20px; }
    .section-divider::before, .section-divider::after {
        content: ''; flex: 1; height: 1px;
        background: linear-gradient(90deg, transparent, var(--border-subtle), transparent);
    }
    .section-divider span {
        font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.15em;
        color: var(--text-muted); font-weight: 600; white-space: nowrap;
    }
    .faq-section h2 {
        text-align: center; font-weight: 900; color: var(--text-bright);
        font-size: clamp(1.5rem, 3vw, 2rem); letter-spacing: -0.03em; margin-bottom: 8px;
    }
    .faq-section .faq-subtitle { text-align: center; color: var(--text-muted); font-size: 0.92rem; margin-bottom: 40px; }
    .faq-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; max-width: 960px; margin: 0 auto; }
    .faq-item {
        background: var(--bg-card); backdrop-filter: blur(16px);
        border: 1px solid var(--border-subtle); border-radius: var(--radius-md);
        overflow: hidden; transition: all 0.3s ease;
    }
    .faq-item:hover { border-color: var(--border-glow); }
    .faq-item.active { border-color: rgba(216,143,255,0.25); box-shadow: 0 0 20px rgba(244,209,255,0.04); }
    .faq-question {
        padding: 20px 22px; display: flex; align-items: center;
        justify-content: space-between; gap: 14px; cursor: pointer;
        user-select: none; transition: background 0.3s ease;
    }
    .faq-question:hover { background: rgba(244,209,255,0.03); }
    .faq-question-text { font-size: 0.88rem; font-weight: 600; color: var(--text-light); line-height: 1.5; }
    .faq-toggle {
        width: 30px; height: 30px; min-width: 30px; border-radius: 8px;
        background: rgba(216,143,255,0.08); border: 1px solid rgba(216,143,255,0.12);
        display: flex; align-items: center; justify-content: center;
        color: var(--accent); font-size: 0.72rem; transition: all 0.4s ease;
    }
    .faq-item.active .faq-toggle { background: rgba(216,143,255,0.15); transform: rotate(180deg); }
    .faq-answer { max-height: 0; overflow: hidden; transition: max-height 0.4s ease, padding 0.3s ease; }
    .faq-item.active .faq-answer { max-height: 300px; }
    .faq-answer-inner { padding: 0 22px 20px; font-size: 0.84rem; color: var(--text-muted); line-height: 1.8; }
    .success-modal .modal-content {
        background: rgba(21,10,30,0.97); backdrop-filter: blur(24px);
        border: 1px solid rgba(16,185,129,0.25); border-radius: 20px;
        box-shadow: var(--shadow-lg), 0 0 60px rgba(16,185,129,0.06);
        text-align: center; overflow: hidden;
    }
    .success-modal .modal-body { padding: 48px 36px 36px; }
    .success-check-circle {
        width: 80px; height: 80px; border-radius: 50%;
        background: rgba(16,185,129,0.1); border: 2px solid rgba(16,185,129,0.25);
        display: flex; align-items: center; justify-content: center;
        margin: 0 auto 24px; position: relative;
    }
    .success-check-circle i { font-size: 2rem; color: #10b981; }
    .success-check-circle::after {
        content: ''; position: absolute; inset: -6px; border-radius: 50%;
        border: 1px solid rgba(16,185,129,0.1); animation: successPulse 2s ease-in-out infinite;
    }
    @keyframes successPulse { 0%, 100% { transform: scale(1); opacity: 1; } 50% { transform: scale(1.15); opacity: 0; } }
    .success-modal h4 { font-weight: 800; color: var(--text-bright); font-size: 1.35rem; margin-bottom: 10px; }
    .success-modal p { color: var(--text-muted); font-size: 0.92rem; line-height: 1.7; margin-bottom: 28px; }
    .success-modal .btn-success-close {
        background: linear-gradient(135deg, #10b981, #059669); color: white; border: none;
        padding: 13px 40px; border-radius: 14px; font-weight: 700; font-size: 0.9rem;
        cursor: pointer; font-family: 'Poppins', sans-serif; transition: all 0.3s ease;
    }
    .success-modal .btn-success-close:hover {
        transform: translateY(-2px); box-shadow: 0 8px 28px rgba(16,185,129,0.3); color: white;
    }
    .success-confetti {
        position: absolute; top: 0; left: 0; right: 0; height: 4px;
        background: linear-gradient(90deg, #10b981, var(--accent), var(--accent-rose), #10b981);
        background-size: 200% 100%; animation: confettiSlide 3s linear infinite;
    }
    @keyframes confettiSlide { to { background-position: -200% 0; } }
    @media (max-width: 991px) { .contact-grid { grid-template-columns: 1fr; } .faq-grid { grid-template-columns: 1fr; } }
    @media (max-width: 767px) {
        .contact-page { padding: 110px 0 50px; }
        .contact-hero { margin-bottom: 40px; }
        .cf-row { grid-template-columns: 1fr; }
        .contact-form-card { padding: 28px 20px; }
        .success-modal .modal-body { padding: 36px 24px 28px; }
    }
    @media (prefers-reduced-motion: reduce) {
        .success-check-circle::after, .success-confetti { animation: none; }
        .cf-submit::before { display: none; }
    }
</style>

<section class="contact-page">
    <div class="container">
        <div class="contact-hero" data-aos="fade-up">
            <div class="contact-hero-badge">
                <i class="fa-solid fa-sparkles"></i>
                We'd Love to Hear From You
            </div>
            <h1>Got a Question?<br><span>Let's Talk.</span></h1>
            <p>Whether you need help, have feedback, or just want to say hello — our team is ready to help you out.</p>
        </div>

        <div class="contact-grid">
            <div class="contact-info-col" data-aos="fade-up" data-aos-delay="0">
                <div class="contact-info-card">
                    <div class="contact-info-icon"><i class="fa-solid fa-envelope"></i></div>
                    <div>
                        <div class="contact-info-label">Email Us</div>
                        <div class="contact-info-value"><a href="mailto:support@aichecker.com">support@aichecker.com</a></div>
                    </div>
                </div>
                <div class="contact-info-card">
                    <div class="contact-info-icon"><i class="fa-solid fa-clock"></i></div>
                    <div>
                        <div class="contact-info-label">Working Hours</div>
                        <div class="contact-info-value">Mon – Fri, 9:00 AM – 6:00 PM</div>
                    </div>
                </div>
                <div class="contact-info-card">
                    <div class="contact-info-icon"><i class="fa-solid fa-location-dot"></i></div>
                    <div>
                        <div class="contact-info-label">Location</div>
                        <div class="contact-info-value">Remote Team — Worldwide</div>
                    </div>
                </div>
                <div class="response-banner" data-aos="fade-up" data-aos-delay="100">
                    <span class="rb-icon"><i class="fa-solid fa-bolt"></i></span>
                    <h6>Average Response Time</h6>
                    <p>We typically reply within 2–4 hours during business days.</p>
                </div>
            </div>

            <div class="contact-form-card" data-aos="fade-up" data-aos-delay="100">
                <h3>Send a Message</h3>
                <p class="form-subtitle">Fill out the form below and we'll get back to you soon.</p>
                <form id="contactForm" novalidate>
                    <div class="cf-row">
                        <div class="cf-group">
                            <label class="cf-label">Your Name <span class="required">*</span></label>
                            <input type="text" class="cf-input" id="cfName" name="name" placeholder="John Doe" autocomplete="name">
                            <div class="cf-error-msg" id="cfNameError"><i class="fa-solid fa-circle-exclamation"></i><span></span></div>
                        </div>
                        <div class="cf-group">
                            <label class="cf-label">Email Address <span class="required">*</span></label>
                            <input type="email" class="cf-input" id="cfEmail" name="email" placeholder="john@example.com" autocomplete="email">
                            <div class="cf-error-msg" id="cfEmailError"><i class="fa-solid fa-circle-exclamation"></i><span></span></div>
                        </div>
                    </div>
                    <div class="cf-group">
                        <label class="cf-label">Subject <span class="required">*</span></label>
                        <input type="text" class="cf-input" id="cfSubject" name="subject" placeholder="What's this about?">
                        <div class="cf-error-msg" id="cfSubjectError"><i class="fa-solid fa-circle-exclamation"></i><span></span></div>
                    </div>
                    <div class="cf-group">
                        <label class="cf-label">Message <span class="required">*</span></label>
                        <textarea class="cf-input" id="cfMessage" name="message" placeholder="Tell us more about your question or issue..." maxlength="2000"></textarea>
                        <div class="cf-char-count"><span id="cfCharCount">0</span> / 2000</div>
                        <div class="cf-error-msg" id="cfMessageError"><i class="fa-solid fa-circle-exclamation"></i><span></span></div>
                    </div>
                    <button type="submit" class="cf-submit" id="cfSubmitBtn">
                        <span class="btn-text"><i class="fa-solid fa-paper-plane"></i> Send Message</span>
                        <div class="spinner"></div>
                    </button>
                </form>
            </div>
        </div>

        <div class="section-divider" data-aos="fade-up">
            <span> Frequently Asked Questions </span>
        </div>

        <div class="faq-section" data-aos="fade-up" data-aos-delay="0">
            <h2>Common Questions</h2>
            <p class="faq-subtitle">Quick answers to things people ask us the most.</p>
            <div class="faq-grid">
                <div class="faq-item">
                    <div class="faq-question" onclick="toggleFaq(this)">
                        <span class="faq-question-text">What is AI Checker and how does it work?</span>
                        <span class="faq-toggle"><i class="fa-solid fa-chevron-down"></i></span>
                    </div>
                    <div class="faq-answer"><div class="faq-answer-inner">AI Checker analyzes your text using advanced AI detection models to determine whether the content was written by a human or generated by AI tools like ChatGPT, Claude, or Gemini. It provides a detailed probability score for each result.</div></div>
                </div>
                <div class="faq-item">
                    <div class="faq-question" onclick="toggleFaq(this)">
                        <span class="faq-question-text">Is AI Checker free to use?</span>
                        <span class="faq-toggle"><i class="fa-solid fa-chevron-down"></i></span>
                    </div>
                    <div class="faq-answer"><div class="faq-answer-inner">Yes! We offer a free tier that allows you to check a certain number of words per day. For unlimited access and premium features like batch checking and API access, you can upgrade to our Pro plan.</div></div>
                </div>
                <div class="faq-item">
                    <div class="faq-question" onclick="toggleFaq(this)">
                        <span class="faq-question-text">How accurate is the AI detection?</span>
                        <span class="faq-toggle"><i class="fa-solid fa-chevron-down"></i></span>
                    </div>
                    <div class="faq-answer"><div class="faq-answer-inner">Our detection models are continuously trained and updated to achieve high accuracy rates. While no tool is 100% perfect, our results are among the most reliable in the industry, with accuracy typically above 95% on standard texts.</div></div>
                </div>
                <div class="faq-item">
                    <div class="faq-question" onclick="toggleFaq(this)">
                        <span class="faq-question-text">Can I check multiple documents at once?</span>
                        <span class="faq-toggle"><i class="fa-solid fa-chevron-down"></i></span>
                    </div>
                    <div class="faq-answer"><div class="faq-answer-inner">Batch checking is available on our Pro plan. You can upload multiple files or paste several texts at once, and we'll process them all together with individual reports for each submission.</div></div>
                </div>
                <div class="faq-item">
                    <div class="faq-question" onclick="toggleFaq(this)">
                        <span class="faq-question-text">Is my submitted text stored or shared?</span>
                        <span class="faq-toggle"><i class="fa-solid fa-chevron-down"></i></span>
                    </div>
                    <div class="faq-answer"><div class="faq-answer-inner">Absolutely not. Your privacy is our top priority. All text submissions are processed in real-time and are never stored on our servers. We do not share, sell, or use your content for any purpose beyond providing the detection result.</div></div>
                </div>
                <div class="faq-item">
                    <div class="faq-question" onclick="toggleFaq(this)">
                        <span class="faq-question-text">Do you offer an API for developers?</span>
                        <span class="faq-toggle"><i class="fa-solid fa-chevron-down"></i></span>
                    </div>
                    <div class="faq-answer"><div class="faq-answer-inner">Yes, we provide a RESTful API that developers can integrate into their own applications. The API supports all detection features and comes with detailed documentation, code examples, and dedicated technical support.</div></div>
                </div>
            </div>
        </div>
    </div>
</section>

<div class="modal fade success-modal" id="successModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="success-confetti"></div>
            <div class="modal-body">
                <div class="success-check-circle"><i class="fa-solid fa-check"></i></div>
                <h4>Message Sent!</h4>
                <p>Thank you for reaching out. We've received your message and will get back to you within 2–4 hours.</p>
                <button class="btn-success-close" data-bs-dismiss="modal">Got It</button>
            </div>
        </div>
    </div>
</div>

<div style="height:40px;"></div>

<?php include 'footer.php'; ?>

<script>
(function () {
    var form = document.getElementById('contactForm');
    var submitBtn = document.getElementById('cfSubmitBtn');
    var successModal = new bootstrap.Modal(document.getElementById('successModal'));
    var messageTextarea = document.getElementById('cfMessage');
    var charCount = document.getElementById('cfCharCount');

    messageTextarea.addEventListener('input', function () {
        charCount.textContent = this.value.length;
    });

    var inputs = form.querySelectorAll('.cf-input');
    inputs.forEach(function (input) {
        input.addEventListener('input', function () {
            this.classList.remove('error');
            var errorEl = document.getElementById(this.id + 'Error');
            if (errorEl) errorEl.classList.remove('show');
        });
    });

    function showFieldError(fieldId, message) {
        var field = document.getElementById(fieldId);
        var errorEl = document.getElementById(fieldId + 'Error');
        if (field) field.classList.add('error');
        if (errorEl) {
            errorEl.querySelector('span').textContent = message;
            errorEl.classList.add('show');
        }
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();

        inputs.forEach(function (input) { input.classList.remove('error'); });
        form.querySelectorAll('.cf-error-msg').forEach(function (el) { el.classList.remove('show'); });

        var name    = document.getElementById('cfName').value.trim();
        var email   = document.getElementById('cfEmail').value.trim();
        var subject = document.getElementById('cfSubject').value.trim();
        var message = document.getElementById('cfMessage').value.trim();
        var hasError = false;

        if (name.length < 2) { showFieldError('cfName', 'Please enter your name.'); hasError = true; }
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { showFieldError('cfEmail', 'Please enter a valid email.'); hasError = true; }
        if (subject.length < 3) { showFieldError('cfSubject', 'Please enter a subject.'); hasError = true; }
        if (message.length < 10) { showFieldError('cfMessage', 'Message must be at least 10 characters.'); hasError = true; }
        if (hasError) return;

        submitBtn.classList.add('loading');
        submitBtn.disabled = true;

        var formData = new FormData();
        formData.append('action', 'send_message');
        formData.append('name', name);
        formData.append('email', email);
        formData.append('subject', subject);
        formData.append('message', message);

        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(function (response) {
            return response.text().then(function (text) {
                try { return JSON.parse(text); }
                catch (e) { return { success: false, errors: ['Server error occurred.'] }; }
            });
        })
        .then(function (data) {
            submitBtn.classList.remove('loading');
            submitBtn.disabled = false;
            if (data.success) {
                form.reset();
                charCount.textContent = '0';
                successModal.show();
            } else if (data.errors && data.errors.length > 0) {
                data.errors.forEach(function (err) {
                    var lower = err.toLowerCase();
                    if (lower.includes('name')) showFieldError('cfName', err);
                    else if (lower.includes('email')) showFieldError('cfEmail', err);
                    else if (lower.includes('subject')) showFieldError('cfSubject', err);
                    else showFieldError('cfMessage', err);
                });
            }
        })
        .catch(function () {
            submitBtn.classList.remove('loading');
            submitBtn.disabled = false;
            showFieldError('cfMessage', 'Network error. Please try again.');
        });
    });
})();

function toggleFaq(el) {
    var item = el.closest('.faq-item');
    var wasActive = item.classList.contains('active');
    document.querySelectorAll('.faq-item.active').forEach(function (fi) { fi.classList.remove('active'); });
    if (!wasActive) item.classList.add('active');
}
</script>