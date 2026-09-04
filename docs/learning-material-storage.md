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
