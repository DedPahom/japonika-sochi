<?php
declare(strict_types=1);

require_once ROOT_PATH . '/config/database.php';
require_once __DIR__ . '/CustomerPricing.php';

/** Session-based cart backed by the existing carts/cart_items tables. */
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
        $stmt = $db->prepare("SELECT ci.product_id, ci.quantity, p.article, p.name, p.price, p.no_discount, p.quantity AS stock, COALESCE((SELECT pi.file_path FROM product_images pi WHERE pi.product_id = p.id ORDER BY pi.sort_order, pi.id LIMIT 1), '') AS main_image
            FROM cart_items ci JOIN products p ON p.id = ci.product_id WHERE ci.cart_id = ? ORDER BY ci.created_at DESC");
        $stmt->execute([$cartId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function summary(): array
    {
        $items = self::getItems();
        $count = 0;
        $total = 0.0;
        $discount = CustomerPricing::currentDiscount();
        foreach ($items as &$item) {
            $count += (int)$item['quantity'];
            $basePrice = (float)$item['price'];
            $item['final_price'] = !empty($item['no_discount']) ? $basePrice : floor($basePrice * (1 - $discount / 100));
            $item['applied_discount'] = (!empty($item['no_discount']) || $item['final_price'] >= $basePrice) ? 0 : $discount;
            $total += (float)$item['final_price'] * (int)$item['quantity'];
        }
        unset($item);
        return ['items' => $items, 'count' => $count, 'total' => $total, 'discount' => $discount];
    }
}
