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

    <section class="about-values" aria-label="Наши принципы">
        <article class="about-value"><span aria-hidden="true">✦</span><h2>Безопасность</h2><p>Внимательно подходим к выбору товаров для ежедневного ухода и дома.</p></article>
        <article class="about-value"><span aria-hidden="true">◎</span><h2>Эффективность</h2><p>Подбираем ассортимент, который помогает решать повседневные задачи.</p></article>
        <article class="about-value"><span aria-hidden="true">♡</span><h2>Экономичность</h2><p>Стараемся, чтобы полезные покупки были разумными и удобными.</p></article>
    </section>

    <section class="about-delivery" aria-labelledby="delivery-title">
        <div class="about-delivery__icon" aria-hidden="true">↗</div>
        <div>
            <h2 id="delivery-title">Доставка</h2>
            <p>Доставляем товары по Сочи, а заказы по России и странам СНГ отправляем транспортными компаниями.</p>
        </div>
    </section>

    <section class="about-contact" aria-labelledby="contact-title">
        <div>
            <h2 id="contact-title">Связаться с нами</h2>
            <p>Напишите нам на электронную почту:</p>
            <a href="mailto:shop@mail.japonika-sochi.ru">shop@mail.japonika-sochi.ru</a>
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
                    <p class="about-shop__note">Тел. для связи : +7 (967) 304-38-38</p>
                    <p class="about-shop__hours"><span aria-hidden="true">◷</span><strong>Ежедневно</strong> 10:00–21:00</p>
                    <p class="about-shop__break">Без перерыва</p>
                    <a class="about-shop__map-link" href="https://yandex.ru/maps/-/CXv9yBz0" target="_blank" rel="noopener noreferrer">Открыть в Яндекс Картах <span aria-hidden="true">↗</span></a>
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
                    <p class="about-shop__note">Тел. для связи : +7 (988) 287-02-11</p>
                    <p class="about-shop__hours"><span aria-hidden="true">◷</span><strong>Ежедневно</strong> 10:00–20:00</p>
                    <p class="about-shop__break">Перерыв 13:00–14:00</p>
                    <a class="about-shop__map-link" href="https://yandex.ru/maps/-/CXv9uHkU" target="_blank" rel="noopener noreferrer">Открыть в Яндекс Картах <span aria-hidden="true">↗</span></a>
                </div>
                <div class="about-shop__map-wrap">
                    <iframe class="about-shop__map" src="https://yandex.ru/map-widget/v1/?ll=39.661686%2C43.661073&amp;z=18&amp;oid=192308810008" title="Карта: магазин Японика на Батумском шоссе, 41 корпус 1, Дагомыс" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>
                </div>
            </article>
        </div>
    </section>
</section>
<?php require __DIR__ . '/templates/footer.php'; ?>
