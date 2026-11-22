<?php
/**
 * The messenger hub surface: the Better Messages inbox rendered inside
 * BuddyNext's community shell (left rail, right sidebar, mobile bottom
 * bar), so switching to Messages no longer drops the member out of the
 * community chrome.
 *
 * The content is the official embed shortcode - it carries Better
 * Messages' own asset setup, which a bare hub route (no page context)
 * would otherwise not trigger.
 *
 * @package BetterMessagesBuddyNext
 */

defined( 'ABSPATH' ) || exit;

?>
<div class="bmbn-messages-hub">
	<?php echo do_shortcode( '[better_messages]' ); ?>
</div>
