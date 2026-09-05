# Learning materials up to 10 GB

The maximum file size is 10,737,418,240 bytes (10 GiB, shown as 10 GB in the UI).
Uploads use sequential multipart chunks of at most 1 MiB, reduced automatically
for PHP's upload and POST limits. No 10 GB PHP request or memory limit is needed.
Keep the page open. Failed parts retry twice; Retry Upload continues the current
upload while that page remains open. Refreshing the page starts a new upload.

Server requirements:

- 64-bit PHP and a filesystem supporting files larger than 4 GB.
- Persistent writable storage with room for the uploaded files. Hosting disk
  quotas, request limits, bandwidth limits and server timeouts still apply.
- The database account must be able to create tables and alter the existing
  learning-material table. The page automatically upgrades file_size to BIGINT
  and adds storage_name. Existing database-backed materials remain downloadable.

New file bytes live in `storage/learning-materials`, excluded from Git. Apache
denies direct access using the committed .htaccess. Each stored file also has a
PHP exit guard, which must remain in place. Downloads remove that guard and stream
the original bytes after checking authentication. Range requests support resumed
downloads. Do not configure this directory to serve PHP files as plain text.

For hosting, prefer setting the server environment variable
`NSTP_LEARNING_MATERIALS_DIR` to an absolute persistent directory outside the web
root, writable by PHP. All web workers must share that directory. Changing the
directory requires moving existing material files to it.

Back up both the database and the material storage directory. A Git push or a
database-only backup does not include uploaded files. Cancel Upload removes the
unfinished file. Starting an upload cleans up to 20 unfinished uploads last active
more than 24 hours ago; published files are not cleaned up.

## Deleting materials

Super admins can permanently delete any published material. A coordinator can
delete only a material they uploaded. The UI requires a confirmation, and the
server independently checks the session, CSRF token, role, and ownership. The
database record is deleted in a transaction; after commit, its protected stored
file is removed. Legacy database-backed materials have no separate file to remove.
Deleted materials disappear from lists and their download/playback links return
not found. Deletion cannot recall copies that students already downloaded.

Material mutation forms POST to the current Learning Management page, which
internally dispatches to the secured upload handler. This supports deployments
whose web routing returns 404 for direct browser requests under /endpoint.
The original endpoint URL remains available for backward compatibility.
The delete form sends a neutral management operation code because some shared
hosting web-application firewalls return a branded 404 for request values that
contain destructive SQL/action terms. Authorization is still enforced by the
server-side role, ownership, session, and CSRF checks.

## Open/close student access

Each material has an **Allow student access** switch for super admins and its
uploading coordinator. Existing and newly uploaded materials start open. Turning
the switch off hides the material from student lists and counts and rejects new
student download, playback, HEAD and range requests, including legacy materials.
Staff retain their existing audience-based access. Reopening restores student
access subject to the original component/MS-level selection. The automatic schema
upgrade adds `is_open` with a default of 1. Already downloaded or buffered content
and transfers authorized before closure cannot be recalled.

`python tests/quiz-http.py` includes isolated HTTP checks for material closing,
reopening, ownership, CSRF, legacy records and blocked video range requests.

## Video upload and playback

Admins and coordinators can upload MP4, WebM, and MOV videos through the same
chunk uploader, with the same 10 GB limit. File content is checked with fileinfo;
renaming text or HTML to a video extension does not make it valid.

Video materials have a native player with controls and no automatic preload.
Playback uses the authenticated download endpoint with `play=1`, an inline video
content type, and the existing byte-range support for seeking. Component and MS
level restrictions apply to playback as well as downloads. The Download button
still sends an attachment. Playback depends on the browser and the video codec;
unsupported videos can be downloaded. The server does not transcode video.

Run `php tests/learning-material-video.php` to verify container type detection,
guarded-storage offsets, size limits, and disguised-file rejection. These small
header fixtures do not test actual codec playback or a full 10 GB transfer.

## Component audience rules

Uploaders select one or more of CWTS, LTS and ROTC. ROTC also requires one or more
of the system's MS-1, MS-31 and MS-41 student levels. Selecting CWTS and ROTC shares
one material with both components. ROTC staff see all levels selected for ROTC;
ROTC students see only materials for their resolved MS level. The list, pagination
count, direct downloads, HEAD requests and range downloads use the same filter.

Super admins can see all materials and edit any material's audience. Coordinators
can edit their own uploads, and uploaders retain access to their own materials.
Existing materials (NULL audience) remain available to all accounts until their
audience is changed using Change audience. Empty or invalid new selections are
rejected on the server. Audience metadata is saved at upload start and retained
through chunk retries and finalization.
