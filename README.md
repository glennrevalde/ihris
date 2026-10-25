# G-SMaRT HRIS — Integrated Human Resource Information System

A web-based HRIS for Philippine companies built with **vanilla HTML, CSS, JavaScript, PHP 8 and MySQL/MariaDB** (no frameworks, no Composer, no npm). It implements the three integrated modules described in the *Integrated HRIS System Narrative*:

1. **Personnel Information System (PIS)** — 201 files, leaves with email approval, evaluations, salary grades, org structure, discipline.
2. **Payroll** — BIR/SSS/PhilHealth/Pag-IBIG tables, overtime and premiums, deductions, benefits, ledgers, multi-branch, reports, 13th month, final pay.
3. **Attendance Monitoring System (AMS)** — biometric and Excel/CSV imports, web/mobile clock-in with GPS geofence and selfie, unified DTR, cutoff approval that feeds payroll.

A built-in **User Manual** documents every page of all three modules, with a search and a per-page Help button. See section 11.

---

## 1. Requirements

| Item | Version |
|---|---|
| PHP | 8.0 or newer, with the `pdo_mysql`, `mbstring`, `zip` (for .xlsx import), `openssl` (for SMTP over TLS) and `fileinfo` extensions |
| Database | MySQL 5.5.3 or newer (5.5 / 5.6 / 5.7 / 8.x) or MariaDB 5.5+ (InnoDB, utf8mb4). Works with strict mode and `ONLY_FULL_GROUP_BY` on. |
| Web server | Apache (XAMPP/WAMP/LAMP) with `.htaccess` enabled, or Nginx, or `php -S` for testing |
| Browser | Any current browser. Camera and GPS for web clock-in need **HTTPS** or `http://localhost`. |

## 2. Installation (no installer — just import the SQL)

1. Copy the `ihris` folder into your web root, e.g. `C:\xampp\htdocs\ihris` (make sure `index.php` sits directly inside `htdocs\ihris`, not in `htdocs\ihris\ihris`).
2. Start **Apache** and **MySQL**.
3. Open phpMyAdmin → **Import** → choose **one** of these files from the `database` folder → **Go**:
   * `database/ihris_mysql55.sql` — clean start: all tables, statutory tables, leave types, holidays, salary grades, code of conduct and the `admin` account.
   * `database/ihris_mysql55_demo.sql` — the same plus a sample company (15 employees, two months of attendance, payroll, leaves, loans, evaluations and cases).

   The file creates the database `ihris` by itself. Importing again drops and re-creates the G-SMaRT HRIS tables, so never import over live data.
4. If your MySQL login is not `root` with a blank password on `127.0.0.1:3306`, edit `db_host`, `db_port`, `db_user`, `db_pass` (and `db_name` if you renamed the database) in `config/config.php`.
5. Open **`http://localhost/ihris/`** and sign in as `admin` / `admin123`. You will be asked to set a new password.

If the database can't be reached or hasn't been imported yet, `index.php` shows a page that says exactly what is wrong (MySQL not running, wrong password, database missing, tables missing) instead of an error.

### Upgrading an existing installation (keeps your data)

Copy the updated files over the old ones and open the site. `index.php` checks the `db_version`
setting and applies every missing database change itself (new tables, new columns, new settings),
so nothing has to be imported by hand. The current database version is **18**. Every company database
(see section 12) upgrades itself the same way the first time it is opened.

If you would rather run the SQL yourself (or the web user has no `ALTER`/`CREATE` rights), select
your HRIS database (for example `hris_web`) in phpMyAdmin and import, in order, the scripts you have not applied yet:

* `database/upgrade_to_v2_mysql55.sql` — M.A.D.A.S. attendance and payroll integration.
* `database/upgrade_to_v3_mysql55.sql` — employee's transactions with terms, logs-per-day on shifts,
  employee CSV import, audit and menu changes.
* `database/upgrade_to_v4_mysql55.sql` — CSV imports of transactions, deductions and benefits, the
  manner of posting of benefits, the deduction source of multi-location pay, corrected log types.
* `database/upgrade_to_v5_mysql55.sql` — the system name *G-SMaRT HRIS* (System Settings → System name),
  the e-mail sender name, and the new help texts of the break-log and duplicate-log policies.
* `database/upgrade_to_v6_mysql55.sql` — multi-company: join requests made with a Company Code
  (`users.reg_status`, `registered_at`, `reg_note`, `reviewed_by`, `reviewed_at`) and the hidden
  Super Admin support account (`users.is_support`). Existing accounts are unchanged.
* `database/upgrade_to_v7_mysql55.sql` — Attendance Transaction Closing (`attendance_summaries.is_closed`,
  `closed_at`, `closed_by`; the transactions of periods already approved or paid are marked closed),
  the Payroll Computation parameters saved on each run (`payroll_runs.calc_*`), page-level user access
  (`user_page_access` table, `users.page_access`) and the 3rd / 4th pay terms of fixed transactions.
* `database/upgrade_to_v8_mysql55.sql` — dynamic roles and permissions (`roles`, `permissions`,
  `role_permissions`, `user_permission_overrides`, `users.role_id`; the five existing roles are kept as
  roles), Period Closing (`period_closings`, `payroll_runs.posted_at` / `posted_by`,
  `payroll_items.posted_at` / `closing_id`; runs finalized before are marked posted) and editable ledger
  entries (`ledger_entries.edited_at`, `edited_by`, `edit_note`). The permission list itself and the
  default permissions of the existing roles are written the first time the site opens after the upgrade,
  and the module / page limits of version 7 are converted into Deny overrides, so every account keeps
  exactly the access it had.
* `database/upgrade_to_v9_mysql55.sql` — the employee masterfile additions (`employees.eye_color`,
  `religion`, `weight`, `height`, `spouse_name`, `spouse_address`, `spouse_contact`, `father_name`,
  `mother_name`; tables `employee_children`, `employee_education`, `employee_prev_employment`), the YTD
  information of the previous employer (`employee_prev_ytd`) and linked accounts of multi-company users
  (`users.linked_from`). The master database gets the table `company_user_links` by itself on the next
  page load (or import `database/ihris_master_mysql55.sql` again: it only creates what is missing).
* `database/upgrade_to_v10_mysql55.sql` — how each biometric terminal is reached (`bio_devices.conn_method`,
  `push_token_hash`, `push_token_hint`, `auto_post`, `last_push_at`, `device_info`; existing terminals keep
  the built-in protocol) and the saved column layouts of customizable reports (`report_layouts`).
* `database/upgrade_to_v11_mysql55.sql` — the default weekly day-off of the employee (`employees.day_off`,
  weekday numbers 0 = Sunday to 6; blank for everyone until set).
* `database/upgrade_to_v12_mysql55.sql` — "All companies / branches" and "All departments" of the employee
  assignment of the users (`users.scope_all_branches`, `users.scope_all_units`). Accounts that had no company
  and no department ticked get both, so they keep seeing every employee; run once, a second run changes nothing.
* `database/upgrade_to_v13_mysql55.sql` — Hold of fixed transactions (`employee_fixed_trans.status` gets `hold`,
  `held_at`, `held_by`), the reprocessing filters of the payroll run (`payroll_runs.calc_emp_ids`, `calc_branch_ids`,
  `calc_unit_ids`), the Memoranda / Announcements (`memo_templates`, `memos`; the sample formats are added the first
  time the screen opens) and the company logo on reports (settings `report_logo_*`).
* `database/upgrade_to_v14_mysql55.sql` — pre-arranged overtime of the shifts (`shifts.ot_in`, `ot_out`), the overtime
  logs and minutes of the daily time record (`dtr.ot_in`, `ot_out`, `pre_ot_minutes`, `ot_late_minutes`, `ot_ut_minutes`)
  and the NTE to-do list (`nte_todos`). The days already computed are recomputed by themselves when opened (unlocked days only).
* `database/upgrade_to_v15_mysql55.sql` — employment statuses Resigned / Terminated / Retired (`employees.employment_status`),
  the item edits of the Final Pay (`final_pay_overrides`), the penalty reset of the offenses (`conduct_offenses.reset_days`),
  the 13th month basis (settings `thirteenth_basis`, `thirteenth_fy_from`, `thirteenth_fy_to`) and the company events (`company_events`).
* `database/upgrade_to_v16_mysql55.sql` — Cancel Calculation of the final pay (`final_pays.status` Cancelled, `cancelled_at`,
  `cancelled_by`, `cancel_reason`) and the day-off of a per-date shift change (`employee_schedules.is_rest_day` may be empty =
  follow the rest days of the shift, or the default day-off of the employee on a 7-day shift). The days already computed are
  recomputed by themselves when opened (unlocked days only).
* `database/upgrade_to_v17_mysql55.sql` — the display order of the import configurations (`import_profiles.sort_order`:
  ZKTeco attlog.dat (tab), Generic CSV, Space-delimited, then the others).
* `database/upgrade_to_v18_mysql55.sql` — the Employee Loan / Deduction Request of the portal and its approval (`loan_requests`),
  the codes offered for requests (`deduction_types.ess_request`) and the longest term an employee may apply for (setting `loan_request_max_terms`).

The scripts are written for MySQL 5.5 (no `ADD COLUMN IF NOT EXISTS`; a small stored procedure
checks `information_schema` first), are safe to run more than once and never touch existing rows
except to fill the new `shifts.logs_per_day` column from the breaks already defined.

### Quick test server

```bash
php -S 127.0.0.1:8080 -t ihris
```

### Troubleshooting

* **"Forbidden" / "You don't have permission to access this resource"**: the `app`, `config`, `database`, `pages` and `uploads` folders are blocked from the browser on purpose (their `.htaccess` files deny access). Always open the site through `http://localhost/ihris/` or `http://localhost/ihris/index.php`, never a file inside those folders (for example `…/pages/login.php`). If the site root itself says Forbidden, check the last lines of Apache's `error.log` (XAMPP: `C:\xampp\apache\logs\error.log`); it names the file and the rule that blocked it.
* **Blank page or "Parse error"**: the app needs PHP 8.0 or newer. Old XAMPP bundles that shipped with MySQL 5.5 use PHP 5.x.
* **Leave-approval email links point to the wrong address**: set `app_url` in `config/config.php` to the address people use to open the site.

## 3. Demo accounts (when demo data is loaded)

| Username | Password | Role | Person |
|---|---|---|---|
| `admin` | `admin123` (must be changed at first sign-in) | System Administrator (Super Admin) | — |
| `hr` | `hr123` | HR Officer | Maria Santos, HR Manager |
| `payroll` | `payroll123` | Payroll Officer | Jose Reyes, Finance & Payroll Manager |
| `director` | `director123` | Supervisor (department head) | Antonio Cruz, Operations Director |
| `supervisor` | `super123` | Supervisor | Liza Garcia, Sales Manager |
| `pedro` | `pedro123` | Supervisor | Pedro Ramos, Production Manager |
| `employee` | `employee123` | Employee | Mark Villanueva, Sales Associate |
| `emp-0006` … `emp-0015` | `Welcome123` | Employee | Other employees |

Demo data covers **August–September 2026**: August has approved cutoffs and finalized payroll; September has attendance with designed exceptions (unauthorized OT, missing punches, a clock-in outside the geofence), pending leaves, loans, evaluations, disciplinary cases and a retroactive step increase. Change every password before using the system with real data.

## 4. Email-based leave approval (narrative 2.2)

* Each approval stage (immediate supervisor → department head → HR) gets an email with the leave type, dates, number of days and the remaining balance, plus **Approve / Return / Disallow** buttons.
* The buttons open a tokenized page (`index.php?p=leave_action&token=…`) that works without signing in. Each link is single-use and expires.
* The employee is emailed after each decision. Every email and action is time-stamped in the leave's approval trail and in **Administration → Email Outbox**.
* **Administration → Settings → Mail** has three delivery modes:
  * **Log only** (default): nothing is sent; open the emails in *Email Outbox* and click the buttons from there. Good for testing.
  * **SMTP**: built-in client with STARTTLS/SSL and login. For Gmail use `smtp.gmail.com`, port 587, STARTTLS, and an App Password.
  * **PHP mail()**: uses the server's mailer.
* Set `app_url` in `config/config.php` correctly, because approval links are built from it.

## 5. Module map

| Narrative section | Where in the app |
|---|---|
| 2.1 Employee masterfile / digital 201 | Personnel → Employee Masterfile (personal info, SSS/PhilHealth/Pag-IBIG/TIN, employment, history, scanned documents). Changes to rate, grade, pay group or branch are logged to history and flow to payroll; a past effective date creates retroactive pay. |
| 2.2 Leave management | Personnel → Leave Types / Leave Applications / Leave Balances & Accrual; My Workspace → My Leaves. Statutory leaves seeded (SIL, VL, SL, ML 105/+15/60, PL 7, Solo Parent 7, Special Leave for Women, VAWC 10, bereavement, emergency). Monthly/annual/service-based accrual, carry-over, cash conversion, forfeiture, year-end processing. |
| 2.3 Performance evaluation | Personnel → Evaluation Cycles / Competencies & Merit Matrix; Approvals → Team Evaluations; My Workspace → My Evaluations. Weighted KPIs + Likert 1–5 (self, supervisor, 360 peer); the overall rating drives merit increases, promotion and step recommendations, and can open a disciplinary case for poor performance. |
| 2.4 Salary grades & scaling | Personnel → Salary Grade Table (SG-1…SG-6, min/mid/max, steps) and Salary Scaling Simulator (across-the-board %, RTWPB wage order, grade restructure; flags pay below minimum; preview before commit). |
| 2.5 Organizational structure | Personnel → Organization Structure (multi-level tree, heads, reports per department, approval routing). |
| 2.6 Twin-notice rule | Personnel → Disciplinary Cases (NTE with at least 5 days to answer, explanation, hearing, evaluation, Notice of Decision; each step time-stamped; printable notices). |
| 2.7 Code of conduct | Personnel → Code of Conduct & Penalties (offenses + progressive penalty matrix; prior offenses counted automatically over a rolling 12 months). |
| 3.1–3.4 Statutory tables | Payroll → BIR Withholding Tax Table, SSS Contribution Table (with generator), PhilHealth Table, Pag-IBIG Table. All editable, dated by *effective from*. |
| 3.5 OT & premiums | Payroll → Overtime / Premium Table (125%, 130%, 169%, 200%, 260%, ND 10%, combined). |
| 3.6 Lates & undertime | Payroll → Lates / Undertime Table + Administration → System Settings (per-minute or bracket deduction, grace period, habitual tardiness threshold; flagged employees can be sent to a disciplinary case from Attendance → Attendance Exceptions). |
| 3.7 Attendance integration | Payroll runs pull the approved attendance cutoff; discrepancies are listed as run exceptions before finalization. |
| 3.8 Deductions | Payroll → Deduction Types / Employee Deductions (unlimited types; Art. 113 checks for employee consent and the 20% cap on accountability deductions; minimum take-home pay floor). |
| 3.9 13th month & benefits | 13th-month runs (PD 851), de minimis ceilings with excess moved to the ₱90,000 other-benefits pool, company benefits with eligibility rules. |
| 3.10 Subsidiary ledgers | Payroll → Subsidiary Ledgers (SSS/Pag-IBIG loans, company loans, cash advances, payables, accrued benefits), posted and reconciled on every finalized run. |
| 3.11 Reports | Reports → Payroll Register, Monthly Payroll Summary (with budget variance), Branch Cost Allocation, Government Remittances (SSS R-3/R-5, PhilHealth RF-1, Pag-IBIG MCRF, BIR 1601-C), Year-to-Date & BIR 2316 (with alphalist), Department Reports, Audit Trail. |
| 3.12 Multi-branch | Payroll → Branches / Employers, default branch for statutory deductions, per-branch payslip lines, consolidated payslip, inter-branch cost allocation report. |
| 3.13 Other payroll | Pay groups, bank file, payslip PDF self-service, retroactive pay, final pay (due within 30 days of separation). |
| 4.1 Biometric summary | Attendance → Daily Time Records (punches compared with the shift: lates, undertime, OT, night differential, absences). |
| 4.2 Biometric log import | Attendance → Biometric Log Import (.txt/.dat) with configurable import profiles (delimiter, column mapping, date format, punch codes). |
| 4.3 Excel/CSV import | Attendance → Excel / CSV Import (.xlsx/.csv) with validation; unmatched or conflicting rows go to the Import Review Queue for HR. |
| 4.4 Web/mobile check-in | My Workspace → Time Clock (GPS / Selfie) (GPS geofence per branch + live selfie). Out-of-fence or unverified punches go to Attendance → Web Check-in Verification. |
| 4.5 Unified DTR & payroll push | Attendance → Cutoffs & Summaries (process, review exceptions, net out approved leaves, approve and lock, then send to payroll). |
| Built-in documentation | Main → User Manual (browse by section, search every article, a screenshot on every article), plus **Help** in the top bar of every screen. |

### Additions in version 3

| Request | Where in the app |
|---|---|
| Leaves deducted from the gross hours of monthly-salaried employees | Payroll System (PAY) → Payroll Transaction / Payroll Transaction II / Payroll Batch Transaction (and the same screens under Attendance). The perfect-attendance hours of the period are shown; VL, SL, EL, OL and PM lower *Hours work* automatically as they are typed and again on save. |
| Employee's transactions with **terms** (loans, advances, long-term items) | Payroll System (PAY) → Employee's Transactions: Terms (paydays), Trans. term (every pay, 1st/2nd/3rd/4th pay), monthly interest rate, interest amount, Trans/PD principal and interest, trans. date, start date, document no., Co./Branch, live preview. Listing with ST, principal, interest, terms paid, per pay, total paid, balance; **View** opens the ledger and schedule (payment outside payroll, amortization change, cancel, re-activate). Payroll runs take one installment per matching payday and post it at finalization. |
| Audit trail of all actionable items | System → Audit Trail (also under Reports): every sign-in, create, change, approval, compute, finalize, import, export, print and settings change, filterable by user, module and action, with links to the records. |
| Import multiple employees from CSV | Personnel (PIS) → Import Employees (CSV) or the Import CSV button on the Employee Masterfile: template download, validation and preview, defaults for blank columns, optional portal accounts, update-existing mode, import batches. |
| Menu "Pay Trans" renamed | Now **Payroll System (PAY)**. |
| Save & New on the employee form | Personnel (PIS) → New employee → **Save & New** keeps the company, pay code, shift, unit, type and group for the next record. |
| Import attendance logs shown in the Attendance Entry format | Attendance (AMS) → Import Attendance Logs → View: *Attendance entry format*. One row per employee and day with the log columns of the shift code (2, 4 or 6 logs), the status of the day and a link to Attendance Entry. |
| Log columns follow the shift code | Setup → Shift Table → *Logs per day* (Auto / 2 / 4 / 6). Attendance Entry shows Time in, Break out 1, Break in 1, Break out 2, Break in 2 and Time out according to the shift. |
| AMS screens renamed | Attendance Summary Entry, Attendance Summary Entry II, Attendance Batch Transaction Entry. *Approve* is now **Close attendance**: the closed period appears in the three payroll transaction screens, where the payroll master may still encode directly. |
| New employees in Payroll Transaction Setup | A newly added active employee appears in the employee list of every open period immediately. |

### Additions in version 5

| Request | Where in the app |
|---|---|
| System renamed to **G-SMaRT HRIS** | Sign-in page, sidebar, browser tab, footer, e-mails, manual. The name is the *System name* setting (System Settings → General), so it can be changed again without editing files. The folder `ihris`, the database and `config/config.php` stay as they are. |
| *No BREAK log on SHIFT w/ break as ABSENT* (every break must be logged) | Attendance → Policies. The shift is split at its breaks into work segments; a segment whose opening or closing log is missing is absent. Shift 8:00–12:00 / 1:00–5:00: logs 8:00, 12:01, 5:05 → no break in → 1:00–5:00 absent (4 hrs); logs 7:57, 5:15 → no break out and no break in → 8 hrs absent. A punch between the in and the out is placed on the nearest scheduled break log, so 8:00, 12:58, 5:05 reads as a break in with the break out missing. With *…as UNDERTIME* the same minutes are counted as undertime. The DTR shows *No break in 1 log*. Days computed before are rebuilt when shown. |
| Duplicate logs | `# of minutes between LOGS w/ same TYPE as INVALID` again drops only a repeat of the same log type (or of two untyped punches), so a short 15-minute break is always kept. |
| Multi-location hours listed by Co. / Branch | Payroll Batch Transaction and Attendance Batch Transaction Entry: choosing a company / branch lists its employees **and** everyone whose multi-location entry has hours there. Their row shows only the hours of that branch (*Part of a multi-location transaction*); saving it changes that location and the total becomes the sum of the locations again. All companies lists every transaction whole. |
| Payroll Register and payslips by branch | Payroll Batch Register → Co. / Branch: the employees of the branch plus the multi-location employees who worked there, each with only the pay earned in that branch, its share of the employer contributions and the deductions when it is the deduction source (*Branch part*). The branch totals of all branches add up to the *All* register. **Branch payslip** opens the payslip on that branch's tab. Print Payslips with a company / branch prints the branch payslip of those employees; with All, one consolidated payslip each (or split). Print and CSV export follow the same filter. |

### Additions in version 20

Database version 18: open the site once (it upgrades by itself) or import `database/upgrade_to_v18_mysql55.sql` (MySQL 5.5; after the version 17 script if it was not imported yet).

| Request | Where in the app |
|---|---|
| Select one, two or multiple Co/Branch, Department, Employee Group (Payroll Transaction, Payroll Transaction II, Payroll Batch Transaction, Payroll Batch Register, Payroll Batch Register II, Print Payslips) | The **Co. / Branch**, **Department** and **Employee group** filters are now lists: open one, tick one, two or more values (Find, All, Clear), close it with **Done** or a click outside. Nothing ticked = all; a department includes the units under it. Same on the attendance counterparts (Attendance Summary Entry I / II, Attendance Batch Transaction Entry, Attendance Entry, Employee Leave Entry, Batch Shift Define, Auto-Generate, Attendance Transaction Closing) and on Employee's Transactions and Fixed Transaction Setup. In the registers and Print Payslips, a multi-location employee has one line / payslip per company / branch chosen (the part of each), so the companies / branches add up to the whole run; the Selection line and the printed reports name every value chosen. |
| Make the time editable by number keys | Every time field (Shift Table, Attendance Entry logs, settings, forms): **0815** or **815** = 08:15 AM, **1730** = 05:30 PM, **815p** / **8:15 pm** = PM, **12a** = 12:00 AM (without a / p the time is read as 24-hour), **N** = now, the arrow keys = one minute (Shift + arrow = 15 minutes), the clock icon (or Alt + Down) opens the picker. The form still sends HH:MM. |
| Employee Portal: Request Employee Loan/Deduction | My Workspace → **Loan / Deduction Request**: Type of loan / deduction (codes of the Employee Transactions Table marked **Portal request**), Date applied, Amount applied, Term (paydays), Interest rate (follows the code; 0 = none), Computed interest, Mode of payment (every 1st / 2nd / 3rd / 4th pay, every pay), Principal amort., Int. amort., Total amort. (computed while typing), purpose; **Save as draft** / **Submit for approval** / **Cancel request**; Status Draft → Submitted for approval → Approved / Rejected (and Cancelled). Interest = amount × rate % a month × months (as the transactions with terms); Int. amort. = interest ÷ term; Total amort. = Principal amort. + Int. amort. |
| Approval facility for the Immediate Superior, HR Manager or the Administrator | Approvals → **Loan / Deduction Approvals** (and a card on Pending Approvals): the immediate superior sees the team's requests, HR and the administrator every request of their assignment (nobody decides his / her own). The approver reviews the application and the employee's ability to pay (last net pay, active deductions with terms), sets the **Amount approved**, term, mode, interest rate and **Start payment**, and approves: the request is entered in **Employee's Transactions** as a transaction with terms (document no. = the request no.), taken by the payroll from the start of payment. Reject needs a reason. E-mails to the superior on submission and to the employee on the decision. Added: the **Portal request** column of the Employee Transactions Table (which codes employees may apply for), the setting *Loan / Deduction Request: longest term* (48 paydays), the request numbers LR-YYYY-NNNN and the audit trail. |

### Additions in version 19

Database version 17: open the site once (it upgrades by itself) or import `database/upgrade_to_v17_mysql55.sql` (MySQL 5.5; after the version 16 script if it was not imported yet).

| Request | Where in the app |
|---|---|
| Show the actual shift schedule (2, 4, 6, 8 logs), not the description, when assigning a shift | Every shift list (Employee Masterfile → Edit → *Shift schedule*, Masterfile Batch Edit → *Shift*, Employee Shift Entry, Batch Shift Define, Attendance Entry → *Change shift*) shows the code, the time coverage of every log and the rest days, e.g. *DAY-MF — 8:00 AM-12:00 PM, 1:00 PM-5:00 PM (rest Sun, Sat)*; a 6-log shift shows its three spans, a pre-arranged overtime adds *OT 5:00 PM-7:30 PM* (8 logs); a break without fixed times shows its length. |
| Type numbers in the date fields, on every form | All date fields of the system (forms, filters, grids, pop-ups): type **09192026**, **091926** or **0919** (this year) for 09/19/2026; *9/19/26*, *9-19-2026*, *2026-09-19* work too and the slashes come by themselves; **T** = today; the arrow keys or **+ / -** = one day later / earlier; the calendar icon (or Alt + Down) opens the calendar. A date that does not exist is outlined in red and blocks the save. The server still receives YYYY-MM-DD. Also fixed: the *None yet* line of the empty lists of the 201 form (children, education, previous employers) was squeezed into one narrow column. |
| Multiple selection of log files (Time in at Company A, Break out / in at Company B, Time out at Company C) | Attendance (AMS) → Import Attendance Logs → From file → **Log files**: choose one, two, three or more files at once; each file gets its own *Import configuration* and *Company / branch* (a file named *attlog* / *.dat* picks ZKTeco, *.csv* picks Generic CSV). The files with a log type are read first and the punches of a file without log type are placed by the shift code among the punches of the same employee-day from every file, so the three logs make one day. The message gives the result of each file; the list shows the files of the upload and Post logs posts them together. |
| Date from / Date to on Upload & Stage, by the established cut-off | From file → **Cut-off** (the open periods, the latest by default) fills **Date from** and **Date to**; only the logs of those dates are staged, the other lines of the file (a backup of the device not date filtered) are skipped and counted. At most 3 months at a time. |
| Rearrange the Import Configuration | 1) ZKTeco attlog.dat (tab), 2) Generic CSV (ID, Date, Time, Type), 3) Space-delimited (no punch type), in every Import configuration list. Import Log Configuration → **Display order** changes the position (new configurations come last). |
| View all the uploaded & staged biologs | The staged logs list shows **x–y of total**, with page numbers and **Show** 100 / 500 / 1,000 / 2,500 a page or **All**, and **Find** (employee name, no. or biometric ID). The Attendance entry format arranges every log of the selection (up to 20,000). |
| Close Attendance for the selected Company / Branch only | Attendance (AMS) → Attendance Transaction Closing: closing a Company / Branch (or group, type, department, ticked employees) closes only those employees; the others stay open: their biologs can be posted, their attendance viewed, corrected and summarized, and closed later. The lock of a day is now per employee (the employee's transaction closed, or the payroll of the period paid) instead of the whole period. Process DTR & summarize and Process Attendance keep working on the open employees of a period where some companies are closed. A Co. / Branch or other list changed on the closing screen applies at once (before, a branch picked without pressing Apply left the selection on All companies, so Close attendance closed every company), and the confirmation names the selection. |

### Additions in version 18

Database version 16: open the site once (it upgrades by itself) or import `database/upgrade_to_v16_mysql55.sql` (MySQL 5.5; after the version 15 script if it was not imported yet).

| Request | Where in the app |
|---|---|
| Masterfile Batch Edit: scroll bar for the Columns selection; all applicable fields of the masterfile | Personnel (PIS) → Masterfile Batch Edit → **Columns**: a panel with its own scroll bar, **Find a field**, **Select all / Default / Clear**, and every field of the 201 file in groups: Personal (name, suffix, sex, birth date, civil status, eyes, religion, weight, height), Contact & family (contact no., email, address, emergency contact, spouse, father, mother), Government numbers, Employment (employee no., biometric ID, dates, status, position, rank, job, immediate supervisor), Organization (company / branch, department, employee group / type, class group, rank category, classification, location), Pay & tax (pay group, rate type, basic rate, salary grade and step, days-per-year, voluntary Pag-IBIG, exemption code, MWE, solo parent, bank) and Attendance (shift, default day-off, time capture, exempt from time-keeping). 60 fields; the salary fields follow the Basic Salary rights. On a phone the panel opens at the bottom of the screen. |
| Final Pay: Cancel Calculation | Payroll System (PAY) → Final Pay → open a final pay → **Cancel calculation** (draft or approved): reason (required) and *The separation is cancelled too* (reinstates the employee). The final pay becomes **Cancelled** (kept with the reason, who and when; not released or paid), its run is cancelled and the pending transactions, benefits and loan schedules it included go back to the next payroll. A cancelled final pay offers **Undo cancellation** and **New calculation**. A released final pay cannot be cancelled. |
| Rearrange the Attendance (AMS) menu | AMS Policies & Configuration, Biometric Device Setup, Import Log Configuration, Periods & Cutoffs, Employee Leave Entry, Employee Shift Entry, Batch Shift Define, Web Check-in Verification, Overtime Authorizations, Import Attendance Logs, Import Review Queue, Raw Punch Logs, Attendance Exceptions, Process Attendance, Attendance Entry (logs), Excel / CSV Import, Auto-Generate Transactions, Attendance Summary Entry, Attendance Summary Entry II, Attendance Batch Transaction Entry, Daily Time Records, Attendance Transaction Closing (the User Manual follows the same order). |
| Rearrange the Process & Registers menu | Payroll Runs (Process), Payroll Batch Register, Payroll Batch Register II, Print Payslips, Bank File / Payroll ATM, Payroll Inquiry, Period Closing / Payroll Closing. |
| Shift schedule when pointing at the Shift Code | Point at a shift code (Attendance Entry SHF column and the shift beside the name, Daily Time Records, Employee Shift Entry, Batch Shift Define, Shift Table, the 201 file, My Profile, Dashboard): a popup shows the complete shift log by log: 2 logs (Time in, Time out), 4 logs (with Break out / Break in), 6 logs (two breaks), plus Overtime in / Overtime out of the pre-arranged overtime (up to 8 logs), with the working hours and the day-off. On a phone, tap the code. |
| Default day-off of the employee | The day-off follows one rule everywhere (attendance imported from a file, a database or the machine, the days without logs, Change shift, Batch Shift Define, Employee Shift Entry, the schedule CSV import): a shift with rest days keeps them (the embedded day-off wins); a 7-day shift takes the **default day-off of the Employee Masterfile**. What could tag a day-off as Absent: changing the shift of a date range (Attendance Entry → Change shift, Batch Shift Define with a shift that has rest days) saved every date as a working day, and a day-off loaded by the Employee CSV import did not recompute the days already processed. Now a per-date shift change keeps the day-off, the upgrade corrects the dates already saved that way, and the days computed before are recomputed when opened. Employee Shift Entry → **Day-off**: Follow the shift / default day-off, Rest day or Working day. A day-off or shift changed by the Employee CSV import now recomputes the open daily time records too. |

### Additions in version 17

Database version 15: open the site once (it upgrades by itself) or import `database/upgrade_to_v15_mysql55.sql` (MySQL 5.5; after the version 14 script if it was not imported yet).

| Request | Where in the app |
|---|---|
| Employment status Resigned, Terminated, Retired | Personnel (PIS) → Employee Masterfile → Edit → **Employment status**: Probationary, Regular, Project based, Contractual, **Resigned, Terminated, Retired**. Separating an employee sets it from the separation type (resignation → Resigned, retirement → Retired, termination / dismissal → Terminated) unless it is chosen; reinstating sets it back to Regular. Also in the masterfile filter, Masterlist report and import. Employees already separated are updated by the upgrade. |
| Work computed without a time out (2-log 11 AM – 11 PM shift) | The time out was a log after midnight (e.g. 12:58 AM of the next day), shown faded and with the AM/PM cut off. Attendance Entry now shows every time in full with AM/PM and marks a next-day log with **+1** (hover for the date). |
| Late of 13.90 hrs | Logs that fall entirely outside the shift (e.g. 12:54 AM – 5:52 AM after an 11 AM – 11 PM shift) are no longer late / undertime: the day is absent for the shift hours (*Logs outside the shift*) and the hours worked appear as overtime for approval. Late and undertime can never exceed the hours of the shift. |
| Attendance Summary Entry: cursor stays put when an employee is selected | Selecting an employee (and saving, filtering, paging) keeps the page and the lists at the same scroll position, also on Attendance Summary Entry II, Payroll Transaction Entry I and II and the other employee-list screens. |
| Fixed Transactions: multi-selection of codes | Payroll System (PAY) → Fixed Transaction Setup → Assign: tick **one, two or more codes**, each with its own amount and terms, for the employees picked. |
| Employee's Transactions: multi-selection of codes | Payroll System (PAY) → Employee's Transactions → New transaction: tick one, two or more earning / deduction codes, each with its own amount (and terms when more than one pay). |
| Employee Benefits: multi-selection of benefits | Payroll System (PAY) → Employee Benefits → **Enroll**: tick one, two or more benefits (grouped by category), each with its own amount and posting. |
| Payroll Transaction Entry I / II: keep the position of the forms | Selecting an employee keeps the position of the page and of the list (see *stays put* above). |
| Final Pay: edit or delete items | Payroll System (PAY) → Final Pay → open a final pay → Breakdown: **Edit** (description, quantity, amount) or **Delete** any item, **Undo** / **Restore** it, **+ Add item** (earning or deduction, taxable or not). The totals and net pay are recomputed; a released final pay is locked. |
| 13th Month parameters: Calendar year or Fiscal year | System → Payroll Parameters → **13th Month** tab: *Basis* **Calendar year** (January – December of the year) or **Fiscal year** (From / To dates from the previous year to the current year, exactly one year; a shorter or longer period is refused), include paid leaves, release deadline, and the computation in use. The fiscal basis totals the basic salary earned from the From date to the To date; the 13th month run and the 13th Month report follow it. |
| Offense table: separate Code column | Personnel (PIS) → Code of Conduct & Penalties: the **Code** is its own column before the Offense category. |
| No. of Days to reset Penalty | Personnel (PIS) → Code of Conduct & Penalties → Edit → **No. of Days to reset Penalty** (optional): when the last offense of the same code was more than this many days ago, the new one counts as a first offense. Blank = never resets. Also in the conduct import / export. |
| Employee Masterfile batch edit | Personnel (PIS) → **Masterfile Batch Edit** (also the *Batch edit* button of the Employee Masterfile): filters Company, Branch, Department, Employee group (plus status and search), rows = employees, columns = fields (choose the columns). Edit the cells, the changed ones are highlighted; **Save changes** validates and saves only those (each change is in the employee history and audit trail). |
| Employee portal calendar | My Workspace → **My Calendar** (also on the dashboard): month view with the schedule (shift / rest day), approved and pending leaves, overtime (requests and pre-arranged overtime of the shift), holidays and company events, plus the agenda of the month. HR keeps the events in Personnel (PIS) → **Company Events** (whole company, a branch or a department). |

### Additions in version 16

Database version 14: open the site once (it upgrades by itself) or import `database/upgrade_to_v14_mysql55.sql` (MySQL 5.5; after the version 13 script if it was not imported yet).

| Request | Where in the app |
|---|---|
| Incremental searching (Employee Masterfile, Leave Applications, Disciplinary Cases, Employee Leave Entry, Attendance Entry, Attendance Summary Entry, Employee's Transactions, Fixed Transaction Setup, New Loan/Payable) | **Search as you type**, no Apply needed: the Employee Masterfile *Search* and the *Find employee* of the attendance / payroll transaction screens refresh the list with every letter (the address keeps the filters). Every word typed must match (name, employee no., biometric ID): "rey mor" finds *Morilla, Rey*. Every **Employee** select of the system (filters and forms) is a search box: type part of the name or number, pick with the mouse or the arrow keys + Enter. Every multi-employee list is a **pick list**: search box, tick one, two or more, *Select all shown*, *Clear*, *Show selected only*, and the count picked. |
| Schedule Overrides: filters Co/Branch, Department, Employee Group, Employee Type and an Employee search | Attendance (AMS) → Employee Shift Entry (Schedule Overrides): filter bar **Co. / Branch, Department, Employee group, Employee type, Employee search** (one employee) and the date range. The *Employees* of *Assign schedule* follow the filters; the Employee search shows the overrides of that employee only and ticks the employee. Without filters every employee is listed and one, two, three or more can be ticked as before. Filters stay after saving. |
| Employee's Transactions: Co/Branch, Employee Group filters; incremental employee selection; filters kept after saving | Payroll System (PAY) → Employee's Transactions: **Co. / Branch, Department, Employee group, Employee type, Find employee**; the lists and the *Employees* pick list of *New transaction* follow them while typing. After saving (or deleting) a transaction the screen returns with the same filters. |
| Fixed Transactions: employee names, one / two / more employees per code, Co/Branch and Employee Group filters kept after saving | Payroll System (PAY) → Fixed Transaction Setup: same filters (Co. / Branch, Department, Employee group, Employee type, Find employee) for the list and the **Employees** pick list of *Assign a fixed transaction* (tick one, two or more for the code). The filters stay after Assign / Hold / Stop / Delete. |
| Rename to Employee Term Loan/Payables, New Loan/Payable, Create Loan/Payable; one menu for Subsidiary Ledgers and Employee Deductions & Loans | Payroll System (PAY) → **Employee Term Loan/Payables** (one menu item) with two tabs: *Loans & payables (subsidiary ledgers)* and *Employee deductions*. Buttons **+ New Loan/Payable** and **Create Loan/Payable**; the employee is found by typing. Permissions of both screens unchanged. |
| Print Payslips: Employee Group filter | Process & Registers → Print Payslips → **Employee group** (screen, print, PDF, reprint and the Selection line). |
| Bank File / Payroll ATM menu not working | It needed a payroll run from the run screen. From the menu it now opens on the latest computed / finalized run with a **Payroll run** picker, **Employer / branch** and **Employee group** filters, the credits, the downloads (.txt fixed width, CSV) and the employees paid in cash. |
| Loan & Ledger Balances: Employee Group and Co/Branch filters | Reports → Loan & Ledger Balances → **Co. / Branch** and **Employee group** (screen, print, CSV and the Selection line). |
| Employee Group Table: Active "No" | Company Parameters Setup → Employee Group Table → **Active: Yes / No**. A group set to *No* is no longer offered in the Employee group filters and selections of the reports and transactions; the employees already in it keep it (shown *(inactive)* on their 201 file). |
| Shift Table: Overtime In and Overtime Out (pre-arranged overtime) | Company Parameters Setup → Shift Table → **Overtime In / Overtime Out** (e.g. shift 8:00 AM – 5:00 PM, overtime 7:00 PM – 9:30 PM = 2.5 hours), for 2-log, 4-log and 6-log shifts. The employee logs OT In / OT Out after the time out; the overtime is **approved automatically**: late on Overtime In is deducted (2.5 hrs − minutes late), out before Overtime Out is deducted (2.5 hrs − undertime), a log after Overtime Out adds nothing. An employee who stays on without logging out gets the overtime from Overtime In to the time out, capped at Overtime Out. Attendance Entry shows **OT In, OT Out** and **Sched OT**; DTR correction has *Overtime In / Out*. The credited overtime flows to the attendance transaction as approved overtime. |
| Habitual tardiness: To-do's for NTE for the immediate superior and HR | Dashboard → **To-do's: Notice to Explain (NTE)**: the employees over *Habitual tardiness: times late per month* (or the monthly tardiness cap). HR (Create permission on Disciplinary Cases) sees every employee of their assignment with **Issue NTE** (a draft case with the late days written in) and **Dismiss** with a note; the immediate superior (supervisor or unit head) sees the members of the team. A to-do closes when a case is opened or the late days are corrected. |
| AUL Transaction Listing with frequency; also in the Periodical Reports | Reports → YTD Reports → **AUL Frequency Listing (times Absent, Late, Undertime)**: the number of times each employee was absent, late (beyond the grace period) and undertime, with days absent and minutes late / undertime, per payroll period (Detail) or per employee (Summary), same format and filters as the YTD Reports. Reports → Periodical Reports → **AUL Transaction Listing** and **AUL Frequency Listing** for the open payroll period selected. |

### Additions in version 15

No database change: version 15 runs on the version 13 database of version 14 (no upgrade script).

| Request | Where in the app |
|---|---|
| Logo placement options for the reports (copied from Memo Templates & Design) | System → System Settings → Company logo on reports → **Placement of the logo**: *Logo and company name, centered*, *Logo at the left, name beside it*, *Logo at the left most of the report*, *Logo at the right, name beside it*, *Logo at the right most of the report*. Applied to the letterhead of every ticked report and to the payslip. Memo Templates & Design → Design → Letterhead offers the same five placements (plus name only and none). |
| Payroll Computation form also for the first processing, in all payroll runs | Process & Registers → Payroll Runs → New payroll run → *After creating the run*: **Open the Payroll Computation form** (default) to choose the Employee / ID, Company / Branch and Department to process (one, two or more) and the options, or *Process every employee of the pay code now*. The form is the same for Process payroll (first run, *Process only*) and Process again (*Reprocess only*), for regular, 13th month and special runs. Each draft or computed run of the list has a **Process / Reprocess** button that opens it. |
| Reprocess Payroll on the Payroll Batch Register and Register II processes the selected filters | **Reprocess payroll** now opens the Payroll Computation form with the register's filters pre-selected and displayed: Department, HO / Branch, Employee group and Employee type, the employees found by Find, or the employee clicked in the list. Only those employees are processed; after Process the register opens again with the same filters. |

### Additions in version 14

Database version 13: open the site once (it upgrades by itself) or import `database/upgrade_to_v13_mysql55.sql`.

| Request | Where in the app |
|---|---|
| Earning or Deduction indicator of the fixed transactions | Payroll System (PAY) → Fixed Transaction Setup: the Code lists (filter and Assign form) are grouped into **Earnings** and **Deductions**, each code says *(Earning)* or *(Deduction, replaces SSS …)*, and the form says below the code whether it is added to or taken from the pay. The list keeps the colored Earning / Deduction text under each code. |
| "Hold" button, then "Resume" | Fixed Transaction Setup → **Hold** on an active fixed transaction: it stays on file but is left out of the earning / deduction computation of the payroll (the engine takes active ones only). The row is shaded *On hold since …* and the button becomes **Resume**, which applies it again from the next computation. Status filter *On hold*; Hold / Resume in the audit trail; the CSV import accepts the status *hold*. |
| Find filter also filters the employees of the Assign form | Fixed Transaction Setup: **Find** (and Department) of the filter narrow the *Employees* list of *Assign a fixed transaction* (with *Show all employees*), plus a *Type to narrow the list* box above the list. |
| Payroll run: Employee / ID, Company / Branch and Department filters | Process & Registers → payroll run → Process payroll / **Process again** → *Reprocess only*: **Employee / ID**, **Company / Branch** and **Department** lists (one, two or more of each, with Find and Clear). Only the matching employees are recomputed; the others keep their figures. Saved on the run and shown in *Last Payroll Computation*. |
| Memoranda / Announcements facility | Personnel (PIS) → **Memoranda / Announcements**: memoranda and announcements from the common **proforma** (blank memorandum / announcement) or a **sample format** of the usual occasions (holiday, suspension of work, pay date change, 13th month pay, tardiness reminder, dress code / ID, outing, Christmas party, annual physical exam, drill, welcome, promotion, farewell, new system notice). Rich text editor (bold, lists, alignment, headings, table, fields such as {company} {date} {to}), live preview, numbering MEMO-YYYY-0001 / ANN-YYYY-0001, saved with **who made it and the date created** (and who changed / issued it), Draft / Issued / Archived, **View, Copy, Print**. Personnel (PIS) → **Memo Templates & Design**: add / edit / deactivate templates, restore the samples, and the **customizable design** (font, size, line spacing, accent color, letterhead with logo, title style, paper, margins, footer, acknowledgment block, DRAFT watermark, number prefixes) with a live sample. My Workspace → **Memos & Announcements** lists the issued ones meant for everyone. Permissions: Memoranda / Announcements (Approve = issue) and Memo Templates & Design. |
| System Settings: Add Company Logo to Reports | System → System Settings → **Company logo on reports**: upload the logo (PNG / JPG / GIF / WEBP, resized), **Add Company Logo to Reports**, and the list of reports (Personnel, Attendance, Payroll, Reports) with **All reports**: one, several or all. Optional: without a logo or with the box off the reports keep the text letterhead. The logo prints above the company name of the ticked reports and on the payslip; the memos use it through their design. |
| Account type "Cash" | Employee Masterfile → Compensation → Account type **Cash** (also Employee's Bank Setup and the employee import). The net pay of a Cash employee is listed in the **Cash Payment Listing**, and left out of the bank file and the Bank Deposit Listing even with an account number. |
| Selected organizational unit shown in the form and the report (and all other reports) | Earnings & Deductions: *Selection: Organizational unit …, Company / Branch …, Code …* under the result (Summary per code and Per employee) and in the printed report header. Same *Selection* line on Periodical / Monthly / YTD Reports, Bank Deposit and Cash Payment Listings, Attendance Summary Report, Loan & Ledger Balances, 13th Month Report, Payroll Batch Register (I and II) and Print Payslips. |

### Additions in version 13

Database version 12: open the site once (it upgrades by itself) or import `database/upgrade_to_v12_mysql55.sql` (after the version 11 script if it was not imported yet).

| Request | Where in the app |
|---|---|
| Company / Branch Assignment for users: only the ticked company / branch / department is seen | System → User Access & Permissions → *Company / branch assignment* and *Department assignment*. The assignment now applies everywhere, not only to some lists: Employee Masterfile (list and CSV), 201 files (opened by address too: an employee outside the assignment gives *Employee outside your assignment*), 201 File Masterlisting and every employee list or report (DTR, logs, exceptions, schedules, leave, cases, evaluations, ledgers, deductions, benefits, transactions, retro, final pay, registers, payslips, bank file, remittances, YTD, 13th month, monthly and branch cost reports, dashboard counts), the employee choices of the forms, the CSV imports (a row of an employee outside the assignment is refused) and the HR step of Pending Approvals. Companies only = their employees in every department; departments only = those departments (and units under them) in every company; both = the departments inside the companies. A new or edited employee must stay inside the assignment. |
| If none is ticked, the user cannot see the employees | Nothing ticked in both panels = no employee (lists say so). **All companies / branches** and **All departments** give the whole side, including companies and departments added later. *Employees this user sees* shows the result while ticking; the user list marks *no employee assigned*; User Accounts reminds you after creating such an account. The Super Admin role sees everyone; the employee linked to an account still opens his or her own records. Upgrade: accounts that had nothing ticked (all employees under the old rule) received both *All* boxes, so nobody loses access. |
| Basic Salary View / Edit permission | Role Management and User Access & Permissions → Personnel (PIS) → **Basic Salary (employee basic rate)**: View and Edit. Without View the basic rate is hidden on the Employee Masterfile list and export, the 201 file (basic and equivalent rates, rate column of the employment history, Compensation tab), the edit form (*Hidden*; salary steps without amounts), the 201 File Masterlisting columns, the employee import preview and template example, and the audit trail details; the Salary Scaling Simulator cannot be opened. Without Edit the rate is read only (or hidden) and kept when the other details are saved; the employee import ignores `basic_rate`; applying an evaluation salary step / merit increase is refused. Every other detail of the employee can still be seen, changed and saved. After the upgrade every role keeps what it had: roles that could view the employees can view the salary, roles that could edit them can edit it (HR Officer: View and Edit, Payroll Officer: View). |

### Additions in version 12

Database version 11: open the site once (it upgrades by itself) or import `database/upgrade_to_v11_mysql55.sql`.

| Request | Where in the app |
|---|---|
| Add or Edit the GL Account from the form | Fixed Transaction Code Setup, Transaction Table (earnings and deductions), Benefits Table and Payroll Parameters (GL accounts of the payroll journal): the GL account list has **New** and **Edit** buttons. They open a small form (account code, title, type, active) over the page; the account is saved in the Payroll Code Table at once, checked first (code required and unique, title, type), and the list is refreshed with the account selected, without leaving or losing the form being encoded. A corrected account code is carried to every code and Payroll Parameters setting that used the old one. The buttons follow the Create / Edit permissions of the Payroll Code Table; the Payroll Code Table itself now refuses to delete an account still in use. |
| Default day-off of the employee | Personnel (PIS) → Employee Masterfile → New / Edit → Attendance → **Default day-off (weekly)**: Sunday to Saturday, optional and flexible (none, one, two, three or more days). Shown on the 201 file, in the Employee 201 File Masterlisting (column *Default day-off*) and in the employee CSV import (column `day_off`, e.g. `Sat, Sun` or `0,6`). |
| Shift with 7 days work marks the default day-off | A 7-day shift is a shift with *Whole-week duty* or without rest days in the Shift Table. On such a shift the employee's default day-off weekdays are rest days in the daily time records, leave and payroll computations. **Batch Shift Define**: assigning a 7-day shift saves the dates of each employee's default day-off as day-off (*Keep each employee's default day-off*, ticked by default; the employee list shows the day-off). Changing the day-off or the shift of an employee re-computes the open daily time records. Shifts with their own rest days keep them. |
| Unless removed through Schedule Override | Attendance (AMS) → Employee Shift Entry (Schedule Overrides): a date saved with *Mark as rest day* unticked is a working day even on the default day-off (*No, works* in the list); removing the override brings the day-off back. |

### Additions in version 11

No database change: version 11 runs on the version 10 database (no upgrade script).

| Request | Where in the app |
|---|---|
| CSV import facility visible on the form itself, checking the data first before importing | An **Import from CSV** panel under the page title of each screen: **Download format** (and *Current data (CSV)* or *Format with the employees of the period* where useful), **Columns of the format** (what each column takes, required columns marked), the file and the options of the screen, then **Load and check**. Nothing is written on loading: every row is checked against the rules of the screen and shown on an editable grid with its status (New, Update, Replace, Keep, Skip, Error) and the reason of each error, the wrong cell in red. Correct the cells or tick Remove, **Check data** again, **Error report (CSV)** for the rows in error; **Save to database** is available only when every row is valid, re-checks the rows and saves all of them or none (recorded in the import batches and the audit trail). **Discard** drops the file. The panel is shown to users with the new **Import** permission of the screen (Role Management); the built-in HR and Payroll roles have it on their screens. |
| Company Parameters Setup imports | Company Table, Department Table (parent unit in the table or in the same file, loops refused, unit head by employee no.), Location Table, Job Table, Shift Table, Code of Conduct & Penalties (one row per offense number of an offense code; the penalty steps of the file replace the old ones), Transaction Table (earning and deduction codes), Benefits Table, Fixed Transaction Table, SSS Table (matched on effective date and range from), Health Table / PhilHealth (effective date), Lates / Undertime Table (from minutes). A row whose key exists updates the record (blank cells keep the current value) when *Update existing records* is ticked. |
| Payroll System (PAY) imports | Payroll Transaction (also Payroll Transaction II and Payroll Batch Transaction; closed attendance transactions only — an open one is reported on the check), Employee's Transactions, Fixed Transaction Setup, **Transaction Payment** (ledger no., or the ledger type when the employee has one open ledger; each payment checked against the running balance), Employee Deductions & Loans, Employee Benefits, **Retroactive Adjustments** (old and new rate with the effective date; the differential is computed as on the screen and the masterfile updated when asked), **YTD Info (Previous Employer)** (one row per employee and year). |
| Personnel (PIS) imports | Leave Balances & Accrual **manual adjustment** (employee, leave type, days with a minus sign for a deduction, reason; added to or replacing the adjustment on the balance of the year; closed years refused), Competencies & Merit Matrix, Code of Conduct & Penalties. |
| Attendance (AMS) imports | **Employee Shift Entry** (employee, date or date range, shift code or rest day, work location; past dates are re-processed into the DTR), **Attendance Summary Entry** (also Summary Entry II and Batch Transaction Entry; open transactions only). |
| Attendance (AMS) menu sequence | AMS Policies & Configuration, Biometric Device Setup, Import Log Configuration, Employee Leave Entry, Employee Shift Entry, Batch Shift Define, Periods & Cutoffs, Import Attendance Logs, Process Attendance, Attendance Entry (logs), Attendance Summary Entry, Attendance Summary Entry II, Attendance Batch Transaction Entry, Daily Time Records, Attendance Exceptions, Raw Punch Logs, Web Check-in Verification, Overtime Authorizations, Import Review Queue, Auto-Generate Transactions, Excel / CSV Import, Attendance Transaction Closing. |
| Second menu sequence (the picture shows the Payroll System (PAY) menu) | Period Parameters, Employee's Transactions, Fixed Transaction Setup, Employee Deductions & Loans, Employee Benefits, Transaction Payment, Subsidiary Ledgers, Payroll Transaction, Payroll Transaction II, Payroll Batch Transaction, Retroactive Adjustments, YTD Info (Previous Employer), Final Pay. |
| User Manual | New article *Import from CSV on the forms* (Getting started) with a screenshot; every screen with the panel says so in its article. |

### Additions in version 10

| Request | Where in the app |
|---|---|
| Super Admin sign out not responding | The sign-out icon of the platform pages (Super Admin Dashboard, Companies …) now signs the Super Admin out. Before, the request was sent back to the dashboard because a Super Admin has no company account. |
| "The device rejected the communication key" although the terminal has no password | The built-in ZKTeco client was rewritten after the SDK protocol: the communication key is now scrambled exactly as zkemkeeper.dll does (the last step used the wrong bytes, so even key 0 was refused by terminals that ask for it), every request carries the next reply number, and the logs and users are read with the buffered commands of newer firmwares (8, 16 and 40-byte log records; 28 and 72-byte user records). Tested with UDP and TCP, with and without a key. The error now says that the key is the terminal's **Comm Key** (Menu, COMM., Comm Key), not its administrator password. |
| Use zkemkeeper.dll to connect with the ZKTeco terminal | Attendance (AMS) → Biometric Device Setup → **Connection method**: *Built-in ZKTeco protocol* (no DLL, any server), *ZKTeco SDK zkemkeeper.dll* (PHP's COM extension on a Windows server, class CZKEM {00853A19-BD51-419B-9269-2DABE57EB61F}) or *Office PC agent* (for an HRIS on the internet: a Windows PC of the office reads the terminal with zkemkeeper.dll and sends the logs over HTTPS with a device token; **Generate token**, **Download the agent package (.zip)**, logs received at `index.php?p=bio_push`). The package and `tools/zkteco/` contain `zk_agent.ps1` / `zk_agent.bat` (32-bit PowerShell), `register_sdk.bat` (copies the SDK DLLs to SysWOW64 and runs regsvr32), `zk_64bit_php.reg` (64-bit PHP / XAMPP), `README.txt` and your `sdk/zkemkeeper.dll`. `tools/zk_download.php` downloads the terminals on a schedule (cron / Task Scheduler). The zkemkeeper.dll you sent (6.0.3.3, 32-bit) loads **zkemsdk.dll**, which loads the communication DLLs of the SDK: copy them from your programmer's HRIS folder (or the ZKTeco Standalone SDK) into `tools/zkteco/sdk`. |
| Employee 201 File Masterlisting | Reports → **Employee 201 File Masterlisting** (also a button on the Employee Masterfile). Default columns Employee ID / Code, Name, Date Hired, Position and Basic Salary. **Customize columns**: every field of the employee information (83 fields: identification, personal information, family background with children and birthdays, education and previous employers, government numbers, employment, compensation including monthly / daily / hourly rate, attendance settings) can be added, removed and ordered. Filters (company / branch, unit, type, group, class group, employment status, status, hiring dates, find), sort, group by with head counts and totals, saved layouts (shared, one default), print on the letterhead and CSV. |

### Additions in version 9

| Request | Where in the app |
|---|---|
| Payroll Batch Register II (the Others in detail) | Process & Registers → **Payroll Batch Register II**: every earning and every deduction consolidated in *Others* has its own column (COLA, allowances, de minimis, company loans, union dues …), with the same filters, row selection, print (legal landscape) and CSV as the Batch Register. The detail columns add up to the Others of the Batch Register. |
| Payroll Inquiry: view subsidiary without leaving the form | Process & Registers → Payroll Inquiry → Loans & accountabilities → **View subsidiary** on each ledger (loan, cash advance, payable, accrued benefit) and each deduction with terms: transaction date, total payable, payments applied, balance, amortization, the ledger entries and the projected schedule open over the inquiry; Close returns to it. |
| Projected amortization schedule from the transaction date | Subsidiary Ledgers → ledger, Payroll Inquiry and the printed ledger: the schedule starts at the start (transaction) date of the loan or payable, follows the pay periods of the employee pay code and its usual pay day, and marks the payroll dates already covered by the payments as **Paid**. Backward entries and migrated loans show their whole schedule, not one starting today. |
| Employee Masterfile additions | Personnel (PIS) → Employee Masterfile → New / Edit: **Physical and other personal data** (color of eyes, religion, weight, height), **Family background** (spouse name, address and contact no., father, mother, children with their birthdays), **Educational background** (level, school, course, year graduated) and **Employment history (previous employers)**. Shown on the 201 file tab **Family & Background**. |
| Earnings & Deductions report | Reports → **Earnings & Deductions**: Earnings and deductions / Earnings only / Deductions only; per employee (with the source: Fixed Transaction, Employee's Transactions, Employee Deductions & Loans, Employee Benefits) or a summary per code; salary items (basic, overtime, absences, lates and undertime, night premium, paid leaves) excluded; run or date range, company / branch, organizational unit, code; print and CSV. |
| Bank deposit and cash payment listings | Reports → **Bank Deposit Listing** and **Cash Payment Listing**: net pay per employee of a payroll run, by company, branch, division, department or employee type, with account numbers (bank) or a signature column (cash). |
| Monthly Reports | Reports → **Monthly Reports**: SSS / ECC (EE, ER, ECC), Pag-IBIG (EE, ER), PhilHealth (EE, ER), SSS Loan, Pag-IBIG Loan and Withholding Tax reports of a month, by company / branch, organizational unit, employee group or class group. Only closed payroll periods are counted, and the employer share is computed only when every period of the month is closed (the report names the periods still open). |
| Periodical Reports | Reports → **Periodical Reports**: the same reports for an open payroll period with the employee deductions only (no employer share), plus deductions and earnings per employee (other than the salary items) and the bank and cash listings. Closed periods are reported in the Monthly Reports. |
| YTD Reports | Reports → **YTD Reports**: per payroll period and per employee over a date coverage: Payroll Register, SSS / ECC, Pag-IBIG, PhilHealth, SSS Loans, Pag-IBIG Loans, Withholding Tax, Deductions, Earnings, Payroll Transaction (hours), AUL (absent, undertime, late) and the BIR 2316 / alphalist link; detail or summary. |
| YTD Information for Previous Employer | Payroll System (PAY) → **YTD Info (Previous Employer)**: company, employee group, employee and year; employer name and TIN, period, YTD gross, basic, SSS, PhilHealth, HDMF, 13th month and other benefits, de minimis, non-tax, taxable, tax and the other figures. The taxable and the tax are added to the annualized (year-end) tax, BIR 2316 (lines 22, 25B and Part III) and the alphalist. |
| AMS: Process Attendance with Replace Manual Entry | Attendance (AMS) → **Process Attendance**: pay code, open period, company code, department, group, type; all employees of the selection or the ones ticked (one or many); **Replace Manual Entry** ticked replaces the manually encoded transactions by the processed biometric attendance, unticked keeps them. Closed transactions are never changed. |
| A user who toggles between 2 companies without being a Super Admin | Super Admin → **Multi-company Users**: choose the home company and account, the other company, its role there (roles with every permission are not offered) and optionally its employee record. The user signs in once and switches company with the selector in the top bar. In each company the user has only that company's role and permissions; the linked account is listed on that company's User Accounts (*Linked from …*), has no password of its own and cannot sign in directly. Deactivate / Remove stop the access at the next click; every switch is audited in both companies and the platform. |

### Additions in version 8

| Request | Where in the app |
|---|---|
| Edit option for Employee's Transactions with terms | Payroll System (PAY) → Employee's Transactions → **Edit** on a transaction with terms (also on its ledger screen). Nothing taken yet: every field, the schedule is rebuilt. Installments taken: the remaining balance is spread again over the remaining paydays from the date given. |
| SSS Calamity Loan apart from SSS Loan on the payslip | Payslip (screen, PDF, batch print): its own **SSS Calamity Loan** row (code SSS-CL / TTD_SSS-CL, an SSS calamity description or an SSS calamity loan ledger); SSS Loan no longer includes it. |
| Period Closing / Payroll Closing as the last, separate procedure | Process & Registers → **Period Closing / Payroll Closing**: backup warning and database backup download, Pay Code, Period to Close, Company / Branch, Employee Group, Employee Type, Status, Exit / Close, closed periods and a printable closing report. Closing posts the salaries, payments, leaves, government deductions, taxes and the employee ledgers (loans, installments, 13th month), and locks the period as paid. *Finalize* on a run now only approves the figures and releases the payslips; the next payroll of a pay code is processed after the previous period is closed. |
| Other parameters than the company code | Period Closing: Pay Code, Period, Company / Branch, Employee Group and Employee Type (partial closings are allowed; the period locks when everything is posted). |
| Edit Subsidiary Ledger entries | Subsidiary Ledgers → ledger → **Edit** / **Delete entry** on each entry with the reason; running balance, balance and status are recomputed; who, when and why are kept and audited. |
| Period Parameters under Payroll System (PAY) | Moved from System to **Payroll System (PAY) → Period Parameters**. |
| Only open payroll periods on the attendance screens | Attendance Summary Entry, Entry II, Batch Transaction Entry, Attendance Transaction Closing, Attendance Entry, Auto-Generate, Leave / Shift entry, Attendance Exceptions and the attendance CSV import no longer offer closed / paid periods. |
| Print the employee ledger | Subsidiary Ledgers → **Print employee ledger** (choose the employee), **Print** on a ledger row or a ledger, and **Print my ledgers** in My Workspace: employee no. and name, ledger type, ledger details, entries with running balance, totals and signatories. |
| Dynamic roles and permissions (narrative) | System → **Role Management** (create, edit, deactivate, delete, reassign users; permissions page by page and action by action: View, Create, Edit, Delete, Approve, Reject, Import, Export, Print, Generate, Configure, generated from the real pages of the system). System → **User Accounts** (basic information, role, account status, Use role defaults / Customize access with Inherit, Allow, Deny, Reset to role defaults, **Effective access**). System → **User Access & Permissions** (the same per user, plus company and department assignment). The menu, the dashboard, buttons, forms, links and exports follow the effective permissions, and `index.php` enforces the same permissions on the server for every page, form, export, print and AJAX request (403 Unauthorized access). The System Administrator role is the protected Super Admin role; at least one active Super Admin always remains. Every role and permission change is in the audit trail. |

### Additions in version 7

| Request | Where in the app |
|---|---|
| Close Attendance Entry, even when the attendance was encoded by hand | Attendance (AMS) → Attendance Summary Entry: **Close attendance entry** / **Re-open entry** for the employee shown. Attendance Summary Entry II: **Close attendance entry** closes every open transaction listed with the filters. A closed transaction is read-only on the attendance side. |
| Stand-alone Attendance Transaction Closing form | Attendance (AMS) → **Attendance Transaction Closing**: period, Co. / Branch, Employee Group, Employee Type (and department / name), counters, optional row selection, *Exceptions reviewed*, **Close attendance** and **Re-open**. Summarized and manually encoded transactions close the same way. |
| Only closed attendance appears in the Payroll Transaction | Payroll Transaction, Payroll Transaction II and Payroll Batch Transaction list only closed transactions (the pay master may still correct them until the payroll is finalized). The payroll run pays only closed transactions; an employee still open gets the blocking exception *Attendance not closed*. When every transaction of a period is closed the period is *Closed for payroll*. |
| Payroll Computation form during the Payroll Run | Payroll run → **Process payroll**: Company / Branch, Employee Group, Employee Type, Pay Code, Period, Tax Table to use, Annualization, Regular Processing, 13th Month, Periodical Calculation (not used), Processing Type Batch / Selection with Employee ID From–To, Capture Process (not used), the note on regular processing, **Exit**, **Process** and **View errors**. The parameters are saved with the run (*Last Payroll Computation*). |
| Others of the register split into its details | Payroll Batch Register: double-click the **Others** header of the earnings or of the deductions to show one column per item (e.g. Position Allowance, Meal Subsidy); double-click again to fold. Print register and Export CSV follow. |
| Fixed Transaction Setup: start date, amount and terms only | Payroll System (PAY) → Fixed Transaction Setup: terms every pay, 1st, 2nd, 3rd or 4th pay; a start date and no end date (it applies while the employee works; Stop ends it). Also in Fixed Transaction Table defaults and the CSV import. |
| Module access listed in detail per user | System → User Access & Assignment → **Chosen pages and actions**: a grid of every page of the role by module with View, Add, Edit, Delete, Print / Export and Approve. Enforced on every page and action. |
| Emp. Setup renamed | Now **Company Parameters Setup** (menu, hub page, manual). |
| Delete cancelled payroll runs | Payroll Runs and the run screen: **Delete** on a cancelled run removes it for good (refused when it has ledger postings). |

### Additions in version 4

| Request | Where in the app |
|---|---|
| Back from Raw Punch Logs to the employee's Attendance Entry | Attendance Entry (logs) → View raw log opens Raw Punch Logs with **Back to Attendance Entry (logs)**; opened from the menu for one employee it offers *Attendance Entry of this employee*. |
| Correct the type of a wrongly classified log | Raw Punch Logs and Daily Time Records → Edit (Raw punches): a **Type** box on every punch (In, Break out, Break in, Out). The day is recomputed and the original type is kept (*corrected, read as Out*). |
| Full view of the hours matrices | Payroll / Attendance Summary Entry: every amount cell fits its value; the two matrices stack when the card is narrow. |
| Placement of the logs (break out not shown) | Punches without break types are placed by position following the shift code's logs per day: In, Break out, Break in, Out (4 logs); In, Out (2); six for 6-log shifts or the six-log policy. Days computed by the old engine are rebuilt when shown. |
| Log type map empty → shift code decides | Import Log Configuration: a blank map types the punches by the employee's shift (2, 4 or 6 logs) as they are staged or imported. |
| AMS policy: logs inside the interval are invalid | `# of minutes between LOGS w/ same TYPE as INVALID` drops a repeated log of the same type within the interval (version 5: logs of different types always count); Raw Punch Logs and the day screen mark them *Not counted*. |
| Payroll Register: select an employee, open the screens for them | Click a row of the register; RePrint payslip, Open payroll transaction, Open emp. transaction, Open fixed transaction, Open trans. payment, Payroll run and Payroll inquiry open already on that employee, each with **Back to Payroll Batch Register**. |
| Drill-down of Cur. Taxable Income | Payslip → the *Cur. Taxable Income* figure opens *Current taxable income, how it was computed*: taxable and non-taxable earnings, contributions subtracted, BIR table, bracket and tax computation. |
| Back to Payroll Transaction from the DTR | Payroll Transaction → Log details (DTR) shows **Back to Payroll Transaction** (only when opened from there). |
| CSV imports with Check data and correction | **Import CSV** on Payroll Transaction (and II, Batch), Attendance Batch Transaction Entry, Employee's Transactions, Fixed Transaction Setup, Employee Deductions and Employee Benefits: format download (also pre-filled with the employees of the period), Check data with every error marked, on-screen correction or error report, Save to database only when clean; period transactions can keep, replace the listed, or replace every entry of the period. |
| Manner of posting of benefits | Employee Benefits: 1st, 2nd, 3rd, 4th pay or every pay per enrollment (enroll form, list, import). |
| Multi-location pay: separate payslips, Set Deduction Source (pink) | Multi-Location Entry (from Payroll Transaction, Payroll Batch Transaction and **Attendance Batch Transaction Entry**): add locations, select a row, **Set Deduction Source** → the row turns pink and that location carries the non-hour earnings and every deduction; its payslip shows the deductions and net pay, the other location payslips are paid in full. Print Payslips → *Separate payslip per company / branch / division*. |

Also fixed in version 4: links that carried the page filters (for example *Attendance entry* from
Payroll Transaction, *Multi loc.* on the batch grids, *Transaction II* / *Batch entry*) now open the
screen they name instead of reloading the current one.

## 6. Typical monthly flow

1. **Attendance**: import biometric/Excel logs, or let staff clock in on the web → Attendance → Cutoffs & Summaries → *New cutoff* → *Process DTR & summarize* (or encode the summaries by hand) → fix exceptions (missing punches, unauthorized OT, geofence) → **Attendance Transaction Closing** (or *Close attendance* on the cutoff).
2. **Payroll**: Process & Registers → Payroll Runs → *New payroll run* for the pay group and period → *Process payroll* (Payroll Computation form) → review exceptions and adjustments → *Finalize* (posts ledgers, accrues 13th month, locks the cutoff) → payslips, bank file, reports.
3. **Remittances**: Reports → Government Remittances to export the SSS, PhilHealth, Pag-IBIG and BIR files.
4. **Leave year-end**: Personnel → Leave Balances & Accrual → *Year-end* (carry-over, cash conversion, forfeiture).

No cron jobs are needed. Leave accruals, year-end processing and resending approval emails are done from buttons in the app.

## 7. Statutory data

The seeded tables reflect the rules published for **2026** (TRAIN law withholding tax table effective 2023; SSS 15% with the ₱5,000–₱35,000 MSC range including MPF; PhilHealth 5% on ₱10,000–₱100,000 split 50/50; Pag-IBIG 2% with the ₱10,000 compensation cap; 2026 regular and special holidays; ₱90,000 13th-month and other-benefits exemption and the current de minimis ceilings). **They are data, not code**: when agencies issue new rates, add rows with a new *effective from* date and the old periods keep computing correctly. Verify the tables against the latest BIR, SSS, PhilHealth and Pag-IBIG circulars before going live.

## 8. Security notes

* Passwords are hashed with `password_hash()`; new accounts must change the password on first sign-in.
* All forms carry CSRF tokens; all SQL uses PDO prepared statements; output is escaped.
* Access is decided by permissions (`app/permissions.php`): each role gives default permissions (`page.action` keys such as `employees.create`), a user may have Allow / Deny overrides, and the effective permissions are checked on the server for every request in `index.php` (`perm_authorize()`), then used to leave out of the page the links, buttons and forms the user may not use. Built-in roles: **System Administrator** (Super Admin), **HR Officer**, **Payroll Officer**, **Supervisor / Dept. Head**, **Employee**; any number of custom roles can be added in Role Management. Supervisors only see their own team.
* Uploaded files are stored outside direct web access (`uploads/.htaccess` denies everything) and served through an authorization check (`index.php?p=file`).
* `app/`, `config/`, `database/`, `pages/` are blocked by `.htaccess`. On Nginx, deny those paths in the server block.
* Every create, update, approval and payroll action is written to the audit trail.
* Set `'debug' => false` in `config/config.php` in production and serve the site over HTTPS.

## 9. Notes and limits

* **Facial capture** is a live selfie taken in the browser (upload from the gallery is not allowed). Where the browser supports the `FaceDetector` API it checks that a face is present. There is no third-party face matching; HR verifies the photos in *Web Check-in Verification*.
* **Bank file**: the fixed-width layout is a generic template. Adjust `pages/payroll/bank_file.php` to your bank's required format.
* **PDF payslips** are made by a small built-in PDF writer (`app/pdf.php`); no external library is needed.
* **Excel import** reads `.xlsx` with PHP's `ZipArchive`. Save old `.xls` files as `.xlsx` or `.csv` first.

## 10. Folder structure

```
ihris/
├── index.php              Front controller (index.php?p=<route>)
├── config/config.php      Database credentials, app URL, time zone
├── database/
│   ├── ihris_mysql55.sql       Clean database: 63 tables + reference data + admin account
│   │                           (also the template every new company database is built from)
│   ├── ihris_mysql55_demo.sql  Same, plus the sample company
│   ├── ihris_master_mysql55.sql  Multi-company master database (only if the app cannot create it)
│   └── upgrade_to_v2 … v10     Upgrade scripts (optional, the app upgrades itself)
├── app/
│   ├── bootstrap.php, db.php, helpers.php, auth.php, layout.php, crud.php, routes.php
│   ├── tenancy.php        Multi-company: master database, company resolution, sign-in map
│   ├── permissions.php    Roles, permission catalog, user overrides, effective permissions, server gate
│   ├── access_ui.php      Permission grids of Role Management, User Accounts and User Access
│   ├── mailer.php         SMTP / mail() / log
│   ├── pdf.php            Payslip PDF writer
│   ├── xlsx.php           .xlsx reader
│   ├── manual.php         User Manual engine (lookup, route mapping, search)
│   ├── manual_content.php User Manual articles — the whole manual, in one file
│   ├── setup_help.php     Page shown when the database is not ready
│   └── engine/            Business logic: org, statutory, leave, attendance, discipline,
│                          evaluation, ledger, payroll, retro, employee, imports,
│                          closing (Period Closing), provision (creates company databases)
├── tools/
│   ├── zk_download.php    Scheduled download of the ZKTeco terminals (cron / Task Scheduler)
│   └── zkteco/            Office PC agent and ZKTeco SDK setup (zkemkeeper.dll), see README.txt
├── pages/
│   ├── pis/               Personnel module pages
│   ├── ams/               Attendance module pages
│   ├── payroll/           Payroll module pages
│   ├── reports/           Reports
│   ├── admin/             Users, settings, audit trail, budgets, email outbox
│   ├── company/           Company Owner: Company Profile & Code, Join Requests
│   ├── super/             Super Admin: dashboard, companies, create company, logs, accounts
│   ├── register.php       Join a company with its Company Code
│   ├── ess/               Employee self-service (clock, leaves, DTR, OT, payslips…)
│   └── manual/            User Manual: index.php (browse + search), topic.php (article)
├── assets/css/app.css, assets/js/app.js
└── uploads/               documents, photos, selfies, imports, cases (not web-accessible);
                           Company 1 uses uploads/, company N uses uploads/company_N/
```

## 11. User Manual

The system documents itself. **Main → User Manual** opens a manual with one article for every
page of every module, grouped into nine sections (Getting started, My Workspace, Personnel,
Attendance, Pay transactions, Payroll processing, Reports, Setup tables, System administration).

Each article says what the screen is for, who may open it, where it sits in the menu, the steps
to do the work, what every field and button means, the rules the system enforces, what the screen
prints or exports, and the mistakes that are easy to make — plus links to related articles.

* **Screenshots.** Every article shows a picture of the actual screen it describes, taken from a
  running system. Click it, or *Open full screenshot*, for the full-size image.

* **Search.** The box at the top of the manual searches titles, summaries, steps, field names and
  keywords, and returns a highlighted extract of each hit. While browsing without a query, the
  same box filters the articles on screen as you type.
* **Help on every page.** The **Help** button in the top bar opens the article for the page you
  are looking at (`index.php?p=manual_topic&t=<route>`).
* **Everyone can read it.** Any signed-in user sees the whole manual. Articles for pages their role
  cannot open say so and hide the *Open this page* button; actual access rights are unchanged.

### Editing the manual

All the text lives in **`app/manual_content.php`** — plain PHP arrays, no database table and no
cache. To correct a sentence, edit it and save. To document a new screen, copy an existing block:

```php
[
'slug'    => 'my_new_page',          // also the route name, when they match
'section' => 'pis',                  // one of the keys in 'sections' at the top of the file
'title'   => 'My New Page',
'summary' => 'One sentence, shown in the list and in search results.',
'roles'   => ['hr'],                 // 'all', 'admin', or a list of roles
'menu'    => 'Personnel (PIS) > My New Page',
'purpose' => 'A short paragraph.',
'steps'   => ['First…', 'Then…'],
'fields'  => [['Field name', 'What it means']],
'rules'   => ['What the system refuses to do.'],
'tips'    => ['What is easy to get wrong.'],
'related' => ['employees'],
'keywords'=> 'extra words the search should match',
],
```

`app/manual.php` links articles to routes, so the Help button finds the right one automatically.
The header comment of `app/manual_content.php` lists every key an article accepts.

### Replacing a screenshot

The pictures live in **`assets/manual/`**, one PNG per route: `assets/manual/employees.png` is the
screenshot shown on the *Employee Masterfile* article. An article picks up the file named after its
route by itself, so to refresh a picture you only replace the file — open the page, take a
1440-pixel-wide screenshot of the whole browser window and save it over the old one.

Two optional keys change that: `'shot' => 'dashboard'` makes an article borrow another page's
picture (used by the overview articles, which have no screen of their own), `'shot_caption' => '…'`
writes the caption under it, and `'shot' => false` leaves an article without a picture.

The screenshots that ship with the system were taken against the sample company in
`database/ihris_mysql55_demo.sql`, so the names and amounts in them are demo data. Replace them with
screenshots of your own installation if you would rather the manual showed your own company.

## 12. Multi-company (one application, one database per company)

```
                         G-SMaRT HRIS (one codebase)
                                  │
                   MASTER DATABASE  ihris_master
          companies · Company Codes · sign-in map · Super Admins · logs
                                  │
        ┌─────────────────────────┼─────────────────────────┐
    Company 1                 Company 2                 Company 3
    hris_web (existing)       ihris_company_002         ihris_company_003
    employees, users,         employees, users,         employees, users,
    attendance, payroll…      attendance, payroll…      attendance, payroll…
```

**What changes on the first page load.** The master database `ihris_master` is created, the database
named in `config/config.php` (`hris_web`) is registered **in place** as Company 1 (nothing is copied,
moved or changed in it), it receives a Company Code, its user accounts are mapped for sign-in, and a
first Super Admin is created: **`superadmin` / `SuperAdmin#2026`**, to be changed at the first sign-in.
Existing users keep signing in exactly as before. If the master database cannot be created, the system
keeps running as one company and administrators see a notice on the dashboard.

**Configuration.** Nothing has to change in `config/config.php`. These optional keys can be added there
(or to `config/config.local.php`); the values shown are the defaults:

```php
'multi_company'        => true,            // false = run as a single company, as before
'master_db_name'       => 'ihris_master',  // platform database
'company_db_prefix'    => 'ihris_company_',// new company databases: ihris_company_002, _003…
'super_admin_username' => 'superadmin',    // first Super Admin (only used when none exists)
'super_admin_password' => 'SuperAdmin#2026',
```

All databases live on the MySQL server of `config/config.php` and use its account; no password is
stored per company, and no credential appears in a page, a link, a session or the Company Code.

**MySQL rights.** The account needs every right on the master database and on the company databases
(CREATE DATABASE for new companies, CREATE / ALTER / INDEX / REFERENCES / INSERT / UPDATE / DELETE /
SELECT inside them). As MySQL root:

```sql
GRANT ALL PRIVILEGES ON `ihris\_company\_%`.* TO 'your_mysql_user'@'localhost';
GRANT ALL PRIVILEGES ON `ihris_master`.* TO 'your_mysql_user'@'localhost';
FLUSH PRIVILEGES;
```

With `root` on XAMPP nothing is needed. When the account may not create databases at all, import
`database/ihris_master_mysql55.sql` as root and grant the rights above; the Super Admin dashboard shows
whether company creation will work.

**Roles.** Above the unchanged company roles (admin, hr, payroll, supervisor, employee):

* **Super Admin** (master database): Super Admin Dashboard, Companies, Create Company, company details
  (information, activate / deactivate, Company Owner, Company Code, database check / upgrade, users,
  provisioning log), Multi-company Users (users who switch between companies, see version 9), Audit & Provisioning
  Logs, Super Admin Accounts. **Enter company** opens a company
  under a hidden support account of that company (an administrator, recorded in its own audit trail);
  an orange bar names the company until **Leave company**. Existing admins never become Super Admins.
* **Company Owner** (one per company, assigned by the Super Admin): an administrator of that company
  only, plus **Company > Company Profile & Code** and **Company > Join Requests**. Only the owner (or a
  Super Admin) can change the owner's account; the owner cannot be deactivated by another admin.

**Creating a company** (Super Admin → Create Company): company details + owner name, e-mail, username
and an optional temporary password. The system reserves the company record, generates the Company Code
and the database name from the company number (never from the typed name), runs `CREATE DATABASE`,
installs the structure of `database/ihris_mysql55.sql` and upgrades it to the current version, puts in
the clean defaults, creates the owner, validates every table and only then marks the company Active.
If any step fails, the new database is dropped, the company is marked Failed with the reason, and the
Super Admin can Retry or Discard it.

A new company database receives the reference data of a clean installation: settings and policies,
BIR tax, SSS, PhilHealth, Pag-IBIG, premium and pay rates, late brackets and national holidays (or,
optionally, a copy of another company's current statutory tables), leave types, shifts, pay codes,
salary grades, competencies, code of conduct, deduction / benefit / earning types, GL accounts and the
lookup tables. It gets its own Head Office branch and top organization unit named after the company.
It **never** receives another company's employees, users, attendance, biometric logs, leave, overtime,
payroll runs, payslips, transactions, ledgers, evaluations, cases, audit trail, e-mails or imports, nor
the sample branches, departments and locations of the installation script.

**Joining with a Company Code.** The sign-in page links to **Join your company**: Company Code, full name,
e-mail, username, password. The code is checked in the master database (unknown, replaced or inactive
codes get the same message; 10 wrong codes from one connection block it for 15 minutes); the account is
created in that company's database as *Pending* and appears on the owner's **Join Requests**. Approval
sets the role and may link an existing 201 record (no employee record is created, so HR's masterfile is
not duplicated). After approval the person signs in with username or e-mail and password only.
Regenerating the code stops the old code for new registrations; existing accounts are unaffected.

**Sign-in identity.** Usernames are unique across all companies (the master table `company_memberships`
maps each username to its company and user id); an e-mail address works too when it belongs to one
account. The session keeps the user id and company id; the company's database is looked up in the
master database on every request (never from a URL or form value), and a deactivated company signs its
users out. Accounts created from a 201 file whose employee number is already a username elsewhere get a
suffix (`emp-0001.2`).

**Deactivation** never deletes a database: data, payroll and attendance history stay untouched, users
cannot sign in, the code accepts no registration, the Super Admin keeps access, and activation restores
everything. Permanent deletion of a company database is deliberately not offered in the application.

#   i h r i s  
 