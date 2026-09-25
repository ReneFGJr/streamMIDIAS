<?php
namespace Midas\Sync;
defined('ABSPATH') || exit;

final class Cli {
    /** Descobre conjuntos de um repositório. Uso: wp midas discover <repository-id> */
    public function discover(array $args): void { Queue::enqueue(absint($args[0] ?? 0)); \WP_CLI::success('Descoberta enfileirada.'); }
    /** Sincroniza uma coleção. Uso: wp midas sync <collection-id> */
    public function sync(array $args): void {
        global $wpdb;
        $id = absint($args[0] ?? 0);
        $repo = $wpdb->get_var($wpdb->prepare('SELECT repository_id FROM ' . \Midas\Storage\Database::table('collections') . ' WHERE id=%d AND enabled=1', $id));
        if (!$repo) { \WP_CLI::error('Coleção não publicada ou inexistente.'); }
        Queue::enqueue((int)$repo, $id);
        \WP_CLI::success('Sincronização enfileirada.');
    }
    /** Processa lotes da fila. Uso: wp midas run --batches=100 */
    public function run(array $args, array $assoc): void {
        $queue = new Queue();
        for ($i = 0; $i < max(1, min(100000, (int)($assoc['batches'] ?? 1))); $i++) { $queue->tick(); if ($i + 1 < (int)($assoc['batches'] ?? 1)) { sleep(1); } }
        \WP_CLI::success('Processamento concluído; consulte os logs para falhas.');
    }
}
