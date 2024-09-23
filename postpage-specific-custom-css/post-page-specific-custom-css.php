<?php
/**
 * Plugin Name: Post/Page specific custom CSS
 * Plugin URI: https://wordpress.org/plugins/postpage-specific-custom-css/
 * Description: Post/Page specific custom CSS will allow you to add cascade stylesheet to specific posts/pages. It will give you special area in the post/page edit field to attach your CSS. It will also let you decide if this CSS has to be added in multi-page/post view (like archive posts) or only in a single view.
 * Version: 0.2.4
 * Author: Łukasz Nowicki
 * Author URI: https://lukasznowicki.info/
 * Requires at least: 5.0
 * Requires PHP: 7.0
 * Tested up to: 5.8
 * Text Domain: postpage-specific-custom-css
 * Domain Path: /languages
 */

namespace Phylax\WPPlugin\PPCustomCSS;

use WP_Post;
use const DOING_AUTOSAVE;

defined( 'ABSPATH' ) or exit;

require_once __DIR__ . '/ViewHelpers.php';

class Plugin {

	const USER_META_STRING = 'ppscc_birthday_message';
	const MENU_SLUG = 'post-page-custom-css';
	const PARENT_MENU_SLUG = 'options-general.php';
	const OPTION_GROUP = 'ppcs_settings_group';
	const OPTION_NAME = 'ppcs_settings_name';

	const OPTION_ENABLE_HIGHLIGHTING = 'enable_highlighting_in_settings';
	const POST_ENABLE_HIGHLIGHTING = 'enable_highlighting_in_posts';
	const PAGE_ENABLE_HIGHLIGHTING = 'enable_highlighting_in_pages';

	const OPT_CONTROL_USER_EDITOR = 'control_user_editor';
	const OPT_DEFAULT_POST_CSS = 'default_post_css';
	const OPT_DEFAULT_PAGE_CSS = 'default_page_css';
	const OPT_BIGGER_TEXTAREA = 'bigger_textarea';

	const POST_META_CSS = '_phylax_ppsccss_css';
	const POST_META_SINGLE = '_phylax_ppsccss_single_only';
	const POST_META_VALID = '_phylax_ppsccss_valid';

	const NONCE_HIDE_BIRTHDAY = 'ppscc_hide_birthday';
	const NONCE_VALIDATE_CSS = 'ppscc_validate_stylesheet';

	const CAP_MANAGE_OPTIONS = 'manage_options';
	const CAP_EDIT_OTHERS_PAGES = 'edit_others_pages';

	private $view;

	private $isBirthday = false;
	private $isDayBefore = false;
	private $isDayAfter = false;

	private $flagsURLs;

	public function __construct() {
		$this->view = new ViewHelpers( (array) get_option( self::OPTION_NAME ), self::OPTION_NAME );
		add_action( 'init', [
			$this,
			'init',
		] );
		add_filter( 'the_content', [
			$this,
			'the_content',
		], 999 );
		if ( is_admin() ) {
			$this->startInAdmin();
		}
	}

	public function startInAdmin() {
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'page_settings_link_filter' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post', [ $this, 'save_post' ] );
		add_action( 'admin_menu', [ $this, 'add_options_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ] );
		$today = date( 'md' );
		switch ( $today ) {
			case '0501':
				$this->isDayBefore = true;
				break;
			case '0502':
				$this->isBirthday = true;
				break;
			case '0503':
				$this->isDayAfter = true;
				break;
		}
		if ( $this->isDayBefore || $this->isBirthday || $this->isDayAfter ) {
			add_action( 'admin_notices', [ $this, 'adminNotices' ] );
		}
	}

	public function options_admin_enqueue_scripts() {
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
		$field = '';
		if ( ( $screen->id === 'post' ) && ( $screen->post_type === 'post' ) ) {
			$field = self::POST_ENABLE_HIGHLIGHTING;
		}
		if ( ( $screen->id === 'page' ) && ( $screen->post_type === 'page' ) ) {
			$field = self::PAGE_ENABLE_HIGHLIGHTING;
		}
		if ( '' === $field ) {
			return;
		}
		$settings = (array) get_option( self::OPTION_NAME );
		$value    = (int) ( $settings[ $field ] ?? 0 );
		if ( 1 === $value ) {
			wp_enqueue_code_editor( [
				'type'       => 'text/javascript',
				'codemirror' => [
					'autoRefresh' => true,
				],
			] );
		}
	}

	public function register_settings() {
		register_setting( self::OPTION_GROUP, self::OPTION_NAME );
		add_settings_section( 'plugin-behavior', __( 'Options', 'postpage-specific-custom-css' ), [
			$this,
			'section_plugin_behavior',
		], self::MENU_SLUG );
		add_settings_field( 'control-user', __( 'User control', 'postpage-specific-custom-css' ), [
			$this,
			'control_user_editor'
		], self::MENU_SLUG, 'plugin-behavior' );
		add_settings_section( 'default-values', __( 'Default values', 'postpage-specific-custom-css' ), [
			$this,
			'section_default_values',
		], self::MENU_SLUG );
		add_settings_field( 'default_post_css', __( 'Default stylesheet for new posts', 'postpage-specific-custom-css' ), [
			$this,
			'default_post_css',
		], self::MENU_SLUG, 'default-values' );
		add_settings_field( 'default_page_css', __( 'Default stylesheet for new pages', 'postpage-specific-custom-css' ), [
			$this,
			'default_page_css',
		], self::MENU_SLUG, 'default-values' );
		add_settings_field( 'enable_highlighting_in', __( 'Code highlight', 'postpage-specific-custom-css' ), [
			$this,
			'enable_highlighting_in',
		], self::MENU_SLUG, 'plugin-behavior' );
		add_settings_field( 'bigger_textarea', __( 'Bigger input field', 'postpage-specific-custom-css' ), [
			$this,
			'bigger_textarea',
		], self::MENU_SLUG, 'plugin-behavior' );
	}

	public function bigger_textarea() {
		$this->view->openFieldset( self::OPT_BIGGER_TEXTAREA );
		$this->view->screenReaderLegend( __( 'Make input boxes bigger', 'postpage-specific-custom-css' ) );
		$this->view->checkBoxField( self::OPT_BIGGER_TEXTAREA, __( 'Make input boxes on Posts and Pages bigger', 'postpage-specific-custom-css' ) );
		$this->view->closeFieldset();
	}

	public function enable_highlighting_in() {
		$this->view->openFieldset( 'enable_code_highlighting' );
		$this->view->screenReaderLegend( __( 'Enable code highlighting', 'postpage-specific-custom-css' ) );
		$this->view->checkBoxField( self::OPTION_ENABLE_HIGHLIGHTING, __( 'Enable code highlighting for fields on settings page', 'postpage-specific-custom-css' ) );
		$this->view->checkBoxField( self::POST_ENABLE_HIGHLIGHTING, __( 'Enable code highlighting for Posts fields', 'postpage-specific-custom-css' ) );
		$this->view->checkBoxField( self::PAGE_ENABLE_HIGHLIGHTING, __( 'Enable code highlighting for Pages fields', 'postpage-specific-custom-css' ) );
		$this->view->closeFieldset();
		$this->view->printFieldDescription( __( '<strong>Warning</strong> Please consider that on weaker computers, enabling CSS highlighting may slow you down.', 'postpage-specific-custom-css' ) );

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
		$errors   = $this->validateCSS( $value );
		$this->view->openFieldset( self::OPT_DEFAULT_POST_CSS );
		$this->view->screenReaderLegend( __( 'Default stylesheet for new posts', 'postpage-specific-custom-css' ) );
		$this->view->textAreaField( 'defaultPostCSS', self::OPT_DEFAULT_POST_CSS, $value, $errors, count( $errors ) );
		$this->view->closeFieldset();
	}

	public function validateCSS( string $css ): array {
		$errors     = [];
		$imbalanced = false;
		if ( preg_match( '#</?\w+#', $css ) ) {
			$errors[] = __( 'Markup is not allowed in CSS.', 'postpage-specific-custom-css' );
		}
		if ( ! $this->validate_balanced_characters( '{', '}', $css ) ) {
			$errors[]   = sprintf(
			/* translators: 1: {}, 2: }, 3: { */
				__( 'Your curly brackets %1$s are imbalanced. Make sure there is a closing %2$s for every opening %3$s.', 'postpage-specific-custom-css' ),
				'<code>{}</code>',
				'<code>}</code>',
				'<code>{</code>'
			);
			$imbalanced = true;
		}
		if ( ! $this->validate_balanced_characters( '[', ']', $css ) ) {
			$errors[]   = sprintf(
			/* translators: 1: {}, 2: }, 3: { */
				__( 'Your brackets %1$s are imbalanced. Make sure there is a closing %2$s for every opening %3$s.', 'postpage-specific-custom-css' ),
				'<code>[]</code>',
				'<code>]</code>',
				'<code>[</code>'
			);
			$imbalanced = true;
		}
		if ( ! $this->validate_balanced_characters( '(', ')', $css ) ) {
			$errors[]   = sprintf(
			/* translators: 1: {}, 2: }, 3: { */
				__( 'Your parentheses %1$s are imbalanced. Make sure there is a closing %2$s for every opening %3$s.', 'postpage-specific-custom-css' ),
				'<code>()</code>',
				'<code>)</code>',
				'<code>(</code>'
			);
			$imbalanced = true;
		}
		if ( ! $this->validate_equal_characters( '"', $css ) ) {
			$errors[]   = sprintf(
			/* translators: 1: " (double quote) */
				__( 'Your double quotes %1$s are uneven. Make sure there is a closing %1$s for every opening %1$s.', 'postpage-specific-custom-css' ),
				'<code>"</code>'
			);
			$imbalanced = true;
		}
		$unclosed_comment_count = $this->validate_count_unclosed_comments( $css );
		if ( 0 < $unclosed_comment_count ) {
			$errors[]   = sprintf(
			/* translators: 1: number of unclosed comments, 2: *​/ */
				_n(
					'There is %1$s unclosed code comment. Close each comment with %2$s.',
					'There are %1$s unclosed code comments. Close each comment with %2$s.',
					$unclosed_comment_count,
					'postpage-specific-custom-css'
				),
				$unclosed_comment_count,
				'<code>*/</code>'
			);
			$imbalanced = true;
		} elseif ( ! $this->validate_balanced_characters( '/*', '*/', $css ) ) {
			$errors[]   = sprintf(
			/* translators: 1: *​/, 2: /​* */
				__( 'There is an extra %1$s, indicating an end to a comment. Be sure that there is an opening %2$s for every closing %1$s.', 'postpage-specific-custom-css' ),
				'<code>*/</code>',
				'<code>/*</code>'
			);
			$imbalanced = true;
		}
		if ( $imbalanced && $this->is_possible_content_error( $css ) ) {
			$errors[] = sprintf(
			/* translators: %s: content: ""; */
				__( 'Imbalanced/unclosed character errors can be caused by %s declarations. You may need to remove this or add it to a custom CSS file.', 'postpage-specific-custom-css' ),
				'<code>content: "";</code>'
			);
		}

		return $errors;
	}

	private function validate_balanced_characters( string $opening_char, string $closing_char, string $css ): bool {
		return substr_count( $css, $opening_char ) === substr_count( $css, $closing_char );
	}

	private function validate_equal_characters( string $char, string $css ): bool {
		$char_count = substr_count( $css, $char );

		return ( 0 === $char_count % 2 );
	}

	private function validate_count_unclosed_comments( string $css ): int {
		$count    = 0;
		$comments = explode( '/*', $css );
		if ( ! is_array( $comments ) || ( 1 >= count( $comments ) ) ) {
			return $count;
		}
		unset( $comments[0] ); // The first item is before the first comment.
		foreach ( $comments as $comment ) {
			if ( false === strpos( $comment, '*/' ) ) {
				$count ++;
			}
		}

		return $count;
	}

	private function is_possible_content_error( string $css ): bool {
		$found = preg_match( '/\bcontent\s*:/', $css );
		if ( ! empty( $found ) ) {
			return true;
		}

		return false;
	}

	public function default_page_css() {
		$settings = (array) get_option( self::OPTION_NAME );
		$value    = wp_unslash( $settings[ self::OPT_DEFAULT_PAGE_CSS ] ?? '' );
		$errors   = $this->validateCSS( $value );
		$this->view->openFieldset( self::OPT_DEFAULT_PAGE_CSS );
		$this->view->screenReaderLegend( __( 'Default stylesheet for new pages', 'postpage-specific-custom-css' ) );
		$this->view->textAreaField( 'defaultPageCSS', self::OPT_DEFAULT_PAGE_CSS, $value, $errors, count( $errors ) );
		$this->view->closeFieldset();
	}

	public function section_default_values() {
		$this->view->settingsInlineStyle();
		$this->view->printFieldDescription( __( 'You can set the pre-filled content for your newly created posts or pages. <strong>Warning: improper CSS will be stored but not attached.</strong>', 'postpage-specific-custom-css' ) );
	}

	public function section_plugin_behavior() {
	}

	public function adminNotices( $show_on_demand = false ) {
		if ( ! $show_on_demand && $this->current_user_already_seen_message() ) {
			return;
		}
		add_action( 'admin_print_footer_scripts', function () {
			$this->view->birthday_script( self::NONCE_HIDE_BIRTHDAY );
			$this->view->birthday_style();
		} );
		$this->flagsURLs = plugins_url( '/assets/flags/', __FILE__ );
		$flagList        = [ 'us', 'gb', 'eu', 'ch', 'pl' ];
		$current_user    = wp_get_current_user();
		?>
        <div id="ppscc_birthday_notice" class="notice notice-success is-dismissible">
            <p><span class="dashicons dashicons-buddicons-groups"
                     style="font-size:120px;width: 120px;height: 120px;float:left;color:#0a0;"></span>
                <strong><?php echo sprintf( __( 'Hello %s!', 'postpage-specific-custom-css' ), esc_attr( $current_user->display_name ) ); ?></strong>
            </p>
            <p>
				<?php
				if ( $this->isBirthday ) {
					echo __( 'Today is my birthday.', 'postpage-specific-custom-css' ) . ' ';
					echo sprintf( __( 'I hope I just turned <strong>%d</strong>.', 'postpage-specific-custom-css' ), ( (int) date( 'Y' ) ) - 1977 ) . ' ';
				}
				if ( $this->isDayBefore ) {
					echo __( 'Tomorrow will be my birthday.', 'postpage-specific-custom-css' ) . ' ';
					echo sprintf( __( 'I hope I will turn <strong>%d</strong> tomorrow.', 'postpage-specific-custom-css' ), ( (int) date( 'Y' ) ) - 1977 ) . ' ';
				}
				if ( $this->isDayAfter ) {
					echo __( 'Yesterday were my birthday.', 'postpage-specific-custom-css' ) . ' ';
					echo sprintf( __( 'I hope I turned <strong>%d</strong>...', 'postpage-specific-custom-css' ), ( (int) date( 'Y' ) ) - 1977 ) . ' ';
				}
				echo sprintf( __( 'I just think, maybe you want to <a href="%s">give me a review</a> for my plugin?', 'postpage-specific-custom-css' ), 'https://wordpress.org/support/plugin/postpage-specific-custom-css/reviews/#new-post' ) . ' ';
				echo __( 'Or maybe you have such a good situation that you would like to consider a small donation? Click on the currency flag and the account number will show if you would like to repay my work. <strong>I do not insist!</strong> It would be just nice to get a birthday present.', 'postpage-specific-custom-css' ) . '<br>';
				?>
            </p>
            <p class="ppsc_story"><?php
				echo __( 'Or maybe you would like to know my story? I weighed 230.6 kg (508.5lb), I couldn\'t move, I almost became a cripple. Thanks to my fiancee I started to lose weight. I\'m halfway there. Currently, I weigh about 165kg (363lb). Or maybe less?', 'postpage-specific-custom-css' ) . ' ';
				echo __( '<a href="%s">Follow me on Instagram</a> and <a href="%s">follow the Facebook page</a>. You can also send me wishes there if you like, thank you :)', 'postpage-specific-custom-css' ) . ' ';
				?></p>
            <p style="text-align: center"><?php echo __( 'Click on the flag to see account details. For every, even the smallest payment - thank you a lot!', 'postpage-specific-custom-css' ); ?></p>
            <p style="text-align: center">
				<?php foreach ( $flagList as $code ) : ?>
                    <a href="#" class="ppsc_show"
                       data-ppsc="<?php echo $code; ?>"><?php echo strip_tags( $this->flag( $code ), '<img>' ); ?></a>
				<?php endforeach; ?>
            </p>
			<?php
			$accounts = [
				'us' => 'PL04249010570000990143895083',
				'gb' => 'PL39249010570000990443895083',
				'eu' => 'PL48249010570000990243895083',
				'ch' => 'PL92249010570000990343895083',
				'pl' => '57249010570000990043895083',
			];
			$currency = [
				'us' => __( 'United States dollar - USD', 'postpage-specific-custom-css' ),
				'gb' => __( 'Pound sterling - GBP', 'postpage-specific-custom-css' ),
				'eu' => __( 'Euro - EUR', 'postpage-specific-custom-css' ),
				'ch' => __( 'Swiss franc - CHF', 'postpage-specific-custom-css' ),
				'pl' => __( 'Polish złoty - PLN', 'postpage-specific-custom-css' ),
			];
			foreach ( $flagList as $code ) {
				$this->accountLine( $code, $accounts[ $code ], $currency[ $code ] );
			}
			?>
        </div>
		<?php
	}

	public function current_user_already_seen_message(): bool {
		if ( ! current_user_can( self::CAP_MANAGE_OPTIONS ) ) {
			// Always return true for non-administrators
			return true;
		}
		$value = (array) ( get_user_meta( get_current_user_id(), self::USER_META_STRING, true ) ?? [] );

		return (bool) ( $value[ date( 'Y' ) ] ?? false );
	}

	public function flag( string $code ): string {
		$alt = '';
		switch ( $code ) {
			case 'us':
				$alt = __( 'United States dollar - USD', 'postpage-specific-custom-css' );
				break;
			case 'gb':
				$alt = __( 'Pound sterling - GBP', 'postpage-specific-custom-css' );
				break;
			case 'eu':
				$alt = __( 'Euro - EUR', 'postpage-specific-custom-css' );
				break;
			case 'ch':
				$alt = __( 'Swiss franc - CHF', 'postpage-specific-custom-css' );
				break;
			case 'pl':
				$alt = __( 'Polish złoty - PLN', 'postpage-specific-custom-css' );
				break;
		}
		if ( '' === $alt ) {
			return '';
		}

		return '<img src="' . $this->flagsURLs . $code . '.png' . '" alt="' . $alt . '" title="' . $alt . '" style="width:32px;height:22px;">';
	}

	public function accountLine( $code, $account, $currency ) {
		?>
        <div class="ppsc_hide_all" id="acinfofor_<?php echo $code; ?>">
            <div class="ppsc_info_line"><?php echo __( 'BIC/SWIFT:', 'postpage-specific-custom-css' ); ?>
                <span>ALBPPLPW</span>
				<?php echo __( 'Currency:', 'postpage-specific-custom-css' ) . ' <span> ' . $currency; ?></span></div>
            <div class="ppsc_info_line"><?php echo __( 'Account number:', 'postpage-specific-custom-css' ); ?>
                <span><?php echo $account; ?></span></div>
        </div>
		<?php
	}

	public function page_settings_link_filter( array $links ): array {
		$links[] = '<a href="' . $this->build_settings_link() . '">' . __( 'Settings', 'postpage-specific-custom-css' ) . '</a>';
		if ( $this->isBirthday || $this->isDayBefore || $this->isDayAfter ) {
			$links[] = '<a href="' . get_admin_url() . 'options-general.php?page=post-page-custom-css">' . __( 'My birthday!', 'postpage-specific-custom-css' ) . '</a>';
		}

		return $links;
	}

	private function build_settings_link(): string {
		return admin_url( self::PARENT_MENU_SLUG . '?page=' . self::MENU_SLUG );
	}

	public function add_options_page() {
		$sub_menu_suffix = add_submenu_page( self::PARENT_MENU_SLUG, __( 'Post/Page specific custom CSS', 'postpage-specific-custom-css' ), __( 'Post/Page CSS', 'postpage-specific-custom-css' ), self::CAP_MANAGE_OPTIONS, self::MENU_SLUG, [
			$this,
			'options_page_view',
		] );
		$settings        = (array) get_option( self::OPTION_NAME );
		$value           = (int) ( $settings[ self::OPTION_ENABLE_HIGHLIGHTING ] ?? 0 );
		if ( 1 === $value ) {
			add_action( 'load-' . $sub_menu_suffix, [
				$this,
				'options_admin_enqueue_scripts',
			] );
		}
	}

	public function options_page_view() {
		?>
        <div class="wrap">
            <h1><?php echo __( 'Post/Page Custom CSS', 'postpage-specific-custom-css' ); ?></h1>
            <form action="options.php" method="POST">
				<?php settings_fields( self::OPTION_GROUP ); ?>
                <div>
					<?php do_settings_sections( self::MENU_SLUG ); ?>
                </div>
				<?php submit_button(); ?>
            </form>
        </div>
		<?php
		$settings = (array) get_option( self::OPTION_NAME );
		$value    = (int) ( $settings[ self::OPTION_ENABLE_HIGHLIGHTING ] ?? 0 );
		if ( 1 === $value ) :
			?>
            <script>
                jQuery(function ($) {
                    const defaultPageCSS = $('#defaultPageCSS');
                    const defaultPostCSS = $('#defaultPostCSS');
                    let editorSettings;
                    if (defaultPageCSS.length === 1) {
                        editorSettings = wp.codeEditor.defaultSettings ? _.clone(wp.codeEditor.defaultSettings) : {};
                        editorSettings.codemirror = _.extend({}, editorSettings.codemirror, {
                            indentUnit: 2, tabSize: 2, mode: 'css',
                        });
                        wp.codeEditor.initialize(defaultPageCSS, editorSettings);
                    }
                    if (defaultPostCSS.length === 1) {
                        editorSettings = wp.codeEditor.defaultSettings ? _.clone(wp.codeEditor.defaultSettings) : {};
                        editorSettings.codemirror = _.extend({}, editorSettings.codemirror, {
                            indentUnit: 2, tabSize: 2, mode: 'css',
                        });
                        wp.codeEditor.initialize(defaultPostCSS, editorSettings);
                    }
                });
            </script>
		<?php
		endif;
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
				$errors = $this->validateCSS( $phylax_ppsccss_css );
				if ( 0 === count( $errors ) ) {
					$phylax_valid_css = '1';
				} else {
					$phylax_valid_css = '0';
				}
				update_post_meta( $post->ID, self::POST_META_VALID, $phylax_valid_css );
			}
			if ( '1' !== $phylax_valid_css ) {
				return $content;
			}

			if ( is_single() || is_page() ) {
				$content = $this->join( $content, $phylax_ppsccss_css );
			} elseif ( '0' == $phylax_ppsccss_single_only ) {
				$content = $this->join( $content, $phylax_ppsccss_css );
			}
		}

		return $content;
	}

	public function join(
		$content, $css
	): string {
		return '<!-- ' . __( 'Added by Post/Page specific custom CSS plugin, thank you for using!', 'postpage-specific-custom-css' ) . ' -->' . PHP_EOL . '<style>' . $css . '</style>' . PHP_EOL . $content;
	}

	public function add_meta_boxes() {
		if ( $this->allowedToView() ) {
			add_meta_box( 'phylax_ppsccss', __( 'Custom CSS', 'postpage-specific-custom-css' ), [
				$this,
				'render_phylax_ppsccss',
			], [
				'post',
				'page',
			], 'advanced', 'high' );
		}
	}

	public function allowedToView(): bool {
		$settings      = (array) get_option( self::OPTION_NAME );
		$allow_editors = (bool) ( $settings[ self::OPT_CONTROL_USER_EDITOR ] ?? 0 );
		if (
			current_user_can( self::CAP_MANAGE_OPTIONS ) ||
			(
				$allow_editors &&
				current_user_can( self::CAP_EDIT_OTHERS_PAGES )
			)
		) {
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
		if ( ( 'page' != $_POST['post_type'] ) && ( 'post' != $_POST['post_type'] ) ) {
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
		$errors           = $this->validateCSS( $phylax_ppsccss_css );
		$phylax_valid_css = '0';
		if ( 0 === count( $errors ) ) {
			$phylax_valid_css = '1';
		}
		update_post_meta( $post_id, self::POST_META_CSS, $phylax_ppsccss_css );
		update_post_meta( $post_id, self::POST_META_SINGLE, $phylax_ppsccss_single_only );
		update_post_meta( $post_id, self::POST_META_VALID, $phylax_valid_css );
	}

	public function render_phylax_ppsccss( $post ) {
		wp_nonce_field( 'phylax_ppsccss', 'phylax_ppsccss_nonce' );
		$screen   = $theField = $defaultField = '';
		$settings = (array) get_option( self::OPTION_NAME );
		switch ( $post->post_type ) {
			case 'post':
				$theField     = self::POST_ENABLE_HIGHLIGHTING;
				$defaultField = self::OPT_DEFAULT_POST_CSS;
				$screen       = __( 'Custom stylesheet for your post', 'postpage-specific-custom-css' );
				break;
			case 'page':
				$theField     = self::PAGE_ENABLE_HIGHLIGHTING;
				$defaultField = self::OPT_DEFAULT_PAGE_CSS;
				$screen       = __( 'Custom stylesheet for your page', 'postpage-specific-custom-css' );
				break;
		}
		if ( '' === $theField ) {
			return;
		}
		$enable_highlighting = (int) ( $settings[ $theField ] ?? 0 );
		$post_meta           = get_post_meta( $post->ID );
		$brand_new           = false;
		if ( false === isset( $post_meta[ self::POST_META_CSS ] ) ) {
			$brand_new = true;
		}
		$phylax_ppsccss_css = get_post_meta( $post->ID, self::POST_META_CSS, true );
		if ( ( '' === $phylax_ppsccss_css ) && ( true === $brand_new ) ) {
			$phylax_ppsccss_css .= $settings[ $defaultField ];
		}
		$phylax_ppsccss_single_only = get_post_meta( $post->ID, self::POST_META_SINGLE, true );
		if ( '' == $phylax_ppsccss_single_only ) {
			$phylax_ppsccss_single_only = 0;
		}
		if ( $phylax_ppsccss_single_only ) {
			$checked = ' checked="checked"';
		} else {
			$checked = '';
		}
		$biggerBox    = (int) ( $settings[ self::OPT_BIGGER_TEXTAREA ] ?? 0 );
		$errors       = $this->validateCSS( $phylax_ppsccss_css );
		$errors_count = count( $errors );
		?>
        <p class="post-attributes-label-wrapper"><label for="phylax_ppsccss_css"><?php echo $screen; ?></label></p>
        <div id="ppscc-validating">
            <span><?php echo __( 'Validating CSS, please wait...', 'postpage-specific-custom-css' ); ?></span><img
                    src="/wp-admin/images/loading.gif" alt=""/>
        </div>
        <div id="ppscc-css-errors_container">
			<?php echo strip_tags( $this->view->getErrorItems( $errors, $errors_count ), '<ul><li>' ); ?>
        </div>
        <script>
            jQuery(function ($) {
                const ppsccEditPost = wp.data.select('core/edit-post');
                if (
                    ('object' !== typeof ppsccEditPost) ||
                    (null === ppsccEditPost)
                ) {
                    return;
                }
                const ppsccNonce = "<?php echo wp_create_nonce( self::NONCE_VALIDATE_CSS ); ?>";
                const ppsccValidateMessage = $('#ppscc-validating');
                const ppsccErrorContainer = $('#ppscc-css-errors_container');
                let saveStep = 0;

                function validateMessage(display_mode) {
                    ppsccValidateMessage.css('display', display_mode);
                }

                async function validateCSS() {
                    try {
                        const ppsccPostId = wp.data.select('core/editor').getEditedPostAttribute('id');
                        let ajaxResult = await $.post(ajaxurl, {
                            url: ajaxurl,
                            action: 'ppscc_validate',
                            data: {
                                ppsccNonce,
                                ppsccPostId,
                            },
                        });
                        validateMessage('none');
                        ajaxResult = JSON.parse(ajaxResult);
                        if (0 !== ajaxResult.error) {
                            return;
                        }
                        ppsccErrorContainer.html(ajaxResult.errors);
                    } catch (error) {
                        validateMessage('none');
                    }
                }

                wp.data.subscribe(function () {
                    const isSavingPost = wp.data.select('core/editor').isSavingPost();
                    const isSavingMetaBoxes = ppsccEditPost.isSavingMetaBoxes();
                    switch (true) {
                        case (!isSavingPost && !isSavingMetaBoxes && (saveStep === 0)):
                            return;
                        case ((isSavingPost || isSavingMetaBoxes) && (saveStep === 0)):
                            validateMessage('flex');
                            ppsccErrorContainer.html('');
                            saveStep = 1;
                            return;
                        case (isSavingMetaBoxes):
                            saveStep++;
                            return;
                        case (!isSavingMetaBoxes && (saveStep > 1)):
                            saveStep = 0;
                            validateCSS();
                            return;
                    }
                });
            });
        </script>
        <div id="phylax_ppsccss_css_outer"
             class="<?php echo( ( $errors_count > 0 ) ? 'ppsc_validate_errors' : '' ); ?>">
            <textarea name="phylax_ppsccss_css" id="phylax_ppsccss_css"
                      class="widefat textarea"
                      rows="<?php echo( ( 0 === $biggerBox ) ? '10' : '25' ) ?>"><?php echo esc_textarea( $phylax_ppsccss_css ); ?></textarea>
        </div>
        <p class="post-attributes-label-wrapper">
            <label for="phylax_ppsccss_single_only"><input type="hidden" name="phylax_ppsccss_single_only"
                                                           value="0"><input type="checkbox"
                                                                            name="phylax_ppsccss_single_only" value="1"
                                                                            id="phylax_ppsccss_single_only"<?php echo( ( $phylax_ppsccss_single_only === true ) ? ' checked="checked"' : '' ); ?>> <?php echo __( 'Attach this CSS code only on single page view', 'postpage-specific-custom-css' ); ?>
            </label>
        </p>
		<?php $this->view->printFieldDescription( __( 'Please add only valid CSS code, it will be placed between &lt;style&gt; tags. Improper CSS code will be stored but not attached to the post and/or page.', 'postpage-specific-custom-css' ) ); ?>
		<?php
		if ( $enable_highlighting ) :
			?>
            <script>
                jQuery(function ($) {
                    const phylaxCSSEditorDOM = $('#phylax_ppsccss_css');
                    let phylaxCSSEditorSettings;
                    let phylaxCSSEditorInstance;
                    if (phylaxCSSEditorDOM.length === 1) {
                        phylaxCSSEditorSettings = wp.codeEditor.defaultSettings ? _.clone(wp.codeEditor.defaultSettings) : {};
                        phylaxCSSEditorSettings.codemirror = _.extend({}, phylaxCSSEditorSettings.codemirror, {
                            indentUnit: 2, tabSize: 2, mode: 'css',
                        });
                        phylaxCSSEditorInstance = wp.codeEditor.initialize(phylaxCSSEditorDOM, phylaxCSSEditorSettings);
                        $(document).on('keyup', '#phylax_ppsccss_css_outer .CodeMirror-code', function () {
                            phylaxCSSEditorDOM.html(phylaxCSSEditorInstance.codemirror.getValue());
                            phylaxCSSEditorDOM.trigger('change');
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
                    }
                </style>
			<?php
			endif;
		endif;
	}

	public function init() {
		load_plugin_textdomain( 'postpage-specific-custom-css', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
		if ( is_admin() ) {
			add_action( 'wp_ajax_ppscc_validate', function () {
				$data        = (array) ( $_POST['data'] ?? [] );
				$ppsccNonce  = (string) ( $data['ppsccNonce'] ?? '' );
				$ppsccPostId = abs( (int) ( $data['ppsccPostId'] ?? '' ) );
				if ( false === wp_verify_nonce( $ppsccNonce, self::NONCE_VALIDATE_CSS ) ) {
					$this->validateUnauthorised();
				}
				if ( 0 === $ppsccPostId ) {
					$this->validateUnauthorised();
				}
				if ( ! $this->allowedToView() ) {
					$this->validateUnauthorised();
				}
				$css = trim( (string) ( get_post_meta( $ppsccPostId, self::POST_META_CSS, true ) ?? '' ) );
				if ( '' === $css ) {
					$this->validateAnswer();
				}
				$errors = $this->validateCSS( $css );

				$this->validateAnswer(
					$this->view->getErrorItems( $errors, count( $errors ) )
				);
			} );
		}
		if ( current_user_can( self::CAP_MANAGE_OPTIONS ) ) {
			$ppscc_birthday = (string) ( get_user_meta( get_current_user_id(), 'ppscc_birthday', true ) ?? '' );
			if ( 'hide' === $ppscc_birthday ) {
				return;
			}
			add_action( 'wp_ajax_ppscc_hide', function () {
				$ppscc_nonce = (string) ( $_POST['ppscc_nonce'] ?? '' );
				if ( '' === $ppscc_nonce ) {
					exit;
				}
				if ( false === wp_verify_nonce( $ppscc_nonce, self::NONCE_HIDE_BIRTHDAY ) ) {
					exit;
				}
				if ( ! current_user_can( self::CAP_MANAGE_OPTIONS ) ) {
					exit;
				}
				$this->update_user_meta();
				echo json_encode( [ 'error' => 0 ] );
				exit;
			} );
		}
	}

	public function validateUnauthorised() {
		echo json_encode( [ 'error' => 1 ] );
		exit;
	}

	public function validateAnswer( string $errors = '' ) {
		echo json_encode( [
			'error'  => 0,
			'errors' => $errors,
		] );
		exit;
	}

	public function update_user_meta() {
		if ( ! current_user_can( self::CAP_MANAGE_OPTIONS ) ) {
			return;
		}
		$current_user_id      = get_current_user_id();
		$value                = (array) ( get_user_meta( $current_user_id, self::USER_META_STRING, true ) ?? [] );
		$value[ date( 'Y' ) ] = 1;
		update_user_meta( $current_user_id, self::USER_META_STRING, $value );
	}

	/**
	 * This text is used for readme and header translation
	 */
	private function _text() {
		__( 'Post/Page specific custom CSS will allow you to add cascade stylesheet to specific posts/pages. It will give you special area in the post/page edit field to attach your CSS. It will also let you decide if this CSS has to be added in multi-page/post view (like archive posts) or only in a single view.', 'postpage-specific-custom-css' );
	}

}

new Plugin();
