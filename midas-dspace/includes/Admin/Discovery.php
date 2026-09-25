<?php
namespace Midas\Admin;
use Midas\Storage\Database as DB;
use Midas\Sync\Queue;
defined('ABSPATH') || exit;

final class Discovery {
    public function register(): void {
        add_action('wp_ajax_midas_discovery', [$this, 'advance']);
        add_action('admin_enqueue_scripts', static function () {
            if (($_GET['page'] ?? '') !== 'midas-collections') { return; }
            wp_enqueue_script('midas-discovery', plugins_url('assets/js/discovery.js', MIDAS_FILE), [], MIDAS_VERSION, true);
            wp_localize_script('midas-discovery', 'midasDiscovery', ['url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('midas_discovery')]);
        });
    }
    public static function panel(): void {
        echo '<div class="notice notice-info inline"><p id="midas-discovery-status" role="status" aria-live="polite">Verificando descoberta de coleções…</p><p>Os conjuntos são processados em lotes de 100, sem limite total. Mantenha esta página aberta para continuar a descoberta.</p><p><button type="button" class="button" id="midas-discovery-resume">Continuar descoberta</button> <a class="button" href="' . esc_url(admin_url('admin.php?page=midas-collections')) . '">Atualizar lista de coleções</a></p></div>';
    }
    public function advance(): void {
        if (!current_user_can('manage_options')) { wp_send_json_error(['message' => 'Acesso negado.'], 403); }
        check_ajax_referer('midas_discovery', 'nonce');
        try {
            (new Queue())->tick(true);
            global $wpdb;
            $jobs = $wpdb->get_results("SELECT status,next_run FROM " . DB::table('checkpoints') . " WHERE job='sets'");
            $pending = false; $failed = false; $next = null;
            foreach ($jobs as $job) {
                if (in_array($job->status, ['queued', 'running'], true)) {
                    $pending = true;
                    $next = $next === null ? (int)$job->next_run : min($next, (int)$job->next_run);
                }
                if ($job->status === 'failed') { $failed = true; }
            }
            $count = (int)$wpdb->get_var('SELECT COUNT(*) FROM ' . DB::table('collections'));
            $message = $count . ' conjuntos descobertos. ';
            if ($pending) { $message .= 'Descoberta em andamento; a próxima etapa será executada automaticamente.'; }
            elseif ($failed) { $message .= 'Há falhas. Consulte Sincronização / Logs e use Retomar após corrigir a causa.'; }
            elseif (!$jobs) { $message .= 'Cadastre um repositório ou solicite a descoberta em Repositórios.'; }
            else { $message .= 'Descoberta concluída. Clique em Atualizar lista de coleções para exibir os novos conjuntos.'; }
            wp_send_json_success(['message' => $message, 'pending' => $pending, 'delay' => max(3, min(60, ($next ?? time()) - time()))]);
        } catch (\Throwable $e) { wp_send_json_error(['message' => 'Falha na descoberta: ' . $e->getMessage()], 500); }
    }
}
