<?php
/**
 * PALADIN — API documentation (Swagger UI).
 *
 * Public docs page (no auth). Delegated to from the front controller for
 * GET /api/docs. Core classes are already loaded; Security::nonce() satisfies
 * the CSP for the init <script>. Swagger UI assets come from cdn.jsdelivr.net,
 * which is allowlisted for script-src and style-src.
 */
$nonce = Security::nonce();
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PALADIN API</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
    <style>
        body { margin: 0; background: #fafafa; }
        #swagger-ui { max-width: 1200px; margin: 0 auto; }
    </style>
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script nonce="<?= Security::h($nonce) ?>">
        window.addEventListener('load', function () {
            window.ui = SwaggerUIBundle({
                url: '/api/openapi.json',
                dom_id: '#swagger-ui'
            });
        });
    </script>
</body>
</html>
