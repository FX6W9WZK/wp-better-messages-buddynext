# Upstream request: profile actions seam

Filed 2026-11-22 as https://github.com/buddynext/buddynext/issues/230
(neutral wording - a generic registration seam for integration buttons
in the profile actions row; no messenger specifics).

Proposed filter:

    apply_filters( 'buddynext_profile_action_links', array(), $profile_user_id, $viewer_id )
    // each: array{ label: string, url: string, icon?: string }

When it lands: replace Better_Messages_BuddyNext_Profile's hero-seam +
script insertion with one callback on that filter (both the wide button
and the More-menu row then come from core's own slot model), and drop
the bmbn-profile-message CSS classes.
