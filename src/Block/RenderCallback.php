<?php
/**
 * RenderCallback — server-side render orchestrator for the MFD Dashboard block.
 *
 * Purpose:
 *   Outputs the static HTML shell that the frontend React hydration script
 *   attaches to. The shell intentionally contains NO user-specific data —
 *   all metrics are fetched client-side via XHR to bypass Varnish/Cloudflare
 *   full-page caching layers.
 *
 * What the server renders:
 *   - A wrapper div with data attributes (block attributes from the editor).
 *   - An ARIA-labelled loading skeleton visible until JS hydrates.
 *   - A <noscript> fallback for accessibility.
 *
 * What the server does NOT render:
 *   - Any user-specific metrics (last_login, profile_strength, etc.)
 *   - These are fetched exclusively by frontend.js via the REST API.
 *
 * @package MFD\DashboardWidget\Block
 */

declare(strict_types=1);

namespace MFD\DashboardWidget\Block;

/**
 * Class RenderCallback
 *
 * Produces the server-side HTML shell for the dashboard block.
 * Called by register_block_type_from_metadata()'s render_callback.
 *
 * @package MFD\DashboardWidget\Block
 */
final class RenderCallback
{
    /**
     * Render the block HTML shell.
     *
     * WordPress passes the block attributes (set in the editor) and the
     * inner blocks content as arguments. We use $attributes to encode
     * widget-visibility flags as JSON data attributes, which frontend.js
     * reads to conditionally render panels.
     *
     * @param  array<string, mixed> $attributes Block attributes from block.json schema.
     * @param  string               $content    Inner blocks markup (unused for dynamic blocks).
     * @return string                           Escaped HTML markup string.
     */
    public function render(array $attributes, string $content): string
    {
        // Only render for logged-in users — guests see nothing.
        if (! is_user_logged_in()) {
            return '';
        }

        // Extract and sanitize boolean toggles from block attributes.
        $showLastLogin    = (bool) ($attributes['showLastLogin']    ?? true);
        $showStrength     = (bool) ($attributes['showStrength']     ?? true);
        $showDownloads    = (bool) ($attributes['showDownloads']    ?? true);
        $showActivity     = (bool) ($attributes['showActivity']     ?? true);
        $accentColor      = sanitize_hex_color($attributes['accentColor'] ?? '#6366f1') ?? '#6366f1';
        $containerWidth   = sanitize_text_field($attributes['containerWidth'] ?? 'full');

        // Encode widget config as a JSON data attribute — read by frontend.js.
        // esc_attr() encodes the JSON string for safe HTML attribute embedding.
        $widgetConfig = esc_attr(
            wp_json_encode([
                'showLastLogin'  => $showLastLogin,
                'showStrength'   => $showStrength,
                'showDownloads'  => $showDownloads,
                'showActivity'   => $showActivity,
                'accentColor'    => $accentColor,
                'containerWidth' => $containerWidth,
            ])
        );

        // Unique block instance ID for aria-labelledby and JS targeting.
        // wp_unique_id() ensures multiple blocks on one page don't collide.
        $instanceId = esc_attr(wp_unique_id('mfd-dashboard-'));

        ob_start();
        ?>
        <div
            id="<?php echo $instanceId; ?>"
            class="mfd-dashboard-block wp-block-mfd-dashboard-widget-dashboard"
            data-widget-config="<?php echo $widgetConfig; ?>"
            data-instance-id="<?php echo $instanceId; ?>"
            aria-label="<?php esc_attr_e('User Dashboard Widget', 'mfd-dashboard-widget'); ?>"
            role="region"
        >
            <?php $this->renderLoadingSkeleton($instanceId, $showStrength, $showActivity); ?>

            <noscript>
                <p class="mfd-noscript-notice">
                    <?php esc_html_e(
                        'This dashboard requires JavaScript to display live metrics. Please enable JavaScript in your browser.',
                        'mfd-dashboard-widget'
                    ); ?>
                </p>
            </noscript>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Render the loading skeleton HTML — visible before JS hydration completes.
     *
     * The skeleton structure mirrors the fully-hydrated layout so there is
     * zero layout shift (CLS score: 0) when the real content swaps in.
     * CSS animation is defined in style.css using Tailwind + custom keyframes.
     *
     * @param  string $instanceId  Unique block instance ID for scoping.
     * @param  bool   $showStrength Whether the strength meter widget is enabled.
     * @param  bool   $showActivity Whether the activity grid widget is enabled.
     * @return void
     */
    private function renderLoadingSkeleton(
        string $instanceId,
        bool $showStrength,
        bool $showActivity
    ): void {
        ?>
        <div
            class="mfd-skeleton"
            id="<?php echo esc_attr($instanceId); ?>-skeleton"
            aria-hidden="true"
        >
            <?php /* Header row — avatar + name skeleton */ ?>
            <div class="mfd-skeleton__header">
                <div class="mfd-skeleton__avatar mfd-skeleton__pulse"></div>
                <div class="mfd-skeleton__header-text">
                    <div class="mfd-skeleton__line mfd-skeleton__line--wide mfd-skeleton__pulse"></div>
                    <div class="mfd-skeleton__line mfd-skeleton__line--narrow mfd-skeleton__pulse"></div>
                </div>
            </div>

            <?php /* Metric cards row */ ?>
            <div class="mfd-skeleton__cards">
                <div class="mfd-skeleton__card mfd-skeleton__pulse"></div>
                <div class="mfd-skeleton__card mfd-skeleton__pulse"></div>
                <div class="mfd-skeleton__card mfd-skeleton__pulse"></div>
            </div>

            <?php if ($showStrength) : ?>
            <?php /* Profile strength meter placeholder */ ?>
            <div class="mfd-skeleton__meter mfd-skeleton__pulse"></div>
            <?php endif; ?>

            <?php if ($showActivity) : ?>
            <?php /* Activity grid placeholder — 5 row shimmer */ ?>
            <div class="mfd-skeleton__table">
                <?php for ($i = 0; $i < 5; $i++) : ?>
                <div class="mfd-skeleton__row">
                    <div class="mfd-skeleton__cell mfd-skeleton__pulse"></div>
                    <div class="mfd-skeleton__cell mfd-skeleton__cell--wide mfd-skeleton__pulse"></div>
                    <div class="mfd-skeleton__cell mfd-skeleton__pulse"></div>
                </div>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}
