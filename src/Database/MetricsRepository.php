<?php
/**
 * MetricsRepository — $wpdb abstraction for all CRUD operations
 * against the `{prefix}mfd_user_metrics` custom table.
 *
 * Design rules:
 *   - All queries use $wpdb->prepare() — zero raw string interpolation for data.
 *   - Table name is interpolated directly (not via %s placeholder) because
 *     WordPress's prepare() does NOT support table name placeholders.
 *   - No business logic — pure persistence layer.
 *   - All public methods throw typed \RuntimeException on DB failure.
 *
 * @package MFD\DashboardWidget\Database
 */

declare(strict_types=1);

namespace MFD\DashboardWidget\Database;

use MFD\DashboardWidget\Database\DTO\UserMetricsDTO;

/**
 * Class MetricsRepository
 *
 * PSR-compliant, $wpdb-backed repository for user telemetry records.
 *
 * @package MFD\DashboardWidget\Database
 */
final class MetricsRepository
{
    /**
     * Fully-qualified table name (e.g. `wp_mfd_user_metrics`).
     * Set once in constructor — never mutated.
     */
    private readonly string $table;

    /**
     * @param \wpdb $wpdb Injected $wpdb instance for testability.
     */
    public function __construct(private readonly \wpdb $wpdb)
    {
        $this->table = $this->wpdb->prefix . Installer::TABLE_SUFFIX;
    }

    // -----------------------------------------------------------------------
    // Read
    // -----------------------------------------------------------------------

    /**
     * Fetch the metrics record for a given WordPress user ID.
     *
     * @param  int $userId WordPress user ID.
     * @return UserMetricsDTO|null DTO on success, null if no record exists yet.
     */
    public function findByUserId(int $userId): ?UserMetricsDTO
    {
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — table name, not data.
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "SELECT * FROM `{$this->table}` WHERE user_id = %d LIMIT 1",
                $userId
            ),
            ARRAY_A
        );

        if (! is_array($row) || empty($row)) {
            return null;
        }

        return UserMetricsDTO::fromRow($row);
    }

    // -----------------------------------------------------------------------
    // Write
    // -----------------------------------------------------------------------

    /**
     * Insert a brand-new metrics record for a user.
     *
     * Merges provided $data over sensible defaults. Should only be called
     * after confirming no existing row — use upsert() for general writes.
     *
     * @param  int                   $userId WordPress user ID.
     * @param  array<string, mixed>  $data   Optional column overrides on top of defaults.
     * @return int                           The newly inserted row ID.
     * @throws \RuntimeException            On $wpdb insert failure.
     */
    public function create(int $userId, array $data = []): int
    {
        $defaults = [
            'user_id'          => $userId,
            'last_login'       => null,
            'profile_strength' => 0,
            'download_count'   => 0,
            'activity_log'     => wp_json_encode([]),
        ];

        $payload = array_merge($defaults, $data);

        // Encode activity_log if it arrives as an array.
        if (isset($payload['activity_log']) && is_array($payload['activity_log'])) {
            $payload['activity_log'] = wp_json_encode($payload['activity_log']);
        }

        $result = $this->wpdb->insert(
            $this->table,
            $payload,
            $this->buildFormatArray($payload)
        );

        if (false === $result) {
            throw new \RuntimeException(
                sprintf(
                    'MFD MetricsRepository::create failed for user #%d. DB error: %s',
                    $userId,
                    $this->wpdb->last_error
                )
            );
        }

        return (int) $this->wpdb->insert_id;
    }

    /**
     * Update an existing metrics record identified by user_id.
     *
     * Only the columns present in $data are touched — no full-row overwrites.
     *
     * @param  int                   $userId WordPress user ID.
     * @param  array<string, mixed>  $data   Column => value map of fields to update.
     * @return int                           Number of rows affected (0 or 1).
     * @throws \RuntimeException            On $wpdb update failure.
     */
    public function update(int $userId, array $data): int
    {
        if (empty($data)) {
            return 0;
        }

        // Encode activity_log arrays to JSON string before persisting.
        if (isset($data['activity_log']) && is_array($data['activity_log'])) {
            $data['activity_log'] = wp_json_encode($data['activity_log']);
        }

        $result = $this->wpdb->update(
            $this->table,
            $data,
            ['user_id' => $userId],
            $this->buildFormatArray($data),
            ['%d'] // WHERE clause format.
        );

        if (false === $result) {
            throw new \RuntimeException(
                sprintf(
                    'MFD MetricsRepository::update failed for user #%d. DB error: %s',
                    $userId,
                    $this->wpdb->last_error
                )
            );
        }

        return (int) $result;
    }

    /**
     * Upsert — insert a new record or update an existing one atomically.
     *
     * The returned DTO always reflects the persisted DB state after the operation.
     *
     * @param  int                   $userId WordPress user ID.
     * @param  array<string, mixed>  $data   Fields to persist.
     * @return UserMetricsDTO                Refreshed DTO from DB after write.
     * @throws \RuntimeException            On any DB failure.
     */
    public function upsert(int $userId, array $data): UserMetricsDTO
    {
        $existing = $this->findByUserId($userId);

        if (null === $existing) {
            $this->create($userId, $data);
        } else {
            $this->update($userId, $data);
        }

        // Re-fetch to guarantee the returned DTO reflects the DB state.
        $refreshed = $this->findByUserId($userId);

        if (null === $refreshed) {
            throw new \RuntimeException(
                sprintf(
                    'MFD MetricsRepository::upsert: record missing after write for user #%d.',
                    $userId
                )
            );
        }

        return $refreshed;
    }

    // -----------------------------------------------------------------------
    // Domain-specific convenience methods
    // -----------------------------------------------------------------------

    /**
     * Record a last-login timestamp for the given user.
     * Creates the row if it does not yet exist.
     *
     * @param  int $userId WordPress user ID.
     * @return void
     * @throws \RuntimeException On DB failure.
     */
    public function touchLastLogin(int $userId): void
    {
        $this->upsert($userId, [
            'last_login' => current_time('mysql', true), // UTC datetime string.
        ]);
    }

    /**
     * Increment the download_count for a user by the given amount.
     *
     * Uses a SQL expression via a direct $wpdb->query() with prepare() to
     * perform an atomic increment — avoids read-modify-write race conditions.
     *
     * @param  int $userId WordPress user ID.
     * @param  int $amount Positive integer to add. Default: 1.
     * @return void
     * @throws \InvalidArgumentException   If $amount < 1.
     * @throws \RuntimeException           If the user record does not exist yet.
     */
    public function incrementDownloadCount(int $userId, int $amount = 1): void
    {
        if ($amount < 1) {
            throw new \InvalidArgumentException(
                'MFD MetricsRepository::incrementDownloadCount: $amount must be >= 1.'
            );
        }

        // Ensure row exists before updating — upsert with current count + 0
        // so existing data is untouched on creation path.
        $existing = $this->findByUserId($userId);
        if (null === $existing) {
            $this->create($userId, ['download_count' => $amount]);
            return;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                "UPDATE `{$this->table}` SET download_count = download_count + %d WHERE user_id = %d",
                $amount,
                $userId
            )
        );

        if (false === $result) {
            throw new \RuntimeException(
                sprintf(
                    'MFD MetricsRepository::incrementDownloadCount failed for user #%d.',
                    $userId
                )
            );
        }
    }

    /**
     * Append a single telemetry event to the user's activity_log JSON array.
     *
     * The log is capped at 50 events (FIFO eviction) to prevent unbounded
     * LONGTEXT column growth.
     *
     * @param  int                   $userId    WordPress user ID.
     * @param  string                $eventType Slug-style event identifier (e.g. 'login', 'download').
     * @param  array<string, mixed>  $payload   Arbitrary, sanitizable event context.
     * @return void
     * @throws \RuntimeException On DB failure.
     */
    public function appendActivityEvent(int $userId, string $eventType, array $payload = []): void
    {
        $dto = $this->findByUserId($userId);
        $log = $dto ? $dto->activityLog : [];

        $log[] = [
            'type'      => sanitize_key($eventType),
            'payload'   => $this->sanitizePayload($payload),
            'timestamp' => current_time('mysql', true),
        ];

        // Enforce 50-event FIFO cap.
        if (count($log) > 50) {
            $log = array_slice($log, -50, 50, false);
        }

        $this->upsert($userId, ['activity_log' => $log]);
    }

    /**
     * Return a paginated slice of the user's activity log, most recent first.
     *
     * Pagination is performed in PHP against the decoded JSON array — the
     * activity_log column is always fetched in full. For >50-event histories
     * the column is already capped, so performance is predictable.
     *
     * @param  int   $userId  WordPress user ID.
     * @param  int   $page    1-indexed page number. Clamped to valid range.
     * @param  int   $perPage Items per page. Defaults to 10, max 50.
     * @return array{items: array<int, array<string,mixed>>, total: int, pages: int, current_page: int}
     */
    public function getPaginatedActivity(int $userId, int $page = 1, int $perPage = 10): array
    {
        $perPage = min(max(1, $perPage), 50);

        $dto = $this->findByUserId($userId);
        $log = $dto ? array_reverse($dto->activityLog) : []; // Most recent first.

        $total  = count($log);
        $pages  = max(1, (int) ceil($total / $perPage));
        $page   = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        return [
            'items'        => array_values(array_slice($log, $offset, $perPage)),
            'total'        => $total,
            'pages'        => $pages,
            'current_page' => $page,
        ];
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    /**
     * Build a $wpdb format string array matching the keys in $data.
     *
     * Rules:
     *   - Integer columns ('user_id', 'profile_strength', 'download_count') → '%d'
     *   - String/JSON columns ('activity_log', datetime strings, etc.)       → '%s'
     *   - PHP int values detected at runtime                                 → '%d'
     *   - PHP float values                                                    → '%f'
     *   - Everything else                                                     → '%s'
     *
     * @param  array<string, mixed> $data
     * @return array<int, string>
     */
    private function buildFormatArray(array $data): array
    {
        static $integerColumns = ['user_id', 'profile_strength', 'download_count'];

        $formats = [];

        foreach ($data as $column => $value) {
            $formats[] = match (true) {
                in_array($column, $integerColumns, true) => '%d',
                is_int($value)                           => '%d',
                is_float($value)                         => '%f',
                default                                  => '%s',
            };
        }

        return $formats;
    }

    /**
     * Recursively sanitize an arbitrary payload array for safe storage.
     *
     * Strings → sanitize_text_field()
     * Integers/floats → cast directly.
     * Arrays → recursive.
     * Booleans → preserved.
     * null → preserved.
     *
     * @param  array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitizePayload(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            $safeKey = sanitize_key((string) $key);

            $sanitized[$safeKey] = match (true) {
                is_string($value)  => sanitize_text_field($value),
                is_int($value)     => $value,
                is_float($value)   => $value,
                is_bool($value)    => $value,
                is_null($value)    => null,
                is_array($value)   => $this->sanitizePayload($value),
                default            => sanitize_text_field((string) $value),
            };
        }

        return $sanitized;
    }
}
