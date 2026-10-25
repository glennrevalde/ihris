<?php
/**
 * Super Admin Dashboard: the companies of the platform, their status and the latest platform activity.
 * Everything here is read from the master database only (no cross-database counting on page load).
 */
$st = mpairs('SELECT status, COUNT(*) FROM companies GROUP BY status');
$total = array_sum($st);
$owners = (int)mval('SELECT COUNT(*) FROM companies WHERE owner_user_id IS NOT NULL AND status IN (\'active\', \'inactive\')');
$accounts = mpairs('SELECT status, COUNT(*) FROM company_memberships GROUP BY status');
$recent = mrows('SELECT c.*, (SELECT code FROM company_codes k WHERE k.company_id = c.id AND k.status = \'active\' ORDER BY k.id DESC LIMIT 1) AS code
                 FROM companies c ORDER BY c.created_at DESC, c.id DESC LIMIT 8');
$activity = mrows('SELECT a.*, c.name AS company_name FROM master_audit_log a LEFT JOIN companies c ON c.id = a.company_id ORDER BY a.id DESC LIMIT 12');
$grants = mc_server_grants();

page_header('Super Admin Dashboard', [
    'subtitle' => 'Every company runs on its own database; this platform only keeps which company, which database and which account.',
    'actions' => '<a class="btn" href="' . url('sa_companies') . '">All companies</a><a class="btn btn-primary" href="' . url('sa_company_new') . '">+ Create company</a>',
]);
?>
<div class="grid grid-4">
  <?= stat_card('Total companies', (string)$total, (int)($st['provisioning'] ?? 0) . ' provisioning', 'info') ?>
  <?= stat_card('Active companies', (string)(int)($st['active'] ?? 0), 'Users can sign in', 'good') ?>
  <?= stat_card('Inactive companies', (string)(int)($st['inactive'] ?? 0), 'Data kept, sign-in blocked') ?>
  <?= stat_card('Failed provisioning', (string)(int)($st['failed'] ?? 0), (int)($st['failed'] ?? 0) ? '<a href="' . url('sa_companies', ['status' => 'failed']) . '">Review</a>' : 'None', (int)($st['failed'] ?? 0) ? 'warn' : '') ?>
</div>
<div class="grid grid-4" style="margin-top:16px">
  <?= stat_card('Company Owners assigned', $owners . ' / ' . ((int)($st['active'] ?? 0) + (int)($st['inactive'] ?? 0)), 'Companies with an owner') ?>
  <?= stat_card('Mapped accounts', (string)(int)($accounts['active'] ?? 0), (int)($accounts['inactive'] ?? 0) . ' inactive') ?>
  <?= stat_card('Pending join requests', (string)(int)($accounts['pending'] ?? 0), 'Waiting for Company Owners', (int)($accounts['pending'] ?? 0) ? 'warn' : '') ?>
  <?= stat_card('Company databases', (string)(int)mval("SELECT COUNT(*) FROM companies WHERE db_status = 'ready'"), 'Named ' . h(company_db_prefix()) . '###, master ' . h(master_db_name())) ?>
</div>

<div class="grid grid-2" style="margin-top:16px">
  <div class="card flush">
    <div class="card-head pad"><div><h2>Recently created companies</h2></div><a class="btn btn-sm" href="<?= url('sa_companies') ?>">All companies</a></div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Company</th><th>Code</th><th>Status</th><th>Created</th></tr></thead>
        <tbody>
        <?php foreach ($recent as $c): ?>
          <tr class="clickable-row">
            <td><a href="<?= url('sa_company', ['id' => $c['id']]) ?>"><strong><?= h($c['name']) ?></strong></a><br><small class="muted"><?= h($c['db_name']) ?><?= $c['is_primary'] ? ', original installation' : '' ?></small></td>
            <td class="mono"><?= h($c['code'] ?: '—') ?></td>
            <td><?= badge($c['status'], COMPANY_STATUSES[$c['status']] ?? $c['status']) ?></td>
            <td class="small"><?= h(fmt_dt($c['created_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        <?= $recent ? '' : empty_row(4, 'No company yet.') ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card flush">
    <div class="card-head pad"><div><h2>Platform activity</h2></div><a class="btn btn-sm" href="<?= url('sa_logs') ?>">Audit &amp; provisioning logs</a></div>
    <div class="table-wrap">
      <table class="table table-compact">
        <thead><tr><th>When</th><th>Action</th><th>Company</th><th>By</th></tr></thead>
        <tbody>
        <?php foreach ($activity as $a): ?>
          <tr><td class="small nowrap"><?= h(fmt_dt($a['created_at'])) ?></td><td><?= h(label($a['action'])) ?></td><td class="small"><?= h($a['company_name'] ?? '—') ?></td><td class="small"><?= h($a['actor_name']) ?></td></tr>
        <?php endforeach; ?>
        <?= $activity ? '' : empty_row(4, 'No activity yet.') ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card" style="margin-top:16px">
  <div class="card-head"><div><h2>Database server</h2><div class="sub">Automatic company creation needs CREATE DATABASE, CREATE / ALTER TABLE, INDEX, REFERENCES, INSERT, UPDATE, DELETE and SELECT for the MySQL account of config/config.php.</div></div></div>
  <?php if ($grants['can_create'] === true): ?>
    <div class="callout good">The MySQL account can create the company databases (<?= h(company_db_prefix()) ?>…).</div>
  <?php elseif ($grants['can_create'] === false): ?>
    <div class="callout warn">The MySQL account does not appear to be allowed to create databases named <?= h(company_db_prefix()) ?>…, so creating a company may fail. Run these statements as the MySQL root user:
      <pre class="code-block"><?= h(mc_required_grants_sql()) ?></pre></div>
  <?php else: ?>
    <div class="callout warn">The grants of the MySQL account could not be read<?= $grants['error'] ? ': ' . h($grants['error']) : '' ?>.</div>
  <?php endif; ?>
  <?php if ($grants['grants']): ?>
    <details><summary class="small muted">Grants of the MySQL account</summary><pre class="code-block"><?= h(implode(";\n", $grants['grants'])) ?>;</pre></details>
  <?php endif; ?>
</div>
<?php page_footer();
