<?php

namespace Phylax\WPPlugin\PPCustomCSS;

class ViewHelpers {

    /** @var array<string, mixed> */
    public array $settings;

    public string $option_name;

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct( array $settings, string $option_name ) {
        $this->settings    = $settings;
        $this->option_name = $option_name;
    }

    /**
     * @param array<int, string> $errors
     */
    public function textAreaField( string $id, string $key, string $value, array $errors = [], int $error_count = 0 ): void {
        unset( $errors, $error_count );
        ?>
        <div>
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

    /**
     * @param array<int, string> $errors
     */
    public function getErrorItems( array $errors, int $error_count ): string {
        $error_items = '';
        if ( $error_count > 0 ) {
            $error_items .= '<ul class="ppscc-css-errors">';
            foreach ( $errors as $error ) {
                if ( ! is_scalar( $error ) ) {
                    continue;
                }
                $error_items .= '<li>' . esc_html( (string) $error ) . '</li>';
            }
            $error_items .= '</ul>';
        }

        return $error_items;
    }

    public function printSafeAttr( string $option, string $key = '' ): void {
        echo esc_attr( $this->getSafeAttr( $option, $key ) );
    }

    public function getSafeAttr( string $option, string $key = '' ): string {
        $option = preg_replace( '/[^A-Za-z0-9_-]/', '', $option );
        $key    = preg_replace( '/[^A-Za-z0-9_-]/', '', $key );
        $option = is_string( $option ) ? $option : '';
        $key    = is_string( $key ) ? $key : '';
        $view   = $option;
        if ( '' !== $key ) {
            $view .= '[' . $key . ']';
        }

        return $view;
    }

    public function checkBoxField( string $key, string $label, bool $br = true, int $value = -1 ): void {
        if ( -1 === $value ) {
            $value = (int) ( $this->settings[ $key ] ?? 0 );
        }
        $field_id = 'item_' . $key;
        ?>
        <input type="hidden" name="<?php $this->printSafeAttr( $this->option_name, $key ); ?>" value="0">
        <label for="<?php echo esc_attr( $field_id ); ?>">
            <input
                    id="<?php echo esc_attr( $field_id ); ?>"
                    type="checkbox"
                    name="<?php $this->printSafeAttr( $this->option_name, $key ); ?>"
                    value="1"
                <?php echo ( 1 === $value ) ? 'checked="checked"' : ''; ?>
            > <?php echo esc_html( $label ); ?>
        </label>
        <?php
        if ( $br ) {
            echo "<br>\n";
        }
    }

    public function settingsInlineStyle(): void {
        ?>
        <style>
            .ppscc-css-errors {
                color: red;
                margin-bottom: 0.5rem;
                font-family: monospace;
            }

            #phylax_ppsccss_css_outer .phylax-ppsccss-css-error {
                background-color: rgba(255, 0, 0, 0.2);
                border-bottom: 1px solid red;
            }

            .ppsc_screen_wide {
                width: 100%;
            }
        </style>
        <?php
    }

    public function openFieldset( string $id = '' ): void {
        echo '<fieldset';
        if ( '' !== $id ) {
            echo ' id="' . esc_attr( 'ppscc_set_' . $id ) . '"';
        }
        echo '>';
    }

    public function closeFieldset(): void {
        echo "</fieldset>\n";
    }

    public function screenReaderLegend( string $content ): void {
        ?>
        <legend class="screen-reader-text">
            <span><?php echo esc_html( $content ); ?></span>
        </legend>
        <?php
    }

    public function printFieldDescription( string $content ): void {
        echo $this->getFieldDescription( $content );
    }

    public function getFieldDescription( string $content ): string {
        // Concatenation avoids heredoc interpolating "$..." inside translated strings.
        return '<p class="description">' . esc_html( $content ) . '</p>';
    }
}
