=== Development Assistant ===

Stable tag: 1.2.10
Contributors: Dmitry Pripa
Requires PHP: 7.4.0
Requires at least: 5.0.0
Tested up to: 6.8.2
License: GPL-2.0-or-later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
Tags: development, support, debug, testing, manager

Toolkit for debugging and customer support.

== Description ==
Development Assistant is a comprehensive toolkit designed to streamline the development process and enhance support capabilities within WordPress. Whether you're a seasoned developer or a novice WordPress user, this plugin provides essential functionalities to manage debugging, diagnose issues, and facilitate smoother development workflows.

= Features =
- **Debugging Made Easy:** Enable WP_DEBUG, WP_DEBUG_LOG, and WP_DEBUG_DISPLAY modes directly from the WordPress admin panel without the need to manually edit the wp-config.php file. Effortlessly toggle these settings to facilitate efficient debugging and error tracking.
- **Create Support User in One Click:** Create a support user with a single click to provide temporary access to your WordPress environment. This feature simplifies the process of sharing debugging information with developers or support teams, enabling them to diagnose and resolve issues more effectively. You can control after how many days the user will be auto-deleted. After creating a user, you can quickly copy the credentials to the clipboard, or share them via email (optionally adding a message).
- **Plugin Conflict Resolution:** Simplify the process of identifying and resolving plugin conflicts. Quickly compare the performance of active and inactive plugins, and temporarily disable or enable plugins to isolate issues without disrupting your entire plugin ecosystem.
- **SMTP Testing with MailHog:** Seamlessly integrate MailHog for SMTP testing purposes. Verify the functionality of email delivery within your WordPress environment, ensuring reliable communication with users and clients.
- **Download Plugins:** Download plugins directly from the WordPress admin panel's plugin view. Streamline your workflow by easily obtaining plugin files for offline storage, manual installation, or testing in other environments and sandboxes. This feature facilitates seamless testing of plugins in various environments, allowing for thorough evaluation and development iterations.
- **Reset:** Effortlessly undo any changes made by the plugin to restore your WordPress environment to its original state. This feature deletes all plugin settings and data from the database, resets debug constants to their pre-activation states, deletes the debug.log file (if it didn't exist before activation), and activates any temporarily deactivated plugins.

= Who Can Benefit =
- **Power Developers:** Streamline your development workflow with a comprehensive toolkit tailored for debugging and issue resolution. Enhance productivity and efficiency while tackling complex WordPress projects.
- **Novice Users:** Empower yourself to diagnose and troubleshoot WordPress issues with ease. Quickly share debugging information with developers or support teams to expedite issue resolution and enhance your WordPress experience.

Development Assistant is your go-to solution for simplifying WordPress development tasks and enhancing support capabilities. Whether you're troubleshooting intricate issues or optimizing your development workflow, this plugin equips you with the tools you need for success.

== Changelog ==

= 1.2.10 =
- Minor fixes

= 1.2.9 =
- Fixed plugin activation logic (hook was used too late, preventing logic from executing)

= 1.2.8 =
- Just updated dependencies and codebase accordingly

= 1.2.7 =
- Fixed Assistant Panel positioning on the screen
- Fixed MailHog host handling
- Refactored code base to improve performance

= 1.2.6 =
- Minor fixes

= 1.2.5 =
- Added filter hooks for customization
- Minor improvements

= 1.2.4 =
- Minor fixes and improvements

= 1.2.3 =
- Moved development environment settings to a separate tab
- Added ability to manually change the environment type to development
- Added advanced MailHog settings
- Added MailHog to Assistant Panel (for development environment only)
- Added display of debug.log size

= 1.2.2 =
- Fixed creation support user

= 1.2.1 =
- Added confirmation before deactivating plugin without resetting
- Minor fixes and improvements

= 1.2.0 =
- Added Assistant Panel
- Added ability to create and manage the support user
- Migrated to PHP 7.4, so this is now the minimum required version
- Minor fixes and improvements

= 1.1.3 =
- Added protection against direct access to the debug log via the link
- Minor fixes

= 1.1.2 =
- Minor fixes

= 1.1.1 =
- Minor fixes

= 1.1.0 =
- Added ability to temporarily deactivate plugins and reactivate them all in one click
- Added ability to download installed plugins directly from the plugins screen
- Added ability to download the debug.log file
- Fixed ability to disable plugin reset
- Other minor improvements

= 1.0.3 =
- UX improvements

= 1.0.2 =
- UX improvements
- Minor fixes

= 1.0.1 =
- Minor fixes and improvements

= 1.0.0 =
- Initial release
