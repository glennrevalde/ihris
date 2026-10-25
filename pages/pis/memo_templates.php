<?php
/**
 * Memo Templates & Design (v14): the proformas and sample formats of the memoranda / announcements
 * (add, edit, deactivate, delete, restore the samples) and the configurable design of the printed memo.
 *   ?tab=design   design settings with a live sample
 *   ?edit=ID / ?new=1   template form
 */
memo_seed_templates();
$tab = get('tab') === 'design' ? 'design' : 'templates';

if (is_post()) {
    $act = (string)post('_action');
    $id = (int)post('id', 0);
    try {
        if ($act === 'design') {
            $changes = [];
            foreach (memo_design_fields() as $k => [$label, $type, $def, $opt]) {
                if ($type === 'bool') {
                    $v = post($k) ? '1' : '0';
                } else {
                    $v = trim((string)post($k, $def));
                    if ($type === 'select' && !isset($opt[$v])) {
                        $v = $def;
                    }
                    if ($type === 'color' && !preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
                        $v = $def;
                    }
                    if (in_array($k, ['memo_prefix', 'announcement_prefix'], true)) {
                        $v = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $v)) ?: $def;
                    }
                    $v = mb_substr($v, 0, 250);
                }
                if ((string)setting($k, '') !== $v) {
                    set_setting($k, $v);
                    $changes[$k] = $v;
                }
            }
            if ($changes) {
                audit('pis', 'memo_design_update', 'settings', null, $changes);
            }
            flash('success', 'Memo design saved' . ($changes ? '' : ' (no change)') . '.');
            redirect('memo_templates', ['tab' => 'design']);
        }
        if ($act === 'save') {
            $data = [
                'name' => mb_substr(trim((string)post('name')), 0, 120), 'kind' => isset(MEMO_KINDS[post('kind')]) ? (string)post('kind') : 'memo',
                'category' => mb_substr(trim((string)post('category')), 0, 60) ?: 'General', 'subject' => mb_substr(trim((string)post('subject')), 0, 200),
                'body' => memo_clean_html((string)post('body')), 'is_active' => post('is_active') ? 1 : 0, 'updated_at' => now(),
            ];
            if ($data['name'] === '') {
                throw new RuntimeException('Enter the name of the template.');
            }
            if ($id) {
                $old = row('SELECT * FROM memo_templates WHERE id = ?', [$id]);
                update('memo_templates', $data, 'id = ?', [$id]);
                audit('pis', 'memo_template_update', 'memo_templates', $id, diff_fields($old ?: [], $data));
            } else {
                $code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]+/', '-', $data['name']), 0, 24)) . '-' . substr(bin2hex(random_bytes(2)), 0, 4);
                $id = insert('memo_templates', $data + ['code' => $code, 'is_sample' => 0, 'sort_order' => 900, 'created_by' => user_id(), 'created_at' => now()]);
                audit('pis', 'memo_template_create', 'memo_templates', $id, ['name' => $data['name']]);
            }
            flash('success', 'Template "' . $data['name'] . '" saved.');
        } elseif ($act === 'toggle' && $id) {
            q('UPDATE memo_templates SET is_active = 1 - is_active, updated_at = ? WHERE id = ?', [now(), $id]);
            audit('pis', 'memo_template_toggle', 'memo_templates', $id);
            flash('success', 'Template updated.');
        } elseif ($act === 'delete' && $id) {
            $t = row('SELECT * FROM memo_templates WHERE id = ?', [$id]);
            if ($t && (int)$t['is_sample'] === 1) {
                throw new RuntimeException('A sample format cannot be deleted; deactivate it instead (Restore samples brings deleted samples back).');
            }
            q('UPDATE memos SET template_id = NULL WHERE template_id = ?', [$id]);
            q('DELETE FROM memo_templates WHERE id = ?', [$id]);
            audit('pis', 'memo_template_delete', 'memo_templates', $id, ['name' => $t['name'] ?? '']);
            flash('success', 'Template deleted.');
        } elseif ($act === 'restore') {
            $n = memo_seed_templates(true);
            flash('success', $n ? "$n sample format(s) restored." : 'Every sample format is already in the list.');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('memo_templates');
}

page_header('Memo Templates & Design', [
    'subtitle' => 'The common proforma and the sample formats of the memoranda and announcements, and how the printed memo looks.',
    'back' => url('memos'),
    'actions' => '<a class="btn btn-primary" href="' . url('memos', ['new' => 1]) . '">+ New memo / announcement</a>',
]);
echo '<nav class="tabs-nav"><a class="tab-link' . ($tab === 'templates' ? ' active' : '') . '" href="' . h(url('memo_templates')) . '">Templates</a>'
    . '<a class="tab-link' . ($tab === 'design' ? ' active' : '') . '" href="' . h(url('memo_templates', ['tab' => 'design'])) . '">Design</a></nav>';

if ($tab === 'design'):
    $d = memo_design();
    $sample = ['kind' => 'memo', 'memo_no' => memo_next_no('memo'), 'memo_date' => today(), 'to_line' => 'All Employees', 'from_line' => 'Human Resources Department',
        'cc_line' => 'Department Heads', 'subject' => 'Sample memorandum', 'status' => 'draft', 'signatory_name' => 'Juan dela Cruz', 'signatory_title' => 'HR Manager',
        'body' => '<p>This sample shows the design chosen on the left: font, size, line spacing, accent color, letterhead, title, paper, footer and acknowledgment block.</p><ul><li>Change a setting.</li><li>Press Save design.</li></ul><p>For your information and guidance.</p>'];
    ?>
    <div class="memo-split">
      <form method="post" class="card" data-design-form>
        <?= csrf_field() ?><input type="hidden" name="_action" value="design">
        <h2>Design of the printed memo</h2>
        <div class="form-grid">
        <?php foreach (memo_design_fields() as $k => [$label, $type, $def, $opt]): ?>
          <div class="field<?= in_array($k, ['memo_footer', 'memo_header'], true) ? ' wide' : '' ?>">
            <?php if ($type === 'bool'): ?>
              <label class="check"><input type="checkbox" name="<?= h($k) ?>" value="1" data-design="<?= h($k) ?>"<?= checked($d[$k] === '1') ?>> <?= h($label) ?></label><?= $opt ? '<small class="help">' . h($opt) . '</small>' : '' ?>
            <?php elseif ($type === 'select'): ?>
              <label><?= h($label) ?></label><select name="<?= h($k) ?>" data-design="<?= h($k) ?>"><?= options($opt, $d[$k]) ?></select>
            <?php elseif ($type === 'color'): ?>
              <label><?= h($label) ?></label><input type="color" name="<?= h($k) ?>" value="<?= h($d[$k]) ?>" data-design="<?= h($k) ?>"><small class="help"><?= h($opt) ?></small>
            <?php else: ?>
              <label><?= h($label) ?></label><input name="<?= h($k) ?>" value="<?= h($d[$k]) ?>" maxlength="250" data-design="<?= h($k) ?>"><?= $opt ? '<small class="help">' . h($opt) . '</small>' : '' ?>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        </div>
        <p class="small muted">The logo of the letterhead is the company logo of System Settings, Company logo on reports<?= report_logo_uri() ? '' : ' (none uploaded yet)' ?>.</p>
        <div class="form-actions"><button class="btn btn-primary">Save design</button></div>
      </form>
      <div class="memo-wrap" data-design-preview><?= memo_document($sample, $d) ?></div>
    </div>
    <script>
    (function () {
      var f = document.querySelector('[data-design-form]'), w = document.querySelector('[data-design-preview]');
      if (!f || !w) return;
      var doc = w.querySelector('.memo-doc');
      var v = function (k) { var el = f.querySelector('[name="' + k + '"]'); return el ? (el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value) : ''; };
      var swap = function (prefix, val) { doc.className = doc.className.split(' ').filter(function (c) { return c.indexOf(prefix) !== 0; }).join(' ') + ' ' + prefix + val; };
      var upd = function () {
        doc.style.setProperty('--m-font', v('memo_font'));
        doc.style.setProperty('--m-size', v('memo_font_size') + 'pt');
        doc.style.setProperty('--m-lh', v('memo_line_height'));
        doc.style.setProperty('--m-accent', v('memo_accent'));
        doc.style.setProperty('--m-margin', v('memo_margin') + 'mm');
        swap('memo-paper-', v('memo_paper').toLowerCase());
        swap('memo-title-', v('memo_title_style'));
        swap('memo-head-', v('memo_header'));
        doc.classList.toggle('is-draft', v('memo_watermark') === '1');
        var lh = doc.querySelector('.memo-lh'); if (lh) lh.style.display = v('memo_header') === 'none' ? 'none' : '';
        var lg = doc.querySelector('.memo-logo'); if (lg) lg.style.display = v('memo_header') === 'name_only' ? 'none' : '';
        var t = doc.querySelector('.memo-title'); if (t) t.style.display = v('memo_title_style') === 'none' ? 'none' : '';
        var a = doc.querySelector('.memo-ack'); if (a) a.style.display = v('memo_show_ack') === '1' ? '' : 'none';
        var ft = doc.querySelector('.memo-foot');
        if (!ft && v('memo_footer')) { ft = document.createElement('footer'); ft.className = 'memo-foot'; doc.appendChild(ft); }
        if (ft) { ft.textContent = v('memo_footer'); ft.style.display = v('memo_footer') ? '' : 'none'; }
        var no = doc.querySelector('.memo-no'); if (no) no.textContent = (v('memo_prefix') || 'MEMO').toUpperCase().replace(/[^A-Z0-9]/g, '') + no.textContent.replace(/^[A-Z0-9]+/, '');
      };
      f.addEventListener('input', upd); f.addEventListener('change', upd); upd();
    })();
    </script>
    <?php
    page_footer();
    return;
endif;

$edit = (int)get('edit', 0) ? row('SELECT * FROM memo_templates WHERE id = ?', [(int)get('edit')]) : null;
$isNew = (bool)get('new');
$tpls = rows('SELECT t.*, (SELECT COUNT(*) FROM memos m WHERE m.template_id = t.id) AS used FROM memo_templates t ORDER BY t.category, t.sort_order, t.name');
$byCat = [];
foreach ($tpls as $t) {
    $byCat[$t['category']][] = $t;
}
?>
<?php if ($edit || $isNew): $e = $edit ?: ['name' => '', 'kind' => 'memo', 'category' => 'General', 'subject' => '', 'body' => '<p></p>', 'is_active' => 1]; ?>
  <form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="_action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <h2><?= $edit ? 'Edit template' : 'New template' ?></h2>
    <div class="form-grid">
      <div class="field"><label>Name</label><input name="name" value="<?= h($e['name']) ?>" maxlength="120" required></div>
      <div class="field"><label>Type</label><select name="kind"><?= options(MEMO_KINDS, $e['kind']) ?></select></div>
      <div class="field"><label>Category</label><input name="category" value="<?= h($e['category']) ?>" maxlength="60" list="tplCats"><datalist id="tplCats"><?php foreach (array_keys($byCat) as $c): ?><option value="<?= h($c) ?>"><?php endforeach; ?></datalist></div>
      <div class="field"><label class="check"><input type="checkbox" name="is_active" value="1"<?= checked((int)$e['is_active'] === 1) ?>> Active (offered on the memo form)</label></div>
      <div class="field wide"><label>Subject</label><input name="subject" value="<?= h($e['subject']) ?>" maxlength="200"></div>
      <div class="field wide"><label>Text</label><?= memo_editor('body', (string)$e['body'], 'tplBody') ?>
        <small class="help">Use [brackets] for what the writer completes, and the fields of Insert field (such as {company} or {date}) for what the system fills.</small></div>
    </div>
    <div class="form-actions"><button class="btn btn-primary">Save template</button> <a class="btn" href="<?= url('memo_templates') ?>">Cancel</a></div>
  </form>
<?php endif; ?>
<div class="card">
  <div class="card-head"><div><h2>Templates</h2><div class="sub"><?= count($tpls) ?> template(s): the common proformas, the sample formats of the usual company occasions and your own.</div></div>
    <div class="toolbar"><a class="btn btn-primary btn-sm" href="<?= url('memo_templates', ['new' => 1]) ?>">+ New template</a>
      <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="_action" value="restore"><button class="btn btn-sm">Restore samples</button></form></div></div>
  <?php foreach ($byCat as $cat => $list): ?>
    <h3 class="tpl-cat"><?= h($cat) ?></h3>
    <div class="tpl-grid">
      <?php foreach ($list as $t): ?>
        <div class="tpl-card<?= (int)$t['is_active'] ? '' : ' is-off' ?>">
          <span class="memo-tag k-<?= h($t['kind']) ?>"><?= h(MEMO_KINDS[$t['kind']]) ?><?= (int)$t['is_sample'] ? ', sample' : '' ?><?= (int)$t['is_active'] ? '' : ', inactive' ?></span>
          <h4><?= h($t['name']) ?></h4>
          <p><?= h(memo_excerpt($t['body'], 110)) ?></p>
          <small class="muted">Used by <?= (int)$t['used'] ?> memo(s)</small>
          <div class="tpl-actions">
            <?php if ((int)$t['is_active']): ?><a class="btn btn-sm btn-primary" href="<?= url('memos', ['new' => 1, 'template' => $t['id']]) ?>">Use</a><?php endif; ?>
            <a class="btn btn-sm" href="<?= url('memo_templates', ['edit' => $t['id']]) ?>">Edit</a>
            <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="_action" value="toggle"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn btn-sm"><?= (int)$t['is_active'] ? 'Deactivate' : 'Activate' ?></button></form>
            <?php if (!(int)$t['is_sample']): ?><form method="post" class="inline" data-confirm="Delete this template?"><?= csrf_field() ?><input type="hidden" name="_action" value="delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn btn-sm btn-danger-outline">Delete</button></form><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php page_footer();
