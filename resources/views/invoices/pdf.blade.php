<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $fields['number'] ?? 'Invoice' }}</title>
    <style>
        @page {
            margin: 24px 28px 30px;
        }

        body {
            margin: 0;
            color: #1f1e1b;
            font-family: DejaVu Sans, sans-serif;
            font-size: 10.5px;
            line-height: 1.42;
        }

        .header {
            border-bottom: 2px solid #1f1e1b;
            padding-bottom: 16px;
            margin-bottom: 18px;
        }

        .header-table,
        .meta-table,
        .totals-table {
            width: 100%;
            border-collapse: collapse;
        }

        .logo {
            max-width: 205px;
            max-height: 95px;
        }

        .title {
            text-align: right;
            font-size: 36px;
            line-height: 1;
            font-weight: 700;
            letter-spacing: -1px;
            text-transform: uppercase;
        }

        .invoice-number {
            margin-top: 8px;
            color: #6f665c;
            font-weight: 700;
        }

        .company {
            margin-top: 8px;
        }

        .company strong,
        .box strong {
            display: block;
            margin-bottom: 4px;
            font-size: 12px;
        }

        .box {
            border: 1px solid #d7cec0;
            background: #fbf7ef;
            padding: 10px 12px;
            border-radius: 8px;
            vertical-align: top;
        }

        .label {
            color: #6f665c;
            font-weight: 700;
        }

        .items {
            width: 100%;
            margin-top: 18px;
            border-collapse: collapse;
        }

        .items thead {
            display: table-header-group;
        }

        .items th {
            padding: 8px 7px;
            border-top: 2px solid #1f1e1b;
            border-bottom: 2px solid #1f1e1b;
            color: #6f665c;
            font-size: 9px;
            letter-spacing: 0.8px;
            text-align: left;
            text-transform: uppercase;
        }

        .items td {
            padding: 7px;
            border-bottom: 1px solid #e2d9ca;
            vertical-align: top;
        }

        .number {
            text-align: right;
            white-space: nowrap;
        }

        .totals {
            width: 250px;
            margin-left: auto;
            margin-top: 18px;
        }

        .totals-table td {
            padding: 7px 0;
            border-bottom: 1px solid #e2d9ca;
            font-weight: 700;
        }

        .grand td {
            padding: 10px;
            border-bottom: 0;
            background: #1f1e1b;
            color: #fffdf8;
            font-size: 13px;
        }

        .notes {
            margin-top: 22px;
            padding-top: 12px;
            border-top: 1px solid #e2d9ca;
            color: #6f665c;
        }

        .footer {
            position: fixed;
            left: 0;
            right: 0;
            bottom: -12px;
            color: #8a8074;
            font-size: 8.5px;
            text-align: center;
        }
    </style>
</head>
<body>
    @php
        $money = fn ($value) => '£'.number_format((float) $value, 2);
        $formatDate = function ($value) {
            if (empty($value)) {
                return 'N/A';
            }

            try {
                return \Carbon\Carbon::parse($value)->format('d/m/Y');
            } catch (\Throwable $e) {
                return $value;
            }
        };
    @endphp

    <div class="header">
        <table class="header-table">
            <tr>
                <td style="width: 55%; vertical-align: top;">
                    @if ($company['logo'])
                        <img src="{{ $company['logo'] }}" alt="Liverpool Hair & Cosmetics logo" class="logo">
                    @endif
                    <div class="company">
                        <strong>{{ $company['name'] }}</strong>
                        <div>{{ $company['address'] }}</div>
                        <div>Tel: {{ $company['phone'] }}</div>
                    </div>
                </td>
                <td style="width: 45%; vertical-align: top;">
                    <div class="title">Invoice</div>
                    <div class="invoice-number">{{ $fields['number'] ?? 'N/A' }}</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="meta-table">
        <tr>
            <td class="box" style="width: 58%;">
                <strong>Bill to</strong>
                <div>{{ $fields['customerName'] ?? 'N/A' }}</div>
                <div>{!! nl2br(e($fields['customerAddress'] ?? 'N/A')) !!}</div>
                <div>{{ $fields['customerContact'] ?? 'N/A' }}</div>
            </td>
            <td style="width: 4%;"></td>
            <td class="box" style="width: 38%;">
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td class="label">Invoice date</td>
                        <td class="number">{{ $formatDate($fields['date'] ?? null) }}</td>
                    </tr>
                    <tr>
                        <td class="label">Due date</td>
                        <td class="number">{{ $formatDate($fields['dueDate'] ?? null) }}</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 54%;">Product / service</th>
                <th class="number" style="width: 13%;">Qty</th>
                <th class="number" style="width: 16%;">Unit price</th>
                <th class="number" style="width: 17%;">Line total</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>{{ $line['description'] }}</td>
                    <td class="number">{{ rtrim(rtrim(number_format($line['qty'], 2), '0'), '.') }}</td>
                    <td class="number">{{ $money($line['price']) }}</td>
                    <td class="number"><strong>{{ $money($line['total']) }}</strong></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <table class="totals-table">
            <tr>
                <td>Subtotal</td>
                <td class="number">{{ $money($subtotal) }}</td>
            </tr>
            <tr>
                <td>VAT ({{ rtrim(rtrim(number_format($vatRate, 2), '0'), '.') }}%)</td>
                <td class="number">{{ $money($vat) }}</td>
            </tr>
            <tr class="grand">
                <td>Total</td>
                <td class="number">{{ $money($total) }}</td>
            </tr>
        </table>
    </div>

    @if (! empty($fields['notes']))
        <div class="notes">{!! nl2br(e($fields['notes'])) !!}</div>
    @endif

    <div class="footer">
        {{ $company['name'] }} | {{ $company['address'] }} | {{ $company['phone'] }}
    </div>
</body>
</html>
