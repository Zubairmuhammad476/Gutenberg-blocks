<?php
/**
 * RestController — registers all MFD REST API routes under the
 * `custom-dashboard/v1` namespace.
 *
 * Architecture:
 *   - This class is solely responsible for route *registration*.
 *   - Actual permission callbacks and response logic live in MetricsEndpoint.
 *   - Follows the single-responsibility principle — router, not handler.
 *
 * REST Namespace: custom-dashboard/v1
 * Routes registered:
 *   GET  /custom-dashboard/v1/user-metrics        — current user's full metrics.
 *   GET  /custom-dashboard/v1/user-metrics/activity — paginated activity log.
 *   POST /custom-dashboard/v1/user-metrics/strength — update profile strength.
 *
 * @package MFD\DashboardWidget\Api
 */

declare(strict_types=1);

namespace MFD\DashboardWidget\Api;

/**
 * Class RestController
 *
 * Registers all MFD REST routes under the custom-dashboard/v1 namespace.
 *
 * @package MFD\DashboardWidget\Api
 */
final class RestController
{
    /**
     * The REST API namespace shared by all MFD endpoints.
     * Aligns with the project specification: `/custom-dashboard/v1/`.
     */
    public const NAMESPACE = 'custom-dashboard/v1';

    private readonly MetricsEndpoint $metricsEndpoint;

    public function __construct()
    {
        $this->metricsEndpoint = new MetricsEndpoint();
    }

    /**
     * Register all plugin REST routes.
     *
     * Hooked into `rest_api_init` by Plugin::boot().
     * Every route delegates permission + response handling to MetricsEndpoint.
     */
    public function register(): void
    {
        // ----------------------------------------------------------------
        // Route 1: GET /custom-dashboard/v1/user-metrics
        //   Returns the full metrics payload for the currently logged-in user.
        // ----------------------------------------------------------------
        register_rest_route(
            self::NAMESPACE,
            '/user-metrics',
            [
                'methods'             => \WP_REST_Server::READABLE, // GET, HEAD
                'callback'            => [$this->metricsEndpoint, 'getMetrics'],
                'permission_callback' => [$this->metricsEndpoint, 'isAuthorized'],
                'args'                => [],
            ]
        );

        // ----------------------------------------------------------------
        // Route 2: GET /custom-dashboard/v1/user-metrics/activity
        //   Returns paginated activity log for the current user.
        // ----------------------------------------------------------------
        register_rest_route(
            self::NAMESPACE,
            '/user-metrics/activity',
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this->metricsEndpoint, 'getActivity'],
                'permission_callback' => [$this->metricsEndpoint, 'isAuthorized'],
                'args'                => [
                    'page'     => [
                        'description'       => __('Page number for activity log pagination.', 'mfd-dashboard-widget'),
                        'type'              => 'integer',
                        'default'           => 1,
                        'minimum'           => 1,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => static fn ($v) => is_numeric($v) && (int) $v >= 1,
                    ],
                    'per_page' => [
                        'description'       => __('Number of activity items per page (max 50).', 'mfd-dashboard-widget'),
                        'type'              => 'integer',
                        'default'           => 10,
                        'minimum'           => 1,
                        'maximum'           => 50,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => static fn ($v) => is_numeric($v) && (int) $v >= 1 && (int) $v <= 50,
                    ],
                ],
            ]
        );

        // ----------------------------------------------------------------
        // Route 3: POST /custom-dashboard/v1/user-metrics/strength
        //   Allows the client to push a recalculated profile strength score.
        // ----------------------------------------------------------------
        register_rest_route(
            self::NAMESPACE,
            '/user-metrics/strength',
            [
                'methods'             => \WP_REST_Server::CREATABLE, // POST
                'callback'            => [$this->metricsEndpoint, 'updateProfileStrength'],
                'permission_callback' => [$this->metricsEndpoint, 'isAuthorized'],
                'args'                => [
                    'score' => [
                        'description'       => __('Profile completion score (0–100).', 'mfd-dashboard-widget'),
                        'type'              => 'integer',
                        'required'          => true,
                        'minimum'           => 0,
                        'maximum'           => 100,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => static fn ($v) => is_numeric($v) && (int) $v >= 0 && (int) $v <= 100,
                    ],
                ],
            ]
        );
    }
}
