# CWTS and LTS masterlist reconciliation

`reconcile-cwts-masterlist.php` aligns each existing CWTS or LTS student's
current section and facilitator with the section worksheets in an exported
student masterlist.

The selected workbook is treated as the exact, authoritative folder roster.
Students listed in the workbook are assigned to that worksheet's section and
facilitator. Component students absent from the workbook are not deleted; if
they are currently assigned to a section folder, they are moved to the
component pending list with `created_by = NULL`.

Workbook names are matched against the entire student table, not only students
already tagged with the selected component. This recovers pending, public,
unassigned, or incorrectly tagged students. When a listed student comes from a
different component, the exact sync also updates the student account and latest
registration component. Web applies containing such cross-component recovery
must be performed by a Super Admin.

The command applies every safely matched, unambiguous workbook row in one run.
Unmatched or ambiguous rows, and rows whose facilitator is missing, are skipped
without blocking the other students. Removal of unlisted students from section
folders is intentionally deferred unless the entire workbook matches safely.
Before a successful apply, the command writes a JSON snapshot of the affected
database records to `backups/`.

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

The key result fields are:

- `assignable_matched`: rows that Apply will place immediately
- `skipped_workbook_rows`: rows that cannot be placed safely
- `move_to_pending_count`: unlisted students removed from folders when the full workbook matches
- `deferred_move_to_pending_count`: cleanup withheld because one or more workbook rows were skipped

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
