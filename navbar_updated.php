<?php
// Determine current page for active link highlighting
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <a class="navbar-brand ml-4" href="#">QR Code Attendance System</a>
    <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarSupportedContent" 
        aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navbarSupportedContent">
        <ul class="navbar-nav mr-auto">
            <li class="nav-item <?= ($currentPage == 'index.php') ? 'active' : '' ?>">
                <a class="nav-link" href="./index.php">
                    <i class="fas fa-home mr-1"></i> Dashboard
                </a>
            </li>
            <li class="nav-item <?= ($currentPage == 'attendance.php') ? 'active' : '' ?>">
                <a class="nav-link" href="./attendance.php">
                    <i class="fas fa-qrcode mr-1"></i> Attendance Scanner
                </a>
            </li>
            <li class="nav-item <?= ($currentPage == 'masterlist.php') ? 'active' : '' ?>">
                <a class="nav-link" href="./masterlist.php">
                    <i class="fas fa-user-graduate mr-1"></i> List of Students
                </a>
            </li>
            <li class="nav-item <?= ($currentPage == 'archive-manager.php') ? 'active' : '' ?>">
                <a class="nav-link" href="./archive-manager.php">
                    <i class="fas fa-archive mr-1"></i> Archive
                </a>
            </li>
        </ul>
        <ul class="navbar-nav ml-auto">
            <?php if (isset($_SESSION['user_id'])): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-toggle="dropdown">
                        <i class="fas fa-user"></i> <?= htmlspecialchars($_SESSION['full_name']) ?>
                    </a>
                    <div class="dropdown-menu dropdown-menu-right">
                        <a class="dropdown-item" href="landing_page.php">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </li>
            <?php else: ?>
                <li class="nav-item">
                    <a class="nav-link" href="login.php">Login</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="register.php">Register</a>
                </li>
            <?php endif; ?>
        </ul>
    </div>
</nav>