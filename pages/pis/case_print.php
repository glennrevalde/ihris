<?php
$c = case_full((int)get('id'));
if (!$c) {
    throw new RuntimeException('Case not found.');
}
$doc = get('doc') === 'nod' ? 'nod' : 'nte';
$branch = row('SELECT * FROM branches WHERE id = ?', [$c['default_branch_id']]);
$hrHead = perm_users_with('cases.approve')[0] ?? null;   // the HR officer who decides cases (Role Management)
print_header(($doc === 'nte' ? 'Notice to Explain' : 'Notice of Decision') . ' ' . $c['case_no']);
echo company_letterhead($branch);
?>
<h1><?= $doc === 'nte' ? 'Notice to Explain' : 'Notice of Decision' ?></h1>
<table class="doc-table" style="border:0"><tbody>
  <tr><td style="border:0;width:120px">Date</td><td style="border:0"><strong><?= h(fmt_date($doc === 'nte' ? $c['nte_date'] : $c['nod_date'], 'F d, Y')) ?></strong></td><td style="border:0">Case No.</td><td style="border:0"><strong><?= h($c['case_no']) ?></strong></td></tr>
  <tr><td style="border:0">To</td><td style="border:0"><strong><?= h(full_name($c, false)) ?></strong><br><?= h($c['position']) ?>, <?= h($c['org_unit_name']) ?></td><td style="border:0">Employee No.</td><td style="border:0"><?= h($c['employee_no']) ?></td></tr>
  <tr><td style="border:0">Subject</td><td style="border:0" colspan="3"><strong><?= h($c['category']) ?></strong></td></tr>
</tbody></table>
<?php if ($doc === 'nte'): ?>
  <p>Dear <?= h($c['first_name']) ?>,</p>
  <p>This is to formally inform you of the following act or omission which, if proven, constitutes a violation of company policy:</p>
  <div class="form-box"><h3>Specific act or omission complained of</h3><p><?= nl2br(h($c['incident_details'])) ?></p><p><strong>Date of incident:</strong> <?= h(fmt_date($c['incident_date'], 'F d, Y')) ?></p></div>
  <div class="form-box"><h3>Company rule / Code of Conduct provision allegedly violated</h3><p><?= h($c['policy_provision'] ?: $c['category']) ?> — <?= h($c['offense_desc']) ?></p>
    <p>This would be your <strong><?= h(ordinal((int)$c['offense_count'])) ?> offense</strong> under this category within the applicable period. The corresponding penalty under the Code of Conduct is: <strong><?= h(preg_replace('/^\d+\w+ offense: /', '', (string)$c['suggested_penalty'])) ?></strong>.</p></div>
  <p>In compliance with the requirements of procedural due process, you are hereby directed to <strong>submit a written explanation within <?= max(0, days_between($c['nte_date'], $c['explanation_due'])) ?> calendar days, or on or before <?= h(fmt_date($c['explanation_due'], 'F d, Y')) ?></strong>, why no disciplinary action should be taken against you. You may also request a hearing or conference and be assisted by a representative of your choice.</p>
  <p>Failure to submit your explanation within the said period shall be construed as a waiver of your right to be heard, and the case shall be decided on the basis of the available evidence.</p>
<?php else: ?>
  <p>Dear <?= h($c['first_name']) ?>,</p>
  <p>This refers to the Notice to Explain dated <?= h(fmt_date($c['nte_date'], 'F d, Y')) ?> regarding <strong><?= h($c['category']) ?></strong> (incident of <?= h(fmt_date($c['incident_date'], 'F d, Y')) ?>).</p>
  <p><?= $c['explanation_text'] ? 'Management has carefully evaluated your written explanation dated ' . h(fmt_date($c['explanation_date'], 'F d, Y')) . ($c['hearing_minutes'] ? ' and the findings of the administrative conference held on ' . h(fmt_date($c['hearing_date'], 'F d, Y')) : '') . '.' : 'You did not submit a written explanation within the period given, which is deemed a waiver of your right to be heard.' ?></p>
  <div class="form-box"><h3>Grounds for the decision</h3><p><?= nl2br(h($c['decision_grounds'])) ?></p></div>
  <div class="form-box"><h3>Decision</h3><p>After due consideration, management has decided to impose the penalty of <strong><?= h(strtoupper(PENALTY_LABELS[$c['decision']] ?? '')) ?></strong><?= $c['decision'] === 'suspension' ? ' for <strong>' . (int)$c['suspension_days'] . ' working day(s)</strong> without pay, starting ' . h(fmt_date($c['suspension_start'], 'F d, Y')) : '' ?>, effective <strong><?= h(fmt_date($c['effectivity_date'], 'F d, Y')) ?></strong>.</p>
    <?php if ($c['decision'] !== 'dismissal' && $c['decision'] !== 'exonerated'): ?><p>You are reminded that a repetition of the same or similar offense shall be dealt with more severely in accordance with the Code of Conduct.</p><?php endif; ?></div>
<?php endif; ?>
<div class="sig-row">
  <div class="sig"><div class="line"><?= h($hrHead['full_name'] ?? 'HR Manager') ?></div><small>Human Resources Department</small></div>
  <div class="sig"><div class="line">&nbsp;</div><small>Department Head</small></div>
</div>
<div class="sig-row" style="margin-top:40px">
  <div class="sig"><div class="line"><?= h(full_name($c, false)) ?></div><small>Received by employee — signature over printed name / date</small></div>
  <div class="sig"></div>
</div>
<?php print_footer();
