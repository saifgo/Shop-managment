# Micro ERP — System Architecture & Delivery Blueprint

Version 1.0 — September 2026

This document defines the implementation baseline for a shop-focused micro ERP built with Symfony and React/Vite.

## Core recommendation

- Backend: Symfony 7.4 LTS / PHP 8.2+
- Frontend: React 19.2 + Vite 8.x + TypeScript
- Database: PostgreSQL 18.x
- Async: Symfony Messenger + Redis/Doctrine transport
- Storage: S3-compatible / MinIO
- API: REST/JSON + OpenAPI
- Architecture: modular monolith, domain/application/infrastructure/UI layers

## Core business flow

Customer Portal → Cart → Order → Reservation/Backorder → Demand → Production → Stage quantity accounting → Stock → Delivery → Invoice → Payment → Return/Exchange

## Domains

1. Identity
2. Catalog
3. Customers
4. Sales
5. Inventory
6. Production
7. Purchasing
8. Documents
9. Payments
10. Finance
11. Returns
12. Audit

## Production stages

1. Preparing Materials
2. Making the Product
3. Refining
4. First Oven
5. Decoration
6. Second Oven
7. Sorting

Each stage records input quantity, accepted output, loss quantity, loss reason, actor and timestamps. Production supports multiple concurrent productions.

## Critical design rules

- Use immutable stock and posted-document histories.
- Preserve order-time pricing.
- Treat backorders as first-class demand.
- Keep business rules out of controllers and React.
- Use database transactions for inventory, production and payment operations.
- Make critical POST commands idempotent.
- Use audit events for sensitive actions.

## Delivery phases

0. Foundation
1. Catalog + Customers
2. Commerce + Backorders
3. Production
4. Delivery + Documents
5. Payments + Returns
6. Purchasing + Finance
7. Hardening

## Current technology references

- Symfony: https://symfony.com/releases
- React: https://react.dev/versions
- Vite: https://vite.dev/releases
- PostgreSQL: https://www.postgresql.org/support/versioning/
- Symfony Messenger: https://symfony.com/doc/7.4/messenger.html
- API Platform: https://api-platform.com/docs/main/symfony/
- Keycloak: https://www.keycloak.org/

See the companion DOCX for the complete entity model, endpoint plan, state machines, testing strategy, deployment architecture, team responsibilities and implementation rules.
