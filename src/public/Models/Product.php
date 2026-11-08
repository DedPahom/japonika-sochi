<?php
declare(strict_types=1);

require_once ROOT_PATH . '/config/database.php';

/**
 * Модель товара
 */
class Product
{
    /**
     * Получить товары с пагинацией
     */
    public static function getList(int $page = 1, int $perPage = PRODUCTS_PER_PAGE, string $sort = 'popular'): array
    {
        $db = getDBConnection();
        $offset = max(0, ($page - 1) * $perPage);
        $sortOrder = match ($sort) {
            'article' => 'p.article ASC, p.id ASC',
            'cheaper' => 'p.price ASC, p.article ASC, p.id ASC',
            'expensive' => 'p.price DESC, p.article ASC, p.id ASC',
            default => 'COALESCE(p.order_count, 0) DESC, COALESCE(p.ordered_quantity, 0) DESC, COALESCE(p.view_count, 0) DESC, p.article ASC, p.id ASC',
        };

        // In-stock products are always listed before preorder/out-of-stock products.
        $sql = "SELECT p.*, COALESCE(pi.file_path, '') AS main_image
                FROM products p
                LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.sort_order = 0
                ORDER BY CASE WHEN p.quantity > 0 THEN 0 ELSE 1 END, {$sortOrder}
                LIMIT :limit OFFSET :offset";

        $stmt = $db->prepare($sql);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Count at most one view per session per product in a 24-hour window. */
    public static function recordView(int $productId): void
    {
        if ($productId < 1) return;
        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        if (!empty($_SESSION['admin_id'])) return;

        $now = time();
        $viewed = $_SESSION['product_viewed_at'] ?? [];
        if (!is_array($viewed)) $viewed = [];
        foreach ($viewed as $id => $timestamp) {
            if (!is_numeric($timestamp) || (int)$timestamp < $now - 86400) unset($viewed[$id]);
        }
        $key = (string)$productId;
        if (isset($viewed[$key])) { $_SESSION['product_viewed_at'] = $viewed; return; }
        $viewed[$key] = $now;
        if (count($viewed) > 500) {
            asort($viewed, SORT_NUMERIC);
            $viewed = array_slice($viewed, -500, null, true);
        }
        $_SESSION['product_viewed_at'] = $viewed;
        getDBConnection()->prepare('UPDATE products SET view_count = view_count + 1 WHERE id = ?')->execute([$productId]);
    }

    /**
     * Получить общее количество товаров
     */
    public static function getTotalCount(): int
    {
        $db = getDBConnection();
        return (int) $db->query("SELECT COUNT(*) FROM products")->fetchColumn();
    }

    /**
     * Получить товар по ID
     */
    public static function getById(int $id): ?array
    {
        $db = getDBConnection();
        
        $sql = "SELECT p.*, 
                       COALESCE(pi.file_path, '') as main_image
                FROM products p
                LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.sort_order = 0
                WHERE p.id = :id";
        
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        
        $product = $stmt->fetch();
        return $product ?: null;
    }

    /**
     * Получить изображения товара
     */
    public static function getImages(int $productId): array
    {
        $db = getDBConnection();
        
        $stmt = $db->prepare("SELECT * FROM product_images WHERE product_id = :id ORDER BY sort_order");
        $stmt->bindValue(':id', $productId, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }

    /**
     * Получить категории товара
     */
    public static function getCategories(int $productId): array
    {
        $db = getDBConnection();
        
        $sql = "SELECT c.* FROM categories c
                JOIN product_categories pc ON c.id = pc.category_id
                WHERE pc.product_id = :id";
        
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':id', $productId, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }

    /**
     * Проверить, есть ли товар в наличии
     */
    public static function isInStock(array $product): bool
    {
        return (int) $product['quantity'] > 0;
    }

    /**
     * Получить цену с учётом скидки
     */
    public static function getFinalPrice(array $product, int $userDiscount = 0, ?int $promoDiscount = null): float
    {
        $price = (float) $product['price'];
        
        // Если скидки не применяются — возвращаем розничную цену
        if ($product['no_discount']) {
            return $price;
        }
        
        // Определяем максимальную скидку
        $discount = 0;
        
        if ($userDiscount > 0) {
            $discount = $userDiscount;
        }
        
        if ($promoDiscount !== null && $promoDiscount > $discount) {
            $discount = $promoDiscount;
        }
        
        // Вычисляем цену со скидкой
        $finalPrice = $price * (1 - $discount / 100);
        
        // Округление до рублей в меньшую сторону
        return floor($finalPrice);
    }
}