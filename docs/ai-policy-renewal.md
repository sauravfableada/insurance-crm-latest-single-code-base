# AI policy renewal

## Deployment

Deploy the controllers, services, model, routes, views and JavaScript together. Run from the project root:

```sh
php artisan migrate --path=database/migrations/2026_10_29_000002_add_ai_renewal_integrity_fields.php --force
php artisan optimize:clear
```

Existing policy-member, policy-add-on and PDF-history migrations must also be installed. The save endpoint refuses to discard extracted members or add-ons when their tables are missing. The integrity migration adds a unique document hash and a durable JSON review record; it does not modify existing terms.

## Workflow

Use **AI Renewal** on Manage Policies, **Renew** on the policy dashboard, or **Renew with AI** on the manual renewal page. Choose the exact source policy first. New policies use the separate AI upload page.

Uploads and extraction reports remain private and are bound to their uploader and selected source policy. Renewal extraction does not create customers. The existing PDF text/OCR pipeline, all-page chunking, field suggestions and JSON reports are reused.

The user reviews old/new values, insured persons and add-ons, then confirms saving the linked renewal with a single button. Missing optional values carried forward are identified in the comparison and can be explicitly cleared for the new term. Changed numbers, similar names, category changes and incomplete/uncertain extraction remain flagged in the page comparison. The compact save dialog does not require extra checkboxes or written verification notes. Clearly different holder names and conflicting holder dates of birth are blocked, even if the policy number matches.

Saving locks the source term, validates its extraction-time fingerprint, rejects duplicate terms/documents and an already-renewed source, and creates a linked child term. Documents, members and add-ons belong to the new term. The previous row and its documents remain unchanged. The new term stores the original extraction, reviewed values, confirmation timestamp and actor in `renewal_review`. Failed saves roll back new database rows and remove the new PDF. Existing historical information is displayed as originally stored; details overwritten by older versions cannot be reconstructed automatically.

Manual renewal now creates a linked term as well. Policy history shows each term's dates, number, insurer, premium, cover and document. Current dashboard statistics/reminders exclude superseded terms.

## Validation

```sh
php vendor/phpunit/phpunit/phpunit tests/Feature/AiPolicyRenewalTest.php
```

Tests cover unchanged/changed numbers, changed insurers/categories/premiums/coverage, independent Health/General/Vehicle/Life/Travel/Marine/Fire/Burglary/Personal Accident/Other saves, similar and unrelated holders, DOB conflicts, missing/incomplete extraction, duplicate terms/documents, multiple policies for one holder, stale sources, permissions, HTTP review/confirmation, source-bound uploads, rollback/file cleanup, manual history preservation and extraction without customer creation. OCR/provider behavior uses the existing configured AI providers; real provider output still requires human review and is not guaranteed correct by the deterministic integrity checks.
