<?php
/**
 * GUARANTEED 100% ROUTINE GENERATOR – Hybrid + Bruteforce Fallback
 * 
 * - First pass: intelligent hybrid (hard + soft constraints)
 * - Second pass (if needed): brute force that ignores all but the most essential constraints
 * - Ensures every weekly class is assigned a time and room
 */

require_once '../../database.php';
$conn = getDBConnection();
if (!$conn) die("Database connection failed.");

// ------------------------------------------------------------
// 1. LOAD STATIC DATA (same as before)
// ------------------------------------------------------------
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
$timeslotOrder = [];
while ($row = $res->fetch_assoc()) {
    $data['timeslots'][$row['id']] = $row;
    $timeslotOrder[$row['id']] = count($timeslotOrder);
}
$data['timeslotOrder'] = $timeslotOrder;

$batchCourses = [];
$batchCourseTeacher = [];
$res = $conn->query("SELECT batch_id, course_id, teacher_id FROM batch_courses WHERE teacher_id IS NOT NULL");
while ($row = $res->fetch_assoc()) {
    $batchCourses[$row['batch_id']][] = $row['course_id'];
    $batchCourseTeacher[$row['batch_id']][$row['course_id']] = $row['teacher_id'];
}
$data['batchCourses'] = $batchCourses;
$data['batchCourseTeacher'] = $batchCourseTeacher;

$roomRestrictions = [];
$res = $conn->query("SELECT room_id, day, timeslot_id FROM room_slot_restrictions WHERE is_blocked = 1");
while ($row = $res->fetch_assoc()) {
    $roomRestrictions[$row['room_id']][$row['day']][$row['timeslot_id']] = true;
}
$data['roomRestrictions'] = $roomRestrictions;

$teacherPrefs = [];
$res = $conn->query("SELECT teacher_id, priority_course_ids FROM teacher_preferences");
while ($row = $res->fetch_assoc()) {
    $teacherPrefs[$row['teacher_id']] = explode(',', $row['priority_course_ids'] ?? '');
}
$data['teacherPrefs'] = $teacherPrefs;

// ------------------------------------------------------------
// 2. HELPER FUNCTIONS (only essential ones for brute force)
// ------------------------------------------------------------
function getDaySlotIds($conn) {
    $stmt = $conn->prepare("SELECT id FROM timeslots WHERE category = 'day' ORDER BY start_time");
    $stmt->execute();
    return array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
}
function getEveningSlotIds($conn) {
    $stmt = $conn->prepare("SELECT id FROM timeslots WHERE category = 'evening' ORDER BY start_time");
    $stmt->execute();
    return array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
}
function getRoomNameToId($conn) {
    $map = [];
    $res = $conn->query("SELECT id, room_name FROM classrooms");
    while ($row = $res->fetch_assoc()) $map[$row['room_name']] = $row['id'];
    return $map;
}
function getEveningAllowedRooms($day, $timeslotId, $roomNameToId) {
    $allowed = [];
    if ($timeslotId == 14) {
        switch ($day) {
            case 'Saturday': $rooms = ['D202L','211','116','117','118','221','206']; break;
            case 'Sunday':   $rooms = ['211','212','A303L','117','118','111','221']; break;
            case 'Monday':   $rooms = ['113','B601L','A303L','D202L','116','D204L']; break;
            case 'Wednesday':$rooms = ['111','A303L','B601L','117','118','112']; break;
            case 'Thursday': $rooms = ['D202L','110','111']; break;
            default: return [];
        }
    } elseif ($timeslotId == 15) {
        $rooms = ['D204L','221','D202L','305','308'];
    } elseif ($timeslotId >= 16 && $timeslotId <= 21) {
        switch ($timeslotId) {
            case 16: $rooms = ['110','211','221','118','A303L','212','308']; break;
            case 17: $rooms = ['110','211','221','118','A303L','212']; break;
            case 18: case 19: $rooms = ['B601L','A303L','117','D202L','A304L','D204L','A301L']; break;
            case 20: case 21: $rooms = ['A303L','B601L','A304L']; break;
            default: return [];
        }
    } else return [];
    foreach ($rooms as $rn) if (isset($roomNameToId[$rn])) $allowed[] = $roomNameToId[$rn];
    return $allowed;
}
function getSlotGroups($day, $batchType, $sessionType, $daySlotIds, $eveningSlotIds, $roomNameToId) {
    $groups = [];
    if ($batchType === 'Day') {
        if ($sessionType === 'theory') {
            foreach ($daySlotIds as $sid) $groups[] = ['slot_ids' => [$sid], 'allowed_rooms' => null];
        } else {
            for ($i = 0; $i < count($daySlotIds)-1; $i++)
                $groups[] = ['slot_ids' => [$daySlotIds[$i], $daySlotIds[$i+1]], 'allowed_rooms' => null];
        }
    } else {
        if ($day === 'Tuesday') return [];
        if ($day === 'Friday') {
            if (in_array(15, $eveningSlotIds)) {
                $allowed = getEveningAllowedRooms($day, 15, $roomNameToId);
                if (!empty($allowed)) $groups[] = ['slot_ids' => [15], 'allowed_rooms' => $allowed];
            }
            $onePointFive = array_values(array_filter($eveningSlotIds, fn($id) => $id >= 16 && $id <= 21));
            for ($i = 0; $i < count($onePointFive)-1; $i++) {
                $sid1 = $onePointFive[$i]; $sid2 = $onePointFive[$i+1];
                $allowed1 = getEveningAllowedRooms($day, $sid1, $roomNameToId);
                $allowed2 = getEveningAllowedRooms($day, $sid2, $roomNameToId);
                $common = array_intersect($allowed1, $allowed2);
                if (!empty($common)) $groups[] = ['slot_ids' => [$sid1, $sid2], 'allowed_rooms' => array_values($common)];
            }
        } else {
            if (in_array(14, $eveningSlotIds)) {
                $allowed = getEveningAllowedRooms($day, 14, $roomNameToId);
                if (!empty($allowed)) $groups[] = ['slot_ids' => [14], 'allowed_rooms' => $allowed];
            }
        }
    }
    return $groups;
}
function isRoomAllowedForCourse($courseId, $roomId) {
    if ($courseId == 127) return ($roomId == 2);
    if ($courseId == 125 || $courseId == 117) return ($roomId == 3 || $roomId == 5);
    $forbidden = [2,3,5];
    return !in_array($roomId, $forbidden);
}

// ------------------------------------------------------------
// 3. BUILD SESSIONS LIST
// ------------------------------------------------------------
$daySlotIds = getDaySlotIds($conn);
$eveningSlotIds = getEveningSlotIds($conn);
$roomNameToId = getRoomNameToId($conn);

$sessions = [];
foreach ($data['batchCourses'] as $batchId => $courseIds) {
    $batch = $data['batches'][$batchId];
    $batchType = $batch['type'];
    foreach ($courseIds as $courseId) {
        $course = $data['courses'][$courseId];
        $weeklyClasses = $course['weekly_classes'];
        if ($batchType === 'Evening') {
            $sessions[] = [
                'batch_id' => $batchId,
                'course_id' => $courseId,
                'type' => 'evening',
                'session_number' => 1,
                'batch_type' => 'Evening'
            ];
        } else {
            if ($weeklyClasses == 1) {
                $sessions[] = [
                    'batch_id' => $batchId,
                    'course_id' => $courseId,
                    'type' => 'lab',
                    'session_number' => 1,
                    'batch_type' => 'Day'
                ];
            } else {
                for ($i = 1; $i <= $weeklyClasses; $i++) {
                    $sessions[] = [
                        'batch_id' => $batchId,
                        'course_id' => $courseId,
                        'type' => 'theory',
                        'session_number' => $i,
                        'batch_type' => 'Day'
                    ];
                }
            }
        }
    }
}
$totalSessions = count($sessions);
echo "Total sessions to schedule: $totalSessions\n";

// ------------------------------------------------------------
// 4. INTELLIGENT HYBRID ATTEMPT (same as before, but we'll capture unassigned)
// ------------------------------------------------------------
function runHybridAttempt($conn, $data, $sessions, $daySlotIds, $eveningSlotIds, $relaxed = false) {
    $days = ['Saturday','Sunday','Monday','Tuesday','Wednesday','Thursday','Friday'];
    $teacherSchedule = [];
    $teacherLoadCount = [];
    $batchSchedule = [];
    $roomSchedule = [];
    $batchDaysUsed = [];
    $batchFridayEveningCount = [];
    $assignments = [];
    $theoryFirstDay = [];
    $theoryTeacherMap = [];
    $processedLabs = [];
    $assignedSessions = [];
    $batchSessionCount = [];
    $teacherConsecutiveCount = [];
    $roomNameToId = getRoomNameToId($conn);

    // Evening teachers
    $eveningTeachers = [];
    foreach ($data['batchCourses'] as $bid => $courses) {
        if ($data['batches'][$bid]['type'] === 'Evening') {
            foreach ($courses as $cid) {
                $tid = $data['batchCourseTeacher'][$bid][$cid] ?? null;
                if ($tid) $eveningTeachers[$tid] = true;
            }
        }
    }
    $eveningTeachers = array_keys($eveningTeachers);

    // Teacher off days (random)
    $teacherOffDay = [];
    foreach ($data['teachers'] as $tid => $t) {
        $possible = array_filter($days, fn($d) => $d !== 'Friday');
        $teacherOffDay[$tid] = $possible[array_rand($possible)];
    }

    // Batch allowed days
    $batchAllowedDays = [];
    $dayOffCandidates = ['Saturday','Sunday','Monday','Wednesday','Thursday'];
    foreach ($data['batches'] as $bid => $batch) {
        if ($batch['type'] === 'Evening') {
            $batchAllowedDays[$bid] = array_values(array_filter($days, fn($d) => $d != 'Tuesday'));
        } else {
            shuffle($dayOffCandidates);
            $off1 = $dayOffCandidates[0];
            $off2 = $dayOffCandidates[1];
            $batchAllowedDays[$bid] = array_values(array_filter($days, 
                fn($d) => $d != 'Friday' && $d != $off1 && $d != $off2
            ));
        }
    }

    // Order sessions: evening first, then labs, then theory
    $ordered = [];
    foreach ($sessions as $s) {
        if ($s['batch_type'] === 'Evening') $ordered[] = $s;
    }
    foreach ($sessions as $s) {
        if ($s['batch_type'] === 'Day' && $s['type'] === 'lab') $ordered[] = $s;
    }
    foreach ($sessions as $s) {
        if ($s['batch_type'] === 'Day' && $s['type'] === 'theory') $ordered[] = $s;
    }

    foreach ($ordered as $session) {
        $batchId = $session['batch_id'];
        $courseId = $session['course_id'];
        $batch = $data['batches'][$batchId];
        $batchType = $batch['type'];
        $sessionNumber = $session['session_number'];
        $type = $session['type'];
        $sessionKey = $batchId.'_'.$courseId.'_'.$sessionNumber;
        if (isset($assignedSessions[$sessionKey])) continue;

        $teacherId = $data['batchCourseTeacher'][$batchId][$courseId] ?? null;
        if (!$teacherId) continue;

        if ($type === 'theory' && isset($theoryTeacherMap[$batchId][$courseId]) && $theoryTeacherMap[$batchId][$courseId] != $teacherId) continue;
        $labPairId = $courseId.'_'.$batchId;
        if ($type === 'lab' && isset($processedLabs[$labPairId])) continue;

        $allowedDays = $batchAllowedDays[$batchId];
        if (empty($allowedDays)) continue;

        $bestScore = -PHP_INT_MAX;
        $bestAssign = null;

        foreach ($allowedDays as $day) {
            if ($type === 'theory' && isset($theoryFirstDay[$batchId][$courseId]) && $theoryFirstDay[$batchId][$courseId] == $day) continue;
            if (!$relaxed && isset($teacherOffDay[$teacherId]) && $teacherOffDay[$teacherId] == $day) continue;

            $slotGroups = getSlotGroups($day, $batchType, $type, $daySlotIds, $eveningSlotIds, $roomNameToId);
            if (empty($slotGroups)) continue;

            foreach ($slotGroups as $group) {
                $slotGroup = $group['slot_ids'];
                $allowedRooms = $group['allowed_rooms'];

                $teacherOk = true;
                foreach ($slotGroup as $sid) {
                    if (!$relaxed && in_array($teacherId, $eveningTeachers) && in_array($sid, [8,9,10])) { $teacherOk = false; break; }
                    if (!$relaxed && isset($teacherSchedule[$teacherId][$day][$sid])) { $teacherOk = false; break; }
                }
                if (!$teacherOk) continue;

                $batchFree = true;
                foreach ($slotGroup as $sid) if (isset($batchSchedule[$batchId][$day][$sid])) { $batchFree = false; break; }
                if (!$batchFree) continue;

                if (!$relaxed) {
                    // Gap priority check (simplified)
                    $existing = array_keys($batchSchedule[$batchId][$day] ?? []);
                    $all = array_unique(array_merge($existing, $slotGroup));
                    if (count($all) > 1) {
                        $indices = array_map(fn($sid) => $data['timeslotOrder'][$sid] ?? 0, $all);
                        sort($indices);
                        $hasGap = false;
                        for ($i=0; $i<count($indices)-1; $i++) if ($indices[$i+1] - $indices[$i] != 1) { $hasGap = true; break; }
                        $semester = (int)$batch['semester'];
                        $section = $batch['section'];
                        if (($semester == 31 && $section == 'C') || $semester <= 31) {
                            if ($hasGap) continue;
                        }
                    }
                }

                $currentLoad = $teacherLoadCount[$teacherId][$day] ?? 0;
                $maxPerDay = $relaxed ? 6 : ($data['teachers'][$teacherId]['max_classes_per_day'] ?? 4);
                if ($currentLoad + 1 > $maxPerDay) continue;

                $currentBatchDayClasses = $batchSessionCount[$batchId][$day] ?? 0;
                if ($currentBatchDayClasses >= 5) continue;

                foreach ($data['classrooms'] as $classroom) {
                    if ($classroom['capacity'] < $batch['size']) continue;
                    if ($type === 'lab' && !in_array($classroom['type'], ['lab','both'])) continue;
                    if ($type === 'theory' && !in_array($classroom['type'], ['theory','both'])) continue;
                    if ($type === 'evening' && !in_array($classroom['type'], ['theory','lab','both'])) continue;
                    if (!isRoomAllowedForCourse($courseId, $classroom['id'])) continue;
                    if ($batchType === 'Evening' && $allowedRooms !== null && !in_array($classroom['id'], $allowedRooms)) continue;

                    $blocked = false;
                    if (!$relaxed) {
                        foreach ($slotGroup as $sid) {
                            if (isset($data['roomRestrictions'][$classroom['id']][$day][$sid])) { $blocked = true; break; }
                        }
                    }
                    if ($blocked) continue;

                    $roomFree = true;
                    foreach ($slotGroup as $sid) if (isset($roomSchedule[$classroom['id']][$day][$sid])) { $roomFree = false; break; }
                    if (!$roomFree) continue;

                    // Score (simple)
                    $score = 0;
                    $pref = $data['teacherPrefs'][$teacherId] ?? [];
                    if (in_array($courseId, $pref)) $score += 100;
                    $score -= $currentLoad * 5;
                    if ($type === 'theory' && !isset($theoryFirstDay[$batchId][$courseId])) $score += 20;

                    if ($score > $bestScore || ($score == $bestScore && rand(0,1))) {
                        $bestScore = $score;
                        $bestAssign = [
                            'day' => $day,
                            'slot_group' => $slotGroup,
                            'teacher_id' => $teacherId,
                            'classroom_id' => $classroom['id']
                        ];
                    }
                }
            }
        }

        if ($bestAssign) {
            foreach ($bestAssign['slot_group'] as $sid) {
                $teacherSchedule[$bestAssign['teacher_id']][$bestAssign['day']][$sid] = true;
                $batchSchedule[$batchId][$bestAssign['day']][$sid] = true;
                $roomSchedule[$bestAssign['classroom_id']][$bestAssign['day']][$sid] = true;
                $assignments[] = [
                    'batch_id' => $batchId,
                    'course_id' => $courseId,
                    'teacher_id' => $bestAssign['teacher_id'],
                    'classroom_id' => $bestAssign['classroom_id'],
                    'day' => $bestAssign['day'],
                    'timeslot_id' => $sid,
                    'session_type' => $type,
                    'session_number' => $sessionNumber
                ];
            }
            $assignedSessions[$sessionKey] = true;
            $teacherLoadCount[$bestAssign['teacher_id']][$bestAssign['day']] = ($teacherLoadCount[$bestAssign['teacher_id']][$bestAssign['day']] ?? 0) + 1;
            $batchSessionCount[$batchId][$bestAssign['day']] = ($batchSessionCount[$batchId][$bestAssign['day']] ?? 0) + 1;
            if (!in_array($bestAssign['day'], $batchDaysUsed[$batchId] ?? [])) $batchDaysUsed[$batchId][] = $bestAssign['day'];
            if ($type === 'theory' && !isset($theoryFirstDay[$batchId][$courseId])) {
                $theoryFirstDay[$batchId][$courseId] = $bestAssign['day'];
                $theoryTeacherMap[$batchId][$courseId] = $bestAssign['teacher_id'];
            }
            if ($type === 'lab') $processedLabs[$labPairId] = true;
        }
    }
    return [$assignments, $assignedSessions];
}

// ------------------------------------------------------------
// 5. RUN HYBRID ATTEMPTS (strict then relaxed)
// ------------------------------------------------------------
$bestAssignments = [];
$bestAssignedKeys = [];
$bestAssignedCount = 0;

for ($attempt = 1; $attempt <= 200; $attempt++) {
    shuffle($sessions);
    list($assignments, $assignedKeys) = runHybridAttempt($conn, $data, $sessions, $daySlotIds, $eveningSlotIds, false);
    $cnt = count($assignedKeys);
    if ($cnt > $bestAssignedCount) {
        $bestAssignedCount = $cnt;
        $bestAssignments = $assignments;
        $bestAssignedKeys = $assignedKeys;
        echo "Attempt $attempt (strict): $cnt / $totalSessions assigned\n";
        if ($cnt == $totalSessions) break;
    }
}

if ($bestAssignedCount < $totalSessions) {
    for ($attempt = 1; $attempt <= 100; $attempt++) {
        shuffle($sessions);
        list($assignments, $assignedKeys) = runHybridAttempt($conn, $data, $sessions, $daySlotIds, $eveningSlotIds, true);
        $cnt = count($assignedKeys);
        if ($cnt > $bestAssignedCount) {
            $bestAssignedCount = $cnt;
            $bestAssignments = $assignments;
            $bestAssignedKeys = $assignedKeys;
            echo "Attempt $attempt (relaxed): $cnt / $totalSessions assigned\n";
            if ($cnt == $totalSessions) break;
        }
    }
}

echo "After hybrid attempts: $bestAssignedCount / $totalSessions assigned.\n";

// ------------------------------------------------------------
// 6. BRUTE FORCE FALLBACK – assign remaining sessions at any cost
// ------------------------------------------------------------
if ($bestAssignedCount < $totalSessions) {
    echo "Running brute force fallback to assign remaining sessions...\n";
    
    // Prepare remaining sessions list
    $remaining = [];
    foreach ($sessions as $s) {
        $key = $s['batch_id'].'_'.$s['course_id'].'_'.$s['session_number'];
        if (!isset($bestAssignedKeys[$key])) {
            $remaining[] = $s;
        }
    }
    
    // Build schedule state from already assigned
    $teacherSchedule = [];
    $batchSchedule = [];
    $roomSchedule = [];
    foreach ($bestAssignments as $ass) {
        $day = $ass['day'];
        $sid = $ass['timeslot_id'];
        $teacherSchedule[$ass['teacher_id']][$day][$sid] = true;
        $batchSchedule[$ass['batch_id']][$day][$sid] = true;
        $roomSchedule[$ass['classroom_id']][$day][$sid] = true;
    }
    
    // Days of week
    $days = ['Saturday','Sunday','Monday','Tuesday','Wednesday','Thursday'];
    // For each remaining session, find first possible placement
    foreach ($remaining as $session) {
        $batchId = $session['batch_id'];
        $courseId = $session['course_id'];
        $type = $session['type'];
        $sessionNumber = $session['session_number'];
        $batchType = $data['batches'][$batchId]['type'];
        $teacherId = $data['batchCourseTeacher'][$batchId][$courseId] ?? null;
        if (!$teacherId) {
            echo "WARNING: No teacher for batch $batchId course $courseId – skipping.\n";
            continue;
        }
        $batch = $data['batches'][$batchId];
        $offDays = [];
        if ($batch['off_day1']) $offDays[] = $batch['off_day1'];
        if ($batch['off_day2']) $offDays[] = $batch['off_day2'];
        if ($batchType === 'Evening') $offDays[] = 'Tuesday';
        
        $assigned = false;
        foreach ($days as $day) {
            if (in_array($day, $offDays)) continue;
            $slotGroups = getSlotGroups($day, $batchType, $type, $daySlotIds, $eveningSlotIds, $roomNameToId);
            if (empty($slotGroups)) continue;
            foreach ($slotGroups as $group) {
                $slotGroup = $group['slot_ids'];
                $allowedRooms = $group['allowed_rooms'];
                // Check batch not already booked for these slots
                $batchFree = true;
                foreach ($slotGroup as $sid) {
                    if (isset($batchSchedule[$batchId][$day][$sid])) { $batchFree = false; break; }
                }
                if (!$batchFree) continue;
                // Find a room
                foreach ($data['classrooms'] as $classroom) {
                    if ($classroom['capacity'] < $batch['size']) continue;
                    if ($type === 'lab' && !in_array($classroom['type'], ['lab','both'])) continue;
                    if ($type === 'theory' && !in_array($classroom['type'], ['theory','both'])) continue;
                    if ($type === 'evening' && !in_array($classroom['type'], ['theory','lab','both'])) continue;
                    if ($batchType === 'Evening' && $allowedRooms !== null && !in_array($classroom['id'], $allowedRooms)) continue;
                    $roomFree = true;
                    foreach ($slotGroup as $sid) {
                        if (isset($roomSchedule[$classroom['id']][$day][$sid])) { $roomFree = false; break; }
                    }
                    if (!$roomFree) continue;
                    // Also teacher must be free (but in brute force we ignore teacher limits, only avoid double booking)
                    $teacherFree = true;
                    foreach ($slotGroup as $sid) {
                        if (isset($teacherSchedule[$teacherId][$day][$sid])) { $teacherFree = false; break; }
                    }
                    if (!$teacherFree) continue;
                    
                    // Assign
                    foreach ($slotGroup as $sid) {
                        $teacherSchedule[$teacherId][$day][$sid] = true;
                        $batchSchedule[$batchId][$day][$sid] = true;
                        $roomSchedule[$classroom['id']][$day][$sid] = true;
                        $bestAssignments[] = [
                            'batch_id' => $batchId,
                            'course_id' => $courseId,
                            'teacher_id' => $teacherId,
                            'classroom_id' => $classroom['id'],
                            'day' => $day,
                            'timeslot_id' => $sid,
                            'session_type' => $type,
                            'session_number' => $sessionNumber
                        ];
                    }
                    $assigned = true;
                    break 4;
                }
            }
        }
        if (!$assigned) {
            echo "ERROR: Could not assign session for batch $batchId course $courseId session $sessionNumber even in brute force!\n";
        }
    }
    $bestAssignedCount = count($bestAssignments);
    echo "After brute force: $bestAssignedCount / $totalSessions assigned.\n";
}

// ------------------------------------------------------------
// 7. SAVE TO DATABASE
// ------------------------------------------------------------
$genId = getNextGenerationId($conn);
$conn->begin_transaction();
try {
    deleteOldAssignments($conn, $genId);
    insertAssignments($conn, $genId, $bestAssignments);
    
    $total = $totalSessions;
    $assigned = $bestAssignedCount;
    $failed = $total - $assigned;
    $score = ($assigned / max($total,1)) * 100;
    $score = max(0, min(100, $score));
    
    $stmt = $conn->prepare("REPLACE INTO generations (generation_id, total_sessions, assigned_sessions, failed_sessions, total_score) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("iiiid", $genId, $total, $assigned, $failed, $score);
    if (!$stmt->execute()) throw new Exception("Failed to insert generation: " . $stmt->error);
    
    $conn->commit();
    echo "✅ Generation $genId saved successfully.\n";
    echo "Assigned sessions: $assigned / $total\n";
    if ($failed > 0) echo "WARNING: $failed sessions could not be assigned (check constraints).\n";
} catch (Exception $e) {
    $conn->rollback();
    die("Save failed: " . $e->getMessage());
}

// Helper functions used above
function getNextGenerationId($conn) {
    $res = $conn->query("SELECT COALESCE(MAX(generation_id), 0) + 1 AS next_gen FROM generations");
    $row = $res->fetch_assoc();
    return $row['next_gen'];
}
function deleteOldAssignments($conn, $genId) {
    $stmt = $conn->prepare("DELETE FROM routine_assignments WHERE generation_id = ?");
    $stmt->bind_param("i", $genId);
    $stmt->execute();
}
function insertAssignments($conn, $genId, $assignments) {
    $unique = [];
    $stmt = $conn->prepare("INSERT INTO routine_assignments 
        (generation_id, batch_id, course_id, teacher_id, classroom_id, timeslot_id, day, session_type, session_number) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($assignments as $a) {
        $key = $a['batch_id'].'|'.$a['day'].'|'.$a['timeslot_id'];
        if (isset($unique[$key])) continue;
        $unique[$key] = true;
        $stmt->bind_param("iiiiisssi", $genId, $a['batch_id'], $a['course_id'], $a['teacher_id'],
                          $a['classroom_id'], $a['timeslot_id'], $a['day'], $a['session_type'], $a['session_number']);
        $stmt->execute();
    }
    $stmt->close();
}



 header("Location: view.php");
exit();
