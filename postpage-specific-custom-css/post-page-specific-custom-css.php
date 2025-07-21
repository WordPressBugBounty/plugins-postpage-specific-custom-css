<?php
/**
 * Plugin Name: Post/Page Specific Custom Code
 * Plugin URI: https://wordpress.org/plugins/postpage-specific-custom-css/
 * Description: Post/Page Specific Custom Code allows you to add cascading stylesheets to individual posts, pages, and WooCommerce products. It provides a dedicated area in the edit screen where you can attach your CSS. You can also choose whether the CSS should be applied only on single views or also on multi-item views (like archive pages).
 * Version: 0.3.0
 * Author: Łukasz Nowicki
 * Author URI: https://lukasznowicki.info/
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Tested up to: 6.8
 * Text Domain: postpage-specific-custom-css
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

namespace Phylax\WPPlugin\PPCustomCSS;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use WP_Post;
use const DOING_AUTOSAVE;

defined( 'ABSPATH' ) or exit;

add_action( 'admin_notices', function () {
    if ( ! is_user_logged_in() ) {
        return;
    }
    $user         = wp_get_current_user();
    $meta_key     = 'phylax_pp_scc_plugin_name_change_notice_dismissed';
    $already_seen = get_user_meta( $user->ID, $meta_key, true );
    if ( ! $already_seen ) {
        echo '<div class="notice notice-info is-dismissible">';
        echo '<p><strong>Heads up!</strong> The plugin <em>Post/Page specific custom CSS</em> has been renamed to <em>Post/Page Specific Custom Code</em>. In this update, we’ve also added full support for WooCommerce products – you can now apply custom code directly to them.</p>';
        echo '</div>';
        update_user_meta( $user->ID, $meta_key, '1' );
    }
} );

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
    const POST_META_VALID  = '_phylax_ppsccss_valid';

    const STYLE_LIVE_CHECKER_FIELD = '_phylax_pp_sc_css_live_checker_field';

    const CAP_MANAGE_OPTIONS    = 'manage_options';
    const CAP_EDIT_OTHERS_PAGES = 'edit_others_pages';

    private ViewHelpers $view;

    public function __construct() {
        $this->view = new ViewHelpers( (array) get_option( self::OPTION_NAME ), self::OPTION_NAME );
        add_filter( 'the_content', [
            $this,
            'the_content',
        ],          999 );
        if ( is_admin() ) {
            $this->startInAdmin();
        }
    }

    public function startInAdmin() {
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [
            $this,
            'page_settings_link_filter',
        ] );
        add_action( 'add_meta_boxes', [
            $this,
            'add_meta_boxes',
        ] );
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

    public function options_admin_enqueue_scripts() {
        wp_enqueue_script( 'css-tree', 'https://unpkg.com/css-tree/dist/csstree.js', [], null, true );
        wp_enqueue_code_editor( [ 'type' => 'text/css' ] );
    }

    public function admin_enqueue_scripts() {
        $screen = get_current_screen();
        if ( false === is_a( $screen, 'WP_Screen' ) ) {
            return;
        }
        if ( 'post' !== $screen->base ) {
            return;
        }
        wp_enqueue_script( 'css-tree', 'https://unpkg.com/css-tree/dist/csstree.js', [], null, true );
        wp_enqueue_code_editor( [
                                    'type'       => 'text/javascript',
                                    'codemirror' => [
                                        'autoRefresh' => true,
                                    ],
                                ] );
    }

    public function register_settings() {
        register_setting( self::OPTION_GROUP, self::OPTION_NAME );
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
        add_settings_field( 'default_post_css', __( 'Default stylesheet for new Posts', 'postpage-specific-custom-css' ), [
            $this,
            'default_post_css',
        ],                  self::MENU_SLUG, 'default-values' );
        add_settings_field( 'default_page_css', __( 'Default stylesheet for new Pages', 'postpage-specific-custom-css' ), [
            $this,
            'default_page_css',
        ],                  self::MENU_SLUG, 'default-values' );
        add_settings_field( 'default_product_css', __( 'Default stylesheet for new WooCommerce Products', 'postpage-specific-custom-css' ), [
            $this,
            'default_product_css',
        ],                  self::MENU_SLUG, 'default-values' );
        add_settings_field( 'bigger_textarea', __( 'Bigger input field', 'postpage-specific-custom-css' ), [
            $this,
            'bigger_textarea',
        ],                  self::MENU_SLUG, 'plugin-behavior' );
    }

    public function bigger_textarea() {
        $this->view->openFieldset( self::OPT_BIGGER_TEXTAREA );
        $this->view->screenReaderLegend( __( 'Make input boxes bigger', 'postpage-specific-custom-css' ) );
        $this->view->checkBoxField( self::OPT_BIGGER_TEXTAREA, __( 'Make input boxes on Posts and Pages bigger', 'postpage-specific-custom-css' ) );
        $this->view->closeFieldset();
    }

    public function control_user_editor() {
        $this->view->openFieldset( 'plugin_behavior' );
        $this->view->screenReaderLegend( __( 'Allow Editors to edit CSS code.', 'postpage-specific-custom-css' ) );
        $this->view->checkBoxField( self::OPT_CONTROL_USER_EDITOR, __( 'Allow Editors to edit CSS code for posts and pages.', 'postpage-specific-custom-css' ) );
        $this->view->closeFieldset();
        $this->view->printFieldDescription( __( 'Please note, that allowing Editors to edit CSS code, does not mean that Editors will be able to change settings and/or default values for the plugin. And please be careful. Allowing editors to edit your CSS may crash your site layout if in wrong hands.', 'postpage-specific-custom-css' ) );
    }

    public function default_post_css() {
        $settings = (array) get_option( self::OPTION_NAME );
        $value    = wp_unslash( $settings[ self::OPT_DEFAULT_POST_CSS ] ?? '' );
        $this->view->openFieldset( self::OPT_DEFAULT_POST_CSS );
        $this->view->screenReaderLegend( __( 'Default stylesheet for new posts', 'postpage-specific-custom-css' ) );
        $this->view->textAreaField( 'defaultPostCSS', self::OPT_DEFAULT_POST_CSS, $value );
        $this->view->closeFieldset();
    }

    public function default_page_css() {
        $settings = (array) get_option( self::OPTION_NAME );
        $value    = wp_unslash( $settings[ self::OPT_DEFAULT_PAGE_CSS ] ?? '' );
        $this->view->openFieldset( self::OPT_DEFAULT_PAGE_CSS );
        $this->view->screenReaderLegend( __( 'Default stylesheet for new pages', 'postpage-specific-custom-css' ) );
        $this->view->textAreaField( 'defaultPageCSS', self::OPT_DEFAULT_PAGE_CSS, $value );
        $this->view->closeFieldset();
    }

    public function default_product_css() {
        $settings = (array) get_option( self::OPTION_NAME );
        $value    = wp_unslash( $settings[ self::OPT_DEFAULT_PRODUCT_CSS ] ?? '' );
        $this->view->openFieldset( self::OPT_DEFAULT_PRODUCT_CSS );
        $this->view->screenReaderLegend( __( 'Default stylesheet for new WooCommerce Product', 'postpage-specific-custom-css' ) );
        $this->view->textAreaField( 'defaultProductCSS', self::OPT_DEFAULT_PRODUCT_CSS, $value );
        $this->view->closeFieldset();
    }

    public function section_default_values() {
        $this->view->settingsInlineStyle();
        $this->view->printFieldDescription( __( 'You can define pre-filled CSS code that will be automatically added to every newly created post or page. <strong>Note: the code is not validated — invalid CSS is allowed and will be stored as is.</strong>', 'postpage-specific-custom-css' ) );
    }

    public function section_plugin_behavior() {}

    public function page_settings_link_filter( array $links ): array {
        array_unshift( $links, '<a href="' . $this->build_settings_link() . '">' . __( 'Settings', 'postpage-specific-custom-css' ) . '</a>' );

        return $links;
    }

    private function build_settings_link(): string {
        return admin_url( self::PARENT_MENU_SLUG . '?page=' . self::MENU_SLUG );
    }

    public function add_options_page() {
        $sub_menu_suffix = add_submenu_page( self::PARENT_MENU_SLUG, __( 'Post/Page Specific Custom Code', 'postpage-specific-custom-css' ), __( 'Post/Page CSS', 'postpage-specific-custom-css' ), self::CAP_MANAGE_OPTIONS, self::MENU_SLUG, [
            $this,
            'options_page_view',
        ] );
        add_action( 'load-' . $sub_menu_suffix, [
            $this,
            'options_admin_enqueue_scripts',
        ] );
    }

    public function options_page_view() {
        ?>
        <div class="wrap">
            <h1><?php
                echo __( 'Post/Page Custom Code', 'postpage-specific-custom-css' ); ?></h1>
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
        <script>
            jQuery(function ($) {
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
        <?php
    }

    public function the_content(
        string $content
    ): string {
        global $post;
        if ( ! isset( $post ) || ! is_a( $post, 'WP_Post' ) ) {
            return $content;
        }
        /** @var WP_Post $post */
        $phylax_ppsccss_single_only = get_post_meta( $post->ID, self::POST_META_SINGLE, true );
        $phylax_ppsccss_css         = get_post_meta( $post->ID, self::POST_META_CSS, true );
        if ( '' != $phylax_ppsccss_css ) {
            $phylax_valid_css = (string) ( get_post_meta( $post->ID, self::POST_META_VALID, true ) ?? '' );
            if ( '' === $phylax_valid_css ) {
                $phylax_valid_css = '1';
            }
            if ( '1' !== $phylax_valid_css ) {
                return $content;
            }
            if ( is_single() || is_page() ) {
                $content = $this->join( $content, $phylax_ppsccss_css );
            } else if ( '0' == $phylax_ppsccss_single_only ) {
                $content = $this->join( $content, $phylax_ppsccss_css );
            }
        }

        return $content;
    }

    public function join(
        $content, $css
    ): string {
        return '<!-- ' . __( 'Added by Post/Page Specific Custom Code plugin, thank you for using!', 'postpage-specific-custom-css' ) . ' -->' . PHP_EOL . '<style>' . $css . '</style>' . PHP_EOL . $content;
    }

    public function add_meta_boxes() {
        if ( $this->allowedToView() ) {
            add_meta_box( 'phylax_ppsccss', __( 'Custom Code', 'postpage-specific-custom-css' ), [
                $this,
                'render_post_page_edit_view',
            ], [
                              'post',
                              'page',
                              'product',
                          ], 'advanced', 'high' );
        }
    }

    public function allowedToView(): bool {
        $settings      = (array) get_option( self::OPTION_NAME );
        $allow_editors = (bool) ( $settings[ self::OPT_CONTROL_USER_EDITOR ] ?? 0 );
        if ( current_user_can( self::CAP_MANAGE_OPTIONS ) || ( $allow_editors && current_user_can( self::CAP_EDIT_OTHERS_PAGES ) ) ) {
            return true;
        }

        return false;
    }

    public function save_post(
        int $post_id
    ) {
        $post_id = abs( $post_id );
        if ( 0 === $post_id ) {
            return;
        }
        $nonce_value = (string) ( $_POST['phylax_ppsccss_nonce'] ?? '' );
        if ( '' === $nonce_value ) {
            return;
        }
        if ( ! wp_verify_nonce( $nonce_value, 'phylax_ppsccss' ) ) {
            return;
        }
        if ( ( 'product' != $_POST['post_type'] ) && ( 'page' != $_POST['post_type'] ) && ( 'post' != $_POST['post_type'] ) ) {
            return;
        }
        if ( ! $this->allowedToView() ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        $phylax_ppsccss_css         = trim( strip_tags( $_POST['phylax_ppsccss_css'] ) );
        $phylax_ppsccss_single_only = (int) $_POST['phylax_ppsccss_single_only'];
        if ( ( $phylax_ppsccss_single_only < 0 ) || ( $phylax_ppsccss_single_only > 1 ) ) {
            $phylax_ppsccss_single_only = 0;
        }
        $phylax_valid_css = ( 0 === (int) ( $_POST[ self::STYLE_LIVE_CHECKER_FIELD ] ?? 0 ) ) ? '1' : '0';
        update_post_meta( $post_id, self::POST_META_CSS, $phylax_ppsccss_css );
        update_post_meta( $post_id, self::POST_META_SINGLE, $phylax_ppsccss_single_only );
        update_post_meta( $post_id, self::POST_META_VALID, $phylax_valid_css );
    }

    public function render_post_page_edit_view( $post ) {
        wp_nonce_field( 'phylax_ppsccss', 'phylax_ppsccss_nonce' );
        echo '<input id="' . self::STYLE_LIVE_CHECKER_FIELD . '" type="hidden" name="' . self::STYLE_LIVE_CHECKER_FIELD . '" value="-1">';
        $screen   = "";
        $body_key = "";
        $settings = (array) get_option( self::OPTION_NAME );
        switch ( $post->post_type ) {
            case 'product':
                $screen   = __( 'Custom stylesheet for your product', 'postpage-specific-custom-css' );
                $body_key = self::OPT_DEFAULT_PRODUCT_CSS;
                break;
            case 'post':
                $screen   = __( 'Custom stylesheet for your post', 'postpage-specific-custom-css' );
                $body_key = self::OPT_DEFAULT_POST_CSS;
                break;
            case 'page':
                $screen   = __( 'Custom stylesheet for your page', 'postpage-specific-custom-css' );
                $body_key = self::OPT_DEFAULT_PAGE_CSS;
                break;
        }
        $post_meta = get_post_meta( $post->ID );
        $brand_new = false;
        if ( false === isset( $post_meta[ self::POST_META_CSS ] ) ) {
            $brand_new = true;
        }
        if ( $brand_new ) {
            $phylax_ppsccss_css         = $settings[ $body_key ] ?? '';
            $phylax_ppsccss_single_only = false;
        } else {
            $phylax_ppsccss_css         = get_post_meta( $post->ID, self::POST_META_CSS, true );
            $phylax_ppsccss_single_only = get_post_meta( $post->ID, self::POST_META_SINGLE, true );
        }
        if ( '' == $phylax_ppsccss_single_only ) {
            $phylax_ppsccss_single_only = 0;
        }
        $biggerBox = (int) ( $settings[ self::OPT_BIGGER_TEXTAREA ] ?? 0 );
        ?>
        <p class="post-attributes-label-wrapper"><label for="phylax_ppsccss_css"><?php
                echo $screen; ?></label></p>
        <div id="phylax_ppsccss_css_outer">
            <textarea name="phylax_ppsccss_css" id="phylax_ppsccss_css"
                      class="widefat textarea"
                      rows="<?php
                      echo( ( 0 === $biggerBox ) ? '10' : '25' ) ?>"><?php
                echo esc_textarea( $phylax_ppsccss_css ); ?></textarea>
        </div>
        <p class="post-attributes-label-wrapper">
            <label for="phylax_ppsccss_single_only"><input type="hidden" name="phylax_ppsccss_single_only"
                                                           value="0"><input type="checkbox"
                                                                            name="phylax_ppsccss_single_only" value="1"
                                                                            id="phylax_ppsccss_single_only"<?php
                echo( ( $phylax_ppsccss_single_only === true ) ? ' checked="checked"' : '' ); ?>> <?php
                echo __( 'Attach this CSS code only on single page view', 'postpage-specific-custom-css' ); ?>
            </label>
        </p>
        <?php
        $this->view->printFieldDescription( __( 'Please add only valid CSS code, it will be placed between &lt;style&gt; tags. Improper CSS code will be stored but not attached to the post and/or page.', 'postpage-specific-custom-css' ) ); ?>
        <script>
            jQuery(function ($) {
                const phylaxCSSEditorDOM = $('#phylax_ppsccss_css');
                let phylaxCSSEditorSettings;
                let phylaxCSSEditorInstance;
                let throttleTimer = null;
                const liveCheckerFieldID = document.getElementById('<?php echo self::STYLE_LIVE_CHECKER_FIELD; ?>');
                if (phylaxCSSEditorDOM.length === 1) {
                    phylaxCSSEditorSettings = wp.codeEditor.defaultSettings ? _.clone(wp.codeEditor.defaultSettings) : {};
                    phylaxCSSEditorSettings.codemirror = _.extend({}, phylaxCSSEditorSettings.codemirror, {
                        indentUnit: 2, tabSize: 2, mode: 'css', lint: false,
                    });
                    phylaxCSSEditorInstance = wp.codeEditor.initialize(phylaxCSSEditorDOM, phylaxCSSEditorSettings);

                    function validateCSSInEditor() {
                        const cm = phylaxCSSEditorInstance.codemirror;
                        const css = cm.getValue();
                        const outer = document.getElementById('phylax_ppsccss_css_outer');
                        if (!outer) return;
                        document.querySelectorAll('.css-error').forEach(el => el.remove());
                        cm.getAllMarks().forEach(mark => mark.clear());
                        const existingErrors = document.getElementById('phylax_ppsccss_css_errors');
                        if (existingErrors) {
                            existingErrors.parentNode.removeChild(existingErrors);
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
                            const ast = csstree.parse(css, {
                                positions: true,
                                context: 'stylesheet'
                            });
                            csstree.walk(ast, {
                                enter: function (node) {
                                    if (node.type === 'Raw' && node.loc) {
                                        const {line, column} = node.loc.start;
                                        hasErrors = true;
                                        const lineContent = cm.getLine(line - 1);
                                        const toCh = Math.min(column + 5, lineContent.length);
                                        cm.markText(
                                            {line: line - 1, ch: column - 1},
                                            {line: line - 1, ch: toCh},
                                            {
                                                className: 'css-error',
                                                title: 'Unrecognized syntax'
                                            }
                                        );
                                        const li = document.createElement('li');
                                        li.innerHTML = `Line ${line}, Col ${column}: Unrecognized syntax`;
                                        li.style.cursor = 'pointer';
                                        li.onclick = () => {
                                            cm.focus();
                                            cm.setCursor({line: line - 1, ch: column - 1});
                                            cm.scrollIntoView({line: line - 1, ch: 0}, 100);
                                        };
                                        ul.appendChild(li);
                                    }
                                }
                            });
                        } catch (err) {
                            hasErrors = true;
                            const match = /Line (\d+),? col(?:umn)? (\d+)/i.exec(err.message);
                            let line = 1, column = 1;
                            if (match) {
                                line = parseInt(match[1]);
                                column = parseInt(match[2]);
                            }
                            const lineContent = cm.getLine(line - 1);
                            const toCh = Math.min(column + 5, lineContent.length);
                            cm.markText(
                                {line: line - 1, ch: column - 1},
                                {line: line - 1, ch: toCh},
                                {
                                    className: 'css-error',
                                    title: err.message
                                }
                            );
                            const li = document.createElement('li');
                            li.innerHTML = `Line ${line}, Col ${column}: ${err.message}`;
                            li.style.cursor = 'pointer';
                            li.onclick = () => {
                                cm.focus();
                                cm.setCursor({line: line - 1, ch: column - 1});
                                cm.scrollIntoView({line: line - 1, ch: 0}, 100);
                            };
                            ul.appendChild(li);
                        }
                        if (hasErrors) {
                            liveCheckerFieldID.value = "1";
                            outer.parentNode.insertBefore(errorDiv, outer);
                        } else {
                            liveCheckerFieldID.value = "0";
                        }
                    }

                    setTimeout(validateCSSInEditor, 100);
                    $(document).on('keyup', '#phylax_ppsccss_css_outer .CodeMirror-code', function () {
                        phylaxCSSEditorDOM.html(phylaxCSSEditorInstance.codemirror.getValue());
                        phylaxCSSEditorDOM.data('changed', true);
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
                        clearTimeout(throttleTimer);
                        throttleTimer = setTimeout(validateCSSInEditor, 450);
                    });
                }
            });
        </script>
        <?php
        $this->view->settingsInlineStyle();
        if ( 1 === $biggerBox ) :
            ?>
            <style>
                #phylax_ppsccss_css_outer .CodeMirror {
                    height: 600px;
                }</style><?php
        endif;
    }
}

new Plugin();
