<?php
/**
 * Signatory — the names and positions printed on the payroll register, reports and forms.
 */
$rows = rows("SELECT * FROM settings WHERE grp = 'signatory' AND label IS NOT NULL ORDER BY sort_order");
$slip = rows("SELECT * FROM settings WHERE skey IN ('payslip_sig_label','payslip_sig_name') ORDER BY sort_order");
if (is_post()) {
    verify_csrf();
    $n = settings_save(array_merge($rows, $slip), 'payroll');
    flash('success', $n . ' signatory setting(s) saved.');
    redirect('signatories');
}
page_header('Signatory', [
    'subtitle' => 'Printed at the bottom of the payroll register, the payroll journal and the government report forms.',
    'actions' => '<a class="btn" href="' . url('payroll_setup') . '">All setup tables</a>',
]);
?>
<div class="grid grid-2-1">
  <div class="card">
    <h2>Report signatories</h2>
    <form method="post"><?= csrf_field() ?>
      <?= settings_fields($rows, 'form-grid cols-2') ?>
      <h3 style="margin-top:18px">Payslip signature</h3>
      <?= settings_fields($slip, 'form-grid cols-2') ?>
      <div class="form-actions"><button class="btn btn-primary">Save signatories</button></div>
    </form>
  </div>
  <div class="card">
    <h2>Preview</h2>
    <div class="print-page" style="width:auto;min-height:0;box-shadow:none;border:1px solid var(--border);padding:18px">
      <?= signatory_block() ?>
    </div>
    <p class="help">Leave a name blank to print an empty signature line.</p>
  </div>
</div>
<?php page_footer();
