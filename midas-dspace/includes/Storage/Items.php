<?php
namespace Midas\Storage;
defined('ABSPATH') || exit;

final class Items {
    public static function save(array $record, object $repository, int $collection, string $runId = ''): void {
        global $wpdb;
        $table = Database::table('items');
        $hash = hash('sha256', $record['identifier']);
        $id = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE repository_id=%d AND identifier_hash=%s", $repository->id, $hash));
        if ($id) {
            $previous = $wpdb->get_row($wpdb->prepare("SELECT datestamp,deleted FROM $table WHERE id=%d", $id));
            // A replay from another collection must not resurrect a newer tombstone.
            if ($previous && $previous->datestamp && $record['datestamp'] && strcmp($previous->datestamp, $record['datestamp']) > 0) { return; }
        }
        if ($record['deleted']) {
            if ($id) { Database::check($wpdb->update($table, ['deleted' => 1, 'datestamp' => $record['datestamp']], ['id' => $id])); }
            return;
        }
        $files = \Midas\DSpace\Media::resolve($record, $repository);
        $sets = $record['sets'];
        unset($record['sets'], $record['candidates']);
        $record += ['repository_id' => $repository->id, 'identifier_hash' => $hash];
        $record['media_status'] = $files ? 'available' : 'unavailable';
        if ($id) { Database::check($wpdb->update($table, $record, ['id' => $id])); }
        else { Database::check($wpdb->insert($table, $record)); $id = (int)$wpdb->insert_id; }
        $relations = Database::table('item_collections');
        $collections = $wpdb->get_results($wpdb->prepare('SELECT id,set_spec FROM ' . Database::table('collections') . ' WHERE repository_id=%d', $repository->id));
        $membership = [];
        foreach ($collections as $candidate) {
            foreach ($sets as $spec) {
                if ($spec === $candidate->set_spec || str_starts_with($spec, $candidate->set_spec . ':')) { $membership[] = (int)$candidate->id; break; }
            }
        }
        if (!in_array($collection, $membership, true)) { $membership[] = $collection; }
        foreach ($membership as $cid) { Database::check($wpdb->query($wpdb->prepare("INSERT IGNORE INTO $relations (item_id,collection_id) VALUES (%d,%d)", $id, $cid))); }
        if ($runId !== '') { Database::check($wpdb->update($relations, ['seen_run' => $runId], ['item_id' => $id, 'collection_id' => $collection])); }
        // Only authoritative, nonempty header membership can remove old relations.
        if ($sets) { Database::check($wpdb->query($wpdb->prepare("DELETE FROM $relations WHERE item_id=%d AND collection_id NOT IN (" . implode(',', $membership) . ')', $id))); }
        $fileTable = Database::table('files');
        Database::check($wpdb->update($fileTable, ['verified' => 0], ['item_id' => $id]));
        foreach ($files as $file) {
            $file['verified'] = 1;
            $fileHash = hash('sha256', $file['url']);
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $fileTable WHERE item_id=%d AND url_hash=%s", $id, $fileHash));
            $file['attachment_id'] = $existing ? (int)$existing->attachment_id : 0;
            if ($repository->media_mode === 'local' && !$file['attachment_id']) { $file['attachment_id'] = \Midas\DSpace\Media::copy($file['url'], $file['mime']); }
            if ($existing) { Database::check($wpdb->update($fileTable, $file, ['id' => $existing->id])); }
            else { Database::check($wpdb->insert($fileTable, $file + ['item_id' => $id, 'url_hash' => $fileHash])); }
        }
        // Old files are retained for recovery but the frontend only shows URLs verified in this harvest.

    }
}
