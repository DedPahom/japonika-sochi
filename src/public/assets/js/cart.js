/* Add to assets/js/main.js, or load as a separate deferred script. */
document.addEventListener('click', async function (event) {
    const button = event.target.closest('[data-cart-add]');
    if (!button) return;

    const productId = button.dataset.cartAdd;
    const card = button.closest('.product-card');
    const quantityInput = card ? card.querySelector('.quick-buy__quantity') : null;
    const quantity = Math.max(1, Math.min(999, parseInt(quantityInput?.value || '1', 10) || 1));
    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = 'Добавляем…';

    try {
        const body = new URLSearchParams({
            action: 'add',
            product_id: productId,
            quantity: String(quantity),
            csrf: button.dataset.cartCsrf || ''
        });
        const response = await fetch('/cart-action.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'Accept': 'application/json'},
            body,
            credentials: 'same-origin'
        });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.message || 'Не удалось добавить товар.');

        document.querySelectorAll('#header-cart-count, [data-cart-count]').forEach(el => el.textContent = result.count);
        document.querySelectorAll('#header-cart-total').forEach(el => el.textContent = result.formatted_total);
        button.textContent = 'Добавлено ✓';
        window.setTimeout(() => { button.textContent = originalText; button.disabled = false; }, 1300);
    } catch (error) {
        alert(error.message || 'Не удалось добавить товар в корзину.');
        button.textContent = originalText;
        button.disabled = false;
    }
});
