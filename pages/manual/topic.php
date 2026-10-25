<?php
/**
 * User Manual — one article.
 *
 * The parameter t is either a manual slug or an application route, so the Help
 * button in the top bar can simply pass the route of the page being looked at.
 */
$key = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)get('t', '')));
$topic = $key !== '' ? manual_topic($key) : null;
if (!$topic && $key !== '') {
    $topic = manual_topic_for_route($key);
}
if (!$topic) {
    flash('info', 'There is no manual article for that page yet. Search the manual below.');
    redirect('manual');
}

$q = trim((string)get('q', ''));
$sections = manual_sections();
$section = $sections[$topic['section']] ?? ['title' => 'Manual', 'icon' => 'book'];
[$prev, $next] = manual_neighbours($topic['slug']);
$canOpen = manual_user_can_open($topic);
$shot = manual_shot($topic);

// Contents of this article, built from the blocks that actually have content.
$blocks = [];
if ($shot)             { $blocks['shot']    = 'Screenshot'; }
if ($topic['steps'])   { $blocks['steps']   = 'How it is used'; }
if ($topic['fields'])  { $blocks['fields']  = 'Fields and options'; }
if ($topic['buttons']) { $blocks['buttons'] = 'Buttons'; }
if ($topic['rules'])   { $blocks['rules']   = 'Rules the system enforces'; }
if ($topic['outputs']) { $blocks['outputs'] = 'What it produces'; }
if ($topic['tips'])    { $blocks['tips']    = 'Worth knowing'; }
if ($topic['related']) { $blocks['related'] = 'Related articles'; }

$actions = '';
if ($canOpen) {
    $actions = '<a class="btn btn-primary" href="' . h(url($topic['route'])) . '">Open this page</a>';
}
$actions .= '<a class="btn" href="' . h(url('manual')) . '">All articles</a>';

page_header($topic['title'], [
    'subtitle' => h($topic['summary']),
    'back' => $q !== '' ? url('manual', ['q' => $q]) : url('manual'),
    'back_label' => $q !== '' ? 'Back to the search for "' . $q . '"' : 'User Manual',
    'actions' => $actions,
]);
?>
<div class="man-article">
  <article class="man-body">

    <div class="man-meta">
      <div><span>Section</span><strong><?= h($section['title']) ?></strong></div>
      <?php if ($topic['menu']): ?><div><span>Where to find it</span><strong><?= h($topic['menu']) ?></strong></div><?php endif; ?>
      <div><span>Default access</span><strong><?= h(manual_roles_text($topic['roles'])) ?></strong><small class="muted">Roles and users can be given other access in Role Management</small></div>
    </div>

    <?php if ($topic['purpose']): ?>
      <p class="man-lead"><?= h($topic['purpose']) ?></p>
    <?php endif; ?>

    <?php if (!$canOpen && $topic['route']): ?>
      <p class="man-locked">Your role cannot open this page, so it is described here for reference only.</p>
    <?php endif; ?>

    <?php if ($shot): ?>
      <figure class="man-shot" id="shot">
        <a href="<?= h($shot['url']) ?>" target="_blank" rel="noopener" title="Open the full screenshot">
          <img src="<?= h($shot['url']) ?>" alt="Screenshot of <?= h($shot['caption']) ?>" loading="lazy" decoding="async">
        </a>
        <figcaption>
          <span><?= h($shot['caption']) ?></span>
          <a href="<?= h($shot['url']) ?>" target="_blank" rel="noopener">Open full screenshot</a>
        </figcaption>
      </figure>
    <?php endif; ?>

    <?php if ($topic['steps']): ?>
      <section class="man-block" id="steps">
        <h2>How it is used</h2>
        <ol class="man-steps">
          <?php foreach ($topic['steps'] as $s): ?><li><?= h($s) ?></li><?php endforeach; ?>
        </ol>
      </section>
    <?php endif; ?>

    <?php if ($topic['fields']): ?>
      <section class="man-block" id="fields">
        <h2>Fields and options</h2>
        <dl class="man-defs">
          <?php foreach ($topic['fields'] as $f): ?>
            <div><dt><?= h($f[0] ?? '') ?></dt><dd><?= h($f[1] ?? '') ?></dd></div>
          <?php endforeach; ?>
        </dl>
      </section>
    <?php endif; ?>

    <?php if ($topic['buttons']): ?>
      <section class="man-block" id="buttons">
        <h2>Buttons</h2>
        <dl class="man-defs">
          <?php foreach ($topic['buttons'] as $b): ?>
            <div><dt><?= h($b[0] ?? '') ?></dt><dd><?= h($b[1] ?? '') ?></dd></div>
          <?php endforeach; ?>
        </dl>
      </section>
    <?php endif; ?>

    <?php if ($topic['rules']): ?>
      <section class="man-block" id="rules">
        <h2>Rules the system enforces</h2>
        <ul class="man-rules">
          <?php foreach ($topic['rules'] as $r): ?><li><?= h($r) ?></li><?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>

    <?php if ($topic['outputs']): ?>
      <section class="man-block" id="outputs">
        <h2>What it produces</h2>
        <ul class="man-outputs">
          <?php foreach ($topic['outputs'] as $o): ?><li><?= h($o) ?></li><?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>

    <?php if ($topic['tips']): ?>
      <section class="man-block" id="tips">
        <h2>Worth knowing</h2>
        <ul class="man-tips">
          <?php foreach ($topic['tips'] as $t): ?><li><?= h($t) ?></li><?php endforeach; ?>
        </ul>
      </section>
    <?php endif; ?>

    <?php if ($topic['related']): ?>
      <section class="man-block" id="related">
        <h2>Related articles</h2>
        <div class="man-related">
          <?php foreach ($topic['related'] as $slug):
              $rt = manual_topic($slug);
              if (!$rt) continue; ?>
            <a href="<?= url('manual_topic', ['t' => $rt['slug']]) ?>">
              <strong><?= h($rt['title']) ?></strong>
              <small><?= h($rt['summary']) ?></small>
            </a>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>

    <nav class="man-prevnext">
      <?php if ($prev): ?>
        <a class="man-pn" href="<?= url('manual_topic', ['t' => $prev['slug']]) ?>"><span>Previous</span><strong><?= h($prev['title']) ?></strong></a>
      <?php else: ?><span></span><?php endif; ?>
      <?php if ($next): ?>
        <a class="man-pn next" href="<?= url('manual_topic', ['t' => $next['slug']]) ?>"><span>Next</span><strong><?= h($next['title']) ?></strong></a>
      <?php endif; ?>
    </nav>
  </article>

  <aside class="man-side">
    <div class="man-side-box">
      <form method="get" action="index.php" class="man-side-find">
        <input type="hidden" name="p" value="manual">
        <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search the manual" aria-label="Search the manual">
      </form>
      <?php if ($blocks): ?>
        <div class="man-side-title">On this page</div>
        <nav class="man-toc">
          <?php foreach ($blocks as $id => $label): ?>
            <a href="#<?= h($id) ?>"><?= h($label) ?></a>
          <?php endforeach; ?>
        </nav>
      <?php endif; ?>
      <div class="man-side-title">More in <?= h($section['title']) ?></div>
      <nav class="man-toc">
        <?php foreach (manual_section_topics($topic['section']) as $st):
            if ($st['slug'] === $topic['slug']) continue; ?>
          <a href="<?= url('manual_topic', ['t' => $st['slug']]) ?>"><?= h($st['title']) ?></a>
        <?php endforeach; ?>
      </nav>
    </div>
  </aside>
</div>
<?php
page_footer();
