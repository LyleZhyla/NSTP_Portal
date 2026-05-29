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

$formId = (int) ($_POST['form_id'] ?? 0);
if ($formId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid QR form']);
    exit();
}

try {
    ensurePublicRegistrationFormsTable($conn);

    $stmt = $conn->prepare("SELECT form_title FROM tbl_public_registration_forms WHERE form_id = ? LIMIT 1");
    $stmt->execute([$formId]);
    $formTitle = $stmt->fetchColumn();

    if (!$formTitle) {
        echo json_encode(['success' => false, 'message' => 'QR form not found']);
        exit();
    }

    $conn->beginTransaction();

    $stmt = $conn->prepare("UPDATE tbl_public_student_registrations SET form_id = NULL WHERE form_id = ?");
    $stmt->execute([$formId]);

    $stmt = $conn->prepare("DELETE FROM tbl_public_registration_forms WHERE form_id = ?");
    $stmt->execute([$formId]);

    $conn->commit();
    logSystemEvent($conn, 'public_registration_qr_deleted', 'Deleted QR form: ' . $formTitle);

    echo json_encode([
        'success' => true,
        'message' => 'QR form deleted successfully.',
    ]);
} catch (Throwable $error) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    echo json_encode(['success' => false, 'message' => 'Unable to delete QR form: ' . $error->getMessage()]);
}
?>
