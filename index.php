<?php
// Start session only if needed (optional)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/database.php";

if (!function_exists('getDBConnection')) {
    die("❌ database.php loaded, but getDBConnection() NOT found");
}

$db = new Database();
$conn = $db->connect();

// Use global connection from database.php
global $conn;
if (!isset($conn) || !$conn) {
    die("Database connection failed. Please check your configuration.");
}

// Helper function to count records
function countRecords($conn, $table) {
    try {
        $result = $conn->query("SELECT COUNT(*) as total FROM `$table`");
        if ($result && $row = $result->fetch_assoc()) {
            return (int)$row['total'];
        }
    } catch (Exception $e) {
        // Silent fail
    }
    return 0;
}

// Fetch real stats
$totalTeachers = countRecords($conn, 'teachers');
$totalCourses   = countRecords($conn, 'courses');
$totalBatches   = countRecords($conn, 'batches');
$totalStudents  = countRecords($conn, 'students');
$totalDepartments = countRecords($conn, 'departments');
$totalRoutineAssignments = countRecords($conn, 'routine_assignments');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SRMS • Smart Routine Management System</title>
    <link rel="icon" type="image/png" sizes="32x32" href="https://salman.rfnhsc.com/salman.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #f8fafc;
            overflow-x: hidden;
        }

        /* Aurora background */
        .aurora-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -2;
            overflow: hidden;
        }
        .gradient-blob {
            position: absolute;
            width: 40vw;
            height: 40vh;
            border-radius: 50%;
            filter: blur(60px);
            opacity: 0.15;
        }
        .blob-1 { background: linear-gradient(135deg, #38bdf8, #5eead4); top: 10%; left: 5%; }
        .blob-2 { background: linear-gradient(135deg, #c7d2fe, #a78bfa); top: 60%; right: 5%; }
        .blob-3 { background: linear-gradient(135deg, #f472b6, #fbbf24); bottom: 10%; left: 30%; }
        .blob-4 { background: linear-gradient(135deg, #34d399, #38bdf8); top: 20%; right: 30%; }

        /* Hero Section */
        .section-hero {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 2rem;
        }
        .hero-headline {
            font-size: 3.5rem;
            font-weight: 800;
            background: linear-gradient(to right, #f8fafc, #38bdf8);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .hero-subline {
            font-size: 1.2rem;
            color: #cbd5e1;
            margin: 1.5rem 0;
        }
        .hero-actions {
            display: flex;
            gap: 1rem;
            margin-top: 2rem;
        }
        .btn-hero {
            padding: 0.8rem 2rem;
            border-radius: 40px;
            font-weight: 600;
            text-decoration: none;
            transition: 0.3s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #38bdf8, #5eead4);
            color: #0f172a;
        }
        .btn-secondary {
            background: transparent;
            border: 1px solid rgba(255,255,255,0.2);
            color: #f8fafc;
        }
        .btn-hero:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.2);
        }

        /* Stats row (real DB data) */
        .stats-row {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 2rem;
            margin: 3rem auto;
            max-width: 1200px;
        }
        .stat-card {
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(12px);
            border-radius: 24px;
            padding: 1.5rem;
            text-align: center;
            min-width: 150px;
            border: 0.5px solid rgba(255,255,255,0.1);
        }
        .stat-card h3 {
            font-size: 2rem;
            font-weight: 800;
            background: linear-gradient(135deg, #5eead4, #38bdf8);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .stat-card p {
            color: #cbd5e1;
            margin-top: 0.5rem;
        }

        /* Keep original sections (problem, solution, features, etc.) */
        section {
            padding: 5rem 2rem;
            position: relative;
        }
        .section-header {
            text-align: center;
            margin-bottom: 3rem;
        }
        .section-header h2 {
            font-size: 2.5rem;
            background: linear-gradient(to right, #f8fafc, #38bdf8);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .glass-card {
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(12px);
            border-radius: 24px;
            padding: 2rem;
            border: 0.5px solid rgba(255,255,255,0.1);
        }

        /* Mac Dock (only navigation) */
        .mac-dock {
            position: fixed;
            bottom: 20px;
            left: 0;
            right: 0;
            display: flex;
            justify-content: center;
            z-index: 1000;
            pointer-events: none;
        }
        .dock-items {
            background: rgba(30, 41, 59, 0.8);
            backdrop-filter: blur(20px);
            border-radius: 42px;
            padding: 8px 24px;
            display: flex;
            gap: 16px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            border: 0.5px solid rgba(255,255,255,0.2);
            pointer-events: auto;
        }
        .dock-item {
            color: #cbd5e1;
            font-size: 1.4rem;
            text-decoration: none;
            transition: 0.2s;
            width: 52px;
            height: 52px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 32px;
            position: relative;
        }
        .dock-item:hover {
            transform: scale(1.2) translateY(-8px);
            background: rgba(255,255,255,0.1);
            color: #38bdf8;
        }
        .dock-item.active {
            color: #38bdf8;
        }
        .dock-item::after {
            content: attr(data-label);
            position: absolute;
            bottom: 70px;
            background: #1e293bcc;
            backdrop-filter: blur(8px);
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            white-space: nowrap;
            opacity: 0;
            transition: opacity 0.2s;
            pointer-events: none;
        }
        .dock-item:hover::after {
            opacity: 1;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .hero-headline { font-size: 2rem; }
            .stats-row { gap: 1rem; }
            .dock-items { gap: 8px; padding: 6px 12px; }
            .dock-item { width: 44px; height: 44px; font-size: 1.2rem; }
        }
    </style>
</head>
<body>

<div class="aurora-bg">
    <div class="gradient-blob blob-1"></div>
    <div class="gradient-blob blob-2"></div>
    <div class="gradient-blob blob-3"></div>
    <div class="gradient-blob blob-4"></div>
</div>

<!-- Hero Section -->
<section class="section-hero">
    <h1 class="hero-headline">Smart Routine Management System</h1>
    <p class="hero-subline">Intelligent, conflict‑free scheduling for modern universities</p>
    <div class="hero-actions">
        <a href="login.php" class="btn-hero btn-primary">Login</a>
        <a href="register.php" class="btn-hero btn-secondary">Register</a>
        <a href="#features" class="btn-hero btn-secondary">Explore Features</a>
    </div>
</section>

<!-- Real Database Stats -->
<div class="stats-row">
    <div class="stat-card"><h3><?= $totalTeachers ?></h3><p>👨‍🏫 Teachers</p></div>
    <div class="stat-card"><h3><?= $totalCourses ?></h3><p>📚 Courses</p></div>
    <div class="stat-card"><h3><?= $totalBatches ?></h3><p>👥 Batches</p></div>
    <div class="stat-card"><h3><?= $totalStudents ?></h3><p>🎓 Students</p></div>
    <div class="stat-card"><h3><?= $totalDepartments ?></h3><p>🏛️ Departments</p></div>
    <div class="stat-card"><h3><?= $totalRoutineAssignments ?></h3><p>📅 Scheduled Classes</p></div>
</div>

<!-- Problem Section (kept as is) -->
<section id="problem">
    <div class="section-header"><h2>Why SRMS?</h2><p>Solving real scheduling pains</p></div>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px,1fr)); gap:2rem; max-width:1200px; margin:0 auto;">
        <div class="glass-card"><i class="fas fa-hourglass-half" style="font-size:2rem;"></i><h3>Long Gaps</h3><p>Students waste hours between classes → reduced focus.</p></div>
        <div class="glass-card"><i class="fas fa-chalkboard-teacher" style="font-size:2rem;"></i><h3>Teacher Burnout</h3><p>Unbalanced loads and no consultation time.</p></div>
        <div class="glass-card"><i class="fas fa-bug" style="font-size:2rem;"></i><h3>Manual Conflicts</h3><p>Room/teacher clashes, last‑minute changes.</p></div>
    </div>
</section>

<!-- Features Section (abbreviated) -->
<section id="features">
    <div class="section-header"><h2>Designed for Everyone</h2><p>Teachers & students benefit equally</p></div>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px,1fr)); gap:2rem; max-width:1200px; margin:0 auto;">
        <div class="glass-card"><i class="fas fa-calendar-check"></i><h3>No Student Gaps</h3><p>Continuous class flow, better concentration.</p></div>
        <div class="glass-card"><i class="fas fa-balance-scale"></i><h3>Balanced Workload</h3><p>Fair distribution for teachers across days.</p></div>
        <div class="glass-card"><i class="fas fa-robot"></i><h3>AI Optimization</h3><p>Constraint‑based scoring + automatic conflict resolution.</p></div>
        <div class="glass-card"><i class="fas fa-chart-line"></i><h3>Live Reports</h3><p>Export PDF/Excel, view conflicts instantly.</p></div>
    </div>
</section>

<!-- Footer / Mac Dock (only navigation) -->
<div class="mac-dock">
    <div class="dock-items">
        <a href="index.php" class="dock-item active" data-label="Home"><i class="fas fa-home"></i></a>
        <a href="login.php" class="dock-item" data-label="Login"><i class="fas fa-sign-in-alt"></i></a>
        <a href="register.php" class="dock-item" data-label="Register"><i class="fas fa-user-plus"></i></a>
        <a href="modules/department/list.php" class="dock-item" data-label="Departments"><i class="fas fa-building"></i></a>
        <a href="modules/batch/list.php" class="dock-item" data-label="Batches"><i class="fas fa-users"></i></a>
        <a href="modules/course/list.php" class="dock-item" data-label="Courses"><i class="fas fa-book"></i></a>
        <a href="teacher/index.php" class="dock-item" data-label="Teachers"><i class="fas fa-chalkboard-teacher"></i></a>
        <a href="modules/routine/view.php" class="dock-item" data-label="Routine"><i class="fas fa-calendar-week"></i></a>
        <?php if (isset($_SESSION['user_id'])): ?>
            <a href="logout.php" class="dock-item" data-label="Logout"><i class="fas fa-sign-out-alt"></i></a>
        <?php endif; ?>
    </div>
</div>

<!-- Simple animation for stats (optional) -->
<script>
    // Animate stat numbers (just for visual effect)
    const statNumbers = document.querySelectorAll('.stat-card h3');
    statNumbers.forEach(el => {
        const final = parseInt(el.innerText);
        let current = 0;
        const step = Math.ceil(final / 30);
        const timer = setInterval(() => {
            current += step;
            if (current >= final) {
                el.innerText = final;
                clearInterval(timer);
            } else {
                el.innerText = current;
            }
        }, 30);
    });
</script>

</body>
</html>