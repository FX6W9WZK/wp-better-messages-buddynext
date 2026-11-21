<?php
/**
 * Layout plumbing for the messenger inside the BuddyNext/BuddyX shell.
 *
 * Deliberately NO color work: the messenger's palette is the site
 * owner's business, managed in Better Messages -> Settings -> Appearance
 * (color schemes with light and dark values). This class only wires the
 * two things a color scheme cannot express:
 *
 * - Mode coupling. Better Messages decides dark mode by
 *   prefers-color-scheme or its own body classes, never by BuddyX's
 *   html[data-bx-mode] toggle - so the messenger would follow the
 *   visitor's OS instead of the site's own light/dark switch. A tiny
 *   script mirrors the attribute onto body.bm-messages-dark/-light
 *   (an explicit class always wins over BM's media query), live via
 *   MutationObserver when the visitor flips the toggle.
 *
 * - The doubled page header. The theme prints the page title and
 *   breadcrumbs above the messenger, which carries its own header -
 *   hidden on any page whose content is the messenger.
 *
 * @package BetterMessagesBuddyNext
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_BuddyNext_Skin' ) ) {

    class Better_Messages_BuddyNext_Skin
    {
        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_BuddyNext_Skin();
            }

            return $instance;
        }

        public function __construct()
        {
            if ( bm_buddynext_setting( 'buddynextSkin', '1' ) !== '1' ) {
                return;
            }

            add_filter( 'better_messages_css_customizations', array( $this, 'rules' ) );
            add_action( 'wp_body_open', array( $this, 'mode_sync_script' ), 1 );
        }

        /**
         * Keep Better Messages' own dark mode in step with the site's
         * BuddyX toggle.
         *
         * @return void
         */
        public function mode_sync_script()
        {
            ?>
            <script>
            ( function () {
                var root = document.documentElement;
                function sync() {
                    if ( ! document.body ) {
                        return;
                    }
                    var dark = root.getAttribute( 'data-bx-mode' ) === 'dark';
                    document.body.classList.toggle( 'bm-messages-dark', dark );
                    document.body.classList.toggle( 'bm-messages-light', ! dark );
                }
                sync();
                new MutationObserver( sync ).observe( root, { attributes: true, attributeFilter: [ 'data-bx-mode' ] } );
            } )();
            </script>
            <?php
        }

        /**
         * The theme's page furniture doubles the messenger's own header:
         * the sub-header (page title + breadcrumbs) disappears on any
         * page whose content is the messenger.
         *
         * @param array $rules Rule strings.
         * @return array
         */
        public function rules( $rules )
        {
            $rules   = (array) $rules;
            $rules[] = 'body:has(.bm-wrap-main) .site-sub-header{display:none;}';

            return $rules;
        }
    }
}
