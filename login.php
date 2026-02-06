<?php
session_start();

// Database credentials
$db_host = "localhost";
$db_name = "rfnhscco_routine";
$db_user = "rfnhscco_routine";
$db_pass = 'abO(sOQ}AhmZ$yd4';

class Database {
    private $host = "localhost";
    private $db_name = "rfnhscco_routine";
    private $username = "rfnhscco_routine";
    private $password = 'abO(sOQ}AhmZ$yd4';
    private $conn;

    public function connect() {
        $this->conn = new mysqli(
            $this->host,
            $this->username,
            $this->password,
            $this->db_name
        );

        if ($this->conn->connect_error) {
            die("Database connection failed: " . $this->conn->connect_error);
        }

        $this->conn->set_charset("utf8mb4");
        return $this->conn;
    }

    public function close() {
        if ($this->conn) {
            $this->conn->close();
        }
    }
}

// Function to check if username/email exists in database
function checkUsernameExists($username) {
    $db = new Database();
    $conn = $db->connect();
    
    $username = mysqli_real_escape_string($conn, $username);
    
    $sql = "SELECT username, email FROM users WHERE (username = ? OR email = ?) AND status = 'active'";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $username, $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $exists = $result->num_rows > 0;
    
    $stmt->close();
    $db->close();
    
    return $exists;
}

// Function to check if account is locked
function isAccountLocked($username) {
    if (!isset($_SESSION['login_attempts'][$username])) {
        return false;
    }
    
    $attempts = $_SESSION['login_attempts'][$username];
    
    // If 3 or more failed attempts and last attempt was less than 3 minutes ago
    if ($attempts['count'] >= 3) {
        $lock_time = 180; // 3 minutes in seconds
        $time_since_last_attempt = time() - $attempts['last_attempt'];
        
        if ($time_since_last_attempt < $lock_time) {
            return [
                'locked' => true,
                'remaining_time' => $lock_time - $time_since_last_attempt
            ];
        } else {
            // Reset attempts after lock time expires
            unset($_SESSION['login_attempts'][$username]);
            return false;
        }
    }
    
    return false;
}

// Function to record failed login attempt
function recordFailedAttempt($username) {
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = [];
    }
    
    if (!isset($_SESSION['login_attempts'][$username])) {
        $_SESSION['login_attempts'][$username] = [
            'count' => 1,
            'last_attempt' => time()
        ];
    } else {
        $_SESSION['login_attempts'][$username]['count']++;
        $_SESSION['login_attempts'][$username]['last_attempt'] = time();
    }
}

// Function to clear failed attempts on successful login
function clearFailedAttempts($username) {
    if (isset($_SESSION['login_attempts'][$username])) {
        unset($_SESSION['login_attempts'][$username]);
    }
}

// Login function
function loginUser($username, $password) {
    $db = new Database();
    $conn = $db->connect();
    
    // Sanitize inputs
    $username = mysqli_real_escape_string($conn, $username);
    
    // Check if account is locked
    $lock_status = isAccountLocked($username);
    if ($lock_status && $lock_status['locked']) {
        $remaining = ceil($lock_status['remaining_time'] / 60);
        return [
            'success' => false, 
            'message' => "Account locked. Try again in $remaining minute(s).",
            'locked' => true
        ];
    }
    
    // Query to check user (without role filter)
    $sql = "SELECT u.*, s.student_id, t.teacher_id 
            FROM users u 
            LEFT JOIN students s ON u.id = s.user_id 
            LEFT JOIN teachers t ON u.id = t.user_id 
            WHERE (u.username = ? OR u.email = ?) 
            AND u.status = 'active'";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $username, $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        // Verify password
        if (password_verify($password, $user['password'])) {
            // Clear failed attempts
            clearFailedAttempts($username);
            
            // Set session variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['status'] = $user['status'];
            $_SESSION['logged_in'] = true;
            
            // Set role-specific data
            if ($user['role'] === 'student') {
                $_SESSION['student_id'] = $user['student_id'] ?? '';
                $_SESSION['department'] = $user['department'] ?? '';
            } elseif ($user['role'] === 'teacher') {
                $_SESSION['teacher_id'] = $user['teacher_id'] ?? '';
                $_SESSION['department'] = $user['department'] ?? '';
                $_SESSION['designation'] = $user['designation'] ?? '';
            } elseif ($user['role'] === 'admin') {
                $_SESSION['admin_id'] = $user['id'];
            }
            
            // Set last login time
            $_SESSION['last_login'] = time();
            
            $stmt->close();
            $db->close();
            
            return [
                'success' => true, 
                'message' => 'Login successful!',
                'role' => $user['role']
            ];
        } else {
            // Record failed attempt
            recordFailedAttempt($username);
            
            $stmt->close();
            $db->close();
            return ['success' => false, 'message' => 'Invalid password!'];
        }
    } else {
        $stmt->close();
        $db->close();
        return ['success' => false, 'message' => 'User not found or inactive!'];
    }
}

// Handle login request
$login_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    $login_result = loginUser($username, $password);
    
    // If login successful, redirect to appropriate dashboard
    if ($login_result['success']) {
        $role = $login_result['role'];
        
        // Redirect based on role
        switch ($role) {
            case 'admin':
                header('Location: admin.php');
                break;
            case 'teacher':
                header('Location: teacher.php');
                break;
            case 'student':
                header('Location: student.php');
                break;
            default:
                header('Location: dashboard.php');
        }
        exit();
    }
}

// Handle username check AJAX request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_username'])) {
    $username = $_POST['username'] ?? '';
    
    if (empty($username)) {
        echo json_encode(['exists' => false]);
        exit();
    }
    
    $exists = checkUsernameExists($username);
    echo json_encode(['exists' => $exists]);
    exit();
}

// Check if user is already logged in
function isLoggedIn() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

// If user is already logged in, redirect to appropriate dashboard
if (isLoggedIn()) {
    $role = $_SESSION['role'] ?? 'student';
    switch ($role) {
        case 'admin':
            header('Location: admin.php');
            break;
        case 'teacher':
            header('Location: teacher.php');
            break;
        case 'student':
            header('Location: student.php');
            break;
        default:
            header('Location: dashboard.php');
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LOSERS-Smart Routine Management System</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Sora:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        :root {
            --midnight-start: #0F172A;
            --midnight-end: #020617;
            --glass-light: rgba(255, 255, 255, 0.08);
            --glass-border: rgba(255, 255, 255, 0.18);
            
            /* Status Colors */
            --valid-color: #10B981;
            --invalid-color: #EF4444;
            --warning-color: #F59E0B;
            
            --accent-color: #818CF8;
            --accent-dark: #4F46E5;
            --accent-glow: rgba(129, 140, 248, 0.3);
        }

        body {
            min-height: 100vh;
            background: linear-gradient(135deg, var(--midnight-start), var(--midnight-end));
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden;
            color: #fff;
            transition: filter 0.5s ease;
        }

        /* Aurora Background */
        .aurora-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            overflow: hidden;
        }

        .aurora-blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(60px);
            opacity: 0.15;
        }

        .blob-1 {
            width: 500px;
            height: 500px;
            background: linear-gradient(45deg, #818CF8, #F87171);
            top: -200px;
            left: -200px;
        }

        .blob-2 {
            width: 600px;
            height: 600px;
            background: linear-gradient(45deg, #F87171, #34D399);
            bottom: -200px;
            right: -200px;
        }

        .blob-3 {
            width: 400px;
            height: 400px;
            background: linear-gradient(45deg, #34D399, #818CF8);
            top: 50%;
            left: 70%;
        }

        /* Glass Card */
        .glass-card {
            width: 820px;
            background: var(--glass-light);
            backdrop-filter: blur(18px) saturate(180%);
            -webkit-backdrop-filter: blur(18px) saturate(180%);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            padding: 40px;
            box-shadow: 
                0 8px 32px rgba(0, 0, 0, 0.2),
                inset 0 1px 0 rgba(255, 255, 255, 0.1),
                var(--accent-glow) 0 0 40px;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            transform-style: preserve-3d;
            position: relative;
            overflow: hidden;
        }

        .glass-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 1px;
            background: linear-gradient(90deg, 
                transparent, 
                rgba(255, 255, 255, 0.3), 
                transparent);
        }

        /* Error Message */
        .error-message {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            display: none;
        }

        .error-message i {
            color: #ef4444;
        }

        .error-message.show {
            display: flex;
            animation: fadeIn 0.5s ease;
        }

        /* Warning Message */
        .warning-message {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.3);
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            display: none;
        }

        .warning-message i {
            color: #f59e0b;
        }

        .warning-message.show {
            display: flex;
            animation: fadeIn 0.5s ease;
        }

        /* Success Message */
        .success-message {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.3);
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            display: none;
        }

        .success-message i {
            color: #10b981;
        }

        .success-message.show {
            display: flex;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Logo & Header */
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }

        .logo h1 {
            font-family: 'Sora', sans-serif;
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(90deg, #fff, var(--accent-color));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 8px;
        }

        .logo p {
            font-size: 14px;
            opacity: 0.7;
            font-weight: 300;
        }

        /* Form Elements */
        .form-group {
            margin-bottom: 24px;
            position: relative;
        }

        .form-label {
            display: block;
            font-size: 14px;
            margin-bottom: 8px;
            opacity: 0.9;
            transition: all 0.3s ease;
        }

        .form-input {
            width: 100%;
            padding: 14px 16px;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 12px;
            color: white;
            font-size: 15px;
            transition: all 0.3s ease;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 2px var(--accent-glow);
            background: rgba(255, 255, 255, 0.08);
        }

        .form-input::placeholder {
            color: rgba(255, 255, 255, 0.5);
        }

        /* Username status indicator */
        .username-status {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 14px;
            display: none;
        }

        .username-status.valid {
            color: var(--valid-color);
            display: block;
        }

        .username-status.invalid {
            color: var(--invalid-color);
            display: block;
        }

        .password-container {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: rgba(255, 255, 255, 0.5);
            cursor: pointer;
            padding: 4px;
            transition: color 0.3s ease;
        }

        .toggle-password:hover {
            color: var(--accent-color);
        }

        .hint-text {
            font-size: 12px;
            opacity: 0.6;
            margin-top: 6px;
            display: block;
            min-height: 18px;
        }

        /* Password Strength */
        .strength-meter {
            margin-top: 8px;
            display: none;
        }

        .strength-bars {
            display: flex;
            gap: 4px;
            margin-bottom: 6px;
        }

        .strength-bar {
            flex: 1;
            height: 4px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 2px;
            transition: all 0.3s ease;
        }

        .strength-text {
            font-size: 11px;
            text-align: right;
            opacity: 0.7;
        }

        /* Lock Timer */
        .lock-timer {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            display: none;
        }

        .lock-timer.show {
            display: flex;
            animation: fadeIn 0.5s ease;
        }

        .lock-timer i {
            color: #ef4444;
        }

        .timer {
            font-weight: 600;
            color: #ef4444;
        }

        /* Options Row */
        .options-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .checkbox-container {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }

        .custom-checkbox {
            width: 18px;
            height: 18px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }

        .custom-checkbox.checked {
            background: var(--accent-color);
            border-color: var(--accent-color);
        }

        .custom-checkbox.checked::after {
            content: '✓';
            color: white;
            font-size: 12px;
        }

        .checkbox-label {
            font-size: 14px;
            opacity: 0.8;
        }

        .forgot-password {
            font-size: 14px;
            color: var(--accent-color);
            text-decoration: none;
            opacity: 0.9;
            transition: opacity 0.3s ease;
        }

        .forgot-password:hover {
            opacity: 1;
        }

        /* Login Button */
        .login-button {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, var(--accent-color), var(--accent-dark));
            border: none;
            border-radius: 12px;
            color: white;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .login-button:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
        }

        .login-button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .login-button:active:not(:disabled) {
            transform: translateY(0);
        }

        .login-button.loading {
            pointer-events: none;
            opacity: 0.8;
        }

        .button-ripple {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: scale(0);
            animation: ripple 0.6s linear;
        }

        @keyframes ripple {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }

        /* Register Link */
        .register-link {
            text-align: center;
            margin-top: 24px;
            font-size: 14px;
            opacity: 0.7;
        }

        .register-link a {
            color: var(--accent-color);
            text-decoration: none;
            font-weight: 500;
            margin-left: 6px;
            opacity: 0.9;
            transition: opacity 0.3s ease;
        }

        .register-link a:hover {
            opacity: 1;
        }

        /* Security Status */
        .security-status {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            opacity: 0.6;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .security-icon {
            color: var(--accent-color);
        }

        /* Suspicious Mode */
        .suspicious-mode .glass-card {
            filter: grayscale(0.8);
            animation: pulseWarning 2s infinite;
        }

        @keyframes pulseWarning {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.9; }
        }

        /* Error State */
        .error-shake {
            animation: shake 0.5s cubic-bezier(.36,.07,.19,.97) both;
        }

        @keyframes shake {
            10%, 90% { transform: translateX(-1px); }
            20%, 80% { transform: translateX(2px); }
            30%, 50%, 70% { transform: translateX(-2px); }
            40%, 60% { transform: translateX(2px); }
        }

        /* Responsive */
        @media (max-width: 480px) {
            .glass-card {
                width: 90%;
                padding: 30px 20px;
                margin: 20px;
            }
        }
    </style>
</head>
<body>
    <!-- Aurora Background -->
    <div class="aurora-bg">
        <div class="aurora-blob blob-1"></div>
        <div class="aurora-blob blob-2"></div>
        <div class="aurora-blob blob-3"></div>
    </div>

    <!-- Glass Login Card -->
    <div class="glass-card" id="loginCard">
        <div class="logo">
            <h1>LOSERS</h1>
            <p>Smart Routine Management System</p>
        </div>

        <!-- Lock Timer -->
        <div class="lock-timer" id="lockTimer">
            <i class="fas fa-lock"></i>
            <span>Account locked. Try again in <span id="timerDisplay" class="timer">3:00</span></span>
        </div>

        <!-- Error Message -->
        <div class="error-message" id="errorMessage">
            <i class="fas fa-exclamation-circle"></i>
            <span id="errorText"></span>
        </div>

        <!-- Warning Message -->
        <div class="warning-message" id="warningMessage">
            <i class="fas fa-exclamation-triangle"></i>
            <span id="warningText"></span>
        </div>

        <!-- Success Message -->
        <div class="success-message" id="successMessage">
            <i class="fas fa-check-circle"></i>
            <span id="successText"></span>
        </div>

        <!-- Login Form -->
        <form id="loginForm" method="POST" action="">
            <input type="hidden" name="login" value="1">
            
            <div class="form-group">
                <label class="form-label">Username or Email</label>
                <input type="text" 
                       class="form-input" 
                       id="usernameInput" 
                       name="username"
                       placeholder="Enter your username or email"
                       required
                       autocomplete="username"
                       oninput="checkUsername()">
                <span class="username-status" id="usernameStatus"></span>
                <span class="hint-text" id="usernameHint"></span>
            </div>

            <div class="form-group">
                <label class="form-label">Password</label>
                <div class="password-container">
                    <input type="password" 
                           class="form-input" 
                           id="passwordInput" 
                           name="password"
                           placeholder="Enter your password"
                           required
                           autocomplete="current-password"
                           oninput="checkPasswordStrength()">
                    <button type="button" class="toggle-password" onclick="togglePassword()">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                
                <!-- Password Strength Meter -->
                <div class="strength-meter" id="strengthMeter">
                    <div class="strength-bars">
                        <div class="strength-bar" id="bar1"></div>
                        <div class="strength-bar" id="bar2"></div>
                        <div class="strength-bar" id="bar3"></div>
                        <div class="strength-bar" id="bar4"></div>
                    </div>
                    <div class="strength-text" id="strengthText"></div>
                </div>
            </div>

            <div class="options-row">
                <label class="checkbox-container" onclick="toggleRememberMe()">
                    <div class="custom-checkbox" id="rememberCheckbox"></div>
                    <span class="checkbox-label">Remember me</span>
                </label>
                <a href="#" class="forgot-password" onclick="showForgotPassword()">Forgot password?</a>
            </div>

            <button type="submit" class="login-button" id="loginButton">
                <span id="buttonText">Login to Continue</span>
            </button>
        </form>

        <div class="register-link">
            Don't have an account? <a href="register.php">Register here</a>
        </div>

        <div class="security-status">
            <i class="fas fa-shield-alt security-icon"></i>
            <span id="securityText">Secure connection • AES-256 encrypted</span>
        </div>
    </div>

    <script>
        // Current state
        let failedAttempts = 0;
        let isSuspiciousMode = false;
        let isPasswordVisible = false;
        let lockTimer = null;
        let lockEndTime = null;
        let usernameCheckTimeout = null;

        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
            animateEntrance();
            setupMouseTilt();
            checkForLockedAccount();
            
            // Show error message if login failed
            <?php if ($login_result && !$login_result['success']): ?>
                if (<?php echo isset($login_result['locked']) && $login_result['locked'] ? 'true' : 'false'; ?>) {
                    showLockTimer(<?php echo isset($lock_status['remaining_time']) ? $lock_status['remaining_time'] : 180; ?>);
                } else {
                    showError("<?php echo addslashes($login_result['message']); ?>");
                    failedLogin();
                }
            <?php endif; ?>
        });

        // Check username existence in database
        function checkUsername() {
            const usernameInput = document.getElementById('usernameInput');
            const username = usernameInput.value.trim();
            const usernameStatus = document.getElementById('usernameStatus');
            const usernameHint = document.getElementById('usernameHint');
            
            // Clear previous timeout
            clearTimeout(usernameCheckTimeout);
            
            // Reset status
            usernameStatus.className = 'username-status';
            usernameHint.textContent = '';
            
            if (username.length === 0) {
                return;
            }
            
            // Debounce the API call
            usernameCheckTimeout = setTimeout(() => {
                // Show loading state
                usernameStatus.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
                usernameStatus.className = 'username-status';
                usernameStatus.style.display = 'block';
                
                // Check username via AJAX
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `check_username=1&username=${encodeURIComponent(username)}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.exists) {
                        usernameStatus.innerHTML = '<i class="fas fa-check-circle"></i>';
                        usernameStatus.className = 'username-status valid';
                        usernameHint.textContent = '✓ Username/email found in system';
                        usernameHint.style.color = 'var(--valid-color)';
                        
                        // Animate success
                        gsap.to(usernameStatus, {
                            scale: 1.2,
                            duration: 0.2,
                            yoyo: true,
                            repeat: 1
                        });
                    } else {
                        usernameStatus.innerHTML = '<i class="fas fa-times-circle"></i>';
                        usernameStatus.className = 'username-status invalid';
                        usernameHint.textContent = '✗ Username/email not found';
                        usernameHint.style.color = 'var(--invalid-color)';
                    }
                })
                .catch(error => {
                    console.error('Error checking username:', error);
                    usernameStatus.style.display = 'none';
                });
            }, 500); // 500ms debounce
        }

        // Check for locked account on page load
        function checkForLockedAccount() {
            const username = document.getElementById('usernameInput').value;
            if (username) {
                // You would typically make an AJAX call here to check lock status
                // For now, we'll rely on the PHP session
                updateLoginButtonState();
            }
        }

        // Show error message
        function showError(message) {
            hideAllMessages();
            const errorElement = document.getElementById('errorMessage');
            const errorText = document.getElementById('errorText');
            
            errorText.textContent = message;
            errorElement.classList.add('show');
            
            // Auto-hide after 5 seconds
            setTimeout(() => {
                errorElement.classList.remove('show');
            }, 5000);
        }

        // Show warning message
        function showWarning(message) {
            hideAllMessages();
            const warningElement = document.getElementById('warningMessage');
            const warningText = document.getElementById('warningText');
            
            warningText.textContent = message;
            warningElement.classList.add('show');
            
            // Auto-hide after 5 seconds
            setTimeout(() => {
                warningElement.classList.remove('show');
            }, 5000);
        }

        // Show success message
        function showSuccess(message) {
            hideAllMessages();
            const successElement = document.getElementById('successMessage');
            const successText = document.getElementById('successText');
            
            successText.textContent = message;
            successElement.classList.add('show');
            
            // Auto-hide after 3 seconds
            setTimeout(() => {
                successElement.classList.remove('show');
            }, 3000);
        }

        // Hide all messages
        function hideAllMessages() {
            document.getElementById('errorMessage').classList.remove('show');
            document.getElementById('warningMessage').classList.remove('show');
            document.getElementById('successMessage').classList.remove('show');
        }

        // Show lock timer
        function showLockTimer(seconds) {
            const lockTimer = document.getElementById('lockTimer');
            const timerDisplay = document.getElementById('timerDisplay');
            
            lockEndTime = Date.now() + (seconds * 1000);
            updateTimerDisplay();
            
            lockTimer.classList.add('show');
            
            // Start timer
            if (lockTimer) clearInterval(lockTimer);
            lockTimer = setInterval(updateTimerDisplay, 1000);
            
            // Disable login button
            updateLoginButtonState();
        }

        // Update timer display
        function updateTimerDisplay() {
            if (!lockEndTime) return;
            
            const now = Date.now();
            const remaining = Math.max(0, lockEndTime - now);
            
            const minutes = Math.floor(remaining / 60000);
            const seconds = Math.floor((remaining % 60000) / 1000);
            
            const timerDisplay = document.getElementById('timerDisplay');
            timerDisplay.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
            
            // Update login button state
            updateLoginButtonState();
            
            // Clear timer when done
            if (remaining <= 0) {
                clearInterval(lockTimer);
                lockTimer = null;
                lockEndTime = null;
                document.getElementById('lockTimer').classList.remove('show');
                showSuccess("Account unlocked. You can now try logging in.");
            }
        }

        // Update login button state based on lock status
        function updateLoginButtonState() {
            const loginButton = document.getElementById('loginButton');
            const username = document.getElementById('usernameInput').value;
            
            if (lockEndTime && Date.now() < lockEndTime) {
                loginButton.disabled = true;
                loginButton.textContent = 'Account Locked';
            } else {
                loginButton.disabled = false;
                loginButton.textContent = 'Login to Continue';
            }
        }

        // Password visibility toggle
        function togglePassword() {
            const passwordInput = document.getElementById('passwordInput');
            const eyeIcon = document.querySelector('.toggle-password i');
            
            isPasswordVisible = !isPasswordVisible;
            passwordInput.type = isPasswordVisible ? 'text' : 'password';
            eyeIcon.className = isPasswordVisible ? 'fas fa-eye-slash' : 'fas fa-eye';
            
            // Animate password reveal
            if (isPasswordVisible) {
                gsap.to('.password-container', {
                    scale: 1.02,
                    duration: 0.2,
                    yoyo: true,
                    repeat: 1,
                    ease: 'power2.inOut'
                });
            }
        }

        // Password strength checker
        function checkPasswordStrength() {
            const password = document.getElementById('passwordInput').value;
            const strengthMeter = document.getElementById('strengthMeter');
            const bars = [1, 2, 3, 4].map(i => document.getElementById(`bar${i}`));
            const strengthText = document.getElementById('strengthText');
            
            if (password.length === 0) {
                strengthMeter.style.display = 'none';
                return;
            }
            
            strengthMeter.style.display = 'block';
            
            // Calculate strength
            let strength = 0;
            if (password.length >= 8) strength++;
            if (/[A-Z]/.test(password)) strength++;
            if (/[0-9]/.test(password)) strength++;
            if (/[^A-Za-z0-9]/.test(password)) strength++;
            
            // Update bars
            bars.forEach((bar, index) => {
                if (index < strength) {
                    let color;
                    switch(strength) {
                        case 1: color = '#ef4444'; break; // Weak - red
                        case 2: color = '#f97316'; break; // Fair - orange
                        case 3: color = '#eab308'; break; // Good - yellow
                        case 4: color = '#22c55e'; break; // Strong - green
                    }
                    bar.style.background = color;
                    bar.style.boxShadow = `0 0 8px ${color}80`;
                } else {
                    bar.style.background = 'rgba(255, 255, 255, 0.1)';
                    bar.style.boxShadow = 'none';
                }
            });
            
            // Update text
            const texts = ['Very Weak', 'Weak', 'Fair', 'Good', 'Strong'];
            strengthText.textContent = texts[strength];
            strengthText.style.color = strength >= 4 ? '#22c55e' : strength >= 3 ? '#eab308' : '#ef4444';
        }

        // Remember me toggle
        function toggleRememberMe() {
            const checkbox = document.getElementById('rememberCheckbox');
            checkbox.classList.toggle('checked');
            
            // Haptic feedback simulation
            if (navigator.vibrate) {
                navigator.vibrate(10);
            }
        }

        // Failed login
        function failedLogin() {
            failedAttempts++;
            
            // Shake animation for error
            const card = document.getElementById('loginCard');
            card.classList.add('error-shake');
            setTimeout(() => card.classList.remove('error-shake'), 500);
            
            // Show warning for multiple attempts
            if (failedAttempts === 2) {
                showWarning("Warning: One more failed attempt will lock your account for 3 minutes.");
            }
            
            // Check for suspicious mode
            if (failedAttempts >= 3) {
                enableSuspiciousMode();
                showLockTimer(180); // 3 minutes
            }
        }

        // Enable suspicious mode
        function enableSuspiciousMode() {
            if (isSuspiciousMode) return;
            
            isSuspiciousMode = true;
            document.body.classList.add('suspicious-mode');
            
            // Update security text
            document.getElementById('securityText').textContent = 
                '⚠️ Multiple failed attempts • Enhanced security enabled';
        }

        // Show forgot password
        function showForgotPassword() {
            const username = document.getElementById('usernameInput').value;
            if (!username) {
                showError("Please enter your username/email first.");
                return false;
            }
            
            // In a real implementation, this would make an AJAX call
            alert(`Password reset link will be sent to the email associated with: ${username}`);
            return false;
        }

        // Handle form submission
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const username = document.getElementById('usernameInput').value.trim();
            const password = document.getElementById('passwordInput').value;
            const loginButton = document.getElementById('loginButton');
            
            // Validate inputs
            if (!username || !password) {
                showError("Please fill in all fields.");
                return;
            }
            
            // Check if account is locked
            if (lockEndTime && Date.now() < lockEndTime) {
                showError("Account is locked. Please wait and try again.");
                return;
            }
            
            // Disable button and show loading
            loginButton.disabled = true;
            loginButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Logging in...';
            
            // Submit form
            this.submit();
        });

        // Entrance animation
        function animateEntrance() {
            gsap.from('.glass-card', {
                y: 60,
                opacity: 0,
                duration: 1.2,
                ease: 'power3.out'
            });
            
            // Animate blobs
            gsap.to('.blob-1', {
                x: 'random(-100, 100)',
                y: 'random(-50, 50)',
                duration: 20,
                repeat: -1,
                yoyo: true,
                ease: 'sine.inOut'
            });
            
            gsap.to('.blob-2', {
                x: 'random(-80, 80)',
                y: 'random(-40, 40)',
                duration: 25,
                repeat: -1,
                yoyo: true,
                ease: 'sine.inOut',
                delay: 2
            });
            
            gsap.to('.blob-3', {
                x: 'random(-60, 60)',
                y: 'random(-30, 30)',
                duration: 18,
                repeat: -1,
                yoyo: true,
                ease: 'sine.inOut',
                delay: 4
            });
        }

        // Mouse tilt effect
        function setupMouseTilt() {
            const card = document.getElementById('loginCard');
            
            card.addEventListener('mousemove', (e) => {
                const rect = card.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;
                
                const centerX = rect.width / 2;
                const centerY = rect.height / 2;
                
                const rotateY = ((x - centerX) / centerX) * 2;
                const rotateX = ((centerY - y) / centerY) * 2;
                
                gsap.to(card, {
                    rotateX: rotateX,
                    rotateY: rotateY,
                    duration: 0.5,
                    ease: 'power2.out'
                });
            });
            
            card.addEventListener('mouseleave', () => {
                gsap.to(card, {
                    rotateX: 0,
                    rotateY: 0,
                    duration: 0.5,
                    ease: 'power2.out'
                });
            });
        }
    </script>
</body>
</html>