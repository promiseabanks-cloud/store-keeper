/**
 * Store Keeper back office: shipping, parcels and messages (§12.2, §13.3, §14.2).
 *
 * Loaded after admin.js in the same wp-admin screen. It renders the three
 * screens that admin.js already has a nav slot for; every read and write goes
 * through the REST API, and printing uses the browser's own print dialog.
 */
(function (wp) {
    'use strict';

    if (!wp || !wp.element || !wp.apiFetch) {
        return;
    }

    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var apiFetch = wp.apiFetch;

    var config = window.storekeeperAdmin || {};
    var root = (config.root || '/wp-json/storekeeper/v1').replace(/\/$/, '');

    var PARCEL_NEXT = {
        pending: ['packed', 'cancelled'],
        packed: ['dispatched', 'ready_for_pickup', 'cancelled'],
        ready_for_pickup: ['delivered', 'failed_delivery', 'returned'],
        dispatched: ['delivered', 'failed_delivery'],
        failed_delivery: ['dispatched', 'returned']
    };

    var STATUS_LABELS = {
        pending: 'Not packed yet',
        packed: 'Packed',
        ready_for_pickup: 'Ready for pickup',
        dispatched: 'On the way',
        delivered: 'Delivered',
        failed_delivery: 'Delivery failed',
        returned: 'Returned',
        cancelled: 'Cancelled'
    };

    var TYPE_LABELS = {
        flat: 'Flat fee',
        weight_band: 'By weight',
        free_over: 'Free over a threshold',
        pickup: 'Pickup',
        quote_later: 'Quote later'
    };

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

    function nairaToKobo(value) {
        return Math.round(Number(value || 0) * 100);
    }

    function field(label, input) {
        return el('p', { className: 'sk-field' },
            el('label', null, label),
            input
        );
    }

    function notice(message) {
        return message
            ? el('div', { className: 'notice notice-' + (message.tone === 'good' ? 'success' : 'error') + ' inline' },
                el('p', null, message.text))
            : null;
    }

    function pill(text, tone) {
        return el('span', { className: 'sk-pill' + (tone ? ' sk-pill-' + tone : '') }, text);
    }

    function statusTone(status) {
        if (status === 'delivered') {
            return 'good';
        }
        if (status === 'failed_delivery' || status === 'cancelled') {
            return 'danger';
        }
        if (status === 'pending' || status === 'packed') {
            return 'warning';
        }
        return null;
    }

    function addressLine(order) {
        return [order.line1, order.line2, order.landmark, order.lga, order.state]
            .filter(function (part) { return part; })
            .join(', ');
    }

    function orderTotal(order) {
        return order && order.total_kobo !== undefined ? money(order.total_kobo) : '';
    }

    function printArea(content) {
        var node = el('div', { className: 'sk-print' }, content);

        // Print outside the app shell: the screen is hidden while printing, and
        // the slip must not be hidden with it.
        if (wp.element.createPortal && document.body) {
            return wp.element.createPortal(node, document.body);
        }

        return node;
    }

    function usePrint() {
        var jobState = useState(null);
        var job = jobState[0];
        var setJob = jobState[1];

        useEffect(function () {
            function clear() {
                setJob(null);
            }

            window.addEventListener('afterprint', clear);

            return function () {
                window.removeEventListener('afterprint', clear);
            };
        }, []);

        function print(next) {
            setJob(next);
            window.setTimeout(function () {
                window.print();
            }, 80);
        }

        return [job, print];
    }

    function ParcelPrint(props) {
        var job = props.job;

        if (!job) {
            return null;
        }

        if (job.type === 'slip') {
            var shipment = job.shipment;
            var order = shipment.order || {};
            var items = shipment.items || [];
            var pieces = items.reduce(function (sum, item) { return sum + Number(item.qty_pieces); }, 0);

            return printArea([
                el('h1', null, config.storeName || 'Store Keeper'),
                el('h2', null, 'Packing slip'),
                el('p', { className: 'sk-print-strong' }, 'Order ' + (order.number || '')),
                el('p', null, (order.shipping_address || {}).recipient_name || 'Walk-in customer'),
                el('p', null, (order.shipping_address || {}).shipping_label || ''),
                el('p', null, addressLine(order.shipping_address || {})),
                el('p', null, (order.shipping_address || {}).phone_e164 || ''),
                el('table', null,
                    el('thead', null, el('tr', null,
                        el('th', null, 'Item'), el('th', null, 'Variant'),
                        el('th', null, 'SKU'), el('th', null, 'Qty'))),
                    el('tbody', null, items.map(function (item) {
                        return el('tr', { key: item.order_item_id },
                            el('td', null, item.product_name),
                            el('td', null, item.variant_title),
                            el('td', null, item.sku || '—'),
                            el('td', null, item.qty_pieces));
                    }))
                ),
                el('p', { className: 'sk-print-strong' }, 'Pieces in this parcel: ' + pieces),
                el('p', { className: 'sk-print-muted' }, 'Packed by ______________________   Date ______________')
            ]);
        }

        var rows = job.rows || [];

        return printArea([
            el('h1', null, config.storeName || 'Store Keeper'),
            el('h2', null, 'Dispatch sheet'),
            el('p', null, rows.length + ' parcel(s)'),
            el('table', null,
                el('thead', null, el('tr', null,
                    el('th', null, 'Order'), el('th', null, 'Recipient'),
                    el('th', null, 'Phone'), el('th', null, 'Address'),
                    el('th', null, 'Pieces'), el('th', null, 'Waybill'))),
                el('tbody', null, rows.map(function (row) {
                    return el('tr', { key: row.uuid },
                        el('td', null, row.order.number),
                        el('td', null, row.order.recipient_name || '—'),
                        el('td', null, row.order.phone || '—'),
                        el('td', null, addressLine(row.order) || 'Pickup'),
                        el('td', null, row.pieces),
                        el('td', null, '')
                    );
                }))
            ),
            el('p', { className: 'sk-print-muted' }, 'Rider ______________________   Signature ______________________')
        ]);
    }

    function Parcels() {
        var rowsState = useState([]);
        var rows = rowsState[0];
        var setRows = rowsState[1];

        var filterState = useState('pending');
        var filter = filterState[0];
        var setFilter = filterState[1];

        var selectedState = useState({});
        var selected = selectedState[0];
        var setSelected = selectedState[1];

        var detailState = useState(null);
        var detail = detailState[0];
        var setDetail = detailState[1];

        var targetState = useState('');
        var target = targetState[0];
        var setTarget = targetState[1];

        var formState = useState({ carrier: '', waybill_number: '', rider_name: '', rider_phone: '', note: '', collected: '', collection_method: 'cash', restock: true });
        var form = formState[0];
        var setForm = formState[1];

        var busyState = useState(false);
        var busy = busyState[0];
        var setBusy = busyState[1];

        var messageState = useState(null);
        var message = messageState[0];
        var setMessage = messageState[1];

        var printState = usePrint();
        var printJob = printState[0];
        var print = printState[1];

        function load(status) {
            var url = root + '/shipments' + (status ? '?status=' + encodeURIComponent(status) : '');

            return apiFetch({ url: url }).then(function (response) {
                setRows(response.data || []);
            }).catch(function (failure) {
                setMessage({ tone: 'bad', text: failure.message || 'Could not load parcels.' });
            });
        }

        useEffect(function () {
            load(filter);
        }, [filter]);

        function open(row) {
            setMessage(null);
            setTarget('');
            apiFetch({ url: root + '/shipments/' + row.uuid }).then(function (response) {
                setDetail(response.data);
            }).catch(function (failure) {
                setMessage({ tone: 'bad', text: failure.message || 'Could not open the parcel.' });
            });
        }

        function toggle(row) {
            var copy = Object.assign({}, selected);
            if (copy[row.uuid]) {
                delete copy[row.uuid];
            } else {
                copy[row.uuid] = true;
            }
            setSelected(copy);
        }

        function selectedRows() {
            return rows.filter(function (row) { return selected[row.uuid]; });
        }

        function bulkPack() {
            var ids = selectedRows().map(function (row) { return row.id; });

            if (ids.length === 0) {
                return;
            }

            setBusy(true);
            setMessage(null);
            apiFetch({
                url: root + '/shipments/bulk-status',
                method: 'POST',
                data: { shipment_ids: ids, status: 'packed' }
            }).then(function (response) {
                var failed = Object.keys(response.data.failed || {}).length;
                setMessage({
                    tone: failed === 0 ? 'good' : 'bad',
                    text: response.data.updated.length + ' parcel(s) packed.' +
                        (failed === 0 ? '' : ' ' + failed + ' could not be packed.')
                });
                setSelected({});
                load(filter);
            }).catch(function (failure) {
                setMessage({ tone: 'bad', text: failure.message || 'Could not pack the parcels.' });
            }).then(function () {
                setBusy(false);
            });
        }

        function printSlip(row) {
            apiFetch({ url: root + '/shipments/' + row.uuid }).then(function (response) {
                print({ type: 'slip', shipment: response.data });
            }).catch(function (failure) {
                setMessage({ tone: 'bad', text: failure.message || 'Could not load the packing slip.' });
            });
        }

        function change(key, value) {
            var copy = Object.assign({}, form);
            copy[key] = value;
            setForm(copy);
        }

        function act() {
            if (!detail || !target) {
                return;
            }

            var payload = { status: target, note: form.note };

            if (target === 'dispatched') {
                payload.carrier = form.carrier;
                payload.waybill_number = form.waybill_number;
                payload.rider_name = form.rider_name;
                payload.rider_phone = form.rider_phone;
            }

            if (target === 'failed_delivery') {
                payload.note = form.note;
            }

            if (target === 'delivered' && form.collected !== '') {
                payload.collected_kobo = nairaToKobo(form.collected);
                payload.collection_method = form.collection_method;
            }

            if (target === 'returned') {
                payload.restock = form.restock;
            }

            setBusy(true);
            setMessage(null);

            apiFetch({
                url: root + '/shipments/' + detail.uuid,
                method: 'PATCH',
                data: payload
            }).then(function (response) {
                setMessage({ tone: 'good', text: 'Parcel is now ' + (STATUS_LABELS[response.data.status] || response.data.status) + '.' });
                setDetail(response.data);
                setTarget('');
                load(filter);
            }).catch(function (failure) {
                setMessage({ tone: 'bad', text: failure.message || 'Could not move the parcel.' });
            }).then(function () {
                setBusy(false);
            });
        }

        var chosen = detail ? rows.filter(function (row) { return row.uuid === detail.uuid; })[0] : null;
        var nextStatuses = detail ? (PARCEL_NEXT[detail.status] || []) : [];
        var pickedRows = selectedRows();

        return el('div', null,
            el('h2', null, 'Parcels'),
            el('p', { className: 'sk-help' },
                'Packing commits that parcel\'s stock. A parcel leaves this list once it is packed; print the packing slip or a dispatch sheet from here.'),

            notice(message),

            el('p', { className: 'sk-help' },
                'Show: ',
                el('select', {
                    value: filter,
                    onChange: function (event) { setFilter(event.target.value); }
                },
                    el('option', { value: 'pending' }, 'To pack'),
                    el('option', { value: 'packed' }, 'Packed'),
                    el('option', { value: 'dispatched' }, 'On the way'),
                    el('option', { value: 'delivered' }, 'Delivered'),
                    el('option', { value: 'failed_delivery' }, 'Delivery failed'),
                    el('option', { value: '' }, 'Every parcel')
                ),
                pickedRows.length > 0
                    ? el('span', null,
                        ' · ' + pickedRows.length + ' selected ',
                        el('button', {
                            type: 'button',
                            className: 'button button-small',
                            disabled: busy,
                            onClick: bulkPack
                        }, 'Mark packed'),
                        el('button', {
                            type: 'button',
                            className: 'button button-small',
                            onClick: function () { print({ type: 'sheet', rows: pickedRows }); }
                        }, 'Print dispatch sheet'))
                    : null),

            rows.length === 0
                ? el('p', { className: 'sk-empty' },
                    filter === 'pending' ? 'Nothing waiting to be packed.' : 'No parcels with that status.')
                : el('table', { className: 'widefat striped sk-table' },
                    el('thead', null, el('tr', null,
                        el('th', null, ''),
                        el('th', null, 'Order'),
                        el('th', null, 'Recipient'),
                        el('th', null, 'Delivery'),
                        el('th', null, 'Pieces'),
                        el('th', null, 'Created'),
                        el('th', null, ''))),
                    el('tbody', null, rows.map(function (row) {
                        return el('tr', { key: row.uuid },
                            el('td', null, el('input', {
                                type: 'checkbox',
                                checked: !!selected[row.uuid],
                                onChange: function () { toggle(row); }
                            })),
                            el('td', null,
                                el('strong', null, row.order.number),
                                el('div', { className: 'sk-help' }, row.status_label)),
                            el('td', null,
                                row.order.recipient_name || '—',
                                el('div', { className: 'sk-help' }, row.order.phone || '')),
                            el('td', null,
                                row.order.shipping_label || row.method_name || 'Pickup',
                                el('div', { className: 'sk-help' }, addressLine(row.order) || '')),
                            el('td', null, row.pieces),
                            el('td', null, row.created_at),
                            el('td', null,
                                el('button', {
                                    type: 'button',
                                    className: 'button button-small',
                                    onClick: function () { open(row); }
                                }, 'Open'),
                                el('button', {
                                    type: 'button',
                                    className: 'button button-small',
                                    onClick: function () { printSlip(row); }
                                }, 'Packing slip'))
                        );
                    }))
                ),

            detail
                ? el('div', { className: 'sk-panel sk-parcel-detail' },
                    el('h2', null, 'Parcel for ' + detail.order.number),
                    el('p', { className: 'sk-help' },
                        detail.order.recipient_name || '—',
                        ' · ', detail.order.phone || 'no phone',
                        ' · ', addressLine(detail.order) || 'Pickup at the shop',
                        ' · ', pill(STATUS_LABELS[detail.status] || detail.status, statusTone(detail.status)),
                        ' · ', detail.order.payment_status),

                    el('table', { className: 'widefat striped sk-table' },
                        el('thead', null, el('tr', null,
                            el('th', null, 'Item'), el('th', null, 'Variant'),
                            el('th', null, 'SKU'), el('th', null, 'In this parcel'))),
                        el('tbody', null, (detail.items || []).map(function (item) {
                            return el('tr', { key: item.order_item_id },
                                el('td', null, item.product_name),
                                el('td', null, item.variant_title),
                                el('td', null, el('code', null, item.sku || '—')),
                                el('td', null, item.qty_pieces + ' of ' + item.order_qty_pieces));
                        }))
                    ),

                    nextStatuses.length > 0
                        ? el('div', { className: 'sk-parcel-action' },
                            el('h3', null, 'Move this parcel'),
                            el('div', { className: 'sk-form-grid' },
                                field('Next status', el('select', {
                                    value: target,
                                    onChange: function (event) { setTarget(event.target.value); }
                                },
                                    el('option', { value: '' }, 'Choose…'),
                                    nextStatuses.map(function (status) {
                                        return el('option', { key: status, value: status }, STATUS_LABELS[status] || status);
                                    })
                                )),
                                target === 'dispatched'
                                    ? field('Carrier', el('input', {
                                        type: 'text',
                                        value: form.carrier,
                                        placeholder: 'GIG Logistics or Shop rider',
                                        onChange: function (event) { change('carrier', event.target.value); }
                                    }))
                                    : null,
                                target === 'dispatched'
                                    ? field('Waybill number', el('input', {
                                        type: 'text',
                                        value: form.waybill_number,
                                        onChange: function (event) { change('waybill_number', event.target.value); }
                                    }))
                                    : null,
                                target === 'dispatched'
                                    ? field('Rider name', el('input', {
                                        type: 'text',
                                        value: form.rider_name,
                                        onChange: function (event) { change('rider_name', event.target.value); }
                                    }))
                                    : null,
                                target === 'dispatched'
                                    ? field('Rider phone', el('input', {
                                        type: 'tel',
                                        value: form.rider_phone,
                                        onChange: function (event) { change('rider_phone', event.target.value); }
                                    }))
                                    : null,
                                target === 'delivered' && detail.order.payment_status !== 'paid'
                                    ? field('Cash collected (₦)', el('input', {
                                        type: 'number',
                                        min: '0',
                                        value: form.collected,
                                        placeholder: String(Math.round(detail.order.total_kobo / 100)),
                                        onChange: function (event) { change('collected', event.target.value); }
                                    }))
                                    : null,
                                target === 'delivered' && detail.order.payment_status !== 'paid'
                                    ? field('Collected by', el('select', {
                                        value: form.collection_method,
                                        onChange: function (event) { change('collection_method', event.target.value); }
                                    },
                                        el('option', { value: 'cash' }, 'Cash'),
                                        el('option', { value: 'bank_transfer' }, 'Transfer')))
                                    : null
                            ),
                            target === 'failed_delivery' || target === 'returned' || target === 'cancelled'
                                ? field(target === 'failed_delivery' ? 'Why it failed' : 'Note', el('input', {
                                    type: 'text',
                                    value: form.note,
                                    onChange: function (event) { change('note', event.target.value); }
                                }))
                                : null,
                            target === 'returned'
                                ? el('p', { className: 'sk-field' },
                                    el('label', null,
                                        el('input', {
                                            type: 'checkbox',
                                            checked: form.restock,
                                            onChange: function (event) { change('restock', event.target.checked); }
                                        }),
                                        ' Put the goods back on the shelf'))
                                : null,
                            el('p', { className: 'sk-actions' },
                                el('button', {
                                    type: 'button',
                                    className: 'button button-primary',
                                    disabled: busy || !target,
                                    onClick: act
                                }, busy ? 'Saving…' : 'Save status'),
                                el('button', {
                                    type: 'button',
                                    className: 'button',
                                    onClick: function () { setDetail(null); }
                                }, 'Close'))
                        )
                        : el('p', { className: 'sk-help' }, 'This parcel is finished; nothing left to change.'),

                    el('h3', null, 'Timeline'),
                    el('table', { className: 'widefat striped sk-table' },
                        el('tbody', null, (detail.events || []).map(function (event) {
                            return el('tr', { key: event.id },
                                el('td', null, event.created_at),
                                el('td', null, pill(event.status, statusTone(event.status))),
                                el('td', null, event.note || '—'));
                        }))
                    ),

                    el('p', { className: 'sk-actions' },
                        el('button', {
                            type: 'button',
                            className: 'button',
                            onClick: function () { printSlip(detail); }
                        }, 'Print packing slip'),
                        chosen && chosen.status === 'packed'
                            ? el('button', {
                                type: 'button',
                                className: 'button',
                                onClick: function () { print({ type: 'sheet', rows: [chosen] }); }
                            }, 'Print dispatch sheet')
                            : null)
                )
                : null,

            el(ParcelPrint, { job: printJob })
        );
    }

    function methodFee(method) {
        var cfg = method.config || {};

        if (method.type === 'flat') {
            return money(cfg.amount_kobo || 0);
        }

        if (method.type === 'free_over') {
            return 'Free over ' + money(cfg.threshold_kobo || 0) + ', else ' + money(cfg.else_amount_kobo || 0);
        }

        if (method.type === 'weight_band') {
            var bands = cfg.bands || [];
            return bands.length + ' band(s)' + (cfg.over_per_kg_kobo ? ' + ' + money(cfg.over_per_kg_kobo) + '/kg above' : '');
        }

        if (method.type === 'pickup') {
            return 'No fee';
        }

        return 'Quoted later';
    }

    function bandsToText(bands) {
        return (bands || []).map(function (band) {
            return band.max_grams + ': ' + Math.round(Number(band.amount_kobo || 0) / 100);
        }).join('\n');
    }

    function parseBands(text) {
        return String(text || '').split('\n').map(function (line) {
            return line.trim();
        }).filter(function (line) {
            return line !== '';
        }).map(function (line) {
            var parts = line.split(/[:=]/);
            return {
                max_grams: Number(String(parts[0] || '').trim()),
                amount_kobo: nairaToKobo(parts[1] === undefined ? 0 : String(parts[1]).trim())
            };
        });
    }

    function MethodEditor(props) {
        var method = props.method;
        var isNew = !method;
        var cfg = method ? (method.config || {}) : {};

        var nameState = useState(method ? method.name : '');
        var name = nameState[0];
        var setName = nameState[1];

        var typeState = useState(method ? method.type : 'flat');
        var type = typeState[0];
        var setType = typeState[1];

        var zoneState = useState(method && method.zone_id ? String(method.zone_id) : '');
        var zoneId = zoneState[0];
        var setZoneId = zoneState[1];

        var activeState = useState(method ? !!method.is_active : true);
        var active = activeState[0];
        var setActive = activeState[1];

        var amountState = useState(cfg.amount_kobo !== undefined ? String(cfg.amount_kobo / 100) : '');
        var amount = amountState[0];
        var setAmount = amountState[1];

        var thresholdState = useState(cfg.threshold_kobo !== undefined ? String(cfg.threshold_kobo / 100) : '');
        var threshold = thresholdState[0];
        var setThreshold = thresholdState[1];

        var elseState = useState(cfg.else_amount_kobo !== undefined ? String(cfg.else_amount_kobo / 100) : '');
        var elseAmount = elseState[0];
        var setElseAmount = elseState[1];

        var bandsState = useState(bandsToText(cfg.bands));
        var bands = bandsState[0];
        var setBands = bandsState[1];

        var overState = useState(cfg.over_per_kg_kobo !== undefined ? String(cfg.over_per_kg_kobo / 100) : '');
        var overPerKg = overState[0];
        var setOverPerKg = overState[1];

        var instructionsState = useState(cfg.instructions || '');
        var instructions = instructionsState[0];
        var setInstructions = instructionsState[1];

        var quoteState = useState(cfg.message || '');
        var quoteMessage = quoteState[0];
        var setQuoteMessage = quoteState[1];

        var busyState = useState(false);
        var busy = busyState[0];
        var setBusy = busyState[1];

        function config() {
            if (type === 'flat') {
                return { amount_kobo: nairaToKobo(amount) };
            }

            if (type === 'free_over') {
                return {
                    threshold_kobo: nairaToKobo(threshold),
                    else_amount_kobo: nairaToKobo(elseAmount)
                };
            }

            if (type === 'weight_band') {
                var payload = { bands: parseBands(bands) };

                if (overPerKg !== '') {
                    payload.over_per_kg_kobo = nairaToKobo(overPerKg);
                }

                return payload;
            }

            if (type === 'pickup') {
                return { instructions: instructions };
            }

            return { message: quoteMessage };
        }

        function save() {
            var payload = {
                name: name,
                type: type,
                zone_id: type === 'pickup' ? null : (zoneId ? Number(zoneId) : null),
                config: config(),
                is_active: active
            };

            setBusy(true);

            apiFetch({
                url: root + '/shipping/methods' + (isNew ? '' : '/' + method.id),
                method: isNew ? 'POST' : 'PATCH',
                data: payload
            }).then(function () {
                props.onSaved(isNew ? 'Shipping method added.' : 'Shipping method saved.');
            }).catch(function (failure) {
                props.onError(failure.message || 'Could not save the shipping method.');
            }).then(function () {
                setBusy(false);
            });
        }

        return el('div', { className: 'sk-panel sk-form' },
            el('h2', null, isNew ? 'Add a shipping method' : 'Edit ' + method.name),
            el('div', { className: 'sk-form-grid' },
                field('Name', el('input', {
                    type: 'text',
                    value: name,
                    required: true,
                    placeholder: 'Lagos mainland delivery',
                    onChange: function (event) { setName(event.target.value); }
                })),
                field('Type', el('select', {
                    value: type,
                    onChange: function (event) { setType(event.target.value); }
                }, Object.keys(TYPE_LABELS).map(function (key) {
                    return el('option', { key: key, value: key }, TYPE_LABELS[key]);
                }))),
                type !== 'pickup'
                    ? field('Zone', el('select', {
                        value: zoneId,
                        onChange: function (event) { setZoneId(event.target.value); }
                    },
                        el('option', { value: '' }, 'Choose a zone…'),
                        props.zones.map(function (zone) {
                            return el('option', { key: zone.id, value: zone.id }, zone.name);
                        })))
                    : null,
                type === 'flat'
                    ? field('Fee (₦)', el('input', {
                        type: 'number', min: '0', value: amount,
                        onChange: function (event) { setAmount(event.target.value); }
                    }))
                    : null,
                type === 'free_over'
                    ? field('Free from (₦)', el('input', {
                        type: 'number', min: '0', value: threshold,
                        onChange: function (event) { setThreshold(event.target.value); }
                    }))
                    : null,
                type === 'free_over'
                    ? field('Fee below that (₦)', el('input', {
                        type: 'number', min: '0', value: elseAmount,
                        onChange: function (event) { setElseAmount(event.target.value); }
                    }))
                    : null,
                type === 'weight_band'
                    ? field('Extra per kg above the last band (₦)', el('input', {
                        type: 'number', min: '0', value: overPerKg,
                        onChange: function (event) { setOverPerKg(event.target.value); }
                    }))
                    : null
            ),
            type === 'weight_band'
                ? field('Bands, one per line: grams : fee in naira', el('textarea', {
                    rows: 3,
                    value: bands,
                    placeholder: '1000: 1500\n3000: 3000',
                    onChange: function (event) { setBands(event.target.value); }
                }))
                : null,
            type === 'pickup'
                ? field('What the customer is told', el('input', {
                    type: 'text', value: instructions,
                    placeholder: 'Collect from the shop within 3 days.',
                    onChange: function (event) { setInstructions(event.target.value); }
                }))
                : null,
            type === 'quote_later'
                ? field('What the customer is told', el('input', {
                    type: 'text', value: quoteMessage,
                    placeholder: 'The store will confirm the delivery fee.',
                    onChange: function (event) { setQuoteMessage(event.target.value); }
                }))
                : null,
            el('p', { className: 'sk-field' },
                el('label', null,
                    el('input', {
                        type: 'checkbox',
                        checked: active,
                        onChange: function (event) { setActive(event.target.checked); }
                    }),
                    ' Offered at checkout')),
            el('p', { className: 'sk-actions' },
                el('button', {
                    type: 'button',
                    className: 'button button-primary',
                    disabled: busy,
                    onClick: save
                }, busy ? 'Saving…' : 'Save method'),
                el('button', { type: 'button', className: 'button', onClick: props.onCancel }, 'Cancel'))
        );
    }

    function Shipping() {
        var zonesState = useState([]);
        var zones = zonesState[0];
        var setZones = zonesState[1];

        var methodsState = useState([]);
        var methods = methodsState[0];
        var setMethods = methodsState[1];

        var editingState = useState(null);
        var editing = editingState[0];
        var setEditing = editingState[1];

        var zoneNameState = useState('');
        var zoneName = zoneNameState[0];
        var setZoneName = zoneNameState[1];

        var areasState = useState('');
        var areas = areasState[0];
        var setAreas = areasState[1];

        var messageState = useState(null);
        var message = messageState[0];
        var setMessage = messageState[1];

        function load() {
            apiFetch({ url: root + '/shipping/zones' }).then(function (response) {
                setZones(response.data || []);
            }).catch(function (failure) {
                setMessage({ tone: 'bad', text: failure.message || 'Could not load the zones.' });
            });

            apiFetch({ url: root + '/shipping/methods' }).then(function (response) {
                setMethods(response.data || []);
            }).catch(function (failure) {
                setMessage({ tone: 'bad', text: failure.message || 'Could not load the methods.' });
            });
        }

        useEffect(load, []);

        function parseAreas(text) {
            return String(text || '').split('\n').map(function (line) {
                return line.trim();
            }).filter(function (line) {
                return line !== '';
            }).map(function (line) {
                var parts = line.split('/');
                return {
                    state: String(parts[0] || '').trim(),
                    lga: parts.length > 1 && String(parts[1]).trim() !== '' ? String(parts[1]).trim() : null
                };
            });
        }

        function createZone(event) {
            event.preventDefault();
            setMessage(null);

            apiFetch({
                url: root + '/shipping/zones',
                method: 'POST',
                data: { name: zoneName, areas: parseAreas(areas) }
            }).then(function () {
                setMessage({ tone: 'good', text: 'Zone added.' });
                setZoneName('');
                setAreas('');
                load();
            }).catch(function (failure) {
                setMessage({ tone: 'bad', text: failure.message || 'Could not add the zone.' });
            });
        }

        function toggleMethod(method) {
            apiFetch({
                url: root + '/shipping/methods/' + method.id,
                method: 'PATCH',
                data: { is_active: !method.is_active }
            }).then(function () {
                load();
            }).catch(function (failure) {
                setMessage({ tone: 'bad', text: failure.message || 'Could not change the method.' });
            });
        }

        function zoneNameFor(id) {
            var zone = zones.filter(function (row) { return Number(row.id) === Number(id); })[0];
            return zone ? zone.name : 'No zone';
        }

        return el('div', null,
            el('h2', null, 'Delivery zones'),
            el('p', { className: 'sk-help' },
                'The first zone whose state (and local government, when given) matches the address wins. A zone with no areas is the catch-all.'),
            notice(message),

            zones.length === 0
                ? el('p', { className: 'sk-empty' }, 'No zones yet. Add the catch-all first.')
                : el('table', { className: 'widefat striped sk-table' },
                    el('thead', null, el('tr', null,
                        el('th', null, 'Zone'), el('th', null, 'Covers'), el('th', null, 'Order'))),
                    el('tbody', null, zones.map(function (zone) {
                        return el('tr', { key: zone.id },
                            el('td', null, el('strong', null, zone.name)),
                            el('td', null, (zone.areas || []).length === 0
                                ? el('span', { className: 'sk-pill sk-pill-warning' }, 'whole country')
                                : (zone.areas || []).map(function (area) {
                                    return el('span', { key: area.state + '/' + (area.lga || ''), className: 'sk-pill sk-chip-muted' },
                                        area.state + (area.lga ? ' / ' + area.lga : ''));
                                })),
                            el('td', null, zone.sort_order));
                    }))
                ),

            el('form', { className: 'sk-panel sk-form', onSubmit: createZone },
                el('h2', null, 'Add a zone'),
                el('div', { className: 'sk-form-grid' },
                    field('Name', el('input', {
                        type: 'text', required: true, value: zoneName,
                        placeholder: 'Lagos Island',
                        onChange: function (event) { setZoneName(event.target.value); }
                    }))
                ),
                field('Areas, one per line: State or State / LGA', el('textarea', {
                    rows: 3,
                    value: areas,
                    placeholder: 'Lagos / Lagos Island\nLagos / Eti-Osa\nFCT',
                    onChange: function (event) { setAreas(event.target.value); }
                })),
                el('p', { className: 'sk-help' }, 'Leave the areas empty to make this the catch-all zone.'),
                el('p', { className: 'sk-actions' },
                    el('button', { type: 'submit', className: 'button button-primary' }, 'Add zone'))
            ),

            el('h2', null, 'Shipping methods'),
            methods.length === 0
                ? el('p', { className: 'sk-empty' }, 'No methods yet. Add pickup first; a store with no method cannot take an order.')
                : el('table', { className: 'widefat striped sk-table' },
                    el('thead', null, el('tr', null,
                        el('th', null, 'Method'), el('th', null, 'Zone'),
                        el('th', null, 'Type'), el('th', null, 'Fee'),
                        el('th', null, 'Offered'), el('th', null, ''))),
                    el('tbody', null, methods.map(function (method) {
                        return el('tr', { key: method.id },
                            el('td', null, el('strong', null, method.name)),
                            el('td', null, method.type === 'pickup' ? '—' : zoneNameFor(method.zone_id)),
                            el('td', null, method.type_label || TYPE_LABELS[method.type] || method.type),
                            el('td', null, methodFee(method)),
                            el('td', null, pill(method.is_active ? 'yes' : 'no', method.is_active ? 'good' : null)),
                            el('td', null,
                                el('button', {
                                    type: 'button',
                                    className: 'button button-small',
                                    onClick: function () { toggleMethod(method); }
                                }, method.is_active ? 'Turn off' : 'Turn on'),
                                el('button', {
                                    type: 'button',
                                    className: 'button button-small',
                                    onClick: function () { setEditing(method); }
                                }, 'Edit'))
                        );
                    }))
                ),

            el(MethodEditor, {
                key: editing ? 'edit-' + editing.id : 'new',
                method: editing,
                zones: zones,
                onCancel: function () { setEditing(null); },
                onSaved: function (text) {
                    setMessage({ tone: 'good', text: text });
                    setEditing(null);
                    load();
                },
                onError: function (text) {
                    setMessage({ tone: 'bad', text: text });
                }
            })
        );
    }

    function Messages() {
        var rowsState = useState([]);
        var rows = rowsState[0];
        var setRows = rowsState[1];

        var countsState = useState({ queued: 0, sent: 0, failed: 0 });
        var counts = countsState[0];
        var setCounts = countsState[1];

        var filterState = useState('');
        var filter = filterState[0];
        var setFilter = filterState[1];

        var templatesState = useState([]);
        var templates = templatesState[0];
        var setTemplates = templatesState[1];

        var editingState = useState('');
        var editingEvent = editingState[0];
        var setEditingEvent = editingState[1];

        var subjectState = useState('');
        var subject = subjectState[0];
        var setSubject = subjectState[1];

        var bodyState = useState('');
        var body = bodyState[0];
        var setBody = bodyState[1];

        var busyState = useState(false);
        var busy = busyState[0];
        var setBusy = busyState[1];

        var messageState = useState(null);
        var message = messageState[0];
        var setMessage = messageState[1];

        function loadLog() {
            var url = root + '/messages' + (filter ? '?status=' + encodeURIComponent(filter) : '');

            apiFetch({ url: url }).then(function (response) {
                setRows(response.data || []);
                setCounts(response.counts || { queued: 0, sent: 0, failed: 0 });
            }).catch(function (failure) {
                setMessage({ tone: 'bad', text: failure.message || 'Could not load the message log.' });
            });
        }

        function loadTemplates() {
            apiFetch({ url: root + '/messages/templates' }).then(function (response) {
                setTemplates(response.data || []);
            }).catch(function (failure) {
                setMessage({ tone: 'bad', text: failure.message || 'Could not load the templates.' });
            });
        }

        useEffect(loadLog, [filter]);
        useEffect(loadTemplates, []);

        function dispatchNow() {
            setBusy(true);
            setMessage(null);
            apiFetch({ url: root + '/messages/dispatch', method: 'POST', data: {} }).then(function (response) {
                setMessage({
                    tone: response.data.failed > 0 ? 'bad' : 'good',
                    text: response.data.sent + ' sent, ' + response.data.failed + ' failed, ' +
                        response.data.remaining + ' still queued.'
                });
                loadLog();
            }).catch(function (failure) {
                setMessage({ tone: 'bad', text: failure.message || 'Could not send the queued messages.' });
            }).then(function () {
                setBusy(false);
            });
        }

        function edit(template) {
            setEditingEvent(template.event);
            setSubject(template.subject);
            setBody(template.body);
            setMessage(null);
        }

        function save() {
            setBusy(true);
            setMessage(null);

            apiFetch({
                url: root + '/messages/templates/' + editingEvent,
                method: 'PUT',
                data: { subject: subject, body: body, is_active: true }
            }).then(function () {
                setMessage({ tone: 'good', text: 'Template saved.' });
                setEditingEvent('');
                loadTemplates();
            }).catch(function (failure) {
                setMessage({ tone: 'bad', text: failure.message || 'Could not save the template.' });
            }).then(function () {
                setBusy(false);
            });
        }

        function reset() {
            setBusy(true);
            setMessage(null);

            apiFetch({
                url: root + '/messages/templates/' + editingEvent,
                method: 'PUT',
                data: { reset: true }
            }).then(function () {
                setMessage({ tone: 'good', text: 'Back to the built-in message.' });
                setEditingEvent('');
                loadTemplates();
            }).catch(function (failure) {
                setMessage({ tone: 'bad', text: failure.message || 'Could not reset the template.' });
            }).then(function () {
                setBusy(false);
            });
        }

        var current = templates.filter(function (row) { return row.event === editingEvent; })[0] || null;

        return el('div', null,
            el('h2', null, 'Outbox'),
            el('p', { className: 'sk-help' },
                'Messages are queued inside the request that caused them and sent by the hourly job. ' +
                'Sending now only takes queued messages.'),
            el('p', { className: 'sk-help' },
                el('span', { className: 'sk-pill sk-pill-warning' }, counts.queued + ' queued'),
                ' ',
                el('span', { className: 'sk-pill sk-pill-good' }, counts.sent + ' sent'),
                ' ',
                el('span', { className: 'sk-pill' + (counts.failed > 0 ? ' sk-pill-danger' : '') }, counts.failed + ' failed'),
                ' · Show: ',
                el('select', {
                    value: filter,
                    onChange: function (event) { setFilter(event.target.value); }
                },
                    el('option', { value: '' }, 'Everything'),
                    el('option', { value: 'queued' }, 'Queued'),
                    el('option', { value: 'sent' }, 'Sent'),
                    el('option', { value: 'failed' }, 'Failed')),
                ' ',
                el('button', {
                    type: 'button',
                    className: 'button button-small',
                    disabled: busy,
                    onClick: dispatchNow
                }, busy ? 'Sending…' : 'Send queued now')),

            notice(message),

            rows.length === 0
                ? el('p', { className: 'sk-empty' }, 'No messages match this filter.')
                : el('table', { className: 'widefat striped sk-table' },
                    el('thead', null, el('tr', null,
                        el('th', null, 'Queued'), el('th', null, 'Event'),
                        el('th', null, 'To'), el('th', null, 'Subject'),
                        el('th', null, 'Status'), el('th', null, 'Tries'), el('th', null, 'Problem'))),
                    el('tbody', null, rows.map(function (row) {
                        return el('tr', { key: row.id },
                            el('td', null, row.created_at),
                            el('td', null, el('code', null, row.event)),
                            el('td', null, row.recipient),
                            el('td', null, row.subject || '—',
                                row.body ? el('div', { className: 'sk-help sk-message-body' }, row.body) : null),
                            el('td', null, pill(row.status, row.status === 'sent' ? 'good' : (row.status === 'failed' ? 'danger' : 'warning'))),
                            el('td', null, row.attempts),
                            el('td', null, row.error || '—'));
                    }))
                ),

            el('h2', null, 'Templates'),
            el('p', { className: 'sk-help' },
                'Every message starts with a built-in template. Save an override to change the wording; a queued message keeps the copy it was created with.'),
            el('table', { className: 'widefat striped sk-table' },
                el('thead', null, el('tr', null,
                    el('th', null, 'Event'), el('th', null, 'Channel'),
                    el('th', null, 'Subject'), el('th', null, 'Uses'), el('th', null, ''))),
                el('tbody', null, templates.map(function (template) {
                    return el('tr', { key: template.event },
                        el('td', null, el('code', null, template.event)),
                        el('td', null, template.channel),
                        el('td', null, template.subject),
                        el('td', null, template.is_overridden
                            ? pill('custom', 'warning')
                            : pill('built-in', 'good')),
                        el('td', null, el('button', {
                            type: 'button',
                            className: 'button button-small',
                            onClick: function () { edit(template); }
                        }, 'Edit')));
                }))
            ),

            current
                ? el('div', { className: 'sk-panel sk-form' },
                    el('h2', null, 'Edit ' + current.event),
                    el('p', { className: 'sk-help' },
                        'Placeholders: ',
                        (current.placeholders || []).map(function (name) {
                            return el('code', { key: name, className: 'sk-placeholder' }, '{{' + name + '}}');
                        })),
                    field('Subject', el('input', {
                        type: 'text',
                        value: subject,
                        onChange: function (event) { setSubject(event.target.value); }
                    })),
                    field('Body', el('textarea', {
                        rows: 8,
                        value: body,
                        onChange: function (event) { setBody(event.target.value); }
                    })),
                    el('p', { className: 'sk-help' }, 'Built-in copy: ' + current.default_subject),
                    el('p', { className: 'sk-actions' },
                        el('button', {
                            type: 'button',
                            className: 'button button-primary',
                            disabled: busy,
                            onClick: save
                        }, busy ? 'Saving…' : 'Save template'),
                        current.is_overridden
                            ? el('button', {
                                type: 'button',
                                className: 'button',
                                disabled: busy,
                                onClick: reset
                            }, 'Use the built-in copy')
                            : null,
                        el('button', {
                            type: 'button',
                            className: 'button',
                            onClick: function () { setEditingEvent(''); }
                        }, 'Cancel'))
                )
                : null
        );
    }

    window.storekeeperScreens = {
        Parcels: Parcels,
        Shipping: Shipping,
        Messages: Messages
    };
})(window.wp);
