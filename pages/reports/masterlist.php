<?php
/**
 * Report: Employee 201 File Masterlisting. A list of employees whose columns can be changed: by
 * default Employee ID / Code, Name, Date Hired, Position and Basic Salary; any field of the employee
 * information can be added, removed or moved. Layouts can be saved for everyone (and one made the
 * default). Filters, sorting and grouping; print (letterhead) and CSV export follow the layout shown.
 */
$fields = ml_fields();
$layouts = ml_layouts();
$byId = [];
foreach ($layouts as $l) {
    $byId[(int)$l['id']] = $l;
}

if (is_post()) {
    $act = (string)post('_action');
    $keepQs = (string)post('qs');
    parse_str($keepQs, $back);
    unset($back['p']);
    try {
        if ($act === 'save_layout') {
            $name = mb_substr(trim((string)post('layout_name')), 0, 80);
            if ($name === '') {
                throw new RuntimeException('Enter a name for the layout.');
            }
            $cols = ml_columns((string)post('cols'));
            $opts = json_encode(['sort' => (string)post('sort'), 'dir' => post('dir') === 'desc' ? 'desc' : 'asc', 'group' => array_key_exists((string)post('group'), ML_GROUPS) ? (string)post('group') : '']);
            $existing = row('SELECT * FROM report_layouts WHERE report_key = ? AND name = ?', [ML_REPORT_KEY, $name]);
            if (post('make_default')) {
                q('UPDATE report_layouts SET is_default = 0 WHERE report_key = ?', [ML_REPORT_KEY]);
            }
            if ($existing) {
                update('report_layouts', ['col_list' => implode(',', $cols), 'opts' => $opts, 'updated_at' => now()] + (post('make_default') ? ['is_default' => 1] : []), 'id = ?', [$existing['id']]);
                $lid = (int)$existing['id'];
            } else {
                $lid = insert('report_layouts', ['report_key' => ML_REPORT_KEY, 'name' => $name, 'col_list' => implode(',', $cols), 'opts' => $opts,
                    'is_default' => post('make_default') ? 1 : 0, 'created_by' => user_id(), 'created_at' => now(), 'updated_at' => now()]);
            }
            audit('reports', 'masterlist_layout_save', 'report_layouts', $lid, ['name' => $name, 'columns' => $cols, 'default' => (bool)post('make_default')]);
            flash('success', 'Layout "' . $name . '" saved' . (post('make_default') ? ' and made the default' : '') . '.');
            $back = ['layout' => $lid] + array_diff_key($back, ['cols' => 1, 'sort' => 1, 'dir' => 1, 'group' => 1, 'layout' => 1]);
        } elseif ($act === 'delete_layout' || $act === 'default_layout') {
            $l = $byId[(int)post('layout_id')] ?? null;
            if (!$l) {
                throw new RuntimeException('Choose a saved layout.');
            }
            if ($act === 'delete_layout') {
                q('DELETE FROM report_layouts WHERE id = ?', [$l['id']]);
                audit('reports', 'masterlist_layout_delete', 'report_layouts', (int)$l['id'], ['name' => $l['name']]);
                flash('success', 'Layout "' . $l['name'] . '" deleted.');
                unset($back['layout']);
            } else {
                q('UPDATE report_layouts SET is_default = IF(id = ?, 1, 0) WHERE report_key = ?', [$l['id'], ML_REPORT_KEY]);
                audit('reports', 'masterlist_layout_default', 'report_layouts', (int)$l['id'], ['name' => $l['name']]);
                flash('success', 'Layout "' . $l['name'] . '" is now the default of the report.');
            }
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('rpt_201_master', $back);
}

// layout: explicit columns, else the chosen saved layout, else the default layout, else the standard columns
$layoutId = (int)get('layout', 0);
$layout = $layoutId ? ($byId[$layoutId] ?? null) : null;
if (!$layout && get('cols') === null && !get('col') && !isset($_GET['layout'])) {   // layout= (empty) asks for the standard columns
    foreach ($layouts as $l) {
        if ($l['is_default']) {
            $layout = $l;
        }
    }
}
$lopt = $layout ? (json_decode((string)$layout['opts'], true) ?: []) : [];
if (get('cols') !== null && get('cols') !== '') {
    $cols = ml_columns((string)get('cols'));
} elseif (get('col')) {
    $cols = ml_columns((array)get('col'));
} else {
    $cols = ml_columns($layout ? (string)$layout['col_list'] : ML_DEFAULT_COLUMNS);
}
$sort = (string)(get('sort') ?? ($lopt['sort'] ?? 'name'));
$sort = isset($fields[$sort]) ? $sort : 'name';
$dir = (get('dir') ?? ($lopt['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
$group = (string)(get('group') ?? ($lopt['group'] ?? ''));
$group = array_key_exists($group, ML_GROUPS) ? $group : '';
$f = ml_filters($_GET);
$rows = ml_rows($cols, $f, $sort, $dir, $group);
$gk = ML_GROUPS[$group][1] ?? null;
$moneyCols = array_values(array_filter($cols, fn($c) => $fields[$c][3] === 'money'));
$filterKeep = array_filter($f, fn($v) => $v !== null && $v !== '');
if ($filterKeep['status'] === 'active') {
    unset($filterKeep['status']);
}
$keep = $filterKeep + array_filter(['cols' => implode(',', $cols), 'sort' => $sort !== 'name' ? $sort : null, 'dir' => $dir !== 'asc' ? $dir : null,
    'group' => $group ?: null, 'layout' => $layout && $layoutId ? $layoutId : null]);

$filterText = [];
if ($f['branch_id']) {
    $filterText[] = (string)val("SELECT CONCAT(COALESCE(branch_code, code), ' ', name) FROM branches WHERE id = ?", [$f['branch_id']]);
}
if ($f['org_unit_id']) {
    $filterText[] = org_path($f['org_unit_id']);
}
foreach (['employee_type_id' => 'emp_types', 'employee_group_id' => 'emp_groups', 'class_group_id' => 'class_groups'] as $k => $t) {
    if ($f[$k]) {
        $filterText[] = (string)val("SELECT name FROM `$t` WHERE id = ?", [$f[$k]]);
    }
}
if ($f['employment_status']) {
    $filterText[] = ucwords(str_replace('_', ' ', $f['employment_status']));
}
$filterText[] = ['active' => 'Active employees', 'separated' => 'Separated employees', 'all' => 'Active and separated'][$f['status']];
if ($f['hired_from'] || $f['hired_to']) {
    $filterText[] = 'Hired ' . ($f['hired_from'] ? fmt_date($f['hired_from']) : 'any time') . ' to ' . ($f['hired_to'] ? fmt_date($f['hired_to']) : 'today');
}
if ($f['q'] !== '') {
    $filterText[] = 'Matching "' . $f['q'] . '"';
}

if (get('export')) {
    audit('reports', 'export_masterlist', 'report', null, ['columns' => $cols, 'rows' => count($rows)]);
    $out = [];
    foreach ($rows as $r) {
        $out[] = array_map(fn($c) => ml_value($r[$c] ?? null, $fields[$c][3], true), $cols);
    }
    csv_download('employee_201_masterlist_' . date('Ymd') . '.csv', array_map(fn($c) => $fields[$c][0], $cols), $out);
}

/** The report table (screen and print). */
$table = function (bool $print) use ($rows, $cols, $fields, $gk, $moneyCols): string {
    $h = '<div class="table-wrap ml-wrap"><table class="table ml-table' . ($print ? ' doc-table' : '') . '" id="mlTable"><thead><tr><th class="num ml-no">#</th>';
    foreach ($cols as $c) {
        $t = $fields[$c][3];
        $h .= '<th class="' . (in_array($t, ['money', 'num', 'int'], true) ? 'num' : '') . ($t === 'long' ? ' ml-long' : '') . '">' . h($fields[$c][0]) . '</th>';
    }
    $h .= '</tr></thead><tbody>';
    $n = 0;
    $cur = null;
    $sum = array_fill_keys($moneyCols, 0.0);
    $gsum = $sum;
    $gcount = 0;
    $span = count($cols) + 1;
    $groupFoot = function ($label, $count, $gs) use ($cols, $fields, $moneyCols): string {
        $h = '<tr class="ml-sub"><td colspan="2">' . h($label) . ': ' . $count . ' employee(s)</td>';
        foreach (array_slice($cols, 1) as $c) {
            $h .= '<td class="num">' . (in_array($c, $moneyCols, true) ? money($gs[$c]) : '') . '</td>';
        }
        return $h . '</tr>';
    };
    foreach ($rows as $r) {
        if ($gk) {
            $g = (string)(ml_value($r[$gk] ?? null, $fields[$gk][3]) ?: 'Not assigned');
            if ($g !== $cur) {
                if ($cur !== null) {
                    $h .= $groupFoot($cur, $gcount, $gsum);
                }
                $cur = $g;
                $gcount = 0;
                $gsum = array_fill_keys($moneyCols, 0.0);
                $h .= '<tr class="ml-group"><td colspan="' . $span . '">' . h($g) . '</td></tr>';
            }
        }
        $n++;
        $gcount++;
        $h .= '<tr><td class="num muted ml-no">' . $n . '</td>';
        foreach ($cols as $c) {
            $t = $fields[$c][3];
            $val = ml_value($r[$c] ?? null, $t);
            if ($c === 'name' && !$print) {
                $val = '<a href="' . h(url('employee_view', ['id' => $r['__id']])) . '">' . h($val) . '</a>';
            } else {
                $val = h($val);
            }
            if (in_array($c, $moneyCols, true)) {
                $sum[$c] += (float)($r[$c] ?? 0);
                $gsum[$c] += (float)($r[$c] ?? 0);
            }
            $h .= '<td class="' . (in_array($t, ['money', 'num', 'int'], true) ? 'num' : '') . ($t === 'long' ? ' ml-long' : '') . ($t === 'date' || in_array($c, ['name', 'employee_no'], true) ? ' nowrap' : '') . '">' . $val . '</td>';
        }
        $h .= '</tr>';
    }
    if ($gk && $cur !== null) {
        $h .= $groupFoot($cur, $gcount, $gsum);
    }
    if (!$rows) {
        $h .= empty_row($span, 'No employee matches these filters.');
    }
    $h .= '</tbody>';
    if ($rows) {
        $h .= '<tfoot><tr><td colspan="2">Total: ' . count($rows) . ' employee(s)</td>';
        foreach (array_slice($cols, 1) as $c) {
            $h .= '<td class="num">' . (in_array($c, $moneyCols, true) ? money($sum[$c]) : '') . '</td>';
        }
        $h .= '</tr></tfoot>';
    }
    return $h . '</table></div>';
};

if (get('print')) {
    audit('reports', 'print_masterlist', 'report', null, ['columns' => $cols, 'rows' => count($rows)]);
    $wide = count($cols) > 6 || array_intersect(array_map(fn($c) => $fields[$c][3], $cols), ['long']);
    print_header('Employee 201 File Masterlisting', (bool)$wide, count($cols) > 9 ? 'legal' : '');
    echo company_letterhead($f['branch_id'] ? row('SELECT * FROM branches WHERE id = ?', [$f['branch_id']]) : null);
    echo '<h1>Employee 201 File Masterlisting</h1>';
    echo '<div class="doc-meta"><div><strong>' . h(implode(', ', $filterText)) . '</strong>' . ($layout ? ', layout ' . h($layout['name']) : '') . '</div><div>Printed ' . h(fmt_dt(now())) . '</div></div>';
    echo $table(true);
    echo signatory_block();
    print_footer();
    exit;
}

$canConfig = can('rpt_201_master.configure');
page_header('Employee 201 File Masterlisting', [
    'subtitle' => 'List of employees from the 201 file. Change the columns with Customize columns: any field of the employee information can be shown.',
    'actions' => '<a class="btn" target="_blank" href="' . h(url('rpt_201_master', $keep + ['print' => 1])) . '">Print</a><a class="btn" href="' . h(url('rpt_201_master', $keep + ['export' => 1])) . '">Export CSV</a>',
]);
$groupsOf = [];
foreach ($fields as $k => $d) {
    $groupsOf[$d[1]][$k] = $d[0];
}
?>
<form method="get" class="card filters" id="mlForm">
  <input type="hidden" name="p" value="rpt_201_master">
  <input type="hidden" name="cols" id="mlCols" value="<?= h(implode(',', $cols)) ?>">
  <?php if ($layout && $layoutId): ?><input type="hidden" name="layout" value="<?= (int)$layoutId ?>"><?php endif; ?>
  <label>Company / Branch<select name="branch_id"><?= options(pairs("SELECT id, CONCAT(COALESCE(branch_code, code), ' — ', name) FROM branches ORDER BY company_code, branch_code, code"), $f['branch_id'], 'All companies') ?></select></label>
  <label class="grow">Organizational unit<select name="org_unit_id"><?= options(org_unit_options(), $f['org_unit_id'], 'All units') ?></select></label>
  <label>Employee type<select name="employee_type_id"><?= options(pairs("SELECT id, CONCAT(code, ' — ', name) FROM emp_types ORDER BY code"), $f['employee_type_id'], 'All types') ?></select></label>
  <label>Employee group<select name="employee_group_id"><?= options(emp_group_options(), $f['employee_group_id'], 'All groups') ?></select></label>
  <label>Class group<select name="class_group_id"><?= options(pairs("SELECT id, CONCAT(code, ' — ', name) FROM class_groups ORDER BY code"), $f['class_group_id'], 'All classes') ?></select></label>
  <label>Employment status<select name="employment_status"><?= options(EMPLOYMENT_STATUSES, $f['employment_status'], 'All') ?></select></label>
  <label>Status<select name="status"><?= options(['active' => 'Active', 'separated' => 'Separated', 'all' => 'Active and separated'], $f['status']) ?></select></label>
  <label>Hired from<input type="date" name="hired_from" value="<?= h((string)$f['hired_from']) ?>"></label>
  <label>To<input type="date" name="hired_to" value="<?= h((string)$f['hired_to']) ?>"></label>
  <label class="grow">Find<input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="ID, name or position"></label>
  <label>Sort by<select name="sort"><?= options(array_map(fn($c) => $fields[$c][0], array_combine($cols, $cols)) + ['name' => 'Name'], $sort) ?></select></label>
  <label>Order<select name="dir"><?= options(['asc' => 'Ascending', 'desc' => 'Descending'], $dir) ?></select></label>
  <label>Group by<select name="group"><?= options(array_map(fn($g) => $g[0], ML_GROUPS), $group) ?></select></label>
  <button class="btn btn-primary">Apply</button>

  <details class="ml-custom"<?= get('custom') ? ' open' : '' ?>>
    <summary><span>Customize columns</span><small class="muted"><?= count($cols) ?> shown<?= $layout ? ', layout ' . h($layout['name']) : '' ?></small></summary>
    <div class="ml-custom-body">
      <div class="ml-shown">
        <p class="label-line">Shown columns, in order</p>
        <ol class="ml-order" id="mlOrder">
          <?php foreach ($cols as $c): ?>
            <li data-key="<?= h($c) ?>"><span><?= h($fields[$c][0]) ?></span>
              <button type="button" class="icon-btn" data-ml-move="-1" aria-label="Move up">&uarr;</button>
              <button type="button" class="icon-btn" data-ml-move="1" aria-label="Move down">&darr;</button>
              <button type="button" class="icon-btn" data-ml-remove aria-label="Remove">&times;</button></li>
          <?php endforeach; ?>
        </ol>
        <div class="ml-shown-actions">
          <button type="button" class="btn btn-sm" data-ml-reset="<?= h(implode(',', ML_DEFAULT_COLUMNS)) ?>">Standard columns</button>
          <button type="submit" class="btn btn-sm btn-primary" name="custom" value="1">Show these columns</button>
        </div>
      </div>
      <div class="ml-available">
        <div class="ml-avail-head"><p class="label-line">Employee information fields</p><input type="search" id="mlFind" placeholder="Find a field" aria-label="Find a field"></div>
        <div class="ml-groups">
          <?php foreach ($groupsOf as $gname => $items): ?>
            <fieldset class="ml-group-box"><legend><?= h($gname) ?></legend>
              <?php foreach ($items as $k => $lbl): ?>
                <label class="check"><input type="checkbox" value="<?= h($k) ?>" data-ml-col data-label="<?= h($lbl) ?>" <?= checked(in_array($k, $cols, true)) ?>> <?= h($lbl) ?></label>
              <?php endforeach; ?>
            </fieldset>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </details>
</form>

<div class="card ml-layouts">
  <div class="ml-layout-row">
    <form method="get" class="ml-layout-pick">
      <input type="hidden" name="p" value="rpt_201_master">
      <?php foreach ($filterKeep as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h((string)$v) ?>"><?php endforeach; ?>
      <label>Saved layout<select name="layout" data-autosubmit><?= options(array_map(fn($l) => $l['name'] . ($l['is_default'] ? ' (default)' : ''), $byId), $layout ? (int)$layout['id'] : null, 'Standard columns') ?></select></label>
      <noscript><button class="btn">Load</button></noscript>
    </form>
    <?php if ($canConfig): ?>
    <form method="post" class="ml-layout-save" id="mlSave">
      <?= csrf_field() ?><input type="hidden" name="_action" value="save_layout"><input type="hidden" name="qs" value="<?= h(http_build_query($keep)) ?>">
      <input type="hidden" name="cols" value="<?= h(implode(',', $cols)) ?>" data-ml-cols-copy><input type="hidden" name="sort" value="<?= h($sort) ?>"><input type="hidden" name="dir" value="<?= h($dir) ?>"><input type="hidden" name="group" value="<?= h($group) ?>">
      <label>Save the columns shown as<input name="layout_name" maxlength="80" value="<?= h($layout['name'] ?? '') ?>" placeholder="Layout name" required></label>
      <label class="check"><input type="checkbox" name="make_default" value="1" <?= checked(!empty($layout['is_default'])) ?>> Default layout</label>
      <button class="btn btn-primary">Save layout</button>
    </form>
    <?php if ($layout): ?>
    <form method="post" class="ml-layout-del">
      <?= csrf_field() ?><input type="hidden" name="layout_id" value="<?= (int)$layout['id'] ?>"><input type="hidden" name="qs" value="<?= h(http_build_query($keep)) ?>">
      <?php if (!$layout['is_default']): ?><button class="btn" name="_action" value="default_layout">Make default</button><?php endif; ?>
      <button class="btn btn-danger-outline" name="_action" value="delete_layout" data-confirm="Delete the layout <?= h($layout['name']) ?>?">Delete layout</button>
    </form>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<div class="card flush">
  <div class="card-head pad"><div><h2>Employee 201 File Masterlisting</h2><div class="sub"><?= h(implode(', ', $filterText)) ?>, <?= count($rows) ?> employee(s)</div></div>
    <input type="search" class="table-search" data-target="mlTable" placeholder="Find in the list"></div>
  <?= $table(false) ?>
</div>
<?php page_footer();
