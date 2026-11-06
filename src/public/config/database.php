<?php
/**
 * Подключение к базе данных
 */

declare(strict_types=1);

const DB_HOST = 'localhost';
const DB_NAME = 'u2956522_japonika_db';
const DB_USER = 'u2956522_japonika_admin';
const DB_PASS = 'xM6dR8aK3xsW6yJ1'; 

const DB_CHARSET = 'utf8mb4';

/**
 * Создаёт подключение к базе данных
 * 
 * @return PDO
 * @throws PDOException
 */
function getDBConnection(): PDO
{
    static $pdo = null;
    
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_NAME,
            DB_CHARSET
        );
        
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            throw new PDOException('Ошибка подключения к базе данных: ' . $e->getMessage());
        }
    }
    
    return $pdo;
}

/**
 * Проверяет подключение к базе
 * 
 * @return bool
 */
function testDBConnection(): bool
{
    try {
        $db = getDBConnection();
        $db->query('SELECT 1');
        return true;
    } catch (PDOException $e) {
        return false;
    }
}
