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
    public static function getList(int $page = 1, int $perPage = PRODUCTS_PER_PAGE): array
    {
        $db = getDBConnection();
        $offset = ($page - 1) * $perPage;
        
        $sql = "SELECT p.*, 
                       COALESCE(pi.file_path, '') as main_image
                FROM products p
                LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.sort_order = 0
                ORDER BY CASE WHEN p.quantity > 0 THEN 0 ELSE 1 END, p.created_at DESC, p.id DESC
                LIMIT :limit OFFSET :offset";
        
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
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