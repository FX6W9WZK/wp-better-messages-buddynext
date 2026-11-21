<?php
/**
 * BuddyNext navigation for Better Messages.
 *
 * The main entry is a tab registered on `buddynext_nav_tabs`: that is the
 * catalogue the backend nav editor (Settings > Navigation) renders, so the
 * owner can rename, reorder, hide or gate it there like any entry - and
 * BuddyNext's NavOverrides bridges every URL-carrying tab from that filter
 * onto the left rail by itself. (BuddyNext's own Messages entry cannot be
 * used: the editor couples it to the WPMediaVerse engine switch.)
 *
 * On top of that: the unread badge on the bridged rail item (the bridge
 * carries no badge), a row in the account dropdown catalogue (which also
 * feeds the menu metabox and the nav editor's account section) and a row
 * in the mobile bar's sheet.
 *
 * The profile "Message" button has no core seam yet (profile-actions.php
 * hardcodes PageRouter::messages_url()); a filterable URL has been
 * drafted as an upstream request in docs/upstream-issue-buddynext-seams.md.
 *
 * @package BetterMessagesBuddyNext
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_BuddyNext_Nav' ) ) {

    class Better_Messages_BuddyNext_Nav
    {
        const NAV_KEY = 'bm-messages';

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

            add_filter( 'buddynext_nav_tabs', array( $this, 'nav_tab' ) );
            add_filter( 'buddynext_rail_items', array( $this, 'rail_badge' ), 30, 2 );
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
         * Register the tab in the backend nav editor's catalogue. A tab
         * from this filter that carries a `url` is bridged onto the left
         * rail by NavOverrides::apply_rail, deduped and capability-gated,
         * so this one registration covers backend management AND the rail.
         *
         * @param array $tabs Catalogue entries.
         * @return array
         */
        public function nav_tab( $tabs )
        {
            $tabs   = (array) $tabs;

            foreach ( $tabs as $tab ) {
                if ( ( $tab['slug'] ?? '' ) === self::NAV_KEY ) {
                    return $tabs; // Idempotent: the filter runs in admin and on the rail bridge.
                }
            }

            $tabs[] = array(
                'slug'        => self::NAV_KEY,
                'label'       => _x( 'Messages', 'Navigation entry', 'better-messages-buddynext' ),
                'icon'        => 'message-circle',
                'order'       => 55,
                'url'         => $this->inbox_url(),
                'capability'  => 'read',
                'custom'      => true,
                'description' => _x( 'The Better Messages inbox', 'Navigation entry description', 'better-messages-buddynext' ),
                'integration' => 'Better Messages',
            );

            return $tabs;
        }

        /**
         * The rail bridge carries no badge - lay the unread count onto the
         * bridged item after NavOverrides ran (it filters at 20).
         *
         * @param array  $items Rail items.
         * @param string $hub   Current hub slug.
         * @return array
         */
        public function rail_badge( $items, $hub )
        {
            $unread = 0;

            foreach ( (array) $items as $i => $item ) {
                if ( ( $item['key'] ?? '' ) === self::NAV_KEY ) {
                    if ( 0 === $unread ) {
                        $unread = $this->unread_count();
                    }
                    $items[ $i ]['badge'] = $unread;
                }
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
         * slot); the site owner can move it in the nav editor. Same key
         * as the main tab, so the editor's mobile overrides land on it.
         *
         * @param array  $items  Bar item definitions.
         * @param string $active Active mobile-bar key.
         * @return array
         */
        public function mobile_items( $items, $active = '' )
        {
            foreach ( $items as $item ) {
                if ( ( $item['key'] ?? '' ) === self::NAV_KEY ) {
                    return $items;
                }
            }

            $unread = $this->unread_count();

            $items[] = array(
                'key'         => self::NAV_KEY,
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
