<?php

namespace Phylax\WPPlugin\PPCustomCSS;

class ViewHelpers {

    public $settings;
    public $option_name;

    public function __construct( array $settings, string $option_name ) {
        $this->settings    = $settings;
        $this->option_name = $option_name;
    }

    public function textAreaField( string $id, string $key, string $value, array $errors = [], int $error_count = 0 ) {
        ?>
        <div>
            <label class="ppsc_screen_wide">
            <textarea
                    id="<?php
                    echo esc_attr( $id ); ?>"
                    name="<?php
                    $this->printSafeAttr( $this->option_name, $key ); ?>"
                    class="ppsc_css_source large-text code"
                    rows="10"
                    cols="50"><?php
                echo esc_textarea( $value ); ?></textarea>
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
        <input type="hidden" name="<?php
        $this->printSafeAttr( $this->option_name, $key ); ?>" value="0">
        <label for="item_<?php
        echo $key; ?>">
            <input
                    id="item_<?php
                    echo $key; ?>"
                    type="checkbox"
                    name="<?php
                    $this->printSafeAttr( $this->option_name, $key ); ?>"
                    value="1"
                <?php
                echo( ( $value === 1 ) ? 'checked="checked"' : '' ); ?>
            > <?php
            echo $label; ?>
        </label>
        <?php
        if ( $br ) {
            echo '<br>' . "\n";
        }
    }

    public function settingsInlineStyle() {
        ?>
        <style>
            .css-error-message {
                color: red;
                margin-bottom: 0.5rem;
                font-family: monospace;
            }

            .css-error {
                background-color: rgba(255, 0, 0, 0.2);
                border-bottom: 1px solid red;
            }

            .ppsc_screen_wide {
                width: 100%;
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
            <span><?php
                echo $content; ?></span>
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
}
