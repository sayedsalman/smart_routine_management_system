<?php

session_start();


if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || $_SESSION['role'] !== 'student') {
    header('Location: ../../login.php');
    exit();
}
date_default_timezone_set('Asia/Dhaka');

require_once '../../database.php';

$conn = getDBConnection();
$user_id = $_SESSION['user_id'];


$student_query = $conn->prepare("
    SELECT s.*, 
           b.semester as batch_semester, b.section as batch_section, b.type as batch_type,
           b.off_day1, b.off_day2,
           d.name as department_name, d.code as department_code,
           u.username, u.email, u.mobile, u.status,
           up.full_name, up.dob, up.gender, up.division, up.district, up.upazilla, up.postal_code, up.address
    FROM students s
    JOIN users u ON s.user_id = u.id
    JOIN batches b ON s.batch_id = b.id
    JOIN departments d ON s.department_id = d.id
    LEFT JOIN user_profiles up ON u.id = up.user_id
    WHERE s.user_id = ?
");
$student_query->bind_param("i", $user_id);
$student_query->execute();
$student = $student_query->get_result()->fetch_assoc();

if (!$student) {
    die("Student record not found.");
}

// Store useful session data
$_SESSION['student_id'] = $student['student_id'];
$_SESSION['batch_id'] = $student['batch_id'];
$_SESSION['department_id'] = $student['department_id'];
$_SESSION['department_code'] = $student['department_code'];
$_SESSION['batch_semester'] = $student['batch_semester'];
$_SESSION['section'] = $student['batch_section'];

$batch_id = $student['batch_id'];
$batch_semester = $student['batch_semester'];
$section = $student['batch_section'];
$department_code = $student['department_code'];
$student_id = $student['student_id'];

// Get courses for this batch
$courses_query = $conn->prepare("
    SELECT c.id, c.code, c.title, c.credit, c.weekly_classes
    FROM courses c
    JOIN batch_courses bc ON c.id = bc.course_id
    WHERE bc.batch_id = ?
    ORDER BY c.code
");
$courses_query->bind_param("i", $batch_id);
$courses_query->execute();
$courses = $courses_query->get_result()->fetch_all(MYSQLI_ASSOC);

// Get routine assignments for this batch (includes Saturday now)
$routine_query = $conn->prepare("
    SELECT ra.*, 
           c.code as course_code, c.title as course_title, c.credit,
           t.teacher_id, t.designation,
           u.username as teacher_username,
           up.full_name as teacher_name,
           cr.room_name, cr.has_projector, cr.has_ac,
           ts.start_time, ts.end_time
    FROM routine_assignments ra
    JOIN courses c ON ra.course_id = c.id
    JOIN teachers t ON ra.teacher_id = t.id
    JOIN users u ON t.user_id = u.id
    LEFT JOIN user_profiles up ON u.id = up.user_id
    JOIN classrooms cr ON ra.classroom_id = cr.id
    JOIN timeslots ts ON ra.timeslot_id = ts.id
    WHERE ra.batch_id = ?
    ORDER BY FIELD(ra.day, 'Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday'), ts.start_time
");
$routine_query->bind_param("i", $batch_id);
$routine_query->execute();
$routines = $routine_query->get_result()->fetch_all(MYSQLI_ASSOC);

// All days including Saturday
$all_days = ['Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday'];

// Group routine by day
$routine_by_day = [];
foreach ($all_days as $day) {
    $routine_by_day[$day] = [];
}
foreach ($routines as $routine) {
    $routine_by_day[$routine['day']][] = $routine;
}

// Get today's classes
$today = date('l'); // e.g., Saturday, Sunday, ...
$today_classes = $routine_by_day[$today] ?? [];

// Week overview (class count per day)
$week_overview = [];
foreach ($all_days as $day) {
    $week_overview[$day] = count($routine_by_day[$day]);
}

// Handle profile update (unchanged)
$profile_message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $division = trim($_POST['division'] ?? '');
    $district = trim($_POST['district'] ?? '');
    $upazilla = trim($_POST['upazilla'] ?? '');
    $postal_code = trim($_POST['postal_code'] ?? '');
    
    $update_user = $conn->prepare("UPDATE users SET mobile = ? WHERE id = ?");
    $update_user->bind_param("si", $mobile, $user_id);
    $update_user->execute();
    
    $check_profile = $conn->prepare("SELECT id FROM user_profiles WHERE user_id = ?");
    $check_profile->bind_param("i", $user_id);
    $check_profile->execute();
    $profile_exists = $check_profile->get_result()->num_rows > 0;
    
    if ($profile_exists) {
        $update_profile = $conn->prepare("UPDATE user_profiles SET full_name = ?, address = ?, division = ?, district = ?, upazilla = ?, postal_code = ? WHERE user_id = ?");
        $update_profile->bind_param("ssssssi", $full_name, $address, $division, $district, $upazilla, $postal_code, $user_id);
    } else {
        $update_profile = $conn->prepare("INSERT INTO user_profiles (user_id, full_name, address, division, district, upazilla, postal_code, dob, gender) VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), 'other')");
        $update_profile->bind_param("issssss", $user_id, $full_name, $address, $division, $district, $upazilla, $postal_code);
    }
    $update_profile->execute();
    
    // Refresh student data
    $student_query = $conn->prepare("
        SELECT s.*, 
               b.semester as batch_semester, b.section as batch_section, b.type as batch_type,
               b.off_day1, b.off_day2,
               d.name as department_name, d.code as department_code,
               u.username, u.email, u.mobile, u.status,
               up.full_name, up.dob, up.gender, up.division, up.district, up.upazilla, up.postal_code, up.address
        FROM students s
        JOIN users u ON s.user_id = u.id
        JOIN batches b ON s.batch_id = b.id
        JOIN departments d ON s.department_id = d.id
        LEFT JOIN user_profiles up ON u.id = up.user_id
        WHERE s.user_id = ?
    ");
    $student_query->bind_param("i", $user_id);
    $student_query->execute();
    $student = $student_query->get_result()->fetch_assoc();
    
    $profile_message = "Profile updated successfully!";
}

// Handle password change (unchanged)
$password_message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    $pass_query = $conn->prepare("SELECT password FROM users WHERE id = ?");
    $pass_query->bind_param("i", $user_id);
    $pass_query->execute();
    $current_hash = $pass_query->get_result()->fetch_assoc()['password'];
    
    if (!password_verify($current_password, $current_hash)) {
        $password_message = "Current password is incorrect!";
    } elseif (strlen($new_password) < 8) {
        $password_message = "Password must be at least 8 characters!";
    } elseif ($new_password !== $confirm_password) {
        $password_message = "New passwords do not match!";
    } else {
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $update_pass = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $update_pass->bind_param("si", $new_hash, $user_id);
        $update_pass->execute();
        $password_message = "Password changed successfully!";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Panel | Smart Routine Management System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* (All styles remain same â€“ no changes needed) */
        * { margin:0; padding:0; box-sizing:border-box; }
        :root { --primary: #4F46E5; --primary-dark: #4338CA; --primary-light: #818CF8; --secondary: #10B981; --secondary-dark: #059669; --danger: #EF4444; --warning: #F59E0B; --info: #3B82F6; --dark: #1F2937; --gray-50: #F9FAFB; --gray-100: #F3F4F6; --gray-200: #E5E7EB; --gray-300: #D1D5DB; --gray-400: #9CA3AF; --gray-500: #6B7280; --gray-600: #4B5563; --gray-700: #374151; --gray-800: #1F2937; --gray-900: #111827; --shadow-sm: 0 1px 2px 0 rgba(0,0,0,0.05); --shadow: 0 1px 3px 0 rgba(0,0,0,0.1); --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1); --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1); --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.1); --radius: 12px; --radius-lg: 16px; --radius-xl: 20px; }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; color: var(--gray-800); }
        .glass-nav { position: fixed; top: 0; left: 0; right: 0; background: rgba(255,255,255,0.95); backdrop-filter: blur(10px); box-shadow: var(--shadow-md); z-index: 1000; padding: 0.75rem 2rem; }
        .nav-container { max-width: 1400px; margin: 0 auto; display: flex; justify-content: space-between; align-items: center; }
        .logo { display: flex; align-items: center; gap: 10px; }
        .logo-icon { width: 40px; height: 40px; background: linear-gradient(135deg, var(--primary), var(--primary-light)); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: white; font-size: 1.2rem; }
        .logo-text h1 { font-size: 1.3rem; font-weight: 700; color: var(--gray-800); }
        .logo-text p { font-size: 0.7rem; color: var(--gray-500); }
        .nav-menu { display: flex; gap: 0.5rem; background: var(--gray-100); padding: 0.25rem; border-radius: 40px; }
        .nav-item { padding: 0.6rem 1.5rem; border-radius: 40px; cursor: pointer; transition: all 0.3s ease; font-weight: 500; color: var(--gray-600); }
        .nav-item.active { background: var(--primary); color: white; box-shadow: var(--shadow-md); }
        .nav-item:hover:not(.active) { background: var(--gray-200); color: var(--primary); }
        .user-info { display: flex; align-items: center; gap: 15px; }
        .user-avatar { width: 45px; height: 45px; background: linear-gradient(135deg, var(--primary), var(--primary-light)); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 600; font-size: 1.2rem; }
        .user-details { text-align: right; }
        .user-name { font-weight: 600; color: var(--gray-800); }
        .user-role { font-size: 0.75rem; color: var(--gray-500); }
        .logout-btn { padding: 0.5rem 1rem; background: var(--danger); color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 0.85rem; transition: all 0.3s ease; }
        .logout-btn:hover { background: #DC2626; transform: translateY(-2px); }
        .main-content { max-width: 1400px; margin: 0 auto; padding: 100px 2rem 2rem; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px,1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .stat-card { background: white; border-radius: var(--radius-lg); padding: 1.5rem; box-shadow: var(--shadow-lg); transition: transform 0.3s ease, box-shadow 0.3s ease; }
        .stat-card:hover { transform: translateY(-5px); box-shadow: var(--shadow-xl); }
        .stat-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
        .stat-title { font-size: 0.9rem; font-weight: 500; color: var(--gray-500); text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-icon { width: 48px; height: 48px; background: rgba(79,70,229,0.1); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 1.5rem; color: var(--primary); }
        .stat-value { font-size: 2.2rem; font-weight: 700; color: var(--gray-800); margin-bottom: 0.25rem; }
        .stat-subtitle { font-size: 0.85rem; color: var(--gray-500); }
        .section { display: none; animation: fadeIn 0.4s ease; }
        .section.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .section-title { font-size: 1.5rem; font-weight: 700; color: white; }
        .section-title i { margin-right: 10px; }
        .routine-container { background: white; border-radius: var(--radius-lg); overflow: hidden; box-shadow: var(--shadow-lg); }
        .day-tabs { display: flex; gap: 0.5rem; padding: 1rem; background: var(--gray-100); border-bottom: 1px solid var(--gray-200); flex-wrap: wrap; }
        .day-tab { padding: 0.6rem 1.2rem; border-radius: 30px; cursor: pointer; transition: all 0.3s ease; font-weight: 500; color: var(--gray-600); }
        .day-tab.active { background: var(--primary); color: white; }
        .day-tab:hover:not(.active) { background: var(--gray-200); }
        .routine-table { width: 100%; border-collapse: collapse; }
        .routine-table th, .routine-table td { padding: 1rem; text-align: left; border-bottom: 1px solid var(--gray-200); }
        .routine-table th { background: var(--gray-50); font-weight: 600; color: var(--gray-600); }
        .routine-table tr:hover { background: var(--gray-50); }
        .course-badge { background: rgba(79,70,229,0.1); color: var(--primary); padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.8rem; font-weight: 500; display: inline-block; }
        .room-badge { background: rgba(16,185,129,0.1); color: var(--secondary); padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.8rem; display: inline-block; }
        .time-badge { font-family: monospace; font-weight: 600; color: var(--gray-700); }
        .empty-routine { text-align: center; padding: 3rem; color: var(--gray-500); }
        .profile-card { background: white; border-radius: var(--radius-lg); overflow: hidden; box-shadow: var(--shadow-lg); }
        .profile-header { background: linear-gradient(135deg, var(--primary), var(--primary-light)); padding: 2rem; text-align: center; color: white; }
        .profile-avatar { width: 100px; height: 100px; background: rgba(255,255,255,0.2); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; font-size: 2.5rem; font-weight: 600; }
        .profile-name { font-size: 1.5rem; font-weight: 700; }
        .profile-id { font-size: 0.9rem; opacity: 0.9; }
        .profile-body { padding: 2rem; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px,1fr)); gap: 1.5rem; }
        .info-group { margin-bottom: 1.5rem; }
        .info-label { font-size: 0.8rem; font-weight: 500; color: var(--gray-500); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 0.25rem; }
        .info-value { font-size: 1rem; font-weight: 500; color: var(--gray-800); }
        .form-group { margin-bottom: 1rem; }
        .form-label { display: block; font-size: 0.85rem; font-weight: 500; color: var(--gray-700); margin-bottom: 0.5rem; }
        .form-control { width: 100%; padding: 0.75rem 1rem; border: 1px solid var(--gray-300); border-radius: 8px; font-size: 0.9rem; transition: all 0.3s ease; }
        .form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(79,70,229,0.1); }
        .btn { padding: 0.6rem 1.2rem; border: none; border-radius: 8px; font-weight: 500; cursor: pointer; transition: all 0.3s ease; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { background: var(--primary-dark); transform: translateY(-2px); }
        .btn-outline { background: transparent; border: 1px solid var(--gray-300); color: var(--gray-700); }
        .btn-outline:hover { border-color: var(--primary); color: var(--primary); }
        .alert { padding: 1rem; border-radius: 8px; margin-bottom: 1rem; }
        .alert-success { background: rgba(16,185,129,0.1); color: var(--secondary-dark); border-left: 3px solid var(--secondary); }
        .alert-danger { background: rgba(239,68,68,0.1); color: var(--danger); border-left: 3px solid var(--danger); }
        @media (max-width:768px) { .glass-nav { padding: 0.75rem 1rem; } .nav-menu { display: none; } .user-details { display: none; } .main-content { padding: 80px 1rem 1rem; } .info-grid { grid-template-columns: 1fr; } .routine-table { font-size: 0.8rem; } .routine-table th, .routine-table td { padding: 0.75rem; } }
    </style>
</head>
<body>
    <nav class="glass-nav">
        <div class="nav-container">
            <div class="logo">
                <div class="logo-icon"><i class="fas fa-calendar-alt"></i></div>
                <div class="logo-text"><h1>SRMS</h1><p>Student Panel</p></div>
            </div>
            <div class="nav-menu">
                <div class="nav-item active" data-section="dashboard"><i class="fas fa-tachometer-alt"></i> Dashboard</div>
                <div class="nav-item" data-section="routine"><i class="fas fa-table"></i> Class Routine</div>
                <div class="nav-item" data-section="profile"><i class="fas fa-user-circle"></i> Profile</div>
            </div>
            <div class="user-info">
                <div class="user-details">
                    <div class="user-name"><?php echo htmlspecialchars($student['full_name'] ?? $_SESSION['username']); ?></div>
                    <div class="user-role">Student â€¢ <?php echo htmlspecialchars($batch_semester); ?>th Batch</div>
                </div>
                <div class="user-avatar"><?php echo strtoupper(substr($student['full_name'] ?? $_SESSION['username'], 0, 1)); ?></div>
                <form method="POST" action="logout.php" style="display:inline;"><button type="submit" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</button></form>
            </div>
        </div>
    </nav>

    <div class="main-content">
        <!-- Dashboard Section -->
        <div id="dashboard" class="section active">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header"><span class="stat-title">Welcome Back</span><div class="stat-icon"><i class="fas fa-smile-wink"></i></div></div>
                    <div class="stat-value"><?php echo htmlspecialchars($student['full_name'] ?? $_SESSION['username']); ?></div>
                    <div class="stat-subtitle"><?php echo htmlspecialchars($department_code); ?> â€¢ Section <?php echo htmlspecialchars($section); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-header"><span class="stat-title">Total Courses</span><div class="stat-icon"><i class="fas fa-book"></i></div></div>
                    <div class="stat-value"><?php echo count($courses); ?></div>
                    <div class="stat-subtitle">This Semester</div>
                </div>
                <div class="stat-card">
                    <div class="stat-header"><span class="stat-title">Today's Classes</span><div class="stat-icon"><i class="fas fa-chalkboard"></i></div></div>
                    <div class="stat-value"><?php echo count($today_classes); ?></div>
                    <div class="stat-subtitle"><?php echo $today; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-header"><span class="stat-title">Weekly Classes</span><div class="stat-icon"><i class="fas fa-chart-line"></i></div></div>
                    <div class="stat-value"><?php echo array_sum($week_overview); ?></div>
                    <div class="stat-subtitle">Total Sessions</div>
                </div>
            </div>

            <!-- Today's Schedule -->
            <div class="routine-container" style="margin-bottom:2rem;">
                <div style="padding:1rem 1.5rem; background:var(--gray-100); border-bottom:1px solid var(--gray-200);"><h3 style="font-size:1.1rem;"><i class="fas fa-sun"></i> Today's Schedule (<?php echo $today; ?>)</h3></div>
                <?php if(count($today_classes)>0): ?>
                <table class="routine-table"><thead><tr><th>Time</th><th>Course</th><th>Teacher</th><th>Room</th><th>Type</th></tr></thead><tbody>
                    <?php foreach($today_classes as $class): ?>
                    <tr>
                        <td class="time-badge"><?php echo date('h:i A',strtotime($class['start_time'])).' - '.date('h:i A',strtotime($class['end_time'])); ?></td>
                        <td><strong><?php echo htmlspecialchars($class['course_code']); ?></strong><br><small><?php echo htmlspecialchars($class['course_title']); ?></small></td>
                        <td><?php echo htmlspecialchars($class['teacher_name'] ?? $class['teacher_username']); ?><br><small><?php echo htmlspecialchars($class['designation']); ?></small></td>
                        <td><span class="room-badge"><?php echo htmlspecialchars($class['room_name']); ?></span></td>
                        <td><span class="course-badge"><?php echo ucfirst($class['session_type']); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody></table>
                <?php else: ?>
                <div class="empty-routine"><i class="fas fa-coffee" style="font-size:2rem; margin-bottom:1rem; display:block;"></i><p>No classes scheduled for today! Enjoy your day off.</p></div>
                <?php endif; ?>
            </div>

            <!-- Course List -->
            <div class="routine-container">
                <div style="padding:1rem 1.5rem; background:var(--gray-100); border-bottom:1px solid var(--gray-200);"><h3 style="font-size:1.1rem;"><i class="fas fa-list"></i> Your Courses This Semester</h3></div>
                <table class="routine-table"><thead><tr><th>Code</th><th>Course Title</th><th>Credit</th><th>Weekly Classes</th></tr></thead><tbody>
                    <?php foreach($courses as $course): ?>
                    <tr><td><span class="course-badge"><?php echo htmlspecialchars($course['code']); ?></span></td><td><?php echo htmlspecialchars($course['title']); ?></td><td><?php echo $course['credit']; ?></td><td><?php echo $course['weekly_classes']; ?> sessions</td></tr>
                    <?php endforeach; ?>
                    <?php if(count($courses)==0): ?><tr><td colspan="4" class="empty-routine">No courses assigned yet.</td></tr><?php endif; ?>
                </tbody></table>
            </div>
        </div>

        <!-- Routine Section (includes Saturday) -->
        <div id="routine" class="section">
            <div class="section-header"><h2 class="section-title"><i class="fas fa-table"></i> Class Routine</h2><div><span class="course-badge">Batch: <?php echo htmlspecialchars($batch_semester); ?></span><span class="room-badge" style="margin-left:0.5rem;">Section: <?php echo htmlspecialchars($section); ?></span></div></div>
            <div class="routine-container">
                <div class="day-tabs">
                    <?php foreach($all_days as $day): ?>
                    <div class="day-tab <?php echo $day===$today?'active':''; ?>" data-day="<?php echo $day; ?>"><?php echo $day; ?> <span style="font-size:0.7rem;">(<?php echo count($routine_by_day[$day]); ?> classes)</span></div>
                    <?php endforeach; ?>
                </div>
                <div id="routine-content">
                    <?php foreach($all_days as $day): ?>
                    <div class="day-routine" id="day-<?php echo $day; ?>" style="display:<?php echo $day===$today?'block':'none'; ?>">
                        <?php if(count($routine_by_day[$day])>0): ?>
                        <table class="routine-table"><thead><tr><th>Time</th><th>Course Code</th><th>Course Title</th><th>Teacher</th><th>Room</th><th>Type</th></tr></thead><tbody>
                            <?php foreach($routine_by_day[$day] as $class): ?>
                            <tr>
                                <td class="time-badge"><?php echo date('h:i A',strtotime($class['start_time'])).' - '.date('h:i A',strtotime($class['end_time'])); ?></td>
                                <td><strong><?php echo htmlspecialchars($class['course_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($class['course_title']); ?></td>
                                <td><?php echo htmlspecialchars($class['teacher_name'] ?? $class['teacher_username']); ?></td>
                                <td><span class="room-badge"><?php echo htmlspecialchars($class['room_name']); ?></span></td>
                                <td><span class="course-badge"><?php echo ucfirst($class['session_type']); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody></table>
                        <?php else: ?>
                        <div class="empty-routine"><i class="fas fa-calendar-day" style="font-size:2rem; margin-bottom:1rem; display:block;"></i><p>No classes scheduled on <?php echo $day; ?>.</p>
                        <?php if($student['off_day1']==$day || $student['off_day2']==$day): ?><p><small>ðŸ“Œ This is an official off day for your batch.</small></p><?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Profile Section (unchanged) -->
        <div id="profile" class="section">
            <div class="section-header"><h2 class="section-title"><i class="fas fa-user-circle"></i> My Profile</h2></div>
            <div class="profile-card">
                <div class="profile-header"><div class="profile-avatar"><?php echo strtoupper(substr($student['full_name'] ?? $_SESSION['username'],0,1)); ?></div><div class="profile-name"><?php echo htmlspecialchars($student['full_name'] ?? $_SESSION['username']); ?></div><div class="profile-id">Student ID: <?php echo htmlspecialchars($student['student_id']); ?></div></div>
                <div class="profile-body">
                    <?php if($profile_message): ?><div class="alert alert-success"><?php echo $profile_message; ?></div><?php endif; ?>
                    <?php if($password_message): ?><div class="alert <?php echo strpos($password_message,'successfully')!==false?'alert-success':'alert-danger'; ?>"><?php echo $password_message; ?></div><?php endif; ?>
                    <div class="info-grid">
                        <div><h3 style="margin-bottom:1rem; font-size:1.1rem;">Personal Information</h3>
                            <div class="info-group"><div class="info-label">Full Name</div><div class="info-value"><?php echo htmlspecialchars($student['full_name'] ?? 'Not set'); ?></div></div>
                            <div class="info-group"><div class="info-label">Date of Birth</div><div class="info-value"><?php echo htmlspecialchars($student['dob'] ?? 'Not set'); ?></div></div>
                            <div class="info-group"><div class="info-label">Gender</div><div class="info-value"><?php echo htmlspecialchars(ucfirst($student['gender'] ?? 'Not set')); ?></div></div>
                            <div class="info-group"><div class="info-label">Mobile Number</div><div class="info-value"><?php echo htmlspecialchars($student['mobile']); ?></div></div>
                            <div class="info-group"><div class="info-label">Email Address</div><div class="info-value"><?php echo htmlspecialchars($student['email']); ?></div></div>
                        </div>
                        <div><h3 style="margin-bottom:1rem; font-size:1.1rem;">Academic Information</h3>
                            <div class="info-group"><div class="info-label">Department</div><div class="info-value"><?php echo htmlspecialchars($student['department_name']); ?></div></div>
                            <div class="info-group"><div class="info-label">Batch</div><div class="info-value"><?php echo htmlspecialchars($student['batch_semester']); ?>th Batch</div></div>
                            <div class="info-group"><div class="info-label">Section</div><div class="info-value"><?php echo htmlspecialchars($student['batch_section']); ?></div></div>
                            <div class="info-group"><div class="info-label">Student ID</div><div class="info-value"><?php echo htmlspecialchars($student['student_id']); ?></div></div>
                        </div>
                        <div><h3 style="margin-bottom:1rem; font-size:1.1rem;">Address Information</h3>
                            <div class="info-group"><div class="info-label">Division</div><div class="info-value"><?php echo htmlspecialchars(ucfirst($student['division'] ?? 'Not set')); ?></div></div>
                            <div class="info-group"><div class="info-label">District</div><div class="info-value"><?php echo htmlspecialchars(ucfirst($student['district'] ?? 'Not set')); ?></div></div>
                            <div class="info-group"><div class="info-label">Upazilla / Thana</div><div class="info-value"><?php echo htmlspecialchars(ucfirst($student['upazilla'] ?? 'Not set')); ?></div></div>
                            <div class="info-group"><div class="info-label">Postal Code</div><div class="info-value"><?php echo htmlspecialchars($student['postal_code'] ?? 'Not set'); ?></div></div>
                            <div class="info-group"><div class="info-label">Full Address</div><div class="info-value"><?php echo htmlspecialchars($student['address'] ?? 'Not set'); ?></div></div>
                        </div>
                    </div>
                    <hr style="margin:1.5rem 0; border-color:var(--gray-200);">
                    <h3 style="margin-bottom:1rem; font-size:1.1rem;">Update Profile Information</h3>
                    <form method="POST" action="">
                        <div class="info-grid">
                            <div><div class="form-group"><label class="form-label">Full Name</label><input type="text" name="full_name" class="form-control" value="<?php echo htmlspecialchars($student['full_name'] ?? ''); ?>"></div>
                            <div class="form-group"><label class="form-label">Mobile Number</label><input type="tel" name="mobile" class="form-control" value="<?php echo htmlspecialchars($student['mobile']); ?>"></div>
                            <div class="form-group"><label class="form-label">Division</label><input type="text" name="division" class="form-control" value="<?php echo htmlspecialchars($student['division'] ?? ''); ?>"></div>
                            <div class="form-group"><label class="form-label">District</label><input type="text" name="district" class="form-control" value="<?php echo htmlspecialchars($student['district'] ?? ''); ?>"></div></div>
                            <div><div class="form-group"><label class="form-label">Upazilla / Thana</label><input type="text" name="upazilla" class="form-control" value="<?php echo htmlspecialchars($student['upazilla'] ?? ''); ?>"></div>
                            <div class="form-group"><label class="form-label">Postal Code</label><input type="text" name="postal_code" class="form-control" value="<?php echo htmlspecialchars($student['postal_code'] ?? ''); ?>"></div>
                            <div class="form-group"><label class="form-label">Full Address</label><textarea name="address" class="form-control" rows="3"><?php echo htmlspecialchars($student['address'] ?? ''); ?></textarea></div></div>
                        </div>
                        <button type="submit" name="update_profile" class="btn btn-primary">Update Profile</button>
                    </form>
                    <hr style="margin:1.5rem 0; border-color:var(--gray-200);">
                    <h3 style="margin-bottom:1rem; font-size:1.1rem;">Change Password</h3>
                    <form method="POST" action="" style="max-width:400px;">
                        <div class="form-group"><label class="form-label">Current Password</label><input type="password" name="current_password" class="form-control" required></div>
                        <div class="form-group"><label class="form-label">New Password</label><input type="password" name="new_password" class="form-control" required><small style="color:var(--gray-500);">Minimum 8 characters with uppercase, lowercase, number & special character</small></div>
                        <div class="form-group"><label class="form-label">Confirm New Password</label><input type="password" name="confirm_password" class="form-control" required></div>
                        <button type="submit" name="change_password" class="btn btn-primary">Change Password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Navigation between sections
        document.querySelectorAll('.nav-item').forEach(item=>{
            item.addEventListener('click',function(){
                const sectionId=this.getAttribute('data-section');
                document.querySelectorAll('.nav-item').forEach(nav=>nav.classList.remove('active'));
                this.classList.add('active');
                document.querySelectorAll('.section').forEach(section=>section.classList.remove('active'));
                document.getElementById(sectionId).classList.add('active');
            });
        });
        // Day tab switching (includes Saturday)
        document.querySelectorAll('.day-tab').forEach(tab=>{
            tab.addEventListener('click',function(){
                const day=this.getAttribute('data-day');
                document.querySelectorAll('.day-tab').forEach(t=>t.classList.remove('active'));
                this.classList.add('active');
                document.querySelectorAll('.day-routine').forEach(r=>r.style.display='none');
                document.getElementById(`day-${day}`).style.display='block';
            });
        });
    </script>
</body>
</html>
<?php $conn->close(); ?>