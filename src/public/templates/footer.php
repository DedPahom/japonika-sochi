<?php
declare(strict_types=1);

require_once __DIR__ . '/../Models/Category.php';
$mobileCategoryRows = Category::getAll();
$mobileCategoryChildren = [];
foreach ($mobileCategoryRows as $mobileCategoryRow) {
    $mobileParentId = (int)($mobileCategoryRow['parent_id'] ?? 0);
    $mobileCategoryChildren[$mobileParentId][] = $mobileCategoryRow;
}

if (!function_exists('mobileRenderCategoryMenu')) {
    function mobileRenderCategoryMenu(int $parentId, array $childrenByParent, int $depth = 0): void
    {
        if (empty($childrenByParent[$parentId]) || $depth > 12) return;
        echo '<ul class="mobile-category-list">';
        foreach ($childrenByParent[$parentId] as $category) {
            $slug = rawurlencode((string)$category['slug']);
            $name = htmlspecialchars((string)$category['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            echo '<li><a class="mobile-category-list__link" href="/catalog.php?category=' . $slug . '">' . $name . '</a>';
            mobileRenderCategoryMenu((int)$category['id'], $childrenByParent, $depth + 1);
            echo '</li>';
        }
        echo '</ul>';
    }
}
?>
        </div>
    </main>
    <nav class="mobile-bottom-nav" aria-label="Мобильная навигация">
        <a href="/" class="mobile-bottom-nav__item <?= $requestPath === '/' || $requestPath === '/index.php' ? 'is-active' : '' ?>" aria-label="Главная">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"></path></svg><span>Главная</span>
        </a>
        <button type="button" class="mobile-bottom-nav__item" data-mobile-menu-open aria-haspopup="dialog" aria-controls="mobile-category-dialog" aria-expanded="false">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"></path></svg><span>Меню</span>
        </button>
        <a href="/cart.php" class="mobile-bottom-nav__item <?= $requestPath === '/cart.php' ? 'is-active' : '' ?>" aria-label="Корзина, <?= (int)$headerCart['count'] ?> товаров">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2l2.2 11.2a2 2 0 0 0 2 1.6h8.5a2 2 0 0 0 1.9-1.4L22 8H6"></path><circle cx="10" cy="20" r="1"></circle><circle cx="18" cy="20" r="1"></circle></svg>
            <span>Корзина</span><b class="mobile-bottom-nav__count" data-cart-count><?= (int)$headerCart['count'] ?></b>
        </a>
        <a href="<?= htmlspecialchars($accountHref, ENT_QUOTES, 'UTF-8') ?>" class="mobile-bottom-nav__item <?= in_array($requestPath, ['/account.php','/login.php'], true) || str_starts_with($requestPath, '/admin/') ? 'is-active' : '' ?>" aria-label="<?= htmlspecialchars($accountLabel, ENT_QUOTES, 'UTF-8') ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"></circle><path d="M4 21a8 8 0 0 1 16 0"></path></svg><span><?= htmlspecialchars($accountLabel, ENT_QUOTES, 'UTF-8') ?></span>
        </a>
    </nav>

    <dialog class="mobile-category-dialog" id="mobile-category-dialog" aria-labelledby="mobile-category-title">
        <div class="mobile-category-dialog__header">
            <h2 id="mobile-category-title">Разделы каталога</h2>
            <button type="button" class="mobile-category-dialog__close" data-mobile-menu-close aria-label="Закрыть меню">×</button>
        </div>
        <div class="mobile-category-dialog__content">
            <a class="mobile-category-list__link mobile-category-list__link--all" href="/catalog.php">Все товары</a>
            <?php mobileRenderCategoryMenu(0, $mobileCategoryChildren); ?>
        </div>
    </dialog>

    <footer class="footer">
        <div class="container">
            <div class="footer__inner">
                <p>&copy; <?= date('Y') ?> <?= htmlspecialchars(SITE_NAME, ENT_QUOTES, 'UTF-8') ?>. Все права защищены.</p>
                <p>Магазин азиатской косметики и товаров для дома</p>
            </div>
        </div>
    </footer>
    <script src="/assets/js/main.js" defer></script>
    <script src="/assets/js/cart.js" defer></script>
    <script src="/assets/js/mobile-menu.js" defer></script>
</body>
</html>
