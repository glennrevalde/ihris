<?php
/**
 * v36: Undertime Form Design. Header (company / branch name, address, contact no.), logos, series no., title,
 * employee fields, the table (headings, widths, rows, IN / OUT pairs by the shift's logs), signatories (label,
 * caption, name, position by row / column, format) and footer are all customizable. The preview follows every change.
 */
$cur = ut_layout();

/** The layout posted by the designer (lay[...]), with the logos uploaded ($store) or only shown ($store = false). */
$fromPost = function (bool $store) use ($cur): array {
    $p = (array)post('lay', []);
    foreach (['header_lines', 'info_fields', 'signatories'] as $k) {
        $p[$k] = array_values(array_filter((array)($p[$k] ?? []), 'is_array'));
    }
    foreach (['header_lines' => ['bold', 'italic'], 'signatories' => ['note', 'bold']] as $k => $flags) {
        foreach ($p[$k] as $i => $r) {
            foreach ($flags as $f) {
                $p[$k][$i][$f] = !empty($r[$f]) ? 1 : 0;
            }
        }
    }
    foreach (['header_rule', 'show_total', 'show_reason', 'sign_label_bold'] as $k) {
        $p[$k] = !empty($p[$k]) ? 1 : 0;
    }
    $data = [];
    foreach (['logo_left', 'logo_right'] as $side) {
        $g = (array)($p[$side] ?? []);
        $g['file'] = !empty($g['remove']) ? '' : ($cur[$side]['file'] ?? '');
        $f = $_FILES['logo_file_' . $side] ?? null;
        if ($f && $f['error'] === UPLOAD_ERR_OK) {
            if ($store) {
                $g['file'] = pass_slip_logo_store('logo_file_' . $side) ?? $g['file'];
            } else {
                $info = @getimagesize($f['tmp_name']);
                if ($info && in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_GIF], true) && $f['size'] <= 4 * 1048576) {
                    $data[$side] = 'data:' . $info['mime'] . ';base64,' . base64_encode((string)file_get_contents($f['tmp_name']));
                }
            }
            $g['source'] = 'upload';
        }
        unset($g['remove']);
        $p[$side] = $g;
    }
    $l = ut_clean_layout($p);
    foreach ($data as $side => $uri) {
        $l[$side]['data'] = $uri;
    }
    return $l;
};

/** A sample application for the preview: the signed-in employee (or a sample name), two dates. */
$sample = function (array $l): array {
    $e = my_employee_id() ? pass_slip_employee((int)my_employee_id()) : null;
    if (!$e) {
        $b = row('SELECT id, name, company_name, address FROM branches WHERE is_active = 1 ORDER BY is_default DESC, id LIMIT 1');
        $e = ['first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'middle_name' => '', 'suffix' => '', 'position' => 'Sales Associate',
            'department' => 'Operations', 'employee_no' => '0001', 'biometric_id' => '1001', 'employee_group' => 'Rank and File',
            'default_branch_id' => $b['id'] ?? null, 'branch_name' => $b['name'] ?? '', 'company_name' => $b['company_name'] ?? '', 'company_address' => $b['address'] ?? ''];
    }
    $pairs = $l['log_pairs'] === 'auto' ? 2 : (int)$l['log_pairs'];
    $t1 = array_slice(['08:00', '12:00', '13:00', '15:30', '', ''], 0, $pairs * 2);
    $t2 = array_slice(['08:00', '12:00', '13:00', '16:00', '', ''], 0, $pairs * 2);
    if ($pairs === 1) {
        $t1 = ['08:00', '15:30'];
        $t2 = ['08:00', '16:00'];
    }
    unset($e['employee_id']);
    return $e + ['slip_no' => substr(today(), 2, 2) . str_pad('45', $l['seq_digits'], '0', STR_PAD_LEFT), 'ut_no' => '', 'date_filed' => today(), 'status' => 'pending',
        'entries' => json_encode([['d' => add_days(today(), -2), 't' => $t1, 'm' => 90, 'r' => 'Medical check-up'], ['d' => add_days(today(), -1), 't' => $t2, 'm' => 60, 'r' => 'Personal errand']])];
};

if (is_post()) {
    $act = post('_action');
    if ($act === 'preview') {
        $l = $fromPost(false);
        echo ut_html($l, post('preview_mode') === 'blank' ? null : $sample($l));
        exit;
    }
    if ($act === 'reset') {
        ut_layout_save(ut_default_layout());
        audit('ams', 'undertime_design_reset', 'form_layouts', 'undertime');
        flash('success', 'The Undertime Form is back to the original layout.');
        redirect('undertime_design');
    }
    try {
        ut_layout_save($fromPost(true));
        audit('ams', 'undertime_design_save', 'form_layouts', 'undertime');
        flash('success', 'Undertime Form design saved.');
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect('undertime_design');
}

$l = $cur;
$canSave = can('undertime_design.configure');
$align = ['left' => 'Left', 'center' => 'Center', 'right' => 'Right'];
$num = fn(string $name, $v, $step = '1', $min = '0', $max = '99') => '<input type="number" name="' . h($name) . '" value="' . h($v) . '" step="' . $step . '" min="' . $min . '" max="' . $max . '">';
$hlRow = fn($i, array $r) => '<tr><td><input name="lay[header_lines][' . h($i) . '][text]" value="' . h($r['text'] ?? '') . '" placeholder="Text or {company_name}"></td>'
    . '<td>' . str_replace('<input', '<input class="w-num"', $num("lay[header_lines][$i][size]", $r['size'] ?? 9, '0.5', '6', '28')) . '</td>'
    . '<td class="center"><input type="checkbox" name="lay[header_lines][' . h($i) . '][bold]" value="1"' . (!empty($r['bold']) ? ' checked' : '') . ' aria-label="Bold"></td>'
    . '<td class="center"><input type="checkbox" name="lay[header_lines][' . h($i) . '][italic]" value="1"' . (!empty($r['italic']) ? ' checked' : '') . ' aria-label="Italic"></td>'
    . '<td><button type="button" class="btn btn-sm btn-danger-outline remove-row" aria-label="Remove line">×</button></td></tr>';
$infoRow = fn($i, array $r) => '<tr><td><input name="lay[info_fields][' . h($i) . '][label]" value="' . h($r['label'] ?? '') . '" placeholder="Label"></td>'
    . '<td><select name="lay[info_fields][' . h($i) . '][source]">' . options(PASS_SLIP_INFO_SOURCES, $r['source'] ?? 'blank') . '</select></td>'
    . '<td><select name="lay[info_fields][' . h($i) . '][side]">' . options(['left' => 'Left', 'right' => 'Right', 'full' => 'Full width'], $r['side'] ?? 'left') . '</select></td>'
    . '<td><button type="button" class="btn btn-sm btn-danger-outline remove-row" aria-label="Remove field">×</button></td></tr>';
$signRow = fn($i, array $r) => '<tr><td><input name="lay[signatories][' . h($i) . '][label]" value="' . h($r['label'] ?? '') . '" placeholder="e.g. Approved by:"></td>'
    . '<td><input name="lay[signatories][' . h($i) . '][caption]" value="' . h($r['caption'] ?? '') . '" placeholder="e.g. Supervisor"></td>'
    . '<td><select name="lay[signatories][' . h($i) . '][name]">' . options(PASS_SLIP_SIGN_SOURCES, $r['name'] ?? 'blank') . '</select></td>'
    . '<td><input name="lay[signatories][' . h($i) . '][text]" value="' . h($r['text'] ?? '') . '" placeholder="Fixed name"></td>'
    . '<td>' . str_replace('<input', '<input class="w-num"', $num("lay[signatories][$i][row]", $r['row'] ?? 1, '1', '1', '9')) . '</td>'
    . '<td>' . str_replace('<input', '<input class="w-num"', $num("lay[signatories][$i][col]", $r['col'] ?? 1, '1', '1', '4')) . '</td>'
    . '<td class="center"><input type="checkbox" name="lay[signatories][' . h($i) . '][bold]" value="1"' . (!empty($r['bold']) ? ' checked' : '') . ' aria-label="Caption in bold"></td>'
    . '<td class="center"><input type="checkbox" name="lay[signatories][' . h($i) . '][note]" value="1"' . (!empty($r['note']) ? ' checked' : '') . ' aria-label="Note"></td>'
    . '<td><button type="button" class="btn btn-sm btn-danger-outline remove-row" aria-label="Remove signatory">×</button></td></tr>';
$logoBox = function (string $side, string $title) use ($l, $num): string {
    $g = $l[$side];
    $img = $g['file'] !== '' ? report_logo_file_uri(report_logo_path_file($g['file'])) : '';
    return '<div class="logo-box"><strong>' . h($title) . '</strong>'
        . '<label class="field"><span>Logo</span><select name="lay[' . $side . '][source]">' . options(['company' => 'Company logo (System Settings)', 'upload' => 'Uploaded image', 'none' => 'No logo'], $g['source']) . '</select></label>'
        . ($img ? '<img src="' . h($img) . '" alt="Uploaded ' . h(strtolower($title)) . '"><label class="check"><input type="checkbox" name="lay[' . $side . '][remove]" value="1"> Remove the uploaded image</label>' : '')
        . '<label class="field"><span>Upload image (PNG, JPG, GIF)</span><input type="file" name="logo_file_' . $side . '" accept="image/png,image/jpeg,image/gif"></label>'
        . '<label class="field"><span>Height (mm)</span>' . $num("lay[$side][height]", $g['height'], '0.5', '5', '60') . '</label></div>';
};

page_header('Undertime Form Design', [
    'subtitle' => 'Header, logos, series no., table, signatories and footer of the printed UNDERTIME FORM. The preview follows every change.',
    'actions' => '<a class="btn" target="_blank" href="' . h(url('undertime_print', ['blank' => 1])) . '">Print blank form</a>',
]);
?>
<div class="psd-layout">
<form method="post" enctype="multipart/form-data" class="card" data-ps-design data-no-live action="<?= h(url('undertime_design')) ?>"><?= csrf_field() ?><input type="hidden" name="_action" value="save">
  <section class="psd-sec"><h3>Paper and margins</h3>
    <div class="form-grid">
      <div class="field"><label>Paper</label><select name="lay[paper]"><?= options(array_map(fn($p) => $p[0], PASS_SLIP_PAPERS), $l['paper']) ?></select></div>
      <div class="field"><label>Orientation</label><select name="lay[orientation]"><?= options(['portrait' => 'Portrait', 'landscape' => 'Landscape'], $l['orientation']) ?></select></div>
      <div class="field"><label>Font</label><select name="lay[font]"><?= options(PASS_SLIP_FONTS, $l['font']) ?></select></div>
      <div class="field"><label>Font size (pt)</label><?= $num('lay[font_size]', $l['font_size'], '0.5', '6', '16') ?></div>
    </div>
    <div class="psd-margins" style="margin-top:10px">
      <div class="field"><label>Top (mm)</label><?= $num('lay[margin_top]', $l['margin_top'], '0.5', '0', '60') ?></div>
      <div class="field"><label>Right (mm)</label><?= $num('lay[margin_right]', $l['margin_right'], '0.5', '0', '60') ?></div>
      <div class="field"><label>Bottom (mm)</label><?= $num('lay[margin_bottom]', $l['margin_bottom'], '0.5', '0', '60') ?></div>
      <div class="field"><label>Left (mm)</label><?= $num('lay[margin_left]', $l['margin_left'], '0.5', '0', '60') ?></div>
    </div>
  </section>

  <section class="psd-sec"><h3>Logos</h3>
    <p class="hint">The company logo is the logo of the employee's company / branch (System Settings). Upload any other image for this form only.</p>
    <div class="psd-logo"><?= $logoBox('logo_left', 'Left logo') ?><?= $logoBox('logo_right', 'Right logo') ?></div>
  </section>

  <section class="psd-sec"><h3>Header</h3>
    <p class="hint">Company / branch name, address, contact no. and any other line. Codes filled in: {company_name}, {company_address}, {branch_name}.</p>
    <div class="form-grid">
      <div class="field"><label>Alignment</label><select name="lay[header_align]"><?= options($align, $l['header_align']) ?></select></div>
      <div class="field"><label>&nbsp;</label><label class="check"><input type="checkbox" name="lay[header_rule]" value="1"<?= $l['header_rule'] ? ' checked' : '' ?>> Line under the header</label></div>
    </div>
    <div class="psd-rows-wrap" style="margin-top:10px"><table class="psd-rows"><thead><tr><th>Text</th><th>Size (pt)</th><th>Bold</th><th>Italic</th><th></th></tr></thead>
      <tbody id="utdHead"><?php foreach ($l['header_lines'] as $i => $r) echo $hlRow($i, $r); ?></tbody></table></div>
    <template id="utdHead-tpl"><?= $hlRow('__i__', ['size' => 8]) ?></template>
    <button type="button" class="btn btn-sm" data-add-row="utdHead">+ Add line</button>
  </section>

  <section class="psd-sec"><h3>Form name and series no.</h3>
    <div class="form-grid">
      <div class="field wide"><label>Form name (title)</label><input name="lay[title]" value="<?= h($l['title']) ?>"></div>
      <div class="field"><label>Alignment</label><select name="lay[title_align]"><?= options($align, $l['title_align']) ?></select></div>
      <div class="field"><label>Size (pt)</label><?= $num('lay[title_size]', $l['title_size'], '0.5', '8', '28') ?></div>
      <div class="field wide"><label>Series no.</label><input name="lay[form_no]" value="<?= h($l['form_no']) ?>" placeholder="e.g. HRUF-{no}"><small class="muted">{no} = the form number (year + running number, e.g. 260045), {year} = the year filed. Leave blank to hide.</small></div>
      <div class="field"><label>Position</label><select name="lay[form_no_position]"><?= options(['title_row' => 'Right of the form name', 'above' => 'Above the form name', 'below' => 'Below the form name'], $l['form_no_position']) ?></select></div>
      <div class="field"><label>Size (pt)</label><?= $num('lay[form_no_size]', $l['form_no_size'], '0.5', '6', '20') ?></div>
      <div class="field"><label>Running number digits</label><?= $num('lay[seq_digits]', $l['seq_digits'], '1', '1', '8') ?></div>
    </div>
  </section>

  <section class="psd-sec"><h3>Employee fields</h3>
    <div class="psd-rows-wrap"><table class="psd-rows"><thead><tr><th>Label</th><th>Shows</th><th>Side</th><th></th></tr></thead>
      <tbody id="utdInfo"><?php foreach ($l['info_fields'] as $i => $r) echo $infoRow($i, $r); ?></tbody></table></div>
    <template id="utdInfo-tpl"><?= $infoRow('__i__', ['side' => 'left']) ?></template>
    <button type="button" class="btn btn-sm" data-add-row="utdInfo">+ Add field</button>
  </section>

  <section class="psd-sec"><h3>Table</h3>
    <p class="hint">The TIME columns follow the logs of the employee's shift: 2 logs = IN / OUT, 4 logs = two pairs, 6 logs = three pairs. Choose a fixed number to print the same columns for everyone.</p>
    <div class="form-grid">
      <div class="field"><label>Date heading</label><input name="lay[col_date]" value="<?= h($l['col_date']) ?>"></div>
      <div class="field"><label>Time heading</label><input name="lay[col_time]" value="<?= h($l['col_time']) ?>"></div>
      <div class="field"><label>In</label><input name="lay[col_in]" value="<?= h($l['col_in']) ?>"></div>
      <div class="field"><label>Out</label><input name="lay[col_out]" value="<?= h($l['col_out']) ?>"></div>
      <div class="field"><label>Total heading</label><input name="lay[col_total]" value="<?= h($l['col_total']) ?>"></div>
      <div class="field"><label>Reason heading</label><input name="lay[col_reason]" value="<?= h($l['col_reason']) ?>"></div>
      <div class="field"><label>IN / OUT pairs</label><select name="lay[log_pairs]"><?= options(['auto' => 'By the shift (2, 4 or 6 logs)', '1' => '1 pair (2 logs)', '2' => '2 pairs (4 logs)', '3' => '3 pairs (6 logs)'], $l['log_pairs']) ?></select></div>
      <div class="field"><label>Total hours as</label><select name="lay[total_format]"><?= options(['hm' => '1:30', 'dec' => '1.50', 'words' => '1 hr 30 min'], $l['total_format']) ?></select></div>
      <div class="field"><label>Date format</label><select name="lay[date_format]"><?= options(['m/d/Y' => date('m/d/Y'), 'M d, Y' => date('M d, Y'), 'M d, Y (D)' => date('M d, Y (D)'), 'Y-m-d' => date('Y-m-d'), 'd M Y' => date('d M Y')], $l['date_format']) ?></select></div>
      <div class="field"><label>Time format</label><select name="lay[time_format]"><?= options(['h:i A' => '08:00 AM', 'g:i a' => '8:00 am', 'H:i' => '08:00 (24-hour)'], $l['time_format']) ?></select></div>
      <div class="field"><label>Most dates per form</label><?= $num('lay[max_dates]', $l['max_dates'], '1', '1', '31') ?></div>
      <div class="field"><label>Rows printed (at least)</label><?= $num('lay[blank_rows]', $l['blank_rows'], '1', '0', '31') ?></div>
      <div class="field"><label>Row height (mm)</label><?= $num('lay[row_height]', $l['row_height'], '0.5', '5', '25') ?></div>
    </div>
    <div class="psd-margins" style="margin-top:10px">
      <div class="field"><label>Date width (%)</label><?= $num('lay[w_date]', $l['w_date'], '1', '5', '80') ?></div>
      <div class="field"><label>Time width per IN / OUT pair (%)</label><?= $num('lay[w_time]', $l['w_time'], '1', '5', '80') ?></div>
      <div class="field"><label>Total width (%)</label><?= $num('lay[w_total]', $l['w_total'], '1', '5', '80') ?></div>
      <div class="field"><label>Reason width (%)</label><?= $num('lay[w_reason]', $l['w_reason'], '1', '5', '80') ?></div>
    </div>
    <div class="form-grid" style="margin-top:6px">
      <label class="check"><input type="checkbox" name="lay[show_total]" value="1"<?= $l['show_total'] ? ' checked' : '' ?>> Total hours column</label>
      <label class="check"><input type="checkbox" name="lay[show_reason]" value="1"<?= $l['show_reason'] ? ' checked' : '' ?>> Reason / purpose column</label>
    </div>
  </section>

  <section class="psd-sec"><h3>Signatories</h3>
    <p class="hint">Place each signature by row and column, and choose its format. "Approver of the slip" prints the name of whoever approved the form.</p>
    <div class="form-grid">
      <div class="field"><label>Columns</label><?= $num('lay[sign_cols]', $l['sign_cols'], '1', '1', '4') ?></div>
      <div class="field"><label>Size (pt)</label><?= $num('lay[sign_size]', $l['sign_size'], '0.5', '6', '14') ?></div>
      <div class="field"><label>Space between rows (mm)</label><?= $num('lay[sign_gap]', $l['sign_gap'], '0.5', '0', '30') ?></div>
      <div class="field"><label>Note label</label><input name="lay[note_label]" value="<?= h($l['note_label']) ?>"></div>
      <div class="field"><label>&nbsp;</label><label class="check"><input type="checkbox" name="lay[sign_label_bold]" value="1"<?= $l['sign_label_bold'] ? ' checked' : '' ?>> Labels in bold</label></div>
    </div>
    <div class="psd-rows-wrap" style="margin-top:10px"><table class="psd-rows"><thead><tr><th>Label</th><th>Caption</th><th>Name printed</th><th>Fixed name</th><th>Row</th><th>Col</th><th>Bold</th><th>Note</th><th></th></tr></thead>
      <tbody id="utdSign"><?php foreach ($l['signatories'] as $i => $r) echo $signRow($i, $r); ?></tbody></table></div>
    <template id="utdSign-tpl"><?= $signRow('__i__', ['row' => 1, 'col' => 1]) ?></template>
    <button type="button" class="btn btn-sm" data-add-row="utdSign">+ Add signatory</button>
  </section>

  <section class="psd-sec"><h3>Footer</h3>
    <div class="form-grid">
      <div class="field"><label>Note line</label><input name="lay[footer_note]" value="<?= h($l['footer_note']) ?>" placeholder="Blank to hide"></div>
    </div>
    <div class="field" style="margin-top:10px"><label>Footer text</label><textarea name="lay[footer]" rows="2" maxlength="500" placeholder="Optional text at the bottom of the form"><?= h($l['footer']) ?></textarea></div>
  </section>

  <div class="form-actions">
    <?php if ($canSave): ?><button class="btn btn-primary">Save design</button>
    <button class="btn btn-danger-outline" name="_action" value="reset" formnovalidate data-confirm="Restore the original layout? Your changes to the form will be replaced.">Restore original layout</button><?php endif; ?>
  </div>
  <input type="hidden" name="preview_mode" id="psdMode" value="sample">
</form>
<div class="card psd-preview">
  <div class="card-head"><h2>Preview</h2>
    <select aria-label="Preview with" onchange="document.getElementById('psdMode').value=this.value;document.getElementById('psdMode').dispatchEvent(new Event('change',{bubbles:true}))" style="width:auto"><option value="sample">Sample form</option><option value="blank">Blank form</option></select></div>
  <div class="psd-preview-frame" id="psPreview"><div class="psd-scale"><?= ut_html($l, $sample($l)) ?></div></div>
</div>
</div>
<?php page_footer();
