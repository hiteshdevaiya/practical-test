<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API Documentation · Autocomplete Search</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.17.14/swagger-ui.min.css">
    <style>
        body { margin: 0; background: #fafafa; }
        .back { display: block; padding: 10px 20px; background: #020617; font: 14px system-ui, sans-serif; }
        .back a { color: #38bdf8; text-decoration: none; }
        .keybox { padding: 10px 20px; background: #ecfdf5; border-bottom: 1px solid #a7f3d0; font: 14px system-ui, sans-serif; color: #064e3b; }
        .keybox code { background: #d1fae5; padding: 2px 6px; border-radius: 4px; word-break: break-all; }
        .keybox button { margin: 0 8px; padding: 2px 10px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="back"><a href="{{ url('/') }}">&larr; Back to demo</a></div>
    @if ($apiKey !== '')
        <div class="keybox">
            <strong>Demo API key</strong> (header <code>X-API-Key</code>):
            <code id="demo-key">{{ $apiKey }}</code>
            <button type="button" id="copy-key">Copy</button>
            <span>Already applied to <b>Authorize</b>.</span>
        </div>
    @else
        <div class="keybox">
            No demo key configured. Run <code>php artisan api-client:create "Demo Client" --publish</code>,
            then click <b>Authorize</b>.
        </div>
    @endif
    <div id="swagger-ui"></div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.17.14/swagger-ui-bundle.min.js"></script>
    <script>
        window.addEventListener('load', function () {
            const ui = SwaggerUIBundle({
                url: @json(url('/docs/openapi.json')),
                dom_id: '#swagger-ui',
                deepLinking: true,
                persistAuthorization: true,
                tryItOutEnabled: true,
                displayRequestDuration: true,
                docExpansion: 'list',
            });
            const key = @json($apiKey);
            if (key) { ui.preauthorizeApiKey('ApiKeyAuth', key); }
            const copy = document.getElementById('copy-key');
            if (copy) {
                copy.addEventListener('click', function () {
                    navigator.clipboard.writeText(key);
                    copy.textContent = 'Copied';
                });
            }
        });
    </script>
</body>
</html>
