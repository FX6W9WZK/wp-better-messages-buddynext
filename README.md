# Unofficial Better Messages for BuddyNext

Connects [Better Messages](https://www.better-messages.com/) with the
[BuddyNext](https://buddynext.com/) community plugin.

## What it does

- **Group chats for spaces.** Every BuddyNext space automatically gets a
  Better Messages group chat. Active space members are the chat's
  participants, kept in sync on every join, leave, removal, ban and
  ownership transfer. The chat's name, image and link always come live
  from the space; deleting a space deletes its chat. Spaces that existed
  before activation are picked up by a one-time backfill.
- **Followers / connections messaging rule** (on by default). Members can
  start a private conversation with — and reply to — another member when
  they are connected or when an approved follow relation exists in either
  direction. Site administrators stay reachable for everyone.
- **Block lists.** BuddyNext blocks work in the messenger: neither side of
  a block can message the other.
- **Guests can never start conversations**, regardless of the Better
  Messages guest-access setting.
- **Identity.** Profile links inside the messenger point to BuddyNext
  profiles, the friends surface (recipient search, friends widget) is fed
  from the BuddyNext social graph, and Better Messages leaves names and
  photos to BuddyNext. Avatars need no mapping — BuddyNext already filters
  WordPress avatars site-wide.

## Requirements

- WordPress 6.0+, PHP 8.2+
- [Better Messages](https://wordpress.org/plugins/bp-better-messages/) 2.15.9+
  (free or WebSocket version)
- BuddyNext 1.2+

## Installation

Install and activate both required plugins, then this one. No settings
screen: the integration is active with sensible defaults as soon as all
three plugins run.

## Options

Written in the exact shape of the built-in Better Messages integrations
(`addons/*.php`), the plugin reads its options from
`Better_Messages()->settings` with these keys and defaults, so settings
fields can be added to the Better Messages settings app without touching
the integration code:

| Key | Default | Meaning |
| --- | --- | --- |
| `buddynextEnableGroups` | `'1'` | Group chats for BuddyNext spaces |
| `buddynextEnableGroupsFiles` | `'1'` | File uploads inside space chats |
| `buddynextOnlyFollowersMode` | `'1'` | Private messages only between connected / following members |

A single space can opt out of its chat through the space meta key
`better_messages` set to `disabled` (default is enabled).

## Note to the Better Messages team

This integration is intentionally structured like your own addons —
`Better_Messages_BuddyNext` mirrors `addons/peepso.php`,
`Better_Messages_BuddyNext_Groups` mirrors `addons/peepso-groups.php`,
including the friends/groups provider filters, the recipients sync and
the `with_lock` thread creation. You are very welcome to adopt it into
Better Messages core; the two classes in `includes/` should drop into
`addons/` nearly unchanged. Verified against Better Messages 3.3.0 and
BuddyNext 1.2.9.

## License

GPL-2.0-or-later.
