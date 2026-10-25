<?php
/**
 * Platform audit and provisioning logs (master database). The operational audit trail of each company
 * stays in that company's database (System > Audit Trail inside the company).
 */
$tab = get('tab') === 'provisioning' ? 'provisioning' : 'audit';
$cid = (int)get('company', 0);
$act = trim((string)get('action', ''));
$companies = mrows('SELECT id, name FROM companies ORDER BY name');
$names = array_column($companies, 'name', 'id');
if ($tab === 'audit') {
    $w = ['1=1'];
    $p = [];
    if ($cid) {
        $w[] = 'company_id = ?';
        $p[] = $cid;
    }
    if ($act !== '') {
        $w[] = 'action = ?';
        $p[] = $act;
    }
    $list = mrows('SELECT * FROM master_audit_log WHERE ' . implode(' AND ', $w) . ' ORDER BY id DESC LIMIT 300', $p);
    $actions = mrows('SELECT DISTINCT action FROM master_audit_log ORDER BY action');
} else {
    $list = mrows('SELECT * FROM company_provisioning_logs' . ($cid ? ' WHERE company_id = ?' : '') . ' ORDER BY id DESC LIMIT 300', $cid ? [$cid] : []);
}
page_header('Audit & Provisioning Logs', ['subtitle' => 'Platform actions: companies, owners, codes, sign-ins of Super Admins, join requests. Credentials are never recorded.']);
?>
<div class="tabs-nav">
  <a class="tab-link<?= $tab === 'audit' ? ' active' : '' ?>" href="<?= url('sa_logs', array_filter(['company' => $cid])) ?>">Platform audit</a>
  <a class="tab-link<?= $tab === 'provisioning' ? ' active' : '' ?>" href="<?= url('sa_logs', array_filter(['tab' => 'provisioning', 'company' => $cid])) ?>">Provisioning log</a>
</div>
<form method="get" class="card filters">
  <input type="hidden" name="p" value="sa_logs"><input type="hidden" name="tab" value="<?= h($tab) ?>">
  <label>Company<select name="company" data-autosubmit><?= options($names, $cid ?: null, 'All companies') ?></select></label>
  <?php if ($tab === 'audit'): ?><label>Action<select name="action" data-autosubmit><?= options(array_combine(array_column($actions, 'action'), array_map('label', array_column($actions, 'action'))) ?: [], $act ?: null, 'All actions') ?></select></label><?php endif; ?>
</form>
<div class="card flush"><div class="table-wrap">
<?php if ($tab === 'audit'): ?>
  <table class="table table-compact">
    <thead><tr><th>When</th><th>Action</th><th>Company</th><th>By</th><th>Details</th><th>IP</th></tr></thead>
    <tbody>
    <?php foreach ($list as $a): ?>
      <tr><td class="small nowrap"><?= h(fmt_dt($a['created_at'])) ?></td><td><?= h(label($a['action'])) ?></td><td class="small"><?= h($names[$a['company_id']] ?? ($a['company_id'] ? 'Company ' . $a['company_id'] : '—')) ?></td>
        <td class="small"><?= h($a['actor_name']) ?><br><span class="muted"><?= h(label($a['actor_type'])) ?></span></td><td class="small mono wrap-any"><?= h(mb_strimwidth((string)$a['details'], 0, 220, '…')) ?></td><td class="small"><?= h($a['ip_address']) ?></td></tr>
    <?php endforeach; ?>
    <?= $list ? '' : empty_row(6, 'No entry.') ?>
    </tbody>
  </table>
<?php else: ?>
  <table class="table table-compact">
    <thead><tr><th>When</th><th>Company</th><th>Step</th><th>Result</th><th>Details</th></tr></thead>
    <tbody>
    <?php foreach ($list as $l): ?>
      <tr><td class="small nowrap"><?= h(fmt_dt($l['created_at'])) ?></td><td class="small"><?= h($names[$l['company_id']] ?? 'Company ' . $l['company_id']) ?></td><td><?= h(label($l['step'])) ?></td>
        <td><?= badge($l['status'] === 'ok' ? 'completed' : ($l['status'] === 'failed' ? 'failed' : 'logged'), ['ok' => 'OK', 'failed' => 'Failed', 'info' => 'Info'][$l['status']]) ?></td><td class="small"><?= h((string)$l['message']) ?></td></tr>
    <?php endforeach; ?>
    <?= $list ? '' : empty_row(5, 'No entry.') ?>
    </tbody>
  </table>
<?php endif; ?>
</div></div>
<?php page_footer();
