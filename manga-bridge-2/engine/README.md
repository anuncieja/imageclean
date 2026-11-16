# Manga Bridge 2.0 — external engine, milestone 1

**Status: experimental, isolated, no live import, no automatic publishing.**

This is a working, dependency-free Node.js 22+ proof of concept for validating complete manga manifests, deduplicating job IDs and generating safe dry-run reports. Nothing in this milestone connects to a WordPress installation, performs source scraping, downloads images or accesses the Cloudflare Worker. Only synthetic example.org test content is used.

Run from the engine directory with npm test and npm run demo.

A job is considered ready only when its explicit expectedChapters matches the chapter list, chapter numbers/URLs are unique, and each chapter contains valid, nonduplicated HTTPS image URLs. URLs are structurally checked, not network-verified. Ready means ready for later verification, not ready to publish. All reports specify allowPublish: false.

The included fetchBounded() is an isolated building block for later source adapters: hostname allowlist, no redirects, 8s default timeout, 1 MiB response cap, no retries. Do not enable external fetching with user-supplied allowlists; production also needs egress protection against DNS rebinding, per-host rate limits, and policy checks.

The queue demo persists a ready job atomically to a local folder with conflict protection. Actions runners are ephemeral, therefore this is NOT a durable production queue; a persistent job store and a signed WordPress bridge will be designed and tested separately.

Next gates:
1. Obtain the exact v1.7.52 plugin ZIP and map the current WordPress data contract without destructive changes.
2. Reproduce source chapter pagination using permitted, verifiable endpoints; add source adapter fixture tests.
3. Add low-load image verification and resumable/durable queue with strict host-level request budgets.
4. Add authenticated WP read/dry-run endpoint, staging-only tests and rollback.
5. Analyze Cloudflare Worker traffic before any proxy configuration changes.

CI runs on pushes to branch manga-bridge-2; legacy ImageClean files on main are untouched. Repository is currently PUBLIC: never commit credentials, site exports, customer data, private logs or sensitive information.
