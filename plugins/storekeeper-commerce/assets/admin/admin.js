/**
 * Store Keeper back office (Phase 2, in progress).
 *
 * React via @wordpress/element and @wordpress/api-fetch — no build step yet.
 * Every read and write goes through the REST API; nothing here touches the
 * database, and gateway secrets are only ever sent towards the server.
 */
(function (wp) {
    'use strict';

    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var apiFetch = wp.apiFetch;

    var config = window.storekeeperAdmin || {};
    var root = (config.root || '/wp-json/storekeeper/v1').replace(/\/$/, '');

    function money(kobo) {
        if (kobo === null || kobo === undefined) {
            return '—';
        }

        var amount = Number(kobo) / 100;
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

    function idempotencyKey() {
        if (window.crypto && window.crypto.randomUUID) {
            return window.crypto.randomUUID();
        }
        return 'sk-' + Date.now() + '-' + Math.random().toString(16).slice(2);
    }

    function stat(label, value, tone) {
        return el('div', { className: 'sk-card sk-stat' + (tone ? ' sk-' + tone : '') },
            el('span', { className: 'sk-stat-value' }, value),
            el('span', { className: 'sk-stat-label' }, label)
        );
    }

    function field(label, input) {
        return el('p', { className: 'sk-field' },
            el('label', null, label),
            input
        );
    }

    function Products(props) {
        var nameState = useState('');
        var name = nameState[0];
        var setName = nameState[1];

        var priceState = useState('');
        var price = priceState[0];
        var setPrice = priceState[1];

        var stockState = useState('');
        var stock = stockState[0];
        var setStock = stockState[1];

        var locationState = useState('');
        var location = locationState[0];
        var setLocation = locationState[1];

        var descState = useState('');
        var description = descState[0];
        var setDescription = descState[1];

        var busyState = useState(false);
        var busy = busyState[0];
        var setBusy = busyState[1];

        var messageState = useState(null);
        var message = messageState[0];
        var setMessage = messageState[1];

        useEffect(function () {
            if (!location && props.locations.length > 0) {
                setLocation(String(props.locations[0].id));
            }
        }, [props.locations]);

        function submit(event) {
            event.preventDefault();
            setBusy(true);
            setMessage(null);

            apiFetch({
                url: root + '/products',
                method: 'POST',
                headers: { 'Idempotency-Key': idempotencyKey() },
                data: {
                    name: name,
                    description: description,
                    price_kobo: Math.round(Number(price || 0) * 100),
                    stock: Number(stock || 0),
                    location_id: location ? Number(location) : null,
                    status: 'active'
                }
            }).then(function () {
                setMessage({ tone: 'good', text: 'Saved. The product is live in your catalogue.' });
                setName('');
                setPrice('');
                setStock('');
                setDescription('');
                props.onCreated();
            }).catch(function (failure) {
                setMessage({ tone: 'bad', text: failure.message || 'Could not save the product.' });
            }).then(function () {
                setBusy(false);
            });
        }

        return el('div', null,
            el('form', { className: 'sk-panel sk-form', onSubmit: submit },
                el('h2', null, 'Add a product'),
                el('div', { className: 'sk-form-grid' },
                    field('Product name', el('input', {
                        type: 'text',
                        required: true,
                        value: name,
                        placeholder: 'e.g. Gold hoop earrings',
                        onChange: function (event) { setName(event.target.value); }
                    })),
                    field('Retail price (₦)', el('input', {
                        type: 'number',
                        min: '0',
                        step: '50',
                        value: price,
                        placeholder: '8500',
                        onChange: function (event) { setPrice(event.target.value); }
                    })),
                    field('Opening stock (pieces)', el('input', {
                        type: 'number',
                        min: '0',
                        value: stock,
                        placeholder: '60',
                        onChange: function (event) { setStock(event.target.value); }
                    })),
                    field('Stock location', el('select', {
                        value: location,
                        onChange: function (event) { setLocation(event.target.value); }
                    }, props.locations.map(function (row) {
                        return el('option', { key: row.id, value: row.id }, row.name);
                    })))
                ),
                field('Description', el('textarea', {
                    rows: 2,
                    value: description,
                    placeholder: 'What the customer needs to know',
                    onChange: function (event) { setDescription(event.target.value); }
                })),
                el('p', { className: 'sk-actions' },
                    el('button', { type: 'submit', className: 'button button-primary', disabled: busy },
                        busy ? 'Saving…' : 'Save product')
                ),
                message ? el('div', { className: 'notice notice-' + (message.tone === 'good' ? 'success' : 'error') + ' inline' },
                    el('p', null, message.text)) : null
            ),

            el('h2', null, 'Catalogue'),
            props.rows.length === 0
                ? el('p', { className: 'sk-empty' }, 'No products yet. Add your first one above.')
                : el('table', { className: 'widefat striped sk-table' },
                    el('thead', null,
                        el('tr', null,
                            el('th', null, 'Product'),
                            el('th', null, 'Price'),
                            el('th', null, 'Variants'),
                            el('th', null, 'Available'),
                            el('th', null, 'Status')
                        )
                    ),
                    el('tbody', null, props.rows.map(function (row) {
                        return el('tr', { key: row.uuid },
                            el('td', null, el('strong', null, row.name)),
                            el('td', null, money(row.price_kobo)),
                            el('td', null, row.variant_count),
                            el('td', null, row.available + ' pieces'),
                            el('td', null, el('span', { className: 'sk-pill' }, row.status))
                        );
                    }))
                )
        );
    }

    function Gateways(props) {
        var draftState = useState({});
        var draft = draftState[0];
        var setDraft = draftState[1];

        var busyState = useState(false);
        var busy = busyState[0];
        var setBusy = busyState[1];

        var messageState = useState(null);
        var message = messageState[0];
        var setMessage = messageState[1];

        var data = props.data;

        function value(slug, key, fallback) {
            var row = draft[slug] || {};
            return row[key] !== undefined ? row[key] : fallback;
        }

        function change(slug, key, next) {
            var copy = Object.assign({}, draft);
            copy[slug] = Object.assign({}, copy[slug] || {});
            copy[slug][key] = next;
            setDraft(copy);
        }

        function save() {
            setBusy(true);
            setMessage(null);

            var payload = {};
            data.gateways.forEach(function (gateway) {
                payload[gateway.slug] = {
                    enabled: value(gateway.slug, 'enabled', gateway.enabled),
                    test_mode: value(gateway.slug, 'test_mode', gateway.test_mode),
                    public_key: value(gateway.slug, 'public_key', gateway.public_key),
                    merchant_id: value(gateway.slug, 'merchant_id', gateway.merchant_id),
                    secret_key: value(gateway.slug, 'secret_key', '')
                };
            });

            apiFetch({
                url: root + '/settings/payments',
                method: 'PUT',
                data: { gateways: payload }
            }).then(function (response) {
                setMessage({ tone: 'good', text: 'Gateway settings saved.' });
                setDraft({});
                props.onSaved(response.data);
            }).catch(function (failure) {
                setMessage({ tone: 'bad', text: failure.message || 'Could not save the gateway settings.' });
            }).then(function () {
                setBusy(false);
            });
        }

        return el('div', null,
            el('h2', null, 'Payment gateways'),
            el('p', { className: 'sk-help' },
                'Customer payments settle directly into your own gateway account. Enter the keys from your ' +
                'provider dashboard; secret keys are encrypted before they are stored and are never shown again.'),

            data.encryption.available
                ? null
                : el('div', { className: 'notice notice-error inline' }, el('p', null, data.encryption.message)),

            data.gateways.map(function (gateway) {
                return el('div', { key: gateway.slug, className: 'sk-panel sk-gateway' },
                    el('div', { className: 'sk-gateway-head' },
                        el('strong', null, gateway.label),
                        el('label', { className: 'sk-toggle' },
                            el('input', {
                                type: 'checkbox',
                                checked: value(gateway.slug, 'enabled', gateway.enabled),
                                onChange: function (event) { change(gateway.slug, 'enabled', event.target.checked); }
                            }),
                            'Enabled'
                        ),
                        el('label', { className: 'sk-toggle' },
                            el('input', {
                                type: 'checkbox',
                                checked: value(gateway.slug, 'test_mode', gateway.test_mode),
                                onChange: function (event) { change(gateway.slug, 'test_mode', event.target.checked); }
                            }),
                            'Test mode'
                        )
                    ),
                    el('div', { className: 'sk-form-grid' },
                        field('Public key', el('input', {
                            type: 'text',
                            value: value(gateway.slug, 'public_key', gateway.public_key),
                            placeholder: 'pk_…',
                            onChange: function (event) { change(gateway.slug, 'public_key', event.target.value); }
                        })),
                        field('Secret key', el('input', {
                            type: 'password',
                            autoComplete: 'new-password',
                            value: value(gateway.slug, 'secret_key', ''),
                            placeholder: gateway.has_secret_key ? 'Saved (' + gateway.secret_hint + ') — leave blank to keep' : 'sk_…',
                            onChange: function (event) { change(gateway.slug, 'secret_key', event.target.value); }
                        })),
                        gateway.slug === 'opay'
                            ? field('Merchant ID', el('input', {
                                type: 'text',
                                value: value(gateway.slug, 'merchant_id', gateway.merchant_id),
                                placeholder: 'OPay merchant id',
                                onChange: function (event) { change(gateway.slug, 'merchant_id', event.target.value); }
                            }))
                            : null
                    )
                );
            }),

            el('p', { className: 'sk-actions' },
                el('button', { type: 'button', className: 'button button-primary', disabled: busy, onClick: save },
                    busy ? 'Saving…' : 'Save gateways')
            ),
            message ? el('div', { className: 'notice notice-' + (message.tone === 'good' ? 'success' : 'error') + ' inline' },
                el('p', null, message.text)) : null
        );
    }

    function App() {
        var overviewState = useState(null);
        var overview = overviewState[0];
        var setOverview = overviewState[1];

        var productsState = useState([]);
        var products = productsState[0];
        var setProducts = productsState[1];

        var locationsState = useState([]);
        var locations = locationsState[0];
        var setLocations = locationsState[1];

        var gatewaysState = useState(null);
        var gateways = gatewaysState[0];
        var setGateways = gatewaysState[1];

        var errorState = useState(null);
        var error = errorState[0];
        var setError = errorState[1];

        var tabState = useState('dashboard');
        var tab = tabState[0];
        var setTab = tabState[1];

        function loadProducts() {
            return apiFetch({ url: root + '/products?limit=50' }).then(function (response) {
                setProducts(response.data || []);
            });
        }

        function loadOverview() {
            return apiFetch({ url: root + '/overview' }).then(function (response) {
                setOverview(response.data);
            });
        }

        useEffect(function () {
            loadOverview().catch(function (failure) {
                setError(failure.message || 'Could not load the overview.');
            });
            loadProducts().catch(function () {});
            apiFetch({ url: root + '/locations' }).then(function (response) {
                setLocations(response.data || []);
            }).catch(function () {});
            apiFetch({ url: root + '/settings/payments' }).then(function (response) {
                setGateways(response.data);
            }).catch(function () {});
        }, []);

        if (error) {
            return el('div', { className: 'notice notice-error' }, el('p', null, error));
        }

        if (!overview) {
            return el('p', { className: 'storekeeper-loading' }, 'Loading your store…');
        }

        return el('div', null,
            el('div', { className: 'sk-tabs' },
                [['dashboard', 'Dashboard'], ['products', 'Products'], ['settings', 'Payment gateways']].map(function (pair) {
                    return el('button', {
                        key: pair[0],
                        type: 'button',
                        className: 'sk-tab' + (tab === pair[0] ? ' is-active' : ''),
                        onClick: function () { setTab(pair[0]); }
                    }, pair[1]);
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
                    overview.low_stock.length === 0
                        ? el('p', { className: 'sk-empty' }, 'Nothing is low on stock. Good.')
                        : el('table', { className: 'widefat striped sk-table' },
                            el('thead', null, el('tr', null,
                                el('th', null, 'Product'), el('th', null, 'Variant'),
                                el('th', null, 'SKU'), el('th', null, 'Available'))),
                            el('tbody', null, overview.low_stock.map(function (row) {
                                return el('tr', { key: row.variant_id },
                                    el('td', null, row.product_name),
                                    el('td', null, row.title),
                                    el('td', null, el('code', null, row.sku)),
                                    el('td', null, el('span', {
                                        className: row.available <= 0 ? 'sk-pill sk-pill-danger' : 'sk-pill sk-pill-warning'
                                    }, row.available + ' / ' + row.threshold)));
                            }))
                        )
                )
                : null,

            tab === 'products'
                ? el(Products, {
                    rows: products,
                    locations: locations,
                    onCreated: function () { loadProducts(); loadOverview(); }
                })
                : null,

            tab === 'settings'
                ? (gateways
                    ? el(Gateways, { data: gateways, onSaved: setGateways })
                    : el('p', { className: 'storekeeper-loading' }, 'Loading gateway settings…'))
                : null,

            el('p', { className: 'sk-footnote' },
                'Store Keeper · catalogue, stock, orders and gateways in one place. ' +
                'Orders, customers and the storefront arrive in the next phases; this screen reads live data.')
        );
    }

    var container = document.getElementById('storekeeper-admin');
    if (container && wp.element) {
        wp.element.render(el(App), container);
    }
})(window.wp);
