<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Autocomplete Search Demo</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-slate-950 text-slate-100 antialiased">
    <div class="relative mx-auto flex min-h-screen max-w-5xl flex-col gap-8 px-5 py-10 sm:px-8">
        <header class="flex flex-col gap-2">
            <p class="text-xs font-semibold uppercase tracking-[0.25em] text-sky-400">Laravel · PostgreSQL · pg_trgm</p>
            <h1 class="text-3xl font-semibold tracking-tight text-white sm:text-4xl">Autocomplete search demo</h1>
            <p class="max-w-2xl text-sm leading-6 text-slate-400">
                Type at least {{ $minLength }} characters. Searching is always free; a billable API usage is
                recorded only when you select a suggestion from the dropdown.
            </p>
        </header>

        @if ($apiKey === '')
            <div class="rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-200" role="alert">
                No demo API key is configured. Run
                <code class="rounded bg-black/30 px-1.5 py-0.5 font-mono text-xs">php artisan api-client:create "Demo Client" --publish</code>
                and reload this page.
            </div>
        @endif

        <main class="flex flex-col gap-6">
            <section class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5 shadow-2xl shadow-black/30 sm:p-7">
                <form id="search-form" class="relative" role="search" autocomplete="off" novalidate>
                    <label for="search" class="mb-2 block text-sm font-medium text-slate-300">
                        Search records
                    </label>

                    <div class="relative">
                        <input
                            id="search"
                            name="q"
                            type="search"
                            inputmode="search"
                            placeholder="e.g. london road, londn road, roa lond"
                            class="w-full rounded-xl border border-slate-700 bg-slate-950 px-4 py-3.5 pr-12 text-base text-white placeholder:text-slate-500 focus:border-sky-500 focus:outline-none focus:ring-2 focus:ring-sky-500/40"
                            role="combobox"
                            aria-autocomplete="list"
                            aria-expanded="false"
                            aria-controls="suggestions"
                            aria-describedby="search-hint"
                            autocomplete="off"
                            spellcheck="false"
                        >
                        <span id="search-spinner" class="pointer-events-none absolute right-4 top-1/2 hidden -translate-y-1/2" aria-hidden="true">
                            <svg class="h-5 w-5 animate-spin text-sky-400" viewBox="0 0 24 24" fill="none">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                            </svg>
                        </span>

                        <ul
                            id="suggestions"
                            role="listbox"
                            aria-label="Search suggestions"
                            class="absolute z-20 mt-2 hidden max-h-80 w-full overflow-y-auto rounded-xl border border-slate-700 bg-slate-900 py-1 shadow-2xl"
                        ></ul>
                    </div>

                    <p id="search-hint" class="mt-2 text-xs text-slate-500">
                        ↑/↓ to navigate · Enter to select · Esc to close
                    </p>
                </form>

                <div id="correction" class="mt-4 hidden rounded-lg border border-sky-500/30 bg-sky-500/10 px-3 py-2 text-sm text-sky-200" role="status"></div>
                <p id="message" class="mt-4 hidden rounded-lg border border-rose-500/40 bg-rose-500/10 px-3 py-2 text-sm text-rose-200" role="alert"></p>
            </section>

            <section class="grid gap-4 sm:grid-cols-3" aria-label="Live metrics">
                <div class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Billable usage</p>
                    <p id="billable-usage" class="mt-2 text-3xl font-semibold tabular-nums text-emerald-400">0</p>
                    <p class="mt-1 text-xs text-slate-500">Only selections count</p>
                </div>
                <div class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Free searches</p>
                    <p id="search-requests" class="mt-2 text-3xl font-semibold tabular-nums text-sky-400">0</p>
                    <p class="mt-1 text-xs text-slate-500">Typing never bills</p>
                </div>
                <div class="rounded-2xl border border-slate-800 bg-slate-900/70 p-5">
                    <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Last request</p>
                    <p id="timing" class="mt-2 text-3xl font-semibold tabular-nums text-slate-200">—</p>
                    <p id="timing-detail" class="mt-1 text-xs text-slate-500">server / database / cache</p>
                </div>
            </section>

            <section class="rounded-2xl border border-slate-800 bg-slate-900/40 p-5 text-sm leading-6 text-slate-400">
                <h2 class="mb-2 text-sm font-semibold text-slate-200">What to try</h2>
                <ul class="grid gap-2 sm:grid-cols-2">
                    <li><code class="rounded bg-slate-800 px-1.5 py-0.5 font-mono text-xs text-sky-300">london roa</code> — partial, any order</li>
                    <li><code class="rounded bg-slate-800 px-1.5 py-0.5 font-mono text-xs text-sky-300">roa lond</code> — tokens in reverse order</li>
                    <li><code class="rounded bg-slate-800 px-1.5 py-0.5 font-mono text-xs text-sky-300">londn road</code> — typo correction</li>
                    <li><code class="rounded bg-slate-800 px-1.5 py-0.5 font-mono text-xs text-sky-300">25 london</code> — house number filter</li>
                </ul>
            </section>
        </main>

        <footer class="mt-auto text-xs text-slate-600">
            Search: <code class="font-mono">GET /api/v1/search</code> ·
            Selection: <code class="font-mono">POST /api/v1/search/select</code> ·
            Usage: <code class="font-mono">GET /api/v1/usage</code> ·
            <a href="{{ url('/docs') }}" target="_blank" rel="noopener" style="color:#38bdf8;text-decoration:underline;">API docs (Swagger)</a>
        </footer>
    </div>

    <script>
        window.__AUTOCOMPLETE_DEMO__ = @json($demoConfig);
    </script>
</body>
</html>
