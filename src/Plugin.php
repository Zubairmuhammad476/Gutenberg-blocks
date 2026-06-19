<?php
/**
 * Plugin Orchestrator — boots all subsystems via a strict singleton.
 *
 * Responsibilities:
 *   - Delegate activation/deactivation to the DB Installer.
 *   - Register REST API routes via RestController.
 *   - Register the Gutenberg block via BlockRegistrar.
 *   - Load the plugin text domain.
 *
 * @package MFD\DashboardWidget
 */

declare(strict_types=1);

namespace MFD\DashboardWidget;

use MFD\DashboardWidget\Api\RestController;
use MFD\DashboardWidget\Block\BlockRegistrar;
use MFD\DashboardWidget\Database\Installer;

/**
 * Class Plugin
 *
 * Singleton orchestrator. Hydrates and connects all subsystems.
 *
 * @package MFD\DashboardWidget
 */
final class Plugin
{
    // -----------------------------------------------------------------------
    // Singleton guard.
    // -----------------------------------------------------------------------
    private static ?Plugin $instance = null;

    // -----------------------------------------------------------------------
    // Subsystem instances.
    // -----------------------------------------------------------------------
    private readonly Installer      $installer;
    private readonly RestController $restController;
    private readonly BlockRegistrar $blockRegistrar;

    /**
     * Private constructor — enforces singleton via getInstance().
     */
    private function __construct()
    {
        $this->installer      = new Installer();
        $this->restController = new RestController();
        $this->blockRegistrar = new BlockRegistrar();
    }

    /**
     * Returns the sole Plugin instance. Creates it on first call.
     *
     * @return self
     */
    public static function getInstance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    // -----------------------------------------------------------------------
    // Lifecycle methods.
    // -----------------------------------------------------------------------

    /**
     * Boot all plugin subsystems by hooking into WordPress action events.
     * Called on `plugins_loaded` — never directly.
     */
    public function boot(): void
    {
        // Text domain — i18n must be the very first action.
        add_action('init', [$this, 'loadTextDomain'], 1);

        // REST API — register routes on rest_api_init.
        add_action('rest_api_init', [$this->restController, 'register']);

        // Block — register on init after text domain is loaded.
        add_action('init', [$this->blockRegistrar, 'register'], 10);

        // Track logins — hook into wp_login to update last_login column.
        add_action('wp_login', [$this, 'onUserLogin'], 10, 2);
    }

    /**
     * Plugin activation handler — runs DB migrations.
     *
     * Called by register_activation_hook in the main plugin file.
     * Intentionally public so the anonymous closure can reach it.
     */
    public function activate(): void
    {
        $this->installer->run();
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation handler.
     */
    public function deactivate(): void
    {
        flush_rewrite_rules();
    }

    // -----------------------------------------------------------------------
    // WordPress action callbacks.
    // -----------------------------------------------------------------------

    /**
     * Load the plugin text domain for translations.
     */
    public function loadTextDomain(): void
    {
        load_plugin_textdomain(
            'mfd-dashboard-widget',
            false,
            dirname(MFD_PLUGIN_BASE) . '/languages'
        );
    }

    /**
     * Fired on the `wp_login` action — records the login event in the DB.
     *
     * @param string   $userLogin Username (slug).
     * @param \WP_User $user      The authenticated WP_User object.
     */
    public function onUserLogin(string $userLogin, \WP_User $user): void
    {
        try {
            global $wpdb;

            $repo = new \MFD\DashboardWidget\Database\MetricsRepository($wpdb);
            $repo->touchLastLogin($user->ID);
            $repo->appendActivityEvent(
                $user->ID,
                'login',
                ['username' => sanitize_user($userLogin)]
            );
        } catch (\Throwable $e) {
            // Swallow silently — never interrupt the login flow.
            // In production, hook a real logger (e.g., WP_DEBUG_LOG) here.
            if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log('MFD Dashboard Widget [onUserLogin]: ' . $e->getMessage());
            }
        }
    }

    // -----------------------------------------------------------------------
    // Prevent PHP magic methods from breaking the singleton.
    // -----------------------------------------------------------------------

    private function __clone(): void {}

    /**
     * @throws \RuntimeException Always — unserialization is forbidden.
     */
    public function __wakeup(): never
    {
        throw new \RuntimeException('MFD Plugin singleton cannot be unserialized.');
    }
}
