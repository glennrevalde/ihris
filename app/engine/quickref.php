<?php
/**
 * Add or edit a referenced master record from the form that uses it (v12).
 *
 * A select field declared with 'quick' => '<kind>' (for example the GL account of the Transaction
 * Table, Benefits Table, Fixed Transaction Table) gets two buttons, New and Edit. They open a small
 * form over the page; the record is saved in its own table without leaving the form, and every
 * select of that kind on the page is refreshed with the new or corrected entry, the one used
 * selected.
 *
 * The record is saved by its own page (the route of the kind, e.g. gl_accounts) with
 * _action=quick_add (Create permission) or quick_edit (Edit permission), so the permission gate
 * and the audit trail are the ones of that page.
 */

const GL_ACCOUNT_TYPES = ['asset' => 'Asset', 'liability' => 'Liability', 'equity' => 'Equity', 'income' => 'Income', 'expense' => 'Expense'];

/** The kinds of master records that can be added or edited from another form. */
function quickref_defs(): array
{
    return [
        'gl_accounts' => [
            'title' => 'GL account',
            'route' => 'gl_accounts',
            'table' => 'gl_accounts',
            'module' => 'setup',
            'key' => 'code',                 // the column the select stores
            'order' => 'code',
            'fields' => [
                'code' => ['label' => 'Account code', 'required' => true, 'attrs' => 'maxlength="20" autocomplete="off"'],
                'name' => ['label' => 'Account title', 'required' => true, 'wide' => true, 'attrs' => 'maxlength="120" autocomplete="off"'],
                'account_type' => ['label' => 'Type', 'type' => 'select', 'required' => true, 'options' => GL_ACCOUNT_TYPES],
                'is_active' => ['label' => 'Active', 'type' => 'checkbox'],
            ],
            'defaults' => ['account_type' => 'expense', 'is_active' => 1],
            'label' => fn(array $r) => $r['code'] . ' — ' . $r['name'],
            'validate' => 'gl_account_validate',
            'after_save' => 'gl_account_cascade',
            'note' => 'Saved in the Payroll Code Table (GL Accounts). A changed account code is carried to the transaction, deduction, benefit and fixed transaction codes and to the GL accounts of Payroll Parameters that use it.',
        ],
    ];
}

/* ------------------------------------------------------------------------- GL accounts */

/** Checks of a GL account (also used by the Payroll Code Table itself). */
function gl_account_validate(array &$d, int $id): array
{
    $err = [];
    $d['code'] = strtoupper(trim((string)($d['code'] ?? '')));
    $d['name'] = trim((string)($d['name'] ?? ''));
    if ($d['code'] === '') {
        $err[] = 'Account code is required.';
    } elseif (!preg_match('/^[A-Z0-9][A-Z0-9.\-_\/]{0,19}$/', $d['code'])) {
        $err[] = 'Account code: letters, digits, dot, dash, underscore or slash only (up to 20).';
    } elseif ((int)val('SELECT COUNT(*) FROM gl_accounts WHERE UPPER(code) = ? AND id <> ?', [$d['code'], $id])) {
        $err[] = 'Account code ' . $d['code'] . ' already exists.';
    }
    if ($d['name'] === '') {
        $err[] = 'Account title is required.';
    }
    if (!isset(GL_ACCOUNT_TYPES[$d['account_type'] ?? ''])) {
        $err[] = 'Choose the account type.';
    }
    return $err;
}

/** Tables and settings that store a GL account code. */
function gl_account_users(): array
{
    return ['earning_types', 'deduction_types', 'benefit_types', 'fixed_trans_codes'];
}

/** A renamed account code follows into every code and setting that uses it. Returns the rows changed. */
function gl_account_cascade(string $old, string $new): int
{
    if ($old === '' || $old === $new) {
        return 0;
    }
    $n = 0;
    foreach (gl_account_users() as $t) {
        $n += q("UPDATE `$t` SET gl_code = ? WHERE gl_code = ?", [$new, $old])->rowCount();
    }
    $n += q("UPDATE settings SET svalue = ? WHERE skey LIKE 'gl\\_%' AND svalue = ?", [$new, $old])->rowCount();
    if ($n) {
        audit('setup', 'gl_code_renamed', 'gl_accounts', null, ['from' => $old, 'to' => $new, 'rows' => $n]);
    }
    return $n;
}

/* ------------------------------------------------------------------------- the facility */

function quickref_def(string $kind): ?array
{
    return quickref_defs()[$kind] ?? null;
}

/** Records of a kind as the form needs them: id, the key and the editable fields. */
function quickref_records(array $def): array
{
    $cols = array_unique(array_merge(['id', $def['key']], array_keys($def['fields'])));
    $out = [];
    foreach (rows('SELECT `' . implode('`, `', $cols) . '` FROM `' . $def['table'] . '` ORDER BY ' . $def['order']) as $r) {
        $r['id'] = (int)$r['id'];
        $r['_label'] = ($def['label'])($r);
        $out[(string)$r[$def['key']]] = $r;
    }
    return $out;
}

/** Kinds used on the page (their form is written once, by page_footer()). */
function quickref_used(?string $kind = null): array
{
    static $used = [];
    if ($kind !== null) {
        $used[$kind] = true;
    }
    return array_keys($used);
}

/** May the signed-in user add / edit records of this kind? */
function quickref_rights(string $kind): array
{
    $def = quickref_def($kind);
    if (!$def || !function_exists('can_page')) {
        return ['add' => false, 'edit' => false];
    }
    return ['add' => can_page($def['route'], 'create'), 'edit' => can_page($def['route'], 'edit')];
}

/**
 * The select with its New / Edit buttons. $options are the choices of the select (the active
 * records); the value already stored stays selectable even when its record is inactive.
 */
function quickref_select(string $kind, string $name, array $f, $value, string $selectAttrs): string
{
    $def = quickref_def($kind);
    $rights = quickref_rights($kind);
    $options = $f['options'] ?? [];
    if ($value !== null && $value !== '' && !isset($options[$value]) && $def) {
        $rec = row('SELECT * FROM `' . $def['table'] . '` WHERE `' . $def['key'] . '` = ?', [$value]);
        $options[$value] = $rec ? ($def['label'])($rec) . (isset($rec['is_active']) && !$rec['is_active'] ? ' (inactive)' : '') : $value . ' (not in the table)';
    }
    $sel = '<select name="' . h($name) . '" data-qr="' . h($kind) . '"' . $selectAttrs . '>'
        . options($options, $value, array_key_exists('placeholder', $f) ? $f['placeholder'] : (empty($f['required']) ? '—' : null)) . '</select>';
    if (!$def || (!$rights['add'] && !$rights['edit']) || !empty($f['readonly'])) {
        return $sel;
    }
    quickref_used($kind);
    $btn = '';
    if ($rights['add']) {
        $btn .= '<button type="button" class="btn btn-sm qr-btn" data-qr-open="' . h($kind) . '" data-qr-mode="add" title="New ' . h($def['title']) . '" aria-label="New ' . h($def['title']) . '">' . icon('plus') . '<span>New</span></button>';
    }
    if ($rights['edit']) {
        $btn .= '<button type="button" class="btn btn-sm qr-btn" data-qr-open="' . h($kind) . '" data-qr-mode="edit" title="Edit the selected ' . h($def['title']) . '" aria-label="Edit the selected ' . h($def['title']) . '">' . icon('edit') . '<span>Edit</span></button>';
    }
    return '<div class="qr-field">' . $sel . '<div class="qr-actions">' . $btn . '</div></div>';
}

/** The add / edit forms of the kinds used on the page (written outside every other form). */
function quickref_modals(): string
{
    $html = '';
    foreach (quickref_used() as $kind) {
        $def = quickref_def($kind);
        $rights = quickref_rights($kind);
        $recs = quickref_records($def);
        $fields = '';
        foreach ($def['fields'] as $col => $f) {
            $fields .= form_field($col, $f, $def['defaults'][$col] ?? null);
        }
        $html .= '<div class="modal qr-modal" id="qr-' . h($kind) . '" data-qr-kind="' . h($kind) . '" data-qr-key="' . h($def['key']) . '"'
            . ' data-qr-title="' . h($def['title']) . '" data-qr-add="' . ($rights['add'] ? 1 : 0) . '" data-qr-edit="' . ($rights['edit'] ? 1 : 0) . '" role="dialog" aria-modal="true" aria-labelledby="qr-' . h($kind) . '-h">'
            . '<div class="modal-box"><form class="qr-form" method="post" action="' . h(url($def['route'])) . '" novalidate>'
            . csrf_field() . '<input type="hidden" name="_qr" value="' . h($kind) . '"><input type="hidden" name="_action" value="quick_add"><input type="hidden" name="id" value="0">'
            . '<div class="qr-head"><h2 id="qr-' . h($kind) . '-h">New ' . h($def['title']) . '</h2><button type="button" class="qr-x" data-modal-close aria-label="Close">' . icon('x') . '</button></div>'
            . '<div class="qr-msg" role="alert" hidden></div>'
            . '<div class="form-grid qr-grid">' . $fields . '</div>'
            . '<p class="muted small qr-note">' . h($def['note'] ?? '') . '</p>'
            . '<div class="form-actions"><button class="btn btn-primary" type="submit" data-allow-resubmit>Save</button> <button class="btn" type="button" data-modal-close>Cancel</button>'
            . '<a class="btn btn-ghost qr-all" href="' . h(url($def['route'])) . '" target="_blank" rel="noopener">Open the full table</a></div>'
            . '</form><script type="application/json" class="qr-data">' . json_encode(array_values($recs), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . '</script></div></div>';
    }
    return $html;
}

/**
 * Save posted by the New / Edit form (JSON reply). Called at the top of the kind's own page;
 * returns without output when the request is not one of them.
 */
function quickref_handle(string $kind): void
{
    if (!is_post() || post('_qr') !== $kind || !in_array(post('_action'), ['quick_add', 'quick_edit'], true)) {
        return;
    }
    verify_csrf();
    $def = quickref_def($kind);
    $id = post('_action') === 'quick_edit' ? (int)post('id', 0) : 0;
    $rights = quickref_rights($kind);
    if (!$def || ($id ? !$rights['edit'] : !$rights['add'])) {
        json_response(['ok' => false, 'errors' => ['You are not allowed to ' . ($id ? 'edit' : 'add') . ' this record.']], 403);
    }
    $old = $id ? row('SELECT * FROM `' . $def['table'] . '` WHERE id = ?', [$id]) : null;
    if ($id && !$old) {
        json_response(['ok' => false, 'errors' => ['The record no longer exists. Close the form and reload the page.']], 404);
    }
    $data = [];
    $errors = [];
    foreach ($def['fields'] as $col => $f) {
        $type = $f['type'] ?? 'text';
        if ($type === 'checkbox') {
            $data[$col] = post($col) ? 1 : 0;
            continue;
        }
        $v = trim((string)post($col, ''));
        if ($v === '' && !empty($f['required'])) {
            $errors[$col] = $f['label'] . ' is required.';
        }
        if ($type === 'select' && $v !== '' && !isset($f['options'][$v])) {
            $errors[$col] = $f['label'] . ': choose from the list.';
        }
        $data[$col] = $v === '' ? null : $v;
    }
    if (!$errors && !empty($def['validate'])) {
        $errors = array_merge($errors, ($def['validate'])($data, $id));
    }
    if ($errors) {
        json_response(['ok' => false, 'errors' => array_values($errors)], 422);
    }
    $moved = 0;
    try {
        tx(function () use ($def, $id, $old, &$data, &$moved) {
            if ($id) {
                update($def['table'], $data, 'id = ?', [$id]);
                audit($def['module'], 'update', $def['table'], $id, diff_fields($old, $data) + ['source' => 'quick edit']);
                if (!empty($def['after_save'])) {
                    $moved = ($def['after_save'])((string)$old[$def['key']], (string)$data[$def['key']]);
                }
            } else {
                $id = insert($def['table'], $data);
                audit($def['module'], 'create', $def['table'], $id, $data + ['source' => 'quick add']);
            }
            $data['id'] = $id;
        });
    } catch (PDOException $e) {
        json_response(['ok' => false, 'errors' => [$e->getCode() == 23000 ? 'Duplicate or invalid value: ' . $e->errorInfo[2] : 'Not saved: ' . $e->getMessage()]], 422);
    }
    $rec = row('SELECT * FROM `' . $def['table'] . '` WHERE id = ?', [$data['id']]);
    $out = ['id' => (int)$rec['id'], '_label' => ($def['label'])($rec)];
    foreach (array_keys($def['fields']) as $c) {
        $out[$c] = $rec[$c];
    }
    json_response([
        'ok' => true,
        'record' => $out,
        'old_key' => $old ? (string)$old[$def['key']] : null,
        'message' => $def['title'] . ' ' . $rec[$def['key']] . ($old ? ' updated' : ' added')
            . ($moved ? '; the new code was carried to ' . $moved . ' code(s) and setting(s) that used ' . $old[$def['key']] : '') . '.',
    ]);
}
