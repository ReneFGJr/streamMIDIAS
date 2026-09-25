<?php
namespace Midas\Storage;
defined('ABSPATH') || exit;

final class Database {
    public const VERSION = '1';
    public static function table(string $name): string {
        global $wpdb;
        return $wpdb->prefix . 'midas_' . $name;
    }
    public static function install(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $collate = $wpdb->get_charset_collate();
        $schemas = [
            'repositories' => "id bigint unsigned NOT NULL AUTO_INCREMENT, name varchar(255) NOT NULL, oai_url text NOT NULL, rest_url text NULL, frequency int unsigned NOT NULL DEFAULT 86400, media_mode varchar(16) NOT NULL DEFAULT 'remote', metadata_prefix varchar(64) NOT NULL DEFAULT 'oai_dc', granularity varchar(32) NOT NULL DEFAULT 'YYYY-MM-DD', formats longtext NULL, PRIMARY KEY  (id)",
            'collections' => "id bigint unsigned NOT NULL AUTO_INCREMENT, repository_id bigint unsigned NOT NULL, spec_hash char(64) NOT NULL, set_spec text NOT NULL, name text NOT NULL, kind varchar(32) NOT NULL DEFAULT 'unknown', enabled tinyint NOT NULL DEFAULT 0, PRIMARY KEY  (id), UNIQUE KEY repository_spec (repository_id,spec_hash), KEY published (enabled)",
            'items' => "id bigint unsigned NOT NULL AUTO_INCREMENT, repository_id bigint unsigned NOT NULL, identifier_hash char(64) NOT NULL, identifier text NOT NULL, title text NOT NULL, author text NULL, item_date varchar(255) NULL, abstract longtext NULL, subjects text NULL, language text NULL, license text NULL, source_url text NULL, datestamp varchar(32) NULL, raw_metadata longtext NULL, deleted tinyint NOT NULL DEFAULT 0, media_status varchar(32) NOT NULL DEFAULT 'unavailable', PRIMARY KEY  (id), UNIQUE KEY repository_identifier (repository_id,identifier_hash), KEY active (deleted), FULLTEXT KEY catalog_search (title,author,abstract,subjects)",
            'item_collections' => "item_id bigint unsigned NOT NULL, collection_id bigint unsigned NOT NULL, seen_run varchar(32) NOT NULL DEFAULT '', PRIMARY KEY  (item_id,collection_id), KEY collection_items (collection_id,item_id)",
            'files' => "id bigint unsigned NOT NULL AUTO_INCREMENT, item_id bigint unsigned NOT NULL, url_hash char(64) NOT NULL, url text NOT NULL, mime varchar(128) NOT NULL, bundle varchar(255) NULL, verified tinyint NOT NULL DEFAULT 1, attachment_id bigint unsigned NOT NULL DEFAULT 0, PRIMARY KEY  (id), UNIQUE KEY item_url (item_id,url_hash)",
            'checkpoints' => "id bigint unsigned NOT NULL AUTO_INCREMENT, repository_id bigint unsigned NOT NULL, collection_id bigint unsigned NOT NULL DEFAULT 0, job varchar(16) NOT NULL, token longtext NULL, payload longtext NULL, record_offset int NOT NULL DEFAULT 0, window_from varchar(32) NULL, window_until varchar(32) NULL, last_success varchar(32) NULL, last_full_success varchar(32) NULL, run_id varchar(32) NULL, status varchar(16) NOT NULL DEFAULT 'idle', attempts int NOT NULL DEFAULT 0, next_run bigint NOT NULL DEFAULT 0, PRIMARY KEY  (id), UNIQUE KEY job_scope (repository_id,collection_id,job), KEY due (status,next_run)",
            'logs' => "id bigint unsigned NOT NULL AUTO_INCREMENT, repository_id bigint unsigned NOT NULL, created_at datetime NOT NULL, level varchar(16) NOT NULL, message text NOT NULL, PRIMARY KEY  (id), KEY repository_time (repository_id,created_at)",
        ];
        foreach ($schemas as $name => $columns) {
            dbDelta('CREATE TABLE ' . self::table($name) . " (\n" . str_replace(', ', ",\n", $columns) . "\n) $collate;");
        }
        if ($wpdb->last_error) { throw new \RuntimeException($wpdb->last_error); }
        update_option('midas_schema', self::VERSION, false);
    }
    public static function check($result): void {
        global $wpdb;
        if ($result === false) { throw new \RuntimeException('Falha no banco: ' . $wpdb->last_error); }
    }
    public static function log(int $repository, string $level, string $message): void {
        global $wpdb;
        $wpdb->insert(self::table('logs'), ['repository_id' => $repository, 'created_at' => gmdate('Y-m-d H:i:s'), 'level' => $level, 'message' => substr($message, 0, 4000)]);
        $wpdb->query('DELETE FROM ' . self::table('logs') . ' WHERE created_at < UTC_TIMESTAMP() - INTERVAL 30 DAY');
    }
}
