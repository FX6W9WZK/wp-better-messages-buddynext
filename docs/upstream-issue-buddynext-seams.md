Proposed issue for github.com/buddynext/buddynext (not yet filed - needs Thomas' go):

Title: Make the messages entry points filterable so external messengers can plug in

## Use case

brettspiel.events replaced the WPMediaVerse-backed messenger with Better Messages
(integration: https://github.com/FX6W9WZK/wp-better-messages-buddynext). The rail
(`buddynext_rail_items`), the account dropdown catalogue (`buddynext_user_links`)
and the mobile bar (`buddynext_mobile_nav_items`) made that replacement clean -
thank you for those seams.

Two entry points have no seam yet:

1. **`PageRouter::messages_url()`** is hardcoded to the core hub. A filter
   (e.g. `buddynext_messages_url`) would let an external messenger take over
   every core-emitted messages link in one place.

2. **The profile Message button** (`templates/parts/profile-actions.php`) gates
   on `MessagesData::entry_enabled()` and links `PageRouter::messages_url()`
   directly. With WPMediaVerse messaging off (the sensible state when an
   external messenger runs), the button disappears and an integration cannot
   supply its own. A filter on the gate + URL - or an args filter like the
   other `buddynext_part_*` templates carry - would close the gap:

   $bn_pa_can_message = apply_filters( 'buddynext_profile_can_message', $bn_pa_can_message, (int) $bn_pa_uid );
   $bn_pa_message_url = apply_filters( 'buddynext_profile_message_url', $bn_pa_message_url, (int) $bn_pa_uid );

With those two filters, an external messenger integration covers every surface
without template overrides.
