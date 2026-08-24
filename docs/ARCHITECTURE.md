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

### Assistant Panel

`inc/Assistant.php` renders the admin notice panel. Sections under
`inc/Assistant/` expose status and actions for debug configuration and the
support user. The panel consumes the same services and options as the settings
pages; it must not introduce a second state model.

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
