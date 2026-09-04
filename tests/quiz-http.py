"""Isolated PHP HTTP integration checks. Uses SQLite fixtures, never the live DB.
Run: python tests/quiz-http.py. --keep-server leaves a successful fixture for UI QA.
"""
import argparse
import json
import pathlib
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
for name in ['quiz.php', 'auth_check.php', 'include/quizzes.php', 'include/quiz-grades.php', 'include/learning-materials.php', 'include/user-permissions.php', 'include/theme-loader.php', 'include/theme.css', 'include/quizzes.css', 'include/quiz-renderer.js', 'include/quiz-builder.js', 'include/quiz-player.js', 'include/quiz-app.js', 'endpoint/quiz.php']:
    shutil.copyfile(repo / name, root / name)
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
CREATE TABLE tbl_quiz_grade_links(quiz_id INTEGER PRIMARY KEY,grade_column_id INTEGER);
CREATE TABLE tbl_quiz_files(file_id INTEGER PRIMARY KEY AUTOINCREMENT,response_id INTEGER,question_id TEXT,original_name TEXT,storage_name TEXT,file_size INTEGER);
''')
db.commit()
(root / 'router.php').write_text('''<?php
// Test-only role fixture. It is outside the application and is never deployed.
$user=(int)($_SERVER['HTTP_X_TEST_USER']??1);
session_id('quiz-fixture-'.$user);session_start();
if($user){$_SESSION['user_id']=$user;$_SESSION['last_activity']=time();$_SESSION['quiz_csrf']='test-token';}else $_SESSION=[];
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
    print(json.dumps({'root': str(root), 'port': port, 'pid': process.pid, 'builder': f'http://127.0.0.1:{port}/quiz.php?id={copy["id"]}&mode=edit'}), flush=True)
    success = True
finally:
    db.close()
    log.close()
    if not (success and keep):
        process.terminate()
        process.wait(timeout=10)
        shutil.rmtree(root)
