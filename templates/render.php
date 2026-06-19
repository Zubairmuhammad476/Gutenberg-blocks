<?php
/**
 * render.php — MFD Dashboard Widget server-side render template.
 *
 * Consumed by block.json's `render` key:
 *   "render": "file:../templates/render.php"
 *
 * WordPress injects three variables into this template's scope:
 *   $attributes  (array)  — Sanitized block attribute values from block.json schema.
 *   $content     (string) — Inner block content (unused — dynamic block).
 *   $block       (object) — WP_Block instance (available for context if needed).
 *
 * Cache-bypass contract:
 *   This template intentionally outputs ZERO user-specific data.
 *   All metrics are fetched exclusively by frontend.js via the REST API,
 *   which runs client-side after the page is fully served by any cache layer
 *   (Varnish, Cloudflare, WP Super Cache, W3 Total Cache, etc.).
 *
 * Layout-shift contract:
 *   The skeleton structure below is a precise structural mirror of the
 *   hydrated React layout. Each skeleton element occupies the exact same
 *   dimensions as its live counterpart, guaranteeing CLS = 0.
 *
 * @package MFD\DashboardWidget
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner blocks markup.
 * @var \WP_Block            $block      Block instance.
 */

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// Guard — only render for authenticated users.
// ─────────────────────────────────────────────────────────────────────────────
if (! is_user_logged_in()) {
    return;
}

// ─────────────────────────────────────────────────────────────────────────────
// Attribute extraction & sanitization.
// All values originate from the block editor — still sanitized defensively.
// ─────────────────────────────────────────────────────────────────────────────
$show_last_login  = (bool) ($attributes['showLastLogin']  ?? true);
$show_strength    = (bool) ($attributes['showStrength']   ?? true);
$show_downloads   = (bool) ($attributes['showDownloads']  ?? true);
$show_activity    = (bool) ($attributes['showActivity']   ?? true);
$accent_color     = sanitize_hex_color($attributes['accentColor']    ?? '#6366f1') ?? '#6366f1';
$container_width  = sanitize_text_field($attributes['containerWidth'] ?? 'full');

// Validate containerWidth against the allowed enum values from block.json.
$allowed_widths  = ['full', 'wide', 'normal'];
$container_width = in_array($container_width, $allowed_widths, true) ? $container_width : 'full';

// ─────────────────────────────────────────────────────────────────────────────
// Widget config — serialized as a JSON data attribute.
// frontend.js reads this to know which panels to render.
// wp_json_encode produces valid JSON; esc_attr encodes it for HTML attribute context.
// ─────────────────────────────────────────────────────────────────────────────
$widget_config_json = esc_attr(
    wp_json_encode(
        [
            'showLastLogin'  => $show_last_login,
            'showStrength'   => $show_strength,
            'showDownloads'  => $show_downloads,
            'showActivity'   => $show_activity,
            'accentColor'    => $accent_color,
            'containerWidth' => $container_width,
        ],
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    )
);

// ─────────────────────────────────────────────────────────────────────────────
// Instance ID — unique per block on the page.
// wp_unique_id guarantees no collisions when multiple blocks are placed.
// ─────────────────────────────────────────────────────────────────────────────
$instance_id = esc_attr(wp_unique_id('mfd-dashboard-'));

// ─────────────────────────────────────────────────────────────────────────────
// Container width CSS class map.
// ─────────────────────────────────────────────────────────────────────────────
$width_class_map = [
    'full'   => 'mfd-w-full',
    'wide'   => 'mfd-max-w-5xl mfd-mx-auto',
    'normal' => 'mfd-max-w-3xl mfd-mx-auto',
];
$width_class = $width_class_map[$container_width] ?? 'mfd-w-full';

// ─────────────────────────────────────────────────────────────────────────────
// CSS custom property for accent color — inlined on the root element.
// Consumed by the SVG ring stroke and bar fill in frontend.js.
// ─────────────────────────────────────────────────────────────────────────────
$css_vars = sprintf('--mfd-accent:%s;', esc_attr($accent_color));
?>
<div
    id="<?php echo $instance_id; ?>"
    class="mfd-dashboard-block <?php echo esc_attr($width_class); ?>"
    data-widget-config="<?php echo $widget_config_json; ?>"
    data-instance-id="<?php echo $instance_id; ?>"
    style="<?php echo esc_attr($css_vars); ?>"
    role="region"
    aria-label="<?php esc_attr_e('User Dashboard Widget', 'mfd-dashboard-widget'); ?>"
    aria-live="polite"
    aria-busy="true"
>

    <?php /* ──────────────────────────────────────────────────────────────────
     * SKELETON ZONE 1 — Profile Header
     * Mirrors: ProfileCard component (avatar + name + email).
     * ─────────────────────────────────────────────────────────────────────── */ ?>
    <div
        class="mfd-skeleton mfd-skeleton--profile"
        id="<?php echo $instance_id; ?>-skeleton"
        aria-hidden="true"
        data-mfd-skeleton="profile"
    >
        <div class="mfd-skeleton__profile-card">
            <div class="mfd-skeleton__avatar mfd-skeleton__pulse" role="presentation"></div>
            <div class="mfd-skeleton__profile-text">
                <div class="mfd-skeleton__line mfd-skeleton__line--name mfd-skeleton__pulse" role="presentation"></div>
                <div class="mfd-skeleton__line mfd-skeleton__line--email mfd-skeleton__pulse" role="presentation"></div>
            </div>
        </div>
    </div>

    <?php /* ──────────────────────────────────────────────────────────────────
     * SKELETON ZONE 2 — Metrics Grid
     * Mirrors: MetricCardRow (Last Login | Downloads | Strength).
     * The number of visible cards adapts to the $show_* toggles so the
     * skeleton grid columns match the live layout exactly (zero CLS).
     * ─────────────────────────────────────────────────────────────────────── */ ?>
    <?php
    $visible_card_count = (int) $show_last_login + (int) $show_downloads + (int) $show_strength;
    ?>
    <?php if ($visible_card_count > 0) : ?>
    <div
        class="mfd-skeleton__metric-grid mfd-skeleton__metric-grid--<?php echo esc_attr((string) $visible_card_count); ?>"
        aria-hidden="true"
        data-mfd-skeleton="metrics"
        role="presentation"
    >
        <?php if ($show_last_login) : ?>
        <div class="mfd-skeleton__metric-card" role="presentation">
            <div class="mfd-skeleton__line mfd-skeleton__line--label mfd-skeleton__pulse"></div>
            <div class="mfd-skeleton__line mfd-skeleton__line--value mfd-skeleton__pulse"></div>
            <div class="mfd-skeleton__line mfd-skeleton__line--sub mfd-skeleton__pulse"></div>
        </div>
        <?php endif; ?>

        <?php if ($show_downloads) : ?>
        <div class="mfd-skeleton__metric-card" role="presentation">
            <div class="mfd-skeleton__line mfd-skeleton__line--label mfd-skeleton__pulse"></div>
            <div class="mfd-skeleton__line mfd-skeleton__line--value mfd-skeleton__pulse"></div>
            <div class="mfd-skeleton__line mfd-skeleton__line--sub mfd-skeleton__pulse"></div>
        </div>
        <?php endif; ?>

        <?php if ($show_strength) : ?>
        <div class="mfd-skeleton__metric-card" role="presentation">
            <div class="mfd-skeleton__line mfd-skeleton__line--label mfd-skeleton__pulse"></div>
            <div class="mfd-skeleton__line mfd-skeleton__line--value mfd-skeleton__pulse"></div>
            <div class="mfd-skeleton__line mfd-skeleton__line--sub mfd-skeleton__pulse"></div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php /* ──────────────────────────────────────────────────────────────────
     * SKELETON ZONE 3 — Profile Progress / Strength Meter
     * Mirrors: StrengthMeter component (SVG ring + 5 breakdown bars).
     * ─────────────────────────────────────────────────────────────────────── */ ?>
    <?php if ($show_strength) : ?>
    <div
        class="mfd-skeleton__strength-section"
        aria-hidden="true"
        data-mfd-skeleton="strength"
        role="presentation"
    >
        <?php /* Header row: title + badge */ ?>
        <div class="mfd-skeleton__strength-header">
            <div class="mfd-skeleton__line mfd-skeleton__line--section-title mfd-skeleton__pulse"></div>
            <div class="mfd-skeleton__line mfd-skeleton__line--badge mfd-skeleton__pulse"></div>
        </div>

        <?php /* Ring + breakdown bars */ ?>
        <div class="mfd-skeleton__strength-body">
            <?php /* SVG ring placeholder — exact 96×96 to match the live ring */ ?>
            <div class="mfd-skeleton__ring mfd-skeleton__pulse" role="presentation" aria-hidden="true">
                <svg
                    width="96"
                    height="96"
                    viewBox="0 0 96 96"
                    aria-hidden="true"
                    focusable="false"
                >
                    <circle
                        cx="48" cy="48" r="40"
                        fill="none"
                        stroke="transparent"
                        stroke-width="8"
                    />
                </svg>
            </div>

            <?php /* 5 breakdown bar skeletons */ ?>
            <div class="mfd-skeleton__bars">
                <?php for ($i = 0; $i < 5; $i++) : ?>
                <div class="mfd-skeleton__bar-item" role="presentation">
                    <div class="mfd-skeleton__line mfd-skeleton__line--bar-label mfd-skeleton__pulse"></div>
                    <div class="mfd-skeleton__bar-track" role="presentation">
                        <div class="mfd-skeleton__bar-fill mfd-skeleton__pulse"></div>
                    </div>
                </div>
                <?php endfor; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php /* ──────────────────────────────────────────────────────────────────
     * SKELETON ZONE 4 — Recent Downloads / Activity Matrix
     * Mirrors: ActivityGrid (table header + 5 data rows + pagination strip).
     * ─────────────────────────────────────────────────────────────────────── */ ?>
    <?php if ($show_activity) : ?>
    <div
        class="mfd-skeleton__activity-section"
        aria-hidden="true"
        data-mfd-skeleton="activity"
        role="presentation"
    >
        <?php /* Section header */ ?>
        <div class="mfd-skeleton__activity-header">
            <div class="mfd-skeleton__line mfd-skeleton__line--section-title mfd-skeleton__pulse"></div>
            <div class="mfd-skeleton__line mfd-skeleton__line--badge mfd-skeleton__pulse"></div>
        </div>

        <?php /* Column headers strip */ ?>
        <div class="mfd-skeleton__table-head" role="presentation">
            <div class="mfd-skeleton__th mfd-skeleton__pulse"></div>
            <div class="mfd-skeleton__th mfd-skeleton__th--wide mfd-skeleton__pulse"></div>
            <div class="mfd-skeleton__th mfd-skeleton__pulse"></div>
        </div>

        <?php /* 5 data row skeletons */ ?>
        <div class="mfd-skeleton__table-body" role="presentation">
            <?php for ($i = 0; $i < 5; $i++) : ?>
            <div class="mfd-skeleton__table-row" role="presentation">
                <div class="mfd-skeleton__td mfd-skeleton__td--badge mfd-skeleton__pulse"></div>
                <div class="mfd-skeleton__td mfd-skeleton__td--detail mfd-skeleton__pulse"></div>
                <div class="mfd-skeleton__td mfd-skeleton__td--date mfd-skeleton__pulse"></div>
            </div>
            <?php endfor; ?>
        </div>

        <?php /* Pagination strip */ ?>
        <div class="mfd-skeleton__pagination" role="presentation">
            <div class="mfd-skeleton__line mfd-skeleton__line--page-info mfd-skeleton__pulse"></div>
            <div class="mfd-skeleton__pagination-btns">
                <div class="mfd-skeleton__page-btn mfd-skeleton__pulse"></div>
                <div class="mfd-skeleton__page-btn mfd-skeleton__pulse"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php /* ──────────────────────────────────────────────────────────────────
     * NOSCRIPT — Accessibility fallback. Displayed only when JS is disabled.
     * All user-specific data is fetched client-side, so without JS the block
     * cannot hydrate. We surface a clear, accessible message instead.
     * ─────────────────────────────────────────────────────────────────────── */ ?>
    <noscript>
        <div class="mfd-noscript-notice" role="status">
            <p>
                <?php esc_html_e(
                    'This dashboard requires JavaScript to display live user metrics. Please enable JavaScript in your browser settings.',
                    'mfd-dashboard-widget'
                ); ?>
            </p>
        </div>
    </noscript>

</div><?php /* end .mfd-dashboard-block */
