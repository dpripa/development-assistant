# Architecture

Development Assistant is a WordPress admin plugin. Runtime behavior is owned by
PHP, while TypeScript and CSS provide focused interactions and presentation for
admin screens.

Release authorization and publishing procedures are defined separately in
[RELEASE_POLICY.md](RELEASE_POLICY.md).

## Entry Point and Composition

`development-assistant.php` defines the plugin constants, loads Composer's
autoload file, and creates the singleton `WPDevAssist\App` instance.

`inc/App.php` is the composition root. It constructs shared infrastructure and
injects it into feature-level objects:

- `ActionQuery` registers nonce-protected query actions.
- `AdminNotice` stores and renders admin feedback.
- `Fs` resolves plugin and WordPress filesystem paths.
- `Asset` enqueues generated styles and classic scripts.
- `ExternalFileMutationManager` is the only runtime boundary allowed to mutate
  files outside the plugin directory. `Htaccess`, `DebugConfigEditor`, and
  `WPDebug` build debug-safety behavior on that boundary.
- `Setting`, `PluginsScreen`, and `Assistant` compose the visible admin features.

Keep dependency construction in `App`. Feature classes should receive their
dependencies explicitly instead of creating parallel global service locators.

## Feature Boundaries

### Settings

`inc/Setting.php` owns the main settings page and coordinates the settings
features under `inc/Setting/`:

- `DebugLog` renders, downloads, and deletes the WordPress debug log.
- `SupportUser` manages the temporary support-user lifecycle and email sharing.
- `Control`, `Page`, `BasePage`, and `Tab` provide shared settings UI structure.

WordPress options are the persistent source of truth. New options must have an
explicit key, default, registration path, reset behavior, and compatibility
story. The plugin applies one production-safe policy on every host; it does not
detect or expose a separate development-environment mode.

### Assistant Menu

`inc/Assistant.php` renders a custom menu in the WordPress admin bar for
authorized users, both in wp-admin and on the front end when the toolbar is
visible. Sections under `inc/Assistant/` expose status and actions for debug
configuration and the support user. The menu consumes the same services and
options as the settings pages; it must not introduce a second state model.
State-changing menu actions use wp-admin URLs so the existing `admin_init`
handlers, nonce validation, and capability checks apply from either context.

### Plugins Screen

`inc/PluginsScreen.php` adds Development Assistant actions to the WordPress
plugins screen. `ActivationManager` owns temporary deactivation state, and
`Downloader` owns plugin archive downloads.

## Actions and Security Boundaries

State-changing admin links are registered through `ActionQuery`. Handlers must
retain nonce validation, capability checks, input sanitization, output escaping,
and safe redirects. Features that expose logs, plugin archives, user credentials,
or configuration changes require particular care.

All runtime writes, replacements, or deletions outside the plugin directory
must be registered with `ExternalFileMutationManager`; direct filesystem
mutation from feature classes is rejected by the Composer lint checks. Critical
`wp-config.php` and `.htaccess` edits are fail-closed: the manager checks the
WordPress file-modification policy and filesystem permissions, locks the
target, stores a protected baseline and transaction backup under
`wp-content/.development-assistant-recovery/`, writes through a same-directory
temporary file, verifies the result, and rolls back a failed transaction. A
successful operation may be reported only after readback validation. Recovery
files are sensitive server state and must never be exposed or packaged.

Do not weaken authentication or authorization for local-development
convenience. Never persist or log plaintext credentials beyond the feature's
existing, documented lifecycle.

## Activation, Deactivation, and Reset

Activation initializes default options and records the original debug-related
state. Deactivation always removes managed access directives. When reset is
enabled, deactivation also restores debug configuration, removes plugin-owned
state, handles the debug log according to its original existence, removes the
support user, and, through the managed deactivation flow, restores temporarily
deactivated plugins.

Changes affecting files, options, users, or plugin activation must be checked
against both deactivation modes: reset enabled and reset disabled.

## Frontend Assets

Frontend source is organized by admin feature under `src/`. Entry points import
their own plain CSS and use strict TypeScript. PHP provides runtime data through
localized global objects declared in `src/globals.d.ts`.

Vite produces minified and non-minified files directly under `assets/`. PHP
loads the minified classic-script filenames. The Vite build rejects output that
would require module imports or shared chunks, preserving compatibility with
the current `wp_enqueue_script` integration.

`assets/` is generated and ignored by Git. Run `make build-src` to recreate it.

## Local Runtime

The Docker Compose environment owns WordPress, MariaDB, nginx, Mailpit, Adminer,
WP-CLI, and Composer tooling. The complete local WordPress runtime lives in the
ignored `wp/` directory, with this repository linked into the standard plugin
location.

Local runtime state, credentials, database backups, uploads, logs, and generated
certificates must never be committed.

PHP integration tests use the official WordPress PHPUnit bootstrap and run in
the same PHP 8.2 image as the local WordPress service. The test profile owns a
separate fixed-name MariaDB database backed only by tmpfs; it does not connect
to `db`, mount `db_data`, or retain a test database after the run. Architecture
tests in the same PHPUnit suite enforce the external-file mutation boundary.

## Mail Health

`App` composes `MailHealth\Store`, `Monitor`, `Probe`, `Scheduler`, and
`Setting\MailHealth`. The submenu has one autosaved on/off checkbox and a compact
status. Native POST submission remains available without JavaScript. The existing
`setting` script handles autosave and authenticated status refresh every three
seconds while the page is visible. Mutation endpoints require POST,
`manage_options`, and the toggle nonce; the read endpoint requires capability
and a separate nonce. No manual test or receipt-confirmation endpoint exists.

The per-blog non-autoloaded `wp_dev_assist_mail_health` option uses schema version
2. Its only user choice is enabled. Recipient comes from `admin_email`; check
interval (one hour), evidence lifetime (two hours), SMTP timeout (15 seconds),
and retries (approximately 5/15 minutes) are internal constants. Enabling queues
an immediately due background check. A pending job becomes visibly delayed
after 30 seconds; its original age is preserved when a missing cron entry is
restored. An overdue pending job may execute when cron resumes, provided its
configuration and attempt still match. Worker execution retains its own
two-minute deadline. Disabling clears jobs, skips passive observation,
and hides notices. Schema migration removes former recipient/timing settings,
receipt tickets, delivery evidence, and manual cooldown state while preserving
the enabled choice, live worker lease, and hourly send counters.

Internal transport, freshness, scheduler, and observation evidence remain
separate so stale or unobservable results cannot become a healthy status.
`State::summary()` derives the short user-facing state. `Store` uses conditional
SQL compare-and-swap and explicit cache invalidation. Workers can update only
their own attempt/generation within a two-minute lease. The rolling cap is six
probe sends per site per hour; concurrent requests and stale callbacks cannot
bypass it. SMTP operation timeouts are restored in `finally`. Arbitrary custom
transport execution cannot be forcibly cancelled by this deadline.

`Monitor` observes core mail hooks and the WP Mail SMTP 4.9+ synchronous SMTP
success hook. It correlates only request-local message hashes and blog context,
never retaining message bodies or customer addresses. Queued/API/custom-mailer
return values do not prove actual sending. Observed fallback success retains the
primary failure. An unrelated successful message cannot resolve an incident;
an accepted probe can. No inbox/provider delivery integration is included, and
transport acceptance never claims delivery to Inbox.

Known mail option changes and administrator email changes invalidate old evidence
and queue a fresh check on the next initialization. Network activation remains
lazy; network deactivation clears each site's jobs in batches of 100 and honors
its reset setting. Events are bounded to 20 entries/seven days. The checkbox is
bound to site identity to disable detected cloned-site monitoring.


The local `scripts/init-wp.sh` writes the ignored MU plugin
`wp/wp-content/mu-plugins/development-assistant-local-cron.php`. Its
`cron_request` filter only redirects the local/development site's own
`localhost` origin, matching port and cron path, to Apache at the `wordpress` Compose service on its internal port 80. This
works from both the WordPress and WP-CLI containers. The cron query, request options, and canonical Host are
preserved; forwarded scheme keeps WordPress's HTTPS context. Other origins,
paths, and production environments are unchanged. No general HTTP interception,
TLS-validation override, or production-plugin dependency is introduced.

### Actionable mail diagnoses

`State` distinguishes `invalid_sender` (local address validation) from the broader
sender/relay sending failure. `Monitor` recognizes the current PHPMailer invalid
address prefix, including a translated WordPress prefix. Only the plugin's own
probe may retain a bounded ASCII address-shaped From value from that exact error;
ordinary messages keep the fixed category only. Arbitrary provider text, URLs,
HTML, multiline data, and oversized values are never retained as sender metadata.
The additive `diagnostic` field defaults empty for existing schema-2 state.

A local sender validation error is a site configuration incident. It does not
trigger the temporary transport-failure retry sequence; normal periodic checks
continue. Sender metadata follows the selected incident/probe and clears on
successful probe recovery or a different failure. `Setting\\MailHealth` renders
fixed translatable diagnosis/impact/action messages and escapes all displayed
metadata. It revalidates stored sender metadata before rendering. Notices share
the same diagnosis/action mapping. Cron delay and unverifiable sending have their
own guidance and never assert an SMTP outage. No settings or frontend protocol
changes are required.
