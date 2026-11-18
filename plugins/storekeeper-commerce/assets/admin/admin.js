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

    function Orders(props) {
        var customerState = useState('');
        var customer = customerState[0];
        var setCustomer = customerState[1];

        var phoneState = useState('');
        var phone = phoneState[0];
        var setPhone = phoneState[1];

        var variantState = useState('');
        var variantId = variantState[0];
        var setVariantId = variantState[1];

        var qtyState = useState('1');
        var qty = qtyState[0];
        var setQty = qtyState[1];

        var methodState = useState('cash');
        var method = methodState[0];
        var setMethod = methodState[1];

        var gatewayState = useState('');
        var gateway = gatewayState[0];
        var setGateway = gatewayState[1];

        var busyState = useState(false);
        var busy = busyState[0];
        var setBusy = busyState[1];

        var messageState = useState(null);
        var message = messageState[0];
        var setMessage = messageState[1];

        var gatewayData = (props.gateways || {}).gateways || [];
        var availableGateways = gatewayData.filter(function (row) {
            return row.enabled && row.has_secret_key;
        });

        useEffect(function () {
            if (!variantId && props.variants.length > 0) {
                setVariantId(String(props.variants[0].variant_id));
            }
        }, [props.variants]);

        useEffect(function () {
            if (!gateway && availableGateways.length > 0) {
                setGateway(availableGateways[0].slug);
            }
        }, [props.gateways]);

        function createOrder(event) {
            event.preventDefault();
            setBusy(true);
            setMessage(null);

            apiFetch({
                url: root + '/orders',
                method: 'POST',
                headers: { 'Idempotency-Key': idempotencyKey() },
                data: {
                    channel: 'counter',
                    payment_method: method,
                    customer: { name: customer || 'Walk-in customer', phone: phone },
                    lines: [{ variant_id: Number(variantId), qty: Number(qty || 1) }],
                    handed_over_now: method === 'cash' || method === 'pos_terminal'
                }
            }).then(function (response) {
                setMessage({
                    tone: 'good',
                    text: 'Order ' + response.data.order.number + ' created for ' +
                        money(response.data.order.total_kobo) + '.'
                });
                setCustomer('');
                setPhone('');
                setQty('1');
                props.onChanged();
            }).catch(function (failure) {
                setMessage({ tone: 'bad', text: failure.message || 'Could not create the order.' });
            }).then(function () {
                setBusy(false);
            });
        }

        function markPaid(row) {
            apiFetch({
                url: root + '/orders/' + row.uuid + '/payments',
                method: 'POST',
                data: { method: 'bank_transfer' }
            }).then(function () {
                props.onChanged();
            }).catch(function (failure) {
                setMessage({ tone: 'bad', text: failure.message || 'Could not record the payment.' });
            });
        }

        function paymentLink(row) {
            apiFetch({
                url: root + '/orders/' + row.uuid + '/payment-link',
                method: 'POST',
                data: { gateway: gateway || (availableGateways[0] || {}).slug }
            }).then(function (response) {
                setMessage({
                    tone: 'good',
                    text: 'Payment link for ' + row.number + ' (' + money(response.data.amount_kobo) + '): ',
                    link: response.data.redirect_url
                });
            }).catch(function (failure) {
                setMessage({ tone: 'bad', text: failure.message || 'Could not create a payment link.' });
            });
        }

        function cancel(row) {
            apiFetch({
                url: root + '/orders/' + row.uuid + '/cancel',
                method: 'POST',
                data: { reason: 'Cancelled from the back office' }
            }).then(function () {
                props.onChanged();
            }).catch(function (failure) {
                setMessage({ tone: 'bad', text: failure.message || 'Could not cancel the order.' });
            });
        }

        return el('div', null,
            el('form', { className: 'sk-panel sk-form', onSubmit: createOrder },
                el('h2', null, 'New order'),
                el('div', { className: 'sk-form-grid' },
                    field('Customer name', el('input', {
                        type: 'text',
                        value: customer,
                        placeholder: 'Ada Obi',
                        onChange: function (event) { setCustomer(event.target.value); }
                    })),
                    field('Phone', el('input', {
                        type: 'tel',
                        value: phone,
                        placeholder: '08031234567',
                        onChange: function (event) { setPhone(event.target.value); }
                    })),
                    field('Item', el('select', {
                        value: variantId,
                        onChange: function (event) { setVariantId(event.target.value); }
                    }, props.variants.map(function (row) {
                        return el('option', { key: row.variant_id, value: row.variant_id },
                            row.product_name + ' — ' + row.title + ' (' + money(row.price_kobo) + ')');
                    }))),
                    field('Quantity', el('input', {
                        type: 'number',
                        min: '1',
                        value: qty,
                        onChange: function (event) { setQty(event.target.value); }
                    })),
                    field('Payment', el('select', {
                        value: method,
                        onChange: function (event) { setMethod(event.target.value); }
                    },
                        el('option', { value: 'cash' }, 'Cash'),
                        el('option', { value: 'bank_transfer' }, 'Bank transfer'),
                        el('option', { value: 'pos_terminal' }, 'POS terminal'),
                        el('option', { value: 'pay_on_delivery' }, 'Pay on delivery'),
                        el('option', { value: 'opay' }, 'OPay'),
                        el('option', { value: 'paystack' }, 'Paystack'),
                        el('option', { value: 'flutterwave' }, 'Flutterwave')
                    ))
                ),
                el('p', { className: 'sk-help' },
                    'Cash and POS terminal orders are handed over immediately. Other methods reserve the stock and wait for payment.'),
                el('p', { className: 'sk-actions' },
                    el('button', { type: 'submit', className: 'button button-primary', disabled: busy },
                        busy ? 'Creating…' : 'Create order')
                ),
                message ? el('div', { className: 'notice notice-' + (message.tone === 'good' ? 'success' : 'error') + ' inline' },
                    el('p', null, message.text,
                        message.link ? el('a', { href: message.link, target: '_blank', rel: 'noreferrer' }, 'Open checkout') : null)) : null
            ),

            el('h2', null, 'Orders'),
            availableGateways.length > 0
                ? el('p', { className: 'sk-help' },
                    'Send a customer a checkout link: ',
                    el('select', {
                        value: gateway,
                        onChange: function (event) { setGateway(event.target.value); }
                    }, availableGateways.map(function (row) {
                        return el('option', { key: row.slug, value: row.slug }, row.label + (row.test_mode ? ' (test mode)' : ''));
                    })))
                : el('p', { className: 'sk-help' }, 'Enable a payment gateway to send customers checkout links.'),
            props.rows.length === 0
                ? el('p', { className: 'sk-empty' }, 'No orders yet.')
                : el('table', { className: 'widefat striped sk-table' },
                    el('thead', null, el('tr', null,
                        el('th', null, 'Order'), el('th', null, 'Customer'),
                        el('th', null, 'Total'), el('th', null, 'Paid'),
                        el('th', null, 'Status'), el('th', null, 'Payment'), el('th', null, ''))),
                    el('tbody', null, props.rows.map(function (row) {
                        return el('tr', { key: row.uuid },
                            el('td', null, el('strong', null, row.number)),
                            el('td', null, row.customer_name || '—'),
                            el('td', null, money(row.total_kobo)),
                            el('td', null, money(row.paid_kobo)),
                            el('td', null, el('span', { className: 'sk-pill' }, row.status)),
                            el('td', null, el('span', {
                                className: 'sk-pill' + (row.payment_status === 'paid' ? ' sk-pill-good' : (row.payment_status.indexOf('partial') === 0 ? ' sk-pill-warning' : ''))
                            }, row.payment_status)),
                            el('td', null,
                                row.status !== 'cancelled' && row.payment_status !== 'paid'
                                    ? el('button', {
                                        type: 'button',
                                        className: 'button button-small',
                                        onClick: function () { markPaid(row); }
                                    }, 'Mark paid')
                                    : null,
                                row.status !== 'cancelled' && row.payment_status !== 'paid' && availableGateways.length > 0
                                    ? el('button', {
                                        type: 'button',
                                        className: 'button button-small',
                                        onClick: function () { paymentLink(row); }
                                    }, 'Payment link')
                                    : null,
                                row.status !== 'cancelled'
                                    ? el('button', {
                                        type: 'button',
                                        className: 'button button-small sk-danger-button',
                                        onClick: function () { cancel(row); }
                                    }, 'Cancel')
                                    : null
                            )
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

    function notice(message) {
        return message
            ? el('div', { className: 'notice notice-' + (message.tone === 'good' ? 'success' : 'error') + ' inline' },
                el('p', null, message.text))
            : null;
    }

    function Customers(props) {
        var searchState = useState('');
        var search = searchState[0];
        var setSearch = searchState[1];

        var nameState = useState('');
        var name = nameState[0];
        var setName = nameState[1];

        var phoneState = useState('');
        var phone = phoneState[0];
        var setPhone = phoneState[1];

        var businessState = useState('');
        var business = businessState[0];
        var setBusiness = businessState[1];

        var groupState = useState('');
        var groupId = groupState[0];
        var setGroupId = groupState[1];

        var messageState = useState(null);
        var message = messageState[0];
        var setMessage = messageState[1];

        function createCustomer(event) {
            event.preventDefault();
            setMessage(null);

            apiFetch({
                url: root + '/customers',
                method: 'POST',
                data: {
                    name: name,
                    phone: phone,
                    business_name: business || null,
                    group_id: groupId ? Number(groupId) : null
                }
            }).then(function (response) {
                setMessage({ tone: 'good', text: 'Saved ' + response.data.name + '.' });
                setName('');
                setPhone('');
                setBusiness('');
                props.onChanged();
            }).catch(function (failure) {
                setMessage({ tone: 'bad', text: failure.message || 'Could not save the customer.' });
            });
        }

        function decide(row, action) {
            apiFetch({
                url: root + '/customers/' + row.uuid + '/' + action,
                method: 'POST',
                data: action === 'reject' ? { reason: 'Rejected from the back office' } : {}
            }).then(function () {
                props.onChanged();
            }).catch(function (failure) {
                setMessage({ tone: 'bad', text: failure.message || 'Could not update the customer.' });
            });
        }

        function moveTo(row, nextGroupId) {
            apiFetch({
                url: root + '/customers/' + row.uuid + '/group',
                method: 'POST',
                data: { group_id: Number(nextGroupId) }
            }).then(function () {
                props.onChanged();
            }).catch(function (failure) {
                setMessage({ tone: 'bad', text: failure.message || 'Could not change the group.' });
            });
        }

        var shown = props.rows.filter(function (row) {
            if (!search) { return true; }
            var haystack = (row.name + ' ' + row.business_name + ' ' + row.phone).toLowerCase();
            return haystack.indexOf(search.toLowerCase()) !== -1;
        });

        return el('div', null,
            el('form', { className: 'sk-panel sk-form', onSubmit: createCustomer },
                el('h2', null, 'New customer'),
                el('div', { className: 'sk-form-grid' },
                    field('Name', el('input', {
                        type: 'text', value: name, required: true,
                        onChange: function (event) { setName(event.target.value); }
                    })),
                    field('Phone', el('input', {
                        type: 'tel', value: phone, required: true, placeholder: '08031234567',
                        onChange: function (event) { setPhone(event.target.value); }
                    })),
                    field('Business name', el('input', {
                        type: 'text', value: business,
                        onChange: function (event) { setBusiness(event.target.value); }
                    })),
                    field('Group', el('select', {
                        value: groupId,
                        onChange: function (event) { setGroupId(event.target.value); }
                    },
                        el('option', { value: '' }, 'Retail (default)'),
                        props.groups.map(function (row) {
                            return el('option', { key: row.id, value: row.id }, row.name);
                        })))
                ),
                el('p', { className: 'sk-actions' },
                    el('button', { type: 'submit', className: 'button button-primary' }, 'Save customer')),
                notice(message)
            ),

            el('h2', null, 'Customers'),
            el('p', { className: 'sk-help' },
                'Search: ',
                el('input', {
                    type: 'search', value: search, placeholder: 'Name or phone',
                    onChange: function (event) { setSearch(event.target.value); }
                })),
            shown.length === 0
                ? el('p', { className: 'sk-empty' }, 'No customers yet.')
                : el('table', { className: 'widefat striped sk-table' },
                    el('thead', null, el('tr', null,
                        el('th', null, 'Customer'), el('th', null, 'Phone'),
                        el('th', null, 'Group'), el('th', null, 'Spent'),
                        el('th', null, 'Approval'), el('th', null, ''))),
                    el('tbody', null, shown.map(function (row) {
                        return el('tr', { key: row.uuid },
                            el('td', null,
                                el('strong', null, row.name),
                                row.business_name ? el('div', { className: 'sk-help' }, row.business_name) : null),
                            el('td', null, row.phone),
                            el('td', null, el('select', {
                                value: row.group_id,
                                onChange: function (event) { moveTo(row, event.target.value); }
                            }, props.groups.map(function (group) {
                                return el('option', { key: group.id, value: group.id }, group.name);
                            }))),
                            el('td', null, money(row.total_spent_kobo)),
                            el('td', null, el('span', {
                                className: 'sk-pill' + (row.approval_status === 'approved' ? ' sk-pill-good' : (row.approval_status === 'pending' ? ' sk-pill-warning' : ''))
                            }, row.approval_status)),
                            el('td', null,
                                row.approval_status === 'pending'
                                    ? el('button', {
                                        type: 'button', className: 'button button-small',
                                        onClick: function () { decide(row, 'approve'); }
                                    }, 'Approve')
                                    : null,
                                row.approval_status === 'pending'
                                    ? el('button', {
                                        type: 'button', className: 'button button-small sk-danger-button',
                                        onClick: function () { decide(row, 'reject'); }
                                    }, 'Reject')
                                    : null)
                        );
                    }))
                )
        );
    }

    function Prices(props) {
        var listState = useState('');
        var listId = listState[0];
        var setListId = listState[1];

        var rowsState = useState([]);
        var rows = rowsState[0];
        var setRows = rowsState[1];

        var variantState = useState('');
        var variantId = variantState[0];
        var setVariantId = variantState[1];

        var qtyState = useState('1');
        var minQty = qtyState[0];
        var setMinQty = qtyState[1];

        var amountState = useState('');
        var amount = amountState[0];
        var setAmount = amountState[1];

        var messageState = useState(null);
        var message = messageState[0];
        var setMessage = messageState[1];

        var base = props.lists.filter(function (row) { return Number(row.is_base) === 1; })[0] || props.lists[0];

        useEffect(function () {
            if (!listId && base) {
                setListId(String(base.id));
            }
            if (!variantId && props.variants.length > 0) {
                setVariantId(String(props.variants[0].variant_id));
            }
        }, [props.lists, props.variants]);

        function load() {
            if (!listId) { return; }
            apiFetch({ url: root + '/price-lists/' + listId + '/prices?limit=500' }).then(function (response) {
                setRows(response.data || []);
            }).catch(function (failure) {
                setMessage({ tone: 'bad', text: failure.message || 'Could not load prices.' });
            });
        }

        useEffect(load, [listId]);

        function savePrice(event) {
            event.preventDefault();
            setMessage(null);

            apiFetch({
                url: root + '/price-lists/' + listId + '/prices',
                method: 'PUT',
                data: {
                    variant_id: Number(variantId),
                    min_qty: Number(minQty || 1),
                    amount_kobo: Math.round(Number(amount || 0) * 100)
                }
            }).then(function () {
                setMessage({ tone: 'good', text: 'Price saved.' });
                setAmount('');
                load();
            }).catch(function (failure) {
                setMessage({ tone: 'bad', text: failure.message || 'Could not save the price.' });
            });
        }

        return el('div', null,
            el('form', { className: 'sk-panel sk-form', onSubmit: savePrice },
                el('h2', null, 'Set a price'),
                el('div', { className: 'sk-form-grid' },
                    field('Price list', el('select', {
                        value: listId,
                        onChange: function (event) { setListId(event.target.value); }
                    }, props.lists.map(function (row) {
                        return el('option', { key: row.id, value: row.id }, row.name + (Number(row.is_base) === 1 ? ' (base)' : ''));
                    }))),
                    field('Item', el('select', {
                        value: variantId,
                        onChange: function (event) { setVariantId(event.target.value); }
                    }, props.variants.map(function (row) {
                        return el('option', { key: row.variant_id, value: row.variant_id },
                            row.product_name + ' — ' + row.title);
                    }))),
                    field('From quantity', el('input', {
                        type: 'number', min: '1', value: minQty,
                        onChange: function (event) { setMinQty(event.target.value); }
                    })),
                    field('Price per piece', el('input', {
                        type: 'number', min: '0', step: '0.01', value: amount, required: true, placeholder: '4500',
                        onChange: function (event) { setAmount(event.target.value); }
                    }))),
                el('p', { className: 'sk-actions' },
                    el('button', { type: 'submit', className: 'button button-primary' }, 'Save price')),
                notice(message)
            ),

            el('h2', null, 'Prices'),
            rows.length === 0
                ? el('p', { className: 'sk-empty' }, 'No prices in this list yet.')
                : el('table', { className: 'widefat striped sk-table' },
                    el('thead', null, el('tr', null,
                        el('th', null, 'Product'), el('th', null, 'Variant'),
                        el('th', null, 'From'), el('th', null, 'Price'))),
                    el('tbody', null, rows.map(function (row) {
                        return el('tr', { key: row.id },
                            el('td', null, row.product_name),
                            el('td', null, row.variant_title),
                            el('td', null, row.min_qty),
                            el('td', null, money(row.amount_kobo)));
                    }))
                )
        );
    }

    function Locations(props) {
        var nameState = useState('');
        var name = nameState[0];
        var setName = nameState[1];

        var typeState = useState('shop');
        var type = typeState[0];
        var setType = typeState[1];

        var addressState = useState('');
        var address = addressState[0];
        var setAddress = addressState[1];

        var onlineState = useState(true);
        var sellableOnline = onlineState[0];
        var setSellableOnline = onlineState[1];

        var messageState = useState(null);
        var message = messageState[0];
        var setMessage = messageState[1];

        function createLocation(event) {
            event.preventDefault();
            setMessage(null);

            apiFetch({
                url: root + '/locations',
                method: 'POST',
                data: {
                    name: name,
                    type: type,
                    address: address || null,
                    sellable_online: sellableOnline,
                    is_pickup_point: type === 'shop'
                }
            }).then(function () {
                setMessage({ tone: 'good', text: 'Location added.' });
                setName('');
                setAddress('');
                props.onChanged();
            }).catch(function (failure) {
                setMessage({ tone: 'bad', text: failure.message || 'Could not add the location.' });
            });
        }

        return el('div', null,
            el('form', { className: 'sk-panel sk-form', onSubmit: createLocation },
                el('h2', null, 'New location'),
                el('div', { className: 'sk-form-grid' },
                    field('Name', el('input', {
                        type: 'text', value: name, required: true, placeholder: 'Balogun shop',
                        onChange: function (event) { setName(event.target.value); }
                    })),
                    field('Type', el('select', {
                        value: type,
                        onChange: function (event) { setType(event.target.value); }
                    },
                        el('option', { value: 'shop' }, 'Shop'),
                        el('option', { value: 'warehouse' }, 'Warehouse'),
                        el('option', { value: 'store' }, 'Store'),
                        el('option', { value: 'other' }, 'Other'))),
                    field('Address', el('input', {
                        type: 'text', value: address,
                        onChange: function (event) { setAddress(event.target.value); }
                    }))),
                el('p', { className: 'sk-field' },
                    el('label', null,
                        el('input', {
                            type: 'checkbox', checked: sellableOnline,
                            onChange: function (event) { setSellableOnline(event.target.checked); }
                        }),
                        ' Sell stock from here online')),
                el('p', { className: 'sk-actions' },
                    el('button', { type: 'submit', className: 'button button-primary' }, 'Add location')),
                notice(message)
            ),

            el('h2', null, 'Locations'),
            el('table', { className: 'widefat striped sk-table' },
                el('thead', null, el('tr', null,
                    el('th', null, 'Location'), el('th', null, 'Type'),
                    el('th', null, 'Online'), el('th', null, 'Address'))),
                el('tbody', null, props.rows.map(function (row) {
                    return el('tr', { key: row.id },
                        el('td', null, el('strong', null, row.name)),
                        el('td', null, row.type),
                        el('td', null, el('span', {
                            className: 'sk-pill' + (Number(row.sellable_online) === 1 ? ' sk-pill-good' : '')
                        }, Number(row.sellable_online) === 1 ? 'yes' : 'no')),
                        el('td', null, row.address || '—'));
                }))
            )
        );
    }

    function Staff(props) {
        var data = props.data || { staff: [], roles: [] };

        return el('div', null,
            el('h2', null, 'Staff'),
            el('p', { className: 'sk-help' },
                'Add or remove people on the WordPress Users screen; this list shows who can work in the store and what they may approve.'),
            data.staff.length === 0
                ? el('p', { className: 'sk-empty' }, 'No staff accounts yet.')
                : el('table', { className: 'widefat striped sk-table' },
                    el('thead', null, el('tr', null,
                        el('th', null, 'Name'), el('th', null, 'Email'),
                        el('th', null, 'Role'), el('th', null, 'Confirms payments'))),
                    el('tbody', null, data.staff.map(function (row) {
                        return el('tr', { key: row.id },
                            el('td', null, el('strong', null, row.name)),
                            el('td', null, row.email),
                            el('td', null, el('span', { className: 'sk-pill' }, row.role || '—')),
                            el('td', null, row.can_confirm_payments ? 'yes' : 'no'));
                    }))
                ),
            el('h2', null, 'Roles'),
            el('table', { className: 'widefat striped sk-table' },
                el('tbody', null, data.roles.map(function (row) {
                    return el('tr', { key: row.slug },
                        el('td', null, row.label),
                        el('td', null, el('code', null, row.wp_role)));
                }))
            )
        );
    }

    function Alerts(props) {
        if (!props.rows || props.rows.length === 0) {
            return el('p', { className: 'sk-empty' }, 'No open alerts.');
        }

        return el('table', { className: 'widefat striped sk-table' },
            el('thead', null, el('tr', null,
                el('th', null, 'Alert'), el('th', null, 'Detail'),
                el('th', null, 'When'), el('th', null, ''))),
            el('tbody', null, props.rows.map(function (row) {
                var detail = Object.keys(row.payload || {}).map(function (key) {
                    return key + ': ' + row.payload[key];
                }).join(', ');

                return el('tr', { key: row.id },
                    el('td', null, el('span', {
                        className: 'sk-pill' + (row.severity === 'critical' ? ' sk-pill-danger' : ' sk-pill-warning')
                    }, row.type)),
                    el('td', null, detail),
                    el('td', null, row.created_at),
                    el('td', null, el('button', {
                        type: 'button', className: 'button button-small',
                        onClick: function () { props.onResolve(row); }
                    }, 'Resolve')));
            }))
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

        var ordersState = useState([]);
        var orders = ordersState[0];
        var setOrders = ordersState[1];

        var variantsState = useState([]);
        var variants = variantsState[0];
        var setVariants = variantsState[1];

        var customersState = useState([]);
        var customers = customersState[0];
        var setCustomers = customersState[1];

        var groupsState = useState([]);
        var groups = groupsState[0];
        var setGroups = groupsState[1];

        var priceListsState = useState([]);
        var priceLists = priceListsState[0];
        var setPriceLists = priceListsState[1];

        var staffState = useState(null);
        var staff = staffState[0];
        var setStaff = staffState[1];

        var alertsState = useState([]);
        var alerts = alertsState[0];
        var setAlerts = alertsState[1];

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

        function loadOrders() {
            return apiFetch({ url: root + '/orders?limit=50' }).then(function (response) {
                setOrders(response.data || []);
            });
        }

        function loadVariants() {
            return apiFetch({ url: root + '/variants?limit=200' }).then(function (response) {
                setVariants(response.data || []);
            });
        }

        function loadCustomers() {
            return apiFetch({ url: root + '/customers?limit=200' }).then(function (response) {
                setCustomers(response.data || []);
            });
        }

        function loadGroups() {
            return apiFetch({ url: root + '/customer-groups' }).then(function (response) {
                setGroups(response.data || []);
            });
        }

        function loadPriceLists() {
            return apiFetch({ url: root + '/price-lists' }).then(function (response) {
                setPriceLists(response.data || []);
            });
        }

        function loadStaff() {
            return apiFetch({ url: root + '/staff' }).then(function (response) {
                setStaff(response.data);
            });
        }

        function loadAlerts() {
            return apiFetch({ url: root + '/alerts' }).then(function (response) {
                setAlerts(response.data || []);
            });
        }

        function resolveAlert(row) {
            apiFetch({
                url: root + '/alerts/' + row.id + '/resolve',
                method: 'POST',
                data: { resolution: 'Resolved from the back office' }
            }).then(function () {
                loadAlerts();
                loadOverview();
            }).catch(function () {});
        }

        useEffect(function () {
            loadOverview().catch(function (failure) {
                setError(failure.message || 'Could not load the overview.');
            });
            loadProducts().catch(function () {});
            loadOrders().catch(function () {});
            loadVariants().catch(function () {});
            apiFetch({ url: root + '/locations' }).then(function (response) {
                setLocations(response.data || []);
            }).catch(function () {});
            apiFetch({ url: root + '/settings/payments' }).then(function (response) {
                setGateways(response.data);
            }).catch(function () {});
            loadCustomers().catch(function () {});
            loadGroups().catch(function () {});
            loadPriceLists().catch(function () {});
            loadStaff().catch(function () {});
            loadAlerts().catch(function () {});
        }, []);

        if (error) {
            return el('div', { className: 'notice notice-error' }, el('p', null, error));
        }

        if (!overview) {
            return el('p', { className: 'storekeeper-loading' }, 'Loading your store…');
        }

        var sections = [
            ['dashboard', 'Overview', 'What needs attention today'],
            ['products', 'Products', 'Catalogue, variants and stock'],
            ['orders', 'Orders', 'Every sale, every channel'],
            ['parcels', 'Parcels', 'Pack, dispatch and print'],
            ['shipping', 'Shipping', 'Zones, fees and delivery'],
            ['customers', 'Customers', 'People and wholesale accounts'],
            ['prices', 'Prices', 'Price lists and quantity tiers'],
            ['locations', 'Locations', 'Shops and warehouses'],
            ['staff', 'Staff', 'Who works in this store'],
            ['messages', 'Messages', 'Outbox and message templates'],
            ['settings', 'Gateways', 'Where customers pay you']
        ];
        var current = sections.filter(function (section) { return section[0] === tab; })[0] || sections[0];

        return el('div', { className: 'sk-shell' },
            el('aside', { className: 'sk-rail' },
                el('div', { className: 'sk-brand' },
                    el('span', { className: 'sk-brand-mark' }, 'SK'),
                    el('div', { className: 'sk-brand-text' },
                        el('strong', null, config.storeName || 'Store Keeper'),
                        el('span', null, 'Commerce'))),
                el('nav', { className: 'sk-nav', 'aria-label': 'Store sections' },
                    sections.map(function (section) {
                        return el('button', {
                            key: section[0],
                            type: 'button',
                            className: 'sk-nav-item' + (tab === section[0] ? ' is-active' : ''),
                            onClick: function () { setTab(section[0]); }
                        }, section[1]);
                    })),
                el('div', { className: 'sk-rail-foot' },
                    el('a', { className: 'sk-rail-link', href: config.siteUrl }, 'View store'),
                    el('a', { className: 'sk-rail-link', href: config.wpAdminUrl }, 'WordPress admin'),
                    el('span', { className: 'sk-user' }, 'Signed in as ' + (config.userName || 'staff')),
                    el('a', { className: 'sk-rail-link sk-rail-link-quiet', href: config.logoutUrl }, 'Sign out'))),

            el('main', { className: 'sk-main' },
                el('header', { className: 'sk-topbar' },
                    el('div', null,
                        el('p', { className: 'sk-eyebrow' }, config.storeName || 'Store Keeper'),
                        el('h1', { className: 'sk-page-title' }, current[1]),
                        el('p', { className: 'sk-page-sub' }, current[2])),
                    el('div', { className: 'sk-topbar-meta' },
                        el('span', { className: 'sk-chip' }, overview.orders_today + ' orders today'),
                        el('span', { className: 'sk-chip sk-chip-muted' }, overview.stock_units.toLocaleString() + ' units on hand'))),

                el('div', { className: 'sk-content' },

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
                        ),
                    el('h2', null, 'Alerts'),
                    el(Alerts, { rows: alerts, onResolve: resolveAlert })
                )
                : null,

            tab === 'products'
                ? el(Products, {
                    rows: products,
                    locations: locations,
                    onCreated: function () { loadProducts(); loadOverview(); }
                })
                : null,

            tab === 'orders'
                ? el(Orders, {
                    rows: orders,
                    variants: variants,
                    gateways: gateways,
                    onChanged: function () {
                        loadOrders();
                        loadVariants();
                        loadOverview();
                        loadProducts();
                    }
                })
                : null,

            tab === 'parcels'
                ? (window.storekeeperScreens
                    ? el(window.storekeeperScreens.Parcels)
                    : el('p', { className: 'sk-empty' }, 'This screen did not load. Reload the page.'))
                : null,

            tab === 'shipping'
                ? (window.storekeeperScreens
                    ? el(window.storekeeperScreens.Shipping)
                    : el('p', { className: 'sk-empty' }, 'This screen did not load. Reload the page.'))
                : null,

            tab === 'customers'
                ? el(Customers, {
                    rows: customers,
                    groups: groups,
                    onChanged: function () {
                        loadCustomers();
                        loadGroups();
                        loadOverview();
                    }
                })
                : null,

            tab === 'prices'
                ? el(Prices, {
                    lists: priceLists,
                    variants: variants,
                    onChanged: function () { loadPriceLists(); }
                })
                : null,

            tab === 'locations'
                ? el(Locations, {
                    rows: locations,
                    onChanged: function () {
                        apiFetch({ url: root + '/locations' }).then(function (response) {
                            setLocations(response.data || []);
                        }).catch(function () {});
                    }
                })
                : null,

            tab === 'staff'
                ? (staff
                    ? el(Staff, { data: staff })
                    : el('p', { className: 'storekeeper-loading' }, 'Loading staff…'))
                : null,

            tab === 'messages'
                ? (window.storekeeperScreens
                    ? el(window.storekeeperScreens.Messages)
                    : el('p', { className: 'sk-empty' }, 'This screen did not load. Reload the page.'))
                : null,

            tab === 'settings'
                ? (gateways
                    ? el(Gateways, { data: gateways, onSaved: setGateways })
                    : el('p', { className: 'storekeeper-loading' }, 'Loading gateway settings…'))
                : null,

            el('p', { className: 'sk-footnote' },
                'Store Keeper · catalogue, stock, orders, customers, prices, payments and staff in one place. ' +
                'Storefront, POS and platform data all come from the same live store.')
                )
            )
        );
    }

    var container = document.getElementById('storekeeper-admin');
    if (container && wp.element) {
        wp.element.render(el(App), container);
    }
})(window.wp);
