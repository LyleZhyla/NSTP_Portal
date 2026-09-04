<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/conn/conn.php';

$activeTab = ($_GET['tab'] ?? '') === 'learning-materials' ? 'learning-materials' : 'assessment';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Learning Management - TAU NSTP</title>
    <?php include __DIR__ . '/include/theme-loader.php'; ?>
    <link rel="icon" type="image/png" href="include/logo.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="include/theme.css">
    <style>
        .learning-tabs { gap: .25rem; }
        .learning-tabs .nav-link { padding: .9rem 1.1rem; }
        .learning-tabs .nav-link.active { font-weight: 600; }
        .learning-tabs .nav-link:focus-visible { outline: 2px solid #198754; outline-offset: -3px; }
        .learning-empty { padding: 3rem 1rem; text-align: center; }
        .learning-empty-icon {
            display: inline-flex; align-items: center; justify-content: center;
            width: 72px; height: 72px; margin-bottom: 1.25rem;
            border-radius: 50%; background: rgba(25, 135, 84, .12); color: #198754; font-size: 1.8rem;
        }
        .dark-mode .learning-empty-icon { color: #75dba8; }
        .learning-empty p { max-width: 440px; margin: .75rem auto 0; }
        @media (max-width: 575px) {
            .learning-tabs .nav-item { flex: 1; text-align: center; }
            .learning-tabs .nav-link { padding: .8rem .5rem; height: 100%; }
            .learning-empty { padding: 2rem .5rem; }
        }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-widget="pushmenu" href="#" role="button" aria-label="Toggle sidebar"><i class="fas fa-bars" aria-hidden="true"></i></a>
            </li>
        </ul>
        <ul class="navbar-nav ml-auto">
            <?php include __DIR__ . '/include/header-notifications.php'; ?>
            <?php include __DIR__ . '/include/theme-toggle.php'; ?>
            <?php include __DIR__ . '/include/theme-toggle-slider.php'; ?>
        </ul>
    </nav>
    <?php include __DIR__ . '/adminlte-sidebar.php'; ?>

    <main class="content-wrapper">
        <section class="content-header">
            <div class="container-fluid">
                <h1><i class="fas fa-book-open mr-2" aria-hidden="true"></i>Learning Management</h1>
                <p class="text-muted mt-2 mb-0">Your space for NSTP assessments and learning materials.</p>
            </div>
        </section>
        <section class="content">
            <div class="container-fluid">
                <div class="card card-outline card-success">
                    <div class="card-header p-0 pt-1 border-bottom-0">
                        <ul class="nav nav-tabs learning-tabs" id="learning-tabs" role="tablist" aria-label="Learning Management">
                            <li class="nav-item" role="presentation">
                                <a class="nav-link <?= $activeTab === 'assessment' ? 'active' : '' ?>" id="assessment-tab" href="?tab=assessment" role="tab" aria-controls="assessment-panel" aria-selected="<?= $activeTab === 'assessment' ? 'true' : 'false' ?>" tabindex="<?= $activeTab === 'assessment' ? '0' : '-1' ?>">
                                    <i class="fas fa-clipboard-check mr-2" aria-hidden="true"></i>Assessment
                                </a>
                            </li>
                            <li class="nav-item" role="presentation">
                                <a class="nav-link <?= $activeTab === 'learning-materials' ? 'active' : '' ?>" id="learning-materials-tab" href="?tab=learning-materials" role="tab" aria-controls="learning-materials-panel" aria-selected="<?= $activeTab === 'learning-materials' ? 'true' : 'false' ?>" tabindex="<?= $activeTab === 'learning-materials' ? '0' : '-1' ?>">
                                    <i class="fas fa-book-reader mr-2" aria-hidden="true"></i>Learning Materials
                                </a>
                            </li>
                        </ul>
                    </div>
                    <div class="card-body">
                        <div id="assessment-panel" role="tabpanel" aria-labelledby="assessment-tab" tabindex="0" <?= $activeTab !== 'assessment' ? 'hidden' : '' ?>>
                            <div class="learning-empty">
                                <span class="learning-empty-icon"><i class="fas fa-clipboard-check" aria-hidden="true"></i></span>
                                <h2 class="h4">No assessments yet</h2>
                                <p class="text-muted">There are no assessments available at this time.</p>
                            </div>
                        </div>
                        <div id="learning-materials-panel" role="tabpanel" aria-labelledby="learning-materials-tab" tabindex="0" <?= $activeTab !== 'learning-materials' ? 'hidden' : '' ?>>
                            <div class="learning-empty">
                                <span class="learning-empty-icon"><i class="fas fa-book-reader" aria-hidden="true"></i></span>
                                <h2 class="h4">No learning materials yet</h2>
                                <p class="text-muted">There are no modules, reading materials, or lesson resources available at this time.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>
    <?php include __DIR__ . '/footer.php'; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script>
(function () {
    const tabs = Array.from(document.querySelectorAll('#learning-tabs [role="tab"]'));
    function selectTab(tab) {
        tabs.forEach(function (item) {
            const selected = item === tab;
            item.classList.toggle('active', selected);
            item.setAttribute('aria-selected', String(selected));
            item.tabIndex = selected ? 0 : -1;
            document.getElementById(item.getAttribute('aria-controls')).hidden = !selected;
        });
    }
    tabs.forEach(function (tab, index) {
        tab.addEventListener('click', function (event) {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            selectTab(tab);
            history.replaceState(null, '', tab.href);
        });
        tab.addEventListener('keydown', function (event) {
            let next;
            if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
            else if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
            else if (event.key === 'Home') next = 0;
            else if (event.key === 'End') next = tabs.length - 1;
            else if (event.key === ' ') next = index;
            else return;
            event.preventDefault();
            tabs[next].focus();
            tabs[next].click();
        });
    });
})();
</script>
</body>
</html>
