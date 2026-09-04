<?php
session_start();
require_once __DIR__ . '/../include/quizzes.php';
function quizReply($status, $data) { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo quizJson($data); exit; }
if (empty($_SESSION['user_id'])) quizReply(401, ['message' => 'Please sign in again.']);
require_once __DIR__ . '/../conn/conn.php';
if (!learningMaterialSessionActive($conn)) quizReply(401, ['message' => 'Your session has expired.']);
$actor = getCurrentUserRecord($conn);
if (!$actor) quizReply(403, ['message' => 'Account unavailable.']);
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$data = $_POST;
try {
    if ($post && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === 0) {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 1048576) throw new InvalidArgumentException('Quiz data is too large.');
        $data = json_decode(file_get_contents('php://input', false, null, 0, 1048577), true, 512, JSON_THROW_ON_ERROR);
    }
    if (!is_array($data)) throw new InvalidArgumentException('Invalid request.');
    $action = $post ? ($data['action'] ?? '') : ($_GET['action'] ?? 'load');
    if ($post) {
        $csrf = $data['csrf_token'] ?? '';
        if (!is_string($csrf) || empty($_SESSION['quiz_csrf']) || !hash_equals($_SESSION['quiz_csrf'], $csrf)) quizReply(403, ['message' => 'Reload the page and try again.']);
    } elseif (!in_array($action, ['load','responses','response','export','attachment','grade_columns'], true)) quizReply(405, ['message' => 'Use POST for changes.']);
    session_write_close();
    ensureQuizTables($conn);
    $id = (int) ($post ? ($data['id'] ?? 0) : ($_GET['id'] ?? 0));
    $viewer = learningMaterialViewer($conn, $actor);

    if ($action === 'grade_columns') {
        if (!canUploadLearningMaterials($actor)) throw new DomainException('Only quiz authors can select a score destination.');
        ensureGradeTables($conn); seedDefaultGradeColumns($conn);
        quizReply(200, ['columns'=>quizGradeColumns($conn, $actor)]);
    }
    if ($action === 'save') {
        if (!canUploadLearningMaterials($actor)) quizReply(403, ['message' => 'Only administrators and coordinators can create quizzes.']);
        $d = quizDefinition($data['definition'] ?? null);
        if (!empty($d['grade_column_id'])) { ensureGradeTables($conn); seedDefaultGradeColumns($conn); }
        quizValidateGradeDestination($conn, $actor, $d);
        $conn->beginTransaction();
        if ($id) {
            $quiz = quizFind($conn, $id, true);
            if (!quizCanManage($actor, $quiz)) throw new DomainException('You cannot edit this quiz.');
            if ((int)($data['revision'] ?? 0) !== (int)$quiz['revision']) throw new DomainException('This quiz changed in another window. Reload before editing.');
            $count = $conn->prepare('SELECT COUNT(*) FROM tbl_quiz_responses WHERE quiz_id = ?'); $count->execute([$id]);
            if ((int)$count->fetchColumn() > 0) throw new DomainException('Responses/drafts already exist. Duplicate this quiz to change its questions or settings. You can still close or reopen it.');
            $stmt = $conn->prepare('UPDATE tbl_quizzes SET title=?, description=?, audience_components=?, audience_rotc_levels=?, definition_json=?, revision=revision+1 WHERE quiz_id=?');
            $stmt->execute([$d['title'],$d['description'],implode(',',$d['components']),implode(',',$d['levels']),quizJson($d),$id]);
        } else {
            $stmt = $conn->prepare('INSERT INTO tbl_quizzes (uploaded_by,title,description,audience_components,audience_rotc_levels,definition_json) VALUES (?,?,?,?,?,?)');
            $stmt->execute([$actor['user_id'],$d['title'],$d['description'],implode(',',$d['components']),implode(',',$d['levels']),quizJson($d)]); $id = (int)$conn->lastInsertId();
        }
        quizSaveGradeLink($conn, $id, $d);
        $conn->commit(); quizReply(200, ['id'=>$id,'revision'=>(int)quizFind($conn,$id)['revision'],'message'=>'Quiz saved.']);
    }
    if ($action === 'audience') {
        if ($actor['role'] !== 'super_admin') throw new DomainException('Only administrators can change the audience independently.');
        $audience = normalizeLearningMaterialAudience($data['components'] ?? [], $data['levels'] ?? []);
        $conn->beginTransaction();
        $quiz = quizFind($conn, $id, true);
        if ((int)($data['revision'] ?? 0) !== (int)$quiz['revision']) throw new DomainException('This quiz changed in another window. Reload before editing.');
        $definition = json_decode($quiz['definition_json'], true);
        $definition['components'] = explode(',', $audience['components']);
        $definition['levels'] = $audience['levels'] === '' ? [] : explode(',', $audience['levels']);
        quizValidateGradeDestination($conn, $actor, $definition);
        $conn->prepare('UPDATE tbl_quizzes SET audience_components=?, audience_rotc_levels=?, definition_json=?, revision=revision+1 WHERE quiz_id=?')->execute([$audience['components'], $audience['levels'], quizJson($definition), $id]);
        $revision = (int)$quiz['revision'] + 1;
        $conn->commit();
        quizReply(200, ['revision'=>$revision, 'components'=>$definition['components'], 'levels'=>$definition['levels']]);
    }
    if (in_array($action, ['status','duplicate'], true)) {
        $conn->beginTransaction(); $quiz = quizFind($conn,$id,true);
        if (!quizCanManage($actor,$quiz)) throw new DomainException('You cannot manage this quiz.');
        if ($action === 'status') {
            $status = $data['status'] ?? '';
            if (!in_array($status,['draft','published','closed'],true)) throw new InvalidArgumentException('Invalid publication status.');
            $conn->prepare('UPDATE tbl_quizzes SET status=?, revision=revision+1 WHERE quiz_id=?')->execute([$status,$id]);
        } else {
            $d = json_decode($quiz['definition_json'],true); $d['title'] = mb_substr($d['title'],0,170).' (copy)'; $d['grade_column_id'] = 0;
            $stmt = $conn->prepare('INSERT INTO tbl_quizzes (uploaded_by,title,description,audience_components,audience_rotc_levels,definition_json) VALUES (?,?,?,?,?,?)');
            $stmt->execute([$actor['user_id'],$d['title'],$d['description'],$quiz['audience_components'],$quiz['audience_rotc_levels'],quizJson($d)]); $id=(int)$conn->lastInsertId();
        }
        $conn->commit(); quizReply(200,['id'=>$id,'revision'=>(int)quizFind($conn,$id)['revision']]);
    }
    if (in_array($action,['response','grade','attachment'],true)) {
        $responseId = (int)($post ? ($data['response_id']??0) : ($_GET['response_id']??0));
        $stmt=$conn->prepare('SELECT r.*,u.full_name,u.username FROM tbl_quiz_responses r JOIN tbl_users u ON u.user_id=r.user_id WHERE r.response_id=?'); $stmt->execute([$responseId]); $response=$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$response) throw new OutOfBoundsException('Response not found.');
        $quiz=quizFind($conn,$response['quiz_id']); $manager=quizCanManage($actor,$quiz);
        if (!$manager && (int)$response['user_id'] !== (int)$actor['user_id']) throw new DomainException('You cannot view this response.');
        if ($action === 'attachment') {
            $stmt=$conn->prepare('SELECT * FROM tbl_quiz_files WHERE file_id=? AND response_id=?');$stmt->execute([(int)($_GET['file_id']??0),$responseId]);$file=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!$file) throw new OutOfBoundsException('Attachment not found.');
            $path=learningMaterialStoragePath($file['storage_name']); $stream=is_file($path)?fopen($path,'rb'):false;
            if (!$stream) throw new OutOfBoundsException('Attachment unavailable.');
            fseek($stream,strlen(learningMaterialFileGuard()));header('Content-Type: application/octet-stream');header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');header('Content-Disposition: attachment; filename="response-file"; filename*=UTF-8\'\''.rawurlencode($file['original_name']));header('Content-Length: '.$file['file_size']);fpassthru($stream);fclose($stream);exit;
        }
        if ($action === 'grade') {
            if (!$manager || $response['state'] !== 'submitted') throw new DomainException('Only the quiz manager can grade submitted responses.');
            $conn->beginTransaction(); $quiz=quizFind($conn,$quiz['quiz_id'],true);
            $stmt=$conn->prepare('SELECT * FROM tbl_quiz_responses WHERE response_id=?');$stmt->execute([$responseId]);$response=$stmt->fetch(PDO::FETCH_ASSOC);
            if ($response['state'] !== 'submitted' || $response['answers_json'] !== ($data['answers_version']??'')) throw new DomainException('The response changed. Reload before grading.');
            $grades=json_decode($response['grades_json'],true);$values=$data['grades']??null;
            if (!is_array($values) || array_diff(array_keys($values),array_keys($grades))) throw new InvalidArgumentException('Invalid grading data.');
            $score=0;
            foreach($grades as $qid=>&$grade) {
                $value=$values[$qid]['points']??null;
                if (!is_numeric($value) || !is_finite((float)$value) || $value<0 || $value>$grade['max']) throw new InvalidArgumentException('Enter a score within the available points for every question.');
                $grade['points']=round((float)$value,2);$grade['manual']=false;$grade['feedback']=quizText($values[$qid]['feedback']??'',2000);$score+=$grade['points'];
            } unset($grade);
            $conn->prepare('UPDATE tbl_quiz_responses SET grades_json=?,score=?,needs_review=0,released=? WHERE response_id=?')->execute([quizJson($grades),$score,($data['release']??false)===true?1:0,$responseId]);
            quizSyncGrade($conn, $quiz, $response['user_id']);
            $conn->commit();quizReply(200,['message'=>'Grades saved.']);
        }
        $released=$manager || (bool)$response['released'];
        $definition=json_decode($quiz['definition_json'],true);
        $stmt=$conn->prepare('SELECT file_id,original_name,question_id FROM tbl_quiz_files WHERE response_id=?');$stmt->execute([$responseId]);
        quizReply(200,['response_id'=>$responseId,'name'=>$response['full_name'],'username'=>$response['username'],'violations'=>quizFocusCount($conn,$responseId),'state'=>$response['state'],'answers'=>json_decode($response['answers_json'],true),
            'answers_version'=>$manager?$response['answers_json']:null,'grades'=>$released?json_decode($response['grades_json'],true):null,'score'=>$released?$response['score']:null,'total'=>$response['total_points'],'released'=>(bool)$response['released'],'needs_review'=>(bool)$response['needs_review'],
            'definition'=>$released?$definition:quizPublicDefinition($definition),'files'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    $quiz=quizFind($conn,$id); $d=json_decode($quiz['definition_json'],true); $manager=quizCanManage($actor,$quiz);
    if (in_array($action,['responses','export'],true)) {
        if (!$manager) throw new DomainException('Only the quiz manager can view responses.');
        if ($action==='export') {
            $stmt=$conn->prepare("SELECT r.*,u.full_name,u.username FROM tbl_quiz_responses r JOIN tbl_users u ON u.user_id=r.user_id WHERE r.quiz_id=? AND r.state='submitted' ORDER BY r.response_id");$stmt->execute([$id]);
            header('Content-Type: text/csv; charset=utf-8');header('Cache-Control: no-store');header('Content-Disposition: attachment; filename="quiz-'.$id.'-responses.csv"');$out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");
            $questions=array_values(array_filter($d['questions'],fn($q)=>$q['type']!=='section'));
            $safe=function($v){$v=(string)$v;return preg_match('/^[\s]*[=+@-]/u',$v)?"'".$v:$v;};
            fputcsv($out,array_map($safe,array_merge(['Name','Username','Submitted at','Score','Total','Needs review','Released'],array_column($questions,'title'))));
            while($row=$stmt->fetch(PDO::FETCH_ASSOC)){$answers=json_decode($row['answers_json'],true);$line=[$row['full_name'],$row['username'],$row['submitted_at'],$row['score'],$row['total_points'],$row['needs_review'],$row['released']];foreach($questions as $q){$v=$answers[$q['id']]??'';$line[]=is_array($v)?quizJson($v):$v;}fputcsv($out,array_map($safe,$line));}fclose($out);exit;
        }
        $page=max(1,min(100000,(int)($_GET['page']??1)));$offset=($page-1)*50;
        $stmt=$conn->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN needs_review=1 THEN 1 ELSE 0 END) AS pending, AVG(score) AS average_score FROM tbl_quiz_responses WHERE quiz_id=? AND state='submitted'");$stmt->execute([$id]);$summary=$stmt->fetch(PDO::FETCH_ASSOC);
        $stmt=$conn->prepare("SELECT r.response_id,(SELECT COUNT(*) FROM tbl_quiz_focus_events f WHERE f.response_id=r.response_id) AS violations,r.score,r.total_points,r.needs_review,r.released,r.submitted_at,u.full_name,u.username FROM tbl_quiz_responses r JOIN tbl_users u ON u.user_id=r.user_id WHERE r.quiz_id=? AND r.state='submitted' ORDER BY r.response_id DESC LIMIT 50 OFFSET {$offset}");$stmt->execute([$id]);
        quizReply(200,['title'=>$quiz['title'],'summary'=>$summary,'responses'=>$stmt->fetchAll(PDO::FETCH_ASSOC),'page'=>$page]);
    }
    if (!quizVisible($conn,$actor,$quiz,$viewer) && !($action==='load' && quizResponse($conn,$id,$actor['user_id']))) throw new DomainException('This quiz is not available to your account.');
    if ($action==='load') {
        $edit=($_GET['mode']??'')==='edit';if($edit&&!$manager)throw new DomainException('You cannot edit this quiz.');
        $response=quizResponse($conn,$id,$actor['user_id']);$count=$conn->prepare('SELECT COUNT(*) FROM tbl_quiz_responses WHERE quiz_id=?');$count->execute([$id]);
        $accepting=true;$acceptingMessage='';try{if(!quizVisible($conn,$actor,$quiz,$viewer))throw new InvalidArgumentException('This quiz is no longer available to your component.');quizAccepting($quiz,$d);}catch(InvalidArgumentException $error){$accepting=false;$acceptingMessage=$error->getMessage();}
        quizReply(200,['id'=>$id,'status'=>$quiz['status'],'revision'=>(int)$quiz['revision'],'manager'=>$manager,'locked'=>(int)$count->fetchColumn()>0,'accepting'=>$accepting,'accepting_message'=>$acceptingMessage,'definition'=>$edit?$d:quizPublicDefinition($d),
            'response'=>$response?['response_id'=>(int)$response['response_id'],'state'=>$response['state'],'answers'=>json_decode($response['answers_json'],true),'released'=>(bool)$response['released'],'violations'=>quizFocusCount($conn,$response['response_id']),'focus_events'=>quizFocusIds($conn,$response['response_id'])]:null]);
    }
    if (!in_array($action,['start','draft','submit','upload_file','focus_event'],true)) throw new InvalidArgumentException('Unknown action.');
    if ($actor['role']!=='student') throw new DomainException('Only students submit quiz responses. Use Preview to test your quiz.');
    $conn->beginTransaction();$quiz=quizFind($conn,$id,true);$d=json_decode($quiz['definition_json'],true);
    if (!quizVisible($conn,$actor,$quiz,$viewer)) throw new DomainException('This quiz is no longer available.');
    quizAccepting($quiz,$d);
    $response=quizResponse($conn,$id,$actor['user_id']);
    if (!$response) {
        $conn->prepare("INSERT INTO tbl_quiz_responses (quiz_id,user_id,answers_json,grades_json) VALUES (?,?,'{}','{}')")->execute([$id,$actor['user_id']]);$response=quizResponse($conn,$id,$actor['user_id']);
    }
    $violations=quizFocusCount($conn,$response['response_id']);
    if ($action==='focus_event' && $response['state']==='submitted' && $violations>=3) {
        $conn->commit(); quizReply(200,['response_id'=>(int)$response['response_id'],'violations'=>$violations,'forced'=>true]);
    }
    if ($response['state']==='submitted'&&(!$d['allow_edit']||$violations>=3)) {
        if ($action==='submit') {$conn->commit();quizReply(200,['response_id'=>(int)$response['response_id'],'message'=>'Response already submitted.']);}
        throw new DomainException('You already submitted this quiz.');
    }
    $forced=false;
    if ($action==='focus_event') {
        if (empty($d['monitor_focus'])) throw new DomainException('Focus monitoring is disabled for this quiz.');
        $eventId=$data['event_id']??'';
        if (!is_string($eventId)||!preg_match('/^[a-zA-Z0-9_-]{16,64}$/D',$eventId)) throw new InvalidArgumentException('Invalid focus event.');
        $exists=$conn->prepare('SELECT COUNT(*) FROM tbl_quiz_focus_events WHERE response_id=? AND event_id=?');
        $exists->execute([$response['response_id'],$eventId]);
        if (!(int)$exists->fetchColumn()) {
            $conn->prepare('INSERT INTO tbl_quiz_focus_events (response_id,event_id) VALUES (?,?)')->execute([$response['response_id'],$eventId]);
        }
        $violations=quizFocusCount($conn,$response['response_id']);
        if ($violations<3) { $conn->commit(); quizReply(200,['violations'=>$violations,'forced'=>false]); }
        $forced=true; $action='submit';
        $data['answers']=quizForcedAnswers($d, is_array($data['answers']??null)?$data['answers']:json_decode($response['answers_json'],true));
        foreach ($d['questions'] as $q) if ($q['type']==='file' && isset($data['answers'][$q['id']])) {
            try { quizCheckFiles($conn,$response['response_id'],[$q],$data['answers']); }
            catch (InvalidArgumentException $error) { unset($data['answers'][$q['id']]); }
        }
    }
    if ($action==='start') {$events=quizFocusIds($conn,$response['response_id']);$conn->commit();quizReply(200,['violations'=>$violations,'focus_events'=>$events,'response_id'=>(int)$response['response_id'],'answers'=>json_decode($response['answers_json'],true)]);}
    if ($action==='upload_file') {
        $qid=$data['question_id']??'';$question=null;foreach($d['questions'] as $q)if($q['id']===$qid&&$q['type']==='file')$question=$q;
        if(!$question)throw new InvalidArgumentException('Invalid file question.');
        $file=$_FILES['file']??[];
        if(($file['error']??null)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name']))throw new InvalidArgumentException('Choose a file within the displayed size limit.');
        $name=quizText(basename(str_replace('\\','/',$file['name'])),255,true);$size=validateLearningMaterialFile($file['tmp_name'],$name,learningMaterialChunkLimit());
        $count=$conn->prepare('SELECT COUNT(*) FROM tbl_quiz_files WHERE response_id=?');$count->execute([$response['response_id']]);if((int)$count->fetchColumn()>=200)throw new InvalidArgumentException('Too many file uploads for this response.');
        $storage=bin2hex(random_bytes(32)).'.php';$path=learningMaterialStoragePath($storage);$target=fopen($path,'xb');if(!$target)throw new RuntimeException('Cannot save attachment.');
        try {$source=fopen($file['tmp_name'],'rb');if(!$source)throw new RuntimeException('Cannot read attachment.');try{if(fwrite($target,learningMaterialFileGuard())!==strlen(learningMaterialFileGuard())||stream_copy_to_stream($source,$target)!==$size)throw new RuntimeException('Cannot write attachment.');}finally{fclose($source);}}finally{fclose($target);}
        $conn->prepare('INSERT INTO tbl_quiz_files (response_id,question_id,original_name,storage_name,file_size) VALUES (?,?,?,?,?)')->execute([$response['response_id'],$qid,$name,$storage,$size]);$fileId=(int)$conn->lastInsertId();$conn->commit();
        quizReply(200,['file_id'=>$fileId,'name'=>$name,'size'=>$size]);
    }
    $answers=$data['answers']??null;if(!is_array($answers)||count($answers)>100)throw new InvalidArgumentException('Invalid answers.');
    quizCheckFiles($conn,$response['response_id'],$d['questions'],$answers);
    $graded=$action==='submit'?quizGrade($d,$answers,!$forced):null;
    if($action==='draft') {
        if($response['state']==='submitted')throw new DomainException('Use Submit changes to update a submitted response.');
        $draft=array_intersect_key($answers,array_flip(array_column($d['questions'],'id')));
        $conn->prepare('UPDATE tbl_quiz_responses SET answers_json=? WHERE response_id=?')->execute([quizJson($draft),$response['response_id']]);
    } else {
        $release=$d['release_immediately']&&!$graded['needs_review'];
        $conn->prepare("UPDATE tbl_quiz_responses SET answers_json=?,grades_json=?,state='submitted',score=?,total_points=?,needs_review=?,released=?,submitted_at=CURRENT_TIMESTAMP WHERE response_id=?")->execute([quizJson($graded['answers']),quizJson($graded['grades']),$graded['score'],$graded['total'],$graded['needs_review']?1:0,$release?1:0,$response['response_id']]);
    }
    if ($action === 'submit') quizSyncGrade($conn, $quiz, $actor['user_id']);
    $conn->commit();quizReply(200,['response_id'=>(int)$response['response_id'],'forced'=>$forced,'violations'=>$violations,'message'=>$action==='submit'?$d['confirmation']:'Draft saved.']);
} catch (Throwable $error) {
    if (isset($conn)&&$conn->inTransaction())$conn->rollBack();
    if(isset($path)&&$action==='upload_file'&&is_file($path))unlink($path);
    $status=$error instanceof DomainException?403:($error instanceof OutOfBoundsException?404:($error instanceof InvalidArgumentException||$error instanceof JsonException?400:500));
    if($status===500)error_log('Quiz error: '.$error->getMessage());
    quizReply($status,['message'=>$status===500?'Unable to complete this quiz request. Please try again.':$error->getMessage()]);
}
