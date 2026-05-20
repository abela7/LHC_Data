# LHC_Data — agent memory

## Learned User Preferences

- Expects modern, clear UI on Deliveroo-prep and product surfaces; dislikes cluttered or confusing controls (notably image URL and gallery UX).
- When editing products, wants each image URL clearly associated with its picture; prefers careful, thorough implementation for data and form changes.
- May ask the agent to run `npm run build` after frontend changes so compiled assets stay in sync.
- On Deliveroo catalogue grids with bulk selection, expects card-area clicks in selection mode to toggle selection rather than opening the product.
- Prefers optional layout or density controls collapsed by default (e.g. accordion) so the page stays simple until those controls are needed.
- For PDF exports and printed catalogues, wants classic, professional styling (serif fonts like Georgia, black/white/grey palette, no decorative colors); explicitly dislikes "childish" design.
- For Deliveroo official products, expects one catalogue price per product family (same `price` for every variant under the same `brand_slug` + `family_name`), not mixed amounts across variants in that family.

## Learned Workspace Facts

- Local Laravel app is exercised at `http://localhost/LHC_Data/public/` (e.g. `deliveroo-products`, `pdf-products`).
- Deliveroo family product listings are also exposed at a short URL pattern `/{brand}/families/{familyToken}` (same handler as `/deliveroo-products/official/{brand}/families/{family}`; `{brand}` is limited to configured Deliveroo brand slugs).
- PDF catalogue extraction is a conservative staging workflow: layout-aware text (e.g. pdfplumber word positions), Laravel/MySQL staging and review—not OCR-first bulk ecommerce import.
- Extraction guidance from the product owner: keep `brand` separate from `product_name`; do not put page merchandising lines (e.g. "DOZEN PRICE") into product names; when the brand is not visible on the page, verify on the web or record as unknown.
- Deliveroo PDF catalogue export at `/deliveroo-products/catalogue-pdf?brand=…` uses `barryvdh/laravel-dompdf`; product images (Shopify CDN) are base64-embedded after GD resize (140px, JPEG q65) to keep PDF size manageable.
- An "All Products" page exists at `/deliveroo-products/all` with pagination, brand filter, and search.
- `scripts/normalize-deliveroo-family-prices.php` sets a single price per `deliveroo_official_products` family from the mode of non-null variant prices (ties → lowest), currency GBP—re-runnable after imports or bulk edits. `scripts/list-products-without-price.php` lists rows with null `price`.
