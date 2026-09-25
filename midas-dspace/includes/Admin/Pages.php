<?php
namespace Midas\Admin;
use Midas\Storage\Database as DB;
use Midas\Sync\Queue;
defined('ABSPATH') || exit;

final class Pages {
    public function register(): void {
        add_action('admin_menu', function () {
            add_menu_page('MIDAS', 'MIDAS', 'manage_options', 'midas', [$this, 'repositories'], 'dashicons-video-alt3');
            foreach (['midas' => ['Repositórios', 'repositories'], 'midas-collections' => ['Coleções', 'collections'], 'midas-logs' => ['Sincronização / Logs', 'logs'], 'midas-settings' => ['Configurações', 'settings']] as $slug => [$name, $method]) {
                add_submenu_page('midas', $name, $name, 'manage_options', $slug, [$this, $method]);
            }
        });
        add_action('admin_post_midas_save', [$this, 'save']);
        add_action('admin_notices', function () {
            $message = get_transient('midas_notice_' . get_current_user_id());
            if ($message) { delete_transient('midas_notice_' . get_current_user_id()); echo '<div class="notice notice-info"><p>' . esc_html($message) . '</p></div>'; }
        });
    }
    private function start(string $title): void {
        if (!current_user_can('manage_options')) { wp_die('Acesso negado.'); }
        echo '<div class="wrap"><h1>MIDAS — ' . esc_html($title) . '</h1>';
    }
    private function form(string $operation): void {
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="midas_save"><input type="hidden" name="operation" value="' . esc_attr($operation) . '">';
        wp_nonce_field('midas_save');
    }
    private function field(string $label, string $name, string $value = '', string $type = 'text'): void {
        echo '<p><label>' . esc_html($label) . '<br><input class="regular-text" type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"></label></p>';
    }
    public function repositories(): void {
        global $wpdb;
        $this->start('Repositórios');
        foreach ($wpdb->get_results('SELECT * FROM ' . DB::table('repositories') . ' ORDER BY id') as $repo) {
            echo '<h2>' . esc_html($repo->name) . ' (#' . (int)$repo->id . ')</h2>';
            $this->repositoryForm($repo);
            $this->form('discover');
            echo '<input type="hidden" name="repository_id" value="' . (int)$repo->id . '">';
            submit_button('Testar endpoint e descobrir conjuntos', 'secondary'); echo '</form><hr>';
        }
        echo '<h2>Adicionar repositório</h2>';
        $this->repositoryForm((object)['id' => 0, 'name' => '', 'oai_url' => '', 'rest_url' => '', 'frequency' => 86400, 'media_mode' => 'remote']);
        echo '</div>';
    }
    private function repositoryForm(object $repo): void {
        $this->form('repository');
        echo '<input type="hidden" name="repository_id" value="' . (int)$repo->id . '">';
        $this->field('Nome', 'name', $repo->name);
        $this->field('URL OAI-PMH', 'oai_url', $repo->oai_url, 'url');
        $this->field('API REST pública (DSpace 7: …/server/api; DSpace 5/6: …/rest)', 'rest_url', $repo->rest_url ?? '', 'url');
        $this->field('Intervalo em segundos (mínimo 300)', 'frequency', (string)$repo->frequency, 'number');
        echo '<p><label>Modo de mídia <select name="media_mode"><option value="remote" ' . selected($repo->media_mode, 'remote', false) . '>Links remotos</option><option value="local" ' . selected($repo->media_mode, 'local', false) . '>Cópia local dos arquivos públicos</option></select></label></p>';
        submit_button('Salvar e testar'); echo '</form>';
    }
    public function collections(): void {
        global $wpdb;
        $this->start('Coleções');
        echo '<p>Somente conjuntos selecionados serão publicados. Tipos desconhecidos exigem avaliação do administrador.</p>';
        $this->form('collections');
        $rows = $wpdb->get_results('SELECT c.*,r.name repository_name FROM ' . DB::table('collections') . ' c JOIN ' . DB::table('repositories') . ' r ON r.id=c.repository_id ORDER BY r.id,c.id');
        echo '<table class="widefat striped"><thead><tr><th>Publicar</th><th>ID</th><th>Repositório / conjunto</th><th>setSpec</th><th>Tipo</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td><input aria-label="Publicar ' . esc_attr($row->name) . '" type="checkbox" name="collections[]" value="' . (int)$row->id . '" ' . checked($row->enabled, 1, false) . '></td><td>' . (int)$row->id . '</td><td>' . esc_html($row->repository_name . ' / ' . $row->name) . '</td><td>' . esc_html($row->set_spec) . '</td><td>' . esc_html($row->kind) . '</td></tr>';
        }
        echo '</tbody></table>'; submit_button('Salvar seleção e sincronizar'); echo '</form></div>';
    }
    public function logs(): void {
        global $wpdb;
        $this->start('Sincronização / Logs');
        $this->form('run'); submit_button('Executar próximo lote', 'secondary'); echo '</form>';
        echo '<h2>Fila</h2><table class="widefat striped"><tr><th>Repositório / coleção</th><th>Operação</th><th>Estado</th><th>Tentativas</th><th>Último sucesso UTC</th><th>Ação</th></tr>';
        foreach ($wpdb->get_results('SELECT * FROM ' . DB::table('checkpoints') . ' ORDER BY id DESC LIMIT 200') as $job) {
            echo '<tr><td>' . (int)$job->repository_id . ' / ' . (int)$job->collection_id . '</td><td>' . esc_html($job->job) . '</td><td>' . esc_html($job->status) . '</td><td>' . (int)$job->attempts . '</td><td>' . esc_html($job->last_success ?? '') . '</td><td>';
            $this->form('retry'); echo '<input type="hidden" name="job_id" value="' . (int)$job->id . '">'; submit_button('Retomar', 'secondary small', 'submit', false); echo '</form></td></tr>';
        }
        echo '</table><h2>Eventos recentes (retenção de 30 dias)</h2><table class="widefat striped"><tr><th>UTC</th><th>Repositório</th><th>Nível</th><th>Mensagem</th></tr>';
        foreach ($wpdb->get_results('SELECT * FROM ' . DB::table('logs') . ' ORDER BY id DESC LIMIT 100') as $log) {
            echo '<tr><td>' . esc_html($log->created_at) . '</td><td>' . (int)$log->repository_id . '</td><td>' . esc_html($log->level) . '</td><td>' . esc_html($log->message) . '</td></tr>';
        }
        echo '</table></div>';
    }
    public function settings(): void {
        $this->start('Configurações'); $this->form('settings');
        $this->field('Repositório GitHub público (OWNER/REPO)', 'github', get_option('midas_github', ''));
        echo '<p>Atualizações usam releases com o arquivo <code>midas-dspace.zip</code>.</p><p><label><input type="checkbox" name="delete_data" value="1" ' . checked(get_option('midas_delete_data'), 1, false) . '> Apagar tabelas e cópias locais do MIDAS ao desinstalar</label></p>';
        submit_button('Salvar configurações'); echo '</form></div>';
    }
    public function save(): void {
        if (!current_user_can('manage_options')) { wp_die('Acesso negado.', '', ['response' => 403]); }
        check_admin_referer('midas_save');
        global $wpdb;
        $op = sanitize_key(wp_unslash($_POST['operation'] ?? ''));
        $page = 'midas';
        try {
            switch ($op) {
                case 'repository':
                    $id = absint($_POST['repository_id'] ?? 0);
                    $data = ['name' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')), 'oai_url' => esc_url_raw(wp_unslash($_POST['oai_url'] ?? ''), ['http', 'https']), 'rest_url' => esc_url_raw(wp_unslash($_POST['rest_url'] ?? ''), ['http', 'https']), 'frequency' => max(300, absint($_POST['frequency'] ?? 86400)), 'media_mode' => ($_POST['media_mode'] ?? '') === 'local' ? 'local' : 'remote'];
                    if (!$data['name'] || !wp_http_validate_url($data['oai_url']) || ($data['rest_url'] && !wp_http_validate_url($data['rest_url']))) { throw new \RuntimeException('Informe nome e URLs públicas válidas.'); }
                    $old = $id ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . DB::table('repositories') . ' WHERE id=%d', $id)) : null;
                    if ($id && !$old) { throw new \RuntimeException('Repositório inexistente.'); }
                    if ($old && $old->oai_url !== $data['oai_url']) { throw new \RuntimeException('Para trocar o endpoint OAI, cadastre outro repositório para preservar as identidades locais.'); }
                    $data += (new \Midas\Oai\Client($data['oai_url']))->identify();
                    if ($id) { DB::check($wpdb->update(DB::table('repositories'), $data, ['id' => $id])); }
                    else { DB::check($wpdb->insert(DB::table('repositories'), $data)); $id = (int)$wpdb->insert_id; }
                    Queue::enqueue($id); break;
                case 'discover':
                    $id = absint($_POST['repository_id'] ?? 0);
                    $url = $wpdb->get_var($wpdb->prepare('SELECT oai_url FROM ' . DB::table('repositories') . ' WHERE id=%d', $id));
                    if (!$url) { throw new \RuntimeException('Repositório inexistente.'); }
                    $data = (new \Midas\Oai\Client($url))->identify();
                    DB::check($wpdb->update(DB::table('repositories'), $data, ['id' => $id])); Queue::enqueue($id); break;
                case 'collections':
                    $page = 'midas-collections';
                    $ids = array_map('absint', (array)($_POST['collections'] ?? []));
                    DB::check($wpdb->query('UPDATE ' . DB::table('collections') . ' SET enabled=0'));
                    foreach ($ids as $id) {
                        DB::check($wpdb->update(DB::table('collections'), ['enabled' => 1], ['id' => $id]));
                        $repo = $wpdb->get_var($wpdb->prepare('SELECT repository_id FROM ' . DB::table('collections') . ' WHERE id=%d', $id));
                        if ($repo) { Queue::enqueue((int)$repo, $id); }
                    } break;
                case 'run': $page = 'midas-logs'; (new Queue())->tick(); break;
                case 'retry':
                    $page = 'midas-logs';
                    $job = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . DB::table('checkpoints') . ' WHERE id=%d', absint($_POST['job_id'] ?? 0)));
                    if ($job) { Queue::enqueue((int)$job->repository_id, (int)$job->collection_id); } break;
                case 'settings':
                    $page = 'midas-settings';
                    $repo = trim(sanitize_text_field(wp_unslash($_POST['github'] ?? '')));
                    if ($repo && !preg_match('~^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$~', $repo)) { throw new \RuntimeException('Use OWNER/REPO.'); }
                    update_option('midas_github', $repo); update_option('midas_delete_data', empty($_POST['delete_data']) ? 0 : 1); delete_transient('midas_release'); break;
                default: throw new \RuntimeException('Operação inválida.');
            }
            $notice = 'Operação concluída. Consulte Sincronização / Logs para acompanhar a fila.';
        } catch (\Throwable $e) { $notice = 'Falha: ' . $e->getMessage(); }
        set_transient('midas_notice_' . get_current_user_id(), $notice, 60);
        wp_safe_redirect(admin_url('admin.php?page=' . $page)); exit;
    }
}
