<?php
declare(strict_types=1);

// Настройки сайта
const SITE_NAME = 'Японика Сочи';
const SITE_URL = 'https://japonika-sochi.ru';

// Пути
const ROOT_PATH = __DIR__ . '/..';
const PUBLIC_PATH = ROOT_PATH . '/public';
const UPLOAD_PATH = PUBLIC_PATH . '/uploads';

// Пагинация
const PRODUCTS_PER_PAGE = 20;

// Включаем отображение ошибок (для разработки)
ini_set('display_errors', '1');
error_reporting(E_ALL);