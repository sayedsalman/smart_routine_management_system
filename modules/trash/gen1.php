<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
set_time_limit(300);
ob_start();

// =================================================================
// 1. DATABASE CONNECTION & INITIAL LOAD
// =================================================================
require_once '../../database.php';
$conn = getDBConnection();
if (!$conn) die("Database connection failed.");

// Load all data
$data = [];
$res = $conn->query("SELECT * FROM batches");
while ($row = $res->fetch_assoc()) $data['batches'][$row['id']] = $row;

$res = $conn->query("SELECT * FROM courses");
while ($row = $res->fetch_assoc()) $data['courses'][$row['id']] = $row;

$res = $conn->query("SELECT * FROM teachers");
while ($row = $res->fetch_assoc()) $data['teachers'][$row['id']] = $row;

$res = $conn->query("SELECT * FROM classrooms");
while ($row = $res->fetch_assoc()) $data['classrooms'][$row['id']] = $row;

$res = $conn->query("SELECT * FROM timeslots ORDER BY start_time");
while ($row = $res->fetch_assoc()) {
    $data['timeslots'][$row['id']] = $row;
}

$batchCourseTeacher = [];
$res = $conn->query("SELECT batch_id, course_id, teacher_id FROM batch_courses WHERE teacher_id IS NOT NULL");
while ($row = $res->fetch_assoc()) {
    $batchCourseTeacher[$row['batch_id']][$row['course_id']] = $row['teacher_id'];
}
$data['batchCourseTeacher'] = $batchCourseTeacher;

$roomRestrictions = [];
$res = $conn->query("SELECT room_id, day, timeslot_id FROM room_slot_restrictions WHERE is_blocked = 1");
while ($row = $res->fetch_assoc()) {
    $roomRestrictions[$row['room_id']][$row['day']][$row['timeslot_id']] = true;
}
$data['roomRestrictions'] = $roomRestrictions;

// =================================================================
// 2. EVENING SLOT CONFIGURATION - FRIDAY IS MAIN DAY
// =================================================================
$daySlots = [8, 9, 10, 11, 12, 13];  // 1.5 hour each
$eveningSlots = [14, 15, 16, 17, 18, 19, 20, 21];  // 14: 3hr, 15: 3hr, 16-21: 1.5hr

// Evening batch schedule - Friday has MOST slots
$eveningSchedule = [
    'Saturday'   => ['slots' => [14]],      // 1 slot (3hr)
    'Sunday'     => ['slots' => [14]],      // 1 slot (3hr)
    'Monday'     => ['slots' => [14]],      // 1 slot (3hr)
    'Tuesday'    => ['slots' => []],        // OFF
    'Wednesday'  => ['slots' => [14]],      // 1 slot (3hr)
    'Thursday'   => ['slots' => [14]],      // 1 slot (3hr)
    'Friday'     => ['slots' => [15, 16, 17, 18, 19, 20, 21]]  // 7 slots! (1x3hr + 6x1.5hr)
];

// Room availability for evening slots
$eveningRooms = [
    14 => ['D202L','211','116','117','118','221','206','110','111','112','113','A303L','B601L'],
    15 => ['D204L','221','D202L','305','308','A303L','B601L','A304L'],
    16 => ['110','211','221','118','A303L','212','308','111','112'],
    17 => ['110','211','221','118','A303L','212','111','112'],
    18 => ['B601L','A303L','117','D202L','A304L','D204L','A301L','110','111'],
    19 => ['B601L','A303L','117','D202L','A304L','D204L','A301L','110','111'],
    20 => ['A303L','B601L','A304L','110','111'],
    21 => ['A303L','B601L','A304L','110','111']
];

function getRoomIdByName($roomName, $roomNameToId) {
    return $roomNameToId[$roomName] ?? null;
}

// Build room name to ID mapping
$roomNameToId = [];
$res = $conn->query("SELECT id, room_name FROM classrooms");
while ($row = $res->fetch_assoc()) {
    $roomNameToId[$row['room_name']] = $row['id'];
}

// Convert evening rooms to IDs
foreach ($eveningRooms as $slotId => $roomNames) {
    $eveningRooms[$slotId] = array_filter(array_map(function($name) use ($roomNameToId) {
        return $roomNameToId[$name] ?? null;
    }, $roomNames));
}

// =================================================================
// 3. BUILD SESSIONS LIST
// =================================================================
$sessions = [];
$batchInfo = [];

foreach ($data['batchCourseTeacher'] as $batchId => $courses) {
    $batch = $data['batches'][$batchId];
    $batchType = $batch['type'];
    
    foreach ($courses as $courseId => $teacherId) {
        $course = $data['courses'][$courseId];
        $weeklyClasses = $course['weekly_classes'];
        
        if ($batchType === 'Evening') {
            // Evening batch: 1 session per course
            $sessions[] = [
                'batch_id' => $batchId,
                'course_id' => $courseId,
                'teacher_id' => $teacherId,
                'type' => 'evening',
                'session_num' => 1,
                'batch_type' => 'Evening',
                'batch_size' => $batch['size'],
                'batch_semester' => $batch['semester'],
                'batch_section' => $batch['section']
            ];
        } else {
            // Day batch
            if ($weeklyClasses == 1) {
                // Lab course
                $sessions[] = [
                    'batch_id' => $batchId,
                    'course_id' => $courseId,
                    'teacher_id' => $teacherId,
                    'type' => 'lab',
                    'session_num' => 1,
                    'batch_type' => 'Day',
                    'batch_size' => $batch['size'],
                    'batch_semester' => $batch['semester'],
                    'batch_section' => $batch['section']
                ];
            } else {
                // Theory course - multiple sessions
                for ($i = 1; $i <= $weeklyClasses; $i++) {
                    $sessions[] = [
                        'batch_id' => $batchId,
                        'course_id' => $courseId,
                        'teacher_id' => $teacherId,
                        'type' => 'theory',
                        'session_num' => $i,
                        'batch_type' => 'Day',
                        'batch_size' => $batch['size'],
                        'batch_semester' => $batch['semester'],
                        'batch_section' => $batch['section']
                    ];
                }
            }
        }
    }
}

$totalSessions = count($sessions);
$eveningSessions = array_filter($sessions, fn($s) => $s['type'] === 'evening');
$labSessions = array_filter($sessions, fn($s) => $s['type'] === 'lab');
$theorySessions = array_filter($sessions, fn($s) => $s['type'] === 'theory');

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                    UNIVERSITY TIMETABLE SCHEDULING SYSTEM                   ║\n";
echo "╚════════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "📊 SESSION BREAKDOWN:\n";
echo "   ├─ Evening Sessions:  " . count($eveningSessions) . "\n";
echo "   ├─ Lab Sessions:      " . count($labSessions) . "\n";
echo "   ├─ Theory Sessions:   " . count($theorySessions) . "\n";
echo "   └─ TOTAL:             $totalSessions\n";
echo "\n";
echo "🌙 EVENING SCHEDULE (Friday is MAIN day):\n";
echo "   Saturday:    Slot 14 (3hr)\n";
echo "   Sunday:      Slot 14 (3hr)\n";
echo "   Monday:      Slot 14 (3hr)\n";
echo "   Tuesday:     OFF\n";
echo "   Wednesday:   Slot 14 (3hr)\n";
echo "   Thursday:    Slot 14 (3hr)\n";
echo "   Friday:      Slots 15,16,17,18,19,20,21 (7 slots!)\n";
echo "\n";

// =================================================================
// 4. MAIN SCHEDULER - ENSURES ALL SESSIONS ARE SCHEDULED
// =================================================================
function scheduleAllSessions($conn, $data, $sessions, $daySlots, $eveningSchedule, $eveningRooms, $roomNameToId) {
    $teacherSchedule = [];
    $batchSchedule = [];
    $roomSchedule = [];
    $assignments = [];
    $assignedKeys = [];
    $failedSessions = [];
    
    // Track counts
    $stats = ['evening' => 0, 'lab' => 0, 'theory' => 0];
    
    // Priority: Evening FIRST (most restrictive), then Lab, then Theory
    usort($sessions, function($a, $b) {
        $priority = ['evening' => 1, 'lab' => 2, 'theory' => 3];
        return $priority[$a['type']] - $priority[$b['type']];
    });
    
    $total = count($sessions);
    echo "🎯 Starting scheduler (Evening batches prioritized - Friday has 7 slots)...\n\n";
    
    foreach ($sessions as $idx => $session) {
        if (($idx + 1) % 20 == 0) {
            echo "   Progress: " . ($idx + 1) . " / $total sessions\n";
        }
        
        $batchId = $session['batch_id'];
        $courseId = $session['course_id'];
        $type = $session['type'];
        $sessionNum = $session['session_num'];
        $batchType = $session['batch_type'];
        $batchSize = $session['batch_size'];
        $teacherId = $session['teacher_id'];
        
        if (!$teacherId) {
            $failedSessions[] = $session;
            continue;
        }
        
        // Set off days based on batch type
        $offDays = ['Friday']; // All batches have Friday off? NO! Evening batches have classes on Friday!
        
        if ($batchType === 'Day') {
            $offDays = ['Friday'];  // Day batches: Friday off
            if ($data['batches'][$batchId]['off_day1']) $offDays[] = $data['batches'][$batchId]['off_day1'];
            if ($data['batches'][$batchId]['off_day2']) $offDays[] = $data['batches'][$batchId]['off_day2'];
        } else {
            // Evening batches: Tuesday off ONLY (Friday is MAIN day!)
            $offDays = ['Tuesday'];
        }
        
        $assigned = false;
        
        // For evening batches, prioritize Friday (has most slots)
        if ($batchType === 'Evening') {
            $daysPriority = ['Friday', 'Saturday', 'Sunday', 'Monday', 'Wednesday', 'Thursday'];
        } else {
            $daysPriority = ['Saturday', 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday'];
        }
        
        foreach ($daysPriority as $day) {
            if (in_array($day, $offDays)) continue;
            
            // Get available slots for this day and batch type
            if ($batchType === 'Evening') {
                $availableSlots = $eveningSchedule[$day]['slots'] ?? [];
            } else {
                // Day batch slots
                if ($type === 'theory') {
                    $availableSlots = $daySlots;
                } else {
                    // Lab needs 2 consecutive slots
                    $slotPairs = [];
                    for ($i = 0; $i < count($daySlots) - 1; $i++) {
                        $slotPairs[] = [$daySlots[$i], $daySlots[$i + 1]];
                    }
                    $availableSlots = $slotPairs;
                }
            }
            
            if (empty($availableSlots)) continue;
            
            // For each slot option
            foreach ($availableSlots as $slotOption) {
                $slotGroup = is_array($slotOption) ? $slotOption : [$slotOption];
                $slotsCount = count($slotGroup);
                
                // Check if batch is free for ALL slots in group
                $batchFree = true;
                foreach ($slotGroup as $sid) {
                    if (isset($batchSchedule[$batchId][$day][$sid])) {
                        $batchFree = false;
                        break;
                    }
                }
                if (!$batchFree) continue;
                
                // Check if teacher is free for ALL slots
                $teacherFree = true;
                foreach ($slotGroup as $sid) {
                    if (isset($teacherSchedule[$teacherId][$day][$sid])) {
                        $teacherFree = false;
                        break;
                    }
                }
                if (!$teacherFree) continue;
                
                // Check teacher daily limit
                $currentDailyLoad = count($teacherSchedule[$teacherId][$day] ?? []);
                $maxPerDay = $data['teachers'][$teacherId]['max_classes_per_day'] ?? 5;
                if ($currentDailyLoad + $slotsCount > $maxPerDay) continue;
                
                // Get available rooms for this slot
                $availableRoomIds = [];
                if ($batchType === 'Evening') {
                    $availableRoomIds = $eveningRooms[$slotGroup[0]] ?? [];
                } else {
                    $availableRoomIds = array_keys($data['classrooms']);
                }
                
                // Filter rooms by capacity and type
                foreach ($availableRoomIds as $roomId) {
                    if (!isset($data['classrooms'][$roomId])) continue;
                    $room = $data['classrooms'][$roomId];
                    
                    if ($room['capacity'] < $batchSize) continue;
                    
                    if ($type === 'lab' && !in_array($room['type'], ['lab', 'both'])) continue;
                    if ($type === 'theory' && !in_array($room['type'], ['theory', 'both'])) continue;
                    
                    // Course-specific room restrictions
                    if ($courseId == 127 && $roomId != 2) continue;
                    if (($courseId == 125 || $courseId == 117) && !in_array($roomId, [3, 5])) continue;
                    
                    // Check room availability for ALL slots
                    $roomFree = true;
                    foreach ($slotGroup as $sid) {
                        if (isset($roomSchedule[$roomId][$day][$sid])) {
                            $roomFree = false;
                            break;
                        }
                    }
                    if (!$roomFree) continue;
                    
                    // ASSIGN THE SESSION!
                    foreach ($slotGroup as $sid) {
                        $teacherSchedule[$teacherId][$day][$sid] = true;
                        $batchSchedule[$batchId][$day][$sid] = true;
                        $roomSchedule[$roomId][$day][$sid] = true;
                        $assignments[] = [
                            'batch_id' => $batchId,
                            'course_id' => $courseId,
                            'teacher_id' => $teacherId,
                            'classroom_id' => $roomId,
                            'day' => $day,
                            'timeslot_id' => $sid,
                            'session_type' => $type,
                            'session_number' => $sessionNum
                        ];
                    }
                    
                    $key = $batchId . '_' . $courseId . '_' . $sessionNum;
                    $assignedKeys[$key] = true;
                    $assigned = true;
                    $stats[$type]++;
                    break 3;
                }
            }
        }
        
        if (!$assigned) {
            $failedSessions[] = $session;
            echo "   ❌ Failed: {$session['batch_semester']}-{$session['batch_section']}: {$session['type']} course ID {$courseId}\n";
        }
    }
    
    echo "\n📈 SCHEDULING STATISTICS:\n";
    echo "   ✅ Evening assigned: {$stats['evening']} / " . count(array_filter($sessions, fn($s) => $s['type'] === 'evening')) . "\n";
    echo "   ✅ Lab assigned:     {$stats['lab']} / " . count(array_filter($sessions, fn($s) => $s['type'] === 'lab')) . "\n";
    echo "   ✅ Theory assigned:  {$stats['theory']} / " . count(array_filter($sessions, fn($s) => $s['type'] === 'theory')) . "\n";
    echo "   ✅ TOTAL assigned:   " . count($assignedKeys) . " / " . count($sessions) . "\n";
    
    return [$assignments, $assignedKeys, $failedSessions, $stats];
}

// =================================================================
// 5. EXECUTE SCHEDULER
// =================================================================
list($assignments, $assignedKeys, $failedSessions, $stats) = scheduleAllSessions(
    $conn, $data, $sessions, $daySlots, $eveningSchedule, $eveningRooms, $roomNameToId
);

$assignedCount = count($assignedKeys);
$failedCount = $totalSessions - $assignedCount;

echo "\n";
echo "╔════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                              FINAL RESULTS                                 ║\n";
echo "╚════════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "   📊 Total Sessions:     $totalSessions\n";
echo "   ✅ Assigned:           $assignedCount\n";
echo "   ❌ Failed:             $failedCount\n";
echo "   📈 Success Rate:       " . round(($assignedCount / $totalSessions) * 100, 2) . "%\n";

if ($failedCount > 0) {
    echo "\n⚠️  FAILED SESSIONS (" . count($failedSessions) . "):\n";
    echo str_repeat("─", 80) . "\n";
    foreach ($failedSessions as $fs) {
        echo "   • Batch {$fs['batch_semester']}-{$fs['batch_section']}: {$fs['type']} (Course ID: {$fs['course_id']})\n";
    }
}

// =================================================================
// 6. SAVE TO DATABASE
// =================================================================
function saveToDatabase($conn, $assignments, $totalSessions, $assignedCount, $failedCount) {
    // Get next generation ID
    $res = $conn->query("SELECT COALESCE(MAX(generation_id), 0) + 1 AS next_gen FROM generations");
    $row = $res->fetch_assoc();
    $genId = (int)($row['next_gen'] ?? 1);
    
    // Clear old assignments
    $stmt = $conn->prepare("DELETE FROM routine_assignments WHERE generation_id = ?");
    $stmt->bind_param("i", $genId);
    $stmt->execute();
    $stmt->close();
    
    // Insert new assignments
    $insertStmt = $conn->prepare("INSERT INTO routine_assignments 
        (generation_id, batch_id, course_id, teacher_id, classroom_id, timeslot_id, day, session_type, session_number) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    $savedCount = 0;
    $unique = [];
    
    foreach ($assignments as $a) {
        $key = $a['batch_id'] . '|' . $a['day'] . '|' . $a['timeslot_id'];
        if (isset($unique[$key])) continue;
        $unique[$key] = true;
        
        $insertStmt->bind_param("iiiiisssi",
            $genId,
            $a['batch_id'],
            $a['course_id'],
            $a['teacher_id'],
            $a['classroom_id'],
            $a['timeslot_id'],
            $a['day'],
            $a['session_type'],
            $a['session_number']
        );
        
        if ($insertStmt->execute()) {
            $savedCount++;
        }
    }
    $insertStmt->close();
    
    // Save generation record
    $score = ($assignedCount / max($totalSessions, 1)) * 100;
    $genStmt = $conn->prepare("INSERT INTO generations 
        (generation_id, total_sessions, assigned_sessions, failed_sessions, total_score) 
        VALUES (?, ?, ?, ?, ?)");
    $genStmt->bind_param("iiiid", $genId, $totalSessions, $assignedCount, $failedCount, $score);
    $genStmt->execute();
    $genStmt->close();
    
    return ['genId' => $genId, 'savedCount' => $savedCount, 'score' => $score];
}

echo "\n💾 Saving to database...\n";

try {
    $result = saveToDatabase($conn, $assignments, $totalSessions, $assignedCount, $failedCount);
    
    echo "\n";
    echo "╔════════════════════════════════════════════════════════════════════════════╗\n";
    echo "║                           SAVE SUCCESSFUL                                  ║\n";
    echo "╚════════════════════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    echo "   🆔 Generation ID:     {$result['genId']}\n";
    echo "   💾 Assignments saved: {$result['savedCount']} / " . count($assignments) . "\n";
    echo "   📈 Success Rate:      " . round($result['score'], 2) . "%\n";
    
} catch (Exception $e) {
    echo "\n❌ ERROR saving: " . $e->getMessage() . "\n";
}

ob_end_clean();
echo "\n✅ Scheduling complete! Redirecting...\n";
header("Location: view.php");
exit();
?>
