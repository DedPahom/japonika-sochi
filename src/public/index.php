<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Японика Сочи</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .status {
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        .status.ok {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .status.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        h1 {
            color: #333;
        }
    </style>
</head>
<body>
    <h1>🌸 Японика Сочи</h1>
    <p>Сайт в разработке</p>
    
    <h2>Проверка подключения к базе данных</h2>
    
    <?php
    try {
        if (testDBConnection()) {
            echo '<div class="status ok">✅ Подключение к базе данных успешно!</div>';
            
            // Проверим, есть ли таблицы
            $db = getDBConnection();
            $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            
            if (count($tables) > 0) {
                echo '<p>Таблицы в базе данных:</p>';
                echo '<ul>';
                foreach ($tables as $table) {
                    echo '<li>' . htmlspecialchars($table) . '</li>';
                }
                echo '</ul>';
            } else {
                echo '<p>⚠️ Таблиц пока нет. Запусти SQL-скрипт из database/schema.sql</p>';
            }
        }
    } catch (PDOException $e) {
        echo '<div class="status error">';
        echo '<strong>❌ Ошибка подключения к базе данных</strong>';
        echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '<p>Проверь настройки в config/database.php</p>';
        echo '</div>';
    }
    ?>
</body>
</html>