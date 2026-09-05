<?php
require_once __DIR__ . '/../include/quizzes.php';
function checkQuiz($condition, $name) { if (!$condition) throw new RuntimeException('FAIL: '.$name); echo "PASS: {$name}\n"; }
function invalidQuiz(callable $call, $name) { try {$call();} catch(InvalidArgumentException $e) {checkQuiz(true,$name);return;} throw new RuntimeException('FAIL: '.$name); }
function questionFixture($id,$type,$more=[]) { return array_merge(['id'=>$id,'type'=>$type,'title'=>$id,'help'=>'','points'=>2,'required'=>true,'options'=>['A','B'],'answer'=>['B'],'rows'=>['Row 1'],'min'=>1,'max'=>5],$more); }
function definitionFixture($questions) { return ['title'=>'Quiz','description'=>'','components'=>['CWTS','ROTC'],'levels'=>['MS-31'],'questions'=>$questions]; }
$d=quizDefinition(definitionFixture([
    questionFixture('mc','multiple_choice'),questionFixture('cb','checkboxes',['answer'=>['A','B']]),
    questionFixture('short','short_answer',['answer'=>['New York']]),questionFixture('essay','paragraph',['required'=>false]),
]));
$g=quizGrade($d,['mc'=>'B','cb'=>['B','A'],'short'=>' new   YORK ','essay'=>'Explain the topic.']);
checkQuiz($g['score']===6&&$g['total']===8&&$g['needs_review'],'auto scoring and manual review');
invalidQuiz(fn()=>quizGrade($d,['mc'=>' ','cb'=>['A'],'short'=>'x']),'whitespace cannot bypass required');
invalidQuiz(fn()=>quizGrade($d,['mc'=>'C','cb'=>['A'],'short'=>'x']),'unknown option denied');
$g=quizGrade($d,['mc'=>'B','cb'=>['A'],'short'=>'New York']);checkQuiz($g['score']===4&&!$g['needs_review'],'checkbox exact match and skipped optional essay');
$public=quizPublicDefinition($d);checkQuiz(!isset($public['questions'][0]['answer'],$public['questions'][0]['feedback']),'public definition hides keys and feedback');
$branch=quizDefinition(definitionFixture([
    questionFixture('route','multiple_choice',['next'=>['B'=>'last']]),questionFixture('middle','section'),
    questionFixture('skip','short_answer'),questionFixture('last','section'),questionFixture('finish','dropdown')
]));
$g=quizGrade($branch,['route'=>'B','finish'=>'B','skip'=>'tampered']);checkQuiz($g['score']===4&&!isset($g['answers']['skip']),'server routing skips hidden required questions and ignores hidden answers');
invalidQuiz(fn()=>quizDefinition(definitionFixture([questionFixture('bad','multiple_choice',['next'=>['A'=>'start']])])),'backward routing denied');
invalidQuiz(fn()=>quizDefinition(definitionFixture([questionFixture('a','multiple_choice',['next'=>['A'=>'submit']]),questionFixture('b','paragraph')])),'branch must be last in section');
foreach(['multiple_grid','checkbox_grid'] as $type){$grid=quizDefinition(definitionFixture([questionFixture('grid',$type,['answer'=>[]])]));$answer=$type==='checkbox_grid'?['Row 1'=>['A']]:['Row 1'=>'A'];checkQuiz(quizGrade($grid,['grid'=>$answer])['needs_review'],'manual grading '.$type);invalidQuiz(fn()=>quizGrade($grid,['grid'=>[]]),'required '.$type);}
foreach(['scale'=>'3','rating'=>'5','date'=>'2026-09-04','time'=>'09:30'] as $type=>$answer){$q=quizDefinition(definitionFixture([questionFixture('q',$type,['answer'=>[$answer]])]));checkQuiz(quizGrade($q,['q'=>$answer])['score']===2,'grade '.$type);}
$email=quizDefinition(definitionFixture([questionFixture('email','short_answer',['answer'=>[],'validation'=>'email'])]));invalidQuiz(fn()=>quizGrade($email,['email'=>'wrong']),'email validation');
invalidQuiz(fn()=>quizDefinition(['title'=>'Quiz','components'=>['ROTC'],'levels'=>[],'questions'=>[questionFixture('a','multiple_choice')]]),'ROTC level required');
invalidQuiz(fn()=>quizDefinition(definitionFixture([questionFixture('a','multiple_choice',['options'=>['A','A']])])),'duplicate options denied');
invalidQuiz(fn()=>quizMediaUrl('javascript:alert(1)'),'unsafe image URL denied');
checkQuiz(quizMediaUrl('https://youtu.be/abcdefghijk',true)==='https://www.youtube-nocookie.com/embed/abcdefghijk','safe YouTube embed');
checkQuiz(quizCanManage(['role'=>'coordinator','user_id'=>1],['uploaded_by'=>1])&&!quizCanManage(['role'=>'coordinator','user_id'=>2],['uploaded_by'=>1]),'coordinator ownership');
$timed=quizDefinition(array_merge(definitionFixture([questionFixture('timer','multiple_choice')]),['time_limit_minutes'=>15]));
checkQuiz($timed['time_limit_minutes']===15,'quiz time limit is normalized');
invalidQuiz(fn()=>quizDefinition(array_merge(definitionFixture([questionFixture('timer','multiple_choice')]),['time_limit_minutes'=>10081])),'quiz time limit maximum enforced');
$timing=quizResponseTiming(['started_at'=>date('Y-m-d H:i:s',time()-901)],$timed);
checkQuiz($timing['expired']&&$timing['remaining_seconds']===0,'server detects expired quiz response');
$multiDestination=quizDefinition(array_merge(definitionFixture([questionFixture('multi','multiple_choice')]),['grade_column_ids'=>[11,12,11]]));
checkQuiz($multiDestination['grade_column_ids']===[11,12]&&$multiDestination['grade_column_id']===11,'multiple grade destinations are normalized');
