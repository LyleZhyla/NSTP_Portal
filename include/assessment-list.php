<?php
require_once __DIR__ . '/quizzes.php';
try {
    $quizVisibility = learningMaterialVisibilitySql(learningMaterialViewer($conn, $materialActor));
    $quizPage = max(1, min(100000, (int) ($_GET['quiz_page'] ?? 1)));
    $quizOffset = ($quizPage - 1) * 20;
    $quizWhere = $materialActor['role'] === 'super_admin' ? '1=1' : "((m.status='published' AND {$quizVisibility['sql']}) OR m.uploaded_by=? OR r.response_id IS NOT NULL)";
    $quizParams = [$materialActor['user_id']];
    if ($materialActor['role'] !== 'super_admin') $quizParams = array_merge($quizParams,$quizVisibility['params'],[$materialActor['user_id']]);
    $quizJoin = ' FROM tbl_quizzes m LEFT JOIN tbl_quiz_responses r ON r.quiz_id=m.quiz_id AND r.user_id=? WHERE ' . $quizWhere;
    $stmt=$conn->prepare('SELECT COUNT(*)'.$quizJoin);$stmt->execute($quizParams);$quizCount=(int)$stmt->fetchColumn();
    $stmt=$conn->prepare('SELECT m.quiz_id,m.title,m.description,m.status,m.uploaded_by,m.audience_components,m.audience_rotc_levels,r.response_id,r.state AS response_state'.$quizJoin." ORDER BY m.quiz_id DESC LIMIT 20 OFFSET {$quizOffset}");$stmt->execute($quizParams);$quizzes=$stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
    <div><h2 class="h4 mb-1">Assessments</h2><p class="text-muted mb-2">Quizzes and activities for your component.</p></div>
    <?php if (canUploadLearningMaterials($materialActor)): ?><a href="quiz.php?mode=edit" class="btn btn-success"><i class="fas fa-plus mr-1" aria-hidden="true"></i>Create Quiz</a><?php endif; ?>
</div>
<?php if (!$quizzes): ?><div class="learning-empty"><span class="learning-empty-icon"><i class="fas fa-clipboard-check" aria-hidden="true"></i></span><h3 class="h5">No assessments yet</h3><p class="text-muted">Available quizzes will appear here.</p></div><?php endif; ?>
<?php foreach ($quizzes as $quiz): $manage=quizCanManage($materialActor,$quiz); ?>
<article class="border rounded p-3 mb-3">
    <h3 class="h5"><?= quizEscape($quiz['title']) ?> <span class="badge badge-<?= $quiz['status']==='published'?'success':'secondary' ?>"><?= quizEscape(ucfirst($quiz['status'])) ?></span></h3>
    <p class="text-muted" style="white-space:pre-wrap;overflow-wrap:anywhere"><?= quizEscape($quiz['description']) ?></p>
    <p class="small text-muted"><?= quizEscape(learningMaterialAudienceLabel($quiz)) ?></p>
    <?php if ($manage): ?>
        <a class="btn btn-success btn-sm" href="quiz.php?id=<?= (int)$quiz['quiz_id'] ?>&amp;mode=edit">Edit Quiz</a>
        <a class="btn btn-outline-secondary btn-sm" href="quiz.php?id=<?= (int)$quiz['quiz_id'] ?>&amp;mode=preview">Preview</a>
        <a class="btn btn-outline-success btn-sm" href="quiz.php?id=<?= (int)$quiz['quiz_id'] ?>&amp;mode=responses">Responses</a>
    <?php elseif ($quiz['response_state']==='submitted'): ?>
        <a class="btn btn-outline-success btn-sm" href="quiz.php?id=<?= (int)$quiz['quiz_id'] ?>&amp;mode=result&amp;response_id=<?= (int)$quiz['response_id'] ?>">View My Response</a>
    <?php else: ?>
        <a class="btn btn-success btn-sm" href="quiz.php?id=<?= (int)$quiz['quiz_id'] ?>"><?= $materialActor['role']==='student'?($quiz['response_state']==='draft'?'Continue Quiz':'Open Quiz'):'Preview Quiz' ?></a>
    <?php endif; ?>
</article>
<?php endforeach; ?>
<?php if ($quizCount>20): ?><nav aria-label="Assessment pages" class="d-flex justify-content-between"><span>Page <?= $quizPage ?> of <?= (int)ceil($quizCount/20) ?></span><div><?php if($quizPage>1): ?><a class="btn btn-sm btn-outline-secondary" href="?tab=assessment&amp;quiz_page=<?= $quizPage-1 ?>">Previous</a><?php endif; ?> <?php if($quizPage*20<$quizCount): ?><a class="btn btn-sm btn-outline-secondary" href="?tab=assessment&amp;quiz_page=<?= $quizPage+1 ?>">Next</a><?php endif; ?></div></nav><?php endif; ?>
<?php } catch(Throwable $error) { error_log('Quiz list: '.$error->getMessage()); ?><div class="alert alert-warning">Assessments are temporarily unavailable. Please try again later.</div><?php } ?>
