/*
 * Store Keeper POS (§15.1). Plain JavaScript on purpose: no build step, works
 * offline, and every write goes through the same REST/sync endpoints as any
 * other device.
 */
(() => {
    'use strict';

    const cfg = window.SK_POS || {};
    const screen = document.getElementById('screen');
    const badge = document.getElementById('pos-sync');
    const deviceLine = document.getElementById('pos-device');
    const tabs = document.getElementById('tabs');

    const store = {
        get(key, fallback) {
            try {
                const raw = localStorage.getItem(key);
                return raw === null ? fallback : JSON.parse(raw);
            } catch (error) {
                return fallback;
            }
        },
        set(key, value) {
            localStorage.setItem(key, JSON.stringify(value));
        },
        remove(key) {
            localStorage.removeItem(key);
        }
    };

    const state = {
        device: store.get('sk_pos_device', null),
        token: store.get('sk_pos_token', null),
        cashier: store.get('sk_pos_cashier', null),
        cursor: store.get('sk_pos_cursor', null),
        lastSync: store.get('sk_pos_last_sync', null),
        outbox: store.get('sk_pos_outbox', []),
        rejected: store.get('sk_pos_rejected', []),
        catalog: store.get('sk_pos_catalog', []),
        customers: store.get('sk_pos_customers', []),
        prices: store.get('sk_pos_prices', []),
        settings: store.get('sk_pos_settings', {}),
        packUnits: store.get('sk_pos_pack_units', []),
        groups: store.get('sk_pos_groups', []),
        cart: [],
        customerId: null,
        tab: 'sell',
        search: '',
        busy: false,
        notice: null
    };

    const money = (kobo) => (Number(kobo || 0) / 100).toLocaleString('en-NG', {
        style: 'currency',
        currency: cfg.currency || 'NGN',
        maximumFractionDigits: 2
    });

    const esc = (value) => String(value === null || value === undefined ? '' : value)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

    const uuid = () => (window.crypto && crypto.randomUUID
        ? crypto.randomUUID()
        : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
            const r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        }));

    async function api(path, options) {
        const opts = options || {};
        const headers = { 'Content-Type': 'application/json' };
        if (opts.staff) {
            headers['X-WP-Nonce'] = cfg.nonce;
        } else if (state.token) {
            headers.Device = state.token;
        }

        const response = await fetch((cfg.restUrl || '') + path, {
            method: opts.method || 'GET',
            headers,
            credentials: 'same-origin',
            body: opts.body === undefined ? undefined : JSON.stringify(opts.body)
        });

        let payload = {};
        try {
            payload = await response.json();
        } catch (error) {
            payload = {};
        }

        if (!response.ok) {
            const error = new Error(payload.message || ('Request failed (' + response.status + ')'));
            error.code = payload.code || 'http_' + response.status;
            error.status = response.status;
            throw error;
        }

        return payload.data !== undefined ? payload.data : payload;
    }

    function save() {
        store.set('sk_pos_device', state.device);
        store.set('sk_pos_token', state.token);
        store.set('sk_pos_cashier', state.cashier);
        store.set('sk_pos_cursor', state.cursor);
        store.set('sk_pos_last_sync', state.lastSync);
        store.set('sk_pos_outbox', state.outbox);
        store.set('sk_pos_rejected', state.rejected);
        store.set('sk_pos_catalog', state.catalog);
        store.set('sk_pos_customers', state.customers);
        store.set('sk_pos_prices', state.prices);
        store.set('sk_pos_settings', state.settings);
        store.set('sk_pos_pack_units', state.packUnits);
        store.set('sk_pos_groups', state.groups);
    }

    function notice(text, tone) {
        state.notice = text ? { text, tone: tone || 'info' } : null;
    }

    function minutesSince(iso) {
        if (!iso) {
            return null;
        }
        const then = new Date(iso.replace(' ', 'T') + 'Z').getTime();
        return Math.max(0, Math.round((Date.now() - then) / 60000));
    }

    function syncBadge() {
        const pending = state.outbox.length + state.rejected.length;
        const age = minutesSince(state.lastSync);
        const staleAfter = Number(state.settings.stale_after_minutes || 10);

        if (!navigator.onLine) {
            badge.textContent = 'Offline · ' + pending + ' queued';
            badge.className = 'pos-badge is-bad';
        } else if (age === null) {
            badge.textContent = 'Not synced yet';
            badge.className = 'pos-badge';
        } else if (age >= staleAfter * 3) {
            badge.textContent = 'Last sync ' + age + ' min ago · ' + pending + ' queued';
            badge.className = 'pos-badge is-bad';
        } else if (age >= staleAfter) {
            badge.textContent = 'Last sync ' + age + ' min ago · ' + pending + ' queued';
            badge.className = 'pos-badge is-warn';
        } else {
            badge.textContent = 'Synced ' + (age < 1 ? 'just now' : age + ' min ago') + (pending ? ' · ' + pending + ' queued' : '');
            badge.className = 'pos-badge is-ok';
        }

        deviceLine.textContent = state.device
            ? ' · ' + esc(state.device.name) + (state.settings.location_name ? ' · ' + esc(state.settings.location_name) : '')
            : '';
    }

    function total() {
        return state.cart.reduce((sum, line) => sum + line.unit_price_kobo * line.qty, 0);
    }

    function cartCount() {
        return state.cart.reduce((sum, line) => sum + line.qty, 0);
    }

    function basePriceFor(variantId) {
        const variant = state.catalog.find((row) => Number(row.variant_id) === Number(variantId));
        return variant && variant.price_kobo !== null ? Number(variant.price_kobo) : 0;
    }

    function priceFor(variantId) {
        let price = basePriceFor(variantId);
        const groupId = state.customerId
            ? (state.customers.find((c) => Number(c.id) === Number(state.customerId)) || {}).group_id
            : null;

        const group = state.groups.find((g) => Number(g.id) === Number(groupId));
        if (group) {
            const rows = state.prices.filter((row) => row.price_list_id === group.price_list_id
                && Number(row.variant_id) === Number(variantId) && Number(row.min_qty) <= 1 && !row.pack_unit_id);
            if (rows.length) {
                price = Math.min.apply(null, rows.map((row) => Number(row.amount_kobo)));
            }
        }
        return price;
    }

    /* ---------------------------------------------------------------- views */

    function header() {
        syncBadge();
        tabs.hidden = !(state.token && state.cashier);
    }

    function renderRegister() {
        screen.innerHTML = `
            <section class="pos-card">
                <h1>Register this device</h1>
                <p class="pos-muted">A manager signs in with WordPress once, names the device and picks the
                    location it sells from. The device token stays on this device.</p>
                ${noticeHtml()}
                ${cfg.loggedIn ? `
                <label>Device name<input id="reg-name" value="${esc(cfg.storeName)} counter"></label>
                <label>Location<select id="reg-location"><option value="">Loading…</option></select></label>
                <button class="pos-primary" id="reg-go">Register device</button>
                ` : `
                <p class="pos-empty">Sign in to WordPress as a manager, then reload this page.</p>
                <a class="pos-primary pos-link" href="${esc(cfg.adminUrl || '/wp-admin/')}">Open wp-admin</a>
                `}
            </section>`;

        if (cfg.loggedIn) {
            loadLocations().then((locations) => {
                const select = document.getElementById('reg-location');
                select.innerHTML = locations.map((location) =>
                    `<option value="${Number(location.id)}">${esc(location.name)}</option>`).join('');
                if (!locations.length) {
                    select.innerHTML = '<option value="">No active location — create one in the back office</option>';
                }
            }).catch((error) => notice(error.message, 'bad'));

            document.getElementById('reg-go').addEventListener('click', registerDevice);
        }
    }

    async function loadLocations() {
        const rows = await api('/locations', { staff: true });
        return (rows || []).filter((row) => Number(row.is_active) === 1);
    }

    async function registerDevice() {
        const name = document.getElementById('reg-name').value.trim();
        const locationId = Number(document.getElementById('reg-location').value);
        if (!name || !locationId) {
            notice('Give the device a name and choose a location.', 'bad');
            return;
        }

        try {
            const result = await api('/pos/devices', {
                method: 'POST',
                staff: true,
                body: { name, location_id: locationId, app_version: 'pos-1' }
            });
            state.device = result;
            state.token = result.token;
            save();
            await pull();
            render();
        } catch (error) {
            notice(error.message, 'bad');
            render();
        }
    }

    function renderSignin() {
        screen.innerHTML = `
            <section class="pos-card">
                <h1>Who is on the till?</h1>
                <p class="pos-muted">${esc(state.device ? state.device.name : '')} · pick your name, then your PIN.</p>
                ${noticeHtml()}
                <div id="staff-list" class="pos-staff"><p class="pos-muted">Loading staff…</p></div>
                <label>PIN<input id="pin" type="password" inputmode="numeric" autocomplete="off" maxlength="6"></label>
                <button class="pos-primary" id="pin-go">Sign in</button>
                <button class="pos-ghost" id="forget">Forget this device</button>
            </section>`;

        let selected = null;

        api('/pos/staff').then((rows) => {
            const list = document.getElementById('staff-list');
            if (!rows || !rows.length) {
                list.innerHTML = '<p class="pos-empty">No staff with store roles yet. Create them in wp-admin.</p>';
                return;
            }
            list.innerHTML = rows.map((person) => `
                <button type="button" class="pos-person" data-id="${Number(person.user_id)}">
                    ${esc(person.name)}${person.has_pin ? '' : ' <span class="pos-muted">(no PIN set)</span>'}
                </button>`).join('');
            list.querySelectorAll('.pos-person').forEach((button) => {
                button.addEventListener('click', () => {
                    selected = Number(button.dataset.id);
                    list.querySelectorAll('.pos-person').forEach((b) => b.classList.remove('is-active'));
                    button.classList.add('is-active');
                    document.getElementById('pin').focus();
                });
            });
        }).catch((error) => {
            document.getElementById('staff-list').innerHTML = `<p class="pos-empty">${esc(error.message)}</p>`;
        });

        document.getElementById('pin-go').addEventListener('click', async () => {
            if (!selected) {
                notice('Pick your name first.', 'bad');
                render();
                return;
            }
            try {
                const result = await api('/pos/cashier/verify', {
                    method: 'POST',
                    body: { user_id: selected, pin: document.getElementById('pin').value }
                });
                const person = (await api('/pos/staff')).find((row) => Number(row.user_id) === selected);
                state.cashier = { user_id: selected, name: person ? person.name : 'Cashier' };
                state.notice = null;
                save();
                await Promise.allSettled([pull(), pushOutbox()]);
                render();
            } catch (error) {
                notice(error.code === 'invalid_device'
                    ? 'This device was revoked. Register it again.'
                    : 'PIN not accepted. Try again.', 'bad');
                render();
            }
        });

        document.getElementById('forget').addEventListener('click', () => {
            if (!confirm('Remove this device token from this browser?')) {
                return;
            }
            state.device = null;
            state.token = null;
            state.cashier = null;
            state.cursor = null;
            save();
            render();
        });
    }

    function noticeHtml() {
        return state.notice ? `<p class="pos-notice is-${esc(state.notice.tone)}">${esc(state.notice.text)}</p>` : '';
    }

    function renderSell() {
        const term = state.search.trim().toLowerCase();
        const rows = state.catalog.filter((row) => !term
            || String(row.product_name).toLowerCase().includes(term)
            || String(row.sku).toLowerCase().includes(term)
            || String(row.title).toLowerCase().includes(term)).slice(0, 60);

        screen.innerHTML = `
            <div class="pos-sell">
                <section class="pos-products">
                    ${noticeHtml()}
                    <input id="search" class="pos-search" placeholder="Search or scan a SKU…" value="${esc(state.search)}">
                    <div class="pos-grid">
                        ${rows.map((row) => `
                            <button type="button" class="pos-tile" data-variant="${Number(row.variant_id)}">
                                <span class="pos-tile-name">${esc(row.product_name)}</span>
                                <span class="pos-tile-title">${esc(row.title)}</span>
                                <span class="pos-tile-price">${money(priceFor(row.variant_id))}</span>
                            </button>`).join('') || '<p class="pos-empty">No products match. Pull to refresh.</p>'}
                    </div>
                </section>
                <aside class="pos-cart">
                    <div class="pos-cart-head">
                        <strong>Sale</strong>
                        <span class="pos-muted">${cartCount()} item${cartCount() === 1 ? '' : 's'}</span>
                    </div>
                    <label class="pos-small">Customer
                        <select id="customer">
                            <option value="">Walk-in</option>
                            ${state.customers.map((customer) => `
                                <option value="${Number(customer.id)}" ${Number(state.customerId) === Number(customer.id) ? 'selected' : ''}>
                                    ${esc(customer.name)} · ${esc(customer.phone_e164)}
                                </option>`).join('')}
                        </select>
                    </label>
                    <div class="pos-lines">
                        ${state.cart.map((line, index) => `
                            <div class="pos-line">
                                <div>
                                    <strong>${esc(line.product_name)}</strong>
                                    <span class="pos-muted">${esc(line.title)}</span>
                                </div>
                                <div class="pos-line-right">
                                    <div class="pos-qty">
                                        <button type="button" data-dec="${index}">−</button>
                                        <span>${line.qty}</span>
                                        <button type="button" data-inc="${index}">+</button>
                                    </div>
                                    <span>${money(line.unit_price_kobo * line.qty)}</span>
                                    <button type="button" class="pos-x" data-del="${index}">×</button>
                                </div>
                            </div>`).join('') || '<p class="pos-empty">Tap products to add them.</p>'}
                    </div>
                    <div class="pos-total">
                        <span>Total</span>
                        <strong>${money(total())}</strong>
                    </div>
                    <label class="pos-small">Payment
                        <select id="method">
                            <option value="cash">Cash</option>
                            <option value="pos_terminal">POS terminal</option>
                            <option value="bank_transfer">Transfer</option>
                        </select>
                    </label>
                    <button class="pos-primary" id="complete" ${state.cart.length ? '' : 'disabled'}>
                        ${navigator.onLine ? 'Take payment' : 'Queue sale (offline)'}
                    </button>
                    <button class="pos-ghost" id="hold" ${navigator.onLine && state.cart.length ? '' : 'disabled'}>
                        Park sale${navigator.onLine ? '' : ' (needs connection)'}
                    </button>
                </aside>
            </div>`;

        const search = document.getElementById('search');
        search.addEventListener('input', () => {
            state.search = search.value;
            const position = search.selectionStart;
            renderSell();
            const next = document.getElementById('search');
            next.focus();
            next.setSelectionRange(position, position);
        });

        document.querySelectorAll('.pos-tile').forEach((tile) => {
            tile.addEventListener('click', () => addToCart(Number(tile.dataset.variant)));
        });
        document.querySelectorAll('[data-inc]').forEach((button) => button.addEventListener('click', () => {
            state.cart[Number(button.dataset.inc)].qty += 1;
            renderSell();
        }));
        document.querySelectorAll('[data-dec]').forEach((button) => button.addEventListener('click', () => {
            const index = Number(button.dataset.dec);
            state.cart[index].qty -= 1;
            if (state.cart[index].qty <= 0) {
                state.cart.splice(index, 1);
            }
            renderSell();
        }));
        document.querySelectorAll('[data-del]').forEach((button) => button.addEventListener('click', () => {
            state.cart.splice(Number(button.dataset.del), 1);
            renderSell();
        }));

        const customer = document.getElementById('customer');
        customer.addEventListener('change', () => {
            state.customerId = customer.value ? Number(customer.value) : null;
            state.cart = state.cart.map((line) => ({
                ...line,
                unit_price_kobo: line.manual ? line.unit_price_kobo : priceFor(line.variant_id)
            }));
            renderSell();
        });

        document.getElementById('complete').addEventListener('click', completeSale);
        document.getElementById('hold').addEventListener('click', parkSale);
    }

    function addToCart(variantId) {
        const variant = state.catalog.find((row) => Number(row.variant_id) === Number(variantId));
        if (!variant) {
            return;
        }
        const existing = state.cart.find((line) => Number(line.variant_id) === Number(variantId));
        if (existing) {
            existing.qty += 1;
        } else {
            state.cart.push({
                variant_id: Number(variantId),
                variant_uuid: variant.variant_uuid,
                product_name: variant.product_name,
                title: variant.title,
                qty: 1,
                unit_price_kobo: priceFor(variantId),
                manual: false
            });
        }
        renderSell();
    }

    function saleOperation() {
        return {
            op: 'counter_sale',
            client_uuid: uuid(),
            client_created_at: new Date().toISOString(),
            cashier_user_id: state.cashier.user_id,
            customer_id: state.customerId,
            discount_kobo: 0,
            lines: state.cart.map((line) => ({
                variant_uuid: line.variant_uuid,
                qty: line.qty,
                unit_price_kobo: line.unit_price_kobo
            })),
            payments: [{ method: document.getElementById('method').value, amount_kobo: total() }]
        };
    }

    async function completeSale() {
        if (!state.cart.length || state.busy) {
            return;
        }
        state.busy = true;
        const lines = state.cart.slice();
        const amount = total();
        const operation = saleOperation();

        try {
            if (navigator.onLine) {
                const result = await api('/sync/push', {
                    method: 'POST',
                    body: { batch_uuid: uuid(), operations: [operation] }
                });
                const first = (result.results || [])[0] || {};
                if (first.status === 'rejected') {
                    throw new Error(first.error || 'The sale was rejected.');
                }
                receipt(lines, amount, first.order_number, first.warnings || []);
                state.cart = [];
                state.customerId = null;
                await pull();
            } else {
                state.outbox.push(operation);
                save();
                receipt(lines, amount, 'queued offline', ['The sale will sync when the connection returns.']);
                state.cart = [];
                state.customerId = null;
            }
            notice(null);
        } catch (error) {
            notice(error.message, 'bad');
            renderSell();
        } finally {
            state.busy = false;
        }
    }

    async function parkSale() {
        if (!state.cart.length) {
            return;
        }
        try {
            await api('/pos/held', {
                method: 'POST',
                body: {
                    client_uuid: uuid(),
                    cashier_user_id: state.cashier.user_id,
                    customer_id: state.customerId,
                    lines: state.cart.map((line) => ({
                        variant_uuid: line.variant_uuid,
                        qty: line.qty,
                        unit_price_kobo: line.unit_price_kobo
                    }))
                }
            });
            state.cart = [];
            state.customerId = null;
            notice('Sale parked. Find it under Held.', 'ok');
            switchTab('held');
        } catch (error) {
            notice(error.message, 'bad');
            renderSell();
        }
    }

    function receipt(lines, amount, orderNumber, warnings) {
        screen.innerHTML = `
            <section class="pos-card pos-receipt">
                <h1>Sale recorded</h1>
                <p class="pos-muted">${esc(cfg.storeName)} · ${new Date().toLocaleString()}</p>
                <p class="pos-receipt-number">${esc(orderNumber || '')}</p>
                <ul class="pos-receipt-lines">
                    ${lines.map((line) => `<li><span>${line.qty} × ${esc(line.product_name)}</span><span>${money(line.qty * line.unit_price_kobo)}</span></li>`).join('')}
                </ul>
                <div class="pos-total"><span>Paid</span><strong>${money(amount)}</strong></div>
                <p class="pos-muted">Cashier: ${esc(state.cashier ? state.cashier.name : '')}</p>
                ${(warnings || []).map((warning) => `<p class="pos-notice is-warn">${esc(warning)}</p>`).join('')}
                <button class="pos-primary" id="next-sale">Next sale</button>
                <button class="pos-ghost" onclick="window.print()">Print receipt</button>
            </section>`;
        document.getElementById('next-sale').addEventListener('click', () => switchTab('sell'));
    }

    function renderHeld() {
        screen.innerHTML = `
            <section class="pos-card">
                <h1>Held sales</h1>
                ${noticeHtml()}
                <div id="held-list"><p class="pos-muted">Loading…</p></div>
            </section>`;

        api('/pos/held').then((rows) => {
            const list = document.getElementById('held-list');
            if (!rows || !rows.length) {
                list.innerHTML = '<p class="pos-empty">No parked sales on this device.</p>';
                return;
            }
            list.innerHTML = rows.map((order) => `
                <div class="pos-held">
                    <div>
                        <strong>${esc(order.number)}</strong>
                        <span class="pos-muted">${(order.items || []).length} line(s) · ${money(order.total_kobo)}</span>
                    </div>
                    <div>
                        <button class="pos-ghost" data-resume="${Number(order.id)}">Resume</button>
                        <button class="pos-x" data-drop="${Number(order.id)}">×</button>
                    </div>
                </div>`).join('');

            list.querySelectorAll('[data-resume]').forEach((button) => button.addEventListener('click', async () => {
                const order = rows.find((row) => Number(row.id) === Number(button.dataset.resume));
                state.cart = (order.items || []).map((item) => ({
                    variant_id: Number(item.variant_id),
                    variant_uuid: (state.catalog.find((row) => Number(row.variant_id) === Number(item.variant_id)) || {}).variant_uuid,
                    product_name: item.product_name,
                    title: item.variant_title,
                    qty: Number(item.qty),
                    unit_price_kobo: Number(item.unit_price_kobo),
                    manual: true
                }));
                state.customerId = order.customer_id ? Number(order.customer_id) : null;
                await api('/pos/held/' + Number(order.id), { method: 'DELETE' });
                switchTab('sell');
            }));

            list.querySelectorAll('[data-drop]').forEach((button) => button.addEventListener('click', async () => {
                if (!confirm('Discard this parked sale?')) {
                    return;
                }
                try {
                    await api('/pos/held/' + Number(button.dataset.drop), { method: 'DELETE' });
                    renderHeld();
                } catch (error) {
                    notice(error.message, 'bad');
                    renderHeld();
                }
            }));
        }).catch((error) => {
            document.getElementById('held-list').innerHTML = `<p class="pos-empty">${esc(error.message)}</p>`;
        });
    }

    function renderCashup() {
        screen.innerHTML = `
            <section class="pos-card">
                <h1>Cash-up</h1>
                <p class="pos-muted">Expected cash is the opening float plus cash taken by
                    ${esc(state.cashier ? state.cashier.name : 'you')} on this device today, less cash refunds.</p>
                ${noticeHtml()}
                <label>Opening float (naira)<input id="float" type="number" min="0" step="1" value="0"></label>
                <label>Counted cash (naira)<input id="counted" type="number" min="0" step="1" value="0"></label>
                <label>Note<input id="cashup-note" maxlength="200"></label>
                <button class="pos-primary" id="cashup-go">Record cash-up</button>
                <div id="cashup-result"></div>
            </section>`;

        document.getElementById('cashup-go').addEventListener('click', async () => {
            try {
                const result = await api('/pos/cash-ups', {
                    method: 'POST',
                    body: {
                        cashier_user_id: state.cashier.user_id,
                        opening_float_kobo: Math.round(Number(document.getElementById('float').value || 0) * 100),
                        counted_cash_kobo: Math.round(Number(document.getElementById('counted').value || 0) * 100),
                        note: document.getElementById('cashup-note').value
                    }
                });
                document.getElementById('cashup-result').innerHTML = `
                    <div class="pos-total"><span>Expected</span><strong>${money(result.expected_cash_kobo)}</strong></div>
                    <div class="pos-total"><span>Difference</span><strong class="${Number(result.difference_kobo) === 0 ? '' : 'is-bad'}">${money(result.difference_kobo)}</strong></div>`;
                notice('Cash-up recorded.', 'ok');
            } catch (error) {
                notice(error.message, 'bad');
                renderCashup();
            }
        });
    }

    function renderSales() {
        screen.innerHTML = `
            <section class="pos-card">
                <h1>Sync</h1>
                ${noticeHtml()}
                <div class="pos-total"><span>Waiting to send</span><strong>${state.outbox.length}</strong></div>
                <div class="pos-total"><span>Rejected (needs a manager)</span><strong>${state.rejected.length}</strong></div>
                <p class="pos-muted">Last pull: ${state.lastSync ? esc(new Date(state.lastSync.replace(' ', 'T') + 'Z').toLocaleString()) : 'never'}</p>
                <button class="pos-primary" id="sync-now">Sync now</button>
                <button class="pos-ghost" id="push-now">Send queued sales</button>
                <button class="pos-ghost" id="pin-manager">Cashier PINs (manager)</button>
                ${state.rejected.length ? `
                <h2>Rejected</h2>
                <pre class="pos-rejected">${esc(JSON.stringify(state.rejected, null, 2))}</pre>
                <button class="pos-ghost" id="clear-rejected">Clear rejected</button>` : ''}
            </section>`;

        document.getElementById('sync-now').addEventListener('click', async () => {
            try {
                await Promise.all([pull(), pushOutbox()]);
                notice('Synced.', 'ok');
            } catch (error) {
                notice(error.message, 'bad');
            }
            renderSales();
        });
        document.getElementById('push-now').addEventListener('click', async () => {
            try {
                await pushOutbox();
                notice('Queued sales sent.', 'ok');
            } catch (error) {
                notice(error.message, 'bad');
            }
            renderSales();
        });
        document.getElementById('pin-manager').addEventListener('click', openPinManager);
        if (document.getElementById('clear-rejected')) {
            document.getElementById('clear-rejected').addEventListener('click', () => {
                state.rejected = [];
                save();
                renderSales();
            });
        }
    }

    async function openPinManager() {
        try {
            const rows = await api('/pos/staff');
            screen.innerHTML = `
                <section class="pos-card">
                    <h1>Cashier PINs</h1>
                    <p class="pos-muted">A manager sets a 4–6 digit PIN for each person who sells.</p>
                    ${noticeHtml()}
                    ${rows.map((person) => `
                        <div class="pos-held">
                            <div><strong>${esc(person.name)}</strong>
                                <span class="pos-muted">${person.has_pin ? 'PIN set' : 'No PIN'}</span></div>
                            <div>
                                <input class="pos-pin" data-user="${Number(person.user_id)}" inputmode="numeric" maxlength="6" placeholder="PIN">
                                <button class="pos-ghost" data-save="${Number(person.user_id)}">Save</button>
                            </div>
                        </div>`).join('')}
                    <button class="pos-ghost" id="back-sales">Back</button>
                </section>`;

            document.querySelectorAll('[data-save]').forEach((button) => button.addEventListener('click', async () => {
                const input = document.querySelector('.pos-pin[data-user="' + button.dataset.save + '"]');
                try {
                    await api('/pos/pins', {
                        method: 'POST',
                        staff: true,
                        body: { user_id: Number(button.dataset.save), pin: input.value }
                    });
                    notice('PIN saved.', 'ok');
                    openPinManager();
                } catch (error) {
                    notice(error.code === 'rest_forbidden'
                        ? 'Sign in to wp-admin as the owner or manager to set PINs.'
                        : error.message, 'bad');
                    openPinManager();
                }
            }));
            document.getElementById('back-sales').addEventListener('click', renderSales);
        } catch (error) {
            notice(error.message, 'bad');
            renderSales();
        }
    }

    /* ---------------------------------------------------------------- sync */

    async function pull() {
        if (!state.token || !navigator.onLine) {
            syncBadge();
            return;
        }
        const data = await api('/sync/pull' + (state.cursor ? '?cursor=' + encodeURIComponent(state.cursor) : ''));
        state.cursor = data.cursor;
        state.settings = data.settings || state.settings;
        state.lastSync = data.server_time || new Date().toISOString();

        if (data.full_resync_required || !state.catalog.length) {
            state.catalog = data.catalog || [];
            state.prices = data.prices || [];
            state.packUnits = data.pack_units || [];
            state.groups = data.groups || [];
            state.customers = data.customer_changes || [];
        } else if (data.customer_changes && data.customer_changes.length) {
            const byId = new Map(state.customers.map((c) => [Number(c.id), c]));
            data.customer_changes.forEach((c) => byId.set(Number(c.id), c));
            state.customers = Array.from(byId.values());
        }

        if (data.stock_levels) {
            const levels = new Map(data.stock_levels.map((row) => [Number(row.variant_id), row]));
            state.catalog = state.catalog.map((row) => ({
                ...row,
                available: levels.has(Number(row.variant_id))
                    ? Number(levels.get(Number(row.variant_id)).on_hand) - Number(levels.get(Number(row.variant_id)).reserved)
                    : row.available
            }));
        }

        save();
        syncBadge();
    }

    async function pushOutbox() {
        if (!state.outbox.length || !state.token || !navigator.onLine) {
            return;
        }
        const operations = state.outbox.slice();
        const result = await api('/sync/push', {
            method: 'POST',
            body: { batch_uuid: uuid(), operations }
        });

        const statuses = new Map((result.results || []).map((row) => [row.client_uuid, row]));
        const keep = [];
        operations.forEach((operation) => {
            const row = statuses.get(operation.client_uuid);
            if (!row) {
                keep.push(operation);
            } else if (row.status === 'rejected') {
                state.rejected.push({ operation, error: row.error });
            }
        });

        state.outbox = keep;
        state.lastSync = new Date().toISOString();
        save();
        syncBadge();
    }

    /* ---------------------------------------------------------------- shell */

    function switchTab(tab) {
        state.tab = tab;
        document.querySelectorAll('#tabs button').forEach((button) => {
            button.classList.toggle('is-active', button.dataset.tab === tab);
        });
        render();
    }

    function render() {
        header();
        state.notice = null;

        if (!state.device || !state.token) {
            renderRegister();
            return;
        }

        if (!state.cashier) {
            renderSignin();
            return;
        }

        if (state.tab === 'held') {
            renderHeld();
        } else if (state.tab === 'cashup') {
            renderCashup();
        } else if (state.tab === 'sales') {
            renderSales();
        } else {
            renderSell();
        }
    }

    document.getElementById('tabs').addEventListener('click', (event) => {
        const button = event.target.closest('button[data-tab]');
        if (button) {
            switchTab(button.dataset.tab);
        }
    });

    document.getElementById('pos-menu').addEventListener('click', () => {
        if (!state.cashier) {
            return;
        }
        switchTab('sales');
    });

    window.addEventListener('online', () => {
        syncBadge();
        Promise.allSettled([pull(), pushOutbox()]).then(render);
    });
    window.addEventListener('offline', syncBadge);

    setInterval(() => {
        if (!state.device) {
            return;
        }
        pushOutbox().catch(() => {});
        pull().catch(() => {});
        syncBadge();
    }, 60000);

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/pos/sw.js', { scope: '/pos/' }).catch(() => {});
    }

    render();
    if (state.device && state.token) {
        Promise.allSettled([pull(), pushOutbox()]).then(render);
    }
})();
