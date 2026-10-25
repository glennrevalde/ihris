<?php
/**
 * One memorandum / announcement: the document as it prints, who made it and when, and the actions
 * Edit, Copy (new draft), Print, Issue, Archive and Delete. ?print=1 opens the printable page.
 */
$id = (int)get('id', 0);
$m = $id ? memo_row($id) : null;
if (!$m) {
    throw new RuntimeException('Memo not found.');
}
if (is_post()) {
    $act = (string)post('_action');
    if ($act === 'issue' && $m['status'] === 'draft') {
        update('memos', ['status' => 'issued', 'issued_at' => now(), 'issued_by' => user_id()], 'id = ?', [$id]);
        audit('pis', 'memo_issue', 'memos', $id);
        flash('success', $m['memo_no'] . ' issued.' . ($m['audience'] === 'all' ? ' It is now listed in My Workspace, Memos & Announcements.' : ''));
    } elseif ($act === 'archive' && $m['status'] !== 'archived') {
        update('memos', ['status' => 'archived', 'updated_by' => user_id(), 'updated_at' => now()], 'id = ?', [$id]);
        audit('pis', 'memo_archive', 'memos', $id);
        flash('success', $m['memo_no'] . ' archived. It stays on file and can still be viewed, copied and printed.');
    } elseif ($act === 'unarchive' && $m['status'] === 'archived') {
        update('memos', ['status' => $m['issued_at'] ? 'issued' : 'draft', 'updated_by' => user_id(), 'updated_at' => now()], 'id = ?', [$id]);
        audit('pis', 'memo_unarchive', 'memos', $id);
        flash('success', $m['memo_no'] . ' restored.');
    } elseif ($act === 'delete') {
        if ($m['status'] !== 'draft') {
            flash('error', 'Only drafts can be deleted. Archive an issued memo instead.');
            redirect('memo_view', ['id' => $id]);
        }
        q('DELETE FROM memos WHERE id = ?', [$id]);
        audit('pis', 'memo_delete', 'memos', $id, ['memo_no' => $m['memo_no'], 'subject' => $m['subject']]);
        flash('success', 'Draft ' . $m['memo_no'] . ' deleted.');
        redirect('memos');
    }
    redirect('memo_view', ['id' => $id]);
}

if (get('print')) {
    $d = memo_design();
    audit('pis', 'memo_print', 'memos', $id);
    print_header($m['memo_no'] . ' — ' . memo_fill($m['subject'], $m, false));
    $size = ['A4' => 'A4', 'letter' => 'letter', 'legal' => '8.5in 13in'][$d['memo_paper']] ?? 'A4';
    echo '<style>@page { size: ' . $size . '; margin: ' . (int)$d['memo_margin'] . 'mm; } .print-page.memo-print-page { width: auto; max-width: 100%; padding: 0; background: transparent; box-shadow: none; }</style>';
    echo '<script>document.querySelector(".print-page").classList.add("memo-print-page");</script>';
    echo memo_document($m, $d, true);
    print_footer();
    exit;
}

$actions = '<a class="btn" href="' . url('memo_view', ['id' => $id, 'print' => 1]) . '" target="_blank" rel="noopener">Print</a>';
if (can('memos.create')) {
    $actions .= '<a class="btn" href="' . url('memos', ['copy' => $id]) . '">Copy</a>';
}
if (can('memos.edit') && ($m['status'] === 'draft' || can('memos.approve'))) {
    $actions .= '<a class="btn" href="' . url('memos', ['edit' => $id]) . '">Edit</a>';
}
if ($m['status'] === 'draft' && can('memos.approve')) {
    $actions .= '<form method="post" class="inline" data-confirm="Issue this memo now?">' . csrf_field() . '<input type="hidden" name="_action" value="issue"><button class="btn btn-primary">Issue</button></form>';
}
page_header($m['memo_no'] . ': ' . memo_fill($m['subject'], $m, false), ['back' => url('memos'), 'actions' => $actions,
    'subtitle' => MEMO_KINDS[$m['kind']] . ', ' . MEMO_STATUSES[$m['status']] . ($m['audience'] === 'all' ? ', for everyone' : ', for the addressees')]);
?>
<div class="grid grid-2-1">
  <div class="memo-wrap"><?= memo_document($m) ?></div>
  <div>
    <div class="card"><h2>Record</h2>
      <div class="memo-meta">
        <div><span>Number</span><?= h($m['memo_no']) ?></div>
        <div><span>Status</span><span class="memo-state s-<?= h($m['status']) ?>"><?= h(MEMO_STATUSES[$m['status']]) ?></span></div>
        <div><span>Made by</span><?= h($m['created_name'] ?: 'system') ?></div>
        <div><span>Date created</span><?= h(fmt_dt($m['created_at'])) ?></div>
        <?php if ($m['updated_at'] && $m['updated_at'] !== $m['created_at']): ?><div><span>Last changed</span><?= h(fmt_dt($m['updated_at'])) ?><?= $m['updated_name'] ? ' by ' . h($m['updated_name']) : '' ?></div><?php endif; ?>
        <?php if ($m['issued_at']): ?><div><span>Issued</span><?= h(fmt_dt($m['issued_at'])) ?><?= $m['issued_name'] ? ' by ' . h($m['issued_name']) : '' ?></div><?php endif; ?>
        <div><span>Template</span><?= h($m['template_name'] ?: 'none') ?></div>
        <?php if ($m['copied_from']): ?><div><span>Copied from</span><a href="<?= url('memo_view', ['id' => $m['copied_from']]) ?>"><?= h((string)val('SELECT memo_no FROM memos WHERE id = ?', [$m['copied_from']]) ?: '#' . $m['copied_from']) ?></a></div><?php endif; ?>
      </div>
    </div>
    <div class="card"><h2>Actions</h2>
      <p class="small muted">Print opens the page to print or save as PDF with the design of Templates &amp; design. Copy starts a new draft with the same text.</p>
      <div class="form-actions">
        <?php if ($m['status'] !== 'archived' && can('memos.edit')): ?>
          <form method="post" class="inline" data-confirm="Archive this memo?"><?= csrf_field() ?><input type="hidden" name="_action" value="archive"><button class="btn">Archive</button></form>
        <?php elseif ($m['status'] === 'archived' && can('memos.edit')): ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="_action" value="unarchive"><button class="btn">Restore</button></form>
        <?php endif; ?>
        <?php if ($m['status'] === 'draft' && can('memos.delete')): ?>
          <form method="post" class="inline" data-confirm="Delete this draft?"><?= csrf_field() ?><input type="hidden" name="_action" value="delete"><button class="btn btn-danger-outline">Delete draft</button></form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php page_footer();
