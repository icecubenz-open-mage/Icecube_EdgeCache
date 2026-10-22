# Icecube_EdgeCache

Edge caching for [Maho](https://mahocommerce.com), built on Maho's sessionless pages option (`web/sessionless`), [proposed upstream](https://github.com/mahocommerce/maho/discussions/608). With that option, Maho renders a new visitor's storefront page without a session, so it carries no `Set-Cookie` and no form key. This module marks those responses cacheable for a CDN or reverse proxy, tags them with the products, categories, CMS pages and blocks they show, and purges those tags through Maho's queue when the admin saves something.

- Observers only: no class rewrites, no core edits, no frontend JavaScript. Core's `js.js` fetches a form key on the first POST.
- Adapters for Cloudflare and Varnish (xkey), plus a no-op adapter that logs purges. Adding another CDN is one class and one config node.

The design notes, including the header rules, the purge and retry behaviour, known gaps and a sample VCL, are in [docs/design/edge-cache.md](docs/design/edge-cache.md).

## Requirements

- Maho 26.9 or later, or `dev-main`. The module caches only on a Maho that has the sessionless pages option, which no release has yet. On one without it, the module installs and does nothing: every storefront response gets the uncacheable headers. On Maho 26.9.2, stay on 0.1.x (`composer require icecube/edge-cache:^0.1`), which caches without the option.
- PHP 8.5
- A running queue worker (`./maho queue:work`). Purges are queue messages, and without a worker they sit in the queue table. The module routes its `edgecache` queue to Maho's `fast` pool.

## Install

The package isn't on Packagist, so add the repository first:

```sh
composer config repositories.edge-cache vcs https://github.com/icecubenz-open-mage/Icecube_EdgeCache
composer require icecube/edge-cache:^0.2
./maho cache:flush
```

It's a `maho-module` package, so maho-composer-plugin picks it up from `vendor/` like any other module. The module is off until you enable it.

Then turn on System > Configuration > General > Web > Pages Without a Session (`web/sessionless/enabled`). With that off nothing renders without a session, and this module caches nothing. Core's route list there decides which pages are cached; add `catalogsearch/result/index` and `cms/index/noRoute` for search results and 404s, and your blog's routes if you have one.

Nothing is cached while the store has a design exception by User-Agent (System > Configuration > Design), since the CDN would hand one visitor's theme to everyone.

## Upgrading from 0.1.x

0.2.0 is a breaking change, so `composer update` keeps a project on `^0.1`. Move with `composer require icecube/edge-cache:^0.2`. The module no longer renders pages without a session or swaps the form key itself: the sentinel, `edgecache.js`, the inline head script, `/edgecache/state` and the `edgecache_s` cookie are gone.

- `edgecache/general/cacheable_actions` is replaced by core's `web/sessionless/routes`, written as `module/controller/action`.
- `edgecache/general/bypass_cookies` is replaced by core's `web/sessionless/cookies`.
- Both old settings are ignored. Their rows in `core_config_data` can be deleted.

## Configuration

System > Configuration > Advanced > Edge Cache.

| Path | Default | What |
| --- | --- | --- |
| `edgecache/general/enabled` | `0` | Turns the module on. Website and store view scope work too |
| `edgecache/general/adapter` | `noop` | `noop`, `cloudflare` or `varnish` |
| `edgecache/general/ttl` | `86400` | Edge TTL in seconds. Browsers always revalidate |
| `edgecache/general/stale_while_revalidate` | `86400` | Seconds |
| `edgecache/general/stale_if_error` | `604800` | Seconds |
| `edgecache/general/short_ttl_actions` | search results, 404 | Full action names no tag can purge, so they get the short TTL. They're only cached once core's route list has them |
| `edgecache/general/short_ttl` | `300` | Seconds. `0` stops those pages being cached |
| `edgecache/general/tag_prefix` | empty | Keeps environments that share one CDN zone apart, e.g. `stg-` |
| `edgecache/general/purge_delay` | `15` | Seconds a purge waits so a burst of saves goes out as one call. See the design notes for sizing it to your CDN's rate limit |
| `edgecache/noop/log_purges` | `1` | Write purges to `var/log/edgecache.log` |
| `edgecache/cloudflare/zone_id` | | Zone ID |
| `edgecache/cloudflare/api_token` | | API token with the Cache Purge permission only. Stored encrypted |
| `edgecache/cloudflare/header` | `CDN-Cache-Control` | Use `Cloudflare-CDN-Cache-Control` when another CDN sits behind Cloudflare |
| `edgecache/varnish/nodes` | | Node URLs, one per line, e.g. `http://10.0.0.5:6081`. Every node gets every purge |
| `edgecache/varnish/soft_purge` | `0` | Sends `X-Soft-Purge: 1` so purged pages stay servable as stale while Varnish refetches |

## Adapters

| Adapter | TTL header | Tag header | Purges |
| --- | --- | --- | --- |
| Noop | `X-Edge-Cache-Control` | `X-Edge-Tags` | Logged only. For development and tests |
| Cloudflare | `CDN-Cache-Control` | `Cache-Tag` | Zone purge API, by tag, URL or everything |
| Varnish | `CDN-Cache-Control` | `xkey` | `PURGE` with `xkey` to every node, `BAN` per store host for everything. Needs `vmod_xkey` |

Cloudflare doesn't cache HTML by default. Add a Cache Rule that makes the storefront eligible for cache and respects the origin's cache headers, and exclude admin and API paths from it.

For Varnish, start from the sample VCL in the design notes.

## The CDN must bypass on the same cookies

Core only renders a page without a session for a request with none of these cookies. The CDN has to skip its cache for exactly the same set, or it will serve the shared page to a visitor the origin would have rendered differently (a logged-in customer, another store view, someone who declined cookies).

Bypass the cache when the request carries any of:

- `maho_session`, `frontend`, `om_frontend` (the session cookies)
- every name in core's `web/sessionless/cookies`

In Cloudflare that's a condition on the Cache Rule (or a separate Bypass cache rule) on `http.cookie`. The sample VCL does it in `vcl_recv`. If you add a cookie to core's list, add it at the CDN too.

## Adding a CDN

Implement `Icecube_EdgeCache_Model_Adapter_AdapterInterface` (extend `Icecube_EdgeCache_Model_Adapter_AbstractHttp` for HTTP purges) and register it from any module:

```xml
<config>
    <global>
        <edgecache>
            <adapters>
                <mycdn>
                    <class>mymodule/edgecache_adapter</class>
                    <label>My CDN</label>
                </mycdn>
            </adapters>
        </edgecache>
    </global>
</config>
```

It then shows up in the CDN select. The design notes describe the contract and the header and purge details for Fastly, Bunny and Akamai.

## Tests

The Pest tests in `tests/` boot a real Maho install, so they run from a Maho project that has this package installed from source (`--prefer-source` or a path repository), with a fresh test store installed at `http://localhost/`. Point Pest at `vendor/icecube/edge-cache/tests` with a test case that calls `Mage::app()` in `setUp()` and `Mage::reset()` in `tearDown()`, and define a global `currentStore()` that returns `Mage::app()->getStore()`.

## Licence

[OSL-3.0](LICENSE)
