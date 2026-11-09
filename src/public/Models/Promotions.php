<?php
declare(strict_types=1);

require_once ROOT_PATH . '/config/database.php';

/** Promotion lookup and cart discount rules. All date boundaries are Moscow local time. */
class Promotions
{
    private static function nowLocal(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))->format('Y-m-d H:i:s');
    }

    /** Active, eligible product-promotion relations at the current Moscow time. */
    private static function activeRows(?PDO $db = null): array
    {
        $db ??= getDBConnection();
        $now = self::nowLocal();
        $stmt = $db->prepare("SELECT pr.id AS product_id, pr.no_discount, p.id AS promo_id, p.name, p.pub_name, p.picture, p.discount,
                    p.promotion_type, p.minimum_quantity, p.start_date, p.end_date
                FROM promos p
                JOIN product_promos pp ON pp.promo_id = p.id
                JOIN products pr ON pr.id = pp.product_id
                WHERE p.enabled = 1 AND p.start_date <= ? AND p.end_date >= ?");
        $stmt->execute([$now, $now]);
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), static fn($r) => (int)$r['no_discount'] === 0));
    }

    private static function pictureUrl(string $filename): string
    {
        $filename = basename($filename);
        if (!preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp|gif)$/', $filename)) return '';
        return '/uploads/promotions/' . rawurlencode($filename);
    }

    /** Map product IDs to every currently active promo badge. */
    public static function badgesByProductIds(array $productIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn($id) => $id > 0)));
        if (!$ids) return [];
        $wanted = array_fill_keys($ids, true);
        $result = [];
        foreach (self::activeRows() as $row) {
            $productId = (int)$row['product_id'];
            if (!isset($wanted[$productId])) continue;
            $customTitle = trim((string)($row['pub_name'] ?? ''));
            $candidate = [
                'title' => $customTitle !== '' ? $customTitle : (string)$row['name'],
                'has_custom_title' => $customTitle !== '',
                'picture_url' => self::pictureUrl((string)($row['picture'] ?? '')),
                'type' => (string)$row['promotion_type'],
                'discount_percent' => (int)$row['discount'],
                'min_quantity' => (int)$row['minimum_quantity'],
            ];
            if (!isset($result[$productId]) || $candidate['discount_percent'] > $result[$productId]['discount_percent']) $result[$productId] = $candidate;
        }
        return $result;
    }

    /**
     * Determine the best applicable promotion for each cart product. Quantity promos
     * count eligible units across their explicit product list and apply only to that list.
     */
    public static function cartDiscounts(array $items, ?PDO $db = null): array
    {
        $cart = [];
        foreach ($items as $item) {
            $id = (int)($item['product_id'] ?? 0);
            if ($id > 0) $cart[$id] = ['quantity' => max(0, (int)($item['quantity'] ?? 0)), 'no_discount' => !empty($item['no_discount'])];
        }
        if (!$cart) return [];

        $active = [];
        foreach (self::activeRows($db) as $row) {
            $promoId = (int)$row['promo_id'];
            $active[$promoId]['meta'] = $row;
            $active[$promoId]['products'][(int)$row['product_id']] = true;
        }

        $best = [];
        foreach ($active as $promo) {
            $meta = $promo['meta'];
            $type = (string)$meta['promotion_type'];
            $eligibleIds = [];
            $unitCount = 0;
            foreach ($promo['products'] as $productId => $_) {
                if (!isset($cart[$productId]) || $cart[$productId]['no_discount']) continue;
                $eligibleIds[] = (int)$productId;
                $unitCount += $cart[$productId]['quantity'];
            }
            if ($type === 'quantity_discount' && $unitCount < (int)$meta['minimum_quantity']) continue;
            if ($type !== 'product_discount' && $type !== 'quantity_discount') continue;

            foreach ($eligibleIds as $productId) {
                $candidate = [
                    'discount_percent' => (int)$meta['discount'],
                    'title' => (string)$meta['name'],
                    'type' => $type,
                    'min_quantity' => (int)$meta['minimum_quantity'],
                ];
                if (!isset($best[$productId]) || $candidate['discount_percent'] > $best[$productId]['discount_percent']) {
                    $best[$productId] = $candidate;
                }
            }
        }
        return $best;
    }
}
