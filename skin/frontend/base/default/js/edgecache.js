// SPDX-FileCopyrightText: 2026 Icecube
// SPDX-License-Identifier: OSL-3.0
//
// Puts the visitor's own state back into a page the edge served to everyone:
// the form key (replaced by a sentinel in the shared HTML) and the minicart.
// /edgecache/state starts a session, so it is only called when the visitor
// already has one (the edgecache_s marker cookie) or is about to need one.

(() => {
    'use strict';

    const SENTINEL = '__EDGEFK__';
    const MARKER = /(?:^|;\s*)edgecache_s=1(?:;|$)/;
    const ACTIONABLE = 'a, button, input[type="submit"], input[type="image"], [onclick], [data-params]';

    let pending = null;
    let settled = false;
    let shared = null;

    // Only a page rendered for the edge carries the sentinel, and it always has one
    // (the minicart script holds the form key), so this tells cached pages apart.
    function isSharedPage() {
        shared ??= document.documentElement.innerHTML.includes(SENTINEL);
        return shared;
    }

    function stateUrl() {
        return window.EDGECACHE_STATE_URL;
    }

    function hasSentinel(el) {
        return Array.from(el.attributes).some((attr) => attr.value.includes(SENTINEL))
            || (typeof el.value === 'string' && el.value.includes(SENTINEL));
    }

    function needsKey(target) {
        const el = target instanceof Element ? target.closest(ACTIONABLE) : null;
        if (!el) {
            return false;
        }
        if (hasSentinel(el)) {
            return true;
        }
        const form = el.form ?? el.closest('form');
        return !!form && formNeedsKey(form);
    }

    function formNeedsKey(form) {
        return hasSentinel(form) || Array.from(form.elements).some(hasSentinel);
    }

    function replaceSentinel(formKey) {
        for (const el of document.querySelectorAll('*')) {
            for (const attr of Array.from(el.attributes)) {
                if (attr.value.includes(SENTINEL)) {
                    el.setAttribute(attr.name, attr.value.replaceAll(SENTINEL, formKey));
                }
            }
            if (typeof el.value === 'string' && el.value.includes(SENTINEL)) {
                el.value = el.value.replaceAll(SENTINEL, formKey);
            }
        }
    }

    function applyState(state) {
        window.FORM_KEY = state.formKey;
        replaceSentinel(state.formKey);

        const minicart = window.minicart;
        if (minicart) {
            minicart.formKey = state.formKey;
            const qty = Number(state.cart?.qty) || 0;
            minicart.updateCartQty(qty);
            if (qty > 0) {
                minicart.refresh();
            }
        }

        document.dispatchEvent(new CustomEvent('edgecache:hydrated', { detail: state }));
    }

    function hydrate() {
        if (!isSharedPage()) {
            settled = true;
            return Promise.resolve();
        }
        pending ??= mahoFetch(stateUrl(), { loaderArea: false })
            .then((state) => {
                if (state && typeof state.formKey === 'string') {
                    applyState(state);
                }
            })
            .catch(() => {
                // A page that cannot hydrate still works for browsing; a POST from it fails
                // the form key check and shows core's message, which is the right outcome.
            })
            .finally(() => {
                settled = true;
            });
        return pending;
    }

    function startOnIntent(event) {
        if (settled || !(event.target instanceof Element)) {
            return;
        }
        if (event.target.closest('form') || needsKey(event.target)) {
            hydrate();
        }
    }

    // Capture phase, so an inline onclick (customFormSubmit, productAddToCartForm.submit)
    // never runs with the sentinel still in place; the click is replayed once hydrated.
    function holdClick(event) {
        if (settled || !needsKey(event.target)) {
            return;
        }
        const el = event.target.closest(ACTIONABLE);
        event.preventDefault();
        event.stopImmediatePropagation();
        hydrate().then(() => el.click());
    }

    function holdSubmit(event) {
        const form = event.target;
        if (settled || !(form instanceof HTMLFormElement) || !formNeedsKey(form)) {
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        hydrate().then(() => form.requestSubmit(event.submitter ?? undefined));
    }

    document.addEventListener('pointerdown', startOnIntent, true);
    document.addEventListener('focusin', startOnIntent, true);
    document.addEventListener('click', holdClick, true);
    document.addEventListener('submit', holdSubmit, true);

    mahoOnReady(() => {
        if (MARKER.test(document.cookie) && isSharedPage()) {
            hydrate();
        }
    });
})();
