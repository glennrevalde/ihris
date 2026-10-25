<?php
$year = (int)(get('year') ?: date('Y'));
crud_page([
    'page' => 'holidays', 'table' => 'holidays', 'title' => 'Holidays', 'singular' => 'holiday', 'module' => 'ams',
    'subtitle' => 'Regular holidays and special non-working days (per Presidential Proclamation). Local holidays can be limited to one branch. Premiums follow the Overtime / Premium table.',
    'order' => 'holiday_date', 'where' => 'YEAR(holiday_date) = ?', 'where_params' => [$year],
    'top_html' => '<form class="card filters" method="get"><input type="hidden" name="p" value="holidays"><label>Year <select name="year" data-autosubmit>'
        . implode('', array_map(fn($y) => '<option' . ($y == $year ? ' selected' : '') . '>' . $y . '</option>', range(date('Y') + 1, date('Y') - 3))) . '</select></label></form>',
    'fields' => [
        'holiday_date' => ['label' => 'Date', 'type' => 'date', 'required' => true],
        'name' => ['label' => 'Holiday', 'required' => true],
        'holiday_type' => ['label' => 'Type', 'type' => 'select', 'options' => ['regular' => 'Regular holiday', 'special' => 'Special non-working day'], 'required' => true],
        'branch_id' => ['label' => 'Local to branch', 'type' => 'select', 'options' => branch_options(false), 'placeholder' => 'Nationwide'],
    ],
    'format' => ['holiday_date' => fn($v) => h(fmt_date($v, 'D, M d, Y')), 'holiday_type' => fn($v) => badge($v === 'regular' ? 'holiday' : 'on_leave', $v === 'regular' ? 'Regular' : 'Special')],
    'defaults' => ['holiday_type' => 'regular', 'holiday_date' => $year . '-01-01'],
]);
