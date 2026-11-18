<?php
/**
 * v36: the printed Undertime Form, on the paper, margins and layout of Undertime Form Design.
 *   ?id=N     an application: its employee, his superiors and the users of Undertime Applications
 *   ?blank=1  a blank form (with the signed-in employee's name, position and department when the account is linked;
 *             users of Undertime Applications may add &employee_id=N)
 */
$layout = ut_layout();
$me = my_employee_id();
if (get('id')) {
    $a = ut_row((int)get('id'));
    if (!$a) {
        throw new RuntimeException('Undertime form not found.');
    }
    $own = (int)$a['employee_id'] === (int)$me;
    $allowed = $own ? (is_super_user() || can('my_undertime.print') || can('my_undertime.view')) : ut_can_view($a);
    if (!$allowed) {
        http_response_code(403);
        throw new RuntimeException('You cannot open this undertime form.');
    }
    $title = 'Undertime Form ' . $a['ut_no'] . ' - ' . full_name($a, false);
} else {
    $emp = (int)get('employee_id');
    if ($emp && $emp !== (int)$me && !(is_super_user() || can('undertime_apps.view') || is_manager_of($emp))) {
        $emp = 0;
    }
    $emp = $emp ?: (int)$me;
    if (!$emp && !(is_super_user() || can('undertime_apps.view') || can('undertime_design.view'))) {
        http_response_code(403);
        throw new RuntimeException('Your account is not linked to an employee record.');
    }
    $a = $emp ? pass_slip_employee($emp) : null;
    $title = 'Undertime Form' . ($a ? ' - ' . full_name($a, false) : '');
}
[$pw, $ph] = pass_slip_paper_mm($layout);
print_header($title);
echo '<style>@page { size: ' . $pw . 'mm ' . $ph . 'mm; margin: 0; }</style>';
echo ut_html($layout, $a);
echo '<script>(function(){var p=document.querySelector(".print-page");if(p)p.classList.add("ps-print-page");var b=document.querySelector("[data-save-pdf]");if(b){b.setAttribute("data-pdf-size","' . $pw . 'x' . $ph . '");b.setAttribute("data-pdf-margin","0");}})();</script>';
print_footer();
