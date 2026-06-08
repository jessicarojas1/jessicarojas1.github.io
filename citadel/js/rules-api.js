/* CITADEL — API & OpenAPI security pack.
 * Checks for insecure API definitions (OpenAPI/Swagger specs) and API-layer
 * weaknesses (JWT "none" bypass, GraphQL introspection). Same rule shape;
 * appended to CITADEL.rules and run by the standard scanner.
 * window.CITADEL.rules (extended)
 */
(function (root) {
  'use strict';
  const CITADEL = root.CITADEL = root.CITADEL || {};
  const SPEC = ['YAML', 'JSON'];
  const EXTRA = [
    { id: 'api-empty-security', name: 'OpenAPI operation with auth disabled (security: [])', category: 'authz',
      severity: 'high', cwe: 'CWE-862', langs: SPEC, confidence: 'medium',
      re: /security\s*:\s*\[\s*\]/,
      remediation: 'An empty security array removes authentication for the operation. Require a security scheme unless the endpoint is intentionally public.' },
    { id: 'api-basic-scheme', name: 'API uses HTTP Basic authentication', category: 'authn',
      severity: 'medium', cwe: 'CWE-522', langs: SPEC, confidence: 'low',
      re: /scheme\s*:\s*basic\b/i,
      remediation: 'Prefer OAuth2/OIDC bearer tokens over HTTP Basic; never send credentials on every request in Base64.' },
    { id: 'api-key-in-query', name: 'API key passed via query parameter', category: 'privacy',
      severity: 'medium', cwe: 'CWE-598', langs: SPEC, confidence: 'medium',
      re: /type\s*:\s*apiKey[\s\S]{0,60}?in\s*:\s*query/i,
      remediation: 'API keys in the query string get logged (proxies, browser history, server logs). Use an Authorization header instead.' },
    { id: 'api-http-server', name: 'OpenAPI server defined over plain HTTP', category: 'transport',
      severity: 'medium', cwe: 'CWE-319', langs: SPEC, confidence: 'low',
      re: /url\s*:\s*["']?http:\/\/(?!localhost|127\.0\.0\.1)/i,
      remediation: 'Serve APIs over HTTPS only; remove http:// server entries from the spec.' },
    { id: 'api-cors-allow-origin-wildcard', name: 'CORS Access-Control-Allow-Origin: *', category: 'authz',
      severity: 'medium', cwe: 'CWE-942', langs: '*', confidence: 'medium',
      re: /Access-Control-Allow-Origin["']?\s*[:=]\s*["']?\*/i,
      remediation: 'Reflect an allow-list of trusted origins instead of "*", especially with credentials.' },
    { id: 'jwt-alg-none', name: 'JWT "none" / unverified algorithm', category: 'authn',
      severity: 'high', cwe: 'CWE-347', langs: '*', confidence: 'medium',
      re: /(alg|algorithm)s?\s*["']?\s*[:=]\s*\[?\s*["']?none["']?/i,
      remediation: 'Never accept the "none" JWT algorithm; pin to a strong algorithm (RS256/ES256/HS256) and always verify the signature.' },
    { id: 'gql-playground-enabled', name: 'GraphQL playground/GraphiQL enabled', category: 'config',
      severity: 'low', cwe: 'CWE-200', langs: '*', confidence: 'low',
      re: /(playground|graphiql)\s*[:=]\s*true/i,
      remediation: 'Disable the GraphQL playground/GraphiQL UI in production.' }
  ];
  (CITADEL.rules = CITADEL.rules || []).push.apply(CITADEL.rules, EXTRA);
})(window);
