<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$db = getDBConnection();
if (empty($_SESSION['promotion_import_csrf'])) $_SESSION['promotion_import_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['promotion_import_csrf'];
$stageKey = 'promotion_import_stage';

function promoImportH(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function promoImportRedirect(string $notice): void
{
    header('Location: /admin/import-promotions.php?' . http_build_query(['notice' => $notice]));
    exit;
}

function promoImportDate(string $value, string $field): string
{
    $value = trim($value);
    $format = strlen($value) === 10 ? '!Y-m-d' : '!Y-m-d\\TH:i:s';
    $date = DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('Europe/Moscow'));
    $errors = DateTimeImmutable::getLastErrors();
    $expected = strlen($value) === 10 ? 'Y-m-d' : 'Y-m-d\\TH:i:s';
    if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format($expected) !== $value) {
        throw new RuntimeException("Поле {$field}: ожидается YYYY-MM-DD либо YYYY-MM-DDT00:00:00.");
    }
    return $date->format('Y-m-d');
}

function promoImportResolveProducts(PDO $db, array $guids): array
{
    $guids = array_values(array_unique(array_map(static fn($v) => strtolower(trim((string)$v)), $guids)));
    if (!$guids || count($guids) > 5000) throw new RuntimeException('В акции должен быть список от 1 до 5000 товаров.');
    foreach ($guids as $guid) if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $guid)) throw new RuntimeException("Некорректный onec_id: {$guid}");
    $marks = implode(',', array_fill(0, count($guids), '?'));
    $stmt = $db->prepare("SELECT id, onec_id, article, name, no_discount FROM products WHERE LOWER(onec_id) IN ({$marks})");
    $stmt->execute($guids);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $found = [];
    foreach ($rows as $row) $found[strtolower((string)$row['onec_id'])] = $row;
    $missing = array_values(array_diff($guids, array_keys($found)));
    if ($missing) throw new RuntimeException('В каталоге не найдены onec_id: ' . implode(', ', array_slice($missing, 0, 10)) . (count($missing) > 10 ? ' …' : ''));
    $blocked = [];
    foreach ($found as $guid => $row) if ((int)$row['no_discount'] === 1) $blocked[] = $guid;
    if ($blocked) throw new RuntimeException('Товары no_discount нельзя включать в акции: ' . implode(', ', array_slice($blocked, 0, 10)) . (count($blocked) > 10 ? ' …' : ''));
    $ids = [];
    foreach ($guids as $guid) $ids[] = (int)$found[$guid]['id'];
    return $ids;
}

function promoImportNormalizeJson(PDO $db, string $json): array
{
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data) || (int)($data['schema_version'] ?? 0) !== 1 || !isset($data['promotions']) || !is_array($data['promotions'])) throw new RuntimeException('Ожидается JSON schema_version=1 и массив promotions.');
    if (count($data['promotions']) > 2000) throw new RuntimeException('В файле не должно быть более 2000 акций.');
    $normalized = []; $keys = [];
    foreach ($data['promotions'] as $index => $row) {
        $line = $index + 1;
        if (!is_array($row)) throw new RuntimeException("Акция {$line}: ожидался объект.");
        $type = (string)($row['type'] ?? '');
        if (!in_array($type, ['product_discount', 'quantity_discount'], true)) throw new RuntimeException("Акция {$line}: неверный type.");
        $title = trim((string)($row['title'] ?? ''));
        if ($title === '' || mb_strlen($title, 'UTF-8') > 255) throw new RuntimeException("Акция {$line}: title обязателен и не длиннее 255 символов.");
        $discount = filter_var($row['discount_percent'] ?? null, FILTER_VALIDATE_INT);
        if ($discount === false || $discount < 1 || $discount > 99) throw new RuntimeException("Акция {$line}: discount_percent должен быть целым числом от 1 до 99.");
        $start = promoImportDate((string)($row['start_date'] ?? ''), "start_date акции {$line}");
        $end = promoImportDate((string)($row['end_date'] ?? ''), "end_date акции {$line}");
        if ($end < $start) throw new RuntimeException("Акция {$line}: end_date раньше start_date.");
        $enabled = $row['enabled'] ?? null;
        if (!is_bool($enabled)) throw new RuntimeException("Акция {$line}: enabled должен быть true или false.");
        $minimum = 0;
        if ($type === 'quantity_discount') {
            $minimum = filter_var($row['min_quantity'] ?? null, FILTER_VALIDATE_INT);
            if ($minimum === false || $minimum < 2 || $minimum > 100000) throw new RuntimeException("Акция {$line}: min_quantity должен быть целым числом от 2 до 100000.");
        }
        $guids = $row['product_onec_ids'] ?? null;
        if (!is_array($guids) || !$guids || count($guids) > 5000) throw new RuntimeException("Акция {$line}: product_onec_ids должен быть непустым массивом.");
        $productIds = promoImportResolveProducts($db, $guids);
        $externalId = trim((string)($row['promotion_id'] ?? ''));
        if ($externalId !== '' && !preg_match('/^[A-Za-z0-9._:-]{1,80}$/', $externalId)) throw new RuntimeException("Акция {$line}: promotion_id содержит недопустимые символы.");
        // The supplied example has no ID; stable key from type+title makes repeated uploads update that promo.
        $identity = $externalId !== '' ? 'id:' . $externalId : 'title:' . mb_strtolower($title, 'UTF-8');
        $importKey = 'json:' . hash('sha256', $type . "\0" . $identity);
        if (isset($keys[$importKey])) throw new RuntimeException("Акция {$line}: повторяется promotion_id или пара type + title.");
        $keys[$importKey] = true;
        $normalized[] = ['type'=>$type,'title'=>$title,'discount_percent'=>$discount,'start_date'=>$start,'end_date'=>$end,'enabled'=>$enabled,'min_quantity'=>$minimum,'product_ids'=>$productIds,'import_key'=>$importKey];
    }
    return $normalized;
}

function promoImportSaveLinks(PDO $db, int $promoId, array $productIds): void
{
    $db->prepare('DELETE FROM product_promos WHERE promo_id = ?')->execute([$promoId]);
    $stmt = $db->prepare('INSERT INTO product_promos (product_id, promo_id) VALUES (?, ?)');
    foreach (array_unique(array_map('intval', $productIds)) as $productId) $stmt->execute([$productId, $promoId]);
}

$notice = (string)($_GET['notice'] ?? '') === 'imported' ? 'Импорт завершён: акции обновлены или созданы, список товаров синхронизирован.' : '';
$error = '';
$preview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Сессия формы устарела. Обновите страницу.'); }
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'upload_preview') {
            if (empty($_FILES['promotions_json']) || (int)$_FILES['promotions_json']['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Выберите JSON-файл акций.');
            if ((int)$_FILES['promotions_json']['size'] > 10 * 1024 * 1024) throw new RuntimeException('Максимальный размер файла — 10 МБ.');
            $raw = file_get_contents((string)$_FILES['promotions_json']['tmp_name']);
            if ($raw === false) throw new RuntimeException('Не удалось прочитать файл.');
            $promos = promoImportNormalizeJson($db, $raw);
            if (!$promos) throw new RuntimeException('В JSON нет акций для импорта.');
            $stagePath = tempnam(sys_get_temp_dir(), 'promotions-import-');
            if ($stagePath === false || file_put_contents($stagePath, json_encode($promos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) throw new RuntimeException('Не удалось сохранить файл предпросмотра.');
            @chmod($stagePath, 0600);
            if (!empty($_SESSION[$stageKey]) && is_file((string)$_SESSION[$stageKey])) @unlink((string)$_SESSION[$stageKey]);
            $_SESSION[$stageKey] = $stagePath;
            $preview = ['count'=>count($promos),'products'=>array_sum(array_map(static fn($p)=>count($p['product_ids']),$promos)),'items'=>$promos];
        } elseif ($action === 'confirm_import') {
            $path = (string)($_SESSION[$stageKey] ?? '');
            unset($_SESSION[$stageKey]);
            if ($path === '' || !is_file($path)) promoImportRedirect('stage_expired');
            $promos = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            @unlink($path);
            $db->beginTransaction();
            try {
                $find = $db->prepare('SELECT id FROM promos WHERE import_key = ? LIMIT 1');
                foreach ($promos as $p) {
                    $startAt = $p['start_date'] . ' 00:00:00';
                    $endAt = $p['end_date'] . ' 23:59:59';
                    $find->execute([$p['import_key']]);
                    $id = $find->fetchColumn();
                    if ($id !== false) {
                        $promoId = (int)$id;
                        $db->prepare('UPDATE promos SET name=?,discount=?,start_date=?,end_date=?,promotion_type=?,minimum_quantity=?,enabled=? WHERE id=?')
                            ->execute([$p['title'],$p['discount_percent'],$startAt,$endAt,$p['type'],$p['min_quantity'],(int)$p['enabled'],$promoId]);
                    } else {
                        $db->prepare('INSERT INTO promos (name,discount,start_date,end_date,promotion_type,minimum_quantity,enabled,import_key) VALUES (?,?,?,?,?,?,?,?)')
                            ->execute([$p['title'],$p['discount_percent'],$startAt,$endAt,$p['type'],$p['min_quantity'],(int)$p['enabled'],$p['import_key']]);
                        $promoId = (int)$db->lastInsertId();
                    }
                    promoImportSaveLinks($db, $promoId, $p['product_ids']);
                }
                $db->commit();
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                throw $e;
            }
            promoImportRedirect('imported');
        } else {
            throw new RuntimeException('Неизвестное действие формы.');
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('Promotion import failed: ' . $e->getMessage());
        $error = $e instanceof JsonException ? 'JSON повреждён или имеет неверный формат.' : $e->getMessage();
    }
}

if (!$preview && !empty($_SESSION[$stageKey]) && is_file((string)$_SESSION[$stageKey])) {
    $staged = json_decode((string)file_get_contents((string)$_SESSION[$stageKey]), true);
    if (is_array($staged)) $preview = ['count'=>count($staged),'products'=>array_sum(array_map(static fn($p)=>count($p['product_ids'] ?? []),$staged)),'items'=>$staged];
} elseif (!empty($_SESSION[$stageKey])) {
    unset($_SESSION[$stageKey]);
}

$pageTitle = 'Импорт акций — Админ-панель';
require __DIR__ . '/../templates/header.php';
?>
<div class="admin admin-promotions">
    <div class="admin__header"><h1>Импорт акций</h1><div><a href="/admin/promotions.php" class="btn btn--small">Управление акциями</a> <a href="/admin/index.php" class="btn btn--small">← В админку</a></div></div>
    <?php if ($notice): ?><div class="admin-notice"><?= promoImportH($notice) ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert--error"><?= promoImportH($error) ?></div><?php endif; ?>
    <section class="promo-admin-panel"><h2>Загрузить JSON из 1С</h2>
        <p>Выберите файл с акциями. Файл будет проверен до изменения базы; после успешной проверки потребуется подтвердить импорт.</p>
        <form method="post" enctype="multipart/form-data" class="promo-admin-upload">
            <input type="hidden" name="csrf" value="<?= promoImportH($csrf) ?>">
            <input type="hidden" name="action" value="upload_preview">
            <label>JSON-файл акций<input type="file" name="promotions_json" accept="application/json,.json" required></label>
            <button class="btn btn--primary">Проверить файл</button>
        </form>
        <?php if ($preview): ?>
        <div class="promo-admin-preview">
            <strong>Предпросмотр:</strong> <?= (int)$preview['count'] ?> акций, <?= (int)$preview['products'] ?> связей «акция — товар».
            <p>Товары, которых нет в каталоге, и товары с <code>no_discount</code> блокируют импорт при проверке.</p>
            <?php if (!empty($preview['items'])): ?><div class="promo-admin-table-wrap"><table class="admin-table"><thead><tr><th>Акция</th><th>Скидка</th><th>Период</th><th>Товаров</th></tr></thead><tbody>
                <?php foreach (array_slice($preview['items'], 0, 30) as $item): ?><tr><td><?= promoImportH($item['title']) ?><br><small><?= $item['type'] === 'quantity_discount' ? 'От ' . (int)$item['min_quantity'] . ' шт.' : 'На товар' ?></small></td><td><?= (int)$item['discount_percent'] ?>%</td><td><?= promoImportH($item['start_date']) ?> — <?= promoImportH($item['end_date']) ?></td><td><?= count($item['product_ids']) ?></td></tr><?php endforeach; ?>
            </tbody></table></div><?php if (count($preview['items']) > 30): ?><p>Показаны первые 30 акций.</p><?php endif; ?><?php endif; ?>
            <form method="post"><input type="hidden" name="csrf" value="<?= promoImportH($csrf) ?>"><input type="hidden" name="action" value="confirm_import"><button class="btn btn--primary">Подтвердить импорт</button></form>
        </div>
        <?php endif; ?>
    </section>
    <section class="promo-admin-panel"><h2>Правила импорта</h2><ul>
        <li>Поддерживаются типы <code>product_discount</code> и <code>quantity_discount</code>.</li>
        <li>Вторую загрузку той же акции связывайте через неизменный <code>promotion_id</code>; если ID не задан, используется сочетание типа и названия.</li>
        <li>Импорт обновляет только акции из файла; отсутствующие в JSON акции не удаляются.</li>
        <li>Даты интерпретируются по Москве: начало — 00:00, окончание — 23:59:59 включительно.</li>
    </ul></section>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
