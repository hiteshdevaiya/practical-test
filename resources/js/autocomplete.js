/**
 * Autocomplete demo controller.
 *
 * Responsibilities:
 *  - debounce keystrokes (200 ms) and enforce the minimum query length;
 *  - cancel stale in-flight searches so only the latest response renders;
 *  - keep a short-lived client cache to avoid repeat requests on backspace;
 *  - support keyboard navigation and ARIA combobox/listbox semantics;
 *  - bill exactly one usage through POST /api/v1/search/select per selection,
 *    while never billing searches.
 *
 * All server-provided text is rendered with textContent (never innerHTML),
 * so record content cannot inject markup into the page.
 */

const CACHE_TTL_MS = 60_000;
const DEBOUNCE_MS = 200;

/**
 * Escape a string for safe use inside a regular expression.
 *
 * @param {string} value Raw text.
 * @returns {string} Escaped text.
 */
function escapeRegExp(value) {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * Build a highlight pattern from normalized query tokens.
 *
 * @param {string} query Normalized query.
 * @returns {RegExp|null} Case-insensitive pattern or null.
 */
function highlightPattern(query) {
    const tokens = [...new Set(query.split(' ').filter((token) => token.length > 0))];

    if (tokens.length === 0) {
        return null;
    }

    return new RegExp(`(${tokens.map(escapeRegExp).join('|')})`, 'gi');
}

/**
 * Convert a record content string into a fragment with <mark> highlights.
 *
 * @param {string} content Record content.
 * @param {RegExp|null} pattern Highlight pattern.
 * @returns {DocumentFragment} Safe DOM fragment.
 */
function highlightedFragment(content, pattern) {
    const fragment = document.createDocumentFragment();

    if (pattern === null) {
        fragment.append(document.createTextNode(content));

        return fragment;
    }

    const parts = content.split(pattern);

    parts.forEach((part, index) => {
        if (part === '') {
            return;
        }

        if (index % 2 === 1) {
            const mark = document.createElement('mark');
            mark.className = 'rounded bg-sky-200 px-0.5 text-sky-900';
            mark.textContent = part;
            fragment.append(mark);
        } else {
            fragment.append(document.createTextNode(part));
        }
    });

    return fragment;
}

/**
 * Parse a Server-Timing header into a name -> description map.
 *
 * @param {string} header Server-Timing header value.
 * @returns {Record<string, string>} Parsed metrics.
 */
function parseServerTiming(header) {
    const metrics = {};

    if (!header) {
        return metrics;
    }

    header.split(',').forEach((segment) => {
        const [namePart, ...attributes] = segment.trim().split(';');
        const name = namePart.trim();

        if (!name) {
            return;
        }

        const duration = attributes.find((attribute) => attribute.trim().startsWith('dur='));
        const description = attributes.find((attribute) => attribute.trim().startsWith('desc='));

        if (duration) {
            metrics[name] = duration.trim().slice(4);
        } else if (description) {
            metrics[name] = description.trim().slice(5).replaceAll('"', '');
        }
    });

    return metrics;
}

/**
 * Initialise the autocomplete UI.
 */
export function initAutocomplete() {
    const config = window.__AUTOCOMPLETE_DEMO__ ?? {};
    const input = document.querySelector('#search');
    const form = document.querySelector('#search-form');
    const list = document.querySelector('#suggestions');
    const spinner = document.querySelector('#search-spinner');
    const correction = document.querySelector('#correction');
    const message = document.querySelector('#message');
    const billable = document.querySelector('#billable-usage');
    const searches = document.querySelector('#search-requests');
    const timing = document.querySelector('#timing');
    const timingDetail = document.querySelector('#timing-detail');

    if (!input || !form || !list) {
        return;
    }

    const minLength = Number(config.minLength ?? 3);
    const limit = Number(config.limit ?? 10);
    const apiKey = String(config.apiKey ?? '');
    const headers = {
        'X-API-Key': apiKey,
        Accept: 'application/json',
    };

    /** @type {Map<string, {body: object, expiresAt: number}>} */
    let cache = new Map();
    let controller = null;
    let debounceTimer = null;
    let activeIndex = -1;
    let suggestions = [];
    let selecting = false;
    let searchCount = 0;

    /**
     * Show an inline message.
     *
     * @param {string} text Message text.
     * @param {'error'|'info'} kind Message kind.
     */
    function showMessage(text, kind = 'error') {
        message.textContent = text;
        message.classList.toggle('hidden', text === '');
        message.classList.toggle('border-rose-300', kind === 'error');
        message.classList.toggle('bg-rose-50', kind === 'error');
        message.classList.toggle('text-rose-900', kind === 'error');
        message.classList.toggle('border-sky-300', kind === 'info');
        message.classList.toggle('bg-sky-50', kind === 'info');
        message.classList.toggle('text-sky-900', kind === 'info');
    }

    /**
     * Update the billable usage counter.
     *
     * @param {number|null} value New total.
     */
    function setBillable(value) {
        if (typeof value === 'number' && Number.isFinite(value)) {
            billable.textContent = String(value);
        }
    }

    /**
     * Update the free search counter.
     *
     * @param {number} value New total.
     */
    function setSearches(value) {
        searchCount = Math.max(0, value);
        searches.textContent = String(searchCount);
    }

    /**
     * Render request timings in the status bar.
     *
     * @param {Headers} responseHeaders Response headers.
     */
    function renderTiming(responseHeaders) {
        const metrics = parseServerTiming(responseHeaders.get('Server-Timing'));

        if (metrics.app) {
            timing.textContent = `${Number(metrics.app).toFixed(1)} ms`;
        }

        const parts = [];

        if (metrics.db) {
            parts.push(`db ${Number(metrics.db).toFixed(1)} ms`);
        }

        if (metrics.cache) {
            parts.push(`cache ${metrics.cache}`);
        }

        timingDetail.textContent = parts.length > 0 ? parts.join(' · ') : 'server / database / cache';
    }

    /**
     * Close the suggestion list.
     */
    function closeList() {
        list.classList.add('hidden');
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        activeIndex = -1;
    }

    /**
     * Move the active option.
     *
     * @param {number} delta Direction.
     */
    function moveActive(delta) {
        if (suggestions.length === 0) {
            return;
        }

        activeIndex = (activeIndex + delta + suggestions.length) % suggestions.length;

        [...list.children].forEach((item, index) => {
            const selected = index === activeIndex;
            item.setAttribute('aria-selected', selected ? 'true' : 'false');
            item.classList.toggle('bg-slate-100', selected);
        });

        const active = list.children[activeIndex];

        if (active) {
            input.setAttribute('aria-activedescendant', active.id);
            active.scrollIntoView({ block: 'nearest' });
        }
    }

    /**
     * Render suggestions returned by the API.
     *
     * @param {object} body Search response.
     */
    function render(body) {
        suggestions = Array.isArray(body.data) ? body.data : [];
        list.replaceChildren();
        activeIndex = -1;

        if (body.corrected_query && body.corrected_query !== body.query) {
            correction.textContent = `Showing results for “${body.corrected_query}”`;
            correction.classList.remove('hidden');
        } else {
            correction.classList.add('hidden');
        }

        if (suggestions.length === 0) {
            closeList();
            showMessage(input.value.trim().length < minLength ? '' : 'No suggestions found.', 'info');

            return;
        }

        showMessage('');

        const pattern = highlightPattern(String(body.query ?? ''));

        suggestions.forEach((suggestion, index) => {
            const item = document.createElement('li');
            item.id = `suggestion-${index}`;
            item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', 'false');
            item.dataset.index = String(index);
            item.className = 'flex cursor-pointer items-center justify-between gap-3 px-4 py-2.5 text-sm text-slate-800 hover:bg-slate-100';

            // Wrap the highlighted text in a single element: appending the
            // fragment directly would make every text node and <mark> a flex
            // item, spreading the suggestion across the row.
            const label = document.createElement('span');
            label.className = 'min-w-0 truncate';
            label.append(highlightedFragment(String(suggestion.content), pattern));
            item.append(label);

            if (suggestion.match === 'corrected') {
                const badge = document.createElement('span');
                badge.className = 'shrink-0 rounded-full border border-sky-300 px-2 py-0.5 text-[10px] uppercase tracking-wide text-sky-700';
                badge.textContent = 'corrected';
                item.append(badge);
            }

            list.append(item);
        });

        list.classList.remove('hidden');
        input.setAttribute('aria-expanded', 'true');
    }

    /**
     * Execute a search request.
     *
     * @param {string} query Normalized query.
     */
    async function suggest(query) {
        if (query.length < minLength) {
            closeList();
            correction.classList.add('hidden');
            showMessage('');
            spinner.classList.add('hidden');

            return;
        }

        if (!apiKey) {
            showMessage('No demo API key configured. Run api-client:create --publish.');

            return;
        }

        const cached = cache.get(query);

        if (cached && cached.expiresAt > Date.now()) {
            render(cached.body);
            spinner.classList.add('hidden');

            return;
        }

        controller?.abort();
        controller = new AbortController();
        spinner.classList.remove('hidden');

        try {
            const response = await fetch(`/api/v1/search?q=${encodeURIComponent(query)}&limit=${limit}`, {
                headers,
                signal: controller.signal,
            });

            if (response.status === 401) {
                showMessage('API key rejected. Create a demo key with api-client:create --publish.');

                return;
            }

            if (response.status === 429) {
                const retryAfter = response.headers.get('Retry-After');
                showMessage(`Rate limit reached. Try again${retryAfter ? ` in ${retryAfter}s` : ' shortly'}.`);

                return;
            }

            if (!response.ok) {
                const body = await response.json().catch(() => ({}));
                showMessage(body.message ?? `Request failed (${response.status}).`);

                return;
            }

            const body = await response.json();
            cache.set(query, { body, expiresAt: Date.now() + CACHE_TTL_MS });

            // Drop expired entries so a long session cannot grow without bound.
            for (const [key, value] of cache) {
                if (value.expiresAt <= Date.now()) {
                    cache.delete(key);
                }
            }

            setSearches(searchCount + 1);
            render(body);
            renderTiming(response.headers);
        } catch (error) {
            if (error.name !== 'AbortError') {
                showMessage('Network error while searching. Check the server log.');
            }
        } finally {
            spinner.classList.add('hidden');
        }
    }

    /**
     * Refresh usage totals from the API.
     */
    async function refreshUsage() {
        if (!apiKey) {
            return;
        }

        try {
            const response = await fetch('/api/v1/usage', { headers });

            if (!response.ok) {
                return;
            }

            const body = await response.json();
            setBillable(Number(body.billable_usage_count ?? 0));
            setSearches(Number(body.search_requests ?? 0));
        } catch {
            // The status bar is informational; a failure must not break search.
        }
    }

    /**
     * Bill one selection and update the UI.
     *
     * @param {object} suggestion Selected suggestion.
     */
    async function select(suggestion) {
        if (selecting || !suggestion?.selection_token) {
            return;
        }

        selecting = true;
        input.disabled = true;
        list.setAttribute('aria-busy', 'true');
        closeList();

        try {
            const response = await fetch('/api/v1/search/select', {
                method: 'POST',
                headers: { ...headers, 'Content-Type': 'application/json' },
                body: JSON.stringify({ selection_token: suggestion.selection_token }),
            });

            if (response.status === 401) {
                showMessage('API key rejected. Run api-client:create --publish.');

                return;
            }

            if (response.status === 403 || response.status === 422) {
                showMessage('This selection token is invalid or expired. Search again.');

                return;
            }

            if (response.status === 404) {
                showMessage('The selected record no longer exists.');

                return;
            }

            if (!response.ok) {
                const body = await response.json().catch(() => ({}));
                showMessage(body.message ?? `Selection failed (${response.status}).`);

                return;
            }

            const body = await response.json();
            input.value = body.data?.content ?? suggestion.content;
            cache = new Map();
            setBillable(Number(body.usage?.billable_usage_count ?? 0));
            showMessage(
                body.usage?.counted
                    ? `Selected “${input.value}”. One billable usage recorded.`
                    : `This selection was already counted; the total is unchanged.`,
                'info',
            );
        } catch {
            showMessage('Network error while recording the selection.');
        } finally {
            selecting = false;
            input.disabled = false;
            list.removeAttribute('aria-busy');
            input.focus();
        }
    }

    input.addEventListener('input', () => {
        clearTimeout(debounceTimer);
        const query = input.value.trim().toLowerCase();
        debounceTimer = window.setTimeout(() => suggest(query), DEBOUNCE_MS);
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();

            if (list.classList.contains('hidden')) {
                const query = input.value.trim().toLowerCase();
                suggest(query).then(() => moveActive(1));
            } else {
                moveActive(1);
            }
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            moveActive(-1);
        } else if (event.key === 'Enter') {
            if (activeIndex >= 0 && suggestions[activeIndex]) {
                event.preventDefault();
                select(suggestions[activeIndex]);
            }
        } else if (event.key === 'Escape') {
            closeList();
        }
    });

    list.addEventListener('mousedown', (event) => {
        // Prevent the input from losing focus before the click is handled.
        event.preventDefault();
    });

    list.addEventListener('click', (event) => {
        const item = event.target.closest('[role="option"]');

        if (!item) {
            return;
        }

        const suggestion = suggestions[Number(item.dataset.index)];

        if (suggestion) {
            select(suggestion);
        }
    });

    form.addEventListener('submit', (event) => {
        event.preventDefault();

        if (activeIndex >= 0 && suggestions[activeIndex]) {
            select(suggestions[activeIndex]);
        }
    });

    document.addEventListener('click', (event) => {
        if (!form.contains(event.target)) {
            closeList();
        }
    });

    void refreshUsage();
}

export default initAutocomplete;
