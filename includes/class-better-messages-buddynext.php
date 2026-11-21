<?php
/**
 * The member-to-member side of the BuddyNext integration: identity,
 * the friends provider fed from BuddyNext's social graph, messaging
 * rules (followers / connections only), block list support and a guest
 * hardening rule.
 *
 * Mirrors the shape of bp-better-messages/addons/peepso.php.
 *
 * @package BetterMessagesBuddyNext
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_BuddyNext' ) ) {

    class Better_Messages_BuddyNext
    {
        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_BuddyNext();
            }

            return $instance;
        }

        public function __construct()
        {
            /**
             * Profile links inside the messenger point at BuddyNext
             * profiles. Avatars need no override: BuddyNext already
             * filters get_avatar for its members site-wide.
             */
            add_filter( 'better_messages_rest_user_item', array( $this, 'rest_user_item' ), 20, 3 );

            /**
             * BuddyNext owns member names and photos, so Better Messages
             * hides its own "Members Can Change Their Name / Photo"
             * settings, exactly as it does for the profile plugins it
             * knows natively.
             */
            add_filter( 'better_messages_user_profiles_plugin', array( $this, 'profiles_plugin' ) );

            /**
             * Friends provider: Better Messages' friends surface (the
             * Friends widget, the recipient search) is fed from the
             * BuddyNext social graph - accepted connections plus approved
             * follow relations.
             */
            add_filter( 'better_messages_friends_active', '__return_true' );
            add_filter( 'better_messages_get_friends', array( $this, 'get_friends' ), 10, 2 );
            add_filter( 'better_messages_user_has_friends', array( $this, 'user_has_friends' ), 10, 2 );
            add_filter( 'better_messages_is_friends', array( $this, 'is_friends' ), 10, 3 );
            add_filter( 'better_messages_search_friends', array( $this, 'search_friends' ), 10, 3 );

            /**
             * Messaging rule: by default only followers and connections
             * may start a private conversation with a member (and reply
             * in one). Group chats and chat rooms are not affected. The
             * relation is checked in both directions, so the person who
             * was legitimately written to can always answer.
             */
            if ( bm_buddynext_setting( 'buddynextOnlyFollowersMode', '1' ) === '1' ) {
                add_filter( 'better_messages_only_friends_mode', '__return_true' );
                add_filter( 'better_messages_can_send_message', array( $this, 'disable_non_friends_reply' ), 10, 3 );
                add_action( 'better_messages_before_new_thread', array( $this, 'disable_start_thread_for_non_friends' ), 15, 2 );
            }

            /**
             * BuddyNext block lists: neither side of a block can start a
             * conversation with, or reply to, the other.
             */
            add_filter( 'better_messages_can_send_message', array( $this, 'disable_blocked_replies' ), 20, 3 );
            add_action( 'better_messages_before_new_thread', array( $this, 'disable_start_thread_for_blocked_users' ), 20, 2 );
            add_filter( 'better_messages_rest_user_item', array( $this, 'blocked_user_item' ), 10, 3 );

            /**
             * Guests never write: Better Messages only lets guests in
             * when guest access is switched on, this rule keeps them out
             * of new conversations regardless of that setting.
             */
            add_action( 'better_messages_before_new_thread', array( $this, 'disable_start_thread_for_guests' ), 5, 2 );
        }

        /** ------------------------------------------------------------
         *  Identity
         *  ------------------------------------------------------------ */

        public function rest_user_item( $item, $user_id, $include_personal )
        {
            if ( $user_id > 0 && class_exists( '\BuddyNext\Core\PageRouter' ) ) {
                $url = \BuddyNext\Core\PageRouter::profile_url( (int) $user_id );

                if ( ! empty( $url ) ) {
                    $item['url'] = $url;
                }
            }

            return $item;
        }

        public function profiles_plugin( $plugin )
        {
            return 'BuddyNext';
        }

        /** ------------------------------------------------------------
         *  Friends provider (connections + follows)
         *  ------------------------------------------------------------ */

        /**
         * The ids a member may always message: their accepted connections
         * and every member with an approved follow relation in either
         * direction.
         *
         * @param int $user_id User id.
         * @return array<int,int>
         */
        public function friend_ids( int $user_id ): array
        {
            if ( $user_id <= 0 ) {
                return array();
            }

            global $wpdb;

            $ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT CASE WHEN requester_id = %d THEN recipient_id ELSE requester_id END
                 FROM {$wpdb->prefix}bn_connections
                 WHERE ( requester_id = %d OR recipient_id = %d ) AND status = 'accepted'",
                $user_id, $user_id, $user_id
            ) );

            $follow_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT CASE WHEN follower_id = %d THEN following_id ELSE follower_id END
                 FROM {$wpdb->prefix}bn_follows
                 WHERE ( follower_id = %d OR following_id = %d ) AND status = 'approved'",
                $user_id, $user_id, $user_id
            ) );

            $ids = array_unique( array_map( 'intval', array_merge( $ids, $follow_ids ) ) );
            $ids = array_values( array_diff( $ids, array( $user_id ) ) );

            return $ids;
        }

        public function get_friends( $friends, $user_id )
        {
            $users = array();

            foreach ( $this->friend_ids( (int) $user_id ) as $friend_id ) {
                $user = get_userdata( $friend_id );

                if ( ! $user ) {
                    continue;
                }

                $users[] = Better_Messages()->functions->rest_user_item( $user->ID );
            }

            return $users;
        }

        public function user_has_friends( $has, $user_id )
        {
            if ( $has ) {
                return true;
            }

            return count( $this->friend_ids( (int) $user_id ) ) > 0;
        }

        public function is_friends( $bool, $user_id_1, $user_id_2 )
        {
            $user_id_1 = (int) $user_id_1;
            $user_id_2 = (int) $user_id_2;

            if ( $user_id_1 <= 0 || $user_id_2 <= 0 ) {
                return false;
            }

            if ( buddynext_service( 'connections' )->are_connected( $user_id_1, $user_id_2 ) ) {
                return true;
            }

            $follows = buddynext_service( 'follows' );

            return $follows->is_following( $user_id_1, $user_id_2 )
                || $follows->is_following( $user_id_2, $user_id_1 );
        }

        public function search_friends( $result, $search, $user_id )
        {
            if ( $user_id < 0 ) {
                return $result;
            }

            $friends = $this->friend_ids( (int) $user_id );

            if ( count( $friends ) > 0 ) {
                global $wpdb;

                $sql = $wpdb->prepare(
                    "SELECT ID FROM `{$wpdb->users}`
                    WHERE ( `user_nicename` LIKE %s OR `display_name` LIKE %s )
                    AND `ID` IN (" . implode( ',', array_map( 'intval', $friends ) ) . ')
                    LIMIT 0, 10',
                    '%' . $wpdb->esc_like( $search ) . '%',
                    '%' . $wpdb->esc_like( $search ) . '%'
                );

                $matched = $wpdb->get_col( $sql );

                foreach ( $matched as $friend ) {
                    $result[] = intval( $friend );
                }
            }

            return $result;
        }

        /** ------------------------------------------------------------
         *  Rules: followers / connections only
         *  ------------------------------------------------------------ */

        public function disable_start_thread_for_non_friends( &$args, &$errors )
        {
            if ( current_user_can( 'manage_options' ) ) {
                return null;
            }

            if ( ! is_array( $errors ) ) {
                $errors = array();
            }

            if ( count( $errors ) > 0 ) {
                return null;
            }

            $recipients = $args['recipients'];

            if ( ! is_array( $recipients ) ) {
                $recipients = array( $recipients );
            }

            $current_user_id = Better_Messages()->functions->get_current_user_id();

            foreach ( $recipients as $recipient ) {
                $recipient = (int) $recipient;

                if ( $recipient < 0 ) {
                    continue; // AI bots and guests are not gated by the social graph.
                }

                if ( user_can( $recipient, 'manage_options' ) ) {
                    continue; // Site administrators stay reachable.
                }

                if ( ! $this->is_friends( false, $current_user_id, $recipient ) ) {
                    $errors['buddynext_not_connected'] = sprintf(
                        _x( 'You can write to %s once you follow each other or are connected.', 'Error when starting a conversation without a relation', 'better-messages-buddynext' ),
                        Better_Messages()->functions->get_name( $recipient )
                    );
                }
            }
        }

        public function disable_non_friends_reply( $allowed, $user_id, $thread_id )
        {
            if ( ! $allowed ) {
                return $allowed;
            }

            $type = Better_Messages()->functions->get_thread_type( $thread_id );

            if ( $type !== 'thread' ) {
                return $allowed;
            }

            $participants = Better_Messages()->functions->get_participants( $thread_id );

            if ( count( $participants['recipients'] ) !== 1 ) {
                return $allowed;
            }

            reset( $participants['recipients'] );
            $peer_id = (int) key( $participants['recipients'] );

            if ( current_user_can( 'manage_options' ) || $peer_id < 0 || ( $peer_id > 0 && user_can( $peer_id, 'manage_options' ) ) ) {
                return $allowed;
            }

            if ( ! $this->is_friends( false, (int) $user_id, $peer_id ) ) {
                global $bp_better_messages_restrict_send_message;
                $bp_better_messages_restrict_send_message['buddynext_not_connected'] = _x( 'You can send messages once you follow each other or are connected.', 'Message when a reply is blocked without a relation', 'better-messages-buddynext' );
                return false;
            }

            return $allowed;
        }

        /** ------------------------------------------------------------
         *  Rules: BuddyNext block lists
         *  ------------------------------------------------------------ */

        public function blocked_user_item( $item, $user_id, $include_personal )
        {
            if ( $include_personal && $user_id > 0 && Better_Messages()->functions->get_current_user_id() !== $user_id ) {
                $current_user_id = Better_Messages()->functions->get_current_user_id();

                if ( $current_user_id > 0 ) {
                    $item['blocked'] = (int) buddynext_service( 'blocks' )->is_blocked( $current_user_id, (int) $user_id );
                }
            }

            return $item;
        }

        public function disable_start_thread_for_blocked_users( &$args, &$errors )
        {
            if ( current_user_can( 'manage_options' ) ) {
                return null;
            }

            $current_user_id = Better_Messages()->functions->get_current_user_id();

            if ( $current_user_id <= 0 ) {
                return null;
            }

            $recipients = $args['recipients'];

            if ( ! is_array( $recipients ) ) {
                $recipients = array( $recipients );
            }

            foreach ( $recipients as $user_id ) {
                $user_id = (int) $user_id;

                if ( $user_id > 0 && buddynext_service( 'blocks' )->is_blocking_either( $current_user_id, $user_id ) ) {
                    $errors['buddynext_blocked'] = sprintf(
                        _x( 'You cannot send messages to %s.', 'Error when starting a conversation with a blocked member', 'better-messages-buddynext' ),
                        Better_Messages()->functions->get_name( $user_id )
                    );
                }
            }
        }

        public function disable_blocked_replies( $allowed, $user_id, $thread_id )
        {
            if ( ! $allowed ) {
                return $allowed;
            }

            $user_id = (int) $user_id;

            if ( $user_id <= 0 || user_can( $user_id, 'manage_options' ) ) {
                return $allowed;
            }

            $type = Better_Messages()->functions->get_thread_type( $thread_id );

            if ( $type !== 'thread' ) {
                return $allowed;
            }

            $participants = Better_Messages()->functions->get_participants( $thread_id );

            if ( count( $participants['recipients'] ) !== 1 ) {
                return $allowed;
            }

            reset( $participants['recipients'] );
            $peer_id = (int) key( $participants['recipients'] );

            if ( $peer_id > 0 && buddynext_service( 'blocks' )->is_blocking_either( $user_id, $peer_id ) ) {
                global $bp_better_messages_restrict_send_message;
                $bp_better_messages_restrict_send_message['buddynext_blocked'] = _x( 'You cannot send messages in this conversation.', 'Message when a reply is blocked because of a block', 'better-messages-buddynext' );
                return false;
            }

            return $allowed;
        }

        /** ------------------------------------------------------------
         *  Rules: guests
         *  ------------------------------------------------------------ */

        public function disable_start_thread_for_guests( &$args, &$errors )
        {
            if ( Better_Messages()->functions->get_current_user_id() <= 0 ) {
                $errors['buddynext_guests'] = _x( 'Please sign in to send messages.', 'Error when a guest tries to start a conversation', 'better-messages-buddynext' );
            }
        }
    }
}
