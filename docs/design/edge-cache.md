# Edge cache

`icecube/edge-cache` (module `Icecube_EdgeCache`) makes anonymous storefront pages cacheable at whatever sits in front of Maho: Cloudflare, Varnish, or another CDN through an adapter. It's observer-only. No rewrites, no core edits.

EdgeCache picks the pages, core blanks the key. On the `controller_action_predispatch_session_start` event ([#888](https://github.com/mahocommerce/maho/pull/888)), EdgeCache sets `FLAG_NO_START_SESSION` for a GET or HEAD to a listed page from a visitor with no session. Core then sends that page with an empty form key, and its `js.js` fetches a real one before the first POST. EdgeCache decides whether the response may be shared, tags it and purges it.

Maho file references below are against Maho main (`603008384`) with core's `sessionless-form-key` (`174f93302`, `ce7558461`) and `resize-without-session` (`31b40b6c3`) commits applied. Versions 0.1.x swapped the form key with their own JavaScript, on 26.9.2. Versions 0.2.x left the page list to a core option that wasn't merged. Coming from 0.2.x, `cacheable_actions` and `bypass_cookies` rows left from 0.1 take effect again, and the default list adds search results, the 404 page and the blog to core's old four routes.

## What it does

- Listed pages render without a session for a visitor who has none, and are marked cacheable, unless a session started or a cookie or form key got into them after all.
- Cache tags are collected while the page renders and sent in the adapter's tag header.
- Cache cleans are turned into tag purges, buffered per process and sent through the Maho queue.

No local full-page cache. Tiered edges, a long TTL with stale-while-revalidate and tag purge keep misses rare. Self-hosted Varnish is the local option if one's needed.

## Architecture

```text
                +--------------------- provider-neutral core ---------------------+
request ------> | predispatch_session_start: Policy::setAction                     |
                |   list, method, cookies -> FLAG_NO_START_SESSION -> render       |
                | TagCollector (model_load_after, collection load events)          |
                | http_response_send_before: Policy::applyToResponse               |
                |   flag, status, content type, refusal guard, TTL, headers        |
save/clean ---> | application_clean_cache etc -> PurgeBuffer -> queue `edgecache`  |
                |   -> PurgeHandler (#[MessageHandler]) -> adapter                 |
                +-------------------------------+---------------------------------+
                                                | AdapterInterface
                            +-------------+-----+--------+-------------+
                            | Noop (dev)  | Cloudflare   | Varnish     |
                            +-------------+--------------+-------------+
```

### Sessionless render

`Mage_Core_Controller_Varien_Action::preDispatch()` fires `controller_action_predispatch_session_start` and starts the session unless `FLAG_NO_START_SESSION` is set (`app/code/core/Mage/Core/Controller/Varien/Action.php:473-476`). The event has been in every release since 26.5.0. EdgeCache's observer sets the flag when all of these hold:

- the module is enabled
- core has the form key fallback (below)
- the method is GET or HEAD
- the request carries no session cookie (`maho_session`, `frontend`, `om_frontend`) and none of `edgecache/general/bypass_cookies`
- the full action name is in `edgecache/general/cacheable_actions`

It runs for every action a request dispatches, so a forward decides again.

With the flag set, the session never starts, and `Mage_Core_Model_Session::getFormKey()` returns an empty string while there's no session (`app/code/core/Mage/Core/Model/Session.php:39-49`). The page goes out with `FORM_KEY = ''` and empty `form_key` inputs and data-params. See Form key.

EdgeCache reads the flag again when the response goes out, so an observer that sets or clears it after EdgeCache's still counts. A response is a candidate for the edge when the module is enabled, core has the fallback, the action that rendered it (forwards included) has the flag, and its full action name is on the list. Other actions skip the session for their own reasons (OAuth, the API, robots and llms.txt, the image route) and are left out.

The legacy `core/index/resize` URL skips the session for every visitor, bypass cookies or not: it only redirects to the image file, and a first visit from an old email shouldn't get a session cookie from an image. It does so on a Maho without core's form key fallback too, since an image holds no form key. Its responses get the uncacheable headers.

### Without core's form key fallback

A page rendered without a session on a Maho that doesn't blank the key would print a key from a session that's never saved. Every form on it would post a key no session knows, and fail, whether or not the page is cached. So on such a Maho EdgeCache never sets the flag, apart from the legacy resize URL: every visitor keeps their session, every storefront response gets the uncacheable headers, and the module only purges. Refusing to cache after rendering without a session wouldn't be enough, since the visitor who got that render would still have broken forms.

EdgeCache detects the fallback by `Mage_Core_Model_Session::FORM_KEY_ROUTE`, the route core's `js.js` fetches the key from.

That route (`core/index/formKey`, full action name `core_index_formKey`) starts the visitor's session and answers `private, no-store`. EdgeCache never skips the session on it, even if it's added to `cacheable_actions`: the check is hard-coded, as the session cookie names are.

The observer is declared in the global area. Core fires the event before it loads the frontend area, so an observer declared there would never run.

### Bypass cookies

EdgeCache keeps the session for a request carrying any of these, so its page is never cached. The CDN must bypass its cache on exactly the same set, or it will serve the shared page to a visitor the origin would have rendered differently.

| Cookie | Why |
|---|---|
| `maho_session` | The visitor has a session: cart, login, messages |
| `frontend`, `om_frontend` | Legacy session names; the session migrates them to `maho_session` on start |
| `store` | Switches the store view without a URL change (`Mage_Core_Model_App::_checkCookieStore()`) |
| `user_allowed_save_cookie` | Cookie-restriction consent; decides whether the notice and the GA, GTM and Meta pixel scripts render |
| `maho_a11y_scan` | The accessibility scanner's one-time token; turns on template hints for the request |

The session names are always included, so clearing the setting can't make sessions cacheable. The rest are `edgecache/general/bypass_cookies`, so a module that varies HTML on its own cookie adds it there, and at the CDN.

### Design exceptions by User-Agent

System > Configuration > Design can pick the package, theme, templates, skin or layout by matching the User-Agent (`design/package/ua_regexp` and `design/theme/*_ua_regexp`, read in `Mage_Core_Model_Design_Package::_checkUserAgentAgainstRegexps()`). The edge keys on the URL, so the first visitor's theme would be served to everyone. EdgeCache refuses to cache anything while any of those exceptions is set, at any scope that reaches the store view. The page still renders without a session; it just isn't shared.

A guest's first POST starts a session (core fetches the key from `core/index/formKey`), and from then on the CDN sends that visitor to origin for every page. That's the price of a simple, safe bypass rule.

### Response policy

Runs on `http_response_send_before` (`app/code/core/Mage/Core/Controller/Response/Http.php:627`), which fires after ContentNegotiation's `controller_front_send_response_before`. It has to be the last observer of that event: a cookie or header that a later observer adds isn't seen, and the response goes out with the cacheable headers anyway. A module that changes the response on that event has to run before EdgeCache's observer (`edgecache_apply_policy`), or move to an earlier event such as `controller_front_send_response_before`. In order:

1. Core's image route (`Mage_Core_ImageController`) is left exactly as core sends it. See Resized images.
2. Markdown negotiated on a non-`.md` URL gets `Cache-Control: private, no-store`.
3. A frontend action that isn't a candidate (above) gets the adapter's uncacheable headers.
4. Anything other than a 200 or 404 HTML response, or a redirect, is uncacheable.
5. If a session started anyway, a cookie is about to be sent (through `setcookie()`, the header bag or a raw header), a design exception picks the theme by User-Agent, or the body still holds a form key, it's uncacheable. Core prints no key without a session, so one there came from a module that made its own (`renewFormKey()`), and belongs to no visitor's session. The first refusal per action each hour is logged to `var/log/edgecache.log`, through core's rate limiter, so a module that always starts a session can't fill the log. The guard never strips the cookie or the key.
6. 404 and the short-TTL actions get `edgecache/general/short_ttl`. Everything else gets the normal TTL.

Caching 404s is deliberate: bots probing random URLs would otherwise all hit origin. Two side effects. A URL created after its 404 was cached serves that 404 for up to `short_ttl` plus stale-while-revalidate (about 10 minutes by default), and random URLs take up cache space. Setting `short_ttl` to 0 stops caching them, along with the other short-TTL actions such as search results. A 404 only renders sessionless while `cms_index_noRoute` is in `cacheable_actions`, as it is by default. Only a URL no router matches gets that name. A missing product or category forwards from its own action to the CMS 404, and `getFullActionName()` keeps the requested route with the forwarded controller and action, so the forwarded action is `catalog_index_noRoute`. That's not on the list, so the 404 for a missing product gets the uncacheable headers.
7. Tags are normalised and capped to the adapter's limits, the browser header and adapter headers are set, and `Accept` is removed from `Vary`.

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

An adapter can also implement `Icecube_EdgeCache_Model_Adapter_InvalidatesInterface`, whose `invalidateAll()` marks the whole cache stale instead of emptying it. The next request for a page gets the stale copy within `stale_while_revalidate` while the CDN fetches the page again, so a deploy can refresh every page without leaving the edge cold. Nothing in the module calls it: a save purges, because a changed price or stock level must not be served again. It's for a deploy script that knows the old pages are still fine to show once more. Cloudflare implements it with `POST /zones/{id}/invalidate_cache`. Maho sends no `ETag` or `Last-Modified` for a page, so Cloudflare can't answer a revalidation with a 304 and fetches each page in full.

Adding a provider takes one class, one `global/edgecache/adapters/<code>/class` node and a `system.xml` group with a `depends` on the adapter select. It doesn't have to live in this package.

### Header and purge matrix

RFC 9213 `CDN-Cache-Control` is used wherever the provider documents it. There are no Fastly, Bunny or Akamai adapters; they're listed to show the contract fits them.

| Provider | TTL header | Tag header | Limits | Purge tags | Purge all |
|---|---|---|---|---|---|
| Noop (dev) | `X-Edge-Cache-Control` | `X-Edge-Tags` | 16 KB | logged | logged |
| Cloudflare | `CDN-Cache-Control`, or `Cloudflare-CDN-Cache-Control` when another CDN is downstream | `Cache-Tag`, comma-separated | 16 KB, 1,024 chars per tag, 100 per purge | `POST /zones/{id}/purge_cache` `{"tags":[...]}` | `{"purge_everything":true}`, or the same to `invalidate_cache` for `invalidateAll()` |
| Varnish | `CDN-Cache-Control`, parsed in VCL then unset | `xkey`, space-separated | 7 KB (`http_resp_hdr_len` default 8 KB) | `PURGE` with `xkey`, optional `X-Soft-Purge: 1`, to every node | `BAN` with `X-Ban-Url: .` per store host |
| Fastly | `Surrogate-Control` | `Surrogate-Key`, space-separated | 16 KB, 1 KB per key, 256 per purge | `POST /service/{id}/purge` | `purge_all`, or soft-purge an `all` key |
| Bunny | `Cache-Control` (overrides the browser header) | `CDN-Tag` | 1,024 bytes | `POST /pullzone/{id}/purgeCache` | `purgeCache` with no tag |
| Akamai | `Akamai-Cache-Control` | `Edge-Cache-Tag`, comma-separated | 128 tags, 128 chars | `POST /ccu/v3/invalidate/tag/{network}` | CP code invalidate |

Akamai ignores origin `Cache-Control` by default, so its `getUncacheableHeaders()` must send `Akamai-Cache-Control: no-store`. Cloudflare and Varnish send `CDN-Cache-Control: no-store` too. Neither caches `private` or `Set-Cookie` responses, but core sends a 301 as `max-age=86400` with no cookie, and nothing could purge it.

### Sample VCL

For Varnish 7 with `vmod_xkey`. It's a starting point, not a tested production config. Keep the cookie list in step with Bypass cookies (session names plus `edgecache/general/bypass_cookies`).

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
    # An uncaught exception page goes out before any observer can mark it
    if (beresp.status >= 500 || !beresp.http.CDN-Cache-Control || beresp.http.CDN-Cache-Control ~ "no-store" || beresp.http.Set-Cookie) {
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

On main, pages link a resized image at its file path, `media/catalog/product/cache/<settings hash>/<width>/...`. The web server serves the file once it exists and sends a miss to `Mage_Core_ImageController`, which writes it and answers with `Cache-Control: public, max-age=31536000` and no session (`app/code/core/Mage/Core/controllers/ImageController.php`). The hash covers the format, quality and other settings, so a changed setting gives a new URL. EdgeCache leaves that response alone: it gets what the file will get from the web server on every later request.

`core/index/resize/?t=...&s=...` is the URL of earlier releases. Core keeps it for emails and pages cached before an upgrade and answers a valid signature with a 301 to the file (`app/code/core/Mage/Core/controllers/IndexController.php:54-79`). EdgeCache skips the session for it whatever cookies the request carries (see Sessionless render). That redirect, and its 400, 403 and 404 answers, get the uncacheable headers.

A config save or a Flush Catalog Images Cache (`clean_catalog_images_cache_after`) purges everything, which reaches the untagged image objects at the edge.

## Header rules

| Case | Browser `Cache-Control` | Edge TTL (adapter header) | Tags |
|---|---|---|---|
| Rendered without a session (action on the list), no session started, no cookie or key, 200 HTML | `public, max-age=0, must-revalidate` | `edgecache/general/ttl` (86400), SWR 86400, SIE 604800 | yes |
| The same for `catalogsearch_result_index`, `cms_index_noRoute`, any 404 | same | `short_ttl` (300) | yes |
| Core's image route | core's (`public, max-age=31536000`) | none | no |
| `core_index_resize` (301, 400, 403, 404) | core default | `getUncacheableHeaders()` | no |
| A design exception by User-Agent is set | core default | `getUncacheableHeaders()` | no |
| An action on the list, but the request carries a bypass cookie | core default | `getUncacheableHeaders()` | no |
| A Maho without core's form key fallback | core default | `getUncacheableHeaders()` | no |
| An uncaught exception (500 error page) | core default | none, the CDN decides | no |
| Markdown on a non-`.md` URL | `private, no-store` | `getUncacheableHeaders()` | no |
| POST, checkout, customer, cart, `core/index/formKey` | `private, no-store` or core default | `getUncacheableHeaders()` | no |

The default list is `cms_index_index`, `cms_page_view`, `catalog_category_view`, `catalog_product_view`, `blog_index_index`, `blog_index_category`, `blog_index_view`, `catalogsearch_result_index` and `cms_index_noRoute`.

Anyone holding a session (a guest with a cart, a logged-in customer) goes to origin, because the CDN bypasses on `maho_session`. Edge hits are for visitors without one. If the bypass rule is ever narrowed so session holders get edge hits, logged-in customers would see anonymous prices: a vary cookie (`edgecache_vary`, a hash of the customer group) would be needed first, and isn't built.

## Form key (core)

A sessionless page has `FORM_KEY = ''` and `FORM_KEY_URL` in its head. Core's `js.js` gets a real key from `GET core/index/formKey` (`Mage_Core_Model_Session::FORM_KEY_ROUTE`, which starts the session and answers `{"form_key": ...}` with `private, no-store`) before the first POST leaves: in `mahoFetch()` for a POST, in a capture-phase `submit` listener that holds the submit and resubmits once the key is in, and in a wrapper round `HTMLFormElement.prototype.submit` for `form.submit()`, which fires no event. The key then goes into the `form_key` input, adding one if the form has none. Main prints no key in URLs on the base theme: add to cart, wishlist and compare post it in the body, through a form or `customFormSubmit()` with `{"form_key":""}` data-params.

Checked in a browser against a cold, edge-cacheable page on Maho main (`603008384`) with core's `sessionless-form-key` (`174f93302`, `ce7558461`) and `resize-without-session` (`31b40b6c3`) commits: add to cart (category list and product page), wishlist, compare and newsletter each fetched the key once and posted it.

Nothing puts the visitor's own state back into a cached page. A session holder served one (by a CDN that doesn't bypass on `maho_session`) sees the anonymous page with an empty minicart, though a POST from it still gets their own key. Bypass on the session cookie.

Speculation Rules (off by default) can prefetch footer links such as My Account, which starts a session. If it's on, exclude session-starting paths from its rules.

Search autocomplete starts a session too: typing in the header search box calls `catalogsearch/ajax/suggest` (`maho-autocomplete.js`), which isn't on the list, so from then on that visitor goes to origin.

## Maho notes

- The purge observer uses `catalogrule_before_apply` (`app/code/core/Mage/CatalogRule/Model/Action/Index/Refresh.php:88`), which fires before the rule prices are written, so a page fetched during a long reindex can be cached with the old prices. `catalogrule_after_apply` (same file, line 574) fires once they are, and would be the better trigger.
- Sitemap, robots and llms controllers set `FLAG_NO_START_SESSION` themselves. They aren't on the list, so they get the uncacheable headers.
