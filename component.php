<?php
session_start();

require_once 'conn/conn.php';
require_once 'include/user-permissions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: landing_page.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

if ($role !== 'student') {
    header('Location: profile.php');
    exit();
}

$componentOptions = ['CWTS', 'LTS', 'ROTC'];
$componentSelectionEnabled = isComponentSelectionEnabled($conn);
$message = '';
$error = '';

if (isset($_POST['update_component'])) {
    if (!$componentSelectionEnabled) {
        $error = 'Component selection is currently closed.';
    } else {
    $selectedComponent = strtoupper(trim($_POST['component'] ?? ''));

    if (!in_array($selectedComponent, $componentOptions, true)) {
        $error = 'Please select a valid NSTP component.';
    } else {
        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("SELECT full_name, role FROM tbl_users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$currentUser || $currentUser['role'] !== 'student') {
                throw new Exception('Only student accounts can choose a component here.');
            }

            $stmt = $conn->prepare("SELECT tbl_student_id FROM tbl_student WHERE user_id = ? LIMIT 1");
            $stmt->execute([$user_id]);
            $existingStudent = $stmt->fetch(PDO::FETCH_ASSOC);

            $studentName = trim($currentUser['full_name'] ?? 'Student');
            if ($existingStudent) {
                $stmt = $conn->prepare("
                    UPDATE tbl_student
                    SET student_name = ?, course_section = ?
                    WHERE user_id = ?
                ");
                $stmt->execute([$studentName, $selectedComponent, $user_id]);
            } else {
                do {
                    $generatedCode = 'STU_' . uniqid('', true) . '_' . random_int(1000, 9999);
                    $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_student WHERE generated_code = ?");
                    $stmt->execute([$generatedCode]);
                } while ((int) $stmt->fetchColumn() > 0);

                $stmt = $conn->prepare("
                    INSERT INTO tbl_student (user_id, student_name, original_section, course_section, generated_code, created_by)
                    VALUES (?, ?, NULL, ?, ?, NULL)
                ");
                $stmt->execute([$user_id, $studentName, $selectedComponent, $generatedCode]);
            }

            $stmt = $conn->prepare("UPDATE tbl_users SET program = ? WHERE user_id = ?");
            $stmt->execute([$selectedComponent, $user_id]);

            $stmt = $conn->prepare("
                UPDATE tbl_public_student_registrations
                SET component = ?
                WHERE user_id = ?
            ");
            $stmt->execute([$selectedComponent, $user_id]);

            $conn->commit();
            $_SESSION['program'] = $selectedComponent;
            $message = 'NSTP component updated successfully!';
        } catch (Throwable $componentError) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $error = $componentError->getMessage();
        }
    }
    }
}

$stmt = $conn->prepare("SELECT * FROM tbl_users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $conn->prepare("SELECT * FROM tbl_student WHERE user_id = ? LIMIT 1");
$stmt->execute([$user_id]);
$studentRecord = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Component - TAU NSTP QR Attendance System</title>
    <?php include('./include/theme-loader.php'); ?>
    <link rel="icon" type="image/png" href="include/logo.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="include/theme.css">
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
            <?php include './include/theme-toggle.php'; ?>
            <?php include './include/theme-toggle-slider.php'; ?>
        </ul>
    </nav>

    <?php include 'adminlte-sidebar.php'; ?>

    <div class="content-wrapper">
        <section class="content-header">
            <div class="container-fluid">
                <h1><i class="fas fa-layer-group mr-2"></i>Component</h1>
            </div>
        </section>

        <section class="content">
            <div class="container-fluid">
                <?php if ($message): ?>
                    <div class="alert alert-success"><i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Choose NSTP Component</h3>
                    </div>
                    <div class="card-body">
                        <?php if (!$componentSelectionEnabled): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-lock mr-2"></i>
                                Component selection is currently closed by the Super Admin.
                            </div>
                        <?php endif; ?>

                        <form method="POST">
                            <div class="form-group">
                                <label for="component">NSTP Component</label>
                                <select class="form-control" id="component" name="component" required <?php echo $componentSelectionEnabled ? '' : 'disabled'; ?>>
                                    <option value="">-- Select Component --</option>
                                    <?php foreach ($componentOptions as $component): ?>
                                        <option value="<?php echo $component; ?>" <?php echo (($user['program'] ?? '') === $component) ? 'selected' : ''; ?>>
                                            <?php echo $component; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">Your QR code will be created after choosing a component.</small>
                            </div>
                            <button type="submit" name="update_component" class="btn btn-success" <?php echo $componentSelectionEnabled ? '' : 'disabled'; ?>>
                                <i class="fas fa-check mr-2"></i>Save Component
                            </button>
                        </form>

                        <?php if ($studentRecord): ?>
                            <hr>
                            <p class="mb-1"><strong>Current Component:</strong> <?php echo htmlspecialchars($studentRecord['course_section']); ?></p>
                            <p class="mb-0"><strong>QR Code:</strong> <code><?php echo htmlspecialchars($studentRecord['generated_code']); ?></code></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <?php include 'footer.php'; ?>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
</body>
</html>
