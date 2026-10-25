<?php
/**
 * Companies (Super Admin): every company of the platform with its Company Code, database, owner and status.
 */
$status = (string)get('status', '');
$q = trim((string)get('q', ''));
$where = ['1=1'];
$params = [];
if (isset(COMPANY_STATUSES[$status])) {
    $where[] = 'c.status = ?';
    $params[] = $status;
}
if ($q !== '') {
    $where[] = '(c.name LIKE ? OR c.db_name LIKE ? OR c.owner_name LIKE ? OR c.owner_username LIKE ? OR EXISTS (SELECT 1 FROM company_codes k WHERE k.company_id = c.id AND k.code = ?))';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, strtoupper($q));
}
$list = mrows('SELECT c.*, (SELECT code FROM company_codes k WHERE k.company_id = c.id AND k.status = \'active\' ORDER BY k.id DESC LIMIT 1) AS code,
                      (SELECT COUNT(*) FROM company_memberships m WHERE m.company_id = c.id AND m.status = \'active\') AS accounts,
                      (SELECT COUNT(*) FROM company_memberships m WHERE m.company_id = c.id AND m.status = \'pending\') AS pending
               FROM companies c WHERE ' . implode(' AND ', $where) . ' ORDER BY c.is_primary DESC, c.name', $params);

page_header('Companies', [
    'subtitle' => 'One database per company. Deactivating a company keeps all its data and blocks its sign-ins.',
    'actions' => '<a class="btn btn-primary" href="' . url('sa_company_new') . '">+ Create company</a>',
]);
?>
<form method="get" class="card filters">
  <input type="hidden" name="p" value="sa_companies">
  <label>Status<select name="status" data-autosubmit><?= options(COMPANY_STATUSES, $status, 'All statuses') ?></select></label>
  <label class="grow">Find<input type="search" name="q" value="<?= h($q) ?>" placeholder="Company, owner, database or Company Code"></label>
  <button class="btn btn-primary">Apply</button>
</form>
<div class="card flush">
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Company</th><th>Company Code</th><th>Database</th><th>Company Owner</th><th class="num">Accounts</th><th>Status</th><th>Created</th><th class="actions"></th></tr></thead>
      <tbody>
      <?php foreach ($list as $c): ?>
        <tr>
          <td><a href="<?= url('sa_company', ['id' => $c['id']]) ?>"><strong><?= h($c['name']) ?></strong></a><br><small class="muted">Company <?= (int)$c['id'] ?><?= $c['is_primary'] ? ', original installation' : '' ?></small></td>
          <td class="mono"><?= h($c['code'] ?: '—') ?></td>
          <td class="small"><?= h($c['db_name']) ?><br><span class="muted"><?= h(label($c['db_status'])) ?></span></td>
          <td class="small"><?= $c['owner_user_id'] ? h($c['owner_name']) . '<br><span class="muted">' . h($c['owner_username']) . '</span>' : '<span class="muted">not assigned</span>' ?></td>
          <td class="num"><?= (int)$c['accounts'] ?><?= (int)$c['pending'] ? '<br><small class="muted">' . (int)$c['pending'] . ' pending</small>' : '' ?></td>
          <td><?= badge($c['status'], COMPANY_STATUSES[$c['status']] ?? $c['status']) ?></td>
          <td class="small nowrap"><?= h(fmt_date(substr((string)$c['created_at'], 0, 10))) ?></td>
          <td class="actions">
            <a class="btn btn-sm" href="<?= url('sa_company', ['id' => $c['id']]) ?>">Manage</a>
            <?php if (in_array($c['status'], ['active', 'inactive'], true) && $c['db_status'] === 'ready'): ?>
              <form method="post" action="<?= h(url('sa_enter')) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="_action" value="enter"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn-sm">Enter</button></form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?= $list ? '' : empty_row(8, 'No company matches these filters.') ?>
      </tbody>
    </table>
  </div>
</div>
<?php page_footer();
