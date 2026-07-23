<?php
/**
 * Plugin Name: Post/Page Specific Custom Code
 * Plugin URI: https://wordpress.org/plugins/postpage-specific-custom-css/
 * Description: Add custom CSS to individual posts, pages, and WooCommerce products. Includes a dedicated editor on the edit screen, and an option to load styles only on single views or also on archives.
 * Version: 0.3.1
 * Author: Łukasz Nowicki
 * Author URI: https://lukasznowicki.info/
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Tested up to: 7.0
 * Text Domain: postpage-specific-custom-css
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

namespace Phylax\WPPlugin\PPCustomCSS;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use WP_Post;
use WP_Query;
use WP_Screen;

defined( 'ABSPATH' ) || exit;

add_action( 'before_woocommerce_init', function () {
    if ( class_exists( FeaturesUtil::class ) ) {
        FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

require_once __DIR__ . '/ViewHelpers.php';

class Plugin {

    const MENU_SLUG        = 'post-page-custom-css';
    const PARENT_MENU_SLUG = 'options-general.php';
    const OPTION_GROUP     = 'ppcs_settings_group';
    const OPTION_NAME      = 'ppcs_settings_name';

    const OPT_CONTROL_USER_EDITOR = 'control_user_editor';
    const OPT_DEFAULT_POST_CSS    = 'default_post_css';
    const OPT_DEFAULT_PAGE_CSS    = 'default_page_css';
    const OPT_DEFAULT_PRODUCT_CSS = 'default_product_css';
    const OPT_BIGGER_TEXTAREA     = 'bigger_textarea';

    const POST_META_CSS    = '_phylax_ppsccss_css';
    const POST_META_SINGLE = '_phylax_ppsccss_single_only';

    const CAP_MANAGE_OPTIONS    = 'manage_options';
    const CAP_EDIT_OTHERS_PAGES = 'edit_others_pages';
    const CAP_EDIT_PRODUCTS     = 'edit_products';

    const SUPPORTED_POST_TYPES = [
        'post',
        'page',
        'product',
    ];

    const CSSTREE_VERSION = '3.1.0';

    /** Filter: return false to disable CodeMirror CSS highlighting in admin editors. */
    const FILTER_HIGHLIGHT_CODE = 'postpage_sccss-highlight_code';

    /**
     * Action: register a product for deferred CSS (custom loops that skip WC templates).
     * Pass a product ID, WP_Post, or WC_Product: do_action( ..., $product ).
     */
    const ACTION_REGISTER_PRODUCT = 'postpage_sccss_register_product';

    /**
     * Filter: adjust product IDs whose deferred CSS will be printed in the footer.
     *
     * @param int[] $product_ids
     */
    const FILTER_DEFERRED_PRODUCT_IDS = 'postpage_sccss_deferred_product_ids';

    const DONATE_URL = 'https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=LEXEGNRGEF7H4';

    const USER_META_RELEASE_NOTES_SEEN = 'phylax_ppsccss_release_notes_v031_seen';
    const QUERY_RELEASE_NOTES          = 'ppsccss_release_notes';
    const NONCE_RELEASE_NOTES          = 'ppsccss_release_notes';

    private ViewHelpers $view;

    /**
     * Post IDs whose CSS was already emitted on this front-end request.
     *
     * @var array<int, true>
     */
    private array $front_css_printed_ids = [];

    /**
     * Product IDs rendered outside the main-query head pass (shortcodes, WC loops, hooks).
     *
     * @var array<int, true>
     */
    private array $seen_product_ids = [];

    /** Whether deferred product CSS was already printed for this request. */
    private bool $deferred_css_printed = false;

    public function __construct() {
        $this->view = new ViewHelpers( self::get_plugin_settings(), self::OPTION_NAME );
        if ( ! is_admin() ) {
            add_action( 'wp_head', [
                $this,
                'print_front_css',
            ],          100 );
            // Products rendered via standard WC templates (content-product, etc.).
            add_filter( 'woocommerce_post_class', [
                $this,
                'capture_rendered_product',
            ],          10, 2 );
            // Custom markup that never calls wc_product_class() / wc_get_product_class().
            add_action( self::ACTION_REGISTER_PRODUCT, [
                $this,
                'register_product_for_deferred_css',
            ],          10, 1 );
            // Late footer: catch loops that run during earlier wp_footer callbacks.
            add_action( 'wp_footer', [
                $this,
                'print_deferred_product_css',
            ],          PHP_INT_MAX );
        }
        if ( is_admin() ) {
            $this->startInAdmin();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_plugin_settings(): array {
        $settings = get_option( self::OPTION_NAME, [] );

        return is_array( $settings ) ? $settings : [];
    }

    /**
     * Convert mixed option/meta values to string without PHP 8 array-to-string warnings.
     *
     * @param mixed $value Raw value.
     */
    public static function stringify( $value ): string {
        if ( is_string( $value ) ) {
            return $value;
        }
        if ( is_int( $value ) || is_float( $value ) ) {
            return (string) $value;
        }
        if ( is_bool( $value ) ) {
            return $value ? '1' : '';
        }

        return '';
    }

    public function startInAdmin() {
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [
            $this,
            'page_settings_link_filter',
        ] );
        add_action( 'admin_notices', [
            $this,
            'maybe_show_release_notes',
        ] );
        add_action( 'add_meta_boxes', [
            $this,
            'add_meta_boxes',
        ],          10, 2 );
        add_action( 'save_post', [
            $this,
            'save_post',
        ] );
        add_action( 'admin_menu', [
            $this,
            'add_options_page',
        ] );
        add_action( 'admin_init', [
            $this,
            'register_settings',
        ] );
        add_action( 'admin_enqueue_scripts', [
            $this,
            'admin_enqueue_scripts',
        ] );
    }

    /**
     * Whether CodeMirror assets were successfully enqueued for this request.
     * null = enqueue not attempted yet.
     */
    private ?bool $code_editor_available = null;

    public function options_admin_enqueue_scripts() {
        $this->enqueue_code_editor_assets( [ 'type' => 'text/css' ] );
    }

    public function admin_enqueue_scripts(): void {
        $screen = get_current_screen();
        if ( ! $screen instanceof WP_Screen ) {
            return;
        }
        if ( 'post' !== $screen->base ) {
            return;
        }
        if ( ! in_array( $screen->post_type, self::SUPPORTED_POST_TYPES, true ) ) {
            return;
        }
        $post = get_post();
        if ( ! $post instanceof WP_Post || ! $this->user_can_edit_css( $post ) ) {
            return;
        }
        $this->enqueue_css_tree();
        $this->enqueue_code_editor_assets( [
            'type'       => 'text/css',
            'codemirror' => [
                'autoRefresh' => true,
            ],
        ] );
    }

    /**
     * Whether the plugin filter allows CodeMirror highlighting.
     *
     * Disable with: add_filter( 'postpage_sccss-highlight_code', '__return_false' );
     */
    private function is_code_highlighting_enabled(): bool {
        return (bool) apply_filters( self::FILTER_HIGHLIGHT_CODE, true );
    }

    /**
     * Enqueue WP code editor assets when the filter allows it and the user has
     * syntax highlighting enabled in their profile.
     *
     * wp_enqueue_code_editor() returns false when the user disabled highlighting;
     * that must be respected or inline JS will reference missing wp.codeEditor.
     *
     * @param array<string, mixed> $args Arguments for wp_enqueue_code_editor().
     */
    private function enqueue_code_editor_assets( array $args ): bool {
        if ( ! $this->is_code_highlighting_enabled() ) {
            $this->code_editor_available = false;

            return false;
        }
        $settings                    = wp_enqueue_code_editor( $args );
        $this->code_editor_available = false !== $settings;

        return $this->code_editor_available;
    }

    /**
     * Whether CodeMirror can be initialized on the current admin screen.
     */
    private function is_code_editor_available(): bool {
        return true === $this->code_editor_available;
    }

    private function enqueue_css_tree(): void {
        wp_enqueue_script(
            'phylax-ppsccss-css-tree',
            plugins_url( 'assets/js/csstree.js', __FILE__ ),
            [],
            self::CSSTREE_VERSION,
            true
        );
    }

    public function register_settings() {
        register_setting( self::OPTION_GROUP, self::OPTION_NAME, [
            'type'              => 'array',
            'sanitize_callback' => [
                $this,
                'sanitize_settings',
            ],
            'default'           => [],
        ] );
        add_settings_section( 'plugin-behavior', __( 'Options', 'postpage-specific-custom-css' ), [
            $this,
            'section_plugin_behavior',
        ],                    self::MENU_SLUG );
        add_settings_field( 'control-user', __( 'User control', 'postpage-specific-custom-css' ), [
            $this,
            'control_user_editor',
        ],                  self::MENU_SLUG, 'plugin-behavior' );
        add_settings_section( 'default-values', __( 'Default values', 'postpage-specific-custom-css' ), [
            $this,
            'section_default_values',
        ],                    self::MENU_SLUG );
        add_settings_field( 'default_post_css', __( 'Default CSS for new posts', 'postpage-specific-custom-css' ), [
            $this,
            'default_post_css',
        ],                  self::MENU_SLUG, 'default-values' );
        add_settings_field( 'default_page_css', __( 'Default CSS for new pages', 'postpage-specific-custom-css' ), [
            $this,
            'default_page_css',
        ],                  self::MENU_SLUG, 'default-values' );
        add_settings_field( 'default_product_css', __( 'Default CSS for new WooCommerce products', 'postpage-specific-custom-css' ), [
            $this,
            'default_product_css',
        ],                  self::MENU_SLUG, 'default-values' );
        add_settings_field( 'bigger_textarea', __( 'Larger input field', 'postpage-specific-custom-css' ), [
            $this,
            'bigger_textarea',
        ],                  self::MENU_SLUG, 'plugin-behavior' );
    }

    public function bigger_textarea() {
        $this->view->openFieldset( self::OPT_BIGGER_TEXTAREA );
        $this->view->screenReaderLegend( __( 'Make input fields larger', 'postpage-specific-custom-css' ) );
        $this->view->checkBoxField( self::OPT_BIGGER_TEXTAREA, __( 'Use a larger CSS editor on posts, pages, and products', 'postpage-specific-custom-css' ) );
        $this->view->closeFieldset();
    }

    public function control_user_editor() {
        $this->view->openFieldset( 'plugin_behavior' );
        $this->view->screenReaderLegend( __( 'Allow Editors to edit CSS', 'postpage-specific-custom-css' ) );
        $this->view->checkBoxField( self::OPT_CONTROL_USER_EDITOR, __( 'Allow Editors to edit CSS on posts and pages', 'postpage-specific-custom-css' ) );
        $this->view->closeFieldset();
        $this->view->printFieldDescription( __( 'This does not give Editors access to plugin settings or default CSS values. WooCommerce Shop Managers (and other users who can edit products) can always edit CSS on products they are allowed to edit — this option does not affect products. Be careful: incorrect CSS can break your site layout.', 'postpage-specific-custom-css' ) );
    }

    public function default_post_css() {
        $settings = self::get_plugin_settings();
        // get_option() values are already unslashed; wp_unslash() would strip CSS escapes (e.g. \f123).
        $value = self::stringify( $settings[ self::OPT_DEFAULT_POST_CSS ] ?? '' );
        $this->view->openFieldset( self::OPT_DEFAULT_POST_CSS );
        $this->view->screenReaderLegend( __( 'Default CSS for new posts', 'postpage-specific-custom-css' ) );
        $this->view->textAreaField( 'defaultPostCSS', self::OPT_DEFAULT_POST_CSS, $value );
        $this->view->closeFieldset();
    }

    public function default_page_css() {
        $settings = self::get_plugin_settings();
        $value    = self::stringify( $settings[ self::OPT_DEFAULT_PAGE_CSS ] ?? '' );
        $this->view->openFieldset( self::OPT_DEFAULT_PAGE_CSS );
        $this->view->screenReaderLegend( __( 'Default CSS for new pages', 'postpage-specific-custom-css' ) );
        $this->view->textAreaField( 'defaultPageCSS', self::OPT_DEFAULT_PAGE_CSS, $value );
        $this->view->closeFieldset();
    }

    public function default_product_css() {
        $settings = self::get_plugin_settings();
        $value    = self::stringify( $settings[ self::OPT_DEFAULT_PRODUCT_CSS ] ?? '' );
        $this->view->openFieldset( self::OPT_DEFAULT_PRODUCT_CSS );
        $this->view->screenReaderLegend( __( 'Default CSS for new WooCommerce products', 'postpage-specific-custom-css' ) );
        $this->view->textAreaField( 'defaultProductCSS', self::OPT_DEFAULT_PRODUCT_CSS, $value );
        $this->view->closeFieldset();
    }

    public function section_default_values() {
        $this->view->settingsInlineStyle();
        $this->view->printFieldDescription( __( 'You can define CSS that is pre-filled when a new post, page, or product is created. Note: this default CSS is not validated — invalid CSS is stored as entered.', 'postpage-specific-custom-css' ) );
    }

    public function section_plugin_behavior() {}

    /**
     * Sanitize plugin settings before they are stored.
     *
     * @param mixed $input Raw option value from Settings API (already unslashed).
     */
    public function sanitize_settings( $input ): array {
        $input = is_array( $input ) ? $input : [];

        return [
            self::OPT_CONTROL_USER_EDITOR => ! empty( $input[ self::OPT_CONTROL_USER_EDITOR ] ) ? 1 : 0,
            self::OPT_BIGGER_TEXTAREA     => ! empty( $input[ self::OPT_BIGGER_TEXTAREA ] ) ? 1 : 0,
            self::OPT_DEFAULT_POST_CSS    => $this->sanitize_css_for_style_tag( self::stringify( $input[ self::OPT_DEFAULT_POST_CSS ] ?? '' ) ),
            self::OPT_DEFAULT_PAGE_CSS    => $this->sanitize_css_for_style_tag( self::stringify( $input[ self::OPT_DEFAULT_PAGE_CSS ] ?? '' ) ),
            self::OPT_DEFAULT_PRODUCT_CSS => $this->sanitize_css_for_style_tag( self::stringify( $input[ self::OPT_DEFAULT_PRODUCT_CSS ] ?? '' ) ),
        ];
    }

    /**
     * Make CSS safe to embed inside a <style> element (prevent markup breakout).
     *
     * Does not use strip_tags(): legitimate CSS may contain angle-bracket text
     * (e.g. content: "<div>";) which strip_tags would corrupt.
     *
     * Only the raw closing sequence </style is neutralized, by escaping it to
     * <\/style. HTML will not treat that as an end tag; CSS reads \/ as a slash.
     * Escaping (not stripping) is idempotent: nested fragments cannot recombine
     * into a fresh </style across repeated sanitize passes (Stored XSS).
     */
    public function sanitize_css_for_style_tag( string $css ): string {
        $checked = wp_check_invalid_utf8( $css );
        $css     = is_string( $checked ) ? $checked : '';
        $css     = str_replace( "\0", '', $css );

        $css = preg_replace_callback(
            '#</style#i',
            static function ( array $match ): string {
                return '<\\/' . substr( $match[0], 2 );
            },
            $css
        );

        return trim( is_string( $css ) ? $css : '' );
    }

    public function page_settings_link_filter( array $links ): array {
        $notes_url = wp_nonce_url(
            add_query_arg( self::QUERY_RELEASE_NOTES, '1', self_admin_url( 'plugins.php' ) ),
            self::NONCE_RELEASE_NOTES
        );
        $links['ppsccss_release_notes'] = '<a href="' . esc_url( $notes_url ) . '">' . esc_html__( 'Release notes', 'postpage-specific-custom-css' ) . '</a>';
        array_unshift( $links, '<a href="' . esc_url( $this->build_settings_link() ) . '">' . esc_html__( 'Settings', 'postpage-specific-custom-css' ) . '</a>' );

        return $links;
    }

    /**
     * Show release notes once for each administrator, or when opened from the plugins list.
     */
    public function maybe_show_release_notes(): void {
        if ( ! current_user_can( self::CAP_MANAGE_OPTIONS ) ) {
            return;
        }

        $forced = $this->is_release_notes_request();
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) {
            return;
        }

        $seen = (string) get_user_meta( $user_id, self::USER_META_RELEASE_NOTES_SEEN, true );
        if ( ! $forced && '' !== $seen ) {
            return;
        }

        $this->render_release_notes_notice( $forced );

        if ( ! $forced ) {
            update_user_meta( $user_id, self::USER_META_RELEASE_NOTES_SEEN, '1' );
        }
    }

    private function is_release_notes_request(): bool {
        if ( ! isset( $_GET[ self::QUERY_RELEASE_NOTES ] ) ) {
            return false;
        }
        if ( '1' !== self::stringify( wp_unslash( $_GET[ self::QUERY_RELEASE_NOTES ] ) ) ) {
            return false;
        }
        $nonce = self::stringify( wp_unslash( $_GET['_wpnonce'] ?? '' ) );
        if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_RELEASE_NOTES ) ) {
            return false;
        }

        return true;
    }

    private function render_release_notes_notice( bool $forced ): void {
        $donate_url = self::DONATE_URL;
        ?>
        <div class="notice notice-info<?php echo $forced ? '' : ' is-dismissible'; ?>">
            <p><strong><?php echo esc_html__( 'Post/Page Specific Custom Code — what\'s new', 'postpage-specific-custom-css' ); ?></strong></p>
            <p><?php echo esc_html__( 'Thanks for using the plugin! Here is a quick tour of the latest improvements:', 'postpage-specific-custom-css' ); ?></p>
            <ul style="list-style:disc;margin-left:1.5em;">
                <li><?php echo esc_html__( 'A fresh name and focus: Post/Page Specific Custom Code — built for the CSS you attach to individual content.', 'postpage-specific-custom-css' ); ?></li>
                <li><?php echo esc_html__( 'Full WooCommerce product support, including Shop Manager access and CSS on shop and category archives.', 'postpage-specific-custom-css' ); ?></li>
                <li><?php echo esc_html__( 'Smoother front-end delivery: styles for the main query load in the page head; CSS for products rendered in secondary WooCommerce loops may appear in the footer.', 'postpage-specific-custom-css' ); ?></li>
                <li><?php echo esc_html__( 'A more reliable editor experience: local CSS validation, safer storage, and fixes that keep your “single view only” setting intact.', 'postpage-specific-custom-css' ); ?></li>
            </ul>
            <p>
                <?php echo esc_html__( 'If this plugin saves you time, a small donation helps keep development going. Thank you!', 'postpage-specific-custom-css' ); ?>
                <a class="button button-secondary" style="margin-left:0.5em;" href="<?php echo esc_url( $donate_url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html__( 'Donate via PayPal', 'postpage-specific-custom-css' ); ?></a>
            </p>
            <?php if ( $forced ) : ?>
                <p class="description"><?php echo esc_html__( 'You opened these notes from the plugins list. You can reopen them anytime with the “Release notes” link.', 'postpage-specific-custom-css' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function build_settings_link(): string {
        return admin_url( self::PARENT_MENU_SLUG . '?page=' . self::MENU_SLUG );
    }

    public function add_options_page() {
        $sub_menu_suffix = add_submenu_page( self::PARENT_MENU_SLUG, __( 'Post/Page Specific Custom Code', 'postpage-specific-custom-css' ), __( 'Post/Page CSS', 'postpage-specific-custom-css' ), self::CAP_MANAGE_OPTIONS, self::MENU_SLUG, [
            $this,
            'options_page_view',
        ] );
        if ( is_string( $sub_menu_suffix ) && '' !== $sub_menu_suffix ) {
            add_action( 'load-' . $sub_menu_suffix, [
                $this,
                'options_admin_enqueue_scripts',
            ] );
        }
    }

    public function options_page_view() {
        ?>
        <div class="wrap">
            <h1><?php
                echo esc_html__( 'Post/Page Specific Custom Code', 'postpage-specific-custom-css' ); ?></h1>
            <form action="options.php" method="POST">
                <?php
                settings_fields( self::OPTION_GROUP ); ?>
                <div>
                    <?php
                    do_settings_sections( self::MENU_SLUG ); ?>
                </div>
                <?php
                submit_button(); ?>
            </form>
        </div>
        <?php if ( $this->is_code_editor_available() ) : ?>
        <script>
            jQuery(function ($) {
                if (!window.wp?.codeEditor) {
                    return;
                }
                const defaultPageCSS = $('#defaultPageCSS');
                const defaultPostCSS = $('#defaultPostCSS');
                const defaultProductCSS = $('#defaultProductCSS');
                let editorSettings;
                if (defaultPageCSS.length === 1) {
                    editorSettings = wp.codeEditor.defaultSettings ? _.clone(wp.codeEditor.defaultSettings) : {};
                    editorSettings.codemirror = _.extend({}, editorSettings.codemirror, {
                        indentUnit: 2, tabSize: 2, mode: 'css', lint: false,
                    });
                    wp.codeEditor.initialize(defaultPageCSS, editorSettings);
                }
                if (defaultPostCSS.length === 1) {
                    editorSettings = wp.codeEditor.defaultSettings ? _.clone(wp.codeEditor.defaultSettings) : {};
                    editorSettings.codemirror = _.extend({}, editorSettings.codemirror, {
                        indentUnit: 2, tabSize: 2, mode: 'css', lint: false,
                    });
                    wp.codeEditor.initialize(defaultPostCSS, editorSettings);
                }
                if (defaultProductCSS.length === 1) {
                    editorSettings = wp.codeEditor.defaultSettings ? _.clone(wp.codeEditor.defaultSettings) : {};
                    editorSettings.codemirror = _.extend({}, editorSettings.codemirror, {
                        indentUnit: 2, tabSize: 2, mode: 'css', lint: false,
                    });
                    wp.codeEditor.initialize(defaultProductCSS, editorSettings);
                }
            });
        </script>
        <?php endif; ?>
        <?php
    }

    /**
     * Print CSS known at head time (singular view, main query, shop, posts page).
     */
    public function print_front_css(): void {
        $this->emit_front_style( $this->collect_front_css(), 'phylax-ppsccss-front' );
    }

    /**
     * Register a product when WooCommerce generates its rendered HTML classes.
     *
     * @param array<int, string> $classes Product CSS classes.
     * @param mixed              $product WC_Product instance (or compatible object).
     *
     * @return array<int, string>
     */
    public function capture_rendered_product( $classes, $product ) {
        if ( ! is_array( $classes ) ) {
            $classes = [];
        }
        $this->register_product_for_deferred_css( $product );

        return $classes;
    }

    /**
     * Register a product whose CSS should be printed later (footer) if not already in head.
     *
     * Accepts a product ID, WP_Post, or WC_Product. Variations resolve to the parent product.
     *
     * @param mixed $product Product ID, WP_Post, or WC_Product.
     */
    public function register_product_for_deferred_css( $product ): void {
        $product_id = $this->resolve_product_id_for_css( $product );
        if ( $product_id <= 0 ) {
            return;
        }

        $this->seen_product_ids[ $product_id ] = true;
    }

    /**
     * Resolve a product identifier suitable for loading product CSS meta.
     *
     * @param mixed $product Product ID, WP_Post, or WC_Product.
     */
    private function resolve_product_id_for_css( $product ): int {
        $product_id = 0;

        if ( is_numeric( $product ) ) {
            $product_id = absint( $product );
        } elseif ( $product instanceof WP_Post ) {
            $product_id = absint( $product->ID );
        } elseif ( is_object( $product ) && is_a( $product, 'WC_Product' ) ) {
            if ( method_exists( $product, 'get_parent_id' ) ) {
                $parent_id = absint( $product->get_parent_id() );
                if ( $parent_id > 0 ) {
                    return $parent_id;
                }
            }
            $product_id = absint( $product->get_id() );
        }

        if ( $product_id <= 0 ) {
            return 0;
        }

        $post_type = get_post_type( $product_id );
        if ( 'product' === $post_type ) {
            return $product_id;
        }
        if ( 'product_variation' === $post_type ) {
            $parent_id = absint( wp_get_post_parent_id( $product_id ) );

            return ( $parent_id > 0 ) ? $parent_id : 0;
        }

        return 0;
    }

    /**
     * Print product CSS discovered after wp_head (e.g. [products], related products, custom loops).
     *
     * Detection is best-effort for standard WooCommerce loop templates. Custom HTML that never
     * calls wc_product_class() needs do_action( 'postpage_sccss_register_product', $product ).
     * AJAX / infinite-scroll responses are out of scope.
     */
    public function print_deferred_product_css(): void {
        if ( $this->deferred_css_printed ) {
            return;
        }
        $this->deferred_css_printed = true;

        $product_ids = apply_filters(
            self::FILTER_DEFERRED_PRODUCT_IDS,
            array_keys( $this->seen_product_ids )
        );
        $product_ids = array_values(
            array_unique(
                array_filter(
                    array_map( 'absint', (array) $product_ids )
                )
            )
        );

        $chunks = [];
        foreach ( $product_ids as $product_id ) {
            if ( isset( $this->front_css_printed_ids[ $product_id ] ) ) {
                continue;
            }
            $product = get_post( $product_id );
            if ( ! $product instanceof WP_Post || 'product' !== $product->post_type ) {
                continue;
            }
            // Grid / shortcode context: honour "single view only".
            $css = $this->take_applicable_css_for_post( $product, false );
            if ( '' !== $css ) {
                $chunks[] = $css;
            }
        }
        $this->emit_front_style( $chunks, 'phylax-ppsccss-deferred' );
    }

    /**
     * @param string[] $chunks   Sanitized CSS chunks.
     * @param string   $style_id Element id for the <style> tag.
     */
    private function emit_front_style( array $chunks, string $style_id ): void {
        if ( [] === $chunks ) {
            return;
        }
        $css = $this->sanitize_css_for_style_tag( implode( "\n", $chunks ) );
        if ( '' === $css ) {
            return;
        }
        echo '<!-- ' . esc_html__( 'Added by Post/Page Specific Custom Code. Thank you for using it!', 'postpage-specific-custom-css' ) . ' -->' . "\n";
        echo '<style id="' . esc_attr( $style_id ) . '">' . "\n" . $css . "\n" . '</style>' . "\n";
    }

    /**
     * @return string[] Sanitized CSS chunks for the current request.
     */
    private function collect_front_css(): array {
        // Shop / product taxonomies: main query holds products, but the shop page itself can
        // still look "singular". Aggregate product CSS from the listing; on the shop also
        // include CSS assigned to the Shop page itself.
        if ( $this->is_product_listing_context() ) {
            $chunks = [];
            if ( function_exists( 'is_shop' ) && is_shop() ) {
                $shop_css = $this->get_shop_page_css();
                if ( '' !== $shop_css ) {
                    $chunks[] = $shop_css;
                }
            }

            return array_merge( $chunks, $this->collect_css_from_main_query( false, [ 'product' ] ) );
        }

        // Settings → Reading → Posts page: is_home() is true, not is_singular( 'page' ),
        // and the page is not in $wp_query->posts (those are blog posts).
        if ( is_home() && ! is_front_page() ) {
            $chunks = [];
            $posts_page_css = $this->get_posts_page_css();
            if ( '' !== $posts_page_css ) {
                $chunks[] = $posts_page_css;
            }

            return array_merge( $chunks, $this->collect_css_from_main_query( false, self::SUPPORTED_POST_TYPES ) );
        }

        if ( is_singular( self::SUPPORTED_POST_TYPES ) ) {
            $post = get_queried_object();
            if ( ! $post instanceof WP_Post ) {
                return [];
            }
            $css = $this->take_applicable_css_for_post( $post, true );

            return ( '' === $css ) ? [] : [ $css ];
        }

        return $this->collect_css_from_main_query( false, self::SUPPORTED_POST_TYPES );
    }

    /**
     * CSS assigned to the WooCommerce Shop page (page ID from wc_get_page_id( 'shop' )).
     */
    private function get_shop_page_css(): string {
        if ( ! function_exists( 'wc_get_page_id' ) ) {
            return '';
        }
        $shop_page_id = (int) wc_get_page_id( 'shop' );
        if ( $shop_page_id <= 0 ) {
            return '';
        }
        $shop_page = get_post( $shop_page_id );
        if ( ! $shop_page instanceof WP_Post ) {
            return '';
        }

        // The shop front is the Shop page's primary view — treat as singular context.
        return $this->take_applicable_css_for_post( $shop_page, true );
    }

    /**
     * CSS assigned to the page set as Settings → Reading → Posts page.
     */
    private function get_posts_page_css(): string {
        $page_id = (int) get_option( 'page_for_posts' );
        if ( $page_id <= 0 ) {
            return '';
        }
        $page = get_post( $page_id );
        if ( ! $page instanceof WP_Post || 'page' !== $page->post_type ) {
            return '';
        }

        // The blog index is this page's primary public view — treat as singular context.
        return $this->take_applicable_css_for_post( $page, true );
    }

    /**
     * Whether the current request is a WooCommerce product listing (shop or product taxonomy).
     */
    private function is_product_listing_context(): bool {
        if ( ! function_exists( 'is_shop' ) ) {
            return false;
        }

        return is_shop()
               || ( function_exists( 'is_product_taxonomy' ) && is_product_taxonomy() )
               || ( function_exists( 'is_product_category' ) && is_product_category() )
               || ( function_exists( 'is_product_tag' ) && is_product_tag() );
    }

    /**
     * Collect CSS from posts in the main query (archives, shop, home, search, etc.).
     *
     * @param bool     $is_singular_context Passed through to applicability rules.
     * @param string[] $post_types          Allowed post types.
     *
     * @return string[]
     */
    private function collect_css_from_main_query( bool $is_singular_context, array $post_types ): array {
        global $wp_query;
        if ( ! $wp_query instanceof WP_Query || empty( $wp_query->posts ) ) {
            return [];
        }

        $chunks = [];
        foreach ( $wp_query->posts as $post ) {
            if ( ! $post instanceof WP_Post ) {
                continue;
            }
            if ( ! in_array( $post->post_type, $post_types, true ) ) {
                continue;
            }
            $css = $this->take_applicable_css_for_post( $post, $is_singular_context );
            if ( '' !== $css ) {
                $chunks[] = $css;
            }
        }

        return $chunks;
    }

    /**
     * Like get_applicable_css_for_post(), but skips IDs already emitted on this request.
     *
     * @param bool $is_singular_context When true, CSS is allowed regardless of the "single only" flag.
     */
    private function take_applicable_css_for_post( WP_Post $post, bool $is_singular_context ): string {
        if ( isset( $this->front_css_printed_ids[ $post->ID ] ) ) {
            return '';
        }
        $css = $this->get_applicable_css_for_post( $post, $is_singular_context );
        if ( '' !== $css ) {
            $this->front_css_printed_ids[ $post->ID ] = true;
        }

        return $css;
    }

    /**
     * @param bool $is_singular_context When true, CSS is allowed regardless of the "single only" flag.
     */
    private function get_applicable_css_for_post( WP_Post $post, bool $is_singular_context ): string {
        $css = self::stringify( get_post_meta( $post->ID, self::POST_META_CSS, true ) );
        if ( '' === $css ) {
            return '';
        }
        if ( ! $is_singular_context ) {
            $single_only = (int) self::stringify( get_post_meta( $post->ID, self::POST_META_SINGLE, true ) );
            // Archive/list views: only when not limited to single view (empty/legacy => 0).
            if ( 1 === $single_only ) {
                return '';
            }
        }

        return $this->sanitize_css_for_style_tag( $css );
    }

    /**
     * @param string       $post_type Post type.
     * @param WP_Post|null $post      Post object.
     */
    public function add_meta_boxes( $post_type, $post = null ): void {
        if ( ! is_string( $post_type ) || ! in_array( $post_type, self::SUPPORTED_POST_TYPES, true ) ) {
            return;
        }
        if ( ! $this->user_can_edit_css( $post ) ) {
            return;
        }
        add_meta_box( 'phylax_ppsccss', __( 'Custom Code', 'postpage-specific-custom-css' ), [
            $this,
            'render_post_page_edit_view',
        ],          $post_type, 'advanced', 'high' );
    }

    /**
     * Whether the current user may edit plugin CSS for the given post.
     *
     * - Administrators (manage_options): all supported types
     * - Products: users who can edit the product and have edit_products (e.g. Shop Manager)
     * - Posts/pages: Editors only when the dedicated option is enabled
     */
    public function user_can_edit_css( $post ): bool {
        if ( ! $post instanceof WP_Post ) {
            return false;
        }
        if ( ! in_array( $post->post_type, self::SUPPORTED_POST_TYPES, true ) ) {
            return false;
        }
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            return false;
        }
        if ( current_user_can( self::CAP_MANAGE_OPTIONS ) ) {
            return true;
        }
        if ( 'product' === $post->post_type ) {
            return current_user_can( self::CAP_EDIT_PRODUCTS );
        }
        $settings      = self::get_plugin_settings();
        $allow_editors = ! empty( $settings[ self::OPT_CONTROL_USER_EDITOR ] );
        if ( ! $allow_editors ) {
            return false;
        }

        return current_user_can( self::CAP_EDIT_OTHERS_PAGES );
    }

    /**
     * @param int $post_id Post ID.
     */
    public function save_post( $post_id ): void {
        $post_id = absint( $post_id );
        if ( 0 === $post_id ) {
            return;
        }
        $nonce_value = self::stringify( wp_unslash( $_POST['phylax_ppsccss_nonce'] ?? '' ) );
        if ( '' === $nonce_value ) {
            return;
        }
        if ( ! wp_verify_nonce( $nonce_value, 'phylax_ppsccss' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post ) {
            return;
        }
        if ( ! in_array( $post->post_type, self::SUPPORTED_POST_TYPES, true ) ) {
            return;
        }
        if ( ! $this->user_can_edit_css( $post ) ) {
            return;
        }
        $phylax_ppsccss_css         = $this->sanitize_css_for_style_tag( wp_unslash( self::stringify( $_POST['phylax_ppsccss_css'] ?? '' ) ) );
        $phylax_ppsccss_single_only = (int) ( $_POST['phylax_ppsccss_single_only'] ?? 0 );
        if ( ( $phylax_ppsccss_single_only < 0 ) || ( $phylax_ppsccss_single_only > 1 ) ) {
            $phylax_ppsccss_single_only = 0;
        }
        update_post_meta( $post_id, self::POST_META_CSS, $phylax_ppsccss_css );
        update_post_meta( $post_id, self::POST_META_SINGLE, $phylax_ppsccss_single_only );
        // Legacy client-side "valid" flag — no longer used; drop on save.
        delete_post_meta( $post_id, '_phylax_ppsccss_valid' );
    }

    /**
     * Whether the current edit screen is for creating a new post (not editing an existing one).
     */
    private function is_new_post_edit_screen( WP_Post $post ): bool {
        if ( 'auto-draft' === $post->post_status ) {
            return true;
        }
        $screen = get_current_screen();

        return $screen instanceof WP_Screen && 'add' === $screen->action;
    }

    /**
     * @param WP_Post $post Post being edited.
     */
    public function render_post_page_edit_view( $post ): void {
        if ( ! $post instanceof WP_Post ) {
            return;
        }
        wp_nonce_field( 'phylax_ppsccss', 'phylax_ppsccss_nonce' );
        $screen   = '';
        $body_key = '';
        $settings = self::get_plugin_settings();
        switch ( $post->post_type ) {
            case 'product':
                $screen   = __( 'Custom CSS for this product', 'postpage-specific-custom-css' );
                $body_key = self::OPT_DEFAULT_PRODUCT_CSS;
                break;
            case 'post':
                $screen   = __( 'Custom CSS for this post', 'postpage-specific-custom-css' );
                $body_key = self::OPT_DEFAULT_POST_CSS;
                break;
            case 'page':
                $screen   = __( 'Custom CSS for this page', 'postpage-specific-custom-css' );
                $body_key = self::OPT_DEFAULT_PAGE_CSS;
                break;
            default:
                return;
        }
        // Default CSS only for genuinely new posts (post-new / auto-draft).
        // Missing plugin meta on an old post must not pre-fill defaults — saving would
        // otherwise attach default CSS to content that never used this plugin.
        if ( ! metadata_exists( 'post', $post->ID, self::POST_META_CSS ) && $this->is_new_post_edit_screen( $post ) ) {
            $phylax_ppsccss_css         = self::stringify( $settings[ $body_key ] ?? '' );
            $phylax_ppsccss_single_only = 0;
        } else {
            $phylax_ppsccss_css         = self::stringify( get_post_meta( $post->ID, self::POST_META_CSS, true ) );
            $phylax_ppsccss_single_only = (int) self::stringify( get_post_meta( $post->ID, self::POST_META_SINGLE, true ) );
        }
        if ( 0 !== $phylax_ppsccss_single_only && 1 !== $phylax_ppsccss_single_only ) {
            $phylax_ppsccss_single_only = 0;
        }
        $biggerBox = (int) ( $settings[ self::OPT_BIGGER_TEXTAREA ] ?? 0 );
        ?>
        <p class="post-attributes-label-wrapper"><label for="phylax_ppsccss_css"><?php
                echo esc_html( $screen ); ?></label></p>
        <div id="phylax_ppsccss_css_outer">
            <textarea name="phylax_ppsccss_css" id="phylax_ppsccss_css"
                      class="widefat textarea"
                      rows="<?php
                      echo( ( 0 === $biggerBox ) ? '10' : '25' ) ?>"><?php
                echo esc_textarea( self::stringify( $phylax_ppsccss_css ) ); ?></textarea>
        </div>
        <p class="post-attributes-label-wrapper">
            <label for="phylax_ppsccss_single_only"><input type="hidden" name="phylax_ppsccss_single_only"
                                                           value="0"><input type="checkbox"
                                                                            name="phylax_ppsccss_single_only" value="1"
                                                                            id="phylax_ppsccss_single_only"<?php
                checked( $phylax_ppsccss_single_only, 1 ); ?>> <?php
                echo esc_html__( 'Load this CSS only on the single view', 'postpage-specific-custom-css' ); ?>
            </label>
        </p>
        <?php
        $this->view->printFieldDescription( __( 'Enter valid CSS only. It is printed inside a style element. CSS known during the main request is printed in the page head; CSS discovered in secondary WooCommerce loops may be printed in the footer. The editor can highlight syntax problems when it detects them.', 'postpage-specific-custom-css' ) );
        $highlight_code = $this->is_code_editor_available();
        ?>
        <script>
            jQuery(function ($) {
                const phylaxCSSEditorDOM = $('#phylax_ppsccss_css');
                const highlightCode = <?php echo $highlight_code ? 'true' : 'false'; ?>;
                let phylaxCSSEditorInstance = null;
                let throttleTimer = null;
                /** @type {Array<{ clear: () => void }>} CodeMirror text marks created by this plugin only. */
                let phylaxErrorMarks = [];
                if (phylaxCSSEditorDOM.length !== 1) {
                    return;
                }

                if (highlightCode && window.wp?.codeEditor) {
                    let phylaxCSSEditorSettings = wp.codeEditor.defaultSettings ? _.clone(wp.codeEditor.defaultSettings) : {};
                    phylaxCSSEditorSettings.codemirror = _.extend({}, phylaxCSSEditorSettings.codemirror, {
                        indentUnit: 2, tabSize: 2, mode: 'css', lint: false,
                    });
                    phylaxCSSEditorInstance = wp.codeEditor.initialize(phylaxCSSEditorDOM, phylaxCSSEditorSettings);
                }

                function getCssValue() {
                    if (phylaxCSSEditorInstance?.codemirror) {
                        return phylaxCSSEditorInstance.codemirror.getValue();
                    }
                    return phylaxCSSEditorDOM.val() || '';
                }

                function clearErrorUi() {
                    // Clear only marks this plugin created — never getAllMarks() / global .css-error.
                    phylaxErrorMarks.forEach(mark => {
                        try {
                            mark.clear();
                        } catch (e) {
                        }
                    });
                    phylaxErrorMarks = [];
                    const existingErrors = document.getElementById('phylax_ppsccss_css_errors');
                    if (existingErrors) {
                        existingErrors.parentNode.removeChild(existingErrors);
                    }
                }

                function validateCSSInEditor() {
                    const css = getCssValue();
                    const outer = document.getElementById('phylax_ppsccss_css_outer');
                    if (!outer) return;
                    clearErrorUi();
                    if (typeof csstree === 'undefined') {
                        return;
                    }

                    const cm = phylaxCSSEditorInstance?.codemirror || null;

                    function reportError(line, column, message) {
                        const safeLine = Math.max(1, line || 1);
                        const safeCol = Math.max(1, column || 1);
                        if (cm) {
                            const lineContent = cm.getLine(safeLine - 1) || '';
                            const toCh = Math.min(safeCol + 5, lineContent.length);
                            const mark = cm.markText(
                                {line: safeLine - 1, ch: safeCol - 1},
                                {line: safeLine - 1, ch: toCh},
                                {
                                    className: 'phylax-ppsccss-css-error',
                                    title: message
                                }
                            );
                            if (mark) {
                                phylaxErrorMarks.push(mark);
                            }
                        }
                        const li = document.createElement('li');
                        li.textContent = `Line ${safeLine}, Col ${safeCol}: ${message}`;
                        li.style.cursor = 'pointer';
                        li.onclick = () => {
                            if (cm) {
                                cm.focus();
                                cm.setCursor({line: safeLine - 1, ch: safeCol - 1});
                                cm.scrollIntoView({line: safeLine - 1, ch: 0}, 100);
                            } else {
                                phylaxCSSEditorDOM.trigger('focus');
                            }
                        };
                        ul.appendChild(li);
                    }

                    const errorDiv = document.createElement('div');
                    errorDiv.id = 'phylax_ppsccss_css_errors';
                    errorDiv.style.margin = '1rem 0 .5rem 0';
                    errorDiv.style.border = '1px solid #a02020';
                    errorDiv.style.padding = '6px 0 0 0';
                    const ul = document.createElement('ul');
                    ul.style.paddingLeft = '1.5rem';
                    ul.style.margin = '0';
                    errorDiv.appendChild(ul);
                    let hasErrors = false;
                    try {
                        // Raw nodes are normal (e.g. url()); real syntax issues go through onParseError.
                        csstree.parse(css, {
                            positions: true,
                            context: 'stylesheet',
                            onParseError: function (err) {
                                hasErrors = true;
                                const line = err.line || 1;
                                const column = err.column || 1;
                                reportError(line, column, err.message || 'Parse error');
                            }
                        });
                    } catch (err) {
                        hasErrors = true;
                        const match = /Line (\d+),? col(?:umn)? (\d+)/i.exec(err.message || '');
                        let line = 1, column = 1;
                        if (match) {
                            line = parseInt(match[1], 10);
                            column = parseInt(match[2], 10);
                        }
                        reportError(line, column, err.message || 'Parse error');
                    }
                    if (hasErrors) {
                        outer.parentNode.insertBefore(errorDiv, outer);
                    }
                }

                function syncTextareaFromCm() {
                    if (!phylaxCSSEditorInstance?.codemirror) {
                        return;
                    }
                    // Use .val() — .html() parses angle brackets and corrupts CSS like content:"<div>".
                    phylaxCSSEditorDOM.val(phylaxCSSEditorInstance.codemirror.getValue());
                }

                function markEditorDirty() {
                    phylaxCSSEditorDOM.data('changed', true);
                    // Notify other listeners that the underlying textarea changed (e.g. after CM sync).
                    // Do not bind this same handler to textarea "change" — that would recurse endlessly.
                    phylaxCSSEditorDOM.trigger('change');
                    if (window.wp?.data?.dispatch) {
                        try {
                            window.wp.data.dispatch('core/editor').editPost({meta: {}});
                        } catch (e) {
                        }
                    } else if (window.wp?.autosave?.local) {
                        try {
                            window.wp.autosave.local.changed = true;
                        } catch (e) {
                        }
                    }
                }

                function scheduleValidation() {
                    clearTimeout(throttleTimer);
                    throttleTimer = setTimeout(validateCSSInEditor, 450);
                }

                function flushForSave() {
                    clearTimeout(throttleTimer);
                    syncTextareaFromCm();
                    validateCSSInEditor();
                }

                function onEditorChange() {
                    syncTextareaFromCm();
                    markEditorDirty();
                    scheduleValidation();
                }

                if (phylaxCSSEditorInstance?.codemirror) {
                    phylaxCSSEditorInstance.codemirror.on('change', onEditorChange);
                } else {
                    // Plain textarea: listen to input only. Binding "change" would recurse because
                    // markEditorDirty() calls trigger('change') (Maximum call stack size exceeded).
                    phylaxCSSEditorDOM.on('input', onEditorChange);
                }

                setTimeout(validateCSSInEditor, 100);

                // Classic editor form submit.
                $('#post').on('submit', flushForSave);

                // Flush before save UI actions (classic + block editor), early enough for metabox serialization.
                $(document).on(
                    'mousedown',
                    '#publish, #save-post, .editor-post-publish-button, .editor-post-publish-button__button, .editor-post-save-draft, .editor-post-publish-panel__toggle, .editor-post-publish-panel__publish-button',
                    flushForSave
                );

                // Ctrl/Cmd+S
                $(document).on('keydown', function (e) {
                    if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S')) {
                        flushForSave();
                    }
                });

                // Block editor: when a manual save starts, ensure textarea is synced.
                if (window.wp?.data?.subscribe && window.wp?.data?.select) {
                    let wasSaving = false;
                    window.wp.data.subscribe(function () {
                        const editorSelect = window.wp.data.select('core/editor');
                        if (!editorSelect || typeof editorSelect.isSavingPost !== 'function') {
                            return;
                        }
                        const isSaving = !!editorSelect.isSavingPost();
                        const isAutosaving = typeof editorSelect.isAutosavingPost === 'function' && editorSelect.isAutosavingPost();
                        if (isSaving && !wasSaving && !isAutosaving) {
                            flushForSave();
                        }
                        wasSaving = isSaving;
                    });
                }
            });
        </script>
        <?php
        $this->view->settingsInlineStyle();
        if ( $highlight_code && 1 === $biggerBox ) :
            ?>
            <style>
                #phylax_ppsccss_css_outer .CodeMirror {
                    height: 600px;
                }</style><?php
        endif;
    }
}

new Plugin();
