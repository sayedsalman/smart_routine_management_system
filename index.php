<?php
session_start();

$student_name = $_SESSION['student_name'] ?? "Akash";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard - Smart Routine</title>
<link rel="stylesheet" href="style.css">
</head>
<body class="light-mode">
<div class="container">

    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <h2>Smart Routine</h2>
            <button class="theme-toggle" id="themeToggle">🌙</button>
        </div>
        <ul class="nav-links">
            <li><a href="index.php" class="active">Dashboard</a></li>
            <li><a href="routine.php">My Routine</a></li>
            <li><a href="profile.php">Profile</a></li>
            <li><a href="logout.php">Logout</a></li>
        </ul>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <header>
            <h1>Welcome, <?php echo htmlspecialchars($student_name); ?>!</h1>
            <p>Manage your routine easily and stay organized.</p>
        </header>

        <!-- Quick Action Buttons -->
        <section class="quick-actions">
            <button class="action-btn">Add Routine</button>
            <button class="action-btn">View Calendar</button>
            <button class="action-btn">Download Schedule</button>
        </section>

        <!-- Notifications -->
        <section class="notifications">
            <h2>Notifications</h2>
            <div class="notification-card">📌 No upcoming tasks yet.</div>
            <div class="notification-card">🔔 Check your routine daily.</div>
        </section>

        <!-- Stats / Placeholder Cards -->
        <section class="cards">
            <div class="card">
                <h3>Today's Routine</h3>
                <p>Check your classes and tasks for today.</p>
            </div>
            <div class="card">
                <h3>Weekly Routine</h3>
                <p>View your weekly schedule at a glance.</p>
            </div>
            <div class="card">
                <h3>Profile</h3>
                <p>Edit and update your personal info.</p>
            </div>
        </section>
    </main>
</div>
<script src="script.js"></script>
</body>
</html>