@extends('layouts.app')

@section('title', 'Invoice Generator')
@section('section', 'Invoices')
@section('heading', 'Invoice Generator')

@section('content')
    <style>
        .invoice-builder {
            max-width: 1480px;
            margin: 0 auto;
            padding: clamp(1rem, 2vw, 2rem);
        }

        .invoice-shell {
            display: grid;
            grid-template-columns: minmax(320px, 430px) minmax(0, 1fr);
            gap: 1.5rem;
            align-items: start;
        }

        .invoice-panel,
        .invoice-paper {
            background: #fffdf8;
            border: 1px solid #ded5c7;
            border-radius: 28px;
            box-shadow: 0 18px 55px rgba(32, 28, 22, 0.08);
        }

        .invoice-panel {
            position: sticky;
            top: 1rem;
            padding: 1.2rem;
        }

        .invoice-panel h2,
        .invoice-card-title {
            margin: 0 0 0.9rem;
            font-size: 0.82rem;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 0.13em;
            color: #6e675c;
        }

        .invoice-form-grid {
            display: grid;
            gap: 0.8rem;
        }

        .invoice-field {
            display: grid;
            gap: 0.35rem;
        }

        .invoice-field span {
            font-size: 0.75rem;
            font-weight: 900;
            color: #4f5b55;
        }

        .invoice-field input,
        .invoice-field textarea,
        .invoice-field select {
            width: 100%;
            min-height: 2.85rem;
            padding: 0.75rem 0.85rem;
            border: 1px solid #d7cdbc;
            border-radius: 14px;
            background: #fffaf1;
            color: #17211d;
            outline: none;
        }

        .invoice-field textarea {
            min-height: 5.8rem;
            resize: vertical;
        }

        .invoice-field input:focus,
        .invoice-field textarea:focus,
        .invoice-field select:focus {
            border-color: #0b7b68;
            box-shadow: 0 0 0 4px rgba(11, 123, 104, 0.12);
        }

        .invoice-actions {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.7rem;
            margin-top: 1rem;
        }

        .invoice-btn {
            min-height: 3rem;
            border: 1px solid #0b7b68;
            border-radius: 999px;
            background: #0b7b68;
            color: white;
            font-weight: 900;
            cursor: pointer;
        }

        .invoice-btn.is-secondary {
            background: #eef6f3;
            color: #0b7b68;
        }

        .invoice-btn.is-danger {
            border-color: #d6b7aa;
            background: #fff3ee;
            color: #9b402e;
        }

        .invoice-lines {
            margin-top: 1rem;
            display: grid;
            gap: 0.75rem;
        }

        .invoice-line-editor {
            display: grid;
            grid-template-columns: minmax(0, 1.5fr) 72px 98px 38px;
            gap: 0.45rem;
            align-items: end;
            padding: 0.75rem;
            border-radius: 18px;
            background: #f6f1e8;
        }

        .invoice-line-editor button {
            min-height: 2.85rem;
            border: 0;
            border-radius: 12px;
            background: #ffe8df;
            color: #9b402e;
            font-weight: 900;
            cursor: pointer;
        }

        .invoice-paper {
            padding: clamp(1.4rem, 3vw, 3rem);
            min-height: 1040px;
            color: #1c1c1a;
        }

        .invoice-paper-header {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 2rem;
            align-items: start;
            padding-bottom: 1.6rem;
            border-bottom: 2px solid #1c1c1a;
        }

        .invoice-logo {
            max-width: 270px;
            height: auto;
            object-fit: contain;
        }

        .invoice-title {
            text-align: right;
        }

        .invoice-title h1 {
            margin: 0;
            font-size: clamp(2.8rem, 7vw, 5rem);
            line-height: 0.9;
            font-weight: 900;
            letter-spacing: -0.06em;
        }

        .invoice-title p {
            margin-top: 0.8rem;
            font-weight: 800;
            color: #6a6258;
        }

        .invoice-meta-grid {
            display: grid;
            grid-template-columns: 1.1fr 0.9fr;
            gap: 2rem;
            margin-top: 2rem;
        }

        .invoice-company,
        .invoice-customer {
            line-height: 1.55;
        }

        .invoice-company strong,
        .invoice-customer strong {
            display: block;
            margin-bottom: 0.4rem;
            font-size: 1.1rem;
        }

        .invoice-details {
            display: grid;
            gap: 0.55rem;
            padding: 1rem;
            border-radius: 18px;
            background: #f6f1e8;
        }

        .invoice-detail-row,
        .invoice-total-row {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
        }

        .invoice-detail-row span:first-child,
        .invoice-total-row span:first-child {
            color: #6a6258;
            font-weight: 800;
        }

        .invoice-table {
            width: 100%;
            margin-top: 2.2rem;
            border-collapse: collapse;
        }

        .invoice-table th {
            padding: 0.85rem 0.7rem;
            border-bottom: 2px solid #1c1c1a;
            text-align: left;
            font-size: 0.75rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: #6a6258;
        }

        .invoice-table td {
            padding: 1rem 0.7rem;
            border-bottom: 1px solid #e3dacb;
            vertical-align: top;
        }

        .invoice-table .is-number,
        .invoice-table th.is-number {
            text-align: right;
        }

        .invoice-totals {
            width: min(100%, 360px);
            margin: 2rem 0 0 auto;
            display: grid;
            gap: 0.7rem;
        }

        .invoice-total-row {
            padding-bottom: 0.7rem;
            border-bottom: 1px solid #e3dacb;
            font-weight: 900;
        }

        .invoice-total-row.is-grand {
            margin-top: 0.3rem;
            padding: 1rem;
            border: 0;
            border-radius: 18px;
            background: #1c1c1a;
            color: #fffdf8;
            font-size: 1.25rem;
        }

        .invoice-total-row.is-grand span:first-child {
            color: #d9d1c4;
        }

        .invoice-footer {
            margin-top: 3rem;
            padding-top: 1.2rem;
            border-top: 1px solid #e3dacb;
            display: grid;
            gap: 0.55rem;
            color: #6a6258;
            font-size: 0.9rem;
        }

        @media (max-width: 1100px) {
            .invoice-shell {
                grid-template-columns: 1fr;
            }

            .invoice-panel {
                position: static;
            }
        }

        @media (max-width: 680px) {
            .invoice-builder {
                padding: 0.75rem;
            }

            .invoice-panel,
            .invoice-paper {
                border-radius: 20px;
            }

            .invoice-line-editor {
                grid-template-columns: 1fr 70px 94px 38px;
            }

            .invoice-paper-header,
            .invoice-meta-grid {
                grid-template-columns: 1fr;
            }

            .invoice-title {
                text-align: left;
            }

            .invoice-logo {
                max-width: 210px;
            }
        }

        @media print {
            @page {
                size: A4;
                margin: 12mm;
            }

            body {
                background: white !important;
            }

            .sidebar,
            .topnav-bar,
            .invoice-panel {
                display: none !important;
            }

            .app-main {
                margin: 0 !important;
            }

            .invoice-builder {
                max-width: none;
                padding: 0;
            }

            .invoice-shell {
                display: block;
            }

            .invoice-paper {
                min-height: 0;
                padding: 0;
                border: 0;
                border-radius: 0;
                box-shadow: none;
            }
        }
    </style>

    <div class="invoice-builder" data-invoice-builder data-pdf-url="{{ route('invoice-generator.pdf') }}">
        <div class="invoice-shell">
            <aside class="invoice-panel">
                <h2>Invoice details</h2>
                <div class="invoice-form-grid">
                    <label class="invoice-field">
                        <span>Invoice number</span>
                        <input data-invoice-input="number" value="LHC-{{ now()->format('Ymd-His') }}">
                    </label>
                    <label class="invoice-field">
                        <span>Invoice date</span>
                        <input type="date" data-invoice-input="date" value="{{ now()->format('Y-m-d') }}">
                    </label>
                    <label class="invoice-field">
                        <span>Due date</span>
                        <input type="date" data-invoice-input="dueDate" value="{{ now()->addDays(7)->format('Y-m-d') }}">
                    </label>
                    <label class="invoice-field">
                        <span>Customer name</span>
                        <input data-invoice-input="customerName" placeholder="Customer / company name">
                    </label>
                    <label class="invoice-field">
                        <span>Customer address</span>
                        <textarea data-invoice-input="customerAddress" placeholder="Customer address"></textarea>
                    </label>
                    <label class="invoice-field">
                        <span>Customer contact</span>
                        <input data-invoice-input="customerContact" placeholder="Phone or email">
                    </label>
                    <label class="invoice-field">
                        <span>VAT rate (%)</span>
                        <input type="number" min="0" step="0.01" data-invoice-input="vatRate" value="0">
                    </label>
                    <label class="invoice-field">
                        <span>Payment / notes</span>
                        <textarea data-invoice-input="notes">Thank you for your business.</textarea>
                    </label>
                </div>

                <div class="invoice-lines" data-invoice-lines></div>

                <div class="invoice-actions">
                    <button type="button" class="invoice-btn is-secondary" data-invoice-action="load-prepared">Load handwritten draft</button>
                    <button type="button" class="invoice-btn is-secondary" data-invoice-action="add-line">Add product</button>
                    <button type="button" class="invoice-btn" data-invoice-action="download-pdf">Download clean PDF</button>
                    <button type="button" class="invoice-btn is-secondary" data-invoice-action="print">Print</button>
                    <button type="button" class="invoice-btn is-danger" data-invoice-action="clear">Clear</button>
                </div>
            </aside>

            <main class="invoice-paper" id="invoice-preview">
                <header class="invoice-paper-header">
                    <div>
                        <img src="{{ $company['logo'] }}" alt="Liverpool Hair & Cosmetics logo" class="invoice-logo">
                        <div class="invoice-company">
                            <strong>{{ $company['name'] }}</strong>
                            <div>{{ $company['address'] }}</div>
                            <div>Tel: {{ $company['phone'] }}</div>
                        </div>
                    </div>
                    <div class="invoice-title">
                        <h1>Invoice</h1>
                        <p data-invoice-preview="number"></p>
                    </div>
                </header>

                <section class="invoice-meta-grid">
                    <div class="invoice-customer">
                        <strong>Bill to</strong>
                        <div data-invoice-preview="customerName">Customer name</div>
                        <div data-invoice-preview="customerAddress">Customer address</div>
                        <div data-invoice-preview="customerContact"></div>
                    </div>
                    <div class="invoice-details">
                        <div class="invoice-detail-row">
                            <span>Invoice date</span>
                            <strong data-invoice-preview="date"></strong>
                        </div>
                        <div class="invoice-detail-row">
                            <span>Due date</span>
                            <strong data-invoice-preview="dueDate"></strong>
                        </div>
                    </div>
                </section>

                <table class="invoice-table">
                    <thead>
                        <tr>
                            <th>Product / service</th>
                            <th class="is-number">Qty</th>
                            <th class="is-number">Unit price</th>
                            <th class="is-number">Line total</th>
                        </tr>
                    </thead>
                    <tbody data-invoice-preview="lines"></tbody>
                </table>

                <section class="invoice-totals">
                    <div class="invoice-total-row">
                        <span>Subtotal</span>
                        <strong data-invoice-preview="subtotal"></strong>
                    </div>
                    <div class="invoice-total-row">
                        <span data-invoice-preview="vatLabel">VAT</span>
                        <strong data-invoice-preview="vat"></strong>
                    </div>
                    <div class="invoice-total-row is-grand">
                        <span>Total</span>
                        <strong data-invoice-preview="total"></strong>
                    </div>
                </section>

                <footer class="invoice-footer">
                    <div data-invoice-preview="notes"></div>
                    <div>{{ $company['name'] }} | {{ $company['address'] }} | {{ $company['phone'] }}</div>
                </footer>
            </main>
        </div>
    </div>

    <script>
        (() => {
            const root = document.querySelector('[data-invoice-builder]');
            if (!root) return;

            const storageKey = 'lhc.invoice.generator.v1';
            const currency = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP' });
            const inputs = Object.fromEntries([...root.querySelectorAll('[data-invoice-input]')].map((input) => [input.dataset.invoiceInput, input]));
            const preview = Object.fromEntries([...root.querySelectorAll('[data-invoice-preview]')].map((node) => [node.dataset.invoicePreview, node]));
            const lineContainer = root.querySelector('[data-invoice-lines]');
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            let lines = [{ description: '', qty: 1, price: '' }];
            const preparedHandwrittenInvoice = {
                fields: {
                    number: 'LHC-{{ now()->format('Ymd') }}-001',
                    date: '{{ now()->format('Y-m-d') }}',
                    dueDate: '{{ now()->addDays(7)->format('Y-m-d') }}',
                    customerName: 'N/A',
                    customerAddress: 'N/A',
                    customerContact: 'N/A',
                    vatRate: '0',
                    notes: 'Payment due on receipt.'
                },
                lines: [
                    { description: 'Obsession Poppin Twist 20" - Colour 1', qty: 10, price: 11.00 },
                    { description: 'Obsession Poppin Twist 20" - Colour 1B', qty: 10, price: 11.00 },
                    { description: 'Obsession Poppin Twist 20" - Colour 2', qty: 10, price: 11.00 },
                    { description: 'Obsession Poppin Twist 20" - Colour 4', qty: 10, price: 11.00 },
                    { description: 'Obsession Poppin Twist 16" - Colour 1', qty: 10, price: 10.00 },
                    { description: 'Obsession Poppin Twist 16" - Colour 1B', qty: 10, price: 10.00 },
                    { description: 'Obsession Poppin Twist 16" - Colour 2', qty: 10, price: 10.00 },
                    { description: 'Obsession Poppin Twist 16" - Colour 4', qty: 10, price: 10.00 },
                    { description: 'Obsession Poppin Twist 12" - Colour 1', qty: 6, price: 6.99 },
                    { description: 'Obsession Poppin Twist 12" - Colour 1B', qty: 6, price: 6.99 },
                    { description: 'Obsession Poppin Twist 12" - Colour 2', qty: 6, price: 6.99 },
                    { description: 'Obsession Poppin Twist 12" - Colour 4', qty: 6, price: 6.99 },
                    { description: 'Cherish 3X Pre-Stretched Spiral French Curl 22" - Colour 1', qty: 10, price: 11.00 },
                    { description: 'Cherish 3X Pre-Stretched Spiral French Curl 22" - Colour 1B', qty: 10, price: 11.00 },
                    { description: 'Cherish 3X Pre-Stretched Spiral French Curl 22" - Colour 2', qty: 10, price: 11.00 },
                    { description: 'Cherish 3X Pre-Stretched Spiral French Curl 22" - Colour 4', qty: 10, price: 11.00 },
                    { description: 'Cherish 3X Pre-Stretched Spiral French Curl 22" - Colour T30', qty: 10, price: 11.00 },
                    { description: 'Cherish 3X Pre-Stretched Spiral French Curl 22" - Colour T530', qty: 10, price: 11.00 },
                    { description: 'Cherish 3X Pre-Stretched Spiral French Curl 22" - Colour TCOPPER', qty: 10, price: 11.00 },
                    { description: 'Cherish 3X Pre-Stretched Spiral French Curl 22" - Colour T4/30', qty: 10, price: 11.00 },
                    { description: 'Cherish 3X Pre-Stretched Spiral French Curl 22" - Colour T27/613', qty: 10, price: 11.00 },
                    { description: 'Cherish 3X Pre-Stretched Spiral French Curl 22" - Colour T30/27', qty: 10, price: 11.00 },
                    { description: 'Cherish 3X Pre-Stretched Spiral French Curl 22" - Colour T33/30', qty: 10, price: 11.00 },
                    { description: 'Cherish 3X Pre-Stretched Spiral French Curl 22" - Colour T4/30/27', qty: 10, price: 11.00 },
                    { description: 'Cherish 3X Pre-Stretched Spiral French Curl 22" - Colour T1B/30/33', qty: 10, price: 11.00 },
                    { description: 'Cherish 3X Pre-Stretched Spiral French Curl 22" - Colour T350', qty: 10, price: 11.00 },
                    { description: 'Cherish 3X Pre-Stretched Spiral French Curl 22" - Colour VANILLA', qty: 10, price: 11.00 },
                    { description: 'Cherish 3X Pre-Stretched Spiral French Curl 22" - Colour ORANGE', qty: 10, price: 11.00 },
                    { description: 'Cherish 3X Pre-Stretched Spiral French Curl 22" - Colour ROSE WINE', qty: 10, price: 11.00 },
                    { description: 'N/A', qty: 1, price: 120.00 },
                    { description: 'Organic Relaxer Regular', qty: 12, price: 6.99 },
                    { description: 'Organic Relaxer Super', qty: 12, price: 6.99 },
                    { description: 'Just For Me Relaxer', qty: 12, price: 6.99 },
                    { description: 'Just For Me Softener Set', qty: 12, price: 6.99 },
                    { description: 'N/A', qty: 12, price: 6.99 },
                    { description: 'N/A', qty: 12, price: 6.99 },
                    { description: 'Olive Oil Relaxer Regular', qty: 12, price: 6.99 },
                    { description: 'Olive Oil Relaxer Super', qty: 12, price: 6.99 },
                    { description: 'Beautiful Beginnings Relaxer Fine', qty: 12, price: 6.99 },
                    { description: 'N/A', qty: 12, price: 6.99 },
                    { description: 'N/A', qty: 12, price: 6.99 },
                    { description: 'Queen Helene Cream 2', qty: 12, price: 6.99 },
                    { description: 'Queen Elizabeth', qty: 12, price: 6.99 },
                    { description: 'Blue Magic Blue', qty: 12, price: 3.49 },
                    { description: 'Blue Magic Green', qty: 12, price: 3.49 },
                    { description: 'Soft & Sheen Gel Pink', qty: 12, price: 3.49 },
                    { description: 'Soft & Sheen Gel Clear', qty: 12, price: 3.49 },
                    { description: 'Soft & Sheen Gel Brown', qty: 12, price: 3.49 },
                    { description: 'Gummy Wax Red', qty: 36, price: 2.99 },
                    { description: 'Red One Red', qty: 36, price: 2.99 },
                    { description: 'Sunny Isle Jamaican Black Castor Oil', qty: 12, price: 8.99 },
                    { description: 'Mango & Lime Oil', qty: 12, price: 6.99 },
                    { description: 'N/A', qty: 12, price: 7.99 },
                    { description: 'Dudu Soap', qty: 12, price: 1.79 },
                    { description: 'N/A', qty: 12, price: 3.99 },
                    { description: 'N/A', qty: 12, price: 3.99 },
                    { description: 'N/A', qty: 12, price: 5.99 },
                    { description: 'N/A', qty: 12, price: 5.99 },
                    { description: 'Soft & Free Curl Activator 500ml', qty: 12, price: 6.99 },
                    { description: 'ORS Shampoo', qty: 12, price: 4.99 },
                    { description: 'ORS Conditioner', qty: 12, price: 4.99 },
                    { description: 'Olive Oil Relaxer Regular', qty: 36, price: 5.99 },
                    { description: 'Olive Oil Relaxer Super', qty: 24, price: 5.99 },
                    { description: 'Dark & Lovely Regular', qty: 48, price: 5.99 },
                    { description: 'Dark & Lovely Super', qty: 12, price: 5.99 },
                    { description: 'N/A', qty: 12, price: 10.99 },
                    { description: 'Red One', qty: 36, price: 2.99 },
                    { description: 'Gummy Wax', qty: 36, price: 2.99 },
                    { description: 'Black Castor Oil', qty: 12, price: 8.99 },
                    { description: 'Olive Oil Sheen Spray', qty: 12, price: 4.99 },
                    { description: 'Hair & White Lotion', qty: 2, price: 14.00 },
                    { description: 'Black Hair Shampoo Sachet', qty: 12, price: 7.99 },
                    { description: 'Creme of Nature Oil', qty: 12, price: 4.99 },
                    { description: 'Organic Kid Texturizer Soft', qty: 12, price: 6.99 },
                    { description: 'N/A', qty: 1, price: 36.00 },
                    { description: 'Hair Brush', qty: 12, price: 3.00 }
                ]
            };

            function money(value) {
                return currency.format(Number.isFinite(value) ? value : 0);
            }

            function load() {
                try {
                    const saved = JSON.parse(localStorage.getItem(storageKey) || '{}');
                    Object.entries(saved.fields || {}).forEach(([key, value]) => {
                        if (inputs[key]) inputs[key].value = value;
                    });
                    if (Array.isArray(saved.lines) && saved.lines.length) {
                        lines = saved.lines;
                    }
                } catch (error) {
                    lines = [{ description: '', qty: 1, price: '' }];
                }
            }

            function save() {
                localStorage.setItem(storageKey, JSON.stringify(currentPayload()));
            }

            function currentPayload() {
                const fields = Object.fromEntries(Object.entries(inputs).map(([key, input]) => [key, input.value]));
                return { fields, lines };
            }

            function applyDraft(draft) {
                Object.entries(draft.fields || {}).forEach(([key, value]) => {
                    if (inputs[key]) inputs[key].value = value;
                });
                lines = Array.isArray(draft.lines) && draft.lines.length
                    ? draft.lines.map((line) => ({ ...line }))
                    : [{ description: '', qty: 1, price: '' }];
            }

            function renderLineEditors() {
                lineContainer.innerHTML = '';
                lines.forEach((line, index) => {
                    const row = document.createElement('div');
                    row.className = 'invoice-line-editor';
                    row.innerHTML = `
                        <label class="invoice-field">
                            <span>Product</span>
                            <input value="${escapeHtml(line.description || '')}" data-line-field="description" data-line-index="${index}" placeholder="Product name">
                        </label>
                        <label class="invoice-field">
                            <span>Qty</span>
                            <input type="number" min="0" step="1" value="${escapeHtml(line.qty ?? 1)}" data-line-field="qty" data-line-index="${index}">
                        </label>
                        <label class="invoice-field">
                            <span>Price</span>
                            <input type="number" min="0" step="0.01" value="${escapeHtml(line.price ?? '')}" data-line-field="price" data-line-index="${index}">
                        </label>
                        <button type="button" data-remove-line="${index}" aria-label="Remove product">x</button>
                    `;
                    lineContainer.appendChild(row);
                });
            }

            function renderPreview() {
                preview.number.textContent = inputs.number.value || 'Invoice';
                preview.customerName.textContent = inputs.customerName.value || 'Customer name';
                preview.customerAddress.innerHTML = nl2br(inputs.customerAddress.value || 'Customer address');
                preview.customerContact.textContent = inputs.customerContact.value || '';
                preview.date.textContent = formatDate(inputs.date.value);
                preview.dueDate.textContent = formatDate(inputs.dueDate.value);
                preview.notes.innerHTML = nl2br(inputs.notes.value || '');

                let subtotal = 0;
                preview.lines.innerHTML = '';
                lines.forEach((line) => {
                    const qty = parseFloat(line.qty) || 0;
                    const price = parseFloat(line.price) || 0;
                    const lineTotal = qty * price;
                    subtotal += lineTotal;

                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td>${escapeHtml(line.description || 'Product')}</td>
                        <td class="is-number">${qty}</td>
                        <td class="is-number">${money(price)}</td>
                        <td class="is-number"><strong>${money(lineTotal)}</strong></td>
                    `;
                    preview.lines.appendChild(tr);
                });

                const vatRate = parseFloat(inputs.vatRate.value) || 0;
                const vat = subtotal * (vatRate / 100);
                preview.subtotal.textContent = money(subtotal);
                preview.vatLabel.textContent = `VAT (${vatRate.toFixed(2).replace(/\.00$/, '')}%)`;
                preview.vat.textContent = money(vat);
                preview.total.textContent = money(subtotal + vat);
            }

            function render() {
                renderLineEditors();
                renderPreview();
                save();
            }

            function formatDate(value) {
                if (!value) return '';
                const [year, month, day] = value.split('-');
                return [day, month, year].filter(Boolean).join('/');
            }

            function escapeHtml(value) {
                return String(value)
                    .replaceAll('&', '&amp;')
                    .replaceAll('<', '&lt;')
                    .replaceAll('>', '&gt;')
                    .replaceAll('"', '&quot;')
                    .replaceAll("'", '&#039;');
            }

            function nl2br(value) {
                return escapeHtml(value).replaceAll('\n', '<br>');
            }

            async function downloadCleanPdf(button) {
                const originalText = button.textContent;
                button.disabled = true;
                button.textContent = 'Preparing PDF...';

                try {
                    const response = await fetch(root.dataset.pdfUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/pdf',
                            'X-CSRF-TOKEN': csrfToken
                        },
                        body: JSON.stringify(currentPayload())
                    });

                    if (!response.ok) {
                        throw new Error('PDF export failed.');
                    }

                    const blob = await response.blob();
                    const url = URL.createObjectURL(blob);
                    const link = document.createElement('a');
                    const invoiceNumber = (inputs.number.value || 'lhc-invoice').replace(/[^a-z0-9_-]+/gi, '-').replace(/^-+|-+$/g, '');
                    link.href = url;
                    link.download = `${invoiceNumber || 'lhc-invoice'}.pdf`;
                    document.body.appendChild(link);
                    link.click();
                    link.remove();
                    URL.revokeObjectURL(url);
                } catch (error) {
                    alert('Could not create the clean PDF. Please try again.');
                } finally {
                    button.disabled = false;
                    button.textContent = originalText;
                }
            }

            root.addEventListener('input', (event) => {
                const lineIndex = event.target.dataset.lineIndex;
                const lineField = event.target.dataset.lineField;
                if (lineIndex !== undefined && lineField) {
                    lines[Number(lineIndex)][lineField] = event.target.value;
                }
                renderPreview();
                save();
            });

            root.addEventListener('click', (event) => {
                const action = event.target.dataset.invoiceAction;
                if (action === 'add-line') {
                    lines.push({ description: '', qty: 1, price: '' });
                    render();
                    return;
                }
                if (action === 'load-prepared') {
                    if (!confirm('Replace the current invoice with the handwritten draft?')) return;
                    applyDraft(preparedHandwrittenInvoice);
                    render();
                    return;
                }
                if (action === 'print') {
                    renderPreview();
                    window.print();
                    return;
                }
                if (action === 'download-pdf') {
                    renderPreview();
                    save();
                    downloadCleanPdf(event.target);
                    return;
                }
                if (action === 'clear' && confirm('Clear this invoice draft?')) {
                    localStorage.removeItem(storageKey);
                    location.reload();
                    return;
                }

                const removeIndex = event.target.dataset.removeLine;
                if (removeIndex !== undefined) {
                    lines.splice(Number(removeIndex), 1);
                    if (!lines.length) lines.push({ description: '', qty: 1, price: '' });
                    render();
                }
            });

            load();
            if (new URLSearchParams(window.location.search).get('draft') === 'handwritten') {
                applyDraft(preparedHandwrittenInvoice);
            }
            render();
        })();
    </script>
@endsection
