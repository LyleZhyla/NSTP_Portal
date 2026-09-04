# Quiz builder and assessments

Open **Learning Management → Assessment → Create Quiz** as a super admin or
coordinator. Save the draft, use Preview, and Publish when ready. The app creates
the quiz tables automatically using the configured database connection.

## Included

- Multiple choice, checkboxes, dropdown, short answer, paragraph, linear scale,
  rating, date, time, multiple-choice grid, checkbox grid, and file response.
- Required questions, per-question instructions and points, answer keys, response
  validation (email/number/URL), answer feedback, and manual grading.
- Sections and forward-only answer-based section routing from multiple choice
  or dropdown. A branching question must be last in its section.
- Add, duplicate, move, and remove questions; duplicate an entire quiz.
- HTTPS image links and YouTube embeds on questions/sections, and theme color.
- Component/MS-level audiences using the existing learning-material rules.
- Opening/closing date and time in Asia/Manila, publish/close/unpublish,
  optional question/option shuffling, confirmation message, and optional response
  editing. Shuffling keeps branching questions at the end of their section.
- One response per student account, draft autosave, section navigation, and
  personal response review. Staff can preview; only students submit responses.
- Server-side scoring, exact-set checkbox scoring, and case/whitespace-insensitive
  text keys. Paragraphs, grids, files, and questions without a key are manually
  graded when points are assigned. Blank optional answers receive zero points.
- Grade review, per-question score overrides and feedback, held/released scores,
  response count/average/pending statistics, paginated responses, and CSV export.

## Access and data integrity

Super admins manage all quizzes. Coordinators manage their own quizzes. Published
quizzes are visible only to their selected components and ROTC student MS levels;
ROTC staff can preview selected ROTC quizzes. Students only see their own
responses. Answer keys and feedback are omitted from student payloads until the
manager releases the score. Immediate release only occurs when no manual grading
is pending. Enabling both response editing and immediate release allows students
to revise answers after seeing released feedback; choose those settings knowingly.

After the first student draft or response is created, the definition is locked.
Duplicate the quiz to change its questions/settings. Status can still be changed.
Admins can update the separate **Quiz components** card and click **Save components**
even after responses exist. Select CWTS, LTS, and/or ROTC; ROTC requires at least
one MS level. Audience changes preserve questions, responses, and grades. Newly
excluded students cannot continue answering, but retain access to their own records.
This keeps saved responses and grading aligned with the original questions.
Student drafts survive closure. Formally submitted responses remain viewable by
their owner even if the quiz is later closed or its audience no longer matches.

Quiz attachments use the protected learning-material storage directory and must
be backed up with the database. Each is limited to at most 1 MiB (reduced to the
server's PHP request limit), with PDF, DOCX, PPTX, XLSX, TXT, PNG, JPG supported.
This attachment limit is separate from the 10 GB learning-material uploader.
Up to 100 question/section items per quiz and 1 MiB of JSON per API request are
supported. Responses do not automatically modify the existing Grade Computation
module.

This is a native Google Forms-inspired workflow, not Google Forms integration.
Google Sheets/Drive synchronization, Google add-ons, email notifications,
collaborative live editing, and import of external Google Forms are not included.

## Verification

`php tests/quizzes.php` checks definition validation, scoring, branching,
required questions, safe media links and answer-key redaction.

`python tests/quiz-http.py` runs the actual PHP endpoint against an isolated
SQLite test database and loopback PHP server. It verifies permissions, draft and
publish flow, private keys/results, submissions, grading/release, CSV safety,
attachments and quiz locking. It does not touch the configured application DB.
MySQL DDL and transaction-lock behavior still need verification on deployment.
