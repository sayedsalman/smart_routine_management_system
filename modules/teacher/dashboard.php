
<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../../login.php");
    exit();
}

date_default_timezone_set('Asia/Dhaka');
require_once '../../database.php';
$conn = getDBConnection();

$user_id = $_SESSION['user_id'];

// Get teacher details with profile
$teacher_query = $conn->prepare("
    SELECT t.id, t.teacher_id, t.department, t.designation, t.max_classes_per_day, t.available_days,
           u.username, u.email, u.mobile,
           up.full_name, up.gender, up.address
    FROM teachers t
    JOIN users u ON t.user_id = u.id
    LEFT JOIN user_profiles up ON u.id = up.user_id
    WHERE t.user_id = ?
");
$teacher_query->bind_param("i", $user_id);
$teacher_query->execute();
$teacher_result = $teacher_query->get_result();
if ($teacher_result->num_rows === 0) die("Teacher profile not found.");
$teacher = $teacher_result->fetch_assoc();
$teacher_id = $teacher['id'];

// --- FIX: Get the latest generation_id correctly ---
// Option 1: from routine_assignments (most reliable)
$gen_query = $conn->query("SELECT MAX(generation_id) as latest_gen FROM routine_assignments");
$latest_gen_row = $gen_query->fetch_assoc();
$latest_gen = $latest_gen_row['latest_gen'] ?? 1;
// If still null, fallback to generations table
if (!$latest_gen) {
    $gen_query2 = $conn->query("SELECT MAX(generation_id) as latest_gen FROM generations");
    $latest_gen = $gen_query2->fetch_assoc()['latest_gen'] ?? 1;
}
// -------------------------------------------------

// Ensure required tables exist (same as before)
$conn->query("
    CREATE TABLE IF NOT EXISTS teacher_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        teacher_id INT NOT NULL,
        setting_key VARCHAR(50) NOT NULL,
        setting_value TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_setting (teacher_id, setting_key),
        FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
    )
");

// Fetch teacher's courses from batch_courses (actual assigned courses)
$courses_query = $conn->prepare("
    SELECT DISTINCT c.id, c.code, c.title, c.credit, c.weekly_classes,
           COALESCE(SUM(TIMESTAMPDIFF(HOUR, ts.start_time, ts.end_time)), 0) as actual_hours
    FROM batch_courses bc
    JOIN courses c ON bc.course_id = c.id
    LEFT JOIN routine_assignments ra ON ra.course_id = c.id AND ra.teacher_id = ? AND ra.generation_id = ?
    LEFT JOIN timeslots ts ON ra.timeslot_id = ts.id
    WHERE bc.teacher_id = ?
    GROUP BY c.id
    ORDER BY c.code
");
$courses_query->bind_param("iii", $teacher_id, $latest_gen, $teacher_id);
$courses_query->execute();
$teacher_courses = $courses_query->get_result();
$courses_list = $teacher_courses->fetch_all(MYSQLI_ASSOC);
$teacher_courses->data_seek(0);

// Fetch teacher preferences
$pref = $conn->query("SELECT * FROM teacher_preferences WHERE teacher_id = $teacher_id")->fetch_assoc();
if (!$pref) {
    $conn->query("INSERT INTO teacher_preferences (teacher_id, max_classes_per_day) VALUES ($teacher_id, 4)");
    $pref = ['max_classes_per_day' => 4, 'priority_course_ids' => null];
}
$max_continuous = $pref['max_classes_per_day'] ?? 4;
$priority_course_ids = $pref['priority_course_ids'] ?? '';

// Teacher settings
$settings = [];
$settings_res = $conn->query("SELECT setting_key, setting_value FROM teacher_settings WHERE teacher_id = $teacher_id");
while ($row = $settings_res->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$morning_bias = $settings['morning_bias'] ?? '1';
$same_dept_priority = $settings['same_dept_priority'] ?? '1';
$preferred_teaching_hours = $settings['preferred_teaching_hours'] ?? '5';

// Teacher availability
$availability = [];
$avail_res = $conn->query("SELECT day, start_slot_id, end_slot_id FROM teacher_availability WHERE teacher_id = $teacher_id");
while ($row = $avail_res->fetch_assoc()) {
    $availability[$row['day']][$row['start_slot_id']] = $row['end_slot_id'];
}

// Fetch routine assignments for this teacher and the correct generation
$routine_raw = $conn->prepare("
    SELECT ra.day, ra.timeslot_id, ts.start_time, ts.end_time,
           c.code, c.title, cr.room_name, b.semester, b.section
    FROM routine_assignments ra
    JOIN timeslots ts ON ra.timeslot_id = ts.id
    JOIN courses c ON ra.course_id = c.id
    JOIN classrooms cr ON ra.classroom_id = cr.id
    JOIN batches b ON ra.batch_id = b.id
    WHERE ra.teacher_id = ? AND ra.generation_id = ?
    ORDER BY FIELD(ra.day, 'Saturday','Sunday','Monday','Tuesday','Wednesday','Thursday','Friday'), ts.start_time
");
$routine_raw->bind_param("ii", $teacher_id, $latest_gen);
$routine_raw->execute();
$routine_result = $routine_raw->get_result();

$routine_by_day = [];
$all_timeslots = [];
while ($row = $routine_result->fetch_assoc()) {
    $day = $row['day'];
    $slot_id = $row['timeslot_id'];
    if (!isset($all_timeslots[$slot_id])) {
        $all_timeslots[$slot_id] = ['start' => $row['start_time'], 'end' => $row['end_time']];
    }
    $routine_by_day[$day][$slot_id][] = $row;
}

// Also get any additional timeslots that might be in teacher_availability (optional)
$extra_timeslots = $conn->query("SELECT DISTINCT ts.id, ts.start_time, ts.end_time FROM timeslots ts ORDER BY ts.start_time");
while ($row = $extra_timeslots->fetch_assoc()) {
    if (!isset($all_timeslots[$row['id']])) {
        $all_timeslots[$row['id']] = ['start' => $row['start_time'], 'end' => $row['end_time']];
    }
}
// Sort timeslots by start time
uasort($all_timeslots, function($a, $b) { return strtotime($a['start']) - strtotime($b['start']); });

$week_days = ['Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

// Today's classes (for notification)
$today = date('l');
$today_classes = [];
if (isset($routine_by_day[$today])) {
    foreach ($routine_by_day[$today] as $slot_id => $classes) {
        foreach ($classes as $class) {
            $today_classes[] = $class;
        }
    }
}
$today_count = count($today_classes);

// Weekly totals
$weekly_total = 0;
$weekly_hours = 0;
foreach ($routine_by_day as $day => $slots) {
    foreach ($slots as $slot_id => $classes) {
        $weekly_total += count($classes);
        $start = strtotime($all_timeslots[$slot_id]['start']);
        $end = strtotime($all_timeslots[$slot_id]['end']);
        $weekly_hours += ($end - $start) / 3600;
    }
}

// Daily hours breakdown
$daily_hours_array = [];
foreach ($week_days as $day) {
    $daily_hours_array[$day] = 0;
    if (isset($routine_by_day[$day])) {
        foreach ($routine_by_day[$day] as $slot_id => $classes) {
            $start = strtotime($all_timeslots[$slot_id]['start']);
            $end = strtotime($all_timeslots[$slot_id]['end']);
            $daily_hours_array[$day] += count($classes) * (($end - $start) / 3600);
        }
    }
}

// Course distribution hours
$course_distribution = [];
foreach ($courses_list as $course) {
    $total = 0;
    foreach ($routine_by_day as $day => $slots) {
        foreach ($slots as $slot_id => $classes) {
            foreach ($classes as $class) {
                if ($class['code'] == $course['code']) {
                    $start = strtotime($all_timeslots[$slot_id]['start']);
                    $end = strtotime($all_timeslots[$slot_id]['end']);
                    $total += ($end - $start) / 3600;
                }
            }
        }
    }
    if ($total > 0) {
        $course_distribution[] = ['code' => $course['code'], 'hours' => $total];
    }
}

// AJAX handlers (same as before)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    if ($action === 'save_preferences') {
        $max_continuous = intval($_POST['max_continuous'] ?? 4);
        $teaching_hours = intval($_POST['teaching_hours'] ?? 5);
        $morning_bias = $_POST['morning_bias'] ?? '1';
        $same_dept_priority = $_POST['same_dept_priority'] ?? '1';
        $priority_course_ids = $_POST['priority_course_ids'] ?? '';
        $conn->query("UPDATE teacher_preferences SET max_classes_per_day = $max_continuous, priority_course_ids = '$priority_course_ids' WHERE teacher_id = $teacher_id");
        $conn->query("INSERT INTO teacher_settings (teacher_id, setting_key, setting_value) VALUES ($teacher_id, 'preferred_teaching_hours', '$teaching_hours') ON DUPLICATE KEY UPDATE setting_value = '$teaching_hours'");
        $conn->query("INSERT INTO teacher_settings (teacher_id, setting_key, setting_value) VALUES ($teacher_id, 'morning_bias', '$morning_bias') ON DUPLICATE KEY UPDATE setting_value = '$morning_bias'");
        $conn->query("INSERT INTO teacher_settings (teacher_id, setting_key, setting_value) VALUES ($teacher_id, 'same_dept_priority', '$same_dept_priority') ON DUPLICATE KEY UPDATE setting_value = '$same_dept_priority'");
        echo json_encode(['success' => true]);
        exit;
    }
    if ($action === 'save_availability') {
        $availability_data = json_decode($_POST['availability_data'], true);
        $conn->query("DELETE FROM teacher_availability WHERE teacher_id = $teacher_id");
        $stmt = $conn->prepare("INSERT INTO teacher_availability (teacher_id, day, start_slot_id, end_slot_id) VALUES (?, ?, ?, ?)");
        foreach ($availability_data as $item) {
            $stmt->bind_param("isii", $teacher_id, $item['day'], $item['start_slot_id'], $item['end_slot_id']);
            $stmt->execute();
        }
        echo json_encode(['success' => true]);
        exit;
    }
    echo json_encode(['success' => false]);
    exit;
}

// CSV export
if (isset($_GET['export_csv'])) {
    $filename = 'my_routine_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Day', 'Start Time', 'End Time', 'Course Code', 'Course Title', 'Room', 'Batch']);
    foreach ($routine_by_day as $day => $slots) {
        foreach ($slots as $slot_id => $classes) {
            $start = date('h:i A', strtotime($all_timeslots[$slot_id]['start']));
            $end = date('h:i A', strtotime($all_timeslots[$slot_id]['end']));
            foreach ($classes as $class) {
                fputcsv($output, [
                    $day,
                    $start,
                    $end,
                    $class['code'],
                    $class['title'],
                    $class['room_name'],
                    $class['semester'] . $class['section']
                ]);
            }
        }
    }
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Teacher Dashboard | SRMS</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.11.4/gsap.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.14.0/Sortable.min.js"></script>
    <style>
        :root {
            --warm-bg: #fef9e8;
            --warm-card: #fffef7;
            --warm-border: #f0e6d2;
            --warm-shadow: rgba(0, 0, 0, 0.05);
            --primary-warm: #e8a87c;
            --primary-soft: #f3d9b1;
            --accent-warm: #c38d62;
            --text-dark: #4a3b2c;
            --text-soft: #7d6b55;
            --sidebar-width: 280px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: var(--warm-bg);
            color: var(--text-dark);
            overflow-x: hidden;
        }
        .menu-toggle {
            position: fixed; top: 20px; left: 20px; z-index: 110;
            background: var(--warm-card); backdrop-filter: blur(12px);
            border: 1px solid var(--warm-border); border-radius: 16px;
            padding: 12px; cursor: pointer; display: none;
            font-size: 1.2rem; color: var(--text-dark); box-shadow: 0 4px 12px var(--warm-shadow);
        }
        .teacher-dashboard { display: flex; min-height: 100vh; }
        .glass-sidebar {
            width: var(--sidebar-width);
            background: rgba(255, 252, 240, 0.85);
            backdrop-filter: blur(16px);
            border-right: 1px solid var(--warm-border);
            display: flex; flex-direction: column;
            position: fixed; height: 100vh; z-index: 100;
            transition: transform 0.3s ease;
            box-shadow: 2px 0 12px var(--warm-shadow);
        }
        .sidebar-header { padding: 28px 24px; border-bottom: 1px solid var(--warm-border); }
        .logo { display: flex; align-items: center; gap: 12px; }
        .logo-icon { font-size: 2rem; }
        .logo-text { font-size: 1.6rem; font-weight: 700; color: var(--accent-warm); }
        .logo-role { font-size: 0.7rem; background: var(--primary-soft); padding: 2px 10px; border-radius: 20px; color: var(--accent-warm); }
        .sidebar-nav { flex: 1; padding: 32px 0; }
        .sidebar-nav ul { list-style: none; }
        .nav-item {
            padding: 14px 28px; display: flex; align-items: center; gap: 16px;
            cursor: pointer; transition: all 0.3s; color: var(--text-soft);
            border-left: 3px solid transparent; margin: 4px 0;
            position: relative;
        }
        .nav-item:hover { background: rgba(200, 160, 120, 0.08); color: var(--accent-warm); }
        .nav-item.active {
            background: rgba(200, 160, 120, 0.12);
            color: var(--accent-warm);
            border-left-color: var(--accent-warm);
        }
        .nav-item i { width: 24px; text-align: center; font-size: 1.2rem; }
        .notification-badge {
            position: absolute;
            right: 20px;
            top: 10px;
            background: var(--primary-warm);
            color: white;
            border-radius: 20px;
            padding: 2px 8px;
            font-size: 0.7rem;
            font-weight: bold;
        }
        .sidebar-footer {
            padding: 20px 24px; border-top: 1px solid var(--warm-border);
            display: flex; align-items: center; justify-content: space-between;
            gap: 16px;
        }
        .footer-user { display: flex; align-items: center; gap: 16px; }
        .avatar-circle {
            width: 48px; height: 48px;
            background: linear-gradient(135deg, var(--primary-warm), var(--accent-warm));
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 1.2rem; color: white;
        }
        .teacher-info h4 { font-size: 1rem; font-weight: 600; margin-bottom: 4px; }
        .teacher-info p { font-size: 0.75rem; color: var(--text-soft); }
        .logout-btn {
            background: var(--primary-soft);
            color: var(--accent-warm);
            border: none;
            border-radius: 40px;
            padding: 8px 16px;
            cursor: pointer;
            font-size: 0.8rem;
            transition: 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .logout-btn:hover { background: var(--primary-warm); color: white; }
        .main-content {
            flex: 1; margin-left: var(--sidebar-width);
            padding: 32px 40px; transition: margin-left 0.3s;
        }
        .dashboard-section { display: none; animation: fadeIn 0.4s ease; }
        .dashboard-section.active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
        .glass-card {
            background: var(--warm-card);
            backdrop-filter: blur(8px);
            border: 1px solid var(--warm-border);
            border-radius: 28px;
            padding: 28px;
            transition: transform 0.3s, box-shadow 0.3s;
            box-shadow: 0 8px 20px var(--warm-shadow);
        }
        .glass-card:hover { transform: translateY(-3px); box-shadow: 0 16px 30px rgba(0, 0, 0, 0.08); }
        .profile-card { display: flex; gap: 28px; align-items: center; flex-wrap: wrap; }
        .profile-avatar {
            width: 100px; height: 100px;
            background: linear-gradient(135deg, var(--primary-warm), var(--accent-warm));
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-size: 3rem; font-weight: 700; color: white;
        }
        .profile-details h2 { font-size: 1.8rem; margin-bottom: 8px; }
        .profile-badge {
            display: inline-block; background: var(--primary-soft); padding: 4px 12px;
            border-radius: 20px; font-size: 0.8rem; margin-right: 10px; color: var(--accent-warm);
        }
        .profile-stats { display: flex; gap: 20px; margin-top: 16px; flex-wrap: wrap; }
        .stat-item { background: rgba(200, 160, 120, 0.08); padding: 8px 16px; border-radius: 20px; }
        .workload-table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .workload-table th, .workload-table td { padding: 14px 12px; text-align: left; border-bottom: 1px solid var(--warm-border); }
        .workload-table th { color: var(--accent-warm); font-weight: 500; }
        .workload-meter { width: 100px; height: 6px; background: #ede5d6; border-radius: 3px; overflow: hidden; }
        .workload-fill { height: 100%; background: linear-gradient(90deg, var(--primary-warm), var(--accent-warm)); border-radius: 3px; }
        .timetable-container { overflow-x: auto; margin-top: 24px; }
        .timetable { min-width: 900px; width: 100%; border-collapse: collapse; }
        .timetable th, .timetable td { border: 1px solid var(--warm-border); padding: 16px 12px; text-align: center; vertical-align: middle; }
        .timetable th { background: var(--primary-soft); font-weight: 600; color: var(--accent-warm); }
        .class-code { font-weight: 600; color: var(--accent-warm); }
        .class-room { font-size: 0.7rem; color: var(--text-soft); }
        .evening-note { font-size: 0.8rem; color: var(--primary-warm); font-style: italic; }
        .btn {
            padding: 10px 24px; border-radius: 40px; font-weight: 500;
            border: none; cursor: pointer; transition: all 0.2s;
            font-family: inherit; font-size: 0.9rem;
        }
        .btn-primary { background: linear-gradient(135deg, var(--primary-warm), var(--accent-warm)); color: white; }
        .btn-primary:hover { transform: scale(1.02); box-shadow: 0 4px 12px rgba(200, 140, 100, 0.3); }
        .btn-secondary { background: var(--primary-soft); color: var(--accent-warm); }
        .quick-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 24px; margin-top: 32px; }
        .stat-card { display: flex; align-items: center; gap: 20px; }
        .stat-icon {
            width: 56px; height: 56px; background: linear-gradient(135deg, var(--primary-warm), var(--accent-warm));
            border-radius: 20px; display: flex; align-items: center; justify-content: center;
            font-size: 1.5rem; color: white;
        }
        .stat-value { font-size: 2rem; font-weight: 700; color: var(--accent-warm); }
        .notification-toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--accent-warm);
            color: white;
            padding: 12px 20px;
            border-radius: 40px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 1000;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @media (max-width: 992px) {
            .glass-sidebar { transform: translateX(-100%); }
            .glass-sidebar.mobile-open { transform: translateX(0); }
            .menu-toggle { display: block; }
            .main-content { margin-left: 0; padding: 80px 20px 20px; }
        }
        @media (max-width: 768px) {
            .profile-card { flex-direction: column; text-align: center; }
            .quick-stats { grid-template-columns: 1fr; }
        }
        @media print {
            .glass-sidebar, .menu-toggle, .action-buttons, .nav-item, .sidebar-footer, .btn, .notification-toast { display: none; }
            .main-content { margin: 0; padding: 0; background: white; color: black; }
            .glass-card { background: white; border: 1px solid #ccc; box-shadow: none; }
            .timetable td, .timetable th { border: 1px solid #aaa; }
        }
    </style>
</head>
<body>
<button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
<div class="teacher-dashboard">
    <aside class="glass-sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="logo">
                <span class="logo-icon">ðŸ“š</span>
                <span class="logo-text">SRMS</span>
                <span class="logo-role">Teacher</span>
            </div>
        </div>
        <nav class="sidebar-nav">
            <ul>
                <li class="nav-item active" data-section="dashboard"><i class="fas fa-home"></i><span>Dashboard</span></li>
                <li class="nav-item" data-section="schedule">
                    <i class="fas fa-calendar-alt"></i><span>Routine</span>
                    <?php if ($today_count > 0): ?>
                    <span class="notification-badge"><?php echo $today_count; ?></span>
                    <?php endif; ?>
                </li>
                <li class="nav-item" data-section="preferences"><i class="fas fa-sliders-h"></i><span>Preferences</span></li>
                <li class="nav-item" data-section="availability"><i class="fas fa-clock"></i><span>Availability</span></li>
                <li class="nav-item" data-section="analytics"><i class="fas fa-chart-pie"></i><span>Analytics</span></li>
                <li class="nav-item" data-section="downloads"><i class="fas fa-download"></i><span>Downloads</span></li>
            </ul>
        </nav>
        <div class="sidebar-footer">
            <div class="footer-user">
                <div class="avatar-circle"><?php echo strtoupper(substr($teacher['full_name'] ?? $teacher['username'], 0, 2)); ?></div>
                <div class="teacher-info">
                    <h4><?php echo htmlspecialchars($teacher['full_name'] ?? $teacher['username']); ?></h4>
                    <p><?php echo htmlspecialchars($teacher['designation']); ?> â€¢ <?php echo htmlspecialchars($teacher['department']); ?></p>
                </div>
            </div>
            <a href="../../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </aside>

    <main class="main-content">
        <!-- Dashboard Section -->
        <section id="dashboard" class="dashboard-section active">
            <div class="glass-card profile-card">
                <div class="profile-avatar"><i class="fas fa-chalkboard-user"></i></div>
                <div class="profile-details">
                    <h2><?php echo htmlspecialchars($teacher['full_name'] ?? $teacher['username']); ?></h2>
                    <div>
                        <span class="profile-badge"><i class="fas fa-id-card"></i> <?php echo htmlspecialchars($teacher['teacher_id']); ?></span>
                        <span class="profile-badge"><i class="fas fa-building"></i> <?php echo htmlspecialchars($teacher['department']); ?></span>
                        <span class="profile-badge"><i class="fas fa-user-tie"></i> <?php echo htmlspecialchars($teacher['designation']); ?></span>
                    </div>
                    <div class="profile-stats">
                        <div class="stat-item"><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($teacher['email']); ?></div>
                        <div class="stat-item"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($teacher['mobile']); ?></div>
                        <div class="stat-item"><i class="fas fa-calendar-week"></i> Max/Day: <?php echo $teacher['max_classes_per_day']; ?></div>
                    </div>
                </div>
            </div>
            <div class="quick-stats">
                <div class="glass-card stat-card"><div class="stat-icon"><i class="fas fa-chalkboard"></i></div><div><h3>Total Courses</h3><div class="stat-value"><?php echo count($courses_list); ?></div></div></div>
                <div class="glass-card stat-card"><div class="stat-icon"><i class="fas fa-clock"></i></div><div><h3>Weekly Hours</h3><div class="stat-value"><?php echo round($weekly_hours, 1); ?> <span style="font-size:1rem;">hrs</span></div></div></div>
                <div class="glass-card stat-card"><div class="stat-icon"><i class="fas fa-calendar-day"></i></div><div><h3>Today's Classes</h3><div class="stat-value"><?php echo $today_count; ?></div></div></div>
            </div>
            <div class="glass-card" style="margin-top: 24px;">
                <h3><i class="fas fa-tasks"></i> Assigned Courses & Workload (From Batch Assignments)</h3>
                <table class="workload-table">
                    <thead>
                        <tr><th>Code</th><th>Title</th><th>Credit</th><th>Weekly Classes</th><th>Actual Hours/Week</th><th>Load</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach($courses_list as $course): 
                            $expected = $course['weekly_classes'] * 1.5;
                            $actual = $course['actual_hours'];
                            $percent = $expected > 0 ? min(100, ($actual / $expected) * 100) : 0;
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($course['code']); ?></strong></td>
                            <td><?php echo htmlspecialchars($course['title']); ?></td>
                            <td><?php echo $course['credit']; ?></td>
                            <td><?php echo $course['weekly_classes']; ?></td>
                            <td><?php echo round($actual, 1); ?> hrs</td>
                            <td><div class="workload-meter"><div class="workload-fill" style="width: <?php echo $percent; ?>%;"></div></div></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Schedule Section with proper routine display -->
        <section id="schedule" class="dashboard-section">
            <div class="glass-card">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 20px;">
                    <h2><i class="fas fa-calendar-alt"></i> Weekly Teaching Routine (Generation <?php echo $latest_gen; ?>)</h2>
                    <div class="action-buttons" style="display: flex; gap: 12px;">
                        <button class="btn btn-primary" onclick="window.print()"><i class="fas fa-print"></i> Print / PDF</button>
                        <a href="?export_csv=1" class="btn btn-secondary"><i class="fas fa-file-excel"></i> Export CSV</a>
                    </div>
                </div>
                <?php if (empty($routine_by_day)): ?>
                    <div style="text-align: center; padding: 40px; background: var(--primary-soft); border-radius: 20px;">
                        <i class="fas fa-calendar-times" style="font-size: 3rem; color: var(--accent-warm);"></i>
                        <p style="margin-top: 16px;">No routine assigned yet for generation <?php echo $latest_gen; ?>.<br>Please contact the administrator to run the scheduler.</p>
                    </div>
                <?php else: ?>
                <div class="timetable-container">
                    <table class="timetable">
                        <thead>
                            <tr>
                                <th>Time / Day</th>
                                <?php foreach($week_days as $day): ?>
                                <th><?php echo $day; ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($all_timeslots as $slot_id => $times): ?>
                            <tr>
                                <td style="font-weight: 500;"><?php echo date('h:i A', strtotime($times['start'])) . ' - ' . date('h:i A', strtotime($times['end'])); ?></td>
                                <?php foreach($week_days as $day): ?>
                                <td>
                                    <?php if ($day == 'Friday' && strpos($times['start'], '18:') === 0): ?>
                                        <div class="evening-note">âœ¨ Evening Slot</div>
                                    <?php endif; ?>
                                    <?php if (isset($routine_by_day[$day][$slot_id])): ?>
                                        <?php foreach ($routine_by_day[$day][$slot_id] as $class): ?>
                                        <div class="class-cell">
                                            <div class="class-code"><?php echo htmlspecialchars($class['code']); ?></div>
                                            <div class="class-room"><?php echo htmlspecialchars($class['room_name']); ?></div>
                                            <div class="class-room">Batch: <?php echo $class['semester'] . $class['section']; ?></div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span style="color: var(--text-soft);">â€”</span>
                                    <?php endif; ?>
                                </td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <?php if ($today_count > 0): ?>
                <div style="margin-top: 24px; padding: 16px; background: var(--primary-soft); border-radius: 20px;">
                    <h4><i class="fas fa-sun"></i> Today's Classes (<?php echo $today; ?>)</h4>
                    <div style="display: flex; flex-wrap: wrap; gap: 12px; margin-top: 12px;">
                        <?php foreach ($today_classes as $class): ?>
                        <div style="background: white; padding: 8px 16px; border-radius: 40px; color: var(--accent-warm);">
                            <?php echo date('h:i A', strtotime($class['start_time'])); ?> - <?php echo $class['code']; ?> (<?php echo $class['room_name']; ?>)
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- Preferences Section (unchanged) -->
        <section id="preferences" class="dashboard-section">
            <div class="glass-card">
                <h2><i class="fas fa-sliders-h"></i> Preference Management</h2>
                <p style="margin-bottom: 24px;">Customize your teaching preferences to improve routine generation.</p>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 32px;">
                    <div>
                        <h3>Course Ranking</h3>
                        <div id="courseRanking" style="margin-top: 16px;">
                            <?php 
                            $priority_ids = explode(',', $priority_course_ids);
                            $courses_list_rank = $courses_list;
                            usort($courses_list_rank, function($a, $b) use ($priority_ids) {
                                $pos_a = array_search($a['id'], $priority_ids);
                                $pos_b = array_search($b['id'], $priority_ids);
                                if($pos_a === false && $pos_b === false) return 0;
                                if($pos_a === false) return 1;
                                if($pos_b === false) return -1;
                                return $pos_a - $pos_b;
                            });
                            foreach($courses_list_rank as $idx => $c): ?>
                            <div class="course-item" data-course-id="<?php echo $c['id']; ?>" style="display: flex; align-items: center; gap: 12px; padding: 12px; background: var(--primary-soft); margin-bottom: 8px; border-radius: 16px; cursor: move;">
                                <div class="course-handle"><i class="fas fa-grip-vertical"></i></div>
                                <div style="flex:1;"><strong><?php echo htmlspecialchars($c['code']); ?></strong> - <?php echo htmlspecialchars($c['title']); ?></div>
                                <div class="course-rank"><span class="rank-badge" style="background: var(--accent-warm); color: white; padding: 4px 10px; border-radius: 20px;">#<?php echo $idx+1; ?></span></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div>
                        <h3>Teaching Preferences</h3>
                        <div style="margin-bottom: 20px;">
                            <label>Max Continuous Classes: <span id="sliderValue"><?php echo $max_continuous; ?></span></label>
                            <input type="range" id="continuousClasses" min="1" max="5" value="<?php echo $max_continuous; ?>" style="width: 100%; margin-top: 8px;">
                        </div>
                        <div style="margin-bottom: 20px;">
                            <label>Preferred Teaching Hours/Week: <span id="hoursValue"><?php echo $preferred_teaching_hours; ?>h</span></label>
                            <input type="range" id="teachingHours" min="2" max="8" value="<?php echo $preferred_teaching_hours; ?>" style="width: 100%; margin-top: 8px;">
                        </div>
                        <div style="display: flex; gap: 20px; margin-bottom: 20px;">
                            <label><input type="checkbox" id="morningBias" <?php echo $morning_bias == '1' ? 'checked' : ''; ?>> Morning Bias</label>
                            <label><input type="checkbox" id="sameDeptPriority" <?php echo $same_dept_priority == '1' ? 'checked' : ''; ?>> Same Department Priority</label>
                        </div>
                        <button class="btn btn-primary" id="savePreferencesBtn">Save Preferences</button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Availability Section -->
        <section id="availability" class="dashboard-section">
            <div class="glass-card">
                <h2><i class="fas fa-clock"></i> Weekly Availability</h2>
                <div class="timetable-container">
                    <table class="timetable">
                        <thead><tr><th>Time</th><th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th></tr></thead>
                        <tbody>
                            <?php foreach($all_timeslots as $slot_id => $times): ?>
                            <tr>
                                <td><?php echo date('h:i A', strtotime($times['start'])); ?></td>
                                <?php foreach(['Monday','Tuesday','Wednesday','Thursday','Friday'] as $day): 
                                    $status = 'available';
                                    if(isset($availability[$day][$slot_id])) $status = 'preferred';
                                    else if(isset($availability[$day]) && array_key_exists($slot_id, $availability[$day]) === false && !empty($availability)) $status = 'busy';
                                ?>
                                <td class="day-cell-avail" data-day="<?php echo $day; ?>" data-slot-id="<?php echo $slot_id; ?>" style="cursor:pointer; background: <?php echo $status == 'preferred' ? '#f3d9b1' : ($status == 'busy' ? '#f5d0c5' : '#fffef7'); ?>;">
                                    <?php if($status == 'preferred'): ?><i class="fas fa-star" style="color: #e8a87c;"></i><?php elseif($status == 'busy'): ?><i class="fas fa-ban" style="color: #c38d62;"></i><?php endif; ?>
                                </td>
                                <?php endforeach; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div style="margin-top: 24px; display: flex; gap: 16px;">
                    <button class="btn btn-secondary" id="resetAvailabilityBtn">Reset</button>
                    <button class="btn btn-primary" id="saveAvailabilityBtn">Save Availability</button>
                </div>
            </div>
        </section>

        <!-- Analytics Section -->
        <section id="analytics" class="dashboard-section">
            <div class="glass-card">
                <h2><i class="fas fa-chart-line"></i> Workload Analytics (Latest Generation)</h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 32px; margin-top: 24px;">
                    <div>
                        <h3>Daily Hours</h3>
                        <?php foreach($week_days as $day): ?>
                        <div style="margin-bottom: 12px;">
                            <div style="display: flex; justify-content: space-between;"><span><?php echo $day; ?></span><span><?php echo round($daily_hours_array[$day], 1); ?> hrs</span></div>
                            <div class="workload-meter"><div class="workload-fill" style="width: <?php echo min(100, ($daily_hours_array[$day]/8)*100); ?>%;"></div></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div>
                        <h3>Course Distribution</h3>
                        <?php foreach($course_distribution as $cd): ?>
                        <div style="margin-bottom: 12px;">
                            <div style="display: flex; justify-content: space-between;"><span><?php echo $cd['code']; ?></span><span><?php echo round($cd['hours'], 1); ?> hrs</span></div>
                            <div class="workload-meter"><div class="workload-fill" style="width: <?php echo round(($cd['hours']/max(1,$weekly_hours))*100); ?>%;"></div></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>

        <!-- Downloads Section -->
        <section id="downloads" class="dashboard-section">
            <div class="glass-card">
                <h2><i class="fas fa-download"></i> Downloads & Reports</h2>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 24px; margin-top: 24px;">
                    <div style="background: var(--primary-soft); border-radius: 20px; padding: 20px; text-align: center;">
                        <i class="fas fa-print" style="font-size: 2rem; color: var(--accent-warm);"></i>
                        <h3>Print Routine</h3>
                        <p>Printable weekly timetable</p>
                        <button class="btn btn-primary" onclick="window.print()" style="margin-top: 12px;">Print / PDF</button>
                    </div>
                    <div style="background: var(--primary-soft); border-radius: 20px; padding: 20px; text-align: center;">
                        <i class="fas fa-file-excel" style="font-size: 2rem; color: var(--accent-warm);"></i>
                        <h3>CSV Export</h3>
                        <p>Download routine as CSV</p>
                        <a href="?export_csv=1" class="btn btn-primary" style="display: inline-block; margin-top: 12px;">Download CSV</a>
                    </div>
                </div>
            </div>
        </section>
    </main>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Show notification if there are classes today
    <?php if ($today_count > 0): ?>
    const toast = document.createElement('div');
    toast.className = 'notification-toast';
    toast.innerHTML = `<i class="fas fa-bell"></i> You have <?php echo $today_count; ?> class(es) today. Check your schedule!`;
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 5000);
    <?php endif; ?>

    // Mobile sidebar
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    if(menuToggle) {
        menuToggle.addEventListener('click', () => sidebar.classList.toggle('mobile-open'));
        document.addEventListener('click', function(e) {
            if(window.innerWidth <= 992 && sidebar.classList.contains('mobile-open') && !sidebar.contains(e.target) && !menuToggle.contains(e.target))
                sidebar.classList.remove('mobile-open');
        });
    }
    // Navigation
    const navItems = document.querySelectorAll('.nav-item');
    const sections = document.querySelectorAll('.dashboard-section');
    navItems.forEach(item => {
        item.addEventListener('click', function() {
            const target = this.getAttribute('data-section');
            navItems.forEach(nav => nav.classList.remove('active'));
            this.classList.add('active');
            sections.forEach(section => {
                section.classList.remove('active');
                if(section.id === target) section.classList.add('active');
            });
            if(window.innerWidth <= 992) sidebar.classList.remove('mobile-open');
        });
    });
    // Course ranking sortable
    const rankingEl = document.getElementById('courseRanking');
    if(rankingEl) {
        new Sortable(rankingEl, {
            animation: 150,
            handle: '.course-handle',
            onEnd: function() {
                const items = rankingEl.querySelectorAll('.course-item');
                items.forEach((item, idx) => {
                    item.querySelector('.rank-badge').textContent = '#' + (idx+1);
                });
            }
        });
    }
    // Sliders
    const continuousSlider = document.getElementById('continuousClasses');
    const hoursSlider = document.getElementById('teachingHours');
    if(continuousSlider) continuousSlider.addEventListener('input', function() { document.getElementById('sliderValue').textContent = this.value; });
    if(hoursSlider) hoursSlider.addEventListener('input', function() { document.getElementById('hoursValue').textContent = this.value + 'h'; });
    // Save preferences
    document.getElementById('savePreferencesBtn')?.addEventListener('click', function() {
        const courseItems = document.querySelectorAll('#courseRanking .course-item');
        const priorityIds = Array.from(courseItems).map(item => item.getAttribute('data-course-id')).join(',');
        const data = new FormData();
        data.append('action', 'save_preferences');
        data.append('max_continuous', document.getElementById('continuousClasses').value);
        data.append('teaching_hours', document.getElementById('teachingHours').value);
        data.append('morning_bias', document.getElementById('morningBias').checked ? '1' : '0');
        data.append('same_dept_priority', document.getElementById('sameDeptPriority').checked ? '1' : '0');
        data.append('priority_course_ids', priorityIds);
        fetch(window.location.href, { method: 'POST', body: data, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json()).then(res => { if(res.success) alert('Preferences saved!'); else alert('Error'); });
    });
    // Availability grid interaction
    const availCells = document.querySelectorAll('.day-cell-avail');
    availCells.forEach(cell => {
        cell.addEventListener('click', function() {
            const bg = this.style.background;
            if(bg === 'rgb(255, 254, 247)' || bg === '' || bg.includes('fffef7')) {
                this.style.background = '#f3d9b1';
                this.innerHTML = '<i class="fas fa-star" style="color: #e8a87c;"></i>';
            } else if(bg.includes('f3d9b1')) {
                this.style.background = '#f5d0c5';
                this.innerHTML = '<i class="fas fa-ban" style="color: #c38d62;"></i>';
            } else {
                this.style.background = '#fffef7';
                this.innerHTML = '';
            }
        });
    });
    document.getElementById('saveAvailabilityBtn')?.addEventListener('click', function() {
        const availabilityData = [];
        availCells.forEach(cell => {
            const day = cell.getAttribute('data-day');
            const slotId = cell.getAttribute('data-slot-id');
            if(cell.style.background.includes('f3d9b1')) {
                availabilityData.push({ day: day, start_slot_id: slotId, end_slot_id: slotId });
            }
        });
        const data = new FormData();
        data.append('action', 'save_availability');
        data.append('availability_data', JSON.stringify(availabilityData));
        fetch(window.location.href, { method: 'POST', body: data, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json()).then(res => { if(res.success) alert('Availability saved!'); else alert('Error'); });
    });
    document.getElementById('resetAvailabilityBtn')?.addEventListener('click', function() {
        availCells.forEach(cell => {
            cell.style.background = '#fffef7';
            cell.innerHTML = '';
        });
    });
    gsap.from('.glass-card', { duration: 0.6, y: 20, opacity: 0, stagger: 0.1, ease: "power2.out" });
});
</script>
</body>
</html>
<?php $conn->close(); ?>
