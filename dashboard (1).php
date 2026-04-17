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
        case 'batch_courses': $csv_data = [['Department Code','Semester','Section','Type','Course Code','Teacher ID (mandatory)'],['CSE','31','C','Day','CSE101','TCH001'],['CSE','31','C','Evening','CSE102','TCH002']]; break;
        case 'room_slots': $csv_data = [['day','timeslot_start','timeslot_end','room_name','department_code (optional)'],['Saturday','08:30:00','09:55:00','308','CSE'],['Saturday','10:00:00','11:25:00','A220','']]; break;
        default: exit;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$template.'_template.csv"');
    $out = fopen('php://output', 'w');
    foreach ($csv_data as $row) fputcsv($out, $row);
    fclose($out);
    exit;
}

// ---------- PDF EXPORT (Print view) ----------
if (isset($_GET['print_dept_routine'])) {
    $dept_id = (int)$_GET['print_dept_routine'];
    $dept = $conn->query("SELECT name, code FROM departments WHERE id = $dept_id")->fetch_assoc();
    if (!$dept) exit("Invalid department");
    $batches = $conn->query("SELECT * FROM batches WHERE department_id = $dept_id ORDER BY semester, section");
    $gen_id = $conn->query("SELECT MAX(generation_id) as gen FROM routine_assignments")->fetch_assoc()['gen'];
    $timeslots = $conn->query("SELECT * FROM timeslots ORDER BY start_time")->fetch_all(MYSQLI_ASSOC);
    $days = ['Saturday','Sunday','Monday','Tuesday','Wednesday','Thursday','Friday'];
    ?>
    <!DOCTYPE html>
    <html>
    <head><title><?= $dept['name'] ?> Routine</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h2 { text-align: center; }
        .batch-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; page-break-after: avoid; }
        .batch-table th, .batch-table td { border: 1px solid #ddd; padding: 6px; font-size: 12px; }
        .batch-table th { background: #f2f2f2; }
        .page-break { page-break-before: always; }
        @media print { body { margin: 0; } .no-print { display: none; } }
    </style>
    </head>
    <body>
    <button class="no-print" onclick="window.print()">Print / Save PDF</button>
    <?php while($batch = $batches->fetch_assoc()): 
        $offDays = [$batch['off_day1']];
        if($batch['off_day2']) $offDays[] = $batch['off_day2'];
        $displayDays = array_diff($days, $offDays);
        if($batch['type'] == 'Evening') $displayDays = $days;
        $displayDays = array_values($displayDays);
    ?>
    <h3><?= $dept['code'] ?> – Batch <?= $batch['semester'].$batch['section'] ?> (<?= $batch['type'] ?>)</h3>
    <table class="batch-table">
        <thead><tr><th>Time</th><?php foreach($displayDays as $d): ?><th><?= $d ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach($timeslots as $slot): ?>
        <tr>
            <td><?= date('h:i A', strtotime($slot['start_time'])) ?> - <?= date('h:i A', strtotime($slot['end_time'])) ?></td>
            <?php foreach($displayDays as $day): ?>
            <td>
                <?php
                $res = $conn->query("
                    SELECT c.code, u.username, cr.room_name
                    FROM routine_assignments ra
                    JOIN courses c ON ra.course_id = c.id
                    JOIN teachers t ON ra.teacher_id = t.id
                    JOIN users u ON t.user_id = u.id
                    JOIN classrooms cr ON ra.classroom_id = cr.id
                    WHERE ra.batch_id = {$batch['id']}
                      AND ra.day = '$day'
                      AND ra.timeslot_id = {$slot['id']}
                      AND ra.generation_id = $gen_id
                ");
                while($row = $res->fetch_assoc()):
                ?>
                <div><strong><?= $row['code'] ?></strong><br><?= $row['username'] ?><br><small><?= $row['room_name'] ?></small></div>
                <?php endwhile; ?>
            </td>
            <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endwhile; ?>
    </body>
    </html>
    <?php exit;
}

$conn = getDBConnection();

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
    $room_name = $_POST['room_name'];
    $capacity = (int)$_POST['capacity'];
    $dept_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
    $projector = 1; $ac = 1;
    $type = $_POST['type'];
    if (preg_match('/[Ll]$/', $room_name)) $type = 'lab';
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

// Batch-Course Assignments (mandatory teacher)
if (isset($_POST['assign_batch_course'])) {
    $batch_id = (int)$_POST['batch_id'];
    $course_ids = $_POST['course_ids'] ?? [];
    $teacher_ids = $_POST['teacher_ids'] ?? [];
    for ($i = 0; $i < count($course_ids); $i++) {
        $course_id = (int)$course_ids[$i];
        $teacher_id = (int)$teacher_ids[$i];
        if ($teacher_id > 0) {
            $stmt = $conn->prepare("INSERT INTO batch_courses (batch_id, course_id, teacher_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE teacher_id = VALUES(teacher_id)");
            $stmt->bind_param("iii", $batch_id, $course_id, $teacher_id);
            $stmt->execute();
            $stmt->close();
        } else {
            $error = "Teacher selection is mandatory for each course.";
        }
    }
    if (empty($error)) $msg = "Assigned courses to batch with teachers.";
}
if (isset($_GET['remove_bc'])) { $conn->query("DELETE FROM batch_courses WHERE id=".(int)$_GET['remove_bc']); $msg = "Removed."; }

// Room Slot Restrictions
if (isset($_POST['save_room_slot'])) {
    $room_id = (int)$_POST['room_id'];
    $day = $_POST['day'];
    $timeslot_id = (int)$_POST['timeslot_id'];
    $dept_id = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;
    $action = $_POST['room_slot_action'];
    if ($action == 'create') {
        $stmt = $conn->prepare("INSERT IGNORE INTO room_slot_restrictions (room_id, day, timeslot_id, department_id, is_blocked) VALUES (?, ?, ?, ?, 1)");
        $stmt->bind_param("isii", $room_id, $day, $timeslot_id, $dept_id);
        if ($stmt->execute()) $msg = "Restriction added.";
        else $error = $stmt->error;
        $stmt->close();
    } elseif ($action == 'update') {
        $id = (int)$_POST['restriction_id'];
        $stmt = $conn->prepare("UPDATE room_slot_restrictions SET room_id=?, day=?, timeslot_id=?, department_id=? WHERE id=?");
        $stmt->bind_param("isiii", $room_id, $day, $timeslot_id, $dept_id, $id);
        if ($stmt->execute()) $msg = "Restriction updated.";
        else $error = $stmt->error;
        $stmt->close();
    }
}
if (isset($_GET['del_restriction'])) {
    $id = (int)$_GET['del_restriction'];
    $conn->query("DELETE FROM room_slot_restrictions WHERE id=$id");
    $msg = "Deleted.";
}

// Delete Generation
if (isset($_POST['delete_generation'])) {
    $gen_id = (int)$_POST['generation_id'];
    $conn->query("DELETE FROM routine_assignments WHERE generation_id = $gen_id");
    $msg = "Generation $gen_id and all its routine assignments have been deleted.";
}

// Manual Routine Edit: Add/Update/Delete
if (isset($_POST['manual_routine_action'])) {
    $action = $_POST['manual_routine_action'];
    if ($action == 'add') {
        $batch_id = (int)$_POST['batch_id'];
        $course_id = (int)$_POST['course_id'];
        $teacher_id = (int)$_POST['teacher_id'];
        $classroom_id = (int)$_POST['classroom_id'];
        $day = $_POST['day'];
        $timeslot_id = (int)$_POST['timeslot_id'];
        $session_type = $_POST['session_type'];
        $session_number = (int)$_POST['session_number'];
        $gen_id = (int)$_POST['generation_id'];
        $stmt = $conn->prepare("INSERT INTO routine_assignments (generation_id, batch_id, course_id, teacher_id, classroom_id, timeslot_id, day, session_type, session_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiiiisssi", $gen_id, $batch_id, $course_id, $teacher_id, $classroom_id, $timeslot_id, $day, $session_type, $session_number);
        if ($stmt->execute()) $msg = "Routine entry added.";
        else $error = $stmt->error;
        $stmt->close();
    } elseif ($action == 'update') {
        $id = (int)$_POST['assignment_id'];
        $batch_id = (int)$_POST['batch_id'];
        $course_id = (int)$_POST['course_id'];
        $teacher_id = (int)$_POST['teacher_id'];
        $classroom_id = (int)$_POST['classroom_id'];
        $day = $_POST['day'];
        $timeslot_id = (int)$_POST['timeslot_id'];
        $session_type = $_POST['session_type'];
        $session_number = (int)$_POST['session_number'];
        $stmt = $conn->prepare("UPDATE routine_assignments SET batch_id=?, course_id=?, teacher_id=?, classroom_id=?, timeslot_id=?, day=?, session_type=?, session_number=? WHERE id=?");
        $stmt->bind_param("iiiiissii", $batch_id, $course_id, $teacher_id, $classroom_id, $timeslot_id, $day, $session_type, $session_number, $id);
        if ($stmt->execute()) $msg = "Routine entry updated.";
        else $error = $stmt->error;
        $stmt->close();
    } elseif ($action == 'delete') {
        $id = (int)$_POST['assignment_id'];
        $conn->query("DELETE FROM routine_assignments WHERE id=$id");
        $msg = "Routine entry deleted.";
    }
}

// -------------------- AJAX ENDPOINTS --------------------
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $ajax = $_GET['ajax'];
    
    // Get courses for a batch
    if ($ajax == 'get_courses') {
        $batch_id = (int)$_GET['batch_id'];
        $courses = [];
        $res = $conn->query("
            SELECT c.id, c.code, c.title, c.weekly_classes, t.id as teacher_id, u.username as teacher_name
            FROM batch_courses bc
            JOIN courses c ON bc.course_id = c.id
            JOIN teachers t ON bc.teacher_id = t.id
            JOIN users u ON t.user_id = u.id
            WHERE bc.batch_id = $batch_id
            ORDER BY c.code
        ");
        while($row = $res->fetch_assoc()) $courses[] = $row;
        echo json_encode(['courses' => $courses]);
        exit;
    }
    
    // Get timeslots for a category and day (respecting batch type)
    if ($ajax == 'get_timeslots') {
        $category = $_GET['category']; // 'day' or 'evening'
        $day = $_GET['day'];
        $batch_type = $_GET['batch_type'];
        $sql = "SELECT id, start_time, end_time FROM timeslots WHERE category = '$category' ORDER BY start_time";
        $res = $conn->query($sql);
        $timeslots = [];
        while($row = $res->fetch_assoc()) {
            if ($batch_type == 'Evening') {
                if ($day == 'Friday') {
                    if ($row['id'] == 14) continue; // exclude 3h slot on Friday
                } else {
                    if ($row['id'] != 14) continue; // only 3h slot on Sat-Thu
                }
            }
            $timeslots[] = [
                'id' => $row['id'],
                'start_time_formatted' => date('h:i A', strtotime($row['start_time'])),
                'end_time_formatted' => date('h:i A', strtotime($row['end_time']))
            ];
        }
        echo json_encode(['timeslots' => $timeslots]);
        exit;
    }
    
    // Get free rooms for a given (day, timeslot, batch, course)
    if ($ajax == 'free_rooms') {
        $batch_id = (int)$_GET['batch_id'];
        $course_id = (int)$_GET['course_id'];
        $day = $_GET['day'];
        $timeslot_id = (int)$_GET['timeslot_id'];
        $generation_id = (int)$_GET['generation_id'];
        
        $batch = $conn->query("SELECT type, off_day1, off_day2, size FROM batches WHERE id = $batch_id")->fetch_assoc();
        if (!$batch) exit(json_encode(['error' => 'Invalid batch']));
        $course = $conn->query("SELECT weekly_classes FROM courses WHERE id = $course_id")->fetch_assoc();
        $session_type = ($course['weekly_classes'] == 1) ? 'lab' : 'theory';
        
        // Rooms not restricted for CSE (dept_id=1) on this day & timeslot
        $restricted = [];
        $restrictRes = $conn->query("SELECT room_id FROM room_slot_restrictions WHERE is_blocked = 1 AND department_id = 1 AND day = '$day' AND timeslot_id = $timeslot_id");
        while($row = $restrictRes->fetch_assoc()) $restricted[] = $row['room_id'];
        $restricted_in = empty($restricted) ? '' : 'AND id NOT IN (' . implode(',', $restricted) . ')';
        
        // Rooms already allocated in this generation
        $allocated = [];
        $allocRes = $conn->query("SELECT classroom_id FROM routine_assignments WHERE generation_id = $generation_id AND day = '$day' AND timeslot_id = $timeslot_id");
        while($row = $allocRes->fetch_assoc()) $allocated[] = $row['classroom_id'];
        $allocated_in = empty($allocated) ? '' : 'AND id NOT IN (' . implode(',', $allocated) . ')';
        
        $room_type_condition = ($session_type == 'lab') ? "type = 'lab'" : "type IN ('theory','both')";
        $rooms = $conn->query("
            SELECT id, room_name, capacity 
            FROM classrooms 
            WHERE capacity >= {$batch['size']}
              AND $room_type_condition
              $restricted_in
              $allocated_in
            ORDER BY room_name
        ");
        $free_rooms = [];
        while($r = $rooms->fetch_assoc()) $free_rooms[] = $r;
        echo json_encode(['free_rooms' => $free_rooms]);
        exit;
    }
    
    // Get latest generation ID
    if ($ajax == 'get_latest_gen') {
        $gen = $conn->query("SELECT MAX(generation_id) as gen FROM routine_assignments")->fetch_assoc()['gen'];
        echo json_encode(['gen' => $gen ?: 1]);
        exit;
    }
}

// -------------------- CSV IMPORTS --------------------
$import_msg = ''; $import_error = '';
foreach (['depts','batches','courses','teachers','classrooms','timeslots','batch_courses','room_slots'] as $type) {
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
                        $room=trim($r[0]); $cap=(int)$r[1]; $type=trim($r[2]); $dept_code=isset($r[3])&&trim($r[3])?trim($r[3]):null; $proj=1; $ac=1;
                        if (preg_match('/[Ll]$/', $room)) $type = 'lab';
                        $dept_id=null; if($dept_code){ $d=$conn->query("SELECT id FROM departments WHERE code='$dept_code'")->fetch_assoc(); if($d) $dept_id=$d['id']; }
                        $stmt=$conn->prepare("INSERT INTO classrooms (room_name,capacity,department_id,has_projector,has_ac,type) VALUES (?,?,?,?,?,?)"); $stmt->bind_param("siiiss",$room,$cap,$dept_id,$proj,$ac,$type); if($stmt->execute()) $success++; else $failed++; $stmt->close(); }
                    break;
                case 'timeslots':
                    foreach ($rows as $r) { if (count($r)<2) { $failed++; continue; }
                        $start=trim($r[0]); $end=trim($r[1]); $stmt=$conn->prepare("INSERT INTO timeslots (start_time,end_time) VALUES (?,?)"); $stmt->bind_param("ss",$start,$end); if($stmt->execute()) $success++; else $failed++; $stmt->close(); }
                    break;
                case 'batch_courses':
                    foreach ($rows as $r) { if (count($r)<6) { $failed++; continue; }
                        $dept_code=trim($r[0]); $sem=trim($r[1]); $sec=trim($r[2]); $type=trim($r[3]); $course_code=trim($r[4]); $teacher_id_str=trim($r[5]);
                        $batch=$conn->query("SELECT b.id FROM batches b JOIN departments d ON b.department_id=d.id WHERE d.code='$dept_code' AND b.semester='$sem' AND b.section='$sec' AND b.type='$type'")->fetch_assoc();
                        $course=$conn->query("SELECT id FROM courses WHERE code='$course_code'")->fetch_assoc();
                        $teacher=$conn->query("SELECT id FROM teachers WHERE teacher_id='$teacher_id_str'")->fetch_assoc();
                        if($batch && $course && $teacher){
                            $stmt=$conn->prepare("INSERT INTO batch_courses (batch_id,course_id,teacher_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE teacher_id = VALUES(teacher_id)");
                            $stmt->bind_param("iii", $batch['id'], $course['id'], $teacher['id']);
                            if($stmt->execute()) $success++; else $failed++;
                            $stmt->close();
                        } else $failed++; }
                    break;
                case 'room_slots':
                    foreach ($rows as $r) { if (count($r)<4) { $failed++; continue; }
                        $day = trim($r[0]); $start_time = trim($r[1]); $end_time = trim($r[2]); $room_name = trim($r[3]); $dept_code = isset($r[4]) ? trim($r[4]) : null;
                        $ts = $conn->query("SELECT id FROM timeslots WHERE start_time = '$start_time' AND end_time = '$end_time'")->fetch_assoc();
                        if (!$ts) { $failed++; continue; }
                        $timeslot_id = $ts['id'];
                        $room = $conn->query("SELECT id FROM classrooms WHERE room_name = '$room_name'")->fetch_assoc();
                        if (!$room) { $failed++; continue; }
                        $room_id = $room['id'];
                        $dept_id = null;
                        if ($dept_code) {
                            $dept = $conn->query("SELECT id FROM departments WHERE code = '$dept_code'")->fetch_assoc();
                            if ($dept) $dept_id = $dept['id'];
                        }
                        $stmt = $conn->prepare("INSERT IGNORE INTO room_slot_restrictions (room_id, day, timeslot_id, department_id, is_blocked) VALUES (?, ?, ?, ?, 1)");
                        $stmt->bind_param("isii", $room_id, $day, $timeslot_id, $dept_id);
                        if ($stmt->execute()) $success++; else $failed++;
                        $stmt->close();
                    }
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
$batch_courses = $conn->query("
    SELECT bc.id, b.semester, b.section, b.type, c.code, c.title, d.name as dept_name,
           t.teacher_id as assigned_teacher_id, u.username as teacher_name
    FROM batch_courses bc
    JOIN batches b ON bc.batch_id = b.id
    JOIN courses c ON bc.course_id = c.id
    JOIN departments d ON b.department_id = d.id
    JOIN teachers t ON bc.teacher_id = t.id
    JOIN users u ON t.user_id = u.id
");
$users = $conn->query("SELECT id, username, role FROM users WHERE role IN ('admin','teacher') ORDER BY username");
$days = ['Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

// Stats
$totalDepts = $depts->num_rows;
$totalBatches = $batches->num_rows;
$totalCourses = $courses->num_rows;
$totalTeachers = $teachers->num_rows;
$totalTimeslots = $timeslots->num_rows;
$totalClassrooms = $classrooms->num_rows;
$totalRoutineAssignments = $conn->query("SELECT COUNT(*) FROM routine_assignments")->fetch_row()[0];
$lastGenId = $conn->query("SELECT MAX(generation_id) as last FROM routine_assignments")->fetch_assoc()['last'];
$generations = $conn->query("SELECT DISTINCT generation_id FROM routine_assignments ORDER BY generation_id DESC");
$genCount = $generations->num_rows;

$deptCourseCounts = []; $deptNames = [];
$deptCoursesRes = $conn->query("SELECT d.name, COUNT(c.id) as count FROM departments d LEFT JOIN courses c ON d.id = c.department_id GROUP BY d.id");
while($row = $deptCoursesRes->fetch_assoc()) { $deptNames[] = $row['name']; $deptCourseCounts[] = $row['count']; }
$dayBatches = $conn->query("SELECT COUNT(*) FROM batches WHERE type='Day'")->fetch_row()[0];
$eveningBatches = $conn->query("SELECT COUNT(*) FROM batches WHERE type='Evening'")->fetch_row()[0];

// Conflict data for dashboard
$teacherOverload = [];
$teacherOverloadRes = $conn->query("
    SELECT t.id, t.teacher_id, u.username, ra.day, COUNT(*) as cnt, t.max_classes_per_day
    FROM routine_assignments ra
    JOIN teachers t ON ra.teacher_id = t.id
    JOIN users u ON t.user_id = u.id
    GROUP BY ra.teacher_id, ra.day
    HAVING cnt > t.max_classes_per_day
");
while($row = $teacherOverloadRes->fetch_assoc()) $teacherOverload[] = $row;

$roomDoubleBookings = [];
$roomDoubleRes = $conn->query("
    SELECT ra.day, ra.timeslot_id, ra.classroom_id, cr.room_name, COUNT(*) as cnt
    FROM routine_assignments ra
    JOIN classrooms cr ON ra.classroom_id = cr.id
    GROUP BY ra.day, ra.timeslot_id, ra.classroom_id
    HAVING cnt > 1
");
while($row = $roomDoubleRes->fetch_assoc()) $roomDoubleBookings[] = $row;

$teacherClashes = [];
$teacherClashRes = $conn->query("
    SELECT ra.day, ra.timeslot_id, ra.teacher_id, u.username, COUNT(*) as cnt
    FROM routine_assignments ra
    JOIN teachers t ON ra.teacher_id = t.id
    JOIN users u ON t.user_id = u.id
    GROUP BY ra.day, ra.timeslot_id, ra.teacher_id
    HAVING cnt > 1
");
while($row = $teacherClashRes->fetch_assoc()) $teacherClashes[] = $row;

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
        .conflict-list { max-height: 200px; overflow-y: auto; }
        .status-scheduled { color: green; font-weight: bold; }
        .status-missing { color: red; font-weight: bold; }
        .room-free { background: #d4edda; }
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
            <li class="<?= ($section=='timeslots')?'active':'' ?>"><a href="?section=timeslots"><i class="fas fa-hourglass-half"></i> Time Slots</a></li>
            <li class="<?= ($section=='classrooms')?'active':'' ?>"><a href="?section=classrooms"><i class="fas fa-door-open"></i> Classrooms</a></li>
            <li class="<?= ($section=='room_slots')?'active':'' ?>"><a href="?section=room_slots"><i class="fas fa-calendar-times"></i> Room Slot Restrictions</a></li>
            <li class="<?= ($section=='assignments')?'active':'' ?>"><a href="?section=assignments"><i class="fas fa-link"></i> Batch → Course</a></li>
            <li class="<?= ($section=='routine_status')?'active':'' ?>"><a href="?section=routine_status"><i class="fas fa-check-circle"></i> Routine Status</a></li>
            <li class="<?= ($section=='teacher_routine')?'active':'' ?>"><a href="?section=teacher_routine"><i class="fas fa-chalkboard-teacher"></i> Teacher Routine</a></li>
            <li class="<?= ($section=='room_allocation')?'active':'' ?>"><a href="?section=room_allocation"><i class="fas fa-door-open"></i> Room Allocation</a></li>
            <li class="<?= ($section=='manual_edit')?'active':'' ?>"><a href="?section=manual_edit"><i class="fas fa-edit"></i> Manual Routine Edit</a></li>
            <li class="<?= ($section=='csv_import')?'active':'' ?>"><a href="?section=csv_import"><i class="fas fa-upload"></i> CSV Import</a></li>
            <li class="<?= ($section=='routine')?'active':'' ?>"><a href="?section=routine"><i class="fas fa-calendar-week"></i> View Routine</a></li>
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
            <div class="stat-card"><h4>Generations</h4><h2><?= $genCount ?></h2></div>
        </div>
        <div class="row">
            <div class="col-md-6"><div class="section-card"><div class="section-title"><i class="fas fa-chart-bar"></i> Courses per Department</div><canvas id="deptCoursesChart" style="max-height:300px;"></canvas></div></div>
            <div class="col-md-6"><div class="section-card"><div class="section-title"><i class="fas fa-chart-pie"></i> Batch Type Distribution</div><canvas id="batchTypeChart" style="max-height:300px;"></canvas></div></div>
        </div>
        
        <!-- Delete Generation -->
        <div class="section-card">
            <div class="section-title"><i class="fas fa-trash-alt"></i> Delete Routine Generation</div>
            <form method="POST" class="row g-2">
                <div class="col-auto">
                    <select name="generation_id" class="form-select" required>
                        <option value="">Select Generation ID</option>
                        <?php $genRes = $conn->query("SELECT DISTINCT generation_id FROM routine_assignments ORDER BY generation_id DESC"); while($g = $genRes->fetch_assoc()): ?>
                        <option value="<?= $g['generation_id'] ?>">Generation <?= $g['generation_id'] ?> (<?= $conn->query("SELECT COUNT(*) FROM routine_assignments WHERE generation_id = ".$g['generation_id'])->fetch_row()[0] ?> classes)</option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" name="delete_generation" class="btn btn-danger" onclick="return confirm('WARNING: This will delete ALL routine assignments for this generation. Continue?')">Delete Generation</button>
                </div>
            </form>
        </div>

        <!-- Conflicts Summary -->
        <div class="section-card">
            <div class="section-title"><i class="fas fa-exclamation-triangle"></i> Current Conflicts (Latest Generation)</div>
            <div class="row">
                <div class="col-md-4">
                    <h5>Teacher Overload</h5>
                    <?php if(count($teacherOverload) > 0): ?>
                    <ul class="conflict-list">
                        <?php foreach($teacherOverload as $c): ?>
                        <li><?= htmlspecialchars($c['username']) ?> (<?= $c['teacher_id'] ?>) has <?= $c['cnt'] ?> classes on <?= $c['day'] ?> (max <?= $c['max_classes_per_day'] ?>)</li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: echo "<p class='text-success'>No teacher overload.</p>"; endif; ?>
                </div>
                <div class="col-md-4">
                    <h5>Room Double‑Booking</h5>
                    <?php if(count($roomDoubleBookings) > 0): ?>
                    <ul class="conflict-list">
                        <?php foreach($roomDoubleBookings as $c): ?>
                        <li><?= $c['room_name'] ?> on <?= $c['day'] ?> (slot <?= $c['timeslot_id'] ?>) has <?= $c['cnt'] ?> classes</li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: echo "<p class='text-success'>No room conflicts.</p>"; endif; ?>
                </div>
                <div class="col-md-4">
                    <h5>Teacher Clashes</h5>
                    <?php if(count($teacherClashes) > 0): ?>
                    <ul class="conflict-list">
                        <?php foreach($teacherClashes as $c): ?>
                        <li><?= htmlspecialchars($c['username']) ?> on <?= $c['day'] ?> (slot <?= $c['timeslot_id'] ?>) has <?= $c['cnt'] ?> classes</li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: echo "<p class='text-success'>No teacher clashes.</p>"; endif; ?>
                </div>
            </div>
        </div>

        <div class="section-card"><div class="section-title"><i class="fas fa-rocket"></i> Quick Actions</div>
        <div><a href="?section=routine" class="btn">View Routine</a> <a href="modules/routine/generate.php" class="btn btn-warning">Generate Routine</a> <a href="modules/routine/view.php" class="btn btn-warning">View</a><a href="?section=assignments" class="btn btn-outline">Assign Batch Courses</a> <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal">+ New User</button></div></div>
        <script> new Chart(document.getElementById('deptCoursesChart'),{type:'bar',data:{labels:<?= json_encode($deptNames) ?>,datasets:[{label:'Number of Courses',data:<?= json_encode($deptCourseCounts) ?>,backgroundColor:'#0071e3'}]}}); new Chart(document.getElementById('batchTypeChart'),{type:'pie',data:{labels:['Day Batches','Evening Batches'],datasets:[{data:[<?= $dayBatches ?>,<?= $eveningBatches ?>],backgroundColor:['#0071e3','#ffc107']}]}}); </script>
        <?php endif; ?>

        <!-- DEPARTMENTS -->
        <?php if($section == 'departments'): ?>
        <div class="section-card"><div class="d-flex justify-content-between"><h3>Departments</h3><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#deptModal" onclick="clearDeptForm()">+ Add</button></div>
        <table class="table"><thead><tr><th>ID</th><th>Name</th><th>Code</th><th>Actions</th></tr></thead><tbody>
        <?php $depts->data_seek(0); while($d=$depts->fetch_assoc()): ?>
        <tr><td><?= $d['id'] ?></td><td><?= htmlspecialchars($d['name']) ?></td><td><?= $d['code'] ?></td>
        <td><button class="btn btn-sm btn-warning" onclick="editDept(<?= $d['id'] ?>,'<?= addslashes($d['name']) ?>','<?= $d['code'] ?>')">Edit</button> <a href="?section=departments&del_dept=<?= $d['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete?')">Del</a></td>
        <?php endwhile; ?></tbody></table></div>
        <?php endif; ?>

        <!-- BATCHES -->
        <?php if($section == 'batches'): ?>
        <div class="section-card"><div class="d-flex justify-content-between"><h3>Batches</h3><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#batchModal" onclick="clearBatchForm()">+ Add</button></div>
        <table class="table"><thead><tr><th>Dept</th><th>Type</th><th>Sem</th><th>Sec</th><th>Size</th><th>Off Days</th><th>Actions</th></tr></thead><tbody>
        <?php $batches->data_seek(0); while($b=$batches->fetch_assoc()): $off_days = "Friday"; if (!empty($b['off_day2'])) $off_days .= ", " . $b['off_day2']; ?>
        <tr><td><?= $b['dept_name'] ?></td><td><?= $b['type'] ?></td><td><?= $b['semester'] ?></td><td><?= $b['section'] ?></td><td><?= $b['size'] ?></td><td><?= $off_days ?></td>
        <td><button class="btn btn-sm btn-warning" onclick="editBatch(<?= $b['id'] ?>,<?= $b['department_id'] ?>,'<?= $b['type'] ?>','<?= $b['semester'] ?>','<?= $b['section'] ?>',<?= $b['size'] ?>,'<?= $b['off_day2'] ?>')">Edit</button> <a href="?section=batches&del_batch=<?= $b['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete?')">Del</a></td>
        <?php endwhile; ?></tbody></table></div>
        <?php endif; ?>

        <!-- COURSES -->
        <?php if($section == 'courses'): ?>
        <div class="section-card"><div class="d-flex justify-content-between"><h3>Courses</h3><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#courseModal" onclick="clearCourseForm()">+ Add</button></div>
        <table class="table"><thead><tr><th>Code</th><th>Title</th><th>Credit</th><th>Weekly</th><th>Dept</th><th>Actions</th></tr></thead><tbody>
        <?php $courses->data_seek(0); while($c=$courses->fetch_assoc()): ?>
        <tr><td><?= $c['code'] ?></td><td><?= htmlspecialchars($c['title']) ?></td><td><?= $c['credit'] ?></td><td><?= $c['weekly_classes'] ?></td><td><?= $c['dept_name'] ?></td>
        <td><button class="btn btn-sm btn-warning" onclick="editCourse(<?= $c['id'] ?>,<?= $c['department_id'] ?>,'<?= addslashes($c['title']) ?>','<?= $c['code'] ?>',<?= $c['credit'] ?>,<?= $c['weekly_classes'] ?>)">Edit</button> <a href="?section=courses&del_course=<?= $c['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete?')">Del</a></td>
        <?php endwhile; ?></tbody></table></div>
        <?php endif; ?>

        <!-- TEACHERS -->
        <?php if($section == 'teachers'): ?>
        <div class="section-card"><div class="d-flex justify-content-between"><h3>Teachers</h3><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#teacherModal" onclick="clearTeacherForm()">+ Add Teacher</button></div>
        <table class="table"><thead><tr><th>Teacher ID</th><th>Name</th><th>Dept</th><th>Designation</th><th>Max/Day</th><th>Actions</th></tr></thead><tbody>
        <?php $teachers->data_seek(0); while($t=$teachers->fetch_assoc()): ?>
        <tr><td><?= $t['teacher_id'] ?></td><td><?= htmlspecialchars($t['username']) ?></td><td><?= $t['department'] ?></td><td><?= $t['designation'] ?></td><td><?= $t['max_classes_per_day'] ?></td>
        <td><button class="btn btn-sm btn-warning" onclick="editTeacher(<?= $t['id'] ?>,<?= $t['user_id'] ?>,'<?= $t['teacher_id'] ?>','<?= $t['department'] ?>','<?= $t['designation'] ?>',<?= $t['max_classes_per_day'] ?>,'<?= $t['available_days'] ?>')">Edit</button> <a href="?section=teachers&del_teacher=<?= $t['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete?')">Del</a></td>
        <?php endwhile; ?></tbody></tr></div>
        <?php endif; ?>

        <!-- TIME SLOTS -->
        <?php if($section == 'timeslots'): ?>
        <div class="section-card"><div class="d-flex justify-content-between"><h3>Time Slots</h3><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#timeslotModal" onclick="clearTimeslotForm()">+ Add</button></div>
        <table class="table"><thead><tr><th>ID</th><th>Start</th><th>End</th><th>Actions</th></tr></thead><tbody>
        <?php $timeslots->data_seek(0); while($ts=$timeslots->fetch_assoc()): ?>
        <tr><td><?= $ts['id'] ?></td><td><?= $ts['start_time'] ?></td><td><?= $ts['end_time'] ?></td>
        <td><button class="btn btn-sm btn-warning" onclick="editTimeslot(<?= $ts['id'] ?>,'<?= $ts['start_time'] ?>','<?= $ts['end_time'] ?>')">Edit</button> <a href="?section=timeslots&del_timeslot=<?= $ts['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete?')">Del</a></td>
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
        <?php endwhile; ?>
        </tbody></table></div>
        <?php endif; ?>

        <!-- ROOM SLOT RESTRICTIONS -->
        <?php if($section == 'room_slots'): ?>
        <div class="section-card">
            <div class="d-flex justify-content-between align-items-center mb-3"><h3>Room Slot Restrictions</h3><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#roomSlotModal" onclick="clearRoomSlotForm()">+ Add Restriction</button></div>
            <form method="GET" class="row g-2 mb-3">
                <input type="hidden" name="section" value="room_slots">
                <div class="col-auto"><select name="filter_room" class="form-select"><option value="">All Rooms</option><?php $allRooms = $conn->query("SELECT id, room_name FROM classrooms ORDER BY room_name"); while($r=$allRooms->fetch_assoc()): ?><option value="<?= $r['id'] ?>" <?= (isset($_GET['filter_room']) && $_GET['filter_room']==$r['id'])?'selected':'' ?>><?= $r['room_name'] ?></option><?php endwhile; ?></select></div>
                <div class="col-auto"><select name="filter_day" class="form-select"><option value="">All Days</option><?php foreach(['Saturday','Sunday','Monday','Tuesday','Wednesday','Thursday'] as $d): ?><option <?= (isset($_GET['filter_day']) && $_GET['filter_day']==$d)?'selected':'' ?>><?= $d ?></option><?php endforeach; ?></select></div>
                <div class="col-auto"><button type="submit" class="btn btn-secondary">Filter</button></div>
            </form>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th>Room</th><th>Day</th><th>Timeslot</th><th>Department</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php
                    $where = [];
                    if (isset($_GET['filter_room']) && $_GET['filter_room']) $where[] = "rs.room_id = ".(int)$_GET['filter_room'];
                    if (isset($_GET['filter_day']) && $_GET['filter_day']) $where[] = "rs.day = '".$conn->real_escape_string($_GET['filter_day'])."'";
                    $whereClause = $where ? "WHERE " . implode(" AND ", $where) : "";
                    $restrictions = $conn->query("
                        SELECT rs.*, cr.room_name, ts.start_time, ts.end_time, d.name as dept_name
                        FROM room_slot_restrictions rs
                        JOIN classrooms cr ON rs.room_id = cr.id
                        JOIN timeslots ts ON rs.timeslot_id = ts.id
                        LEFT JOIN departments d ON rs.department_id = d.id
                        $whereClause
                        ORDER BY cr.room_name, FIELD(rs.day,'Saturday','Sunday','Monday','Tuesday','Wednesday','Thursday'), ts.start_time
                    ");
                    while($rs = $restrictions->fetch_assoc()):
                    ?>
                    <tr><td><?= $rs['room_name'] ?></td><td><?= $rs['day'] ?></td><td><?= date('h:i A', strtotime($rs['start_time'])) ?> - <?= date('h:i A', strtotime($rs['end_time'])) ?></td><td><?= $rs['dept_name'] ?? 'All Departments' ?></td><td><a href="?section=room_slots&del_restriction=<?= $rs['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this restriction?')">Remove</a></td></tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="modal fade" id="roomSlotModal" tabindex="-1"><div class="modal-dialog"><form method="POST" class="modal-content"><div class="modal-header"><h5>Room Slot Restriction</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="room_slot_action" id="room_slot_action" value="create"><input type="hidden" name="restriction_id" id="restriction_id"><select name="room_id" id="restriction_room" class="form-select mb-2" required><option value="">Select Room</option><?php $rooms = $conn->query("SELECT id, room_name FROM classrooms ORDER BY room_name"); while($r=$rooms->fetch_assoc()): ?><option value="<?= $r['id'] ?>"><?= $r['room_name'] ?></option><?php endwhile; ?></select><select name="day" id="restriction_day" class="form-select mb-2" required><option value="">Select Day</option><option>Saturday</option><option>Sunday</option><option>Monday</option><option>Tuesday</option><option>Wednesday</option><option>Thursday</option></select><select name="timeslot_id" id="restriction_timeslot" class="form-select mb-2" required><option value="">Select Timeslot</option><?php $slots = $conn->query("SELECT id, start_time, end_time FROM timeslots ORDER BY start_time"); while($s=$slots->fetch_assoc()): ?><option value="<?= $s['id'] ?>"><?= date('h:i A', strtotime($s['start_time'])) ?> - <?= date('h:i A', strtotime($s['end_time'])) ?></option><?php endwhile; ?></select><select name="department_id" id="restriction_dept" class="form-select mb-2"><option value="">All Departments (Global Block)</option><?php $depts->data_seek(0); while($d=$depts->fetch_assoc()): ?><option value="<?= $d['id'] ?>"><?= $d['name'] ?></option><?php endwhile; ?></select></div><div class="modal-footer"><button type="submit" name="save_room_slot" class="btn btn-primary">Save</button></div></form></div></div>
        <script>function clearRoomSlotForm() { document.getElementById('room_slot_action').value = 'create'; document.getElementById('restriction_id').value = ''; document.getElementById('restriction_room').value = ''; document.getElementById('restriction_day').value = ''; document.getElementById('restriction_timeslot').value = ''; document.getElementById('restriction_dept').value = ''; }</script>
        <?php endif; ?>

        <!-- ASSIGNMENTS -->
        <?php if($section == 'assignments'): ?>
        <div class="section-card">
            <h4>Batch → Courses (teacher mandatory)</h4>
            <button class="btn btn-sm btn-primary mb-2" data-bs-toggle="modal" data-bs-target="#assignBatchCourseModal">+ Assign</button>
            <table class="table table-sm">
                <thead><tr><th>Batch</th><th>Course</th><th>Assigned Teacher</th><th></th></tr></thead>
                <tbody>
                <?php $batch_courses->data_seek(0); while($bc=$batch_courses->fetch_assoc()): ?>
                <tr><td><?= $bc['dept_name']." ".$bc['semester'].$bc['section']." (".$bc['type'].")" ?></td><td><?= $bc['code']." - ".$bc['title'] ?></td><td><?= $bc['teacher_name'] ?></td><td><a href="?section=assignments&remove_bc=<?= $bc['id'] ?>" class="btn btn-sm btn-danger">Remove</a></td></tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- ROUTINE STATUS -->
        <?php if($section == 'routine_status'): ?>
        <div class="section-card">
            <h3>Course Assignment Status (Latest Generation)</h3>
            <?php
            $gen_id = $conn->query("SELECT MAX(generation_id) as gen FROM routine_assignments")->fetch_assoc()['gen'];
            if(!$gen_id) echo "<p>No routine generated yet.</p>";
            else {
                $batchesAll = $conn->query("SELECT b.*, d.name as dept_name FROM batches b JOIN departments d ON b.department_id = d.id ORDER BY d.name, b.semester");
                while($batch = $batchesAll->fetch_assoc()):
                    $batch_courses = $conn->query("SELECT c.id, c.code, c.title, bc.teacher_id FROM batch_courses bc JOIN courses c ON bc.course_id = c.id WHERE bc.batch_id = {$batch['id']}");
                    if($batch_courses->num_rows == 0) continue;
                    echo "<h4>{$batch['dept_name']} - Sem {$batch['semester']}{$batch['section']} ({$batch['type']})</h4>";
                    echo "<table class='table table-sm'><thead><tr><th>Course</th><th>Scheduled?</th><th>Teacher</th></tr></thead><tbody>";
                    while($crs = $batch_courses->fetch_assoc()):
                        $scheduled = $conn->query("SELECT COUNT(*) as cnt FROM routine_assignments WHERE batch_id={$batch['id']} AND course_id={$crs['id']} AND generation_id=$gen_id")->fetch_assoc()['cnt'];
                        $status = ($scheduled > 0) ? "<span class='status-scheduled'>✓ Scheduled</span>" : "<span class='status-missing'>✗ Not scheduled</span>";
                        $teacherName = $conn->query("SELECT u.username FROM teachers t JOIN users u ON t.user_id = u.id WHERE t.id = {$crs['teacher_id']}")->fetch_assoc()['username'] ?? 'Not assigned';
                        echo "<tr><td>{$crs['code']} - {$crs['title']}</td><td>$status</td><td>$teacherName</td></tr>";
                    endwhile;
                    echo "</tbody></table><hr>";
                endwhile;
            }
            ?>
        </div>
        <?php endif; ?>

        <!-- TEACHER ROUTINE -->
        <?php if($section == 'teacher_routine'): ?>
        <div class="section-card">
            <h3>Teacher Weekly Routine</h3>
            <form method="GET" class="mb-3">
                <input type="hidden" name="section" value="teacher_routine">
                <select name="teacher_id" class="form-select w-auto d-inline-block" onchange="this.form.submit()">
                    <option value="">-- Select Teacher --</option>
                    <?php $teachersAll = $conn->query("SELECT t.id, u.username FROM teachers t JOIN users u ON t.user_id = u.id ORDER BY u.username"); while($tch = $teachersAll->fetch_assoc()): ?>
                    <option value="<?= $tch['id'] ?>" <?= (isset($_GET['teacher_id']) && $_GET['teacher_id'] == $tch['id']) ? 'selected' : '' ?>><?= $tch['username'] ?></option>
                    <?php endwhile; ?>
                </select>
            </form>
            <?php if(isset($_GET['teacher_id']) && $_GET['teacher_id']): 
                $teacher_id = (int)$_GET['teacher_id'];
                $teacher = $conn->query("SELECT u.username FROM teachers t JOIN users u ON t.user_id = u.id WHERE t.id = $teacher_id")->fetch_assoc();
                $gen_id = $conn->query("SELECT MAX(generation_id) as gen FROM routine_assignments")->fetch_assoc()['gen'];
                if(!$gen_id) echo "<p>No routine generated.</p>";
                else {
                    $daysOfWeek = ['Saturday','Sunday','Monday','Tuesday','Wednesday','Thursday','Friday'];
                    $timeslots = $conn->query("SELECT * FROM timeslots ORDER BY start_time")->fetch_all(MYSQLI_ASSOC);
                    echo "<div class='table-responsive'><table class='table table-bordered'><thead><tr><th>Time</th>";
                    foreach($daysOfWeek as $d) echo "<th>$d</th>";
                    echo "</tr></thead><tbody>";
                    foreach($timeslots as $slot):
                        echo "<tr><td class='bg-light'>".date('h:i A', strtotime($slot['start_time']))." - ".date('h:i A', strtotime($slot['end_time']))."</td>";
                        foreach($daysOfWeek as $day):
                            echo "<td>";
                            $res = $conn->query("
                                SELECT c.code, cr.room_name, b.semester, b.section
                                FROM routine_assignments ra
                                JOIN courses c ON ra.course_id = c.id
                                JOIN classrooms cr ON ra.classroom_id = cr.id
                                JOIN batches b ON ra.batch_id = b.id
                                WHERE ra.teacher_id = $teacher_id AND ra.day = '$day' AND ra.timeslot_id = {$slot['id']} AND ra.generation_id = $gen_id
                            ");
                            while($row = $res->fetch_assoc()):
                                echo "<div><strong>{$row['code']}</strong><br><small>{$row['room_name']}<br>Batch: {$row['semester']}{$row['section']}</small></div>";
                            endwhile;
                            echo "</td>";
                        endforeach;
                        echo "</tr>";
                    endforeach;
                    echo "</tbody></table></div>";
                }
            endif; ?>
        </div>
        <?php endif; ?>

        <!-- ROOM ALLOCATION (respecting restrictions) -->
        <?php if($section == 'room_allocation'): ?>
        <div class="section-card">
            <h3>Room Availability for CSE Department (Respecting Restrictions)</h3>
            <p class="text-muted">Shows only rooms that are <strong>not restricted</strong> for CSE in each day/timeslot. <span class="text-success">Allocated</span> rooms show the course; <span class="text-info">Free</span> rooms are available.</p>
            <?php
            $gen_id = $conn->query("SELECT MAX(generation_id) as gen FROM routine_assignments")->fetch_assoc()['gen'];
            if(!$gen_id) {
                echo "<p>No routine generated yet.</p>";
            } else {
                $allRooms = $conn->query("SELECT id, room_name FROM classrooms ORDER BY room_name")->fetch_all(MYSQLI_ASSOC);
                $timeslots = $conn->query("SELECT * FROM timeslots ORDER BY start_time")->fetch_all(MYSQLI_ASSOC);
                $daysOfWeek = ['Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

                $assignments = [];
                $assignRes = $conn->query("
                    SELECT ra.day, ra.timeslot_id, ra.classroom_id, c.code, u.username, b.semester, b.section
                    FROM routine_assignments ra
                    JOIN courses c ON ra.course_id = c.id
                    JOIN teachers t ON ra.teacher_id = t.id
                    JOIN users u ON t.user_id = u.id
                    JOIN batches b ON ra.batch_id = b.id
                    WHERE ra.generation_id = $gen_id
                ");
                while($row = $assignRes->fetch_assoc()) {
                    $assignments[$row['day']][$row['timeslot_id']][$row['classroom_id']][] = $row['code'] . " (" . $row['username'] . "/" . $row['semester'].$row['section'] . ")";
                }

                $restricted = [];
                $restrictRes = $conn->query("
                    SELECT room_id, day, timeslot_id
                    FROM room_slot_restrictions
                    WHERE is_blocked = 1 AND department_id = 1
                ");
                while($row = $restrictRes->fetch_assoc()) {
                    $restricted[$row['room_id']][$row['day']][$row['timeslot_id']] = true;
                }

                echo "<div class='table-responsive'><table class='table table-bordered table-sm'>";
                echo "<thead class='table-dark'><tr><th>Time / Day</th>";
                foreach($daysOfWeek as $day) echo "<th>$day</th>";
                echo "</tr></thead><tbody>";
                foreach($timeslots as $slot) {
                    echo "<tr><td class='bg-light'>" . date('h:i A', strtotime($slot['start_time'])) . " - " . date('h:i A', strtotime($slot['end_time'])) . "</td>";
                    foreach($daysOfWeek as $day) {
                        $availableRooms = [];
                        foreach($allRooms as $room) {
                            if(!isset($restricted[$room['id']][$day][$slot['id']])) {
                                $availableRooms[$room['id']] = $room['room_name'];
                            }
                        }
                        $allocatedList = [];
                        $freeList = [];
                        foreach($availableRooms as $roomId => $roomName) {
                            if(isset($assignments[$day][$slot['id']][$roomId])) {
                                $courses = implode(", ", $assignments[$day][$slot['id']][$roomId]);
                                $allocatedList[] = "$roomName [$courses]";
                            } else {
                                $freeList[] = $roomName;
                            }
                        }
                        echo "<td style='vertical-align:top;'>";
                        if(!empty($allocatedList)) echo "<div><i class='fas fa-check-circle text-success'></i> <strong>Allocated:</strong> " . implode(", ", $allocatedList) . "</div>";
                        if(!empty($freeList)) echo "<div><i class='fas fa-door-open text-info'></i> <strong>Free:</strong> " . implode(", ", $freeList) . "</div>";
                        if(empty($allocatedList) && empty($freeList)) echo "— <span class='text-muted'>No rooms available (all restricted)</span>";
                        echo "</td>";
                    }
                    echo "</tr>";
                }
                echo "</tbody></table></div>";
            }
            ?>
        </div>
        <?php endif; ?>

        <!-- MANUAL ROUTINE EDIT (dynamic) -->
        <?php if($section == 'manual_edit'): ?>
        <div class="section-card">
            <h3>Manual Routine Edit (Add/Update/Delete)</h3>
            <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#manualRoutineModal" onclick="clearManualForm()">+ Add New Entry</button>
            <div class="table-responsive">
                <table class="table table-sm">
                    <thead><tr><th>ID</th><th>Gen</th><th>Batch</th><th>Course</th><th>Teacher</th><th>Room</th><th>Day</th><th>Timeslot</th><th>Type</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php
                    $manualAssignments = $conn->query("
                        SELECT ra.*, b.semester, b.section, c.code as course_code, u.username as teacher_name, cr.room_name, ts.start_time, ts.end_time
                        FROM routine_assignments ra
                        JOIN batches b ON ra.batch_id = b.id
                        JOIN courses c ON ra.course_id = c.id
                        JOIN teachers t ON ra.teacher_id = t.id
                        JOIN users u ON t.user_id = u.id
                        JOIN classrooms cr ON ra.classroom_id = cr.id
                        JOIN timeslots ts ON ra.timeslot_id = ts.id
                        ORDER BY ra.generation_id DESC, ra.id DESC LIMIT 200
                    ");
                    while($ra = $manualAssignments->fetch_assoc()):
                    ?>
                    <tr>
                        <td><?= $ra['id'] ?></td>
                        <td><?= $ra['generation_id'] ?></td>
                        <td><?= $ra['semester'].$ra['section'] ?></td>
                        <td><?= $ra['course_code'] ?></td>
                        <td><?= $ra['teacher_name'] ?></td>
                        <td><?= $ra['room_name'] ?></td>
                        <td><?= $ra['day'] ?></td>
                        <td><?= date('h:i A', strtotime($ra['start_time'])) ?> - <?= date('h:i A', strtotime($ra['end_time'])) ?></td>
                        <td><?= $ra['session_type'] ?></td>
                        <td>
                            <button class="btn btn-sm btn-warning" onclick="editManualRoutine(<?= htmlspecialchars(json_encode($ra)) ?>)">Edit</button>
                            <form method="POST" style="display:inline-block;">
                                <input type="hidden" name="manual_routine_action" value="delete">
                                <input type="hidden" name="assignment_id" value="<?= $ra['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this entry?')">Del</button>
                            </form>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Modal for Add/Edit -->
        <div class="modal fade" id="manualRoutineModal" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <form method="POST" class="modal-content" id="manualRoutineForm">
                    <div class="modal-header"><h5>Routine Entry</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <input type="hidden" name="manual_routine_action" id="manual_action" value="add">
                        <input type="hidden" name="assignment_id" id="manual_id">
                        <div class="row">
                            <div class="col-md-6">
                                <label>Generation ID</label>
                                <input type="number" name="generation_id" id="manual_gen" class="form-control mb-2" required>
                                <label>Batch</label>
                                <select name="batch_id" id="manual_batch" class="form-select mb-2" required>
                                    <option value="">Select Batch</option>
                                    <?php 
                                    $batchesAll = $conn->query("SELECT b.*, d.name as dept_name FROM batches b JOIN departments d ON b.department_id = d.id ORDER BY d.name, b.semester"); 
                                    while($b = $batchesAll->fetch_assoc()): ?>
                                    <option value="<?= $b['id'] ?>" data-type="<?= $b['type'] ?>" data-off1="<?= $b['off_day1'] ?>" data-off2="<?= $b['off_day2'] ?>"><?= $b['dept_name']." - Sem ".$b['semester'].$b['section']." (".$b['type'].")" ?></option>
                                    <?php endwhile; ?>
                                </select>
                                <label>Course</label>
                                <select name="course_id" id="manual_course" class="form-select mb-2" required>
                                    <option value="">Select Batch First</option>
                                </select>
                                <label>Teacher (auto-filled)</label>
                                <input type="text" id="manual_teacher_name" class="form-control mb-2" readonly>
                                <input type="hidden" name="teacher_id" id="manual_teacher_id">
                            </div>
                            <div class="col-md-6">
                                <label>Day</label>
                                <select name="day" id="manual_day" class="form-select mb-2" required>
                                    <option value="">Select Day</option>
                                </select>
                                <label>Timeslot</label>
                                <select name="timeslot_id" id="manual_timeslot" class="form-select mb-2" required>
                                    <option value="">Select Timeslot</option>
                                </select>
                                <label>Available Rooms</label>
                                <select name="classroom_id" id="manual_room" class="form-select mb-2" required disabled>
                                    <option value="">Select a day and timeslot first</option>
                                </select>
                                <input type="hidden" name="session_type" id="manual_type" value="theory">
                                <input type="hidden" name="session_number" id="manual_number" value="1">
                            </div>
                        </div>
                        <div id="roomLoadMsg" class="text-info small"></div>
                    </div>
                    <div class="modal-footer"><button type="submit" class="btn btn-primary">Save</button></div>
                </form>
            </div>
        </div>

        <script>
        let batchCourses = {};
        let batchType = {};
        let batchOffDays = {};

        document.getElementById('manual_batch').addEventListener('change', function() {
            const batchId = this.value;
            const selectedOption = this.options[this.selectedIndex];
            const batchTypeVal = selectedOption.getAttribute('data-type');
            const off1 = selectedOption.getAttribute('data-off1');
            const off2 = selectedOption.getAttribute('data-off2');
            batchType[batchId] = batchTypeVal;
            batchOffDays[batchId] = [off1, off2].filter(d => d);
            
            if (!batchId) {
                document.getElementById('manual_course').innerHTML = '<option value="">Select Batch First</option>';
                document.getElementById('manual_teacher_id').value = '';
                document.getElementById('manual_teacher_name').value = '';
                return;
            }
            
            fetch(`?ajax=get_courses&batch_id=${batchId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.courses) {
                        batchCourses[batchId] = data.courses;
                        let options = '<option value="">Select Course</option>';
                        data.courses.forEach(c => {
                            options += `<option value="${c.id}" data-teacher-id="${c.teacher_id}" data-teacher-name="${c.teacher_name}" data-weekly="${c.weekly_classes}">${c.code} - ${c.title}</option>`;
                        });
                        document.getElementById('manual_course').innerHTML = options;
                    } else {
                        document.getElementById('manual_course').innerHTML = '<option value="">No courses assigned</option>';
                    }
                });
        });

        document.getElementById('manual_course').addEventListener('change', function() {
            const selected = this.options[this.selectedIndex];
            const teacherId = selected.getAttribute('data-teacher-id');
            const teacherName = selected.getAttribute('data-teacher-name');
            const weekly = selected.getAttribute('data-weekly');
            document.getElementById('manual_teacher_id').value = teacherId || '';
            document.getElementById('manual_teacher_name').value = teacherName || '';
            const sessionType = (weekly == 1) ? 'lab' : 'theory';
            document.getElementById('manual_type').value = sessionType;
            
            document.getElementById('manual_day').innerHTML = '<option value="">Select Day</option>';
            document.getElementById('manual_timeslot').innerHTML = '<option value="">Select Timeslot</option>';
            document.getElementById('manual_room').innerHTML = '<option value="">Select a day and timeslot first</option>';
            document.getElementById('manual_room').disabled = true;
            
            const batchId = document.getElementById('manual_batch').value;
            if (batchId && batchOffDays[batchId]) {
                const allDays = ['Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
                let workingDays = allDays.filter(day => !batchOffDays[batchId].includes(day));
                if (batchType[batchId] === 'Evening' && !workingDays.includes('Friday')) {
                    workingDays.push('Friday');
                    workingDays.sort((a,b) => allDays.indexOf(a) - allDays.indexOf(b));
                }
                let dayOptions = '<option value="">Select Day</option>';
                workingDays.forEach(day => { dayOptions += `<option value="${day}">${day}</option>`; });
                document.getElementById('manual_day').innerHTML = dayOptions;
            }
        });

        document.getElementById('manual_day').addEventListener('change', function() {
            const day = this.value;
            const batchId = document.getElementById('manual_batch').value;
            if (!batchId || !day) return;
            const batchTypeVal = batchType[batchId];
            const category = (batchTypeVal === 'Evening') ? 'evening' : 'day';
            fetch(`?ajax=get_timeslots&category=${category}&day=${day}&batch_type=${batchTypeVal}`)
                .then(res => res.json())
                .then(data => {
                    if (data.timeslots) {
                        let options = '<option value="">Select Timeslot</option>';
                        data.timeslots.forEach(ts => {
                            options += `<option value="${ts.id}">${ts.start_time_formatted} - ${ts.end_time_formatted}</option>`;
                        });
                        document.getElementById('manual_timeslot').innerHTML = options;
                        document.getElementById('manual_room').innerHTML = '<option value="">Select a timeslot first</option>';
                        document.getElementById('manual_room').disabled = true;
                    } else {
                        document.getElementById('manual_timeslot').innerHTML = '<option value="">No timeslots available</option>';
                    }
                });
        });

        document.getElementById('manual_timeslot').addEventListener('change', function() {
            const timeslotId = this.value;
            const day = document.getElementById('manual_day').value;
            const batchId = document.getElementById('manual_batch').value;
            const courseId = document.getElementById('manual_course').value;
            const genId = document.getElementById('manual_gen').value;
            if (!timeslotId || !day || !batchId || !courseId || !genId) {
                document.getElementById('manual_room').innerHTML = '<option value="">Missing required fields</option>';
                document.getElementById('manual_room').disabled = true;
                return;
            }
            document.getElementById('roomLoadMsg').innerHTML = 'Loading free rooms...';
            fetch(`?ajax=free_rooms&batch_id=${batchId}&course_id=${courseId}&day=${day}&timeslot_id=${timeslotId}&generation_id=${genId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.error) {
                        document.getElementById('roomLoadMsg').innerHTML = data.error;
                        document.getElementById('manual_room').innerHTML = '<option value="">No free rooms</option>';
                        document.getElementById('manual_room').disabled = true;
                    } else if (data.free_rooms && data.free_rooms.length > 0) {
                        let options = '<option value="">Select a free room</option>';
                        data.free_rooms.forEach(room => {
                            options += `<option value="${room.id}">${room.room_name} (Capacity: ${room.capacity})</option>`;
                        });
                        document.getElementById('manual_room').innerHTML = options;
                        document.getElementById('manual_room').disabled = false;
                        document.getElementById('roomLoadMsg').innerHTML = `${data.free_rooms.length} free room(s) available.`;
                    } else {
                        document.getElementById('manual_room').innerHTML = '<option value="">No free rooms available</option>';
                        document.getElementById('manual_room').disabled = true;
                        document.getElementById('roomLoadMsg').innerHTML = 'All rooms are either restricted or already allocated.';
                    }
                })
                .catch(err => {
                    document.getElementById('roomLoadMsg').innerHTML = 'Error loading rooms.';
                    console.error(err);
                });
        });

        function clearManualForm() {
            document.getElementById('manual_action').value = 'add';
            document.getElementById('manual_id').value = '';
            document.getElementById('manual_gen').value = '';
            document.getElementById('manual_batch').value = '';
            document.getElementById('manual_course').innerHTML = '<option value="">Select Batch First</option>';
            document.getElementById('manual_teacher_id').value = '';
            document.getElementById('manual_teacher_name').value = '';
            document.getElementById('manual_day').innerHTML = '<option value="">Select Day</option>';
            document.getElementById('manual_timeslot').innerHTML = '<option value="">Select Timeslot</option>';
            document.getElementById('manual_room').innerHTML = '<option value="">Select a day and timeslot first</option>';
            document.getElementById('manual_room').disabled = true;
            document.getElementById('manual_type').value = 'theory';
            document.getElementById('manual_number').value = 1;
            document.getElementById('roomLoadMsg').innerHTML = '';
            fetch('?ajax=get_latest_gen').then(res=>res.json()).then(data=>{ if(data.gen) document.getElementById('manual_gen').value=data.gen; });
        }

        function editManualRoutine(data) {
            document.getElementById('manual_action').value = 'update';
            document.getElementById('manual_id').value = data.id;
            document.getElementById('manual_gen').value = data.generation_id;
            const batchSelect = document.getElementById('manual_batch');
            batchSelect.value = data.batch_id;
            batchSelect.dispatchEvent(new Event('change'));
            setTimeout(() => {
                const courseSelect = document.getElementById('manual_course');
                courseSelect.value = data.course_id;
                courseSelect.dispatchEvent(new Event('change'));
            }, 300);
            setTimeout(() => {
                document.getElementById('manual_day').value = data.day;
                document.getElementById('manual_day').dispatchEvent(new Event('change'));
                setTimeout(() => {
                    document.getElementById('manual_timeslot').value = data.timeslot_id;
                    document.getElementById('manual_timeslot').dispatchEvent(new Event('change'));
                    setTimeout(() => {
                        document.getElementById('manual_room').value = data.classroom_id;
                        document.getElementById('manual_type').value = data.session_type;
                        document.getElementById('manual_number').value = data.session_number;
                    }, 500);
                }, 300);
            }, 600);
            new bootstrap.Modal(document.getElementById('manualRoutineModal')).show();
        }

        fetch('?ajax=get_latest_gen').then(res=>res.json()).then(data=>{ if(data.gen) document.getElementById('manual_gen').value=data.gen; });
        </script>
        <?php endif; ?>

        <!-- CSV IMPORT -->
        <?php if($section == 'csv_import'): ?>
        <div class="section-card"><div class="section-title"><i class="fas fa-upload"></i> CSV Import Tools</div><p class="text-muted">Upload CSV files (headers required). Click "Example CSV" to download templates.</p>
        <div class="row"><?php $imports = ['depts'=>'Departments','batches'=>'Batches','courses'=>'Courses','teachers'=>'Teachers','classrooms'=>'Classrooms','timeslots'=>'Time Slots','batch_courses'=>'Batch → Courses','room_slots'=>'Room Slot Restrictions']; foreach($imports as $key=>$label): ?><div class="col-md-6 col-lg-4 mb-3"><div class="card csv-card"><div class="card-body"><h5><?= $label ?></h5><a href="?export_template=<?= $key ?>" class="btn btn-sm btn-outline-primary mb-2"><i class="fas fa-download"></i> Example CSV</a><form method="POST" enctype="multipart/form-data"><input type="file" name="<?= $key ?>_csv" accept=".csv" class="form-control form-control-sm mb-2" required><button type="submit" name="import_<?= $key ?>" class="btn btn-sm btn-success">Import</button></form></div></div></div><?php endforeach; ?></div></div>
        <?php endif; ?>

        <!-- ROUTINE VIEW -->
        <?php if($section == 'routine'): ?>
        <div class="section-card">
            <h3>Class Routine (Latest Generation)</h3>
            <form method="GET" class="mb-3">
                <input type="hidden" name="section" value="routine">
                <select name="batch_id" class="form-select w-auto d-inline-block" onchange="this.form.submit()">
                    <option value="">-- Select Batch --</option>
                    <?php $batches->data_seek(0); while($b = $batches->fetch_assoc()): ?>
                    <option value="<?= $b['id'] ?>" <?= (isset($_GET['batch_id']) && $_GET['batch_id'] == $b['id']) ? 'selected' : '' ?>><?= $b['dept_name']." - Sem ".$b['semester'].$b['section']." (".$b['type'].")" ?></option>
                    <?php endwhile; ?>
                </select>
                <select name="generation_id" class="form-select w-auto d-inline-block ms-2" onchange="this.form.submit()">
                    <option value="">-- Latest Generation --</option>
                    <?php $genRes = $conn->query("SELECT DISTINCT generation_id FROM routine_assignments ORDER BY generation_id DESC"); while($g = $genRes->fetch_assoc()): ?>
                    <option value="<?= $g['generation_id'] ?>" <?= (isset($_GET['generation_id']) && $_GET['generation_id'] == $g['generation_id']) ? 'selected' : '' ?>>Generation <?= $g['generation_id'] ?></option>
                    <?php endwhile; ?>
                </select>
            </form>
            <?php if(isset($_GET['batch_id']) && $_GET['batch_id']): $bid = (int)$_GET['batch_id']; 
                $batchInfo = $conn->query("SELECT type, off_day1, off_day2, department_id FROM batches WHERE id = $bid")->fetch_assoc();
                if(!$batchInfo) { echo "<p>Invalid batch.</p>"; } else {
                    $gen_id = isset($_GET['generation_id']) ? (int)$_GET['generation_id'] : $conn->query("SELECT MAX(generation_id) as gen FROM routine_assignments")->fetch_assoc()['gen'];
                    if(!$gen_id) echo "<p>No routine generated yet.</p>";
                    else {
                        $allDays = ['Saturday','Sunday','Monday','Tuesday','Wednesday','Thursday','Friday'];
                        $offDays = [$batchInfo['off_day1']];
                        if(!empty($batchInfo['off_day2'])) $offDays[] = $batchInfo['off_day2'];
                        $displayDays = array_diff($allDays, $offDays);
                        if($batchInfo['type'] == 'Evening' && in_array('Friday', $offDays)) {
                            $displayDays = array_merge($displayDays, ['Friday']);
                            $displayDays = array_unique($displayDays);
                        }
                        usort($displayDays, function($a,$b) use ($allDays) { return array_search($a,$allDays) - array_search($b,$allDays); });
                        $timeslotCategory = ($batchInfo['type'] == 'Evening') ? 'evening' : 'day';
                        $slotsRes = $conn->query("SELECT * FROM timeslots WHERE category = '$timeslotCategory' ORDER BY start_time");
                        $slotList = [];
                        while($slot = $slotsRes->fetch_assoc()) $slotList[] = $slot;
                ?>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-dark"><tr><th>Time</th><?php foreach($displayDays as $d): ?><th><?= $d ?></th><?php endforeach; ?></tr></thead>
                        <tbody>
                        <?php foreach($slotList as $slot): ?>
                        <tr>
                            <td class="bg-light"><?= date('h:i A', strtotime($slot['start_time'])) . ' - ' . date('h:i A', strtotime($slot['end_time'])) ?></td>
                            <?php foreach($displayDays as $day): ?>
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
                                    <div><strong><?= $row['code'] ?></strong><br><?= $row['teacher'] ?><br><small><?= $row['room_name'] ?></small></div>
                                <?php endwhile; ?>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mt-3"><a href="?print_dept_routine=<?= $batchInfo['department_id'] ?>" class="btn btn-success" target="_blank"><i class="fas fa-print"></i> Print Department Routine (PDF)</a></div>
                <?php } } ?>
            <?php else: ?><p>Select a batch to view routine.</p><?php endif; ?>
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
<div class="modal fade" id="classroomModal" tabindex="-1"><div class="modal-dialog"><form method="POST" class="modal-content"><div class="modal-header"><h5>Classroom</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" name="classroom_action" id="classroom_action" value="create"><input type="hidden" name="id" id="classroom_id"><input name="room_name" id="room_name" class="form-control mb-2" placeholder="Room Name" required><input name="capacity" id="capacity" type="number" class="form-control mb-2" placeholder="Capacity" required><select name="type" id="classroom_type" class="form-select mb-2"><option value="both">Both (Theory & Lab)</option><option value="theory">Theory Only</option><option value="lab">Lab Only</option></select><select name="department_id" id="classroom_dept" class="form-select mb-2"><option value="">Any Department</option><?php $depts->data_seek(0); while($d=$depts->fetch_assoc()): ?><option value="<?= $d['id'] ?>"><?= $d['name'] ?></option><?php endwhile; ?></select><div class="form-check"><input class="form-check-input" type="checkbox" name="has_projector" id="has_projector" checked disabled><label>Projector (always)</label></div><div class="form-check"><input class="form-check-input" type="checkbox" name="has_ac" id="has_ac" checked disabled><label>AC (always)</label></div></div><div class="modal-footer"><button type="submit" class="btn btn-primary">Save</button></div></form></div></div>

<!-- Batch-Course Assignment Modal -->
<div class="modal fade" id="assignBatchCourseModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content">
            <div class="modal-header"><h5>Assign Courses to Batch (teacher mandatory)</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <select name="batch_id" class="form-select mb-2" required>
                    <option value="">Select Batch</option>
                    <?php $batches->data_seek(0); while($b=$batches->fetch_assoc()): ?>
                        <option value="<?= $b['id'] ?>"><?= $b['dept_name']." - Sem ".$b['semester'].$b['section']." (".$b['type'].")" ?></option>
                    <?php endwhile; ?>
                </select>
                <div id="course-list">
                    <table class="table table-sm" id="course-table">
                        <thead><tr><th>Course</th><th>Assign Teacher (required)</th></tr></thead>
                        <tbody>
                            <?php $courses->data_seek(0); while($c=$courses->fetch_assoc()): ?>
                            <tr>
                                <td><input type="checkbox" name="course_ids[]" value="<?= $c['id'] ?>"> <?= $c['code']." - ".$c['title'] ?></td>
                                <td>
                                    <select name="teacher_ids[]" class="form-select form-select-sm">
                                        <option value="" disabled selected>Select teacher</option>
                                        <?php 
                                        $teachers_list = $conn->query("SELECT t.id, u.username FROM teachers t JOIN users u ON t.user_id = u.id");
                                        while($tch=$teachers_list->fetch_assoc()): ?>
                                            <option value="<?= $tch['id'] ?>"><?= $tch['username'] ?></option>
                                        <?php endwhile; ?>
                                    </select>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer"><button type="submit" name="assign_batch_course" class="btn btn-primary">Assign</button></div>
        </form>
    </div>
</div>

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
function clearClassroomForm(){ document.getElementById('classroom_action').value='create'; document.getElementById('classroom_id').value=''; document.getElementById('room_name').value=''; document.getElementById('capacity').value=''; document.getElementById('classroom_type').value='both'; document.getElementById('classroom_dept').value=''; document.getElementById('has_projector').checked=true; document.getElementById('has_ac').checked=true; }
function editClassroom(id,name,cap,dept_id,proj,ac,type){ document.getElementById('classroom_action').value='update'; document.getElementById('classroom_id').value=id; document.getElementById('room_name').value=name; document.getElementById('capacity').value=cap; document.getElementById('classroom_type').value=type; document.getElementById('classroom_dept').value=dept_id||''; document.getElementById('has_projector').checked=true; document.getElementById('has_ac').checked=true; new bootstrap.Modal(document.getElementById('classroomModal')).show(); }
function clearRoomSlotForm() { document.getElementById('room_slot_action').value = 'create'; document.getElementById('restriction_id').value = ''; document.getElementById('restriction_room').value = ''; document.getElementById('restriction_day').value = ''; document.getElementById('restriction_timeslot').value = ''; document.getElementById('restriction_dept').value = ''; }
</script>
</body>
</html>
<?php $conn->close(); ?>
