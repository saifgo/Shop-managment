**MICRO ERP  
SYSTEM ARCHITECTURE & DELIVERY BLUEPRINT**

**Symfony API + React/Vite Applications**

Version 1.0 • September 2026  
Prepared as a handoff document for the implementation team

*  
Scope: customer commerce, CRM, inventory, manufacturing, purchasing, invoicing, payments, finance, deliveries, returns and exchanges.*

# 1. Executive Summary

Build a focused, domain-driven micro ERP for a shop that sells products online, accepts orders even when stock is unavailable, plans and runs multi-stage production, tracks quantity losses between production stages, manages customers and suppliers, produces commercial documents, records payments and expenses, and handles delivery damage, returns and exchanges.

The product should be implemented as a modular monolith first: one Symfony application, one PostgreSQL database, background workers through Symfony Messenger, and a React/Vite frontend for the customer portal and administration. The architecture must make business domains explicit so that modules can later be extracted only if scale requires it.

## Architecture decision at a glance

| **Concern** | **Decision**                                                                         | **Reason**                                                                                       |
|-------------|--------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------------|
| Backend     | Symfony 7.4 LTS + PHP 8.2+                                                           | Long support window; ideal for a domain-heavy API backend.                                       |
| Frontend    | React 19.2 + Vite 8.x + TypeScript                                                   | Fast SPA tooling, strong ecosystem, clear separation from API.                                   |
| Database    | PostgreSQL 18.x                                                                      | Relational integrity, transactions, JSON support, strong reporting/querying.                     |
| API         | REST/JSON + OpenAPI; API Platform optional                                           | Team-friendly contract-first development without forcing all business logic into generated CRUD. |
| Async       | Symfony Messenger + Redis/Doctrine transport                                         | Background jobs, notifications, document generation and integrations.                            |
| Auth        | Symfony Security; Keycloak optional for SSO only if needed                           | Avoid unnecessary external identity complexity for v1.                                           |
| Files       | S3-compatible object storage; MinIO for self-hosted or compatible cloud storage      | Product media, invoices, delivery proofs and attachments.                                        |
| Search      | PostgreSQL first; Meilisearch optional when catalog/report search outgrows DB search | Keep v1 simple and cheap.                                                                        |
| Deployment  | Docker Compose initially; Coolify/managed VM-friendly                                | Simple operations and portability.                                                               |

Current-version note: Symfony currently lists 7.4 as the maintained LTS branch and 8.1 as the current stable branch. This blueprint deliberately targets Symfony 7.4 LTS to optimize for multi-year maintenance. React docs currently list 19.2 as the latest version and Vite has an 8.1 release. PostgreSQL 18 is the current supported major release.

## Primary business flow

Customer portal  
-\> Cart  
-\> Order  
-\> Availability / reservation  
-\> Backorder demand  
-\> Production planning  
-\> Multi-stage production  
-\> Stock receipt  
-\> Order allocation  
-\> Delivery  
-\> Invoice  
-\> Payment  
-\> Return / exchange / credit adjustment

# 2. Architectural Principles

- Inventory, production and finance must be auditable. Never rely on destructive overwrites where a movement or ledger entry should exist.

- Business rules live in domain/application services, not controllers or React components.

- Every critical transition is explicit, validated and recorded with actor, timestamp and reason where applicable.

- Orders preserve historical commercial facts: unit price, tax, discounts and product snapshot data are immutable after confirmation unless a controlled correction flow is used.

- Production is quantity-accounting first: every stage transition records input, accepted output and losses/rejections.

- The system must support multiple concurrent productions and partial deliveries.

- Backorders are first-class demand, not failed orders.

- Document entities are generated from transactional facts but remain immutable after posting; corrections use cancellation/reversal/credit workflows.

- Prefer PostgreSQL transactions and constraints for invariants instead of application-level assumptions.

- Design APIs to be idempotent for commands that can be retried.

# 3. Product Scope

| **Module**      | **V1 scope**                                                                    | **Priority** |
|-----------------|---------------------------------------------------------------------------------|--------------|
| Customer portal | Catalog, pricing, cart, checkout, order history, invoices, balance, returns     | P0           |
| Admin dashboard | Operational KPIs, pending orders, demand, production, deliveries, payments      | P0           |
| Products        | Products, variants, attributes, pricing, customer overrides, media              | P0           |
| Inventory       | Stock ledger, reservations, backorders, adjustments, locations                  | P0           |
| Customers       | Person / enterprise / association, legal/tax data, addresses, custom prices     | P0           |
| Sales           | Orders, order lines, confirmation, fulfillment allocation, partial delivery     | P0           |
| Production      | 7 configurable stages, quantities, loss reasons, stage history, concurrent jobs | P0           |
| Documents       | Quote, sales order, delivery note, invoice, credit note                         | P0           |
| Payments        | Partial/full payments, statuses, allocation to invoices                         | P0           |
| Purchasing      | Suppliers, supplier products, purchase orders, receipts, supplier invoices      | P1           |
| Finance         | Income, expenses, scheduled transactions, cash position                         | P1           |
| Returns         | Return/exchange workflow, damaged goods, replacement, credit                    | P0           |
| Notifications   | Email + in-app operational notifications                                        | P1           |
| Reporting       | Sales, margin, stock, production yield, receivables, payables                   | P1           |

## Explicit non-goals for V1

- Full statutory accounting / general ledger replacement.

- Multi-company consolidation.

- Complex warehouse management such as wave picking, barcode RF optimization or advanced route optimization.

- Marketplace integration as a first release.

- A full HR/payroll module.

# 4. Actors, Roles and Permissions

| **Role**            | **Typical capabilities**                                                                |
|---------------------|-----------------------------------------------------------------------------------------|
| Super Admin         | System settings, users, roles, all modules, reversals and audit review.                 |
| Sales/Admin         | Catalog, customers, orders, demand, deliveries, invoices, payments, returns.            |
| Production Manager  | Create/plan productions, assign products, advance stages, approve losses, review yield. |
| Production Operator | View assigned stages, enter quantities/losses, add notes, move stage when permitted.    |
| Warehouse/Stock     | Receive goods, stock adjustments, reservations, delivery preparation.                   |
| Purchasing          | Suppliers, purchase orders, receipts, supplier invoices, supplier payments.             |
| Finance             | Invoices, payments, expenses, income, balances, reports.                                |
| Customer            | Own catalog, orders, invoices, payments, balance, returns and profile.                  |

Authorization is permission-based, not role-name based. Roles are bundles of permissions. Sensitive operations such as stock adjustments, invoice cancellation, payment deletion and production-loss correction should require elevated permissions and create audit events.

# 5. Domain Model

## 5.1 Core aggregates

| **Aggregate** | **Root**                    | **Key entities**                                                                            |
|---------------|-----------------------------|---------------------------------------------------------------------------------------------|
| Catalog       | Product                     | Product, ProductVariant, Attribute, Category, Media, PriceList, CustomerPriceOverride       |
| Customer      | Customer                    | Customer, CustomerIdentity, Address, Contact, CustomerPriceOverride, PortalUser             |
| Sales         | Order                       | Order, OrderItem, Reservation, DemandAllocation                                             |
| Inventory     | InventoryItem / StockLedger | StockLocation, StockBalance, StockMovement, Reservation, StockAdjustment                    |
| Production    | ProductionOrder             | ProductionOrder, ProductionItem, ProductionStage, StageExecution, ProductionLoss            |
| Purchasing    | PurchaseOrder               | Supplier, SupplierProduct, PurchaseOrder, PurchaseReceipt, SupplierInvoice, SupplierPayment |
| Documents     | CommercialDocument          | Quote, SalesOrderDocument, DeliveryNote, Invoice, CreditNote, DocumentLine                  |
| Payments      | Payment                     | Payment, PaymentAllocation, PaymentMethod                                                   |
| Finance       | FinancialTransaction        | Income, Expense, ScheduledTransaction                                                       |
| Returns       | ReturnRequest               | ReturnRequest, ReturnItem, ReplacementOrder / CreditAdjustment                              |
| Identity      | User                        | User, Role, Permission, UserRole                                                            |
| Audit         | AuditEvent                  | AuditEvent, DomainEventOutbox                                                               |

## 5.2 Entity relationship sketch

Customer 1---\* Order 1---\* OrderItem \*---1 ProductVariant  
Product 1---\* ProductVariant  
Customer 1---\* CustomerPriceOverride \*---1 ProductVariant  
OrderItem \*---\* Reservation \*---1 StockLocation  
Order 1---\* FulfillmentAllocation \*---1 ProductionOrder  
ProductionOrder 1---\* ProductionItem \*---1 ProductVariant  
ProductionItem 1---\* StageExecution \*---1 ProductionStage  
StageExecution 1---\* ProductionLoss  
PurchaseOrder \*---1 Supplier  
PurchaseOrder 1---\* PurchaseOrderItem \*---1 ProductVariant  
Invoice 1---\* InvoiceLine  
Invoice 1---\* PaymentAllocation \*---1 Payment  
DeliveryNote 1---\* DeliveryLine \*---1 OrderItem  
ReturnRequest \*---1 Order  
ReturnRequest 1---\* ReturnItem \*---1 ProductVariant

## 5.3 Important invariants

- An order item must preserve its agreed unit price and applicable discount/tax snapshot.

- A reservation cannot exceed available reservable stock at the moment it is committed, unless an explicit backorder policy permits it.

- Posted stock movements are immutable; corrections create compensating movements.

- A posted invoice is immutable; changes are handled through cancellation/credit-note workflows.

- A production stage execution can close only when input and output/loss quantities reconcile according to configured rules.

- Production quantity cannot become negative.

- A return must reference an original order/delivery line unless an authorized standalone return policy is enabled.

# 6. Inventory & Backorder Design

## 6.1 Stock ledger

Use a ledger model. Stock balance is derived or maintained as a transactional projection from StockMovement rows. The ledger must capture source type and source identifier so any quantity can be traced to a business operation.

| **Movement type**  | **Example**                      | **Effect**  |
|--------------------|----------------------------------|-------------|
| PURCHASE_RECEIPT   | 100 units received from supplier | +100        |
| PRODUCTION_RECEIPT | 87 units accepted after firing   | +87         |
| SALE_RESERVATION   | 8 units reserved for order       | reserved +8 |
| SALE_SHIPMENT      | 8 units delivered                | -8 physical |
| RETURN_RECEIPT     | 2 acceptable units returned      | +2          |
| DAMAGE             | 2 broken in delivery             | -2          |
| ADJUSTMENT         | Inventory count correction       | +/−         |
| TRANSFER_OUT / IN  | Move between locations           | -/+         |

## 6.2 Availability calculation

physical_on_hand  
- reserved_for_confirmed_orders  
= available_to_sell  
  
confirmed_demand  
- available_to_sell  
= net_production_demand

The API should expose these values explicitly; do not force the frontend to reconstruct stock logic.

## 6.3 Backorder behavior

| **Condition**                      | **System behavior**                                                            |
|------------------------------------|--------------------------------------------------------------------------------|
| Stock fully available              | Reserve required quantity; order can become Ready/Processing.                  |
| Stock partially available          | Reserve available quantity; create backorder quantity for remainder.           |
| Stock unavailable                  | Create backorder demand; do not block order unless product policy forbids it.  |
| Production receipt                 | Allocate available units to oldest eligible backorders by configured priority. |
| Customer cancels backordered lines | Remove demand and release any reservations.                                    |

# 7. Sales & Order Management

## 7.1 Order lifecycle

DRAFT  
-\> SUBMITTED  
-\> CONFIRMED  
-\> PARTIALLY_ALLOCATED  
-\> READY_TO_DELIVER  
-\> PARTIALLY_DELIVERED  
-\> DELIVERED  
-\> CANCELLED  
  
Separate line-level fulfillment state:  
UNALLOCATED -\> RESERVED -\> BACKORDERED -\> READY -\> DELIVERED

Order state and payment state are independent. Example: an order may be DELIVERED while its invoice is PARTIALLY_PAID.

## 7.2 Customer demand view

Filters: product \| variant \| customer \| date \| order state \| delivery state  
  
Rows:  
Product \| Variant \| Client \| Ordered \| Reserved \| Backordered \| Ready \| Delivered

## 7.3 Production demand view

Product \| Variant \| Ordered \| Reserved \| On Hand \| Net Demand \| Already In Production \| To Produce

The To Produce number is informational and should be recalculated from current transactional data. Production orders then convert a selected quantity into committed manufacturing work.

# 8. Production Management

## 8.1 Configurable workflow

The default workflow requested for the shop is configurable rather than hard-coded:

| **Sequence** | **Default stage**   | **Can record quantity?** | **Can record losses?** |
|--------------|---------------------|--------------------------|------------------------|
| 1            | Preparing Materials | Yes                      | Yes                    |
| 2            | Making the Product  | Yes                      | Yes                    |
| 3            | Refining            | Yes                      | Yes                    |
| 4            | First Oven          | Yes                      | Yes                    |
| 5            | Decoration          | Yes                      | Yes                    |
| 6            | Second Oven         | Yes                      | Yes                    |
| 7            | Sorting             | Yes                      | Yes                    |

## 8.2 Production order

| **Field**                     | **Purpose**                                                |
|-------------------------------|------------------------------------------------------------|
| Reference                     | Human-readable production number, e.g. PROD-2026-00031.    |
| Status                        | DRAFT, PLANNED, IN_PROGRESS, PAUSED, COMPLETED, CANCELLED. |
| Priority                      | NORMAL, HIGH, URGENT.                                      |
| Source                        | Optional link to demand/order planning run.                |
| Planned start / due date      | Scheduling and SLA.                                        |
| Created by / assigned manager | Accountability.                                            |
| Notes                         | Production instructions and context.                       |

## 8.3 Quantity accounting

For each stage execution:  
  
input_qty  
accepted_output_qty  
loss_qty  
loss_reason(s)  
notes  
performed_by  
started_at  
completed_at  
  
Validation:  
input_qty = accepted_output_qty + loss_qty + other_authorized_adjustments

For stages where the physical process is not perfectly one-to-one, the workflow configuration can specify whether reconciliation is strict, quantity can be transformed, or scrap requires an explicit material conversion rule.

## 8.4 Production example

| **Stage**           | **Input** | **Accepted** | **Loss** | **Loss reason**   |
|---------------------|-----------|--------------|----------|-------------------|
| Preparing Materials | 100       | 100          | 0        | \-                |
| Making              | 100       | 94           | 6        | Broken / unusable |
| Refining            | 94        | 91           | 3        | Cracks            |
| First Oven          | 91        | 87           | 4        | Kiln damage       |
| Decoration          | 87        | 85           | 2        | Decoration reject |
| Second Oven         | 85        | 83           | 2        | Firing damage     |
| Sorting             | 83        | 80           | 3        | Quality reject    |

Final yield in this example is 80 units from 100 planned. Every reduction remains explainable.

## 8.5 Multiple concurrent productions

No singleton “current production” assumption. The system must support N active ProductionOrders and N stage executions at the same time. Admin can filter by product, stage, due date, priority and assigned operator.

# 9. Commercial Documents & Invoicing

## 9.1 Document chain

Quote (optional)  
-\> Sales Order / Bon de Commande  
-\> Delivery Note / Bon de Livraison  
-\> Invoice / Facture  
-\> Payment(s)  
  
Invoice corrections:  
-\> Credit Note / Avoir

## 9.2 Document requirements

- Sequential human-readable numbering by document type and fiscal year.

- Immutable posted document snapshot: customer legal information, addresses, lines, unit prices, tax and totals.

- PDF generation with stored file version in object storage.

- Document status plus lifecycle timestamps.

- Linking between source and derived documents.

- Partial delivery support: one order may generate multiple delivery notes.

- Partial invoicing support should be configurable; default policy can be invoice-per-order after delivery.

## 9.3 Suggested invoice statuses

DRAFT -\> ISSUED -\> PARTIALLY_PAID -\> PAID  
\\\> OVERDUE  
DRAFT -\> CANCELLED  
ISSUED -\> CREDITED (via credit note, not mutation)

# 10. Payments, Income & Expenses

## 10.1 Payment model

| **Entity**           | **Key fields**                                                              |
|----------------------|-----------------------------------------------------------------------------|
| Payment              | reference, customer/supplier, amount, currency, method, date, status, notes |
| PaymentAllocation    | payment, invoice, allocated_amount                                          |
| Expense              | supplier/payee, category, amount, date, attachment, status                  |
| Income               | source, category, amount, date, reference                                   |
| ScheduledTransaction | type, category, amount, recurrence, next_run_at, active                     |

A payment can be split across multiple invoices. An invoice can receive multiple payments. Payment status should be derived from allocations plus invoice total, with support for partial and overpayment cases.

## 10.2 Customer balance

total_issued_invoices  
- total_allocated_payments  
- total_credit_notes  
= outstanding_balance

# 11. Supplier & Purchasing

Supplier  
-\> Supplier Product Catalog  
-\> Purchase Order  
-\> Purchase Receipt  
-\> Supplier Invoice  
-\> Supplier Payment

- Track the supplier-specific purchase price and optional lead time/MOQ.

- Receiving goods creates inventory movements.

- Supplier invoice remains separate from purchase order and receipt so partial receiving is supported.

- Supplier payments use the same allocation pattern as customer invoice payments.

# 12. Delivery, Returns & Exchanges

## 12.1 Delivery lifecycle

READY_TO_DELIVER  
-\> PACKED  
-\> DISPATCHED  
-\> IN_TRANSIT  
-\> DELIVERED  
-\> DELIVERY_EXCEPTION

## 12.2 Return lifecycle

REQUESTED  
-\> APPROVED  
-\> RECEIVED  
-\> INSPECTED  
-\> RESOLVED  
  
Resolution:  
REFUND \| EXCHANGE \| REPLACEMENT \| CREDIT_NOTE \| REJECTED

| **Event**                       | **Inventory effect**                                                                            | **Financial effect**                                                                       |
|---------------------------------|-------------------------------------------------------------------------------------------------|--------------------------------------------------------------------------------------------|
| Damaged in transit; replacement | Damaged outbound item recorded; replacement decreases stock when shipped                        | Original invoice normally remains; replacement may be zero-priced or controlled by policy. |
| Return accepted; refund         | Returned good can increase stock only if condition is sellable; damaged item goes to loss/scrap | Refund or credit note reduces receivable.                                                  |
| Exchange                        | Returned item processed; replacement shipped                                                    | Difference captured by credit/debit adjustment or new invoice, depending policy.           |

# 13. Customer Portal

| **Screen**         | **Requirements**                                                            |
|--------------------|-----------------------------------------------------------------------------|
| Dashboard          | Active orders, outstanding balance, recent invoices, delivery status.       |
| Catalog            | Visible products only; customer-specific price overrides applied.           |
| Product detail     | Images, variants, size/detail attributes, availability/backorder messaging. |
| Cart               | Variant quantities, price snapshot, validation before checkout.             |
| Checkout           | Address, delivery option, order summary, payment selection if enabled.      |
| Orders             | Order history, line fulfillment, backorder status, documents.               |
| Invoices & balance | Invoices, paid/unpaid/overdue, payment history, downloadable documents.     |
| Returns            | Request return/exchange and see status.                                     |
| Profile            | Customer/contact/address data with controlled editing.                      |

# 14. Administration UX

| **Area**   | **Key views/actions**                                                                            |
|------------|--------------------------------------------------------------------------------------------------|
| Dashboard  | Orders, demand, active production, production yield, pending deliveries, receivables, low stock. |
| Orders     | List, detail, reserve, fulfill, create document, deliver, cancel, backorder.                     |
| Demand     | Group by client; group by product/variant; calculate net demand; create production.              |
| Production | Kanban by stage; production detail; stage execution; losses; history.                            |
| Inventory  | Current stock, reservations, ledger, adjustments, movement source drilldown.                     |
| Products   | CRUD, variants, attributes, media, pricing, customer overrides.                                  |
| Customers  | 360 view: orders, invoices, payments, balance, prices, returns.                                  |
| Suppliers  | Supplier products, purchase orders, receipts, invoices, balance.                                 |
| Finance    | Invoices, payments, expenses, income, scheduled transactions, aging.                             |
| Settings   | Numbering, tax rules, stages, loss reasons, payment methods, users, roles.                       |

# 15. API Contract & Endpoint Plan

Use REST/JSON with OpenAPI as the authoritative contract. Commands that mutate important aggregates should be explicit action endpoints rather than generic PATCH operations.

## 15.1 Authentication

POST /api/auth/login  
POST /api/auth/refresh  
POST /api/auth/logout  
GET /api/me

## 15.2 Catalog & customers

GET /api/products  
POST /api/products  
GET /api/products/{id}  
PATCH /api/products/{id}  
GET /api/products/{id}/variants  
POST /api/products/{id}/variants  
  
GET /api/customers  
POST /api/customers  
GET /api/customers/{id}  
PATCH /api/customers/{id}  
GET /api/customers/{id}/orders  
GET /api/customers/{id}/balance  
POST /api/customers/{id}/price-overrides

## 15.3 Sales

POST /api/cart/validate  
POST /api/orders  
GET /api/orders  
GET /api/orders/{id}  
POST /api/orders/{id}/confirm  
POST /api/orders/{id}/cancel  
POST /api/orders/{id}/reserve  
POST /api/orders/{id}/create-delivery  
GET /api/demand/by-customer  
GET /api/demand/by-product

## 15.4 Production

GET /api/productions  
POST /api/productions  
GET /api/productions/{id}  
POST /api/productions/{id}/start  
POST /api/productions/{id}/pause  
POST /api/productions/{id}/cancel  
POST /api/productions/{id}/stages/{stageId}/start  
POST /api/productions/{id}/stages/{stageId}/complete  
GET /api/productions/{id}/history  
GET /api/production-demand

## 15.5 Documents & payments

POST /api/orders/{id}/documents/sales-order  
POST /api/deliveries/{id}/documents/delivery-note  
POST /api/invoices  
GET /api/invoices/{id}  
POST /api/invoices/{id}/issue  
POST /api/invoices/{id}/cancel  
POST /api/invoices/{id}/credit-note  
POST /api/payments  
POST /api/payments/{id}/allocate  
GET /api/customers/{id}/receivables

## 15.6 Idempotency

POST commands that create financial, stock or document effects should accept an Idempotency-Key header. The backend stores the key and response reference for a bounded retention period.

# 16. Symfony Backend Architecture

src/  
├── Domain/  
│ ├── Catalog/  
│ ├── Customer/  
│ ├── Sales/  
│ ├── Inventory/  
│ ├── Production/  
│ ├── Purchasing/  
│ ├── Documents/  
│ ├── Payments/  
│ ├── Finance/  
│ ├── Returns/  
│ └── Shared/  
├── Application/  
│ ├── Catalog/  
│ ├── Sales/  
│ ├── Production/  
│ ├── Inventory/  
│ └── ...  
├── Infrastructure/  
│ ├── Persistence/Doctrine/  
│ ├── Messaging/  
│ ├── Storage/  
│ └── Integrations/  
└── UI/  
└── Http/  
├── Controller/  
├── Request/  
└── Response/

## 16.1 Layer responsibilities

| **Layer**      | **Responsibility**                                                                        |
|----------------|-------------------------------------------------------------------------------------------|
| Domain         | Entities, value objects, domain rules, domain events; framework-light.                    |
| Application    | Use cases / commands / queries, authorization checks, transaction boundary orchestration. |
| Infrastructure | Doctrine mappings, repositories, queues, storage, external integrations.                  |
| HTTP UI        | Validation at API boundary, serialization, status codes, authentication context.          |

## 16.2 Command/query split

Use commands for business mutations (ConfirmOrder, CreateProduction, CompleteStage, IssueInvoice, RecordPayment, ReceiveReturn). Use queries/read models for dashboard and reporting views. This avoids forcing complex operational screens through normalized entity graphs.

# 17. React Frontend Architecture

src/  
├── app/  
│ ├── router/  
│ ├── providers/  
│ └── auth/  
├── features/  
│ ├── catalog/  
│ ├── cart/  
│ ├── orders/  
│ ├── production/  
│ ├── inventory/  
│ ├── customers/  
│ ├── suppliers/  
│ ├── invoices/  
│ ├── payments/  
│ └── returns/  
├── shared/  
│ ├── api/  
│ ├── components/  
│ ├── forms/  
│ ├── tables/  
│ └── utils/  
└── styles/

- TypeScript mandatory.

- Server state: TanStack Query (or equivalent) for API caching, mutations and invalidation.

- Client state: small, focused store only for session/UI/cart concerns; do not duplicate server state.

- Forms: schema-driven validation (e.g. Zod) with backend validation remaining authoritative.

- Tables: server-side pagination/filtering/sorting for admin data sets.

- The customer portal and admin portal can be separate route trees in one frontend repo or separate apps sharing a component package.

# 18. Database Blueprint

## 18.1 Main tables

| **Domain** | **Representative tables**                                                                                        |
|------------|------------------------------------------------------------------------------------------------------------------|
| Identity   | users, roles, permissions, user_roles                                                                            |
| Catalog    | products, product_variants, product_attributes, categories, product_media, price_lists, customer_price_overrides |
| Customers  | customers, customer_addresses, customer_contacts, portal_accounts                                                |
| Sales      | orders, order_items, order_status_history, reservations, demand_allocations                                      |
| Inventory  | stock_locations, stock_balances, stock_movements, stock_adjustments                                              |
| Production | production_orders, production_items, production_stages, stage_executions, production_losses                      |
| Purchasing | suppliers, supplier_products, purchase_orders, purchase_order_items, purchase_receipts, purchase_receipt_items   |
| Documents  | documents, document_lines, document_number_sequences, document_relations                                         |
| Payments   | payments, payment_allocations, payment_methods                                                                   |
| Finance    | financial_transactions, expense_categories, income_categories, scheduled_transactions                            |
| Returns    | return_requests, return_items, return_events                                                                     |
| Audit      | audit_events, outbox_messages, idempotency_keys                                                                  |

## 18.2 Data type rules

- UUID/ULID primary keys are recommended for API-safe identifiers.

- DECIMAL/NUMERIC for money and quantities requiring precision; never floating point for financial amounts.

- TIMESTAMPTZ for timestamps.

- Currency stored explicitly even if V1 is single-currency.

- Soft-delete only where business semantics require recovery/history; do not soft-delete ledgers/documents that should be immutable.

# 19. State Machines

## 19.1 Order state machine

| **State**           | **Meaning**                              | **Allowed next states**                          |
|---------------------|------------------------------------------|--------------------------------------------------|
| DRAFT               | Customer/admin has not committed order   | SUBMITTED, CANCELLED                             |
| SUBMITTED           | Checkout completed                       | CONFIRMED, CANCELLED                             |
| CONFIRMED           | Order accepted and demand committed      | PARTIALLY_ALLOCATED, READY_TO_DELIVER, CANCELLED |
| PARTIALLY_ALLOCATED | Some lines fulfilled, others backordered | READY_TO_DELIVER, PARTIALLY_DELIVERED, CANCELLED |
| READY_TO_DELIVER    | Shippable quantity available             | PARTIALLY_DELIVERED, DELIVERED                   |
| PARTIALLY_DELIVERED | At least one delivery completed          | PARTIALLY_DELIVERED, DELIVERED                   |
| DELIVERED           | All fulfillable quantities delivered     | \-                                               |

## 19.2 Production state machine

DRAFT -\> PLANNED -\> IN_PROGRESS -\> PAUSED -\> IN_PROGRESS -\> COMPLETED  
\\\> CANCELLED  
  
Stage status:  
PENDING -\> ACTIVE -\> COMPLETED  
PENDING -\> SKIPPED (only when workflow permits)

## 19.3 Payment status

UNPAID \| PARTIALLY_PAID \| PAID \| OVERPAID \| CANCELLED

# 20. Domain Events & Background Jobs

| **Event**           | **Typical consumers**                                            |
|---------------------|------------------------------------------------------------------|
| OrderConfirmed      | Reserve stock, create demand, notify admin/customer.             |
| ProductionCompleted | Post finished stock, allocate backorders, notify admin.          |
| StageCompleted      | Update production board/read model, calculate yield.             |
| DeliveryCreated     | Generate delivery document/PDF, notify customer.                 |
| InvoiceIssued       | Generate PDF, update receivable projection, notify customer.     |
| PaymentRecorded     | Allocate payment, update invoice/customer balance.               |
| ReturnResolved      | Stock adjustment, credit/refund workflow, customer notification. |

Use Symfony Messenger for asynchronous jobs. Start with Doctrine or Redis transport; introduce RabbitMQ only when throughput or operational separation justifies it.

# 21. Third-Party / Free-First Services

| **Capability** | **Preferred option**                                            | **Fallback / notes**                                                      |
|----------------|-----------------------------------------------------------------|---------------------------------------------------------------------------|
| Email          | Self-hosted SMTP relay or a provider with a free/dev tier       | Keep provider adapter interface; verify current limits before production. |
| Object storage | MinIO self-hosted or any S3-compatible bucket                   | Avoid storing binary files in PostgreSQL.                                 |
| Search         | PostgreSQL full-text first; Meilisearch optional                | Add only when catalog/admin search needs typo tolerance or scale.         |
| Identity / SSO | Symfony Security for V1                                         | Keycloak if multiple applications/SSO become necessary.                   |
| Monitoring     | Prometheus + Grafana; application logs to structured JSON       | Optional hosted observability service later.                              |
| Error tracking | Self-hosted GlitchTip or Sentry-compatible setup                | Keep integration behind an error-reporting abstraction.                   |
| PDF            | Headless Chromium/Playwright service or server-side HTML-to-PDF | Store generated PDFs and version them.                                    |
| Backups        | PostgreSQL pg_dump + WAL/managed backup strategy                | 3-2-1 rule for production backups.                                        |

Free/self-hosted should mean low vendor lock-in, not “no operational cost.” Every external service must have an adapter and a fallback where practical.

# 22. Security Requirements

- HTTPS everywhere in non-local environments.

- Short-lived access tokens or secure session strategy; rotate refresh tokens if JWT is used.

- Password hashing through Symfony PasswordHasher; no custom hashing.

- Rate-limit authentication and public order endpoints.

- Object storage files must not be publicly writable; use signed URLs or controlled download endpoints.

- Tenant/company scoping should exist in the data model even if V1 has one company; this prevents painful future redesign.

- Audit sensitive actions: login/security events, stock adjustment, production loss correction, invoice issue/cancel, payment mutation, return resolution and permission changes.

- Never log secrets, authentication tokens, full payment credentials or unnecessary customer identity data.

- Database access from application should use least-privileged credentials.

# 23. Performance & Reliability

| **Area**                    | **Target / design**                                                                        |
|-----------------------------|--------------------------------------------------------------------------------------------|
| Typical API response        | p95 \< 300 ms for common read endpoints under normal load.                                 |
| Checkout command            | Transactional and idempotent; avoid long synchronous jobs.                                 |
| Dashboard                   | Use read queries/projections; avoid N+1 entity hydration.                                  |
| PDF/email                   | Async via Messenger.                                                                       |
| Stock operations            | Use database transactions and row-level locking or advisory locking for contention points. |
| Production stage completion | Single transaction for reconciliation + stage transition + resulting stock/event changes.  |
| Availability                | Health/readiness endpoints; graceful worker restarts.                                      |

# 24. Testing Strategy

| **Layer**            | **What to test**                                                                                         |
|----------------------|----------------------------------------------------------------------------------------------------------|
| Unit                 | Value objects, pricing rules, quantity reconciliation, state transitions, balance calculations.          |
| Integration          | Repositories, transaction boundaries, stock ledger posting, invoice/payment allocation.                  |
| Application/use-case | ConfirmOrder, CreateProduction, CompleteStage, ReceiveGoods, IssueInvoice, RecordPayment, ResolveReturn. |
| API                  | Authentication, authorization, validation, idempotency, error contracts.                                 |
| Frontend             | Forms, critical UI behavior, route protection, table filters.                                            |
| E2E                  | Customer orders, backorder, admin production, delivery, invoice/payment, return/exchange.                |

## 24.1 Mandatory acceptance scenarios

- Customer buys an in-stock product; stock is reserved and order becomes fulfillable.

- Customer buys more than stock; available quantity is reserved and remainder becomes backorder demand.

- Admin views demand grouped by customer and product.

- Admin creates a production for selected product quantities.

- Admin advances each production stage with accepted quantity and losses; history is preserved.

- Two productions can be active concurrently without data collision.

- Production completion adds accepted stock and allocates it to eligible backorders.

- One order is delivered in multiple shipments.

- Invoice is partially paid then fully paid.

- Customer requests exchange after transit damage; replacement and stock movements are traceable.

# 25. Deployment Architecture

Internet  
\|  
Reverse Proxy / TLS  
\|  
+--------------+--------------+  
\| \|  
React app Symfony API  
\|  
+----------------------+----------------+  
\| \| \| \|  
PostgreSQL Redis Object Store Worker  
(S3/MinIO) (Messenger)

## 25.1 Environments

| **Environment** | **Purpose**                                                         |
|-----------------|---------------------------------------------------------------------|
| Local           | Docker Compose; seeded demo data.                                   |
| CI              | Automated lint, unit/integration tests, build and migration checks. |
| Staging         | Production-like; QA and E2E.                                        |
| Production      | Managed VM/Coolify or equivalent; encrypted backups and monitoring. |

## 25.2 CI/CD gates

- PHPStan/Psalm (choose one) and PHP CS Fixer.

- Symfony test suite.

- Frontend typecheck + lint + production build.

- OpenAPI contract validation.

- Database migration check from clean database.

- E2E smoke suite on staging.

- Container image vulnerability scan as a recommended gate.

# 26. Observability

| **Signal** | **Implementation**                                                                                                     |
|------------|------------------------------------------------------------------------------------------------------------------------|
| Logs       | Structured JSON; include correlation/request ID, actor ID where safe, aggregate ID and command name.                   |
| Metrics    | HTTP latency, DB latency, queue depth, failed messages, stock commands, production throughput, invoice/payment counts. |
| Tracing    | OpenTelemetry optional from the start for request correlation.                                                         |
| Alerts     | Queue failures, DB saturation, backup failure, high 5xx, disk capacity, certificate expiry.                            |

# 27. Auditability

Audit events are separate from ordinary domain history. Domain histories explain business facts; audit events explain who performed a sensitive action, from which interface and when.

| **Audit example**         | **Captured data**                                                            |
|---------------------------|------------------------------------------------------------------------------|
| Stock adjusted            | actor, permission, product/variant, old projection, movement, reason, source |
| Invoice cancelled         | actor, invoice, reason, timestamp, resulting credit/cancellation document    |
| Production loss corrected | actor, stage execution, previous loss, new loss, reason                      |
| Price override changed    | actor, customer, product, previous price, new price, effective dates         |

# 28. Delivery Roadmap

| **Phase**                | **Deliverables**                                                    | **Definition of done**                                                   |
|--------------------------|---------------------------------------------------------------------|--------------------------------------------------------------------------|
| 0\. Foundation           | Repo setup, Docker, CI, auth, OpenAPI, base UI shell, DB migrations | Team can clone, run, test and deploy a clean environment.                |
| 1\. Catalog + Customers  | Products, variants, pricing, customers, portal auth                 | Customer can browse correct price and admin can manage catalog/customer. |
| 2\. Commerce             | Cart, checkout, orders, reservation, backorder, demand views        | Order-to-demand flow works with partial stock.                           |
| 3\. Production           | Production planner, stages, quantities, losses, history             | Two concurrent productions can run through all stages and reconcile.     |
| 4\. Delivery + Documents | Delivery, invoice, credit note, PDFs                                | Partial delivery and invoice workflows work end-to-end.                  |
| 5\. Payments + Returns   | Payments, allocations, balances, returns/exchanges                  | Receivable balances and return outcomes are auditable.                   |
| 6\. Purchasing + Finance | Suppliers, purchase flows, expenses/income, schedules               | Supplier-to-stock and expense tracking work.                             |
| 7\. Hardening            | Performance, security review, E2E, backup/restore drills, reports   | Production-ready release.                                                |

## 28.1 Suggested team shape

| **Role**           | **Focus**                                                         |
|--------------------|-------------------------------------------------------------------|
| Backend lead       | Domain model, transactions, API/application services, reviews.    |
| Backend developer  | Sales/inventory/production implementations.                       |
| Frontend lead      | Admin information architecture, design system, data grids, state. |
| Frontend developer | Portal and admin feature delivery.                                |
| QA/Automation      | Acceptance scenarios, API/E2E, regression suite.                  |
| DevOps (part-time) | Docker, CI/CD, backups, monitoring, environments.                 |

# 29. Team Definition of Done

- Business rule documented and implemented in application/domain layer.

- Database migration included and reversible where appropriate.

- API contract updated and validated.

- Authorization enforced on the server.

- Happy-path + failure-path automated tests exist.

- Audit/history behavior verified for critical operations.

- UI loading, empty, error and success states implemented.

- Telemetry/logging included for important commands.

- No direct database mutation bypasses domain/application services.

# 30. Non-negotiable Implementation Rules

- Do not put domain rules in React.

- Do not directly mutate stock balance from controllers.

- Do not edit posted invoices or posted stock movements in place.

- Do not calculate final prices only in the frontend.

- Do not infer production history from the current stage; persist stage executions.

- Do not use floating point for financial amounts.

- Do not couple document PDF generation to the HTTP request when it is slow; queue it.

- Do not make generic CRUD endpoints the only mechanism for business operations.

- Do not allow silent quantity corrections; require explicit reason and actor.

# 31. Recommended Technology Set

| **Layer**   | **Recommendation**                                                                                                               |
|-------------|----------------------------------------------------------------------------------------------------------------------------------|
| Backend     | PHP 8.2+, Symfony 7.4 LTS, Doctrine ORM, Symfony Validator, Security, Messenger, Serializer.                                     |
| API docs    | OpenAPI; API Platform can be used selectively for resource exposure and documentation.                                           |
| Frontend    | React 19.2, Vite 8.x, TypeScript.                                                                                                |
| Client data | TanStack Query; lightweight local store only where needed.                                                                       |
| Forms       | React Hook Form + Zod (or equivalent).                                                                                           |
| Database    | PostgreSQL 18.x.                                                                                                                 |
| Cache/queue | Redis; Symfony Messenger.                                                                                                        |
| Storage     | S3-compatible object storage / MinIO.                                                                                            |
| Search      | PostgreSQL FTS initially; Meilisearch later if justified.                                                                        |
| Testing     | PHPUnit + Symfony WebTestCase; Vitest + React Testing Library; Playwright for E2E.                                               |
| UI          | A consistent internal design system using accessible components; prioritize dense admin workflows and responsive customer pages. |

# 32. Current Technology References

- Symfony releases: https://symfony.com/releases — Symfony 7.4 is the current LTS maintained branch; Symfony 8.1 is the current stable branch as of September 2026.

- React versions: https://react.dev/versions — React 19.2 is listed as the latest version.

- Vite releases: https://vite.dev/releases — Vite 8.x is the current major line; Vite 8.1 was released June 2026.

- PostgreSQL versioning: https://www.postgresql.org/support/versioning/ — PostgreSQL 18 is supported; use the latest current minor release for the selected major.

- Symfony Messenger: https://symfony.com/doc/7.4/messenger.html — supports synchronous and asynchronous message handling via transports.

- API Platform + Symfony: https://api-platform.com/docs/main/symfony/ — useful for OpenAPI/API infrastructure and optional generated resource endpoints.

- Keycloak: https://www.keycloak.org/ — open-source identity and access management; optional for SSO rather than required in V1.

# 33. Final Architecture Recommendation

Build this as a modular Symfony monolith with a React/Vite frontend. The most valuable custom domain is production + inventory + backorder orchestration. Keep accounting deliberately lightweight in V1 but model receivables/payments rigorously. Use third-party/open-source components at infrastructure boundaries, not as replacements for the shop’s core domain rules.

The implementation team should treat this document as the architecture baseline and convert each phase into epics, tickets, database migrations, OpenAPI contracts and acceptance tests. Changes to the domain model should be reviewed like architecture changes, especially around stock, production quantity and posted financial documents.
