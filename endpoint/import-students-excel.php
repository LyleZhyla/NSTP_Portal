<?php
session_start();
include("../conn/conn.php");
require_once '../vendor/autoload.php';
require_once '../include/public-registration-forms.php';
require_once '../include/college-courses.php';
require_once '../include/religions.php';
require_once '../include/automatic-sectioning.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

header('Content-Type: application/json');

$response = ['success' => false, 'message' => '', 'imported' => 0, 'errors' => [], 'total_rows' => 0];

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'Unauthorized access';
    echo json_encode($response);
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'facilitator';

if (!in_array($user_role, ['super_admin', 'coordinator'], true)) {
    $response['message'] = 'Only super admins and coordinators can import students.';
    echo json_encode($response);
    exit();
}

function importColumnExists(PDO $conn, $tableName, $columnName) {
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

function ensureSuperAdminImportSchema(PDO $conn) {
    ensurePublicRegistrationFormsTable($conn);

    $conn->exec("
        CREATE TABLE IF NOT EXISTS tbl_public_student_registrations (
            registration_id INT AUTO_INCREMENT PRIMARY KEY,
            form_id INT NULL,
            user_id INT NULL,
            registrant_role VARCHAR(20) NOT NULL DEFAULT 'student',
            last_name VARCHAR(100) NOT NULL,
            extension_name VARCHAR(30) NULL,
            first_name VARCHAR(100) NOT NULL,
            middle_name VARCHAR(100) NOT NULL,
            place_of_birth VARCHAR(255) NOT NULL,
            date_of_birth DATE NOT NULL,
            gender VARCHAR(30) NOT NULL DEFAULT 'N/A',
            religion VARCHAR(120) NOT NULL DEFAULT 'N/A',
            blood_type VARCHAR(20) NOT NULL DEFAULT 'N/A',
            contact_number VARCHAR(30) NOT NULL DEFAULT 'N/A',
            email VARCHAR(150) NOT NULL,
            province VARCHAR(120) NOT NULL,
            city_municipality VARCHAR(120) NOT NULL,
            barangay VARCHAR(120) NOT NULL,
            street VARCHAR(180) NOT NULL,
            house_no VARCHAR(80) NOT NULL,
            emergency_name VARCHAR(150) NOT NULL DEFAULT 'N/A',
            emergency_relationship VARCHAR(80) NOT NULL DEFAULT 'N/A',
            emergency_contact_number VARCHAR(30) NOT NULL DEFAULT 'N/A',
            emergency_address VARCHAR(255) NOT NULL DEFAULT 'N/A',
            student_number VARCHAR(10) NULL,
            college VARCHAR(150) NOT NULL,
            course VARCHAR(150) NOT NULL,
            major VARCHAR(120) NOT NULL DEFAULT 'N/A',
            year_section VARCHAR(40) NOT NULL,
            component VARCHAR(20) NULL,
            formal_picture VARCHAR(255) NOT NULL,
            email_sent TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(40) NOT NULL DEFAULT 'submitted',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $columnChecks = [
        'form_id' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN form_id INT NULL AFTER registration_id",
        'user_id' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN user_id INT NULL AFTER form_id",
        'registrant_role' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN registrant_role VARCHAR(20) NOT NULL DEFAULT 'student' AFTER user_id",
        'gender' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN gender VARCHAR(30) NOT NULL DEFAULT 'N/A' AFTER date_of_birth",
        'religion' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN religion VARCHAR(120) NOT NULL DEFAULT 'N/A' AFTER gender",
        'blood_type' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN blood_type VARCHAR(20) NOT NULL DEFAULT 'N/A' AFTER religion",
        'contact_number' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN contact_number VARCHAR(30) NOT NULL DEFAULT 'N/A' AFTER blood_type",
        'emergency_name' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN emergency_name VARCHAR(150) NOT NULL DEFAULT 'N/A' AFTER house_no",
        'emergency_relationship' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN emergency_relationship VARCHAR(80) NOT NULL DEFAULT 'N/A' AFTER emergency_name",
        'emergency_contact_number' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN emergency_contact_number VARCHAR(30) NOT NULL DEFAULT 'N/A' AFTER emergency_relationship",
        'emergency_address' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN emergency_address VARCHAR(255) NOT NULL DEFAULT 'N/A' AFTER emergency_contact_number",
        'student_number' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN student_number VARCHAR(10) NULL AFTER emergency_address",
        'college' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN college VARCHAR(150) NOT NULL DEFAULT 'N/A' AFTER student_number",
        'course' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN course VARCHAR(150) NOT NULL DEFAULT 'N/A' AFTER college",
        'major' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN major VARCHAR(120) NOT NULL DEFAULT 'N/A' AFTER course",
        'component' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN component VARCHAR(20) NULL AFTER year_section",
        'email_sent' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN email_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER formal_picture",
        'status' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN status VARCHAR(40) NOT NULL DEFAULT 'submitted' AFTER email_sent",
        'created_at' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER status",
    ];

    foreach ($columnChecks as $columnName => $alterSql) {
        if (!importColumnExists($conn, 'tbl_public_student_registrations', $columnName)) {
            $conn->exec($alterSql);
        }
    }

    if (!importColumnExists($conn, 'tbl_student', 'student_number')) {
        $conn->exec("ALTER TABLE tbl_student ADD COLUMN student_number VARCHAR(10) NULL AFTER user_id");
    }
}

function activeStudentRegistrationFormForImport(PDO $conn) {
    ensurePublicRegistrationFormsTable($conn);
    $stmt = $conn->prepare("
        SELECT *
        FROM tbl_public_registration_forms
        WHERE is_active = 1 AND registration_role = 'student'
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute();
    $form = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$form) {
        $form = [
            'form_id' => null,
            'fields' => getDefaultPublicRegistrationFields(),
        ];
    } else {
        $form['fields'] = decodePublicRegistrationFields($form['field_config']);
    }

    return $form;
}

function superAdminImportColumns(array $fields) {
    $enabledCount = count(array_filter($fields));
    $studentNumberOnly = !empty($fields['student_number']) && $enabledCount === 1;
    $columns = [];

    if (!empty($fields['name']) || !$studentNumberOnly) {
        $columns['last_name'] = ['Last Name'];
        if (!empty($fields['extension_name'])) $columns['extension_name'] = ['Extension Name'];
        $columns['first_name'] = ['First Name'];
        if (!empty($fields['middle_name'])) $columns['middle_name'] = ['Middle Name'];
    }
    if (!empty($fields['birth_info'])) {
        $columns['place_of_birth'] = ['Place of Birth'];
        $columns['date_of_birth'] = ['Date of Birth', 'Birth Date'];
    }
    if (!empty($fields['religion'])) $columns['religion'] = ['Religion'];
    if (!$studentNumberOnly) {
        $columns['gender'] = ['Gender'];
        $columns['blood_type'] = ['Blood Type'];
        $columns['contact_number'] = ['Contact Number'];
    }
    if (!empty($fields['email'])) $columns['email'] = ['Email', 'Email Address'];
    if (!empty($fields['address'])) {
        $columns['province'] = ['Province'];
        $columns['city_municipality'] = ['City/Municipality', 'City Municipality'];
        $columns['barangay'] = ['Barangay'];
        $columns['street'] = ['Street'];
        $columns['house_no'] = ['House No.', 'House No', 'House Number'];
    }
    if (!$studentNumberOnly) {
        $columns['emergency_name'] = ['Emergency Name'];
        $columns['emergency_relationship'] = ['Emergency Relationship'];
        $columns['emergency_contact_number'] = ['Emergency Contact Number'];
        $columns['emergency_address'] = ['Emergency Address'];
    }
    if (!empty($fields['student_number'])) $columns['student_number'] = ['Student Number'];
    if (!empty($fields['course_section'])) {
        $columns['college'] = ['College'];
        $columns['course'] = ['Course'];
        $columns['major'] = ['Major'];
        $columns['year_section'] = ['Year/Section', 'Year Section'];
    }
    if (!empty($fields['formal_picture'])) $columns['formal_picture'] = ['Formal Picture', 'Formal Picture Path'];

    return $columns;
}

function normalizeImportHeader($value) {
    return strtolower(preg_replace('/[^a-z0-9]+/', '', trim((string) $value)));
}

function cleanImportText($value) {
    return trim(preg_replace('/\s+/', ' ', (string) $value));
}

function isImportNA($value) {
    return strtoupper(cleanImportText($value)) === 'N/A';
}

function importCellValue($worksheet, $cellAddress, $fieldKey = '') {
    $cell = $worksheet->getCell($cellAddress);
    $value = $cell->getCalculatedValue();

    if ($fieldKey === 'date_of_birth' && is_numeric($value)) {
        try {
            return ExcelDate::excelToDateTimeObject($value)->format('m/d/Y');
        } catch (Throwable $error) {
            return (string) $value;
        }
    }

    return cleanImportText($value);
}

function superAdminImportDefaults() {
    return [
        'last_name' => 'Student',
        'extension_name' => 'N/A',
        'first_name' => 'Student',
        'middle_name' => 'N/A',
        'place_of_birth' => 'N/A',
        'date_of_birth' => '1900-01-01',
        'gender' => 'N/A',
        'religion' => 'N/A',
        'blood_type' => 'N/A',
        'contact_number' => 'N/A',
        'email' => '',
        'province' => 'N/A',
        'city_municipality' => 'N/A',
        'barangay' => 'N/A',
        'street' => 'N/A',
        'house_no' => 'N/A',
        'emergency_name' => 'N/A',
        'emergency_relationship' => 'N/A',
        'emergency_contact_number' => 'N/A',
        'emergency_address' => 'N/A',
        'student_number' => null,
        'college' => 'N/A',
        'course' => 'N/A',
        'major' => 'N/A',
        'year_section' => 'N/A',
        'formal_picture' => 'include/logo.png',
    ];
}

function generateUniqueImportCode(PDO $conn, $prefix) {
    do {
        $code = $prefix . '_' . uniqid() . '_' . random_int(1000, 9999);
        $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_student WHERE generated_code = ?");
        $stmt->execute([$code]);
    } while ((int) $stmt->fetchColumn() > 0);

    return $code;
}

function handleSuperAdminImport(PDO $conn, array $file, array &$response) {
    $component = strtoupper(trim($_POST['component'] ?? ''));
    $validComponents = ['CWTS', 'LTS', 'ROTC'];
    if (!in_array($component, $validComponents, true)) {
        $response['message'] = 'Please select a valid NSTP component for this import.';
        return;
    }

    ensureSuperAdminImportSchema($conn);
    $activeForm = activeStudentRegistrationFormForImport($conn);
    $fields = $activeForm['fields'];
    $expectedColumns = superAdminImportColumns($fields);

    $spreadsheet = IOFactory::load($file['tmp_name']);
    $worksheet = $spreadsheet->getActiveSheet();
    $highestRow = $worksheet->getHighestRow();
    $highestColumnIndex = Coordinate::columnIndexFromString($worksheet->getHighestColumn());
    $response['total_rows'] = max(0, $highestRow - 1);

    if ($response['total_rows'] <= 0) {
        $response['message'] = 'No student rows found. Row 1 must be headers and row 2 onward must contain data.';
        return;
    }

    $headerMap = [];
    for ($column = 1; $column <= $highestColumnIndex; $column++) {
        $header = normalizeImportHeader($worksheet->getCell(Coordinate::stringFromColumnIndex($column) . '1')->getValue());
        if ($header !== '') {
            $headerMap[$header] = $column;
        }
    }

    $columnIndexes = [];
    $missingHeaders = [];
    foreach ($expectedColumns as $fieldKey => $labels) {
        $found = null;
        foreach ($labels as $label) {
            $normalized = normalizeImportHeader($label);
            if (isset($headerMap[$normalized])) {
                $found = $headerMap[$normalized];
                break;
            }
        }
        if ($found === null) {
            $missingHeaders[] = $labels[0];
        } else {
            $columnIndexes[$fieldKey] = $found;
        }
    }

    if (!empty($missingHeaders)) {
        $response['message'] = 'Import rejected. Missing required column headers: ' . implode(', ', $missingHeaders) . '.';
        return;
    }

    $naAllowed = ['extension_name', 'middle_name', 'house_no', 'blood_type', 'major'];
    $rows = [];
    $errors = [];
    $seenStudentNumbers = [];
    $seenEmails = [];

    for ($row = 2; $row <= $highestRow; $row++) {
        $record = superAdminImportDefaults();
        $rowHasAnyValue = false;

        foreach ($columnIndexes as $fieldKey => $columnIndex) {
            $cellAddress = Coordinate::stringFromColumnIndex($columnIndex) . $row;
            $value = importCellValue($worksheet, $cellAddress, $fieldKey);
            if ($value !== '') $rowHasAnyValue = true;
            $record[$fieldKey] = $value;
        }

        if (!$rowHasAnyValue) {
            continue;
        }

        foreach (array_keys($columnIndexes) as $fieldKey) {
            $value = cleanImportText($record[$fieldKey] ?? '');
            if ($value === '') {
                $errors[] = "Row $row: Missing " . $expectedColumns[$fieldKey][0] . '.';
                continue;
            }
            if (isImportNA($value) && !in_array($fieldKey, $naAllowed, true)) {
                $errors[] = "Row $row: " . $expectedColumns[$fieldKey][0] . " cannot be N/A.";
            }
            $record[$fieldKey] = $value;
        }

        if (!empty($record['student_number'])) {
            $studentNumber = preg_replace('/\D/', '', (string) $record['student_number']);
            if (!preg_match('/^\d{10}$/', $studentNumber)) {
                $errors[] = "Row $row: Student Number must be exactly 10 digits.";
            }
            if (isset($seenStudentNumbers[$studentNumber])) {
                $errors[] = "Row $row: Duplicate Student Number in uploaded file.";
            }
            $seenStudentNumbers[$studentNumber] = true;
            $record['student_number'] = $studentNumber;
        }

        if (!empty($record['email'])) {
            $record['email'] = strtolower($record['email']);
            if (!filter_var($record['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Row $row: Email Address is invalid.";
            }
            if (isset($seenEmails[$record['email']])) {
                $errors[] = "Row $row: Duplicate Email Address in uploaded file.";
            }
            $seenEmails[$record['email']] = true;
        } elseif (!empty($record['student_number'])) {
            $record['email'] = 'student' . $record['student_number'] . '@no-email.tau-nstp.local';
        }

        if (!empty($record['date_of_birth']) && $record['date_of_birth'] !== '1900-01-01') {
            $date = DateTime::createFromFormat('m/d/Y', $record['date_of_birth']);
            $dateErrors = DateTime::getLastErrors();
            $warningCount = is_array($dateErrors) ? (int) $dateErrors['warning_count'] : 0;
            $errorCount = is_array($dateErrors) ? (int) $dateErrors['error_count'] : 0;
            if (!$date || $warningCount > 0 || $errorCount > 0) {
                $errors[] = "Row $row: Date of Birth must use mm/dd/yyyy format.";
            } else {
                $record['date_of_birth'] = $date->format('Y-m-d');
            }
        }

        if (($fields['religion'] ?? false) && !isImportNA($record['religion'])) {
            try {
                if (in_array($record['religion'], philippinesReligionOptions(), true)) {
                    $record['religion'] = normalizeSubmittedReligion($record['religion']);
                } elseif (isReligionAbbreviationOnly($record['religion'])) {
                    $errors[] = "Row $row: Religion must be a full religion name, not an abbreviation.";
                }
            } catch (InvalidArgumentException $error) {
                $errors[] = "Row $row: " . $error->getMessage();
            }
        }

        if (($fields['course_section'] ?? false) && !validateCollegeCourseMajor($record['college'], $record['course'], $record['major'])) {
            $errors[] = "Row $row: College, Course, and Major combination is invalid. Use N/A only when applicable.";
        }

        $record['_row'] = $row;
        $rows[] = $record;
    }

    if (empty($rows)) {
        $response['message'] = 'No valid student data rows found.';
        return;
    }

    if (!empty($seenStudentNumbers)) {
        $placeholders = implode(',', array_fill(0, count($seenStudentNumbers), '?'));
        $stmt = $conn->prepare("SELECT student_number FROM tbl_public_student_registrations WHERE student_number IN ($placeholders)");
        $stmt->execute(array_keys($seenStudentNumbers));
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $existingStudentNumber) {
            $errors[] = "Student Number $existingStudentNumber already exists in public registrations.";
        }

        $stmt = $conn->prepare("SELECT student_number FROM tbl_student WHERE student_number IN ($placeholders)");
        $stmt->execute(array_keys($seenStudentNumbers));
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $existingStudentNumber) {
            $errors[] = "Student Number $existingStudentNumber already exists in the student masterlist.";
        }
    }

    if (!empty($seenEmails)) {
        $placeholders = implode(',', array_fill(0, count($seenEmails), '?'));
        $stmt = $conn->prepare("SELECT email FROM tbl_public_student_registrations WHERE email IN ($placeholders)");
        $stmt->execute(array_keys($seenEmails));
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $existingEmail) {
            $errors[] = "Email $existingEmail already exists in public registrations.";
        }
    }

    if (!empty($errors)) {
        $response['message'] = 'Import rejected. Complete all required registration details before uploading again.';
        $response['errors'] = array_slice($errors, 0, 100);
        return;
    }

    $conn->beginTransaction();
    try {
        $registrationStmt = $conn->prepare("
            INSERT INTO tbl_public_student_registrations (
                form_id, user_id, registrant_role, last_name, extension_name, first_name, middle_name, place_of_birth,
                date_of_birth, gender, religion, blood_type, contact_number, email, province, city_municipality, barangay, street, house_no,
                emergency_name, emergency_relationship, emergency_contact_number, emergency_address,
                student_number, college, course, major, year_section, component, formal_picture, status
            ) VALUES (?, NULL, 'student', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'imported_by_super_admin')
        ");
        $studentStmt = $conn->prepare("
            INSERT INTO tbl_student (user_id, student_number, student_name, original_section, course_section, generated_code, qr_code, created_by)
            VALUES (NULL, ?, ?, ?, ?, ?, NULL, NULL)
        ");

        foreach ($rows as $record) {
            $registrationStmt->execute([
                $activeForm['form_id'] ?? null,
                $record['last_name'],
                isImportNA($record['extension_name']) ? 'N/A' : $record['extension_name'],
                $record['first_name'],
                $record['middle_name'],
                $record['place_of_birth'],
                $record['date_of_birth'],
                $record['gender'],
                $record['religion'],
                $record['blood_type'],
                $record['contact_number'],
                $record['email'],
                $record['province'],
                $record['city_municipality'],
                $record['barangay'],
                $record['street'],
                $record['house_no'],
                $record['emergency_name'],
                $record['emergency_relationship'],
                $record['emergency_contact_number'],
                $record['emergency_address'],
                $record['student_number'],
                $record['college'],
                $record['course'],
                $record['major'],
                $record['year_section'],
                $component,
                $record['formal_picture'],
            ]);

            $studentNameParts = [
                $record['last_name'] . ',',
                $record['first_name'],
                isImportNA($record['middle_name']) ? '' : $record['middle_name'],
                isImportNA($record['extension_name']) ? '' : $record['extension_name'],
            ];
            $studentName = trim(preg_replace('/\s+/', ' ', implode(' ', array_filter($studentNameParts))));
            $originalSection = autoSectionOriginalSection($record['course'], $record['year_section'], $component);
            $autoFolder = autoSectionFolderForStudent(
                $conn,
                $component,
                $record['course'],
                $record['year_section'],
                $originalSection
            );
            $generatedCode = !empty($record['student_number'])
                ? 'PUB_' . $record['student_number']
                : generateUniqueImportCode($conn, 'IMP');

            $studentStmt->execute([
                $record['student_number'],
                $studentName,
                $originalSection,
                $autoFolder,
                $generatedCode,
            ]);
        }

        $conn->commit();
        $response['success'] = true;
        $response['imported'] = count($rows);
        $response['message'] = 'Successfully imported ' . count($rows) . ' complete student registration record(s) with automatic folder sectioning.';
    } catch (Throwable $error) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $response['message'] = 'Import failed while saving records: ' . $error->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    $file = $_FILES['excel_file'];
    
    // Validate file
    $allowedExts = ['xls', 'xlsx', 'csv'];
    $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($fileExt, $allowedExts)) {
        $response['message'] = 'Invalid file type. Please upload an Excel file (.xlsx, .xls, or .csv)';
        echo json_encode($response);
        exit;
    }
    
    // Check file size (max 10MB)
    if ($file['size'] > 10 * 1024 * 1024) {
        $response['message'] = 'File size too large. Maximum 10MB allowed.';
        echo json_encode($response);
        exit;
    }

    if ($user_role === 'super_admin') {
        try {
            handleSuperAdminImport($conn, $file, $response);
        } catch (Throwable $error) {
            $response['message'] = 'Error processing Excel file: ' . $error->getMessage();
            error_log("Super Admin Import Excel Error: " . $error->getMessage());
        }

        if (ob_get_length()) ob_clean();
        echo json_encode($response);
        exit();
    }
    
    try {
        // Get target folder from form data. A facilitator can be assigned later.
        $targetFacilitatorId = null;
        $targetSection = isset($_POST['import_section']) ? trim($_POST['import_section']) : '';

        if (empty($targetSection)) {
            $response['message'] = 'Please select a target folder.';
            echo json_encode($response);
            exit;
        }

        require_once '../include/user-permissions.php';
        $currentUser = getCurrentUserRecord($conn);
        $coordinatorProgram = normalizeProgram($currentUser['program'] ?? ($_SESSION['program'] ?? null));
        if (!$coordinatorProgram) {
            $response['message'] = 'Coordinator program is missing.';
            echo json_encode($response);
            exit;
        }

        if (!sectionFolderExists($conn, $coordinatorProgram, $targetSection)) {
            $response['message'] = 'Please create the folder first before importing students.';
            echo json_encode($response);
            exit;
        }

        $folderStmt = $conn->prepare("
            SELECT ads.user_id
            FROM tbl_admin_sections ads
            INNER JOIN tbl_users u ON u.user_id = ads.user_id
            WHERE ads.course_section = ?
              AND u.role = 'facilitator'
              AND u.program = ?
            LIMIT 1
        ");
        $folderStmt->execute([$targetSection, $coordinatorProgram]);
        $assignedFacilitatorId = $folderStmt->fetchColumn();
        if ($assignedFacilitatorId) {
            $targetFacilitatorId = (int) $assignedFacilitatorId;
        }
        
        // Load the Excel file
        $spreadsheet = IOFactory::load($file['tmp_name']);
        $worksheet = $spreadsheet->getActiveSheet();
        
        // Get the highest row number and column letter
        $highestRow = $worksheet->getHighestRow();
        $highestColumn = $worksheet->getHighestColumn();
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);
        
        $response['total_rows'] = $highestRow - 1; // Subtract header row
        
        $importedCount = 0;
        $errors = [];
        $skippedCount = 0;
        
        // Check if original_section column exists in database
        try {
            $testStmt = $conn->prepare("SELECT original_section FROM tbl_student LIMIT 1");
            $testStmt->execute();
            $columnExists = true;
        } catch (PDOException $e) {
            // Column doesn't exist, add it
            try {
                $alterStmt = $conn->prepare("ALTER TABLE tbl_student ADD COLUMN original_section VARCHAR(255) DEFAULT NULL AFTER student_name");
                $alterStmt->execute();
                $columnExists = true;
                error_log("Added original_section column to tbl_student");
            } catch (PDOException $alterError) {
                error_log("Failed to add original_section column: " . $alterError->getMessage());
                $columnExists = false;
                $errors[] = "Warning: Could not add original_section column. Original sections will not be saved.";
            }
        }
        
        // Get existing students for this folder to check duplicates
        $existingStmt = $conn->prepare("
            SELECT student_name, original_section, course_section 
            FROM tbl_student 
            WHERE course_section = ?
        ");
        $existingStmt->execute([$targetSection]);
        $existingStudents = $existingStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Create lookup arrays for faster duplicate checking
        $existingByNameAndOriginal = [];
        $existingByNameAndFolder = [];
        
        foreach ($existingStudents as $student) {
            $name = strtolower(trim($student['student_name']));
            $original = strtolower(trim($student['original_section'] ?? ''));
            $folder = strtolower(trim($student['course_section']));
            
            if (!empty($original)) {
                $existingByNameAndOriginal[$name][$original] = true;
            }
            $existingByNameAndFolder[$name][$folder] = true;
        }
        
        // Start from row 2 (assuming row 1 has headers) and go through all rows
        for ($row = 2; $row <= $highestRow; $row++) {
            // Read cell values
            $studentName = trim($worksheet->getCell('A' . $row)->getValue() ?? '');
            $originalSection = trim($worksheet->getCell('B' . $row)->getValue() ?? '');
            
            $adminFolder = $targetSection; // Default to target section
            
            // Skip empty rows
            if (empty($studentName)) {
                continue;
            }
            
            // Log for debugging
            error_log("Processing row $row: Name='$studentName', Original='$originalSection', Admin='$adminFolder'");
            
            if (empty($originalSection)) {
                $errors[] = "Row $row: Missing original section for student '$studentName'";
                $skippedCount++;
                continue;
            }
            
            // Check for duplicates - ONLY check within the same admin folder
            $isDuplicate = false;
            $studentNameLower = strtolower($studentName);
            $originalSectionLower = strtolower($originalSection);
            $adminFolderLower = strtolower($adminFolder);
            
            // Check if student with same name and same ORIGINAL section already exists in ANY folder
            if (isset($existingByNameAndOriginal[$studentNameLower][$originalSectionLower])) {
                $isDuplicate = true;
                $errors[] = "Row $row: Student '$studentName' with original section '$originalSection' already exists in the system";
            }
            // Check if student with same name already exists in the SAME admin folder
            else if (isset($existingByNameAndFolder[$studentNameLower][$adminFolderLower])) {
                $isDuplicate = true;
                $errors[] = "Row $row: Student '$studentName' already exists in admin folder '$adminFolder'";
            }
            
            if ($isDuplicate) {
                $skippedCount++;
                continue;
            }
            
            // Generate unique code
            $generatedCode = 'STU_' . uniqid() . '_' . rand(1000, 9999);
            
            // Insert new student
            try {
                if ($columnExists) {
                    $stmt = $conn->prepare("
                        INSERT INTO tbl_student 
                        (student_name, original_section, course_section, generated_code, created_by) 
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $result = $stmt->execute([$studentName, $originalSection, $adminFolder, $generatedCode, $targetFacilitatorId]);
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO tbl_student 
                        (student_name, course_section, generated_code, created_by) 
                        VALUES (?, ?, ?, ?)
                    ");
                    $result = $stmt->execute([$studentName, $adminFolder, $generatedCode, $targetFacilitatorId]);
                }
                
                if ($result) {
                    $importedCount++;
                    error_log("Successfully imported student: $studentName");
                    
                    // Add to lookup arrays to prevent duplicates within same import
                    $existingByNameAndOriginal[$studentNameLower][$originalSectionLower] = true;
                    $existingByNameAndFolder[$studentNameLower][$adminFolderLower] = true;
                    
                } else {
                    $errors[] = "Row $row: Failed to insert student '$studentName'";
                    $skippedCount++;
                }
                
            } catch (PDOException $e) {
                // Check if it's a duplicate entry error
                if ($e->errorInfo[1] == 1062) { // MySQL duplicate entry error code
                    $errors[] = "Row $row: Student '{$studentName}' already exists (duplicate entry)";
                } else {
                    $errors[] = "Row $row: Failed to import '{$studentName}' - " . $e->getMessage();
                }
                error_log("Import error for row $row: " . $e->getMessage());
                $skippedCount++;
            }
        }
        
        // Prepare success message
        if ($importedCount > 0) {
            $response['success'] = true;
            $assignmentText = $targetFacilitatorId ? 'assigned facilitator folder' : 'pending folder';
            $message = "Successfully imported {$importedCount} out of {$response['total_rows']} students into {$assignmentText} '{$targetSection}'.";
            if ($skippedCount > 0) {
                $message .= " Skipped {$skippedCount} duplicate/invalid entries.";
            }
            if (!empty($errors)) {
                $message .= " " . count($errors) . " warnings/errors occurred.";
            }
            $response['message'] = $message;
            $response['imported'] = $importedCount;
            $response['skipped'] = $skippedCount;
            $response['errors'] = array_slice($errors, 0, 50); // Limit to 50 errors
        } else {
            $response['message'] = 'No students were imported. Check errors below.';
            $response['errors'] = $errors;
        }
        
    } catch (Exception $e) {
        $response['message'] = 'Error processing Excel file: ' . $e->getMessage();
        error_log("Import Excel Error: " . $e->getMessage());
    }
} else {
    $response['message'] = 'No file uploaded or invalid request.';
}

// Clear any output buffering
if (ob_get_length()) ob_clean();

echo json_encode($response);
exit();
?>
