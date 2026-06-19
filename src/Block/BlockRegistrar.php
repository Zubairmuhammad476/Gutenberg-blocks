<?php
/**
 * BlockRegistrar — registers the MFD Dashboard Widget Gutenberg block.
 *
 * Uses register_block_type_from_metadata() to consume block.json as the
 * single source of truth for block metadata, attributes, and asset handles.
 *
 * Responsibilities:
 *   - Register the block type with WordPress core.
 *   - Enqueue editor-only assets (editor.css, @wordpress/scripts build).
 *   - Pass server-side data to the frontend script via wp_localize_script.
 *
 * @package MFD\DashboardWidget\Block
 */

declare(strict_types=1);

namespace MFD\DashboardWidget\Block;

/**
 * Class BlockRegistrar
 *
 * Hooks into `init` via Plugin::boot() and registers the block + assets.
 *
 * @package MFD\DashboardWidget\Block
 */
final class BlockRegistrar
{
    /**
     * Block namespace and name — must match block.json `name` field exactly.
     */
    public const BLOCK_NAME = 'mfd-dashboard-widget/dashboard';

    /**
     * Script handle for the frontend view script.
     * Used as the handle for wp_localize_script().
     */
    public const VIEW_SCRIPT_HANDLE = 'mfd-dashboard-widget-view-script';

    /**
     * Register the block type by pointing to block.json.
     *
     * Hooked into `init` at priority 10 via Plugin::boot().
     *
     * register_block_type_from_metadata() auto-discovers and enqueues:
     *   - editorScript  → build/index.js  (editor only)
     *   - editorStyle   → build/index.css (editor only)
     *   - style         → build/style-index.css (frontend + editor)
     *   - viewScript    → build/frontend.js (frontend only)
     *
     * The render_callback is registered inline here so the server-side
     * render has full access to PHP context (current user, nonce, etc.)
     * without coupling block.json to a global function name.
     */
    public function register(): void
    {
        $blockJsonDir = MFD_PLUGIN_DIR . 'build';

        if (! file_exists($blockJsonDir . '/block.json')) {
            // Gracefully bail if the build hasn't been run yet.
            // A notice is surfaced in the editor via the missing block fallback.
            if (defined('WP_DEBUG') && WP_DEBUG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
                trigger_error(
                    'MFD Dashboard Widget: block.json not found in /build. Run `npm run build`.',
                    E_USER_NOTICE
                );
            }
            return;
        }

        register_block_type_from_metadata( $blockJsonDir );

        // Inject server-side data into the frontend view script AFTER
        // the block (and its view script) has been registered.
        add_action('wp_enqueue_scripts', [$this, 'localizeViewScript']);
    }

    /**
     * Localize the frontend view script with all data needed for the
     * async REST fetch — REST URL, nonce, and current user context.
     *
     * This runs on `wp_enqueue_scripts` (after register()) so that the
     * view script handle is guaranteed to exist before localization.
     *
     * wp_localize_script() escapes all values via esc_html and json_encode
     * internally — additional escaping at consume-time (JS side) is applied
     * in frontend.js using DOMPurify for any HTML insertion.
     */
    public function localizeViewScript(): void
    {
        if (! is_user_logged_in()) {
            return; // No sensitive data should be localized for guests.
        }

        $scriptData = [
            'restUrl'   => esc_url_raw(rest_url('custom-dashboard/v1/user-metrics')),
            'nonce'     => wp_create_nonce('mfd_rest_nonce'),
            'userId'    => get_current_user_id(),
            'nonceHeader' => 'X-MFD-Nonce',
            'i18n'      => [
                'loading'     => __('Loading your dashboard...', 'mfd-dashboard-widget'),
                'errorTitle'  => __('Unable to load metrics', 'mfd-dashboard-widget'),
                'errorRetry'  => __('Retry', 'mfd-dashboard-widget'),
                'never'       => __('Never', 'mfd-dashboard-widget'),
                'downloads'   => __('Downloads', 'mfd-dashboard-widget'),
                'lastLogin'   => __('Last Login', 'mfd-dashboard-widget'),
                'strength'    => __('Profile Strength', 'mfd-dashboard-widget'),
                'activity'    => __('Recent Activity', 'mfd-dashboard-widget'),
                'noActivity'  => __('No recent activity recorded.', 'mfd-dashboard-widget'),
                'previous'    => __('Previous', 'mfd-dashboard-widget'),
                'next'        => __('Next', 'mfd-dashboard-widget'),
            ],
        ];

        /**
         * Filter: mfd_view_script_data
         *
         * Allows third-party plugins to append additional data to the
         * localized script object without modifying this class.
         *
         * @param array $scriptData Current localized data array.
         */
        $scriptData = (array) apply_filters('mfd_view_script_data', $scriptData);

        wp_localize_script(
            self::VIEW_SCRIPT_HANDLE,
            'mfdDashboard', // Global JS object name: window.mfdDashboard
            $scriptData
        );
    }
}
