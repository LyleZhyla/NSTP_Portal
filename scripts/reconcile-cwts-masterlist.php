<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../include/section-folders.php';

$isWebRunner = defined('CWTS_RECONCILIATION_WEB') && CWTS_RECONCILIATION_WEB === true;
if (PHP_SAPI !== 'cli' && !$isWebRunner) {
    fwrite(STDERR, "This command can only be run from the command line.\n");
    exit(1);
}

$args = $isWebRunner
    ? (array) ($GLOBALS['cwtsReconciliationArgs'] ?? [])
    : array_slice($argv, 1);
$apply = in_array('--apply', $args, true);
$json = in_array('--json', $args, true);
$production = in_array('--production', $args, true);
$actorId = $isWebRunner ? (int) ($GLOBALS['cwtsReconciliationActorId'] ?? 0) : 0;
$component = $isWebRunner ? strtoupper((string) ($GLOBALS['cwtsReconciliationComponent'] ?? 'CWTS')) : 'CWTS';
$file = null;

foreach ($args as $arg) {
    if (str_starts_with($arg, '--component=')) {
        $component = strtoupper(trim(substr($arg, strlen('--component='))));
        continue;
    }
    if ($file === null && strncmp($arg, '--', 2) !== 0) {
        $file = $arg;
    }
}

if (!in_array($component, ['CWTS', 'LTS'], true)) {
    throw new InvalidArgumentException('Only CWTS and LTS masterlists are supported.');
}

if (!$file || !is_file($file)) {
    if ($isWebRunner) {
        throw new InvalidArgumentException('A valid masterlist workbook is required.');
    }
    fwrite(STDERR, "Usage: php scripts/reconcile-cwts-masterlist.php <masterlist.xlsx> [--component=CWTS|LTS] [--production] [--apply] [--json]\n");
    exit(1);
}

if ($isWebRunner) {
    if (!isset($conn) || !$conn instanceof PDO) {
        throw new RuntimeException('The web runner did not provide a database connection.');
    }
} elseif ($production) {
    $_SERVER['HTTP_HOST'] = 'production';
    require __DIR__ . '/../conn/conn.php';
} else {
    $conn = null;
    $connectionErrors = [];
    foreach ([3306, 3307] as $port) {
        try {
            $conn = new PDO("mysql:host=127.0.0.1;port={$port};dbname=qr_attendance_db;charset=utf8mb4", 'root', '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            break;
        } catch (PDOException $error) {
            $connectionErrors[] = "{$port}: {$error->getMessage()}";
        }
    }
    if (!$conn) {
        fwrite(STDERR, "Unable to connect to the local database on ports 3306 or 3307.\n" . implode("\n", $connectionErrors) . "\n");
        exit(1);
    }
}

function normalizedName(string $value): string
{
    $value = trim($value);
    if (class_exists(Transliterator::class)) {
        $transliterator = Transliterator::create('Any-Latin; Latin-ASCII; Upper()');
        if ($transliterator) {
            $value = (string) $transliterator->transliterate($value);
        }
    } else {
        $value = mb_strtoupper($value, 'UTF-8');
    }

    $value = str_replace(["\xEF\xBF\xBD", 'Ñ', 'ñ'], ['', 'N', 'N'], $value);
    $value = preg_replace('/[^A-Z0-9]+/u', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
}

function tokenName(string $value): string
{
    $tokens = array_values(array_filter(explode(' ', normalizedName($value))));
    sort($tokens, SORT_STRING);
    return implode(' ', $tokens);
}

function corePersonName(string $value): string
{
    $tokens = array_values(array_filter(
        explode(' ', normalizedName($value)),
        static fn(string $token): bool => strlen($token) > 1
    ));
    sort($tokens, SORT_STRING);
    return implode(' ', $tokens);
}

function addIndex(array &$index, string $key, array $record): void
{
    if ($key !== '') {
        $index[$key][] = $record;
    }
}

function uniqueIndexedMatch(array $records, array $exactIndex, array $tokenIndex, string $name, string $program = ''): array
{
    $exact = $exactIndex[normalizedName($name)] ?? [];
    if (count($exact) > 1 && $program !== '') {
        $programKey = normalizedName($program);
        $programMatches = array_values(array_filter($exact, static fn(array $record): bool =>
            normalizedName((string) ($record['registration_program'] ?? '')) === $programKey
        ));
        if (count($programMatches) === 1) {
            return ['status' => 'matched', 'method' => 'normalized_and_program', 'record' => $programMatches[0]];
        }
    }
    if (count($exact) === 1) {
        return ['status' => 'matched', 'method' => 'normalized', 'record' => $exact[0]];
    }
    if (count($exact) > 1) {
        return ['status' => 'ambiguous', 'method' => 'normalized', 'records' => $exact];
    }

    $token = $tokenIndex[tokenName($name)] ?? [];
    if (count($token) > 1 && $program !== '') {
        $programKey = normalizedName($program);
        $programMatches = array_values(array_filter($token, static fn(array $record): bool =>
            normalizedName((string) ($record['registration_program'] ?? '')) === $programKey
        ));
        if (count($programMatches) === 1) {
            return ['status' => 'matched', 'method' => 'token_order_and_program', 'record' => $programMatches[0]];
        }
    }
    if (count($token) === 1) {
        return ['status' => 'matched', 'method' => 'token_order', 'record' => $token[0]];
    }
    if (count($token) > 1) {
        return ['status' => 'ambiguous', 'method' => 'token_order', 'records' => $token];
    }

    return ['status' => 'unmatched'];
}

$workbook = IOFactory::load($file);
$masterRows = [];
$sections = [];
$workbookNameIndex = [];

foreach ($workbook->getWorksheetIterator() as $sheet) {
    $sectionLine = trim((string) $sheet->getCell('A2')->getFormattedValue());
    $facilitatorLine = trim((string) $sheet->getCell('A3')->getFormattedValue());
    $section = trim(str_contains($sectionLine, 'Section:') ? explode('Section:', $sectionLine, 2)[1] : $sheet->getTitle());
    $facilitator = trim(str_contains($facilitatorLine, 'Facilitator:') ? explode('Facilitator:', $facilitatorLine, 2)[1] : '');

    if (!preg_match('/^' . preg_quote($component, '/') . '\s+[A-Z0-9-]+$/i', $section)) {
        continue;
    }

    $sections[$section] = ['section' => $section, 'facilitator' => $facilitator, 'sheet' => $sheet->getTitle()];
    for ($row = 7; $row <= $sheet->getHighestDataRow(); $row++) {
        $number = $sheet->getCell("A{$row}")->getValue();
        $name = trim((string) $sheet->getCell("B{$row}")->getFormattedValue());
        if (!is_numeric($number) || $name === '') {
            continue;
        }

        $record = [
            'sheet' => $sheet->getTitle(),
            'row' => $row,
            'name' => $name,
            'program' => trim((string) $sheet->getCell("C{$row}")->getFormattedValue()),
            'section' => $section,
            'facilitator' => $facilitator,
        ];
        $masterRows[] = $record;
        addIndex($workbookNameIndex, normalizedName($name), $record);
    }
}

$duplicateWorkbookNames = array_values(array_map(
    static fn(array $rows): array => [
        'name' => $rows[0]['name'],
        'occurrences' => count($rows),
        'records' => array_map(static fn(array $r): array => [
            'location' => $r['sheet'] . '!' . $r['row'],
            'program' => $r['program'],
            'section' => $r['section'],
        ], $rows),
    ],
    array_filter($workbookNameIndex, static fn(array $rows): bool => count($rows) > 1)
));

$studentStmt = $conn->prepare("
    SELECT s.tbl_student_id, s.student_name, s.course_section, s.created_by,
           creator.full_name AS creator_name, creator.program AS creator_program,
           student_user.program AS user_program,
           (SELECT latest_component.component
            FROM tbl_public_student_registrations latest_component
            WHERE (s.student_number IS NOT NULL AND s.student_number <> '' AND latest_component.student_number = s.student_number)
               OR (s.user_id IS NOT NULL AND latest_component.user_id = s.user_id)
            ORDER BY latest_component.registration_id DESC LIMIT 1) AS registration_component,
           (SELECT CONCAT_WS(' ', latest.course, latest.year_section)
            FROM tbl_public_student_registrations latest
            WHERE (s.student_number IS NOT NULL AND s.student_number <> '' AND latest.student_number = s.student_number)
               OR (s.user_id IS NOT NULL AND latest.user_id = s.user_id)
            ORDER BY latest.registration_id DESC LIMIT 1) AS registration_program
    FROM tbl_student s
    LEFT JOIN tbl_users student_user ON student_user.user_id = s.user_id
    LEFT JOIN tbl_users creator ON creator.user_id = s.created_by
    WHERE s.course_section LIKE ?
       OR s.course_section = ?
       OR student_user.program = ?
       OR creator.program = ?
       OR EXISTS (
           SELECT 1 FROM tbl_public_student_registrations registration
           WHERE registration.registration_id = (
                    SELECT MAX(latest_registration.registration_id)
                    FROM tbl_public_student_registrations latest_registration
                    WHERE (s.student_number IS NOT NULL AND s.student_number <> '' AND latest_registration.student_number = s.student_number)
                       OR (s.user_id IS NOT NULL AND latest_registration.user_id = s.user_id)
                 )
             AND registration.component = ?
       )
");
$studentStmt->execute([$component . ' %', $component, $component, $component, $component]);
$students = array_values(array_filter($studentStmt->fetchAll(PDO::FETCH_ASSOC), static function (array $student) use ($component): bool {
    $resolvedComponent = normalizeProgram($student['user_program'] ?? null)
        ?: normalizeProgram($student['registration_component'] ?? null)
        ?: inferProgramFromText($student['course_section'] ?? '')
        ?: normalizeProgram($student['creator_program'] ?? null);

    return $resolvedComponent === $component;
}));
$studentExactIndex = [];
$studentTokenIndex = [];
foreach ($students as $student) {
    addIndex($studentExactIndex, normalizedName((string) $student['student_name']), $student);
    addIndex($studentTokenIndex, tokenName((string) $student['student_name']), $student);
}

$facilitatorStmt = $conn->prepare("
    SELECT user_id, full_name, assigned_section
    FROM tbl_users
    WHERE role = 'facilitator' AND program = ?
");
$facilitatorStmt->execute([$component]);
$facilitators = $facilitatorStmt->fetchAll(PDO::FETCH_ASSOC);
$facilitatorExactIndex = [];
$facilitatorTokenIndex = [];
$facilitatorCoreIndex = [];
foreach ($facilitators as $facilitator) {
    addIndex($facilitatorExactIndex, normalizedName((string) $facilitator['full_name']), $facilitator);
    addIndex($facilitatorTokenIndex, tokenName((string) $facilitator['full_name']), $facilitator);
    addIndex($facilitatorCoreIndex, corePersonName((string) $facilitator['full_name']), $facilitator);
}

$sectionFacilitators = [];
$missingFacilitators = [];
foreach ($sections as $section => $details) {
    $match = uniqueIndexedMatch($facilitators, $facilitatorExactIndex, $facilitatorTokenIndex, $details['facilitator']);
    if ($match['status'] === 'unmatched') {
        $coreMatches = $facilitatorCoreIndex[corePersonName($details['facilitator'])] ?? [];
        if (count($coreMatches) === 1) {
            $match = ['status' => 'matched', 'method' => 'facilitator_without_initials', 'record' => $coreMatches[0]];
        } elseif (count($coreMatches) > 1) {
            $match = ['status' => 'ambiguous', 'method' => 'facilitator_without_initials', 'records' => $coreMatches];
        }
    }
    if ($match['status'] !== 'matched') {
        $missingFacilitators[] = ['section' => $section, 'facilitator' => $details['facilitator'], 'status' => $match['status']];
        continue;
    }
    $sectionFacilitators[$section] = $match['record'];
}

$matched = [];
$unmatched = [];
$ambiguous = [];
$matchedStudentIds = [];
foreach ($masterRows as $masterRow) {
    $match = uniqueIndexedMatch($students, $studentExactIndex, $studentTokenIndex, $masterRow['name'], $masterRow['program']);
    if ($match['status'] === 'unmatched') {
        $unmatched[] = $masterRow;
        continue;
    }
    if ($match['status'] === 'ambiguous') {
        $ambiguous[] = $masterRow + ['candidate_ids' => array_column($match['records'], 'tbl_student_id')];
        continue;
    }

    $student = $match['record'];
    $studentId = (int) $student['tbl_student_id'];
    if (isset($matchedStudentIds[$studentId])) {
        $ambiguous[] = $masterRow + ['candidate_ids' => [$studentId], 'reason' => 'student matched more than once'];
        continue;
    }
    $matchedStudentIds[$studentId] = true;
    $facilitator = $sectionFacilitators[$masterRow['section']] ?? null;
    $matched[] = $masterRow + [
        'student_id' => $studentId,
        'database_name' => $student['student_name'],
        'old_section' => $student['course_section'],
        'old_facilitator_id' => $student['created_by'] !== null ? (int) $student['created_by'] : null,
        'new_facilitator_id' => $facilitator ? (int) $facilitator['user_id'] : null,
        'match_method' => $match['method'],
    ];
}

$changes = array_values(array_filter($matched, static fn(array $row): bool =>
    $row['old_section'] !== $row['section'] || $row['old_facilitator_id'] !== $row['new_facilitator_id']
));
$databaseOnly = array_values(array_filter($students, static fn(array $student): bool => !isset($matchedStudentIds[(int) $student['tbl_student_id']])));
$databaseOnlyAssigned = array_values(array_filter($databaseOnly, static fn(array $student): bool =>
    trim((string) ($student['course_section'] ?? '')) !== $component || $student['created_by'] !== null
));
$totalChangesNeeded = count($changes) + count($databaseOnlyAssigned);

$report = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'component' => $component,
    'source_file' => realpath($file),
    'sheet_count' => count($sections),
    'workbook_students' => count($masterRows),
    'database_component_candidates' => count($students),
    'matched' => count($matched),
    'changes_needed' => $totalChangesNeeded,
    'listed_changes_needed' => count($changes),
    'move_to_pending_count' => count($databaseOnlyAssigned),
    'unmatched_count' => count($unmatched),
    'ambiguous_count' => count($ambiguous),
    'database_only_count' => count($databaseOnly),
    'duplicate_workbook_names' => $duplicateWorkbookNames,
    'missing_facilitators' => $missingFacilitators,
    'section_counts' => array_map(static function (array $details) use ($masterRows): array {
        $details['students'] = count(array_filter($masterRows, static fn(array $row): bool => $row['section'] === $details['section']));
        return $details;
    }, array_values($sections)),
    'unmatched' => $unmatched,
    'ambiguous' => $ambiguous,
    'database_only' => array_map(static fn(array $row): array => [
        'student_id' => (int) $row['tbl_student_id'],
        'name' => $row['student_name'],
        'section' => $row['course_section'],
        'will_move_to_pending' => trim((string) ($row['course_section'] ?? '')) !== $component || $row['created_by'] !== null,
    ], $databaseOnly),
    'changes' => $changes,
];

if ($apply) {
    if (!$sections || !$masterRows) {
        $report['applied'] = false;
        $report['blocked_reason'] = 'The workbook contains no valid ' . $component . ' section sheets or student rows.';
    } elseif ($unmatched || $ambiguous || $missingFacilitators || count($matched) !== count($masterRows)) {
        $report['applied'] = false;
        $report['blocked_reason'] = 'Resolve duplicate, unmatched, ambiguous, or missing-facilitator records before applying.';
    } else {
        $backupDirectory = __DIR__ . '/../backups';
        if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0775, true) && !is_dir($backupDirectory)) {
            throw new RuntimeException('Unable to create the reconciliation backup directory.');
        }
        $backupPath = $backupDirectory . '/' . strtolower($component) . '-reconciliation-' . date('Ymd-His') . '.json';
        $backupAssignmentsStmt = $conn->prepare("SELECT ads.* FROM tbl_admin_sections ads INNER JOIN tbl_users u ON u.user_id = ads.user_id WHERE u.role = 'facilitator' AND u.program = ?");
        $backupAssignmentsStmt->execute([$component]);
        $backupFoldersStmt = $conn->prepare("SELECT * FROM tbl_section_folders WHERE program = ?");
        $backupFoldersStmt->execute([$component]);
        $backup = [
            'created_at' => date(DATE_ATOM),
            'component' => $component,
            'source_file' => realpath($file),
            'students' => array_map(static fn(array $student): array => [
                'tbl_student_id' => (int) $student['tbl_student_id'],
                'course_section' => $student['course_section'],
                'created_by' => $student['created_by'],
            ], $students),
            'admin_sections' => $backupAssignmentsStmt->fetchAll(PDO::FETCH_ASSOC),
            'section_folders' => $backupFoldersStmt->fetchAll(PDO::FETCH_ASSOC),
        ];
        if (file_put_contents($backupPath, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
            throw new RuntimeException('Unable to write the reconciliation backup.');
        }
        $report['backup_file'] = realpath($backupPath) ?: $backupPath;

        $conn->beginTransaction();
        try {
            foreach ($sections as $section => $details) {
                $facilitator = $sectionFacilitators[$section];
                createSectionFolder($conn, $component, $section, $actorId ?: null);

                $delete = $conn->prepare("
                    DELETE ads FROM tbl_admin_sections ads
                    INNER JOIN tbl_users u ON u.user_id = ads.user_id
                    WHERE ads.course_section = ? AND u.role = 'facilitator' AND u.program = ? AND ads.user_id <> ?
                ");
                $delete->execute([$section, $component, (int) $facilitator['user_id']]);

                $upsert = $conn->prepare("
                    INSERT INTO tbl_admin_sections (user_id, course_section, assigned_by, assigned_at)
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE assigned_by = VALUES(assigned_by), assigned_at = VALUES(assigned_at)
                ");
                $upsert->execute([(int) $facilitator['user_id'], $section, $actorId ?: null]);
            }

            $update = $conn->prepare("UPDATE tbl_student SET course_section = ?, created_by = ? WHERE tbl_student_id = ?");
            foreach ($changes as $row) {
                $update->execute([$row['section'], $row['new_facilitator_id'], $row['student_id']]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('A listed student assignment changed during reconciliation. Nothing was committed.');
                }
            }

            $moveToPending = $conn->prepare("UPDATE tbl_student SET course_section = ?, created_by = NULL WHERE tbl_student_id = ?");
            foreach ($databaseOnlyAssigned as $student) {
                $moveToPending->execute([$component, (int) $student['tbl_student_id']]);
                if ($moveToPending->rowCount() !== 1) {
                    throw new RuntimeException('An unlisted student assignment changed during reconciliation. Nothing was committed.');
                }
            }

            $conn->commit();
            $report['applied'] = true;
            $report['updated_students'] = $totalChangesNeeded;
            $report['moved_to_pending'] = count($databaseOnlyAssigned);
        } catch (Throwable $error) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $error;
        }
    }
}

if ($isWebRunner) {
    $GLOBALS['cwtsReconciliationReport'] = $report;
    return;
} elseif ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    printf(
        "%s: %d sheets, %d workbook students, %d matched, %d changes, %d unmatched, %d ambiguous, %d missing facilitators, %d database-only.\n",
        strtoupper($report['mode']),
        $report['sheet_count'],
        $report['workbook_students'],
        $report['matched'],
        $report['changes_needed'],
        $report['unmatched_count'],
        $report['ambiguous_count'],
        count($report['missing_facilitators']),
        $report['database_only_count']
    );
    if ($apply) {
        echo !empty($report['applied']) ? "Changes committed.\n" : "No changes applied: {$report['blocked_reason']}\n";
    }
}

exit(($apply && empty($report['applied'])) ? 2 : 0);
