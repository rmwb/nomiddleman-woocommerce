# Naming compatibility

First-party PHP classes, functions and constants now use `NMMPRO_` / `nmmpro_`.

- Canonical `nmmpro_` options fall back to retained `nmm_` values on first read, copying them without deleting the original. New writes use canonical keys; deletes remove both names.
- Public filters/actions invoke the legacy `nmm_` name before the canonical `nmmpro_` name. Register a callback under one name to avoid running it twice.
- Configuration reads prefer `NMMPRO_` constants and accept corresponding `NMM_` constants as a fallback.
- The new scheduled hook is registered before old schedules are retired. Legacy scheduled callbacks remain registered for in-flight jobs.
- Customer and admin AJAX callbacks retain legacy aliases and applicable legacy nonce support. Bundled JavaScript uses the new names.
- Existing payment/HD table names, order metadata, gateway identity and advisory lock names are retained where required for stored data and concurrent workers. Chosen-coin metadata reads also accept the old key.
- Extension file lookup prefers `NMMPRO_<Name>.php`, falls back to `NMM_<Name>.php`, and rejects path traversal. This preserves file discovery, not arbitrary direct calls to renamed internal PHP classes. Third-party extensions using those classes need review.

`tests/test-upgrade-compat.php` exercises settings, hooks, constants, scheduled jobs, customer AJAX and extension file discovery in WordPress. This fixture test does not replace a full production-store upgrade rehearsal.
