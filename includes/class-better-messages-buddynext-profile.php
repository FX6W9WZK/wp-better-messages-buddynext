<?php
/**
 * The profile "Message" button, pointing at Better Messages.
 *
 * BuddyNext's own button (templates/parts/profile-actions.php) is
 * hard-wired to the WPMediaVerse engine: it gates on
 * MessagesData::entry_enabled() and links PageRouter::messages_url(),
 * with no filter on either - and template overrides cannot help, since
 * the template loader searches plugin locations LAST. Until the
 * upstream seams land (docs/upstream-issue-buddynext-seams.md), the
 * button is re-added around the hero instead: the hero part's args
 * filter hands us the profile context, and a small script slots a
 * house-anatomy button (bn-btn, secondary, sm - the exact dress of the
 * original) into the actions row.
 *
 * Shown under the same no-dead-ends rule the original follows: only to
 * logged-in visitors on someone else's profile who may actually write
 * to them (administrators, or a connection / follow relation per the
 * integration's messaging rule).
 *
 * @package BetterMessagesBuddyNext
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_BuddyNext_Profile' ) ) {

    class Better_Messages_BuddyNext_Profile
    {
        /**
         * Context captured from the hero part.
         *
         * @var array{uid:int,viewer:int,is_owner:bool}|null
         */
        private $hero = null;

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_BuddyNext_Profile();
            }

            return $instance;
        }

        public function __construct()
        {
            if ( bm_buddynext_setting( 'buddynextProfileButton', '1' ) !== '1' ) {
                return;
            }

            add_filter( 'buddynext_part_profile_hero_args', array( $this, 'capture_hero' ) );
            add_action( 'buddynext_part_profile_hero_after', array( $this, 'print_button' ) );
        }

        /**
         * @param array $args Hero part args.
         * @return array Unchanged.
         */
        public function capture_hero( $args )
        {
            $this->hero = array(
                'uid'      => (int) ( $args['profile_user_id'] ?? 0 ),
                'viewer'   => (int) ( $args['viewer_id'] ?? get_current_user_id() ),
                'is_owner' => ! empty( $args['is_owner'] ),
            );

            return $args;
        }

        /**
         * Whether the viewer may message the profiled member - the same
         * predicate the integration's messaging rule enforces, so the
         * button never offers a refused action.
         *
         * @param int $viewer Viewer user ID.
         * @param int $uid    Profiled user ID.
         * @return bool
         */
        private function may_message( int $viewer, int $uid ): bool
        {
            if ( user_can( $viewer, 'manage_options' ) || user_can( $uid, 'manage_options' ) ) {
                return true;
            }

            if ( bm_buddynext_setting( 'buddynextOnlyFollowersMode', '1' ) !== '1' ) {
                return true;
            }

            return Better_Messages_BuddyNext::instance()->is_friends( false, $viewer, $uid );
        }

        /**
         * Print the button markup and the one-line script that slots it
         * into the actions row the hero just rendered.
         *
         * @return void
         */
        public function print_button()
        {
            $hero       = $this->hero;
            $this->hero = null;

            if ( null === $hero || $hero['is_owner'] || $hero['uid'] <= 0 || $hero['viewer'] <= 0 ) {
                return;
            }

            if ( ! $this->may_message( $hero['viewer'], $hero['uid'] ) ) {
                return;
            }

            $url = (string) Better_Messages()->functions->create_conversation_link( $hero['uid'], '', '', true, true );

            if ( '' === $url ) {
                return;
            }

            ob_start();
            if ( function_exists( 'buddynext_icon' ) ) {
                buddynext_icon( 'message-circle' );
            }
            $icon = ob_get_clean();

            $button = '<a href="' . esc_url( $url ) . '" class="bn-btn bn-pf-actions__wide-only bmbn-profile-message" data-variant="secondary" data-size="sm">'
                . $icon
                . '<span>' . esc_html( _x( 'Message', 'Profile action', 'better-messages-buddynext' ) ) . '</span></a>';

            ?>
            <script>
            ( function () {
                var actions = document.querySelector( '.bn-pf-actions' );
                if ( ! actions || actions.querySelector( '.bmbn-profile-message' ) ) {
                    return;
                }
                actions.insertAdjacentHTML( 'beforeend', <?php echo wp_json_encode( $button ); ?> );
            } )();
            </script>
            <?php
        }
    }
}
