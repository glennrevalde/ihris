<?php
/**
 * My Workspace > Memos & Announcements (v14): the issued memoranda and announcements of the company
 * meant for everyone, newest first. ?id=N opens one, ?id=N&print=1 prints it.
 */
$id = (int)get('id', 0);
$visible = "m.status = 'issued' AND m.audience = 'all'";
if ($id) {
    $m = row("SELECT m.* FROM memos m WHERE m.id = ? AND $visible", [$id]);
    if (!$m) {
        throw new RuntimeException('This memo is not available.');
    }
    if (get('print')) {
        $d = memo_design();
        print_header($m['memo_no'] . ' — ' . memo_fill($m['subject'], $m, false));
        $size = ['A4' => 'A4', 'letter' => 'letter', 'legal' => '8.5in 13in'][$d['memo_paper']] ?? 'A4';
        echo '<style>@page { size: ' . $size . '; margin: ' . (int)$d['memo_margin'] . 'mm; } .print-page { width: auto; max-width: 100%; padding: 0; background: transparent; box-shadow: none; }</style>';
        echo memo_document($m, $d, true);
        print_footer();
        exit;
    }
    page_header(memo_fill($m['subject'], $m, false), ['back' => url('my_memos'), 'subtitle' => MEMO_KINDS[$m['kind']] . ' ' . $m['memo_no'] . ', ' . fmt_date($m['memo_date']),
        'actions' => '<a class="btn" href="' . url('my_memos', ['id' => $id, 'print' => 1]) . '" target="_blank" rel="noopener">Print</a>']);
    echo '<div class="memo-wrap">' . memo_document($m) . '</div>';
    page_footer();
    return;
}
$q = trim((string)get('q', ''));
$params = [];
$where = $visible;
if ($q !== '') {
    $where .= ' AND (m.subject LIKE ? OR m.body LIKE ? OR m.memo_no LIKE ?)';
    $params = ['%' . $q . '%', '%' . $q . '%', '%' . $q . '%'];
}
$list = rows("SELECT m.* FROM memos m WHERE $where ORDER BY m.memo_date DESC, m.id DESC LIMIT 200", $params);
page_header('Memos & Announcements', ['subtitle' => 'The memoranda and announcements of the company, newest first.']);
?>
<form class="card filters" method="get"><input type="hidden" name="p" value="my_memos">
  <label class="grow">Find<input type="search" name="q" value="<?= h($q) ?>" placeholder="Subject, number or text"></label><button class="btn btn-primary">Find</button></form>
<div class="card flush">
  <?php if (!$list): ?><div class="empty-state"><h3>No memo or announcement yet</h3></div><?php endif; ?>
  <?php foreach ($list as $m): ?>
    <div class="memo-list-item">
      <div><span class="memo-tag k-<?= h($m['kind']) ?>"><?= h(MEMO_KINDS[$m['kind']]) ?></span> <span class="muted"><?= h($m['memo_no']) ?>, <?= h(fmt_date($m['memo_date'])) ?></span>
        <h3><a href="<?= url('my_memos', ['id' => $m['id']]) ?>"><?= h(memo_fill($m['subject'], $m, false)) ?></a></h3></div>
      <div class="actions nowrap"><a class="btn btn-sm" href="<?= url('my_memos', ['id' => $m['id']]) ?>">Read</a></div>
      <div class="memo-excerpt"><?= h(memo_excerpt(memo_fill($m['body'], $m, false))) ?></div>
    </div>
  <?php endforeach; ?>
</div>
<?php page_footer();
