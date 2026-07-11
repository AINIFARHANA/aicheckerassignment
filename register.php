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

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // 1. Sanitize Inputs
    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);
    
    // FIXED: Retrieve 'avatar_seed' (matching the HTML hidden input name)
    $avatar_seed = trim($_POST['avatar_seed']); 

    // 2. Validate Empty Fields
    if (empty($name) || empty($email) || empty($password) || empty($confirm_password) || empty($avatar_seed)) {
        $error_message = "All fields are required, including avatar selection.";
    } 
    elseif ($password !== $confirm_password) {
        $error_message = "Passwords do not match.";
    } 
    elseif (strlen($password) < 6) {
        $error_message = "Password must be at least 6 characters long.";
    } 
    else {
        // 3. Check if Email Exists
        $check_email = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
        $check_email->bind_param("s", $email);
        $check_email->execute();
        $check_email->store_result();

        if ($check_email->num_rows > 0) {
            $error_message = "This email is already registered.";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $user_type = 'user'; 
            
            $stmt = $conn->prepare("INSERT INTO users (name, email, password, user_type, avatar) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $name, $email, $hashed_password, $user_type, $avatar_seed);

            if ($stmt->execute()) {
                $success_message = "Registration successful! Redirecting to login...";
                header("refresh:2;url=login.php");
            } else {
                $error_message = "Something went wrong. Please try again.";
            }
            $stmt->close();
        }
        $check_email->close();
    }
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | AI Assignment Checker</title>
    
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
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px 0;
            margin: 0;
            overflow-x: hidden;
            overflow-y: auto;
        }

        .register-card {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
            width: 100%;
            max-width: 500px;
            padding: 40px;
            position: relative;
            overflow: hidden;
            border: none;
            z-index: 10;
        }

        .header-text {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .header-text h2 {
            color: var(--primary-purple);
            font-weight: 700;
        }

        .header-text p {
            color: #777;
            font-size: 0.95rem;
        }

        .form-label {
            font-weight: 500;
            font-size: 0.9rem;
            color: #555;
            margin-bottom: 5px;
        }

        .input-group-text {
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-right: none;
            color: #aaa;
        }

        .form-control {
            border: 1px solid #e0e0e0;
            border-left: none;
            padding: 10px 15px;
        }
        
        .form-control:focus {
            box-shadow: none;
            border-color: var(--primary-purple);
            border-left: none;
        }

        .password-toggle-btn {
            background: #f8f9fa;
            border: 1px solid #e0e0e0;
            border-left: none;
            cursor: pointer;
            color: #aaa;
            z-index: 10;
        }
        
        .password-toggle-btn:hover {
            color: var(--primary-purple);
        }

        .strength-meter {
            height: 5px;
            border-radius: 5px;
            background-color: #eee;
            margin-top: 5px;
            margin-bottom: 15px;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .strength-bar {
            height: 100%;
            width: 0;
            transition: width 0.3s ease, background-color 0.3s ease;
        }

        /* --- Avatar Selection Styles --- */
        .avatar-selection-container {
            margin: 2rem 0;
            text-align: center;
        }

        .avatar-selection-container label {
            display: block;
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-purple); /* Changed to Purple theme */
            margin-bottom: 1rem;
        }

        .avatar-selection {
            display: flex;
            justify-content: center;
            gap: 1.5rem;
            flex-wrap: wrap;
        }

        .avatar-option {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            border: 3px solid #eee; /* Thicker border */
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            background: #fff;
            object-fit: cover;
            position: relative;
        }

        /* Hover state */
        .avatar-option:hover {
            border-color: #ccc;
            transform: translateY(-3px);
        }

        /* Selected State */
        .avatar-option.selected {
            border-color: var(--primary-purple);
            transform: scale(1.15); /* Slightly larger when selected */
            box-shadow: 0 4px 10px rgba(106, 13, 173, 0.3);
        }

        /* Add a checkmark overlay when selected (Optional but nice) */
        .avatar-option.selected::after {
            content: '\f00c'; /* FontAwesome Check */
            font-family: "Font Awesome 6 Free"; 
            font-weight: 900;
            position: absolute;
            bottom: 0;
            right: 0;
            background: var(--primary-purple);
            color: white;
            width: 20px;
            height: 20px;
            font-size: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }

        .btn-register {
            background-color: var(--primary-purple);
            color: white;
            padding: 12px;
            border-radius: 10px;
            font-weight: 600;
            width: 100%;
            transition: all 0.3s ease;
            border: none;
        }

        .btn-register:hover {
            background-color: #550a8c;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(106, 13, 173, 0.3);
            color: white;
        }

        .footer-link {
            text-align: center;
            margin-top: 20px;
            font-size: 0.9rem;
        }
        
        .footer-link a {
            color: var(--primary-purple);
            text-decoration: none;
            font-weight: 600;
        }
        
        .footer-link a:hover {
            text-decoration: underline;
        }

        /* ===== Moving Stars Background ===== */
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

        /* Shooting stars */
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

        /* Twinkling static stars */
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

    <!-- Moving Stars Background -->
    <div class="stars-container" id="starsContainer"></div>

    <div class="register-card">
        <div class="text-center mb-4">
            <!-- Logo with fallback -->
            <img src="image/logo.png" alt="Logo" class="mb-3" style="width: 70px; border-radius: 50%; border: 3px solid var(--primary-purple);">
            <div class="header-text">
                <h2>Create Your Account</h2>
                <p>Join AI Assignment Checker and start submitting assignments easily.</p>
            </div>
        </div>

        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger d-flex align-items-center shadow-sm" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <div><?php echo $error_message; ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success d-flex align-items-center shadow-sm" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <div><?php echo $success_message; ?></div>
            </div>
        <?php endif; ?>

        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" id="registerForm" autocomplete="off">
            
            <div class="mb-3">
                <label for="name" class="form-label">Full Name</label>
                <div class="input-group">
                    <span class="input-group-text rounded-start-3"><i class="fas fa-user"></i></span>
                    <input type="text" class="form-control rounded-end-3" id="name" name="name" placeholder="John Doe" required value="<?php if(isset($name)) echo $name; ?>">
                </div>
            </div>

            <div class="mb-3">
                <label for="email" class="form-label">Email Address</label>
                <div class="input-group">
                    <span class="input-group-text rounded-start-3"><i class="fas fa-envelope"></i></span>
                    <input type="email" class="form-control rounded-end-3" id="email" name="email" placeholder="name@example.com" required value="<?php if(isset($email)) echo $email; ?>">
                </div>
            </div>

            <div class="mb-2">
                <label for="password" class="form-label">Password</label>
                <div class="input-group">
                    <span class="input-group-text rounded-start-3"><i class="fas fa-lock"></i></span>
                    <input type="password" class="form-control rounded-end-3" id="password" name="password" placeholder="Create a strong password" required>
                    <button class="password-toggle-btn rounded-end-3" type="button" id="togglePassword">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <div class="strength-meter">
                    <div class="strength-bar" id="strengthBar"></div>
                </div>
                <small id="strengthText" class="text-muted" style="font-size: 0.75rem;"></small>
            </div>

            <div class="mb-3">
                <label for="confirm_password" class="form-label">Confirm Password</label>
                <div class="input-group">
                    <span class="input-group-text rounded-start-3"><i class="fas fa-lock"></i></span>
                    <input type="password" class="form-control rounded-end-3" id="confirm_password" name="confirm_password" placeholder="Confirm your password" required>
                </div>
                <div id="passwordMatchFeedback" class="text-danger" style="font-size: 0.8rem; margin-top: 2px;"></div>
            </div>

            <!-- Avatar Selection (Corrected) -->
            <div class="avatar-selection-container">
                <label>Choose Your Avatar</label>
                <div class="avatar-selection">
                    <!-- Default 'Felix' selected via class in HTML (optional, handled by JS too) -->
                    <img src="https://api.dicebear.com/7.x/avataaars/svg?seed=Felix" class="avatar-option selected" onclick="selectAvatar(this, 'Felix')" alt="Felix">
                    <img src="https://api.dicebear.com/7.x/avataaars/svg?seed=Annie" class="avatar-option" onclick="selectAvatar(this, 'Annie')" alt="Annie">
                    <img src="https://api.dicebear.com/7.x/avataaars/svg?seed=Bob" class="avatar-option" onclick="selectAvatar(this, 'Bob')" alt="Bob">
                    <img src="https://api.dicebear.com/7.x/avataaars/svg?seed=Cathy" class="avatar-option" onclick="selectAvatar(this, 'Cathy')" alt="Cathy">
                </div>
                <!-- Hidden Input matches PHP variable name -->
                <input type="hidden" name="avatar_seed" id="avatar_seed" value="Felix">
            </div>

            <div class="d-grid">
                <button type="submit" class="btn btn-register shadow" id="submitBtn">
                    Create Account <i class="fas fa-arrow-right ms-2"></i>
                </button>
            </div>

        </form>

        <div class="footer-link">
            Already have an account? <a href="login.php">Login here</a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            
            const togglePassword = document.querySelector('#togglePassword');
            const passwordInput = document.querySelector('#password');
            
            togglePassword.addEventListener('click', function () {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                this.querySelector('i').classList.toggle('fa-eye');
                this.querySelector('i').classList.toggle('fa-eye-slash');
            });

            const strengthBar = document.getElementById('strengthBar');
            const strengthText = document.getElementById('strengthText');

            passwordInput.addEventListener('input', function() {
                const val = this.value;
                let strength = 0;
                let color = '#eee';
                let text = '';

                if(val.length > 0) strength += 1;
                if(val.length > 5) strength += 1;
                if(val.length > 8 && /[0-9]/.test(val) && /[^A-Za-z0-9]/.test(val)) strength += 1;

                switch(strength) {
                    case 0: 
                        width = '0%'; color = '#eee'; text = '';
                        break;
                    case 1: 
                        width = '30%'; color = '#ff4d4d'; text = 'Weak';
                        break;
                    case 2: 
                        width = '60%'; color = '#ffa500'; text = 'Medium';
                        break;
                    case 3: 
                        width = '100%'; color = '#2ecc71'; text = 'Strong';
                        break;
                }

                strengthBar.style.width = width;
                strengthBar.style.backgroundColor = color;
                strengthText.textContent = text;
                strengthText.style.color = strength === 3 ? '#2ecc71' : (strength === 1 ? '#ff4d4d' : '#ffa500');
            });

            const confirmInput = document.getElementById('confirm_password');
            const matchFeedback = document.getElementById('passwordMatchFeedback');
            const form = document.getElementById('registerForm');
            const submitBtn = document.getElementById('submitBtn');

            confirmInput.addEventListener('input', function() {
                if (this.value !== passwordInput.value && this.value !== '') {
                    matchFeedback.textContent = 'Passwords do not match';
                } else {
                    matchFeedback.textContent = '';
                }
            });

            form.addEventListener('submit', function(e) {
                if(passwordInput.value !== confirmInput.value) {
                    e.preventDefault();
                    matchFeedback.textContent = 'Passwords do not match';
                    return;
                }
                
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Creating Account...';
            });

            // ===== Moving Stars Generator =====
            const container = document.getElementById('starsContainer');

            // Floating rising stars
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

            // Static twinkling stars
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

            // Shooting stars
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

            // Launch shooting stars at random intervals
            function scheduleShootingStar() {
                const delay = Math.random() * 4000 + 2000;
                setTimeout(() => {
                    createShootingStar();
                    scheduleShootingStar();
                }, delay);
            }
            scheduleShootingStar();
        });

        // Avatar Selection Function
        function selectAvatar(el, seed) {
            // Remove 'selected' class from all options
            document.querySelectorAll('.avatar-option').forEach(img => img.classList.remove('selected'));
            
            // Add 'selected' class to clicked option
            el.classList.add('selected');
            
            // Update hidden input value
            document.getElementById('avatar_seed').value = seed;
        }
    </script>
</body>
</html>