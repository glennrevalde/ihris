<?php
/**
 * Memoranda / Announcements (v14): the saved memos of the company with who made them and when, and the
 * form to write one from a proforma or a sample format (app/engine/memos.php).
 *   ?new=1[&template=ID]  new memo      ?edit=ID  edit      ?copy=ID  new draft copied from a memo
 */
memo_seed_templates();
$editId = (int)get('edit', 0);
$copyId = (int)get('copy', 0);
$isForm = get('new') || $editId || $copyId;

if (is_post()) {
    $act = (string)post('_action');
    $id = (int)post('id', 0);
    try {
        if (in_array($act, ['save', 'save_issue'], true)) {
            $kind = isset(MEMO_KINDS[post('kind')]) ? (string)post('kind') : 'memo';
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)post('memo_date')) ? (string)post('memo_date') : today();
            $subject = trim((string)post('subject'));
            $body = memo_clean_html((string)post('body'));
            if ($subject === '') {
                throw new RuntimeException('Enter the subject.');
            }
            if (trim(strip_tags($body)) === '') {
                throw new RuntimeException('Write the text of the ' . strtolower(MEMO_KINDS[$kind]) . '.');
            }
            $branch = (int)post('branch_id') ?: null;
            $data = [
                'kind' => $kind, 'template_id' => (int)post('template_id') ?: null, 'subject' => mb_substr($subject, 0, 200),
                'to_line' => mb_substr(trim((string)post('to_line')), 0, 255) ?: null, 'from_line' => mb_substr(trim((string)post('from_line')), 0, 255) ?: null,
                'cc_line' => mb_substr(trim((string)post('cc_line')), 0, 255) ?: null, 'memo_date' => $date, 'body' => $body,
                'signatory_name' => mb_substr(trim((string)post('signatory_name')), 0, 120) ?: null, 'signatory_title' => mb_substr(trim((string)post('signatory_title')), 0, 120) ?: null,
                'branch_id' => $branch, 'audience' => isset(MEMO_AUDIENCE[post('audience')]) ? (string)post('audience') : 'internal',
                'updated_by' => user_id(), 'updated_at' => now(),
            ];
            if ($id) {
                $old = row('SELECT * FROM memos WHERE id = ?', [$id]);
                if (!$old) {
                    throw new RuntimeException('Memo not found.');
                }
                if ($old['status'] !== 'draft' && !can('memos.approve')) {
                    throw new RuntimeException('An issued memo can be changed only by a user who may issue memos.');
                }
                if ($old['kind'] !== $kind && $old['status'] === 'draft') {
                    $data['memo_no'] = memo_next_no($kind, $date);
                }
                update('memos', $data, 'id = ?', [$id]);
                audit('pis', 'memo_update', 'memos', $id, diff_fields($old, $data));
            } else {
                $data += ['memo_no' => memo_next_no($kind, $date), 'status' => 'draft', 'created_by' => user_id(), 'created_at' => now(), 'copied_from' => (int)post('copied_from') ?: null];
                $id = insert('memos', $data);
                audit('pis', 'memo_create', 'memos', $id, ['memo_no' => $data['memo_no'], 'subject' => $subject, 'kind' => $kind]);
            }
            if ($act === 'save_issue') {
                if (!can('memos.approve')) {
                    throw new RuntimeException('Saved as a draft: issuing needs the Approve permission of Memoranda / Announcements.');
                }
                update('memos', ['status' => 'issued', 'issued_at' => now(), 'issued_by' => user_id()], 'id = ? AND status = ?', [$id, 'draft']);
                audit('pis', 'memo_issue', 'memos', $id);
            }
            flash('success', MEMO_KINDS[$kind] . ' ' . val('SELECT memo_no FROM memos WHERE id = ?', [$id]) . ($act === 'save_issue' ? ' saved and issued.' : ' saved.'));
            redirect('memo_view', ['id' => $id]);
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
        $_SESSION['memo_form'] = $_POST;
        redirect('memos', $id ? ['edit' => $id] : ['new' => 1]);
    }
    redirect('memos');
}

if ($isForm) {
    $src = $editId ? memo_row($editId) : ($copyId ? memo_row($copyId) : null);
    if (($editId || $copyId) && !$src) {
        throw new RuntimeException('Memo not found.');
    }
    $tpl = (int)get('template', 0) ? row('SELECT * FROM memo_templates WHERE id = ? AND is_active = 1', [(int)get('template')]) : null;
    $u = current_user();
    $v = $src ?: [
        'kind' => $tpl['kind'] ?? 'memo', 'subject' => $tpl['subject'] ?? '', 'body' => $tpl['body'] ?? '', 'template_id' => $tpl['id'] ?? null,
        'memo_date' => today(), 'to_line' => 'All Employees', 'from_line' => (string)($u['full_name'] ?? ''), 'cc_line' => '', 'audience' => 'all',
        'signatory_name' => (string)($u['full_name'] ?? ''), 'signatory_title' => '', 'branch_id' => null, 'status' => 'draft', 'memo_no' => '',
    ];
    if ($copyId) {
        $v['memo_date'] = today();
        $v['status'] = 'draft';
        $v['memo_no'] = '';
    }
    if (!empty($_SESSION['memo_form'])) {
        $v = array_merge($v, $_SESSION['memo_form']);
        unset($_SESSION['memo_form']);
    }
    $templates = rows('SELECT id, name, kind, category, subject, body FROM memo_templates WHERE is_active = 1 ORDER BY category, sort_order, name');
    $tplJson = [];
    $tplOpts = [];
    foreach ($templates as $t) {
        $tplJson[(int)$t['id']] = ['name' => $t['name'], 'kind' => $t['kind'], 'subject' => $t['subject'], 'body' => $t['body']];
        $tplOpts[$t['category']][(int)$t['id']] = $t['name'] . ' (' . MEMO_KINDS[$t['kind']] . ')';
    }
    $branches = rows('SELECT id, code, branch_code, company_name, name, address FROM branches WHERE is_active = 1 ORDER BY company_code, branch_code, code');
    $ctx = ['company' => ['name' => (string)setting('company_name'), 'address' => (string)setting('company_address')], 'companies' => [], 'memo_no' => $v['memo_no'] ?: '(number on saving)'];
    foreach ($branches as $b) {
        $ctx['companies'][(int)$b['id']] = ['name' => (string)($b['company_name'] ?: setting('company_name')), 'address' => (string)($b['address'] ?: setting('company_address'))];
    }
    $title = $editId ? 'Edit ' . strtolower(MEMO_KINDS[$v['kind']] ?? 'memo') . ' ' . $v['memo_no'] : ($copyId ? 'Copy of ' . $src['memo_no'] : 'New memorandum / announcement');
    page_header($title, ['subtitle' => 'Start from a proforma or a sample format, fill the details and save. The preview follows the design set in Memo Templates & Design.',
        'back' => $editId ? url('memo_view', ['id' => $editId]) : url('memos'),
        'actions' => '<a class="btn" href="' . url('memo_templates') . '">Templates &amp; design</a>']);
    $previewRow = $v + ['status' => 'draft'];
    if (!$editId) {
        $previewRow['memo_no'] = '';
    }
    ?>
    <div class="memo-split">
      <form method="post" class="card" data-memo-form>
        <?= csrf_field() ?><input type="hidden" name="_action" value="save"><input type="hidden" name="id" value="<?= (int)$editId ?>">
        <?php if ($copyId): ?><input type="hidden" name="copied_from" value="<?= (int)$copyId ?>"><?php endif; ?>
        <div class="form-grid">
          <div class="field wide"><label>Template</label>
            <select name="template_id" data-memo-template>
              <option value="">— Choose a proforma or a sample format —</option>
              <?php foreach ($tplOpts as $cat => $opts): ?>
                <optgroup label="<?= h($cat) ?>"><?php foreach ($opts as $tid => $tl): ?><option value="<?= (int)$tid ?>"<?= (int)($v['template_id'] ?? 0) === $tid ? ' selected' : '' ?>><?= h($tl) ?></option><?php endforeach; ?></optgroup>
              <?php endforeach; ?>
            </select>
            <small class="help">Choosing a template fills the subject and the text; edit them freely. Fields such as {company} and {date} are filled when the memo is shown.</small></div>
          <div class="field"><label>Type</label><select name="kind"><?= options(MEMO_KINDS, $v['kind']) ?></select></div>
          <div class="field"><label>Date</label><input type="date" name="memo_date" value="<?= h($v['memo_date']) ?>" required></div>
          <div class="field wide"><label>To</label><input name="to_line" value="<?= h((string)$v['to_line']) ?>" maxlength="255" placeholder="All Employees / Department Heads / [Name]"></div>
          <div class="field"><label>From</label><input name="from_line" value="<?= h((string)$v['from_line']) ?>" maxlength="255" placeholder="HR Department"></div>
          <div class="field"><label>CC <span class="muted">(optional)</span></label><input name="cc_line" value="<?= h((string)$v['cc_line']) ?>" maxlength="255"></div>
          <div class="field wide"><label>Subject</label><input name="subject" value="<?= h((string)$v['subject']) ?>" maxlength="200" required></div>
          <div class="field wide"><label>Text</label><?= memo_editor('body', (string)$v['body']) ?></div>
          <div class="field"><label>Signatory</label><input name="signatory_name" value="<?= h((string)$v['signatory_name']) ?>" maxlength="120"></div>
          <div class="field"><label>Position of the signatory</label><input name="signatory_title" value="<?= h((string)$v['signatory_title']) ?>" maxlength="120" placeholder="HR Manager"></div>
          <div class="field"><label>Letterhead (company / branch)</label><select name="branch_id"><?= options(array_column(array_map(fn($b) => ['id' => $b['id'], 'n' => ($b['branch_code'] ?: $b['code']) . ' — ' . $b['name']], $branches), 'n', 'id'), $v['branch_id'] ?? null, 'Company (System Settings)') ?></select></div>
          <div class="field"><label>Audience</label><select name="audience"><?= options(MEMO_AUDIENCE, $v['audience'] ?? 'internal') ?></select>
            <small class="help">Everyone: once issued it is listed in My Workspace, Memos &amp; Announcements.</small></div>
        </div>
        <div class="form-actions">
          <button class="btn btn-primary">Save<?= ($v['status'] ?? 'draft') === 'draft' ? ' as draft' : '' ?></button>
          <?php if (($v['status'] ?? 'draft') === 'draft' && can('memos.approve')): ?><button class="btn btn-success" name="_action" value="save_issue" data-confirm="Save and issue this memo now?">Save and issue</button><?php endif; ?>
          <a class="btn" href="<?= $editId ? url('memo_view', ['id' => $editId]) : url('memos') ?>">Cancel</a>
        </div>
      </form>
      <div class="memo-wrap" data-memo-preview data-ctx="<?= h(json_encode($ctx)) ?>" aria-label="Preview"><?= memo_document($previewRow) ?></div>
    </div>
    <script type="application/json" id="memoTemplates"><?= json_encode($tplJson, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
    <?php
    page_footer();
    return;
}

// ---- list
$f = ['kind' => (string)get('kind', ''), 'status' => (string)get('status', ''), 'year' => (string)get('year', ''), 'q' => trim((string)get('q', ''))];
$where = ['1=1'];
$params = [];
if (isset(MEMO_KINDS[$f['kind']])) {
    $where[] = 'm.kind = ?';
    $params[] = $f['kind'];
}
if (isset(MEMO_STATUSES[$f['status']])) {
    $where[] = 'm.status = ?';
    $params[] = $f['status'];
}
if (preg_match('/^\d{4}$/', $f['year'])) {
    $where[] = 'YEAR(m.memo_date) = ?';
    $params[] = (int)$f['year'];
}
if ($f['q'] !== '') {
    $where[] = '(m.memo_no LIKE ? OR m.subject LIKE ? OR m.to_line LIKE ? OR m.body LIKE ?)';
    $like = '%' . $f['q'] . '%';
    array_push($params, $like, $like, $like, $like);
}
$list = rows('SELECT m.*, cu.full_name AS created_name FROM memos m LEFT JOIN users cu ON cu.id = m.created_by WHERE ' . implode(' AND ', $where) . ' ORDER BY m.memo_date DESC, m.id DESC LIMIT 500', $params);
$years = q('SELECT DISTINCT YEAR(memo_date) FROM memos ORDER BY 1 DESC')->fetchAll(PDO::FETCH_COLUMN);
$counts = pairs('SELECT status, COUNT(*) FROM memos GROUP BY status');
$samples = rows('SELECT id, name, kind, category FROM memo_templates WHERE is_active = 1 ORDER BY sort_order LIMIT 8');
page_header('Memoranda / Announcements', [
    'subtitle' => 'Company memoranda and announcements written from a common proforma or a sample format, saved with who made them and when, and ready to view, copy or print.',
    'actions' => (can('memos.create') ? '<a class="btn btn-primary" href="' . url('memos', ['new' => 1]) . '">+ New memo / announcement</a>' : '')
        . '<a class="btn" href="' . url('memo_templates') . '">Templates &amp; design</a>',
]);
?>
<div class="stats"><?= stat_card('Drafts', (string)(int)($counts['draft'] ?? 0)) ?><?= stat_card('Issued', (string)(int)($counts['issued'] ?? 0), '', 'info') ?><?= stat_card('Archived', (string)(int)($counts['archived'] ?? 0)) ?></div>
<?php if (can('memos.create') && $samples): ?>
<div class="card"><div class="card-head"><div><h2>Start from a sample</h2><div class="sub">Common formats for the usual company occasions. All templates are in Templates &amp; design.</div></div></div>
  <div class="tpl-grid">
    <?php foreach ($samples as $t): ?>
      <div class="tpl-card"><span class="memo-tag k-<?= h($t['kind']) ?>"><?= h(MEMO_KINDS[$t['kind']]) ?></span><h4><?= h($t['name']) ?></h4><p><?= h($t['category']) ?></p>
        <div class="tpl-actions"><a class="btn btn-sm" href="<?= url('memos', ['new' => 1, 'template' => $t['id']]) ?>">Use</a></div></div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>
<form class="card filters" method="get"><input type="hidden" name="p" value="memos">
  <label>Type<select name="kind" data-autosubmit><?= options(MEMO_KINDS, $f['kind'], 'All') ?></select></label>
  <label>Status<select name="status" data-autosubmit><?= options(MEMO_STATUSES, $f['status'], 'All') ?></select></label>
  <label>Year<select name="year" data-autosubmit><?= options(array_combine(array_map('strval', $years), array_map('strval', $years)) ?: [], $f['year'], 'All') ?></select></label>
  <label class="grow">Find<input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="Number, subject, addressee or text"></label>
  <button class="btn btn-primary">Apply</button>
</form>
<div class="card flush">
  <div class="card-head pad"><div><h2>Saved memoranda and announcements</h2><div class="sub"><?= count($list) ?> record(s)</div></div></div>
  <?php if (!$list): ?><div class="empty-state"><h3>No memo yet</h3><p class="muted">Press New memo / announcement, or use one of the samples above.</p></div><?php endif; ?>
  <?php foreach ($list as $m): ?>
    <div class="memo-list-item">
      <div>
        <span class="memo-tag k-<?= h($m['kind']) ?>"><?= h(MEMO_KINDS[$m['kind']]) ?></span> <span class="muted"><?= h($m['memo_no']) ?></span>
        <h3><a href="<?= url('memo_view', ['id' => $m['id']]) ?>"><?= h(memo_fill($m['subject'], $m, false)) ?></a></h3>
        <div class="muted">Dated <?= h(fmt_date($m['memo_date'])) ?><?= $m['to_line'] ? ', to ' . h($m['to_line']) : '' ?>. Made by <?= h($m['created_name'] ?: 'system') ?> on <?= h(fmt_dt($m['created_at'])) ?></div>
      </div>
      <div class="actions nowrap"><span class="memo-state s-<?= h($m['status']) ?>"><?= h(MEMO_STATUSES[$m['status']]) ?></span>
        <a class="btn btn-sm" href="<?= url('memo_view', ['id' => $m['id']]) ?>">View</a>
        <?php if (can('memos.create')): ?><a class="btn btn-sm" href="<?= url('memos', ['copy' => $m['id']]) ?>">Copy</a><?php endif; ?>
        <a class="btn btn-sm" href="<?= url('memo_view', ['id' => $m['id'], 'print' => 1]) ?>" target="_blank" rel="noopener">Print</a></div>
      <div class="memo-excerpt"><?= h(memo_excerpt(memo_fill($m['body'], $m, false))) ?></div>
    </div>
  <?php endforeach; ?>
</div>
<?php page_footer();
