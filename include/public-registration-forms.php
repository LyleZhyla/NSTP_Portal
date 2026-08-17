<?php

function getPublicRegistrationFieldOptions() {
    return [
        'name' => 'Name',
        'email' => 'Email Address',
        'extension_name' => 'Extension Name',
        'middle_name' => 'Middle Name',
        'birth_info' => 'Place and Date of Birth',
        'religion' => 'Religion',
        'address' => 'Address',
        'student_number' => 'Student Number',
        'course_section' => 'Course and Year/Section',
        'formal_picture' => 'Formal Picture',
    ];
}

function normalizePublicRegistrationRole($role) {
    $role = strtolower(trim((string) $role));
    return in_array($role, ['student', 'facilitator'], true) ? $role : 'student';
}

function getDefaultPublicRegistrationFields() {
    return array_fill_keys(array_keys(getPublicRegistrationFieldOptions()), true);
}

function normalizePublicRegistrationFields($fields) {
    $defaults = getDefaultPublicRegistrationFields();
    $normalized = [];

    foreach ($defaults as $key => $defaultValue) {
        if (array_key_exists($key, $fields)) {
            $normalized[$key] = (bool) $fields[$key];
            continue;
        }

        $normalized[$key] = in_array($key, ['name', 'email'], true) ? true : false;
    }

    return $normalized;
}

function generatePublicRegistrationSlug() {
    return 'reg-' . date('Ymd') . '-' . bin2hex(random_bytes(4));
}

function ensurePublicRegistrationFormsTable(PDO $conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS tbl_public_registration_forms (
            form_id INT AUTO_INCREMENT PRIMARY KEY,
            form_title VARCHAR(150) NOT NULL,
            form_slug VARCHAR(80) NOT NULL,
            registration_role VARCHAR(20) NOT NULL DEFAULT 'student',
            field_config TEXT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_form_slug (form_slug),
            INDEX idx_is_active (is_active),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'tbl_public_registration_forms'
              AND COLUMN_NAME = 'registration_role'
        ");
        $stmt->execute();
        if ((int) $stmt->fetchColumn() === 0) {
            $conn->exec("ALTER TABLE tbl_public_registration_forms ADD COLUMN registration_role VARCHAR(20) NOT NULL DEFAULT 'student' AFTER form_slug");
        }
    } catch (Throwable $error) {
        // Older databases can still read existing forms; saves will surface schema issues if migration fails.
    }

}

function getPublicRegistrationForms(PDO $conn) {
    $stmt = $conn->prepare("
        SELECT f.*, u.full_name AS created_by_name
        FROM tbl_public_registration_forms f
        LEFT JOIN tbl_users u ON f.created_by = u.user_id
        ORDER BY f.created_at DESC
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function decodePublicRegistrationFields($fieldConfig) {
    $decoded = json_decode((string) $fieldConfig, true);
    return normalizePublicRegistrationFields(is_array($decoded) ? $decoded : []);
}

function getPublicRegistrationForm(PDO $conn, $slug = null) {
    if ($slug) {
        $stmt = $conn->prepare("SELECT * FROM tbl_public_registration_forms WHERE form_slug = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$slug]);
        $form = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($form) {
            $form['fields'] = decodePublicRegistrationFields($form['field_config']);
            return $form;
        }
    }

    $stmt = $conn->prepare("SELECT * FROM tbl_public_registration_forms WHERE is_active = 1 ORDER BY created_at DESC LIMIT 1");
    $stmt->execute();
    $form = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$form) {
        return null;
    }

    $form['fields'] = decodePublicRegistrationFields($form['field_config']);
    return $form;
}
