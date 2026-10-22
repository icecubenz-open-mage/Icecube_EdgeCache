<?php

/**
 * Tells edgecache.js where to fetch the visitor's state, and holds key-carrying clicks until it runs.
 *
 * SPDX-FileCopyrightText: 2026 Icecube
 * SPDX-License-Identifier: OSL-3.0
 * @package Icecube_EdgeCache
 */

declare(strict_types=1);

class Icecube_EdgeCache_Block_Config extends Mage_Core_Block_Abstract
{
    /**
     * edgecache.js is deferred so it doesn't block render, which leaves a gap before it runs
     * where a click could post the sentinel. This queues any click or submit that would carry
     * one into window.EDGECACHE_HELD, and edgecache.js replays them once it has a real key.
     * A control clicked again while it waits isn't queued twice, or add to cart would run twice.
     * Deferred scripts have run, or failed to load, by DOMContentLoaded, so if edgecache.js
     * hasn't taken the queue by then the guard replays it as is: the post carries the
     * sentinel and core shows its invalid form key message, rather than the click vanishing.
     * The sentinel is split so the page doesn't look as if it carries one.
     */
    private const GUARD = <<<'JS'
        (()=>{const q=window.EDGECACHE_HELD=[],h=e=>{if(window.EDGECACHE_HELD!==q||e.button>0||e.ctrlKey||e.metaKey||e.shiftKey||e.altKey||!(e.target instanceof Element))return;const t=e.type==='submit'?e.target:e.target.closest('a,button,input[type=submit],input[type=image],[onclick],[data-params]'),f=t&&(t.form||t.closest('form'));if(t&&(t.outerHTML+(f?f.outerHTML:'')).includes('__EDGE'+'FK__')){e.preventDefault();e.stopImmediatePropagation();if(!q.some(x=>x[1]===t))q.push([e.type,t,e.submitter])}};addEventListener('click',h,true);addEventListener('submit',h,true);addEventListener('DOMContentLoaded',()=>{if(window.EDGECACHE_HELD!==q)return;window.EDGECACHE_HELD=null;for(const[y,t,s]of q)try{y==='submit'?t.requestSubmit(s??undefined):t.click()}catch{y==='submit'&&t.requestSubmit()}})})();
        JS;

    #[\Override]
    protected function _toHtml(): string
    {
        if (!Mage::helper('edgecache')->isEnabled()) {
            return '';
        }

        return sprintf(
            '<script>window.EDGECACHE_STATE_URL = %s;%s</script>',
            json_encode($this->getUrl('edgecache/state'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            self::GUARD,
        );
    }
}
