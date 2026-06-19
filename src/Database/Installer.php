<?php
/**
 * Database Installer — idempotent schema migration engine.
 *
 * Responsibilities:
 *   - Verify existence of the `{prefix}mfd_user_metrics` custom table.
 *   - Create or upgrade via WordPress's dbDelta() — non-destructive by design.
 *   - Track schema version in wp_options to prevent redundant migrations.
 *
 * dbDelta() constraint notes (WordPress core requirement — do NOT alter):
 *   - Two spaces after PRIMARY KEY.
 *   - Each column definition on its own line.
 *   - No trailing comma after the final KEY definition.
 *   - Column definitions must use lowercase SQL keywords.
 *
 * @package MFD\DashboardWidget\Database
 */

declare(strict_types=1);

namespace MFD\DashboardWidget\Database;

/**
 * Class Installer
 *
 * Schema migration engine for the mfd_user_metrics custom table.
 *
 * @package MFD\DashboardWidget\Database
 */
final class Installer
{
    /**
     * Semantic schema version. Increment (e.g., '1.1.0') when altering
     * the column schema to trigger a re-migration on plugin update.
     */
    private const SCHEMA_VERSION     = '1.0.0';

    /**
     * wp_options key that stores the currently installed schema version.
     */
    private const SCHEMA_VERSION_KEY = 'mfd_db_schema_version';

    /**
     * Suffix appended to the $wpdb->prefix to form the full table name.
     */
    public const TABLE_SUFFIX        = 'mfd_user_metrics';

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Run the installer. Idempotent — safe to call on every plugin activation.
     *
     * If the stored schema version already matches SCHEMA_VERSION, this is
     * a no-op. If an older version is detected, dbDelta performs an upgrade.
     */
    public function run(): void
    {
        $installedVersion = (string) get_option(self::SCHEMA_VERSION_KEY, '0.0.0');

        if (version_compare($installedVersion, self::SCHEMA_VERSION, '>=')) {
            return; // Schema is current — nothing to do.
        }

        $this->createOrUpgradeMetricsTable();

        /**
         * Persist the new schema version.
         * Third argument `false` prevents auto-loading this option on every
         * page load — it's only needed during activation.
         */
        update_option(self::SCHEMA_VERSION_KEY, self::SCHEMA_VERSION, false);
    }

    /**
     * Return the fully-qualified table name including the global $wpdb prefix.
     *
     * Example: `wp_mfd_user_metrics`
     *
     * @return string
     */
    public function getTableName(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    // -----------------------------------------------------------------------
    // Private implementation
    // -----------------------------------------------------------------------

    /**
     * Execute the CREATE TABLE statement via dbDelta.
     *
     * Table: `{prefix}mfd_user_metrics`
     *
     * Schema columns:
     *
     *   id                — BIGINT UNSIGNED PK. Auto-increment row identifier.
     *   user_id           — BIGINT UNSIGNED. References wp_users.ID. UNIQUE.
     *   last_login        — DATETIME (UTC). Nullable — NULL until first login.
     *   profile_strength  — TINYINT UNSIGNED (0–100). Computed completeness score.
     *   download_count    — INT UNSIGNED. Cumulative total downloads by user.
     *   activity_log      — LONGTEXT. JSON array of timestamped telemetry events.
     *                       MariaDB 10.5 / MySQL 8.0 native JSON is not used here
     *                       deliberately — LONGTEXT + application-level decode
     *                       guarantees portability across shared hosting environments
     *                       that may have older DB builds despite the 10.5 floor.
     *   created_at        — DATETIME NOT NULL. Set once on INSERT via DEFAULT.
     *   updated_at        — DATETIME NOT NULL. Auto-maintained by ON UPDATE.
     *
     * Indexes:
     *   PRIMARY KEY       id
     *   UNIQUE KEY        user_id            (one telemetry row per user)
     *   KEY               idx_last_login     (sort/filter by recency)
     *   KEY               idx_profile_str    (sort/filter by completeness)
     */
    private function createOrUpgradeMetricsTable(): void
    {
        global $wpdb;

        // dbDelta requires this function to be loaded outside of admin context.
        if (! function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }

        $table          = $this->getTableName();
        $charsetCollate = $wpdb->get_charset_collate(); // e.g. DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci

        /**
         * CRITICAL dbDelta formatting rules — do NOT reformat this heredoc:
         *   1. Two spaces between "PRIMARY KEY" and the opening parenthesis.
         *   2. Each column on its own line with NO trailing comma on the last KEY.
         *   3. The closing parenthesis and $charsetCollate must be on the same line.
         */
        $sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  last_login datetime DEFAULT NULL,
  profile_strength tinyint(3) unsigned NOT NULL DEFAULT 0,
  download_count int(10) unsigned NOT NULL DEFAULT 0,
  activity_log longtext DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY user_id (user_id),
  KEY idx_last_login (last_login),
  KEY idx_profile_str (profile_strength)
) {$charsetCollate};";

        $results = dbDelta($sql);

        // Log dbDelta output when WP_DEBUG_LOG is active for DBA visibility.
        if (! empty($results) && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            foreach ($results as $query => $message) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log(sprintf('MFD Installer [dbDelta]: %s => %s', $query, $message));
            }
        }
    }
}
