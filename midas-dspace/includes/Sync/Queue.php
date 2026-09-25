<?php
namespace Midas\Sync;
use Midas\Storage\Database as DB;
use Midas\Oai\Client;
defined('ABSPATH') || exit;

final class Queue {
    public function register(): void {
        add_action('midas_tick', [$this, 'tick']);
        add_action('init', [$this, 'schedule']);
    }
    public function schedule(): void {
        if (function_exists('as_has_scheduled_action') && function_exists('as_schedule_recurring_action')) {
            wp_clear_scheduled_hook('midas_tick');
            if (!as_has_scheduled_action('midas_tick', [], 'midas')) { as_schedule_recurring_action(time() + 30, 60, 'midas_tick', [], 'midas'); }
        } elseif (!wp_next_scheduled('midas_tick')) {
            wp_schedule_single_event(time() + 60, 'midas_tick');
        }
    }
    public static function enqueue(int $repository, int $collection = 0): void {
        global $wpdb;
        $t = DB::table('checkpoints');
        $job = $collection ? 'records' : 'sets';
        DB::check($wpdb->query($wpdb->prepare("INSERT INTO $t (repository_id,collection_id,job,status,next_run) VALUES (%d,%d,%s,'queued',0) ON DUPLICATE KEY UPDATE status='queued',attempts=0,next_run=0", $repository, $collection, $job)));
    }
    public function tick(): void {
        global $wpdb;
        $this->schedule();
        // MySQL advisory lock is shared by Cron, Action Scheduler and WP-CLI.
        $lock = 'midas_' . substr(hash('sha256', DB::table('checkpoints')), 0, 40);
        if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $lock)) !== 1) { return; }
        try {
            $t = DB::table('checkpoints');
            $job = $wpdb->get_row($wpdb->prepare("SELECT j.* FROM $t j LEFT JOIN " . DB::table('collections') . " c ON c.id=j.collection_id WHERE j.status IN ('queued','running','idle') AND j.next_run<=%d AND (j.job='sets' OR c.enabled=1) ORDER BY j.next_run,j.id LIMIT 1", time()));
            if (!$job) { return; }
            $repository = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . DB::table('repositories') . ' WHERE id=%d', $job->repository_id));
            if (!$repository) { return; }
            try { $this->step($job, $repository); }
            catch (\Throwable $e) {
                $attempts = (int)$job->attempts + 1;
                $changes = ['attempts' => $attempts, 'status' => $attempts >= 8 ? 'failed' : 'queued', 'next_run' => time() + min(3600, 30 * (2 ** min($attempts, 7)))];
                if ($e->getMessage() === 'badResumptionToken') { $changes += ['token' => '', 'payload' => '', 'record_offset' => 0]; }
                DB::check($wpdb->update($t, $changes, ['id' => $job->id]));
                DB::log((int)$repository->id, 'error', $e->getMessage());
            }
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    }
    private function step(object $job, object $repository): void {
        global $wpdb;
        $t = DB::table('checkpoints');
        $client = new Client($repository->oai_url);
        if (!$job->window_until) {
            $settings = $client->identify();
            DB::check($wpdb->update(DB::table('repositories'), $settings, ['id' => $repository->id]));
            foreach ($settings as $key => $value) { $repository->$key = $value; }
            $format = $repository->granularity === 'YYYY-MM-DD' ? 'Y-m-d' : 'Y-m-d\TH:i:s\Z';
            $job->window_until = gmdate($format, time() - 60);
            $fullDue = !$job->last_full_success || strtotime($job->last_full_success) < time() - 30 * DAY_IN_SECONDS;
            $job->window_from = $fullDue ? '' : ($job->last_success ?: '');
            $job->run_id = bin2hex(random_bytes(16));
            DB::check($wpdb->update($t, ['window_until' => $job->window_until, 'window_from' => $job->window_from, 'run_id' => $job->run_id, 'status' => 'running'], ['id' => $job->id]));
        }
        $verb = $job->job === 'sets' ? 'ListSets' : 'ListRecords';
        if ($job->payload) { $xml = Client::xml($job->payload); }
        else {
            $args = [];
            if ($job->token) { $args = ['resumptionToken' => $job->token]; }
            elseif ($job->job === 'records') {
                $spec = $wpdb->get_var($wpdb->prepare('SELECT set_spec FROM ' . DB::table('collections') . ' WHERE id=%d AND repository_id=%d', $job->collection_id, $repository->id));
                if ($spec === null) { throw new \RuntimeException('Coleção não encontrada.'); }
                $args = ['set' => $spec, 'metadataPrefix' => $repository->metadata_prefix, 'until' => $job->window_until];
                if ($job->window_from) { $args['from'] = $job->window_from; }
            }
            $xml = $client->request($verb, $args);
            DB::check($wpdb->update($t, ['payload' => $xml->asXML(), 'record_offset' => 0], ['id' => $job->id]));
            $job->record_offset = 0;
        }
        $nodes = $xml->xpath('/*/*[local-name()="' . $verb . '"]/*[local-name()="' . ($job->job === 'sets' ? 'set' : 'record') . '"]');
        $offset = (int)$job->record_offset;
        $end = min(count($nodes), $offset + ($job->job === 'sets' ? 100 : 3));
        for ($i = $offset; $i < $end; $i++) {
            if ($job->job === 'sets') { $this->collection($nodes[$i], (int)$repository->id); }
            else {
                $record = \Midas\DSpace\Metadata::parse($nodes[$i]);
                if (!$record['deleted'] && $repository->metadata_prefix !== 'oai_dc' && in_array('oai_dc', json_decode($repository->formats ?: '[]', true) ?: [], true)) {
                    $dcXml = $client->request('GetRecord', ['identifier' => $record['identifier'], 'metadataPrefix' => 'oai_dc']);
                    $dcNode = $dcXml->xpath('//*[local-name()="GetRecord"]/*[local-name()="record"]')[0] ?? null;
                    if (!$dcNode) { throw new \RuntimeException('GetRecord não retornou o registro solicitado.'); }
                    $dc = \Midas\DSpace\Metadata::parse($dcNode);
                    if ($dc['identifier'] !== $record['identifier']) { throw new \RuntimeException('GetRecord retornou outro identificador.'); }
                    foreach (['title','author','item_date','abstract','subjects','language','license','source_url'] as $field) {
                        if ($dc[$field]) { $record[$field] = $dc[$field]; }
                    }
                    $record['deleted'] = $dc['deleted'];
                    $record['sets'] = $dc['sets'];
                    $record['datestamp'] = $dc['datestamp'];
                    $record['candidates'] = array_values(array_unique(array_merge($record['candidates'], $dc['candidates'])));
                    $record['raw_metadata'] = wp_json_encode(['rich' => $record['raw_metadata'], 'oai_dc' => $dc['raw_metadata']]);
                }
                \Midas\Storage\Items::save($record, $repository, (int)$job->collection_id, $job->run_id);
            }
            DB::check($wpdb->update($t, ['record_offset' => $i + 1], ['id' => $job->id]));
        }
        if ($end < count($nodes)) {
            DB::check($wpdb->update($t, ['status' => 'running', 'attempts' => 0, 'next_run' => time() + 1], ['id' => $job->id]));
            return;
        }
        $token = Client::token($xml);
        if ($token && $token === $job->token) { throw new \RuntimeException('Servidor repetiu o resumptionToken.'); }
        $changes = ['payload' => '', 'record_offset' => 0, 'token' => $token, 'attempts' => 0, 'status' => $token ? 'running' : 'idle', 'next_run' => time() + ($token ? 1 : max(300, (int)$repository->frequency))];
        if (!$token) {
            if ($job->job === 'records' && !$job->window_from) {
                // Reconcile membership only after the entire full harvest succeeds.
                DB::check($wpdb->query($wpdb->prepare('DELETE FROM ' . DB::table('item_collections') . ' WHERE collection_id=%d AND seen_run<>%s', $job->collection_id, $job->run_id)));
                $changes['last_full_success'] = $job->window_until;
            }
            $changes += ['last_success' => $job->window_until, 'window_from' => '', 'window_until' => ''];
            DB::log((int)$repository->id, 'info', $verb . ' concluído para coleção ' . $job->collection_id);
        }
        DB::check($wpdb->update($t, $changes, ['id' => $job->id]));
    }
    private function collection(\SimpleXMLElement $node, int $repository): void {
        global $wpdb;
        $spec = Client::value($node, 'setSpec');
        if ($spec === '') { throw new \RuntimeException('Conjunto sem setSpec.'); }
        $kind = 'unknown';
        if (str_starts_with($spec, 'col_')) { $kind = 'collection'; }
        elseif (str_starts_with($spec, 'com_')) { $kind = 'community'; }
        elseif (preg_match('/^(driver|openaire|ec_fundedresources)$/i', $spec)) { $kind = 'technical'; }
        $t = DB::table('collections');
        DB::check($wpdb->query($wpdb->prepare("INSERT INTO $t (repository_id,spec_hash,set_spec,name,kind) VALUES (%d,%s,%s,%s,%s) ON DUPLICATE KEY UPDATE name=VALUES(name),kind=VALUES(kind)", $repository, hash('sha256', $spec), $spec, Client::value($node, 'setName'), $kind)));
    }
}
