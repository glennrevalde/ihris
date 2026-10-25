<?php
$id = (int)get('id', 0);
$c = case_full($id);
if (!$c) {
    throw new RuntimeException('Case not found.');
}
$minDays = (int)setting('nte_min_days', 5);
if (is_post()) {
    $act = post('_action');
    $today = today();
    switch ($act) {
        case 'edit_draft':
            if ($c['status'] === 'draft') {
                $sug = suggest_penalty((int)$c['employee_id'], (int)post('offense_id'), (string)post('incident_date'), $id);
                update('disciplinary_cases', ['offense_id' => post('offense_id'), 'incident_date' => post('incident_date'), 'incident_details' => post('incident_details'),
                    'policy_provision' => post('policy_provision'), 'offense_count' => $sug['offense_no'], 'suggested_penalty' => ordinal($sug['offense_no']) . ' offense: ' . $sug['text']], 'id = ?', [$id]);
                case_event($id, 'draft', 'Draft updated.');
                flash('success', 'Draft updated.');
            }
            break;
        case 'issue_nte':
            $nte = post('nte_date') ?: $today;
            $due = post('explanation_due');
            if (!$due || days_between($nte, $due) < $minDays) {
                flash('error', "The employee must be given at least $minDays calendar days to submit a written explanation.");
                break;
            }
            update('disciplinary_cases', ['nte_date' => $nte, 'explanation_due' => $due, 'status' => 'nte_issued'], 'id = ?', [$id]);
            case_event($id, 'nte_issued', "First notice (NTE) issued on " . fmt_date($nte) . "; written explanation due " . fmt_date($due) . '.');
            $email = employee_email((int)$c['employee_id']);
            if ($email) {
                send_mail($email, full_name($c, false), 'Notice to Explain — ' . $c['case_no'], email_template('Notice to Explain',
                    '<p>Dear ' . h($c['first_name']) . ',</p><p>You are hereby required to submit a written explanation regarding the matter below on or before <strong>' . h(fmt_date($due)) . '</strong>.</p>'
                    . email_table(['Case No.' => h($c['case_no']), 'Offense' => h($c['category']), 'Incident date' => h(fmt_date($c['incident_date'])), 'Details' => nl2br(h($c['incident_details'])), 'Policy provision' => h($c['policy_provision'])])
                    . '<p>You may also request an administrative conference. A printed copy of this notice will be served to you personally.</p>'), 'disciplinary_case', $id);
            }
            audit('pis', 'case_nte', 'disciplinary_cases', $id);
            flash('success', 'Notice to Explain issued. Print it for personal service to the employee.');
            break;
        case 'explanation':
            if (!post('explanation_text')) {
                flash('error', 'Please encode the written explanation.');
                break;
            }
            update('disciplinary_cases', ['explanation_text' => post('explanation_text'), 'explanation_date' => post('explanation_date') ?: $today, 'status' => 'explanation_received'], 'id = ?', [$id]);
            case_event($id, 'explanation_received', 'Written explanation received on ' . fmt_date(post('explanation_date') ?: $today) . '.');
            flash('success', 'Explanation recorded.');
            break;
        case 'lapsed':
            if ($c['explanation_due'] && $c['explanation_due'] < $today) {
                update('disciplinary_cases', ['status' => 'for_decision'], 'id = ?', [$id]);
                case_event($id, 'for_decision', 'No written explanation received within the period (due ' . fmt_date($c['explanation_due']) . '). Proceeding to evaluation.');
            }
            break;
        case 'hearing':
            update('disciplinary_cases', ['hearing_date' => str_replace('T', ' ', (string)post('hearing_date')), 'hearing_venue' => post('hearing_venue'), 'status' => 'hearing_scheduled'], 'id = ?', [$id]);
            case_event($id, 'hearing_scheduled', 'Administrative hearing / conference scheduled on ' . fmt_dt(post('hearing_date')) . ' at ' . post('hearing_venue') . '.');
            flash('success', 'Hearing scheduled.');
            break;
        case 'minutes':
            update('disciplinary_cases', ['hearing_minutes' => post('hearing_minutes'), 'status' => 'for_decision'], 'id = ?', [$id]);
            case_event($id, 'for_decision', 'Hearing minutes recorded; case forwarded for evaluation.');
            flash('success', 'Minutes recorded.');
            break;
        case 'to_decision':
            update('disciplinary_cases', ['status' => 'for_decision'], 'id = ?', [$id]);
            case_event($id, 'for_decision', 'Forwarded for evaluation / decision without hearing.');
            break;
        case 'evaluate':
            update('disciplinary_cases', ['evaluation_notes' => post('evaluation_notes')], 'id = ?', [$id]);
            case_event($id, 'evaluation', 'Evaluation notes updated.');
            break;
        case 'decide':
            $okProcess = in_array($c['status'], ['explanation_received', 'hearing_scheduled', 'for_decision'], true)
                || ($c['status'] === 'nte_issued' && $c['explanation_due'] < $today);
            if (!$okProcess) {
                flash('error', 'Due process: the Notice of Decision can only be issued after the employee has been given the opportunity to explain (explanation received or period lapsed).');
                break;
            }
            $dec = (string)post('decision');
            if (!isset(PENALTY_LABELS[$dec]) || !post('decision_grounds')) {
                flash('error', 'Select the decision and state the specific grounds.');
                break;
            }
            $upd = ['decision' => $dec, 'decision_grounds' => post('decision_grounds'), 'evaluation_notes' => post('evaluation_notes') ?: $c['evaluation_notes'],
                'nod_date' => post('nod_date') ?: $today, 'effectivity_date' => post('effectivity_date') ?: $today, 'status' => 'decided',
                'suspension_days' => $dec === 'suspension' ? max(1, (int)post('suspension_days')) : null, 'suspension_start' => $dec === 'suspension' ? (post('suspension_start') ?: post('effectivity_date')) : null];
            update('disciplinary_cases', $upd, 'id = ?', [$id]);
            case_event($id, 'decided', 'Second notice (NOD) issued: ' . PENALTY_LABELS[$dec] . ($dec === 'suspension' ? ' (' . $upd['suspension_days'] . ' working days from ' . fmt_date($upd['suspension_start']) . ')' : '') . ', effective ' . fmt_date($upd['effectivity_date']) . '.');
            audit('pis', 'case_decide', 'disciplinary_cases', $id, $upd);
            if ($dec === 'suspension') {
                // mark the suspension days in the DTR (unpaid, not counted as AWOL)
                $e = att_employee((int)$c['employee_id']);
                foreach (employee_workdays($e, $upd['suspension_start'], (int)$upd['suspension_days']) as $d) {
                    if ($d <= $today) {
                        process_dtr((int)$c['employee_id'], $d);
                    }
                }
            }
            if ($dec === 'dismissal' && post('separate')) {
                employee_apply_changes((int)$c['employee_id'], ['status' => 'separated', 'date_separated' => $upd['effectivity_date'], 'separation_type' => 'dismissal',
                    'separation_reason' => 'Dismissal per ' . $c['case_no']], $upd['effectivity_date'], 'Dismissal per ' . $c['case_no'], 'separation');
                q("UPDATE users SET is_active = 0 WHERE employee_id = ? AND role = 'employee'", [$c['employee_id']]);
                flash('info', 'Employee separated effective ' . fmt_date($upd['effectivity_date']) . '. Compute the final pay.');
            }
            flash('success', 'Notice of Decision issued. Print it for service to the employee.');
            break;
        case 'close':
            update('disciplinary_cases', ['status' => 'closed'], 'id = ?', [$id]);
            case_event($id, 'closed', post('notes') ?: 'Case closed.');
            flash('success', 'Case closed and archived in the 201 file.');
            break;
        case 'attach':
            try {
                $up = save_upload('file', 'cases/' . $id, ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx']);
                if ($up) {
                    insert('case_attachments', ['case_id' => $id, 'title' => post('title') ?: $up['name'], 'file_path' => $up['path'], 'original_name' => $up['name']]);
                    case_event($id, 'attachment', 'Attachment added: ' . (post('title') ?: $up['name']));
                    flash('success', 'Attachment uploaded.');
                }
            } catch (RuntimeException $e) {
                flash('error', $e->getMessage());
            }
            break;
    }
    redirect('case_view', ['id' => $id]);
}
$c = case_full($id);
$events = rows('SELECT * FROM case_events WHERE case_id = ? ORDER BY id', [$id]);
$atts = rows('SELECT * FROM case_attachments WHERE case_id = ? ORDER BY id', [$id]);
$sug = suggest_penalty((int)$c['employee_id'], (int)$c['offense_id'], $c['incident_date'], $id);
$prior = rows("SELECT c.case_no, c.incident_date, c.decision FROM disciplinary_cases c WHERE c.employee_id = ? AND c.offense_id = ? AND c.id <> ? AND c.status IN ('decided','closed') ORDER BY c.incident_date DESC",
    [$c['employee_id'], $c['offense_id'], $id]);
$stageOrder = array_keys(CASE_STAGES);
$curIdx = array_search($c['status'], $stageOrder, true);
$defaultPenalty = $sug['penalty']['penalty_type'] ?? 'written_warning';
page_header('Case ' . $c['case_no'], ['back' => url('cases'), 'actions' =>
    ($c['nte_date'] ? '<a class="btn" target="_blank" href="' . url('case_print', ['id' => $id, 'doc' => 'nte']) . '">Print NTE</a> ' : '') .
    ($c['nod_date'] ? '<a class="btn" target="_blank" href="' . url('case_print', ['id' => $id, 'doc' => 'nod']) . '">Print NOD</a>' : '')]);
?>
<div class="card">
  <div class="steps">
    <?php foreach (['draft' => 'Draft', 'nte_issued' => 'Notice to Explain', 'explanation_received' => 'Explanation', 'hearing_scheduled' => 'Hearing (optional)', 'for_decision' => 'Evaluation', 'decided' => 'Notice of Decision', 'closed' => 'Closed'] as $k => $l):
        $i = array_search($k, $stageOrder, true); ?>
      <div class="step <?= $i < $curIdx ? 'done' : ($i === $curIdx ? 'current' : '') ?>" data-n="<?= $i + 1 ?>"><?= h($l) ?></div>
    <?php endforeach; ?>
  </div>
  <div class="grid grid-2">
    <dl class="kv">
      <dt>Employee</dt><dd><a href="<?= url('employee_view', ['id' => $c['employee_id']]) ?>"><?= h(full_name($c, false)) ?></a>, <?= h($c['employee_no']) ?></dd>
      <dt>Position / unit</dt><dd><?= h($c['position']) ?>, <?= h($c['org_unit_name']) ?></dd>
      <dt>Offense</dt><dd><?= h($c['category']) ?> <span class="badge badge-gray"><?= h($c['offense_code']) ?></span></dd>
      <dt>Incident date</dt><dd><?= h(fmt_date($c['incident_date'])) ?></dd>
      <dt>Policy provision</dt><dd><?= h($c['policy_provision']) ?></dd>
      <dt>Source</dt><dd><?= h(label($c['source'])) ?></dd>
    </dl>
    <div>
      <div class="callout"><strong>Progressive penalty:</strong> <?= h(ordinal($sug['offense_no'])) ?> offense of this category within <?= (int)setting('discipline_lookback_months', 12) ?> months (<?= $sug['prior'] ?> prior) → <strong><?= h($sug['text']) ?></strong></div>
      <?php if ($prior): ?><p class="small"><strong>Prior records:</strong> <?php foreach ($prior as $p) echo h($p['case_no']) . ' (' . h(fmt_date($p['incident_date'])) . ', ' . h(PENALTY_LABELS[$p['decision']] ?? '—') . ') '; ?></p><?php endif; ?>
      <p><strong>Act / omission:</strong><br><?= nl2br(h($c['incident_details'])) ?></p>
    </div>
  </div>
</div>

<div class="grid grid-2-1">
<div>
<?php if ($c['status'] === 'draft'): ?>
  <div class="card"><h3>Edit draft</h3>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="edit_draft">
      <div class="form-grid">
        <div class="field"><label>Offense</label><select name="offense_id"><?= options(pairs("SELECT id, category FROM conduct_offenses WHERE is_active = 1 ORDER BY id"), $c['offense_id']) ?></select></div>
        <div class="field"><label>Incident date</label><input type="date" name="incident_date" value="<?= h($c['incident_date']) ?>"></div>
        <div class="field wide"><label>Act or omission</label><textarea name="incident_details" rows="3"><?= h($c['incident_details']) ?></textarea></div>
        <div class="field wide"><label>Policy provision</label><input name="policy_provision" value="<?= h($c['policy_provision']) ?>"></div>
      </div>
      <div class="form-actions"><button class="btn">Update draft</button></div>
    </form>
  </div>
  <div class="card"><h3>Issue first notice — Notice to Explain</h3>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="issue_nte">
      <div class="form-grid">
        <div class="field"><label>NTE date</label><input type="date" name="nte_date" value="<?= h(today()) ?>"></div>
        <div class="field"><label>Written explanation due (min. <?= $minDays ?> calendar days)</label><input type="date" name="explanation_due" value="<?= h(add_days(today(), $minDays)) ?>"></div>
      </div>
      <div class="form-actions"><button class="btn btn-primary">Issue NTE</button></div>
    </form>
  </div>
<?php endif; ?>

<?php if ($c['status'] === 'nte_issued'): ?>
  <div class="card"><h3>Opportunity to be heard — written explanation</h3>
    <p class="small muted">Explanation due <?= h(fmt_date($c['explanation_due'])) ?>.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="explanation">
      <div class="form-grid"><div class="field"><label>Date received</label><input type="date" name="explanation_date" value="<?= h(today()) ?>"></div>
        <div class="field wide"><label>Employee's written explanation</label><textarea name="explanation_text" rows="4"></textarea></div></div>
      <div class="form-actions"><button class="btn btn-primary">Record explanation</button></div>
    </form>
    <?php if ($c['explanation_due'] < today()): ?>
      <form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="_action" value="lapsed"><button class="btn btn-sm" data-confirm="Proceed without an explanation? The period has lapsed.">Explanation period lapsed — proceed to evaluation</button></form>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($c['status'] === 'explanation_received'): ?>
  <div class="card"><h3>Administrative hearing / conference (where warranted)</h3>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="hearing">
      <div class="form-grid"><div class="field"><label>Date &amp; time</label><input type="datetime-local" name="hearing_date" required></div><div class="field"><label>Venue</label><input name="hearing_venue" value="HR Conference Room"></div></div>
      <div class="form-actions"><button class="btn">Schedule hearing</button></div></form>
    <form method="post" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="_action" value="to_decision"><button class="btn btn-primary">Proceed to evaluation without hearing</button></form>
  </div>
<?php endif; ?>

<?php if ($c['status'] === 'hearing_scheduled'): ?>
  <div class="card"><h3>Hearing minutes</h3><p class="small muted">Scheduled <?= h(fmt_dt($c['hearing_date'])) ?> — <?= h($c['hearing_venue']) ?>. Attach signed minutes below.</p>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="minutes">
      <div class="field"><label>Minutes / findings</label><textarea name="hearing_minutes" rows="5"><?= h($c['hearing_minutes']) ?></textarea></div>
      <div class="form-actions"><button class="btn btn-primary">Save minutes &amp; forward for decision</button></div></form>
  </div>
<?php endif; ?>

<?php if (in_array($c['status'], ['explanation_received', 'hearing_scheduled', 'for_decision'], true) || ($c['status'] === 'nte_issued' && $c['explanation_due'] < today())): ?>
  <div class="card"><h3>Evaluation &amp; second notice — Notice of Decision</h3>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="_action" value="decide">
      <div class="form-grid">
        <div class="field wide"><label>Evaluation (HR &amp; department head) against the penalty matrix</label><textarea name="evaluation_notes" rows="3"><?= h($c['evaluation_notes']) ?></textarea></div>
        <div class="field"><label>Decision / penalty</label><select name="decision" id="decSel"><?= options(PENALTY_LABELS, $defaultPenalty) ?></select><small class="help">Suggested: <?= h($sug['text']) ?></small></div>
        <div class="field"><label>NOD date</label><input type="date" name="nod_date" value="<?= h(today()) ?>"></div>
        <div class="field"><label>Effectivity date</label><input type="date" name="effectivity_date" value="<?= h(add_days(today(), 1)) ?>"></div>
        <div class="field susp"><label>Suspension (working days)</label><input type="number" name="suspension_days" min="1" value="<?= h($sug['penalty']['min_days'] ?? 1) ?>"></div>
        <div class="field susp"><label>Suspension start</label><input type="date" name="suspension_start" value="<?= h(add_days(today(), 1)) ?>"></div>
        <div class="field wide"><label>Specific grounds for the decision</label><textarea name="decision_grounds" rows="3" required></textarea></div>
        <div class="field wide dism"><label class="check"><input type="checkbox" name="separate" value="1"> Separate the employee on the effectivity date (opens final pay)</label></div>
      </div>
      <div class="form-actions"><button class="btn btn-primary" data-confirm="Issue the Notice of Decision?">Issue NOD</button></div>
    </form>
    <script>(function(){var s=document.getElementById('decSel');function f(){document.querySelectorAll('.susp').forEach(function(e){e.style.display=s.value==='suspension'?'':'none';});document.querySelectorAll('.dism').forEach(function(e){e.style.display=s.value==='dismissal'?'':'none';});}s.addEventListener('change',f);f();})();</script>
  </div>
<?php endif; ?>

<?php if ($c['status'] === 'decided'): ?>
  <div class="card"><h3>Decision</h3>
    <dl class="kv"><dt>Penalty</dt><dd><?= h(PENALTY_LABELS[$c['decision']]) ?><?= $c['decision'] === 'suspension' ? ' — ' . (int)$c['suspension_days'] . ' working days from ' . h(fmt_date($c['suspension_start'])) : '' ?></dd>
      <dt>NOD date</dt><dd><?= h(fmt_date($c['nod_date'])) ?></dd><dt>Effective</dt><dd><?= h(fmt_date($c['effectivity_date'])) ?></dd><dt>Grounds</dt><dd><?= nl2br(h($c['decision_grounds'])) ?></dd></dl>
    <form method="post" style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="_action" value="close"><div class="field"><label>Closing notes</label><input name="notes" placeholder="e.g. NOD received by employee on ..."></div>
      <div class="form-actions"><button class="btn">Close case</button></div></form>
  </div>
<?php endif; ?>

<?php if ($c['explanation_text'] || $c['hearing_minutes']): ?>
  <div class="card"><h3>Record</h3>
    <?php if ($c['explanation_text']): ?><p><strong>Written explanation (<?= h(fmt_date($c['explanation_date'])) ?>):</strong><br><?= nl2br(h($c['explanation_text'])) ?></p><?php endif; ?>
    <?php if ($c['hearing_minutes']): ?><p><strong>Hearing minutes (<?= h(fmt_dt($c['hearing_date'])) ?>):</strong><br><?= nl2br(h($c['hearing_minutes'])) ?></p><?php endif; ?>
    <?php if ($c['evaluation_notes']): ?><p><strong>Evaluation:</strong><br><?= nl2br(h($c['evaluation_notes'])) ?></p><?php endif; ?>
  </div>
<?php endif; ?>
</div>
<div>
  <div class="card"><h3>Audit trail</h3>
    <ul class="timeline"><?php foreach ($events as $ev): ?><li><strong><?= h(CASE_STAGES[$ev['stage']] ?? label($ev['stage'])) ?></strong><div class="when"><?= h(fmt_dt($ev['created_at'])) ?>, <?= h($ev['username']) ?></div><div class="small"><?= h($ev['notes']) ?></div></li><?php endforeach; ?></ul>
  </div>
  <div class="card"><h3>Attachments</h3>
    <?php if ($atts): ?><ul><?php foreach ($atts as $a): ?><li><a href="<?= h(upload_url($a['file_path'])) ?>" target="_blank"><?= h($a['title']) ?></a> <span class="small muted"><?= h(fmt_dt($a['uploaded_at'])) ?></span></li><?php endforeach; ?></ul><?php else: ?><p class="muted small">None.</p><?php endif; ?>
    <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="_action" value="attach">
      <div class="field"><label>Title</label><input name="title" placeholder="e.g. Signed NTE receipt"></div><div class="field"><input type="file" name="file" required></div>
      <div class="form-actions"><button class="btn btn-sm">Upload</button></div></form>
  </div>
</div>
</div>
<?php page_footer();
