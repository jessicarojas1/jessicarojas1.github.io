# ADR-0002: Backend framework: ASP.NET Core (.NET 10 LTS)

- **Status:** **Superseded** by [ADR-0008](ADR-0008-php-symfony.md). The owner selected PHP on 2026-10-25 (decision D1). Kept for the record.
- **Date:** 2026-10-25

## Decision
ASP.NET Core on .NET 10 LTS, EF Core with Npgsql, Microsoft.Identity.Web for Entra OIDC, Quartz.NET (clustered, PostgreSQL job store) for schedules, FluentValidation for input validation, Serilog/OpenTelemetry for structured logs and traces, and a generated OpenAPI document (`Microsoft.AspNetCore.OpenApi`) as the API contract.

## Rationale
First-party Entra/OIDC and antiforgery. Compile-time type safety. Mature migrations and optimistic concurrency. Durable jobs without Redis. A smaller dependency graph than the npm equivalent. Predictable LTS support (to Nov 2028).

## Alternative: NestJS
Acceptable if the maintainers are a TypeScript-only team, using Prisma or Drizzle (raw SQL for exclusion constraints and ranges) and pg-boss for jobs. It gains one language across the stack. It costs a larger supply-chain surface and more security glue that has to be assembled.

## Consequences
- The frontend consumes an OpenAPI-generated TypeScript client, so contract drift fails the build.
- If D1 selects TypeScript, this ADR is superseded before Phase 1. No code depends on it yet.
