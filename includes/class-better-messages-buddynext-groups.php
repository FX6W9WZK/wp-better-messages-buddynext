<?php
/**
 * Group chats for BuddyNext spaces: every space automatically gets a
 * Better Messages group thread, and the thread's participants follow
 * the space's active members. Members see the chat in their inbox like
 * any conversation; title, image and link always come live from the
 * space.
 *
 * Mirrors the shape of bp-better-messages/addons/peepso-groups.php.
 *
 * @package BetterMessagesBuddyNext
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_BuddyNext_Groups' ) ) {

    class Better_Messages_BuddyNext_Groups
    {
        const THREAD_META_KEY = 'buddynext_space_id';
        const SPACE_META_KEY  = 'better_messages';

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_BuddyNext_Groups();
            }

            return $instance;
        }

        public function __construct()
        {
            if ( bm_buddynext_setting( 'buddynextEnableGroups', '1' ) === '1' ) {
                add_filter( 'better_messages_groups_active', '__return_true' );
                add_filter( 'better_messages_is_valid_group', array( $this, 'is_valid_group' ), 10, 2 );
                add_filter( 'better_messages_user_has_groups', array( $this, 'user_has_groups' ), 10, 2 );
                add_filter( 'better_messages_get_groups', array( $this, 'get_groups' ), 10, 2 );
                add_filter( 'better_messages_has_access_to_group_chat', array( $this, 'has_access_to_group_chat' ), 10, 3 );
                add_filter( 'better_messages_can_send_message', array( $this, 'can_reply_to_group_chat' ), 10, 3 );

                add_filter( 'better_messages_thread_title', array( $this, 'group_thread_title' ), 10, 3 );
                add_filter( 'better_messages_thread_image', array( $this, 'group_thread_image' ), 10, 3 );
                add_filter( 'better_messages_thread_url', array( $this, 'group_thread_url' ), 10, 3 );

                /**
                 * Membership follows BuddyNext. Every path that changes a
                 * space's member list re-syncs the thread participants;
                 * the sync is idempotent, so overlapping events are
                 * harmless.
                 */
                add_action( 'buddynext_space_member_joined', array( $this, 'on_membership_changed' ), 10, 1 );
                add_action( 'buddynext_space_member_left', array( $this, 'on_membership_changed' ), 10, 1 );
                add_action( 'buddynext_space_member_removed', array( $this, 'on_membership_changed' ), 10, 1 );
                add_action( 'buddynext_space_user_banned', array( $this, 'on_membership_changed' ), 10, 1 );
                add_action( 'buddynext_space_user_unbanned', array( $this, 'on_membership_changed' ), 10, 1 );
                add_action( 'buddynext_space_ownership_transferred', array( $this, 'on_membership_changed' ), 10, 1 );

                /**
                 * A new space gets its chat at once, a deleted space
                 * takes its chat with it (BuddyNext deletes the space's
                 * posts the same way).
                 */
                add_action( 'buddynext_space_created', array( $this, 'on_space_created' ), 10, 2 );
                add_action( 'buddynext_space_deleted', array( $this, 'on_space_deleted' ), 10, 2 );

                /**
                 * Spaces that existed before this integration get their
                 * chats through a one-time backfill, and a daily self-heal
                 * pass keeps everything consistent even when a hook was
                 * missed - e.g. a space-delete request killed mid-flight
                 * by a PHP timeout leaves an orphaned thread, which the
                 * next pass erases (seen live on 2026-11-21, when a
                 * vulnerability scanner saturated the worker pool and a
                 * delete died between the space queries and this hook).
                 */
                add_action( 'init', array( $this, 'maybe_backfill' ), 50 );
                add_action( 'init', array( $this, 'schedule_daily_sync' ), 50 );
                add_action( 'bm_buddynext_daily_sync', array( $this, 'daily_sync' ) );
                add_action( 'admin_post_bm_buddynext_sync', array( $this, 'handle_manual_sync' ) );
                add_filter( 'plugin_action_links_better-messages-buddynext/better-messages-buddynext.php', array( $this, 'action_links' ) );

                if ( bm_buddynext_setting( 'buddynextEnableGroupsFiles', '1' ) === '0' ) {
                    add_action( 'bp_better_messages_user_can_upload_files', array( $this, 'disable_upload_files' ), 10, 3 );
                }
            }

            add_filter( 'better_messages_bulk_get_all_groups', array( $this, 'bulk_get_all_groups' ) );
            add_filter( 'better_messages_bulk_get_group_members', array( $this, 'bulk_get_group_members' ), 10, 3 );
        }

        /** ------------------------------------------------------------
         *  Space lookups
         *  ------------------------------------------------------------ */

        /**
         * The space row, or null when it does not exist or is archived.
         *
         * @param int $space_id Space id.
         * @return array|null
         */
        public function get_space( $space_id )
        {
            $space = buddynext_service( 'spaces' )->get( (int) $space_id );

            if ( ! is_array( $space ) || empty( $space['id'] ) ) {
                return null;
            }

            if ( ! empty( $space['is_archived'] ) ) {
                return null;
            }

            return $space;
        }

        public function is_space_messages_enabled( $space_id = false )
        {
            if ( bm_buddynext_setting( 'buddynextEnableGroups', '1' ) !== '1' ) {
                return 'disabled';
            }

            $messages = 'enabled';

            if ( ! ! $space_id ) {
                $meta = get_space_meta( (int) $space_id, self::SPACE_META_KEY, true );

                if ( $meta === 'disabled' ) {
                    $messages = 'disabled';
                }
            }

            return $messages;
        }

        /**
         * Active members of a space.
         *
         * @param int $space_id Space id.
         * @return array<int,string> User ids.
         */
        public function get_space_members( $space_id )
        {
            global $wpdb;

            return $wpdb->get_col( $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->prefix}bn_space_members
                 WHERE space_id = %d AND status = 'active'",
                $space_id
            ) );
        }

        public function user_has_access( $space_id, $user_id )
        {
            if ( (int) $user_id <= 0 ) {
                return false;
            }

            global $wpdb;

            $status = $wpdb->get_var( $wpdb->prepare(
                "SELECT status FROM {$wpdb->prefix}bn_space_members
                 WHERE space_id = %d AND user_id = %d",
                $space_id, $user_id
            ) );

            return $status === 'active';
        }

        /** ------------------------------------------------------------
         *  Group provider
         *  ------------------------------------------------------------ */

        /**
         * BM calls this with the THREAD id (see get_thread_type()), not
         * a group id - resolve through the thread meta. Mistaking the
         * parameter for a space id made the validation a coin flip on
         * id collisions: thread 5 passed because space 5 happened to
         * exist, every other space chat was downgraded to a plain
         * conversation (seen live 2026-11-22).
         *
         * @param bool $valid     Whether another integration validated it.
         * @param int  $thread_id Thread ID.
         * @return bool
         */
        public function is_valid_group( $valid, $thread_id )
        {
            if ( $valid ) {
                return $valid;
            }

            $space_id = Better_Messages()->functions->get_thread_meta( (int) $thread_id, self::THREAD_META_KEY );

            if ( ! $space_id ) {
                return $valid;
            }

            return null !== $this->get_space( (int) $space_id );
        }

        public function user_has_groups( $has, $user_id )
        {
            if ( $has ) {
                return true;
            }

            if ( (int) $user_id <= 0 ) {
                return false;
            }

            global $wpdb;

            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}bn_space_members m
                 JOIN {$wpdb->prefix}bn_spaces s ON s.id = m.space_id
                 WHERE m.user_id = %d AND m.status = 'active' AND s.is_archived = 0",
                $user_id
            ) );

            return $count > 0;
        }

        public function get_groups( $groups, $user_id )
        {
            $user_id = Better_Messages()->functions->get_current_user_id();

            if ( $user_id <= 0 ) {
                return array();
            }

            global $wpdb;

            $spaces = $wpdb->get_results( $wpdb->prepare(
                "SELECT s.id, s.name, s.avatar_url
                 FROM {$wpdb->prefix}bn_space_members m
                 JOIN {$wpdb->prefix}bn_spaces s ON s.id = m.space_id
                 WHERE m.user_id = %d AND m.status = 'active' AND s.is_archived = 0
                 ORDER BY s.name ASC",
                $user_id
            ) );

            $return = array();

            foreach ( $spaces as $space ) {
                $space_id  = (int) $space->id;
                $thread_id = $this->get_group_thread_id( $space_id );

                $return[] = array(
                    'group_id'  => $space_id,
                    'name'      => html_entity_decode( esc_attr( $space->name ) ),
                    'messages'  => (int) ( $this->is_space_messages_enabled( $space_id ) === 'enabled' ),
                    'thread_id' => (int) $thread_id,
                    'image'     => (string) $space->avatar_url,
                    'url'       => $this->space_url( $space_id ),
                );
            }

            return $return;
        }

        public function has_access_to_group_chat( $has_access, $thread_id, $user_id )
        {
            $space_id = Better_Messages()->functions->get_thread_meta( $thread_id, self::THREAD_META_KEY );

            if ( ! ! $space_id ) {
                if ( $this->is_space_messages_enabled( $space_id ) === 'enabled' && $this->user_has_access( $space_id, $user_id ) ) {
                    return true;
                }
            }

            return $has_access;
        }

        public function can_reply_to_group_chat( $allowed, $user_id, $thread_id )
        {
            $type = Better_Messages()->functions->get_thread_type( $thread_id );

            if ( $type === 'group' ) {
                $space_id = Better_Messages()->functions->get_thread_meta( $thread_id, self::THREAD_META_KEY );

                if ( ! ! $space_id ) {
                    if ( $this->is_space_messages_enabled( $space_id ) === 'enabled' && $this->user_has_access( $space_id, $user_id ) ) {
                        return true;
                    }

                    return false;
                }
            }

            return $allowed;
        }

        public function disable_upload_files( $can_upload, $user_id, $thread_id )
        {
            if ( Better_Messages()->functions->get_thread_type( $thread_id ) === 'group' ) {
                $space_id = Better_Messages()->functions->get_thread_meta( $thread_id, self::THREAD_META_KEY );

                if ( ! ! $space_id ) {
                    return false;
                }
            }

            return $can_upload;
        }

        /** ------------------------------------------------------------
         *  Thread presentation (always live from the space)
         *  ------------------------------------------------------------ */

        public function group_thread_title( $title, $thread_id, $thread )
        {
            $space = $this->thread_space( $thread_id );

            if ( $space && ! empty( $space['name'] ) ) {
                return $space['name'];
            }

            return $title;
        }

        public function group_thread_image( $image, $thread_id, $thread )
        {
            $space = $this->thread_space( $thread_id );

            if ( $space && ! empty( $space['avatar_url'] ) ) {
                return $space['avatar_url'];
            }

            return $image;
        }

        public function group_thread_url( $url, $thread_id, $thread )
        {
            $space = $this->thread_space( $thread_id );

            if ( $space ) {
                return $this->space_url( (int) $space['id'] );
            }

            return $url;
        }

        private function thread_space( $thread_id )
        {
            if ( Better_Messages()->functions->get_thread_type( (int) $thread_id ) !== 'group' ) {
                return null;
            }

            $space_id = Better_Messages()->functions->get_thread_meta( $thread_id, self::THREAD_META_KEY );

            if ( ! $space_id ) {
                return null;
            }

            return $this->get_space( (int) $space_id );
        }

        private function space_url( $space_id )
        {
            if ( class_exists( '\BuddyNext\Core\PageRouter' ) ) {
                return \BuddyNext\Core\PageRouter::space_url( (int) $space_id );
            }

            return '';
        }

        /** ------------------------------------------------------------
         *  Thread creation and membership sync
         *  ------------------------------------------------------------ */

        public function get_group_thread_id( $space_id )
        {
            return Better_Messages()->functions->with_lock( 'buddynext_space_thread|' . (int) $space_id, function () use ( $space_id ) {
                return $this->find_or_create_group_thread( $space_id );
            } );
        }

        private function find_or_create_group_thread( $space_id )
        {
            global $wpdb;

            $thread_id = (int) $wpdb->get_var( $wpdb->prepare( "
            SELECT bm_thread_id
            FROM `" . bm_get_table( 'threadsmeta' ) . "`
            WHERE `meta_key` = %s
            AND   `meta_value` = %s
            ", self::THREAD_META_KEY, $space_id ) );

            $thread_exist = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `" . bm_get_table( 'threads' ) . "` WHERE `id` = %d", $thread_id ) );

            if ( $thread_exist === 0 ) {
                $thread_id = false;
            }

            /**
             * Only a real group thread may carry the space link. Seen
             * live (2026-11-22): a hand-started plain conversation ended
             * up carrying the meta, so the sync poured the space members
             * into a two-person thread and the messenger rendered the
             * space chat as a private dialog. Unhook such a thread - it
             * stays untouched as the conversation it is - and fall
             * through to creating the proper group thread.
             */
            if ( $thread_id && Better_Messages()->functions->get_thread_type( (int) $thread_id ) !== 'group' ) {
                Better_Messages()->functions->delete_thread_meta( (int) $thread_id, self::THREAD_META_KEY );
                Better_Messages()->functions->delete_thread_meta( (int) $thread_id, 'buddynext_space_thread' );
                $thread_id = false;
            }

            if ( ! $thread_id ) {
                if ( $this->is_space_messages_enabled( $space_id ) !== 'enabled' ) {
                    return false;
                }

                $space = $this->get_space( $space_id );

                if ( ! $space ) {
                    return false;
                }

                $wpdb->query( $wpdb->prepare( "
                DELETE
                FROM `" . bm_get_table( 'threadsmeta' ) . "`
                WHERE `meta_key` = %s
                AND   `meta_value` = %s
                ", self::THREAD_META_KEY, $space_id ) );

                $wpdb->insert(
                    bm_get_table( 'threads' ),
                    array(
                        'subject' => $space['name'],
                        'type'    => 'group',
                    )
                );

                $thread_id = $wpdb->insert_id;

                Better_Messages()->functions->update_thread_meta( $thread_id, 'buddynext_space_thread', true );
                Better_Messages()->functions->update_thread_meta( $thread_id, self::THREAD_META_KEY, $space_id );

                $this->sync_thread_members( $thread_id );
            }

            return $thread_id;
        }

        public function on_membership_changed( $space_id )
        {
            if ( $this->is_space_messages_enabled( $space_id ) !== 'enabled' ) {
                return;
            }

            if ( ! $this->get_space( (int) $space_id ) ) {
                return;
            }

            $thread_id = $this->get_group_thread_id( $space_id );

            if ( $thread_id ) {
                $this->sync_thread_members( $thread_id );
            }
        }

        public function on_space_created( $space_id, $owner_id )
        {
            $this->on_membership_changed( $space_id );
        }

        public function on_space_deleted( $space_id, $user_id )
        {
            global $wpdb;

            $thread_id = (int) $wpdb->get_var( $wpdb->prepare( "
            SELECT bm_thread_id
            FROM `" . bm_get_table( 'threadsmeta' ) . "`
            WHERE `meta_key` = %s
            AND   `meta_value` = %s
            ", self::THREAD_META_KEY, $space_id ) );

            if ( $thread_id ) {
                Better_Messages()->functions->erase_thread( $thread_id );
            }
        }

        public function sync_thread_members( $thread_id )
        {
            wp_cache_delete( 'thread_recipients_' . $thread_id, 'bp_messages' );
            wp_cache_delete( 'bm_thread_recipients_' . $thread_id, 'bm_messages' );
            // The validated type is cached (persistently, with an object
            // cache) - drop it so a fixed validation is picked up.
            wp_cache_delete( 'thread_' . $thread_id . '_type', 'bm_messages' );

            $space_id = Better_Messages()->functions->get_thread_meta( $thread_id, self::THREAD_META_KEY );
            $members  = $this->get_space_members( $space_id );

            if ( count( $members ) === 0 ) {
                return false;
            }

            global $wpdb;
            $array       = array();
            $user_ids    = array();
            $removed_ids = array();
            $recipients  = Better_Messages()->functions->get_recipients( $thread_id );

            foreach ( $members as $user_id ) {
                if ( isset( $recipients[ $user_id ] ) ) {
                    unset( $recipients[ $user_id ] );
                    continue;
                }

                $user_ids[] = $user_id;

                $array[] = array( $user_id, $thread_id, 0, 0 );
            }

            $changes = false;

            if ( count( $array ) > 0 ) {
                $sql = "INSERT INTO " . bm_get_table( 'recipients' ) . "
                (user_id, thread_id, unread_count, is_deleted)
                VALUES ";

                $values = array();

                foreach ( $array as $item ) {
                    $values[] = $wpdb->prepare( '(%d, %d, %d, %d)', $item );
                }

                $sql .= implode( ',', $values );

                $wpdb->query( $sql );

                $changes = true;
            }

            if ( count( $recipients ) > 0 ) {
                foreach ( $recipients as $user_id => $recipient ) {
                    if ( $user_id < 0 ) {
                        continue;
                    }

                    $wpdb->delete( bm_get_table( 'recipients' ), array(
                        'thread_id' => $thread_id,
                        'user_id'   => $user_id,
                    ), array( '%d', '%d' ) );

                    $removed_ids[] = $user_id;
                }

                $changes = true;
            }

            Better_Messages()->hooks->clean_thread_cache( $thread_id );

            if ( $changes ) {
                do_action( 'better_messages_thread_updated', $thread_id );
                do_action( 'better_messages_info_changed', $thread_id );
                do_action( 'better_messages_participants_added', $thread_id, $user_ids );
                do_action( 'better_messages_participants_removed', $thread_id, $removed_ids );
            }

            return true;
        }

        /** ------------------------------------------------------------
         *  One-time backfill for pre-existing spaces
         *  ------------------------------------------------------------ */

        public function maybe_backfill()
        {
            if ( get_option( 'bm_buddynext_groups_backfilled' ) ) {
                return;
            }

            if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
                return;
            }

            global $wpdb;

            $space_ids = $wpdb->get_col(
                "SELECT id FROM {$wpdb->prefix}bn_spaces WHERE is_archived = 0 ORDER BY id ASC LIMIT 500"
            );

            foreach ( $space_ids as $space_id ) {
                $this->on_membership_changed( (int) $space_id );
            }

            update_option( 'bm_buddynext_groups_backfilled', time(), false );
        }

        /** ------------------------------------------------------------
         *  Daily self-heal: erase orphaned threads, re-sync members
         *  ------------------------------------------------------------ */

        public function schedule_daily_sync()
        {
            if ( ! wp_next_scheduled( 'bm_buddynext_daily_sync' ) ) {
                wp_schedule_event( time() + 300, 'daily', 'bm_buddynext_daily_sync' );
            }
        }

        /**
         * "Sync now" link in the plugin row, so an administrator can run
         * the self-heal pass without waiting a day for the cron.
         *
         * @param array $links Plugin action links.
         * @return array
         */
        public function action_links( $links )
        {
            $links[] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=bm_buddynext_sync' ), 'bm_buddynext_sync' ) ) . '">'
                . esc_html_x( 'Sync now', 'Plugin row action', 'better-messages-buddynext' )
                . '</a>';

            return $links;
        }

        /**
         * Manual trigger for administrators (the plugin-row "Sync now"
         * link). Runs the same pass as the daily cron and reports what it
         * did - for verifying the integration after an incident without
         * waiting a day for the cron.
         */
        public function handle_manual_sync()
        {
            if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'bm_buddynext_sync' ) ) {
                wp_die( 'Not allowed.' );
            }

            $report = $this->daily_sync();

            wp_die( wp_json_encode( $report ), 'BuddyNext sync', array( 'response' => 200 ) );
        }

        public function daily_sync()
        {
            global $wpdb;

            $report = array(
                'threads_checked' => 0,
                'orphans_erased'  => array(),
                'spaces_synced'   => 0,
            );

            /**
             * Threads whose space is gone (or archived) are erased. Covers
             * every path where the space-deleted hook did not run to the
             * end: a request killed by a timeout, a space removed straight
             * in the database, an archive while this integration was off.
             */
            $rows = $wpdb->get_results( "
            SELECT bm_thread_id, meta_value
            FROM `" . bm_get_table( 'threadsmeta' ) . "`
            WHERE `meta_key` = '" . self::THREAD_META_KEY . "'
            " );

            foreach ( $rows as $row ) {
                $report['threads_checked']++;

                $space_id = (int) $row->meta_value;

                /**
                 * Straight from the database, NOT through get_space():
                 * SpaceService caches rows in the persistent object cache,
                 * and a delete request that died mid-flight leaves the
                 * removed space cached - exactly the situation this pass
                 * exists to repair (seen live 2026-11-21: the orphaned
                 * thread survived the first pass because the erased space
                 * still answered from Redis). The healer trusts only the
                 * table, and drops the stale cache entry when they
                 * disagree.
                 */
                $exists = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}bn_spaces WHERE id = %d AND is_archived = 0",
                    $space_id
                ) );

                if ( 0 === $exists ) {
                    wp_cache_delete( "space_{$space_id}", 'buddynext_spaces' );
                    Better_Messages()->functions->erase_thread( (int) $row->bm_thread_id );
                    $report['orphans_erased'][] = (int) $row->bm_thread_id;
                }
            }

            /**
             * And the other direction: every live space gets its thread
             * participants re-synced, so a missed membership hook heals
             * within a day.
             */
            $space_ids = $wpdb->get_col(
                "SELECT id FROM {$wpdb->prefix}bn_spaces WHERE is_archived = 0 ORDER BY id ASC LIMIT 500"
            );

            foreach ( $space_ids as $space_id ) {
                $this->on_membership_changed( (int) $space_id );
                $report['spaces_synced']++;
            }

            return $report;
        }

        /** ------------------------------------------------------------
         *  Bulk messaging support
         *  ------------------------------------------------------------ */

        public function bulk_get_all_groups( $groups )
        {
            global $wpdb;

            $spaces = $wpdb->get_results(
                "SELECT id, name FROM {$wpdb->prefix}bn_spaces WHERE is_archived = 0 ORDER BY name ASC"
            );

            foreach ( $spaces as $space ) {
                $groups[] = array(
                    'id'         => (int) $space->id,
                    'name'       => html_entity_decode( esc_attr( $space->name ) ),
                    'type'       => 'buddynext',
                    'type_label' => 'BuddyNext',
                );
            }

            return $groups;
        }

        public function bulk_get_group_members( $user_ids, $group_type, $group_id )
        {
            if ( $group_type !== 'buddynext' ) {
                return $user_ids;
            }

            foreach ( $this->get_space_members( (int) $group_id ) as $uid ) {
                $user_ids[] = (int) $uid;
            }

            return $user_ids;
        }
    }
}
