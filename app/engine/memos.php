<?php
/**
 * Memoranda / Announcements (v14).
 *
 *   memo_templates  proformas and sample formats for the usual company occasions (holiday, pay date,
 *                   tardiness reminder, outing, Christmas party, suspension of work, APE, welcome,
 *                   promotion, farewell, drills, system rollout, policy reminder ...). Customizable:
 *                   add, edit, deactivate; the samples can be restored.
 *   memos           the saved memoranda and announcements: number, subject, To / From / CC, date,
 *                   body, signatory, letterhead, audience, Draft / Issued / Archived, who made it and
 *                   when. They can be viewed, copied (a new draft) and printed whenever needed.
 *
 * The body is rich text (bold, italic, underline, lists, headings, alignment, tables), cleaned on save
 * by memo_clean_html(). Placeholders such as {company}, {date}, {to} are filled when the memo is shown.
 * The design (font, size, spacing, accent color, letterhead with or without logo, title style, paper,
 * margins, footer, acknowledgment block, draft watermark, numbering) is configurable: memo_design().
 */

const MEMO_KINDS = ['memo' => 'Memorandum', 'announcement' => 'Announcement'];
const MEMO_STATUSES = ['draft' => 'Draft', 'issued' => 'Issued', 'archived' => 'Archived'];
const MEMO_AUDIENCE = ['all' => 'Everyone (shown in My Workspace)', 'internal' => 'Management / addressees only'];
const MEMO_PLACEHOLDERS = [
    '{company}' => 'Company name', '{company_address}' => 'Company address', '{date}' => 'Memo date (October 14, 2026)',
    '{memo_no}' => 'Memo number', '{to}' => 'To', '{from}' => 'From', '{subject}' => 'Subject',
    '{signatory}' => 'Signatory name', '{signatory_title}' => 'Signatory position', '{year}' => 'Year of the memo',
];

/* --------------------------------------------------------------------------------- design */

/** Configurable design of the printed memo: key => [label, type, default, options/help]. */
function memo_design_fields(): array
{
    return [
        'memo_font' => ['Font', 'select', 'Arial', ['Arial' => 'Arial', 'Calibri' => 'Calibri', 'Cambria' => 'Cambria', 'Georgia' => 'Georgia', 'Times New Roman' => 'Times New Roman', 'Verdana' => 'Verdana', 'Tahoma' => 'Tahoma']],
        'memo_font_size' => ['Font size', 'select', '12', ['10.5' => '10.5 pt', '11' => '11 pt', '12' => '12 pt', '13' => '13 pt', '14' => '14 pt']],
        'memo_line_height' => ['Line spacing', 'select', '1.5', ['1.15' => '1.15', '1.3' => '1.3', '1.5' => '1.5', '1.8' => '1.8', '2' => 'Double']],
        'memo_accent' => ['Accent color', 'color', '#1d3a8a', 'Rules, title and headings'],
        'memo_header' => ['Letterhead', 'select', 'logo_center', LOGO_PLACEMENTS + ['name_only' => 'Company name and address only', 'none' => 'No letterhead (pre-printed paper)']],
        'memo_title_style' => ['Title (MEMORANDUM / ANNOUNCEMENT)', 'select', 'underline', ['underline' => 'Centered with a rule', 'banner' => 'Colored banner', 'boxed' => 'Framed', 'left' => 'Left aligned', 'none' => 'No title']],
        'memo_paper' => ['Paper', 'select', 'A4', ['A4' => 'A4', 'letter' => 'Letter (8.5 x 11)', 'legal' => 'Legal (8.5 x 13)']],
        'memo_margin' => ['Margins', 'select', '18', ['12' => 'Narrow (12 mm)', '18' => 'Normal (18 mm)', '25' => 'Wide (25 mm)']],
        'memo_footer' => ['Footer text', 'text', '', 'Printed at the bottom of every memo, e.g. the company address or a confidentiality note'],
        'memo_show_ack' => ['Acknowledgment block (Received by / Date)', 'bool', '1', 'For memoranda served to employees'],
        'memo_watermark' => ['DRAFT watermark on drafts', 'bool', '1', ''],
        'memo_prefix' => ['Number prefix of memoranda', 'text', 'MEMO', 'Numbers run per year: MEMO-2026-0001'],
        'announcement_prefix' => ['Number prefix of announcements', 'text', 'ANN', 'ANN-2026-0001'],
    ];
}

/** Current design values. */
function memo_design(): array
{
    $out = [];
    foreach (memo_design_fields() as $k => $f) {
        $v = setting($k);
        $out[$k] = $v === null || $v === '' ? (string)$f[2] : (string)$v;
        if ($f[1] === 'select' && !isset($f[3][$out[$k]])) {
            $out[$k] = $f[2];
        }
    }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $out['memo_accent'])) {
        $out['memo_accent'] = '#1d3a8a';
    }
    return $out;
}

/* --------------------------------------------------------------------------------- rich text */

/** Keep only safe formatting of a rich-text body. */
function memo_clean_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }
    $allowed = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'h2', 'h3', 'h4', 'blockquote', 'hr', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'span', 'div', 'sub', 'sup'];
    if (!class_exists('DOMDocument')) {
        return nl2br(h(strip_tags($html)));
    }
    $doc = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="memo-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $root = $doc->getElementById('memo-root') ?: $doc->documentElement;
    $walk = function (DOMNode $node) use (&$walk, $allowed, $doc) {
        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
            $c = $node->childNodes->item($i);
            if ($c instanceof DOMElement) {
                $tag = strtolower($c->tagName);
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea', 'link', 'meta'], true)) {
                    $node->removeChild($c);
                    continue;
                }
                $walk($c);
                if (!in_array($tag, $allowed, true)) {
                    // unknown tag: keep its content
                    while ($c->firstChild) {
                        $node->insertBefore($c->firstChild, $c);
                    }
                    $node->removeChild($c);
                    continue;
                }
                $keep = [];
                $align = '';
                if ($c->hasAttribute('style') && preg_match('/text-align\s*:\s*(left|right|center|justify)/i', $c->getAttribute('style'), $m)) {
                    $align = strtolower($m[1]);
                }
                if ($c->hasAttribute('align') && in_array(strtolower($c->getAttribute('align')), ['left', 'right', 'center', 'justify'], true)) {
                    $align = strtolower($c->getAttribute('align'));
                }
                foreach (['colspan', 'rowspan'] as $a) {
                    if (in_array($tag, ['td', 'th'], true) && $c->hasAttribute($a) && ctype_digit($c->getAttribute($a))) {
                        $keep[$a] = min(20, (int)$c->getAttribute($a));
                    }
                }
                while ($c->attributes->length) {
                    $c->removeAttribute($c->attributes->item(0)->nodeName);
                }
                foreach ($keep as $a => $v) {
                    $c->setAttribute($a, (string)$v);
                }
                if ($align !== '' && in_array($tag, ['p', 'div', 'h2', 'h3', 'h4', 'td', 'th', 'li', 'blockquote'], true)) {
                    $c->setAttribute('style', 'text-align:' . $align);
                }
            } elseif ($c instanceof DOMComment) {
                $node->removeChild($c);
            }
        }
    };
    $walk($root);
    $out = '';
    foreach ($root->childNodes as $c) {
        $out .= $doc->saveHTML($c);
    }
    return trim($out);
}

/** Plain-text excerpt of a body. */
function memo_excerpt(string $html, int $len = 160): string
{
    $t = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace(['</p>', '<br>', '</li>'], ' ', $html)), ENT_QUOTES, 'UTF-8')));
    return mb_strimwidth($t, 0, $len, '…');
}

/** Fill the placeholders of a text with the values of a memo. */
function memo_fill(string $text, array $m, bool $html = true): string
{
    $br = !empty($m['branch_id']) ? row('SELECT * FROM branches WHERE id = ?', [(int)$m['branch_id']]) : null;
    $date = !empty($m['memo_date']) ? $m['memo_date'] : today();
    $vals = [
        '{company}' => (string)(($br['company_name'] ?? '') ?: setting('company_name')),
        '{company_address}' => (string)(($br['address'] ?? '') ?: setting('company_address')),
        '{date}' => date('F j, Y', strtotime($date)),
        '{memo_no}' => (string)($m['memo_no'] ?? ''),
        '{to}' => (string)($m['to_line'] ?? ''),
        '{from}' => (string)($m['from_line'] ?? ''),
        '{subject}' => (string)($m['subject'] ?? ''),
        '{signatory}' => (string)($m['signatory_name'] ?? ''),
        '{signatory_title}' => (string)($m['signatory_title'] ?? ''),
        '{year}' => substr($date, 0, 4),
    ];
    if ($html) {
        $vals = array_map('h', $vals);
    }
    return strtr($text, $vals);
}

/* --------------------------------------------------------------------------------- numbering */

function memo_next_no(string $kind, ?string $date = null): string
{
    $d = memo_design();
    $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $kind === 'announcement' ? $d['announcement_prefix'] : $d['memo_prefix'])) ?: ($kind === 'announcement' ? 'ANN' : 'MEMO');
    $y = substr($date ?: today(), 0, 4);
    $like = $prefix . '-' . $y . '-%';
    $last = (string)val('SELECT memo_no FROM memos WHERE memo_no LIKE ? ORDER BY memo_no DESC LIMIT 1', [$like]);
    $n = $last !== '' ? (int)substr($last, strrpos($last, '-') + 1) + 1 : 1;
    do {
        $no = sprintf('%s-%s-%04d', $prefix, $y, $n++);
    } while ((int)val('SELECT COUNT(*) FROM memos WHERE memo_no = ?', [$no]));
    return $no;
}

/* --------------------------------------------------------------------------------- templates */

/** Sample formats of the usual company occasions: code => [name, kind, category, subject, body]. */
function memo_samples(): array
{
    $p = fn(string ...$x) => '<p>' . implode('</p><p>', $x) . '</p>';
    return [
        'BLANK-MEMO' => ['Memorandum (blank proforma)', 'memo', 'Proforma', '[Subject of the memorandum]',
            $p('[State the purpose of the memorandum in the first paragraph.]', '[Give the details: what, who, when, where and why.]', '[State the action expected from the addressees and the deadline, if any.]', 'For your information and compliance.')],
        'BLANK-ANN' => ['Announcement (blank proforma)', 'announcement', 'Proforma', '[Title of the announcement]',
            $p('We are pleased to announce that [announcement].', '[Details: date, time, venue, who may join or who is concerned.]', 'For questions, please contact [name / office].')],
        'HOLIDAY' => ['Holiday / non-working day', 'announcement', 'Holidays & schedules', 'Declaration of [Holiday] as a [Regular / Special Non-Working] Holiday',
            $p('Please be informed that [day, date] has been declared a <strong>[regular / special non-working] holiday</strong> by virtue of [Proclamation No. ____].',
                'Our offices will be closed on that day. Employees required to report for work will be paid according to the holiday pay rules of the Labor Code.',
                'Regular operations resume on [day, date]. Thank you.')],
        'SUSPENSION' => ['Suspension of work (weather / emergency)', 'announcement', 'Holidays & schedules', 'Suspension of Work on [Date]',
            $p('Due to [typhoon signal no. ___ / heavy rains / emergency], work in all offices of {company} is suspended on <strong>[date], from [time]</strong>.',
                'Employees are advised to stay safe and to keep their phones open for further advisories. Personnel of essential services will be informed by their department heads.',
                'Pay for the suspended hours follows the company policy and the applicable DOLE advisory.')],
        'PAYDATE' => ['Payroll schedule / pay date change', 'memo', 'Payroll', 'Change of Pay Date for the [Month] [1st / 2nd] Cutoff',
            $p('Please be informed that the salary for the cutoff period [date] to [date] will be credited on <strong>[new pay date]</strong> instead of [usual pay date] because of [reason].',
                'Payslips are available in My Workspace, My Payslips, on the pay date. Thank you for your understanding.')],
        'THIRTEENTH' => ['13th month pay release', 'announcement', 'Payroll', 'Release of the {year} 13th Month Pay',
            $p('In compliance with Presidential Decree No. 851, the 13th month pay for {year} will be released on <strong>[date]</strong>, on or before December 24.',
                'The amount is one-twelfth (1/12) of the basic salary earned during the calendar year. Employees with questions on their computation may approach the Payroll Office.')],
        'TARDINESS' => ['Reminder on tardiness and absenteeism', 'memo', 'Policy & discipline', 'Reminder on Attendance, Tardiness and Absenteeism',
            $p('It has come to the attention of management that tardiness and unauthorized absences have increased in the past weeks.',
                'All employees are reminded that working hours are from [time] to [time]. Habitual tardiness ([__] times in a month) and absences without approved leave are violations of the Code of Conduct and will be dealt with accordingly.',
                'Department heads are requested to monitor the attendance of their staff. Strict compliance is expected.')],
        'POLICY' => ['Company policy reminder (dress code / ID)', 'memo', 'Policy & discipline', 'Reminder on the Company [Dress Code / ID Policy]',
            '<p>All employees are reminded of the company policy on [dress code / wearing of company ID]:</p><ul><li>[Rule 1]</li><li>[Rule 2]</li><li>[Rule 3]</li></ul>'
                . '<p>This takes effect immediately. Non-compliance will be handled under the Code of Conduct.</p>'],
        'OUTING' => ['Company outing / team building', 'announcement', 'Company events', 'Company Outing and Team Building {year}',
            $p('We are happy to invite everyone to our <strong>Company Outing and Team Building</strong> on [date] at [venue].',
                'Assembly time is [time] at [place]. Transportation and meals will be provided. Please confirm your attendance with [name / department] on or before [date].',
                'Let us take this time to relax and to strengthen our teamwork!')],
        'CHRISTMAS' => ['Christmas party invitation', 'announcement', 'Company events', '{company} Christmas Party {year}',
            $p('The season of joy is here! Everyone is invited to our <strong>Christmas Party</strong> on [date], [time], at [venue].',
                'Theme: [theme]. There will be games, raffle prizes and awards. Kindly coordinate your department number with [committee].',
                'See you there and Merry Christmas!')],
        'APE' => ['Annual physical examination', 'memo', 'Health & safety', 'Annual Physical Examination {year}',
            $p('The Annual Physical Examination (APE) of all employees will be conducted by [clinic / provider] on [dates] at [venue].',
                'Please follow the schedule of your department. Fast for at least [8 to 10] hours before the examination and bring your company ID.',
                'Attendance is required. Employees who cannot attend on the schedule should inform HR in advance.')],
        'DRILL' => ['Fire / earthquake drill', 'announcement', 'Health & safety', '[Fire / Earthquake] Drill on [Date]',
            $p('A <strong>[fire / earthquake] drill</strong> will be held on [date] at [time] in coordination with [BFP / local DRRMO].',
                'When the alarm sounds, stop work, follow the evacuation route posted in your area and proceed to the assembly area at [place]. Floor marshals will account for all personnel.',
                'Participation of everyone in the building is required.')],
        'WELCOME' => ['Welcome new employee', 'announcement', 'People', 'Welcome to the Team, [Name]!',
            $p('Please join us in welcoming <strong>[Name]</strong>, who joins {company} as [Position] in the [Department] effective [date].',
                '[Name] brings [experience / background]. Let us make [him / her] feel welcome.')],
        'PROMOTION' => ['Promotion announcement', 'announcement', 'People', 'Announcement of Promotion',
            $p('Management is pleased to announce the promotion of <strong>[Name]</strong> to the position of <strong>[New Position]</strong> in the [Department], effective [date].',
                'This recognizes [his / her] dedication and performance. Congratulations!')],
        'FAREWELL' => ['Resignation / farewell', 'announcement', 'People', 'Farewell to [Name]',
            $p('We would like to inform everyone that [Name], [Position] of the [Department], will be leaving the company effective [date].',
                'We thank [him / her] for [__] years of service and wish [him / her] success in the next chapter. Turnover of work is with [Name of reliever].')],
        'SYSTEM' => ['New system / maintenance notice', 'memo', 'Operations', 'Rollout of the New HRIS / System Maintenance on [Date]',
            $p('Please be informed that [system] will be [rolled out / unavailable for maintenance] on [date], from [time] to [time].',
                'Employees may file leaves, overtime and view payslips through My Workspace. Your user name is your employee number; please change your temporary password at your first sign-in.',
                'For assistance, contact [IT / HR] at [local / email].')],
    ];
}

/** Put the sample formats in the template table (first use, or Restore samples). Returns how many were added. */
function memo_seed_templates(bool $force = false): int
{
    if (!mig_table_exists('memo_templates')) {
        return 0;
    }
    if (!$force && (int)val('SELECT COUNT(*) FROM memo_templates')) {
        return 0;
    }
    $n = 0;
    $i = 0;
    foreach (memo_samples() as $code => [$name, $kind, $cat, $subject, $body]) {
        $i += 10;
        if ((int)val('SELECT COUNT(*) FROM memo_templates WHERE code = ?', [$code])) {
            continue;
        }
        insert('memo_templates', ['code' => $code, 'name' => $name, 'kind' => $kind, 'category' => $cat, 'subject' => $subject, 'body' => $body,
            'is_sample' => 1, 'is_active' => 1, 'sort_order' => $i, 'created_by' => user_id(), 'created_at' => now(), 'updated_at' => now()]);
        $n++;
    }
    return $n;
}

/* --------------------------------------------------------------------------------- rendering */

/** The memo as a document (screen preview and print), with the configured design. */
function memo_document(array $m, ?array $design = null, bool $forPrint = false): string
{
    $d = $design ?? memo_design();
    $br = !empty($m['branch_id']) ? row('SELECT * FROM branches WHERE id = ?', [(int)$m['branch_id']]) : null;
    $company = (string)(($br['company_name'] ?? '') ?: setting('company_name'));
    $address = (string)(($br['address'] ?? '') ?: setting('company_address'));
    $logo = function_exists('report_logo_uri') ? report_logo_uri() : '';
    $kind = MEMO_KINDS[$m['kind'] ?? 'memo'] ?? 'Memorandum';
    $vars = '--m-font:' . h($d['memo_font']) . ';--m-size:' . h($d['memo_font_size']) . 'pt;--m-lh:' . h($d['memo_line_height']) . ';--m-accent:' . h($d['memo_accent']) . ';--m-margin:' . (int)$d['memo_margin'] . 'mm';
    $o = '<article class="memo-doc memo-paper-' . h(strtolower($d['memo_paper'])) . ' memo-title-' . h($d['memo_title_style']) . ' memo-head-' . h($d['memo_header'])
        . (($m['status'] ?? 'draft') === 'draft' && $d['memo_watermark'] === '1' ? ' is-draft' : '') . '" style="' . $vars . '">';
    if ($d['memo_header'] !== 'none') {
        $o .= '<header class="memo-lh">';
        if ($logo) {   // hidden by the style when the letterhead is "name only"
            $o .= '<img class="memo-logo" src="' . h($logo) . '" alt="">';
        }
        $o .= '<div class="memo-lh-text"><div class="memo-co">' . h($company) . '</div>' . ($address !== '' ? '<div class="memo-addr">' . h($address) . '</div>' : '') . '</div></header>';
    }
    if ($d['memo_title_style'] !== 'none') {
        $o .= '<div class="memo-title">' . h(strtoupper($kind)) . '</div>';
    }
    $o .= '<div class="memo-no">' . h($m['memo_no'] ?? '') . '</div>';
    $rows = [['To', $m['to_line'] ?? ''], ['From', $m['from_line'] ?? ''], ['CC', $m['cc_line'] ?? ''], ['Date', !empty($m['memo_date']) ? date('F j, Y', strtotime($m['memo_date'])) : ''], ['Subject', $m['subject'] ?? '']];
    $o .= '<table class="memo-fields">';
    foreach ($rows as [$l, $v]) {
        if ($l === 'CC' && trim((string)$v) === '') {
            continue;
        }
        if ($l === 'To' && trim((string)$v) === '' && ($m['kind'] ?? '') === 'announcement') {
            continue;
        }
        $o .= '<tr><th>' . h($l) . '</th><td>:</td><td' . ($l === 'Subject' ? ' class="memo-subject"' : '') . '>' . h(memo_fill((string)$v, $m, false)) . '</td></tr>';
    }
    $o .= '</table><hr class="memo-rule">';
    $o .= '<div class="memo-body">' . memo_fill((string)($m['body'] ?? ''), $m) . '</div>';
    if (trim((string)($m['signatory_name'] ?? '')) !== '') {
        $o .= '<div class="memo-sign"><div class="memo-sign-line"></div><div class="memo-sign-name">' . h($m['signatory_name']) . '</div><div class="memo-sign-title">' . h($m['signatory_title'] ?? '') . '</div></div>';
    }
    if ($d['memo_show_ack'] === '1' && ($m['kind'] ?? 'memo') === 'memo') {
        $o .= '<div class="memo-ack"><div><span>Received by</span><i></i></div><div><span>Date / time</span><i></i></div></div>';
    }
    if (trim($d['memo_footer']) !== '') {
        $o .= '<footer class="memo-foot">' . h(memo_fill($d['memo_footer'], $m, false)) . '</footer>';
    }
    return $o . '</article>';
}

/** Memo with the names of who made and changed it. */
function memo_row(int $id): ?array
{
    return row('SELECT m.*, cu.full_name AS created_name, uu.full_name AS updated_name, iu.full_name AS issued_name, t.name AS template_name
                FROM memos m LEFT JOIN users cu ON cu.id = m.created_by LEFT JOIN users uu ON uu.id = m.updated_by LEFT JOIN users iu ON iu.id = m.issued_by
                LEFT JOIN memo_templates t ON t.id = m.template_id WHERE m.id = ?', [$id]);
}

/** Rich-text editor (toolbar + editable area + hidden field filled on submit). */
function memo_editor(string $name, string $html, string $id = 'memoBody'): string
{
    $ph = '';
    foreach (MEMO_PLACEHOLDERS as $k => $l) {
        $ph .= '<option value="' . h($k) . '">' . h($l . ' ' . $k) . '</option>';
    }
    $b = fn(string $cmd, string $label, string $title, string $arg = '') => '<button type="button" data-rte-cmd="' . h($cmd) . '"' . ($arg !== '' ? ' data-rte-arg="' . h($arg) . '"' : '') . ' title="' . h($title) . '" aria-label="' . h($title) . '">' . $label . '</button>';
    return '<div class="rte" data-rte="' . h($id) . '">'
        . '<div class="rte-bar" role="toolbar" aria-label="Formatting">'
        . '<select data-rte-block aria-label="Paragraph style"><option value="p">Paragraph</option><option value="h3">Heading</option><option value="h4">Sub-heading</option><option value="blockquote">Quote / note</option></select>'
        . $b('bold', '<b>B</b>', 'Bold') . $b('italic', '<i>I</i>', 'Italic') . $b('underline', '<u>U</u>', 'Underline')
        . '<span class="sep"></span>' . $b('insertUnorderedList', '&bull; List', 'Bullet list') . $b('insertOrderedList', '1. List', 'Numbered list')
        . '<span class="sep"></span>' . $b('justifyLeft', '&#8676;', 'Align left') . $b('justifyCenter', '&#8596;', 'Center') . $b('justifyRight', '&#8677;', 'Align right') . $b('justifyFull', '&#8801;', 'Justify')
        . '<span class="sep"></span>' . $b('table', '&#9638; Table', 'Insert a table (3 columns)') . $b('insertHorizontalRule', '&mdash;', 'Line') . $b('removeFormat', 'Clear', 'Clear formatting')
        . '<select data-rte-insert aria-label="Insert a placeholder"><option value="">Insert field…</option>' . $ph . '</select>'
        . '</div>'
        . '<div class="rte-area" contenteditable="true" id="' . h($id) . 'Area" data-rte-area>' . memo_clean_html($html) . '</div>'
        . '<textarea name="' . h($name) . '" id="' . h($id) . '" hidden>' . h($html) . '</textarea></div>';
}
