<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}
require_once 'database.php';

// ---------- CSV TEMPLATE DOWNLOAD HANDLER ----------
if (isset($_GET['export_template'])) {
    ob_clean();
    $template = $_GET['export_template'];
    $csv_data = [];
    switch($template) {
        case 'depts': $csv_data = [['Name','Code'],['Computer Science And Engineering','CSE'],['Electrical Engineering','EEE']]; break;
        case 'batches': $csv_data = [['Department Code','Type','Semester','Section','Size','Off Day 2 (Optional)'],['CSE','Day','31','C',33,'Saturday'],['CSE','Evening','31','A',25,'']]; break;
        case 'courses': $csv_data = [['Department Code','Title','Code','Credit','Weekly Classes'],['CSE','Artificial Intelligence','CSE101',3.0,2],['CSE','Database Systems','CSE102',3.0,3]]; break;
        case 'teachers': $csv_data = [['Username','Teacher ID','Department Code','Designation','Max Classes/Day','Available Days'],['salman','TCH001','CSE','Lecturer',3,'Sunday,Monday,Tuesday'],['atiq','TCH002','CSE','Professor',3,'Monday,Tuesday,Wednesday']]; break;
        case 'classrooms': $csv_data = [['Room Name','Capacity','Type','Department Code (Optional)','Has Projector (0/1)','Has AC (0/1)'],['Room 101',50,'both','CSE',1,0],['Lab 1',30,'lab','CSE',0,1]]; break;
        case 'timeslots': $csv_data = [['Start Time','End Time'],['08:30:00','10:00:00'],['10:15:00','11:45:00']]; break;
        case 'batch_courses': $csv_data = [['Department Code','Semester','Section','Type','Course Code'],['CSE','31','C','Day','CSE101'],['CSE','31','C','Day','CSE102']]; break;
        case 'teacher_courses': $csv_data = [['Teacher ID','Course Code'],['TCH001','CSE101'],['TCH002','CSE102']]; break;
        default: exit;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$template.'_template.csv"');
    $out = fopen('php://output', 'w');
    foreach ($csv_data as $row) fputcsv($out, $row);
    fclose($out);
    exit;
}

$conn = getDBConnection();

// Helper functions with improved error reporting
function execute($conn, $sql, $types = null, ...$params) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $GLOBALS['error'] = "Prepare failed: " . $conn->error;
        return false;
    }
    if ($types) {
        $stmt->bind_param($types, ...$params);
    }
    if ($stmt->execute()) {
        return true;
    } else {
        $GLOBALS['error'] = "Execute failed: " . $stmt->error . " | SQL: " . $sql;
        return false;
    }
}

function parseCSV($file, $hasHeader = true) {
    $rows = [];
    if (($handle = fopen($file['tmp_name'], "r")) !== FALSE) {
        if ($hasHeader) fgetcsv($handle);
        while (($data = fgetcsv($handle)) !== FALSE) {
            $data = array_map('trim', $data);
            $rows[] = $data;
        }
        fclose($handle);
    }
    return $rows;
}

$msg = '';
$error = '';

// -------------------- CRUD HANDLERS --------------------

// Create User
if (isset($_POST['create_user'])) {
    $role = $_POST['role'];
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $mobile = trim($_POST['mobile']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $status = 'active';
    $check = $conn->query("SELECT id FROM users WHERE username = '$username' OR email = '$email' OR mobile = '$mobile'");
    if ($check->num_rows > 0) {
        $error = "Username, Email, or Mobile already exists!";
    } else {
        $stmt = $conn->prepare("INSERT INTO users (role, username, email, mobile, password, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $role, $username, $email, $mobile, $password, $status);
        if ($stmt->execute()) $msg = "User created. ID: " . $conn->insert_id;
        else $error = $stmt->error;
        $stmt->close();
    }
}

// Departments
if (isset($_POST['dept_action'])) {
    $name = trim($_POST['name']); $code = trim($_POST['code']);
    if ($_POST['dept_action'] == 'create') {
        if (execute($conn, "INSERT INTO departments (name, code) VALUES (?, ?)", "ss", $name, $code)) $msg = "Department created.";
    } elseif ($_POST['dept_action'] == 'update') {
        $id = (int)$_POST['id'];
        if (execute($conn, "UPDATE departments SET name=?, code=? WHERE id=?", "ssi", $name, $code, $id)) $msg = "Department updated.";
    }
}
if (isset($_GET['del_dept'])) { $conn->query("DELETE FROM departments WHERE id=".(int)$_GET['del_dept']); $msg = "Deleted."; }

// Batches
if (isset($_POST['batch_action'])) {
    $dept_id = (int)$_POST['department_id']; $type = $_POST['type']; $semester = $_POST['semester']; $section = $_POST['section']; $size = (int)$_POST['size']; $off_day2 = !empty($_POST['off_day2']) ? $_POST['off_day2'] : null;
    if ($_POST['batch_action'] == 'create') {
        $sql = "INSERT INTO batches (department_id, type, semester, section, size, off_day1, off_day2) VALUES (?, ?, ?, ?, ?, 'Friday', ?)";
        if (execute($conn, $sql, "isssis", $dept_id, $type, $semester, $section, $size, $off_day2)) $msg = "Batch created.";
    } elseif ($_POST['batch_action'] == 'update') {
        $id = (int)$_POST['id'];
        $sql = "UPDATE batches SET department_id=?, type=?, semester=?, section=?, size=?, off_day2=? WHERE id=?";
        if (execute($conn, $sql, "isssisi", $dept_id, $type, $semester, $section, $size, $off_day2, $id)) $msg = "Batch updated.";
    }
}
if (isset($_GET['del_batch'])) { $conn->query("DELETE FROM batches WHERE id=".(int)$_GET['del_batch']); $msg = "Deleted."; }

// Courses
if (isset($_POST['course_action'])) {
    $dept_id = (int)$_POST['department_id']; $title = $_POST['title']; $code = $_POST['code']; $credit = (float)$_POST['credit']; $weekly = (int)$_POST['weekly_classes'];
    if ($_POST['course_action'] == 'create') {
        $sql = "INSERT INTO courses (department_id, title, code, credit, weekly_classes) VALUES (?, ?, ?, ?, ?)";
        if (execute($conn, $sql, "issdi", $dept_id, $title, $code, $credit, $weekly)) $msg = "Course created.";
    } elseif ($_POST['course_action'] == 'update') {
        $id = (int)$_POST['id'];
        $sql = "UPDATE courses SET department_id=?, title=?, code=?, credit=?, weekly_classes=? WHERE id=?";
        if (execute($conn, $sql, "issdii", $dept_id, $title, $code, $credit, $weekly, $id)) $msg = "Course updated.";
    }
}
if (isset($_GET['del_course'])) { $conn->query("DELETE FROM courses WHERE id=".(int)$_GET['del_course']); $msg = "Deleted."; }

// Teachers
if (isset($_POST['teacher_action'])) {
    $user_id = (int)$_POST['user_id']; $teacher_id = $_POST['teacher_id']; $department = $_POST['department']; $designation = $_POST['designation']; $max_classes = (int)$_POST['max_classes_per_day']; $available_days = $_POST['available_days'] ?: null;
    if ($_POST['teacher_action'] == 'create') {
        $sql = "INSERT INTO teachers (user_id, teacher_id, department, designation, max_classes_per_day, available_days) VALUES (?, ?, ?, ?, ?, ?)";
        if (execute($conn, $sql, "isssis", $user_id, $teacher_id, $department, $designation, $max_classes, $available_days)) $msg = "Teacher created.";
    } elseif ($_POST['teacher_action'] == 'update') {
        $id = (int)$_POST['id'];
        $sql = "UPDATE teachers SET user_id=?, teacher_id=?, department=?, designation=?, max_classes_per_day=?, available_days=? WHERE id=?";
        if (execute($conn, $sql, "isssisi", $user_id, $teacher_id, $department, $designation, $max_classes, $available_days, $id)) $msg = "Teacher updated.";
    }
}
if (isset($_GET['del_teacher'])) { $conn->query("DELETE FROM teachers WHERE id=".(int)$_GET['del_teacher']); $msg = "Deleted."; }

// Time Slots
if (isset($_POST['timeslot_action'])) {
    $start = $_POST['start_time']; $end = $_POST['end_time'];
    if ($_POST['timeslot_action'] == 'create') {
        if (execute($conn, "INSERT INTO timeslots (start_time, end_time) VALUES (?, ?)", "ss", $start, $end)) $msg = "Timeslot created.";
    } elseif ($_POST['timeslot_action'] == 'update') {
        $id = (int)$_POST['id'];
        if (execute($conn, "UPDATE timeslots SET start_time=?, end_time=? WHERE id=?", "ssi", $start, $end, $id)) $msg = "Timeslot updated.";
    }
}
if (isset($_GET['del_timeslot'])) { $conn->query("DELETE FROM timeslots WHERE id=".(int)$_GET['del_timeslot']); $msg = "Deleted."; }

// Classrooms
if (isset($_POST['classroom_action'])) {
    $room_name = $_POST['room_name']; $capacity = (int)$_POST['capacity']; $dept_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null; $projector = isset($_POST['has_projector']) ? 1 : 0; $ac = isset($_POST['has_ac']) ? 1 : 0; $type = $_POST['type'];
    if ($_POST['classroom_action'] == 'create') {
        $sql = "INSERT INTO classrooms (room_name, capacity, department_id, has_projector, has_ac, type) VALUES (?, ?, ?, ?, ?, ?)";
        if (execute($conn, $sql, "siiiss", $room_name, $capacity, $dept_id, $projector, $ac, $type)) $msg = "Classroom created.";
    } elseif ($_POST['classroom_action'] == 'update') {
        $id = (int)$_POST['id'];
        $sql = "UPDATE classrooms SET room_name=?, capacity=?, department_id=?, has_projector=?, has_ac=?, type=? WHERE id=?";
        if (execute($conn, $sql, "siiissi", $room_name, $capacity, $dept_id, $projector, $ac, $type, $id)) $msg = "Classroom updated.";
    }
}
if (isset($_GET['del_classroom'])) { $conn->query("DELETE FROM classrooms WHERE id=".(int)$_GET['del_classroom']); $msg = "Deleted."; }

// Batch-Course Assignments
if (isset($_POST['assign_batch_course'])) {
    $batch_id = (int)$_POST['batch_id'];
    foreach ($_POST['course_ids'] ?? [] as $cid) {
        $conn->query("INSERT IGNORE INTO batch_courses (batch_id, course_id) VALUES ($batch_id, $cid)");
    }
    $msg = "Assigned courses to batch.";
}
if (isset($_GET['remove_bc'])) { $conn->query("DELETE FROM batch_courses WHERE id=".(int)$_GET['remove_bc']); $msg = "Removed."; }

// Teacher-Course Assignments
if (isset($_POST['assign_teacher_course'])) {
    $teacher_id = (int)$_POST['teacher_id'];
    foreach ($_POST['course_ids'] ?? [] as $cid) {
        $conn->query("INSERT IGNORE INTO teacher_courses (teacher_id, course_id) VALUES ($teacher_id, $cid)");
    }
    $msg = "Assigned teacher to courses.";
}
if (isset($_GET['remove_tc'])) { $conn->query("DELETE FROM teacher_courses WHERE id=".(int)$_GET['remove_tc']); $msg = "Removed."; }

// Teacher Preferences
if (isset($_POST['save_pref_global'])) {
    $teacher_id = (int)$_POST['teacher_id'];
    $max_classes = (int)$_POST['max_classes_per_day'];
    $preferred_days = isset($_POST['preferred_days']) ? implode(',', $_POST['preferred_days']) : null;
    $pref_start = $_POST['preferred_time_start'] ?: null;
    $pref_end = $_POST['preferred_time_end'] ?: null;
    $priority_course_ids = $_POST['priority_course_ids'] ?: null;
    $stmt = $conn->prepare("INSERT INTO teacher_preferences (teacher_id, max_classes_per_day, preferred_days, preferred_time_start, preferred_time_end, priority_course_ids) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE max_classes_per_day=?, preferred_days=?, preferred_time_start=?, preferred_time_end=?, priority_course_ids=?");
    $stmt->bind_param("iissssissis", $teacher_id, $max_classes, $preferred_days, $pref_start, $pref_end, $priority_course_ids, $max_classes, $preferred_days, $pref_start, $pref_end, $priority_course_ids);
    if ($stmt->execute()) $msg = "Global preferences saved.";
    else $error = $stmt->error;
    $stmt->close();
}
if (isset($_POST['save_course_priority'])) {
    $teacher_id = (int)$_POST['teacher_id'];
    $course_id = (int)$_POST['course_id'];
    $priority = $_POST['priority'];
    $stmt = $conn->prepare("INSERT INTO teacher_preferences (teacher_id, course_id, priority) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE priority = ?");
    $stmt->bind_param("iiss", $teacher_id, $course_id, $priority, $priority);
    if ($stmt->execute()) $msg = "Course priority saved.";
    else $error = $stmt->error;
    $stmt->close();
}
if (isset($_GET['del_pref_course'])) {
    $id = (int)$_GET['del_pref_course'];
    $conn->query("DELETE FROM teacher_preferences WHERE id=$id");
    $msg = "Course preference removed.";
}

// Teacher Availability
if (isset($_POST['save_availability'])) {
    $teacher_id = (int)$_POST['teacher_id'];
    $conn->query("DELETE FROM teacher_availability WHERE teacher_id = $teacher_id");
    foreach ($_POST['avail'] ?? [] as $day => $slots) {
        if (!empty($slots['start']) && !empty($slots['end'])) {
            $start_slot = (int)$slots['start']; $end_slot = (int)$slots['end'];
            $conn->query("INSERT INTO teacher_availability (teacher_id, day, start_slot_id, end_slot_id) VALUES ($teacher_id, '$day', $start_slot, $end_slot)");
        }
    }
    $msg = "Availability updated.";
}

// CSV Imports (all tables)
$import_msg = ''; $import_error = '';
foreach (['depts','batches','courses','teachers','classrooms','timeslots','batch_courses','teacher_courses'] as $type) {
    $post_key = 'import_'.$type; $file_key = $type.'_csv';
    if (isset($_POST[$post_key]) && isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] == 0) {
        $rows = parseCSV($_FILES[$file_key], true);
        $success = 0; $failed = 0;
        $conn->begin_transaction();
        try {
            switch($type) {
                case 'depts':
                    foreach ($rows as $r) { if (count($r)<2) { $failed++; continue; }
                        $name=trim($r[0]); $code=trim($r[1]); if($name && $code) { $stmt=$conn->prepare("INSERT INTO departments (name,code) VALUES (?,?)"); $stmt->bind_param("ss",$name,$code); if($stmt->execute()) $success++; else $failed++; $stmt->close(); } else $failed++; }
                    break;
                case 'batches':
                    foreach ($rows as $r) { if (count($r)<5) { $failed++; continue; }
                        $dept_code=trim($r[0]); $type=trim($r[1]); $sem=trim($r[2]); $sec=trim($r[3]); $size=(int)$r[4]; $off2=isset($r[5])&&trim($r[5])?trim($r[5]):null;
                        $dept=$conn->query("SELECT id FROM departments WHERE code='$dept_code'")->fetch_assoc();
                        if($dept){ $stmt=$conn->prepare("INSERT INTO batches (department_id,type,semester,section,size,off_day1,off_day2) VALUES (?,?,?,?,?,'Friday',?)"); $stmt->bind_param("isssis",$dept['id'],$type,$sem,$sec,$size,$off2); if($stmt->execute()) $success++; else $failed++; $stmt->close(); } else $failed++; }
                    break;
                case 'courses':
                    foreach ($rows as $r) { if (count($r)<5) { $failed++; continue; }
                        $dept_code=trim($r[0]); $title=trim($r[1]); $code=trim($r[2]); $credit=(float)$r[3]; $weekly=(int)$r[4];
                        $dept=$conn->query("SELECT id FROM departments WHERE code='$dept_code'")->fetch_assoc();
                        if($dept){ $stmt=$conn->prepare("INSERT INTO courses (department_id,title,code,credit,weekly_classes) VALUES (?,?,?,?,?)"); $stmt->bind_param("issdi",$dept['id'],$title,$code,$credit,$weekly); if($stmt->execute()) $success++; else $failed++; $stmt->close(); } else $failed++; }
                    break;
                case 'teachers':
                    foreach ($rows as $r) { if (count($r)<6) { $failed++; continue; }
                        $username=trim($r[0]); $teacher_id=trim($r[1]); $dept_code=trim($r[2]); $desig=trim($r[3]); $max=(int)$r[4]; $days=trim($r[5])?:null;
                        $user=$conn->query("SELECT id FROM users WHERE username='$username' AND role IN ('teacher','admin')")->fetch_assoc();
                        if($user){ $stmt=$conn->prepare("INSERT INTO teachers (user_id,teacher_id,department,designation,max_classes_per_day,available_days) VALUES (?,?,?,?,?,?)"); $stmt->bind_param("isssis",$user['id'],$teacher_id,$dept_code,$desig,$max,$days); if($stmt->execute()) $success++; else $failed++; $stmt->close(); } else $failed++; }
                    break;
                case 'classrooms':
                    foreach ($rows as $r) { if (count($r)<6) { $failed++; continue; }
                        $room=trim($r[0]); $cap=(int)$r[1]; $type=trim($r[2]); $dept_code=isset($r[3])&&trim($r[3])?trim($r[3]):null; $proj=(int)$r[4]; $ac=(int)$r[5];
                        $dept_id=null; if($dept_code){ $d=$conn->query("SELECT id FROM departments WHERE code='$dept_code'")->fetch_assoc(); if($d) $dept_id=$d['id']; }
                        $stmt=$conn->prepare("INSERT INTO classrooms (room_name,capacity,department_id,has_projector,has_ac,type) VALUES (?,?,?,?,?,?)"); $stmt->bind_param("siiiss",$room,$cap,$dept_id,$proj,$ac,$type); if($stmt->execute()) $success++; else $failed++; $stmt->close(); }
                    break;
                case 'timeslots':
                    foreach ($rows as $r) { if (count($r)<2) { $failed++; continue; }
                        $start=trim($r[0]); $end=trim($r[1]); $stmt=$conn->prepare("INSERT INTO timeslots (start_time,end_time) VALUES (?,?)"); $stmt->bind_param("ss",$start,$end); if($stmt->execute()) $success++; else $failed++; $stmt->close(); }
                    break;
                case 'batch_courses':
                    foreach ($rows as $r) { if (count($r)<5) { $failed++; continue; }
                        $dept_code=trim($r[0]); $sem=trim($r[1]); $sec=trim($r[2]); $type=trim($r[3]); $course_code=trim($r[4]);
                        $batch=$conn->query("SELECT b.id FROM batches b JOIN departments d ON b.department_id=d.id WHERE d.code='$dept_code' AND b.semester='$sem' AND b.section='$sec' AND b.type='$type'")->fetch_assoc();
                        $course=$conn->query("SELECT id FROM courses WHERE code='$course_code'")->fetch_assoc();
                        if($batch && $course){ $stmt=$conn->prepare("INSERT IGNORE INTO batch_courses (batch_id,course_id) VALUES (?,?)"); $stmt->bind_param("ii",$batch['id'],$course['id']); if($stmt->execute()) $success++; else $failed++; $stmt->close(); } else $failed++; }
                    break;
                case 'teacher_courses':
                    foreach ($rows as $r) { if (count($r)<2) { $failed++; continue; }
                        $teacher_id_str=trim($r[0]); $course_code=trim($r[1]);
                        $teacher=$conn->query("SELECT id FROM teachers WHERE teacher_id='$teacher_id_str'")->fetch_assoc();
                        $course=$conn->query("SELECT id FROM courses WHERE code='$course_code'")->fetch_assoc();
                        if($teacher && $course){ $stmt=$conn->prepare("INSERT IGNORE INTO teacher_courses (teacher_id,course_id) VALUES (?,?)"); $stmt->bind_param("ii",$teacher['id'],$course['id']); if($stmt->execute()) $success++; else $failed++; $stmt->close(); } else $failed++; }
                    break;
            }
            $conn->commit();
            $import_msg = ucfirst($type)." imported: $success added, $failed failed.";
        } catch (Exception $e) { $conn->rollback(); $import_error = "Error: " . $e->getMessage(); }
    }
}

// -------------------- FETCH DATA FOR UI --------------------
$depts = $conn->query("SELECT * FROM departments ORDER BY name");
$batches = $conn->query("SELECT b.*, d.name as dept_name FROM batches b JOIN departments d ON b.department_id = d.id ORDER BY d.name, b.semester");
$courses = $conn->query("SELECT c.*, d.name as dept_name FROM courses c JOIN departments d ON c.department_id = d.id ORDER BY d.name, c.code");
$teachers = $conn->query("SELECT t.*, u.username FROM teachers t JOIN users u ON t.user_id = u.id");
$timeslots = $conn->query("SELECT * FROM timeslots ORDER BY start_time");
$classrooms = $conn->query("SELECT c.*, d.name as dept_name FROM classrooms c LEFT JOIN departments d ON c.department_id = d.id ORDER BY c.room_name");
$batch_courses = $conn->query("SELECT bc.id, b.semester, b.section, b.type, c.code, c.title, d.name as dept_name FROM batch_courses bc JOIN batches b ON bc.batch_id = b.id JOIN courses c ON bc.course_id = c.id JOIN departments d ON b.department_id = d.id");
$teacher_courses = $conn->query("SELECT tc.id, t.teacher_id, u.username as teacher_name, c.code, c.title FROM teacher_courses tc JOIN teachers t ON tc.teacher_id = t.id JOIN users u ON t.user_id = u.id JOIN courses c ON tc.course_id = c.id");
$users = $conn->query("SELECT id, username, role FROM users WHERE role IN ('admin','teacher') ORDER BY username");
$days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday'];
$timeslot_list = [];
$ts_res = $conn->query("SELECT * FROM timeslots ORDER BY start_time");
while ($ts = $ts_res->fetch_assoc()) $timeslot_list[] = $ts;

// Stats
$totalDepts = $depts->num_rows;
$totalBatches = $batches->num_rows;
$totalCourses = $courses->num_rows;
$totalTeachers = $teachers->num_rows;
$totalTimeslots = $timeslots->num_rows;
$totalClassrooms = $classrooms->num_rows;
$totalRoutineAssignments = $conn->query("SELECT COUNT(*) FROM routine_assignments")->fetch_row()[0];
$lastGenId = $conn->query("SELECT MAX(generation_id) as last FROM routine_assignments")->fetch_assoc()['last'];

$deptCourseCounts = []; $deptNames = [];
$deptCoursesRes = $conn->query("SELECT d.name, COUNT(c.id) as count FROM departments d LEFT JOIN courses c ON d.id = c.department_id GROUP BY d.id");
while($row = $deptCoursesRes->fetch_assoc()) { $deptNames[] = $row['name']; $deptCourseCounts[] = $row['count']; }
$dayBatches = $conn->query("SELECT COUNT(*) FROM batches WHERE type='Day'")->fetch_row()[0];
$eveningBatches = $conn->query("SELECT COUNT(*) FROM batches WHERE type='Evening'")->fetch_row()[0];

// Teacher Preferences & Availability
$pref_teachers = $conn->query("SELECT t.id, u.username FROM teachers t JOIN users u ON t.user_id = u.id");
$selected_pref_teacher = isset($_GET['pref_teacher']) ? (int)$_GET['pref_teacher'] : 0;
$teacher_global_pref = null; $teacher_course_prefs = [];
if ($selected_pref_teacher) {
    $g = $conn->query("SELECT * FROM teacher_preferences WHERE teacher_id = $selected_pref_teacher AND course_id IS NULL")->fetch_assoc();
    if($g) $teacher_global_pref = $g;
    $cp = $conn->query("SELECT tp.*, c.code, c.title FROM teacher_preferences tp JOIN courses c ON tp.course_id = c.id WHERE tp.teacher_id = $selected_pref_teacher AND tp.course_id IS NOT NULL");
    while($p = $cp->fetch_assoc()) $teacher_course_prefs[] = $p;
}
$teacher_avail = [];
if ($selected_pref_teacher) {
    $av = $conn->query("SELECT * FROM teacher_availability WHERE teacher_id = $selected_pref_teacher");
    while($a = $av->fetch_assoc()) $teacher_avail[$a['day']] = ['start'=>$a['start_slot_id'], 'end'=>$a['end_slot_id']];
}

$section = $_GET['section'] ?? 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SRMS Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        body { background: linear-gradient(135deg, #e0e7ff 0%, #f5f5f7 40%, #dbeafe 100%); min-height: 100vh; transition: background 0.3s; }
        body.dark { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: #e2e8f0; }
        .navbar { position: sticky; top: 0; backdrop-filter: blur(20px); background: rgba(255,255,255,0.7); display: flex; justify-content: space-between; align-items: center; padding: 12px 30px; z-index: 100; border-bottom: 1px solid rgba(255,255,255,0.5); }
        body.dark .navbar { background: rgba(15,23,42,0.8); border-bottom-color: rgba(255,255,255,0.1); }
        .logo { font-weight: 700; font-size:1.4rem; background: linear-gradient(135deg,#1e293b,#0071e3); -webkit-background-clip:text; background-clip:text; }
        body.dark .logo { background: linear-gradient(135deg,#94a3b8,#60a5fa); }
        .nav-links a { margin:0 15px; text-decoration:none; color:#1d1d1f; font-weight:500; }
        body.dark .nav-links a { color:#cbd5e1; }
        .nav-links a:hover { color:#0071e3; }
        .user-area { display:flex; align-items:center; gap:15px; }
        .dark-toggle { background:rgba(0,0,0,0.1); border:none; border-radius:30px; padding:6px 12px; cursor:pointer; }
        body.dark .dark-toggle { background:rgba(255,255,255,0.2); color:white; }
        .app-container { display:flex; min-height:calc(100vh - 70px); }
        .sidebar { width:280px; backdrop-filter:blur(16px); background:rgba(255,255,255,0.4); border-right:1px solid rgba(255,255,255,0.3); padding:25px 0; }
        body.dark .sidebar { background:rgba(30,41,59,0.4); border-right-color:rgba(255,255,255,0.05); }
        .sidebar-menu { list-style:none; }
        .sidebar-menu li { margin:8px 20px; }
        .sidebar-menu a { display:flex; align-items:center; gap:12px; padding:12px 18px; border-radius:16px; text-decoration:none; color:#1e293b; font-weight:500; transition:0.2s; }
        body.dark .sidebar-menu a { color:#cbd5e1; }
        .sidebar-menu a:hover, .sidebar-menu .active a { background:rgba(0,113,227,0.2); backdrop-filter:blur(4px); color:#0071e3; }
        .sidebar-menu i { width:24px; font-size:1.2rem; }
        .main-content { flex:1; padding:30px; overflow-y:auto; }
        .stats-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:1.2rem; margin-bottom:2rem; }
        .stat-card { background:rgba(255,255,255,0.5); backdrop-filter:blur(12px); padding:1rem; border-radius:20px; text-align:center; border:1px solid rgba(255,255,255,0.4); }
        body.dark .stat-card { background:rgba(30,41,59,0.5); }
        .stat-card h4 { font-size:0.8rem; opacity:0.7; margin-bottom:6px; }
        .stat-card h2 { font-size:1.8rem; font-weight:700; color:#0071e3; }
        body.dark .stat-card h2 { color:#60a5fa; }
        .section-card { background:rgba(255,255,255,0.45); backdrop-filter:blur(20px); border-radius:24px; padding:1.5rem; margin-bottom:2rem; border:1px solid rgba(255,255,255,0.3); }
        body.dark .section-card { background:rgba(30,41,59,0.4); }
        .section-title { font-size:1.3rem; font-weight:600; margin-bottom:1rem; display:flex; align-items:center; gap:10px; }
        .btn { background:#0071e3; color:white; padding:8px 18px; border-radius:40px; text-decoration:none; font-weight:500; display:inline-flex; align-items:center; gap:8px; transition:0.2s; border:none; cursor:pointer; }
        .btn-outline { background:transparent; border:1px solid #0071e3; color:#0071e3; }
        .btn-outline:hover { background:rgba(0,113,227,0.1); }
        .btn-danger { background:#dc3545; }
        .btn-warning { background:#ffc107; color:#333; }
        .btn-sm { padding:5px 12px; font-size:0.8rem; }
        .btn-success { background:#198754; }
        table { width:100%; border-collapse:collapse; background:rgba(255,255,255,0.6); border-radius:16px; overflow:hidden; }
        th, td { padding:10px; border-bottom:1px solid rgba(0,0,0,0.1); }
        body.dark th, body.dark td { border-bottom-color:rgba(255,255,255,0.1); }
        .modal-content { background: rgba(255,255,255,0.95); backdrop-filter: blur(10px); }
        body.dark .modal-content { background: #1e293b; color:white; }
        .csv-card { border-left: 4px solid #0071e3; margin-bottom: 15px; }
        .form-check-label { margin-left: 5px; }
    </style>
</head>
<body>
<div class="navbar">
    <div class="logo"><i class="fas fa-calendar-alt"></i> SRMS • Admin</div>
    <div class="nav-links"><a href="#">Dashboard</a><a href="#">Help</a></div>
    <div class="user-area">
        <i class="fas fa-user-shield"></i> <?= htmlspecialchars($_SESSION['username'] ?? 'Admin') ?>
        <button class="dark-toggle" id="darkModeToggle"><i class="fas fa-moon"></i> Dark</button>
        <a href="logout.php" class="btn btn-sm" style="background:#6c757d;">Logout</a>
    </div>
</div>
<div class="app-container">
    <div class="sidebar">
        <ul class="sidebar-menu">
            <li class="<?= ($section=='dashboard')?'active':'' ?>"><a href="?section=dashboard"><i class="fas fa-tachometer-alt"></i> Dashboard</a></li>
            <li class="<?= ($section=='departments')?'active':'' ?>"><a href="?section=departments"><i class="fas fa-building"></i> Departments</a></li>
            <li class="<?= ($section=='batches')?'active':'' ?>"><a href="?section=batches"><i class="fas fa-users"></i> Batches</a></li>
            <li class="<?= ($section=='courses')?'active':'' ?>"><a href="?section=courses"><i class="fas fa-book"></i> Courses</a></li>
            <li class="<?= ($section=='teachers')?'active':'' ?>"><a href="?section=teachers"><i class="fas fa-chalkboard-user"></i> Teachers</a></li>
            <li class="<?= ($section=='preferences')?'active':'' ?>"><a href="?section=preferences"><i class="fas fa-sliders-h"></i> Teacher Preferences</a></li>
            <li class="<?= ($section=='availability')?'active':'' ?>"><a href="?section=availability"><i class="fas fa-clock"></i> Teacher Availability</a></li>
            <li class="<?= ($section=='timeslots')?'active':'' ?>"><a href="?section=timeslots"><i class="fas fa-hourglass-half"></i> Time Slots</a></li>
            <li class="<?= ($section=='classrooms')?'active':'' ?>"><a href="?section=classrooms"><i class="fas fa-door-open"></i> Classrooms</a></li>
            <li class="<?= ($section=='assignments')?'active':'' ?>"><a href="?section=assignments"><i class="fas fa-link"></i> Assignments</a></li>
            <li class="<?= ($section=='csv_import')?'active':'' ?>"><a href="?section=csv_import"><i class="fas fa-upload"></i> CSV Import</a></li>
            <li class="<?= ($section=='routine')?'active':'' ?>"><a href="?section=routine"><i class="fas fa-calendar-week"></i> View Routine</a></li>
            <li class="<?= ($section=='conflicts')?'active':'' ?>"><a href="?section=conflicts"><i class="fas fa-exclamation-triangle"></i> Conflicts</a></li>
            <hr class="bg-secondary my-2">
            <li><a href="modules/routine/generate.php" onclick="return confirm('Generate new routine?')"><i class="fas fa-sync-alt"></i> Generate Routine</a></li>
        </ul>
    </div>
    <div class="main-content">
        <?php if($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
        <?php if($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if($import_msg): ?><div class="alert alert-success"><?= htmlspecialchars($import_msg) ?></div><?php endif; ?>
        <?php if($import_error): ?><div class="alert alert-danger"><?= htmlspecialchars($import_error) ?></div><?php endif; ?>

        <!-- DASHBOARD -->
        <?php if($section == 'dashboard'): ?>
        <div class="stats-grid">
            <div class="stat-card"><h4>Departments</h4><h2><?= $totalDepts ?></h2></div>
            <div class="stat-card"><h4>Batches</h4><h2><?= $totalBatches ?></h2></div>
            <div class="stat-card"><h4>Courses</h4><h2><?= $totalCourses ?></h2></div>
            <div class="stat-card"><h4>Teachers</h4><h2><?= $totalTeachers ?></h2></div>
            <div class="stat-card"><h4>Time Slots</h4><h2><?= $totalTimeslots ?></h2></div>
            <div class="stat-card"><h4>Classrooms</h4><h2><?= $totalClassrooms ?></h2></div>
            <div class="stat-card"><h4>Routine Classes</h4><h2><?= $totalRoutineAssignments ?></h2></div>
            <div class="stat-card"><h4>Last Gen</h4><h2><?= $lastGenId ?: 'None' ?></h2></div>
        </div>
        <div class="row">
            <div class="col-md-6"><div class="section-card"><div class="section-title"><i class="fas fa-chart-bar"></i> Courses per Department</div><canvas id="deptCoursesChart" style="max-height:300px;"></canvas></div></div>
            <div class="col-md-6"><div class="section-card"><div class="section-title"><i class="fas fa-chart-pie"></i> Batch Type Distribution</div><canvas id="batchTypeChart" style="max-height:300px;"></canvas></div></div>
        </div>
        <div class="section-card"><div class="section-title"><i class="fas fa-rocket"></i> Quick Actions</div>
        <div><a href="?section=routine" class="btn">View Routine</a> <a href="modules/routine/generate.php" class="btn btn-warning">Generate Routine</a> <a href="?section=assignments" class="btn btn-outline">Assign Courses</a> <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal">+ New User</button></div></div>
        <script> new Chart(document.getElementById('deptCoursesChart'),{type:'bar',data:{labels:<?= json_encode($deptNames) ?>,datasets:[{label:'Number of Courses',data:<?= json_encode($deptCourseCounts) ?>,backgroundColor:'#0071e3'}]}}); new Chart(document.getElementById('batchTypeChart'),{type:'pie',data:{labels:['Day Batches','Evening Batches'],datasets:[{data:[<?= $dayBatches ?>,<?= $eveningBatches ?>],backgroundColor:['#0071e3','#ffc107']}]}}); </script>
        <?php endif; ?>

        <!-- DEPARTMENTS -->
        <?php if($section == 'departments'): ?>
        <div class="section-card"><div class="d-flex justify-content-between"><h3>Departments</h3><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#deptModal" onclick="clearDeptForm()">+ Add</button></div>
        <table class="table"><thead><tr><th>ID</th><th>Name</th><th>Code</th><th>Actions</th></tr></thead><tbody>
        <?php $depts->data_seek(0); while($d=$depts->fetch_assoc()): ?>
        <tr><td><?= $d['id'] ?></td><td><?= htmlspecialchars($d['name']) ?></td><td><?= $d['code'] ?></td>
        <td><button class="btn btn-sm btn-warning" onclick="editDept(<?= $d['id'] ?>,'<?= addslashes($d['name']) ?>','<?= $d['code'] ?>')">Edit</button> <a href="?section=departments&del_dept=<?= $d['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete?')">Del</a></td></tr>
        <?php endwhile; ?>
        </tbody></table></div>
        <?php endif; ?>

        <!-- BATCHES (SYNTAX FIXED) -->
        <?php if($section == 'batches'): ?>
        <div class="section-card"><div class="d-flex justify-content-between"><h3>Batches</h3><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#batchModal" onclick="clearBatchForm()">+ Add</button></div>
        <table class="table"><thead><tr><th>Dept</th><th>Type</th><th>Sem</th><th>Sec</th><th>Size</th><th>Off Days</th><th>Actions</th></tr></thead><tbody>
        <?php $batches->data_seek(0); while($b=$batches->fetch_assoc()): 
            $off_days = "Friday";
            if (!empty($b['off_day2'])) $off_days .= ", " . $b['off_day2'];
        ?>
        <tr>
            <td><?= $b['dept_name'] ?></td>
            <td><?= $b['type'] ?></td>
            <td><?= $b['semester'] ?></td>
            <td><?= $b['section'] ?></td>
            <td><?= $b['size'] ?></td>
            <td><?= $off_days ?></td>
            <td><button class="btn btn-sm btn-warning" onclick="editBatch(<?= $b['id'] ?>,<?= $b['department_id'] ?>,'<?= $b['type'] ?>','<?= $b['semester'] ?>','<?= $b['section'] ?>',<?= $b['size'] ?>,'<?= $b['off_day2'] ?>')">Edit</button> <a href="?section=batches&del_batch=<?= $b['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete?')">Del</a></td>
        </tr>
        <?php endwhile; ?>
        </tbody></table></div>
        <?php endif; ?>

        <!-- COURSES -->
        <?php if($section == 'courses'): ?>
        <div class="section-card"><div class="d-flex justify-content-between"><h3>Courses</h3><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#courseModal" onclick="clearCourseForm()">+ Add</button></div>
        <table class="table"><thead><tr><th>Code</th><th>Title</th><th>Credit</th><th>Weekly</th><th>Dept</th><th>Actions</th></tr></thead><tbody>
        <?php $courses->data_seek(0); while($c=$courses->fetch_assoc()): ?>
        <tr><td><?= $c['code'] ?></td><td><?= htmlspecialchars($c['title']) ?></td><td><?= $c['credit'] ?></td><td><?= $c['weekly_classes'] ?></td><td><?= $c['dept_name'] ?></td>
        <td><button class="btn btn-sm btn-warning" onclick="editCourse(<?= $c['id'] ?>,<?= $c['department_id'] ?>,'<?= addslashes($c['title']) ?>','<?= $c['code'] ?>',<?= $c['credit'] ?>,<?= $c['weekly_classes'] ?>)">Edit</button> <a href="?section=courses&del_course=<?= $c['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete?')">Del</a></td>
        </tr>
        <?php endwhile; ?>
        </tbody></table></div>
        <?php endif; ?>

        <!-- TEACHERS -->
        <?php if($section == 'teachers'): ?>
        <div class="section-card"><div class="d-flex justify-content-between"><h3>Teachers</h3><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#teacherModal" onclick="clearTeacherForm()">+ Add Teacher</button></div>
        <table class="table"><thead><tr><th>Teacher ID</th><th>Name</th><th>Dept</th><th>Designation</th><th>Max/Day</th><th>Actions</th></tr></thead><tbody>
        <?php $teachers->data_seek(0); while($t=$teachers->fetch_assoc()): ?>
        <tr><td><?= $t['teacher_id'] ?></td><td><?= htmlspecialchars($t['username']) ?></td><td><?= $t['department'] ?></td><td><?= $t['designation'] ?></td><td><?= $t['max_classes_per_day'] ?></td>
        <td><button class="btn btn-sm btn-warning" onclick="editTeacher(<?= $t['id'] ?>,<?= $t['user_id'] ?>,'<?= $t['teacher_id'] ?>','<?= $t['department'] ?>','<?= $t['designation'] ?>',<?= $t['max_classes_per_day'] ?>,'<?= $t['available_days'] ?>')">Edit</button> <a href="?section=teachers&del_teacher=<?= $t['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete?')">Del</a></td>
        </tr>
        <?php endwhile; ?>
        </tbody></table></div>
        <?php endif; ?>

        <!-- TEACHER PREFERENCES -->
        <?php if($section == 'preferences'): ?>
        <div class="section-card"><h3>Teacher Preferences</h3>
        <form method="GET" class="mb-3"><input type="hidden" name="section" value="preferences"><select name="pref_teacher" class="form-select w-auto d-inline-block" onchange="this.form.submit()"><option value="">Select Teacher</option><?php $pref_teachers->data_seek(0); while($pt=$pref_teachers->fetch_assoc()): ?><option value="<?= $pt['id'] ?>" <?= $selected_pref_teacher==$pt['id']?'selected':'' ?>><?= htmlspecialchars($pt['username']) ?></option><?php endwhile; ?></select></form>
        <?php if($selected_pref_teacher): ?>
        <form method="POST" class="mb-4 p-3 border rounded">
            <input type="hidden" name="teacher_id" value="<?= $selected_pref_teacher ?>">
            <h5>Global Preferences</h5>
            <div class="row mb-2"><div class="col-md-3"><label>Max Classes/Day</label><input type="number" name="max_classes_per_day" class="form-control" value="<?= $teacher_global_pref['max_classes_per_day'] ?? '' ?>"></div>
            <div class="col-md-3"><label>Preferred Time Start</label><input type="time" name="preferred_time_start" class="form-control" value="<?= $teacher_global_pref['preferred_time_start'] ?? '' ?>"></div>
            <div class="col-md-3"><label>Preferred Time End</label><input type="time" name="preferred_time_end" class="form-control" value="<?= $teacher_global_pref['preferred_time_end'] ?? '' ?>"></div></div>
            <div class="mb-2"><label>Preferred Days</label><div class="d-flex flex-wrap"><?php foreach(['Sunday','Monday','Tuesday','Wednesday','Thursday'] as $d): $checked = ($teacher_global_pref && strpos($teacher_global_pref['preferred_days'], $d) !== false) ? 'checked' : ''; ?><div class="form-check me-3"><input class="form-check-input" type="checkbox" name="preferred_days[]" value="<?= $d ?>" <?= $checked ?>><label class="form-check-label"><?= $d ?></label></div><?php endforeach; ?></div></div>
            <div class="mb-2"><label>Priority Course IDs (comma separated course ids, optional)</label><input type="text" name="priority_course_ids" class="form-control" placeholder="e.g., 101,102" value="<?= $teacher_global_pref['priority_course_ids'] ?? '' ?>"></div>
            <button type="submit" name="save_pref_global" class="btn btn-primary">Save Global Preferences</button>
        </form>
        <hr>
        <h5>Per-Course Priorities</h5>
        <form method="POST" class="row g-2 mb-3"><input type="hidden" name="teacher_id" value="<?= $selected_pref_teacher ?>">
            <div class="col-auto"><select name="course_id" class="form-select" required><option value="">Select Course</option><?php $all_c = $conn->query("SELECT id, code, title FROM courses ORDER BY code"); while($co=$all_c->fetch_assoc()): ?><option value="<?= $co['id'] ?>"><?= $co['code']." - ".$co['title'] ?></option><?php endwhile; ?></select></div>
            <div class="col-auto"><select name="priority" class="form-select"><option>High</option><option>Medium</option><option>Low</option></select></div>
            <div class="col-auto"><button type="submit" name="save_course_priority" class="btn btn-success">Set Priority</button></div>
        </form>
        <table class="table"><thead><tr><th>Course</th><th>Priority</th><th>Action</th></tr></thead><tbody>
        <?php foreach($teacher_course_prefs as $p): ?>
        <tr><td><?= $p['code']." - ".$p['title'] ?></td><td><?= $p['priority'] ?></td><td><a href="?section=preferences&pref_teacher=<?= $selected_pref_teacher ?>&del_pref_course=<?= $p['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Remove?')">Remove</a></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php else: echo "<p>Select a teacher to manage preferences.</p>"; endif; ?>
        </div>
        <?php endif; ?>

        <!-- TEACHER AVAILABILITY -->
        <?php if($section == 'availability'): ?>
        <div class="section-card"><h3>Teacher Availability (Time Slot Ranges)</h3>
        <form method="GET" class="mb-3"><input type="hidden" name="section" value="availability"><select name="pref_teacher" class="form-select w-auto d-inline-block" onchange="this.form.submit()"><option value="">Select Teacher</option><?php $pref_teachers->data_seek(0); while($pt=$pref_teachers->fetch_assoc()): ?><option value="<?= $pt['id'] ?>" <?= $selected_pref_teacher==$pt['id']?'selected':'' ?>><?= htmlspecialchars($pt['username']) ?></option><?php endwhile; ?></select></form>
        <?php if($selected_pref_teacher): ?>
        <form method="POST"><input type="hidden" name="teacher_id" value="<?= $selected_pref_teacher ?>">
            <table class="table"><thead><tr><th>Day</th><th>Start Slot</th><th>End Slot</th></tr></thead><tbody>
            <?php foreach($days as $day): $av = $teacher_avail[$day] ?? ['start'=>'','end'=>'']; ?>
            <tr><td><?= $day ?></td>
            <td><select name="avail[<?= $day ?>][start]" class="form-select"><option value="">-- None --</option><?php foreach($timeslot_list as $ts): ?><option value="<?= $ts['id'] ?>" <?= ($av['start']==$ts['id'])?'selected':'' ?>><?= date('h:i A',strtotime($ts['start_time'])) ?> - <?= date('h:i A',strtotime($ts['end_time'])) ?></option><?php endforeach; ?></select></td>
            <td><select name="avail[<?= $day ?>][end]" class="form-select"><option value="">-- None --</option><?php foreach($timeslot_list as $ts): ?><option value="<?= $ts['id'] ?>" <?= ($av['end']==$ts['id'])?'selected':'' ?>><?= date('h:i A',strtotime($ts['start_time'])) ?> - <?= date('h:i A',strtotime($ts['end_time'])) ?></option><?php endforeach; ?></select></td>
            </tr>
            <?php endforeach; ?>
            </tbody></table>
            <button type="submit" name="save_availability" class="btn btn-primary">Save Availability</button>
        </form>
        <?php else: echo "<p>Select a teacher to set availability.</p>"; endif; ?>
        </div>
        <?php endif; ?>

        <!-- TIME SLOTS -->
        <?php if($section == 'timeslots'): ?>
        <div class="section-card"><div class="d-flex justify-content-between"><h3>Time Slots</h3><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#timeslotModal" onclick="clearTimeslotForm()">+ Add</button></div>
        <table class="table"><thead><tr><th>ID</th><th>Start</th><th>End</th><th>Actions</th></tr></thead><tbody>
        <?php $timeslots->data_seek(0); while($ts=$timeslots->fetch_assoc()): ?>
        <tr><td><?= $ts['id'] ?></td><td><?= $ts['start_time'] ?></td><td><?= $ts['end_time'] ?></td><td><button class="btn btn-sm btn-warning" onclick="editTimeslot(<?= $ts['id'] ?>,'<?= $ts['start_time'] ?>','<?= $ts['end_time'] ?>')">Edit</button> <a href="?section=timeslots&del_timeslot=<?= $ts['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete?')">Del</a></td></tr>
        <?php endwhile; ?>
        </tbody></table></div>
        <?php endif; ?>

        <!-- CLASSROOMS -->
        <?php if($section == 'classrooms'): ?>
        <div class="section-card"><div class="d-flex justify-content-between"><h3>Classrooms</h3><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#classroomModal" onclick="clearClassroomForm()">+ Add</button></div>
        <table class="table"><thead><tr><th>Room</th><th>Capacity</th><th>Type</th><th>Dept</th><th>Projector</th><th>AC</th><th>Actions</th></tr></thead><tbody>
        <?php $classrooms->data_seek(0); while($cr=$classrooms->fetch_assoc()): ?>
        <tr><td><?= $cr['room_name'] ?></td><td><?= $cr['capacity'] ?></td><td><?= $cr['type'] ?></td><td><?= $cr['dept_name']?:'Any' ?></td><td><?= $cr['has_projector']?'✓':'✗' ?></td><td><?= $cr['has_ac']?'✓':'✗' ?></td>
        <td><button class="btn btn-sm btn-warning" onclick="editClassroom(<?= $cr['id'] ?>,'<?= $cr['room_name'] ?>',<?= $cr['capacity'] ?>,<?= $cr['department_id']?:0 ?>,<?= $cr['has_projector'] ?>,<?= $cr['has_ac'] ?>,'<?= $cr['type'] ?>')">Edit</button> <a href="?section=classrooms&del_classroom=<?= $cr['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete?')">Del</a></td>
        </tr>
        <?php endwhile; ?>
        </tbody></table></div>
        <?php endif; ?>

        <!-- ASSIGNMENTS -->
        <?php if($section == 'assignments'): ?>
        <div class="row g-3">
            <div class="col-md-6"><div class="section-card"><h4>Batch → Courses</h4><button class="btn btn-sm btn-primary mb-2" data-bs-toggle="modal" data-bs-target="#assignBatchCourseModal">+ Assign</button><table class="table table-sm"><thead><tr><th>Batch</th><th>Course</th><th></th></tr></thead><tbody><?php $batch_courses->data_seek(0); while($bc=$batch_courses->fetch_assoc()): ?><tr><td><?= $bc['dept_name']." ".$bc['semester'].$bc['section']." (".$bc['type'].")" ?></td><td><?= $bc['code']." - ".$bc['title'] ?></td><td><a href="?section=assignments&remove_bc=<?= $bc['id'] ?>" class="btn btn-sm btn-danger">Remove</a></td></tr><?php endwhile; ?></tbody></table></div></div>
            <div class="col-md-6"><div class="section-card"><h4>Teacher → Courses</h4><button class="btn btn-sm btn-primary mb-2" data-bs-toggle="modal" data-bs-target="#assignTeacherCourseModal">+ Assign</button><table class="table table-sm"><thead><tr><th>Teacher</th><th>Course</th><th></th></tr></thead><tbody><?php $teacher_courses->data_seek(0); while($tc=$teacher_courses->fetch_assoc()): ?><tr><td><?= $tc['teacher_name']." (".$tc['teacher_id'].")" ?></td><td><?= $tc['code']." - ".$tc['title'] ?></td><td><a href="?section=assignments&remove_tc=<?= $tc['id'] ?>" class="btn btn-sm btn-danger">Remove</a></td></tr><?php endwhile; ?></tbody></table></div></div>
        </div>
        <?php endif; ?>

        <!-- CSV IMPORT -->
        <?php if($section == 'csv_import'): ?>
        <div class="section-card"><div class="section-title"><i class="fas fa-upload"></i> CSV Import Tools</div><p class="text-muted">Upload CSV files (headers required). Click "Example CSV" to download templates.</p>
        <div class="row"><?php $imports = ['depts'=>'Departments','batches'=>'Batches','courses'=>'Courses','teachers'=>'Teachers','classrooms'=>'Classrooms','timeslots'=>'Time Slots','batch_courses'=>'Batch → Courses','teacher_courses'=>'Teacher → Courses']; foreach($imports as $key=>$label): ?><div class="col-md-6 col-lg-4 mb-3"><div class="card csv-card"><div class="card-body"><h5><?= $label ?></h5><a href="?export_template=<?= $key ?>" class="btn btn-sm btn-outline-primary mb-2"><i class="fas fa-download"></i> Example CSV</a><form method="POST" enctype="multipart/form-data"><input type="file" name="<?= $key ?>_csv" accept=".csv" class="form-control form-control-sm mb-2" required><button type="submit" name="import_<?= $key ?>" class="btn btn-sm btn-success">Import</button></form></div></div></div><?php endforeach; ?></div></div>
        <?php endif; ?>

        <!-- ROUTINE VIEW -->
      <?php if($section == 'routine'): ?>
    <div class="section-card">
        <h3>Class Routine (Latest Generation)</h3>

        <!-- Batch নির্বাচন -->
        <form method="GET" class="mb-3">
            <input type="hidden" name="section" value="routine">
            <select name="batch_id" class="form-select w-auto d-inline-block" onchange="this.form.submit()">
                <option value="">-- Select Batch --</option>
                <?php 
                $batches->data_seek(0); 
                while($b = $batches->fetch_assoc()): 
                ?>
                    <option value="<?= $b['id'] ?>" 
                        <?= (isset($_GET['batch_id']) && $_GET['batch_id'] == $b['id']) ? 'selected' : '' ?>>
                        <?= $b['dept_name']." - Sem ".$b['semester'].$b['section']." (".$b['type'].")" ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </form>

        <?php if(isset($_GET['batch_id']) && $_GET['batch_id']): ?>
            
            <?php 
            $bid = (int)$_GET['batch_id']; 
            $gen_id = $conn->query("SELECT MAX(generation_id) as gen FROM routine_assignments")
                           ->fetch_assoc()['gen']; 
            ?>

            <?php if(!$gen_id): ?>
                <p>No routine generated yet.</p>
            <?php else: ?>

                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-dark">
                            <tr>
                                <th>Time</th>
                                <?php foreach($days as $d): ?>
                                    <th><?= $d ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>

                        <tbody>
                            <?php foreach($timeslot_list as $slot): ?>
                                <tr>
                                    <td class="bg-light">
                                        <?= date('h:i A', strtotime($slot['start_time'])) . ' - ' . date('h:i A', strtotime($slot['end_time'])) ?>
                                    </td>

                                    <?php foreach($days as $day): ?>
                                        <td>
                                            <?php 
                                            $res = $conn->query("
                                                SELECT c.code, u.username as teacher, cr.room_name 
                                                FROM routine_assignments ra 
                                                JOIN courses c ON ra.course_id = c.id 
                                                JOIN teachers t ON ra.teacher_id = t.id 
                                                JOIN users u ON t.user_id = u.id 
                                                JOIN classrooms cr ON ra.classroom_id = cr.id 
                                                WHERE ra.batch_id = $bid 
                                                AND ra.day = '$day' 
                                                AND ra.timeslot_id = {$slot['id']} 
                                                AND ra.generation_id = $gen_id
                                            ");

                                            while($row = $res->fetch_assoc()):
                                            ?>
                                                <div>
                                                    <strong><?= $row['code'] ?></strong><br>
                                                    <?= $row['teacher'] ?><br>
                                                    <small><?= $row['room_name'] ?></small>
                                                </div>
                                            <?php endwhile; ?>
                                        </td>
                                    <?php endforeach; ?>

                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php endif; ?>

        <?php else: ?>
            <p>Select a batch to view routine.</p>
        <?php endif; ?>

    </div>
<?php endif; ?>

        <!-- CONFLICTS -->
        <?php if($section == 'conflicts'): ?>
        <div class="section-card"><h3>Conflict Report (Latest Generation)</h3>
        <?php $gen_id = $conn->query("SELECT MAX(generation_id) as last FROM routine_assignments")->fetch_assoc()['last'];
        if(!$gen_id) echo "<p>No routine generated yet.</p>";
        else {
            $overload = $conn->query("SELECT teacher_id, day, COUNT(*) as cnt FROM routine_assignments WHERE generation_id=$gen_id GROUP BY teacher_id, day HAVING cnt > (SELECT max_classes_per_day FROM teachers WHERE id=teacher_id)");
            if($overload->num_rows) { echo "<h5>⚠️ Teacher Overload</h5><ul>"; while($o=$overload->fetch_assoc()) echo "<li>Teacher {$o['teacher_id']} on {$o['day']}: {$o['cnt']} classes</li>"; echo "</ul>"; } else echo "<p>No overload conflicts.</p>";
            $gaps = $conn->query("SELECT batch_id, day, GROUP_CONCAT(timeslot_id ORDER BY timeslot_id) as slots FROM routine_assignments WHERE generation_id=$gen_id GROUP BY batch_id, day");
            echo "<h5>⏳ Batch Gaps</h5><ul>"; $hasGap=false; while($g=$gaps->fetch_assoc()){ $slots=explode(',',$g['slots']); for($i=0;$i<count($slots)-1;$i++) if($slots[$i+1]-$slots[$i]>1) { echo "<li>Batch {$g['batch_id']} on {$g['day']}: gap between {$slots[$i]} and {$slots[$i+1]}</li>"; $hasGap=true; } } if(!$hasGap) echo "<li>No gaps detected.</li>"; echo "</ul>";
        } ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- MODALS (CRUD) -->
<div class="modal fade" id="createUserModal" tabindex="-1"><div class="modal-dialog"><form method="POST" class="modal-content"><div class="modal-header"><h5>Create New User</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><select name="role" class="form-select mb-2"><option value="admin">Admin</option><option value="teacher">Teacher</option><option value="student">Student</option></select><input name="username" class="form-control mb-2" placeholder="Username" required><input name="email" type="email" class="form-control mb-2" placeholder="Email" required><input name="mobile" class="form-control mb-2" placeholder="Mobile"><input name="password" type="password" class="form-control mb-2" placeholder="Password" required></div><div class="modal-footer"><button type="submit" name="create_user" class="btn btn-primary">Create</button></div></form></div></div>
<div class="modal fade" id="deptModal" tabindex="-1"><div class="modal-dialog"><form method="POST" class="modal-content"><div class="modal-header"><h5>Department</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="dept_action" id="dept_action" value="create"><input type="hidden" name="id" id="dept_id"><input name="name" id="dept_name" class="form-control mb-2" placeholder="Name" required><input name="code" id="dept_code" class="form-control" placeholder="Code" required></div><div class="modal-footer"><button type="submit" class="btn btn-primary">Save</button></div></form></div></div>
<div class="modal fade" id="batchModal" tabindex="-1"><div class="modal-dialog"><form method="POST" class="modal-content"><div class="modal-header"><h5>Batch</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="batch_action" id="batch_action" value="create"><input type="hidden" name="id" id="batch_id"><select name="department_id" id="batch_dept" class="form-select mb-2" required><?php $depts->data_seek(0); while($d=$depts->fetch_assoc()): ?><option value="<?= $d['id'] ?>"><?= $d['name'] ?></option><?php endwhile; ?></select><select name="type" class="form-select mb-2"><option>Day</option><option>Evening</option></select><input name="semester" id="batch_sem" class="form-control mb-2" placeholder="Semester" required><input name="section" id="batch_sec" class="form-control mb-2" placeholder="Section" required><input name="size" id="batch_size" type="number" class="form-control mb-2" placeholder="Size"><select name="off_day2" id="batch_off2" class="form-select"><option value="">No second off-day</option><option>Saturday</option><option>Sunday</option><option>Monday</option><option>Tuesday</option><option>Wednesday</option><option>Thursday</option></select></div><div class="modal-footer"><button type="submit" class="btn btn-primary">Save</button></div></form></div></div>
<div class="modal fade" id="courseModal" tabindex="-1"><div class="modal-dialog"><form method="POST" class="modal-content"><div class="modal-header"><h5>Course</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="course_action" id="course_action" value="create"><input type="hidden" name="id" id="course_id"><select name="department_id" id="course_dept" class="form-select mb-2" required><?php $depts->data_seek(0); while($d=$depts->fetch_assoc()): ?><option value="<?= $d['id'] ?>"><?= $d['name'] ?></option><?php endwhile; ?></select><input name="title" id="course_title" class="form-control mb-2" placeholder="Title" required><input name="code" id="course_code" class="form-control mb-2" placeholder="Code" required><input name="credit" id="course_credit" step="0.5" class="form-control mb-2" placeholder="Credit" required><input name="weekly_classes" id="course_weekly" type="number" class="form-control" placeholder="Weekly Classes" required></div><div class="modal-footer"><button type="submit" class="btn btn-primary">Save</button></div></form></div></div>
<div class="modal fade" id="teacherModal" tabindex="-1"><div class="modal-dialog"><form method="POST" class="modal-content"><div class="modal-header"><h5>Teacher</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="teacher_action" id="teacher_action" value="create"><input type="hidden" name="id" id="teacher_id"><select name="user_id" id="teacher_user_id" class="form-select mb-2" required><option value="">Select User</option><?php $users->data_seek(0); while($u=$users->fetch_assoc()): ?><option value="<?= $u['id'] ?>"><?= $u['username']." (".$u['role'].")" ?></option><?php endwhile; ?></select><input name="teacher_id" id="teacher_code" class="form-control mb-2" placeholder="Teacher ID" required><input name="department" id="teacher_dept" class="form-control mb-2" placeholder="Department Code" required><input name="designation" id="teacher_desig" class="form-control mb-2" placeholder="Designation"><input name="max_classes_per_day" id="teacher_max" type="number" class="form-control mb-2" placeholder="Max Classes/Day"><input name="available_days" id="teacher_days" class="form-control" placeholder="Available days (comma separated)"></div><div class="modal-footer"><button type="submit" class="btn btn-primary">Save</button></div></form></div></div>
<div class="modal fade" id="timeslotModal" tabindex="-1"><div class="modal-dialog"><form method="POST" class="modal-content"><div class="modal-header"><h5>Time Slot</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="timeslot_action" id="timeslot_action" value="create"><input type="hidden" name="id" id="timeslot_id"><input type="time" name="start_time" id="ts_start" class="form-control mb-2" required><input type="time" name="end_time" id="ts_end" class="form-control" required></div><div class="modal-footer"><button type="submit" class="btn btn-primary">Save</button></div></form></div></div>
<div class="modal fade" id="classroomModal" tabindex="-1"><div class="modal-dialog"><form method="POST" class="modal-content"><div class="modal-header"><h5>Classroom</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="classroom_action" id="classroom_action" value="create"><input type="hidden" name="id" id="classroom_id"><input name="room_name" id="room_name" class="form-control mb-2" placeholder="Room Name" required><input name="capacity" id="capacity" type="number" class="form-control mb-2" placeholder="Capacity" required><select name="type" id="classroom_type" class="form-select mb-2"><option value="both">Both (Theory & Lab)</option><option value="theory">Theory Only</option><option value="lab">Lab Only</option></select><select name="department_id" id="classroom_dept" class="form-select mb-2"><option value="">Any Department</option><?php $depts->data_seek(0); while($d=$depts->fetch_assoc()): ?><option value="<?= $d['id'] ?>"><?= $d['name'] ?></option><?php endwhile; ?></select><div class="form-check"><input class="form-check-input" type="checkbox" name="has_projector" id="has_projector"><label>Projector</label></div><div class="form-check"><input class="form-check-input" type="checkbox" name="has_ac" id="has_ac"><label>AC</label></div></div><div class="modal-footer"><button type="submit" class="btn btn-primary">Save</button></div></form></div></div>
<div class="modal fade" id="assignBatchCourseModal" tabindex="-1"><div class="modal-dialog"><form method="POST" class="modal-content"><div class="modal-header"><h5>Assign Courses to Batch</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><select name="batch_id" class="form-select mb-2" required><option value="">Select Batch</option><?php $batches->data_seek(0); while($b=$batches->fetch_assoc()): ?><option value="<?= $b['id'] ?>"><?= $b['dept_name']." - Sem ".$b['semester'].$b['section']." (".$b['type'].")" ?></option><?php endwhile; ?></select><select name="course_ids[]" multiple class="form-select" size="6" required><?php $courses->data_seek(0); while($c=$courses->fetch_assoc()): ?><option value="<?= $c['id'] ?>"><?= $c['code']." - ".$c['title'] ?></option><?php endwhile; ?></select><small>Hold Ctrl to select multiple</small></div><div class="modal-footer"><button type="submit" name="assign_batch_course" class="btn btn-primary">Assign</button></div></form></div></div>
<div class="modal fade" id="assignTeacherCourseModal" tabindex="-1"><div class="modal-dialog"><form method="POST" class="modal-content"><div class="modal-header"><h5>Assign Courses to Teacher</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><select name="teacher_id" class="form-select mb-2" required><option value="">Select Teacher</option><?php $teachers->data_seek(0); while($t=$teachers->fetch_assoc()): ?><option value="<?= $t['id'] ?>"><?= $t['username']." (".$t['teacher_id'].")" ?></option><?php endwhile; ?></select><select name="course_ids[]" multiple class="form-select" size="6" required><?php $courses->data_seek(0); while($c=$courses->fetch_assoc()): ?><option value="<?= $c['id'] ?>"><?= $c['code']." - ".$c['title'] ?></option><?php endwhile; ?></select><small>Hold Ctrl to select multiple</small></div><div class="modal-footer"><button type="submit" name="assign_teacher_course" class="btn btn-primary">Assign</button></div></form></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const darkToggle = document.getElementById('darkModeToggle');
if (localStorage.getItem('darkMode') === 'enabled') document.body.classList.add('dark');
darkToggle.addEventListener('click', () => { document.body.classList.toggle('dark'); localStorage.setItem('darkMode', document.body.classList.contains('dark') ? 'enabled' : 'disabled'); });
function clearDeptForm(){ document.getElementById('dept_action').value='create'; document.getElementById('dept_id').value=''; document.getElementById('dept_name').value=''; document.getElementById('dept_code').value=''; }
function editDept(id,name,code){ document.getElementById('dept_action').value='update'; document.getElementById('dept_id').value=id; document.getElementById('dept_name').value=name; document.getElementById('dept_code').value=code; new bootstrap.Modal(document.getElementById('deptModal')).show(); }
function clearBatchForm(){ document.getElementById('batch_action').value='create'; document.getElementById('batch_id').value=''; document.getElementById('batch_sem').value=''; document.getElementById('batch_sec').value=''; document.getElementById('batch_size').value=0; document.getElementById('batch_off2').value=''; }
function editBatch(id,dept_id,type,sem,sec,size,off2){ document.getElementById('batch_action').value='update'; document.getElementById('batch_id').value=id; document.getElementById('batch_dept').value=dept_id; document.querySelector('select[name="type"]').value=type; document.getElementById('batch_sem').value=sem; document.getElementById('batch_sec').value=sec; document.getElementById('batch_size').value=size; document.getElementById('batch_off2').value=off2||''; new bootstrap.Modal(document.getElementById('batchModal')).show(); }
function clearCourseForm(){ document.getElementById('course_action').value='create'; document.getElementById('course_id').value=''; document.getElementById('course_title').value=''; document.getElementById('course_code').value=''; document.getElementById('course_credit').value=''; document.getElementById('course_weekly').value=''; }
function editCourse(id,dept_id,title,code,credit,weekly){ document.getElementById('course_action').value='update'; document.getElementById('course_id').value=id; document.getElementById('course_dept').value=dept_id; document.getElementById('course_title').value=title; document.getElementById('course_code').value=code; document.getElementById('course_credit').value=credit; document.getElementById('course_weekly').value=weekly; new bootstrap.Modal(document.getElementById('courseModal')).show(); }
function clearTeacherForm(){ document.getElementById('teacher_action').value='create'; document.getElementById('teacher_id').value=''; document.getElementById('teacher_user_id').value=''; document.getElementById('teacher_code').value=''; document.getElementById('teacher_dept').value=''; document.getElementById('teacher_desig').value=''; document.getElementById('teacher_max').value=''; document.getElementById('teacher_days').value=''; }
function editTeacher(id,user_id,teacher_id,dept,desig,max,days){ document.getElementById('teacher_action').value='update'; document.getElementById('teacher_id').value=id; document.getElementById('teacher_user_id').value=user_id; document.getElementById('teacher_code').value=teacher_id; document.getElementById('teacher_dept').value=dept; document.getElementById('teacher_desig').value=desig; document.getElementById('teacher_max').value=max; document.getElementById('teacher_days').value=days||''; new bootstrap.Modal(document.getElementById('teacherModal')).show(); }
function clearTimeslotForm(){ document.getElementById('timeslot_action').value='create'; document.getElementById('timeslot_id').value=''; document.getElementById('ts_start').value=''; document.getElementById('ts_end').value=''; }
function editTimeslot(id,start,end){ document.getElementById('timeslot_action').value='update'; document.getElementById('timeslot_id').value=id; document.getElementById('ts_start').value=start; document.getElementById('ts_end').value=end; new bootstrap.Modal(document.getElementById('timeslotModal')).show(); }
function clearClassroomForm(){ document.getElementById('classroom_action').value='create'; document.getElementById('classroom_id').value=''; document.getElementById('room_name').value=''; document.getElementById('capacity').value=''; document.getElementById('classroom_type').value='both'; document.getElementById('classroom_dept').value=''; document.getElementById('has_projector').checked=false; document.getElementById('has_ac').checked=false; }
function editClassroom(id,name,cap,dept_id,proj,ac,type){ document.getElementById('classroom_action').value='update'; document.getElementById('classroom_id').value=id; document.getElementById('room_name').value=name; document.getElementById('capacity').value=cap; document.getElementById('classroom_type').value=type; document.getElementById('classroom_dept').value=dept_id||''; document.getElementById('has_projector').checked=proj==1; document.getElementById('has_ac').checked=ac==1; new bootstrap.Modal(document.getElementById('classroomModal')).show(); }
</script>
</body>
</html>
</html>
<?php $conn->close(); ?>