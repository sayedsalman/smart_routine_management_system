<?php
session_start();
require_once '../../database.php';
require_once 'scheduler_functions.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../../login.php");
    exit();
}

$conn = getDBConnection();

// ------------------------------------------------------------
// Helper: run one scheduling attempt (returns assignments array)
// ------------------------------------------------------------
function runSchedulingAttempt($conn, $data) {
    $days = ['Saturday','Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday'];
    $timeslots = $data['timeslots'];
    $timeslot_ids = array_keys($timeslots);
    $classrooms = $data['classrooms'];
    
    // In-memory schedules
    $teacher_schedule = []; // [teacher_id][day][slot_id] = true
    $batch_schedule = [];
    $room_schedule = [];
    $assignments = []; // list of successful assignments (each element = one timeslot row)
    
    // Build sessions to schedule
    $sessions = [];
    foreach ($data['batches'] as $batch_id => $batch) {
        if (!isset($data['batch_courses'][$batch_id])) continue;
        foreach ($data['batch_courses'][$batch_id] as $course_id) {
            $course = $data['courses'][$course_id];
            $is_lab = ($course['credit'] == 1.5);
            if ($is_lab) {
                $sessions[] = [
                    'batch_id' => $batch_id,
                    'course_id' => $course_id,
                    'type' => 'lab',
                    'required_slots' => 2,
                    'priority' => 100,
                    'batch_size' => $batch['size']
                ];
            } else {
                for ($i = 1; $i <= 2; $i++) {
                    $sessions[] = [
                        'batch_id' => $batch_id,
                        'course_id' => $course_id,
                        'type' => 'theory',
                        'session_number' => $i,
                        'required_slots' => 1,
                        'priority' => 50 + (int)($batch['size'] > 50 ? 20 : 0),
                        'batch_size' => $batch['size']
                    ];
                }
            }
        }
    }
    
    // Sort by priority desc, then labs first
    usort($sessions, function($a, $b) {
        if ($a['priority'] != $b['priority']) return $b['priority'] - $a['priority'];
        return ($a['type'] == 'lab' ? -1 : 1);
    });
    
    foreach ($sessions as $session) {
        $assigned = false;
        $best_score = -PHP_INT_MAX;
        $best_assign = null;
        
        $batch = $data['batches'][$session['batch_id']];
        $available_days = array_filter($days, function($day) use ($batch) {
            return $day != $batch['off_day1'] && $day != $batch['off_day2'];
        });
        
        foreach ($available_days as $day) {
            $max_start = count($timeslot_ids) - $session['required_slots'];
            for ($i = 0; $i <= $max_start; $i++) {
                $slot_ids = array_slice($timeslot_ids, $i, $session['required_slots']);
                
                // Check batch availability for all slots
                $batch_free = true;
                foreach ($slot_ids as $sid) {
                    if (isset($batch_schedule[$session['batch_id']][$day][$sid])) {
                        $batch_free = false;
                        break;
                    }
                }
                if (!$batch_free) continue;
                
                // Get teachers for this course
                $teachers = $data['teacher_courses'][$session['course_id']] ?? [];
                foreach ($teachers as $teacher_id) {
                    // Check teacher availability for all slots
                    $teacher_free = true;
                    foreach ($slot_ids as $sid) {
                        if (isset($teacher_schedule[$teacher_id][$day][$sid])) {
                            $teacher_free = false;
                            break;
                        }
                    }
                    if (!$teacher_free) continue;
                    
                    // Try classrooms
                    foreach ($classrooms as $classroom) {
                        if ($classroom['capacity'] < $session['batch_size']) continue;
                        if ($classroom['type'] != 'both' && $classroom['type'] != $session['type']) continue;
                        
                        // ---------- NEW: Skip if room is restricted for ANY of the required slots ----------
                        $restricted = false;
                        foreach ($slot_ids as $sid) {
                            if (isset($data['room_restrictions'][$classroom['id']][$day][$sid])) {
                                $restricted = true;
                                break;
                            }
                        }
                        if ($restricted) continue;
                        // --------------------------------------------------------------------------------
                        
                        $room_free = true;
                        foreach ($slot_ids as $sid) {
                            if (isset($room_schedule[$classroom['id']][$day][$sid])) {
                                $room_free = false;
                                break;
                            }
                        }
                        if (!$room_free) continue;
                        
                        // Calculate score (implement calculateScore() in scheduler_functions.php)
                        $score = calculateScore(
                            $teacher_id,
                            $session['course_id'],
                            $session['batch_id'],
                            $day,
                            $slot_ids[0],
                            ($session['type'] == 'lab'),
                            $data,
                            $teacher_schedule,
                            $batch_schedule,
                            $assignments
                        );
                        
                        if ($score > $best_score) {
                            $best_score = $score;
                            $best_assign = [
                                'day' => $day,
                                'slot_ids' => $slot_ids,
                                'teacher_id' => $teacher_id,
                                'classroom_id' => $classroom['id']
                            ];
                        }
                    }
                }
            }
        }
        
        if ($best_assign) {
            // Apply to in-memory schedules and store assignment rows
            foreach ($best_assign['slot_ids'] as $slot_id) {
                $teacher_schedule[$best_assign['teacher_id']][$best_assign['day']][$slot_id] = true;
                $batch_schedule[$session['batch_id']][$best_assign['day']][$slot_id] = true;
                $room_schedule[$best_assign['classroom_id']][$best_assign['day']][$slot_id] = true;
                
                // Store one row per timeslot
                $assignments[] = [
                    'batch_id' => $session['batch_id'],
                    'course_id' => $session['course_id'],
                    'teacher_id' => $best_assign['teacher_id'],
                    'classroom_id' => $best_assign['classroom_id'],
                    'day' => $best_assign['day'],
                    'timeslot_id' => $slot_id,
                    'session_type' => $session['type'],
                    'session_number' => $session['session_number'] ?? null
                ];
            }
        }
        // If not assigned, we skip (failed session)
    }
    
    return [
        'assignments' => $assignments,
        'assigned' => count($assignments),
        'total' => count($sessions),
        'failed' => count($sessions) - count($assignments)
    ];
}

// ------------------------------------------------------------
// Main: run multiple attempts and pick best
// ------------------------------------------------------------
$data = loadStaticData($conn);
$best_result = null;
$best_assigned = -1;

for ($attempt = 1; $attempt <= 5; $attempt++) {
    $result = runSchedulingAttempt($conn, $data);
    if ($result['assigned'] > $best_assigned) {
        $best_assigned = $result['assigned'];
        $best_result = $result;
    }
}

if ($best_result && $best_result['assigned'] > 0) {
    // Get next generation_id
    $maxGenQuery = "SELECT COALESCE(MAX(generation_id), 0) + 1 AS next_gen FROM routine_assignments";
    $genResult = $conn->query($maxGenQuery);
    $row = $genResult->fetch_assoc();
    $generation_id = $row['next_gen'];
    
    // Insert each assignment into routine_assignments
    $insert_stmt = $conn->prepare("INSERT INTO routine_assignments 
        (generation_id, batch_id, course_id, teacher_id, classroom_id, timeslot_id, day, session_type, session_number) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    foreach ($best_result['assignments'] as $assign) {
        $insert_stmt->bind_param("iiiiisssi", 
            $generation_id,
            $assign['batch_id'],
            $assign['course_id'],
            $assign['teacher_id'],
            $assign['classroom_id'],
            $assign['timeslot_id'],
            $assign['day'],
            $assign['session_type'],
            $assign['session_number']
        );
        $insert_stmt->execute();
    }
    
    $_SESSION['last_generation_id'] = $generation_id;
    $_SESSION['generation_report'] = [
        'total' => $best_result['total'],
        'assigned' => $best_result['assigned'],
        'failed' => $best_result['failed'],
        'generation_id' => $generation_id
    ];
} else {
    $_SESSION['generation_report'] = [
        'total' => 0,
        'assigned' => 0,
        'failed' => 0,
        'error' => 'No schedule could be generated.'
    ];
}

header("Location: view.php");
exit();
?>