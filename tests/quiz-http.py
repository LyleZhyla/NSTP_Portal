"""Isolated PHP HTTP integration checks. Uses SQLite fixtures, never the live DB.
Run: python tests/quiz-http.py. --keep-server leaves a successful fixture for UI QA.
"""
import argparse
import json
import pathlib
import re
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

keep = argparse.ArgumentParser()
keep.add_argument('--keep-server', action='store_true')
keep = keep.parse_args().keep_server
repo = pathlib.Path(__file__).resolve().parents[1]
root = pathlib.Path(tempfile.mkdtemp(prefix='nstp-quiz-test-')).resolve()
assert root.parent == pathlib.Path(tempfile.gettempdir()).resolve()
for name in ['conn', 'include', 'endpoint', 'sessions', 'storage/learning-materials']:
    (root / name).mkdir(parents=True, exist_ok=True)
for name in ['learning-management.php', 'quiz.php', 'auth_check.php', 'include/quizzes.php', 'include/quiz-grades.php', 'include/learning-materials.php', 'include/user-permissions.php', 'include/theme-loader.php', 'include/theme.css', 'include/quizzes.css', 'include/quiz-renderer.js', 'include/quiz-builder.js', 'include/quiz-player.js', 'include/quiz-focus.js', 'include/quiz-app.js', 'endpoint/quiz.php', 'endpoint/upload-learning-material.php', 'endpoint/download-learning-material.php']:
    shutil.copyfile(repo / name, root / name)
# Stub only MySQL schema migration in this SQLite fixture.
material_helper = root / 'include/learning-materials.php'
material_helper.write_text(re.sub(r'function ensureLearningMaterialsTable\(PDO \$conn\) \{.*?\n\}\n', 'function ensureLearningMaterialsTable(PDO $conn) {}\n', material_helper.read_text(), count=1, flags=re.S))
(root / 'include/grade-schema.php').write_text('<?php function ensureGradeTables(PDO $conn) {} function seedDefaultGradeColumns(PDO $conn) {}')
(root / 'adminlte-sidebar.php').write_text('<aside class="main-sidebar sidebar-dark-primary"><a class="brand-link" href="/quiz.php?mode=edit">TAU NSTP (test)</a><div class="sidebar"><a class="nav-link text-white" href="/quiz.php?mode=edit">Learning Management</a></div></aside>')
for name in ['footer.php', 'include/theme-toggle.php']:
    (root / name).write_text('<?php // Isolated layout stub')
(root / 'conn/conn.php').write_text('''<?php
class QuizTestPDO extends PDO {
 public function exec(string $sql): int|false {
  if(str_contains($sql,'CREATE TABLE IF NOT EXISTS tbl_quiz')) return 0;
  return parent::exec($sql);
 }
 public function prepare(string $sql,array $options=[]): PDOStatement|false {
  return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options);
 }
}
$conn=new QuizTestPDO('sqlite:'.__DIR__.'/../test.sqlite');
$conn->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$conn->sqliteCreateFunction('FIND_IN_SET',function($value,$list){if($list===null)return null;$i=array_search($value,explode(',',$list),true);return $i===false?0:$i+1;},2);
''')
db = sqlite3.connect(root / 'test.sqlite')
db.executescript('''
CREATE TABLE tbl_users(user_id INTEGER PRIMARY KEY,role TEXT,program TEXT,full_name TEXT,username TEXT);
INSERT INTO tbl_users VALUES (1,'super_admin',NULL,'Administrator','admin'),(2,'coordinator','CWTS','Coordinator','coordinator'),(3,'facilitator','CWTS','Facilitator','facilitator'),(4,'student','CWTS','=2+2','student4'),(5,'student','LTS','LTS Student','student5'),(6,'coordinator','ROTC','ROTC Coordinator','coord6'),(7,'student','ROTC','ROTC Student','student7');
CREATE TABLE tbl_student(tbl_student_id INTEGER PRIMARY KEY,user_id INTEGER,created_by INTEGER,student_number TEXT,course_section TEXT,original_section TEXT);
CREATE TABLE tbl_public_student_registrations(registration_id INTEGER PRIMARY KEY,user_id INTEGER,student_number TEXT,component TEXT,rotc_ms_level TEXT);
CREATE TABLE tbl_student_rotc_levels(tbl_student_id INTEGER PRIMARY KEY,rotc_ms_level TEXT);
INSERT INTO tbl_student VALUES(4,4,1,'4','CWTS','CWTS'),(5,5,1,'5','LTS','LTS'),(7,7,1,'7','ROTC','ROTC');
INSERT INTO tbl_public_student_registrations VALUES(4,4,'4','CWTS',NULL),(5,5,'5','LTS',NULL),(7,7,'7','ROTC','MS-31');
CREATE TABLE tbl_quizzes(quiz_id INTEGER PRIMARY KEY AUTOINCREMENT,uploaded_by INTEGER,title TEXT,description TEXT,audience_components TEXT,audience_rotc_levels TEXT,definition_json TEXT,status TEXT DEFAULT 'draft',revision INTEGER DEFAULT 1,created_at TEXT DEFAULT CURRENT_TIMESTAMP,updated_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE tbl_quiz_responses(response_id INTEGER PRIMARY KEY AUTOINCREMENT,quiz_id INTEGER,user_id INTEGER,answers_json TEXT,grades_json TEXT,state TEXT DEFAULT 'draft',score NUMERIC DEFAULT 0,total_points NUMERIC DEFAULT 0,needs_review INTEGER DEFAULT 0,released INTEGER DEFAULT 0,started_at TEXT DEFAULT CURRENT_TIMESTAMP,submitted_at TEXT,updated_at TEXT DEFAULT CURRENT_TIMESTAMP,UNIQUE(quiz_id,user_id));
CREATE TABLE tbl_grade_columns(grade_column_id INTEGER PRIMARY KEY,label TEXT,group_label TEXT,max_score NUMERIC,program_scope TEXT,is_default INTEGER DEFAULT 0,created_by INTEGER,is_active INTEGER DEFAULT 1,sort_order INTEGER DEFAULT 0);
INSERT INTO tbl_grade_columns(grade_column_id,label,group_label,max_score,program_scope,is_default,created_by) VALUES(1,'Quiz total','Written work',50,NULL,1,NULL),(2,'CWTS test','Written work',100,'CWTS',0,2),(3,'ROTC test','Written work',100,'ROTC',0,6);
CREATE TABLE tbl_grade_scores(grade_score_id INTEGER PRIMARY KEY AUTOINCREMENT,grade_column_id INTEGER,tbl_student_id INTEGER,score NUMERIC,updated_by INTEGER,UNIQUE(grade_column_id,tbl_student_id));
CREATE TABLE tbl_learning_materials(material_id INTEGER PRIMARY KEY,title TEXT,description TEXT,original_name TEXT,file_size INTEGER,file_content BLOB,storage_name TEXT,uploaded_by INTEGER,audience_components TEXT,audience_rotc_levels TEXT,is_open INTEGER DEFAULT 1);
INSERT INTO tbl_learning_materials VALUES(1,'Video','','lesson.mp4',4,X'74657374',NULL,2,'CWTS','',1),(2,'Legacy','','lesson.txt',4,X'74657374',NULL,1,NULL,NULL,1);
CREATE TABLE tbl_quiz_grade_links(quiz_id INTEGER PRIMARY KEY,grade_column_id INTEGER);
CREATE TABLE tbl_quiz_focus_events(response_id INTEGER,event_id TEXT,created_at TEXT DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(response_id,event_id));
CREATE TABLE tbl_quiz_files(file_id INTEGER PRIMARY KEY AUTOINCREMENT,response_id INTEGER,question_id TEXT,original_name TEXT,storage_name TEXT,file_size INTEGER);
''')
db.commit()
(root / 'router.php').write_text('''<?php
// Test-only role fixture. It is outside the application and is never deployed.
$user=(int)($_SERVER['HTTP_X_TEST_USER']??1);
session_id('quiz-fixture-'.$user);session_start();
if($user){$_SESSION['user_id']=$user;$_SESSION['last_activity']=time();$_SESSION['quiz_csrf']='test-token';$_SESSION['learning_material_csrf']='test-token';}else $_SESSION=[];
session_write_close();
if($_SERVER['REQUEST_URI']==='/health'){echo 'ok';return true;}
if($_SERVER['REQUEST_URI']==='/endpoint/touch-session.php'){echo '{}';return true;}
return false;
''')
sock = socket.socket()
sock.bind(('127.0.0.1', 0))
port = sock.getsockname()[1]
sock.close()
log = open(root / 'server.log', 'w')
process = subprocess.Popen(['php', '-d', 'session.save_path=' + str(root / 'sessions'), '-S', '127.0.0.1:' + str(port), '-t', str(root), str(root / 'router.php')], stdout=log, stderr=log, creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))


def request(path, user=1, data=None, headers=None):
    h = {'X-Test-User': str(user)}
    h.update(headers or {})
    try:
        response = urllib.request.urlopen(urllib.request.Request(f'http://127.0.0.1:{port}' + path, data=data, headers=h), timeout=15)
    except urllib.error.HTTPError as error:
        response = error
    return response.code, response.headers, response.read()


def api(action, user=1, **values):
    values.update(action=action, csrf_token=values.pop('csrf', 'test-token'))
    status, headers, body = request('/endpoint/quiz.php', user, json.dumps(values).encode(), {'Content-Type': 'application/json'})
    try:
        return status, json.loads(body)
    except ValueError:
        raise AssertionError(body[:1200])


def get(action, user=1, **values):
    status, headers, body = request('/endpoint/quiz.php?' + urllib.parse.urlencode(dict(action=action, **values)), user)
    return status, json.loads(body)


def check(value, label):
    assert value, label
    print('PASS ' + label, flush=True)


def q(qid, kind='multiple_choice', **values):
    return dict(id=qid, type=kind, title=qid, help='', required=True, points=2, options=['A', 'B'], answer=['B'], **values)


def definition(questions=None, **values):
    return dict(title='NSTP Knowledge Check', description='Read each question carefully.', components=['CWTS', 'ROTC'], levels=['MS-31'], questions=questions or [q('choice'), q('short', 'short_answer'), q('essay', 'paragraph')], **values)


success = False
try:
    for _ in range(40):
        try:
            request('/health')
            break
        except OSError:
            time.sleep(.1)
    check(api('save', 0, definition=definition())[0] == 401, 'anonymous denied')
    check(api('save', 3, definition=definition())[0] == 403 and api('save', 4, definition=definition())[0] == 403, 'only admin/coordinator author quizzes')
    check(api('save', csrf='wrong', definition=definition())[0] == 403, 'CSRF enforced')
    status, saved = api('save', definition=definition())
    check(status == 200, 'admin creates draft')
    quiz_id = saved['id']
    status, co = api('save', 2, definition=definition())
    check(status == 200, 'coordinator creates draft')
    check(api('save', 6, id=co['id'], revision=1, definition=definition())[0] == 403, 'coordinator cannot edit another owner')
    check(get('load', 4, id=quiz_id)[0] == 403, 'students cannot load drafts')
    check(api('status', id=quiz_id, status='published')[0] == 200, 'publish quiz')
    status, loaded = get('load', 4, id=quiz_id)
    check(status == 200 and 'answer' not in loaded['definition']['questions'][0] and 'feedback' not in loaded['definition']['questions'][0], 'learner payload hides answer key')
    check(get('load', 4, id=quiz_id, mode='edit')[0] == 403, 'learner cannot request edit payload')
    check(get('load', 5, id=quiz_id)[0] == 403, 'component audience enforced')
    check(get('load', 7, id=quiz_id)[0] == 200, 'ROTC selected MS level allowed')
    check(api('start', 3, id=quiz_id)[0] == 403, 'facilitator preview cannot submit')
    check(api('start', 4, id=quiz_id)[0] == 200, 'student starts one draft')
    check(api('save', id=quiz_id, revision=2, definition=definition())[0] == 403, 'questions lock after a response starts')
    answers = {'choice': 'B', 'short': 'b', 'essay': 'My explanation.'}
    check(api('draft', 4, id=quiz_id, answers={'short': 'partial'})[0] == 200, 'incomplete draft autosave')
    check(api('submit', 4, id=quiz_id, answers={'choice': 'B'})[0] == 400, 'required questions enforced')
    status, submitted = api('submit', 4, id=quiz_id, answers=answers, score=999)
    check(status == 200, 'student submits')
    rid = submitted['response_id']
    row = db.execute('SELECT score,total_points,needs_review,released FROM tbl_quiz_responses WHERE response_id=?', (rid,)).fetchone()
    check(row == (4, 6, 1, 0), 'server grading ignores forged score and holds manual questions')
    check(api('submit', 4, id=quiz_id, answers=answers)[1]['response_id'] == rid, 'duplicate submission is idempotent')
    status, own = get('response', 4, response_id=rid)
    check(status == 200 and own['grades'] is None and 'answer' not in own['definition']['questions'][0], 'unreleased grades/key remain private')
    check(get('response', 7, response_id=rid)[0] == 403 and get('responses', 2, id=quiz_id)[0] == 403, 'responses private to owner and manager')
    detail = get('response', response_id=rid)[1]
    grades = {key: {'points': 2, 'feedback': 'Reviewed.'} for key in detail['grades']}
    check(api('grade', 4, response_id=rid, grades=grades)[0] == 403, 'student cannot grade')
    check(api('grade', response_id=rid, answers_version='stale', grades=grades)[0] == 403, 'stale grading rejected')
    check(api('grade', response_id=rid, answers_version=detail['answers_version'], grades=grades, release=True)[0] == 200, 'manual grade and release')
    own = get('response', 4, response_id=rid)[1]
    check(float(own['score']) == 6 and own['grades'] and own['released'], 'student sees released score')
    status, headers, body = request('/endpoint/quiz.php?action=export&id=' + str(quiz_id))
    check(status == 200 and b"'=2+2" in body and b'choice' in body, 'CSV export and formula escaping')
    check(api('status', id=quiz_id, status='closed')[0] == 200 and api('start', 7, id=quiz_id)[0] == 403, 'closed quiz rejects new responses')
    check(get('load', 4, id=quiz_id)[1]['accepting'] is False, 'closed quiz retains student response')
    check(api('duplicate', 2, id=quiz_id)[0] == 403, 'duplicate requires management access')
    status, copy = api('duplicate', id=quiz_id)
    check(status == 200 and get('load', id=copy['id'], mode='edit')[1]['locked'] is False, 'duplicate creates unlocked draft')
    status, auto = api('save', definition=definition([q('auto')], release_immediately=True))
    api('status', id=auto['id'], status='published')
    status, answer = api('submit', 4, id=auto['id'], answers={'auto': 'B'})
    check(status == 200 and get('response', 4, response_id=answer['response_id'])[1]['released'], 'immediate auto-grade release')
    status, files = api('save', definition=definition([q('upload', 'file')]))
    api('status', id=files['id'], status='published')
    body = b''
    for key, value in dict(action='upload_file', csrf_token='test-token', id=str(files['id']), question_id='upload').items():
        body += ('--BOUND\r\nContent-Disposition: form-data; name="' + key + '"\r\n\r\n' + value + '\r\n').encode()
    body += b'--BOUND\r\nContent-Disposition: form-data; name="file"; filename="response.txt"\r\nContent-Type: text/plain\r\n\r\nMy response file.\r\n--BOUND--\r\n'
    status, headers, body = request('/endpoint/quiz.php', 4, body, {'Content-Type': 'multipart/form-data; boundary=BOUND'})
    check(status == 200, 'file answer uploads')
    file_id = json.loads(body)['file_id']
    check(api('submit', 7, id=files['id'], answers={'upload': {'file_id': file_id}})[0] == 400, 'another student cannot claim an attachment')
    status, answer = api('submit', 4, id=files['id'], answers={'upload': {'file_id': file_id}})
    check(status == 200, 'file answer submits for owner')
    url = '/endpoint/quiz.php?' + urllib.parse.urlencode(dict(action='attachment', response_id=answer['response_id'], file_id=file_id))
    check(request(url, 4)[2] == b'My response file.' and request(url, 7)[0] == 403, 'attachment downloads enforce response ownership')
    current = get('load', id=quiz_id, mode='edit')[1]
    rev = current['revision']
    check(api('audience', 2, id=quiz_id, revision=rev, components=['LTS'], levels=[])[0] == 403 and api('audience', 4, id=quiz_id, revision=rev, components=['LTS'], levels=[])[0] == 403, 'only admin can change locked audience')
    check(api('audience', id=quiz_id, revision=rev, components=[], levels=[])[0] == 400, 'audience requires a component')
    check(api('audience', id=quiz_id, revision=rev, components=['ROTC'], levels=[])[0] == 400, 'ROTC audience requires MS level')
    check(api('audience', id=quiz_id, revision=rev-1, components=['LTS'], levels=[])[0] == 403, 'stale audience update rejected')
    status, updated = api('audience', id=quiz_id, revision=rev, components=['LTS'], levels=['MS-31'])
    check(status == 200 and updated['revision'] == rev+1 and updated['levels'] == [], 'admin updates audience after responses exist')
    changed = get('load', id=quiz_id, mode='edit')[1]
    row = db.execute('SELECT audience_components,audience_rotc_levels FROM tbl_quizzes WHERE quiz_id=?', (quiz_id,)).fetchone()
    check(changed['definition']['questions'] == current['definition']['questions'] and changed['definition']['components'] == ['LTS'] and row == ('LTS', ''), 'audience columns and definition stay synchronized without question changes')
    api('status', id=quiz_id, status='published')
    check(get('load', 5, id=quiz_id)[0] == 200 and get('load', 7, id=quiz_id)[0] == 403, 'new audience gains access and excluded audience loses access')
    check(get('load', 4, id=quiz_id)[1]['accepting'] is False and get('response', 4, response_id=rid)[1]['released'], 'excluded respondent retains grades but cannot keep answering')
    check(get('grade_columns', 4)[0] == 403, 'students cannot list grade destinations')
    choices = get('grade_columns', 2)[1]['columns']
    check({c['grade_column_id'] for c in choices} == {1, 2}, 'coordinator destinations respect column ownership and program')
    check(api('save', 2, definition=definition(grade_column_id=3))[0] == 400, 'forged inaccessible destination rejected')
    check(api('save', definition=definition(grade_column_id=2))[0] == 400, 'component-specific destination rejects mismatched quiz audience')
    status, linked = api('save', 2, definition=definition([q('scored')], grade_column_id=1, release_immediately=True, allow_edit=True))
    check(status == 200, 'coordinator saves grading-sheet destination')
    api('status', 2, id=linked['id'], status='published')
    status, linked_answer = api('submit', 4, id=linked['id'], answers={'scored': 'B'})
    def sheet_score():
        return db.execute('SELECT score FROM tbl_grade_scores WHERE grade_column_id=1 AND tbl_student_id=4').fetchone()
    check(status == 200 and sheet_score() == (50,), 'released quiz score scales to grading column maximum')
    api('submit', 4, id=linked['id'], answers={'scored': 'A'})
    check(sheet_score() == (0,), 'edited submission updates existing grading cell without duplication')
    status, second = api('save', definition=definition([q('second')], grade_column_id=1, release_immediately=True))
    api('status', id=second['id'], status='published')
    api('submit', 4, id=second['id'], answers={'second': 'B'})
    check(sheet_score() == (25,), 'multiple quizzes aggregate earned and possible points in one column')
    status, manual = api('save', definition=definition([q('essay', 'paragraph')], grade_column_id=1))
    api('status', id=manual['id'], status='published')
    status, manual_answer = api('submit', 4, id=manual['id'], answers={'essay': 'A considered response'})
    check(status == 200 and sheet_score() == (25,), 'pending manual grades do not contribute')
    detail = get('response', response_id=manual_answer['response_id'])[1]
    api('grade', response_id=manual_answer['response_id'], answers_version=detail['answers_version'], grades={'essay': {'points': 2}}, release=False)
    check(sheet_score() == (25,), 'held grades do not contribute')
    api('grade', response_id=manual_answer['response_id'], answers_version=detail['answers_version'], grades={'essay': {'points': 2}}, release=True)
    check(sheet_score() == (33.33,), 'manual release synchronizes aggregate score')
    api('grade', response_id=manual_answer['response_id'], answers_version=detail['answers_version'], grades={'essay': {'points': 2}}, release=False)
    check(sheet_score() == (25,), 'withdrawing release removes that quiz contribution')
    dup = api('duplicate', id=manual['id'])[1]
    check(get('load', id=dup['id'], mode='edit')[1]['definition']['grade_column_id'] == 0, 'duplicate does not silently reuse grading destination')
    current = get('load', id=linked['id'], mode='edit')[1]
    check(api('save', 2, id=linked['id'], revision=current['revision'], definition=definition(grade_column_id=0))[0] == 403, 'destination locks once responses exist')
    def availability(user, value, material=1, csrf='test-token'):
        return request('/learning-management.php?tab=learning-materials', user, urllib.parse.urlencode(dict(action='set_availability', material_id=material, is_open=value, csrf_token=csrf)).encode(), {'Content-Type': 'application/x-www-form-urlencoded'})[0]
    video_url = '/endpoint/download-learning-material.php?id=1&play=1'
    check(request(video_url, 4)[0] == 200, 'open video accessible to eligible student')
    check(availability(4, 0) == 403 and availability(3, 0) == 403, 'student and facilitator cannot change availability')
    check(availability(6, 0) == 403, 'coordinator cannot close another owner material')
    check(availability(2, 0, csrf='wrong') == 403 and availability(2, 'invalid') == 400, 'availability validates CSRF and value')
    check(availability(2, 0) == 200, 'owner coordinator can close material')
    check(request(video_url, 4)[0] == 404 and request('/endpoint/download-learning-material.php?id=1', 4)[0] == 404, 'closed material rejects student playback and direct download')
    check(request(video_url, 4, headers={'Range': 'bytes=0-1'})[0] == 404, 'closed video rejects seeking range requests')
    check(request(video_url, 2)[0] == 200 and request(video_url, 1)[0] == 200, 'admin and owner retain closed material access')
    check(availability(1, 1) == 200 and request(video_url, 4)[0] == 200, 'admin can reopen coordinator material')
    check(request(video_url, 5)[0] == 404, 'reopening preserves component restrictions')
    check(availability(1, 0, material=2) == 200 and request('/endpoint/download-learning-material.php?id=2', 4)[0] == 404, 'closed legacy materials cannot bypass student access check')
    monitored = api('save', definition=definition(monitor_focus=True, allow_edit=True))[1]
    api('status', id=monitored['id'], status='published')
    started = api('start', 4, id=monitored['id'])[1]
    def focus(event, **values):
        return api('focus_event', 4, id=monitored['id'], event_id=event, **values)
    check(api('focus_event', 3, id=monitored['id'], event_id='a'*32)[0] == 403, 'staff cannot log student focus violations')
    check(api('focus_event', 4, id=files['id'], event_id='a'*32)[0] == 403, 'focus events rejected when monitoring disabled')
    check(focus('a'*32, answers={'choice':'B'})[1]['violations'] == 1, 'first focus departure recorded')
    check(focus('a'*32, answers={})[1]['violations'] == 1, 'retried event does not double count')
    check(focus('b'*32, answers={})[1]['violations'] == 2, 'second focus departure recorded')
    resumed = get('load',4,id=monitored['id'])[1]['response']
    check(resumed['violations'] == 2 and len(resumed['focus_events']) == 2, 'refresh retains violation history')
    status, forced = focus('c'*32, answers={'choice':'B','short':['invalid partial']})
    check(status == 200 and forced['forced'] and forced['violations'] == 3, 'third departure auto-submits incomplete answers')
    result = get('response', response_id=forced['response_id'])[1]
    check(result['state'] == 'submitted' and float(result['score']) == 2 and result['violations'] == 3, 'forced submission keeps valid answers and scores blanks zero')
    check(focus('c'*32, answers={})[1]['response_id'] == forced['response_id'], 'forced submission retry is idempotent')
    check(api('draft',4,id=monitored['id'],answers={})[0] == 403 and api('start',4,id=monitored['id'])[0] == 403, 'forced response cannot reopen despite allow edit')
    check(get('responses',id=monitored['id'])[1]['responses'][0]['violations'] == 3, 'manager sees focus count in response list')
    stored_name = 'a' * 64 + '.php'
    stored_path = root / 'storage/learning-materials' / stored_name
    stored_path.write_bytes(b'<?php http_response_code(404); exit; __halt_compiler();\nvideo')
    db.execute("INSERT INTO tbl_learning_materials VALUES(3,'Stored video','','stored.mp4',5,X'','%s',2,'CWTS','',1)" % stored_name)
    db.commit()
    def delete_material(user, material, csrf='test-token'):
        body = urllib.parse.urlencode(dict(action='delete_material', material_id=material, csrf_token=csrf)).encode()
        return request('/learning-management.php?tab=learning-materials', user, body, {'Content-Type':'application/x-www-form-urlencoded'})
    check(delete_material(4, 3)[0] == 403 and delete_material(3, 3)[0] == 403, 'students and facilitators cannot delete materials')
    check(delete_material(6, 3)[0] == 403, 'coordinator cannot delete another uploader material')
    check(delete_material(2, 3, 'wrong')[0] == 403, 'material deletion enforces CSRF')
    check(delete_material(2, 3)[0] == 200 and not stored_path.exists(), 'owner coordinator deletes database record and protected stored file')
    check(db.execute('SELECT COUNT(*) FROM tbl_learning_materials WHERE material_id=3').fetchone()[0] == 0 and request('/endpoint/download-learning-material.php?id=3', 2)[0] == 404, 'deleted material is no longer downloadable')
    check(delete_material(2, 2)[0] == 403 and delete_material(1, 2)[0] == 200, 'admin can delete any material while coordinator remains owner-limited')
    check(db.execute('SELECT COUNT(*) FROM tbl_learning_materials WHERE material_id=2').fetchone()[0] == 0, 'legacy database-backed material deletes without a storage file')
    print(json.dumps({'root': str(root), 'port': port, 'pid': process.pid, 'builder': f'http://127.0.0.1:{port}/quiz.php?id={copy["id"]}&mode=edit'}), flush=True)
    success = True
finally:
    db.close()
    log.close()
    if not (success and keep):
        process.terminate()
        process.wait(timeout=10)
        shutil.rmtree(root)
