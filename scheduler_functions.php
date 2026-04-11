<?php
// scheduler_functions.php
require_once '../../database.php';

/**
 * Load all static data into arrays for fast access
 * Includes room slot restrictions for CSE department.
 */
function loadStaticData($conn) {
    $data = [];
    
    // Timeslots
    $data['timeslots'] = [];
    $res = $conn->query("SELECT * FROM timeslots ORDER BY start_time");
    while ($row = $res->fetch_assoc()) {
        $data['timeslots'][$row['id']] = $row;
    }
    
    // Classrooms
    $data['classrooms'] = [];
    $res = $conn->query("SELECT * FROM classrooms");
    while ($row = $res->fetch_assoc()) {
        $data['classrooms'][$row['id']] = $row;
    }
    
    // Teachers
    $data['teachers'] = [];
    $res = $conn->query("SELECT * FROM teachers");
    while ($row = $res->fetch_assoc()) {
        $data['teachers'][$row['id']] = $row;
    }
    
    // Teacher preferences
    $data['teacher_prefs'] = [];
    $res = $conn->query("SELECT * FROM teacher_preferences");
    while ($row = $res->fetch_assoc()) {
        $data['teacher_prefs'][$row['teacher_id']] = $row;
    }
    
    // Teacher courses
    $data['teacher_courses'] = [];
    $res = $conn->query("SELECT * FROM teacher_courses");
    while ($row = $res->fetch_assoc()) {
        $data['teacher_courses'][$row['course_id']][] = $row['teacher_id'];
    }
    
    // Batches
    $data['batches'] = [];
    $res = $conn->query("SELECT b.*, d.name as dept_name FROM batches b JOIN departments d ON b.department_id = d.id");
    while ($row = $res->fetch_assoc()) {
        $data['batches'][$row['id']] = $row;
    }
    
    // Batch courses
    $data['batch_courses'] = [];
    $res = $conn->query("SELECT * FROM batch_courses");
    while ($row = $res->fetch_assoc()) {
        $data['batch_courses'][$row['batch_id']][] = $row['course_id'];
    }
    
    // Courses
    $data['courses'] = [];
    $res = $conn->query("SELECT * FROM courses");
    while ($row = $res->fetch_assoc()) {
        $data['courses'][$row['id']] = $row;
    }
    
    // ---------- NEW: Room slot restrictions for CSE (department_id = 1) ----------
    $data['room_restrictions'] = [];
    $sql = "SELECT room_id, day, timeslot_id FROM room_slot_restrictions 
            WHERE department_id = 1 AND is_blocked = 1";
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $data['room_restrictions'][$row['room_id']][$row['day']][$row['timeslot_id']] = true;
        }
    }
    // ---------------------------------------------------------------------------
    
    return $data;
}

/**
 * Calculate score for a potential assignment
 * 
 * Parameters:
 *   $teacher_id, $course_id, $batch_id, $day, $slot_id, $is_lab
 *   $data (all loaded data, including room_restrictions if needed)
 *   &$teacher_load (reference to teacher schedule array)
 *   &$batch_load (reference to batch schedule array)
 *   &$global_assignments (already made assignments)
 * 
 * Returns integer score (higher = better)
 */
function calculateScore($teacher_id, $course_id, $batch_id, $day, $slot_id, $is_lab, $data, &$teacher_load, &$batch_load, &$global_assignments) {
    $score = 0;
    
    // 1. Teacher preference for time range
    if (isset($data['teacher_prefs'][$teacher_id])) {
        $pref = $data['teacher_prefs'][$teacher_id];
        $slot_time = $data['timeslots'][$slot_id]['start_time'];
        if ($pref['preferred_time_start'] && $pref['preferred_time_end']) {
            if ($slot_time >= $pref['preferred_time_start'] && $slot_time <= $pref['preferred_time_end']) {
                $score += 10;
            } else {
                $score -= 5;
            }
        }
    }
    
    // 2. Gap minimization (previous slot occupied for same batch)
    $prev_slot = $slot_id - 1;
    if ($prev_slot > 0 && isset($batch_load[$batch_id][$day][$prev_slot])) {
        $score += 8;
    }
    
    // 3. Teacher load balancing – compare to average
    $total_assignments = array_sum(array_map('count', $teacher_load[$teacher_id] ?? []));
    $avg_load = (array_sum(array_map(function($t) { return array_sum(array_map('count', $t)); }, $teacher_load)) / max(1, count($teacher_load)));
    $diff = abs($total_assignments - $avg_load);
    $score += max(0, 20 - $diff);
    
    // 4. Daily load limit
    $daily_load = count($teacher_load[$teacher_id][$day] ?? []);
    $max_per_day = $data['teachers'][$teacher_id]['max_classes_per_day'] ?? 4;
    if ($daily_load >= $max_per_day) $score -= 20;
    
    // 5. Consecutive penalty
    if ($daily_load > 0) {
        $last_slot = max(array_keys($teacher_load[$teacher_id][$day] ?? []));
        if ($slot_id == $last_slot + 1) {
            $consecutive = 1;
            $temp = $slot_id;
            while (isset($teacher_load[$teacher_id][$day][$temp-1])) {
                $consecutive++;
                $temp--;
            }
            if ($consecutive > ($data['teachers'][$teacher_id]['max_consecutive'] ?? 3)) {
                $score -= 10;
            }
        }
    }
    
    // 6. Lab priority & room suitability
    if ($is_lab) {
        $score += 15;
        // Lab in midday = better
        $hour = (int)substr($slot_time, 0, 2);
        if ($hour >= 10 && $hour <= 14) $score += 5;
        if ($hour < 9) $score -= 5;
    }
    
    // 7. Avoid same course on same day for same batch
    foreach ($global_assignments as $ass) {
        if ($ass['batch_id'] == $batch_id && $ass['day'] == $day && $ass['course_id'] == $course_id) {
            $score -= 15;
            break;
        }
    }
    
    // 8. (Optional) Penalize if the room is restricted – but generate.php already filters those out.
    //    So we don't need to check here.
    
    return $score;
}

/**
 * Conflict detection after generation
 */
function detectConflicts($conn, $generation_id) {
    $conflicts = [];
    
    // Teacher overload
    $res = $conn->query("
        SELECT teacher_id, day, COUNT(*) as cnt
        FROM routine_assignments
        WHERE generation_id = $generation_id
        GROUP BY teacher_id, day
        HAVING cnt > (SELECT max_classes_per_day FROM teachers WHERE id = teacher_id)
    ");
    while ($row = $res->fetch_assoc()) {
        $conflicts['teacher_overload'][] = $row;
    }
    
    // Batch gaps (empty slot between classes)
    $res = $conn->query("
        SELECT batch_id, day, GROUP_CONCAT(timeslot_id ORDER BY timeslot_id) as slots
        FROM routine_assignments
        WHERE generation_id = $generation_id
        GROUP BY batch_id, day
    ");
    while ($row = $res->fetch_assoc()) {
        $slots = explode(',', $row['slots']);
        for ($i = 0; $i < count($slots)-1; $i++) {
            if ($slots[$i+1] - $slots[$i] > 1) {
                $conflicts['batch_gaps'][] = [
                    'batch_id' => $row['batch_id'],
                    'day' => $row['day'],
                    'gap_between' => $slots[$i] . ' and ' . $slots[$i+1]
                ];
            }
        }
    }
    
    // Same course twice same day for a batch
    $res = $conn->query("
        SELECT batch_id, day, course_id, COUNT(*) as cnt
        FROM routine_assignments
        WHERE generation_id = $generation_id
        GROUP BY batch_id, day, course_id
        HAVING cnt > 1
    ");
    while ($row = $res->fetch_assoc()) {
        $conflicts['course_repeat'][] = $row;
    }
    
    return $conflicts;
}
?>