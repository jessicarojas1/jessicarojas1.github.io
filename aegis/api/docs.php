<?php
// Serve OpenAPI spec as JSON, or render Swagger UI if ?ui=1
$format = $_GET['format'] ?? 'ui';
$specPath = __DIR__ . '/openapi.json';

if ($format === 'json' || !isset($_GET['ui'])) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    readfile($specPath);
    exit;
}
// Swagger UI (CDN)
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
<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
<script>
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
