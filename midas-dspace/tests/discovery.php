<?php
use Midas\Storage\Database as DB;
use Midas\Sync\Queue;
// Uses the disposable WordPress database required by integration.php.
require __DIR__ . '/integration.php';
set_exception_handler(static function (Throwable $e) { fwrite(STDERR, (string)$e . PHP_EOL); exit(1); });
$queue->register();
wp_clear_scheduled_hook('midas_tick');
wp_schedule_single_event(time() + 60, 'midas_tick');
$queue->schedule();
$event = wp_get_scheduled_event('midas_tick');
verify($event && $event->schedule === 'midas_minute', 'Upgrade one-shot event to recurring cron');
$queue->schedule();
verify(wp_next_scheduled('midas_tick') === $event->timestamp, 'Scheduling does not duplicate or postpone existing event');
$wpdb->insert(DB::table('repositories'), ['name' => 'Large discovery', 'oai_url' => 'https://repository.example/oai', 'frequency' => 86400]);
$largeId = (int)$wpdb->insert_id;
$requestsForSets = [];
add_filter('pre_http_request', static function ($pre, $args, $url) use (&$requestsForSets) {
    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
    if (($query['verb'] ?? '') !== 'ListSets') { return $pre; }
    $requestsForSets[] = $query;
    $secondPage = isset($query['resumptionToken']);
    if ($secondPage) {
        verify($query === ['verb' => 'ListSets', 'resumptionToken' => 'next:250+/='], 'Opaque ListSets token and exclusive arguments');
    }
    $body = '<ListSets>';
    for ($n = $secondPage ? 251 : 1; $n <= ($secondPage ? 355 : 250); $n++) {
        $body .= '<set><setSpec>col_large_' . $n . '</setSpec><setName>Coleção ' . $n . '</setName></set>';
    }
    $body .= '<resumptionToken>' . ($secondPage ? '' : 'next:250+/=') . '</resumptionToken></ListSets>';
    return response(envelope($body));
}, 20, 3);
Queue::enqueue($largeId);
$jobId = (int)$wpdb->get_var($wpdb->prepare('SELECT id FROM ' . DB::table('checkpoints') . ' WHERE repository_id=%d', $largeId));
foreach ([100, 200, 250, 350, 355] as $expected) {
    $wpdb->update(DB::table('checkpoints'), ['next_run' => 0], ['id' => $jobId]);
    $queue->tick(true);
    $count = (int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . DB::table('collections') . ' WHERE repository_id=%d', $largeId));
    verify($count === $expected, 'Continue discovery to ' . $expected . ' sets');
}
verify(count($requestsForSets) === 2, 'Stored page resumed without downloading it again');
$done = $wpdb->get_row('SELECT * FROM ' . DB::table('checkpoints') . ' WHERE id=' . $jobId);
verify($done->status === 'idle' && $done->token === '' && $done->payload === '', 'Discovery completes only after last page');
echo "OK: $checks total checks including 355-set discovery and recurring scheduling\n";
