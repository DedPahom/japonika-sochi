<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
$pageTitle = 'О компании — ' . SITE_NAME;
require __DIR__ . '/templates/header.php';
?>
<section class="about-page" aria-labelledby="about-title">
    <header class="about-hero">
        <span class="about-eyebrow">Японика · Сочи</span>
        <h1 id="about-title">О компании</h1>
        <p class="about-hero__lead">Мы рады приветствовать вас в магазине товаров из Японии и Южной Кореи!</p>
        <p>Безопасность, эффективность и экономичность — принципы, на которые мы опираемся при создании ассортимента. Здесь вы найдёте всё необходимое для ухода за собой и за домом.</p>
    </header>

    <section class="about-delivery" aria-labelledby="delivery-title">
        <div class="about-delivery__icon" aria-hidden="true">↗</div>
        <div>
            <h2 id="delivery-title">Доставка</h2>
            <p>Доставляем товары по Сочи сами, а заказы по России и странам СНГ отправляем транспортными компаниями. От 5000 доставка включена.</p>
        </div>
    </section>

    <section class="about-shops" aria-labelledby="shops-title">
        <div class="about-section-heading">
            <span class="about-eyebrow">Будем рады вас видеть</span>
            <h2 id="shops-title">Наши магазины в Сочи</h2>
            <p>Выберите удобный магазин — ниже указаны адрес, режим работы и карта проезда.</p>
        </div>

        <div class="about-shop-grid">
            <article class="about-shop">
                <div class="about-shop__content">
                    <span class="about-shop__number">01</span>
                    <h3>Магазин на Воровского</h3>
                    <address>г. Сочи, ул. Воровского, 6</address>
                    <p class="about-shop__note">Вход со стороны пересечения ул. Парковой и ул. Воровского, 1 этаж.</p>
                    <p class="about-shop__hours"><span aria-hidden="true">◷</span><strong>Ежедневно</strong> 10:00–21:00</p>
                    <a class="about-shop__map-link" href="https://yandex.ru/maps/239/sochi/?ll=39.720300%2C43.586414&amp;mode=poi&amp;poi%5Bpoint%5D=39.720149%2C43.586559&amp;poi%5Buri%5D=ymapsbm1%3A%2F%2Forg%3Foid%3D145004785466&amp;z=19.71" target="_blank" rel="noopener noreferrer">Открыть в Яндекс Картах <span aria-hidden="true">↗</span></a>
                </div>
                <div class="about-shop__map-wrap">
                    <iframe class="about-shop__map" src="https://yandex.ru/map-widget/v1/?ll=39.720149%2C43.586559&amp;z=18&amp;oid=145004785466" title="Карта: магазин Японика на улице Воровского, 6, Сочи" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                </div>
            </article>

            <article class="about-shop">
                <div class="about-shop__content">
                    <span class="about-shop__number">02</span>
                    <h3>Магазин в Дагомысе</h3>
                    <address>г. Сочи, Батумское шоссе, 41, корпус 1</address>
                    <p class="about-shop__note">Павильон расположен рядом с магазином «Магнит».</p>
                    <p class="about-shop__hours"><span aria-hidden="true">◷</span><strong>Ежедневно</strong> 10:00–20:00</p>
                    <p class="about-shop__break">Перерыв 13:00–14:00</p>
                    <a class="about-shop__map-link" href="https://yandex.ru/maps/239/sochi/?display-text=%D0%AF%D0%BF%D0%BE%D0%BD%D0%B8%D0%BA%D0%B0&amp;ll=39.661630%2C43.660993&amp;mode=search&amp;poi%5Bpoint%5D=39.661686%2C43.661073&amp;poi%5Buri%5D=ymapsbm1%3A%2F%2Forg%3Foid%3D192308810008&amp;sll=39.661630%2C43.660993&amp;z=20.96" target="_blank" rel="noopener noreferrer">Открыть в Яндекс Картах <span aria-hidden="true">↗</span></a>
                </div>
                <div class="about-shop__map-wrap">
                    <iframe class="about-shop__map" src="https://yandex.ru/map-widget/v1/?ll=39.661686%2C43.661073&amp;z=18&amp;oid=192308810008" title="Карта: магазин Японика на Батумском шоссе, 41 корпус 1, Дагомыс" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                </div>
            </article>
        </div>
    </section>
</section>
<?php require __DIR__ . '/templates/footer.php'; ?>
