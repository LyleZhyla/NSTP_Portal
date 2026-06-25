<?php
session_start();
include('./conn/conn.php');
require_once './include/user-permissions.php';
require_once './include/landing-content.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || !canAccessAdminManagement($currentUser['role'])) {
    header("Location: index.php");
    exit();
}

ensureLandingStaffTable($conn);
ensureLandingSectionsTable($conn);

$message = '';
$messageType = 'success';

function landingUploadPhoto($fieldName) {
    return uploadLandingStaffPhoto($fieldName, __DIR__);
}

function cleanLandingText($value, $maxLength) {
    return substr(trim((string) $value), 0, $maxLength);
}

function landingInitials($name) {
    $words = preg_split('/\s+/', trim((string) $name));
    $letters = '';

    foreach ($words as $word) {
        if ($word !== '') {
            $letters .= strtoupper(substr($word, 0, 1));
        }

        if (strlen($letters) >= 2) {
            break;
        }
    }

    return $letters ?: 'NS';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'section_update') {
            saveLandingSection($conn, [
                'section_key' => $_POST['section_key'] ?? '',
                'kicker' => $_POST['section_kicker'] ?? '',
                'title' => $_POST['section_title'] ?? '',
                'body' => $_POST['section_body'] ?? '',
                'payload' => $_POST['section_payload'] ?? '',
            ], $_SESSION['user_id'] ?? null);
            $message = 'Landing section updated.';
        } elseif ($action === 'add' || $action === 'update') {
            $fullName = cleanLandingText($_POST['full_name'] ?? '', 150);
            $positionTitle = cleanLandingText($_POST['position_title'] ?? '', 150);
            $program = normalizeLandingProgram($_POST['program'] ?? 'NSTP');
            $groupLabel = cleanLandingText($_POST['group_label'] ?? 'NSTP Office', 100);
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);
            $isVisible = isset($_POST['is_visible']) ? 1 : 0;
            $photoPath = cleanLandingText($_POST['photo_path'] ?? '', 255);
            $uploadedPhoto = landingUploadPhoto('photo_upload');

            if ($uploadedPhoto) {
                $photoPath = $uploadedPhoto;
            }

            if ($fullName === '' || $positionTitle === '') {
                throw new RuntimeException('Name and position/title are required.');
            }

            if ($action === 'add') {
                $stmt = $conn->prepare("
                    INSERT INTO tbl_landing_staff
                        (full_name, position_title, program, group_label, photo_path, sort_order, is_visible, created_by, updated_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $fullName,
                    $positionTitle,
                    $program,
                    $groupLabel ?: 'NSTP Office',
                    $photoPath ?: null,
                    $sortOrder,
                    $isVisible,
                    $_SESSION['user_id'] ?? null,
                    $_SESSION['user_id'] ?? null,
                ]);
                $message = 'Landing staff entry added.';
            } else {
                $entryId = (int) ($_POST['landing_staff_id'] ?? 0);
                if ($entryId <= 0) {
                    throw new RuntimeException('Invalid landing entry.');
                }

                $stmt = $conn->prepare("
                    UPDATE tbl_landing_staff
                    SET full_name = ?,
                        position_title = ?,
                        program = ?,
                        group_label = ?,
                        photo_path = ?,
                        sort_order = ?,
                        is_visible = ?,
                        updated_by = ?
                    WHERE landing_staff_id = ?
                ");
                $stmt->execute([
                    $fullName,
                    $positionTitle,
                    $program,
                    $groupLabel ?: 'NSTP Office',
                    $photoPath ?: null,
                    $sortOrder,
                    $isVisible,
                    $_SESSION['user_id'] ?? null,
                    $entryId,
                ]);
                $message = 'Landing staff entry updated.';
            }
        } elseif ($action === 'delete') {
            $entryId = (int) ($_POST['landing_staff_id'] ?? 0);
            if ($entryId <= 0) {
                throw new RuntimeException('Invalid landing entry.');
            }

            $stmt = $conn->prepare("DELETE FROM tbl_landing_staff WHERE landing_staff_id = ?");
            $stmt->execute([$entryId]);
            $message = 'Landing staff entry deleted.';
        }
    } catch (Throwable $error) {
        $message = $error->getMessage();
        $messageType = 'danger';
    }
}

$landingSections = getLandingSections($conn);
$staffEntries = getLandingStaff($conn, false);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Landing Page Content - TAU NSTP</title>
    <?php include('./include/theme-loader.php'); ?>
    <link rel="icon" type="image/png" href="include/logo.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="include/theme.css">
    <style>
        .content-wrapper { background: #f4f6f9; }
        .entry-photo {
            width: 54px;
            height: 54px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #dee2e6;
            background: #fff;
        }
        .entry-initials {
            width: 54px;
            height: 54px;
            display: grid;
            place-items: center;
            border-radius: 8px;
            background: #0f766e;
            color: #fff;
            font-weight: 700;
        }
        .table td { vertical-align: middle; }
        .form-control-sm { min-width: 120px; }
        .photo-path-input { min-width: 180px; }
        .name-input { min-width: 180px; }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
            </li>
        </ul>
        <ul class="navbar-nav ml-auto">
            <?php include './include/header-notifications.php'; ?>
        </ul>
    </nav>

    <?php include 'adminlte-sidebar.php'; ?>

    <div class="content-wrapper">
        <section class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-8">
                        <h1>Landing Page Content</h1>
                        <p class="text-muted mb-0">Edit the public NSTP staff and organizational chart fields shown on the landing page.</p>
                    </div>
                    <div class="col-sm-4 text-sm-right">
                        <a href="landing_page.php#staff" class="btn btn-outline-primary" target="_blank">
                            <i class="fas fa-eye mr-1"></i> Preview Landing
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <section class="content">
            <div class="container-fluid">
                <?php if ($message): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> alert-dismissible fade show">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-layer-group mr-2"></i>Landing Sections</h3>
                    </div>
                    <div class="card-body">
                        <div class="accordion" id="landingSectionAccordion">
                            <?php foreach ($landingSections as $sectionKey => $section): ?>
                                <?php
                                    $sectionId = 'landingSection' . preg_replace('/[^a-zA-Z0-9]/', '', $sectionKey);
                                    $payloadJson = isset($section['payload']) ? json_encode($section['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '';
                                ?>
                                <div class="card mb-2">
                                    <div class="card-header p-0" id="<?php echo htmlspecialchars($sectionId); ?>Header">
                                        <button class="btn btn-link btn-block text-left px-3 py-2" type="button" data-toggle="collapse" data-target="#<?php echo htmlspecialchars($sectionId); ?>" aria-expanded="<?php echo $sectionKey === 'hero' ? 'true' : 'false'; ?>">
                                            <strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $sectionKey))); ?></strong>
                                            <span class="text-muted ml-2"><?php echo htmlspecialchars($section['title'] ?? ''); ?></span>
                                        </button>
                                    </div>
                                    <div id="<?php echo htmlspecialchars($sectionId); ?>" class="collapse <?php echo $sectionKey === 'hero' ? 'show' : ''; ?>" data-parent="#landingSectionAccordion">
                                        <form method="POST">
                                            <input type="hidden" name="action" value="section_update">
                                            <input type="hidden" name="section_key" value="<?php echo htmlspecialchars($sectionKey); ?>">
                                            <div class="card-body">
                                                <div class="row">
                                                    <div class="col-md-3">
                                                        <div class="form-group">
                                                            <label>Kicker / Small Label</label>
                                                            <input type="text" name="section_kicker" class="form-control" value="<?php echo htmlspecialchars($section['kicker'] ?? ''); ?>">
                                                        </div>
                                                    </div>
                                                    <div class="col-md-9">
                                                        <div class="form-group">
                                                            <label>Title</label>
                                                            <input type="text" name="section_title" class="form-control" value="<?php echo htmlspecialchars($section['title'] ?? ''); ?>" required>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="form-group">
                                                    <label>Body Text</label>
                                                    <textarea name="section_body" class="form-control" rows="3"><?php echo htmlspecialchars($section['body'] ?? ''); ?></textarea>
                                                </div>
                                                <div class="form-group">
                                                    <label>Payload JSON</label>
                                                    <textarea name="section_payload" class="form-control" rows="<?php echo $sectionKey === 'hero' ? 8 : 10; ?>" spellcheck="false"><?php echo htmlspecialchars($payloadJson); ?></textarea>
                                                </div>
                                            </div>
                                            <div class="card-footer text-right">
                                                <button type="submit" class="btn btn-success">
                                                    <i class="fas fa-save mr-1"></i> Save Section
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-plus-circle mr-2"></i>Add Landing Entry</h3>
                    </div>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="add">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Name / Label</label>
                                        <input type="text" name="full_name" class="form-control" placeholder="e.g. CWTS Instructors" required>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-group">
                                        <label>Position / Title</label>
                                        <input type="text" name="position_title" class="form-control" placeholder="e.g. CWTS Instruction Team" required>
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-group">
                                        <label>Program</label>
                                        <input type="text" name="program" class="form-control" value="NSTP" list="programOptions">
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-group">
                                        <label>Group</label>
                                        <input type="text" name="group_label" class="form-control" value="NSTP Office">
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="form-group">
                                        <label>Order</label>
                                        <input type="number" name="sort_order" class="form-control" value="0">
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Photo Path</label>
                                        <input type="text" name="photo_path" class="form-control" placeholder="uploads/landing_staff/photo.png">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="form-group">
                                        <label>Upload Photo</label>
                                        <input type="file" name="photo_upload" class="form-control-file" accept="image/*">
                                    </div>
                                </div>
                                <div class="col-md-2 d-flex align-items-center">
                                    <div class="custom-control custom-checkbox mt-3">
                                        <input type="checkbox" name="is_visible" class="custom-control-input" id="addVisible" checked>
                                        <label class="custom-control-label" for="addVisible">Visible</label>
                                    </div>
                                </div>
                                <div class="col-md-2 d-flex align-items-center justify-content-md-end">
                                    <button type="submit" class="btn btn-primary mt-3">
                                        <i class="fas fa-save mr-1"></i> Add Entry
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-sitemap mr-2"></i>Current Landing Entries</h3>
                    </div>
                    <div class="card-body table-responsive">
                        <datalist id="programOptions">
                            <option value="NSTP">
                            <option value="CWTS">
                            <option value="LTS">
                            <option value="ROTC">
                            <option value="DRRM">
                        </datalist>
                        <table class="table table-bordered table-hover">
                            <thead>
                                <tr>
                                    <th style="width: 72px;">Photo</th>
                                    <th>Name / Label</th>
                                    <th>Position / Title</th>
                                    <th>Program</th>
                                    <th>Group</th>
                                    <th style="width: 90px;">Order</th>
                                    <th style="width: 90px;">Visible</th>
                                    <th>Photo Path</th>
                                    <th style="width: 170px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($staffEntries as $entry): ?>
                                    <?php
                                        $updateFormId = 'updateLandingEntry' . (int) $entry['landing_staff_id'];
                                        $deleteFormId = 'deleteLandingEntry' . (int) $entry['landing_staff_id'];
                                    ?>
                                    <tr>
                                        <td>
                                            <?php if (!empty($entry['photo_path']) && file_exists($entry['photo_path'])): ?>
                                                <img src="<?php echo htmlspecialchars($entry['photo_path']); ?>?v=<?php echo time(); ?>" class="entry-photo" alt="">
                                            <?php else: ?>
                                                <div class="entry-initials"><?php echo htmlspecialchars(landingInitials($entry['full_name'])); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><input form="<?php echo $updateFormId; ?>" type="text" name="full_name" class="form-control form-control-sm name-input" value="<?php echo htmlspecialchars($entry['full_name']); ?>" required></td>
                                        <td><input form="<?php echo $updateFormId; ?>" type="text" name="position_title" class="form-control form-control-sm name-input" value="<?php echo htmlspecialchars($entry['position_title']); ?>" required></td>
                                        <td><input form="<?php echo $updateFormId; ?>" type="text" name="program" class="form-control form-control-sm" value="<?php echo htmlspecialchars($entry['program']); ?>" list="programOptions"></td>
                                        <td><input form="<?php echo $updateFormId; ?>" type="text" name="group_label" class="form-control form-control-sm" value="<?php echo htmlspecialchars($entry['group_label']); ?>"></td>
                                        <td><input form="<?php echo $updateFormId; ?>" type="number" name="sort_order" class="form-control form-control-sm" value="<?php echo (int) $entry['sort_order']; ?>"></td>
                                        <td class="text-center">
                                            <input form="<?php echo $updateFormId; ?>" type="checkbox" name="is_visible" <?php echo ((int) $entry['is_visible'] === 1) ? 'checked' : ''; ?>>
                                        </td>
                                        <td>
                                            <input form="<?php echo $updateFormId; ?>" type="text" name="photo_path" class="form-control form-control-sm photo-path-input mb-1" value="<?php echo htmlspecialchars($entry['photo_path'] ?? ''); ?>">
                                            <input form="<?php echo $updateFormId; ?>" type="file" name="photo_upload" class="form-control-file" accept="image/*">
                                        </td>
                                        <td>
                                            <form id="<?php echo $updateFormId; ?>" method="POST" enctype="multipart/form-data" class="d-inline">
                                                <input type="hidden" name="action" value="update">
                                                <input type="hidden" name="landing_staff_id" value="<?php echo (int) $entry['landing_staff_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-success mb-1">
                                                    <i class="fas fa-save"></i> Save
                                                </button>
                                            </form>
                                            <form id="<?php echo $deleteFormId; ?>" method="POST" class="d-inline" onsubmit="return confirm('Delete this landing entry?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="landing_staff_id" value="<?php echo (int) $entry['landing_staff_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-danger mb-1">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
</body>
</html>
