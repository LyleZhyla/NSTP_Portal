# CWTS masterlist reconciliation

`reconcile-cwts-masterlist.php` aligns each existing CWTS student's current
section and facilitator with the section worksheets in an exported student
masterlist.

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

After deployment, sign in as Super Admin or the CWTS Coordinator and open
`cwts-section-reconciliation.php` from the **CWTS Reconciliation** sidebar
item. Upload the workbook and click **Preview**. The page shows the match and
validation totals without changing the database.

When all required safety totals are zero, select the workbook again, type
`APPLY CWTS`, and click **Apply corrections**. Upload it one final time and
click **Preview** to verify that `Changes Needed` is zero.

### Alternative: server command line

Upload the workbook privately to the server, then run from the project root:

```sh
php scripts/reconcile-cwts-masterlist.php /private/path/student-masterlist-cwts.xlsx --production --json
```

Only continue when the result shows:

- `unmatched_count: 0`
- `ambiguous_count: 0`
- an empty `missing_facilitators` list
- expected values for `workbook_students`, `matched`, and `changes_needed`

`database_only_count` is informational. Database-only students are not deleted
or modified by this command.

## Apply

```sh
php scripts/reconcile-cwts-masterlist.php /private/path/student-masterlist-cwts.xlsx --production --apply --json
```

The result must show `applied: true`. The backup path is returned in
`backup_file`.

## Verify

Run the dry-run again:

```sh
php scripts/reconcile-cwts-masterlist.php /private/path/student-masterlist-cwts.xlsx --production --json
```

After a correct apply, `changes_needed` must be `0` and all 925 workbook rows
must still match. Verify the CWTS A-W counts in the website before allowing
normal section-management activity to resume.
