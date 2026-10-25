<?php
/**
 * Generic reference-table editor.
 * Used for all "editable reference data" (statutory tables, leave types, shifts, holidays, ...)
 * so HR / payroll administrators can apply official rate revisions without code changes.
 */

function crud_page(array $cfg): void
{
    $page    = $cfg['page'];
    $table   = $cfg['table'];
    $fields  = $cfg['fields'];
    $list    = $cfg['list'] ?? array_keys($fields);
    // what the user may do here follows their permissions on this page (app/permissions.php)
    $canAdd  = can_page($page, 'create') && ($cfg['can_add'] ?? true);
    $canEdit = can_page($page, 'edit');
    $canDel  = can_page($page, 'delete') && ($cfg['can_delete'] ?? true);
    $canAny  = $canAdd || $canEdit || $canDel;
    $module  = $cfg['module'] ?? 'setup';
    // the CSV import facility of the table, shown on the form itself (app/engine/dataimport.php)
    $dimp = !empty($cfg['import']) ? dimp_def((string)$cfg['import'], $cfg) : null;
    if ($dimp) {
        dimp_handle($dimp);
    }

    // active filters (GET) -> WHERE
    $where = [];
    $params = [];
    $filterVals = [];
    foreach ($cfg['filters'] ?? [] as $col => $f) {
        $v = get($col, $f['default'] ?? null);
        $filterVals[$col] = $v;
        if ($v !== null && $v !== '') {
            $where[] = "`$col` = ?";
            $params[] = $v;
        }
    }
    if (!empty($cfg['where'])) {
        $where[] = $cfg['where'];
        $params = array_merge($params, $cfg['where_params'] ?? []);
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    if (is_post() && $canAny) {
        verify_csrf();
        $action = post('_action');
        $id = (int)post('id', 0);
        if ($action === 'set_default' && $id && !empty($cfg['set_default']) && $canEdit) {
            q("UPDATE `$table` SET is_default = IF(id = ?, 1, 0)", [$id]);
            audit($module, 'set_default', $table, $id);
            flash('success', ucfirst($cfg['singular'] ?? 'Record') . ' set as default.');
            redirect($page, array_filter($filterVals, fn($v) => $v !== null && $v !== ''));
        }
        if ($action === 'delete' && $id && $canDel) {
            $old = row("SELECT * FROM `$table` WHERE id = ?", [$id]);
            try {
                if (!empty($cfg['before_delete'])) {
                    ($cfg['before_delete'])($id, $old);
                }
                q("DELETE FROM `$table` WHERE id = ?", [$id]);
                audit($module, 'delete', $table, $id, $old);
                flash('success', ($cfg['singular'] ?? 'Record') . ' deleted.');
            } catch (PDOException $e) {
                flash('error', 'Cannot delete: this record is referenced by other data. Deactivate it instead.');
            } catch (RuntimeException $e) {
                flash('error', $e->getMessage());
            }
            redirect($page, array_filter($filterVals, fn($v) => $v !== null && $v !== ''));
        }
        if ($action === 'save' && ($id ? $canEdit : $canAdd)) {
            $data = [];
            $errors = [];
            foreach ($fields as $col => $f) {
                if (!empty($f['readonly']) || ($f['type'] ?? '') === 'heading') {
                    continue;
                }
                $type = $f['type'] ?? 'text';
                if ($type === 'checkbox') {
                    $data[$col] = post($col) ? 1 : 0;
                    continue;
                }
                if ($type === 'multicheck') {
                    $vals = (array)post($col, []);
                    $data[$col] = implode(',', array_map('strval', $vals));
                    if (!empty($f['required']) && !$vals) {
                        $errors[] = $f['label'] . ' is required.';
                    }
                    continue;
                }
                $v = post($col);
                if ($v === null || $v === '') {
                    if (!empty($f['required'])) {
                        $errors[] = $f['label'] . ' is required.';
                    }
                    $data[$col] = array_key_exists('default', $f) ? $f['default'] : null;
                    continue;
                }
                if (in_array($type, ['number', 'money'], true)) {
                    $v = str_replace(',', '', $v);
                    if (!is_numeric($v)) {
                        $errors[] = $f['label'] . ' must be a number.';
                        continue;
                    }
                }
                if ($type === 'time' && strlen($v) === 5) {
                    $v .= ':00';
                }
                $data[$col] = $v;
            }
            if (!empty($cfg['validate'])) {
                $errors = array_merge($errors, (array)($cfg['validate'])($data, $id));
            }
            if ($errors) {
                foreach ($errors as $er) {
                    flash('error', $er);
                }
                $_SESSION['crud_old'] = $_POST;
                redirect($page, array_merge(array_filter($filterVals, fn($v) => $v !== null && $v !== ''), $id ? ['edit' => $id] : ['new' => 1]));
            }
            try {
                if ($id) {
                    $old = row("SELECT * FROM `$table` WHERE id = ?", [$id]);
                    update($table, $data, 'id = ?', [$id]);
                    audit($module, 'update', $table, $id, diff_fields($old ?: [], $data));
                } else {
                    $id = insert($table, $data);
                    audit($module, 'create', $table, $id, $data);
                }
                if (!empty($cfg['after_save'])) {
                    ($cfg['after_save'])($id, $data);
                }
                flash('success', ($cfg['singular'] ?? 'Record') . ' saved.');
            } catch (PDOException $e) {
                $msg = $e->getCode() == 23000 ? 'Duplicate or invalid reference: ' . $e->errorInfo[2] : $e->getMessage();
                flash('error', 'Save failed. ' . $msg);
                $_SESSION['crud_old'] = $_POST;
                redirect($page, $id ? ['edit' => $id] : ['new' => 1]);
            }
            redirect($page, array_filter($filterVals, fn($v) => $v !== null && $v !== ''));
        }
    }

    $order = $cfg['order'] ?? 'id';
    $records = rows("SELECT * FROM `$table`$whereSql ORDER BY $order", $params);

    $editId = (int)get('edit', 0);
    $isNew = (bool)get('new');
    $editing = null;
    if ($editId) {
        $editing = row("SELECT * FROM `$table` WHERE id = ?", [$editId]);
    } elseif ($isNew) {
        $editing = $cfg['defaults'] ?? [];
        foreach ($filterVals as $k => $v) {
            if ($v !== null && $v !== '' && !isset($editing[$k])) {
                $editing[$k] = $v;
            }
        }
    }
    if (!empty($_SESSION['crud_old'])) {
        $editing = array_merge((array)$editing, $_SESSION['crud_old']);
        unset($_SESSION['crud_old']);
    }

    $actions = '';
    if ($canAdd) {
        $actions .= '<a class="btn btn-primary" href="' . h(url($page, array_merge(array_filter($filterVals, fn($v) => $v !== null && $v !== ''), ['new' => 1]))) . '">+ Add ' . h($cfg['singular'] ?? 'record') . '</a>';
    }
    $actions .= $cfg['actions'] ?? '';
    page_header($cfg['title'], ['subtitle' => $cfg['subtitle'] ?? '', 'actions' => $actions]);

    if (!empty($cfg['top_html'])) {
        echo is_callable($cfg['top_html']) ? ($cfg['top_html'])() : $cfg['top_html'];
    }

    if ($editing !== null && ($editId ? $canEdit : $canAdd)) {
        crud_form($cfg, $editing, $filterVals);
    }
    if ($dimp) {
        echo dimp_panel($dimp);
    }

    // filters
    if (!empty($cfg['filters'])) {
        echo '<form class="filters card" method="get"><input type="hidden" name="p" value="' . h($page) . '">';
        foreach ($cfg['filters'] as $col => $f) {
            echo '<label>' . h($f['label']) . ' <select name="' . h($col) . '" onchange="this.form.submit()">'
                . options($f['options'], $filterVals[$col], $f['all'] ?? 'All') . '</select></label>';
        }
        echo '</form>';
    }

    echo '<div class="card"><div class="table-tools"><input type="search" class="input table-search" placeholder="Search..." data-target="crud-table"><span class="muted">' . count($records) . ' record(s)</span></div>';
    echo '<div class="table-wrap"><table class="table" id="crud-table"><thead><tr>';
    foreach ($list as $col) {
        $f = $fields[$col] ?? ['label' => label($col)];
        $cls = in_array($f['type'] ?? '', ['number', 'money'], true) ? ' class="num"' : '';
        echo '<th' . $cls . '>' . h($f['list_label'] ?? $f['label']) . '</th>';
    }
    if ($canEdit || $canDel || !empty($cfg['row_actions'])) {
        echo '<th class="actions"></th>';
    }
    echo '</tr></thead><tbody>';
    if (!$records) {
        echo empty_row(count($list) + 1);
    }
    foreach ($records as $r) {
        echo '<tr>';
        foreach ($list as $col) {
            $f = $fields[$col] ?? [];
            $v = $r[$col] ?? null;
            $type = $f['type'] ?? 'text';
            if (isset($cfg['format'][$col])) {
                $out = ($cfg['format'][$col])($v, $r);
            } elseif ($type === 'select') {
                $out = h($f['options'][$v] ?? ($v === null ? ($f['placeholder'] ?? '') : $v));
            } elseif ($type === 'checkbox') {
                $out = $v ? '<span class="badge badge-green">Yes</span>' : '<span class="badge badge-gray">No</span>';
            } elseif ($type === 'money') {
                $out = $v === null ? '<span class="muted">—</span>' : money($v);
            } elseif ($type === 'date') {
                $out = h(fmt_date($v));
            } elseif ($type === 'time') {
                $out = h($v ? date('h:i A', strtotime($v)) : '');
            } else {
                $out = h($v);
            }
            if ($col === $list[0] && !empty($cfg['set_default']) && !empty($r['is_default'])) {
                $out .= ' <span class="badge badge-blue">Default</span>';
            }
            $cls = in_array($type, ['number', 'money'], true) ? ' class="num"' : '';
            echo "<td$cls>$out</td>";
        }
        if ($canEdit || $canDel || !empty($cfg['row_actions'])) {
            echo '<td class="actions">';
            if (!empty($cfg['row_actions'])) {
                echo ($cfg['row_actions'])($r);
            }
            if ($canEdit && !empty($cfg['set_default']) && empty($r['is_default'])) {
                echo '<form method="post" class="inline">' . csrf_field()
                    . '<input type="hidden" name="_action" value="set_default"><input type="hidden" name="id" value="' . (int)$r['id'] . '">'
                    . '<button class="btn btn-sm btn-ghost" title="Use as the default">Set default</button></form>';
            }
            if ($canEdit) {
                echo '<a class="btn btn-sm" href="' . h(url($page, array_merge(array_filter($filterVals, fn($v) => $v !== null && $v !== ''), ['edit' => $r['id']]))) . '">Edit</a>';
            }
            {
                if ($canDel) {
                    echo '<form method="post" class="inline" data-confirm="Delete this record?">' . csrf_field()
                        . '<input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="' . (int)$r['id'] . '">'
                        . '<button class="btn btn-sm btn-danger-outline">Delete</button></form>';
                }
            }
            echo '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div></div>';

    if (!empty($cfg['bottom_html'])) {
        echo is_callable($cfg['bottom_html']) ? ($cfg['bottom_html'])() : $cfg['bottom_html'];
    }
    page_footer();
}

function crud_form(array $cfg, array $rec, array $filterVals): void
{
    $page = $cfg['page'];
    $id = (int)($rec['id'] ?? 0);
    echo '<div class="card form-card"><h2>' . ($id ? 'Edit' : 'New') . ' ' . h($cfg['singular'] ?? 'record') . '</h2>';
    echo '<form method="post" action="' . h(url($page, array_filter($filterVals, fn($v) => $v !== null && $v !== ''))) . '">' . csrf_field();
    echo '<input type="hidden" name="_action" value="save"><input type="hidden" name="id" value="' . $id . '">';
    echo '<div class="form-grid">';
    foreach ($cfg['fields'] as $col => $f) {
        echo form_field($col, $f, $rec[$col] ?? ($f['default'] ?? null));
    }
    echo '</div><div class="form-actions"><button class="btn btn-primary">Save</button> <a class="btn" href="'
        . h(url($page, array_filter($filterVals, fn($v) => $v !== null && $v !== ''))) . '">Cancel</a></div></form></div>';
}

/** Render a single labelled form control. */
function form_field(string $name, array $f, $value): string
{
    $type = $f['type'] ?? 'text';
    $label = h($f['label'] ?? label($name));
    $req = !empty($f['required']) ? ' required' : '';
    $ro = !empty($f['readonly']) ? ' disabled' : '';
    $help = !empty($f['help']) ? '<small class="help">' . h($f['help']) . '</small>' : '';
    $wide = !empty($f['wide']) || $type === 'textarea' ? ' wide' : '';
    $attrs = $f['attrs'] ?? '';
    if ($type === 'heading') {
        return '<div class="field wide form-section-title">' . $label . ($help ? ' ' . $help : '') . '</div>';
    }
    $html = '<div class="field' . $wide . '">';
    switch ($type) {
        case 'checkbox':
            $html .= '<label class="check"><input type="checkbox" name="' . h($name) . '" value="1"' . checked($value) . $ro . ' ' . $attrs . '> ' . $label . '</label>' . $help;
            break;
        case 'select':
            if (!empty($f['quick']) && function_exists('quickref_select')) {
                // New / Edit of the referenced record without leaving the form (app/engine/quickref.php)
                $html .= '<label>' . $label . '</label>' . quickref_select((string)$f['quick'], $name, $f, $value, $req . $ro . ' ' . $attrs) . $help;
                break;
            }
            $html .= '<label>' . $label . '</label><select name="' . h($name) . '"' . $req . $ro . ' ' . $attrs . '>'
                . options($f['options'], $value, array_key_exists('placeholder', $f) ? $f['placeholder'] : (empty($f['required']) ? '—' : null)) . '</select>' . $help;
            break;
        case 'multicheck':
            $vals = is_array($value) ? $value : array_filter(explode(',', (string)$value), fn($x) => $x !== '');
            $html .= '<label>' . $label . '</label><div class="checks">';
            foreach ($f['options'] as $v => $l) {
                $html .= '<label class="check"><input type="checkbox" name="' . h($name) . '[]" value="' . h($v) . '"' . checked(in_array((string)$v, array_map('strval', $vals), true)) . '> ' . h($l) . '</label>';
            }
            $html .= '</div>' . $help;
            break;
        case 'textarea':
            $html .= '<label>' . $label . '</label><textarea name="' . h($name) . '" rows="' . (int)($f['rows'] ?? 3) . '"' . $req . $ro . ' ' . $attrs . '>' . h($value) . '</textarea>' . $help;
            break;
        case 'money':
        case 'number':
            $step = $f['step'] ?? ($type === 'money' ? '0.01' : 'any');
            $html .= '<label>' . $label . '</label><input type="number" step="' . h($step) . '" name="' . h($name) . '" value="' . h($value) . '"' . $req . $ro . ' ' . $attrs . '>' . $help;
            break;
        case 'time':
            $html .= '<label>' . $label . '</label><input type="time" name="' . h($name) . '" value="' . h($value ? substr((string)$value, 0, 5) : '') . '"' . $req . $ro . ' ' . $attrs . '>' . $help;
            break;
        default:
            $html .= '<label>' . $label . '</label><input type="' . h($type) . '" name="' . h($name) . '" value="' . h($value) . '"' . $req . $ro . ' ' . $attrs . '>' . $help;
    }
    return $html . '</div>';
}

/**
 * Render the editable fields of a group of rows from the `settings` table.
 * Used by System Settings, Payroll Parameters and AMS Policies.
 */
function settings_fields(array $rows, string $gridClass = 'form-grid'): string
{
    ob_start(); ?>
    <div class="<?= h($gridClass) ?>">
    <?php foreach ($rows as $s):
        $t = (string)$s['input_type'];
        $name = h($s['skey']); ?>
      <div class="field<?= in_array($t, ['textarea'], true) ? ' wide' : '' ?>">
        <?php if ($t === 'bool'): ?>
          <label class="check"><input type="checkbox" name="<?= $name ?>" value="1"<?= checked($s['svalue'] === '1') ?>> <?= h($s['label']) ?></label>
        <?php elseif (strpos((string)$s['skey'], 'gl_') === 0 && $t === 'text' && function_exists('quickref_select')):
            // GL account of the payroll journal: a list of the Payroll Code Table, with New / Edit
            $glOpts = pairs("SELECT code, CONCAT(code, ' — ', name) FROM gl_accounts WHERE is_active = 1 ORDER BY code"); ?>
          <label><?= h($s['label']) ?></label><?= quickref_select('gl_accounts', (string)$s['skey'], ['options' => $glOpts], (string)$s['svalue'], '') ?>
        <?php elseif (strpos($t, 'select:') === 0):
            $opts = [];
            foreach (explode(',', substr($t, 7)) as $o) {
                [$k, $l] = array_pad(explode('=', $o, 2), 2, $o);
                $opts[$k] = $l;
            } ?>
          <label><?= h($s['label']) ?></label><select name="<?= $name ?>"><?= options($opts, $s['svalue']) ?></select>
        <?php elseif ($t === 'textarea'): ?>
          <label><?= h($s['label']) ?></label><textarea name="<?= $name ?>" rows="3"><?= h((string)$s['svalue']) ?></textarea>
        <?php elseif ($t === 'password'): ?>
          <label><?= h($s['label']) ?></label><input type="password" name="<?= $name ?>" placeholder="<?= $s['svalue'] ? '•••••••• (unchanged)' : '' ?>" autocomplete="new-password">
        <?php else: ?>
          <label><?= h($s['label']) ?></label><input type="<?= $t === 'number' ? 'number" step="any' : ($t === 'time' ? 'time' : 'text') ?>" name="<?= $name ?>" value="<?= h((string)$s['svalue']) ?>">
        <?php endif; ?>
        <?php if ($s['help']): ?><small class="help"><?= h($s['help']) ?></small><?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
    <?php
    return (string)ob_get_clean();
}

/** Save the posted values of the given settings rows. Returns the number of changes. */
function settings_save(array $rows, string $module = 'admin'): int
{
    $changes = [];
    foreach ($rows as $s) {
        $k = $s['skey'];
        $t = (string)$s['input_type'];
        if ($t === 'bool') {
            $v = post($k) ? '1' : '0';
        } elseif ($t === 'password') {
            $v = post($k);
            if ($v === null || $v === '') {
                continue;
            }
        } else {
            if (!array_key_exists($k, $_POST)) {
                continue;
            }
            $v = (string)post($k, '');
        }
        if ((string)$s['svalue'] !== (string)$v) {
            set_setting($k, $v);
            $changes[$k] = $t === 'password' ? '***' : ['from' => $s['svalue'], 'to' => $v];
        }
    }
    if ($changes) {
        audit($module, 'settings_update', 'settings', null, $changes);
    }
    return count($changes);
}
