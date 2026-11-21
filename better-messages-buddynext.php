<?php
/**
 * Plugin Name:       Better Messages for BuddyNext
 * Plugin URI:        https://github.com/FX6W9WZK/wp-better-messages-buddynext
 * Description:       Connects Better Messages with the BuddyNext community plugin: automatic group chats for BuddyNext spaces, follower/connection based messaging rules, block list support and BuddyNext profile links inside the messenger.
 * Version:           0.1.4
 * Requires PHP:      8.2
 * Requires Plugins:  bp-better-messages, buddynext
 * Author:            Thomas Guldener
 * License:           GPL-2.0-or-later
 * Text Domain:       better-messages-buddynext
 *
 * This plugin is written in the exact shape of the built-in Better Messages
 * integrations (the files under bp-better-messages/addons/), so it can be
 * adopted into Better Messages core as addons/buddynext.php with minimal
 * changes: the two classes below carry no loader logic of their own, all
 * options are read from Better_Messages()->settings with the same
 * key-plus-default pattern core uses, and nothing depends on this wrapper
 * file except the bootstrap at the bottom.
 *
 * @package BetterMessagesBuddyNext
 */

defined( 'ABSPATH' ) || exit;

define( 'BM_BUDDYNEXT_VERSION', '0.1.4' );
define( 'BM_BUDDYNEXT_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Self-hosted updates from GitHub Releases: the plugin is not listed on
 * WordPress.org (yet), so without this every new version would be a manual
 * zip upload. Points at the release ASSET built by the release script, not
 * GitHub's source zipball, whose internal folder name would mismatch the
 * installed plugin folder. Registered outside bm_buddynext_init() on
 * purpose - updates must flow even while Better Messages or BuddyNext are
 * deactivated.
 */
require_once BM_BUDDYNEXT_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';

\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
    'https://github.com/FX6W9WZK/wp-better-messages-buddynext/',
    __FILE__,
    'better-messages-buddynext'
)->getVcsApi()->enableReleaseAssets( '/^better-messages-buddynext\.zip$/' );

register_deactivation_hook( __FILE__, static function (): void {
    wp_clear_scheduled_hook( 'bm_buddynext_daily_sync' );
} );

/**
 * Read an integration option from Better Messages' own settings store, with
 * the integration's default when the key is not (yet) present there.
 *
 * Better Messages keeps every addon option as a key in
 * Better_Messages()->settings whose default lives in core's options.php.
 * Until this integration ships inside core, its keys have no UI and no
 * registered default - this helper supplies the defaults, and the moment
 * core adopts the integration and adds the fields, the stored values win
 * without any migration.
 *
 * @param string $key     Settings key.
 * @param string $default Default value.
 * @return string
 */
function bm_buddynext_setting( string $key, string $default ): string {
    $settings = Better_Messages()->settings;

    if ( isset( $settings[ $key ] ) && '' !== $settings[ $key ] ) {
        return (string) $settings[ $key ];
    }

    return $default;
}

add_action( 'plugins_loaded', 'bm_buddynext_init', 30 );

/**
 * Boot the integration once both plugins are present.
 *
 * @return void
 */
function bm_buddynext_init(): void {
    if ( ! function_exists( 'Better_Messages' ) || ! function_exists( 'buddynext_service' ) ) {
        return;
    }

    load_plugin_textdomain( 'better-messages-buddynext', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    require_once BM_BUDDYNEXT_PATH . 'includes/class-better-messages-buddynext.php';
    require_once BM_BUDDYNEXT_PATH . 'includes/class-better-messages-buddynext-groups.php';

    Better_Messages_BuddyNext::instance();
    Better_Messages_BuddyNext_Groups::instance();
}
