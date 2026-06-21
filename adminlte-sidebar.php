<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$inactivityTimeoutMinutes = 5;
if (isset($_SESSION['user_id'])) {
    if (!isset($conn)) {
        include_once('./conn/conn.php');
    }
    if (!function_exists('getSystemSetting')) {
        require_once './include/user-permissions.php';
    }
    if (!function_exists('profilePictureUrl')) {
        require_once './include/profile-picture-utils.php';
    }

    $inactivityTimeoutMinutes = (int) getSystemSetting($conn, 'inactivity_timeout_minutes', '5');
    if ($inactivityTimeoutMinutes <= 0) {
        $inactivityTimeoutMinutes = 5;
    }

    $now = time();
    $timeoutSeconds = $inactivityTimeoutMinutes * 60;
    if (isset($_SESSION['last_activity']) && ($now - (int) $_SESSION['last_activity']) > $timeoutSeconds) {
        if (!headers_sent()) {
            header("Location: ./endpoint/logout.php?reason=timeout");
        } else {
            echo '<script>window.location.href="./endpoint/logout.php?reason=timeout";</script>';
        }
        exit();
    }
    $_SESSION['last_activity'] = $now;
}

// Determine current page for active link highlighting
$currentPage = basename($_SERVER['PHP_SELF']);
$isStaffUser = isset($_SESSION['role']) && in_array($_SESSION['role'], ['super_admin', 'coordinator', 'facilitator'], true);

// Force refresh profile picture from database if needed
if (isset($_SESSION['user_id'])) {
    // You might want to add this to ensure the session has the latest profile picture
    // This is optional - uncomment if you want to ensure the sidebar always shows the latest image
    /*
    include_once('./conn/conn.php');
    $stmt = $conn->prepare("SELECT profile_picture FROM tbl_users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $profile_pic = $stmt->fetchColumn();
    if ($profile_pic && $profile_pic != ($_SESSION['profile_picture'] ?? '')) {
        $_SESSION['profile_picture'] = $profile_pic;
    }
    */
}
?>

<!-- Main Sidebar Container -->
<aside class="main-sidebar sidebar-dark-primary elevation-4">
    <!-- Brand Logo - UPDATED WITH NSTP LOGO -->
    <a href="landing_page.php" class="brand-link">
        <img src="include/logo.png" alt="NSTP Logo" class="brand-image" style="width: 35px; height: 35px; object-fit: contain; border-radius: 8px; border: 2px solid rgba(255,255,255,0.2); background: white; padding: 3px;">
        <span class="brand-text font-weight-bold">TAU NSTP </span>
        <span class="brand-text-sm font-weight-light d-block d-md-none ml-2" style="font-size: 12px;">NSTP</span>
    </a>

    <!-- Sidebar -->
    <div class="sidebar">
        <!-- User Panel with better mobile responsiveness -->
        <div class="user-panel mt-3 pb-3 mb-3 d-flex align-items-center">
            <div class="image">
                <?php 
                // Check if user has profile picture - IMPROVED CHECKING
                $hasProfilePic = false;
                $profilePicPath = '';
                
                // Check session first
                if (isset($_SESSION['profile_picture']) && !empty($_SESSION['profile_picture'])) {
                    $profilePicPath = $_SESSION['profile_picture'];
                    // Check if file exists
                    if (profilePictureExists($profilePicPath, __DIR__)) {
                        $hasProfilePic = true;
                    }
                }
                
                // If no profile pic in session or file doesn't exist, try to get from database
                if (!$hasProfilePic && isset($_SESSION['user_id'])) {
                    // Include database connection if not already included
                    if (!isset($conn)) {
                        include_once('./conn/conn.php');
                    }
                    
                    $stmt = $conn->prepare("SELECT profile_picture FROM tbl_users WHERE user_id = ?");
                    $stmt->execute([$_SESSION['user_id']]);
                    $dbProfilePic = $stmt->fetchColumn();
                    
                    if ($dbProfilePic && profilePictureExists($dbProfilePic, __DIR__)) {
                        $hasProfilePic = true;
                        $profilePicPath = $dbProfilePic;
                        // Update session for future use
                        $_SESSION['profile_picture'] = $dbProfilePic;
                    }
                }
                
                if ($hasProfilePic): ?>
                    <img src="<?php echo htmlspecialchars(profilePictureUrl($profilePicPath, __DIR__)); ?>" 
                         class="img-circle elevation-2" 
                         alt="User Image"
                         style="width: 40px; height: 40px; object-fit: cover; border-radius: 50%; border: 2px solid #fff;">
                <?php else: ?>
                    <div class="img-circle elevation-2" style="width: 40px; height: 40px; background: linear-gradient(135deg, #0f5132, #198754); display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; border-radius: 50%; border: 2px solid #fff;">
                        <?php 
                        $initials = '';
                        if (isset($_SESSION['full_name'])) {
                            $nameParts = explode(' ', $_SESSION['full_name']);
                            $initials = strtoupper(substr($nameParts[0], 0, 1));
                            if (isset($nameParts[1])) {
                                $initials .= strtoupper(substr($nameParts[1], 0, 1));
                            }
                        } else {
                            $initials = 'A';
                        }
                        echo $initials;
                        ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="info">
                <a href="profile.php" class="d-block">
                    <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Administrator'); ?>
                    <?php if (isset($_SESSION['role'])): ?>
                        <small class="text-muted d-block">
                            <i class="fas fa-shield-alt mr-1"></i>
                            <?php echo ucfirst(str_replace('_', ' ', $_SESSION['role'])); ?>
                        </small>
                    <?php endif; ?>
                </a>
            </div>
        </div>

        <!-- Sidebar Menu -->
        <nav class="mt-2">
            <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
                <!-- Dashboard -->
                <?php if ($isStaffUser): ?>
                <li class="nav-item">
                    <a href="index.php" class="nav-link <?= ($currentPage == 'index.php') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-tachometer-alt"></i>
                        <p>Dashboard</p>
                    </a>
                </li>
                <?php elseif (isset($_SESSION['role']) && $_SESSION['role'] === 'student'): ?>
                <li class="nav-item">
                    <a href="student-dashboard.php" class="nav-link <?= ($currentPage == 'student-dashboard.php') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-tachometer-alt"></i>
                        <p>Dashboard</p>
                    </a>
                </li>
                <?php endif; ?>

                <!-- Attendance Scanner -->
                <?php if ($isStaffUser && isset($_SESSION['role']) && $_SESSION['role'] !== 'super_admin'): ?>
                <li class="nav-item">
                    <a href="attendance.php" class="nav-link <?= ($currentPage == 'attendance.php') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-qrcode"></i>
                        <p>Attendance Scanner</p>
                    </a>
                </li>
                <?php endif; ?>

                <!-- Student Management -->
                <?php if ($isStaffUser && (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['super_admin', 'coordinator'], true))): ?>
                <li class="nav-item">
                    <a href="masterlist.php" class="nav-link <?= ($currentPage == 'masterlist.php') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-user-graduate"></i>
                        <p>Student Management</p>
                    </a>
                </li>

                <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['super_admin', 'coordinator'], true)): ?>
                <li class="nav-item">
                    <a href="student-registrations.php" class="nav-link <?= ($currentPage == 'student-registrations.php') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-clipboard-list"></i>
                        <p>Public Registrations</p>
                    </a>
                </li>
                <?php endif; ?>

                <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['super_admin', 'coordinator', 'facilitator'], true)): ?>
                <li class="nav-item">
                    <a href="downloadables.php" class="nav-link <?= ($currentPage == 'downloadables.php') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-download"></i>
                        <p>Downloadables</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="announcements.php" class="nav-link <?= ($currentPage == 'announcements.php') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-bullhorn"></i>
                        <p>Announcements</p>
                    </a>
                </li>
                <?php endif; ?>

                <!-- Archive Manager -->
                <li class="nav-item">
                    <a href="archive-manager.php" class="nav-link <?= ($currentPage == 'archive-manager.php') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-archive"></i>
                        <p>Archive Manager</p>
                    </a>
                </li>
                <?php endif; ?>

                <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['coordinator', 'facilitator'], true)): ?>
                <li class="nav-item">
                    <a href="grades.php" class="nav-link <?= ($currentPage == 'grades.php') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-calculator"></i>
                        <p>Grade Computation</p>
                    </a>
                </li>
                <?php endif; ?>

                <!-- User Management -->
                <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['super_admin', 'coordinator'], true)): ?>
                <?php $isUserManagementOpen = in_array($currentPage, ['admin-management.php', 'masterlist.php'], true); ?>
                <li class="nav-item <?= $isUserManagementOpen ? 'menu-open' : '' ?>">
                    <a href="#" class="nav-link <?= $isUserManagementOpen ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-user-shield"></i>
                        <p>
                            User Management
                            <i class="right fas fa-angle-left"></i>
                        </p>
                    </a>
                    <ul class="nav nav-treeview">
                        <li class="nav-item">
                            <a href="admin-management.php" class="nav-link <?= ($currentPage == 'admin-management.php') ? 'active' : '' ?>">
                                <i class="far fa-circle nav-icon"></i>
                                <p>Facilitator Management</p>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="masterlist.php" class="nav-link <?= ($currentPage == 'masterlist.php') ? 'active' : '' ?>">
                                <i class="far fa-circle nav-icon"></i>
                                <p>Student Management</p>
                            </a>
                        </li>
                    </ul>
                </li>
                <?php endif; ?>

                <!-- Divider -->
                <li class="nav-header">SYSTEM</li>

                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'student'): ?>
                <li class="nav-item">
                    <a href="component.php" class="nav-link <?= ($currentPage == 'component.php') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-layer-group"></i>
                        <p>Component</p>
                    </a>
                </li>
                <?php endif; ?>

                <!-- My Profile -->
                <li class="nav-item">
                    <a href="profile.php" class="nav-link <?= ($currentPage == 'profile.php') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-user-circle"></i>
                        <p>My Profile</p>
                    </a>
                </li>

                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin'): ?>
                <li class="nav-item">
                    <a href="system-logs.php" class="nav-link <?= ($currentPage == 'system-logs.php') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-clipboard-list"></i>
                        <p>System Logs</p>
                    </a>
                </li>
                <?php endif; ?>
                <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['super_admin', 'coordinator'], true)): ?>
                <li class="nav-item">
                    <a href="attendance-settings.php" class="nav-link <?= ($currentPage == 'attendance-settings.php') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-user-clock"></i>
                        <p>Attendance Settings</p>
                    </a>
                </li>
                <?php endif; ?>
                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin'): ?>
                <li class="nav-item">
                    <a href="system-maintenance.php" class="nav-link <?= ($currentPage == 'system-maintenance.php') ? 'active' : '' ?>">
                        <i class="nav-icon fas fa-database"></i>
                        <p>System Maintenance</p>
                    </a>
                </li>
                <?php endif; ?>
                
                <!-- Logout -->
                <li class="nav-item">
                    <a href="./endpoint/logout.php" class="nav-link text-danger">
                        <i class="nav-icon fas fa-sign-out-alt"></i>
                        <p>Logout</p>
                    </a>
                </li>
            </ul>
        </nav>
    </div>
</aside>

<!-- Custom Styles for NSTP Branding and Responsiveness -->
<style>
    .main-sidebar {
        background: #082f21 !important;
    }

    .main-sidebar .sidebar {
        background: #082f21 !important;
    }

    /* NSTP Branding Styles */
    .brand-link {
        display: flex;
        align-items: center;
        padding: 15px 12px !important;
        border-bottom: 1px solid rgba(255, 255, 255, 0.14);
        background: #063f28 !important;
        transition: all 0.3s ease;
    }
    
    .brand-link:hover {
        background: #0f5132 !important;
    }
    
    .brand-image {
        margin-right: 10px;
        float: left;
        line-height: 0.8;
        object-fit: contain;
        background: white;
        padding: 3px;
        transition: transform 0.3s ease;
    }
    
    .brand-link:hover .brand-image {
        transform: scale(1.05);
    }
    
    .brand-text {
        color: white !important;
        font-size: 1.2rem;
        font-weight: 700 !important;
        letter-spacing: 0.5px;
    }
    
    .brand-text-sm {
        display: none;
        color: rgba(255, 255, 255, 0.9);
        font-size: 12px;
    }
    
    /* User Panel Improvements */
    .user-panel {
        border-bottom: 1px solid rgba(255, 255, 255, 0.14);
        padding-bottom: 15px !important;
        margin-bottom: 15px !important;
    }
    
    .user-panel .info a {
        color: #f8fafc !important;
        font-weight: 600;
    }
    
    .user-panel .info a:hover {
        color: #f7c948 !important;
    }
    
    .user-panel .info small {
        font-size: 11px;
        color: #b7dfc6 !important;
    }
    
    /* Navigation Menu Styles */
    .nav-sidebar .nav-item > .nav-link {
        border-radius: 8px;
        color: #e8f6ee !important;
        padding: 11px 13px;
        margin: 2px 8px;
        transition: all 0.2s ease;
        border-left: 3px solid transparent;
    }
    
    .nav-sidebar .nav-item > .nav-link:hover {
        background: #124832 !important;
        color: #ffffff !important;
        border-left-color: #f7c948;
    }
    
    .nav-sidebar .nav-item > .nav-link.active {
        background: #ffffff !important;
        color: #0f5132 !important;
        border-left: 3px solid #f7c948 !important;
        box-shadow: 0 8px 18px rgba(0,0,0,0.22);
        font-weight: 700;
    }
    
    .nav-sidebar .nav-item > .nav-link.active i {
        color: #198754 !important;
    }
    
    .nav-sidebar .nav-link i {
        color: #b7dfc6 !important;
        margin-right: 10px;
        width: 20px;
        text-align: center;
    }
    
    .nav-sidebar .nav-link.active i {
        color: #198754 !important;
    }
    
    .nav-sidebar .nav-header {
        color: #9dcfb2 !important;
        font-size: 0.75rem;
        padding: 10px 15px 5px;
        text-transform: uppercase;
        letter-spacing: 1px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.14);
        margin-bottom: 5px;
    }
    
    /* Logout button special style */
    .nav-sidebar .nav-item:last-child .nav-link {
        border-top: 1px solid rgba(255, 255, 255, 0.14);
        margin-top: 5px;
    }
    
    .nav-sidebar .nav-item .text-danger {
        color: #ffb4b4 !important;
    }
    
    .nav-sidebar .nav-item .text-danger:hover {
        background: rgba(220, 53, 69, 0.28) !important;
        color: white !important;
    }
    
    /* RESPONSIVE FIXES - IMPROVED */
    @media (max-width: 992px) {
        .brand-text {
            font-size: 1rem;
        }
    }
    
    @media (max-width: 768px) {
        /* Sidebar collapsed state */
        .sidebar-collapse .main-sidebar {
            transform: translateX(-250px);
            width: 250px;
        }
        
        .sidebar-collapse .content-wrapper,
        .sidebar-collapse .main-footer {
            margin-left: 0;
        }
        
        .sidebar-open .main-sidebar {
            transform: translateX(0);
            width: 250px;
        }
        
        /* Brand logo in mobile */
        .brand-link {
            padding: 12px 10px !important;
        }
        
        .brand-text {
            display: none !important;
        }
        
        .brand-text-sm {
            display: inline-block !important;
            margin-left: 5px;
        }
        
        .brand-image {
            width: 35px !important;
            height: 35px !important;
            margin-right: 5px;
        }
        
        /* User panel in mobile */
        .sidebar-collapse .user-panel .info {
            display: none;
        }
        
        .sidebar-open .user-panel .info {
            display: block;
        }
        
        .user-panel .image {
            margin-right: 0;
        }
        
        .sidebar-open .user-panel .image {
            margin-right: 10px;
        }
        
        /* Menu items in mobile */
        .nav-sidebar .nav-link p {
            display: none;
        }
        
        .sidebar-open .nav-sidebar .nav-link p {
            display: inline-block;
        }
        
        .nav-sidebar .nav-link i {
            margin-right: 0;
            font-size: 1.2rem;
        }
        
        .sidebar-open .nav-sidebar .nav-link i {
            margin-right: 10px;
        }
        
        /* Header text adjustments */
        .nav-header {
            text-align: center;
        }
        
        .sidebar-open .nav-header {
            text-align: left;
        }
    }
    
    @media (max-width: 576px) {
        .brand-link {
            padding: 10px 8px !important;
        }
        
        .brand-image {
            width: 30px !important;
            height: 30px !important;
        }
        
        .user-panel .image img,
        .user-panel .image div {
            width: 35px !important;
            height: 35px !important;
        }
    }
    
    /* Animation for sidebar toggle */
    .main-sidebar,
    .content-wrapper,
    .main-footer {
        transition: margin-left 0.3s ease, transform 0.3s ease;
    }
    
    /* Hide scrollbar when sidebar is collapsed on mobile */
    .sidebar-collapse .main-sidebar {
        overflow: hidden;
    }
    
    /* Active menu indicator */
    .nav-sidebar .nav-link.active::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        height: 100%;
        width: 3px;
        background: #ffc107;
    }
</style>

<!-- JavaScript for Responsive Sidebar -->
<script>
$(document).ready(function() {
    const inactivityTimeoutMs = <?php echo (int) $inactivityTimeoutMinutes; ?> * 60 * 1000;
    let inactivityTimer = null;
    let lastActivityPing = 0;

    function resetInactivityTimer() {
        clearTimeout(inactivityTimer);
        inactivityTimer = setTimeout(function() {
            window.location.href = './endpoint/logout.php?reason=timeout';
        }, inactivityTimeoutMs);

        const now = Date.now();
        if (now - lastActivityPing > 60000) {
            lastActivityPing = now;
            fetch('./endpoint/touch-session.php', { method: 'POST', credentials: 'same-origin' }).catch(function() {});
        }
    }

    ['click', 'mousemove', 'keydown', 'scroll', 'touchstart'].forEach(function(eventName) {
        document.addEventListener(eventName, resetInactivityTimer, { passive: true });
    });
    resetInactivityTimer();

    // Add active state to current page
    const currentPage = '<?php echo $currentPage; ?>';
    $('.nav-link').each(function() {
        const href = $(this).attr('href');
        if (href && href.indexOf(currentPage) > -1 && currentPage !== '') {
            $(this).addClass('active');
        }
    });
    
    // Responsive sidebar handling
    function handleSidebarResponsive() {
        const windowWidth = $(window).width();
        
        if (windowWidth < 768) {
            // Mobile: Auto collapse sidebar
            $('body').addClass('sidebar-collapse');
            $('body').removeClass('sidebar-open');
            
            // Remove inline styles that might break the layout
            $('.main-sidebar, .content-wrapper, .main-footer').removeAttr('style');
        } else {
            // Desktop: Show sidebar
            $('body').removeClass('sidebar-collapse sidebar-open');
        }
    }
    
    // Initial call
    handleSidebarResponsive();
    
    // Handle window resize
    let resizeTimer;
    $(window).resize(function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            handleSidebarResponsive();
        }, 250);
    });
    
    // Handle sidebar toggle button
    $('[data-widget="pushmenu"]').on('click', function(e) {
        e.preventDefault();
        
        if ($('body').hasClass('sidebar-collapse')) {
            $('body').removeClass('sidebar-collapse');
            $('body').addClass('sidebar-open');
        } else {
            $('body').addClass('sidebar-collapse');
            $('body').removeClass('sidebar-open');
        }
    });
    
    // Close sidebar when clicking on content on mobile
    $('.content-wrapper').on('click', function() {
        if ($(window).width() < 768 && $('body').hasClass('sidebar-open')) {
            $('body').addClass('sidebar-collapse');
            $('body').removeClass('sidebar-open');
        }
    });
    
    // Add tooltips for collapsed sidebar on mobile
    if ($(window).width() < 768) {
        $('.nav-link').each(function() {
            const title = $(this).find('p').text();
            if (title) {
                $(this).attr('title', title);
                $(this).attr('data-toggle', 'tooltip');
                $(this).attr('data-placement', 'right');
            }
        });
        
        // Initialize tooltips
        if ($.fn.tooltip) {
            $('[data-toggle="tooltip"]').tooltip();
        }
    }
    
    // Prevent tooltips from showing on desktop
    $(window).resize(function() {
        if ($(window).width() >= 768) {
            $('[data-toggle="tooltip"]').tooltip('disable');
        } else {
            $('[data-toggle="tooltip"]').tooltip('enable');
        }
    });
});
</script>
