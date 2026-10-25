<?php
if (is_post() && post('_action') === 'generate') {
    $cid = (int)post('cycle_id');
    $ou = post('ou');
    $where = "status = 'active'";
    $params = [];
    if ($ou) {
        $ids = org_descendant_ids((int)$ou);
        $where .= ' AND org_unit_id IN (' . in_list($ids) . ')';
        $params = $ids;
    }
    $n = 0;
    foreach (rows("SELECT id, supervisor_id FROM employees WHERE $where" . user_scope_sql('employees'), $params) as $e) {
        if (!val('SELECT id FROM evaluations WHERE cycle_id = ? AND employee_id = ?', [$cid, $e['id']])) {
            $eid = insert('evaluations', ['cycle_id' => $cid, 'employee_id' => $e['id'], 'evaluator_employee_id' => $e['supervisor_id']]);
            // copy KPIs from the employee's previous evaluation as a starting template
            $prev = val('SELECT ev.id FROM evaluations ev WHERE ev.employee_id = ? AND ev.id <> ? ORDER BY ev.id DESC LIMIT 1', [$e['id'], $eid]);
            if ($prev) {
                foreach (rows('SELECT kpi_name, weight, target, lower_is_better FROM evaluation_kpis WHERE evaluation_id = ?', [$prev]) as $k) {
                    insert('evaluation_kpis', array_merge($k, ['evaluation_id' => $eid]));
                }
            }
            $n++;
        }
    }
    audit('pis', 'eval_generate', 'evaluation_cycles', $cid, ['created' => $n]);
    flash('success', "$n evaluation form(s) created and assigned to each employee's supervisor.");
    redirect('evaluations', ['cycle_id' => $cid]);
}
crud_page([
    'page' => 'eval_cycles', 'table' => 'evaluation_cycles', 'title' => 'Evaluation Cycles', 'singular' => 'evaluation cycle', 'module' => 'pis',
    'subtitle' => 'Semi-annual or annual appraisal periods. Weights combine the quantitative (KPI) and qualitative (competency) tracks, and the self / supervisor / peer (360°) sources.',
    'order' => 'period_start DESC',
    'fields' => [
        'name' => ['label' => 'Cycle name', 'required' => true],
        'cycle_type' => ['label' => 'Type', 'type' => 'select', 'options' => ['semi_annual' => 'Semi-annual', 'annual' => 'Annual'], 'required' => true],
        'period_start' => ['label' => 'Period start', 'type' => 'date', 'required' => true],
        'period_end' => ['label' => 'Period end', 'type' => 'date', 'required' => true],
        'quant_weight' => ['label' => 'Quantitative weight (%)', 'type' => 'number', 'required' => true],
        'qual_weight' => ['label' => 'Qualitative weight (%)', 'type' => 'number', 'required' => true],
        'supervisor_weight' => ['label' => 'Supervisor rating weight (%)', 'type' => 'number', 'required' => true],
        'self_weight' => ['label' => 'Self-assessment weight (%)', 'type' => 'number', 'required' => true],
        'peer_weight' => ['label' => 'Peer / 360° weight (%)', 'type' => 'number', 'required' => true],
        'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['open' => 'Open', 'closed' => 'Closed'], 'required' => true],
    ],
    'defaults' => ['quant_weight' => 60, 'qual_weight' => 40, 'supervisor_weight' => 60, 'self_weight' => 20, 'peer_weight' => 20, 'status' => 'open', 'cycle_type' => 'semi_annual'],
    'validate' => function ($d) {
        $e = [];
        if ((float)$d['quant_weight'] + (float)$d['qual_weight'] != 100) {
            $e[] = 'Quantitative + qualitative weights must total 100%.';
        }
        if ($d['period_end'] < $d['period_start']) {
            $e[] = 'Period end must be after period start.';
        }
        return $e;
    },
    'list' => ['name', 'cycle_type', 'period_start', 'period_end', 'quant_weight', 'qual_weight', 'status'],
    'format' => ['status' => fn($v) => badge($v)],
    'row_actions' => fn($r) => '<a class="btn btn-sm btn-primary" href="' . url('evaluations', ['cycle_id' => $r['id']]) . '">Evaluations ('
        . (int)val('SELECT COUNT(*) FROM evaluations WHERE cycle_id = ?', [$r['id']]) . ')</a> <button class="btn btn-sm" data-modal-open="genModal" data-field-cycle-id="' . $r['id'] . '">Generate forms</button>',
    'bottom_html' => '<div class="modal" id="genModal"><div class="modal-box"><h2>Generate evaluation forms</h2><form method="post">' . csrf_field()
        . '<input type="hidden" name="_action" value="generate"><input type="hidden" name="cycle_id" value=""><div class="field"><label>Unit (optional)</label><select name="ou">'
        . options(org_unit_options(), null, 'All active employees') . '</select></div><p class="small muted">Creates one form per employee assigned to the immediate supervisor; KPIs from the previous cycle are copied as a template.</p>'
        . '<div class="form-actions"><button class="btn btn-primary">Generate</button><button type="button" class="btn" data-modal-close>Cancel</button></div></form></div></div>',
    'edit_roles' => ['hr'],
]);
