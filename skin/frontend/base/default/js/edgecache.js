// SPDX-FileCopyrightText: 2026 Icecube
// SPDX-License-Identifier: OSL-3.0
//
// Loads only on a page rendered without a session, which the edge serves to everyone, so no
// form key in it belongs to the visitor: core prints an empty one. Before the first POST leaves,
// this fetches a key from EDGECACHE_FORM_KEY_URL, which starts the visitor's session, and writes
// it into FORM_KEY and every form of the page. It wraps what core's js.js already has for form
// keys: mahoFetch(), the submit event and HTMLFormElement.prototype.submit.

(() => {
    'use strict';

    let formKey = '';
    let request = null;
    /** Forms that submit again once the key is in, which must not wait a second time */
    const resubmitting = new WeakSet();

    function isThisSite(url) {
        return URL.canParse(url, location.href) && new URL(url, location.href).origin === location.origin;
    }

    function postsToThisSite(form) {
        return form.getAttribute('method')?.toLowerCase() === 'post' && isThisSite(form.getAttribute('action') ?? '');
    }

    function fill(form) {
        if (formKey === '' || !postsToThisSite(form)) {
            return;
        }
        const inputs = form.querySelectorAll('input[name="form_key"]');
        inputs.forEach((input) => {
            input.value = formKey;
        });
        if (inputs.length === 0 && typeof addFormKey === 'function') {
            addFormKey(form);
        }
    }

    /**
     * @returns {Promise<string>} the key, or '' after a failed fetch, which the next POST retries
     */
    function load() {
        const url = window.EDGECACHE_FORM_KEY_URL;
        if (formKey !== '' || typeof url !== 'string') {
            return Promise.resolve(formKey);
        }
        request ??= fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error(`Status ${response.status}`))))
            .then((result) => {
                if (typeof result?.form_key !== 'string' || result.form_key === '') {
                    throw new Error('No form key in the response');
                }
                formKey = result.form_key;
                window.FORM_KEY = formKey;
                document.querySelectorAll('form').forEach(fill);
                return formKey;
            })
            .catch(() => {
                request = null;
                return '';
            });
        return request;
    }

    function setBodyFormKey(options) {
        options.body ??= new URLSearchParams();
        if (options.body instanceof URLSearchParams || options.body instanceof FormData) {
            options.body.set('form_key', formKey);
        } else if (typeof options.body === 'string') {
            try {
                const parsed = JSON.parse(options.body);
                if (typeof parsed === 'object' && parsed !== null && !Array.isArray(parsed)) {
                    parsed.form_key = formKey;
                    options.body = JSON.stringify(parsed);
                }
            } catch {
                // Not JSON, so no form key to set
            }
        }
    }

    if (typeof window.mahoFetch === 'function') {
        const mahoFetch = window.mahoFetch;
        window.mahoFetch = async function (url, options) {
            if (options?.method?.toUpperCase() === 'POST' && isThisSite(String(url))) {
                await load();
                if (formKey !== '') {
                    setBodyFormKey(options);
                }
            }
            return mahoFetch(url, options);
        };
    }

    // After js.js's own submit listener, which adds FORM_KEY to a form without a form_key field
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !postsToThisSite(form) || resubmitting.has(form)) {
            return;
        }
        if (formKey !== '') {
            fill(form);
            return;
        }
        // Submitted again once the key is in, so the form's own submit listeners run then. After a
        // failed fetch it posts without a key, and the server refuses it as any form without one.
        event.preventDefault();
        event.stopImmediatePropagation();
        const submitter = event.submitter;
        load().then(() => {
            resubmitting.add(form);
            try {
                form.requestSubmit(submitter?.isConnected && submitter.form === form ? submitter : null);
            } catch {
                form.requestSubmit();
            } finally {
                resubmitting.delete(form);
            }
        });
    }, true);

    // form.submit() fires no submit event
    const submit = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function () {
        if (formKey !== '' || !postsToThisSite(this)) {
            fill(this);
            return submit.call(this);
        }
        load().then(() => {
            fill(this);
            submit.call(this);
        });
    };
})();
