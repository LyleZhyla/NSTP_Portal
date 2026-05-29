<?php
session_start();
header('Content-Type: application/json');

require_once '../conn/conn.php';
require_once '../include/public-registration-forms.php';
require_once '../include/college-courses.php';
require_once '../include/student-account-automation.php';

$response = ['success' => false, 'message' => ''];

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
            last_name VARCHAR(100) NOT NULL,
            extension_name VARCHAR(30) NULL,
            first_name VARCHAR(100) NOT NULL,
            middle_name VARCHAR(100) NOT NULL,
            place_of_birth VARCHAR(255) NOT NULL,
            date_of_birth DATE NOT NULL,
            email VARCHAR(150) NOT NULL,
            province VARCHAR(120) NOT NULL,
            city_municipality VARCHAR(120) NOT NULL,
            barangay VARCHAR(120) NOT NULL,
            street VARCHAR(180) NOT NULL,
            house_no VARCHAR(80) NOT NULL,
            student_number VARCHAR(10) NULL,
            college VARCHAR(150) NOT NULL,
            course VARCHAR(150) NOT NULL,
            major VARCHAR(120) NOT NULL DEFAULT 'N/A',
            year_section VARCHAR(40) NOT NULL,
            component VARCHAR(20) NULL,
            formal_picture VARCHAR(255) NOT NULL,
            account_username VARCHAR(80) NOT NULL,
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
            'student_number' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN student_number VARCHAR(10) NULL AFTER house_no",
            'college' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN college VARCHAR(150) NOT NULL DEFAULT '' AFTER student_number",
            'major' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN major VARCHAR(120) NOT NULL DEFAULT 'N/A' AFTER course",
            'component' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN component VARCHAR(20) NULL AFTER year_section",
            'account_username' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN account_username VARCHAR(80) NOT NULL DEFAULT '' AFTER formal_picture",
            'email_sent' => "ALTER TABLE tbl_public_student_registrations ADD COLUMN email_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER account_username",
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    failRegistration('Invalid request method.');
}

try {
    ensurePublicRegistrationTable($conn);
    $formId = (int) ($_POST['form_id'] ?? 0);
    $stmt = $conn->prepare("SELECT * FROM tbl_public_registration_forms WHERE form_id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$formId]);
    $publicForm = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$publicForm) {
        failRegistration('This public registration form is no longer available.');
    }

    $enabledFields = decodePublicRegistrationFields($publicForm['field_config']);
    $studentNumberBased = !empty($enabledFields['student_number']);
    $showNameFields = !empty($enabledFields['name']);
    $showEmailField = !empty($enabledFields['email']);

    $requiredFields = [];

    if (!$studentNumberBased && $showNameFields) {
        array_push($requiredFields, 'last_name', 'first_name');
    }

    if (!$studentNumberBased && $showEmailField) {
        $requiredFields[] = 'email';
    }

    if (!$studentNumberBased && $enabledFields['middle_name']) $requiredFields[] = 'middle_name';
    if (!$studentNumberBased && $enabledFields['birth_info']) array_push($requiredFields, 'place_of_birth', 'date_of_birth');
    if (!$studentNumberBased && $enabledFields['address']) array_push($requiredFields, 'province', 'city_municipality', 'barangay', 'street', 'house_no');
    if ($enabledFields['student_number']) $requiredFields[] = 'student_number';
    if (!$studentNumberBased && $enabledFields['course_section']) array_push($requiredFields, 'college', 'course', 'year_section');
    if ($enabledFields['course_section']) $requiredFields[] = 'component';

    foreach ($requiredFields as $field) {
        if (cleanText($_POST[$field] ?? '') === '') {
            failRegistration('Please complete all required fields.');
        }
    }

    $lastName = cleanText($_POST['last_name'] ?? '');
    $extensionName = cleanText($_POST['extension_name'] ?? '');
    $firstName = cleanText($_POST['first_name'] ?? '');
    $middleName = cleanText($_POST['middle_name'] ?? '');
    $placeOfBirth = cleanText($_POST['place_of_birth'] ?? '');
    $dateOfBirthInput = cleanText($_POST['date_of_birth'] ?? '');
    $email = strtolower(cleanText($_POST['email'] ?? ''));
    $province = $enabledFields['address'] ? cleanText($_POST['province'] ?? '') : 'N/A';
    $cityMunicipality = $enabledFields['address'] ? cleanText($_POST['city_municipality'] ?? '') : 'N/A';
    $barangay = $enabledFields['address'] ? cleanText($_POST['barangay'] ?? '') : 'N/A';
    $street = $enabledFields['address'] ? cleanText($_POST['street'] ?? '') : 'N/A';
    $houseNo = $enabledFields['address'] ? cleanText($_POST['house_no'] ?? '') : 'N/A';
    $studentNumber = $enabledFields['student_number'] ? cleanText($_POST['student_number']) : null;
    $college = $enabledFields['course_section'] ? cleanText($_POST['college'] ?? '') : 'N/A';
    $course = $enabledFields['course_section'] ? cleanText($_POST['course'] ?? '') : 'N/A';
    $major = $enabledFields['course_section'] ? cleanText($_POST['major'] ?? '') : 'N/A';
    $yearSection = $enabledFields['course_section'] ? cleanText($_POST['year_section'] ?? '') : 'N/A';
    $component = $enabledFields['course_section'] ? normalizeProgram($_POST['component'] ?? null) : null;

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

    if (!$showNameFields) {
        $lastName = $studentNumber ?: 'Student';
        $firstName = 'Student';
        $extensionName = 'N/A';
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

        foreach (['province', 'cityMunicipality', 'barangay', 'street', 'houseNo', 'college', 'course', 'major', 'yearSection'] as $optionalField) {
            if ($$optionalField === '') {
                $$optionalField = 'N/A';
            }
        }

        if ($email === '') {
            $email = 'student' . $studentNumber . '@no-email.tau-nstp.local';
        }
    }

    if ($enabledFields['course_section'] && !$component) {
        failRegistration('Please select a valid NSTP component.');
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

    if ($enabledFields['student_number'] && !preg_match('/^\d{10}$/', (string) $studentNumber)) {
        failRegistration('Student Number must be exactly 10 digits and numbers only.');
    }

    if (!$studentNumberBased && $enabledFields['course_section']) {
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

    $dateOfBirth = DateTime::createFromFormat('m/d/Y', $dateOfBirthInput);
    $dateErrors = DateTime::getLastErrors();
    $dateWarningCount = is_array($dateErrors) ? (int) $dateErrors['warning_count'] : 0;
    $dateErrorCount = is_array($dateErrors) ? (int) $dateErrors['error_count'] : 0;
    if (!$dateOfBirth || $dateWarningCount > 0 || $dateErrorCount > 0) {
        failRegistration('Date of Birth must use mm/dd/yyyy format.');
    }
    $dateOfBirthValue = $dateOfBirth->format('Y-m-d');

    $stmt = $conn->prepare("SELECT COUNT(*) FROM tbl_users WHERE email = ?");
    $stmt->execute([$email]);
    if ((int) $stmt->fetchColumn() > 0) {
        failRegistration('This email address already has an account.');
    }

    $stmt = $conn->prepare("
        SELECT student_number
        FROM tbl_public_student_registrations
        WHERE email = ?
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
            form_id, user_id, last_name, extension_name, first_name, middle_name, place_of_birth,
            date_of_birth, email, province, city_municipality, barangay, street, house_no,
            student_number, college, course, major, year_section, component, formal_picture, account_username
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $formId,
        null,
        $lastName,
        $extensionName ?: null,
        $firstName,
        $middleName,
        $placeOfBirth,
        $dateOfBirthValue,
        $email,
        $province,
        $cityMunicipality,
        $barangay,
        $street,
        $houseNo,
        $studentNumber,
        $college,
        $course,
        $major,
        $yearSection,
        $component,
        $dbPicturePath,
        $studentNumber ?: '',
    ]);
    $registrationId = (int) $conn->lastInsertId();

    $conn->commit();

    $accountResult = autoCreateStudentAccountFromPublicRegistrations($conn, $studentNumber);

    $response['success'] = true;
    if (!empty($accountResult['created'])) {
        $response['message'] = !empty($accountResult['email_sent'])
            ? 'Registration submitted successfully. Your student account was created and login credentials were sent to your registered email.'
            : 'Registration submitted successfully. Your student account was created, but the credentials email was not sent. Please check the registered email or contact the administrator.';
    } else {
        $response['message'] = 'Registration submitted successfully. Your account will be created after your second public registration submission.';
    }
} catch (Throwable $error) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    $response['message'] = 'Registration failed: ' . $error->getMessage();
}

echo json_encode($response);
exit();
