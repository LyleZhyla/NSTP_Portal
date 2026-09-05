<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/conn/conn.php';
require_once __DIR__ . '/include/learning-materials.php';

if (!learningMaterialSessionActive($conn)) {
    header('Location: endpoint/logout.php?reason=timeout');
    exit;
}
$materialActor = getCurrentUserRecord($conn);
if (!$materialActor) {
    header('Location: endpoint/logout.php');
    exit;
}
$canUploadMaterials = canUploadLearningMaterials($materialActor);
$materialFlash = $_SESSION['learning_material_flash'] ?? null;
$materialOld = $_SESSION['learning_material_old'] ?? [];
unset($_SESSION['learning_material_flash'], $_SESSION['learning_material_old']);
if ($canUploadMaterials && empty($_SESSION['learning_material_csrf'])) {
    $_SESSION['learning_material_csrf'] = bin2hex(random_bytes(32));
}
$materials = [];
$materialsError = false;
$materialPageCount = 1;
$materialPage = max(1, (int) (filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
try {
    ensureLearningMaterialsTable($conn);
    $visibility = learningMaterialAccessSql(learningMaterialViewer($conn, $materialActor));
    $countStmt = $conn->prepare('SELECT COUNT(*) FROM tbl_learning_materials m WHERE ' . $visibility['sql']);
    $countStmt->execute($visibility['params']);
    $materialCount = (int) $countStmt->fetchColumn();
    $materialPageCount = max(1, (int) ceil($materialCount / 20));
    $materialPage = min($materialPage, $materialPageCount);
    $offset = ($materialPage - 1) * 20;
    $listStmt = $conn->prepare("SELECT m.material_id, m.is_open, m.title, m.description, m.original_name, m.file_size, m.created_at, m.uploaded_by, m.audience_components, m.audience_rotc_levels, u.full_name AS uploader_name
        FROM tbl_learning_materials m LEFT JOIN tbl_users u ON u.user_id = m.uploaded_by
        WHERE {$visibility['sql']} ORDER BY m.created_at DESC, m.material_id DESC LIMIT 20 OFFSET {$offset}");
    $listStmt->execute($visibility['params']);
    $materials = $listStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $error) {
    $materialsError = true;
    error_log('Learning materials list failed: ' . $error->getMessage());
}
function materialEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

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
        .material-description { white-space: pre-wrap; overflow-wrap: anywhere; }
        .material-name { overflow-wrap: anywhere; }
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
                            <?php include __DIR__ . '/include/assessment-list.php'; ?>
                        </div>
                        <div id="learning-materials-panel" role="tabpanel" aria-labelledby="learning-materials-tab" tabindex="0" <?= $activeTab !== 'learning-materials' ? 'hidden' : '' ?>>
                            <?php if ($materialFlash): ?>
                                <div class="alert alert-<?= $materialFlash['type'] === 'success' ? 'success' : 'danger' ?>" role="alert"><?= materialEscape($materialFlash['message']) ?></div>
                            <?php endif; ?>
                            <?php if ($materialsError): ?>
                                <div class="alert alert-warning" role="alert">Learning materials are temporarily unavailable. Please try again later.</div>
                            <?php else: ?>
                            <?php if ($canUploadMaterials): ?>
                            <div class="card card-outline card-success mb-4">
                                <div class="card-header"><h2 class="card-title"><i class="fas fa-upload mr-2" aria-hidden="true"></i>Upload Material</h2></div>
                                <form action="endpoint/upload-learning-material.php" method="post" enctype="multipart/form-data" id="material-upload-form" data-max-size="<?= learningMaterialUploadLimit() ?>">
                                    <div class="card-body">
                                        <p class="text-muted">Choose which components can see and download this material.</p>
                                        <input type="hidden" name="csrf_token" value="<?= materialEscape($_SESSION['learning_material_csrf']) ?>">
                                        <div class="form-group">
                                            <label for="material-title">Title <span class="text-danger" aria-hidden="true">*</span></label>
                                            <input class="form-control" type="text" id="material-title" name="title" maxlength="180" required value="<?= materialEscape($materialOld['title'] ?? '') ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="material-description">Description <span class="text-muted font-weight-normal">(optional)</span></label>
                                            <textarea class="form-control" id="material-description" name="description" rows="3" maxlength="5000"><?= materialEscape($materialOld['description'] ?? '') ?></textarea>
                                        </div>
                                        <div class="form-group mb-0">
                                            <?php
                                            $audienceFormId = 'upload';
                                            $audienceComponents = [];
                                            $audienceLevels = [];
                                            include __DIR__ . '/include/learning-material-audience-form.php';
                                            ?>
                                            <label for="material-file">File <span class="text-danger" aria-hidden="true">*</span></label>
                                            <input class="form-control-file" type="file" id="material-file" name="material" accept=".pdf,.docx,.pptx,.xlsx,.txt,.png,.jpg,.jpeg,.mp4,.webm,.mov" aria-describedby="material-file-help" required>
                                            <small id="material-file-help" class="form-text text-muted">PDF, DOCX, PPTX, XLSX, TXT, PNG, JPG, MP4, WebM, or MOV. Maximum <?= materialEscape(learningMaterialSize(learningMaterialUploadLimit())) ?> per file. Keep this page open until the upload finishes. Office macros are not supported.</small>
                                        </div>
                                    </div>
                                    <div class="card-footer">
                                        <button class="btn btn-success" type="submit" id="material-upload-button" disabled><i class="fas fa-upload mr-1" aria-hidden="true"></i> Upload Material</button>
                                        <button class="btn btn-outline-secondary" type="button" id="material-upload-cancel" hidden>Cancel Upload</button>
                                        <span id="material-upload-status" class="ml-2" role="status"></span>
                                        <progress id="material-upload-progress" class="w-100 mt-2" value="0" max="100" aria-label="Upload progress" hidden></progress>
                                        <noscript><p class="text-danger mt-2">Enable JavaScript to upload learning materials.</p></noscript>
                                    </div>
                                </form>
                            </div>
                            <?php endif; ?>
                            <?php if (empty($materials)): ?>
                            <div class="learning-empty">
                                <span class="learning-empty-icon"><i class="fas fa-book-reader" aria-hidden="true"></i></span>
                                <h2 class="h4">No learning materials yet</h2>
                                <p class="text-muted">There are no modules, reading materials, or lesson resources available at this time.</p>
                            </div>
                            <?php else: ?>
                            <h2 class="h5 mb-3">Available Materials <span class="text-muted">(<?= $materialCount ?>)</span></h2>
                            <?php foreach ($materials as $material): ?>
                            <article class="border rounded p-3 mb-3">
                                <h3 class="h5 material-name"><?= materialEscape($material['title']) ?></h3>
                                <p class="small text-muted material-audience-label">Visible to: <?= materialEscape(learningMaterialAudienceLabel($material)) ?></p>
                                <?php if ($material['description'] !== ''): ?>
                                    <p class="material-description"><?= materialEscape($material['description']) ?></p>
                                <?php endif; ?>
                                <p class="text-muted small mb-2 material-name">
                                    <?= materialEscape($material['original_name']) ?> &middot; <?= materialEscape(learningMaterialSize($material['file_size'])) ?><br>
                                    Uploaded by <?= materialEscape($material['uploader_name'] ?: 'Staff') ?> &middot; <?= materialEscape(date('M j, Y, g:i A', strtotime($material['created_at']))) ?>
                                </p>
                                <?php if (learningMaterialVideoMime($material['original_name'])): ?>
                                <video class="w-100 mb-2" style="max-height:480px;background:#111" controls preload="none" playsinline aria-label="<?= materialEscape($material['title']) ?>">
                                    <source src="endpoint/download-learning-material.php?id=<?= (int) $material['material_id'] ?>&amp;play=1" type="<?= materialEscape(learningMaterialVideoMime($material['original_name'])) ?>">
                                    Your browser does not support video playback. Use Download below.
                                </video>
                                <p class="small text-muted">If this video cannot play in your browser, use Download to watch it on your device.</p>
                                <?php endif; ?>
                                <a class="btn btn-outline-success btn-sm" href="endpoint/download-learning-material.php?id=<?= (int) $material['material_id'] ?>" aria-label="<?= materialEscape('Download ' . $material['title']) ?>"><i class="fas fa-download mr-1" aria-hidden="true"></i> Download</a>
                                <?php if ($materialActor['role'] !== 'student'): ?>
                                <p class="small mt-2 material-availability-label"><?= (int)$material['is_open'] ? 'Open to eligible students' : 'Closed to students' ?></p>
                                <?php endif; ?>
                                <?php if ($canUploadMaterials && ($materialActor['role'] === 'super_admin' || (int) $material['uploaded_by'] === (int) $materialActor['user_id'])): ?>
                                <form class="material-availability mt-3" action="endpoint/upload-learning-material.php" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= materialEscape($_SESSION['learning_material_csrf']) ?>">
                                    <input type="hidden" name="action" value="set_availability">
                                    <input type="hidden" name="material_id" value="<?= (int)$material['material_id'] ?>">
                                    <div class="custom-control custom-switch">
                                        <input type="checkbox" role="switch" class="custom-control-input" id="material-open-<?= (int)$material['material_id'] ?>" <?= (int)$material['is_open'] ? 'checked' : '' ?>>
                                        <label class="custom-control-label" for="material-open-<?= (int)$material['material_id'] ?>">Allow student access</label>
                                    </div>
                                    <small class="availability-status d-block" role="status">Turn off to close this material to students.</small>
                                </form>
                                <details class="mt-3">
                                    <summary>Change audience</summary>
                                    <form class="material-audience-edit mt-3" action="endpoint/upload-learning-material.php" method="post">
                                        <input type="hidden" name="csrf_token" value="<?= materialEscape($_SESSION['learning_material_csrf']) ?>">
                                        <input type="hidden" name="action" value="update_audience">
                                        <input type="hidden" name="material_id" value="<?= (int) $material['material_id'] ?>">
                                        <?php
                                        $audienceFormId = 'edit-' . (int) $material['material_id'];
                                        $audienceComponents = $material['audience_components'] === null ? ['CWTS', 'LTS', 'ROTC'] : explode(',', $material['audience_components']);
                                        $audienceLevels = $material['audience_components'] === null ? getRotcMsLevels() : explode(',', $material['audience_rotc_levels'] ?? '');
                                        include __DIR__ . '/include/learning-material-audience-form.php';
                                        ?>
                                        <button class="btn btn-success btn-sm" type="submit">Save Audience</button>
                                        <span class="audience-save-status ml-2" role="status"></span>
                                    </form>
                                </details>
                                <form class="material-delete mt-3" action="endpoint/upload-learning-material.php" method="post">
                                    <input type="hidden" name="csrf_token" value="<?= materialEscape($_SESSION['learning_material_csrf']) ?>">
                                    <input type="hidden" name="action" value="delete_material">
                                    <input type="hidden" name="material_id" value="<?= (int)$material['material_id'] ?>">
                                    <button class="btn btn-outline-danger btn-sm" type="submit"><i class="fas fa-trash-alt mr-1" aria-hidden="true"></i> Delete Material</button>
                                    <span class="material-delete-status ml-2" role="status"></span>
                                </form>
                                <?php endif; ?>
                            </article>
                            <?php endforeach; ?>
                            <?php if ($materialPageCount > 1): ?>
                            <nav class="d-flex align-items-center justify-content-between" aria-label="Learning materials pages">
                                <span>Page <?= $materialPage ?> of <?= $materialPageCount ?></span>
                                <div>
                                    <?php if ($materialPage > 1): ?><a class="btn btn-outline-secondary btn-sm" href="?tab=learning-materials&amp;page=<?= $materialPage - 1 ?>">Previous</a><?php endif; ?>
                                    <?php if ($materialPage < $materialPageCount): ?><a class="btn btn-outline-secondary btn-sm" href="?tab=learning-materials&amp;page=<?= $materialPage + 1 ?>">Next</a><?php endif; ?>
                                </div>
                            </nav>
                            <?php endif; ?>
                            <?php endif; ?>
                            <?php endif; ?>
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
<script src="include/material-api.js?v=<?= (int) filemtime(__DIR__ . '/include/material-api.js') ?>"></script>
<script src="include/learning-material-audience.js?v=<?= (int) filemtime(__DIR__ . '/include/learning-material-audience.js') ?>"></script>
<script src="include/learning-material-upload.js?v=<?= (int) filemtime(__DIR__ . '/include/learning-material-upload.js') ?>"></script>
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
