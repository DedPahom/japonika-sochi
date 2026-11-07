<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../Models/Phone.php';

const USERS_IMPORT_MAX_BYTES = 20 * 1024 * 1024;
const USERS_IMPORT_MAX_ROWS = 50000;
const USERS_IMPORT_STAGE_KEY = 'users_import_stage';

$db = getDBConnection();
if (empty($_SESSION['users_import_csrf'])) $_SESSION['users_import_csrf'] = bin2hex(random_bytes(32));
$csrf = (string)$_SESSION['users_import_csrf'];
$message = '';
$errors = [];
$preview = null;

function usersImportH(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/** Convert a 1C local date-time (no offset) into the site's DATETIME value (Moscow local time). */
function usersImportDate(mixed $raw): ?string
{
    if ($raw === null || trim((string)$raw) === '') return null;
    $value = trim((string)$raw);
    $zone = new DateTimeZone('Europe/Moscow');
    foreach (['!Y-m-d\\TH:i:s', '!Y-m-d\\TH:i', '!Y-m-d H:i:s', '!d.m.Y H:i:s', '!d.m.Y G:i:s', '!d.m.Y H:i'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value, $zone);
        $issues = DateTimeImmutable::getLastErrors();
        if ($date && ($issues === false || ($issues['warning_count'] === 0 && $issues['error_count'] === 0))) {
            $checkFormat = ltrim($format, '!');
            if ($date->format($checkFormat) === $value) return $date->format('Y-m-d H:i:s');
        }
    }
    throw new RuntimeException('Некорректная дата окончания скидки: ' . $value);
}

/** Validate incoming root-array JSON; use first phone from comma-separated values. */
function parseUsersImport(string $json): array
{
    try { $rows = json_decode($json, true, 512, JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { throw new RuntimeException('Некорректный JSON: ' . $e->getMessage()); }
    if (!is_array($rows) || !array_is_list($rows)) throw new RuntimeException('Ожидается JSON-массив пользователей на верхнем уровне.');
    if (count($rows) > USERS_IMPORT_MAX_ROWS) throw new RuntimeException('В файле больше 50 000 записей.');

    $valid = [];
    $issues = [];
    $duplicates = 0;
    foreach ($rows as $index => $row) {
        $line = $index + 1;
        if (!is_array($row)) { $issues[] = "Строка {$line}: ожидался объект; запись пропущена."; continue; }
        $phoneValue = $row['phone'] ?? null;
        if (!is_string($phoneValue) && !is_numeric($phoneValue)) { $issues[] = "Строка {$line}: нет телефона; запись пропущена."; continue; }
        $phoneRaw = trim((string)$phoneValue);
        $phoneParts = preg_split('/[,;\r\n]+/u', $phoneRaw) ?: [];
        $phoneCandidate = trim((string)($phoneParts[0] ?? ''));
        if ($phoneCandidate === '') { $issues[] = "Строка {$line}: пустой телефон; запись пропущена."; continue; }
        $phone = Phone::normalizeRussian($phoneCandidate);
        if ($phone === null) { $issues[] = "Строка {$line}: некорректный телефон «{$phoneCandidate}»; запись пропущена."; continue; }
        if (count($phoneParts) > 1) $issues[] = "Строка {$line}: найдено несколько телефонов; использован первый ({$phone}).";

        $discountRaw = $row['discount'] ?? 0;
        if ($discountRaw === null || (is_string($discountRaw) && trim($discountRaw) === '')) $discount = 0;
        elseif (is_int($discountRaw) || is_float($discountRaw) || (is_string($discountRaw) && preg_match('/^\d{1,3}$/', trim($discountRaw)))) $discount = (int)$discountRaw;
        else { $issues[] = "Строка {$line}: некорректная скидка; запись пропущена."; continue; }
        if ($discount < 0 || $discount > 100) { $issues[] = "Строка {$line}: скидка вне диапазона 0–100; запись пропущена."; continue; }

        try { $endDate = usersImportDate($row['discount_end_date'] ?? null); }
        catch (RuntimeException $e) { $issues[] = "Строка {$line}: {$e->getMessage()}; запись пропущена."; continue; }
        if ($discount === 0 || $endDate === null) {
            if ($discount > 0 && $endDate === null) $issues[] = "Строка {$line}: скидка больше 0, но дата пустая; скидка сброшена до 0.";
            $discount = 0;
            $endDate = null;
        }

        if (isset($valid[$phone])) {
            $duplicates++;
            $issues[] = "Строка {$line}: телефон повторяется в файле; применено последнее корректное значение.";
        }
        $valid[$phone] = ['phone' => $phone, 'discount' => $discount, 'discount_end_date' => $endDate, 'source_row' => $line];
    }
    return [array_values($valid), $issues, $duplicates, count($rows)];
}

function usersImportStagePath(): ?string
{
    $path = (string)($_SESSION[USERS_IMPORT_STAGE_KEY] ?? '');
    if ($path === '' || !preg_match('~^' . preg_quote(sys_get_temp_dir(), '~') . '/users-import-[a-f0-9]{32}\.json$~', $path)) return null;
    return is_file($path) ? $path : null;
}
function clearUsersImportStage(): void
{
    $path = usersImportStagePath();
    if ($path !== null) @unlink($path);
    unset($_SESSION[USERS_IMPORT_STAGE_KEY]);
}

function applyUsersImport(PDO $db, array $users): array
{
    $db->beginTransaction();
    try {
        $existingRows = $db->query('SELECT id, phone, is_admin FROM users')->fetchAll(PDO::FETCH_ASSOC);
        $byPhone = [];
        foreach ($existingRows as $row) {
            $canonical = Phone::normalizeRussian((string)$row['phone']);
            if ($canonical !== null) $byPhone[$canonical][] = $row;
        }
        $update = $db->prepare('UPDATE users SET discount = ?, discount_end_date = ? WHERE id = ?');
        $insert = $db->prepare('INSERT INTO users (phone, name, password_hash, discount, discount_end_date, is_admin, is_blocked) VALUES (?, NULL, NULL, ?, ?, 0, 0)');
        $stats = ['created' => 0, 'updated' => 0, 'admin_updated' => 0, 'ambiguous' => 0, 'failed' => 0];
        $details = [];
        foreach ($users as $user) {
            $phone = $user['phone'];
            $matches = $byPhone[$phone] ?? [];
            if (count($matches) > 1) {
                $stats['ambiguous']++;
                $details[] = "{$phone}: в базе несколько аккаунтов — пропущен.";
                continue;
            }
            if (count($matches) === 1) {
                $id = (int)$matches[0]['id'];
                $update->execute([$user['discount'], $user['discount_end_date'], $id]);
                $stats['updated']++;
                if ((int)$matches[0]['is_admin'] === 1) $stats['admin_updated']++;
            } else {
                try {
                    $insert->execute([$phone, $user['discount'], $user['discount_end_date']]);
                    $id = (int)$db->lastInsertId();
                    $row = ['id' => $id, 'phone' => $phone, 'is_admin' => 0];
                    $byPhone[$phone] = [$row];
                    $stats['created']++;
                } catch (PDOException $e) {
                    if ($e->getCode() !== '23000') throw $e;
                    $stats['failed']++;
                    $details[] = "{$phone}: конфликт уникального номера — пропущен.";
                }
            }
        }
        $db->commit();
        return [$stats, $details];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $errors[] = 'Сессия формы истекла. Обновите страницу и повторите.';
    } elseif (isset($_POST['confirm_import'])) {
        $stagePath = usersImportStagePath();
        if ($stagePath === null) $errors[] = 'Файл для подтверждения не найден. Загрузите JSON ещё раз.';
        else {
            try {
                $content = file_get_contents($stagePath);
                if ($content === false) throw new RuntimeException('Не удалось прочитать загруженный JSON.');
                [$users, $issues, $duplicates, $totalRows] = parseUsersImport($content);
                [$stats, $details] = applyUsersImport($db, $users);
                clearUsersImportStage();
                $message = "Импорт завершён. Создано: {$stats['created']}; обновлено существующих: {$stats['updated']}; в том числе администраторов: {$stats['admin_updated']}; неоднозначные номера пропущены: {$stats['ambiguous']}; ошибок базы: {$stats['failed']}. Строк обработано: {$totalRows}.";
                $preview = ['issues' => array_merge($issues, $details), 'stats' => $stats];
            } catch (Throwable $e) { $errors[] = 'Импорт отменён: ' . $e->getMessage(); }
        }
    } elseif (isset($_FILES['users_json'])) {
        clearUsersImportStage();
        $file = $_FILES['users_json'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) $errors[] = 'Не удалось загрузить файл (код ' . (int)$file['error'] . ').';
        elseif ((int)$file['size'] > USERS_IMPORT_MAX_BYTES) $errors[] = 'Файл больше допустимых 20 МБ.';
        elseif (strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'json') $errors[] = 'Загрузите файл с расширением .json.';
        else {
            $json = file_get_contents((string)$file['tmp_name']);
            try {
                if (!is_string($json)) throw new RuntimeException('Не удалось прочитать файл.');
                [$users, $issues, $duplicates, $totalRows] = parseUsersImport($json);
                $counts = ['new' => 0, 'existing' => 0, 'ambiguous' => 0];
                $existing = [];
                foreach ($db->query('SELECT id, phone FROM users')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $n = Phone::normalizeRussian((string)$row['phone']);
                    if ($n !== null) $existing[$n][] = (int)$row['id'];
                }
                foreach ($users as $user) {
                    $n = count($existing[$user['phone']] ?? []);
                    if ($n === 0) $counts['new']++;
                    elseif ($n === 1) $counts['existing']++;
                    else { $counts['ambiguous']++; $issues[] = $user['phone'] . ': найдено несколько аккаунтов на сайте; эта строка будет пропущена.'; }
                }
                $stagePath = sys_get_temp_dir() . '/users-import-' . bin2hex(random_bytes(16)) . '.json';
                if (file_put_contents($stagePath, $json, LOCK_EX) === false) throw new RuntimeException('Не удалось сохранить временный файл импорта.');
                $_SESSION[USERS_IMPORT_STAGE_KEY] = $stagePath;
                $preview = ['valid' => count($users), 'total' => $totalRows, 'counts' => $counts, 'issues' => $issues, 'duplicates' => $duplicates];
            } catch (Throwable $e) { $errors[] = 'Файл не прошёл проверку: ' . $e->getMessage(); }
        }
    } elseif (isset($_POST['cancel_import'])) {
        clearUsersImportStage();
        $message = 'Импорт отменён.';
    }
}

if ($preview === null && usersImportStagePath() !== null) {
    try {
        $content = file_get_contents((string)usersImportStagePath());
        if (is_string($content)) {
            [$users, $issues, $duplicates, $totalRows] = parseUsersImport($content);
            $existing = [];
            foreach ($db->query('SELECT id, phone FROM users')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $n = Phone::normalizeRussian((string)$row['phone']);
                if ($n !== null) $existing[$n][] = (int)$row['id'];
            }
            $counts = ['new'=>0,'existing'=>0,'ambiguous'=>0];
            foreach ($users as $user) { $n=count($existing[$user['phone']]??[]); if($n===0)$counts['new']++;elseif($n===1)$counts['existing']++;else{$counts['ambiguous']++;$issues[]=$user['phone'].': несколько аккаунтов; будет пропущен.';} }
            $preview = ['valid'=>count($users),'total'=>$totalRows,'counts'=>$counts,'issues'=>$issues,'duplicates'=>$duplicates];
        }
    } catch (Throwable $e) { $errors[] = 'Не удалось показать предварительный просмотр: ' . $e->getMessage(); }
}

$pageTitle = 'Импорт пользователей из 1С — Админ-панель';
require __DIR__ . '/../templates/header.php';
?>
<div class="admin admin-users-import">
    <div class="admin__header"><h1>Импорт пользователей из 1С</h1><a class="btn btn--small" href="/admin/index.php">← В админку</a></div>
    <p>Загрузите JSON-массив пользователей. Сначала будет показан предварительный просмотр; изменения применятся только после подтверждения.</p>
    <div class="admin-users-import__rules">
        <strong>Правила импорта</strong>
        <ul>
            <li>Новые номера создаются как аккаунты без пароля; покупатель сможет задать пароль при регистрации.</li>
            <li>Для существующего аккаунта меняются только скидка и дата её окончания — пароль, имя, роль и блокировка сохраняются.</li>
            <li>Если номер содержит несколько значений через запятую, используется первый; некорректные номера пропускаются.</li>
            <li>Пустая дата при скидке больше 0 сбрасывает скидку в 0 и очищает дату. При скидке 0 дата также очищается.</li>
            <li>Телефон нормализуется как российский номер; дата без часового пояса трактуется как местное московское время.</li>
        </ul>
    </div>
    <?php foreach ($errors as $error): ?><div class="alert alert--error"><?= usersImportH($error) ?></div><?php endforeach; ?>
    <?php if ($message !== ''): ?><div class="alert alert--success"><?= usersImportH($message) ?></div><?php endif; ?>

    <?php if ($preview !== null && isset($preview['valid'])): ?>
        <section class="admin-users-import__preview">
            <h2>Предварительный просмотр</h2>
            <p>Строк в файле: <strong><?= (int)$preview['total'] ?></strong>; корректных номеров: <strong><?= (int)$preview['valid'] ?></strong>; новых аккаунтов: <strong><?= (int)$preview['counts']['new'] ?></strong>; существующих обновится: <strong><?= (int)$preview['counts']['existing'] ?></strong>; неоднозначных: <strong><?= (int)$preview['counts']['ambiguous'] ?></strong>; повторов в JSON: <strong><?= (int)$preview['duplicates'] ?></strong>.</p>
            <?php if (!empty($preview['issues'])): ?><details><summary>Предупреждения и пропущенные записи (<?= count($preview['issues']) ?>)</summary><ul class="admin-users-import__issues"><?php foreach ($preview['issues'] as $issue): ?><li><?= usersImportH($issue) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
            <div class="admin-users-import__actions">
                <form method="post"><input type="hidden" name="csrf" value="<?= usersImportH($csrf) ?>"><button class="btn btn--primary" name="confirm_import" value="1" type="submit">Подтвердить импорт</button></form>
                <form method="post"><input type="hidden" name="csrf" value="<?= usersImportH($csrf) ?>"><button class="btn btn--small" name="cancel_import" value="1" type="submit">Отменить</button></form>
            </div>
        </section>
    <?php elseif ($preview !== null && isset($preview['stats'])): ?>
        <?php if (!empty($preview['issues'])): ?><details open><summary>Предупреждения и пропущенные записи (<?= count($preview['issues']) ?>)</summary><ul class="admin-users-import__issues"><?php foreach ($preview['issues'] as $issue): ?><li><?= usersImportH($issue) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
    <?php else: ?>
        <form class="form admin-users-import__form" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= usersImportH($csrf) ?>">
            <label for="users-json">JSON-файл (до 20 МБ)</label>
            <input id="users-json" type="file" name="users_json" accept=".json,application/json" required>
            <button class="btn btn--primary" type="submit">Проверить файл</button>
        </form>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../templates/footer.php'; ?>
