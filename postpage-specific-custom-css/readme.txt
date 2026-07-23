=== Post/Page Specific Custom Code ===

Tags: custom css, per-page css, post-specific, page-specific, woocommerce
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 7.4
Contributors: lukasznowicki
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=LEXEGNRGEF7H4
Stable tag: 0.3.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Add custom CSS to individual posts, pages, or WooCommerce products, with optional archive support and a dedicated code editor.

== Description ==

Post/Page Specific Custom Code lets you add custom CSS to individual posts, pages, or WooCommerce products. A dedicated box on the edit screen is where you write your CSS. You can also choose whether it should load only on the single view or on archive-style views as well.

The meta box appears below the content editor for posts, pages, and products. Enter your CSS there and decide whether it applies only on the single view or also on archive-style views (blog/category listings, the WooCommerce shop and product categories/tags). Product CSS also loads on a best-effort basis for products rendered through standard WooCommerce loop templates outside the main query (for example the `[products]` shortcode). CSS discovered only after the page head may be printed in the footer. Custom product markup that skips WooCommerce templates needs a small integration hook. Products injected only via AJAX or infinite scroll are not covered.

CSS fields use a code editor with syntax highlighting by default. You can turn highlighting off with a filter if you prefer a plain textarea (for example on slower machines).

== Installation ==

= Automatic installation =

Automatic installation is the easiest option. Log in to your WordPress admin, go to Plugins, and click Add New.

Search for *Post/Page Specific Custom Code*, then click Install Now.

= Manual installation =

1. Download the plugin ZIP from WordPress.org (or from this repository).
2. Upload the `postpage-specific-custom-css` folder to the `/wp-content/plugins/` directory (so the main plugin file is at `/wp-content/plugins/postpage-specific-custom-css/post-page-specific-custom-css.php`).
3. Activate the plugin through the Plugins menu in WordPress.
4. That's it — enjoy!

== Requirements ==

This plugin requires WordPress <b>5.0</b> or later (please keep WordPress up to date) and <b>PHP 7.4</b> or later on your server.

== Frequently Asked Questions ==

= Is it free? =
Yes. It is licensed under GPLv2 (or later). If you would like to support the work, you are welcome to donate — thank you!

= How do I disable CSS code highlighting in the editor? =
Highlighting is on by default. To use a plain textarea instead, add this to your theme’s `functions.php` or a small custom plugin:

`add_filter( 'postpage_sccss-highlight_code', '__return_false' );`

This affects the Custom Code box on posts, pages, and products, and the default CSS fields on the plugin settings screen. Disabling highlighting uses a plain textarea. On posts, pages, and products, automatic syntax checking still runs for that textarea; the default CSS fields on the plugin settings screen do not have syntax checking.

= How do I load product CSS for a custom product loop? =
If your theme or plugin renders products with the standard WooCommerce templates (`wc_product_class()` / `content-product.php`), registration is automatic. If you output your own HTML, register the product before rendering it:

`do_action( 'postpage_sccss_register_product', $product_id );`

You may pass a product ID, a `WP_Post`, or a `WC_Product` instance.

== Screenshots ==

1. If you do not see the Custom Code panel, open Screen Options and make sure "Custom Code" is checked.
2. Enter CSS that applies to your post, page, or product. You can limit it to the single view only.
3. Plugin settings screen.

== Changelog ==
= 0.3.1 =
* Release date: 2026-07-23
* Fixed "single page view only" checkbox not reflecting the saved value (and overwriting it on save)
* Bundled css-tree 3.1.0 locally instead of loading it from a CDN
* Editor CSS linting is advisory only (highlights issues); CSS is no longer blocked on the front end via a client-side validity flag
* Removed `_phylax_ppsccss_valid` meta and the hidden live-checker form field
* Added server-side sanitization for CSS embedded in <style> tags (save and front-end output)
* Hardened CSS sanitizer: escape </style as <\/style (idempotent) instead of stripping sequences that could recombine into Stored XSS
* Added a sanitize callback for plugin settings (default CSS and option flags)
* Fixed WooCommerce permissions: Shop Managers can edit product CSS; Editors no longer get the product CSS box without product capabilities
* Save handler now checks edit capability for the specific post instead of trusting $_POST['post_type']
* Front-end CSS is printed in wp_head for the main query (less FOUC; one style block on archives); product CSS from secondary loops may be printed in wp_footer
* Restored `postpage_sccss-highlight_code` filter to disable CodeMirror highlighting (`add_filter( 'postpage_sccss-highlight_code', '__return_false' )`)
* Product CSS on WooCommerce shop and product taxonomy archives is taken from the main query (works without the_content in loop templates)
* Added best-effort support for products rendered through standard WooCommerce loop templates outside the main query (e.g. `[products]` shortcode), with an optional `postpage_sccss_register_product` action for custom markup
* Secondary product detection no longer uses `the_posts` (avoids loading CSS for products that were queried but never rendered)

= 0.3.0 =
* Release date: 2025-07-21
* Added support for WooCommerce products (custom CSS can now be assigned to individual products)
* Plugin renamed from "Post/Page specific custom CSS" to "Post/Page Specific Custom Code"

= 0.2.5 =
* Release date: 2025-07-17
* Highlighting for CSS code is now enabled by default.
    7 years ago it was optional due to performance concerns on
    slower machines — nowadays that looks outdated; you can still
    disable it using:
    `add_filter('postpage_sccss-highlight_code', '__return_false');`
* Fixed incorrect validation of an empty CSS field
* Replaced CSS validation with a linting mechanism
* Minor code improvements and compatibility testing with newer PHP and WordPress versions
* Removed birthday notice

= 0.2.4 =
* Release date: 2022-01-20
* Fixed default post/page values (they were reversed; thank you, @olandir, for pointing that out!)

= 0.2.3 =
* Release date: 2021-11-29
* Fixed options saving issue
* Fixed text formatting

= 0.2.2 =
* Release date: 2020-05-10
* Many fixes to stay aligned with WordPress coding standards
* Birthday banner visible only to administrators
* Birthday banner can now be hidden until the following year
* Editors can optionally be allowed to edit CSS

= 0.2.1 =
* Release date: 2020-04-27
* Custom JavaScript note
* Birthday note

= 0.2.0 =
* Release date: 2020-02-25
* Compatibility: WordPress 5.3 and earlier
* Added an options page for the plugin
* Added default CSS for posts and pages
* Added CSS highlighting for posts, pages, and the options screen (default CSS)
* Larger editor fields by default, with an option to make them even bigger

= 0.1.4 =
* Release date: 2018-11-21
* Status: Stable
* Compatibility: WordPress 5.0 and earlier
* Minor code refactoring

= 0.1.3 =
* Release date: 2018-05-18
* Status: Stable
* Compatibility: WordPress 4.9.6 and earlier

= 0.1.2 =
* Release date: 2018-05-05
* Status: Stable
* Compatibility: WordPress 4.9.5 and earlier
* Added screenshots, icons, and a header image for the WordPress repository

= 0.1.1 =
* Release date: 2017-08-03
* Status: Stable
* Compatibility: WordPress 4.8.1 and earlier
* Added screenshots, icons, and a header image for the WordPress repository

= 0.1.0 =
* Release date: 2016-12-16
* Status: Stable
* Initial release

== Upgrade Notice ==
= 0.3.1 =
Fixes the "single page view only" checkbox, CSS validation without a CDN, style-tag sanitization, WooCommerce permissions, wp_head output, and best-effort product CSS for secondary WooCommerce loops. Recommended update.

= 0.2.5 =
Several important improvements: the plugin is leaner, runs more efficiently, and no longer clutters the screen with extra notices. Updating is recommended.
