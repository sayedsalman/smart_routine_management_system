<?php
session_start();

$student_name = $_SESSION['student_name'] ?? "Akash";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Routine - Smart Routine</title>
<link rel="stylesheet" href="style.css">
</head>
<body class="light-mode">
<div class="container">

<aside class="sidebar">
    <div class="sidebar-header">
        <h2>Smart Routine</h2>
        <button class="theme-toggle" id="themeToggle">🌙</button>
    </div>
    <ul class="nav-links">
        <li><a href="index.php">Dashboard</a></li>
        <li><a href="routine.php" class="active">My Routine</a></li>
        <li><a href="profile.php">Profile</a></li>
        <li><a href="logout.php">Logout</a></li>
    </ul>
</aside>

<main class="main-content">
    <header>
        <h1><?php echo htmlspecialchars($student_name); ?>'s Routine</h1>
        <p>Your weekly schedule.</p>
    </header>

    <section class="routine-table">
        <table>
            <thead>
                <tr>
                    <th>Day</th>
                    <th>8:30-9:55</th>
                    <th>10:00-11:25</th>
                    <th>11:30-12:55</th>
                    <th>1:30-2:55</th>
                    <th>3:00-4:25</th>
                    <th>4:30-6:00</th>
                </tr>
            </thead>

            <tbody>
                <tr>
                    <td>Saturday</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                </tr>

                <tr>
                    <td>Sunday</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                </tr>

                <tr>
                    <td>Monday</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                </tr>

                <tr>
                    <td>Tuesday</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                </tr>

                <tr>
                    <td>Wednesday</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                </tr>

                <tr>
                    <td>Thursday</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                    <td class="class-placeholder">—</td>
                </tr>

                <tr>
                    <td>Friday</td>
                    <td colspan="6" class="class-placeholder">OFF DAY</td>
                </tr>
            </tbody>
        </table>
    </section>
</main>

</div>
<script src="script.js"></script>
</body>
</html>