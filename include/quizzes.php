<?php
require_once __DIR__ . '/learning-materials.php';
require_once __DIR__ . '/quiz-grades.php';

function ensureQuizTables(PDO $conn) {
    $conn->exec("CREATE TABLE IF NOT EXISTS tbl_quiz_focus_events (
        response_id INT NOT NULL, event_id VARCHAR(64) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(response_id,event_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS tbl_quiz_grade_links (
        quiz_id INT PRIMARY KEY, grade_column_id INT NOT NULL,
        INDEX idx_quiz_grade_column (grade_column_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS tbl_quizzes (
        quiz_id INT AUTO_INCREMENT PRIMARY KEY, uploaded_by INT NOT NULL,
        title VARCHAR(180) NOT NULL, description TEXT NOT NULL,
        audience_components VARCHAR(20) NOT NULL, audience_rotc_levels VARCHAR(30) NOT NULL,
        definition_json LONGTEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'draft',
        revision INT NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_quiz_owner (uploaded_by), INDEX idx_quiz_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS tbl_quiz_responses (
        response_id INT AUTO_INCREMENT PRIMARY KEY, quiz_id INT NOT NULL, user_id INT NOT NULL,
        answers_json LONGTEXT NOT NULL, grades_json LONGTEXT NOT NULL,
        state VARCHAR(20) NOT NULL DEFAULT 'draft', score DECIMAL(10,2) NOT NULL DEFAULT 0,
        total_points DECIMAL(10,2) NOT NULL DEFAULT 0, needs_review TINYINT NOT NULL DEFAULT 0,
        released TINYINT NOT NULL DEFAULT 0, started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        submitted_at DATETIME NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_quiz_student (quiz_id, user_id), INDEX idx_quiz_response (quiz_id, state)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn->exec("CREATE TABLE IF NOT EXISTS tbl_quiz_files (
        file_id INT AUTO_INCREMENT PRIMARY KEY, response_id INT NOT NULL, question_id VARCHAR(48) NOT NULL,
        original_name VARCHAR(255) NOT NULL, storage_name VARCHAR(68) NOT NULL, file_size INT NOT NULL,
        INDEX idx_response_file (response_id, question_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
function quizEscape($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function quizJson($value) { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
function quizCanManage(array $actor, array $quiz) {
    return ($actor['role'] ?? '') === 'super_admin' || (($actor['role'] ?? '') === 'coordinator' && (int) $quiz['uploaded_by'] === (int) $actor['user_id']);
}
function quizFind(PDO $conn, $id, $lock = false) {
    $stmt = $conn->prepare('SELECT * FROM tbl_quizzes WHERE quiz_id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([(int) $id]);
    $quiz = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$quiz) throw new OutOfBoundsException('Quiz not found.');
    return $quiz;
}
function quizVisible(PDO $conn, array $actor, array $quiz, $viewer = null) {
    if (quizCanManage($actor, $quiz)) return true;
    if ($quiz['status'] !== 'published') return false;
    $visibility = learningMaterialVisibilitySql($viewer ?? learningMaterialViewer($conn, $actor));
    $stmt = $conn->prepare('SELECT quiz_id FROM tbl_quizzes m WHERE m.quiz_id = ? AND ' . $visibility['sql']);
    $stmt->execute(array_merge([$quiz['quiz_id']], $visibility['params']));
    return (bool) $stmt->fetchColumn();
}
function quizText($value, $max, $required = false) {
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) throw new InvalidArgumentException('Invalid text value.');
    $value = trim($value);
    if (mb_strlen($value) > $max || ($required && $value === '')) throw new InvalidArgumentException('A required field is empty or too long.');
    return $value;
}
function quizList($values, $max = 50) {
    if (!is_array($values) || count($values) > $max) throw new InvalidArgumentException('Too many answer options.');
    $result = array_map(fn($v) => quizText($v, 500, true), array_values($values));
    if (count(array_unique($result)) !== count($result)) throw new InvalidArgumentException('Options and grid rows must be unique.');
    return $result;
}
function quizMediaUrl($value, $video = false) {
    $value = quizText($value, 2000);
    if ($value === '') return '';
    $url = parse_url($value);
    if (!$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass'])) throw new InvalidArgumentException('Media links must use HTTPS.');
    if (!$video) return $value;
    $host = strtolower($url['host']); $path = $url['path'] ?? ''; $id = '';
    if ($host === 'youtu.be') $id = trim($path, '/');
    elseif (in_array($host, ['youtube.com','www.youtube.com','m.youtube.com','www.youtube-nocookie.com'], true)) {
        parse_str($url['query'] ?? '', $query);
        $id = preg_match('~^/(?:embed|shorts)/([a-zA-Z0-9_-]{11})$~D', $path, $match) ? $match[1] : ($query['v'] ?? '');
    }
    if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{11}$/D', $id)) throw new InvalidArgumentException('Enter a valid YouTube video link.');
    return 'https://www.youtube-nocookie.com/embed/' . $id;
}
function quizDefinition($input) {
    if (!is_array($input)) throw new InvalidArgumentException('Invalid quiz.');
    $audience = normalizeLearningMaterialAudience($input['components'] ?? [], $input['levels'] ?? []);
    $d = ['title' => quizText($input['title'] ?? '', 180, true), 'description' => quizText($input['description'] ?? '', 5000),
        'components' => explode(',', $audience['components']), 'levels' => array_values(array_filter(explode(',', $audience['levels']))),
        'confirmation' => quizText($input['confirmation'] ?? 'Your response has been recorded.', 1000),
        'accent' => preg_match('/^#[0-9a-f]{6}$/iD', $input['accent'] ?? '') ? $input['accent'] : '#198754'];
    $column = $input['grade_column_id'] ?? 0;
    if (filter_var($column, FILTER_VALIDATE_INT) === false || (int)$column < 0) throw new InvalidArgumentException('Invalid score destination.');
    $d['grade_column_id'] = (int)$column;
    $timeLimit = $input['time_limit_minutes'] ?? 0;
    if (filter_var($timeLimit, FILTER_VALIDATE_INT) === false || (int)$timeLimit < 0 || (int)$timeLimit > 10080) {
        throw new InvalidArgumentException('Time limit must be from 1 minute to 7 days, or 0 for no limit.');
    }
    $d['time_limit_minutes'] = (int)$timeLimit;
    foreach (['shuffle_questions', 'shuffle_options', 'allow_edit', 'release_immediately', 'monitor_focus'] as $key) $d[$key] = ($input[$key] ?? false) === true;
    foreach (['opens_at', 'closes_at'] as $key) {
        $value = $input[$key] ?? '';
        if (!is_string($value) || ($value !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $value) || !strtotime($value)))) throw new InvalidArgumentException('Invalid opening or closing date.');
        $d[$key] = $value;
    }
    if ($d['opens_at'] && $d['closes_at'] && strtotime($d['opens_at']) >= strtotime($d['closes_at'])) throw new InvalidArgumentException('Closing time must be after opening time.');
    $questions = $input['questions'] ?? [];
    if (!is_array($questions) || count($questions) < 1 || count($questions) > 100) throw new InvalidArgumentException('Add 1 to 100 questions/sections.');
    $types = ['multiple_choice', 'checkboxes', 'dropdown', 'short_answer', 'paragraph', 'scale', 'rating', 'date', 'time', 'multiple_grid', 'checkbox_grid', 'file', 'section'];
    $ids = []; $sectionPositions = ['start' => -1]; $d['questions'] = []; $real = 0;
    foreach (array_values($questions) as $position => $q) {
        if (!is_array($q) || !is_string($q['id'] ?? null) || !preg_match('/^[a-zA-Z0-9_-]{1,48}$/D', $q['id']) || isset($ids[$q['id']]) || $q['id'] === 'start') throw new InvalidArgumentException('Question identifiers are invalid or repeated.');
        $ids[$q['id']] = true;
        $type = $q['type'] ?? '';
        if (!in_array($type, $types, true)) throw new InvalidArgumentException('Unsupported question type.');
        $item = ['id' => $q['id'], 'type' => $type, 'title' => quizText($q['title'] ?? '', 500, true), 'help' => quizText($q['help'] ?? '', 2000),
            'required' => ($q['required'] ?? false) === true, 'points' => 0, 'options' => [], 'rows' => [], 'answer' => [], 'next' => [],
            'feedback' => quizText($q['feedback'] ?? '', 2000), 'validation' => 'none', 'min' => 1, 'max' => 5,
            'image_url' => quizMediaUrl($q['image_url'] ?? ''), 'video_url' => quizMediaUrl($q['video_url'] ?? '', true)];
        if ($type === 'section') { $sectionPositions[$q['id']] = $position; $d['questions'][] = $item; continue; }
        $real++;
        $points = filter_var($q['points'] ?? 0, FILTER_VALIDATE_INT);
        if ($points === false || $points < 0 || $points > 1000) throw new InvalidArgumentException('Points must be from 0 to 1,000.');
        $item['points'] = $points;
        if (in_array($type, ['multiple_choice','checkboxes','dropdown','multiple_grid','checkbox_grid'], true)) {
            $item['options'] = quizList($q['options'] ?? []);
            if (count($item['options']) < 2) throw new InvalidArgumentException('Add at least two options per choice/grid question.');
        }
        if (in_array($type, ['multiple_grid','checkbox_grid'], true)) {
            $item['rows'] = quizList($q['rows'] ?? [], 20);
            if (!$item['rows']) throw new InvalidArgumentException('Add at least one grid row.');
        }
        if (in_array($type, ['scale','rating'], true)) {
            $min = filter_var($q['min'] ?? 1, FILTER_VALIDATE_INT); $max = filter_var($q['max'] ?? 5, FILTER_VALIDATE_INT);
            if ($min === false || $max === false || $min < 0 || $max > 10 || $min >= $max) throw new InvalidArgumentException('Scale range must be between 0 and 10.');
            $item['min'] = $min; $item['max'] = $max;
        }
        if ($type === 'short_answer') {
            if (!in_array($q['validation'] ?? 'none', ['none','email','number','url'], true)) throw new InvalidArgumentException('Invalid response validation.');
            $item['validation'] = $q['validation'] ?? 'none';
        }
        if (!in_array($type, ['paragraph','multiple_grid','checkbox_grid','file'], true)) {
            $item['answer'] = quizList($q['answer'] ?? []);
            if (in_array($type, ['multiple_choice','dropdown','checkboxes'], true)) {
                if (array_diff($item['answer'], $item['options'])) throw new InvalidArgumentException('Answer key must use existing options.');
                if ($type !== 'checkboxes' && count($item['answer']) > 1) throw new InvalidArgumentException('Choose one correct option.');
            }
        }
        if (in_array($type, ['multiple_choice','dropdown'], true)) {
            if (!is_array($q['next'] ?? [])) throw new InvalidArgumentException('Invalid section routing.');
            foreach (($q['next'] ?? []) as $option => $target) {
                if (!in_array((string) $option, $item['options'], true) || !is_string($target)) throw new InvalidArgumentException('Invalid section routing.');
                if ($target !== '') $item['next'][(string) $option] = $target;
            }
        }
        $d['questions'][] = $item;
    }
    if (!$real) throw new InvalidArgumentException('Add at least one question.');
    foreach ($d['questions'] as $position => $q) {
        if (!$q['next']) continue;
        if (isset($d['questions'][$position + 1]) && $d['questions'][$position + 1]['type'] !== 'section') throw new InvalidArgumentException('Place a branching question last in its section.');
        foreach ($q['next'] as $target) if ($target !== 'submit' && (!isset($sectionPositions[$target]) || $sectionPositions[$target] <= $position)) throw new InvalidArgumentException('Answer routing can only go to a later section or submit.');
    }
    return $d;
}
function quizSections(array $d) {
    $sections = [['id' => 'start', 'title' => $d['title'], 'help' => '', 'questions' => []]];
    foreach ($d['questions'] as $q) {
        if ($q['type'] === 'section') $sections[] = ['id' => $q['id'], 'title' => $q['title'], 'help' => $q['help'], 'questions' => []];
        else $sections[count($sections)-1]['questions'][] = $q;
    }
    return $sections;
}
function quizPath(array $d, array $answers) {
    $sections = quizSections($d); $map = array_column($sections, null, 'id'); $ids = array_column($sections, 'id'); $index = 0; $result = [];
    while ($index < count($sections)) {
        $target = null;
        foreach ($sections[$index]['questions'] as $q) {
            $result[] = $q;
            $value = $answers[$q['id']] ?? '';
            if ($q['next'] && is_string($value) && isset($q['next'][$value])) $target = $q['next'][$value];
        }
        if ($target === 'submit') break;
        $index = $target !== null ? array_search($target, $ids, true) : $index + 1;
        if ($index === false) throw new RuntimeException('Invalid quiz routing.');
    }
    return $result;
}
function quizGrade(array $d, array $answers, $strict = true) {
    $answers = array_map(fn($value) => is_string($value) ? trim($value) : $value, $answers);
    $clean = []; $grades = []; $score = 0; $total = 0; $review = false;
    foreach (quizPath($d, $answers) as $q) {
        $id = $q['id']; $type = $q['type']; $v = $answers[$id] ?? '';
        $empty = $v === '' || $v === [] || $v === null;
        if ($empty) { $v = ''; if ($strict && $q['required']) throw new InvalidArgumentException('Answer required: ' . $q['title']); }
        elseif ($type === 'checkboxes') {
            $v = quizList($v); if (array_diff($v, $q['options'])) throw new InvalidArgumentException('Invalid checkbox answer.'); sort($v);
        } elseif (in_array($type, ['multiple_grid','checkbox_grid'], true)) {
            if (!is_array($v) || array_diff(array_keys($v), $q['rows'])) throw new InvalidArgumentException('Invalid grid answer.');
            foreach ($q['rows'] as $row) {
                $cell = $v[$row] ?? ($type === 'checkbox_grid' ? [] : '');
                if ($strict && $q['required'] && ($cell === '' || $cell === [])) throw new InvalidArgumentException('Complete every row: ' . $q['title']);
                if ($type === 'checkbox_grid') { $cell = quizList($cell); if (array_diff($cell, $q['options'])) throw new InvalidArgumentException('Invalid grid option.'); }
                elseif ($cell !== '' && (!is_string($cell) || !in_array($cell, $q['options'], true))) throw new InvalidArgumentException('Invalid grid option.');
                $v[$row] = $cell;
            }
        } elseif ($type === 'file') {
            if (!is_array($v) || !isset($v['file_id']) || !is_int($v['file_id'])) throw new InvalidArgumentException('Invalid file response.');
            $v = ['file_id' => $v['file_id']];
        } else {
            $v = quizText($v, $type === 'paragraph' ? 10000 : 2000);
            if (in_array($type, ['multiple_choice','dropdown'], true) && !in_array($v, $q['options'], true)) throw new InvalidArgumentException('Invalid choice.');
            if (in_array($type, ['scale','rating'], true) && (!ctype_digit($v) || (int) $v < $q['min'] || (int) $v > $q['max'])) throw new InvalidArgumentException('Invalid scale value.');
            if ($type === 'date' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $v, $parts) || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]))) throw new InvalidArgumentException('Invalid date answer.');
            if ($type === 'time' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $v)) throw new InvalidArgumentException('Invalid time answer.');
            if ($q['validation'] === 'email' && !filter_var($v, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
            if ($q['validation'] === 'number' && !is_numeric($v)) throw new InvalidArgumentException('Enter a number.');
            if ($q['validation'] === 'url' && (!filter_var($v, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $v))) throw new InvalidArgumentException('Enter an HTTP or HTTPS URL.');
        }
        $clean[$id] = $v; $points = $q['points']; $earned = 0; $manual = false;
        if (in_array($type, ['multiple_grid','checkbox_grid'], true) && is_array($v)) {
            $empty = !array_filter($v, fn($cell) => $cell !== '' && $cell !== []);
        }
        if (!$empty && $points > 0) {
            if (!$q['answer']) { $earned = null; $manual = true; $review = true; }
            elseif ($type === 'checkboxes') { $key = $q['answer']; sort($key); $earned = $v === $key ? $points : 0; }
            else { $normalized = fn($x) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $x))); $earned = in_array($normalized($v), array_map($normalized, $q['answer']), true) ? $points : 0; }
        }
        $score += $earned ?? 0; $total += $points;
        $grades[$id] = ['points' => $earned, 'max' => $points, 'manual' => $manual, 'feedback' => ''];
    }
    return ['answers' => $clean, 'grades' => $grades, 'score' => $score, 'total' => $total, 'needs_review' => $review];
}
function quizPublicDefinition(array $d) {
    foreach ($d['questions'] as &$q) { unset($q['answer'], $q['feedback']); }
    unset($q);
    return $d;
}
function quizAccepting(array $quiz, array $d) {
    if ($quiz['status'] !== 'published') throw new InvalidArgumentException('This quiz is not accepting responses.');
    if ($d['opens_at'] && time() < strtotime($d['opens_at'])) throw new InvalidArgumentException('This quiz has not opened yet.');
    if ($d['closes_at'] && time() > strtotime($d['closes_at'])) throw new InvalidArgumentException('This quiz is closed. Your saved draft is retained.');
}
function quizResponse(PDO $conn, $quizId, $userId) {
    $stmt = $conn->prepare('SELECT * FROM tbl_quiz_responses WHERE quiz_id = ? AND user_id = ?'); $stmt->execute([$quizId, $userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
function quizResponseTiming(array $response, array $definition) {
    $minutes = max(0, (int)($definition['time_limit_minutes'] ?? 0));
    if ($minutes === 0) return null;
    $started = strtotime((string)($response['started_at'] ?? ''));
    if ($started === false) $started = time();
    $now = time();
    $deadline = $started + ($minutes * 60);
    return [
        'started_at' => $started,
        'deadline_at' => $deadline,
        'server_time' => $now,
        'remaining_seconds' => max(0, $deadline - $now),
        'expired' => $now >= $deadline,
    ];
}
function quizCheckFiles(PDO $conn, $responseId, array $questions, array $answers) {
    foreach ($questions as $q) if ($q['type'] === 'file' && !empty($answers[$q['id']])) {
        $value = $answers[$q['id']];
        if (!is_array($value) || !isset($value['file_id'])) throw new InvalidArgumentException('Invalid attachment.');
        $stmt = $conn->prepare('SELECT file_id FROM tbl_quiz_files WHERE file_id = ? AND response_id = ? AND question_id = ?');
        $stmt->execute([$value['file_id'], $responseId, $q['id']]);
        if (!$stmt->fetchColumn()) throw new InvalidArgumentException('The attachment does not belong to this response.');
    }
}

function quizFocusCount(PDO $conn, $responseId) {
    $stmt=$conn->prepare('SELECT COUNT(*) FROM tbl_quiz_focus_events WHERE response_id=?');
    $stmt->execute([$responseId]); return (int)$stmt->fetchColumn();
}
function quizForcedAnswers(array $d, array $answers) {
    // Preserve valid partial work; invalid/incomplete entries count as unanswered.
    foreach ($d['questions'] as $q) {
        if ($q['type']==='section') continue;
        $single=$d; $q['next']=[]; $single['questions']=[$q];
        try { quizGrade($single, [$q['id']=>$answers[$q['id']]??''], false); }
        catch (InvalidArgumentException $error) { unset($answers[$q['id']]); }
    }
    return $answers;
}

function quizFocusIds(PDO $conn, $responseId) {
    $stmt=$conn->prepare('SELECT event_id FROM tbl_quiz_focus_events WHERE response_id=?');
    $stmt->execute([$responseId]); return $stmt->fetchAll(PDO::FETCH_COLUMN);
}
