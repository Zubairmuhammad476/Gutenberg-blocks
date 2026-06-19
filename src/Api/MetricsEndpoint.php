<?php
/**
 * MetricsEndpoint — request handlers and permission callbacks for all
 * MFD REST API routes.
 *
 * Security model:
 *   - isAuthorized() verifies: user is logged in + nonce is valid.
 *   - Users can ONLY access their own metrics (user_id = get_current_user_id()).
 *   - Administrators may access any user's data via the optional `user_id` param.
 *   - All response data is routed through UserMetricsDTO::toApiArray() before
 *     being handed to WP_REST_Response — never raw $wpdb rows.
 *
 * Nonce strategy:
 *   - Nonce action:  'mfd_rest_nonce'
 *   - Transport:     X-MFD-Nonce HTTP request header (set by frontend.js).
 *   - wp_verify_nonce() is called inside isAuthorized() on every request.
 *
 * @package MFD\DashboardWidget\Api
 */

declare(strict_types=1);

namespace MFD\DashboardWidget\Api;

use MFD\DashboardWidget\Database\MetricsRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Class MetricsEndpoint
 *
 * Handles permission checking and response building for all user-metrics routes.
 *
 * @package MFD\DashboardWidget\Api
 */
final class MetricsEndpoint
{
    /**
     * Nonce action string — must match the action used in wp_create_nonce()
     * on the PHP render side and verified here on every REST request.
     */
    public const NONCE_ACTION = 'mfd_rest_nonce';

    /**
     * HTTP header name used to transport the nonce from the client.
     * WordPress REST nonces can also use X-WP-Nonce, but we use a
     * plugin-specific header to avoid conflicts.
     */
    public const NONCE_HEADER = 'X-MFD-Nonce';

    private readonly MetricsRepository $repository;

    public function __construct()
    {
        global $wpdb;
        $this->repository = new MetricsRepository($wpdb);
    }

    // -----------------------------------------------------------------------
    // Permission Callback
    // -----------------------------------------------------------------------

    /**
     * Authorization gate for all MFD REST routes.
     *
     * Checks in order:
     *   1. User is authenticated (is_user_logged_in()).
     *   2. Nonce from X-MFD-Nonce header is valid for 'mfd_rest_nonce'.
     *
     * Returns true to grant access, WP_Error to deny with a meaningful status.
     *
     * @param  WP_REST_Request $request Incoming REST request.
     * @return true|WP_Error
     */
    public function isAuthorized(WP_REST_Request $request): true|WP_Error
    {
        // Gate 1 — user must be logged in.
        if (! is_user_logged_in()) {
            return new WP_Error(
                'mfd_rest_unauthenticated',
                __('You must be logged in to access dashboard metrics.', 'mfd-dashboard-widget'),
                ['status' => 401]
            );
        }

        // Gate 2 — nonce verification.
        $nonce = $request->get_header(self::NONCE_HEADER);

        if (empty($nonce)) {
            return new WP_Error(
                'mfd_rest_missing_nonce',
                __('Security token is missing from the request.', 'mfd-dashboard-widget'),
                ['status' => 403]
            );
        }

        // wp_verify_nonce returns false|1|2. 1 = valid (first 12h), 2 = valid (12-24h).
        $nonceResult = wp_verify_nonce(sanitize_text_field(wp_unslash($nonce)), self::NONCE_ACTION);

        if (false === $nonceResult) {
            return new WP_Error(
                'mfd_rest_invalid_nonce',
                __('Security token is invalid or has expired. Please refresh the page.', 'mfd-dashboard-widget'),
                ['status' => 403]
            );
        }

        return true;
    }

    // -----------------------------------------------------------------------
    // Route Handlers
    // -----------------------------------------------------------------------

    /**
     * GET /custom-dashboard/v1/user-metrics
     *
     * Returns the full telemetry payload for the currently authenticated user.
     * If no DB record exists yet, returns a zeroed-out defaults payload
     * (does NOT auto-create — avoids side effects on read).
     *
     * @param  WP_REST_Request $request Incoming REST request.
     * @return WP_REST_Response
     */
    public function getMetrics(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        $user   = get_userdata($userId);

        if (! $user instanceof \WP_User) {
            return $this->errorResponse(
                'mfd_user_not_found',
                __('Could not resolve current user data.', 'mfd-dashboard-widget'),
                404
            );
        }

        try {
            $dto = $this->repository->findByUserId($userId);

            $metricsPayload = $dto
                ? $dto->toApiArray()
                : $this->buildDefaultMetricsPayload($userId);

            $responseData = [
                'success' => true,
                'data'    => array_merge(
                    $metricsPayload,
                    [
                        'display_name'       => sanitize_text_field($user->display_name),
                        'avatar_url'         => esc_url_raw(get_avatar_url($userId, ['size' => 96])),
                        'email'              => sanitize_email($user->user_email),
                        'formatted_login'    => $dto ? $dto->formattedLastLogin() : __('Never', 'mfd-dashboard-widget'),
                        'nonce'              => wp_create_nonce(self::NONCE_ACTION), // Refresh nonce on each response.
                    ]
                ),
            ];
        } catch (\Throwable $e) {
            return $this->errorResponse(
                'mfd_db_error',
                __('A database error occurred while fetching metrics.', 'mfd-dashboard-widget'),
                500
            );
        }

        return new WP_REST_Response($responseData, 200);
    }

    /**
     * GET /custom-dashboard/v1/user-metrics/activity
     *
     * Returns a paginated slice of the current user's activity log.
     * Page and per_page are validated and sanitized at the route level.
     *
     * @param  WP_REST_Request $request Incoming REST request.
     * @return WP_REST_Response
     */
    public function getActivity(WP_REST_Request $request): WP_REST_Response
    {
        $userId  = get_current_user_id();
        $page    = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');

        try {
            $paginated = $this->repository->getPaginatedActivity($userId, $page, $perPage);
        } catch (\Throwable $e) {
            return $this->errorResponse(
                'mfd_db_error',
                __('Failed to fetch activity log.', 'mfd-dashboard-widget'),
                500
            );
        }

        return new WP_REST_Response(
            [
                'success' => true,
                'data'    => $paginated,
            ],
            200
        );
    }

    /**
     * POST /custom-dashboard/v1/user-metrics/strength
     *
     * Accepts a `score` (0–100) and persists it for the current user.
     * Validated and sanitized by the route's `args` schema at registration.
     *
     * @param  WP_REST_Request $request Incoming REST request with `score` body param.
     * @return WP_REST_Response
     */
    public function updateProfileStrength(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        $score  = (int) $request->get_param('score');

        // Clamp defensively — route-level validation should have caught OOB values.
        $score = max(0, min(100, $score));

        try {
            $dto = $this->repository->upsert($userId, ['profile_strength' => $score]);
        } catch (\Throwable $e) {
            return $this->errorResponse(
                'mfd_db_error',
                __('Failed to update profile strength.', 'mfd-dashboard-widget'),
                500
            );
        }

        return new WP_REST_Response(
            [
                'success' => true,
                'data'    => [
                    'profile_strength' => $dto->profileStrength,
                    'updated_at'       => $dto->updatedAt,
                ],
            ],
            200
        );
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Build a zeroed-out metrics payload for users with no telemetry record yet.
     *
     * @param  int $userId WordPress user ID.
     * @return array<string, mixed>
     */
    private function buildDefaultMetricsPayload(int $userId): array
    {
        return [
            'id'               => 0,
            'user_id'          => $userId,
            'last_login'       => null,
            'profile_strength' => 0,
            'download_count'   => 0,
            'activity_log'     => [],
            'created_at'       => '',
            'updated_at'       => '',
        ];
    }

    /**
     * Build a standardized WP_REST_Response for error states.
     *
     * @param  string $code    Machine-readable error code.
     * @param  string $message Human-readable message (pre-translated).
     * @param  int    $status  HTTP status code.
     * @return WP_REST_Response
     */
    private function errorResponse(string $code, string $message, int $status): WP_REST_Response
    {
        return new WP_REST_Response(
            [
                'success' => false,
                'code'    => $code,
                'message' => $message,
            ],
            $status
        );
    }
}
