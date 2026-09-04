<?php
require_once __DIR__ . '/grade-schema.php';

function quizGradeColumns(PDO $conn, array $actor) {
    if (!in_array($actor['role'], ['super_admin', 'coordinator'], true)) throw new DomainException('Only quiz authors can select a score destination.');
    $sql = 'SELECT grade_column_id,label,group_label,max_score,program_scope,is_default,created_by FROM tbl_grade_columns WHERE is_active=1 AND max_score>0';
    $params = [];
    if ($actor['role'] !== 'super_admin') {
        $sql .= ' AND (program_scope IS NULL OR program_scope=?) AND (is_default=1 OR created_by IS NULL OR created_by=?)';
        $params = [normalizeProgram($actor['program'] ?? null), $actor['user_id']];
    }
    $stmt = $conn->prepare($sql . ' ORDER BY group_label,sort_order,grade_column_id');
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function quizValidateGradeDestination(PDO $conn, array $actor, array $definition) {
    $id = (int)($definition['grade_column_id'] ?? 0);
    if (!$id) return;
    foreach (quizGradeColumns($conn, $actor) as $column) {
        if ((int)$column['grade_column_id'] !== $id) continue;
        if ($column['program_scope'] !== null && array_diff($definition['components'], [$column['program_scope']])) {
            throw new InvalidArgumentException('The score destination must support every selected quiz component. Choose a shared column or change the audience.');
        }
        if (!array_filter($definition['questions'], fn($q) => $q['points'] > 0)) throw new InvalidArgumentException('A quiz linked to the grading sheet needs points.');
        return;
    }
    throw new InvalidArgumentException('This score destination is unavailable to your account. Select an active grading column.');
}

function quizSaveGradeLink(PDO $conn, $quizId, array $definition) {
    $conn->prepare('DELETE FROM tbl_quiz_grade_links WHERE quiz_id=?')->execute([$quizId]);
    if (!empty($definition['grade_column_id'])) {
        $conn->prepare('INSERT INTO tbl_quiz_grade_links (quiz_id,grade_column_id) VALUES (?,?)')->execute([$quizId,$definition['grade_column_id']]);
    }
}

// Called in the same transaction as grading/submission so the two records agree.
function quizSyncGrade(PDO $conn, array $quiz, $userId) {
    $link = $conn->prepare('SELECT grade_column_id FROM tbl_quiz_grade_links WHERE quiz_id=?');
    $link->execute([$quiz['quiz_id']]);
    $columnId = (int)$link->fetchColumn();
    if (!$columnId) return;
    $stmt = $conn->prepare('SELECT * FROM tbl_grade_columns WHERE grade_column_id=? FOR UPDATE');
    $stmt->execute([$columnId]);
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$column || !$column['is_active']) return;
    $stmt = $conn->prepare('SELECT tbl_student_id FROM tbl_student WHERE user_id=?');
    $stmt->execute([$userId]);
    $students = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($students) !== 1) throw new InvalidArgumentException('The student account needs one linked grading-sheet student record before its score can be saved.');
    // A locking read sees concurrent committed submissions, even under REPEATABLE READ.
    $stmt = $conn->prepare("SELECT r.score,r.total_points FROM tbl_quiz_responses r JOIN tbl_quiz_grade_links l ON l.quiz_id=r.quiz_id WHERE l.grade_column_id=? AND r.user_id=? AND r.state='submitted' AND r.released=1 AND r.needs_review=0 FOR UPDATE");
    $stmt->execute([$columnId,$userId]);
    $earned = 0; $total = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) { $earned += (float)$row['score']; $total += (float)$row['total_points']; }
    $score = $total > 0 ? round(min(1, max(0, $earned / $total)) * (float)$column['max_score'], 2) : null;
    $stmt = $conn->prepare('SELECT grade_score_id FROM tbl_grade_scores WHERE grade_column_id=? AND tbl_student_id=? FOR UPDATE');
    $stmt->execute([$columnId,$students[0]]);
    $scoreId = $stmt->fetchColumn();
    if ($scoreId) {
        $conn->prepare('UPDATE tbl_grade_scores SET score=?,updated_by=? WHERE grade_score_id=?')->execute([$score,$quiz['uploaded_by'],$scoreId]);
    } elseif ($score !== null) {
        $conn->prepare('INSERT INTO tbl_grade_scores (grade_column_id,tbl_student_id,score,updated_by) VALUES (?,?,?,?)')->execute([$columnId,$students[0],$score,$quiz['uploaded_by']]);
    }
}
