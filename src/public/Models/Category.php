<?php
declare(strict_types=1);

require_once ROOT_PATH . '/config/database.php';

/**
 * Модель категории
 */
class Category
{
    /**
     * Получить все категории
     */
    public static function getAll(): array
    {
        $db = getDBConnection();
        return $db->query("SELECT * FROM categories ORDER BY name")->fetchAll();
    }

    /**
     * Получить категорию по slug
     */
    public static function getBySlug(string $slug): ?array
    {
        $db = getDBConnection();
        
        $stmt = $db->prepare("SELECT * FROM categories WHERE slug = :slug");
        $stmt->bindValue(':slug', $slug);
        $stmt->execute();
        
        $category = $stmt->fetch();
        return $category ?: null;
    }

    /**
     * Получить товары категории
     */
    public static function getProducts(int $categoryId, int $page = 1, int $perPage = PRODUCTS_PER_PAGE): array
    {
        $db = getDBConnection();
        $offset = ($page - 1) * $perPage;
        
        $sql = "SELECT p.*, 
                       COALESCE(pi.file_path, '') as main_image
                FROM products p
                JOIN product_categories pc ON p.id = pc.product_id
                LEFT JOIN product_images pi ON p.id = pi.product_id AND pi.sort_order = 0
                WHERE pc.category_id = :category_id
                ORDER BY p.created_at DESC
                LIMIT :limit OFFSET :offset";
        
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':category_id', $categoryId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
}