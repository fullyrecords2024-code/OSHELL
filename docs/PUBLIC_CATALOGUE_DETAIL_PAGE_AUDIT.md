# Public Catalogue Detail Page Audit

Date: 2026-11-08
Scope: public product and service cards, detail routes, SEO, images, availability, booking/purchase actions and not-found behaviour.

## 1. Current implementation

### 1.1 Routing

`frontend/src/App.tsx` (`readPublicRoute`, lines ~205-252):

- `/` → home.
- `/org-slug/shop` and `/org-slug/services` → catalogue pages.
- `/org-slug/shop/{numeric-id}` and `/org-slug/services/{numeric-id}` → detail pages.
- `/org-slug/journal/{slug}` → journal article.
- Any other single segment after the organization slug → CMS custom page.

Detail pages resolve client-side:

```ts
const detailProduct = detailType === 'product'
  ? products.find((product) => product.id === detailId) ?? null
  : null
```

Consequences:

- URLs are numeric and not SEO-friendly.
- If the item is not in the currently loaded branch catalogue, the page shows “This product is not available at the selected clinic.” with HTTP 200.
- Refreshing a deep link works because the SPA loads the storefront payload and resolves the ID, but search engines see IDs rather than names.
- No canonical slug, no per-item server route, no sitemap entries.

### 1.2 Cards

- `productCard(product)` and `serviceCard(service)` render `.storefront-product-card` / `.storefront-service-card` with an anchor `.storefront-card-anchor` pointing at `publicProductPath(...)` / `publicServicePath(...)`.
- Cards are clickable and already open a dedicated detail view (verified 29/29 locally and 23/23 live in the previous release).
- The gap is URL quality, unknown-item semantics, gallery data and SEO coverage — not a blank modal or broken click.

### 1.3 Detail content

Product detail renders:

- image/fallback, thumbnails when more than one image, category, name, brand, price, branch availability, description, SKU/category/unit/clinic facts, add-to-bag/quantity, related products, verified reviews, links to services/contact.

Service detail renders:

- image/fallback, thumbnails, category/classification, name, price (or “ask the clinic”), duration/buffer, description, duration/category/clinic/deposit facts, per-branch availability when more than one, booking CTA, related services, verified reviews.

Fallback copy is generic and clearly marked; no invented ingredients, claims, duration or price.

### 1.4 SEO

- Title: `{item name} | {organization name}`.
- Description: item description (possibly empty).
- Canonical: `${publicSiteUrl}/{org}/shop/{id}` or `/services/{id}`.
- JSON-LD: `Product` with SKU/category/brand/price/availability, or `Service` with service type and per-branch offers.
- `sitemap.xml` (`WebsiteSeoController::sitemap`) includes organization pages, CMS pages and journal articles only. It omits products and services.
- `robots.txt` disallows `/staff` and `/api/`.

### 1.5 Images

Schema supports `product_images` and `service_images` and the payload includes them. Production currently has 0 images for all 31 products, so the branded fallback mark renders. This is a data gap, not a code defect; no images will be invented.

### 1.6 Visibility

- Products: `is_active = 1`, `is_public = 1`, available stock (net of reservations) > 0.
- Services: `is_active = 1`, branch pivot `is_available = 1`, `is_public = 1`.
- Inactive/private items are absent from the storefront payload, so a detail URL for them resolves to the “not available” message.

## 2. Gaps against the specification

| ID | Gap | Required change |
| --- | --- | --- |
| WEB-A01 | Numeric URLs | Add unique per-organization `slug` to products and services; backfill from names; use slugs in links |
| WEB-A02 | No server-side item route | Add `GET /public/products/{slug}` and `GET /public/services/{slug}` with 404 for unknown/inactive/private |
| WEB-A03 | Missing item returns 200 text | Render a dedicated not-found view and set a `noindex` meta; API returns real 404 |
| WEB-A04 | Sitemap omits catalogue | Include product and service slugs |
| WEB-A05 | Canonical/JSON-LD use IDs | Use slug URLs |
| WEB-A06 | Availability can leak exact stock | Public copy should say “Available”/“Low stock”/“Out of stock”, not exact units |
| WEB-A07 | Related items may cross availability | Only related items from the same visible catalogue |
| WEB-A08 | Booking uses the in-page account modal | Keep existing appointment workflow; verify CTA reaches it |
| WEB-A09 | No slugs in storefront/sections payload | Include `slug` in product and service payloads |
| WEB-A10 | No image data in production | Business task: upload approved images; until then keep the branded fallback |

## 3. Design decisions

- Slug source: lowercase ASCII name, non-alphanumerics collapsed to `-`, trimmed; collisions get `-{id}`. Unique per organization.
- Slugs are generated on create and refreshed when the name changes only if the slug was auto-generated (a manual slug is preserved).
- Numeric detail URLs remain supported as a redirect/compatibility path to the slug (client-side), so existing shared links keep working.
- Public detail lookup is server-side by slug and respects the same visibility rules as the catalogue.
- Availability copy is qualitative (In stock / Low stock / Out of stock), never the internal quantity.
- Unknown or private item: API 404, frontend not-found view with links back to shop/services and `robots: noindex`.
- Structured data is emitted only when the item resolves and the data is accurate; no ratings are invented.

## 4. Acceptance criteria

- Every visible product/service card opens a URL containing its slug and shows the matching item.
- Deep-linking the slug after a refresh resolves correctly.
- Unknown, inactive or private slugs return 404 from the API and a clear not-found page in the SPA.
- Canonical, title, description and JSON-LD use the slug URL.
- Sitemap contains product and service URLs.
- Add-to-bag and booking CTAs still work; booking reaches the existing appointment flow.
- Mobile layout has no horizontal overflow; gallery/fallback renders.
- No invented ingredients, medical claims, durations, prices or availability.
