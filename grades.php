<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include('./include/theme-loader.php');

if (!isset($_SESSION['user_id'])) {
    header("Location: landing_page.php");
    exit();
}

include('./conn/conn.php');
require_once './include/user-permissions.php';
include('./include/logo-functions.php');

date_default_timezone_set('Asia/Manila');

$userId = (int) ($_SESSION['user_id'] ?? 0);
$userRole = $_SESSION['role'] ?? 'facilitator';
$userName = $_SESSION['full_name'] ?? 'User';

if (!in_array($userRole, ['coordinator', 'facilitator'], true)) {
    header("Location: profile.php");
    exit();
}

function ensureGradeTables(PDO $conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS tbl_grade_columns (
            grade_column_id INT AUTO_INCREMENT PRIMARY KEY,
            column_key VARCHAR(80) NOT NULL UNIQUE,
            label VARCHAR(160) NOT NULL,
            group_code VARCHAR(60) NOT NULL,
            group_label VARCHAR(120) NOT NULL,
            max_score DECIMAL(8,2) NOT NULL DEFAULT 0,
            weight_percent DECIMAL(8,2) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS tbl_grade_scores (
            grade_score_id INT AUTO_INCREMENT PRIMARY KEY,
            grade_column_id INT NOT NULL,
            tbl_student_id INT NOT NULL,
            score DECIMAL(8,2) NULL,
            updated_by INT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_grade_score (grade_column_id, tbl_student_id),
            INDEX idx_grade_student (tbl_student_id),
            CONSTRAINT fk_grade_score_column FOREIGN KEY (grade_column_id)
                REFERENCES tbl_grade_columns (grade_column_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->exec("
        CREATE TABLE IF NOT EXISTS tbl_grade_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value VARCHAR(255) NOT NULL,
            updated_by INT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function seedDefaultGradeColumns(PDO $conn) {
    $defaults = [
        ['bandage_head', 'Top of the head', 'bandaging', 'Bandaging Evaluation', 16, 15, 10],
        ['bandage_chest', 'Chest/Back', 'bandaging', 'Bandaging Evaluation', 16, 15, 20],
        ['bandage_hand_foot', 'Hand/Foot', 'bandaging', 'Bandaging Evaluation', 16, 15, 30],
        ['bandage_shoulder_hips', 'Shoulder/Hips (SEMI)', 'bandaging', 'Bandaging Evaluation', 16, 15, 40],
        ['bandage_elbow_knee', 'Elbow/Knee (SEMI)', 'bandaging', 'Bandaging Evaluation', 16, 15, 50],
        ['bandage_forehead', 'Forehead (narrow)', 'bandaging', 'Bandaging Evaluation', 16, 15, 60],
        ['bandage_ear_cheek_jaw', 'Ear/Cheek/Jaw (narrow)', 'bandaging', 'Bandaging Evaluation', 16, 15, 70],
        ['bandage_palm', 'Palm (narrow)', 'bandaging', 'Bandaging Evaluation', 16, 15, 80],
        ['bandage_forearm_leg', 'Forearm/Leg (narrow)', 'bandaging', 'Bandaging Evaluation', 16, 15, 90],
        ['carry_walking_assist', 'Walking assist', 'carrying', 'Carrying Evaluation', 24, 15, 110],
        ['carry_cradle', 'Cradle carry', 'carrying', 'Carrying Evaluation', 24, 15, 120],
        ['carry_pack_strap', 'Pack strap', 'carrying', 'Carrying Evaluation', 24, 15, 130],
        ['carry_firefighter', 'Firefighter', 'carrying', 'Carrying Evaluation', 24, 15, 140],
        ['carry_extremity', 'Extremity carry', 'carrying', 'Carrying Evaluation', 28, 15, 150],
        ['carry_swing', 'Swing carry', 'carrying', 'Carrying Evaluation', 28, 15, 160],
        ['carry_chair', 'Chair carry', 'carrying', 'Carrying Evaluation', 28, 15, 170],
        ['carry_hammock', 'Hammock carry', 'three_man_carry', '3-4 Man Carry', 28, 15, 190],
        ['carry_bearers', "Bearer's along side", 'three_man_carry', '3-4 Man Carry', 28, 15, 200],
        ['carry_blanket', 'Blanket carry', 'three_man_carry', '3-4 Man Carry', 28, 15, 210],
        ['carry_stretcher', 'Improvised stretcher', 'three_man_carry', '3-4 Man Carry', 28, 15, 220],
        ['spine_board', 'Spine Board Management', 'spine_board', 'Spine Board Equivalent', 32, 15, 240],
        ['cpr', 'CPR', 'cpr', 'CPR Equivalent', 40, 20, 260],
        ['proposal', 'Proposal', 'community', 'Community Immersion', 35, 40, 300],
        ['implementation', 'MRF and Beautification / Implementation', 'community', 'Community Immersion', 55, 60, 310],
    ];

    $stmt = $conn->prepare("
        INSERT IGNORE INTO tbl_grade_columns
            (column_key, label, group_code, group_label, max_score, weight_percent, sort_order, is_default, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1)
    ");

    foreach ($defaults as $column) {
        $stmt->execute($column);
    }
}

function gradeProgramCondition($program, &$params) {
    $program = normalizeProgram($program);

    if ($program === 'LTS') {
        return "UPPER(s.course_section) LIKE ?";
    }

    if ($program === 'CWTS') {
        return "UPPER(s.course_section) LIKE ?";
    }

    if ($program === 'ROTC') {
        return "(UPPER(s.course_section) LIKE ? OR UPPER(s.course_section) LIKE ?)";
    }

    return "1 = 0";
}

function gradeProgramParams($program) {
    $program = normalizeProgram($program);

    if ($program === 'ROTC') {
        return ['%ROTC%', '%ALPHA%'];
    }

    return ['%' . $program . '%'];
}

function getGradeSetting(PDO $conn, $key, $default) {
    $stmt = $conn->prepare("SELECT setting_value FROM tbl_grade_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : $value;
}

function setGradeSetting(PDO $conn, $key, $value, $userId) {
    $stmt = $conn->prepare("
        INSERT INTO tbl_grade_settings (setting_key, setting_value, updated_by)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)
    ");
    $stmt->execute([$key, $value, $userId]);
}

function formatGradeNumber($value, $decimals = 2) {
    if ($value === null || $value === '') {
        return '-';
    }

    return number_format((float) $value, $decimals);
}

function transmuteGrade($equivalentPoints, $denominator = 100) {
    $denominator = max((float) $denominator, 1);
    $grade = 5 - (4 / $denominator * (float) $equivalentPoints);
    return max(1, min(5, $grade));
}

ensureGradeTables($conn);
seedDefaultGradeColumns($conn);

$messages = [];
$errors = [];
$currentUser = getCurrentUserRecord($conn);
$currentProgram = normalizeProgram($_SESSION['program'] ?? ($currentUser['program'] ?? null));
$settingScope = $currentProgram ? strtolower($currentProgram) : 'global';
$totalMeetingsKey = 'total_meetings_' . $settingScope;

$columnsStmt = $conn->prepare("
    SELECT *
    FROM tbl_grade_columns
    WHERE is_active = 1
    ORDER BY sort_order ASC, grade_column_id ASC
");
$columnsStmt->execute();
$gradeColumns = $columnsStmt->fetchAll(PDO::FETCH_ASSOC);
$gradeColumnIds = array_map('intval', array_column($gradeColumns, 'grade_column_id'));

$studentParams = [];
if ($userRole === 'coordinator') {
    $condition = gradeProgramCondition($currentProgram, $studentParams);
    $studentParams = $currentProgram ? gradeProgramParams($currentProgram) : [];
    $studentSql = "
        SELECT s.*, COALESCE(NULLIF(u.full_name, ''), u.username, 'Pending Facilitator Assignment') AS facilitator_name
        FROM tbl_student s
        LEFT JOIN tbl_users u ON s.created_by = u.user_id
        WHERE $condition
        ORDER BY s.course_section ASC, facilitator_name ASC, s.student_name ASC
    ";
} else {
    $studentSql = "
        SELECT s.*, COALESCE(NULLIF(u.full_name, ''), u.username, 'Facilitator') AS facilitator_name
        FROM tbl_student s
        LEFT JOIN tbl_users u ON s.created_by = u.user_id
        WHERE s.created_by = ?
        ORDER BY s.course_section ASC, s.student_name ASC
    ";
    $studentParams = [$userId];
}

$studentsStmt = $conn->prepare($studentSql);
$studentsStmt->execute($studentParams);
$students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);
$accessibleStudentIds = array_map('intval', array_column($students, 'tbl_student_id'));
$accessibleStudentLookup = array_fill_keys($accessibleStudentIds, true);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_column' && $userRole === 'coordinator') {
        $label = trim($_POST['label'] ?? '');
        $maxScore = (float) ($_POST['max_score'] ?? 0);
        $weight = (float) ($_POST['weight_percent'] ?? 0);

        if ($label === '') {
            $errors[] = 'Column name is required.';
        } elseif ($maxScore <= 0) {
            $errors[] = 'Max score must be greater than zero.';
        } elseif ($weight <= 0) {
            $errors[] = 'Weight percent must be greater than zero.';
        } else {
            $stmt = $conn->prepare("
                INSERT INTO tbl_grade_columns
                    (column_key, label, group_code, group_label, max_score, weight_percent, sort_order, is_default, is_active, created_by)
                VALUES (?, ?, 'additional', 'Additional Columns', ?, ?, ?, 0, 1, ?)
            ");
            $stmt->execute([
                'custom_' . bin2hex(random_bytes(8)),
                $label,
                $maxScore,
                $weight,
                500 + time(),
                $userId,
            ]);
            $messages[] = 'New grade column added.';
        }
    }

    if ($action === 'delete_column' && $userRole === 'coordinator') {
        $columnId = (int) ($_POST['column_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE tbl_grade_columns SET is_active = 0 WHERE grade_column_id = ? AND is_default = 0");
        $stmt->execute([$columnId]);
        $messages[] = 'Custom column removed from the class record.';
    }

    if ($action === 'settings' && $userRole === 'coordinator') {
        $meetings = max(1, (int) ($_POST['total_meetings'] ?? 11));
        setGradeSetting($conn, $totalMeetingsKey, (string) $meetings, $userId);
        $messages[] = 'Grade settings updated.';
    }

    if ($action === 'save_scores') {
        $scores = $_POST['scores'] ?? [];
        $columnLookup = [];
        foreach ($gradeColumns as $column) {
            $columnLookup[(int) $column['grade_column_id']] = (float) $column['max_score'];
        }

        $upsert = $conn->prepare("
            INSERT INTO tbl_grade_scores (grade_column_id, tbl_student_id, score, updated_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE score = VALUES(score), updated_by = VALUES(updated_by)
        ");

        foreach ($scores as $studentId => $studentScores) {
            $studentId = (int) $studentId;
            if (!isset($accessibleStudentLookup[$studentId]) || !is_array($studentScores)) {
                continue;
            }

            foreach ($studentScores as $columnId => $scoreValue) {
                $columnId = (int) $columnId;
                if (!isset($columnLookup[$columnId])) {
                    continue;
                }

                $scoreValue = trim((string) $scoreValue);
                $score = $scoreValue === '' ? null : max(0, min((float) $scoreValue, $columnLookup[$columnId]));
                $upsert->execute([$columnId, $studentId, $score, $userId]);
            }
        }

        $messages[] = 'Grades saved successfully.';
    }

    $query = $messages ? '?saved=1' : '?error=1';
    $_SESSION['grade_messages'] = $messages;
    $_SESSION['grade_errors'] = $errors;
    header("Location: grades.php" . $query);
    exit();
}

if (!empty($_SESSION['grade_messages'])) {
    $messages = $_SESSION['grade_messages'];
    unset($_SESSION['grade_messages']);
}

if (!empty($_SESSION['grade_errors'])) {
    $errors = $_SESSION['grade_errors'];
    unset($_SESSION['grade_errors']);
}

$columnsStmt->execute();
$gradeColumns = $columnsStmt->fetchAll(PDO::FETCH_ASSOC);
$gradeColumnIds = array_map('intval', array_column($gradeColumns, 'grade_column_id'));

$scoresByStudent = [];
if (!empty($accessibleStudentIds) && !empty($gradeColumnIds)) {
    $studentPlaceholders = implode(',', array_fill(0, count($accessibleStudentIds), '?'));
    $columnPlaceholders = implode(',', array_fill(0, count($gradeColumnIds), '?'));
    $stmt = $conn->prepare("
        SELECT tbl_student_id, grade_column_id, score
        FROM tbl_grade_scores
        WHERE tbl_student_id IN ($studentPlaceholders)
          AND grade_column_id IN ($columnPlaceholders)
    ");
    $stmt->execute(array_merge($accessibleStudentIds, $gradeColumnIds));

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $scoreRow) {
        $scoresByStudent[(int) $scoreRow['tbl_student_id']][(int) $scoreRow['grade_column_id']] = $scoreRow['score'];
    }
}

$attendanceCounts = [];
if (!empty($accessibleStudentIds)) {
    $studentPlaceholders = implode(',', array_fill(0, count($accessibleStudentIds), '?'));
    $stmt = $conn->prepare("
        SELECT tbl_student_id, COUNT(DISTINCT attendance_date) AS attendance_count
        FROM (
            SELECT tbl_student_id, DATE(time_in) AS attendance_date
            FROM tbl_attendance
            WHERE tbl_student_id IN ($studentPlaceholders)
            UNION ALL
            SELECT tbl_student_id, DATE(time_in) AS attendance_date
            FROM tbl_attendance_archive
            WHERE tbl_student_id IN ($studentPlaceholders)
        ) attendance_days
        GROUP BY tbl_student_id
    ");
    $stmt->execute(array_merge($accessibleStudentIds, $accessibleStudentIds));

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $attendanceRow) {
        $attendanceCounts[(int) $attendanceRow['tbl_student_id']] = (int) $attendanceRow['attendance_count'];
    }
}

$totalMeetings = max(1, (int) getGradeSetting($conn, $totalMeetingsKey, '11'));
$groupMeta = [];
foreach ($gradeColumns as $column) {
    $groupCode = $column['group_code'];
    if (!isset($groupMeta[$groupCode])) {
        $groupMeta[$groupCode] = [
            'label' => $column['group_label'],
            'weight' => 0,
            'max' => 0,
            'count' => 0,
        ];
    }

    if ($groupCode === 'additional') {
        $groupMeta[$groupCode]['weight'] += (float) $column['weight_percent'];
    } elseif ($groupMeta[$groupCode]['weight'] <= 0) {
        $groupMeta[$groupCode]['weight'] = (float) $column['weight_percent'];
    }

    $groupMeta[$groupCode]['max'] += (float) $column['max_score'];
    $groupMeta[$groupCode]['count']++;
}

$customWeight = isset($groupMeta['additional']) ? (float) $groupMeta['additional']['weight'] : 0;
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Grades · TAU NSTP QR Attendance System</title>
    <?php echo getFaviconTags(); ?>
    <link rel="icon" type="image/png" href="include/logo.png">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="include/theme.css">
    <style>
        .grade-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
        }

        .grade-card {
            border-radius: 8px;
            border: 1px solid #dee2e6;
        }

        .grade-table-wrap {
            max-height: calc(100vh - 260px);
            overflow: auto;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            background: #fff;
        }

        .grade-table {
            min-width: 2200px;
            margin-bottom: 0;
        }

        .grade-table th,
        .grade-table td {
            vertical-align: middle !important;
            white-space: nowrap;
            font-size: 0.84rem;
        }

        .grade-table thead th {
            position: sticky;
            top: 0;
            z-index: 5;
            background: #f8f9fa;
        }

        .grade-table .sticky-name {
            position: sticky;
            left: 0;
            z-index: 7;
            background: #fff;
            min-width: 260px;
            max-width: 260px;
            white-space: normal;
            box-shadow: 1px 0 0 #dee2e6;
        }

        .grade-table thead .sticky-name {
            background: #f8f9fa;
            z-index: 9;
        }

        .score-input {
            width: 74px;
            min-width: 74px;
            text-align: center;
            padding-left: 6px;
            padding-right: 6px;
        }

        .computed-cell {
            background: #f7fbff;
            font-weight: 700;
        }

        .final-cell {
            background: #e8f5e9;
            color: #155724;
            font-weight: 800;
        }

        .small-label {
            display: block;
            color: #6c757d;
            font-size: 0.72rem;
            font-weight: 600;
        }

        .column-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 8px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            margin: 2px;
            background: #fff;
        }

        @media (max-width: 768px) {
            .grade-table-wrap {
                max-height: none;
            }

            .grade-toolbar {
                align-items: stretch;
            }
        }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">
    <?php include 'navbar.php'; ?>
    <?php include 'adminlte-sidebar.php'; ?>

    <div class="content-wrapper">
        <section class="content-header">
            <div class="container-fluid">
                <div class="grade-toolbar">
                    <div>
                        <h1 class="mb-1"><i class="fas fa-calculator mr-2"></i>NSTP Grade Computation</h1>
                        <div class="text-muted">
                            <?php echo htmlspecialchars(ucfirst($userRole)); ?> view
                            <?php if ($currentProgram): ?>
                                · <?php echo htmlspecialchars($currentProgram); ?>
                            <?php endif; ?>
                            · <?php echo count($students); ?> student(s)
                        </div>
                    </div>
                    <div class="d-flex flex-wrap" style="gap: 8px;">
                        <?php if ($userRole === 'coordinator'): ?>
                            <button class="btn btn-primary" data-toggle="modal" data-target="#addColumnModal">
                                <i class="fas fa-plus mr-1"></i>Add Column
                            </button>
                            <button class="btn btn-outline-secondary" data-toggle="modal" data-target="#settingsModal">
                                <i class="fas fa-sliders-h mr-1"></i>Settings
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </section>

        <section class="content">
            <div class="container-fluid">
                <?php foreach ($messages as $message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle mr-1"></i><?php echo htmlspecialchars($message); ?>
                        <button type="button" class="close" data-dismiss="alert">&times;</button>
                    </div>
                <?php endforeach; ?>

                <?php foreach ($errors as $error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle mr-1"></i><?php echo htmlspecialchars($error); ?>
                        <button type="button" class="close" data-dismiss="alert">&times;</button>
                    </div>
                <?php endforeach; ?>

                <div class="row">
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box grade-card">
                            <span class="info-box-icon bg-info"><i class="fas fa-users"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Students</span>
                                <span class="info-box-number"><?php echo count($students); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box grade-card">
                            <span class="info-box-icon bg-success"><i class="fas fa-calendar-check"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Attendance Meetings</span>
                                <span class="info-box-number"><?php echo (int) $totalMeetings; ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box grade-card">
                            <span class="info-box-icon bg-warning"><i class="fas fa-table-columns"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Score Columns</span>
                                <span class="info-box-number"><?php echo count($gradeColumns); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <div class="info-box grade-card">
                            <span class="info-box-icon bg-primary"><i class="fas fa-weight-hanging"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">Custom Weight</span>
                                <span class="info-box-number"><?php echo formatGradeNumber($customWeight, 0); ?>%</span>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($userRole === 'coordinator'): ?>
                    <div class="card grade-card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-list mr-2"></i>Custom Columns</h3>
                        </div>
                        <div class="card-body">
                            <?php
                            $customColumns = array_filter($gradeColumns, function ($column) {
                                return (int) $column['is_default'] === 0;
                            });
                            ?>
                            <?php if (empty($customColumns)): ?>
                                <span class="text-muted">No custom columns yet.</span>
                            <?php else: ?>
                                <?php foreach ($customColumns as $column): ?>
                                    <span class="column-chip">
                                        <?php echo htmlspecialchars($column['label']); ?>
                                        <small class="text-muted">
                                            /<?php echo formatGradeNumber($column['max_score'], 0); ?>
                                            · <?php echo formatGradeNumber($column['weight_percent'], 0); ?>%
                                        </small>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Remove this custom column from the class record? Existing scores will be kept in the database but hidden.');">
                                            <input type="hidden" name="action" value="delete_column">
                                            <input type="hidden" name="column_id" value="<?php echo (int) $column['grade_column_id']; ?>">
                                            <button class="btn btn-xs btn-link text-danger p-0" title="Remove column">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </form>
                                    </span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <input type="hidden" name="action" value="save_scores">
                    <div class="card grade-card">
                        <div class="card-header d-flex align-items-center justify-content-between">
                            <h3 class="card-title mb-0"><i class="fas fa-file-signature mr-2"></i>Class Record</h3>
                            <button class="btn btn-success btn-sm ml-auto">
                                <i class="fas fa-save mr-1"></i>Save Scores
                            </button>
                        </div>
                        <div class="card-body p-0">
                            <div class="grade-table-wrap">
                                <table class="table table-bordered table-hover grade-table">
                                    <thead>
                                        <tr>
                                            <th class="sticky-name">Student</th>
                                            <th>Section</th>
                                            <th>Facilitator</th>
                                            <?php foreach ($gradeColumns as $column): ?>
                                                <th>
                                                    <?php echo htmlspecialchars($column['label']); ?>
                                                    <span class="small-label">
                                                        <?php echo htmlspecialchars($column['group_label']); ?>
                                                        · /<?php echo formatGradeNumber($column['max_score'], 0); ?>
                                                    </span>
                                                </th>
                                            <?php endforeach; ?>
                                            <th class="computed-cell">Attendance <span class="small-label">/ <?php echo (int) $totalMeetings; ?></span></th>
                                            <th class="computed-cell">BLS Eq</th>
                                            <th class="computed-cell">BLS Grade</th>
                                            <th class="computed-cell">CI Eq</th>
                                            <th class="computed-cell">CI Grade</th>
                                            <?php if ($customWeight > 0): ?>
                                                <th class="computed-cell">Additional Eq</th>
                                            <?php endif; ?>
                                            <th class="final-cell">Final Grade</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($students)): ?>
                                            <tr>
                                                <td colspan="<?php echo count($gradeColumns) + 9; ?>" class="text-center text-muted py-5">
                                                    <i class="fas fa-user-graduate fa-2x d-block mb-2"></i>
                                                    No students available for this account.
                                                </td>
                                            </tr>
                                        <?php endif; ?>

                                        <?php foreach ($students as $student): ?>
                                            <?php
                                            $studentId = (int) $student['tbl_student_id'];
                                            $rawByGroup = [];
                                            $maxByGroup = [];

                                            foreach ($gradeColumns as $column) {
                                                $groupCode = $column['group_code'];
                                                if (!isset($rawByGroup[$groupCode])) {
                                                    $rawByGroup[$groupCode] = 0;
                                                    $maxByGroup[$groupCode] = 0;
                                                }

                                                $score = $scoresByStudent[$studentId][(int) $column['grade_column_id']] ?? null;
                                                $rawByGroup[$groupCode] += $score === null || $score === '' ? 0 : (float) $score;
                                                $maxByGroup[$groupCode] += (float) $column['max_score'];
                                            }

                                            $attendanceCount = min($totalMeetings, $attendanceCounts[$studentId] ?? 0);
                                            $attendanceEq = ($attendanceCount / max($totalMeetings, 1)) * 20;

                                            $bandagingEq = (($rawByGroup['bandaging'] ?? 0) / max($maxByGroup['bandaging'] ?? 1, 1)) * 15;
                                            $carryingEq = (($rawByGroup['carrying'] ?? 0) / max($maxByGroup['carrying'] ?? 1, 1)) * 15;
                                            $threeManEq = (($rawByGroup['three_man_carry'] ?? 0) / max($maxByGroup['three_man_carry'] ?? 1, 1)) * 15;
                                            $spineEq = (($rawByGroup['spine_board'] ?? 0) / max($maxByGroup['spine_board'] ?? 1, 1)) * 15;
                                            $cprEq = (($rawByGroup['cpr'] ?? 0) / max($maxByGroup['cpr'] ?? 1, 1)) * 20;
                                            $blsEq = $bandagingEq + $carryingEq + $threeManEq + $spineEq + $cprEq + $attendanceEq;
                                            $blsGrade = transmuteGrade($blsEq);

                                            $communityEq = (($rawByGroup['community'] ?? 0) / max($maxByGroup['community'] ?? 1, 1)) * 100;
                                            $communityGrade = transmuteGrade($communityEq);

                                            $additionalEq = 0;
                                            if ($customWeight > 0) {
                                                foreach ($gradeColumns as $column) {
                                                    if ($column['group_code'] !== 'additional') {
                                                        continue;
                                                    }

                                                    $columnId = (int) $column['grade_column_id'];
                                                    $score = $scoresByStudent[$studentId][$columnId] ?? null;
                                                    $score = $score === null || $score === '' ? 0 : (float) $score;
                                                    $additionalEq += ($score / max((float) $column['max_score'], 1)) * (float) $column['weight_percent'];
                                                }
                                            }

                                            $finalPoints = (($blsEq + $communityEq) / 2) + $additionalEq;
                                            $finalDenominator = 100 + $customWeight;
                                            $finalGrade = transmuteGrade($finalPoints, $finalDenominator);
                                            ?>
                                            <tr>
                                                <td class="sticky-name">
                                                    <strong><?php echo htmlspecialchars($student['student_name']); ?></strong>
                                                    <?php if (!empty($student['student_number'])): ?>
                                                        <span class="small-label"><?php echo htmlspecialchars($student['student_number']); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($student['course_section']); ?></td>
                                                <td><?php echo htmlspecialchars($student['facilitator_name']); ?></td>
                                                <?php foreach ($gradeColumns as $column): ?>
                                                    <?php
                                                    $columnId = (int) $column['grade_column_id'];
                                                    $value = $scoresByStudent[$studentId][$columnId] ?? '';
                                                    ?>
                                                    <td>
                                                        <input
                                                            type="number"
                                                            class="form-control form-control-sm score-input"
                                                            name="scores[<?php echo $studentId; ?>][<?php echo $columnId; ?>]"
                                                            value="<?php echo htmlspecialchars((string) $value); ?>"
                                                            min="0"
                                                            max="<?php echo htmlspecialchars((string) $column['max_score']); ?>"
                                                            step="0.01">
                                                    </td>
                                                <?php endforeach; ?>
                                                <td class="computed-cell"><?php echo (int) $attendanceCount; ?></td>
                                                <td class="computed-cell"><?php echo formatGradeNumber($blsEq); ?></td>
                                                <td class="computed-cell"><?php echo formatGradeNumber($blsGrade); ?></td>
                                                <td class="computed-cell"><?php echo formatGradeNumber($communityEq); ?></td>
                                                <td class="computed-cell"><?php echo formatGradeNumber($communityGrade); ?></td>
                                                <?php if ($customWeight > 0): ?>
                                                    <td class="computed-cell"><?php echo formatGradeNumber($additionalEq); ?></td>
                                                <?php endif; ?>
                                                <td class="final-cell"><?php echo formatGradeNumber($finalGrade); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="card-footer text-muted">
                            Formula follows the NSTP class record pattern: BLS equivalent includes bandaging, carrying, 3-4 man carry, spine board, CPR, and attendance. Community immersion uses proposal and implementation. Final grade is transmuted on a 1.00 to 5.00 scale.
                        </div>
                    </div>
                </form>
            </div>
        </section>
    </div>

    <?php include 'footer.php'; ?>
</div>

<?php if ($userRole === 'coordinator'): ?>
<div class="modal fade" id="addColumnModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="add_column">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus mr-2"></i>Add Grade Column</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Column Name</label>
                    <input type="text" name="label" class="form-control" maxlength="160" placeholder="Example: Reflection Paper" required>
                </div>
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label>Max Score</label>
                        <input type="number" name="max_score" class="form-control" min="1" step="0.01" value="100" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label>Weight Percent</label>
                        <input type="number" name="weight_percent" class="form-control" min="1" step="0.01" value="10" required>
                    </div>
                </div>
                <div class="alert alert-info mb-0">
                    Added columns appear at the end of the class record and are included as additional weighted requirements.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button class="btn btn-primary"><i class="fas fa-plus mr-1"></i>Add Column</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="settingsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="settings">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-sliders-h mr-2"></i>Grade Settings</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Total Attendance Meetings</label>
                    <input type="number" name="total_meetings" class="form-control" min="1" value="<?php echo (int) $totalMeetings; ?>" required>
                    <small class="text-muted">Used for Attendance Equivalence. The template you provided uses 11 meetings.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                <button class="btn btn-primary"><i class="fas fa-save mr-1"></i>Save Settings</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script>
$('.score-input').on('input', function() {
    const max = parseFloat(this.max || '0');
    const value = parseFloat(this.value || '0');
    if (max > 0 && value > max) {
        this.value = max;
    }
});
</script>
</body>
</html>
