<?php

require_once 'scheduler_functions.php';
require_once '../../database.php';

$conn = getDBConnection();
$data = loadStaticData($conn);
validateData($data);

$daySlotIds = getDayTimeslotIds($conn);
$eveningSlotIds = getEveningTimeslotIds($conn);

// Build sessions
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

$bestResult = null;
$bestAssigned = 0;
$bestPenalty = PHP_INT_MAX;

// Many attempts – strict first
for ($attempt = 1; $attempt <= 3000; $attempt++) {
    shuffle($sessions);
    $result = runSchedulingAttempt($conn, $data, $sessions, $daySlotIds, $eveningSlotIds, false);
    $totalPenalty = $result['evening_friday_penalty'] + $result['day_batch_min_courses_penalty'];
    if ($result['assigned_sessions'] > $bestAssigned) {
        $bestAssigned = $result['assigned_sessions'];
        $bestPenalty = $totalPenalty;
        $bestResult = $result;
    } elseif ($result['assigned_sessions'] == $bestAssigned && $totalPenalty < $bestPenalty) {
        $bestPenalty = $totalPenalty;
        $bestResult = $result;
    }
    if ($bestAssigned == count($sessions) && $bestPenalty == 0) break;
}

// Relaxed mode if not all assigned
if ($bestAssigned < count($sessions) || $bestPenalty > 0) {
    for ($attempt = 1; $attempt <= 2000; $attempt++) {
        shuffle($sessions);
        $result = runSchedulingAttempt($conn, $data, $sessions, $daySlotIds, $eveningSlotIds, true);
        $totalPenalty = $result['evening_friday_penalty'] + $result['day_batch_min_courses_penalty'];
        if ($result['assigned_sessions'] > $bestAssigned) {
            $bestAssigned = $result['assigned_sessions'];
            $bestPenalty = $totalPenalty;
            $bestResult = $result;
        } elseif ($result['assigned_sessions'] == $bestAssigned && $totalPenalty < $bestPenalty) {
            $bestPenalty = $totalPenalty;
            $bestResult = $result;
        }
        if ($bestAssigned == count($sessions) && $bestPenalty == 0) break;
    }
}

// Final force – should now assign everything
if ($bestAssigned < count($sessions)) {
    $result = runSchedulingAttempt($conn, $data, $sessions, $daySlotIds, $eveningSlotIds, true);
    if ($result['assigned_sessions'] > $bestAssigned) $bestResult = $result;
}

if (!$bestResult || $bestAssigned == 0) die("Scheduling failed – no assignments generated.");

$genId = getNextGenerationId($conn);
deleteOldAssignments($conn, $genId);
insertAssignments($conn, $genId, $bestResult['assignments']);

$total = $bestResult['total_sessions'];
$assigned = $bestResult['assigned_sessions'];
$failed = $bestResult['failed_sessions'];
$score = ($assigned / max($total,1)) * 100;
$score -= $bestResult['evening_friday_penalty'] * 10;
$score -= $bestResult['day_batch_min_courses_penalty'] * 20;
$score = max(0, $score);

$stmt = $conn->prepare("REPLACE INTO generations (generation_id, total_sessions, assigned_sessions, failed_sessions, total_score) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param("iiiid", $genId, $total, $assigned, $failed, $score);
$stmt->execute();

echo "Scheduling completed. Generation ID: $genId<br>";
echo "Assigned sessions: $assigned / $total<br>";
echo "Evening Friday missing: {$bestResult['evening_friday_penalty']}<br>";
echo "Day batches with <2 classes/day: {$bestResult['day_batch_min_courses_penalty']}<br>";
echo "Score: " . round($score,2) . "%<br>";

header("Location: view.php");
exit();
?>
