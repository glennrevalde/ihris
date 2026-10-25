<?php
$me = my_employee_id();
if (!$me) {
    throw new RuntimeException('Your account is not linked to an employee record.');
}
$e = employee_row($me);
if (is_post()) {
    // employees may update their contact details only; everything else is maintained by HR
    $d = ['address' => post('address'), 'contact_no' => post('contact_no'), 'emergency_contact' => post('emergency_contact'), 'emergency_phone' => post('emergency_phone')];
    update('employees', $d + ['updated_at' => now()], 'id = ?', [$me]);
    audit('pis', 'self_update_contact', 'employees', $me, diff_fields($e, $d));
    flash('success', 'Contact details updated.');
    redirect('my_profile');
}
$docs = rows('SELECT * FROM employee_documents WHERE employee_id = ? ORDER BY uploaded_at DESC', [$me]);
$hist = rows('SELECT * FROM employee_history WHERE employee_id = ? ORDER BY effective_date DESC', [$me]);
$sup = $e['supervisor_id'] ? row('SELECT * FROM employees WHERE id = ?', [$e['supervisor_id']]) : null;
page_header('My 201 Profile');
?>
<div class="card"><div class="profile-head">
  <?php if ($e['photo_path']): ?><img class="profile-photo" src="<?= h(upload_url($e['photo_path'])) ?>" alt=""><?php else: ?><div class="profile-photo"><?= h(mb_substr($e['first_name'], 0, 1) . mb_substr($e['last_name'], 0, 1)) ?></div><?php endif; ?>
  <div class="profile-meta"><h2><?= h(full_name($e, false)) ?></h2><div class="muted"><?= h($e['position']) ?>, <?= h(org_path($e['org_unit_id'] ? (int)$e['org_unit_id'] : null)) ?></div>
    <div class="chips"><span class="badge badge-blue"><?= h(label($e['employment_status'])) ?></span> <span class="badge badge-gray"><?= h($e['employee_no']) ?></span> <span class="badge badge-gray"><?= h($e['branch_name']) ?></span></div></div>
</div></div>
<div class="grid grid-2">
  <div class="card"><h3>Employment</h3><dl class="kv">
    <dt>Date hired</dt><dd><?= h(fmt_date($e['date_hired'])) ?></dd><dt>Supervisor</dt><dd><?= h($sup ? full_name($sup, false) : '—') ?></dd>
    <dt>Shift</dt><dd><?= $e['shift_id'] && ($es = shift_row((int)$e['shift_id'])) ? shift_code_html($es, '—', $es['code'] . ' — ' . $es['name']) : h($e['shift_name']) ?></dd><dt>Pay group</dt><dd><?= h($e['pay_group_name']) ?></dd>
    <dt>SSS / PhilHealth</dt><dd><?= h($e['sss_no']) ?> / <?= h($e['philhealth_no']) ?></dd><dt>Pag-IBIG / TIN</dt><dd><?= h($e['pagibig_no']) ?> / <?= h($e['tin']) ?></dd>
    <dt>Bank</dt><dd><?= h($e['bank_name']) ?> <?= h($e['bank_account_no']) ?></dd></dl>
    <p class="small muted">To correct personal data or government numbers, submit supporting documents to HR.</p></div>
  <div class="card"><h3>Contact details</h3>
    <form method="post"><?= csrf_field() ?><div class="form-grid">
      <div class="field wide"><label>Address</label><input name="address" value="<?= h($e['address']) ?>"></div>
      <div class="field"><label>Mobile</label><input name="contact_no" value="<?= h($e['contact_no']) ?>"></div>
      <div class="field"><label>Emergency contact</label><input name="emergency_contact" value="<?= h($e['emergency_contact']) ?>"></div>
      <div class="field"><label>Emergency phone</label><input name="emergency_phone" value="<?= h($e['emergency_phone']) ?>"></div></div>
      <div class="form-actions"><button class="btn btn-primary">Update</button></div></form></div>
</div>
<div class="grid grid-2">
  <div class="card"><h3>Employment history</h3><ul class="timeline"><?php foreach ($hist as $h): ?><li><strong><?= h(label($h['change_type'])) ?></strong> — <?= h($h['new_position']) ?><div class="when"><?= h(fmt_date($h['effective_date'])) ?></div></li><?php endforeach; ?></ul></div>
  <div class="card"><h3>My documents</h3><?php if (!$docs): ?><p class="muted">None.</p><?php else: ?><ul><?php foreach ($docs as $d): ?><li><a href="<?= h(upload_url($d['file_path'])) ?>" target="_blank"><?= h($d['title']) ?></a> <span class="small muted"><?= h(label($d['doc_type'])) ?></span></li><?php endforeach; ?></ul><?php endif; ?></div>
</div>
<?php page_footer();
