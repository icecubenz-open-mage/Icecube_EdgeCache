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
    // Where core prints a form key: a /form_key/ URL segment, a form_key= query and the
    // "form_key":"..." of data-params JSON, in these attributes only. A value attribute is
    // left out because a search box reflects ?q=form_key=__EDGEFK__ into one.
    const FORM_KEY_CONTEXT = /(form_key(?:\/|=|\\?"\s*:\s*\\?"))__EDGEFK__/;
    const KEY_ATTRIBUTES = ['href', 'action', 'onclick', 'data-params'];
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

    function isKeyInput(el) {
        return el instanceof HTMLInputElement && el.name === 'form_key';
    }

    function keyAttributes(el) {
        return KEY_ATTRIBUTES.map((name) => el.getAttributeNode(name)).filter((attr) => attr !== null);
    }

    function hasSentinel(el) {
        return (isKeyInput(el) && el.value === SENTINEL)
            || keyAttributes(el).some((attr) => FORM_KEY_CONTEXT.test(attr.value));
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
        const everywhere = new RegExp(FORM_KEY_CONTEXT, 'g');
        const selector = [...KEY_ATTRIBUTES.map((name) => `[${name}]`), 'input[name="form_key"]'].join(', ');
        for (const el of document.querySelectorAll(selector)) {
            for (const attr of keyAttributes(el)) {
                if (FORM_KEY_CONTEXT.test(attr.value)) {
                    el.setAttribute(attr.name, attr.value.replace(everywhere, (match, prefix) => prefix + formKey));
                }
            }
            if (isKeyInput(el) && el.value === SENTINEL) {
                el.value = formKey;
                el.setAttribute('value', formKey);
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

    // Hydrating starts a session, and a visitor with one bypasses the edge from then on,
    // so only for a control or form that posts a key: not the search box or newsletter.
    function startOnIntent(event) {
        if (settled || !(event.target instanceof Element)) {
            return;
        }
        const form = event.target.closest('form');
        if (needsKey(event.target) || (form && formNeedsKey(form))) {
            hydrate();
        }
    }

    // Capture phase, so an inline onclick (customFormSubmit, productAddToCartForm.submit)
    // never runs with the sentinel still in place; the click is replayed once hydrated.
    function holdClick(event) {
        // A replayed click is a plain one, so leave a new-tab or middle click to the browser.
        const modified = event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey;
        if (settled || modified || !needsKey(event.target)) {
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

    // Clicks and submits the inline guard in <head> held while this script was loading.
    function replayHeld() {
        const held = window.EDGECACHE_HELD;
        window.EDGECACHE_HELD = null;
        if (!Array.isArray(held) || held.length === 0) {
            return;
        }
        hydrate().then(() => {
            for (const [type, el, submitter] of held) {
                if (type === 'submit') {
                    el.requestSubmit(submitter ?? undefined);
                } else {
                    el.click();
                }
            }
        });
    }

    replayHeld();

    mahoOnReady(() => {
        if (MARKER.test(document.cookie) && isSharedPage()) {
            hydrate();
        }
    });
})();
