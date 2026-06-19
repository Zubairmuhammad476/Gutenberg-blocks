<?php
/**
 * Plugin Name:       Micro-Frontend Dashboard Widget
 * Plugin URI:        https://github.com/Zubairmuhammad476/Gutenberg-blocks
 * Description:       A highly interactive, performance-optimized Gutenberg block for enterprise membership dashboards and client portals. Securely aggregates live user metrics via a custom DB engine and protected REST API.
 * Version:           1.0.0
 * Requires at least: 6.7
 * Requires PHP:      8.1
 * Author:            Zubair Muhammad
 * Author URI:        https://github.com/Zubairmuhammad476
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mfd-dashboard-widget
 * Domain Path:       /languages
 *
 * @package MFD\DashboardWidget
 */

declare(strict_types=1);

// Prevent direct file access — non-negotiable.
if (! defined('ABSPATH')) {
    exit;
}

// -------------------------------------------------------------------------
// Plugin Constants — centralised, single source of truth.
// -------------------------------------------------------------------------
define('MFD_VERSION',       '1.0.0');
define('MFD_PLUGIN_FILE',   __FILE__);
define('MFD_PLUGIN_DIR',    plugin_dir_path(__FILE__));
define('MFD_PLUGIN_URL',    plugin_dir_url(__FILE__));
define('MFD_PLUGIN_BASE',   plugin_basename(__FILE__));
define('MFD_MIN_WP',        '6.7');
define('MFD_MIN_PHP',       '8.1');

// -------------------------------------------------------------------------
// Composer PSR-4 Autoloader — must exist before any class reference.
// -------------------------------------------------------------------------
$mfd_autoloader = MFD_PLUGIN_DIR . 'vendor/autoload.php';

if (! file_exists($mfd_autoloader)) {
    // Surface a clear admin notice rather than a fatal PHP error.
    add_action(
        'admin_notices',
        static function (): void {
            printf(
                '<div class="notice notice-error"><p><strong>MFD Dashboard Widget:</strong> %s</p></div>',
                esc_html__(
                    'Composer autoloader not found. Run `composer install` inside the plugin directory.',
                    'mfd-dashboard-widget'
                )
            );
        }
    );
    return; // Bail — nothing else can boot without the autoloader.
}

require_once $mfd_autoloader;

use MFD\DashboardWidget\Plugin;

// -------------------------------------------------------------------------
// Activation Hook — must be registered at file-load time (pre-boot).
// Instantiates the Plugin singleton and delegates to its activate() method.
// -------------------------------------------------------------------------
register_activation_hook(
    __FILE__,
    static function (): void {
        if (! mfd_meets_requirements()) {
            // Translators: 1: Required WP version, 2: Required PHP version.
            $message = sprintf(
                __(
                    'MFD Dashboard Widget requires WordPress %1$s+ and PHP %2$s+. Plugin activation aborted.',
                    'mfd-dashboard-widget'
                ),
                MFD_MIN_WP,
                MFD_MIN_PHP
            );
            wp_die(
                esc_html($message),
                esc_html__('Plugin Activation Error', 'mfd-dashboard-widget'),
                ['back_link' => true]
            );
        }

        Plugin::getInstance()->activate();
    }
);

// -------------------------------------------------------------------------
// Deactivation Hook.
// -------------------------------------------------------------------------
register_deactivation_hook(
    __FILE__,
    static function (): void {
        Plugin::getInstance()->deactivate();
    }
);

// -------------------------------------------------------------------------
// Bootstrap — fires after all plugins are loaded for maximum compatibility.
// -------------------------------------------------------------------------
add_action(
    'plugins_loaded',
    static function (): void {
        if (! mfd_meets_requirements()) {
            return; // Requirements already surfaced via admin notice elsewhere.
        }

        Plugin::getInstance()->boot();
    }
);

// -------------------------------------------------------------------------
// Helper: Environment requirement gate.
// -------------------------------------------------------------------------
/**
 * Check whether the current environment meets the minimum WP/PHP requirements.
 *
 * @return bool True if requirements are satisfied.
 */
function mfd_meets_requirements(): bool
{
    global $wp_version;

    return version_compare(PHP_VERSION, MFD_MIN_PHP, '>=')
        && version_compare($wp_version, MFD_MIN_WP, '>=');
}
