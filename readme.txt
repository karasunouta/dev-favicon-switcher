=== Dev Favicon Switcher ===
Contributors: yourname
Tags: favicon, development, staging, site icon, environment
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically switches favicon between production and development environments to prevent confusion.

== Description ==

Dev Favicon Switcher helps you distinguish between your production and development environments by automatically displaying different favicons based on the URL.

**Perfect for:**
* Developers working with Local by Flywheel or similar tools
* Teams managing multiple environments (production, staging, development)
* Anyone who frequently switches between live and test sites

**Key Features:**
* Automatic favicon switching based on environment URL
* One-click generation of required icon sizes (32x32, 180x180, 192x192, 270x270)
* Support for different upload folder paths between environments
* Simple, intuitive settings interface
* Works seamlessly with All-in-One WP Migration and other migration tools

== Installation ==

1. Upload the `dev-favicon-switcher` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > Dev Favicon to configure

== Configuration ==

1. **Set your production favicon** in Settings > General (if not already set)
2. **Go to Settings > Dev Favicon**
3. **Select a development icon** from your media library (or upload a new one)
4. **Enter your development URL** (e.g., https://mysite.local/)
5. **Click "Check Sizes"** to see if all required icon sizes exist
6. **Click "Generate Missing Sizes"** if needed
7. **Save settings**

**Advanced Options:**
If your production and development icons are stored in different year/month folders (e.g., /2025/10/ vs /2026/01/), you can specify these paths in the "Path Override" section.

== Frequently Asked Questions ==

= Does this work with All-in-One WP Migration? =

Yes! That's exactly what it was designed for. Once configured, the plugin will automatically display the correct favicon after each import, without manual intervention.

= What icon sizes are required? =

The plugin generates the standard WordPress site icon sizes:
* 32x32 (standard favicon)
* 180x180 (Apple touch icon)
* 192x192 (Android Chrome)
* 270x270 (Windows tile)

= Can I use this on multiple development environments? =

Yes, just add multiple development URLs separated by commas, or use a wildcard pattern.

= Will this affect my production site? =

No. The plugin only switches the favicon when the current URL matches your specified development URL. Your production site will always show the original favicon.

= What file formats are supported? =

The plugin currently supports PNG files, which is the recommended format for favicons.

== Screenshots ==

1. Settings page with production and development favicon preview
2. Icon size checker showing which sizes exist
3. Automatic favicon switching in browser tabs

== Changelog ==

= 1.0.0 =
* Initial release
* Automatic favicon switching based on environment URL
* Icon size generator
* Support for path override
* Integration with WordPress media library

== Upgrade Notice ==

= 1.0.0 =
Initial release

== Usage Example ==

**Scenario:** You're developing a site locally using Local by Flywheel, and frequently importing data from the live site using All-in-One WP Migration.

**Problem:** After each import, you lose track of which browser tab is your local site vs. the live site.

**Solution:**
1. Create a monochrome or distinctly colored version of your site icon
2. Upload it to your media library
3. Configure Dev Favicon Switcher with:
   - Development Icon: Your monochrome icon
   - Development URL: https://mysite.local/
4. Click "Generate Missing Sizes"
5. Save settings

**Result:** Every time you open your local site, it will display the monochrome icon. The live site will always show the original colored icon. No manual work needed after AIOWM imports!

== Support ==

For issues, questions, or suggestions, please visit:
https://github.com/yourusername/dev-favicon-switcher/issues

== Credits ==

Developed to solve a real-world workflow problem for WordPress developers using migration tools.