<?php
declare(strict_types=1);

/**
 * Platform admin — cross-tenant operations (multi-tenancy Phase 5).
 *
 * Restricted to platform admins (the SaaS operator), a tier ABOVE tenant admins.
 * Switching tenant is explicit, audited (Auth::switchTenant logs it), and
 * time-boxed; there is no implicit cross-tenant bypass. The actual tenant binding
 * happens per request in index.php via Auth::activeTenantId().
 */
class PlatformController
{
    /** Tenant picker: list active tenants so a platform admin can switch in. */
    public function tenants(): void
    {
        Auth::requirePlatformAdmin();

        $tenants      = Database::fetchAll(
            "SELECT id, name, slug, is_active FROM tenants ORDER BY id");
        $activeId     = Auth::activeTenantId();
        $homeId       = Auth::homeTenantId();
        $pageTitle    = 'Platform — Tenants';
        $activeModule = 'platform';
        $breadcrumbs  = [['Platform', null], ['Tenants', null]];

        ob_start();
        require AEGIS_ROOT . '/views/platform/tenants.php';
        $content = ob_get_clean();
        require AEGIS_ROOT . '/views/layout.php';
    }

    /** Enter a tenant context (audited, time-boxed). Takes effect next request. */
    public function switchTenant(): void
    {
        Auth::requirePlatformAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        $tenantId = (int)($_POST['tenant_id'] ?? 0);
        try {
            Auth::switchTenant($tenantId);
            $_SESSION['flash_success'] = 'Switched tenant context. It will revert automatically within the hour.';
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = 'Could not switch tenant: ' . $e->getMessage();
        }
        header('Location: /platform/tenants');
    }

    /** Return to the home tenant (audited). */
    public function exitTenant(): void
    {
        Auth::requirePlatformAdmin();
        if (!Security::validateCsrf($_POST['csrf_token'] ?? '')) {
            http_response_code(403);
            return;
        }

        Auth::exitTenant();
        $_SESSION['flash_success'] = 'Returned to your home tenant.';
        header('Location: /platform/tenants');
    }
}
