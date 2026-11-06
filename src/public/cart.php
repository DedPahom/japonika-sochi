<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/Models/Cart.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Cart::validateCsrf($_POST['csrf'] ?? null)) {
        http_response_code(403);
        exit('Сессия формы устарела. Обновите страницу и попробуйте снова.');
    }
    $action = (string)($_POST['action'] ?? '');
    $id = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if ($id) {
        try {
            if ($action === 'remove') Cart::remove((int)$id);
            elseif ($action === 'set') {
                $qty = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>999]]);
                if ($qty) Cart::setQuantity((int)$id, (int)$qty);
            }
        } catch (Throwable $e) { error_log('Cart page action failed: ' . $e->getMessage()); }
    }
    header('Location: /cart.php');
    exit;
}

$summary = Cart::summary();
$items = $summary['items'];
$csrf = Cart::csrfToken();
$pageTitle = 'Корзина — ' . SITE_NAME;
require __DIR__ . '/templates/header.php';
?>
<div class="cart-page">
    <h1 class="catalog__title">Корзина</h1>
    <?php if (!$items): ?>
        <div class="cart-empty"><p>В корзине пока пусто.</p><a class="btn btn--primary" href="/catalog.php">Перейти в каталог</a></div>
    <?php else: ?>
        <div class="cart-layout">
            <div class="cart-items">
                <?php foreach ($items as $item): ?>
                    <article class="cart-item">
                        <a class="cart-item__image" href="/product.php?id=<?= (int)$item['product_id'] ?>"><img src="<?= $item['main_image'] !== '' ? '/uploads/products/' . rawurlencode(basename($item['main_image'])) : '/assets/images/no-image.jpg' ?>" alt=""></a>
                        <div class="cart-item__details">
                            <div class="product-card__article">Арт. <?= htmlspecialchars($item['article'], ENT_QUOTES, 'UTF-8') ?></div>
                            <a class="cart-item__name" href="/product.php?id=<?= (int)$item['product_id'] ?>"><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?></a>
                            <div class="cart-item__price"><?= number_format((float)$item['price'], 0, '.', ' ') ?> ₽ / шт.</div>
                        </div>
                        <div class="cart-item__controls">
                            <form method="post" action="/cart.php" class="cart-quantity-form">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="set">
                                <input type="hidden" name="product_id" value="<?= (int)$item['product_id'] ?>">
                                <label class="visually-hidden" for="cart-quantity-<?= (int)$item['product_id'] ?>">Количество</label>
                                <input id="cart-quantity-<?= (int)$item['product_id'] ?>" type="number" name="quantity" min="1" max="999" value="<?= (int)$item['quantity'] ?>">
                                <button type="submit" class="btn btn--small">Обновить</button>
                            </form>
                            <form method="post" action="/cart.php">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="product_id" value="<?= (int)$item['product_id'] ?>">
                                <button type="submit" class="cart-remove">Удалить</button>
                            </form>
                        </div>
                        <div class="cart-item__line-total"><?= number_format((float)$item['price'] * (int)$item['quantity'], 0, '.', ' ') ?> ₽</div>
                    </article>
                <?php endforeach; ?>
            </div>
            <aside class="cart-summary">
                <h2>Итого</h2>
                <div class="cart-summary__row"><span>Товаров</span><strong><?= (int)$summary['count'] ?> шт.</strong></div>
                <div class="cart-summary__total"><span>Сумма</span><strong><?= number_format((float)$summary['total'], 0, '.', ' ') ?> ₽</strong></div>
                <p class="cart-summary__note">Стоимость рассчитана по текущим ценам товаров.</p>
            </aside>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/templates/footer.php'; ?>
