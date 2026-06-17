<?php
session_start();

require_once 'conn/conn.php';
require_once 'include/user-permissions.php';
require_once 'include/automatic-sectioning.php';

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
$rotcMsOptions = ['MS-1', 'MS-31', 'MS-41'];
$componentSelectionEnabled = isComponentSelectionEnabled($conn);
$message = '';
$error = '';

function componentColumnExists(PDO $conn, $tableName, $columnName) {
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$tableName, $columnName]);
    return (int) $stmt->fetchColumn() > 0;
}

function ensureRotcRegistrationColumns(PDO $conn) {
    $columns = [
        'height' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN height VARCHAR(30) NULL AFTER blood_type",
        'rotc_ms_level' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN rotc_ms_level VARCHAR(20) NULL AFTER component",
        'rotc_completion_proof' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN rotc_completion_proof VARCHAR(255) NULL AFTER rotc_ms_level",
    ];

    foreach ($columns as $columnName => $alterSql) {
        if (!componentColumnExists($conn, 'tbl_public_student_registrations', $columnName)) {
            $conn->exec($alterSql);
        }
    }
}

function saveRotcCompletionProof(array $file) {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new Exception('Please upload the required ROTC completion proof.');
    }

    if (($file['size'] ?? 0) > 8 * 1024 * 1024) {
        throw new Exception('ROTC completion proof must not exceed 8MB.');
    }

    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
    $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions, true)) {
        throw new Exception('ROTC completion proof must be a JPG, PNG, WEBP, or PDF file.');
    }

    $uploadDir = __DIR__ . '/uploads/rotc_proofs';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
        throw new Exception('Unable to prepare ROTC proof upload folder.');
    }

    $fileName = 'rotc_proof_' . date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '.' . $extension;
    $targetPath = $uploadDir . '/' . $fileName;
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        throw new Exception('Unable to upload ROTC completion proof.');
    }

    return 'uploads/rotc_proofs/' . $fileName;
}

function parseRotcHeight($height) {
    $height = trim((string) $height);
    if ($height === '') {
        return ['feet' => '', 'inches' => ''];
    }

    if (preg_match('/^(\d+)\s*ft\s*(\d+)?/i', $height, $matches)) {
        return ['feet' => $matches[1] ?? '', 'inches' => $matches[2] ?? '0'];
    }

    if (preg_match('/^(\d+)\s*\'\s*(\d+)?/', $height, $matches)) {
        return ['feet' => $matches[1] ?? '', 'inches' => $matches[2] ?? '0'];
    }

    return ['feet' => '', 'inches' => ''];
}

if (isset($_POST['update_component'])) {
    if (!$componentSelectionEnabled) {
        $error = 'Component selection is currently closed.';
    } else {
    $selectedComponent = strtoupper(trim($_POST['component'] ?? ''));
    $rotcHeightFeet = preg_replace('/\D/', '', (string) ($_POST['rotc_height_feet'] ?? ''));
    $rotcHeightInches = preg_replace('/\D/', '', (string) ($_POST['rotc_height_inches'] ?? ''));
    $rotcHeight = '';
    if ($rotcHeightFeet !== '' && $rotcHeightInches !== '') {
        $rotcHeight = ((int) $rotcHeightFeet) . ' ft ' . ((int) $rotcHeightInches) . ' in';
    }
    $rotcMsLevel = strtoupper(trim((string) ($_POST['rotc_ms_level'] ?? '')));

    if (!in_array($selectedComponent, $componentOptions, true)) {
        $error = 'Please select a valid NSTP component.';
    } elseif ($selectedComponent === 'ROTC' && ($rotcHeightFeet === '' || $rotcHeightInches === '')) {
        $error = 'Please enter your ROTC height in feet and inches.';
    } elseif ($selectedComponent === 'ROTC' && ((int) $rotcHeightFeet < 3 || (int) $rotcHeightFeet > 8)) {
        $error = 'Height feet must be between 3 and 8.';
    } elseif ($selectedComponent === 'ROTC' && ((int) $rotcHeightInches < 0 || (int) $rotcHeightInches > 11)) {
        $error = 'Height inches must be between 0 and 11.';
    } elseif ($selectedComponent === 'ROTC' && !in_array($rotcMsLevel, $rotcMsOptions, true)) {
        $error = 'Please select the MS level you will take.';
    } else {
        try {
            ensureRotcRegistrationColumns($conn);
            $rotcProofPath = null;
            $existingProofPath = null;

            $stmt = $conn->prepare("SELECT full_name, role, username FROM tbl_users WHERE user_id = ?");
            $stmt->execute([$user_id]);
            $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$currentUser || $currentUser['role'] !== 'student') {
                throw new Exception('Only student accounts can choose a component here.');
            }

            $studentNumber = preg_replace('/\D/', '', (string) ($currentUser['username'] ?? ''));
            $stmt = $conn->prepare("
                SELECT course, year_section, rotc_completion_proof
                FROM tbl_public_student_registrations
                WHERE user_id = ? OR (? <> '' AND student_number = ?)
                ORDER BY registration_id DESC
                LIMIT 1
            ");
            $stmt->execute([$user_id, $studentNumber, $studentNumber]);
            $registration = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $existingProofPath = $registration['rotc_completion_proof'] ?? null;

            if ($selectedComponent === 'ROTC' && in_array($rotcMsLevel, ['MS-31', 'MS-41'], true)) {
                if (!empty($_FILES['rotc_completion_proof']) && ($_FILES['rotc_completion_proof']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $rotcProofPath = saveRotcCompletionProof($_FILES['rotc_completion_proof']);
                } elseif (empty($existingProofPath)) {
                    $requiredProof = $rotcMsLevel === 'MS-31' ? 'MS-2' : 'MS-42';
                    throw new Exception("Please upload proof that you completed {$requiredProof}.");
                }
            }

            $originalSection = autoSectionOriginalSection(
                $registration['course'] ?? '',
                $registration['year_section'] ?? '',
                ''
            );
            $pendingComponent = $selectedComponent;

            $conn->beginTransaction();

            $stmt = $conn->prepare("SELECT tbl_student_id FROM tbl_student WHERE user_id = ? LIMIT 1");
            $stmt->execute([$user_id]);
            $existingStudent = $stmt->fetch(PDO::FETCH_ASSOC);

            $studentName = trim($currentUser['full_name'] ?? 'Student');
            if ($existingStudent) {
                $stmt = $conn->prepare("
                    UPDATE tbl_student
                    SET student_name = ?, original_section = ?, course_section = ?, created_by = NULL
                    WHERE user_id = ?
                ");
                $stmt->execute([$studentName, $originalSection, $pendingComponent, $user_id]);
            } else {
                do {
                    $generatedCode = 'STU_' . uniqid('', true) . '_' . random_int(1000, 9999);
                    $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_student WHERE generated_code = ?");
                    $stmt->execute([$generatedCode]);
                } while ((int) $stmt->fetchColumn() > 0);

                $stmt = $conn->prepare("
                    INSERT INTO tbl_student (user_id, student_name, original_section, course_section, generated_code, created_by)
                    VALUES (?, ?, ?, ?, ?, NULL)
                ");
                $stmt->execute([$user_id, $studentName, $originalSection, $pendingComponent, $generatedCode]);
            }

            $stmt = $conn->prepare("UPDATE tbl_users SET program = ? WHERE user_id = ?");
            $stmt->execute([$selectedComponent, $user_id]);

            $stmt = $conn->prepare("
                UPDATE tbl_public_student_registrations
                SET component = ?,
                    height = ?,
                    rotc_ms_level = ?,
                    rotc_completion_proof = COALESCE(?, rotc_completion_proof)
                WHERE user_id = ? OR (? <> '' AND student_number = ?)
            ");
            $stmt->execute([
                $selectedComponent,
                $selectedComponent === 'ROTC' ? $rotcHeight : null,
                $selectedComponent === 'ROTC' ? $rotcMsLevel : null,
                $selectedComponent === 'ROTC' ? $rotcProofPath : null,
                $user_id,
                $studentNumber,
                $studentNumber,
            ]);

            if ($conn->inTransaction()) {
                $conn->commit();
            }
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

$rotcDetails = [];
try {
    ensureRotcRegistrationColumns($conn);
    $stmt = $conn->prepare("
        SELECT height, rotc_ms_level, rotc_completion_proof
        FROM tbl_public_student_registrations
        WHERE user_id = ? OR (? <> '' AND student_number = ?)
        ORDER BY registration_id DESC
        LIMIT 1
    ");
    $studentNumber = preg_replace('/\D/', '', (string) ($user['username'] ?? ''));
    $stmt->execute([$user_id, $studentNumber, $studentNumber]);
    $rotcDetails = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $schemaError) {
    $rotcDetails = [];
}
$rotcHeightParts = parseRotcHeight($rotcDetails['height'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Component - TAU NSTP National Service Training Program</title>
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
            <?php include './include/header-notifications.php'; ?>
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

                        <form method="POST" enctype="multipart/form-data">
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
                            <div id="rotcDetailsBlock" class="border rounded p-3 mb-3" style="display: none;">
                                <h5 class="mb-3"><i class="fas fa-id-card mr-2"></i>ROTC Details</h5>
                                <div class="form-group">
                                    <label>Height <span class="text-danger">*</span></label>
                                    <div class="form-row">
                                        <div class="col-6 col-md-3">
                                            <div class="input-group">
                                                <input type="text" class="form-control rotc-number-only" id="rotc_height_feet" name="rotc_height_feet" value="<?php echo htmlspecialchars($rotcHeightParts['feet']); ?>" inputmode="numeric" pattern="[0-9]*" maxlength="1" placeholder="5">
                                                <div class="input-group-append">
                                                    <span class="input-group-text">ft</span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-6 col-md-3">
                                            <div class="input-group">
                                                <input type="text" class="form-control rotc-number-only" id="rotc_height_inches" name="rotc_height_inches" value="<?php echo htmlspecialchars($rotcHeightParts['inches']); ?>" inputmode="numeric" pattern="[0-9]*" maxlength="2" placeholder="6">
                                                <div class="input-group-append">
                                                    <span class="input-group-text">in</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <small class="form-text text-muted">Use feet and inches only. Example: 5 ft 6 in.</small>
                                </div>
                                <div class="form-group">
                                    <label for="rotc_ms_level">Enrollment Level <span class="text-danger">*</span></label>
                                    <select class="form-control" id="rotc_ms_level" name="rotc_ms_level">
                                        <option value="">-- Select MS Level --</option>
                                        <?php foreach ($rotcMsOptions as $msOption): ?>
                                            <option value="<?php echo $msOption; ?>" <?php echo (($rotcDetails['rotc_ms_level'] ?? '') === $msOption) ? 'selected' : ''; ?>>
                                                <?php echo $msOption; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div id="rotcProofBlock" class="form-group" style="display: none;">
                                    <label for="rotc_completion_proof">Completion Proof <span class="text-danger">*</span></label>
                                    <input type="file" class="form-control-file" id="rotc_completion_proof" name="rotc_completion_proof" accept="image/jpeg,image/png,image/webp,application/pdf">
                                    <small class="form-text text-muted" id="rotcProofHelp"></small>
                                    <?php if (!empty($rotcDetails['rotc_completion_proof'])): ?>
                                        <small class="form-text text-success">
                                            Existing proof on file: <?php echo htmlspecialchars(basename($rotcDetails['rotc_completion_proof'])); ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <button type="submit" name="update_component" class="btn btn-success" <?php echo $componentSelectionEnabled ? '' : 'disabled'; ?>>
                                <i class="fas fa-check mr-2"></i>Save Component
                            </button>
                        </form>

                        <?php if ($studentRecord): ?>
                            <hr>
                            <p class="mb-1"><strong>Current Component:</strong> <?php echo htmlspecialchars($studentRecord['course_section']); ?></p>
                            <?php if (($user['program'] ?? '') === 'ROTC'): ?>
                                <p class="mb-1"><strong>Height:</strong> <?php echo htmlspecialchars($rotcDetails['height'] ?? 'Not set'); ?></p>
                                <p class="mb-1"><strong>Enrollment Level:</strong> <?php echo htmlspecialchars($rotcDetails['rotc_ms_level'] ?? 'Not set'); ?></p>
                            <?php endif; ?>
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
<script>
    const componentSelect = document.getElementById('component');
    const rotcDetailsBlock = document.getElementById('rotcDetailsBlock');
    const rotcHeightFeet = document.getElementById('rotc_height_feet');
    const rotcHeightInches = document.getElementById('rotc_height_inches');
    const rotcMsLevel = document.getElementById('rotc_ms_level');
    const rotcProofBlock = document.getElementById('rotcProofBlock');
    const rotcCompletionProof = document.getElementById('rotc_completion_proof');
    const rotcProofHelp = document.getElementById('rotcProofHelp');
    const hasExistingRotcProof = <?php echo !empty($rotcDetails['rotc_completion_proof']) ? 'true' : 'false'; ?>;

    function syncRotcFields() {
        const isRotc = componentSelect && componentSelect.value === 'ROTC';
        const needsProof = rotcMsLevel && ['MS-31', 'MS-41'].includes(rotcMsLevel.value);
        rotcDetailsBlock.style.display = isRotc ? '' : 'none';
        rotcHeightFeet.required = isRotc;
        rotcHeightInches.required = isRotc;
        rotcMsLevel.required = isRotc;
        rotcProofBlock.style.display = isRotc && needsProof ? '' : 'none';
        rotcCompletionProof.required = isRotc && needsProof && !hasExistingRotcProof;

        if (rotcProofHelp && needsProof) {
            const prerequisite = rotcMsLevel.value === 'MS-31' ? 'MS-2' : 'MS-42';
            rotcProofHelp.textContent = `Upload proof that you completed ${prerequisite}. Accepted files: JPG, PNG, WEBP, or PDF up to 8MB.`;
        }
    }

    if (componentSelect) componentSelect.addEventListener('change', syncRotcFields);
    if (rotcMsLevel) rotcMsLevel.addEventListener('change', syncRotcFields);
    document.querySelectorAll('.rotc-number-only').forEach(input => {
        input.addEventListener('input', () => {
            input.value = input.value.replace(/\D/g, '');
        });
    });
    syncRotcFields();
</script>
</body>
</html>
