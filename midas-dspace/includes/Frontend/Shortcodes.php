<?php
namespace Midas\Frontend;
use Midas\Storage\Database as DB;
defined('ABSPATH') || exit;

final class Shortcodes {
    public function register(): void {
        foreach (['the_content', 'widget_text_content', 'widget_block_content'] as $hook) {
            add_filter($hook, [self::class, 'normalize'], 8);
        }
        add_shortcode('streamVideo', [$this, 'catalog']);
        add_shortcode('streamDocumento', [$this, 'documents']);
        add_shortcode('streamController', [$this, 'controller']);
        add_action('wp_enqueue_scripts', static function () {
            wp_enqueue_style('midas', plugins_url('assets/css/catalog.css', MIDAS_FILE), [], MIDAS_VERSION);
        });
    }
    public static function normalize(string $content): string {
        // Core shortcode regex terminates at a ] even inside a quoted attribute.
        return preg_replace_callback('/(?<!\[)\[(streamController|streamVideo|streamDocumento)\b((?:[^\]"\x27]|"[^"]*"|\x27[^\x27]*\x27)*)\]/', static function ($match) {
            $attributes = preg_replace_callback('/\bcollection\s*=\s*(["\x27])\[([^\]]*)\]\1/', static function ($value) {
                $ids = self::ids('[' . $value[2] . ']');
                return 'collection=' . $value[1] . implode(',', $ids ?: [0]) . $value[1];
            }, $match[2]);
            return '[' . $match[1] . $attributes . ']';
        }, $content);
    }
    public static function ids(string $value): array {
        $value = trim($value);
        if ($value === '') { return []; }
        if (!preg_match('/^(?:\[\s*\d+(?:\s*,\s*\d+)*\s*\]|\d+(?:\s*,\s*\d+)*)$/', $value)) { return [0]; }
        return array_values(array_unique(array_filter(array_map('intval', explode(',', trim($value, '[] ')))))) ?: [0];
    }
    private function query(string $name): string {
        return isset($_GET[$name]) && is_scalar($_GET[$name]) ? sanitize_text_field(wp_unslash((string)$_GET[$name])) : '';
    }
    private function allowed(array $atts): array {
        global $wpdb;
        $published = array_map('intval', $wpdb->get_col('SELECT id FROM ' . DB::table('collections') . ' WHERE enabled=1'));
        $own = self::ids((string)($atts['collection'] ?? ''));
        if ($own) { $published = array_values(array_intersect($published, $own)); }
        // Inspect the page's controller attributes before rendering either shortcode.
        $post = get_post();
        if ($post && preg_match_all('/' . get_shortcode_regex(['streamController']) . '/s', self::normalize($post->post_content), $matches, PREG_SET_ORDER)) {
            $scope = []; $restricted = false;
            foreach ($matches as $match) {
                if ($match[1] === '[' && $match[6] === ']') { continue; }
                $attributes = shortcode_parse_atts($match[3]);
                $ids = self::ids((string)($attributes['collection'] ?? ''));
                if (!$ids) { $restricted = false; $scope = []; break; }
                $restricted = true; $scope = array_merge($scope, $ids);
            }
            if ($restricted) { $published = array_values(array_intersect($published, $scope)); }
        }
        return $published;
    }
    public function controller($atts = []): string {
        global $wpdb;
        $ids = $this->allowed(is_array($atts) ? $atts : []);
        if (!$ids) { return '<p class="midas-empty">Nenhuma coleção disponível.</p>'; }
        $rows = $wpdb->get_results('SELECT id,name FROM ' . DB::table('collections') . ' WHERE enabled=1 AND id IN (' . implode(',', $ids) . ') ORDER BY name');
        $selected = absint($this->query('midas_collection'));
        $html = '<nav class="midas-controller" aria-label="Coleções"><a href="' . esc_url(remove_query_arg(['midas_collection', 'midas_page', 'midas_document_page'])) . '">Todas as coleções</a>';
        foreach ($rows as $row) {
            $html .= '<a ' . ($selected === (int)$row->id ? 'aria-current="page" ' : '') . 'href="' . esc_url(add_query_arg('midas_collection', $row->id, remove_query_arg(['midas_page', 'midas_document_page']))) . '">' . esc_html($row->name) . '</a>';
        }
        $html .= '</nav>';

        $selectedValue = $this->query('midas_collection');
        $pdfIds = $ids;
        if ($selectedValue !== '') { $pdfIds = ctype_digit($selectedValue) ? array_values(array_intersect($ids, [(int)$selectedValue])) : []; }
        if ($pdfIds) {
            $items = DB::table('items');
            $files = DB::table('files');
            $relations = DB::table('item_collections');
            $pdfs = $wpdb->get_results("SELECT i.id,i.title,f.url,f.attachment_id FROM $items i JOIN $files f ON f.item_id=i.id AND f.mime='application/pdf' AND f.verified=1 WHERE i.deleted=0 AND EXISTS (SELECT 1 FROM $relations ic WHERE ic.item_id=i.id AND ic.collection_id IN (" . implode(',', $pdfIds) . ')) ORDER BY i.title,f.id');
            $seen = [];
            $list = '';
            foreach ($pdfs as $pdf) {
                if (isset($seen[$pdf->id])) { continue; }
                $seen[$pdf->id] = true;
                $url = $pdf->attachment_id ? wp_get_attachment_url((int)$pdf->attachment_id) : '';
                $url = $url ?: $pdf->url;
                $list .= '<li><a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer"><span class="midas-pdf-icon" aria-hidden="true">PDF</span><span>' . esc_html($pdf->title) . '</span></a></li>';
            }
            if ($list !== '') { $html .= '<section class="midas-pdf-documents" aria-label="Documentos PDF"><h2>Documentos PDF</h2><ul class="midas-pdf-list">' . $list . '</ul></section>'; }
        }
        return $html;
    }
    public function documents($atts = []): string {
        global $wpdb;
        $ids = $this->allowed(is_array($atts) ? $atts : []);
        $selected = $this->query('midas_collection');
        if ($selected !== '') { $ids = ctype_digit($selected) ? array_values(array_intersect($ids, [(int)$selected])) : []; }
        if (!$ids) { return '<p class="midas-empty">Nenhuma coleção disponível para este filtro.</p>'; }

        $page = min(100000, max(1, absint($this->query('midas_document_page'))));
        $items = DB::table('items');
        $files = DB::table('files');
        $relations = DB::table('item_collections');
        $supported = "('application/pdf','image/jpeg','image/jpg','image/gif','image/png','image/webp')";
        $where = "i.deleted=0 AND EXISTS (SELECT 1 FROM $relations ic WHERE ic.item_id=i.id AND ic.collection_id IN (" . implode(',', $ids) . ")) AND EXISTS (SELECT 1 FROM $files mf WHERE mf.item_id=i.id AND mf.verified=1 AND mf.mime IN $supported)";
        $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM $items i WHERE $where");
        $rows = $wpdb->get_results($wpdb->prepare("SELECT i.* FROM $items i WHERE $where ORDER BY i.id DESC LIMIT %d OFFSET %d", 12, ($page - 1) * 12));
        $html = '<section class="midas-catalog midas-documents" aria-label="Documentos"><p>' . esc_html(number_format_i18n($total)) . ' documento(s)</p><div class="midas-grid midas-document-grid">';
        foreach ($rows as $item) {
            $html .= '<article class="midas-card midas-document-card"><h2>' . esc_html($item->title) . '</h2>';
            $media = $wpdb->get_results($wpdb->prepare("SELECT * FROM $files WHERE item_id=%d AND verified=1 AND mime IN $supported ORDER BY id", $item->id));
            foreach ($media as $file) {
                $url = $file->attachment_id ? wp_get_attachment_url((int)$file->attachment_id) : '';
                $url = $url ?: $file->url;
                if ($file->mime === 'application/pdf') {
                    $html .= '<iframe class="midas-document-pdf" loading="lazy" src="' . esc_url($url) . '" title="PDF: ' . esc_attr($item->title) . '" style="display:block;width:100%;height:32rem;max-height:70vh;border:1px solid #d4dce0;border-radius:.3rem"></iframe><p><a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer"><span class="midas-pdf-icon" aria-hidden="true">PDF</span> Abrir PDF</a></p>';
                } else {
                    $html .= '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer"><img loading="lazy" src="' . esc_url($url) . '" alt="' . esc_attr($item->title) . '"></a>';
                }
            }
            if ($item->author) { $html .= '<p><strong>Autor:</strong> ' . esc_html($item->author) . '</p>'; }
            if ($item->source_url) { $html .= '<p><a href="' . esc_url($item->source_url) . '" target="_blank" rel="noopener noreferrer">Ver registro no repositório</a></p>'; }
            $html .= '</article>';
        }
        $html .= '</div><nav class="midas-pagination" aria-label="Paginação dos documentos">';
        if ($page > 1) { $html .= '<a href="' . esc_url(add_query_arg('midas_document_page', $page - 1)) . '">Anterior</a>'; }
        $html .= '<span>Página ' . $page . ' de ' . max(1, (int)ceil($total / 12)) . '</span>';
        if ($page * 12 < $total) { $html .= '<a href="' . esc_url(add_query_arg('midas_document_page', $page + 1)) . '">Próxima</a>'; }
        return $html . '</nav></section>';
    }
    public function catalog($atts = []): string {
        global $wpdb;
        $ids = $this->allowed(is_array($atts) ? $atts : []);
        $selected = $this->query('midas_collection');
        if ($selected !== '') { $ids = ctype_digit($selected) ? array_values(array_intersect($ids, [(int)$selected])) : []; }
        if (!$ids) { return '<p class="midas-empty">Nenhuma coleção disponível para este filtro.</p>'; }
        $term = substr($this->query('midas_search'), 0, 200);
        $page = min(100000, max(1, absint($this->query('midas_page'))));
        $items = DB::table('items'); $relations = DB::table('item_collections');
        $where = 'i.deleted=0 AND EXISTS (SELECT 1 FROM ' . $relations . ' ic WHERE ic.item_id=i.id AND ic.collection_id IN (' . implode(',', $ids) . '))';
        if ($term !== '') {
            $like = '%' . $wpdb->esc_like($term) . '%';
            $where .= $wpdb->prepare(' AND (i.title LIKE %s OR i.author LIKE %s OR i.abstract LIKE %s OR i.subjects LIKE %s)', $like, $like, $like, $like);
        }
        $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM $items i WHERE $where");
        $rows = $wpdb->get_results($wpdb->prepare("SELECT i.* FROM $items i WHERE $where ORDER BY i.id DESC LIMIT %d OFFSET %d", 12, ($page - 1) * 12));
        $html = '<section class="midas-catalog" aria-label="Catálogo de mídias"><form method="get" action="' . esc_url(get_permalink()) . '" class="midas-search"><label>Buscar no acervo <input type="search" name="midas_search" value="' . esc_attr($term) . '"></label>';
        foreach (['page_id', 'p'] as $key) { if (get_query_var($key)) { $html .= '<input type="hidden" name="' . $key . '" value="' . absint(get_query_var($key)) . '">'; } }
        if ($selected !== '') { $html .= '<input type="hidden" name="midas_collection" value="' . esc_attr($selected) . '">'; }
        $html .= '<button type="submit">Buscar</button></form><p>' . $total . ' registro(s)</p><div class="midas-grid">';
        foreach ($rows as $item) {
            $html .= '<article class="midas-card"><h2>' . esc_html($item->title) . '</h2>';
            $files = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . DB::table('files') . ' WHERE item_id=%d AND verified=1 ORDER BY id', $item->id));
            foreach ($files as $file) {
                $url = $file->attachment_id ? wp_get_attachment_url($file->attachment_id) : '';
                $url = $url ?: $file->url;
                if (str_starts_with($file->mime, 'video/')) {
                    $html .= '<video controls preload="metadata" playsinline><source src="' . esc_url($url) . '" type="' . esc_attr($file->mime) . '">Seu navegador não reproduz este vídeo.</video><p><a href="' . esc_url($file->url) . '">Abrir vídeo original</a></p>';
                } elseif (str_starts_with($file->mime, 'image/')) {
                    $html .= '<a href="' . esc_url($file->url) . '"><img loading="lazy" src="' . esc_url($url) . '" alt="' . esc_attr($item->title) . '"></a>';
                } elseif ($file->mime === 'application/pdf') { $html .= '<p><a href="' . esc_url($url) . '">Abrir PDF</a></p>'; }
            }
            if (!$files) { $html .= '<p>Mídia indisponível. Metadados preservados.</p>'; }
            $html .= '<dl>';
            foreach (['author' => 'Autor', 'item_date' => 'Data', 'subjects' => 'Assuntos', 'language' => 'Idioma', 'license' => 'Licença', 'identifier' => 'Identificador OAI'] as $field => $label) {
                if ($item->$field) { $html .= '<dt>' . $label . '</dt><dd>' . esc_html($item->$field) . '</dd>'; }
            }
            $html .= '</dl>';
            if ($item->abstract) { $html .= '<details><summary>Resumo</summary><p>' . nl2br(esc_html($item->abstract)) . '</p></details>'; }
            if ($item->source_url) { $html .= '<p><a href="' . esc_url($item->source_url) . '">Registro no repositório</a></p>'; }
            $html .= '</article>';
        }
        $html .= '</div><nav class="midas-pagination" aria-label="Paginação">';
        if ($page > 1) { $html .= '<a href="' . esc_url(add_query_arg('midas_page', $page - 1)) . '">Anterior</a>'; }
        $html .= '<span>Página ' . $page . ' de ' . max(1, (int)ceil($total / 12)) . '</span>';
        if ($page * 12 < $total) { $html .= '<a href="' . esc_url(add_query_arg('midas_page', $page + 1)) . '">Próxima</a>'; }
        return $html . '</nav></section>';
    }
}
