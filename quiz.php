<?php
require_once __DIR__.'/auth_check.php';
require_once __DIR__.'/conn/conn.php';
require_once __DIR__.'/include/quizzes.php';
if (!learningMaterialSessionActive($conn)) { header('Location: endpoint/logout.php?reason=timeout'); exit; }
$actor=getCurrentUserRecord($conn);
if (!$actor) { header('Location: endpoint/logout.php'); exit; }
$mode=$_GET['mode']??'take';if(!in_array($mode,['edit','take','preview','responses','result'],true))$mode='take';
if($mode==='edit'&&!canUploadLearningMaterials($actor)){http_response_code(403);exit('Only administrators and coordinators can create quizzes.');}
if(empty($_SESSION['quiz_csrf']))$_SESSION['quiz_csrf']=bin2hex(random_bytes(32));
$boot=['id'=>max(0,(int)($_GET['id']??0)),'responseId'=>max(0,(int)($_GET['response_id']??0)),'mode'=>$mode,'role'=>$actor['role'],'csrf'=>$_SESSION['quiz_csrf'],'fileLimit'=>learningMaterialChunkLimit()];
?>
<!DOCTYPE html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Assessments - TAU NSTP</title>
<?php include __DIR__.'/include/theme-loader.php'; ?>
<link rel="icon" href="include/logo.png"><link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,600,700&display=fallback">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css"><link rel="stylesheet" href="include/theme.css"><link rel="stylesheet" href="include/quizzes.css?v=<?= (int) filemtime(__DIR__.'/include/quizzes.css') ?>">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head><body class="hold-transition sidebar-mini layout-fixed"><div class="wrapper">
<nav class="main-header navbar navbar-expand navbar-white navbar-light"><ul class="navbar-nav"><li class="nav-item"><a class="nav-link" data-widget="pushmenu" href="#" role="button" aria-label="Toggle sidebar"><i class="fas fa-bars" aria-hidden="true"></i></a></li><li class="nav-item"><a class="nav-link" href="learning-management.php?tab=assessment">Assessments</a></li></ul><ul class="navbar-nav ml-auto"><?php include __DIR__.'/include/theme-toggle.php'; ?></ul></nav>
<?php include __DIR__.'/adminlte-sidebar.php'; ?>
<main class="content-wrapper"><section class="content pt-4 pb-5"><div class="quiz-workspace">
<div id="quiz-message" role="status" aria-live="polite"></div><div id="quiz-app"><div class="card p-4">Loading quiz...</div></div>
<noscript><div class="alert alert-warning">Enable JavaScript to create or answer quizzes.</div></noscript>
</div></section></main><?php include __DIR__.'/footer.php'; ?></div>
<script>window.quizBoot=<?= json_encode($boot,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script><script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="include/quiz-renderer.js?v=<?= (int) filemtime(__DIR__.'/include/quiz-renderer.js') ?>"></script><script src="include/quiz-builder.js?v=<?= (int) filemtime(__DIR__.'/include/quiz-builder.js') ?>"></script><script src="include/quiz-focus.js?v=<?= (int) filemtime(__DIR__.'/include/quiz-focus.js') ?>"></script><script src="include/quiz-player.js?v=<?= (int) filemtime(__DIR__.'/include/quiz-player.js') ?>"></script><script src="include/quiz-app.js?v=<?= (int) filemtime(__DIR__.'/include/quiz-app.js') ?>"></script>
</body></html>
