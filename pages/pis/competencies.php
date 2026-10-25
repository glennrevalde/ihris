<?php
crud_page([
    'page' => 'competencies', 'import' => 'competencies', 'table' => 'competencies', 'title' => 'Competencies', 'singular' => 'competency', 'module' => 'pis',
    'subtitle' => 'Behavioral attributes rated on the Likert scale in the qualitative track (e.g. teamwork, initiative, communication, adherence to the Code of Conduct, leadership potential).',
    'order' => 'sort_order, id',
    'actions' => ' <a class="btn" href="' . url('merit_matrix') . '">Merit matrix</a>',
    'fields' => [
        'name' => ['label' => 'Competency', 'required' => true],
        'description' => ['label' => 'Description / behavioral indicator', 'wide' => true],
        'sort_order' => ['label' => 'Sort order', 'type' => 'number', 'default' => 0],
        'is_active' => ['label' => 'Active', 'type' => 'checkbox'],
    ],
    'defaults' => ['is_active' => 1, 'sort_order' => 0],
]);
