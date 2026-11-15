/**
 * Store Keeper back office shell (Phase 2, in progress).
 *
 * React via @wordpress/element and @wordpress/api-fetch — no build step yet, so
 * the pilot can use the screens before the @wordpress/scripts pipeline lands.
 * Every read goes through the REST API; nothing here touches the database.
 */
(function (wp) {
    'use strict';

    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var apiFetch = wp.apiFetch;

    var config = window.storekeeperAdmin || {};

    function money(amountKobo) {
        var amount = Number(amountKobo || 0) / 100;
        try {
            return new Intl.NumberFormat('en-NG', {
                style: 'currency',
                currency: config.currency || 'NGN',
                maximumFractionDigits: 0
            }).format(amount);
        } catch (error) {
            return '₦' + amount.toLocaleString();
        }
    }

    function stat(label, value, tone) {
        return el('div', { className: 'sk-card sk-stat' + (tone ? ' sk-' + tone : '') },
            el('span', { className: 'sk-stat-value' }, value),
            el('span', { className: 'sk-stat-label' }, label)
        );
    }

    function LowStock(props) {
        if (!props.rows || props.rows.length === 0) {
            return el('p', { className: 'sk-empty' }, 'Nothing is low on stock. Good.');
        }

        return el('table', { className: 'widefat striped sk-table' },
            el('thead', null,
                el('tr', null,
                    el('th', null, 'Product'),
                    el('th', null, 'Variant'),
                    el('th', null, 'SKU'),
                    el('th', null, 'Available')
                )
            ),
            el('tbody', null, props.rows.map(function (row) {
                return el('tr', { key: row.variant_id },
                    el('td', null, row.product_name),
                    el('td', null, row.title),
                    el('td', null, el('code', null, row.sku)),
                    el('td', null,
                        el('span', { className: row.available <= 0 ? 'sk-pill sk-pill-danger' : 'sk-pill sk-pill-warning' },
                            row.available + ' / ' + row.threshold
                        )
                    )
                );
            }))
        );
    }

    function Products(props) {
        if (!props.rows || props.rows.length === 0) {
            return el('p', { className: 'sk-empty' }, 'No products yet. Add your first product from the Products screen.');
        }

        return el('table', { className: 'widefat striped sk-table' },
            el('thead', null,
                el('tr', null,
                    el('th', null, 'Product'),
                    el('th', null, 'Variants'),
                    el('th', null, 'Available'),
                    el('th', null, 'Status')
                )
            ),
            el('tbody', null, props.rows.map(function (row) {
                return el('tr', { key: row.uuid },
                    el('td', null, el('strong', null, row.name)),
                    el('td', null, row.variant_count),
                    el('td', null, row.available + ' pieces'),
                    el('td', null, el('span', { className: 'sk-pill' }, row.status))
                );
            }))
        );
    }

    function App() {
        var overviewState = useState(null);
        var overview = overviewState[0];
        var setOverview = overviewState[1];

        var productState = useState([]);
        var products = productState[0];
        var setProducts = productState[1];

        var errorState = useState(null);
        var error = errorState[0];
        var setError = errorState[1];

        var tabState = useState('dashboard');
        var tab = tabState[0];
        var setTab = tabState[1];

        useEffect(function () {
            apiFetch({ path: '/storekeeper/v1/overview' })
                .then(function (response) { setOverview(response.data); })
                .catch(function (failure) { setError(failure.message || 'Could not load the overview.'); });

            apiFetch({ path: '/storekeeper/v1/products?limit=50' })
                .then(function (response) { setProducts(response.data || []); })
                .catch(function (failure) { setError(failure.message || 'Could not load products.'); });
        }, []);

        if (error) {
            return el('div', { className: 'notice notice-error' }, el('p', null, error));
        }

        if (!overview) {
            return el('p', { className: 'storekeeper-loading' }, 'Loading your store…');
        }

        return el('div', null,
            el('div', { className: 'sk-tabs' },
                ['dashboard', 'products'].map(function (name) {
                    return el('button', {
                        key: name,
                        type: 'button',
                        className: 'sk-tab' + (tab === name ? ' is-active' : ''),
                        onClick: function () { setTab(name); }
                    }, name.charAt(0).toUpperCase() + name.slice(1));
                })
            ),

            tab === 'dashboard'
                ? el('div', null,
                    el('div', { className: 'sk-grid' },
                        stat('Products', overview.products),
                        stat('Variants', overview.variants),
                        stat('Units available', overview.stock_units.toLocaleString()),
                        stat('Orders today', overview.orders_today),
                        stat('Awaiting payment', overview.awaiting_payment, overview.awaiting_payment > 0 ? 'warning' : null),
                        stat('Open alerts', overview.open_alerts, overview.open_alerts > 0 ? 'danger' : null)
                    ),
                    el('h2', null, 'Low stock'),
                    el(LowStock, { rows: overview.low_stock })
                )
                : el('div', null,
                    el('h2', null, 'Products'),
                    el(Products, { rows: products })
                ),

            el('p', { className: 'sk-footnote' },
                'Store Keeper · catalogue, stock and orders in one ledger. ' +
                'Screens are added in Phase 2; this shell reads live data from the REST API.'
            )
        );
    }

    var container = document.getElementById('storekeeper-admin');
    if (container && wp.element) {
        wp.element.render(el(App), container);
    }
})(window.wp);
