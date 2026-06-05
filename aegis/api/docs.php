<?php
// Serve OpenAPI spec as JSON, or render Swagger UI if ?ui=1
declare(strict_types=1);

define('AEGIS_ROOT', dirname(__DIR__));
require_once AEGIS_ROOT . '/src/Auth.php';
require_once AEGIS_ROOT . '/src/Security.php';

// Require an active authenticated session
if (!Auth::check()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Authentication required'], JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

Security::setSecurityHeaders();

$format  = $_GET['format'] ?? 'ui';
$specPath = __DIR__ . '/openapi.json';

if ($format === 'json') {
    header('Content-Type: application/json');
    readfile($specPath);
    exit;
}

$nonce = Security::nonce();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>AEGIS GRC API Documentation</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
<style>body{margin:0}.swagger-ui .topbar{background:#0f172a}</style>
</head>
<body>
<div id="swagger-ui"></div>
<script nonce="<?= $nonce ?>" src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
<script nonce="<?= $nonce ?>">
SwaggerUIBundle({
  url: '/api/docs?format=json',
  dom_id: '#swagger-ui',
  presets: [SwaggerUIBundle.presets.apis, SwaggerUIBundle.SwaggerUIStandalonePreset],
  layout: 'StandaloneLayout',
  deepLinking: true,
  defaultModelsExpandDepth: 0
});
</script>
</body>
</html>
