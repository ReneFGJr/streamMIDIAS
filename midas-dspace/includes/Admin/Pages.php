<?php
namespace Midas\Admin;
use Midas\Storage\Database as DB;
use Midas\Sync\Queue;
defined('ABSPATH') || exit;

final class Pages {
    public function register(): void {
        add_action('admin_menu', function () {
            add_menu_page('MIDAS', 'MIDAS', 'manage_options', 'midas', [$this, 'howTo'], 'dashicons-video-alt3');
            foreach (['midas' => ['Painel Geral', 'howTo'], 'midas-repositories' => ['Repositórios', 'repositories'], 'midas-collections' => ['Coleções', 'collections'], 'midas-logs' => ['Sincronização / Logs', 'logs'], 'midas-settings' => ['Configurações', 'settings']] as $slug => [$name, $method]) {
                add_submenu_page('midas', $name, $name, 'manage_options', $slug, [$this, $method]);
            }
            add_submenu_page(null, 'Documentos coletados', 'Documentos coletados', 'manage_options', 'midas-documents', [$this, 'documents']);
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
    private function processName(string $process): string {
        return match ($process) {
            'sets', 'ListSets' => 'Descoberta de coleções (ListSets)',
            'records', 'ListRecords' => 'Coleta de itens (ListRecords)',
            default => $process ?: '—',
        };
    }
    private function statusName(string $status): string {
        return match ($status) {
            'queued' => 'Na fila',
            'running' => 'Em execução',
            'idle' => 'Concluído / aguardando',
            'failed' => 'Falhou',
            default => $status,
        };
    }
    public function howTo(): void {
        global $wpdb;
        $collections = DB::table('collections');
        $repositories = DB::table('repositories');
        $relations = DB::table('item_collections');
        $items = DB::table('items');
        $summary = $wpdb->get_results("SELECT c.id,c.repository_id,c.name,r.name repository_name,COUNT(DISTINCT ic.item_id) collected,COUNT(DISTINCT CASE WHEN i.deleted=0 THEN i.id END) processed FROM $collections c JOIN $repositories r ON r.id=c.repository_id LEFT JOIN $relations ic ON ic.collection_id=c.id LEFT JOIN $items i ON i.id=ic.item_id WHERE c.enabled=1 GROUP BY c.id,c.repository_id,c.name,r.name ORDER BY r.name,c.name");
        $ids = array_map('absint', wp_list_pluck($summary, 'id'));
        $selected = $ids ? implode(',', $ids) : '1,2,3';
        $controller = '[streamController collection="[' . $selected . ']"]';
        $this->start('Painel Geral');
        echo '<p>Use os shortcodes do MIDAS em páginas, posts ou outros textos do WordPress para exibir as coleções publicadas.</p>';
        echo '<h2>Resumo das coleções selecionadas</h2>';
        if ($summary) {
            $totalCollected = 0;
            $totalProcessed = 0;
            echo '<table class="widefat striped"><thead><tr><th>Repositório</th><th>Coleção</th><th>ID</th><th>Documentos coletados</th><th>Documentos processados</th><th class="column-actions" style="text-align:right">Ações</th></tr></thead><tbody>';
            foreach ($summary as $collection) {
                $collected = (int)$collection->collected;
                $processed = (int)$collection->processed;
                $totalCollected += $collected;
                $totalProcessed += $processed;
                $documentsUrl = add_query_arg(['page' => 'midas-documents', 'collection' => (int)$collection->id], admin_url('admin.php'));
                echo '<tr><td>' . esc_html($collection->repository_name) . '</td><td>' . esc_html($collection->name) . '</td><td>' . (int)$collection->id . '</td><td>' . esc_html(number_format_i18n($collected)) . '</td><td>' . esc_html(number_format_i18n($processed)) . '</td><td style="text-align:right;white-space:nowrap">';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin-right:4px"><input type="hidden" name="action" value="midas_save"><input type="hidden" name="operation" value="collect"><input type="hidden" name="collection_id" value="' . (int)$collection->id . '">';
                wp_nonce_field('midas_save');
                echo '<button type="submit" class="button button-small" aria-label="Coletar itens de ' . esc_attr($collection->name) . '" title="Coletar itens"><span class="dashicons dashicons-update" aria-hidden="true" style="vertical-align:middle"></span> Coletar itens</button></form>';
                echo '<a class="button button-small" href="' . esc_url($documentsUrl) . '" aria-label="Ver documentos coletados de ' . esc_attr($collection->name) . '" title="Ver documentos coletados"><span class="dashicons dashicons-visibility" aria-hidden="true" style="vertical-align:middle"></span></a></td></tr>';
            }
            echo '</tbody><tfoot><tr><th colspan="3">Total</th><th>' . esc_html(number_format_i18n($totalCollected)) . '</th><th>' . esc_html(number_format_i18n($totalProcessed)) . '</th><th></th></tr></tfoot></table>';
            echo '<p class="description">Coletados inclui todos os documentos associados à coleção. Processados considera os documentos ativos e disponíveis para o catálogo, sem os registros removidos na origem.</p>';
        } else {
            echo '<p>Nenhuma coleção está selecionada. Escolha ao menos uma em <strong>MIDAS → Coleções</strong> para acompanhar os documentos.</p>';
        }
        echo '<h2>1. Prepare o catálogo</h2>';
        echo '<ol><li>Cadastre e teste a fonte em <strong>MIDAS → Repositórios</strong>.</li><li>O shortcode com as coleções selecionadas fica assim:<br><code>' . esc_html($controller) . '</code><br>Em <strong>MIDAS → Coleções</strong>, selecione as coleções que deseja publicar. O exemplo será atualizado automaticamente com os respectivos IDs.</li><li>Aguarde a importação dos registros. O andamento pode ser consultado em <strong>MIDAS → Sincronização / Logs</strong>.</li></ol>';
        if (!$ids) { echo '<p><em>Nenhuma coleção está selecionada no momento; por isso o shortcode acima usa IDs de exemplo.</em></p>'; }
        echo '<h2>2. Insira no conteúdo do WordPress</h2>';
        echo '<p>No editor de uma página ou post, adicione um bloco <strong>Shortcode</strong> e cole:</p>';
        echo '<p><code>' . esc_html($controller) . '</code></p>';
        echo '<p><code>[streamVideo]</code></p>';
        echo '<p><code>[streamDocumento]</code></p>';
        echo '<p>Troque <code>1,2,3</code> pelos IDs exibidos em <strong>MIDAS → Coleções</strong>. Esses números são os IDs locais do MIDAS, não o <code>setSpec</code> nem o ID do DSpace.</p>';
        echo '<h2>Outras formas de uso</h2>';
        echo '<p><code>[streamVideo]</code> exibe o catálogo das coleções publicadas, com busca, paginação, metadados e mídias.</p>';
        echo '<p><code>[streamVideo collection="1,2,3"]</code> restringe o catálogo diretamente aos IDs informados.</p>';
        echo '<p><code>[streamDocumento]</code> exibe documentos PDF, JPG/JPEG, GIF, PNG e WebP. Para limitar as coleções, use <code>[streamDocumento collection="1,2,3"]</code>.</p>';
        echo '<p><code>[streamController]</code> cria a navegação com todas as coleções publicadas. Para limitar as opções, use <code>[streamController collection="1,2,3"]</code>.</p>';
        echo '<p>O controlador e o catálogo podem aparecer em qualquer ordem no mesmo conteúdo. Depois de inserir os shortcodes, publique ou atualize a página e confira o resultado no site.</p>';
        echo '</div>';
    }
    public function documents(): void {
        global $wpdb;
        $collectionId = absint($_GET['collection'] ?? 0);
        $collection = $wpdb->get_row($wpdb->prepare('SELECT c.id,c.name,r.name repository_name FROM ' . DB::table('collections') . ' c JOIN ' . DB::table('repositories') . ' r ON r.id=c.repository_id WHERE c.id=%d', $collectionId));
        if (!$collection) { wp_die('Coleção não encontrada.', '', ['response' => 404]); }

        $perPage = 50;
        $currentPage = max(1, absint($_GET['paged'] ?? 1));
        $relations = DB::table('item_collections');
        $items = DB::table('items');
        $files = DB::table('files');
        $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $relations ic JOIN $items i ON i.id=ic.item_id WHERE ic.collection_id=%d", $collectionId));
        $documents = $wpdb->get_results($wpdb->prepare("SELECT i.id,i.title,i.author,i.item_date,i.identifier,i.source_url,i.deleted FROM $relations ic JOIN $items i ON i.id=ic.item_id WHERE ic.collection_id=%d ORDER BY i.id DESC LIMIT %d OFFSET %d", $collectionId, $perPage, ($currentPage - 1) * $perPage));
        $availableByDocument = [];
        $documentIds = array_map('absint', wp_list_pluck($documents, 'id'));
        if ($documentIds) {
            foreach ($wpdb->get_results("SELECT item_id,url,mime,bundle,attachment_id FROM $files WHERE verified=1 AND item_id IN (" . implode(',', $documentIds) . ') ORDER BY id') as $file) {
                $availableByDocument[(int)$file->item_id][] = $file;
            }
        }

        $this->start('Documentos coletados');
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=midas')) . '">← Voltar para Painel Geral</a></p>';
        echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px"><h2>' . esc_html($collection->repository_name . ' / ' . $collection->name) . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="midas_save"><input type="hidden" name="operation" value="collect"><input type="hidden" name="collection_id" value="' . $collectionId . '"><input type="hidden" name="return_page" value="midas-documents">';
        wp_nonce_field('midas_save');
        echo '<button type="submit" class="button" aria-label="Coletar itens desta coleção" title="Coletar itens desta coleção"><span class="dashicons dashicons-update" aria-hidden="true" style="vertical-align:middle"></span><span class="screen-reader-text">Coletar itens desta coleção</span></button></form></div>';
        echo '<p><strong>' . esc_html(number_format_i18n($total)) . '</strong> documento(s) coletado(s).</p>';
        if ($documents) {
            echo '<table class="widefat striped"><thead><tr><th>Título</th><th>Autor</th><th>Data</th><th>Identificador</th><th>Estado</th><th>Total de documentos</th><th style="text-align:right">Origem</th></tr></thead><tbody>';
            foreach ($documents as $document) {
                $status = (int)$document->deleted === 1 ? 'Removido na origem' : 'Processado';
                $source = $document->source_url ? '<a class="button button-small" href="' . esc_url($document->source_url) . '" target="_blank" rel="noopener noreferrer">Abrir <span class="screen-reader-text">' . esc_html($document->title) . '</span></a>' : '—';
                $available = $availableByDocument[(int)$document->id] ?? [];
                if ($available) {
                    $availableHtml = '<details><summary class="button button-small">' . esc_html(number_format_i18n(count($available))) . ' disponível(is)</summary><ul style="margin:8px 0 0 18px">';
                    foreach ($available as $file) {
                        $url = $file->attachment_id ? wp_get_attachment_url((int)$file->attachment_id) : '';
                        $url = $url ?: $file->url;
                        $path = (string)(wp_parse_url($file->url, PHP_URL_PATH) ?: '');
                        $name = $file->bundle ?: rawurldecode(basename($path));
                        $name = $name ?: $file->mime;
                        $availableHtml .= '<li><a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($name) . '</a></li>';
                    }
                    $availableHtml .= '</ul></details>';
                } else {
                    $availableHtml = '<button type="button" class="button button-small" disabled>0 disponíveis</button>';
                }
                echo '<tr><td>' . esc_html($document->title) . '</td><td>' . esc_html($document->author ?: '—') . '</td><td>' . esc_html($document->item_date ?: '—') . '</td><td><code>' . esc_html($document->identifier) . '</code></td><td>' . esc_html($status) . '</td><td>' . $availableHtml . '</td><td style="text-align:right">' . $source . '</td></tr>';
            }
            echo '</tbody></table>';
            $totalPages = (int)ceil($total / $perPage);
            if ($totalPages > 1) {
                $paginationBase = str_replace('999999999', '%#%', esc_url(add_query_arg(['page' => 'midas-documents', 'collection' => $collectionId, 'paged' => 999999999], admin_url('admin.php'))));
                echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(paginate_links(['base' => $paginationBase, 'format' => '', 'current' => $currentPage, 'total' => $totalPages, 'type' => 'plain'])) . '</div></div>';
            }
        } else {
            echo '<p>Nenhum documento foi coletado para esta coleção.</p>';
        }
        echo '</div>';
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
        Discovery::panel();
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
        echo '<h2>Fila</h2><table class="widefat striped"><tr><th>Repositório</th><th>Coleção coletada</th><th>Processo</th><th>Estado</th><th>Itens processados</th><th>Tentativas</th><th>Último sucesso UTC</th><th>Ação</th></tr>';
        $jobs = $wpdb->get_results('SELECT j.*,r.name repository_name,c.name collection_name FROM ' . DB::table('checkpoints') . ' j LEFT JOIN ' . DB::table('repositories') . ' r ON r.id=j.repository_id LEFT JOIN ' . DB::table('collections') . ' c ON c.id=j.collection_id ORDER BY j.id DESC LIMIT 200');
        foreach ($jobs as $job) {
            $progress = $job->job === 'records' ? number_format_i18n((int)$job->processed_count) : '—';
            echo '<tr><td>' . esc_html($job->repository_name ?: '#' . (int)$job->repository_id) . '</td><td>' . esc_html($job->collection_name ?: 'Todas / descoberta') . '</td><td>' . esc_html($this->processName((string)$job->job)) . '</td><td>' . esc_html($this->statusName((string)$job->status)) . '</td><td>' . esc_html($progress) . '</td><td>' . (int)$job->attempts . '</td><td>' . esc_html($job->last_success ?? '') . '</td><td>';
            $this->form('retry'); echo '<input type="hidden" name="job_id" value="' . (int)$job->id . '">'; submit_button('Retomar', 'secondary small', 'submit', false); echo '</form></td></tr>';
        }
        echo '</table><h2>Eventos recentes (retenção de 30 dias)</h2><table class="widefat striped"><tr><th>UTC</th><th>Repositório</th><th>Coleção coletada</th><th>Processo executado</th><th>Nível</th><th>Mensagem</th></tr>';
        $events = $wpdb->get_results('SELECT l.*,r.name repository_name,c.name collection_name FROM ' . DB::table('logs') . ' l LEFT JOIN ' . DB::table('repositories') . ' r ON r.id=l.repository_id LEFT JOIN ' . DB::table('collections') . ' c ON c.id=l.collection_id ORDER BY l.id DESC LIMIT 100');
        foreach ($events as $log) {
            echo '<tr><td>' . esc_html($log->created_at) . '</td><td>' . esc_html($log->repository_name ?: '#' . (int)$log->repository_id) . '</td><td>' . esc_html($log->collection_name ?: '—') . '</td><td>' . esc_html($this->processName((string)$log->process)) . '</td><td>' . esc_html($log->level) . '</td><td>' . esc_html($log->message) . '</td></tr>';
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
        $page = 'midas-repositories';
        $notice = '';
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
                case 'collect':
                    $page = 'midas';
                    $collectionId = absint($_POST['collection_id'] ?? 0);
                    $repo = $wpdb->get_var($wpdb->prepare('SELECT repository_id FROM ' . DB::table('collections') . ' WHERE id=%d AND enabled=1', $collectionId));
                    if (!$repo) { throw new \RuntimeException('Coleção selecionada não encontrada.'); }
                    Queue::enqueue((int)$repo, $collectionId);
                    (new Queue())->tick(false, $collectionId);
                    if (sanitize_key(wp_unslash($_POST['return_page'] ?? '')) === 'midas-documents') { $page = 'midas-documents&collection=' . $collectionId; }
                    $notice = 'A coleta dos itens foi iniciada e continuará pela fila. Consulte Sincronização / Logs para acompanhar.';
                    break;
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
            if (!$notice) { $notice = 'Operação concluída. Consulte Sincronização / Logs para acompanhar a fila.'; }
        } catch (\Throwable $e) { $notice = 'Falha: ' . $e->getMessage(); }
        set_transient('midas_notice_' . get_current_user_id(), $notice, 60);
        wp_safe_redirect(admin_url('admin.php?page=' . $page)); exit;
    }
}
