<?php
/**
 * Email outbox / log: every notification with its delivery status. In "log only" mode this is where
 * approval emails can be opened and their Approve / Return / Disallow links clicked during testing.
 */
if (is_post() && post('_action') === 'resend') {
    $m = row('SELECT * FROM email_log WHERE id = ?', [(int)post('id')]);
    if ($m) {
        $ok = send_mail($m['to_email'], $m['to_name'], $m['subject'], $m['body_html'], $m['related_type'], $m['related_id'] ? (int)$m['related_id'] : null);
        audit('admin', 'email_resend', 'email_log', $m['id'], ['to' => $m['to_email'], 'subject' => $m['subject'], 'ok' => $ok ? 1 : 0]);
        flash($ok ? 'success' : 'error', $ok ? 'Email re-sent (a new log entry was created).' : 'Re-send failed. Check the SMTP settings.');
    }
    redirect('outbox');
}
$view = (int)get('view', 0);
if ($view) {
    $m = row('SELECT * FROM email_log WHERE id = ?', [$view]);
    if (!$m) {
        throw new RuntimeException('Email not found.');
    }
    page_header('Email — ' . $m['subject'], ['back' => url('outbox')]);
    ?>
    <div class="card"><dl class="kv"><dt>To</dt><dd><?= h($m['to_name']) ?> &lt;<?= h($m['to_email']) ?>&gt;</dd><dt>Subject</dt><dd><?= h($m['subject']) ?></dd>
      <dt>Status</dt><dd><?= badge($m['status']) ?> <?= h($m['error_message']) ?></dd><dt>Created</dt><dd><?= h(fmt_dt($m['created_at'], 'M d, Y h:i:s A')) ?></dd></dl>
      <p class="small muted">Links inside the message open the approval page exactly as the recipient would see it.</p></div>
    <div class="card" style="padding:0;overflow:hidden"><iframe title="Email preview" sandbox="allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation" style="width:100%;height:760px;border:0" srcdoc="<?= h(str_replace('<a ', '<a target="_blank" ', $m['body_html'])) ?>"></iframe></div>
    <?php
    page_footer();
    exit;
}
$status = get('status', '');
$list = rows('SELECT * FROM email_log' . ($status !== '' ? ' WHERE status = ' . db()->quote($status) : '') . ' ORDER BY id DESC LIMIT 500');
page_header('Email Outbox', ['subtitle' => 'Delivery mode: <strong>' . h(label(setting('mail_mode', 'log'))) . '</strong> (change in Settings › Email). Every email is kept here as a time-stamped trail.']);
?>
<form class="card filters" method="get"><input type="hidden" name="p" value="outbox"><label>Status<select name="status" data-autosubmit><?= options(['logged' => 'Logged (not sent)', 'sent' => 'Sent', 'failed' => 'Failed', 'queued' => 'Queued'], $status, 'All') ?></select></label></form>
<div class="card"><div class="table-wrap"><table class="table compact"><thead><tr><th>Time</th><th>To</th><th>Subject</th><th>Related</th><th>Status</th><th class="actions"></th></tr></thead><tbody>
<?php if (!$list) echo empty_row(6); foreach ($list as $m): ?>
  <tr><td class="nowrap small"><?= h(fmt_dt($m['created_at'], 'M d, h:i:s A')) ?></td><td class="small"><?= h($m['to_email']) ?></td><td><a href="<?= url('outbox', ['view' => $m['id']]) ?>"><?= h($m['subject']) ?></a></td>
    <td class="small"><?= $m['related_type'] === 'leave_application' ? '<a href="' . url('leave_view', ['id' => $m['related_id']]) . '">Leave #' . (int)$m['related_id'] . '</a>' : ($m['related_type'] === 'disciplinary_case' ? '<a href="' . url('case_view', ['id' => $m['related_id']]) . '">Case #' . (int)$m['related_id'] . '</a>' : h($m['related_type'])) ?></td>
    <td><?= badge($m['status']) ?><?= $m['error_message'] ? '<br><span class="small">' . h(mb_strimwidth($m['error_message'], 0, 80, '…')) . '</span>' : '' ?></td>
    <td class="actions"><a class="btn btn-sm" href="<?= url('outbox', ['view' => $m['id']]) ?>">Open</a><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="_action" value="resend"><input type="hidden" name="id" value="<?= $m['id'] ?>"><button class="btn btn-sm">Re-send</button></form></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php page_footer();
