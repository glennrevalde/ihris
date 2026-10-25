<?php
crud_page([
    'page' => 'merit_matrix', 'import' => 'merit_matrix', 'table' => 'merit_matrix', 'title' => 'Merit Matrix', 'singular' => 'rating band', 'module' => 'pis',
    'subtitle' => 'Maps the overall rating (1–5) to the rating label, merit increase, salary step progression, promotion eligibility and performance-improvement flag.',
    'order' => 'min_score DESC',
    'actions' => ' <a class="btn" href="' . url('competencies') . '">Competencies</a>',
    'fields' => [
        'min_score' => ['label' => 'Minimum overall score', 'type' => 'number', 'required' => true, 'step' => '0.01'],
        'rating_label' => ['label' => 'Rating label', 'required' => true],
        'increase_pct' => ['label' => 'Merit increase (%)', 'type' => 'number', 'default' => 0],
        'step_increase' => ['label' => 'Salary step increase', 'type' => 'number', 'default' => 0],
        'promotion_eligible' => ['label' => 'Promotion eligible', 'type' => 'checkbox'],
        'disciplinary_flag' => ['label' => 'Performance improvement plan flag', 'type' => 'checkbox'],
    ],
]);
