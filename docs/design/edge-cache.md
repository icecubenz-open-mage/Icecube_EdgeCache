# Edge cache

`icecube/edge-cache` (module `Icecube_EdgeCache`) makes anonymous storefront pages cacheable at whatever sits in front of Maho: Cloudflare, Varnish, or another CDN through an adapter. It's observer-only. No rewrites, no core edits.

Maho file references below are against 26.9.2 (`vendor/mahocommerce/maho`).

## What it does

- Anonymous GETs on allowlisted routes render without a session, so no `Set-Cookie`.
- The form key in the HTML is a sentinel (`__EDGEFK__`). The browser swaps in a real key from `GET /edgecache/state/` when it needs one.
- Cache tags are collected while the page renders and sent in the adapter's tag header.
- Cache cleans are turned into tag purges, buffered per process and sent through the Maho queue.

No local full-page cache. Tiered edges, a long TTL with stale-while-revalidate and tag purge keep misses rare. Self-hosted Varnish is the local option if one's needed.

## Architecture

```text
                +--------------------- provider-neutral core ---------------------+
request ------> | Policy::prepareSession (allowlist, GET/HEAD, no session cookie)  |
                |   -> FLAG_NO_START_SESSION -> render                             |
                | TagCollector (model_load_after, collection load events)          |
                | http_response_send_before: Policy::applyToResponse               |
                |   status, content type, Set-Cookie guard, TTL, adapter headers   |
                | GET /edgecache/state: form key, customer, cart qty               |
save/clean ---> | application_clean_cache etc -> PurgeBuffer -> queue `edgecache`  |
                |   -> PurgeHandler (#[MessageHandler]) -> adapter                 |
                +-------------------------------+---------------------------------+
                                                | AdapterInterface
                            +-------------+-----+--------+-------------+
                            | Noop (dev)  | Cloudflare   | Varnish     |
                            +-------------+--------------+-------------+
```

### Sessionless render

`Mage_Core_Controller_Varien_Action::preDispatch()` fires `controller_action_predispatch_session_start` just before it starts `core/session`, and skips the start when `FLAG_NO_START_SESSION` is set (`app/code/core/Mage/Core/Controller/Varien/Action.php:473-476`). The Policy sets that flag when all of these hold:

- the module is enabled
- the method is GET or HEAD
- the request carries none of the bypass cookies below
- the full action name is in `edgecache/general/cacheable_actions`

### Bypass cookies

A request carrying any of these renders with a session and is never cached. The CDN must bypass its cache on exactly the same set, or it will serve the shared page to a visitor the origin would have rendered differently.

| Cookie | Why |
|---|---|
| `maho_session` | The visitor has a session: cart, login, messages |
| `frontend`, `om_frontend` | Legacy session names; the session migrates them to `maho_session` on start |
| `store` | Switches the store view without a URL change (`Mage_Core_Model_App::_checkCookieStore()`) |
| `user_allowed_save_cookie` | Cookie-restriction consent; decides whether the notice and the GA, GTM and Meta pixel scripts render |
| `maho_a11y_scan` | The accessibility scanner's one-time token; turns on template hints for the request |

The session names are hard-coded. The rest are `edgecache/general/bypass_cookies`, so a module that varies HTML on its own cookie can add it there.

A guest's first cart action starts a session, and from then on the CDN sends that visitor to origin for every page. That's the price of a simple, safe bypass rule. The hydrator is careful not to start a session before a key is genuinely needed (see Hydration).

### Response policy

Runs on `http_response_send_before` (`app/code/core/Mage/Core/Controller/Response/Http.php:627`), which fires after ContentNegotiation's `controller_front_send_response_before`. In order:

1. Markdown negotiated on a non-`.md` URL gets `Cache-Control: private, no-store`.
2. A frontend action that wasn't rendered sessionless gets the adapter's uncacheable headers.
3. Anything other than a 200 or 404 HTML response, or a redirect, is uncacheable.
4. If a session started anyway, a cookie is about to be sent, or the HTML already holds the form key sentinel (echoed from the request), it's uncacheable. The first refusal per action each hour is logged to `var/log/edgecache.log`, through core's rate limiter, so a module that always starts a session can't fill the log. The guard never strips the cookie. The key is still swapped for the sentinel, because the one the page rendered with may belong to no session; edgecache.js fetches a real one.
5. 404 and the short-TTL actions get `edgecache/general/short_ttl`. Everything else gets the normal TTL.

Caching 404s is deliberate: bots probing random URLs would otherwise all hit origin. Two side effects. A URL created after its 404 was cached serves that 404 for up to `short_ttl` plus stale-while-revalidate (about 10 minutes by default), and random URLs take up cache space. Setting `short_ttl` to 0 stops caching them, along with the other short-TTL actions such as search results.
6. Tags are normalised and capped to the adapter's limits, the browser header and adapter headers are set, `Accept` is removed from `Vary`, and the form key is replaced with the sentinel.

### Tags

Collected from:

- `model_load_after` (`getCacheIdTags()`), product and core collection loads, and EAV listing loads
- `core_block_abstract_to_html_after`, for blocks with a cache lifetime. A `block_html` hit loads no models, so the block's stored cache tags (`getCacheTags()`, which core saves next to the HTML) are the only record of what it shows. Core adds the model's id tags to CMS blocks and CMS block widgets as they render, so those come back specific (`cb7`).

Root categories (level below 2) are skipped, because nearly every page loads one and a product save would otherwise purge the whole site.

A cached block whose tags hold a type with no id of that type (the top menu's `catalog_category`, the new products widget's `catalog_product`) depends on the whole type, so the page gets the coarse tag (`c_all`, `p_all`). Nested blocks rendered inside a cached block's HTML are a known gap: on a hit only the outer block's tags are known.

EAV category collections (subcategory lists, layered navigation) fire no after-load event, so the categories they show aren't tagged. Pages are only covered because the cached top menu puts `c_all` on every page. A theme without a cached top menu would leave a renamed category stale on the pages that list it until their TTL.

Normalised to a short form with an optional prefix, so environments can share a zone:

| Maho tag | Edge tag | Coarse tag |
|---|---|---|
| `catalog_product_12` | `p12` | `p_all` |
| `catalog_category_4` | `c4` | `c_all` |
| `cms_page_3` | `cp3` | `cp_all` |
| `cms_block_7` | `cb7` | `cb_all` |
| `blog_post_9` | `bp9` | `bp_all` |
| `blog_category_2` | `bc2` | `bc_all` |

Anything else is dropped. A bare type tag becomes the coarse tag. When a page has more tags than the adapter allows, the overflow is swapped for the coarse tag of its type.

Coarse tags follow core's own block cache: a coarse tag is purged only when core cleans the bare type tag. Every model save does (`Mage_Core_Model_Abstract::getCacheTags()` returns the bare tag with the id), so a category save purges `c_all`, which is every page with the top menu, and a product save purges `p_all`. A product save also cleans its anchor categories, but by id only, so it doesn't purge the whole site. Mass attribute updates clean by id only too (`Mage_Catalog_Model_Product_Action::_cleanProductCache()`), so a page that overflowed into `p_all` or shows the new products widget can stay stale until its TTL, exactly as core's own `block_html` entry does.

### Purge

- `application_clean_cache` (`app/code/core/Mage/Core/Model/App.php`) catches every `cleanModelCache()`, including product saves with their anchor categories.
- `cataloginventory_stock_item_save_after` purges the product and its anchor categories only when `is_in_stock` changes, because order stock updates skip `cleanModelCache` and a listing hides or shows the product.
- `catalog_product_import_finish_before` purges every product the import touched, plus `p_all`. Imports write straight to the tables and clean no tags.
- `review_save_after` and `review_delete_after` purge the reviewed product.
- `admin_system_config_section_save_after`, `adminhtml_cache_flush_all`, `adminhtml_cache_flush_system`, `catalogrule_before_apply` and `directory_currency_rates_save_after` purge everything. A config save cleans only Maho's `config` and `layout` tags, yet changes the header, footer, price display and contact details on every page.

Known gaps:

- Special prices and catalogue rule prices change at a date boundary with no save, so nothing purges. A product page can show the old price for up to its TTL plus stale-while-revalidate after `special_from_date` or `special_to_date` passes. Keep the TTL short enough for that, or purge everything from a daily cron at the store's midnight if prices are scheduled often.
- Config set from the CLI (`config:set`) fires no admin event. Flush from the admin afterwards.
- A changed URL key leaves the old URL cached until its TTL. Core redirects it, but the edge doesn't know to drop it.
- The stock, review, import and mass-update purges name product ids. A page whose tags overflowed into `p_all` (a category listing more products than the header holds) has no id tag for the products past the cut, so it stays stale until its TTL. Saving a product cleans the bare tag and does reach it.
- A purge-all that keeps failing gets Maho's 3 retries (about 21 minutes), then is parked as failed in System > Message Queue. The tag purges it replaced are already gone, so after a CDN outage that long, retry it from the grid or flush the cache from the admin.

In a web request, tags are deduped and held until `controller_front_send_response_after`. Anywhere else (cron, CLI, queue workers) they're queued as soon as they're cleaned: a resident worker can live for an hour, and Maho's worker exposes no per-message hook to flush on (`WorkerFactory::create()` builds a private event dispatcher, `lib/Maho/Queue/WorkerFactory.php:36`). A tag leaves the buffer only once its message is queued. A failed dispatch is logged, never thrown, and retried on the next flush or at shutdown. The flush dispatches `PurgeTagsMessage` to queue `edgecache`, chunked by the adapter's `tagsPerPurge` and held back `purge_delay` seconds (default 15). Purge-everything dispatches `PurgeAllMessage` unless one is already pending. A claimed one doesn't count: it may already have called the CDN, so a change made since needs its own. (Maho's built-in dedupe counts claimed rows too, so the module checks itself and records `edgecache_purge_all` without enforcing it.) Route `edgecache` to a pool with one worker to keep rate limits predictable.

Cloudflare limits purge requests (5 a minute on Free), so a purge per message would burn through the limit during a mass update. The tag handler claims every other waiting `PurgeTagsMessage` whose tags still fit in one call (up to `tagsPerPurge`), including fresh ones still inside their delay, sends them as one request, and deletes the rows it merged. The delay is what gives a burst of saves time to pile up. A retry that isn't due yet is never merged, so new saves can't cut its backoff short. Rows still `processing` after Maho's abandoned-claim cutoff (5 minutes) are taken over too: a worker killed mid-call leaves them there, core never redelivers them, and purging twice is harmless.

Size `purge_delay` to the plan's purge rate. One worker sends at most one tag purge per delay window, so the delay must be at least 60 divided by the per-minute limit, plus some margin:

| Cloudflare plan | Tag purge limit (per the purge API docs at the time of writing) | `purge_delay` |
| --- | --- | --- |
| Free | 5 requests a minute | 15 (the default) |
| Pro, Business | higher, check the zone's limits | 5 or less |
| Enterprise | higher again | 1 or 0 |

Check the current limits in Cloudflare's docs before lowering it. Too short just means more 429s, which back off and recover.

Adapters throw on 429, 5xx or a refused purge. The handler catches that itself rather than leaving it to Maho's retry, which would retry each message alone and can't escalate. It requeues the merged tags as one message with `attempt + 1`, after 60 seconds, then 240, or after the 429's `Retry-After` if that's longer. A third failure queues a purge-all instead: a stale page is worse than a cold cache. If the retry can't be queued either, the merged rows go back to pending and the exception reaches Maho's retry. A purge-all that succeeds deletes the tag purges queued before it called the CDN, since it has covered them.

## Adapter contract

```php
interface Icecube_EdgeCache_Model_Adapter_AdapterInterface
{
    public function getLimits(): Icecube_EdgeCache_Model_Adapter_Limits;

    /** @param list<string> $tags already normalised and capped */
    public function getCacheableHeaders(Icecube_EdgeCache_Model_Ttl $ttl, array $tags): array;

    /** For providers that ignore Cache-Control: private */
    public function getUncacheableHeaders(): array;

    public function purgeTags(array $tags): void;

    public function purgeUrls(array $urls): void;

    public function purgeAll(): void;
}
```

`Limits` holds `tagSeparator`, `maxTagHeaderBytes`, `maxTagsPerObject`, `maxTagLength`, `tagsPerPurge` and `urlsPerPurge`. Zero means no limit. The tag limits must leave room for the coarse tags on their own (a few dozen bytes), which every real CDN does.

Nothing in the module calls `purgeUrls()`, and no shipped adapter sets `maxTagsPerObject`. Both are in the contract on purpose. A URL purge is the fallback for a page no tag reaches (a URL key change, a CMS page moved), and another module can call it without a change here. Fastly caps tags per object rather than header bytes. Adding either later would be a breaking interface change.

The adapter's headers are set after the browser `Cache-Control`, so an adapter for a CDN that only reads `Cache-Control` can override it.

Adding a provider takes one class, one `global/edgecache/adapters/<code>/class` node and a `system.xml` group with a `depends` on the adapter select. It doesn't have to live in this package.

### Header and purge matrix

RFC 9213 `CDN-Cache-Control` is used wherever the provider documents it. There are no Fastly, Bunny or Akamai adapters; they're listed to show the contract fits them.

| Provider | TTL header | Tag header | Limits | Purge tags | Purge all |
|---|---|---|---|---|---|
| Noop (dev) | `X-Edge-Cache-Control` | `X-Edge-Tags` | 16 KB | logged | logged |
| Cloudflare | `CDN-Cache-Control`, or `Cloudflare-CDN-Cache-Control` when another CDN is downstream | `Cache-Tag`, comma-separated | 16 KB, 1,024 chars per tag, 100 per purge | `POST /zones/{id}/purge_cache` `{"tags":[...]}` | `{"purge_everything":true}` |
| Varnish | `CDN-Cache-Control`, parsed in VCL then unset | `xkey`, space-separated | 7 KB (`http_resp_hdr_len` default 8 KB) | `PURGE` with `xkey`, optional `X-Soft-Purge: 1`, to every node | `BAN` with `X-Ban-Url: .` per store host |
| Fastly | `Surrogate-Control` | `Surrogate-Key`, space-separated | 16 KB, 1 KB per key, 256 per purge | `POST /service/{id}/purge` | `purge_all`, or soft-purge an `all` key |
| Bunny | `Cache-Control` (overrides the browser header) | `CDN-Tag` | 1,024 bytes | `POST /pullzone/{id}/purgeCache` | `purgeCache` with no tag |
| Akamai | `Akamai-Cache-Control` | `Edge-Cache-Tag`, comma-separated | 128 tags, 128 chars | `POST /ccu/v3/invalidate/tag/{network}` | CP code invalidate |

Akamai ignores origin `Cache-Control` by default, so its `getUncacheableHeaders()` must send `Akamai-Cache-Control: no-store`. Cloudflare and Varnish send `CDN-Cache-Control: no-store` too. Neither caches `private` or `Set-Cookie` responses, but core sends a 301 as `max-age=86400` with no cookie, and nothing could purge it.

### Sample VCL

For Varnish 7 with `vmod_xkey`. It's a starting point, not a tested production config. Keep the cookie list in step with Bypass cookies (session names plus `bypass_cookies`).

```vcl
vcl 4.1;

import std;
import xkey;

backend default {
    .host = "maho";
    .port = "80";
}

acl purgers {
    "127.0.0.1";
    "10.0.0.0"/8;
}

sub vcl_recv {
    if (req.method == "PURGE" || req.method == "BAN") {
        if (client.ip !~ purgers) {
            return (synth(403));
        }
        if (req.method == "BAN") {
            std.ban("obj.http.x-host == " + req.http.host + " && obj.http.x-url ~ " + req.http.X-Ban-Url);
            return (synth(200));
        }
        if (!req.http.xkey) {
            return (purge);
        }
        if (req.http.X-Soft-Purge) {
            set req.http.n-gone = xkey.softpurge(req.http.xkey);
        } else {
            set req.http.n-gone = xkey.purge(req.http.xkey);
        }
        return (synth(200, req.http.n-gone));
    }
    if (req.method != "GET" && req.method != "HEAD") {
        return (pass);
    }
    if (req.http.Cookie ~ "(^|;\s*)(maho_session|frontend|om_frontend|store|user_allowed_save_cookie|maho_a11y_scan)=") {
        return (pass);
    }
    unset req.http.Cookie;
    return (hash);
}

sub vcl_backend_response {
    set beresp.http.x-url = bereq.url;
    set beresp.http.x-host = bereq.http.host;
    if (!beresp.http.CDN-Cache-Control || beresp.http.CDN-Cache-Control ~ "no-store" || beresp.http.Set-Cookie) {
        set beresp.uncacheable = true;
        set beresp.ttl = 120s;
        return (deliver);
    }
    set beresp.ttl = std.duration(regsub(beresp.http.CDN-Cache-Control, "^.*max-age=([0-9]+).*$", "\1s"), 0s);
    set beresp.grace = std.duration(regsub(beresp.http.CDN-Cache-Control, "^.*stale-while-revalidate=([0-9]+).*$", "\1s"), 0s);
    set beresp.keep = std.duration(regsub(beresp.http.CDN-Cache-Control, "^.*stale-if-error=([0-9]+).*$", "\1s"), 0s);
    unset beresp.http.CDN-Cache-Control;
    return (deliver);
}

sub vcl_deliver {
    unset resp.http.xkey;
    unset resp.http.x-url;
    unset resp.http.x-host;
}
```

### Vary

The module never adds a `Vary` beyond what core sends. Core ContentNegotiation sends `Vary: Accept` on HTML and serves markdown from the same URL:

| Provider | `Vary: Accept` |
|---|---|
| Cloudflare | ignored, so markdown can poison the HTML entry |
| Fastly, Varnish | honoured on the raw value, so the cache splits per browser Accept string |
| Bunny | not documented, treat as poisoned |
| Akamai | any Vary other than Accept-Encoding stops caching |

So the Policy removes `Accept` from `Vary` on cacheable HTML and makes markdown negotiated on a non-`.md` URL `private, no-store`. Markdown clients get HTML from the edge and can follow `Link: rel="alternate"` to the `.md` URL.

### Resized images

Core serves a product image that hasn't been resized yet through `core/index/resize/?t=...&s=...`, then switches the HTML to the static `media/catalog/product/cache/...` path once the file exists. A page the edge cached before that keeps the resize URL for its whole TTL, so every visitor fetches it. It's a front action, so each fetch used to start `maho_session`, and from then on the visitor bypassed the edge.

`core_index_resize` is always rendered sessionless, bypass cookie or not, since an image is the same for everyone. A 200 `image/*` response gets `Cache-Control: public, max-age=<ttl>` and the adapter's cacheable headers with no tags. The URL is signed over the source file and transform, and a replaced image gets a new file name, so the bytes behind a URL never change and nothing needs a tag; a purge-all still clears them. A bad signature (403), a missing file or a cookie set anyway gets the uncacheable headers.

## Header rules

| Case | Browser `Cache-Control` | Edge TTL (adapter header) | Tags |
|---|---|---|---|
| GET or HEAD, allowlisted, no session cookie, no session started, 200 HTML | `public, max-age=0, must-revalidate` | `edgecache/general/ttl` (86400), SWR 86400, SIE 604800 | yes |
| `catalogsearch_result_index`, `cms_index_noRoute`, any 404 | same | `short_ttl` (300) | yes |
| `core_index_resize`, 200 `image/*` | `public, max-age=<ttl>` | `edgecache/general/ttl` | no |
| Allowlisted, but the request carries a bypass cookie | core default | `getUncacheableHeaders()` | no |
| Markdown on a non-`.md` URL | `private, no-store` | `getUncacheableHeaders()` | no |
| `/edgecache/state`, POST, checkout, customer, cart | `private, no-store` or core default | `getUncacheableHeaders()` | no |

Default allowlist: `cms_index_index`, `cms_page_view`, `catalog_category_view`, `catalog_product_view`, `blog_index_index`, `blog_index_category`, `blog_index_view`, `catalogsearch_result_index`, `cms_index_noRoute`.

Anyone holding a session (a guest with a cart, a logged-in customer) goes to origin, because the CDN bypasses on `maho_session`. Edge hits are for visitors without one. If the bypass rule is ever narrowed so session holders get edge hits, logged-in customers would see anonymous prices: a vary cookie (`edgecache_vary`, a hash of the customer group) would be needed first, and isn't built.

## Hydration

`GET /edgecache/state/` starts a session through the normal `preDispatch` and returns `Cache-Control: private, no-store`:

```json
{"v":1,"formKey":"aB3dE5fG7hJ9kL1m","customer":{"loggedIn":false,"group":0},"cart":{"qty":2}}
```

`skin/frontend/base/default/js/edgecache.js` is added through layout XML (`edgecache.xml`) and does nothing on a page without the sentinel:

- If the non-HttpOnly marker cookie `edgecache_s=1` is present (set from `session_before_renew_cookie`), it fetches the state on load.
- Otherwise it fetches on first intent, a `pointerdown` or `focusin` on a control or inside a form that posts a key. Touching the search box or a newsletter field doesn't count: hydrating starts a session, and the bypass rule sends a session holder to origin for the rest of the visit.
- A capture-phase `click` and `submit` handler holds any action that still carries the sentinel, waits for the key, then replays it. A click with a modifier key or a non-primary button isn't held, since the replay would lose it.
- Once the key arrives it sets `window.FORM_KEY` and `minicart.formKey`, swaps the sentinel in `form_key` inputs and, inside `href`, `action`, `onclick` and `data-params` only, in `/form_key/` and `form_key=` URLs and `"form_key":"..."` JSON, updates the cart count and fires `edgecache:hydrated`. It leaves the sentinel anywhere else alone, `value` attributes included, so `?q=form_key=__EDGEFK__` can't pull the visitor's key into a search box and from there into a URL.
- The origin won't cache a page whose HTML already holds the sentinel before the key is swapped. That only happens when the page echoes request input.

Speculation Rules (off by default) can prefetch footer links such as My Account, which starts a session. If it's on, exclude session-starting paths from its rules.

Search autocomplete starts a session too: typing in the header search box calls `catalogsearch/ajax/suggest` (`maho-autocomplete.js`), which isn't allowlisted, so from then on that visitor goes to origin. Making `suggest` sessionless with the short TTL would avoid that; the module doesn't.

## Maho 26.9.2 notes

- There's no `catalogrule_after_apply` event. The module uses `catalogrule_before_apply` (`app/code/core/Mage/CatalogRule/Model/Action/Index/Refresh.php:88`).
- `head.phtml` doesn't define a global `FORM_KEY`. The hydrator sets it.
- Sitemap, robots and llms controllers set `FLAG_NO_START_SESSION` themselves, so they need nothing from this module.
