<?php
/**
 * BuddyNext navigation for Better Messages.
 *
 * The rail entry is an item of its own (key `bm-messages`) appended on
 * `buddynext_rail_items` at priority 11 - before the owner's overrides
 * (priority 20) and before the backend nav editor's catalogue capture
 * (priority 19), which is how the editor lists third-party rail items as
 * manageable rows. BuddyNext's own Messages entry cannot be reused: core
 * couples it to the WPMediaVerse engine switch, in the editor too.
 *
 * Icon overrides saved in the editor are applied by reading the override
 * options directly (core never lays the icon back onto rail items), the
 * same way the wp-buddynext-bg hubs do it. The account dropdown row rides
 * the `buddynext_user_links` catalogue, the mobile row the mobile filter,
 * where the editor's row position decides bar slot versus More sheet.
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

            add_filter( 'buddynext_rail_items', array( $this, 'rail_item' ), 11, 2 );
            add_filter( 'buddynext_user_links', array( $this, 'user_links' ), 10, 2 );
            add_filter( 'buddynext_mobile_nav_items', array( $this, 'mobile_items' ), 10, 2 );
            add_action( 'admin_init', array( $this, 'seed_mobile_editor_row' ) );
            add_action( 'template_redirect', array( $this, 'redirect_legacy_hub' ) );

            /**
             * The header dropdown builds its rows from the UserLinks
             * catalogue but then passes them through this filter - and a
             * theme may return a curated list of its own there (BuddyX
             * Pro does), which silently drops every catalogue addition.
             * Late priority, so the Messages row survives whatever list
             * the theme curates.
             */
            add_filter( 'buddynext_header_user_menu_links', array( $this, 'header_menu_links' ), 50, 2 );
        }

        /**
         * Ensure the Messages row is part of the header dropdown, after
         * the connections row when one is there, else appended.
         *
         * @param array $links   Quick links ({label,url,icon} rows).
         * @param int   $user_id Member ID.
         * @return array
         */
        public function header_menu_links( $links, $user_id )
        {
            $links = (array) $links;
            $url   = $this->inbox_url();

            if ( '' === $url ) {
                return $links;
            }

            foreach ( $links as $link ) {
                if ( is_array( $link ) && ( $link['url'] ?? '' ) === $url ) {
                    return $links;
                }
            }

            $row = array(
                'label' => _x( 'Messages', 'Navigation entry', 'better-messages-buddynext' ),
                'url'   => $url,
                'icon'  => 'message-circle',
            );

            $position = count( $links );

            foreach ( $links as $index => $link ) {
                if ( is_array( $link ) && false !== strpos( (string) ( $link['url'] ?? '' ), '/connections/' ) ) {
                    $position = $index + 1;
                    break;
                }
            }

            array_splice( $links, $position, 0, array( $row ) );

            return $links;
        }

        /**
         * BuddyNext's own /messages/ hub only shows a "messaging is
         * disabled" card while the WPMediaVerse engine is off - but old
         * bookmarks, stored notifications and third-party links still
         * point there. Send them on to the Better Messages inbox. Only
         * while the core messenger really is off: a site running both
         * keeps its hub.
         *
         * @return void
         */
        public function redirect_legacy_hub()
        {
            if ( get_query_var( 'bn_hub' ) !== 'messages' ) {
                return;
            }

            if ( class_exists( '\BuddyNext\Messages\MessagesData' )
                && \BuddyNext\Messages\MessagesData::entry_enabled() ) {
                return;
            }

            $url = $this->inbox_url();

            if ( '' !== $url ) {
                wp_safe_redirect( $url, 301 );
                exit;
            }
        }

        /**
         * The nav editor's MOBILE catalogue is the static five-slot list
         * plus stored override rows - it captures nothing from the mobile
         * filter, so without a stored row our entry is invisible there
         * and unmanageable. Seed one `custom` row once (empty URL: our
         * registered item occupies the key, and with this plugin
         * deactivated an empty URL renders nothing instead of a dead
         * link). A row that already exists is the owner's and is never
         * touched. Order 70: in the More sheet, after the hub seeds
         * (60/65); dragging it above Profile (50) gives it a bar slot.
         *
         * @return void
         */
        public function seed_mobile_editor_row()
        {
            $option    = 'buddynext_nav_overrides_mobile';
            $overrides = (array) get_option( $option, array() );

            if ( isset( $overrides[ self::NAV_KEY ] ) ) {
                return;
            }

            $overrides[ self::NAV_KEY ] = array(
                'custom'      => true,
                'label'       => _x( 'Messages', 'Navigation entry', 'better-messages-buddynext' ),
                'description' => _x( 'The Better Messages inbox', 'Navigation entry description', 'better-messages-buddynext' ),
                'order'       => 70,
                'icon'        => '',
                'capability'  => 'read',
                'url'         => '',
            );

            update_option( $option, $overrides );
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
         * The icon the nav editor saved for a key, else the default - core
         * applies label/order/hidden overrides onto rail and mobile items
         * but never the icon, so integrations read it back themselves.
         *
         * @param string $key          Item key.
         * @param string $default_icon Fallback icon.
         * @param string $option       Overrides option name.
         * @return string
         */
        public static function nav_icon( string $key, string $default_icon, string $option = 'buddynext_nav_overrides' ): string
        {
            $stored = get_option( $option, array() );

            if ( ! is_array( $stored ) || ! isset( $stored[ $key ] ) || ! is_array( $stored[ $key ] ) ) {
                return $default_icon;
            }

            $icon = sanitize_key( (string) ( $stored[ $key ]['icon'] ?? '' ) );

            return '' !== $icon && 'tab-custom' !== $icon ? $icon : $default_icon;
        }

        /**
         * Whether the mobile entry belongs in the "More" sheet (true) or
         * in the bottom bar (false): the row's position in the editor's
         * mobile list decides - dragged above the locked Profile anchor
         * (order 50) it takes a bar slot.
         *
         * @param string $key Item key.
         * @return bool
         */
        public static function mobile_overflow( string $key ): bool
        {
            $stored = get_option( 'buddynext_nav_overrides_mobile', array() );

            if ( ! is_array( $stored ) || ! isset( $stored[ $key ] ) || ! is_array( $stored[ $key ] ) || ! isset( $stored[ $key ]['order'] ) ) {
                return true;
            }

            return (int) $stored[ $key ]['order'] >= 50;
        }

        /**
         * Our rail entry, placed right after Notifications. Priority 11
         * keeps it visible to the nav editor's catalogue capture (19) and
         * under the owner's overrides (20).
         *
         * @param array  $items Rail items.
         * @param string $hub   Current hub slug.
         * @return array
         */
        public function rail_item( $items, $hub )
        {
            $items = (array) $items;

            foreach ( $items as $item ) {
                if ( ( $item['key'] ?? '' ) === self::NAV_KEY ) {
                    return $items;
                }
            }

            $entry = array(
                'key'   => self::NAV_KEY,
                'url'   => $this->inbox_url(),
                'label' => _x( 'Messages', 'Navigation entry', 'better-messages-buddynext' ),
                'icon'  => self::nav_icon( self::NAV_KEY, 'message-circle' ),
                'badge' => $this->unread_count(),
                'show'  => is_user_logged_in(),
            );

            $position = count( $items );

            foreach ( $items as $index => $item ) {
                if ( 'notifications' === ( $item['key'] ?? '' ) ) {
                    $position = $index + 1;
                    break;
                }
            }

            array_splice( $items, $position, 0, array( $entry ) );

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
         * Mobile bar entry: registering our key here lets the editor's
         * mobile overrides land on it, and its row position decides bar
         * slot versus More sheet (mobile_overflow).
         *
         * @param array  $items  Bar item definitions.
         * @param string $active Active mobile-bar key.
         * @return array
         */
        public function mobile_items( $items, $active = '' )
        {
            $items = (array) $items;

            foreach ( $items as $item ) {
                if ( ( $item['key'] ?? '' ) === self::NAV_KEY ) {
                    return $items;
                }
            }

            $unread = $this->unread_count();

            $items[] = array(
                'key'         => self::NAV_KEY,
                'url'         => $this->inbox_url(),
                'icon'        => self::nav_icon( self::NAV_KEY, self::nav_icon( self::NAV_KEY, 'message-circle' ), 'buddynext_nav_overrides_mobile' ),
                'label'       => _x( 'Messages', 'Navigation entry', 'better-messages-buddynext' ),
                'show'        => is_user_logged_in(),
                'badge'       => $unread > 0,
                'badge_count' => $unread,
                'overflow'    => self::mobile_overflow( self::NAV_KEY ),
            );

            return $items;
        }
    }
}
