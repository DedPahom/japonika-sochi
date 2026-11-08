<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/Models/Cart.php';
require_once __DIR__ . '/Models/UserAccess.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
UserAccess::clearBlockedSession();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Cart::validateCsrf($_POST['csrf'] ?? null)) {
        http_response_code(403);
        exit('Сессия формы устарела. Обновите страницу и попробуйте снова.');
    }

    $action = (string)($_POST['action'] ?? '');
    try {
        if (preg_match('/^(set|remove):(\d+)$/', $action, $match)) {
            foreach ((array)($_POST['preorder_choice'] ?? []) as $choiceId => $choice) {
                $choiceId = filter_var($choiceId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($choiceId && in_array($choice, ['replacement', 'notify'], true)) Cart::setUnavailablePreference((int)$choiceId, (string)$choice);
            }
            $productId = (int)$match[2];
            if ($match[1] === 'remove') Cart::remove($productId);
            else {
                $quantities = (array)($_POST['quantity'] ?? []);
                $quantity = filter_var($quantities[(string)$productId] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 999]]);
                if (!$quantity) throw new RuntimeException('Количество должно быть от 1 до 999.');
                Cart::setQuantity($productId, (int)$quantity);
            }
            header('Location: /cart.php'); exit;
        }

        if ($action === 'save_choices') {
            foreach ((array)($_POST['preorder_choice'] ?? []) as $id => $choice) {
                $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id && in_array($choice, ['replacement', 'notify'], true)) Cart::setUnavailablePreference((int)$id, (string)$choice);
            }
            header('Location: /cart.php'); exit;
        }

        if ($action === 'checkout') {
            foreach ((array)($_POST['preorder_choice'] ?? []) as $id => $choice) {
                $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id && in_array($choice, ['replacement', 'notify'], true)) Cart::setUnavailablePreference((int)$id, (string)$choice);
            }
            $orderId = Cart::createOrder([
                'name' => trim((string)($_POST['name'] ?? '')),
                'phone' => trim((string)($_POST['phone'] ?? '')),
                'comment' => trim((string)($_POST['comment'] ?? '')),
            ]);
            header('Location: /cart.php?ordered=' . $orderId); exit;
        }
        throw new RuntimeException('Неизвестное действие.');
    } catch (Throwable $e) {
        error_log('Cart checkout/action failed: ' . $e->getMessage());
        $error = $e instanceof RuntimeException || $e instanceof InvalidArgumentException ? $e->getMessage() : 'Не удалось обработать корзину. Попробуйте ещё раз.';
    }
}

if (isset($_GET['ordered']) && ctype_digit((string)$_GET['ordered']) && (int)$_GET['ordered'] > 0) {
    $success = 'Заказ №' . (int)$_GET['ordered'] . ' сохранён. Мы свяжемся с вами для подтверждения.';
}

$summary = Cart::summary();
$items = $summary['items'];
$csrf = Cart::csrfToken();
$isLoggedIn = isset($_SESSION['user_id']);
$pageTitle = 'Корзина — ' . SITE_NAME;
require __DIR__ . '/templates/header.php';
?>
<div class="cart-page">
    <h1 class="catalog__title">Корзина</h1>
    <?php if ($error !== ''): ?><div class="alert alert--error"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="alert alert--success"><?= htmlspecialchars($success, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
    <?php if (!$items): ?>
        <div class="cart-empty"><p>В корзине пока пусто.</p><a class="btn btn--primary" href="/catalog.php">Перейти в каталог</a></div>
    <?php else: ?>
        <form method="post" action="/cart.php" class="cart-checkout-form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <div class="cart-layout">
                <div class="cart-items">
                    <?php foreach ($items as $item):
                        $productId = (int)$item['product_id'];
                        $outOfStock = (int)$item['stock'] === 0;
                        $choice = !empty($item['request_replacement']) ? 'replacement' : (!empty($item['notify_on_available']) ? 'notify' : '');
                    ?>
                        <article class="cart-item <?= $outOfStock ? 'cart-item--preorder' : '' ?>">
                            <a class="cart-item__image" href="/product.php?id=<?= $productId ?>"><img src="<?= $item['main_image'] !== '' ? '/uploads/products/' . rawurlencode(basename($item['main_image'])) : '/assets/images/no-image.jpg' ?>" alt=""></a>
                            <div class="cart-item__details">
                                <div class="product-card__article">Арт. <?= htmlspecialchars((string)$item['article'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                                <a class="cart-item__name" href="/product.php?id=<?= $productId ?>"><?= htmlspecialchars((string)$item['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                                <?php if ($outOfStock): ?><span class="cart-item__stock-label">Под заказ · сейчас нет в наличии</span><?php endif; ?>
                                <div class="cart-item__price">
                                    <?= number_format((float)$item['final_price'], 0, '.', ' ') ?> ₽
                                    <?php if ((int)$item['applied_discount'] > 0): ?><del class="product-card__price-old"><?= number_format((float)$item['price'], 0, '.', ' ') ?> ₽</del> <span class="price-discount">(<?= (int)$item['applied_discount'] ?>%)</span><?php endif; ?> / шт.
                                </div>
                                <?php if ($outOfStock): ?>
                                    <fieldset class="cart-item__choices">
                                        <legend>Если товара пока нет:</legend>
                                        <label><input type="radio" name="preorder_choice[<?= $productId ?>]" value="replacement" <?= $choice === 'replacement' ? 'checked' : '' ?>> Предложить замену</label>
                                        <label><input type="radio" name="preorder_choice[<?= $productId ?>]" value="notify" <?= $choice === 'notify' ? 'checked' : '' ?>> Сообщить, когда появится</label>
                                    </fieldset>
                                <?php endif; ?>
                            </div>
                            <div class="cart-item__controls">
                                <label class="visually-hidden" for="cart-quantity-<?= $productId ?>">Количество</label>
                                <input id="cart-quantity-<?= $productId ?>" type="number" name="quantity[<?= $productId ?>]" min="1" max="999" value="<?= (int)$item['quantity'] ?>">
                                <button class="btn btn--small" type="submit" name="action" value="set:<?= $productId ?>">Обновить</button>
                                <button class="cart-remove" type="submit" name="action" value="remove:<?= $productId ?>">Удалить</button>
                            </div>
                            <div class="cart-item__line-total"><?= number_format((float)$item['final_price'] * (int)$item['quantity'], 0, '.', ' ') ?> ₽</div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <aside class="cart-summary">
                    <h2>Итого</h2>
                    <div class="cart-summary__row"><span>Товаров</span><strong><?= (int)$summary['count'] ?> шт.</strong></div>
                    <div class="cart-summary__total"><span>Сумма</span><strong><?= number_format((float)$summary['total'], 0, '.', ' ') ?> ₽</strong></div>
                    <p class="cart-summary__note"><?= !empty($summary['discount']) ? 'Учтена ваша персональная скидка ' . (int)$summary['discount'] . '%' : 'Стоимость рассчитана по текущим ценам товаров.' ?></p>
                    <?php if (!$isLoggedIn): ?>
                        <div class="cart-checkout__contact">
                            <h3>Контактные данные</h3>
                            <label>Имя <input type="text" name="name" maxlength="255" required value="<?= htmlspecialchars((string)($_POST['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></label>
                            <label>Телефон <input type="tel" name="phone" required autocomplete="tel" placeholder="+7 999 999-99-99" value="<?= htmlspecialchars((string)($_POST['phone'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></label>
                        </div>
                    <?php endif; ?>
                    <label class="cart-checkout__comment">Комментарий (необязательно)<textarea name="comment" rows="3" maxlength="3000"><?= htmlspecialchars((string)($_POST['comment'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea></label>
                    <button class="btn btn--primary cart-checkout__button" type="submit" name="action" value="checkout">Оформить заказ</button>
                    <p class="cart-summary__note">Оплата онлайн не требуется. Заказ будет сохранён, после чего магазин свяжется с вами.</p>
                </aside>
            </div>
        </form>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/templates/footer.php'; ?>
