# 2026_10 shipment cleanup: upgrade notes

Files: `2026_10_shipment_cleanup.sql` (the main migration), `2026_10_delivery_schedule_time.sql` (pickup time support), `2026_10_shipment_audit_details.sql` (optional field-level audit details), and `config/astratrack_mysql.sql` (fresh install, same end state).
Order on a live system: **back up, run the main migration if needed, run `2026_10_delivery_schedule_time.sql` and `2026_10_shipment_audit_details.sql`, then deploy the code.** The time migration is safe to rerun and leaves existing pickup dates unchanged. The old code keeps working against the migrated database, so rolling the code back never needs a database change.

## Decisions to confirm before live
1. **Old status mapping.** `In transit` becomes `Trucking` (the only stage in the In Transit group, so tab counts do not move). `Customs processing` becomes `Declaration filed`, `Pending documents` becomes `Documents received`, blank becomes `Documents received`. Edit the INSERT in step 3 if you want different targets.
2. **Sample row.** The Kubota sample shipment now has status `Trucking` (was `In transit`) although its only timeline entry is `Documents received`. It was already inconsistent; it is only sample data.
3. **Tabs.** `statusGroups()` returns the 4 Figma groups by default. On Hold and Cancelled shipments appear under "All" and with their own badge, but have no tab and are not counted in the dashboard bars. `statusGroups(true)` includes them if you want tabs later.
4. **Trucking label.** All pages now show the badge "Trucking" for that stage. Before, `client_dashbroad.php` showed "In Transit" and the other pages "Trucking".
5. **Reference prefix (item 4).** Not changed yet, waiting for your answer. The code actually generates `AST-2026-0001` (with a hyphen), the Figma file shows `SHP-2026-0148`.

## Rollback
MySQL DDL cannot be wrapped in a transaction, so the real rollback is: restore the mysqldump taken before the run. The migration also keeps `shipments_status_bak_2026_10` and `shipment_status_updates_stage_bak_2026_10` (old status wording). To undo by hand:

```sql
ALTER TABLE shipments DROP FOREIGN KEY fk_ship_stage;
ALTER TABLE shipment_status_updates DROP FOREIGN KEY fk_status_stage;
UPDATE shipments s JOIN shipments_status_bak_2026_10 b ON b.shipment_id = s.shipment_id
   SET s.current_status = b.current_status;
UPDATE shipment_status_updates u JOIN shipment_status_updates_stage_bak_2026_10 b ON b.update_id = u.update_id
   SET u.stage = b.stage;
ALTER TABLE shipments DROP INDEX uq_ship_bl, ADD INDEX idx_ship_bl (bl_awb_no);
DROP TABLE shipment_stages;
-- Leave shipments.actual_arrival_date, shipments.remarks and deliveries.receiving_contact in place: dropping them loses
-- anything typed since the upgrade, and the old code does not mind them.
```
Rows created after the upgrade are not in the backup tables; they keep their stage names, which the old code also understands. Drop the two `_bak_2026_10` tables once the upgrade has been stable for a couple of weeks.

## Rename list (nothing was renamed)
`clint_shipment.php` and `client_dashbroad.php` keep their names. If you rename later, every one of these changes:

| Old name | Where it appears |
|---|---|
| `clint_shipment.php` | the file; `assets/css/clint_shipment.css`; `assets/js/clint_shipment.js`; its own header comment and links (about 14 places inside `clint_shipment.php`, including the 3 `<script>`/`<link>` tags); `function/shipment_common.php` (client menu and a comment); `client_dashbroad.php` (menu array and the "View all" link) |
| `client_dashbroad.php` | the file; `assets/css/client_dashbroad.css`; `assets/js/client_dashbroad.js` (its `fetch("client_dashbroad.php?ajax=track...")` call); its own comments, `<link>`, `<script>` and form `action`; `login.php` (redirect after client login); `function/shipment_common.php` (client menu); `clint_shipment.php` (`$crumbHome`); `function/shipment_stages.php` (comment) |

Also check bookmarks, any saved links sent to clients, and server rewrite rules. Search with: `grep -rn "clint_shipment\|client_dashbroad" .`

## Keeping config/gemini.php out of git
`.gitignore` already lists it, but that only protects files git is not tracking yet.
```
git ls-files config/gemini.php               # must print nothing
git log --all --oneline -- config/gemini.php # must print nothing
git check-ignore -v config/gemini.php        # must name the .gitignore rule
```
If either of the first two prints anything, the key is in history: revoke it in Google AI Studio first, then `git rm --cached config/gemini.php` and clean history with `git filter-repo --path config/gemini.php --invert-paths`.
The zip you sent contained `config/gemini.php` with what looks like a live key. Treat it as exposed and **rotate it**. The delivered package contains `config/gemini.sample.php` instead; copy it to `gemini.php` on each machine.

## Database user
`config/database.php` now reads `config/database.local.php` (git-ignored) or the `ASTRATRACK_DB_HOST/NAME/USER/PASS` environment variables. Without either it only falls back to root with no password when the request is for localhost. Create the app user with the commented block at the end of `config/astratrack_mysql.sql`: it gets SELECT, INSERT, UPDATE, DELETE only. Run migrations with an admin account, not the app user. Also set a password on the MySQL root account of any shared machine.

## Checklist: testing the upgrade on a copy
1. `mysqldump --routines astratrack > before.sql`; restore it into a new database `astratrack_copy`; point `config/database.local.php` at the copy.
2. Record baselines on the copy: `SELECT current_status, COUNT(*) FROM shipments GROUP BY 1;` the same for `shipment_status_updates.stage`; total shipments; the 4 tab counts on the admin and staff lists.
3. Run the duplicate check (step 0 SELECT). On the copy, plant a duplicate B/L and confirm the script stops before changing anything, then remove it.
4. Run the migration. It must end with step 6 showing 10 stages, 0, 0, 2, 1. Any unmapped value is listed by the step 3 SELECTs: add it to the mapping and run again.
5. Run the migration a second time: it must complete without errors and change nothing.
6. Tab counts and dashboard bars equal the baselines (watch the customs group, which absorbs `Customs processing`).
7. `SHOW CREATE TABLE` for `shipments`, `deliveries`, `shipment_status_updates`, `shipment_stages` on the copy versus a database built from `astratrack_mysql.sql`: same columns, keys and constraints. `shipments.actual_arrival_date` is optional and stores only the actual date, not ETA or free-time dates. `deliveries.scheduled_time` stores the optional pickup time alongside `scheduled_date`.
8. Admin, staff and client logins: dashboards, list tabs and filters, shipment details (stepper and timeline), tracking by B/L on the client dashboard.
9. Update Status on one shipment through all 10 values including On Hold and Cancelled; check the badge, the stepper, the timeline entry and the Recent Activity line; confirm the client sees the same.
10. `UPDATE shipments SET current_status = 'In transit' WHERE shipment_id = 1;` must be refused by the foreign key.
11. Add a shipment from a PDF with remarks: remarks show on the details page. Try the same B/L again: friendly error, nothing saved. Save two shipments at the same moment: both succeed with different references.
12. Switch the copy to the restricted app user: every page above still works.
13. Rollback drill: restore `before.sql` into a second copy and run the old code against the migrated copy to confirm it still works.
14. Check `git ls-files config/gemini.php` is empty and the old key is revoked.

## Not tested here
I had no PHP or MySQL in my environment, so none of this was executed. The PHP was reviewed by hand and bracket-balance checked only. The SQL uses only MySQL 5.7/8.x and MariaDB 10.3+ syntax, but run the checklist above.

## Noticed, not changed
`shipment_add.php` returns the raw database error text (`detail`) to the browser on a failed save. Consider logging it only. `admin.php`, `staff.php` and `client_dashbroad.php` still count Delivered with the literal stage name `'delivered'`. Shipment status changes do not update `transactions.status` yet.
