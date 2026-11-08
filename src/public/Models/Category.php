<?php
declare(strict_types=1);

require_once ROOT_PATH . '/config/database.php';

class Category
{
    public static function getAll(): array
    {
        return getDBConnection()->query('SELECT * FROM categories ORDER BY name')->fetchAll();
    }

    /** All categories that have at least one directly assigned product in their subtree. */
    public static function getVisibleAll(): array
    {
        $rows = self::getAll();
        $children = [];
        $direct = [];
        foreach ($rows as $row) {
            $parentId = $row['parent_id'] === null ? 0 : (int)$row['parent_id'];
            $children[$parentId][] = (int)$row['id'];
            $direct[(int)$row['id']] = false;
        }
        $stmt = getDBConnection()->query('SELECT DISTINCT category_id FROM product_categories');
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) $direct[(int)$id] = true;

        $hasProducts = [];
        $visiting = [];
        $containsProduct = static function (int $id) use (&$containsProduct, &$hasProducts, &$visiting, $direct, $children): bool {
            if (isset($hasProducts[$id])) return $hasProducts[$id];
            if (isset($visiting[$id])) return false; // Protect against accidental category cycles.
            $visiting[$id] = true;
            $contains = $direct[$id] ?? false;
            if (!$contains) {
                foreach ($children[$id] ?? [] as $childId) {
                    if ($containsProduct($childId)) { $contains = true; break; }
                }
            }
            unset($visiting[$id]);
            return $hasProducts[$id] = $contains;
        };

        $visible = [];
        foreach ($rows as $row) {
            if ($containsProduct((int)$row['id'])) $visible[] = $row;
        }
        return $visible;
    }

    public static function getBySlug(string $slug): ?array
    {
        $stmt = getDBConnection()->prepare('SELECT * FROM categories WHERE slug = ? LIMIT 1');
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function getRoots(): array
    {
        $stmt = getDBConnection()->query('SELECT * FROM categories WHERE parent_id IS NULL ORDER BY name');
        $roots = $stmt->fetchAll();
        $visibleIds = array_fill_keys(array_map(static fn($r) => (int)$r['id'], self::getVisibleAll()), true);
        return array_values(array_filter($roots, static fn($r) => isset($visibleIds[(int)$r['id']])));
    }

    public static function getChildren(int $parentId): array
    {
        $stmt = getDBConnection()->prepare('SELECT * FROM categories WHERE parent_id = ? ORDER BY name');
        $stmt->execute([$parentId]);
        $children = $stmt->fetchAll();
        $visibleIds = array_fill_keys(array_map(static fn($r) => (int)$r['id'], self::getVisibleAll()), true);
        return array_values(array_filter($children, static fn($r) => isset($visibleIds[(int)$r['id']])));
    }

    /** Full breadcrumb, starting at root and ending at the selected category. */
    public static function getPath(int $categoryId): array
    {
        $db = getDBConnection();
        $stmt = $db->prepare('SELECT * FROM categories WHERE id = ?');
        $path = [];
        $id = $categoryId;
        for ($depth = 0; $id && $depth < 100; $depth++) {
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) break;
            array_unshift($path, $row);
            $id = $row['parent_id'] === null ? 0 : (int)$row['parent_id'];
        }
        return $path;
    }

    /** IDs of the selected category and optionally all descendants. */
    public static function getScopeIds(int $categoryId, bool $includeDescendants): array
    {
        if (!$includeDescendants) return [$categoryId];
        $rows = getDBConnection()->query('SELECT id, parent_id FROM categories')->fetchAll();
        $children = [];
        foreach ($rows as $row) $children[(int)($row['parent_id'] ?? 0)][] = (int)$row['id'];
        $result = [];
        $stack = [$categoryId];
        while ($stack) {
            $id = array_pop($stack);
            if (isset($result[$id])) continue;
            $result[$id] = true;
            foreach ($children[$id] ?? [] as $childId) $stack[] = $childId;
        }
        return array_map('intval', array_keys($result));
    }

    public static function getProducts(int $categoryId, bool $includeDescendants, int $page = 1, int $perPage = PRODUCTS_PER_PAGE, string $sort = 'popular'): array
    {
        $ids = self::getScopeIds($categoryId, $includeDescendants);
        if (!$ids) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $offset = max(0, ($page - 1) * $perPage);
        $sortOrder = match ($sort) {
            'article' => 'p.article ASC, p.id ASC',
            'cheaper' => 'p.price ASC, p.article ASC, p.id ASC',
            'expensive' => 'p.price DESC, p.article ASC, p.id ASC',
            default => 'COALESCE(p.order_count, 0) DESC, COALESCE(p.ordered_quantity, 0) DESC, COALESCE(p.view_count, 0) DESC, p.article ASC, p.id ASC',
        };
        $sql = "SELECT p.*, COALESCE((SELECT pi.file_path FROM product_images pi WHERE pi.product_id=p.id ORDER BY pi.sort_order, pi.id LIMIT 1), '') AS main_image
                FROM products p
                WHERE p.id IN (SELECT DISTINCT pc.product_id FROM product_categories pc WHERE pc.category_id IN ($marks))
                ORDER BY CASE WHEN p.quantity > 0 THEN 0 ELSE 1 END, {$sortOrder} LIMIT ? OFFSET ?";
        $stmt = getDBConnection()->prepare($sql);
        $params = array_merge($ids, [$perPage, $offset]);
        foreach ($params as $i => $value) $stmt->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function countProducts(int $categoryId, bool $includeDescendants): int
    {
        $ids = self::getScopeIds($categoryId, $includeDescendants);
        if (!$ids) return 0;
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = getDBConnection()->prepare("SELECT COUNT(DISTINCT pc.product_id) FROM product_categories pc WHERE pc.category_id IN ($marks)");
        $stmt->execute($ids);
        return (int)$stmt->fetchColumn();
    }
}
