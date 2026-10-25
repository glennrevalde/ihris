<?php
/**
 * User Manual engine.
 *
 * The articles themselves live in app/manual_content.php (plain PHP arrays, no
 * database table), so the manual can be corrected by editing one file. This file
 * only resolves topics, links them to the application routes and runs the search.
 *
 * The content file is loaded lazily: pages that never call a manual_* function
 * (every normal screen) pay nothing for it.
 */

/** Default keys of a topic, so pages never have to test for missing entries. */
const MANUAL_TOPIC_DEFAULTS = [
    'slug' => '', 'section' => '', 'title' => '', 'summary' => '', 'purpose' => '',
    'route' => null, 'routes' => [], 'roles' => 'all', 'menu' => '',
    'steps' => [], 'fields' => [], 'buttons' => [], 'rules' => [], 'outputs' => [],
    'tips' => [], 'related' => [], 'keywords' => '',
    // Screenshot: the file name (without .png) under assets/manual. Left out, the
    // route name is used. Set it to false for an article that has no screen.
    'shot' => null, 'shot_caption' => '',
];

/** Folder the manual screenshots live in, relative to the application root. */
const MANUAL_SHOT_DIR = 'assets/manual';

/** Load, normalise and index the manual once per request. */
function manual_data(): array
{
    static $data = null;
    if ($data !== null) {
        return $data;
    }

    $raw = require APP_ROOT . '/app/manual_content.php';
    $sections = $raw['sections'] ?? [];
    $topics = [];
    $byRoute = [];
    $order = [];

    foreach ($raw['topics'] ?? [] as $t) {
        $t = array_merge(MANUAL_TOPIC_DEFAULTS, $t);
        $slug = (string)$t['slug'];
        if ($slug === '' || isset($topics[$slug])) {
            continue;
        }
        if (!isset($sections[$t['section']])) {
            $t['section'] = array_key_first($sections) ?: '';
        }
        // A topic named after a route documents that route unless it says otherwise.
        $routes = $t['routes'];
        if ($t['route']) {
            array_unshift($routes, $t['route']);
        } elseif ($t['route'] === null && manual_route_exists($slug)) {
            $t['route'] = $slug;
            array_unshift($routes, $slug);
        }
        $t['routes'] = array_values(array_unique(array_filter($routes)));
        foreach ($t['routes'] as $r) {
            if (!isset($byRoute[$r])) {
                $byRoute[$r] = $slug;
            }
        }
        $t['haystack'] = manual_haystack($t, $sections[$t['section']]['title'] ?? '');
        $topics[$slug] = $t;
        $order[$t['section']][] = $slug;
    }

    $data = ['sections' => $sections, 'topics' => $topics, 'by_route' => $byRoute, 'order' => $order];
    return $data;
}

/** Is this a real application route? */
function manual_route_exists(string $route): bool
{
    static $routes = null;
    if ($routes === null) {
        $routes = require APP_ROOT . '/app/routes.php';
    }
    return isset($routes[$route]);
}

/** Roles a route is declared for (used to tell the reader who may open a page). */
function manual_route_access(string $route)
{
    static $routes = null;
    if ($routes === null) {
        $routes = require APP_ROOT . '/app/routes.php';
    }
    return $routes[$route][1] ?? null;
}

function manual_sections(): array
{
    return manual_data()['sections'];
}

function manual_section(string $id): ?array
{
    return manual_data()['sections'][$id] ?? null;
}

/** All topics of a section, in authoring order. */
function manual_section_topics(string $id): array
{
    $d = manual_data();
    $out = [];
    foreach ($d['order'][$id] ?? [] as $slug) {
        $out[] = $d['topics'][$slug];
    }
    return $out;
}

function manual_topic(string $slug): ?array
{
    return manual_data()['topics'][$slug] ?? null;
}

function manual_all_topics(): array
{
    return manual_data()['topics'];
}

function manual_count(): int
{
    return count(manual_data()['topics']);
}

/** The article that documents an application route (used by the Help button). */
function manual_topic_for_route(string $route): ?array
{
    $d = manual_data();
    $slug = $d['by_route'][$route] ?? null;
    return $slug ? $d['topics'][$slug] : null;
}

/** Previous / next article across the whole manual, for sequential reading. */
function manual_neighbours(string $slug): array
{
    $flat = [];
    foreach (manual_data()['order'] as $slugs) {
        foreach ($slugs as $s) {
            $flat[] = $s;
        }
    }
    $i = array_search($slug, $flat, true);
    if ($i === false) {
        return [null, null];
    }
    return [
        $i > 0 ? manual_topic($flat[$i - 1]) : null,
        $i < count($flat) - 1 ? manual_topic($flat[$i + 1]) : null,
    ];
}

/** Everything a topic can be searched on, flattened into one lower-case string. */
function manual_haystack(array $t, string $sectionTitle): string
{
    $parts = [$t['title'], $t['summary'], $t['menu'], $sectionTitle, $t['keywords'], $t['purpose']];
    foreach (['steps', 'rules', 'outputs', 'tips'] as $k) {
        foreach ($t[$k] as $line) {
            $parts[] = $line;
        }
    }
    foreach (['fields', 'buttons'] as $k) {
        foreach ($t[$k] as $pair) {
            $parts[] = ($pair[0] ?? '') . ' ' . ($pair[1] ?? '');
        }
    }
    foreach ($t['routes'] as $r) {
        $parts[] = $r;
    }
    return mb_strtolower(preg_replace('/\s+/u', ' ', implode(' ', $parts)));
}

/** The readable part of a topic, used to build search snippets. */
function manual_body_text(array $t): string
{
    $parts = [$t['summary'], $t['purpose']];
    foreach ($t['steps'] as $s) {
        $parts[] = $s;
    }
    foreach ($t['fields'] as $f) {
        $parts[] = ($f[0] ?? '') . ' — ' . ($f[1] ?? '');
    }
    foreach ($t['buttons'] as $b) {
        $parts[] = ($b[0] ?? '') . ' — ' . ($b[1] ?? '');
    }
    foreach (['rules', 'outputs', 'tips'] as $k) {
        foreach ($t[$k] as $line) {
            $parts[] = $line;
        }
    }
    return trim(preg_replace('/\s+/u', ' ', implode('  ', array_filter($parts))));
}

/** Split a query into usable search terms. */
function manual_terms(string $q): array
{
    $q = mb_strtolower(trim($q));
    $raw = preg_split('/[^\p{L}\p{N}\-\+\.\/]+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $terms = [];
    foreach ($raw as $t) {
        if (mb_strlen($t) >= 2 || is_numeric($t)) {
            $terms[] = $t;
        }
    }
    return array_values(array_unique($terms));
}

/**
 * Search every article. Returns [['topic' => …, 'score' => …, 'snippet' => html], …]
 * ranked best first. Articles matching every word rank above partial matches.
 */
function manual_search(string $q, int $limit = 60): array
{
    $terms = manual_terms($q);
    if (!$terms) {
        return [];
    }
    $hits = [];
    foreach (manual_all_topics() as $t) {
        $title = mb_strtolower($t['title']);
        $summary = mb_strtolower($t['summary']);
        $menu = mb_strtolower($t['menu']);
        $keys = mb_strtolower($t['keywords']);
        $score = 0;
        $matched = 0;
        foreach ($terms as $term) {
            $found = false;
            if (mb_strpos($title, $term) !== false) {
                $score += 40;
                $found = true;
                if (mb_strpos($title, $term) === 0) {
                    $score += 25;
                }
                if ($title === $term) {
                    $score += 60;
                }
            }
            if (mb_strpos($keys, $term) !== false) {
                $score += 18;
                $found = true;
            }
            if (mb_strpos($menu, $term) !== false) {
                $score += 14;
                $found = true;
            }
            if (mb_strpos($summary, $term) !== false) {
                $score += 10;
                $found = true;
            }
            $n = mb_substr_count($t['haystack'], $term);
            if ($n > 0) {
                $score += min($n, 6);
                $found = true;
            }
            if ($found) {
                $matched++;
            }
        }
        if (!$matched) {
            continue;
        }
        $hits[] = ['topic' => $t, 'score' => $score, 'all' => $matched === count($terms)];
    }
    if (!$hits) {
        return [];
    }
    $full = array_filter($hits, fn($h) => $h['all']);
    $hits = $full ?: $hits;
    usort($hits, function ($a, $b) {
        return $b['score'] <=> $a['score'] ?: strcmp($a['topic']['title'], $b['topic']['title']);
    });
    $hits = array_slice($hits, 0, $limit);
    foreach ($hits as &$h) {
        $h['snippet'] = manual_snippet($h['topic'], $terms);
    }
    return $hits;
}

/** A short, highlighted extract of the article around the first matching word. */
function manual_snippet(array $t, array $terms, int $len = 190): string
{
    $text = manual_body_text($t);
    $pos = null;
    foreach ($terms as $term) {
        $p = mb_stripos($text, $term);
        if ($p !== false && ($pos === null || $p < $pos)) {
            $pos = $p;
        }
    }
    if ($pos === null) {
        $pos = 0;
    }
    $start = max(0, $pos - 70);
    if ($start > 0) {
        $sp = mb_strpos($text, ' ', $start);
        $start = $sp === false ? $start : $sp + 1;
    }
    $cut = mb_substr($text, $start, $len);
    if (mb_strlen($text) > $start + $len) {
        $cut = preg_replace('/\s+\S*$/u', '', $cut) . '…';
    }
    return manual_mark(($start > 0 ? '…' : '') . $cut, $terms);
}

/** Escape text and wrap the search words in <mark>. */
function manual_mark(string $text, array $terms): string
{
    $out = h($text);
    foreach ($terms as $term) {
        $q = preg_quote(h($term), '/');
        $out = preg_replace('/(' . $q . ')/iu', '<mark>$1</mark>', $out);
    }
    return $out;
}

/** "HR, Payroll and Administrator" — who may open the page the article describes. */
function manual_roles_text($roles): string
{
    if ($roles === 'all' || $roles === '*' || $roles === [] || $roles === null) {
        return 'Every signed-in user';
    }
    if ($roles === 'admin') {
        return 'Administrator only';
    }
    if ($roles === 'public') {
        return 'No sign-in needed';
    }
    $map = ['hr' => 'HR Officer', 'payroll' => 'Payroll Officer', 'supervisor' => 'Supervisor or Department Head', 'employee' => 'Employee', 'admin' => 'Administrator'];
    $names = [];
    foreach ((array)$roles as $r) {
        $names[] = $map[$r] ?? ucfirst((string)$r);
    }
    if (!in_array('admin', (array)$roles, true)) {
        $names[] = 'Administrator';
    }
    if (count($names) === 1) {
        return $names[0];
    }
    $last = array_pop($names);
    return implode(', ', $names) . ' and ' . $last;
}

/** May the signed-in user actually open the page this article documents? */
function manual_user_can_open(array $t): bool
{
    if (!$t['route'] || !manual_route_exists($t['route'])) {
        return false;
    }
    $access = manual_route_access($t['route']);
    if ($access === 'public' || $access === 'auth') {
        return true;
    }
    if ($access === 'admin') {
        return is_super_user();
    }
    if ($access === 'owner') {
        return owner_or_support();
    }
    return is_string($access) && $access !== 'super' && perm_route_allowed($t['route']);
}

/** Link to the manual article of a route (used by the Help button in the top bar). */
function manual_help_url(string $route): string
{
    return url('manual_topic', ['t' => $route]);
}

/**
 * The screenshot of the screen an article describes.
 * Returns ['url' => …, 'caption' => …] or null when there is no picture for it.
 */
function manual_shot(array $t): ?array
{
    $key = $t['shot'] ?? null;
    if ($key === false) {
        return null;
    }
    if (!$key) {
        $key = $t['route'] ?: $t['slug'];
    }
    if (!is_string($key) || !preg_match('/^[a-z0-9_\-]+$/', $key)) {
        return null;
    }
    $rel = MANUAL_SHOT_DIR . '/' . $key . '.png';
    if (!is_file(APP_ROOT . '/' . $rel)) {
        return null;
    }
    $caption = $t['shot_caption'] !== '' ? $t['shot_caption'] : $t['title'];
    return ['url' => $rel, 'key' => $key, 'caption' => $caption];
}

/** How many articles carry a screenshot (shown at the foot of the manual). */
function manual_shot_count(): int
{
    static $n = null;
    if ($n === null) {
        $n = 0;
        foreach (manual_all_topics() as $t) {
            if (manual_shot($t)) {
                $n++;
            }
        }
    }
    return $n;
}
