<?php
/**
 * The BuddyNext house skin for the Better Messages interface.
 *
 * Better Messages paints everything from `--bm-*` design tokens (RGB
 * triplets, consumed as `rgba(var(--token), a)`) and decides dark mode
 * by `prefers-color-scheme` / its own body classes - while this site
 * switches modes through BuddyX's `html[data-bx-mode]` toggle, which
 * BuddyNext's own `--bn-*` tokens follow. Left alone, the messenger
 * stays a bright white card on a dark community (and vice versa).
 *
 * This class re-points the shared BM color tokens to the community's
 * palette: the values are the site's resolved `--bn-*` tokens, measured
 * live on 2026-11-22 in both modes and converted to sRGB triplets
 * (BM cannot read the oklch()/hex custom properties directly). Light
 * sits on `html[data-bx-mode="light"] body` and dark on
 * `html[data-bx-mode="dark"] body` - body-level custom properties beat
 * BM's own :root/media-query declarations for everything inside body,
 * and the [data-bx-mode] selectors outweigh BM's
 * `body:not(.bm-messages-light)` prefers-dark block, so the messenger
 * follows the SITE toggle, never the OS.
 *
 * Non-color tokens (font, radii) reference the bn tokens directly -
 * they are consumed as plain values, not rgba() parts. Rules travel
 * through the documented `better_messages_css_customizations` filter,
 * so they load exactly where the messenger loads. If the community
 * palette changes, re-measure and update the triplet tables.
 *
 * @package BetterMessagesBuddyNext
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_BuddyNext_Skin' ) ) {

    class Better_Messages_BuddyNext_Skin
    {
        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_BuddyNext_Skin();
            }

            return $instance;
        }

        public function __construct()
        {
            if ( bm_buddynext_setting( 'buddynextSkin', '1' ) !== '1' ) {
                return;
            }

            add_filter( 'better_messages_css_customizations', array( $this, 'rules' ) );
        }

        /**
         * The shared BM color tokens for one mode.
         *
         * @param string $mode 'light' or 'dark'.
         * @return array<string,string> token => RGB triplet.
         */
        private function palette( string $mode ): array
        {
            // Snapshot of the site's resolved --bn-* tokens (sRGB), 2026-11-22.
            $bn = ( 'dark' === $mode )
                ? array(
                    'canvas'      => '14, 10, 9',
                    'surface'     => '24, 18, 17',
                    'raised'      => '32, 25, 23',
                    'sunken'      => '9, 7, 6',
                    'ink'         => '245, 241, 240',
                    'ink2'        => '188, 181, 180',
                    'ink3'        => '133, 126, 125',
                    'line'        => '47, 39, 38',
                    'line_faint'  => '36, 30, 29',
                    'line_strong' => '68, 58, 57',
                    'accent_tint' => '58, 20, 21',
                    'success_bg'  => '18, 38, 21',
                    'warn_bg'     => '44, 30, 8',
                    'danger_bg'   => '48, 16, 17',
                    'info_bg'     => '10, 31, 40',
                    'tooltip_bg'  => '68, 58, 57',
                )
                : array(
                    'canvas'      => '253, 251, 251',
                    'surface'     => '255, 255, 255',
                    'raised'      => '254, 251, 251',
                    'sunken'      => '248, 244, 244',
                    'ink'         => '26, 20, 19',
                    'ink2'        => '77, 70, 69',
                    'ink3'        => '109, 103, 102',
                    'line'        => '232, 227, 227',
                    'line_faint'  => '240, 238, 237',
                    'line_strong' => '214, 207, 206',
                    'accent_tint' => '251, 230, 231',
                    'success_bg'  => '235, 245, 236',
                    'warn_bg'     => '252, 242, 228',
                    'danger_bg'   => '251, 233, 233',
                    'info_bg'     => '229, 243, 249',
                    'tooltip_bg'  => '26, 20, 19',
                );

            $accent         = '227, 6, 19';   // --bn-accent (#e30613), same in both modes.
            $accent_hover   = '193, 5, 16';
            $accent_pressed = '163, 4, 14';
            $other_bg       = ( 'dark' === $mode ) ? $bn['raised'] : $bn['sunken'];

            return array(
                // Surfaces.
                '--bm-color-bg'                 => $bn['surface'],
                '--bm-color-bg-secondary'       => $bn['sunken'],
                '--bm-color-bg-elevated'        => $bn['raised'],
                '--bm-color-bg-hover'           => ( 'dark' === $mode ) ? $bn['raised'] : $bn['sunken'],
                '--bm-color-bg-active'          => $bn['line_faint'],

                // Text + icons.
                '--bm-color-text-primary'       => $bn['ink'],
                '--bm-color-text-secondary'     => $bn['ink2'],
                '--bm-color-text-tertiary'      => $bn['ink3'],
                '--bm-color-preview'            => $bn['ink3'],
                '--bm-color-preview-unread'     => $bn['ink'],
                '--bm-color-icon'               => $bn['ink3'],

                // Borders.
                '--bm-color-border'             => $bn['line'],
                '--bm-color-border-subtle'      => $bn['line_faint'],
                '--bm-color-border-strong'      => $bn['line_strong'],

                // Accent (the house rule: accent carries the interactive color).
                '--bm-color-accent'             => $accent,
                '--bm-color-accent-hover'       => $accent_hover,
                '--bm-color-accent-pressed'     => $accent_pressed,
                '--bm-color-accent-subtle'      => $bn['accent_tint'],
                '--bm-color-text-on-accent'     => '255, 255, 255',
                '--bm-color-text-link'          => $accent,

                // Bubbles: own messages on the brand, others on the muted surface.
                '--bm-color-bubble-self-bg'       => $accent,
                '--bm-color-bubble-self-text'     => '255, 255, 255',
                '--bm-color-bubble-self-nickname' => '255, 255, 255',
                '--bm-color-bubble-self-link'     => '255, 255, 255',
                '--bm-color-bubble-self-outline'  => $accent,
                '--bm-color-bubble-self-seen'     => '255, 255, 255',
                '--bm-color-bubble-other-bg'       => $other_bg,
                '--bm-color-bubble-other-text'     => $bn['ink'],
                '--bm-color-bubble-other-nickname' => $bn['ink2'],
                '--bm-color-bubble-other-link'     => $accent,
                '--bm-color-bubble-other-outline'  => $bn['line'],

                // Small furniture.
                '--bm-color-sticky-date-bg'     => $bn['line_faint'],
                '--bm-color-sticky-date-text'   => $bn['ink2'],
                '--bm-color-mention-bg'         => $bn['accent_tint'],
                '--bm-color-mention-text'       => $bn['ink'],
                '--bm-color-tooltip-bg'         => $bn['tooltip_bg'],
                '--bm-color-tooltip-text'       => '245, 241, 240',

                // Presence (the bn semantic palette).
                '--bm-color-online'             => '39, 135, 51',
                '--bm-color-away'               => '198, 125, 0',
                '--bm-color-busy'               => '215, 51, 55',
                '--bm-color-offline'            => $bn['ink3'],
                '--bm-color-recording'          => '215, 51, 55',

                // Status colors.
                '--bm-color-success'            => '39, 135, 51',
                '--bm-color-success-bg'         => $bn['success_bg'],
                '--bm-color-warning'            => '198, 125, 0',
                '--bm-color-warning-bg'         => $bn['warn_bg'],
                '--bm-color-danger'             => '215, 51, 55',
                '--bm-color-danger-bg'          => $bn['danger_bg'],
                '--bm-color-info'               => '0, 135, 188',
                '--bm-color-info-bg'            => $bn['info_bg'],
            );
        }

        /**
         * The per-surface ("zone") tokens. The stylesheet's surfaces do
         * not read the shared names directly - `.bm-card` paints from
         * `--bm-color-card-bg`, the inbox from `--bm-color-inbox-bg`, the
         * conversation list from `--bm-color-list-bg`, and so on - and
         * the plugin prints those zone values from its Appearance
         * defaults, which is why re-pointing only the shared names left
         * the surfaces white. One loop covers every zone prefix with the
         * same vocabulary the reference documents.
         *
         * @param string $mode 'light' or 'dark'.
         * @return array<string,string> token => RGB triplet.
         */
        private function zone_palette( string $mode ): array
        {
            $p      = $this->palette( $mode );
            $accent = $p['--bm-color-accent'];

            $zone_values = array(
                'bg'          => $p['--bm-color-bg'],
                'text'        => $p['--bm-color-text-primary'],
                'muted'       => $p['--bm-color-text-tertiary'],
                'preview'     => $p['--bm-color-preview'],
                'icon'        => $p['--bm-color-icon'],
                'border'      => $p['--bm-color-border'],
                'field'       => $p['--bm-color-bg-secondary'],
                'accent'      => $accent,
                'accent-text' => $p['--bm-color-text-on-accent'],
                'self-bg'     => $p['--bm-color-bubble-self-bg'],
                'self-text'   => $p['--bm-color-bubble-self-text'],
                'self-name'   => $p['--bm-color-bubble-self-nickname'],
                'other-bg'    => $p['--bm-color-bubble-other-bg'],
                'other-text'  => $p['--bm-color-bubble-other-text'],
                'other-name'  => $p['--bm-color-bubble-other-nickname'],
            );

            $prefixes = array(
                'card', 'inbox', 'list', 'secondary', 'header', 'reply',
                'mc', 'mc-head', 'mc-reply', 'dock', 'wlist',
                'mob', 'mob-head', 'mob-reply', 'mob-list', 'mob-tabs',
            );

            $tokens = array();

            foreach ( $prefixes as $prefix ) {
                foreach ( $zone_values as $name => $value ) {
                    $tokens[ '--bm-color-' . $prefix . '-' . $name ] = $value;
                }
            }

            return $tokens;
        }

        /**
         * Build one CSS rule from a token map.
         *
         * @param string               $selector CSS selector.
         * @param array<string,string> $tokens   token => value.
         * @return string
         */
        private function rule( string $selector, array $tokens, bool $important = false ): string
        {
            $decls  = '';
            $suffix = $important ? ' !important;' : ';';

            foreach ( $tokens as $token => $value ) {
                $decls .= $token . ':' . $value . $suffix;
            }

            return $selector . '{' . $decls . '}';
        }

        /**
         * The skin rules, through the documented customization filter.
         *
         * @param array $rules Rule strings.
         * @return array
         */
        public function rules( $rules )
        {
            $rules = (array) $rules;

            // Light is the fallback too: the bootstrap script sets
            // data-bx-mode before render, so the bare-body rule only
            // carries pages that render without the BuddyX shell.
            $rules[] = $this->rule( 'body, html[data-bx-mode="light"] body', $this->palette( 'light' ) + $this->zone_palette( 'light' ) );
            $rules[] = $this->rule( 'html[data-bx-mode="dark"] body', $this->palette( 'dark' ) + $this->zone_palette( 'dark' ) );

            /**
             * The zone tokens again, declared on every element inside the
             * messenger surfaces, important. The interface sets its zone
             * values late and locally (an element-level declaration was
             * the only thing observed to win against it), so inheriting
             * them from body is not enough: this puts the house values on
             * each element, where an author !important declaration beats
             * the plugin's own setters. It deliberately flattens
             * per-surface Appearance colors into the one house palette.
             */
            $bm_scope = static function ( string $mode_prefix ): string {
                $roots = array(
                    '.bm-wrap-main',
                    '.bm-embedded-inbox',
                    '#better-messages-modals-container',
                    '.bm-mini-widgets',
                    '.bm-mini-chats',
                );
                $parts = array();
                foreach ( $roots as $root ) {
                    $parts[] = $mode_prefix . $root;
                    $parts[] = $mode_prefix . $root . ' *';
                }
                return implode( ',', $parts );
            };

            $rules[] = $this->rule( $bm_scope( '' ), $this->zone_palette( 'light' ), true );
            $rules[] = $this->rule( $bm_scope( 'html[data-bx-mode="dark"] ' ), $this->zone_palette( 'dark' ), true );

            // House typography and corner radii, consumed as plain values,
            // so the live bn tokens work directly.
            $rules[] = 'body{'
                . '--bm-messenger-font:var(--bn-font-ui, inherit);'
                . '--bm-radius-card:var(--bn-r-lg, 12px);'
                . '--bm-radius-panel:var(--bn-r-lg, 12px);'
                . '--bm-radius-popover:var(--bn-r-lg, 12px);'
                . '--bm-radius-input:var(--bn-r-md, 8px);'
                . '--bm-radius-button:var(--bn-r-md, 8px);'
                . '}';

            // The theme's page furniture doubles the messenger's own header:
            // the sub-header (page title + breadcrumbs) disappears on any
            // page whose content is the messenger.
            $rules[] = 'body:has(.bm-wrap-main) .site-sub-header{display:none;}';

            return $rules;
        }
    }
}
