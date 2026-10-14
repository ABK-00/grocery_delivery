# GroceryDelivery Feature Matrix

This document defines the feature-complete MVP scope for the multi-vendor grocery delivery platform.

## Public / Authentication
- [x] Landing/access chooser
- [x] Customer registration and login
- [x] Company registration and admin login
- [x] Staff login
- [x] Delivery partner login
- [x] Super admin login
- [x] Password reset
- [x] Role-aware logout and redirects
- [x] Company subscription access enforcement

## Marketplace / Customer
- [x] Multi-vendor marketplace
- [x] Vendor storefronts
- [x] Product browsing and product details
- [x] One-vendor cart
- [x] Checkout
- [x] Saved addresses
- [x] Order history/details
- [x] Rebuild cart from failed/cancelled order
- [x] Paystack checkout
- [x] Payment expiry and stock restoration
- [x] Live delivery tracking
- [x] Notifications
- [x] Support tickets
- [x] Shopping preferences
- [x] Customer Support AI
- [x] Personal Shopping AI

## Vendor Admin
- [x] Dashboard
- [x] Storefront management
- [x] Categories
- [x] Products and product images
- [x] Staff management
- [x] Delivery partner management
- [x] Customer/order relationship view
- [x] Order fulfillment
- [x] Delivery assignment
- [x] Payments
- [x] Refund workflow
- [x] Reports
- [x] Subscription/billing
- [x] Profile
- [x] Notifications/support
- [x] AI operations assistant

## Staff
- [x] Dashboard
- [x] Order processing
- [x] Delivery assignment workflow
- [x] Profile
- [x] Notifications/support
- [x] AI operations assistant

## Delivery Partner
- [x] Dashboard
- [x] Assigned orders
- [x] Delivery history
- [x] Delivery details
- [x] GPS/live location updates
- [x] Profile
- [x] Notifications/support
- [x] AI delivery assistant

## Super Admin
- [x] Dashboard
- [x] Company management
- [x] Company detail view
- [x] User management
- [x] Subscription monitoring
- [x] Payment monitoring
- [x] Refund monitoring
- [x] Reports
- [x] Platform settings
- [x] Audit logs
- [x] Support queue
- [x] AI executive assistant
- [x] AI usage monitoring

## Payment / Inventory Reliability
- [x] Order placement idempotency
- [x] Payment initialization idempotency
- [x] Payment callback idempotency
- [x] Signed Paystack webhook
- [x] Amount/currency verification
- [x] Stock reservation
- [x] Idempotent stock restoration
- [x] 15-minute unpaid order expiry
- [x] Automated expiry cleanup script
- [x] Refund state tracking
- [x] Late-payment handling

## Security / Operations
- [x] Password hashing/verification
- [x] Session regeneration on login
- [x] Role authorization
- [x] Company ownership scoping
- [x] Customer accounts remain platform-scoped
- [x] Prepared SQL for user-controlled input
- [x] CSRF protection on mutation flows
- [x] Secrets excluded from Git
- [x] Audit logging
- [x] Health-check script
- [x] PHP/frontend CI checks
- [x] Subscription maintenance job

## AI Guardrails
- [x] OpenAI Responses API
- [x] API key kept outside source control
- [x] Role-scoped context
- [x] Role-scoped function tools
- [x] Delivery-partner data isolation
- [x] Customer personalization opt-out
- [x] Daily request limit
- [x] Global AI enable/disable switch
- [x] Token usage monitoring
- [x] AI audit events
- [x] Read-only operational tools by default

## Production Setup Still Requires Environment Configuration
These are deployment/configuration steps rather than missing application features:
- Configure production database credentials.
- Configure production Paystack secret key and public webhook URL.
- Configure OpenAI API key.
- Use HTTPS.
- Schedule payment-expiry and subscription-maintenance jobs.
- Run migrations on an existing database.
- Run the health check and smoke-test each role before launch.
