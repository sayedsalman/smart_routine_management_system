<?php
require_once __DIR__ . "/database.php";

if (!function_exists('getDBConnection')) {
    die("❌ database.php loaded, but getDBConnection() NOT found");
}

$db = new Database();
$conn = $db->connect();

// Check username availability
if (isset($_GET['check_username'])) {
    $username = $_GET['check_username'];
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();
    echo json_encode(['available' => $stmt->num_rows === 0]);
    exit;
}

// Check email availability
if (isset($_GET['check_email'])) {
    $email = $_GET['check_email'];
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();
    echo json_encode(['available' => $stmt->num_rows === 0]);
    exit;
}

// Check mobile availability
if (isset($_GET['check_mobile'])) {
    $mobile = $_GET['check_mobile'];
    $stmt = $conn->prepare("SELECT id FROM users WHERE mobile = ?");
    $stmt->bind_param("s", $mobile);
    $stmt->execute();
    $stmt->store_result();
    echo json_encode(['available' => $stmt->num_rows === 0]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $role = $_POST['role'];
    $fullName = $_POST['fullName'];
    $dob = $_POST['dob'];
    $gender = $_POST['gender'];
    $mobile = $_POST['mobile'];
    $email = $_POST['email'];
    $division = $_POST['division'];
    $district = $_POST['district'];
    $upazilla = $_POST['upazilla'];
    $postal_code = $_POST['postal_code'];
    $address = $_POST['address'];
    $username = $_POST['username'];
    $password = $_POST['password'];
    $department = $_POST['department'];
    $designation = $_POST['designation'];
    $batch = $_POST['batch'];
    $section = $_POST['section'];
    $student_id = $_POST['student_id'];
    $teacher_id = $_POST['teacher_id'];

    // Password validation
    if (strlen($password) < 8) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters long']);
        exit;
    }
    if (!preg_match('/[A-Z]/', $password)) {
        echo json_encode(['success' => false, 'message' => 'Password must contain at least one uppercase letter']);
        exit;
    }
    if (!preg_match('/[a-z]/', $password)) {
        echo json_encode(['success' => false, 'message' => 'Password must contain at least one lowercase letter']);
        exit;
    }
    if (!preg_match('/[0-9]/', $password)) {
        echo json_encode(['success' => false, 'message' => 'Password must contain at least one number']);
        exit;
    }
    if (!preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/', $password)) {
        echo json_encode(['success' => false, 'message' => 'Password must contain at least one special character']);
        exit;
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $conn->begin_transaction();

    try {
        $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ? OR mobile = ?");
        $check_stmt->bind_param("sss", $username, $email, $mobile);
        $check_stmt->execute();
        $check_stmt->store_result();
        
        if ($check_stmt->num_rows > 0) {
            throw new Exception("Username, email or mobile already exists");
        }
        $check_stmt->close();

        $stmt = $conn->prepare("INSERT INTO users (role, username, email, mobile, password, status) VALUES (?, ?, ?, ?, ?, 'active')");
        $stmt->bind_param("sssss", $role, $username, $email, $mobile, $hashed_password);
        
        if (!$stmt->execute()) {
            throw new Exception("Error inserting user: " . $stmt->error);
        }
        
        $user_id = $stmt->insert_id;
        $stmt->close();

        $stmt = $conn->prepare("INSERT INTO user_profiles (user_id, full_name, dob, gender, division, district, upazilla, postal_code, address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("issssssss", $user_id, $fullName, $dob, $gender, $division, $district, $upazilla, $postal_code, $address);
        
        if (!$stmt->execute()) {
            throw new Exception("Error inserting profile: " . $stmt->error);
        }
        
        $stmt->close();

        if ($role == 'student') {
            $check_id_stmt = $conn->prepare("SELECT id FROM students WHERE student_id = ?");
            $check_id_stmt->bind_param("s", $student_id);
            $check_id_stmt->execute();
            $check_id_stmt->store_result();
            
            if ($check_id_stmt->num_rows > 0) {
                throw new Exception("Student ID already exists");
            }
            $check_id_stmt->close();
            
            $stmt = $conn->prepare("INSERT INTO students (user_id, student_id, department, batch, section) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("issss", $user_id, $student_id, $department, $batch, $section);
        } else {
            $check_id_stmt = $conn->prepare("SELECT id FROM teachers WHERE teacher_id = ?");
            $check_id_stmt->bind_param("s", $teacher_id);
            $check_id_stmt->execute();
            $check_id_stmt->store_result();
            
            if ($check_id_stmt->num_rows > 0) {
                throw new Exception("Teacher ID already exists");
            }
            $check_id_stmt->close();
            
            $stmt = $conn->prepare("INSERT INTO teachers (user_id, teacher_id, department, designation) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("isss", $user_id, $teacher_id, $department, $designation);
        }
        
        if (!$stmt->execute()) {
            throw new Exception("Error inserting role data: " . $stmt->error);
        }
        
        $stmt->close();
        
        $conn->commit();
        
        $_SESSION['registration_success'] = true;
        $_SESSION['user_id'] = $user_id;
        $_SESSION['role'] = $role;
        $_SESSION['username'] = $username;
        
        echo json_encode(['success' => true, 'message' => 'Registration successful!']);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Routine Manager - Registration</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&family=Nunito:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/ScrollTrigger.min.js"></script>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #4F46E5;
            --primary-light: #6366F1;
            --primary-dark: #3730A3;
            --success: #10B981;
            --warning: #F59E0B;
            --error: #EF4444;
            --info: #3B82F6;
            --dark: #1F2937;
            --bg: #F9FAFB;
            --card: #FFFFFF;
            --text: #111827;
            --text-muted: #6B7280;
            --text-light: #9CA3AF;
            --border: #E5E7EB;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            --radius-sm: 4px;
            --radius: 8px;
            --radius-lg: 12px;
            --radius-xl: 16px;
        }

        body {
            font-family: 'Nunito', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: var(--text);
            line-height: 1.6;
            min-height: 100vh;
            padding: 0;
            overflow-x: hidden;
        }

        .floating-shapes {
            position: fixed;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: -1;
        }

        .shape {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            animation: float 20s infinite linear;
        }

        .shape-1 { width: 80px; height: 80px; top: 10%; left: 5%; animation-delay: 0s; }
        .shape-2 { width: 120px; height: 120px; top: 20%; right: 10%; animation-delay: 5s; }
        .shape-3 { width: 60px; height: 60px; bottom: 30%; left: 15%; animation-delay: 10s; }
        .shape-4 { width: 100px; height: 100px; bottom: 20%; right: 5%; animation-delay: 15s; }

        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(180deg); }
        }

        header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 1.2rem 0;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
        }

        .header-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            opacity: 0;
            transform: translateX(-30px);
        }

        .logo-icon {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.3rem;
        }

        .logo-text h1 {
            font-family: 'Poppins', sans-serif;
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--text);
            line-height: 1.2;
        }

        .logo-text p {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .team-badge {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            padding: 0.5rem 1.2rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9rem;
            opacity: 0;
            transform: translateX(30px);
        }

        .main-container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
            display: grid;
            grid-template-columns: 1fr 1.5fr;
            gap: 3rem;
            animation: fadeIn 0.6s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .info-column {
            padding-top: 2rem;
        }

        .hero-section {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: var(--radius-xl);
            padding: 2.5rem;
            color: white;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
            opacity: 0;
            transform: translateY(20px);
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
        }

        .hero-title {
            font-family: 'Poppins', sans-serif;
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 1rem;
            position: relative;
            z-index: 1;
        }

        .hero-subtitle {
            font-size: 1.1rem;
            opacity: 0.9;
            margin-bottom: 2rem;
            position: relative;
            z-index: 1;
        }

        .problem-section {
            background: rgba(255, 255, 255, 0.9);
            border-radius: var(--radius-xl);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-lg);
            border-left: 4px solid var(--error);
            opacity: 0;
            transform: translateX(-30px);
        }

        .problem-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 1.5rem;
        }

        .problem-header i {
            color: var(--error);
            font-size: 1.5rem;
        }

        .problem-header h2 {
            font-family: 'Poppins', sans-serif;
            font-size: 1.5rem;
            color: var(--dark);
        }

        .problem-list {
            list-style: none;
            padding-left: 0;
        }

        .problem-list li {
            padding: 0.8rem 0;
            border-bottom: 1px solid rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .problem-list li:last-child {
            border-bottom: none;
        }

        .problem-list i {
            color: var(--error);
        }

        .solution-section {
            background: linear-gradient(135deg, var(--success) 0%, #34D399 100%);
            border-radius: var(--radius-xl);
            padding: 2rem;
            margin-bottom: 2rem;
            color: white;
            position: relative;
            overflow: hidden;
            opacity: 0;
            transform: translateX(-30px);
        }

        .solution-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(45deg, transparent 30%, rgba(255,255,255,0.1) 50%, transparent 70%);
            animation: shine 3s infinite;
        }

        @keyframes shine {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }

        .solution-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 1.5rem;
            position: relative;
            z-index: 1;
        }

        .solution-header i {
            font-size: 1.5rem;
        }

        .solution-header h2 {
            font-family: 'Poppins', sans-serif;
            font-size: 1.5rem;
        }

        .solution-features {
            display: grid;
            gap: 1rem;
            position: relative;
            z-index: 1;
        }

        .solution-feature {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0.8rem;
            background: rgba(255,255,255,0.1);
            border-radius: var(--radius);
            backdrop-filter: blur(5px);
        }

        .solution-feature i {
            font-size: 1.2rem;
        }

        .map-section {
            background: var(--card);
            border-radius: var(--radius-xl);
            padding: 2rem;
            margin-top: 2rem;
            box-shadow: var(--shadow-lg);
            opacity: 0;
            transform: translateY(20px);
        }

        .map-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 1.5rem;
        }

        .map-header i {
            color: var(--primary);
            font-size: 1.5rem;
        }

        .map-header h2 {
            font-family: 'Poppins', sans-serif;
            font-size: 1.5rem;
            color: var(--dark);
        }

        .map-visual {
            height: 200px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: var(--radius);
            position: relative;
            overflow: hidden;
            margin-bottom: 1rem;
        }

        .building {
            position: absolute;
            background: white;
            border-radius: 4px;
        }

        .building-1 {
            width: 60px;
            height: 100px;
            bottom: 0;
            left: 20%;
        }

        .building-2 {
            width: 80px;
            height: 150px;
            bottom: 0;
            left: 40%;
        }

        .building-3 {
            width: 70px;
            height: 120px;
            bottom: 0;
            left: 60%;
        }

        .map-label {
            text-align: center;
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        .contact-info {
            background: var(--card);
            border-radius: var(--radius-xl);
            padding: 1.5rem;
            margin-top: 2rem;
            box-shadow: var(--shadow);
            border-left: 4px solid var(--primary);
            opacity: 0;
            transform: translateY(20px);
        }

        .contact-info h3 {
            font-family: 'Poppins', sans-serif;
            margin-bottom: 1rem;
            color: var(--text);
        }

        .contact-details {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .contact-details a {
            color: var(--primary);
            text-decoration: none;
            transition: color 0.2s;
        }

        .contact-details a:hover {
            color: var(--primary-light);
            text-decoration: underline;
        }

        .form-column {
            padding-top: 1rem;
        }

        .form-container {
            background: var(--card);
            border-radius: var(--radius-xl);
            padding: 2.5rem;
            box-shadow: var(--shadow-xl);
            opacity: 0;
            transform: translateY(30px);
        }

        .form-header {
            text-align: center;
            margin-bottom: 2.5rem;
        }

        .form-title {
            font-family: 'Poppins', sans-serif;
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 0.5rem;
        }

        .form-subtitle {
            color: var(--text-muted);
            font-size: 1rem;
        }

        .form-section {
            margin-bottom: 2rem;
            padding-bottom: 2rem;
            border-bottom: 1px solid var(--border);
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 1.5rem;
            color: var(--text);
        }

        .section-header i {
            color: var(--primary);
            font-size: 1.2rem;
        }

        .section-header h3 {
            font-family: 'Poppins', sans-serif;
            font-size: 1.2rem;
            font-weight: 600;
        }

        .role-selection {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .role-card {
            border: 2px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }

        .role-card:hover {
            border-color: var(--primary-light);
            transform: translateY(-3px);
            box-shadow: var(--shadow);
        }

        .role-card.selected {
            border-color: var(--primary);
            background: rgba(79, 70, 229, 0.05);
            transform: translateY(-3px);
            box-shadow: var(--shadow);
        }

        .role-card i {
            font-size: 2rem;
            color: var(--primary);
        }

        .role-card h4 {
            font-family: 'Poppins', sans-serif;
            font-size: 1.1rem;
            color: var(--text);
        }

        .role-card p {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        label {
            display: block;
            font-weight: 500;
            margin-bottom: 0.5rem;
            color: var(--text);
            font-size: 0.95rem;
        }

        .form-control {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 2px solid var(--border);
            border-radius: var(--radius);
            font-family: 'Nunito', sans-serif;
            font-size: 1rem;
            color: var(--text);
            transition: all 0.2s ease;
            background: var(--card);
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .form-control::placeholder {
            color: var(--text-light);
        }

        .form-control:disabled {
            background: var(--bg);
            color: var(--text-muted);
            cursor: not-allowed;
        }

        .help-text {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-top: 0.4rem;
            display: block;
        }

        .id-preview {
            background: var(--bg);
            border-radius: var(--radius);
            padding: 1rem;
            margin-top: 1rem;
            font-family: 'Courier New', monospace;
            font-weight: 600;
            color: var(--primary);
            border: 1px dashed var(--border);
            text-align: center;
        }

        .address-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .password-container {
            position: relative;
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-light);
            cursor: pointer;
            font-size: 1.1rem;
        }

        .password-strength {
            margin-top: 0.5rem;
        }

        .strength-bar {
            height: 6px;
            background: var(--border);
            border-radius: 3px;
            margin-bottom: 0.5rem;
            overflow: hidden;
        }

        .strength-fill {
            height: 100%;
            width: 0%;
            background: var(--error);
            transition: all 0.3s ease;
            border-radius: 3px;
        }

        .strength-text {
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .password-rules {
            margin-top: 1rem;
            padding: 1rem;
            background: var(--bg);
            border-radius: var(--radius);
            font-size: 0.85rem;
        }

        .rule {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.4rem;
            color: var(--text-muted);
        }

        .rule.valid {
            color: var(--success);
        }

        .validation-message {
            font-size: 0.85rem;
            margin-top: 0.4rem;
            display: flex;
            align-items: center;
            gap: 5px;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .validation-message.valid {
            color: var(--success);
            opacity: 1;
        }

        .validation-message.invalid {
            color: var(--error);
            opacity: 1;
        }

        .availability-check {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 0.85rem;
            margin-top: 0.3rem;
        }

        .availability-check.available {
            color: var(--success);
        }

        .availability-check.taken {
            color: var(--error);
        }

        .submit-btn {
            width: 100%;
            padding: 1rem 2rem;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            color: white;
            border: none;
            border-radius: var(--radius);
            font-family: 'Poppins', sans-serif;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            margin-top: 1rem;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        .submit-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .success-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.95);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.5s ease;
        }

        .success-overlay.active {
            opacity: 1;
            pointer-events: all;
        }

        .success-icon {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, var(--success) 0%, #34D399 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 2rem;
            color: white;
            font-size: 3.5rem;
            transform: scale(0);
        }

        .success-text {
            font-family: 'Poppins', sans-serif;
            font-size: 2rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 1rem;
            text-align: center;
        }

        .redirect-text {
            font-size: 1.2rem;
            color: var(--text-muted);
            text-align: center;
        }

        footer {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 2rem 0;
            margin-top: 3rem;
            border-top: 1px solid var(--border);
            opacity: 0;
            transform: translateY(20px);
        }

        .footer-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
            text-align: center;
        }

        .copyright {
            color: var(--text-muted);
            margin-bottom: 1rem;
        }

        .footer-links {
            display: flex;
            justify-content: center;
            gap: 2rem;
        }

        .footer-links a {
            color: var(--text-muted);
            text-decoration: none;
            transition: color 0.2s;
        }

        .footer-links a:hover {
            color: var(--primary);
        }

        @media (max-width: 1024px) {
            .main-container {
                grid-template-columns: 1fr;
                gap: 2rem;
            }
            
            .info-column {
                padding-top: 0;
            }
        }

        @media (max-width: 768px) {
            .main-container {
                padding: 0 1rem;
            }
            
            .header-container {
                padding: 0 1rem;
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .team-badge {
                transform: translateY(10px);
            }
            
            .form-container {
                padding: 1.5rem;
            }
            
            .form-row,
            .role-selection,
            .address-grid {
                grid-template-columns: 1fr;
            }
            
            .role-card {
                flex-direction: row;
                text-align: left;
                padding: 1rem;
            }
            
            .role-card i {
                font-size: 1.5rem;
            }
            
            .hero-title {
                font-size: 1.8rem;
            }
        }

        .animate-in {
            opacity: 1 !important;
            transform: translate(0) !important;
        }
        
        .error-message {
            color: var(--error);
            font-size: 0.9rem;
            margin-top: 0.5rem;
            display: none;
        }
        
        
        .glass-card, .glass-nav, .glass-stat-card, .auth-panel {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 0.5px solid var(--glass-border);
            border-radius: 16px;
            box-shadow: var(--shadow-md);
        }
        
        
        .glass-nav {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            width: 90%;
            max-width: 1200px;
            padding: 15px 30px;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .nav-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
        }
        
        .nav-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        .logo-icon {
            font-size: 1.8rem;
        }
        
        .nav-links {
            display: flex;
            gap: 30px;
            list-style: none;
        }
        
        .nav-links a {
            color: var(--text-secondary);
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s;
            position: relative;
        }
        
        .nav-links a:hover {
            color: var(--primary-light);
        }
        
        .nav-links a::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--primary-light);
            transition: width 0.3s;
        }
        
        .nav-links a:hover::after {
            width: 100%;
        }
        
        .nav-actions {
            display: flex;
            gap: 15px;
        }
        
        .btn-login, .btn-register {
            padding: 8px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-login {
            background: transparent;
            border: 1px solid var(--glass-border);
            color: var(--text-primary);
        }
        
        .btn-register {
            background: linear-gradient(135deg, var(--primary-light), var(--accent-mint));
            border: none;
            color: var(--primary-dark);
        }
        
        .nav-toggle {
            display: none;
            background: transparent;
            border: none;
            color: var(--text-primary);
            font-size: 1.5rem;
            cursor: pointer;
        }
        
        
        
    </style>
</head>
<body>
    <div class="floating-shapes">
        <div class="shape shape-1"></div>
        <div class="shape shape-2"></div>
        <div class="shape shape-3"></div>
        <div class="shape shape-4"></div>
    </div>

    <header>
          <nav class="glass-nav">
        <div class="nav-container">
            <div class="nav-logo">
                <span class="logo-icon">📚</span>
                <span class="logo-text">SRMS</span>
            </div>
            <ul class="nav-links">
                <li><a href="#problem">Problem</a></li>
                <li><a href="#solution">Solution</a></li>
                <li><a href="#features">Features</a></li>
                <li><a href="#how-it-works">How it Works</a></li>
                <li><a href="#team">Team</a></li>
            </ul>
            <div class="nav-actions">
                <a href="https://salman.rfnhsc.com/routine/login.php" class="btn-login">Login</a>
<a href="https://salman.rfnhsc.com/routine/register.php" class="btn-register">Register</a>

            </div>
            <button class="nav-toggle">
                <i class="fas fa-bars"></i>
            </button>
        </div>
    </nav>
    </header>

    <div class="main-container">
        <div class="info-column">
            <div class="hero-section">
                <h1 class="hero-title">Welcome to SRMS</h1>
                <p class="hero-subtitle">Revolutionizing academic scheduling with intelligent automation</p>
            </div>

            <div class="problem-section">
                <div class="problem-header">
                    <i class="fas fa-exclamation-triangle"></i>
                    <h2>The Academic Scheduling Crisis</h2>
                </div>
                <ul class="problem-list">
                    <li><i class="fas fa-clock"></i> Long gaps between classes wasting student time</li>
                    <li><i class="fas fa-chalkboard-teacher"></i> Teacher burnout from continuous classes</li>
                    <li><i class="fas fa-calendar-times"></i> Manual scheduling causing conflicts and errors</li>
                    <li><i class="fas fa-users"></i> No consideration for individual preferences</li>
                    <li><i class="fas fa-chart-line"></i> Inefficient resource allocation</li>
                </ul>
            </div>

            <div class="solution-section">
                <div class="solution-header">
                    <i class="fas fa-lightbulb"></i>
                    <h2>Our Intelligent Solution</h2>
                </div>
                <div class="solution-features">
                    <div class="solution-feature">
                        <i class="fas fa-brain"></i>
                        <span>AI-powered scheduling algorithms</span>
                    </div>
                    <div class="solution-feature">
                        <i class="fas fa-balance-scale"></i>
                        <span>Balanced workload distribution</span>
                    </div>
                    <div class="solution-feature">
                        <i class="fas fa-user-clock"></i>
                        <span>Personalized time preferences</span>
                    </div>
                    <div class="solution-feature">
                        <i class="fas fa-sync-alt"></i>
                        <span>Real-time conflict resolution</span>
                    </div>
                </div>
            </div>

            <div class="map-section">
                <div class="map-header">
                    <i class="fas fa-map-marked-alt"></i>
                    <h2>SRMS Headquarters</h2>
                </div>
                <div class="map-visual">
                    <div class="building building-1"></div>
                    <div class="building building-2"></div>
                    <div class="building building-3"></div>
                </div>
                <div class="map-label">
                    <p><i class="fas fa-map-pin"></i> LOSERS</p>
                    <p>PCIU</p>
                </div>
            </div>

            <div class="contact-info">
                <h3>Contact Information</h3>
                <div class="contact-details">
                    <p><i class="fas fa-map-marker-alt"></i> LOSERS</p>
                    <p><i class="fas fa-envelope"></i> <a href="mailto:support@routinemanager.edu">support@salman.rfnhsc.com</a></p>
                    <p><i class="fas fa-phone"></i> <a href="tel:+8801609506363">+880 1609-506363</a></p>
                </div>
            </div>
        </div>

        <div class="form-column">
            <div class="form-container">
                <form id="registrationForm" method="POST">
                    <input type="hidden" name="role" id="formRole" value="student">
                    <input type="hidden" name="student_id" id="formStudentId" value="">
                    <input type="hidden" name="teacher_id" id="formTeacherId" value="">
                    
                    <div class="form-header">
                        <h2 class="form-title">Create Your Account</h2>
                        <p class="form-subtitle">Join the academic scheduling revolution</p>
                    </div>

                    <div class="form-section">
                        <div class="section-header">
                            <i class="fas fa-user-tag"></i>
                            <h3>Select Your Role</h3>
                        </div>
                        <div class="role-selection">
                            <div class="role-card selected" data-role="student">
                                <i class="fas fa-graduation-cap"></i>
                                <div>
                                    <h4>Student</h4>
                                    <p>Access optimized class schedules</p>
                                </div>
                            </div>
                            <div class="role-card" data-role="teacher">
                                <i class="fas fa-chalkboard-teacher"></i>
                                <div>
                                    <h4>Teacher</h4>
                                    <p>Manage teaching schedules</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="section-header">
                            <i class="fas fa-building"></i>
                            <h3>Academic Information</h3>
                        </div>
                        
                        <div class="form-group">
                            <label for="department">Department *</label>
                            <select class="form-control" id="department" name="department" required>
                                <option value="">-- Choose Department --</option>
                                <option value="CSE">Computer Science & Engineering (CSE)</option>
                                <option value="EEE">Electrical & Electronics Engineering (EEE)</option>
                                <option value="CEN">Computer Engineering (CEN)</option>
                                <option value="ENG">English (ENG)</option>
                                <option value="BBA">Business Administration (BBA)</option>
                                <option value="LAW">Law (LAW)</option>
                            </select>
                        </div>
                        
                        <div id="studentFields" class="conditional-section">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="batch">Batch *</label>
                                    <select class="form-control" id="batch" name="batch" required>
                                        <option value="">-- Select Batch --</option>
                                        <option value="031">031</option>
                                        <option value="032">032</option>
                                        <option value="033">033</option>
                                        <option value="034">034</option>
                                        <option value="035">035</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="section">Section *</label>
                                    <select class="form-control" id="section" name="section" required>
                                        <option value="">-- Select Section --</option>
                                        <option value="A">A</option>
                                        <option value="B">B</option>
                                        <option value="C">C</option>
                                        <option value="D">D</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div id="teacherFields" class="conditional-section" style="display: none;">
                            <div class="form-group">
                                <label for="designation">Designation *</label>
                                <select class="form-control" id="designation" name="designation">
                                    <option value="">-- Select Designation --</option>
                                    <option value="professor">Professor</option>
                                    <option value="associate_professor">Associate Professor</option>
                                    <option value="assistant_professor">Assistant Professor</option>
                                    <option value="lecturer">Lecturer</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label id="idLabel">ID Information</label>
                            <div id="studentIdSection">
                                <div class="form-row">
                                    <div class="form-group">
                                        <input type="text" class="form-control" id="dept-code" placeholder="Department Code" readonly>
                                    </div>
                                    <div class="form-group">
                                        <input type="text" class="form-control" id="batch-code" placeholder="Batch Code" maxlength="3">
                                    </div>
                                    <div class="form-group">
                                        <input type="text" class="form-control" id="student-num" placeholder="Student Number" maxlength="5">
                                    </div>
                                </div>
                                <div class="id-preview" id="idPreview">CSE 031 08201</div>
                            </div>
                            <div id="teacherIdField" style="display: none;">
                                <input type="text" class="form-control" id="teacher-id" name="teacher_id_input" placeholder="Enter your teacher ID (e.g., T-CSE-001)">
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="section-header">
                            <i class="fas fa-user-circle"></i>
                            <h3>Personal Information</h3>
                        </div>
                        
                        <div class="form-group">
                            <label for="fullName">Full Name *</label>
                            <input type="text" class="form-control" id="fullName" name="fullName" placeholder="Enter your full name" required>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="dob">Date of Birth *</label>
                                <input type="date" class="form-control" id="dob" name="dob" required>
                            </div>
                            <div class="form-group">
                                <label for="gender">Gender</label>
                                <select class="form-control" id="gender" name="gender">
                                    <option value="">-- Select Gender --</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="mobile">Mobile Number *</label>
                                <input type="tel" class="form-control" id="mobile" name="mobile" placeholder="+8801609506363" required>
                                <div class="availability-check" id="mobileCheck"></div>
                            </div>
                            <div class="form-group">
                                <label for="email">Email Address *</label>
                                <input type="email" class="form-control" id="email" name="email" placeholder="example@university.edu" required>
                                <div class="availability-check" id="emailCheck"></div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="section-header">
                            <i class="fas fa-map-marker-alt"></i>
                            <h3>Address Information</h3>
                        </div>
                        
                        <div class="address-grid">
                            <div class="form-group">
                                <label for="division">Division *</label>
                                <select class="form-control" id="division" name="division" required>
                                    <option value="">-- Select Division --</option>
                                    <option value="dhaka">Dhaka</option>
                                    <option value="chittagong">Chittagong</option>
                                    <option value="rajshahi">Rajshahi</option>
                                    <option value="khulna">Khulna</option>
                                    <option value="barisal">Barisal</option>
                                    <option value="sylhet">Sylhet</option>
                                    <option value="rangpur">Rangpur</option>
                                    <option value="mymensingh">Mymensingh</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="district">District *</label>
                                <select class="form-control" id="district" name="district" disabled required>
                                    <option value="">-- Select District --</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="upazilla">Upazilla/Thana</label>
                                <select class="form-control" id="upazilla" name="upazilla" disabled>
                                    <option value="">-- Select Upazilla --</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="postal_code">Postal Code</label>
                                <input type="text" class="form-control" id="postal_code" name="postal_code" placeholder="Postal Code">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="address">Full Address</label>
                            <textarea class="form-control" id="address" name="address" rows="3" placeholder="House No, Road No, Area, Village"></textarea>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="section-header">
                            <i class="fas fa-key"></i>
                            <h3>Login Credentials</h3>
                        </div>
                        
                        <div class="form-group">
                            <label for="username">Username *</label>
                            <input type="text" class="form-control" id="username" name="username" placeholder="Choose a unique username" required>
                            <div class="availability-check" id="usernameCheck"></div>
                        </div>
                        
                        <div class="form-group">
                            <label for="password">Password *</label>
                            <div class="password-container">
                                <input type="password" class="form-control" id="password" name="password" placeholder="Create a secure password" required>
                                <button type="button" class="password-toggle" id="togglePassword">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div class="password-strength">
                                <div class="strength-bar">
                                    <div class="strength-fill" id="strengthFill"></div>
                                </div>
                                <div class="strength-text" id="strengthText">Password strength</div>
                            </div>
                            
                            <div class="password-rules">
                                <div class="rule" id="ruleLength">Minimum 8 characters</div>
                                <div class="rule" id="ruleUpper">At least 1 uppercase letter</div>
                                <div class="rule" id="ruleLower">At least 1 lowercase letter</div>
                                <div class="rule" id="ruleNumber">At least 1 number</div>
                                <div class="rule" id="ruleSymbol">At least 1 special character</div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="confirmPassword">Confirm Password *</label>
                            <div class="password-container">
                                <input type="password" class="form-control" id="confirmPassword" placeholder="Re-enter your password" required>
                                <button type="button" class="password-toggle" id="toggleConfirmPassword">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                            <div class="validation-message" id="passwordMatch"></div>
                        </div>
                        
                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox" id="terms" required>
                                <span>I agree to the <a href="#">Terms of Service</a> and <a href="#">Privacy Policy</a></span>
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="submit-btn" id="registerBtn" disabled>
                        <span>Create Account</span>
                    </button>
                    
                    <div style="text-align: center; margin-top: 1.5rem;">
                        <p style="color: var(--text-muted); font-size: 0.95rem;">
                            Already have an account? <a href="login.php" style="color: var(--primary); text-decoration: none; font-weight: 500;">Sign In</a>
                        </p>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="success-overlay" id="successOverlay">
        <div class="success-icon">
            <i class="fas fa-check"></i>
        </div>
        <div class="success-text">Registration Successful!</div>
        <div class="redirect-text">Your account has been created.<br>Redirecting to login page...</div>
    </div>

    <footer>
        <div class="footer-content">
            <div class="copyright">
                © 2026 Smart Routine Manager. All rights reserved.
            </div>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            gsap.to('.logo', {duration: 0.8, x: 0, opacity: 1, ease: 'power3.out', delay: 0.2});
            gsap.to('.team-badge', {duration: 0.8, x: 0, opacity: 1, ease: 'power3.out', delay: 0.4});
            gsap.to('.hero-section', {duration: 0.8, y: 0, opacity: 1, ease: 'power3.out', delay: 0.6});
            gsap.to('.problem-section', {duration: 0.8, x: 0, opacity: 1, ease: 'power3.out', delay: 0.8});
            gsap.to('.solution-section', {duration: 0.8, x: 0, opacity: 1, ease: 'power3.out', delay: 1.0});
            gsap.to('.map-section', {duration: 0.8, y: 0, opacity: 1, ease: 'power3.out', delay: 1.2});
            gsap.to('.contact-info', {duration: 0.8, y: 0, opacity: 1, ease: 'power3.out', delay: 1.4});
            gsap.to('.form-container', {duration: 1, y: 0, opacity: 1, ease: 'power3.out', delay: 1});
            
            gsap.to('footer', {
                duration: 0.8,
                y: 0,
                opacity: 1,
                ease: 'power3.out',
                delay: 1.4,
                scrollTrigger: {trigger: 'footer', start: 'top bottom-=100', toggleActions: 'play none none none'}
            });

            const roleCards = document.querySelectorAll('.role-card');
            const studentFields = document.getElementById('studentFields');
            const teacherFields = document.getElementById('teacherFields');
            const studentIdSection = document.getElementById('studentIdSection');
            const teacherIdField = document.getElementById('teacherIdField');
            const idLabel = document.getElementById('idLabel');
            const formRole = document.getElementById('formRole');
            
            roleCards.forEach(card => {
                card.addEventListener('click', function() {
                    roleCards.forEach(c => c.classList.remove('selected'));
                    this.classList.add('selected');
                    gsap.fromTo(this, {scale: 0.95}, {scale: 1, duration: 0.3, ease: 'back.out(1.7)'});
                    const role = this.getAttribute('data-role');
                    formRole.value = role;
                    
                    if (role === 'student') {
                        studentFields.style.display = 'block';
                        teacherFields.style.display = 'none';
                        studentIdSection.style.display = 'block';
                        teacherIdField.style.display = 'none';
                        idLabel.textContent = 'Student ID (Auto-generated)';
                        animateFields(studentFields);
                    } else {
                        studentFields.style.display = 'none';
                        teacherFields.style.display = 'block';
                        studentIdSection.style.display = 'none';
                        teacherIdField.style.display = 'block';
                        idLabel.textContent = 'Teacher ID';
                        animateFields(teacherFields);
                    }
                    updateIdPreview();
                });
            });
            
            function animateFields(element) {
                gsap.fromTo(element, {y: 20, opacity: 0}, {y: 0, opacity: 1, duration: 0.5, ease: 'power3.out'});
            }

            const departmentSelect = document.getElementById('department');
            const batchSelect = document.getElementById('batch');
            const sectionSelect = document.getElementById('section');
            const deptCodeInput = document.getElementById('dept-code');
            const batchCodeInput = document.getElementById('batch-code');
            const studentNumInput = document.getElementById('student-num');
            const idPreview = document.getElementById('idPreview');
            const formStudentId = document.getElementById('formStudentId');
            const formTeacherId = document.getElementById('formTeacherId');
            const teacherIdInput = document.getElementById('teacher-id');
            
            departmentSelect.addEventListener('change', function() {
                if (this.value) {
                    deptCodeInput.value = this.value;
                    updateIdPreview();
                }
            });
            
            batchSelect.addEventListener('change', function() {
                if (this.value) {
                    batchCodeInput.value = this.value;
                    updateIdPreview();
                }
            });
            
            sectionSelect.addEventListener('change', function() {
                if (this.value) {
                    const randomNum = Math.floor(Math.random() * 999) + 1;
                    const formattedNum = randomNum.toString().padStart(3, '0');
                    studentNumInput.value = formattedNum;
                    updateIdPreview();
                }
            });
            
            function updateIdPreview() {
                const role = document.querySelector('.role-card.selected').getAttribute('data-role');
                if (role === 'student') {
                    const dept = deptCodeInput.value || 'CSE';
                    const batch = batchCodeInput.value || '031';
                    const studentNum = studentNumInput.value || '08201';
                    const studentId = `${dept}-${batch}-${studentNum}`;
                    idPreview.textContent = studentId;
                    formStudentId.value = studentId;
                } else {
                    const teacherId = teacherIdInput.value;
                    idPreview.textContent = teacherId || 'Teacher ID will appear here';
                    formTeacherId.value = teacherId;
                }
            }
            
            teacherIdInput.addEventListener('input', function() {
                updateIdPreview();
            });
            
            studentNumInput.value = '08201';
            updateIdPreview();

            const divisionSelect = document.getElementById('division');
            const districtSelect = document.getElementById('district');
            const upazillaSelect = document.getElementById('upazilla');
            
            const addressData = {
                dhaka: {
                    districts: ['Dhaka', 'Gazipur', 'Narayanganj', 'Tangail', 'Narsingdi', 'Kishoreganj', 'Manikganj', 'Munshiganj', 'Rajbari', 'Faridpur', 'Gopalganj', 'Madaripur', 'Shariatpur'],
                    upazillas: {
                        'Dhaka': ['Dhanmondi', 'Gulshan', 'Mirpur', 'Uttara', 'Mohammadpur', 'Motijheel', 'Ramna', 'Pallabi', 'Badda', 'Kafrul', 'Cantonment'],
                        'Gazipur': ['Gazipur Sadar', 'Kaliakair', 'Sreepur', 'Kapasia', 'Kaliganj'],
                        'Narayanganj': ['Narayanganj Sadar', 'Fatullah', 'Bandar', 'Araihazar', 'Sonargaon'],
                        'Tangail': ['Tangail Sadar', 'Mirzapur', 'Ghatail', 'Kalihati', 'Sakhipur', 'Basail']
                    }
                },
                chittagong: {
                    districts: ['Chittagong', "Cox's Bazar", 'Comilla', 'Noakhali', 'Chandpur', 'Lakshmipur', 'Feni', 'Brahmanbaria'],
                    upazillas: {
                        'Chittagong': ['Chandgaon', 'Double Mooring', 'Kotwali', 'Panchlaish', 'Bandar', 'Pahartali', 'Patenga'],
                        "Cox's Bazar": ["Cox's Bazar Sadar", 'Teknaf', 'Ukhia', 'Maheshkhali', 'Ramu','Chakaria','Pekua'],
                        'Comilla': ['Comilla Sadar', 'Chandina', 'Debidwar', 'Barura', 'Brahmanpara', 'Burichang'],
                        'Noakhali': ['Noakhali Sadar', 'Begumganj', 'Chatkhil', 'Senbagh', 'Kabirhat']
                    }
                },
                rajshahi: {
                    districts: ['Rajshahi', 'Bogra', 'Pabna', 'Sirajganj', 'Natore', 'Naogaon', 'Joypurhat', 'Chapainawabganj'],
                    upazillas: {
                        'Rajshahi': ['Rajshahi Sadar', 'Paba', 'Durgapur', 'Bagha', 'Charghat', 'Bagmara'],
                        'Bogra': ['Bogra Sadar', 'Gabtali', 'Sherpur', 'Dhunat', 'Adamdighi', 'Nandigram'],
                        'Pabna': ['Pabna Sadar', 'Ishwardi', 'Bera', 'Santhia', 'Sujanagar']
                    }
                },
                khulna: {
                    districts: ['Khulna', 'Bagerhat', 'Satkhira', 'Jessore', 'Narail', 'Magura', 'Jhenaidah', 'Kushtia', 'Chuadanga', 'Meherpur'],
                    upazillas: {
                        'Khulna': ['Khulna Sadar', 'Sonadanga', 'Khalishpur', 'Daulatpur', 'Khan Jahan Ali'],
                        'Bagerhat': ['Bagerhat Sadar', 'Mongla', 'Morrelganj', 'Rampal', 'Fakirhat'],
                        'Satkhira': ['Satkhira Sadar', 'Assasuni', 'Kaliganj', 'Debhata', 'Shyamnagar']
                    }
                },
                barisal: {
                    districts: ['Barisal', 'Patuakhali', 'Pirojpur', 'Bhola', 'Barguna', 'Jhalokati'],
                    upazillas: {
                        'Barisal': ['Barisal Sadar', 'Babuganj', 'Bakerganj', 'Banaripara', 'Gaurnadi', 'Hizla'],
                        'Patuakhali': ['Patuakhali Sadar', 'Bauphal', 'Dashmina', 'Dumki', 'Kalapara', 'Mirzaganj']
                    }
                },
                sylhet: {
                    districts: ['Sylhet', 'Moulvibazar', 'Habiganj', 'Sunamganj'],
                    upazillas: {
                        'Sylhet': ['Sylhet Sadar', 'Beanibazar', 'Bishwanath', 'Companiganj', 'Fenchuganj', 'Golapganj'],
                        'Moulvibazar': ['Moulvibazar Sadar', 'Barlekha', 'Juri', 'Kamalganj', 'Kulaura', 'Rajnagar']
                    }
                },
                rangpur: {
                    districts: ['Rangpur', 'Dinajpur', 'Gaibandha', 'Kurigram', 'Lalmonirhat', 'Nilphamari', 'Panchagarh', 'Thakurgaon'],
                    upazillas: {
                        'Rangpur': ['Rangpur Sadar', 'Badarganj', 'Gangachara', 'Kaunia', 'Mithapukur', 'Pirgachha'],
                        'Dinajpur': ['Dinajpur Sadar', 'Birampur', 'Birganj', 'Bochaganj', 'Chirirbandar', 'Fulbari']
                    }
                },
                mymensingh: {
                    districts: ['Mymensingh', 'Jamalpur', 'Netrokona', 'Sherpur'],
                    upazillas: {
                        'Mymensingh': ['Mymensingh Sadar', 'Bhaluka', 'Dhobaura', 'Fulbaria', 'Gaffargaon', 'Gauripur'],
                        'Jamalpur': ['Jamalpur Sadar', 'Bakshiganj', 'Dewanganj', 'Islampur', 'Madarganj', 'Melandaha']
                    }
                }
            };
            
            divisionSelect.addEventListener('change', function() {
                const division = this.value;
                if (division && addressData[division]) {
                    districtSelect.disabled = false;
                    districtSelect.innerHTML = '<option value="">-- Select District --</option>';
                    addressData[division].districts.forEach(district => {
                        const option = document.createElement('option');
                        option.value = district;
                        option.textContent = district;
                        districtSelect.appendChild(option);
                    });
                    upazillaSelect.innerHTML = '<option value="">-- Select Upazilla --</option>';
                    upazillaSelect.disabled = true;
                    gsap.from(districtSelect, {duration: 0.3, y: 10, opacity: 0, ease: 'power3.out'});
                } else {
                    districtSelect.innerHTML = '<option value="">-- Select District --</option>';
                    districtSelect.disabled = true;
                    upazillaSelect.innerHTML = '<option value="">-- Select Upazilla --</option>';
                    upazillaSelect.disabled = true;
                }
            });
            
            districtSelect.addEventListener('change', function() {
                const division = divisionSelect.value;
                const district = this.value;
                if (division && district && addressData[division] && addressData[division].upazillas[district]) {
                    upazillaSelect.disabled = false;
                    upazillaSelect.innerHTML = '<option value="">-- Select Upazilla --</option>';
                    addressData[division].upazillas[district].forEach(upazilla => {
                        const option = document.createElement('option');
                        option.value = upazilla;
                        option.textContent = upazilla;
                        upazillaSelect.appendChild(option);
                    });
                    gsap.from(upazillaSelect, {duration: 0.3, y: 10, opacity: 0, ease: 'power3.out'});
                } else {
                    upazillaSelect.innerHTML = '<option value="">-- Select Upazilla --</option>';
                    upazillaSelect.disabled = true;
                }
            });

            function checkAvailability(type, value, element) {
                if (value.length < 3) {
                    element.innerHTML = '';
                    return;
                }
                fetch(`?check_${type}=${encodeURIComponent(value)}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.available) {
                            element.innerHTML = '<i class="fas fa-check-circle"></i> Available';
                            element.className = 'availability-check available';
                        } else {
                            element.innerHTML = '<i class="fas fa-times-circle"></i> Already taken';
                            element.className = 'availability-check taken';
                        }
                        gsap.fromTo(element, {opacity: 0, y: -5}, {opacity: 1, y: 0, duration: 0.3});
                    });
            }

            const usernameInput = document.getElementById('username');
            const usernameCheck = document.getElementById('usernameCheck');
            const emailInput = document.getElementById('email');
            const emailCheck = document.getElementById('emailCheck');
            const mobileInput = document.getElementById('mobile');
            const mobileCheck = document.getElementById('mobileCheck');
            
            usernameInput.addEventListener('input', function() {
                checkAvailability('username', this.value.trim(), usernameCheck);
            });
            
            emailInput.addEventListener('input', function() {
                const email = this.value.trim();
                if (email.includes('@')) {
                    checkAvailability('email', email, emailCheck);
                } else {
                    emailCheck.innerHTML = '';
                }
            });
            
            mobileInput.addEventListener('input', function() {
                checkAvailability('mobile', this.value.trim(), mobileCheck);
            });

            const passwordInput = document.getElementById('password');
            const strengthFill = document.getElementById('strengthFill');
            const strengthText = document.getElementById('strengthText');
            
            passwordInput.addEventListener('input', function() {
                const password = this.value;
                let strength = 0;
                let color = '#EF4444';
                let text = 'Weak Password';
                
                const hasLength = password.length >= 8;
                const hasUpper = /[A-Z]/.test(password);
                const hasLower = /[a-z]/.test(password);
                const hasNumber = /\d/.test(password);
                const hasSymbol = /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password);
                
                updateRule('ruleLength', hasLength);
                updateRule('ruleUpper', hasUpper);
                updateRule('ruleLower', hasLower);
                updateRule('ruleNumber', hasNumber);
                updateRule('ruleSymbol', hasSymbol);
                
                strength += hasLength ? 20 : 0;
                strength += hasUpper ? 20 : 0;
                strength += hasLower ? 20 : 0;
                strength += hasNumber ? 20 : 0;
                strength += hasSymbol ? 20 : 0;
                
                strengthFill.style.width = `${strength}%`;
                
                if (strength <= 40) {
                    color = '#EF4444';
                    text = 'Weak Password';
                } else if (strength <= 80) {
                    color = '#F59E0B';
                    text = 'Medium Password';
                } else {
                    color = '#10B981';
                    text = 'Strong Password';
                }
                
                strengthFill.style.backgroundColor = color;
                strengthText.textContent = text;
                strengthText.style.color = color;
                
                gsap.fromTo(strengthFill, {scaleX: 0.8}, {scaleX: 1, duration: 0.3, ease: 'power2.out'});
                validateForm();
            });
            
            function updateRule(elementId, isValid) {
                const element = document.getElementById(elementId);
                if (isValid) {
                    element.classList.add('valid');
                    element.innerHTML = '<i class="fas fa-check"></i> ' + element.textContent.replace('✖ ', '').replace('✓ ', '');
                } else {
                    element.classList.remove('valid');
                    element.innerHTML = '<i class="fas fa-times"></i> ' + element.textContent.replace('✖ ', '').replace('✓ ', '');
                }
            }

            const confirmPasswordInput = document.getElementById('confirmPassword');
            const passwordMatch = document.getElementById('passwordMatch');
            
            function checkPasswordMatch() {
                const password = passwordInput.value;
                const confirmPassword = confirmPasswordInput.value;
                if (confirmPassword.length === 0) {
                    passwordMatch.className = 'validation-message';
                    passwordMatch.innerHTML = '';
                    return;
                }
                if (password === confirmPassword && password.length > 0) {
                    passwordMatch.className = 'validation-message valid';
                    passwordMatch.innerHTML = '<i class="fas fa-check-circle"></i> Passwords match';
                } else {
                    passwordMatch.className = 'validation-message invalid';
                    passwordMatch.innerHTML = '<i class="fas fa-times-circle"></i> Passwords do not match';
                }
                gsap.fromTo(passwordMatch, {opacity: 0, y: -5}, {opacity: 1, y: 0, duration: 0.3});
                validateForm();
            }
            
            passwordInput.addEventListener('input', checkPasswordMatch);
            confirmPasswordInput.addEventListener('input', checkPasswordMatch);

            const togglePasswordBtn = document.getElementById('togglePassword');
            const toggleConfirmPasswordBtn = document.getElementById('toggleConfirmPassword');
            
            togglePasswordBtn.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                const icon = this.querySelector('i');
                icon.classList.toggle('fa-eye');
                icon.classList.toggle('fa-eye-slash');
                gsap.fromTo(this, {scale: 0.9}, {scale: 1, duration: 0.2, ease: 'back.out(1.7)'});
            });
            
            toggleConfirmPasswordBtn.addEventListener('click', function() {
                const type = confirmPasswordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                confirmPasswordInput.setAttribute('type', type);
                const icon = this.querySelector('i');
                icon.classList.toggle('fa-eye');
                icon.classList.toggle('fa-eye-slash');
                gsap.fromTo(this, {scale: 0.9}, {scale: 1, duration: 0.2, ease: 'back.out(1.7)'});
            });

            const registerBtn = document.getElementById('registerBtn');
            const successOverlay = document.getElementById('successOverlay');
            const registrationForm = document.getElementById('registrationForm');
            
            const today = new Date();
            const minDate = new Date(today.getFullYear() - 18, today.getMonth(), today.getDate());
            document.getElementById('dob').max = minDate.toISOString().split('T')[0];
            const maxDate = new Date(today.getFullYear() - 100, today.getMonth(), today.getDate());
            document.getElementById('dob').min = maxDate.toISOString().split('T')[0];
            
            function validateForm() {
                const password = passwordInput.value;
                const confirmPassword = confirmPasswordInput.value;
                const hasLength = password.length >= 8;
                const hasUpper = /[A-Z]/.test(password);
                const hasLower = /[a-z]/.test(password);
                const hasNumber = /\d/.test(password);
                const hasSymbol = /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password);
                const passwordsMatch = password === confirmPassword && password.length > 0;
                
                const isPasswordValid = hasLength && hasUpper && hasLower && hasNumber && hasSymbol;
                registerBtn.disabled = !(isPasswordValid && passwordsMatch && document.getElementById('terms').checked);
            }
            
            document.getElementById('terms').addEventListener('change', validateForm);
            
            registrationForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const password = passwordInput.value;
                const hasLength = password.length >= 8;
                const hasUpper = /[A-Z]/.test(password);
                const hasLower = /[a-z]/.test(password);
                const hasNumber = /\d/.test(password);
                const hasSymbol = /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password);
                
                if (!hasLength || !hasUpper || !hasLower || !hasNumber || !hasSymbol) {
                    alert('Password must meet all requirements:\n• Minimum 8 characters\n• At least 1 uppercase letter\n• At least 1 lowercase letter\n• At least 1 number\n• At least 1 special character');
                    return;
                }
                
                registerBtn.disabled = true;
                const originalText = registerBtn.innerHTML;
                registerBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating Account...';
                
                const formData = new FormData(registrationForm);
                fetch('', {method: 'POST', body: formData})
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            successOverlay.classList.add('active');
                            gsap.to('.success-icon', {scale: 1, duration: 0.5, ease: 'elastic.out(1, 0.5)', delay: 0.2});
                            gsap.from('.success-text', {y: 20, opacity: 0, duration: 0.8, delay: 0.5, ease: 'power3.out'});
                            gsap.from('.redirect-text', {y: 20, opacity: 0, duration: 0.8, delay: 0.7, ease: 'power3.out'});
                            setTimeout(() => {window.location.href = 'login.php';}, 3000);
                        } else {
                            alert('Registration failed: ' + data.message);
                            registerBtn.disabled = false;
                            registerBtn.innerHTML = originalText;
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred. Please try again.');
                        registerBtn.disabled = false;
                        registerBtn.innerHTML = originalText;
                    });
            });

            const formControls = document.querySelectorAll('.form-control');
            formControls.forEach(control => {
                control.addEventListener('focus', function() {
                    gsap.to(this, {scale: 1.02, duration: 0.2, ease: 'power2.out'});
                });
                control.addEventListener('blur', function() {
                    gsap.to(this, {scale: 1, duration: 0.2, ease: 'power2.out'});
                });
            });
        });
    </script>
</body>
</html>