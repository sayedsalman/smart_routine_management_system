<?php
$student_name = 'Akash';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Profile - Smart Routine</title>
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
        <li><a href="routine.php">My Routine</a></li>
        <li><a href="profile.php" class="active">Profile</a></li>
        <li><a href="logout.php">Logout</a></li>
    </ul>
</aside>

<main class="main-content">
    <header>
        <h1>Profile</h1>
        <p>Edit your personal information.</p>
    </header>

    <section class="profile-form">
        <form>
            <label>Name:</label>
            <input type="text" value="<?php echo htmlspecialchars($student_name); ?>">
            <label>Email:</label>
            <input type="email" value="akash@example.com">
            <label>Phone:</label>
            <input type="text" value="+880123456789">
            <button type="submit" class="action-btn">Save Changes</button>
        </form>
    </section>
</main>
</div>
<script src="script.js"></script>
</body>
</html>