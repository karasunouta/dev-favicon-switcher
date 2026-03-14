=== Dev Favicon Switcher ===
Contributors: karasunouta
Tags: favicon, development, staging, site icon, environment
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.0
Stable tag: 1.3.12
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically switches your favicon (site icon) between production and development environments to prevent confusion.

== Description ==

**Dev Favicon Switcher** helps you easily distinguish between your production and development environments. It automatically displays a different favicon based on the current URL, avoiding mistakes where you might accidentally edit the live site thinking it's the development environment.

**Key Features:**

* **Automatic Environment Detection**: Automatically applies the development favicon on common local domains (`.local`, `.test`, `.dev`).
* **Custom Development URLs**: Specify exact URLs where the development favicon should appear (supports multiple URLs).
* **Easy Icon Management**: Upload your own dev favicon using the WordPress media uploader, complete with built-in image cropping.
* **Restore Default Icon**: Don't have an icon handy? Easily apply our unified default development favicon with a single click.
* **Automatic Size Generation**: Automatically generates all standard WordPress site icon sizes (32x32, 180x180, 192x192, 270x270) to ensure compatibility across all devices and browsers.
* **No Frontend Bloat**: Everything works efficiently via standard WordPress filters without adding unnecessary frontend scripts.

Perfect for developers working with local environments, staging servers, and migration tools like All-in-One WP Migration.

== Installation ==

1. Upload the `dev-favicon-switcher` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Configure the plugin by going to **Settings > Dev Favicon**.

== Frequently Asked Questions ==

= Does this modify my actual production site? =
No. The plugin only overrides the favicon URL dynamically when it detects that you are accessing the site via one of your configured development URLs (or auto-detected extensions). Your live site will continue to load its normal configured site icon.

= How does the auto-detect feature work? =
If enabled, the plugin checks your current domain. If it ends with `.local`, `.test`, or `.dev`, it automatically considers the environment a development one and switches the favicon.

= What if I don't have a specific development favicon to upload? =
You can simply click the "Restore Default" button in the settings, and the plugin will apply a built-in default development favicon for you.

= Does it support image cropping? =
Yes! When you upload or select a new development favicon from the media library, the plugin provides a cropping tool just like the native WordPress Site Icon feature.

== Screenshots ==

1. The plugin settings screen where you choose your development icon.
2. The favicon switching in action on a browser tab.

== Changelog ==

= 1.3.12 =
* Added a refined settings UI with standard WordPress styling.
* Added standard WP image cropping implementation.
* Added "Restore Default" favicon capability.
* Added automatic garbage collection for old unused favicons.
* Fully prepared for translation.

= 1.0.0 =
* Initial release.
