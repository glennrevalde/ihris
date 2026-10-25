<?php
crud_page([
    'page' => 'import_profiles', 'table' => 'import_profiles', 'title' => 'Biometric Import Mappings', 'singular' => 'import mapping', 'module' => 'ams',
    'subtitle' => 'Configurable column mapping so log files from different device brands can be read without custom integration. Column numbers start at 1.',
    'actions' => ' <a class="btn" href="' . url('import_bio') . '">Back to import</a>',
    'fields' => [
        'name' => ['label' => 'Mapping name (device / format)', 'required' => true],
        'delimiter' => ['label' => 'Delimiter', 'type' => 'select', 'options' => ['tab' => 'Tab', 'comma' => 'Comma (,)', 'semicolon' => 'Semicolon (;)', 'pipe' => 'Pipe (|)', 'space' => 'Whitespace'], 'required' => true],
        'has_header' => ['label' => 'First line is a header', 'type' => 'checkbox'],
        'col_bio_id' => ['label' => 'Column: biometric / user ID', 'type' => 'number', 'required' => true],
        'col_datetime' => ['label' => 'Column: date-time (combined)', 'type' => 'number', 'help' => 'Leave blank if date and time are separate'],
        'col_date' => ['label' => 'Column: date', 'type' => 'number'],
        'col_time' => ['label' => 'Column: time', 'type' => 'number'],
        'datetime_format' => ['label' => 'Date-time format (PHP)', 'required' => true, 'help' => 'e.g. Y-m-d H:i:s, m/d/Y H:i, d/m/Y h:i A'],
        'col_punch' => ['label' => 'Column: punch type / state', 'type' => 'number', 'help' => 'Blank = infer from sequence (first in, last out)'],
        'punch_map' => ['label' => 'Punch code map', 'help' => 'e.g. 0=in,1=out,2=break_out,3=break_in', 'wide' => true],
        'col_device' => ['label' => 'Column: device / terminal', 'type' => 'number'],
        'default_branch_id' => ['label' => 'Default branch of this device', 'type' => 'select', 'options' => branch_options()],
        'notes' => ['label' => 'Notes', 'wide' => true],
    ],
    'defaults' => ['delimiter' => 'tab', 'col_bio_id' => 1, 'col_datetime' => 2, 'datetime_format' => 'Y-m-d H:i:s'],
    'list' => ['name', 'delimiter', 'col_bio_id', 'col_datetime', 'col_punch', 'punch_map', 'default_branch_id'],
    'format' => ['default_branch_id' => fn($v) => h(branch_options(false)[$v] ?? '—')],
]);
