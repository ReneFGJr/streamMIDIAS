<?php
/** Run only against an isolated WordPress test database. Usage: php integration.php /path/to/wordpress */
$root = $argv[1] ?? '';
if (!is_file($root . '/wp-load.php')) { fwrite(STDERR, "Provide a disposable WordPress installation.\n"); exit(1); }
define('WP_INSTALLING', true);
require $root . '/wp-load.php';
if (!str_starts_with(DB_NAME, 'midas_test_')) { throw new RuntimeException('Refusing a non-test database.'); }
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
if (!is_blog_installed()) { wp_install('MIDAS tests', 'midas-test', 'tests@example.test', true, '', wp_generate_password(32)); }
require dirname(__DIR__) . '/midas-dspace.php';
Midas\Storage\Database::install();
use Midas\Storage\Database as DB;
use Midas\Sync\Queue;
$checks = 0;
function verify(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
function response(string $body, int $status = 200, string $mime = 'text/xml'): array {
    return ['headers' => ['content-type' => $mime], 'body' => $body, 'response' => ['code' => $status, 'message' => 'Test'], 'cookies' => [], 'filename' => null];
}
function envelope(string $body): string { return '<OAI-PMH xmlns="http://www.openarchives.org/OAI/2.0/">' . $body . '</OAI-PMH>'; }
$requests = []; $expired = false; $simulateExpiry = false; $simulateHttp = false;
add_filter('pre_http_request', function ($pre, $args, $url) use (&$requests, &$expired, &$simulateExpiry, &$simulateHttp) {
    $requests[] = $url;
    if (str_starts_with($url, 'https://repository.example/oai')) {
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        if ($simulateHttp && ($query['verb'] ?? '') === 'ListRecords') { return response('', 503); }
        switch ($query['verb'] ?? '') {
            case 'Identify': return response(envelope('<Identify><granularity>YYYY-MM-DD</granularity></Identify>'));
            case 'ListMetadataFormats': return response(envelope('<ListMetadataFormats><metadataFormat><metadataPrefix>oai_dc</metadataPrefix></metadataFormat></ListMetadataFormats>'));
            case 'ListSets':
                return response(envelope(isset($query['resumptionToken']) ? '<ListSets><set><setSpec>col_2</setSpec><setName>Segunda coleção</setName></set><resumptionToken/></ListSets>' : '<ListSets><set><setSpec>col_1</setSpec><setName>Primeira coleção</setName></set><resumptionToken>set:2+/=</resumptionToken></ListSets>'));
            case 'ListRecords':
                if (isset($query['resumptionToken'])) {
                    verify(count($query) === 2, 'Token request contains only verb and token');
                    if ($simulateExpiry && !$expired) { $expired = true; return response(envelope('<error code="badResumptionToken">expired</error>')); }
                    return response(envelope('<ListRecords><resumptionToken/></ListRecords>'));
                }
                return response(file_get_contents(dirname(__DIR__) . '/fixtures/records.xml'));
        }
    }
    if (($args['method'] ?? '') === 'HEAD') {
        if (str_ends_with($url, '/video.mp4')) { return response('', 200, 'video/mp4'); }
        if (str_ends_with($url, '/cover.jpg')) { return response('', 200, 'image/jpeg'); }
        if (str_ends_with($url, '/document.pdf')) { return response('', 200, 'application/pdf'); }
        if (str_ends_with($url, '/private.pdf')) { return response('', 403, 'application/pdf'); }
        return response('', 200, 'text/html');
    }
    return new WP_Error('test_network', 'Unexpected external request blocked in tests');
}, 10, 3);
global $wpdb;
foreach (['repositories','collections','items','item_collections','files','checkpoints','logs'] as $name) {
    verify($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(DB::table($name)))) === DB::table($name), 'Schema table ' . $name);
    $wpdb->query('DELETE FROM ' . DB::table($name));
}
$wpdb->insert(DB::table('repositories'), ['name' => 'Test', 'oai_url' => 'https://repository.example/oai', 'rest_url' => '', 'frequency' => 86400]);
$rid = (int)$wpdb->insert_id;
Queue::enqueue($rid);
$queue = new Queue();
$queue->tick();
$wpdb->query('UPDATE ' . DB::table('checkpoints') . ' SET next_run=0');
$queue->tick();
verify((int)$wpdb->get_var('SELECT COUNT(*) FROM ' . DB::table('collections')) === 2, 'ListSets consumes all pages');
$cid = (int)$wpdb->get_var("SELECT id FROM " . DB::table('collections') . " WHERE set_spec='col_1'");
$wpdb->update(DB::table('collections'), ['enabled' => 1], ['id' => $cid]);
Queue::enqueue($rid, $cid); $queue->tick();
verify((int)$wpdb->get_var('SELECT COUNT(*) FROM ' . DB::table('items')) === 1, 'Record imported');
verify((int)$wpdb->get_var('SELECT COUNT(*) FROM ' . DB::table('files') . ' WHERE verified=1') === 3, 'Video/image/PDF verified; private media excluded');
$jobid = (int)$wpdb->get_var("SELECT id FROM " . DB::table('checkpoints') . " WHERE job='records'");
$initial = $wpdb->get_row('SELECT * FROM ' . DB::table('checkpoints') . ' WHERE id=' . $jobid);
$simulateExpiry = true;
$wpdb->update(DB::table('checkpoints'), ['next_run' => 0], ['id' => $jobid]); $queue->tick();
$failed = $wpdb->get_row('SELECT * FROM ' . DB::table('checkpoints') . ' WHERE id=' . $jobid);
verify($failed->token === '' && $failed->window_until === $initial->window_until, 'Expired token resets token and preserves time window');
verify((int)$failed->attempts === 1 && (int)$failed->next_run > time(), 'Retry with backoff');
$wpdb->update(DB::table('checkpoints'), ['next_run' => 0], ['id' => $jobid]); $queue->tick();
verify((int)$wpdb->get_var('SELECT COUNT(*) FROM ' . DB::table('items')) === 1, 'Replay is idempotent');
$wpdb->update(DB::table('checkpoints'), ['next_run' => 0], ['id' => $jobid]); $queue->tick();
$done = $wpdb->get_row('SELECT * FROM ' . DB::table('checkpoints') . ' WHERE id=' . $jobid);
verify($done->status === 'idle' && $done->last_success === $initial->window_until, 'Complete window advances checkpoint');
$simulateHttp = true;
Queue::enqueue($rid, $cid); $queue->tick();
verify((int)$wpdb->get_var('SELECT deleted FROM ' . DB::table('items') . ' LIMIT 1') === 0, 'Temporary HTTP error preserves records');
$simulateHttp = false;
$repo = $wpdb->get_row('SELECT * FROM ' . DB::table('repositories') . ' WHERE id=' . $rid);
$xml = Midas\Oai\Client::xml(file_get_contents(dirname(__DIR__) . '/fixtures/records.xml'));
$record = Midas\DSpace\Metadata::parse($xml->xpath('//*[local-name()="record"]')[0]);
$shortcodes = new Midas\Frontend\Shortcodes(); $shortcodes->register();
$pid = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'MIDAS test', 'post_content' => '[streamVideo][streamController collection="[' . $cid . ']"]']);
$GLOBALS['post'] = get_post($pid);
$_GET = [];
$html = do_shortcode(Midas\Frontend\Shortcodes::normalize($GLOBALS['post']->post_content));
verify(str_contains($html, '<video') && str_contains($html, 'Abrir PDF') && str_contains($html, '<img'), 'All media types render');
verify(!str_contains($html, 'private.pdf'), 'Restricted media never rendered');
$_GET['midas_collection'] = '999999';
verify(!str_contains($shortcodes->catalog(), '<video'), 'URL cannot escape collection scope');
$_GET = [];
$wpdb->update(DB::table('collections'), ['enabled' => 0], ['id' => $cid]);
verify(!str_contains($shortcodes->catalog(), '<video'), 'Unpublished collections disappear immediately');
$wpdb->update(DB::table('collections'), ['enabled' => 1], ['id' => $cid]);
$record['deleted'] = 1; $record['datestamp'] = '2026-09-25';
Midas\Storage\Items::save($record, $repo, $cid);
verify(!str_contains($shortcodes->catalog(), '<video'), 'OAI tombstone hides catalog item');
wp_delete_post($pid, true);
echo "OK: $checks WordPress/MySQL integration checks\n";
