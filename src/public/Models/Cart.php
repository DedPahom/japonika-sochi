<?php
declare(strict_types=1);

require_once ROOT_PATH . '/config/database.php';
require_once __DIR__ . '/CustomerPricing.php';
require_once __DIR__ . '/Phone.php';
require_once __DIR__ . '/Promotions.php';

/** Session cart with checkout snapshots and out-of-stock customer preferences. */
class Cart
{
    private static function startSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    }

    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['cart_csrf'])) $_SESSION['cart_csrf'] = bin2hex(random_bytes(32));
        return (string)$_SESSION['cart_csrf'];
    }

    public static function validateCsrf(?string $token): bool
    {
        self::startSession();
        return isset($_SESSION['cart_csrf']) && is_string($token) && hash_equals($_SESSION['cart_csrf'], $token);
    }

    private static function cartId(PDO $db, bool $create = true): ?int
    {
        self::startSession();
        $sessionId = session_id();
        if ($sessionId === '') return null;
        $stmt = $db->prepare('SELECT id FROM carts WHERE session_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$sessionId]);
        $id = $stmt->fetchColumn();
        if ($id !== false) return (int)$id;
        if (!$create) return null;
        $stmt = $db->prepare('INSERT INTO carts (session_id) VALUES (?)');
        $stmt->execute([$sessionId]);
        return (int)$db->lastInsertId();
    }

    public static function add(int $productId, int $quantity = 1): void
    {
        if ($productId < 1 || $quantity < 1 || $quantity > 999) throw new InvalidArgumentException('Некорректный товар или количество.');
        $db = getDBConnection();
        $exists = $db->prepare('SELECT id FROM products WHERE id = ?');
        $exists->execute([$productId]);
        if (!$exists->fetchColumn()) throw new RuntimeException('Товар не найден.');
        $db->beginTransaction();
        try {
            $cartId = self::cartId($db, true);
            $stmt = $db->prepare('SELECT id, quantity FROM cart_items WHERE cart_id = ? AND product_id = ? ORDER BY id LIMIT 1 FOR UPDATE');
            $stmt->execute([$cartId, $productId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($item) {
                $newQty = min(999, (int)$item['quantity'] + $quantity);
                $db->prepare('UPDATE cart_items SET quantity = ? WHERE id = ?')->execute([$newQty, $item['id']]);
            } else {
                $db->prepare('INSERT INTO cart_items (cart_id, product_id, quantity) VALUES (?, ?, ?)')->execute([$cartId, $productId, $quantity]);
            }
            $db->prepare('UPDATE carts SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$cartId]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function setQuantity(int $productId, int $quantity): void
    {
        if ($productId < 1 || $quantity < 1 || $quantity > 999) throw new InvalidArgumentException('Количество должно быть от 1 до 999.');
        $db = getDBConnection();
        $cartId = self::cartId($db, false);
        if ($cartId === null) return;
        $stmt = $db->prepare('UPDATE cart_items SET quantity = ? WHERE cart_id = ? AND product_id = ?');
        $stmt->execute([$quantity, $cartId, $productId]);
    }

    public static function setUnavailablePreference(int $productId, string $choice): void
    {
        if (!in_array($choice, ['replacement', 'notify'], true)) throw new InvalidArgumentException('Выберите один из вариантов для товара под заказ.');
        $db = getDBConnection();
        $cartId = self::cartId($db, false);
        if ($cartId === null) throw new RuntimeException('Корзина не найдена.');
        $check = $db->prepare('SELECT ci.id, p.quantity AS stock FROM cart_items ci JOIN products p ON p.id = ci.product_id WHERE ci.cart_id = ? AND ci.product_id = ?');
        $check->execute([$cartId, $productId]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Позиция не найдена в корзине.');
        // Inventory may have been replenished after the cart page was rendered; in that case
        // the old preorder choice is no longer relevant and should not block cart actions.
        if ((int)$row['stock'] > 0) return;
        $notify = $choice === 'notify' ? 1 : 0;
        $replacement = $choice === 'replacement' ? 1 : 0;
        $db->prepare('UPDATE cart_items SET notify_on_available = ?, request_replacement = ? WHERE id = ?')->execute([$notify, $replacement, (int)$row['id']]);
    }

    public static function remove(int $productId): void
    {
        $db = getDBConnection();
        $cartId = self::cartId($db, false);
        if ($cartId === null) return;
        $db->prepare('DELETE FROM cart_items WHERE cart_id = ? AND product_id = ?')->execute([$cartId, $productId]);
    }

    public static function getItems(): array
    {
        $db = getDBConnection();
        $cartId = self::cartId($db, false);
        if ($cartId === null) return [];
        $stmt = $db->prepare("SELECT ci.product_id, ci.quantity, ci.notify_on_available, ci.request_replacement,
                p.article, p.name, p.price, p.no_discount, p.quantity AS stock,
                COALESCE((SELECT pi.file_path FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.sort_order, pi.id LIMIT 1), '') AS main_image
            FROM cart_items ci JOIN products p ON p.id = ci.product_id
            WHERE ci.cart_id = ? ORDER BY ci.created_at DESC");
        $stmt->execute([$cartId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function activeDiscountForUser(PDO $db, int $userId): int
    {
        $stmt = $db->prepare('SELECT discount, discount_end_date, is_blocked FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || (int)$user['is_blocked'] === 1) return 0;
        $discount = max(0, min(100, (int)$user['discount']));
        $endRaw = trim((string)($user['discount_end_date'] ?? ''));
        if ($discount === 0 || $endRaw === '') return 0;
        $zone = new DateTimeZone('Europe/Moscow');
        $end = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $endRaw, $zone);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$end || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) return 0;
        return $end >= new DateTimeImmutable('now', $zone) ? $discount : 0;
    }

    public static function summary(): array
    {
        $items = self::getItems();
        $count = 0;
        $total = 0.0;
        $personalDiscount = CustomerPricing::currentDiscount();
        $promoDiscounts = Promotions::cartDiscounts($items);
        foreach ($items as &$item) {
            $count += (int)$item['quantity'];
            $basePrice = (float)$item['price'];
            $promo = $promoDiscounts[(int)$item['product_id']] ?? null;
            $effectiveDiscount = !empty($item['no_discount']) ? 0 : max($personalDiscount, (int)($promo['discount_percent'] ?? 0));
            $item['final_price'] = $effectiveDiscount > 0 ? floor($basePrice * (1 - $effectiveDiscount / 100)) : $basePrice;
            $item['applied_discount'] = $item['final_price'] < $basePrice ? $effectiveDiscount : 0;
            $item['applied_promotion_name'] = ($promo && (int)$promo['discount_percent'] >= $personalDiscount && empty($item['no_discount'])) ? $promo['title'] : '';
            $item['promotion_hint'] = '';
            if ($promo && $item['applied_promotion_name'] !== '') $item['promotion_hint'] = $promo['type'] === 'quantity_discount' ? 'Акция: ' . $promo['title'] : 'Акция: ' . $promo['title'];
            $total += (float)$item['final_price'] * (int)$item['quantity'];
        }
        unset($item);
        return ['items' => $items, 'count' => $count, 'total' => $total, 'discount' => $personalDiscount];
    }

    /** Create an order snapshot and empty the cart atomically. $contact is required only for guests. */
    public static function createOrder(array $contact = []): int
    {
        self::startSession();
        $db = getDBConnection();
        $cartId = self::cartId($db, false);
        if ($cartId === null) throw new RuntimeException('Корзина пуста.');

        $db->beginTransaction();
        try {
            $cartLock = $db->prepare('SELECT id FROM carts WHERE id = ? FOR UPDATE');
            $cartLock->execute([$cartId]);
            if (!$cartLock->fetchColumn()) throw new RuntimeException('Корзина не найдена.');
            $itemsStmt = $db->prepare("SELECT ci.id AS cart_item_id, ci.product_id, ci.quantity,
                    ci.notify_on_available, ci.request_replacement, p.article, p.name AS product_name,
                    p.price, p.no_discount, p.quantity AS stock
                FROM cart_items ci JOIN products p ON p.id = ci.product_id
                WHERE ci.cart_id = ? ORDER BY ci.id FOR UPDATE");
            $itemsStmt->execute([$cartId]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$items) throw new RuntimeException('Корзина пуста. Добавьте товары перед оформлением.');

            // Do not let an administrator's own personal account exercise customer discounts.
            $sessionUserId = (int)($_SESSION['user_id'] ?? 0);
            $sessionIsAdmin = !empty($_SESSION['is_admin']);

            foreach ($items as $item) {
                if ((int)$item['stock'] === 0 && (int)$item['notify_on_available'] + (int)$item['request_replacement'] !== 1) {
                    throw new RuntimeException('Выберите для каждого товара с нулевым остатком: «Предложить замену» или «Сообщить, когда появится».');
                }
            }

            $userId = 0;
            $name = '';
            $phone = '';
            if ($sessionUserId > 0) {
                $userStmt = $db->prepare('SELECT id, phone, name, is_blocked FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
                $userStmt->execute([$sessionUserId]);
                $user = $userStmt->fetch(PDO::FETCH_ASSOC);
                if (!$user || (int)$user['is_blocked'] === 1) throw new RuntimeException('Войдите в действующий аккаунт, чтобы оформить заказ.');
                $userId = (int)$user['id'];
                $phone = (string)$user['phone'];
                $name = trim((string)($user['name'] ?? ''));
                if ($name === '' || $phone === '') throw new RuntimeException('В профиле не заполнены имя или телефон. Обратитесь к администратору.');
            } else {
                $name = trim((string)($contact['name'] ?? ''));
                $rawPhone = trim((string)($contact['phone'] ?? ''));
                if ($name === '' || strlen($name) > 255) throw new RuntimeException('Укажите имя (не длиннее 255 символов).');
                $phone = Phone::normalizeRussian($rawPhone) ?? '';
                if ($phone === '') throw new RuntimeException('Введите корректный российский номер телефона.');
                $matches = [];
                foreach ($db->query('SELECT id, phone, is_blocked FROM users')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if (Phone::normalizeRussian((string)$row['phone']) === $phone) $matches[] = $row;
                }
                if (count($matches) > 1) throw new RuntimeException('Для этого телефона найдено несколько аккаунтов. Обратитесь в магазин.');
                if (count($matches) === 1) {
                    if ((int)$matches[0]['is_blocked'] === 1) throw new RuntimeException('Для этого телефона оформление заказа недоступно. Свяжитесь с магазином.');
                    $userId = (int)$matches[0]['id'];
                } else {
                    $createUser = $db->prepare('INSERT INTO users (phone, name, password_hash, discount, discount_end_date, is_admin, is_blocked) VALUES (?, ?, NULL, 0, NULL, 0, 0)');
                    $createUser->execute([$phone, $name]);
                    $userId = (int)$db->lastInsertId();
                }
            }

            $userDiscount = (!$sessionIsAdmin && $sessionUserId > 0) ? self::activeDiscountForUser($db, $userId) : 0;
            $totalRetail = 0.0;
            $totalFinal = 0.0;
            $snapshots = [];
            $promoDiscounts = Promotions::cartDiscounts($items, $db);
            foreach ($items as $item) {
                $retail = (float)$item['price'];
                $qty = max(1, (int)$item['quantity']);
                $promo = $promoDiscounts[(int)$item['product_id']] ?? null;
                $effectiveDiscount = !empty($item['no_discount']) ? 0 : max($userDiscount, (int)($promo['discount_percent'] ?? 0));
                $final = $effectiveDiscount > 0 ? floor($retail * (1 - $effectiveDiscount / 100)) : $retail;
                $appliedPromotion = ($promo && (int)$promo['discount_percent'] >= $userDiscount && empty($item['no_discount'])) ? (string)$promo['title'] : null;
                $appliedPercent = $final < $retail ? $effectiveDiscount : 0;
                $isPreorder = (int)$item['stock'] === 0 ? 1 : 0;
                $notify = $isPreorder ? (int)$item['notify_on_available'] : 0;
                $replacement = $isPreorder ? (int)$item['request_replacement'] : 0;
                $snapshots[] = [$item, $retail, $final, $qty, $isPreorder, $notify, $replacement, $appliedPercent, $appliedPromotion];
                $totalRetail += $retail * $qty;
                $totalFinal += $final * $qty;
            }

            $comment = trim((string)($contact['comment'] ?? ''));
            $orderStmt = $db->prepare("INSERT INTO orders (user_id, phone, name, comment, status, total_retail, total_final)
                VALUES (?, ?, ?, ?, '', ?, ?)");
            $orderStmt->execute([$userId, $phone, $name, $comment !== '' ? $comment : null, number_format($totalRetail, 2, '.', ''), number_format($totalFinal, 2, '.', '')]);
            $orderId = (int)$db->lastInsertId();

            $insertItem = $db->prepare('INSERT INTO order_items (order_id, product_id, product_name, product_article, price_retail, price_final, applied_discount_percent, applied_promotion_name, quantity, is_preorder, notify_on_available, request_replacement) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $incrementPopularity = $db->prepare('UPDATE products SET order_count = order_count + ?, ordered_quantity = ordered_quantity + ? WHERE id = ?');
            $countedProductIds = [];
            foreach ($snapshots as [$item, $retail, $final, $qty, $isPreorder, $notify, $replacement, $appliedPercent, $appliedPromotion]) {
                $productId = (int)$item['product_id'];
                $insertItem->execute([$orderId, $productId, (string)$item['product_name'], (string)$item['article'], number_format($retail, 2, '.', ''), number_format($final, 2, '.', ''), $appliedPercent, $appliedPromotion, $qty, $isPreorder, $notify, $replacement]);
                // Count distinct orders containing the product plus purchased units.
                // All counters update in this order transaction, so rollback remains exact.
                $isFirstLineForProduct = !isset($countedProductIds[$productId]);
                $incrementPopularity->execute([$isFirstLineForProduct ? 1 : 0, $qty, $productId]);
                $countedProductIds[$productId] = true;
            }

            $db->prepare('UPDATE carts SET user_id = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$userId, $cartId]);
            $db->prepare('DELETE FROM cart_items WHERE cart_id = ?')->execute([$cartId]);
            $db->commit();
            return $orderId;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}
