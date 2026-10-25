<?php
/**
 * Self-assessment (qualitative track) and appraisal history for the signed-in employee.
 */
$me = my_employee_id();
if (!$me) {
    throw new RuntimeException('Your account is not linked to an employee record.');
}
$comps = rows('SELECT * FROM competencies WHERE is_active = 1 ORDER BY sort_order, id');
if (is_post()) {
    $ev = row("SELECT ev.* FROM evaluations ev JOIN evaluation_cycles c ON c.id = ev.cycle_id WHERE ev.id = ? AND ev.employee_id = ? AND ev.status <> 'finalized' AND c.status = 'open'", [(int)post('evaluation_id'), $me]);
    if ($ev) {
        q("DELETE FROM evaluation_ratings WHERE evaluation_id = ? AND rater_type = 'self'", [$ev['id']]);
        foreach ($comps as $c) {
            $r = (int)(post('rating')[$c['id']] ?? 0);
            if ($r >= 1 && $r <= 5) {
                insert('evaluation_ratings', ['evaluation_id' => $ev['id'], 'competency_id' => $c['id'], 'rater_type' => 'self', 'rater_name' => current_user()['full_name'], 'rating' => $r, 'comment' => post('comment')[$c['id']] ?? null]);
            }
        }
        eval_compute((int)$ev['id']);
        audit('pis', 'eval_self_assessment', 'evaluations', $ev['id']);
        flash('success', 'Self-assessment saved.');
    }
    redirect('my_evaluations');
}
$evals = rows('SELECT ev.*, c.name AS cycle_name, c.status AS cycle_status, c.period_start, c.period_end FROM evaluations ev JOIN evaluation_cycles c ON c.id = ev.cycle_id WHERE ev.employee_id = ? ORDER BY c.period_end DESC', [$me]);
$likert = [1 => '1 – Needs Improvement', 2 => '2 – Unsatisfactory', 3 => '3 – Satisfactory', 4 => '4 – Very Satisfactory', 5 => '5 – Outstanding'];
page_header('My Evaluations', ['subtitle' => 'Complete your self-assessment for open cycles. Final ratings are shown once HR finalizes the evaluation.']);
foreach ($evals as $ev):
    $self = pairs("SELECT competency_id, rating FROM evaluation_ratings WHERE evaluation_id = ? AND rater_type = 'self'", [$ev['id']]);
    $open = $ev['status'] !== 'finalized' && $ev['cycle_status'] === 'open'; ?>
<div class="card"><div class="card-head"><h2><?= h($ev['cycle_name']) ?></h2><?= badge($ev['status']) ?></div>
  <?php if ($ev['status'] === 'finalized'): ?>
    <div class="stats" style="margin:0"><?= stat_card('KPI achievement', $ev['quant_pct'] !== null ? h($ev['quant_pct']) . '%' : '—') ?><?= stat_card('Qualitative', h($ev['qual_score'] ?? '—')) ?><?= stat_card('Overall', h($ev['overall_score'] ?? '—'), h($ev['rating_label'])) ?></div>
    <?php if ($ev['remarks']): ?><p style="margin-top:10px"><strong>Remarks:</strong> <?= nl2br(h($ev['remarks'])) ?></p><?php endif; ?>
  <?php endif; ?>
  <?php if ($open): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="evaluation_id" value="<?= $ev['id'] ?>">
      <table class="table compact"><thead><tr><th>Competency</th><th>My rating</th><th>Comment / example</th></tr></thead><tbody>
      <?php foreach ($comps as $c): ?><tr><td><strong><?= h($c['name']) ?></strong><br><span class="small muted"><?= h($c['description']) ?></span></td>
        <td><select name="rating[<?= $c['id'] ?>]"><?= options($likert, $self[$c['id']] ?? null, '—') ?></select></td><td><input name="comment[<?= $c['id'] ?>]"></td></tr><?php endforeach; ?>
      </tbody></table>
      <div class="form-actions"><button class="btn btn-primary">Save self-assessment</button></div>
    </form>
  <?php elseif ($ev['status'] !== 'finalized'): ?><p class="muted">This cycle is closed for self-assessment.</p><?php endif; ?>
</div>
<?php endforeach;
if (!$evals) {
    echo '<div class="card"><p class="muted">No evaluation forms yet.</p></div>';
}
page_footer();
