<?php

require_once __DIR__ . '/user-permissions.php';

function ensureSectionFoldersTable(PDO $conn) {
    static $ensured = false;
    if ($ensured) {
        return;
    }

    $conn->exec("
        CREATE TABLE IF NOT EXISTS tbl_section_folders (
            folder_id INT AUTO_INCREMENT PRIMARY KEY,
            program VARCHAR(20) NOT NULL,
            course_section VARCHAR(255) NOT NULL,
            created_by INT NULL,
            is_locked TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_program_folder (program, course_section),
            KEY idx_program (program),
            KEY idx_course_section (course_section)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $columnStmt = $conn->prepare("\n        SELECT COUNT(*)\n        FROM INFORMATION_SCHEMA.COLUMNS\n        WHERE TABLE_SCHEMA = DATABASE()\n          AND TABLE_NAME = 'tbl_section_folders'\n          AND COLUMN_NAME = 'is_locked'\n    ");
    $columnStmt->execute();
    if ((int) $columnStmt->fetchColumn() === 0) {
        $conn->exec("ALTER TABLE tbl_section_folders ADD COLUMN is_locked TINYINT(1) NOT NULL DEFAULT 1 AFTER created_by");
    }

    $ensured = true;
}

function sectionFolderRecord(PDO $conn, $program, $courseSection) {
    ensureSectionFoldersTable($conn);

    $rawProgram = strtoupper(trim((string) $program));
    $normalizedProgram = normalizeProgram($program) ?: ($rawProgram === 'PUBLIC' ? 'PUBLIC' : null);
    $courseSection = trim((string) $courseSection);
    if ($courseSection === '') {
        return null;
    }

    if ($normalizedProgram) {
        $stmt = $conn->prepare("\n            SELECT folder_id, program, course_section, is_locked\n            FROM tbl_section_folders\n            WHERE program = ? AND course_section = ?\n            LIMIT 1\n        ");
        $stmt->execute([$normalizedProgram, $courseSection]);
    } else {
        $stmt = $conn->prepare("\n            SELECT folder_id, program, course_section, is_locked\n            FROM tbl_section_folders\n            WHERE course_section = ?\n            ORDER BY folder_id ASC\n            LIMIT 1\n        ");
        $stmt->execute([$courseSection]);
    }

    $folder = $stmt->fetch(PDO::FETCH_ASSOC);
    return $folder ?: null;
}

function assertSectionFolderUnlocked(PDO $conn, $program, $courseSection) {
    $folder = sectionFolderRecord($conn, $program, $courseSection);
    if ($folder && (int) ($folder['is_locked'] ?? 1) === 1) {
        throw new RuntimeException('This folder is locked. The Super Admin must unlock it before a student can be removed, moved, or deleted.');
    }

    return $folder;
}

function syncSectionFoldersFromExisting(PDO $conn) {
    ensureSectionFoldersTable($conn);

    $pendingBuckets = ['CWTS', 'LTS', 'ROTC'];
    $deletePendingBuckets = $conn->prepare("
        DELETE FROM tbl_section_folders
        WHERE course_section IN (?, ?, ?)
    ");
    $deletePendingBuckets->execute($pendingBuckets);
}

function createSectionFolder(PDO $conn, $program, $courseSection, $createdBy = null) {
    ensureSectionFoldersTable($conn);

    $rawProgram = strtoupper(trim((string) $program));
    $program = normalizeProgram($program) ?: ($rawProgram === 'PUBLIC' ? 'PUBLIC' : inferProgramFromText($courseSection));
    $courseSection = trim((string) $courseSection);

    if (!$program) {
        throw new InvalidArgumentException('Folder program is required.');
    }

    if ($courseSection === '') {
        throw new InvalidArgumentException('Folder name is required.');
    }

    if ($program !== 'PUBLIC' && inferProgramFromText($courseSection) && inferProgramFromText($courseSection) !== $program) {
        throw new InvalidArgumentException('Folder name does not match the selected program.');
    }

    $stmt = $conn->prepare("
        INSERT INTO tbl_section_folders (program, course_section, created_by)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$program, $courseSection, $createdBy]);

    return $courseSection;
}

function sectionFolderExists(PDO $conn, $program, $courseSection) {
    ensureSectionFoldersTable($conn);

    $rawProgram = strtoupper(trim((string) $program));
    $program = normalizeProgram($program) ?: ($rawProgram === 'PUBLIC' ? 'PUBLIC' : inferProgramFromText($courseSection));
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM tbl_section_folders
        WHERE program = ? AND course_section = ?
    ");
    $stmt->execute([$program, trim((string) $courseSection)]);

    return (int) $stmt->fetchColumn() > 0;
}

function assignSectionFolderToFacilitator(PDO $conn, $facilitatorId, $courseSection, array $actor) {
    $courseSection = trim((string) $courseSection);
    $facilitatorId = (int) $facilitatorId;

    $stmt = $conn->prepare("SELECT user_id, role, program FROM tbl_users WHERE user_id = ?");
    $stmt->execute([$facilitatorId]);
    $facilitator = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$facilitator || ($facilitator['role'] ?? '') !== 'facilitator') {
        throw new InvalidArgumentException('Facilitator not found.');
    }

    if (!canManageUserRecord($actor, $facilitator)) {
        throw new RuntimeException('You are not allowed to assign folders to this facilitator.');
    }

    $program = normalizeProgram($facilitator['program'] ?? null);
    if (!$program) {
        throw new RuntimeException('Facilitator has no assigned component.');
    }

    if (($actor['role'] ?? '') === 'coordinator' && normalizeProgram($actor['program'] ?? null) !== $program) {
        throw new RuntimeException('Folder must match your assigned program.');
    }

    if (!sectionFolderExists($conn, $program, $courseSection)) {
        throw new RuntimeException('No folder found yet. Create the folder first, then assign a facilitator.');
    }

    $removeOtherAssignmentsStmt = $conn->prepare("
        DELETE ads
        FROM tbl_admin_sections ads
        INNER JOIN tbl_users assigned
            ON assigned.user_id = ads.user_id
           AND assigned.role = 'facilitator'
           AND assigned.program = ?
        WHERE ads.course_section = ?
          AND ads.user_id <> ?
    ");
    $removeOtherAssignmentsStmt->execute([$program, $courseSection, $facilitatorId]);

    $checkStmt = $conn->prepare("
        SELECT admin_section_id
        FROM tbl_admin_sections
        WHERE user_id = ? AND course_section = ?
    ");
    $checkStmt->execute([$facilitatorId, $courseSection]);
    $existingAssignmentId = (int) $checkStmt->fetchColumn();

    if ($existingAssignmentId > 0) {
        $updateAssignmentStmt = $conn->prepare("
            UPDATE tbl_admin_sections
            SET assigned_by = ?, assigned_at = NOW()
            WHERE admin_section_id = ?
        ");
        $updateAssignmentStmt->execute([$actor['user_id'] ?? null, $existingAssignmentId]);
        $assignmentId = $existingAssignmentId;
    } else {
        $insertStmt = $conn->prepare("
            INSERT INTO tbl_admin_sections (user_id, course_section, assigned_by, assigned_at)
            VALUES (?, ?, ?, NOW())
        ");
        $insertStmt->execute([$facilitatorId, $courseSection, $actor['user_id'] ?? null]);
        $assignmentId = (int) $conn->lastInsertId();
    }

    $moveStmt = $conn->prepare("
        UPDATE tbl_student s
        LEFT JOIN tbl_users creator ON s.created_by = creator.user_id
        SET s.created_by = ?
        WHERE s.course_section = ?
          AND (
              s.created_by IS NULL
              OR creator.role <> 'facilitator'
              OR creator.program = ?
          )
    ");
    $moveStmt->execute([$facilitatorId, $courseSection, $program]);

    return [
        'assignment_id' => $assignmentId,
        'moved_students' => $moveStmt->rowCount(),
    ];
}
