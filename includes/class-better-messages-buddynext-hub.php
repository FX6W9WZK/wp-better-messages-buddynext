<?php
/**
 * The /nachrichten/ route as a BuddyNext hub.
 *
 * The Better Messages inbox page is a plain theme page, so opening it
 * dropped members out of the community shell - no left rail, no right
 * sidebar, no mobile bottom bar. Registering the same path as a
 * BuddyNext hub (the pattern of the wp-buddynext-bg groups/events hubs)
 * makes BuddyNext render its shell around the messenger; the WordPress
 * page keeps existing as Better Messages' "Messages location", so every
 * URL the messenger emits (emails included) stays the same - the hub
 * rewrite simply takes over rendering for that path.
 *
 * @package BetterMessagesBuddyNext
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_BuddyNext_Hub' ) ) {

    class Better_Messages_BuddyNext_Hub
    {
        const HUB_KEY = 'bm-messages';

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_BuddyNext_Hub();
            }

            return $instance;
        }

        public function __construct()
        {
            if ( bm_buddynext_setting( 'buddynextMessengerHub', '1' ) !== '1' ) {
                return;
            }

            if ( ! class_exists( '\BuddyNext\Core\HubRegistry' ) || ! class_exists( '\BuddyNext\Core\HubDescriptor' ) ) {
                return;
            }

            add_action( 'buddynext_register_hubs', array( $this, 'register_hub' ) );
            add_filter( 'buddynext_template_locations', array( $this, 'template_locations' ) );
            add_filter( 'buddynext_document_title', array( $this, 'document_title' ), 10, 2 );
            add_action( 'init', array( $this, 'maybe_flush_rewrites' ), 99 );
        }

        /**
         * The path of the Better Messages inbox page, relative, no
         * slashes - the hub route mirrors wherever the owner points the
         * "Messages location" setting.
         *
         * @return string
         */
        public static function slug(): string
        {
            $url  = (string) Better_Messages()->functions->get_user_messages_url( 0 );
            $path = trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' );

            // Strip a possible home-path prefix (subdirectory installs).
            $home = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
            if ( '' !== $home && 0 === strpos( $path, $home . '/' ) ) {
                $path = substr( $path, strlen( $home ) + 1 );
            }

            return '' !== $path ? $path : 'messages-inbox';
        }

        /**
         * @param \BuddyNext\Core\HubRegistry $registry Hub registry.
         * @return void
         */
        public function register_hub( $registry ): void
        {
            $registry->register(
                new \BuddyNext\Core\HubDescriptor(
                    self::HUB_KEY,
                    'bmbn_hub_slug',
                    self::slug(),
                    'bmbn_page_messages',
                    _x( 'Messages', 'Navigation entry', 'better-messages-buddynext' ),
                    '[better_messages]',
                    null,
                    array( self::class, 'register_rules' ),
                    array( self::class, 'resolve_template' ),
                    array(),
                    false,
                    'Better Messages',
                    _x( 'The Better Messages inbox', 'Navigation entry description', 'better-messages-buddynext' ),
                    null,
                    false,
                    false
                )
            );
        }

        /**
         * One route: the inbox path renders this hub instead of the bare
         * theme page.
         *
         * @return void
         */
        public static function register_rules(): void
        {
            add_rewrite_rule(
                '^' . preg_quote( self::slug(), '/' ) . '/?$',
                'index.php?bn_hub=' . self::HUB_KEY,
                'top'
            );
        }

        /**
         * @param string $hub The bn_hub value.
         * @return string|null
         */
        public static function resolve_template( string $hub ): ?string
        {
            unset( $hub );

            return 'bm-messages/hub.php';
        }

        /**
         * @param array $locations Template locations.
         * @return array
         */
        public function template_locations( $locations )
        {
            $locations[] = BM_BUDDYNEXT_PATH . 'templates/buddynext';

            return $locations;
        }

        /**
         * @param string $title Resolved title.
         * @param string $hub   Active hub.
         * @return string
         */
        public function document_title( $title, $hub )
        {
            if ( self::HUB_KEY !== $hub ) {
                return $title;
            }

            return _x( 'Messages', 'Navigation entry', 'better-messages-buddynext' );
        }

        /**
         * New rewrite rules need one flush - once per plugin version and
         * once more whenever the inbox slug changes.
         *
         * @return void
         */
        public function maybe_flush_rewrites(): void
        {
            $want = BM_BUDDYNEXT_VERSION . '|' . self::slug();

            /**
             * The version|slug stamp alone is not enough: deactivating
             * any plugin can flush the stored rules while the stamp stays
             * valid, and on reactivation the guard then blocked the very
             * repair it exists for (seen live 2026-11-22). So also check
             * that the stored rules actually CONTAIN our route.
             */
            $rules    = get_option( 'rewrite_rules' );
            $has_rule = is_array( $rules ) && isset( $rules[ '^' . preg_quote( self::slug(), '/' ) . '/?$' ] );

            if ( $has_rule && get_option( 'bmbn_hub_rewrites' ) === $want ) {
                return;
            }

            flush_rewrite_rules( false );
            update_option( 'bmbn_hub_rewrites', $want, false );
        }
    }
}
