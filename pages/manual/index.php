<?php
/**
 * User Manual — browse and search.
 *
 * With no query the page lists every article grouped by section, and the search
 * box filters what is on screen as you type. Pressing Enter runs the full search,
 * which ranks every article and shows a highlighted extract of each hit.
 */
$q = trim((string)get('q', ''));
$sections = manual_sections();
$results = $q !== '' ? manual_search($q) : [];
$terms = $q !== '' ? manual_terms($q) : [];

page_header('User Manual', [
    'subtitle' => 'Every page of the system explained: what it is for, who may use it, the steps to do the work and the mistakes that are easy to make.',
]);
?>
<form class="man-find" method="get" action="index.php" role="search">
  <input type="hidden" name="p" value="manual">
  <span class="man-find-ico"><?= icon('search') ?></span>
  <input type="search" id="manFind" name="q" value="<?= h($q) ?>" autocomplete="off" spellcheck="false"
         placeholder="Search the manual for a page, a feature or a term" aria-label="Search the manual">
  <button class="btn btn-primary" type="submit">Search</button>
  <?php if ($q !== ''): ?><a class="btn" href="<?= url('manual') ?>">Clear</a><?php endif; ?>
</form>

<?php if ($q !== ''): ?>

  <div class="man-resulthead">
    <strong><?= count($results) ?></strong>
    <?= count($results) === 1 ? 'article matches' : 'articles match' ?> &ldquo;<?= h($q) ?>&rdquo;
  </div>

  <?php if (!$results): ?>
    <div class="card">
      <div class="empty-state">
        <?= icon('search') ?>
        <h3>Nothing found</h3>
        <p class="muted">Try a shorter word, the name as it appears in the menu, or browse the sections below.</p>
        <p><a class="btn" href="<?= url('manual') ?>">Browse all <?= manual_count() ?> articles</a></p>
      </div>
    </div>
  <?php else: ?>
    <div class="man-results">
      <?php foreach ($results as $r): $t = $r['topic']; ?>
        <a class="man-result" href="<?= url('manual_topic', ['t' => $t['slug'], 'q' => $q]) ?>">
          <span class="man-result-top">
            <span class="man-result-title"><?= manual_mark($t['title'], $terms) ?></span>
            <span class="man-result-where"><?= h($sections[$t['section']]['title'] ?? '') ?></span>
          </span>
          <?php if ($t['menu']): ?><span class="man-result-menu"><?= manual_mark($t['menu'], $terms) ?></span><?php endif; ?>
          <span class="man-result-text"><?= $r['snippet'] ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php else: ?>

  <nav class="man-jump" aria-label="Manual sections">
    <?php foreach ($sections as $id => $s): ?>
      <a href="#sec-<?= h($id) ?>"><?= h($s['title']) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php foreach ($sections as $id => $s):
      $topics = manual_section_topics($id);
      if (!$topics) continue; ?>
    <section class="man-sec" id="sec-<?= h($id) ?>" data-man-section>
      <div class="man-sec-head">
        <span class="man-sec-ico"><?= icon($s['icon'] ?? 'book') ?></span>
        <div>
          <h2><?= h($s['title']) ?></h2>
          <p class="muted"><?= h($s['intro'] ?? '') ?></p>
        </div>
        <span class="man-sec-count"><?= count($topics) ?> articles</span>
      </div>
      <div class="man-grid">
        <?php foreach ($topics as $t): ?>
          <a class="man-card" href="<?= url('manual_topic', ['t' => $t['slug']]) ?>" data-man-item>
            <span class="man-card-title"><?= h($t['title']) ?></span>
            <span class="man-card-sum"><?= h($t['summary']) ?></span>
            <?php if ($t['menu']): ?><span class="man-card-menu"><?= h($t['menu']) ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>

  <p class="man-foot" id="manEmpty" hidden>No article matches what you typed. Press Search to look inside the articles as well.</p>
  <p class="man-foot"><?= manual_count() ?> articles. Open Help in the top bar of any screen to jump straight to the article for that screen.</p>

<?php endif;
page_footer();
