<?php

function ensureLandingStaffTable(PDO $conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS tbl_landing_staff (
            landing_staff_id INT AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(150) NOT NULL,
            position_title VARCHAR(150) NOT NULL,
            program VARCHAR(30) NOT NULL DEFAULT 'NSTP',
            group_label VARCHAR(100) NOT NULL DEFAULT 'NSTP Office',
            photo_path VARCHAR(255) DEFAULT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_visible TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            updated_by INT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_visible_order (is_visible, sort_order),
            INDEX idx_program (program)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $defaults = getDefaultLandingStaff();
    $stmt = $conn->prepare("
        INSERT INTO tbl_landing_staff (full_name, position_title, program, group_label, photo_path, sort_order, is_visible)
        VALUES (?, ?, ?, ?, ?, ?, 1)
    ");

    foreach ($defaults as $entry) {
        $checkStmt = $conn->prepare("SELECT landing_staff_id FROM tbl_landing_staff WHERE sort_order = ? LIMIT 1");
        $checkStmt->execute([$entry['sort_order']]);
        if ($checkStmt->fetchColumn()) {
            continue;
        }

        $stmt->execute([
            $entry['full_name'],
            $entry['position_title'],
            $entry['program'],
            $entry['group_label'],
            $entry['photo_path'],
            $entry['sort_order'],
        ]);
    }
}

function ensureLandingSectionsTable(PDO $conn) {
    $conn->exec("
        CREATE TABLE IF NOT EXISTS tbl_landing_sections (
            section_key VARCHAR(60) PRIMARY KEY,
            kicker VARCHAR(150) DEFAULT NULL,
            title VARCHAR(255) NOT NULL,
            body TEXT DEFAULT NULL,
            payload LONGTEXT DEFAULT NULL,
            updated_by INT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $defaults = getDefaultLandingSections();
    $stmt = $conn->prepare("
        INSERT IGNORE INTO tbl_landing_sections (section_key, kicker, title, body, payload)
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($defaults as $sectionKey => $section) {
        $stmt->execute([
            $sectionKey,
            $section['kicker'] ?? null,
            $section['title'] ?? '',
            $section['body'] ?? null,
            isset($section['payload']) ? json_encode($section['payload'], JSON_PRETTY_PRINT) : null,
        ]);
    }
}

function getDefaultLandingSections() {
    return [
        'hero' => [
            'kicker' => 'National Service Training Program',
            'title' => 'Choose the NSTP path that fits how you want to serve.',
            'body' => 'Compare CWTS, LTS, and ROTC in one place, meet the NSTP staff, and understand how each program builds service, leadership, and citizenship.',
            'payload' => [
                'primary_label' => 'View Programs',
                'secondary_label' => 'Meet Staff',
            ],
        ],
        'quick_guide' => [
            'kicker' => '',
            'title' => 'Quick Guide',
            'body' => '',
            'payload' => [
                ['name' => 'CWTS', 'description' => 'Community service and civic welfare.'],
                ['name' => 'LTS', 'description' => 'Literacy, tutorials, and learning support.'],
                ['name' => 'ROTC', 'description' => 'Discipline, leadership, and preparedness.'],
            ],
        ],
        'programs' => [
            'kicker' => 'Program Options',
            'title' => 'Three tracks, one purpose: service.',
            'body' => 'Each NSTP component has a different training style, but all three help students become active and responsible citizens.',
            'payload' => [
                ['name' => 'CWTS', 'title' => 'Civic Welfare Training Service', 'accent' => 'teal', 'focus' => 'Community service, social responsibility, disaster preparedness, health, environment, and local development projects.', 'best_for' => 'Students who want hands-on outreach and civic action.', 'output' => 'Service projects, community immersion, advocacy work, and volunteer leadership.'],
                ['name' => 'LTS', 'title' => 'Literacy Training Service', 'accent' => 'gold', 'focus' => 'Teaching support, reading programs, numeracy, tutoring, learning materials, and youth education.', 'best_for' => 'Students who enjoy mentoring learners and helping improve basic education.', 'output' => 'Tutorial sessions, learning modules, reading drives, and classroom support activities.'],
                ['name' => 'ROTC', 'title' => 'Reserve Officers Training Corps', 'accent' => 'crimson', 'focus' => 'Leadership, discipline, citizenship, emergency response, basic military orientation, and national defense awareness.', 'best_for' => 'Students who prefer structured training, command responsibility, and physical formation.', 'output' => 'Drills, leadership exercises, preparedness training, and civic defense participation.'],
            ],
        ],
        'difference' => [
            'kicker' => 'Side By Side',
            'title' => 'What makes each component different?',
            'body' => 'Use this comparison when deciding where your strengths and interests fit best.',
            'payload' => [
                ['component' => 'CWTS', 'focus' => 'Community welfare and civic projects.', 'style' => 'Field work, outreach planning, group projects, and advocacy activities.', 'experience' => 'Students work with communities and respond to real social needs.'],
                ['component' => 'LTS', 'focus' => 'Literacy, numeracy, and education support.', 'style' => 'Tutorials, reading programs, mentoring, and instructional material preparation.', 'experience' => 'Students help learners improve foundational skills through patient guidance.'],
                ['component' => 'ROTC', 'focus' => 'Leadership, discipline, and national defense awareness.', 'style' => 'Formations, drills, command exercises, emergency response, and physical training.', 'experience' => 'Students build confidence, order, teamwork, and readiness under structured training.'],
            ],
        ],
        'activities' => [
            'kicker' => 'Activities',
            'title' => 'NSTP learning happens beyond the attendance scan.',
            'body' => 'These visual themes show the kind of work students encounter across service, education, and leadership activities.',
            'payload' => [
                ['title' => 'Community Work', 'label' => 'Service', 'image' => 'https://images.unsplash.com/photo-1593113598332-cd288d649433?auto=format&fit=crop&w=800&q=80'],
                ['title' => 'Learning Support', 'label' => 'Education', 'image' => 'https://images.unsplash.com/photo-1524995997946-a1c2e315a42f?auto=format&fit=crop&w=800&q=80'],
                ['title' => 'Formation', 'label' => 'Leadership', 'image' => 'https://images.unsplash.com/photo-1500530855697-b586d89ba3ee?auto=format&fit=crop&w=800&q=80'],
            ],
        ],
        'org_chart' => [
            'kicker' => 'NSTP Staff',
            'title' => 'Organizational structure',
            'body' => 'The public landing page follows the NSTP organizational chart. Coordinators and the super administrator can edit each box directly here.',
        ],
        'cta' => [
            'kicker' => '',
            'title' => 'Ready to manage NSTP attendance?',
            'body' => 'Staff can sign in to scan QR codes, view attendance, and manage student records.',
            'payload' => [
                'guest_label' => 'Open QR Attendance System',
                'logged_in_label' => 'Open Dashboard',
            ],
        ],
    ];
}

function getLandingSections(PDO $conn) {
    ensureLandingSectionsTable($conn);

    $stmt = $conn->prepare("SELECT section_key, kicker, title, body, payload FROM tbl_landing_sections");
    $stmt->execute();

    $sections = getDefaultLandingSections();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $payload = null;
        if (!empty($row['payload'])) {
            $payload = json_decode($row['payload'], true);
        }

        $sections[$row['section_key']] = [
            'kicker' => $row['kicker'] ?? '',
            'title' => $row['title'] ?? '',
            'body' => $row['body'] ?? '',
            'payload' => is_array($payload) ? $payload : ($sections[$row['section_key']]['payload'] ?? null),
        ];
    }

    return $sections;
}

function saveLandingSection(PDO $conn, array $data, $userId = null) {
    ensureLandingSectionsTable($conn);

    $sectionKey = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($data['section_key'] ?? '')));
    $defaults = getDefaultLandingSections();
    if (!isset($defaults[$sectionKey])) {
        throw new RuntimeException('Invalid landing section.');
    }

    $payload = trim((string) ($data['payload'] ?? ''));
    if ($payload !== '' && json_decode($payload, true) === null && json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Section payload must be valid JSON.');
    }

    $stmt = $conn->prepare("
        INSERT INTO tbl_landing_sections (section_key, kicker, title, body, payload, updated_by)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            kicker = VALUES(kicker),
            title = VALUES(title),
            body = VALUES(body),
            payload = VALUES(payload),
            updated_by = VALUES(updated_by)
    ");

    return $stmt->execute([
        $sectionKey,
        cleanLandingContentText($data['kicker'] ?? '', 150),
        cleanLandingContentText($data['title'] ?? '', 255),
        trim((string) ($data['body'] ?? '')),
        $payload !== '' ? $payload : null,
        $userId,
    ]);
}

function getDefaultLandingStaff() {
    return [
        [
            'full_name' => 'Dr. Silverio Ramon DC. Salunson',
            'position_title' => 'University President',
            'program' => 'NSTP',
            'group_label' => 'University Administration',
            'photo_path' => null,
            'sort_order' => 10,
        ],
        [
            'full_name' => 'Dr. Sonny DC. Torres',
            'position_title' => 'Vice President, Academic & Student Affairs',
            'program' => 'NSTP',
            'group_label' => 'University Administration',
            'photo_path' => null,
            'sort_order' => 20,
        ],
        [
            'full_name' => 'Dr. Joven D. Valdez',
            'position_title' => 'Director, NSTP',
            'program' => 'NSTP',
            'group_label' => 'NSTP Office',
            'photo_path' => null,
            'sort_order' => 30,
        ],
        [
            'full_name' => 'Mr. Ronimo G. Ubaldo',
            'position_title' => 'Assistant Director, NSTP',
            'program' => 'NSTP',
            'group_label' => 'NSTP Office',
            'photo_path' => null,
            'sort_order' => 40,
        ],
        [
            'full_name' => 'Mx. Roniel S. Quita',
            'position_title' => 'Clerk, NSTP',
            'program' => 'NSTP',
            'group_label' => 'NSTP Office',
            'photo_path' => null,
            'sort_order' => 45,
        ],
        [
            'full_name' => 'Ms. Lyle Zhyla Patalod',
            'position_title' => 'ROTC Coordinator',
            'program' => 'ROTC',
            'group_label' => 'ROTC',
            'photo_path' => null,
            'sort_order' => 50,
        ],
        [
            'full_name' => 'Mr. Rafael G. Macaspac',
            'position_title' => 'CWTS Coordinator',
            'program' => 'CWTS',
            'group_label' => 'CWTS',
            'photo_path' => null,
            'sort_order' => 60,
        ],
        [
            'full_name' => 'Ms. Dancel M. Cabintoy',
            'position_title' => 'LTS Coordinator',
            'program' => 'LTS',
            'group_label' => 'LTS',
            'photo_path' => null,
            'sort_order' => 70,
        ],
        [
            'full_name' => 'Mr. Ronimo G. Ubaldo',
            'position_title' => 'DRRM Coordinator',
            'program' => 'DRRM',
            'group_label' => 'DRRM',
            'photo_path' => null,
            'sort_order' => 80,
        ],
        [
            'full_name' => 'Training Staff',
            'position_title' => 'ROTC Training Staff',
            'program' => 'ROTC',
            'group_label' => 'ROTC',
            'photo_path' => null,
            'sort_order' => 90,
        ],
        [
            'full_name' => 'CWTS Instructors',
            'position_title' => 'CWTS Instruction Team',
            'program' => 'CWTS',
            'group_label' => 'CWTS',
            'photo_path' => null,
            'sort_order' => 100,
        ],
        [
            'full_name' => 'LTS Instructors',
            'position_title' => 'LTS Instruction Team',
            'program' => 'LTS',
            'group_label' => 'LTS',
            'photo_path' => null,
            'sort_order' => 110,
        ],
        [
            'full_name' => 'DRRM Facilitators',
            'position_title' => 'DRRM Facilitation Team',
            'program' => 'DRRM',
            'group_label' => 'DRRM',
            'photo_path' => null,
            'sort_order' => 120,
        ],
    ];
}

function getLandingStaff(PDO $conn, $visibleOnly = true) {
    ensureLandingStaffTable($conn);

    $where = $visibleOnly ? 'WHERE is_visible = 1' : '';
    $stmt = $conn->prepare("
        SELECT landing_staff_id, full_name, position_title, program, group_label, photo_path, sort_order, is_visible
        FROM tbl_landing_staff
        $where
        ORDER BY sort_order ASC, landing_staff_id ASC
    ");
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function normalizeLandingProgram($program) {
    $program = strtoupper(trim((string) $program));
    return $program !== '' ? substr($program, 0, 30) : 'NSTP';
}

function cleanLandingContentText($value, $maxLength) {
    return substr(trim((string) $value), 0, $maxLength);
}

function uploadLandingStaffPhoto($fieldName, $baseDir = '') {
    if (empty($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    $mimeType = mime_content_type($_FILES[$fieldName]['tmp_name']);
    if (!isset($allowedTypes[$mimeType])) {
        throw new RuntimeException('Only JPG, PNG, GIF, and WEBP photos are allowed.');
    }

    if ($_FILES[$fieldName]['size'] > 3 * 1024 * 1024) {
        throw new RuntimeException('Photo must be 3MB or smaller.');
    }

    $relativeDir = 'uploads/landing_staff/';
    $uploadDir = rtrim($baseDir, '/\\');
    $uploadDir = $uploadDir !== '' ? $uploadDir . DIRECTORY_SEPARATOR . $relativeDir : $relativeDir;

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $fileName = 'landing_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowedTypes[$mimeType];
    $targetPath = $uploadDir . $fileName;

    if (!move_uploaded_file($_FILES[$fieldName]['tmp_name'], $targetPath)) {
        throw new RuntimeException('Could not upload the photo.');
    }

    return $relativeDir . $fileName;
}

function saveLandingStaffEntry(PDO $conn, array $data, $userId = null) {
    ensureLandingStaffTable($conn);

    $entryId = (int) ($data['landing_staff_id'] ?? 0);
    $fullName = cleanLandingContentText($data['full_name'] ?? '', 150);
    $positionTitle = cleanLandingContentText($data['position_title'] ?? '', 150);
    $program = normalizeLandingProgram($data['program'] ?? 'NSTP');
    $groupLabel = cleanLandingContentText($data['group_label'] ?? 'NSTP Office', 100);
    $photoPath = cleanLandingContentText($data['photo_path'] ?? '', 255);
    $sortOrder = (int) ($data['sort_order'] ?? 0);
    $isVisible = !empty($data['is_visible']) ? 1 : 0;

    if ($fullName === '' || $positionTitle === '') {
        throw new RuntimeException('Name and position/title are required.');
    }

    if ($entryId > 0) {
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
            $userId,
            $entryId,
        ]);

        return $entryId;
    }

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
        $userId,
        $userId,
    ]);

    return (int) $conn->lastInsertId();
}

function deleteLandingStaffEntry(PDO $conn, $entryId) {
    ensureLandingStaffTable($conn);

    $entryId = (int) $entryId;
    if ($entryId <= 0) {
        throw new RuntimeException('Invalid landing entry.');
    }

    $stmt = $conn->prepare("DELETE FROM tbl_landing_staff WHERE landing_staff_id = ?");
    return $stmt->execute([$entryId]);
}
