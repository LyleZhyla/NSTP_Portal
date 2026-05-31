<?php
session_start();
header('Content-Type: application/json');

require_once '../conn/conn.php';
require_once '../include/user-permissions.php';
require_once '../include/public-registration-forms.php';

$currentUser = getCurrentUserRecord($conn);
if (!$currentUser || !in_array($currentUser['role'] ?? '', ['coordinator', 'super_admin'], true)) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

try {
    ensurePublicRegistrationFormsTable($conn);

    $formId = (int) ($_POST['form_id'] ?? 0);
    $title = trim(preg_replace('/\s+/', ' ', (string) ($_POST['form_title'] ?? '')));
    $registrationRole = normalizePublicRegistrationRole($_POST['registration_role'] ?? 'student');
    $isActive = isset($_POST['is_active']) ? 1 : 0;

    if ($title === '') {
        throw new Exception('Form title is required.');
    }

    $fields = [];
    foreach (array_keys(getPublicRegistrationFieldOptions()) as $fieldKey) {
        $fields[$fieldKey] = isset($_POST['fields'][$fieldKey]);
    }

    if ($registrationRole === 'facilitator') {
        $fields['name'] = true;
        $fields['email'] = true;
        $fields['course_section'] = false;
        $fields['student_number'] = false;
        $fields['extension_name'] = false;
        $fields['middle_name'] = false;
        $fields['birth_info'] = false;
        $fields['religion'] = false;
        $fields['address'] = false;
        $fields['formal_picture'] = false;
    }

    $fieldConfig = json_encode(normalizePublicRegistrationFields($fields));

    if ($formId > 0) {
        $stmt = $conn->prepare("
            UPDATE tbl_public_registration_forms
            SET form_title = ?, registration_role = ?, field_config = ?, is_active = ?
            WHERE form_id = ?
        ");
        $stmt->execute([$title, $registrationRole, $fieldConfig, $isActive, $formId]);
        $message = 'Public registration form updated successfully.';
    } else {
        $slug = generatePublicRegistrationSlug();
        $stmt = $conn->prepare("
            INSERT INTO tbl_public_registration_forms (form_title, form_slug, registration_role, field_config, is_active, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$title, $slug, $registrationRole, $fieldConfig, $isActive, $currentUser['user_id']]);
        $message = 'New public registration QR form created successfully.';
    }

    echo json_encode(['success' => true, 'message' => $message]);
} catch (Throwable $error) {
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
}
