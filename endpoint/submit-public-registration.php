<?php
session_start();
header('Content-Type: application/json');
date_default_timezone_set('Asia/Manila');

require_once '../conn/conn.php';
require_once '../include/public-registration-forms.php';
require_once '../include/college-courses.php';
require_once '../include/student-account-automation.php';
require_once '../include/attendance-settings.php';
require_once '../include/religions.php';
require_once '../include/automatic-sectioning.php';

$response = ['success' => false, 'message' => ''];
$studentRegistrationLockAcquired = false;

function failRegistration($message) {
    echo json_encode(['success' => false, 'message' => $message]);
    exit();
}

function cleanText($value) {
    return trim(preg_replace('/\s+/', ' ', (string) $value));
}

function columnExists(PDO $conn, $tableName, $columnName) {
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

function indexExists(PDO $conn, $tableName, $indexName) {
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND INDEX_NAME = ?
    ");
    $stmt->execute([$tableName, $indexName]);
    return (int) $stmt->fetchColumn() > 0;
}

function ensurePublicRegistrationTable(PDO $conn) {
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
            height VARCHAR(30) NULL,
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
            rotc_ms_level VARCHAR(20) NULL,
            rotc_completion_proof VARCHAR(255) NULL,
            formal_picture VARCHAR(255) NOT NULL,
            email_sent TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(40) NOT NULL DEFAULT 'submitted',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_form_id (form_id),
            INDEX idx_email (email),
            INDEX idx_course_year (college, course, year_section),
            INDEX idx_status (status),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    ensurePublicRegistrationFormsTable($conn);

    try {
        $columnChecks = [
            'form_id' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN form_id INT NULL AFTER registration_id",
            'user_id' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN user_id INT NULL AFTER form_id",
            'registrant_role' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN registrant_role VARCHAR(20) NOT NULL DEFAULT 'student' AFTER user_id",
            'religion' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN religion VARCHAR(120) NOT NULL DEFAULT 'N/A' AFTER date_of_birth",
            'gender' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN gender VARCHAR(30) NOT NULL DEFAULT 'N/A' AFTER date_of_birth",
            'blood_type' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN blood_type VARCHAR(20) NOT NULL DEFAULT 'N/A' AFTER religion",
            'height' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN height VARCHAR(30) NULL AFTER blood_type",
            'contact_number' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN contact_number VARCHAR(30) NOT NULL DEFAULT 'N/A' AFTER blood_type",
            'emergency_name' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN emergency_name VARCHAR(150) NOT NULL DEFAULT 'N/A' AFTER house_no",
            'emergency_relationship' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN emergency_relationship VARCHAR(80) NOT NULL DEFAULT 'N/A' AFTER emergency_name",
            'emergency_contact_number' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN emergency_contact_number VARCHAR(30) NOT NULL DEFAULT 'N/A' AFTER emergency_relationship",
            'emergency_address' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN emergency_address VARCHAR(255) NOT NULL DEFAULT 'N/A' AFTER emergency_contact_number",
            'student_number' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN student_number VARCHAR(10) NULL AFTER house_no",
            'college' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN college VARCHAR(150) NOT NULL DEFAULT '' AFTER student_number",
            'major' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN major VARCHAR(120) NOT NULL DEFAULT 'N/A' AFTER course",
            'component' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN component VARCHAR(20) NULL AFTER year_section",
            'rotc_ms_level' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN rotc_ms_level VARCHAR(20) NULL AFTER component",
            'rotc_completion_proof' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN rotc_completion_proof VARCHAR(255) NULL AFTER rotc_ms_level",
            'email_sent' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN email_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER formal_picture",
            'status' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN status VARCHAR(40) NOT NULL DEFAULT 'submitted' AFTER email_sent",
            'created_at' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER status",
        ];

        foreach ($columnChecks as $columnName => $alterSql) {
            if (!columnExists($conn, 'tbl_public_student_registrations', $columnName)) {
                $conn->exec($alterSql);
            }
        }

        $conn->exec("ALTER TABLE tbl_public_student_registrations MODIFY student_number VARCHAR(10) NULL");
        $conn->exec("ALTER TABLE tbl_public_student_registrations MODIFY course VARCHAR(150) NOT NULL");
        if (columnExists($conn, 'tbl_public_student_registrations', 'account_username')) {
            $conn->exec("ALTER TABLE tbl_public_student_registrations DROP COLUMN account_username");
        }

        ensureStudentNumberColumn($conn);

        if (columnExists($conn, 'tbl_users', 'profile_picture') === false) {
            $conn->exec("ALTER TABLE tbl_users ADD COLUMN profile_picture VARCHAR(255) NULL");
        }
    } catch (Throwable $error) {
        // The submit validation below will still protect new data if the column exists.
    }
}

function detectImageExtension($filePath) {
    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    $mime = null;
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($filePath);
    } elseif (function_exists('mime_content_type')) {
        $mime = mime_content_type($filePath);
    }

    if ($mime && isset($allowedTypes[$mime])) {
        return $allowedTypes[$mime];
    }

    if (function_exists('exif_imagetype')) {
        $imageType = @exif_imagetype($filePath);
        $typeMap = [
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
        ];

        if (defined('IMAGETYPE_WEBP')) {
            $typeMap[IMAGETYPE_WEBP] = 'webp';
        }

        return $typeMap[$imageType] ?? null;
    }

    return null;
}

function appBaseUrl() {
    $isHttps = (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    );
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(dirname(dirname($_SERVER['PHP_SELF'])), '/\\');
    return $scheme . '://' . $host . ($basePath ? $basePath : '');
}

function generatePassword($length = 10) {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $password;
}

function uniqueUsername(PDO $conn, $email, $lastName) {
    $base = strtolower(preg_replace('/[^a-z0-9]+/', '', strtok($email, '@') ?: $lastName));
    if ($base === '') {
        $base = 'student';
    }
    $base = substr($base, 0, 28);
    $username = $base;
    $counter = 1;

    while (true) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_users WHERE username = ?");
        $stmt->execute([$username]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $username;
        }
        $username = $base . $counter;
        $counter++;
    }
}

function uniqueUsernameFromBase(PDO $conn, $baseValue) {
    $base = strtolower(preg_replace('/[^a-z0-9]+/', '', (string) $baseValue));
    if ($base === '') {
        $base = 'student';
    }
    $base = substr($base, 0, 28);
    $username = $base;
    $counter = 1;

    while (true) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_users WHERE username = ?");
        $stmt->execute([$username]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $username;
        }
        $username = $base . $counter;
        $counter++;
    }
}

function fullNameFromRegistrationParts($firstName, $middleName, $lastName, $extensionName = '') {
    $firstName = cleanText($firstName);
    $middleName = cleanText($middleName);
    $lastName = cleanText($lastName);
    $extensionName = cleanText($extensionName);
    $middleInitial = ($middleName !== '' && strtoupper($middleName) !== 'N/A')
        ? strtoupper(substr($middleName, 0, 1)) . '.'
        : '';

    $firstNameParts = array_values(array_filter([
        $firstName,
        $middleInitial,
        strtoupper($extensionName) === 'N/A' ? '' : $extensionName,
    ], fn($part) => $part !== ''));

    $name = $lastName !== ''
        ? $lastName . ', ' . implode(' ', $firstNameParts)
        : implode(' ', $firstNameParts);

    return trim(preg_replace('/\s+/', ' ', $name), ' ,');
}

function createFacilitatorAccountFromPublicRegistration(PDO $conn, array $registration) {
    $email = strtolower(cleanText($registration['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || isPlaceholderEmail($email)) {
        throw new Exception('A valid email address is required for facilitator account creation.');
    }

    $fullName = cleanText($registration['full_name'] ?? '');
    if ($fullName === '') {
        $fullName = fullNameFromRegistrationParts(
            cleanText($registration['first_name'] ?? ''),
            cleanText($registration['middle_name'] ?? 'N/A') ?: 'N/A',
            cleanText($registration['last_name'] ?? ''),
            cleanText($registration['extension_name'] ?? 'N/A') ?: 'N/A'
        );
    }
    if ($fullName === '') {
        $fullName = $email;
    }

    $stmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) {
        throw new Exception('This email address already has an account.');
    }

    $username = uniqueUsername($conn, $email, $registration['last_name'] ?? 'facilitator');
    $password = generatePassword(12);

    $conn->beginTransaction();
    try {
        $stmt = $conn->prepare("
            INSERT INTO tbl_users (username, email, password_hash, full_name, role, program, profile_picture)
            VALUES (?, ?, ?, ?, 'facilitator', ?, ?)
        ");
        $stmt->execute([
            $username,
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            $fullName,
            normalizeProgram($registration['component'] ?? null),
            $registration['formal_picture'] ?? null,
        ]);
        $userId = (int) $conn->lastInsertId();

        $stmt = $conn->prepare("
            INSERT INTO tbl_public_student_registrations (
                form_id, user_id, registrant_role, last_name, extension_name, first_name, middle_name, place_of_birth,
                date_of_birth, religion, email, province, city_municipality, barangay, street, house_no,
                student_number, college, course, major, year_section, component, formal_picture, status
            ) VALUES (?, ?, 'facilitator', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $registration['form_id'],
            $userId,
            $registration['last_name'],
            $registration['extension_name'] ?: null,
            $registration['first_name'],
            $registration['middle_name'],
            $registration['place_of_birth'],
            $registration['date_of_birth'],
            $registration['religion'] ?? 'N/A',
            $email,
            $registration['province'],
            $registration['city_municipality'],
            $registration['barangay'],
            $registration['street'],
            $registration['house_no'],
            null,
            $registration['college'],
            $registration['course'],
            $registration['major'],
            $registration['year_section'],
            normalizeProgram($registration['component'] ?? null),
            $registration['formal_picture'],
            'facilitator_account',
        ]);

        $conn->commit();
        $emailSent = sendAccountCredentialsEmail($email, $fullName, $username, $password, 'facilitator');
        if ($emailSent) {
            $stmt = $conn->prepare("UPDATE tbl_public_student_registrations SET email_sent = 1 WHERE user_id = ? AND registrant_role = 'facilitator'");
            $stmt->execute([$userId]);
        }

        return ['created' => true, 'user_id' => $userId, 'username' => $username, 'email_sent' => $emailSent];
    } catch (Throwable $error) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $error;
    }
}

function isStudentNumberOnlyForm(array $enabledFields) {
    foreach ($enabledFields as $fieldKey => $enabled) {
        if ($fieldKey !== 'student_number' && !empty($enabled)) {
            return false;
        }
    }

    return !empty($enabledFields['student_number']);
}

function findLatestPublicRegistrationByStudentNumber(PDO $conn, $studentNumber) {
    $stmt = $conn->prepare("
        SELECT *
        FROM tbl_public_student_registrations
        WHERE student_number = ?
          AND COALESCE(status, 'submitted') <> 'account_deleted'
        ORDER BY
            CASE WHEN email LIKE '%@no-email.tau-nstp.local' THEN 1 ELSE 0 END,
            created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$studentNumber]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function assertStudentFullRegistrationIsUnique(PDO $conn, $studentNumber, $email) {
    $studentNumber = preg_replace('/\D/', '', (string) $studentNumber);
    $email = strtolower(cleanText($email));

    if ($studentNumber !== '') {
        $stmt = $conn->prepare("
            SELECT registration_id
            FROM tbl_public_student_registrations
            WHERE registrant_role = 'student'
              AND student_number = ?
              AND COALESCE(status, 'submitted') NOT IN ('attendance_only', 'account_deleted')
            LIMIT 1
        ");
        $stmt->execute([$studentNumber]);
        if ($stmt->fetchColumn()) {
            failRegistration('This student number already has a registration submission.');
        }

        $stmt = $conn->prepare("SELECT tbl_student_id FROM tbl_student WHERE student_number = ? AND user_id IS NOT NULL LIMIT 1");
        $stmt->execute([$studentNumber]);
        if ($stmt->fetchColumn()) {
            failRegistration('This student number is already registered.');
        }

        $stmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE username = ? AND role = 'student' LIMIT 1");
        $stmt->execute([$studentNumber]);
        if ($stmt->fetchColumn()) {
            failRegistration('An account already exists for this student number. Please coordinate with the NSTP Office to verify your account.');
        }
    }

    if ($email !== '' && strpos($email, '@no-email.tau-nstp.local') === false) {
        $stmt = $conn->prepare("
            SELECT registration_id
            FROM tbl_public_student_registrations
            WHERE registrant_role = 'student'
              AND email = ?
              AND COALESCE(status, 'submitted') NOT IN ('attendance_only', 'account_deleted')
            LIMIT 1
        ");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn()) {
            failRegistration('This email address already has a public registration submission.');
        }

        $stmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn()) {
            failRegistration('An account already exists for this email address. Please coordinate with the NSTP Office to verify your account.');
        }
    }
}

function acquireStudentFullRegistrationLock(PDO $conn) {
    $stmt = $conn->query("SELECT GET_LOCK('tau_nstp_student_full_registration', 10)");
    if ((int) $stmt->fetchColumn() !== 1) {
        failRegistration('Another registration is being saved. Please wait a moment and try again.');
    }
}

function releaseStudentFullRegistrationLock(PDO $conn) {
    try {
        $conn->query("SELECT RELEASE_LOCK('tau_nstp_student_full_registration')");
    } catch (Throwable $ignored) {
        // MySQL also releases named locks when this request closes its connection.
    }
}

function publicRegistrationFullName(array $registration) {
    $lastName = cleanText($registration['last_name'] ?? '');
    $firstName = cleanText($registration['first_name'] ?? '');
    $middleName = cleanText($registration['middle_name'] ?? '');
    $middleInitial = ($middleName !== '' && strtoupper($middleName) !== 'N/A')
        ? strtoupper(substr($middleName, 0, 1)) . '.'
        : '';

    $firstNameParts = array_values(array_filter([$firstName, $middleInitial], fn($part) => $part !== ''));
    $name = $lastName !== ''
        ? $lastName . ', ' . implode(' ', $firstNameParts)
        : implode(' ', $firstNameParts);

    if ($name === ',' || $name === '') {
        $name = 'Student #' . ($registration['student_number'] ?? '');
    }

    return trim($name, ' ,');
}

function publicRegistrationCourseSection(PDO $conn, array $registration) {
    $component = normalizeProgram($registration['component'] ?? null);
    $component = $component ?: 'PUBLIC';

    if (autoSectionUsesAutomaticFolders($component)) {
        return autoSectionFolderForStudent(
            $conn,
            $component,
            $registration['course'] ?? '',
            $registration['year_section'] ?? '',
            publicRegistrationOriginalSection($registration)
        );
    }

    return $component;
}

function publicRegistrationOriginalSection(array $registration) {
    $course = cleanText($registration['course'] ?? '');
    $yearSection = cleanText($registration['year_section'] ?? '');

    return autoSectionOriginalSection($course, $yearSection, 'Public Registration');
}

function ensurePublicRegistrationStudent(PDO $conn, array $registration) {
    $studentNumber = preg_replace('/\D/', '', (string) ($registration['student_number'] ?? ''));
    if (!preg_match('/^\d{10}$/', $studentNumber)) {
        throw new Exception('Student Number must be exactly 10 digits and numbers only.');
    }

    $stmt = $conn->prepare("
        SELECT s.*, creator.role AS creator_role
        FROM tbl_student s
        LEFT JOIN tbl_users creator ON s.created_by = creator.user_id
        WHERE s.student_number = ?
        LIMIT 1
    ");
    $stmt->execute([$studentNumber]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    $studentName = publicRegistrationFullName($registration);
    $originalSection = publicRegistrationOriginalSection($registration);
    $courseSection = publicRegistrationCourseSection($conn, $registration);
    if ($originalSection === '' || $originalSection === 'N/A') {
        $originalSection = $courseSection;
    }

    if ($student) {
        $isAssignedToFacilitator = !empty($student['created_by']) && ($student['creator_role'] ?? '') === 'facilitator';

        if ($isAssignedToFacilitator) {
            $stmt = $conn->prepare("
                UPDATE tbl_student
                SET student_name = ?, original_section = ?
                WHERE tbl_student_id = ?
            ");
            $stmt->execute([$studentName, $originalSection, $student['tbl_student_id']]);
        } else {
            $stmt = $conn->prepare("
                UPDATE tbl_student
                SET student_name = ?, original_section = ?, course_section = ?
                WHERE tbl_student_id = ?
            ");
            $stmt->execute([$studentName, $originalSection, $courseSection, $student['tbl_student_id']]);
            $student['course_section'] = $courseSection;
        }

        $student['student_name'] = $studentName;
        $student['original_section'] = $originalSection;
        return $student;
    }

    $generatedCode = 'PUB_' . $studentNumber;
    $stmt = $conn->prepare("
        INSERT INTO tbl_student (user_id, student_number, student_name, original_section, course_section, generated_code, qr_code, created_by)
        VALUES (?, ?, ?, ?, ?, ?, NULL, NULL)
    ");
    $stmt->execute([
        $registration['user_id'] ?? null,
        $studentNumber,
        $studentName,
        $originalSection,
        $courseSection,
        $generatedCode,
    ]);

    return [
        'tbl_student_id' => (int) $conn->lastInsertId(),
        'student_number' => $studentNumber,
        'student_name' => $studentName,
        'course_section' => $courseSection,
        'generated_code' => $generatedCode,
    ];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    failRegistration('Invalid request method.');
}

try {
    $formId = (int) ($_POST['form_id'] ?? 0);
    $stmt = $conn->prepare("SELECT * FROM tbl_public_registration_forms WHERE form_id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$formId]);
    $publicForm = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$publicForm) {
        failRegistration('This public registration form is no longer available.');
    }

    $enabledFields = decodePublicRegistrationFields($publicForm['field_config']);
    $studentNumberOnlyForm = isStudentNumberOnlyForm($enabledFields);
    $studentNumberBased = $studentNumberOnlyForm;
    $registrantRole = normalizePublicRegistrationRole($publicForm['registration_role'] ?? 'student');
    $isFacilitatorRegistration = $registrantRole === 'facilitator';
    $showNameFields = !empty($enabledFields['name']);
    $showEmailField = !empty($enabledFields['email']);

    $requiredFields = [];

    if ($isFacilitatorRegistration && $showNameFields) {
        $requiredFields[] = 'full_name';
    } elseif (!$studentNumberBased && $showNameFields) {
        array_push($requiredFields, 'last_name', 'first_name');
    }

    if (!$studentNumberBased && ($showEmailField || $isFacilitatorRegistration)) {
        $requiredFields[] = 'email';
    }

    if (!$studentNumberBased && !$isFacilitatorRegistration && $enabledFields['middle_name']) $requiredFields[] = 'middle_name';
    if (!$studentNumberBased && !$isFacilitatorRegistration && $enabledFields['birth_info']) array_push($requiredFields, 'place_of_birth', 'date_of_birth');
    if (!$studentNumberBased && !$isFacilitatorRegistration && $enabledFields['religion']) $requiredFields[] = 'religion';
    if (!$studentNumberBased && !$isFacilitatorRegistration) array_push($requiredFields, 'gender', 'contact_number', 'emergency_name', 'emergency_relationship', 'emergency_contact_number');
    if (!$studentNumberBased && !$isFacilitatorRegistration && $enabledFields['address']) array_push($requiredFields, 'province', 'city_municipality', 'barangay', 'street', 'house_no');
    if (!$isFacilitatorRegistration && $enabledFields['student_number']) $requiredFields[] = 'student_number';
    if (!$isFacilitatorRegistration && !$studentNumberOnlyForm && $enabledFields['course_section']) array_push($requiredFields, 'college', 'course', 'year_section');

    foreach ($requiredFields as $field) {
        if (cleanText($_POST[$field] ?? '') === '') {
            failRegistration('Please complete all required fields.');
        }
    }

    $fullName = cleanText($_POST['full_name'] ?? '');
    $lastName = cleanText($_POST['last_name'] ?? '');
    $extensionName = cleanText($_POST['extension_name'] ?? '');
    $firstName = cleanText($_POST['first_name'] ?? '');
    $middleName = cleanText($_POST['middle_name'] ?? '');
    $placeOfBirth = cleanText($_POST['place_of_birth'] ?? '');
    $dateOfBirthInput = cleanText($_POST['date_of_birth'] ?? '');
    $gender = (!$isFacilitatorRegistration && !$studentNumberBased) ? cleanText($_POST['gender'] ?? '') : 'N/A';
    $religion = (!$isFacilitatorRegistration && $enabledFields['religion']) ? cleanText($_POST['religion'] ?? '') : 'N/A';
    $religionOther = (!$isFacilitatorRegistration && $enabledFields['religion']) ? cleanText($_POST['religion_other'] ?? '') : '';
    $bloodType = (!$isFacilitatorRegistration && !$studentNumberBased) ? cleanText($_POST['blood_type'] ?? '') : 'N/A';
    $contactNumber = (!$isFacilitatorRegistration && !$studentNumberBased) ? cleanText($_POST['contact_number'] ?? '') : 'N/A';
    $email = strtolower(cleanText($_POST['email'] ?? ''));
    $province = $enabledFields['address'] ? cleanText($_POST['province'] ?? '') : 'N/A';
    $cityMunicipality = $enabledFields['address'] ? cleanText($_POST['city_municipality'] ?? '') : 'N/A';
    $barangay = $enabledFields['address'] ? cleanText($_POST['barangay'] ?? '') : 'N/A';
    $street = $enabledFields['address'] ? cleanText($_POST['street'] ?? '') : 'N/A';
    $houseNo = $enabledFields['address'] ? cleanText($_POST['house_no'] ?? '') : 'N/A';
    $emergencyName = (!$isFacilitatorRegistration && !$studentNumberBased) ? cleanText($_POST['emergency_name'] ?? '') : 'N/A';
    $emergencyRelationship = (!$isFacilitatorRegistration && !$studentNumberBased) ? cleanText($_POST['emergency_relationship'] ?? '') : 'N/A';
    $emergencyContactNumber = (!$isFacilitatorRegistration && !$studentNumberBased) ? cleanText($_POST['emergency_contact_number'] ?? '') : 'N/A';
    $emergencyAddress = (!$isFacilitatorRegistration && !$studentNumberBased) ? cleanText($_POST['emergency_address'] ?? '') : 'N/A';
    $studentNumber = (!$isFacilitatorRegistration && $enabledFields['student_number']) ? cleanText($_POST['student_number']) : null;
    $college = $enabledFields['course_section'] ? cleanText($_POST['college'] ?? '') : 'N/A';
    $course = $enabledFields['course_section'] ? cleanText($_POST['course'] ?? '') : 'N/A';
    $major = $enabledFields['course_section'] ? cleanText($_POST['major'] ?? '') : 'N/A';
    $yearSection = $enabledFields['course_section'] ? cleanText($_POST['year_section'] ?? '') : 'N/A';
    $componentSelectionOpen = !$isFacilitatorRegistration
        && !$studentNumberBased
        && isComponentSelectionEnabled($conn);
    $component = ($isFacilitatorRegistration || $componentSelectionOpen)
        ? normalizeProgram($_POST['component'] ?? null)
        : null;
    if (!$isFacilitatorRegistration && $component && !isStudentComponentOpen($conn, $component)) {
        failRegistration('The selected NSTP component is currently closed for registration.');
    }
    $rotcMsLevel = normalizeRotcMsLevel($_POST['rotc_ms_level'] ?? null);
    if ($component !== 'ROTC') {
        $rotcMsLevel = null;
    } elseif (!$isFacilitatorRegistration && $rotcMsLevel && !isStudentRotcMsLevelOpen($conn, $rotcMsLevel)) {
        failRegistration('The selected ROTC MS level is currently closed for registration.');
    }

    if (!$enabledFields['extension_name']) {
        $extensionName = 'N/A';
    }

    if (!$enabledFields['middle_name']) {
        $middleName = 'N/A';
    }

    if (!$enabledFields['birth_info']) {
        $placeOfBirth = 'N/A';
        $dateOfBirthInput = '01/01/1900';
    }

    if (!$enabledFields['religion'] || $religion === '') {
        $religion = 'N/A';
    } else {
        try {
            $religion = normalizeSubmittedReligion($religion, $religionOther);
        } catch (InvalidArgumentException $error) {
            failRegistration($error->getMessage());
        }

        if (!$studentNumberBased && $religion === 'N/A') {
            failRegistration('Please select your religion.');
        }
    }

    if ($bloodType === '') {
        $bloodType = 'N/A';
    }

    if (!$showNameFields) {
        $lastName = $studentNumber ?: 'Student';
        $firstName = 'Student';
        $extensionName = 'N/A';
    }

    if ($isFacilitatorRegistration) {
        if (!$component) {
            failRegistration('Please select an NSTP component.');
        }

        if ($fullName === '' && $showNameFields) {
            $fullName = trim($firstName . ' ' . $lastName);
        }
        if ($fullName === '') {
            $fullName = strtok($email, '@') ?: 'Facilitator';
        }
        $lastName = $fullName;
        $firstName = $fullName;
        if ($middleName === '') $middleName = 'N/A';
        if ($placeOfBirth === '') $placeOfBirth = 'N/A';
        if ($dateOfBirthInput === '') $dateOfBirthInput = '01/01/1900';
        foreach (['province', 'cityMunicipality', 'barangay', 'street', 'houseNo', 'college', 'course', 'major', 'yearSection', 'religion', 'gender', 'bloodType', 'contactNumber', 'emergencyName', 'emergencyRelationship', 'emergencyContactNumber', 'emergencyAddress'] as $optionalField) {
            if ($$optionalField === '') {
                $$optionalField = 'N/A';
            }
        }
    }

    if (!$showEmailField) {
        $email = $studentNumber ? 'student' . $studentNumber . '@no-email.tau-nstp.local' : 'student' . bin2hex(random_bytes(4)) . '@no-email.tau-nstp.local';
    }

    if ($studentNumberBased) {
        if ($lastName === '') {
            $lastName = $studentNumber ?: 'Student';
        }

        if ($firstName === '') {
            $firstName = 'Student';
        }

        if ($middleName === '') {
            $middleName = 'N/A';
        }

        if ($placeOfBirth === '') {
            $placeOfBirth = 'N/A';
        }

        if ($dateOfBirthInput === '') {
            $dateOfBirthInput = '01/01/1900';
        }

        foreach (['province', 'cityMunicipality', 'barangay', 'street', 'houseNo', 'college', 'course', 'major', 'yearSection', 'religion', 'gender', 'bloodType', 'contactNumber', 'emergencyName', 'emergencyRelationship', 'emergencyContactNumber', 'emergencyAddress'] as $optionalField) {
            if ($$optionalField === '') {
                $$optionalField = 'N/A';
            }
        }

        if ($email === '') {
            $email = 'student' . $studentNumber . '@no-email.tau-nstp.local';
        }
    }

    if (isset($_POST['extension_name_na'])) {
        $extensionName = 'N/A';
    }

    if (isset($_POST['middle_name_na'])) {
        $middleName = 'N/A';
    }

    if (!$studentNumberBased && $enabledFields['middle_name'] && $middleName !== 'N/A' && strlen($middleName) <= 1) {
        failRegistration('Middle Name must be more than one letter, or use N/A if there is no middle name.');
    }

    if ($enabledFields['address'] && isset($_POST['house_no_na'])) {
        $houseNo = 'N/A';
    }

    if (!$studentNumberBased && $enabledFields['address'] && $houseNo === '') {
        failRegistration('House No. is required, or use N/A if there is no house number.');
    }

    if (!$isFacilitatorRegistration && !$studentNumberBased && isset($_POST['emergency_same_address'])) {
        $studentAddressParts = array_filter([$houseNo, $street, $barangay, $cityMunicipality, $province], fn($value) => trim((string) $value) !== '' && strtoupper(trim((string) $value)) !== 'N/A');
        $emergencyAddress = $studentAddressParts ? implode(', ', $studentAddressParts) : 'N/A';
    }

    if (!$isFacilitatorRegistration && !$studentNumberBased && $emergencyAddress === '') {
        failRegistration('Emergency contact address is required, or use same as student address.');
    }

    if (!$isFacilitatorRegistration && $enabledFields['student_number'] && !preg_match('/^\d{10}$/', (string) $studentNumber)) {
        failRegistration('Student Number must be exactly 10 digits and numbers only.');
    }

    if (!$isFacilitatorRegistration && !$studentNumberBased && !preg_match('/^\d{11}$/', (string) $contactNumber)) {
        failRegistration('Contact Number must be exactly 11 digits and numbers only.');
    }

    if (!$isFacilitatorRegistration && !$studentNumberBased && !preg_match('/^\d{11}$/', (string) $emergencyContactNumber)) {
        failRegistration('Emergency Contact Number must be exactly 11 digits and numbers only.');
    }

    if ($componentSelectionOpen && !$component) {
        failRegistration('Please select an NSTP component.');
    }

    if (!$isFacilitatorRegistration && !$studentNumberBased && $component === 'ROTC' && !$rotcMsLevel) {
        failRegistration('Please select your ROTC MS level.');
    }

    if ($studentNumberOnlyForm && !$isFacilitatorRegistration) {
        $existingRegistration = findLatestPublicRegistrationByStudentNumber($conn, $studentNumber);
        if (!$existingRegistration) {
            failRegistration('Student number not found. Please complete the full public registration first.');
        }

        $attendanceOnlyRegistration = $existingRegistration;
        $attendanceOnlyRegistration['form_title'] = $publicForm['form_title'];
        $attendanceOnlyRegistration['component'] = normalizeProgram($existingRegistration['component'] ?? null);
        $attendanceOnlyRegistration['rotc_ms_level'] = $existingRegistration['rotc_ms_level'] ?? null;
        $attendanceOnlyRegistration['formal_picture'] = $existingRegistration['formal_picture'] ?? 'include/logo.png';

        $conn->beginTransaction();
        $stmt = $conn->prepare("
            INSERT INTO tbl_public_student_registrations (
                form_id, user_id, registrant_role, last_name, extension_name, first_name, middle_name, place_of_birth,
                date_of_birth, gender, religion, blood_type, contact_number, email, province, city_municipality, barangay, street, house_no,
                emergency_name, emergency_relationship, emergency_contact_number, emergency_address,
                student_number, college, course, major, year_section, component, rotc_ms_level, formal_picture, status
            ) VALUES (?, ?, 'student', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $formId,
            $existingRegistration['user_id'] ?? null,
            $existingRegistration['last_name'],
            cleanText($existingRegistration['extension_name'] ?? 'N/A') ?: null,
            $existingRegistration['first_name'],
            $existingRegistration['middle_name'],
            $existingRegistration['place_of_birth'],
            $existingRegistration['date_of_birth'] ?? '1900-01-01',
            $existingRegistration['gender'] ?? 'N/A',
            $existingRegistration['religion'] ?? 'N/A',
            $existingRegistration['blood_type'] ?? 'N/A',
            $existingRegistration['contact_number'] ?? 'N/A',
            strtolower(cleanText($existingRegistration['email'] ?? '')),
            $existingRegistration['province'],
            $existingRegistration['city_municipality'],
            $existingRegistration['barangay'],
            $existingRegistration['street'],
            $existingRegistration['house_no'],
            $existingRegistration['emergency_name'] ?? 'N/A',
            $existingRegistration['emergency_relationship'] ?? 'N/A',
            $existingRegistration['emergency_contact_number'] ?? 'N/A',
            $existingRegistration['emergency_address'] ?? 'N/A',
            $studentNumber,
            $existingRegistration['college'],
            $existingRegistration['course'],
            $existingRegistration['major'],
            $existingRegistration['year_section'],
            $attendanceOnlyRegistration['component'],
            $attendanceOnlyRegistration['rotc_ms_level'],
            $attendanceOnlyRegistration['formal_picture'],
            'attendance_only',
        ]);

        $conn->commit();
        $accountResult = autoCreateStudentAccountFromPublicRegistrations($conn, $studentNumber);

        $response['success'] = true;
        if (!empty($accountResult['created'])) {
            $response['message'] = !empty($accountResult['email_sent'])
                ? 'Registration saved successfully. Your student account was created and login credentials were sent to your registered email.'
                : 'Registration saved successfully. Your student account was created, but the credentials email was not sent. Please contact the administrator.';
        } else {
            $response['message'] = ($accountResult['reason'] ?? '') === 'already_exists'
                ? 'An account already exists. Please coordinate with the NSTP Office to verify your account.'
                : 'Registration saved successfully.';
        }
        echo json_encode($response);
        exit();
    }

    if (!$isFacilitatorRegistration && !$studentNumberBased && $enabledFields['course_section']) {
        if (isset($_POST['major_na'])) {
            $major = 'N/A';
        }

        if ($major === '') {
            failRegistration('Please select a major, or use N/A if there is no major.');
        }

        if (!validateCollegeCourseMajor($college, $course, $major)) {
            failRegistration('Please select a valid college, course, and major. Use N/A only if applicable.');
        }
    }

    if ($studentNumberBased && $enabledFields['course_section'] && $college !== 'N/A' && $course !== 'N/A' && $major !== 'N/A' && !validateCollegeCourseMajor($college, $course, $major)) {
        failRegistration('Please select a valid college, course, and major. Use N/A only if applicable.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        failRegistration('Please enter a valid email address.');
    }
    $hasProvidedEmail = !$studentNumberBased || strpos($email, '@no-email.tau-nstp.local') === false;

    if (!$isFacilitatorRegistration && !$studentNumberBased) {
        assertStudentFullRegistrationIsUnique($conn, $studentNumber, $email);
    }

    $dateOfBirth = DateTime::createFromFormat('m/d/Y', $dateOfBirthInput);
    $dateErrors = DateTime::getLastErrors();
    $dateWarningCount = is_array($dateErrors) ? (int) $dateErrors['warning_count'] : 0;
    $dateErrorCount = is_array($dateErrors) ? (int) $dateErrors['error_count'] : 0;
    if (!$dateOfBirth || $dateWarningCount > 0 || $dateErrorCount > 0) {
        failRegistration('Date of Birth must use mm/dd/yyyy format.');
    }
    $dateOfBirthValue = $dateOfBirth->format('Y-m-d');

    if ($isFacilitatorRegistration) {
        $dbPicturePath = 'include/logo.png';
        if ($enabledFields['formal_picture'] && !empty($_FILES['formal_picture']) && ($_FILES['formal_picture']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $picture = $_FILES['formal_picture'];
            if ($picture['size'] > 5 * 1024 * 1024) {
                failRegistration('Profile picture must not exceed 5MB.');
            }

            $extension = detectImageExtension($picture['tmp_name']);
            if (!$extension) {
                failRegistration('Profile picture must be JPG, PNG, or WEBP.');
            }

            $uploadDir = __DIR__ . '/../uploads/formal_pictures';
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
                failRegistration('Unable to prepare upload folder.');
            }

            $fileName = 'facilitator_' . date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '.' . $extension;
            $targetPath = $uploadDir . '/' . $fileName;
            $dbPicturePath = 'uploads/formal_pictures/' . $fileName;

            if (!move_uploaded_file($picture['tmp_name'], $targetPath)) {
                failRegistration('Unable to upload profile picture.');
            }
        }

        $facilitatorResult = createFacilitatorAccountFromPublicRegistration($conn, [
            'form_id' => $formId,
            'full_name' => $fullName,
            'last_name' => $lastName,
            'extension_name' => $extensionName,
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'place_of_birth' => $placeOfBirth,
            'date_of_birth' => $dateOfBirthValue,
            'religion' => $religion,
            'email' => $email,
            'province' => $province,
            'city_municipality' => $cityMunicipality,
            'barangay' => $barangay,
            'street' => $street,
            'house_no' => $houseNo,
            'college' => $college,
            'course' => $course,
            'major' => $major,
            'year_section' => $yearSection,
            'component' => $component,
            'formal_picture' => $dbPicturePath,
        ]);

        $response['success'] = true;
        $response['message'] = !empty($facilitatorResult['email_sent'])
            ? 'Facilitator account created successfully. Login credentials were sent to the registered email.'
            : 'Facilitator account created successfully, but the credentials email was not sent. ' . (getAppMailLastError() ?: 'Please contact the administrator.');
        echo json_encode($response);
        exit();
    }

    $stmt = $conn->prepare("SELECT username FROM tbl_users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $existingEmailUsername = $stmt->fetchColumn();
    if ($existingEmailUsername && (string) $existingEmailUsername !== (string) $studentNumber) {
        failRegistration('An account already exists for this email address. Please coordinate with the NSTP Office to verify your account.');
    }

    $stmt = $conn->prepare("
        SELECT student_number
        FROM tbl_public_student_registrations
        WHERE email = ?
          AND COALESCE(status, 'submitted') <> 'account_deleted'
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $existingRegistrationStudentNumber = $stmt->fetchColumn();
    if (
        $existingRegistrationStudentNumber
        && (!$studentNumberBased || (string) $existingRegistrationStudentNumber !== (string) $studentNumber)
    ) {
        failRegistration('This email address already has a public registration submission.');
    }

    // Recheck while holding a database lock so simultaneous submissions from
    // double-clicks, multiple tabs, or slow requests cannot both pass the
    // earlier duplicate validation and insert the same student.
    acquireStudentFullRegistrationLock($conn);
    $studentRegistrationLockAcquired = true;
    assertStudentFullRegistrationIsUnique($conn, $studentNumber, $email);

    if (!$studentNumberBased && $enabledFields['formal_picture'] && (empty($_FILES['formal_picture']) || ($_FILES['formal_picture']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK)) {
        failRegistration('Formal picture is required.');
    }

    $dbPicturePath = 'include/logo.png';
    if ($enabledFields['formal_picture'] && !empty($_FILES['formal_picture']) && ($_FILES['formal_picture']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $picture = $_FILES['formal_picture'];
        if ($picture['size'] > 5 * 1024 * 1024) {
            failRegistration('Formal picture must not exceed 5MB.');
        }

        $extension = detectImageExtension($picture['tmp_name']);
        if (!$extension) {
            failRegistration('Formal picture must be JPG, PNG, or WEBP.');
        }

        $uploadDir = __DIR__ . '/../uploads/formal_pictures';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
            failRegistration('Unable to prepare upload folder.');
        }

        $fileName = 'formal_' . date('YmdHis') . '_' . bin2hex(random_bytes(5)) . '.' . $extension;
        $targetPath = $uploadDir . '/' . $fileName;
        $dbPicturePath = 'uploads/formal_pictures/' . $fileName;

        if (!move_uploaded_file($picture['tmp_name'], $targetPath)) {
            failRegistration('Unable to upload formal picture.');
        }
    }

    $conn->beginTransaction();

    $stmt = $conn->prepare("
        INSERT INTO tbl_public_student_registrations (
            form_id, user_id, registrant_role, last_name, extension_name, first_name, middle_name, place_of_birth,
            date_of_birth, gender, religion, blood_type, contact_number, email, province, city_municipality, barangay, street, house_no,
            emergency_name, emergency_relationship, emergency_contact_number, emergency_address,
            student_number, college, course, major, year_section, component, rotc_ms_level, formal_picture
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $formId,
        null,
        'student',
        $lastName,
        $extensionName ?: null,
        $firstName,
        $middleName,
        $placeOfBirth,
        $dateOfBirthValue,
        $gender,
        $religion,
        $bloodType,
        $contactNumber,
        $email,
        $province,
        $cityMunicipality,
        $barangay,
        $street,
        $houseNo,
        $emergencyName,
        $emergencyRelationship,
        $emergencyContactNumber,
        $emergencyAddress,
        $studentNumber,
        $college,
        $course,
        $major,
        $yearSection,
        $component,
        $rotcMsLevel,
        $dbPicturePath,
    ]);
    $registrationId = (int) $conn->lastInsertId();

    $conn->commit();
    releaseStudentFullRegistrationLock($conn);
    $studentRegistrationLockAcquired = false;

    $accountResult = autoCreateStudentAccountFromPublicRegistrations($conn, $studentNumber);

    $response['success'] = true;
    if (!empty($accountResult['created'])) {
        $response['message'] = !empty($accountResult['email_sent'])
            ? 'Registration saved successfully. Your student account was created and login credentials were sent to your registered email.'
            : 'Registration saved successfully. Your student account was created, but the credentials email was not sent. Please contact the administrator.';
    } else {
        $response['message'] = ($accountResult['reason'] ?? '') === 'already_exists'
            ? 'An account already exists. Please coordinate with the NSTP Office to verify your account.'
            : 'Registration saved successfully.';
    }
} catch (Throwable $error) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    if ($studentRegistrationLockAcquired) {
        releaseStudentFullRegistrationLock($conn);
        $studentRegistrationLockAcquired = false;
    }
    $existingAccount = false;
    if (!empty($studentNumber)) {
        try {
            $accountCheckStmt = $conn->prepare("SELECT user_id FROM tbl_users WHERE role = 'student' AND username = ? LIMIT 1");
            $accountCheckStmt->execute([$studentNumber]);
            $existingAccount = (bool) $accountCheckStmt->fetchColumn();
        } catch (Throwable $ignored) {
            $existingAccount = false;
        }
    }
    $response['message'] = $existingAccount
        ? 'An account already exists. Please coordinate with the NSTP Office to verify your account.'
        : 'Registration failed. Please try again or coordinate with the NSTP Office.';
    error_log('Public registration failed: ' . $error->getMessage());
}

echo json_encode($response);
exit();
