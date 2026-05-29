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
                <a class="nav-link" href="./index.php">Home</a>
            </li>
            <li class="nav-item <?= ($currentPage == 'masterlist.php') ? 'active' : '' ?>">
                <a class="nav-link" href="./masterlist.php">List of Students</a>
            </li>
            <li class="nav-item <?= ($currentPage == 'archive-manager.php') ? 'active' : '' ?>">
                <a class="nav-link" href="./archive-manager.php">Archive</a>
            </li>
        </ul>
        <ul class="navbar-nav ml-auto">
            <li class="nav-item mr-3">
                <a class="nav-link" href="#">Logout</a>
            </li>
        </ul>
    </div>
</nav>
