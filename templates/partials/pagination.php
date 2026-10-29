<?php
/**
 * @var App\View $this
 * @var int $page
 * @var int $pageCount
 * @var string $base the listing's page-1 URL
 * @var string $locale
 */

use App\Routes;

if ($pageCount <= 1) {
    return;
}
$ui = $this->ui($locale);
$label = $this->pageLabel($locale, $page, $pageCount);
?>
<nav class="pagination" aria-label="<?= e($label) ?>">
<?php if ($page > 1): ?>
  <a href="<?= e(Routes::pagedUrl($base, $page - 1, $locale)) ?>" rel="prev">&larr; <?= e($ui['previous']) ?></a>
<?php else: ?>
  <span aria-disabled="true">&larr; <?= e($ui['previous']) ?></span>
<?php endif ?>
  <span class="pagination__status"><?= e($label) ?></span>
<?php if ($page < $pageCount): ?>
  <a href="<?= e(Routes::pagedUrl($base, $page + 1, $locale)) ?>" rel="next"><?= e($ui['next']) ?> &rarr;</a>
<?php else: ?>
  <span aria-disabled="true"><?= e($ui['next']) ?> &rarr;</span>
<?php endif ?>
</nav>
