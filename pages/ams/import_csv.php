<?php
/**
 * Excel (.xlsx) / CSV attendance import using the standard template.
 * Rows are validated against the masterfile and shift schedule; unmatched or conflicting rows are queued for HR review.
 */
if (get('template')) {
    $d1 = date('Y-m-d', strtotime('last monday'));
    csv_download('attendance_import_template.csv', ['employee_no', 'date', 'time_in', 'time_out', 'remarks', 'branch_code', 'break_out', 'break_in'], [
        ['EMP-0005', $d1, '08:00', '17:00', 'Satellite office logbook', 'QC', '', ''],
        ['EMP-0006', $d1, '07:55', '17:10', '', '', '12:00', '13:00'],
    ]);
}
if (is_post()) {
    try {
        $up = save_upload('sheet', 'imports', ['csv', 'xlsx']);
        if (!$up) {
            throw new RuntimeException('Choose a file to import.');
        }
        $ext = strtolower(pathinfo($up['path'], PATHINFO_EXTENSION));
        $rows = read_attendance_sheet(upload_dir() . '/' . $up['path'], $ext);
        if (!$rows) {
            throw new RuntimeException('No data rows found. Please use the template (first row = column headers).');
        }
        if (!array_key_exists('employee_no', $rows[0]) || !array_key_exists('date', $rows[0])) {
            throw new RuntimeException('Missing required columns. Expected: employee_no, date, time_in, time_out, remarks.');
        }
        $st = import_attendance_sheet($rows, $up['name'], $ext === 'xlsx' ? 'xlsx' : 'csv');
        flash($st['flagged'] ? 'warning' : 'success', sprintf('%d row(s) read: %d posted to attendance, %d flagged for HR review.', $st['total'], $st['imported'], $st['flagged']));
        redirect($st['flagged'] ? 'import_review' : 'import_csv', $st['flagged'] ? ['batch_id' => $st['batch']] : []);
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
        redirect('import_csv');
    }
}
page_header('Excel / CSV Attendance Import', ['subtitle' => 'For branches with manual logbooks or third-party time-tracking tools. Unmatched or conflicting rows are never posted silently — they go to the review queue.',
    'actions' => '<a class="btn" href="' . url('import_csv', ['template' => 1]) . '">Download CSV template</a> <a class="btn" href="' . url('import_review') . '">Review queue</a>']);
?>
<div class="grid grid-2">
  <div class="card"><h2>Upload attendance file</h2>
    <form method="post" enctype="multipart/form-data"><?= csrf_field() ?>
      <div class="field"><label>Excel (.xlsx) or CSV file</label><input type="file" name="sheet" accept=".csv,.xlsx" required></div>
      <div class="form-actions"><button class="btn btn-primary">Validate &amp; import</button></div>
    </form>
  </div>
  <div class="card"><h2>Template columns</h2>
    <table class="table compact"><thead><tr><th>Column</th><th>Required</th><th>Format</th></tr></thead><tbody>
      <tr><td class="mono">employee_no</td><td>Yes</td><td>Employee No. or biometric ID</td></tr>
      <tr><td class="mono">date</td><td>Yes</td><td>YYYY-MM-DD (or Excel date)</td></tr>
      <tr><td class="mono">time_in</td><td>Yes*</td><td>HH:MM (24-hour) or 8:00 AM</td></tr>
      <tr><td class="mono">time_out</td><td>Yes*</td><td>HH:MM; earlier than time-in = overnight shift</td></tr>
      <tr><td class="mono">remarks</td><td>No</td><td>Free text</td></tr>
      <tr><td class="mono">branch_code</td><td>No</td><td>e.g. HO, QC, CEB (defaults to the employee's branch)</td></tr>
      <tr><td class="mono">break_out / break_in</td><td>No</td><td>HH:MM</td></tr>
    </tbody></table>
    <p class="small muted">* at least one of time_in / time_out. Validation checks: employee exists and is employed on the date, valid times, shift schedule / rest day, locked cutoffs and conflicts with existing biometric or web punches.</p>
  </div>
</div>
<?php page_footer();
