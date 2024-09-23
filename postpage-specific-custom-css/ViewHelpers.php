<?php

namespace Phylax\WPPlugin\PPCustomCSS;

class ViewHelpers {

	public $settings;
	public $option_name;

	public function __construct( array $settings, string $option_name ) {
		$this->settings    = $settings;
		$this->option_name = $option_name;
	}

	public function textAreaField( string $id, string $key, string $value, array $errors, int $error_count ) {
		$error_items = $this->getErrorItems( $errors, $error_count );
		?>
        <div class="<?php echo( ( $error_count !== 0 ) ? 'ppsc_validate_errors' : '' ); ?>">
			<?php echo $error_items; ?>
            <label class="ppsc_screen_wide">
            <textarea
                    id="<?php echo esc_attr( $id ); ?>"
                    name="<?php $this->printSafeAttr( $this->option_name, $key ); ?>"
                    class="ppsc_css_source large-text code"
                    rows="10"
                    cols="50"><?php echo esc_textarea( $value ); ?></textarea>
            </label>
        </div>
		<?php
	}

	public function getErrorItems( array $errors, int $error_count ): string {
		$error_items = '';
		if ( $error_count > 0 ) {
			$error_items .= '<ul class="ppscc-css-errors">';
			foreach ( $errors as $error ) {
				$error_items .= '<li>' . $error . '</li>';
			}
			$error_items .= '</ul>';
		}

		return $error_items;
	}

	public function printSafeAttr( string $option, string $key = '' ) {
		echo $this->getSafeAttr( $option, $key );
	}

	public function getSafeAttr( string $option, string $key = '' ): string {
		$option = preg_replace( "/[^A-Za-z0-9_-]/", '', $option );
		$key    = preg_replace( "/[^A-Za-z0-9_-]/", '', $key );
		$view   = $option;
		if ( '' !== $key ) {
			$view .= '[' . $key . ']';
		}

		return $view;
	}

	public function checkBoxField( string $key, string $label, bool $br = true, int $value = - 1 ) {
		if ( - 1 === $value ) {
			$value = (int) ( $this->settings[ $key ] ?? 0 );
		}
		?>
        <input type="hidden" name="<?php $this->printSafeAttr( $this->option_name, $key ); ?>" value="0">
        <label for="item_<?php echo $key; ?>">
            <input
                    id="item_<?php echo $key; ?>"
                    type="checkbox"
                    name="<?php $this->printSafeAttr( $this->option_name, $key ); ?>"
                    value="1"
				<?php echo( ( $value === 1 ) ? 'checked="checked"' : '' ); ?>
            > <?php echo $label; ?>
        </label>
		<?php
		if ( $br ) {
			echo '<br>' . "\n";
		}
	}

	public function settingsInlineStyle() {
		?>
        <style>
            .ppsc_screen_wide {
                width: 100%;
            }

            .ppscc-css-errors {
                background: #fff;
                border: #aa0000 solid 1px;
                color: #bb0000;
                box-shadow: rgba(170, 0, 0, 0.25) 0 0.0625em 0.0625em, rgba(170, 0, 0, 0.25) 0 0.125em 0.5em, rgba(255, 255, 255, 0.1) 0 0 0 1px inset;
                margin: 0 0 1rem 0;
                padding: .5rem 1rem;
            }

            .ppscc-css-errors li:last-child {
                margin-bottom: 0;
            }

            .ppsc_validate_errors textarea,
            .ppsc_validate_errors .CodeMirror {
                border: #aa0000 solid 1px;
                box-shadow: rgba(170, 0, 0, 0.25) 0 0.0625em 0.0625em, rgba(170, 0, 0, 0.25) 0 0.125em 0.5em, rgba(255, 255, 255, 0.1) 0 0 0 1px inset;
            }

            #ppscc-validating {
                margin: 0 0 1rem 0;
                display: none;
                align-items: center;
            }

            #ppscc-validating img {
                display: block;
                width: 16px;
                height: 16px;
                margin: auto auto auto 8px;
            }
        </style>
		<?php
	}

	public function openFieldset( string $id = '' ) {
		echo '<fieldset' . ( ( '' !== $id ) ? ' id="ppscc_set_' . $id . '"' : '' ) . '>';
	}

	public function closeFieldset() {
		echo "</fieldset>\n";
	}

	public function screenReaderLegend( string $content ) {
		?>
        <legend class="screen-reader-text">
            <span><?php echo $content; ?></span>
        </legend>
		<?php
	}

	public function printFieldDescription( string $content ) {
		echo $this->getFieldDescription( $content );
	}

	public function getFieldDescription( string $content ): string {
		return <<< FLDS
    <p class="description">
        $content
    </p>
FLDS;
	}

	public function birthday_script( string $nonce_action ) {
		?>
        <script>
          jQuery(function ($) {
            $(document).on('click', '#ppscc_birthday_notice > button.notice-dismiss', function () {
              $.post(ajaxurl, {
                url: ajaxurl,
                action: "ppscc_hide",
                ppscc_nonce: "<?php echo wp_create_nonce( $nonce_action ); ?>",
              }, function (response) {
              });
            });
            $(document).on('click', '.ppsc_show', function (event) {
              event.preventDefault();
              const id = '#acinfofor_' + $(this).data('ppsc');
              $('.ppsc_hide_all').hide(0);
              $(id).show(0);
            });
          });
        </script>
		<?php
	}

	public function birthday_style() {
		?>
        <style>
            .ppsc_hide_all {
                margin: 1rem 0;
                text-align: center;
            }

            .ppsc_info_line {
                text-align: center;
                margin: .1rem auto .1rem 1rem;
            }

            .ppsc_info_line span {
                display: inline-block;
                border: #ccc solid 1px;
                padding: .5rem 1rem;
                font-weight: bold;
                font-size: 16px;
            }

            .ppsc_hide_all {
                display: none;
            }

            .ppsc_story {
                text-align: center;
                font-weight: bold;
                letter-spacing: 1px;
            }
        </style>
		<?php
	}

}
