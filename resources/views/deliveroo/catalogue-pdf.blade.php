<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>LHC Deliveroo Catalogue</title>
    <style>
        @page {
            margin: 14mm 10mm 12mm 10mm;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 8pt;
            color: #111;
            line-height: 1.35;
        }

        .page-title {
            text-align: center;
            font-size: 22pt;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #111;
            padding: 12px 0 6px;
        }

        .page-title-rule {
            border: none;
            border-top: 2pt solid #111;
            margin: 0 auto 14px;
            width: 60%;
        }

        .category-bar {
            background: #111;
            color: #fff;
            text-align: center;
            font-size: 12pt;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            padding: 8px 0;
            margin: 16px 0 10px;
        }

        .family-bar {
            text-align: center;
            font-size: 9pt;
            font-weight: 700;
            color: #111;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 5px 0 4px;
            margin: 8px 0 6px;
            border-top: 1pt solid #bbb;
            border-bottom: 1pt solid #bbb;
        }

        .product-grid {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 4px;
        }

        .product-grid td {
            width: 25%;
            text-align: center;
            vertical-align: top;
            padding: 8px 3px 10px;
        }

        .product-img {
            width: 82px;
            height: 82px;
            object-fit: contain;
            display: block;
            margin: 0 auto 5px;
        }

        .product-placeholder {
            display: block;
            width: 82px;
            height: 82px;
            margin: 0 auto 5px;
            background: #eee;
            line-height: 82px;
            text-align: center;
            font-size: 6pt;
            color: #bbb;
        }

        .product-name {
            font-size: 6.5pt;
            font-weight: 600;
            color: #111;
            line-height: 1.22;
            margin-bottom: 2px;
        }

        .product-price {
            font-size: 8pt;
            font-weight: 700;
            color: #111;
        }
    </style>
</head>
<body>
    <div class="page-title">LHC Deliveroo Catalogue</div>
    <hr class="page-title-rule">

    @foreach ($catalogue as $categoryName => $families)
        <div class="category-bar">{{ $categoryName }}</div>

        @foreach ($families as $familyName => $products)
            <div class="family-bar">{{ $familyName }}</div>

            <table class="product-grid">
                @foreach (array_chunk($products, 4) as $row)
                    <tr>
                        @foreach ($row as $product)
                            <td>
                                @if ($product['image'])
                                    <img class="product-img" src="{{ $product['image'] }}" alt="">
                                @else
                                    <span class="product-placeholder">&mdash;</span>
                                @endif
                                <div class="product-name">{{ $product['name'] }}</div>
                                <div class="product-price">{{ $product['price'] }}</div>
                            </td>
                        @endforeach
                        @for ($pad = count($row); $pad < 4; $pad++)
                            <td></td>
                        @endfor
                    </tr>
                @endforeach
            </table>
        @endforeach
    @endforeach
</body>
</html>
