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

 $error_message = "";
 $success_message = "";
 $login_success = false;
 $redirect_page = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);

    if (empty($email) || empty($password)) {
        $error_message = "Please fill in both email and password.";
    } else {
        $stmt = $conn->prepare("SELECT user_id, name, email, password, user_type, avatar FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $row = $result->fetch_assoc();
            
            if (password_verify($password, $row['password'])) {
                $_SESSION['user_id'] = $row['user_id'];
                $_SESSION['name'] = $row['name'];
                $_SESSION['email'] = $row['email'];
                $_SESSION['user_type'] = $row['user_type'];
                $_SESSION['avatar'] = $row['avatar'];

                $login_success = true;
                if ($row['user_type'] == 'admin') {
                    $redirect_page = "adminpage.php";
                } else {
                    $redirect_page = "index.php";
                }
            } else {
                $error_message = "Invalid email or password.";
            }
        } else {
            $error_message = "Invalid email or password.";
        }
        $stmt->close();
    }
    $conn->close();
}

// If login successful, show loading animation page instead of login form
if ($login_success):
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Initializing... | AI Assignment Checker</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-purple: #6A0DAD;
            --gradient-bg: linear-gradient(135deg, #6A0DAD 0%, #8e44ad 100%);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Poppins', sans-serif;
            background: var(--gradient-bg);
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden;
        }

        /* Stars background for loading page too */
        .stars-container {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            z-index: 0;
            pointer-events: none;
        }
        .star {
            position: absolute;
            width: 3px; height: 3px;
            background: #fff;
            border-radius: 50%;
            opacity: 0;
            animation: starFloat linear infinite;
        }
        .star::after {
            content: '';
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 6px; height: 6px;
            background: radial-gradient(circle, rgba(255,255,255,0.6) 0%, transparent 70%);
            border-radius: 50%;
        }
        @keyframes starFloat {
            0% { opacity: 0; transform: translateY(0) scale(0.5); }
            10% { opacity: 1; transform: scale(1); }
            90% { opacity: 0.8; }
            100% { opacity: 0; transform: translateY(-100vh) scale(0.3); }
        }
        .twinkle-star {
            position: absolute;
            width: 2px; height: 2px;
            background: #fff;
            border-radius: 50%;
            animation: twinkle ease-in-out infinite alternate;
        }
        @keyframes twinkle {
            0% { opacity: 0.2; transform: scale(0.8); }
            100% { opacity: 1; transform: scale(1.3); }
        }

        /* ===== Loading Screen ===== */
        .loading-screen {
            position: relative;
            z-index: 10;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 30px;
            animation: loadFadeIn 0.5s ease-out;
        }

        @keyframes loadFadeIn {
            from { opacity: 0; transform: scale(0.9); }
            to { opacity: 1; transform: scale(1); }
        }

        /* Spinning Logo */
        .logo-spinner {
            width: 120px;
            height: 120px;
            position: relative;
            animation: logoSpin 2s linear infinite;
        }

        .logo-spinner img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            border: 4px solid rgba(255,255,255,0.3);
        }

        @keyframes logoSpin {
            0% { transform: rotateY(0deg); }
            100% { transform: rotateY(360deg); }
        }

        /* Circular Progress Ring */
        .progress-ring-container {
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 140px; height: 140px;
            pointer-events: none;
        }

        .progress-ring {
            transform: rotate(-90deg);
        }

        .progress-ring__circle-bg {
            fill: none;
            stroke: rgba(255,255,255,0.15);
            stroke-width: 4;
        }

        .progress-ring__circle {
            fill: none;
            stroke: #fff;
            stroke-width: 4;
            stroke-linecap: round;
            stroke-dasharray: 408;
            stroke-dashoffset: 408;
            transition: stroke-dashoffset 1.8s ease-in-out;
            filter: drop-shadow(0 0 6px rgba(255,255,255,0.5));
        }

        .progress-ring__circle.animate {
            stroke-dashoffset: 0;
        }

        /* Scanning pulse around logo */
        .scan-pulse {
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%, -50%);
            width: 130px; height: 130px;
            border-radius: 50%;
            border: 2px solid rgba(255,255,255,0.4);
            animation: scanPulse 1.5s ease-out infinite;
        }

        .scan-pulse:nth-child(2) {
            animation-delay: 0.5s;
        }

        .scan-pulse:nth-child(3) {
            animation-delay: 1s;
        }

        @keyframes scanPulse {
            0% { width: 130px; height: 130px; opacity: 0.6; }
            100% { width: 220px; height: 220px; opacity: 0; }
        }

        /* Text */
        .loading-text {
            color: #fff;
            text-align: center;
        }

        .loading-title {
            font-size: 1.3rem;
            font-weight: 600;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }

        .loading-subtitle {
            font-size: 0.85rem;
            font-weight: 300;
            color: rgba(255,255,255,0.7);
            letter-spacing: 0.5px;
        }

        /* Typing dots */
        .typing-dots {
            display: inline-flex;
            gap: 4px;
            margin-left: 4px;
        }

        .typing-dots span {
            width: 5px; height: 5px;
            background: rgba(255,255,255,0.8);
            border-radius: 50%;
            animation: dotBounce 1.2s ease-in-out infinite;
        }

        .typing-dots span:nth-child(2) { animation-delay: 0.2s; }
        .typing-dots span:nth-child(3) { animation-delay: 0.4s; }

        @keyframes dotBounce {
            0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
            30% { transform: translateY(-6px); opacity: 1; }
        }

        /* Status messages that cycle */
        .status-messages {
            color: rgba(255,255,255,0.5);
            font-size: 0.75rem;
            text-align: center;
            min-height: 20px;
            transition: opacity 0.3s;
        }

        /* Percentage */
        .progress-percentage {
            color: rgba(255,255,255,0.9);
            font-size: 0.9rem;
            font-weight: 600;
            letter-spacing: 1px;
            min-height: 24px;
        }

        /* Welcome name */
        .welcome-name {
            color: #fff;
            font-size: 0.95rem;
            font-weight: 300;
            opacity: 0;
            transform: translateY(10px);
            transition: all 0.5s ease;
        }

        .welcome-name.show {
            opacity: 1;
            transform: translateY(0);
        }
    </style>
</head>
<body>

    <div class="stars-container" id="starsContainer"></div>

    <div class="loading-screen">
        <!-- Logo with spinning + progress ring -->
        <div style="position: relative; width: 140px; height: 140px; display: flex; justify-content: center; align-items: center;">
            <div class="scan-pulse"></div>
            <div class="scan-pulse"></div>
            <div class="scan-pulse"></div>
            <svg class="progress-ring-container" viewBox="0 0 140 140">
                <circle class="progress-ring__circle-bg" cx="70" cy="70" r="65"/>
                <circle class="progress-ring__circle" id="progressCircle" cx="70" cy="70" r="65"/>
            </svg>
            <div class="logo-spinner">
                <img src="image/logo.png" alt="Logo">
            </div>
        </div>

        <!-- Percentage -->
        <div class="progress-percentage" id="progressPercent">0%</div>

        <!-- Main text -->
        <div class="loading-text">
            <div class="loading-title">
                Initializing AI Engine
                <span class="typing-dots">
                    <span></span><span></span><span></span>
                </span>
            </div>
            <div class="loading-subtitle">AI Process of Scanning Assignment</div>
        </div>

        <!-- Cycling status -->
        <div class="status-messages" id="statusMsg">Connecting to server...</div>

        <!-- Welcome message (appears at end) -->
        <div class="welcome-name" id="welcomeName">
            Welcome back, <?php echo htmlspecialchars($_SESSION['name']); ?>!
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {

            // Stars
            const container = document.getElementById('starsContainer');
            for (let i = 0; i < 60; i++) {
                const star = document.createElement('div');
                star.classList.add('star');
                star.style.left = Math.random() * 100 + '%';
                star.style.top = (100 + Math.random() * 20) + '%';
                star.style.width = (Math.random() * 3 + 1) + 'px';
                star.style.height = star.style.width;
                star.style.animationDuration = (Math.random() * 8 + 6) + 's';
                star.style.animationDelay = (Math.random() * 10) + 's';
                container.appendChild(star);
            }
            for (let i = 0; i < 80; i++) {
                const twinkle = document.createElement('div');
                twinkle.classList.add('twinkle-star');
                twinkle.style.left = Math.random() * 100 + '%';
                twinkle.style.top = Math.random() * 100 + '%';
                twinkle.style.width = (Math.random() * 2 + 1) + 'px';
                twinkle.style.height = twinkle.style.width;
                twinkle.style.animationDuration = (Math.random() * 3 + 1.5) + 's';
                twinkle.style.animationDelay = (Math.random() * 4) + 's';
                container.appendChild(twinkle);
            }

            // Progress ring animation
            const circle = document.getElementById('progressCircle');
            const percentEl = document.getElementById('progressPercent');
            const statusEl = document.getElementById('statusMsg');
            const welcomeEl = document.getElementById('welcomeName');
            const circumference = 2 * Math.PI * 65; // ~408.4

            circle.style.strokeDasharray = circumference;
            circle.style.strokeDashoffset = circumference;

            // Trigger ring fill
            setTimeout(() => {
                circle.classList.add('animate');
            }, 100);

            // Status messages cycle
            const statuses = [
                'Connecting to server...',
                'Loading AI models...',
                'Preparing dashboard...',
                'Scanning assignment database...',
                'Almost ready...'
            ];

            let statusIndex = 0;
            const statusInterval = setInterval(() => {
                statusIndex++;
                if (statusIndex < statuses.length) {
                    statusEl.style.opacity = '0';
                    setTimeout(() => {
                        statusEl.textContent = statuses[statusIndex];
                        statusEl.style.opacity = '1';
                    }, 200);
                }
            }, 380);

            // Percentage counter
            let currentPercent = 0;
            const percentInterval = setInterval(() => {
                currentPercent += 1;
                if (currentPercent > 100) currentPercent = 100;
                percentEl.textContent = currentPercent + '%';
                if (currentPercent >= 100) clearInterval(percentInterval);
            }, 18);

            // Show welcome + redirect
            setTimeout(() => {
                clearInterval(statusInterval);
                welcomeEl.classList.add('show');

                setTimeout(() => {
                    window.location.href = "<?php echo $redirect_page; ?>";
                }, 500);
            }, 2000);
        });
    </script>
</body>
</html>

<?php
// If login NOT successful, show normal login page
else:
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | AI Assignment Checker</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-purple: #6A0DAD;
            --gradient-bg: linear-gradient(135deg, #6A0DAD 0%, #8e44ad 100%);
        }

        body {
            font-family: 'Times New Roman', sans-serif;
            background: var(--gradient-bg);
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            margin: 0;
            overflow: hidden;
        }

        .login-card {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            overflow: hidden;
            width: 100%;
            max-width: 420px;
            padding: 40px;
            animation: fadeIn 0.8s ease-in-out;
            border: none;
            position: relative;
            z-index: 10;
        }

        .logo-container {
            text-align: center;
            margin-bottom: 25px;
        }

        .logo-img {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 50%;
            border: 3px solid var(--primary-purple);
            padding: 2px;
        }

        .form-control {
            padding: 12px 15px;
            border: 1px solid #dee2e6;
            border-radius: 10px;
        }

        .form-control:focus {
            border-color: var(--primary-purple);
            box-shadow: 0 0 0 0.2rem rgba(106, 13, 173, 0.25);
        }

        .input-group-text {
            background: #fff;
            border: 1px solid #dee2e6;
            border-right: none;
            border-radius: 10px 0 0 10px;
            color: #6c757d;
        }
        
        .input-group .form-control {
            border-left: none;
            border-radius: 0 10px 10px 0;
        }
        .input-group .form-control:focus {
            border-left: none; 
        }
        
        .password-toggle-btn {
            background: #fff;
            border: 1px solid #dee2e6;
            border-left: none;
            color: #6c757d;
            cursor: pointer;
            border-radius: 0 10px 10px 0;
        }
        
        .password-toggle-btn:hover {
            background-color: #f8f9fa;
            color: var(--primary-purple);
        }

        .btn-login {
            background-color: var(--primary-purple);
            color: white;
            border: none;
            padding: 12px;
            border-radius: 10px;
            font-weight: 600;
            width: 100%;
            transition: all 0.3s ease;
        }

        .btn-login:hover {
            background-color: #550a8c;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(106, 13, 173, 0.3);
            color: white;
        }

        .links {
            display: flex;
            justify-content: space-between;
            font-size: 0.9rem;
            margin-top: 15px;
        }

        .links a {
            text-decoration: none;
            color: #6c757d;
            transition: color 0.3s;
        }

        .links a:hover {
            color: var(--primary-purple);
        }

        .register-link {
            text-align: center;
            margin-top: 20px;
            font-size: 0.9rem;
        }

        .register-link a {
            color: var(--primary-purple);
            font-weight: 600;
            text-decoration: none;
        }

        .register-link a:hover {
            text-decoration: underline;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .stars-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            pointer-events: none;
        }

        .star {
            position: absolute;
            width: 3px;
            height: 3px;
            background: #fff;
            border-radius: 50%;
            opacity: 0;
            animation: starFloat linear infinite;
        }

        .star::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 6px;
            height: 6px;
            background: radial-gradient(circle, rgba(255,255,255,0.6) 0%, transparent 70%);
            border-radius: 50%;
        }

        @keyframes starFloat {
            0% {
                opacity: 0;
                transform: translateY(0) scale(0.5);
            }
            10% {
                opacity: 1;
                transform: scale(1);
            }
            90% {
                opacity: 0.8;
            }
            100% {
                opacity: 0;
                transform: translateY(-100vh) scale(0.3);
            }
        }

        .shooting-star {
            position: absolute;
            width: 2px;
            height: 2px;
            background: #fff;
            border-radius: 50%;
            opacity: 0;
            animation: shoot linear infinite;
        }

        .shooting-star::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 80px;
            height: 1px;
            background: linear-gradient(to left, rgba(255,255,255,0.8), transparent);
            transform-origin: left center;
            transform: rotate(0deg);
        }

        @keyframes shoot {
            0% {
                opacity: 0;
                transform: translate(0, 0) rotate(-35deg);
            }
            5% {
                opacity: 1;
            }
            70% {
                opacity: 1;
            }
            100% {
                opacity: 0;
                transform: translate(-600px, 400px) rotate(-35deg);
            }
        }

        .twinkle-star {
            position: absolute;
            width: 2px;
            height: 2px;
            background: #fff;
            border-radius: 50%;
            animation: twinkle ease-in-out infinite alternate;
        }

        @keyframes twinkle {
            0% { opacity: 0.2; transform: scale(0.8); }
            100% { opacity: 1; transform: scale(1.3); }
        }
    </style>
</head>
<body>

    <div class="stars-container" id="starsContainer"></div>

    <div class="login-card">
        <div class="logo-container">
            <img src="image/logo.png" alt="AI Assignment Checker Logo" class="logo-img">
            <h4 class="mt-3 fw-bold" style="color: var(--primary-purple);">AI Assignment Checker</h4>
            <p class="text-muted small">Welcome back! Please login to continue.</p>
        </div>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger d-flex align-items-center shadow-sm" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <div><?php echo $error_message; ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success d-flex align-items-center shadow-sm" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <div><?php echo $success_message; ?></div>
            </div>
        <?php endif; ?>

        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" id="loginForm">
            
            <div class="mb-3">
                <label for="email" class="form-label small fw-bold">Email Address</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                    <input type="email" class="form-control" id="email" name="email" placeholder="Email" required>
                </div>
            </div>

            <div class="mb-3">
                <label for="password" class="form-label small fw-bold">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                    <input type="password" class="form-control" id="password" name="password" placeholder="Enter your password" required>
                    <button class="password-toggle-btn" type="button" id="togglePassword">
                        <i class="fas fa-eye" id="toggleIcon"></i>
                    </button>
                </div>
            </div>

            <div class="links">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="rememberMe">
                    <label class="form-check-label text-muted" for="rememberMe">
                        Remember Me
                    </label>
                </div>
            </div>

            <div class="d-grid gap-2 mt-4">
                <button type="submit" class="btn btn-login shadow">
                    Login <i class="fas fa-arrow-right ms-2"></i>
                </button>
            </div>
        </form>

        <div class="register-link">
            Don't have an account? <a href="register.php">Register</a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const togglePassword = document.querySelector('#togglePassword');
            const password = document.querySelector('#password');
            const toggleIcon = document.querySelector('#toggleIcon');

            togglePassword.addEventListener('click', function () {
                const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
                password.setAttribute('type', type);
                
                toggleIcon.classList.toggle('fa-eye');
                toggleIcon.classList.toggle('fa-eye-slash');
            });

            const container = document.getElementById('starsContainer');

            for (let i = 0; i < 60; i++) {
                const star = document.createElement('div');
                star.classList.add('star');
                star.style.left = Math.random() * 100 + '%';
                star.style.top = (100 + Math.random() * 20) + '%';
                star.style.width = (Math.random() * 3 + 1) + 'px';
                star.style.height = star.style.width;
                star.style.animationDuration = (Math.random() * 8 + 6) + 's';
                star.style.animationDelay = (Math.random() * 10) + 's';
                container.appendChild(star);
            }

            for (let i = 0; i < 80; i++) {
                const twinkle = document.createElement('div');
                twinkle.classList.add('twinkle-star');
                twinkle.style.left = Math.random() * 100 + '%';
                twinkle.style.top = Math.random() * 100 + '%';
                twinkle.style.width = (Math.random() * 2 + 1) + 'px';
                twinkle.style.height = twinkle.style.width;
                twinkle.style.animationDuration = (Math.random() * 3 + 1.5) + 's';
                twinkle.style.animationDelay = (Math.random() * 4) + 's';
                container.appendChild(twinkle);
            }

            function createShootingStar() {
                const shoot = document.createElement('div');
                shoot.classList.add('shooting-star');
                shoot.style.left = (Math.random() * 60 + 30) + '%';
                shoot.style.top = (Math.random() * 30 + 5) + '%';
                shoot.style.animationDuration = (Math.random() * 1.5 + 0.8) + 's';
                container.appendChild(shoot);

                shoot.addEventListener('animationend', () => {
                    shoot.remove();
                });
            }

            function scheduleShootingStar() {
                const delay = Math.random() * 4000 + 2000;
                setTimeout(() => {
                    createShootingStar();
                    scheduleShootingStar();
                }, delay);
            }
            scheduleShootingStar();
        });
    </script>
</body>
</html>

<?php endif; ?>