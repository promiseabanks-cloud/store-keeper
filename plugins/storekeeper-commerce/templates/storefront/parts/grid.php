<?php
/**
 * Product grid. Expects $grid (CatalogView::grid()), optional $heading.
 *
 * @var array{items:list<array<string,mixed>>,total:int,page:int,pages:int,search:string} $grid
 * @var string $heading
 */

$heading = $heading ?? '';
$cards = $grid['items'] ?? [];
?>

<?php if ($heading !== '') : ?>
    <div class="skf-section-head">
        <h2 class="skf-section-title"><?php echo esc_html($heading); ?></h2>
        <a class="skf-section-link" href="<?php echo esc_url(home_url('/shop/')); ?>">Shop all</a>
    </div>
<?php endif; ?>

<?php if ($cards === []) : ?>
    <div class="skf-empty">
        <p>Nothing here yet. New pieces land every week.</p>
    </div>
<?php else : ?>
    <div class="skf-grid">
        <?php foreach ($cards as $card) : ?>
            <?php require __DIR__ . '/card.php'; ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
