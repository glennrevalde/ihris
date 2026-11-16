<?php
/**
 * v35: the printed Pass Slip Form, on the paper, margins and layout of Pass Slip Form Design.
 *   ?id=N     a filed slip: its employee, his superiors and the users of Pass Slips
 *   ?blank=1  a blank form (with the signed-in employee's name, position and department when the account is linked;
 *             users of Pass Slips may add &employee_id=N)
 */
$layout = pass_slip_layout();
$me = my_employee_id();
$s = null;
if (get('id')) {
    $s = pass_slip_row((int)get('id'));
    if (!$s) {
        throw new RuntimeException('Pass slip not found.');
    }
    $own = (int)$s['employee_id'] === (int)$me;
    $allowed = $own ? (is_super_user() || can('my_pass_slips.print') || can('my_pass_slips.view')) : pass_slip_can_view($s);
    if (!$allowed) {
        http_response_code(403);
        throw new RuntimeException('You cannot open this pass slip.');
    }
    $title = 'Pass Slip ' . $s['slip_no'] . ' - ' . full_name($s, false);
} else {
    $emp = (int)get('employee_id');
    if ($emp && $emp !== (int)$me && !(is_super_user() || can('pass_slips.view') || is_manager_of($emp))) {
        $emp = 0;
    }
    $emp = $emp ?: (int)$me;
    if (!$emp && !(is_super_user() || can('pass_slips.view') || can('pass_slip_design.view'))) {
        http_response_code(403);
        throw new RuntimeException('Your account is not linked to an employee record.');
    }
    $s = $emp ? pass_slip_employee($emp) : null;
    $title = 'Pass Slip Form' . ($s ? ' - ' . full_name($s, false) : '');
}
[$pw, $ph] = pass_slip_paper_mm($layout);
print_header($title);
echo '<style>@page { size: ' . $pw . 'mm ' . $ph . 'mm; margin: 0; }</style>';
echo pass_slip_html($layout, $s);
echo '<script>(function(){var p=document.querySelector(".print-page");if(p)p.classList.add("ps-print-page");var b=document.querySelector("[data-save-pdf]");if(b){b.setAttribute("data-pdf-size","' . $pw . 'x' . $ph . '");b.setAttribute("data-pdf-margin","0");}})();</script>';
print_footer();
