/**
 * Product page interactivity: swatches switch photos and the price without a
 * request, and the URL keeps the selection so a shared link opens on it (§11.4).
 *
 * The markup is complete without this file: every swatch is a real link that the
 * server renders on its own.
 */
(function () {
    'use strict';

    var root = document.querySelector('[data-sk-product]');
    var payload = document.getElementById('skf-product-data');
    if (!root || !payload) {
        return;
    }

    var data;
    try {
        data = JSON.parse(payload.textContent);
    } catch (error) {
        return;
    }

    var selection = Object.assign({}, data.selection || {});
    var hero = root.querySelector('[data-sk-hero]');
    var priceEl = root.querySelector('[data-sk-price]');
    var stockEl = root.querySelector('[data-sk-stock]');
    var thumbsList = root.querySelector('[data-sk-thumbs]');

    function currentVariant() {
        return (data.variants || []).find(function (variant) {
            return Object.keys(selection).every(function (option) {
                return variant.values.indexOf(selection[option]) !== -1;
            });
        });
    }

    function photosFor() {
        var photos = [];
        Object.keys(selection).forEach(function (option) {
            var slug = selection[option];
            (data.photos[slug] || []).forEach(function (photo) {
                photos.push(photo);
            });
        });

        // The product's own photos follow the colour's, as the server rendered them.
        (data.generalPhotos || []).forEach(function (photo) {
            var seen = photos.some(function (existing) {
                return existing.src === photo.src;
            });
            if (!seen) {
                photos.push(photo);
            }
        });

        return photos;
    }

    function showPhoto(photo) {
        if (!hero || !photo) {
            return;
        }
        hero.src = photo.src;
        if (photo.srcset) {
            hero.setAttribute('srcset', photo.srcset);
        } else {
            hero.removeAttribute('srcset');
        }
    }

    function rebuildThumbs(photos) {
        if (!thumbsList) {
            return;
        }

        thumbsList.innerHTML = '';
        photos.forEach(function (photo, index) {
            var item = document.createElement('li');
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'skf-thumb' + (index === 0 ? ' is-active' : '');
            button.setAttribute('data-sk-thumb', '');
            button.dataset.src = photo.src;
            button.dataset.srcset = photo.srcset || '';
            button.setAttribute('aria-label', 'Photo ' + (index + 1));

            var image = document.createElement('img');
            image.src = photo.src;
            image.alt = '';
            image.loading = 'lazy';

            button.appendChild(image);
            item.appendChild(button);
            thumbsList.appendChild(item);
        });
    }

    function updateUrl() {
        var url = new URL(window.location.href);
        Object.keys(selection).forEach(function (option) {
            url.searchParams.set(option, selection[option]);
        });
        window.history.replaceState({}, '', url.toString());
    }

    function syncLinks() {
        root.querySelectorAll('[data-sk-swatch]').forEach(function (link) {
            var next = new URL(window.location.href);
            next.searchParams.set(link.dataset.option, link.dataset.value);
            link.href = next.toString();
        });
    }

    function render() {
        var variant = currentVariant();
        var photos = photosFor();

        if (priceEl) {
            priceEl.textContent = variant ? variant.price : data.labels.ask;
        }

        if (stockEl) {
            stockEl.textContent = variant ? variant.stock : data.labels.outOfStock;
            stockEl.classList.toggle('is-out', !variant || !variant.in_stock);
        }

        root.querySelectorAll('[data-sk-option]').forEach(function (group) {
            var option = group.getAttribute('data-sk-option');
            var label = group.querySelector('[data-sk-option-label]');
            if (!label) {
                return;
            }
            var active = group.querySelector('[data-sk-swatch].is-selected .skf-swatch__name');
            label.textContent = active ? active.textContent : (selection[option] || '');
        });

        showPhoto(photos[0]);
        rebuildThumbs(photos);
    }

    root.addEventListener('click', function (event) {
        var swatch = event.target.closest ? event.target.closest('[data-sk-swatch]') : null;
        if (!swatch) {
            return;
        }

        event.preventDefault();
        selection[swatch.dataset.option] = swatch.dataset.value;

        swatch.closest('.skf-option__values').querySelectorAll('[data-sk-swatch]').forEach(function (link) {
            var active = link === swatch;
            link.classList.toggle('is-selected', active);
            link.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        updateUrl();
        syncLinks();
        render();
    });

    root.addEventListener('click', function (event) {
        var thumb = event.target.closest ? event.target.closest('[data-sk-thumb]') : null;
        if (!thumb) {
            return;
        }

        showPhoto({ src: thumb.dataset.src, srcset: thumb.dataset.srcset });

        root.querySelectorAll('[data-sk-thumb]').forEach(function (button) {
            button.classList.toggle('is-active', button === thumb);
        });
    });

    syncLinks();
})();
