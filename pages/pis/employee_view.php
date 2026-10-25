<?php
$id = (int)get('id', 0);
$e = employee_row($id);
if (!$e) {
    throw new RuntimeException('Employee not found.');
}
// authorized by index.php: employees.view, or a supervisor / department head of this employee (read only)
$staff = can('employees.view');
$seeSalary = salary_can('view');   // Basic Salary view right (app/engine/scope.php)
$canEdit = can('employees.edit');

if (is_post() && $canEdit) {
    $act = post('_action');
    if ($act === 'upload_doc') {
        try {
            $up = save_upload('document', 'documents/' . $id, ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'txt']);
            if (!$up) {
                throw new RuntimeException('Please choose a file to upload.');
            }
            $docId = insert('employee_documents', ['employee_id' => $id, 'doc_type' => post('doc_type', 'other'), 'title' => post('title') ?: $up['name'],
                'file_path' => $up['path'], 'original_name' => $up['name'], 'uploaded_by' => user_id()]);
            audit('pis', 'document_upload', 'employee_documents', $docId, ['employee_id' => $id, 'title' => post('title')]);
            flash('success', 'Document uploaded to the 201 file.');
        } catch (RuntimeException $ex) {
            flash('error', $ex->getMessage());
        }
        header('Location: ' . url('employee_view', ['id' => $id]) . '#tab-docs');
        exit;
    }
    if ($act === 'delete_doc') {
        $doc = row('SELECT * FROM employee_documents WHERE id = ? AND employee_id = ?', [(int)post('doc_id'), $id]);
        if ($doc) {
            q('DELETE FROM employee_documents WHERE id = ?', [$doc['id']]);
            @unlink(upload_dir() . '/' . $doc['file_path']);
            audit('pis', 'document_delete', 'employee_documents', $doc['id'], $doc);
            flash('success', 'Document removed.');
        }
        header('Location: ' . url('employee_view', ['id' => $id]) . '#tab-docs');
        exit;
    }
    if ($act === 'separate') {
        $date = post('date_separated');
        if (!$date) {
            flash('error', 'Separation date is required.');
        } else {
            employee_apply_changes($id, ['status' => 'separated', 'date_separated' => $date, 'separation_type' => post('separation_type'),
                'separation_reason' => post('separation_reason')], $date, label(post('separation_type')) . ': ' . post('separation_reason'), 'separation');
            q('UPDATE users SET is_active = 0 WHERE employee_id = ? AND role = ?', [$id, 'employee']);
            q("UPDATE employee_deductions SET end_date = ? WHERE employee_id = ? AND status = 'active' AND ledger_id IS NULL", [$date, $id]);
            flash('success', 'Employee separated. Compute the final pay (due within ' . setting('final_pay_days', 30) . ' days).');
            redirect('final_pay', ['employee_id' => $id]);
        }
        redirect('employee_view', ['id' => $id]);
    }
    if ($act === 'reinstate') {
        employee_apply_changes($id, ['status' => 'active', 'date_separated' => null, 'separation_type' => null, 'separation_reason' => null], today(), 'Reinstated', 'status_change');
        flash('success', 'Employee reinstated.');
        redirect('employee_view', ['id' => $id]);
    }
    if ($act === 'account') {
        $u = row('SELECT * FROM users WHERE employee_id = ?', [$id]);
        $temp = 'Welcome' . random_int(1000, 9999);
        if ($u) {
            update('users', ['password_hash' => password_hash($temp, PASSWORD_DEFAULT), 'must_change_password' => 1, 'is_active' => 1], 'id = ?', [$u['id']]);
            mc_sync_user((int)$u['id']);
            audit('admin', 'password_reset', 'users', $u['id']);
            flash('info', "Password for \"{$u['username']}\" reset to \"$temp\" (must be changed at sign-in).");
        } else {
            $uname = mc_unique_username(strtolower($e['employee_no']));
            $newUid = insert('users', ['username' => $uname, 'password_hash' => password_hash($temp, PASSWORD_DEFAULT), 'full_name' => full_name($e, false),
                'email' => $e['email'], 'role' => 'employee', 'employee_id' => $id, 'must_change_password' => 1]);
            mc_sync_user($newUid);
            flash('info', "Portal account created: \"$uname\" / \"$temp\".");
        }
        redirect('employee_view', ['id' => $id]);
    }
}

$year = (int)(get('year') ?: date('Y'));
$history = rows('SELECT h.*, u.username FROM employee_history h LEFT JOIN users u ON u.id = h.created_by WHERE h.employee_id = ? ORDER BY h.effective_date DESC, h.id DESC', [$id]);
$docs = rows('SELECT * FROM employee_documents WHERE employee_id = ? ORDER BY uploaded_at DESC', [$id]);
$leaves = rows('SELECT la.*, lt.name AS type_name FROM leave_applications la JOIN leave_types lt ON lt.id = la.leave_type_id WHERE la.employee_id = ? ORDER BY la.date_from DESC', [$id]);
$evals = rows('SELECT ev.*, c.name AS cycle_name, c.period_end FROM evaluations ev JOIN evaluation_cycles c ON c.id = ev.cycle_id WHERE ev.employee_id = ? ORDER BY c.period_end DESC', [$id]);
$cases = rows('SELECT c.*, o.category FROM disciplinary_cases c JOIN conduct_offenses o ON o.id = c.offense_id WHERE c.employee_id = ? ORDER BY c.incident_date DESC', [$id]);
$pay = rows("SELECT pi.*, r.run_no, r.run_type, r.period_start, r.period_end, r.pay_date FROM payroll_items pi JOIN payroll_runs r ON r.id = pi.run_id
             WHERE pi.employee_id = ? AND r.status = 'finalized' ORDER BY r.period_end DESC LIMIT 36", [$id]);
$ledgers = rows('SELECT * FROM ledgers WHERE employee_id = ? ORDER BY status, created_at DESC', [$id]);
$user = row('SELECT * FROM users WHERE employee_id = ?', [$id]);
$sup = $e['supervisor_id'] ? row('SELECT * FROM employees WHERE id = ?', [$e['supervisor_id']]) : null;
$dtr = rows('SELECT * FROM dtr WHERE employee_id = ? AND work_date >= ? ORDER BY work_date DESC', [$id, add_days(today(), -31)]);
$rates = employee_rates($e);

$actions = '';
if ($canEdit) {
    $actions .= '<a class="btn btn-primary" href="' . url('employee_edit', ['id' => $id]) . '">Edit 201</a> ';
    if ($e['status'] === 'active') {
        $actions .= '<button class="btn" data-modal-open="sepModal">Separate</button> ';
        $actions .= '<a class="btn" href="' . url('cases', ['new' => 1, 'employee_id' => $id]) . '">Draft NTE</a> ';
    } else {
        $actions .= '<a class="btn" href="' . url('final_pay', ['employee_id' => $id]) . '">Final pay</a> ';
    }
}
page_header('Employee 201 File', ['back' => url('employees'), 'actions' => $actions]);
?>
<div class="card">
  <div class="profile-head">
    <?php if ($e['photo_path']): ?><img class="profile-photo" src="<?= h(upload_url($e['photo_path'])) ?>" alt=""><?php else: ?><div class="profile-photo"><?= h(mb_substr($e['first_name'], 0, 1) . mb_substr($e['last_name'], 0, 1)) ?></div><?php endif; ?>
    <div class="profile-meta">
      <h2><?= h(full_name($e, false)) ?></h2>
      <div class="muted"><?= h($e['position']) ?>, <?= h(org_path($e['org_unit_id'] ? (int)$e['org_unit_id'] : null)) ?></div>
      <div class="chips">
        <?= badge($e['status']) ?> <span class="badge badge-blue"><?= h(label($e['employment_status'])) ?></span>
        <span class="badge badge-gray"><?= h($e['employee_no']) ?></span>
        <span class="badge badge-gray"><?= h($e['branch_name']) ?></span>
        <span class="badge badge-gray"><?= h($e['pay_group_name']) ?></span>
        <?php if ($e['is_mwe']): ?><span class="badge badge-purple">MWE</span><?php endif; ?>
        <?php if ($e['timekeeping_exempt']): ?><span class="badge badge-purple">Time-keeping exempt</span><?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="tabs">
  <button class="tab" data-tab="tab-profile">Profile</button>
  <button class="tab" data-tab="tab-family">Family &amp; Background</button>
  <button class="tab" data-tab="tab-history">Employment History</button>
  <button class="tab" data-tab="tab-docs">Documents (<?= count($docs) ?>)</button>
  <button class="tab" data-tab="tab-leaves">Leaves</button>
  <button class="tab" data-tab="tab-att">Attendance</button>
  <button class="tab" data-tab="tab-evals">Evaluations</button>
  <button class="tab" data-tab="tab-disc">Discipline (<?= count($cases) ?>)</button>
  <?php if ($staff): ?><?php if ($seeSalary): ?><button class="tab" data-tab="tab-comp">Compensation</button><?php endif; ?><button class="tab" data-tab="tab-ledgers">Ledgers</button><?php endif; ?>
</div>

<div class="tab-panel" id="tab-profile">
  <div class="grid grid-2">
    <div class="card"><h3>Personal information</h3>
      <dl class="kv">
        <dt>Full name</dt><dd><?= h(trim($e['first_name'] . ' ' . $e['middle_name'] . ' ' . $e['last_name'] . ' ' . $e['suffix'])) ?></dd>
        <dt>Sex</dt><dd><?= h(label($e['gender'])) ?></dd>
        <dt>Birth date</dt><dd><?= h(fmt_date($e['birth_date'])) ?><?= $e['birth_date'] ? ' (' . (int)years_of_service($e['birth_date']) . ' yrs)' : '' ?></dd>
        <dt>Civil status</dt><dd><?= h(label($e['civil_status'])) ?></dd>
        <dt>Address</dt><dd><?= h($e['address']) ?></dd>
        <dt>Contact</dt><dd><?= h($e['contact_no']) ?>, <?= h($e['email']) ?></dd>
        <dt>Emergency contact</dt><dd><?= h($e['emergency_contact']) ?> <?= h($e['emergency_phone']) ?></dd>
        <dt>Solo parent</dt><dd><?= $e['is_solo_parent'] ? 'Yes — ID ' . h($e['solo_parent_id_no']) : 'No' ?></dd>
      </dl>
      <h3 style="margin-top:14px">Government IDs</h3>
      <dl class="kv">
        <dt>SSS</dt><dd><?= h($e['sss_no']) ?></dd><dt>PhilHealth</dt><dd><?= h($e['philhealth_no']) ?></dd>
        <dt>Pag-IBIG</dt><dd><?= h($e['pagibig_no']) ?></dd><dt>TIN</dt><dd><?= h($e['tin']) ?></dd>
      </dl>
    </div>
    <div class="card"><h3>Employment &amp; compensation</h3>
      <dl class="kv">
        <dt>Date hired</dt><dd><?= h(fmt_date($e['date_hired'])) ?> (<?= number_format(years_of_service($e['date_hired']), 1) ?> yrs service)</dd>
        <dt>Regularized</dt><dd><?= h(fmt_date($e['date_regularized']) ?: '—') ?></dd>
        <dt>Rank / level</dt><dd><?= h($e['rank_level'] ?: '—') ?></dd>
        <dt>Supervisor</dt><dd><?= $sup ? '<a href="' . url('employee_view', ['id' => $sup['id']]) . '">' . h(full_name($sup, false)) . '</a>' : '—' ?></dd>
        <dt>Salary grade</dt><dd><?= h($e['grade_code'] ? $e['grade_code'] . ($e['salary_step'] ? ' step ' . $e['salary_step'] : '') : '—') ?></dd>
        <?php if ($seeSalary): ?>
        <dt>Basic rate</dt><dd><?= peso($e['basic_rate']) ?> / <?= $e['rate_type'] === 'daily' ? 'day' : 'month' ?></dd>
        <dt>Equivalent rates</dt><dd class="small"><?= peso($rates['monthly']) ?>/mo, <?= peso($rates['daily']) ?>/day, <?= peso($rates['hourly']) ?>/hr (factor <?= (int)$e['days_per_year'] ?>)</dd>
        <?php else: ?>
        <dt>Basic rate</dt><dd class="muted">Hidden (no Basic Salary view right), <?= $e['rate_type'] === 'daily' ? 'daily' : 'monthly' ?>-rated</dd>
        <?php endif; ?>
        <dt>Pay group</dt><dd><?= h($e['pay_group_name']) ?> (<?= h(label($e['frequency'])) ?> BIR table)</dd>
        <dt>Default branch</dt><dd><?= h($e['branch_name']) ?></dd>
        <dt>Bank</dt><dd><?= h($e['bank_name']) ?> <?= h($e['bank_account_no']) ?></dd>
        <dt>Shift</dt><dd><?= $e['shift_id'] && ($es = shift_row((int)$e['shift_id'])) ? shift_code_html($es, '—', $es['code'] . ' — ' . $es['name']) : '—' ?></dd>
        <dt>Default day-off</dt><dd><?= h(day_off_label($e['day_off'] ?? null)) ?></dd>
        <dt>Time capture</dt><dd><?= h(label($e['attendance_method'])) ?></dd>
        <?php if ($e['status'] === 'separated'): ?><dt>Separated</dt><dd><?= h(fmt_date($e['date_separated'])) ?> — <?= h(label($e['separation_type'])) ?>: <?= h($e['separation_reason']) ?></dd><?php endif; ?>
        <dt>Portal account</dt><dd><?= $user ? h($user['username']) . ' (' . h($user['role']) . ($user['is_active'] ? '' : ', inactive') . ')' : 'None' ?>
          <?php if ($canEdit): ?><form method="post" class="inline" data-confirm="<?= $user ? 'Reset this user\'s password?' : 'Create a portal account?' ?>"><?= csrf_field() ?><input type="hidden" name="_action" value="account"><button class="btn btn-sm"><?= $user ? 'Reset password' : 'Create account' ?></button></form><?php endif; ?></dd>
      </dl>
    </div>
  </div>
</div>

<?php $bg = employee_background((int)$e['id']); $dash = fn($x) => $x !== null && $x !== '' ? h($x) : '<span class="muted">—</span>'; ?>
<div class="tab-panel" id="tab-family">
  <div class="grid grid-2">
    <div class="card"><h3>Physical and other personal data</h3>
      <dl class="kv">
        <dt>Color of eyes</dt><dd><?= $dash($e['eye_color'] ?? null) ?></dd>
        <dt>Religion</dt><dd><?= $dash($e['religion'] ?? null) ?></dd>
        <dt>Weight</dt><dd><?= $dash($e['weight'] ?? null) ?></dd>
        <dt>Height</dt><dd><?= $dash($e['height'] ?? null) ?></dd>
      </dl>
      <h3 style="margin-top:14px">Family background</h3>
      <dl class="kv">
        <dt>Spouse</dt><dd><?= $dash($e['spouse_name'] ?? null) ?></dd>
        <dt>Spouse address</dt><dd><?= $dash($e['spouse_address'] ?? null) ?></dd>
        <dt>Spouse contact no.</dt><dd><?= $dash($e['spouse_contact'] ?? null) ?></dd>
        <dt>Father</dt><dd><?= $dash($e['father_name'] ?? null) ?></dd>
        <dt>Mother</dt><dd><?= $dash($e['mother_name'] ?? null) ?></dd>
      </dl>
    </div>
    <div class="card flush"><div class="card-head pad"><div><h3>Children</h3><div class="sub"><?= count($bg['children']) ?> listed</div></div></div>
      <div class="table-wrap"><table class="table table-compact"><thead><tr><th>Name</th><th>Birthday</th><th class="num">Age</th></tr></thead><tbody>
        <?php foreach ($bg['children'] as $c): ?><tr><td><?= h($c['name']) ?></td><td class="nowrap"><?= h(fmt_date($c['birth_date']) ?: '—') ?></td><td class="num"><?= $c['birth_date'] ? (int)years_of_service($c['birth_date']) : '' ?></td></tr><?php endforeach; ?>
        <?= $bg['children'] ? '' : empty_row(3, 'No child recorded.') ?></tbody></table></div>
    </div>
  </div>
  <div class="card flush"><div class="card-head pad"><div><h3>Educational background</h3></div></div>
    <div class="table-wrap"><table class="table table-compact"><thead><tr><th>Level</th><th>Name of school</th><th>Course</th><th>Year graduated</th></tr></thead><tbody>
      <?php foreach ($bg['education'] as $r): ?><tr><td><?= h(EDUCATION_LEVELS[$r['level']] ?? '—') ?></td><td><?= h($r['school']) ?></td><td><?= h($r['course'] ?: '—') ?></td><td><?= h($r['year_graduated'] ?: '—') ?></td></tr><?php endforeach; ?>
      <?= $bg['education'] ? '' : empty_row(4, 'No school recorded.') ?></tbody></table></div>
  </div>
  <div class="card flush"><div class="card-head pad"><div><h3>Employment history (previous employers)</h3><div class="sub">Changes inside the company are under Employment History.</div></div></div>
    <div class="table-wrap"><table class="table table-compact"><thead><tr><th>Company</th><th>Position</th><th>From</th><th>To</th><th>Reason for leaving</th></tr></thead><tbody>
      <?php foreach ($bg['employment'] as $r): ?><tr><td><?= h($r['company']) ?></td><td><?= h($r['position'] ?: '—') ?></td><td class="nowrap"><?= h(fmt_date($r['date_from']) ?: '—') ?></td><td class="nowrap"><?= h(fmt_date($r['date_to']) ?: '—') ?></td><td><?= h($r['reason'] ?: '—') ?></td></tr><?php endforeach; ?>
      <?= $bg['employment'] ? '' : empty_row(5, 'No previous employer recorded.') ?></tbody></table></div>
  </div>
</div>

<div class="tab-panel" id="tab-history">
  <div class="card"><h3>Employment history (promotions, transfers, salary and status changes)</h3>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Effective</th><th>Change</th><th>Position</th><th>Unit</th><?php if ($seeSalary): ?><th class="num">Rate</th><?php endif; ?><th>Grade</th><th>Remarks</th><th>By</th></tr></thead><tbody>
      <?php if (!$history) echo empty_row($seeSalary ? 8 : 7); foreach ($history as $h): ?>
        <tr><td class="nowrap"><?= h(fmt_date($h['effective_date'])) ?></td><td><?= badge($h['change_type'] === 'separation' ? 'separated' : 'submitted', label($h['change_type'])) ?></td>
          <td><?= $h['old_position'] && $h['old_position'] !== $h['new_position'] ? h($h['old_position']) . ' → ' : '' ?><?= h($h['new_position']) ?></td>
          <td class="small"><?= $h['old_org_unit'] && $h['old_org_unit'] !== $h['new_org_unit'] ? h($h['old_org_unit']) . ' → ' : '' ?><?= h($h['new_org_unit']) ?></td>
          <?php if ($seeSalary): ?><td class="num"><?= $h['old_rate'] !== null && (float)$h['old_rate'] !== (float)$h['new_rate'] ? money($h['old_rate']) . ' → ' : '' ?><?= $h['new_rate'] !== null ? money($h['new_rate']) : '' ?></td><?php endif; ?>
          <td class="small"><?= $h['old_grade'] && $h['old_grade'] !== $h['new_grade'] ? h($h['old_grade']) . ' → ' : '' ?><?= h($h['new_grade']) ?></td>
          <td class="small"><?= h($h['remarks']) ?></td><td class="small muted"><?= h($h['username'] ?: 'system') ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
  </div>
</div>

<div class="tab-panel" id="tab-docs">
  <div class="card">
    <h3>201 file documents</h3>
    <p class="muted small">Pre-employment requirements, contracts, appraisal forms, government IDs, clearances and memos.</p>
    <table class="table"><thead><tr><th>Type</th><th>Title</th><th>File</th><th>Uploaded</th><th class="actions"></th></tr></thead><tbody>
    <?php if (!$docs) echo empty_row(5, 'No documents uploaded yet.'); foreach ($docs as $d): ?>
      <tr><td><?= h(label($d['doc_type'])) ?></td><td><?= h($d['title']) ?></td><td><a href="<?= h(upload_url($d['file_path'])) ?>" target="_blank"><?= h($d['original_name']) ?></a></td>
        <td class="small"><?= h(fmt_dt($d['uploaded_at'])) ?></td>
        <td class="actions"><?php if ($canEdit): ?><form method="post" class="inline" data-confirm="Delete this document?"><?= csrf_field() ?><input type="hidden" name="_action" value="delete_doc"><input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>"><button class="btn btn-sm btn-danger-outline">Delete</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table>
    <?php if ($canEdit): ?>
    <form method="post" enctype="multipart/form-data" style="margin-top:14px"><?= csrf_field() ?><input type="hidden" name="_action" value="upload_doc">
      <div class="form-grid">
        <div class="field"><label>Document type</label><select name="doc_type"><?= options(['pre_employment' => 'Pre-employment requirement', 'contract' => 'Employment contract', 'appraisal' => 'Performance appraisal', 'government_id' => 'Government ID', 'clearance' => 'Clearance', 'memo' => 'Memo / notice', 'other' => 'Other'], 'pre_employment') ?></select></div>
        <div class="field"><label>Title</label><input name="title" placeholder="e.g. NBI Clearance 2026"></div>
        <div class="field"><label>File (PDF, image, Office)</label><input type="file" name="document" required></div>
      </div>
      <div class="form-actions"><button class="btn btn-primary">Upload</button></div>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="tab-panel" id="tab-leaves">
  <div class="card"><div class="card-head"><h3>Leave balances <?= $year ?></h3><?php if ($canEdit): ?><a class="btn btn-sm" href="<?= url('leave_applications', ['file_for' => $id]) ?>">File leave on behalf</a><?php endif; ?></div>
    <table class="table compact"><thead><tr><th>Leave type</th><th class="num">Carried over</th><th class="num">Earned</th><th class="num">Adj.</th><th class="num">Used</th><th class="num">Converted</th><th class="num">Available</th><th class="num">Pending</th></tr></thead><tbody>
    <?php foreach (rows('SELECT * FROM leave_types WHERE is_active = 1 ORDER BY id') as $t):
        if (leave_ineligibility($e, $t)) continue;
        if ($t['accrual_method'] === 'per_event') {
            $used = (int)val("SELECT COUNT(*) FROM leave_applications WHERE employee_id = ? AND leave_type_id = ? AND status = 'approved'", [$id, $t['id']]);
            echo '<tr><td>' . h($t['name']) . '</td><td colspan="7" class="muted small">Per occurrence: up to ' . h((float)$t['days_per_year']) . ' day(s)' . ($t['max_events'] ? ', ' . $used . ' of ' . (int)$t['max_events'] . ' occurrence(s) used' : '') . '</td></tr>';
            continue;
        }
        $b = leave_balance($id, (int)$t['id'], $year); ?>
      <tr><td><?= h($t['name']) ?></td><td class="num"><?= $b['carried_over'] ?></td><td class="num"><?= $b['earned'] ?></td><td class="num"><?= $b['adjustment'] ?></td><td class="num"><?= $b['used'] ?></td><td class="num"><?= $b['converted'] ?></td><td class="num"><strong><?= $b['available'] ?></strong></td><td class="num"><?= $b['pending'] ?></td></tr>
    <?php endforeach; ?></tbody></table>
  </div>
  <div class="card"><h3>Leave applications &amp; email approval trail</h3>
    <table class="table"><thead><tr><th>No.</th><th>Type</th><th>Dates</th><th class="num">Days</th><th>Status</th><th>Filed</th></tr></thead><tbody>
    <?php if (!$leaves) echo empty_row(6); foreach ($leaves as $l): ?>
      <tr><td><a href="<?= url('leave_view', ['id' => $l['id']]) ?>"><?= h($l['app_no']) ?></a></td><td><?= h($l['type_name']) ?></td><td><?= h(fmt_date($l['date_from'], 'M d')) ?> – <?= h(fmt_date($l['date_to'])) ?></td><td class="num"><?= h($l['days']) ?></td><td><?= badge($l['status']) ?></td><td class="small"><?= h(fmt_dt($l['filed_at'])) ?></td></tr>
    <?php endforeach; ?></tbody></table>
  </div>
</div>

<div class="tab-panel" id="tab-att">
  <div class="card"><div class="card-head"><h3>Daily time record — last 31 days</h3>
    <a class="btn btn-sm" href="<?= url('dtr', ['employee_id' => $id]) ?>">Open DTR</a></div>
    <div class="table-wrap"><table class="table compact"><thead><tr><th>Date</th><th>Day type</th><th>In</th><th>Out</th><th class="num">Reg hrs</th><th class="num">Late</th><th class="num">UT</th><th class="num">OT (appr.)</th><th>Status</th></tr></thead><tbody>
    <?php if (!$dtr) echo empty_row(9); foreach ($dtr as $d): ?>
      <tr class="<?= $d['exception_status'] === 'open' ? 'row-warn' : '' ?>"><td class="nowrap"><?= h(fmt_date($d['work_date'], 'D M d')) ?></td><td class="small"><?= h(label($d['day_type'])) ?></td><td><?= h(fmt_time($d['time_in'])) ?></td><td><?= h(fmt_time($d['time_out'])) ?></td>
        <td class="num"><?= hours($d['regular_minutes']) ?></td><td class="num"><?= (int)$d['late_minutes'] ?: '' ?></td><td class="num"><?= (int)$d['undertime_minutes'] ?: '' ?></td><td class="num"><?= $d['approved_ot_minutes'] ? hours($d['approved_ot_minutes']) : '' ?></td><td><?= badge($d['status']) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  </div>
</div>

<div class="tab-panel" id="tab-evals">
  <div class="card"><h3>Performance appraisal history</h3>
    <table class="table"><thead><tr><th>Cycle</th><th class="num">Quantitative</th><th class="num">Qualitative</th><th class="num">Overall</th><th>Rating</th><th>Recommendation</th><th>Status</th></tr></thead><tbody>
    <?php if (!$evals) echo empty_row(7); foreach ($evals as $v): ?>
      <tr><td><a href="<?= url('evaluation_edit', ['id' => $v['id']]) ?>"><?= h($v['cycle_name']) ?></a></td><td class="num"><?= $v['quant_pct'] !== null ? h($v['quant_pct']) . '%' : '—' ?></td><td class="num"><?= h($v['qual_score'] ?? '—') ?></td><td class="num"><strong><?= h($v['overall_score'] ?? '—') ?></strong></td><td><?= h($v['rating_label']) ?></td><td class="small"><?= h($v['recommendation']) ?></td><td><?= badge($v['status']) ?></td></tr>
    <?php endforeach; ?></tbody></table>
  </div>
</div>

<div class="tab-panel" id="tab-disc">
  <div class="card"><h3>Disciplinary records</h3>
    <table class="table"><thead><tr><th>Case</th><th>Offense</th><th>Incident</th><th>Offense no.</th><th>Decision</th><th>Status</th></tr></thead><tbody>
    <?php if (!$cases) echo empty_row(6, 'No disciplinary records.'); foreach ($cases as $c): ?>
      <tr><td><a href="<?= url('case_view', ['id' => $c['id']]) ?>"><?= h($c['case_no']) ?></a></td><td><?= h($c['category']) ?></td><td><?= h(fmt_date($c['incident_date'])) ?></td><td><?= h(ordinal((int)$c['offense_count'])) ?></td><td><?= h($c['decision'] ? PENALTY_LABELS[$c['decision']] : '—') ?></td><td><?= badge($c['status'], CASE_STAGES[$c['status']] ?? null) ?></td></tr>
    <?php endforeach; ?></tbody></table>
  </div>
</div>

<?php if ($staff && $seeSalary): ?>
<div class="tab-panel" id="tab-comp">
  <div class="card"><h3>Compensation history (finalized payroll)</h3>
    <div class="table-wrap"><table class="table compact"><thead><tr><th>Run</th><th>Period</th><th>Type</th><th class="num">Rate</th><th class="num">Gross</th><th class="num">Deductions</th><th class="num">Tax</th><th class="num">Net</th><th></th></tr></thead><tbody>
    <?php if (!$pay) echo empty_row(9); foreach ($pay as $p): ?>
      <tr><td><?= h($p['run_no']) ?></td><td class="nowrap"><?= h(fmt_date($p['period_start'], 'M d')) ?> – <?= h(fmt_date($p['period_end'])) ?></td><td><?= h(RUN_TYPES[$p['run_type']] ?? $p['run_type']) ?></td>
        <td class="num"><?= money($p['rate_type'] === 'daily' ? $p['daily_rate'] : $p['monthly_rate']) ?></td><td class="num"><?= money($p['gross_pay']) ?></td><td class="num"><?= money($p['total_deductions']) ?></td><td class="num"><?= money($p['withholding_tax']) ?></td><td class="num"><strong><?= money($p['net_pay']) ?></strong></td>
        <td class="actions"><a class="btn btn-sm" href="<?= url('payslip', ['id' => $p['id']]) ?>">Payslip</a></td></tr>
    <?php endforeach; ?></tbody></table></div>
  </div>
</div>
<?php endif; ?>
<?php if ($staff): ?>
<div class="tab-panel" id="tab-ledgers">
  <div class="card"><div class="card-head"><h3>Employee Term Loan/Payables</h3><a class="btn btn-sm" href="<?= url('ledgers', ['new' => 1, 'employee_id' => $id]) ?>">+ New Loan/Payable</a></div>
    <table class="table"><thead><tr><th>Ledger</th><th>Type</th><th class="num">Principal</th><th class="num">Amortization</th><th class="num">Balance</th><th>Status</th></tr></thead><tbody>
    <?php if (!$ledgers) echo empty_row(6); foreach ($ledgers as $l): ?>
      <tr><td><a href="<?= url('ledger_view', ['id' => $l['id']]) ?>"><?= h($l['ledger_no']) ?></a><br><span class="small muted"><?= h($l['sub_type']) ?></span></td><td><?= h(LEDGER_TYPES[$l['ledger_type']] ?? $l['ledger_type']) ?></td><td class="num"><?= money($l['total_payable']) ?></td><td class="num"><?= money($l['amortization']) ?></td><td class="num"><strong><?= money($l['balance']) ?></strong></td><td><?= badge($l['status']) ?></td></tr>
    <?php endforeach; ?></tbody></table>
  </div>
</div>
<?php endif; ?>

<?php if ($canEdit): ?>
<div class="modal" id="sepModal"><div class="modal-box">
  <h2>Separate employee</h2>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="separate">
    <div class="form-grid">
      <div class="field"><label>Last day / separation date</label><input type="date" name="date_separated" value="<?= h(today()) ?>" required></div>
      <div class="field"><label>Type</label><select name="separation_type"><?= options(['resignation' => 'Resignation', 'end_of_contract' => 'End of contract / project', 'retirement' => 'Retirement', 'termination' => 'Termination (authorized cause)', 'dismissal' => 'Dismissal (just cause)', 'death' => 'Death', 'awol' => 'Abandonment / AWOL'], 'resignation') ?></select></div>
      <div class="field wide"><label>Reason / reference</label><textarea name="separation_reason" rows="2"></textarea></div>
    </div>
    <p class="small muted">The portal account is deactivated and the final pay computation (last salary, pro-rated 13th month, leave conversion, loan offsets) is opened next.</p>
    <div class="form-actions"><button class="btn btn-danger">Separate</button><button type="button" class="btn" data-modal-close>Cancel</button></div>
  </form>
</div></div>
<?php endif;
page_footer();
