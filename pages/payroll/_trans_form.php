<?php
/**
 * Shared rendering helpers for the payroll / attendance TRANSACTION forms
 * (Payroll Transaction Setup, Payroll Transaction II, Attendance Summary Manual Entry I & II).
 * Included by trans_entry.php, trans_entry2.php and trans_batch.php.
 */

if (!function_exists('pt_matrix_rows')) {

    /** Duty / overtime matrix of the M.A.D.A.S. transaction form. */
    function pt_matrix_rows(bool $night = false): array
    {
        $p = $night ? 'np_' : '';
        return [
            ['Regular day',      [$night ? 'np_reg' : null, $p . ($night ? 'reg_ot' : 'ot_reg'), null, null]],
            ['Day off',          [$p . 'rd' . ($night ? '' : '_hrs'), $p . 'rd_ot', null, null]],
            ['Legal holiday',    [$p . 'lh' . ($night ? '' : '_hrs'), $p . 'lh_ot', $p . 'lh_rd', $p . 'lh_rd_ot']],
            ['Special holiday',  [$p . 'sh' . ($night ? '' : '_hrs'), $p . 'sh_ot', $p . 'sh_rd', $p . 'sh_rd_ot']],
        ];
    }

    define('PT_REGULAR_FIELDS', ['hours_work', 'absent_hrs', 'late_hrs', 'undertime_hrs', 'vl_hrs', 'sl_hrs', 'el_hrs', 'ol_hrs', 'pm_hrs', 'holiday_hrs']);
    define('PT_OTHER_FIELDS', ['amt_13th', 'service_charge', 'sl_conversion', 'vl_conversion', 'meal_comp', 'meal_comp_ot', 'tickets', 'other_ded']);

    /** One numeric input of a transaction field. */
    function pt_input(string $field, $value, bool $readonly = false, string $extraClass = ''): string
    {
        $meta = PT_FIELDS[$field] ?? [$field, $field, 'others'];
        $step = $field === 'tickets' ? '1' : '0.01';
        $v = $field === 'tickets' ? (string)(int)$value : number_format((float)$value, 2, '.', '');
        return sprintf('<input type="number" step="%s" min="0" name="%s" value="%s" data-group="%s" %s class="%s" title="%s"%s>',
            $step, h($field), h($v), h($meta[2]), $meta[2] !== 'others' ? 'data-hours="1"' : '', h(trim('num ' . $extraClass)), h($meta[0]), $readonly ? ' readonly' : '');
    }

    /** Label + input. */
    function pt_field(string $field, array $t, bool $ro = false): string
    {
        $meta = PT_FIELDS[$field] ?? [$field, $field, 'others'];
        return '<div class="field"><label for="f_' . h($field) . '">' . h($meta[0]) . '</label>' . pt_input($field, $t[$field] ?? 0, $ro) . '</div>';
    }

    /** Duty / night-premium matrix table. */
    function pt_matrix(array $t, bool $night, bool $ro = false): string
    {
        $h = '<table class="matrix"><thead><tr><th class="rowh"></th><th>' . ($night ? 'Reg.' : 'Duty') . '</th><th>Overtime</th><th>On day-off</th><th>Day-off OT</th></tr></thead><tbody>';
        foreach (pt_matrix_rows($night) as $row) {
            $h .= '<tr><th class="rowh">' . h($row[0]) . '</th>';
            foreach ($row[1] as $f) {
                $h .= $f === null ? '<td class="na"></td>' : '<td>' . pt_input($f, $t[$f] ?? 0, $ro) . '</td>';
            }
            $h .= '</tr>';
        }
        return $h . '</tbody></table>';
    }

    /**
     * The complete hours + others entry form body.
     * $emp / $perfect: when given for a monthly-salaried employee, the leave fields auto-adjust
     * the hours work (perfect attendance hours less the leaves) on screen and on save.
     */
    function pt_form_body(array $t, bool $ro = false, ?array $emp = null, ?float $perfect = null): string
    {
        $monthly = $emp && ($emp['rate_type'] ?? '') === 'monthly' && empty($emp['timekeeping_exempt']) && $perfect !== null && $perfect > 0;
        ob_start(); ?>
        <div class="pt-body"<?= $monthly ? ' data-perfect-hours="' . h(number_format($perfect, 2, '.', '')) . '" data-monthly="1"' : '' ?>>
        <fieldset class="box tone-reg">
          <legend>Duty hours</legend>
          <?php if ($monthly): ?>
            <p class="pt-rule">Monthly-salaried: perfect attendance for this period is <strong><?= h(number_format($perfect, 2)) ?> hrs</strong>.
              The leaves encoded below are deducted from the hours work automatically (hours work = <span data-perfect-calc><?= h(number_format($perfect, 2)) ?> less leaves</span>).</p>
          <?php endif; ?>
          <div class="hours-grid">
            <?php foreach (PT_REGULAR_FIELDS as $f) {
                echo pt_field($f, $t, $ro);
            } ?>
          </div>
        </fieldset>
        <div class="grid pt-matrices">
          <fieldset class="box tone-ot">
            <legend>Overtime &amp; premium hours</legend>
            <div class="matrix-wrap"><?= pt_matrix($t, false, $ro) ?></div>
          </fieldset>
          <fieldset class="box tone-np">
            <legend>Night premium hours</legend>
            <div class="matrix-wrap"><?= pt_matrix($t, true, $ro) ?></div>
          </fieldset>
        </div>
        <fieldset class="box tone-others">
          <legend>Others</legend>
          <div class="hours-grid">
            <?php foreach (PT_OTHER_FIELDS as $f) {
                echo pt_field($f, $t, $ro);
            } ?>
          </div>
        </fieldset>
        </div>
        <?php
        return (string)ob_get_clean();
    }

    /**
     * Period + filter bar shared by every transaction form.
     * $live (v16): regions of the page ("#id,#id") refreshed while typing in Find employee or
     * changing a filter (incremental search, no Apply needed). Empty = Apply reloads the page.
     */
    function pt_filter_bar(string $route, ?array $period, array $filters, array $extra = [], string $live = ''): string
    {
        $periods = pt_period_options();
        $branches = pairs("SELECT id, CONCAT(COALESCE(branch_code, code), ' — ', name) FROM branches WHERE is_active = 1 ORDER BY company_code, branch_code, code");
        $groups = emp_group_options();
        $types = pairs("SELECT id, CONCAT(code, ' — ', name) FROM emp_types WHERE is_active = 1 ORDER BY code");
        $units = pairs("SELECT id, name FROM org_units WHERE is_active = 1 ORDER BY name");
        ob_start(); ?>
        <form method="get" class="card filters" style="margin-bottom:16px"<?= $live !== '' ? ' data-live="' . h($live) . '"' : '' ?>>
          <input type="hidden" name="p" value="<?= h($route) ?>">
          <?php foreach ($extra as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h((string)$v) ?>"><?php endforeach; ?>
          <label>Period
            <select name="cutoff_id" data-autosubmit><?= options($periods, $period['id'] ?? null, $periods ? null : 'No period defined') ?></select>
          </label>
          <?= multi_pick('branch_id', 'Co. / Branch', $branches, $filters['branch_id'] ?? [], 'All companies') ?>
          <?= multi_pick('org_unit_id', 'Department', $units, $filters['org_unit_id'] ?? [], 'All departments') ?>
          <?= multi_pick('employee_group_id', 'Employee group', $groups, $filters['employee_group_id'] ?? [], 'All groups') ?>
          <label>Employee type
            <select name="employee_type_id"><?= options($types, $filters['employee_type_id'], 'All types') ?></select>
          </label>
          <label class="grow">Find employee
            <input type="search" name="q" value="<?= h((string)$filters['q']) ?>" placeholder="Type a name, employee no. or biometric ID" autocomplete="off">
          </label>
          <button class="btn btn-primary">Apply</button>
        </form>
        <?php
        return (string)ob_get_clean();
    }

    /** Status pill of a period. */
    function pt_period_badge(?array $period): string
    {
        if (!$period) {
            return '<span class="badge badge-gray">No period</span>';
        }
        $m = ['open' => ['blue', 'Open'], 'summarized' => ['amber', 'Summarized'], 'approved' => ['green', 'Closed for payroll'], 'locked' => ['gray', 'Paid (period closed)']];
        [$tone, $label] = $m[$period['status']] ?? ['gray', $period['status']];
        if (in_array($period['status'], ['open', 'summarized'], true)) {
            $pr = att_close_progress((int)$period['id']);
            if ($pr['closed']) {
                $label .= ', ' . $pr['closed'] . ' of ' . $pr['total'] . ' closed';
            }
        }
        return '<span class="badge badge-' . $tone . '">' . h($label) . '</span>';
    }

    /** Small rate summary of an employee (used beside the entry form). */
    function pt_rate_chips(array $emp): string
    {
        $r = employee_rates($emp);
        return '<div class="chips">'
            . '<span class="chip">Monthly ₱' . number_format($r['monthly'], 2) . '</span>'
            . '<span class="chip">Daily ₱' . number_format($r['daily'], 2) . '</span>'
            . '<span class="chip">Hourly ₱' . number_format($r['hourly'], 2) . '</span>'
            . '<span class="chip">' . number_format($r['hpd'], 2) . ' hrs/day, factor ' . rtrim(rtrim(number_format($r['factor'], 4), '0'), '.') . '</span>'
            . '<span class="chip">' . h(label($emp['rate_type'])) . '-rated</span></div>';
    }
}
