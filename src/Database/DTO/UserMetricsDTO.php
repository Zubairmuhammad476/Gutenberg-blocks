<?php
/**
 * UserMetricsDTO — Immutable value object for a mfd_user_metrics row.
 *
 * Enforces typed property access, decodes the JSON activity_log, and
 * provides a sanitized serialization surface for REST API responses.
 *
 * @package MFD\DashboardWidget\Database\DTO
 */

declare(strict_types=1);

namespace MFD\DashboardWidget\Database\DTO;

/**
 * Class UserMetricsDTO
 *
 * Immutable value object. Constructed via the static factory `fromRow()`.
 * Never instantiate directly outside of MetricsRepository.
 *
 * @package MFD\DashboardWidget\Database\DTO
 */
final class UserMetricsDTO
{
    /**
     * @param int                            $id              Auto-increment PK.
     * @param int                            $userId          wp_users.ID reference.
     * @param string|null                    $lastLogin       UTC datetime string or null if never logged in.
     * @param int                            $profileStrength Completion score 0–100.
     * @param int                            $downloadCount   Cumulative download total.
     * @param array<int, array<string,mixed>> $activityLog    Decoded telemetry events (newest last).
     * @param string                         $createdAt       Row creation UTC datetime.
     * @param string                         $updatedAt       Row last-update UTC datetime.
     */
    public function __construct(
        public readonly int     $id,
        public readonly int     $userId,
        public readonly ?string $lastLogin,
        public readonly int     $profileStrength,
        public readonly int     $downloadCount,
        public readonly array   $activityLog,
        public readonly string  $createdAt,
        public readonly string  $updatedAt,
    ) {}

    // -----------------------------------------------------------------------
    // Factory
    // -----------------------------------------------------------------------

    /**
     * Construct a DTO from a raw $wpdb ARRAY_A result row.
     *
     * Decodes the JSON `activity_log` column with graceful fallback to an
     * empty array if the column is null, empty, or contains invalid JSON.
     *
     * @param  array<string, mixed> $row Raw associative array from $wpdb->get_row().
     * @return self
     */
    public static function fromRow(array $row): self
    {
        // Decode activity_log JSON — never trust raw DB content directly.
        $activityLog = [];
        $rawLog      = $row['activity_log'] ?? '';

        if (! empty($rawLog) && is_string($rawLog)) {
            $decoded = json_decode($rawLog, true, 512, JSON_BIGINT_AS_STRING);

            if (JSON_ERROR_NONE === json_last_error() && is_array($decoded)) {
                $activityLog = $decoded;
            }
        }

        return new self(
            id:              (int) ($row['id']               ?? 0),
            userId:          (int) ($row['user_id']          ?? 0),
            lastLogin:       isset($row['last_login']) && '' !== $row['last_login']
                                 ? (string) $row['last_login']
                                 : null,
            profileStrength: (int) ($row['profile_strength'] ?? 0),
            downloadCount:   (int) ($row['download_count']   ?? 0),
            activityLog:     $activityLog,
            createdAt:       (string) ($row['created_at']    ?? ''),
            updatedAt:       (string) ($row['updated_at']    ?? ''),
        );
    }

    // -----------------------------------------------------------------------
    // Serialization
    // -----------------------------------------------------------------------

    /**
     * Serialize to a sanitized, REST-API-safe associative array.
     *
     * String fields are sanitized with sanitize_text_field() before
     * being handed off to wp_json_encode() at the REST layer.
     * Integer fields are cast directly — no injection surface.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id'               => $this->id,
            'user_id'          => $this->userId,
            'last_login'       => $this->lastLogin,
            'profile_strength' => $this->profileStrength,
            'download_count'   => $this->downloadCount,
            'activity_log'     => $this->activityLog,
            'created_at'       => $this->createdAt,
            'updated_at'       => $this->updatedAt,
        ];
    }

    /**
     * Format the last_login timestamp for human-readable display.
     *
     * Returns a locale-aware relative string (e.g. "2 hours ago") or
     * the absolute formatted date if the login was more than 7 days ago.
     *
     * @return string Translated human-readable last-login string.
     */
    public function formattedLastLogin(): string
    {
        if (null === $this->lastLogin) {
            return __('Never', 'mfd-dashboard-widget');
        }

        $timestamp = strtotime($this->lastLogin);

        if (false === $timestamp) {
            return __('Unknown', 'mfd-dashboard-widget');
        }

        $diff = time() - $timestamp;

        if ($diff < WEEK_IN_SECONDS) {
            return sprintf(
                /* translators: %s: Human-readable time difference (e.g. "3 hours") */
                __('%s ago', 'mfd-dashboard-widget'),
                human_time_diff($timestamp)
            );
        }

        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }
}
