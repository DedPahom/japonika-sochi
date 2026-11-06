document.addEventListener('DOMContentLoaded', function () {
    const dialog = document.getElementById('mobile-category-dialog');
    const openButton = document.querySelector('[data-mobile-menu-open]');
    const closeButton = document.querySelector('[data-mobile-menu-close]');
    if (!dialog || !openButton) return;

    const setExpanded = (value) => openButton.setAttribute('aria-expanded', value ? 'true' : 'false');

    openButton.addEventListener('click', function () {
        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }
        setExpanded(true);
    });

    closeButton?.addEventListener('click', function () {
        if (typeof dialog.close === 'function') dialog.close();
        else dialog.removeAttribute('open');
        setExpanded(false);
    });

    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) {
            if (typeof dialog.close === 'function') dialog.close();
            else dialog.removeAttribute('open');
            setExpanded(false);
        }
    });

    dialog.addEventListener('close', function () { setExpanded(false); });
});
