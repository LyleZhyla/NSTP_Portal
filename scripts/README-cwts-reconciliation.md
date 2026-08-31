# CWTS and LTS masterlist reconciliation

`reconcile-cwts-masterlist.php` aligns each existing CWTS or LTS student's
current section and facilitator with the section worksheets in an exported
student masterlist.

The selected workbook is treated as the exact, authoritative folder roster.
Students listed in the workbook are assigned to that worksheet's section and
facilitator. Component students absent from the workbook are not deleted; if
they are currently assigned to a section folder, they are moved to the
component pending list with `created_by = NULL`.

The command is intentionally fail-closed. It does not apply changes when a
student is unmatched or ambiguous, or when a facilitator from the workbook is
missing from the database. Before a successful apply, it writes a JSON snapshot
of the affected database records to `backups/`.

## Important

- Do not commit the student masterlist, generated report, or backup to GitHub.
- Run the dry-run against the same production database that the website uses.
- Keep the workbook outside the public web directory when possible.
- The workbook must retain its original layout: section in cell A2,
  facilitator in A3, and student rows beginning at row 7.

## Production dry-run

### Recommended: website admin page

After deployment, sign in as Super Admin or the matching CWTS/LTS Coordinator
and open `section-reconciliation.php` from the **Section Reconciliation**
sidebar item. Select the component, upload its workbook, and click **Preview**.
The page shows the match and validation totals without changing the database.

When all required safety totals are zero, select the workbook again, type
`APPLY CWTS` or `APPLY LTS`, and click **Apply corrections**. Upload it one
final time and click **Preview** to verify that `Changes Needed` is zero.

### Alternative: server command line

Upload the workbook privately to the server, then run from the project root:

```sh
php scripts/reconcile-cwts-masterlist.php /private/path/student-masterlist-cwts.xlsx --production --json
```

For LTS, add `--component=LTS`:

```sh
php scripts/reconcile-cwts-masterlist.php /private/path/student-masterlist-lts.xlsx --component=LTS --production --json
```

Only continue when the result shows:

- `unmatched_count: 0`
- `ambiguous_count: 0`
- an empty `missing_facilitators` list
- expected values for `workbook_students`, `matched`, and `changes_needed`

`database_only_count` is informational. `move_to_pending_count` shows how many
unlisted component students will be removed from their existing section folder.
They remain in the database and retain their account and related records.

## Apply

```sh
php scripts/reconcile-cwts-masterlist.php /private/path/student-masterlist-cwts.xlsx --production --apply --json
```

Use the same `--component=LTS` option when applying an LTS workbook.

The result must show `applied: true`. The backup path is returned in
`backup_file`.

## Verify

Run the dry-run again:

```sh
php scripts/reconcile-cwts-masterlist.php /private/path/student-masterlist-cwts.xlsx --production --json
```

After a correct apply, `changes_needed` must be `0` and every workbook row must
still match. Verify the component's section counts in the website before
allowing normal section-management activity to resume.
