<?php
/**
 * BuddyNext navigation for Better Messages: the Messages entry in the
 * left rail, the account dropdown (which also feeds BuddyNext's menu
 * metabox and nav editor) and the mobile bar's More sheet all point at
 * the Better Messages inbox, with the Better Messages unread count as
 * the badge.
 *
 * BuddyNext's own entries hide themselves when the WPMediaVerse
 * messaging engine is off (MessagesData::entry_enabled()), which is the
 * recommended state alongside this integration - these entries render
 * regardless of that switch.
 *
 * The profile "Message" button has no core seam yet (profile-actions.php
 * hardcodes PageRouter::messages_url()); it disappears with the
 * WPMediaVerse switch and a filterable URL has been requested upstream.
 *
 * @package BetterMessagesBuddyNext
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_BuddyNext_Nav' ) ) {

    class Better_Messages_BuddyNext_Nav
    {
        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_BuddyNext_Nav();
            }

            return $instance;
        }

        public function __construct()
        {
            if ( bm_buddynext_setting( 'buddynextNavEntries', '1' ) !== '1' ) {
                return;
            }

            add_filter( 'buddynext_rail_items', array( $this, 'rail_items' ), 10, 2 );
            add_filter( 'buddynext_user_links', array( $this, 'user_links' ), 10, 2 );
            add_filter( 'buddynext_mobile_nav_items', array( $this, 'mobile_items' ), 10, 2 );
        }

        /**
         * The Better Messages inbox URL for the current user - the page
         * chosen in Better Messages' "Messages location" setting, passed
         * through the `bp_better_messages_page` filter like every link
         * Better Messages itself emits (emails included).
         *
         * @return string
         */
        public function inbox_url(): string
        {
            return (string) Better_Messages()->functions->get_user_messages_url(
                Better_Messages()->functions->get_current_user_id()
            );
        }

        /**
         * Unread conversations of the current user, for badges.
         *
         * @return int
         */
        public function unread_count(): int
        {
            $user_id = Better_Messages()->functions->get_current_user_id();

            if ( $user_id <= 0 ) {
                return 0;
            }

            return (int) Better_Messages()->functions->get_user_unread_count( $user_id );
        }

        /**
         * Left rail: re-point BuddyNext's own Messages item at the Better
         * Messages inbox (it ships hidden when WPMediaVerse messaging is
         * off), or append one when a future core version stops seeding it.
         *
         * @param array  $items Rail items.
         * @param string $hub   Current hub slug.
         * @return array
         */
        public function rail_items( $items, $hub )
        {
            $logged_in = is_user_logged_in();
            $found     = false;

            foreach ( $items as $i => $item ) {
                if ( ( $item['key'] ?? '' ) === 'messages' ) {
                    $items[ $i ]['url']   = $this->inbox_url();
                    $items[ $i ]['badge'] = $this->unread_count();
                    $items[ $i ]['show']  = $logged_in;
                    $found                = true;
                }
            }

            if ( ! $found ) {
                $items[] = array(
                    'key'   => 'messages',
                    'label' => _x( 'Messages', 'Navigation entry', 'better-messages-buddynext' ),
                    'url'   => $this->inbox_url(),
                    'icon'  => 'message-circle',
                    'badge' => $this->unread_count(),
                    'show'  => $logged_in,
                );
            }

            return $items;
        }

        /**
         * Account dropdown catalogue (also feeds the menu metabox and the
         * nav editor's account section). BuddyNext removes its own
         * '#bn-messages' row when WPMediaVerse messaging is off, so this
         * adds a row of its own with an explicit URL - custom rows with a
         * `url` are resolved as-is.
         *
         * @param array $items     Catalogue rows.
         * @param bool  $for_admin Admin catalogue (keeps hidden rows).
         * @return array
         */
        public function user_links( $items, $for_admin = false )
        {
            foreach ( $items as $item ) {
                if ( ( $item['token'] ?? '' ) === '#bmbn-messages' ) {
                    return $items; // Idempotent: the filter may run more than once.
                }
            }

            $row = array(
                'token'      => '#bmbn-messages',
                'label'      => _x( 'Messages', 'Navigation entry', 'better-messages-buddynext' ),
                'icon'       => 'message-circle',
                'visibility' => 'loggedin',
                'url'        => $this->inbox_url(),
            );

            // Right after BuddyNext's own (hidden) messages slot: before
            // notifications when present, else appended.
            $out      = array();
            $inserted = false;

            foreach ( $items as $item ) {
                if ( ! $inserted && ( $item['token'] ?? '' ) === '#bn-notifications' ) {
                    $out[]    = $row;
                    $inserted = true;
                }
                $out[] = $item;
            }

            if ( ! $inserted ) {
                $out[] = $row;
            }

            return $out;
        }

        /**
         * Mobile bottom bar: a Messages row with the unread badge. Order
         * 70 lands it in the More sheet (orders below 50 take a bar
         * slot); the site owner can move it in the nav editor.
         *
         * @param array  $items  Bar item definitions.
         * @param string $active Active mobile-bar key.
         * @return array
         */
        public function mobile_items( $items, $active = '' )
        {
            foreach ( $items as $item ) {
                if ( ( $item['key'] ?? '' ) === 'bmbn-messages' ) {
                    return $items;
                }
            }

            $unread = $this->unread_count();

            $items[] = array(
                'key'         => 'bmbn-messages',
                'url'         => $this->inbox_url(),
                'icon'        => 'message-circle',
                'label'       => _x( 'Messages', 'Navigation entry', 'better-messages-buddynext' ),
                'show'        => is_user_logged_in(),
                'badge'       => $unread > 0,
                'badge_count' => $unread,
                'order'       => 70,
            );

            return $items;
        }
    }
}
