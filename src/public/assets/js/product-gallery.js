(() => {
    const gallery = document.querySelector('[data-product-gallery]');
    if (!gallery) return;

    const mainImage = gallery.querySelector('[data-gallery-main]');
    const openButton = gallery.querySelector('[data-gallery-open]');
    const thumbs = Array.from(gallery.querySelectorAll('[data-gallery-thumb]'));
    const thumbRail = gallery.querySelector('[data-gallery-thumbs]');
    const counter = gallery.querySelector('[data-gallery-counter]');
    const dialog = gallery.querySelector('[data-gallery-dialog]');
    const lightboxImage = gallery.querySelector('[data-lightbox-image]');
    const lightboxCounter = gallery.querySelector('[data-lightbox-counter]');
    if (!mainImage || !openButton) return;

    const images = thumbs.length
        ? thumbs.map((button) => ({
            full: button.dataset.full || button.querySelector('img')?.dataset.full || '',
            thumb: button.querySelector('img')?.getAttribute('src') || '',
            alt: button.dataset.alt || mainImage.alt
        }))
        : [{full: mainImage.getAttribute('src'), thumb: mainImage.getAttribute('src'), alt: mainImage.alt}];

    // The full-size paths are output as data attributes in the thumbnail buttons.
    let active = 0;
    const setActive = (next, focusThumb = false) => {
        if (!images.length) return;
        active = (next + images.length) % images.length;
        const image = images[active];
        mainImage.src = image.full;
        mainImage.alt = image.alt;
        if (lightboxImage) {
            lightboxImage.src = image.full;
            lightboxImage.alt = image.alt;
        }
        if (counter) counter.textContent = `${active + 1} / ${images.length}`;
        if (lightboxCounter) lightboxCounter.textContent = `${active + 1} / ${images.length}`;
        thumbs.forEach((button, index) => {
            const selected = index === active;
            button.classList.toggle('is-active', selected);
            button.setAttribute('aria-current', selected ? 'true' : 'false');
            if (selected) button.scrollIntoView({behavior: 'smooth', block: 'nearest', inline: 'center'});
        });
        if (focusThumb && thumbs[active]) thumbs[active].focus();
    };

    thumbs.forEach((button, index) => {
        const thumb = button.querySelector('img');
        images[index] = {
            full: button.dataset.full || button.dataset.src || button.getAttribute('data-image') || '',
            thumb: thumb?.src || '',
            alt: button.dataset.alt || mainImage.alt
        };
        // Keep a safe fallback if a thumbnail does not have its full-image URL.
        if (!images[index].full) images[index].full = mainImage.getAttribute('src');
        button.addEventListener('click', () => setActive(index));
    });

    gallery.querySelector('[data-gallery-prev]')?.addEventListener('click', () => setActive(active - 1));
    gallery.querySelector('[data-gallery-next]')?.addEventListener('click', () => setActive(active + 1));
    gallery.querySelector('[data-thumbs-prev]')?.addEventListener('click', () => thumbRail?.scrollBy({left: -220, behavior: 'smooth'}));
    gallery.querySelector('[data-thumbs-next]')?.addEventListener('click', () => thumbRail?.scrollBy({left: 220, behavior: 'smooth'}));

    openButton.addEventListener('click', () => {
        if (!dialog || typeof dialog.showModal !== 'function') {
            window.open(images[active].full, '_blank', 'noopener');
            return;
        }
        setActive(active);
        dialog.showModal();
    });

    gallery.querySelector('[data-lightbox-close]')?.addEventListener('click', () => dialog?.close());
    gallery.querySelector('[data-lightbox-prev]')?.addEventListener('click', () => setActive(active - 1));
    gallery.querySelector('[data-lightbox-next]')?.addEventListener('click', () => setActive(active + 1));

    dialog?.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });
    dialog?.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowLeft') { event.preventDefault(); setActive(active - 1); }
        if (event.key === 'ArrowRight') { event.preventDefault(); setActive(active + 1); }
    });
})();
