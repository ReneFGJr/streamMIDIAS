<?php
namespace Midas\DSpace;
defined('ABSPATH') || exit;

final class Media {
    public static function resolve(array $record, object $repository): array {
        $urls = array_fill_keys($record['candidates'], '');
        if ($repository->rest_url && $record['source_url']) {
            foreach (self::rest($repository->rest_url, $record['source_url']) as $url => $bundle) { $urls[$url] = $bundle; }
        }
        $files = [];
        foreach ($urls as $url => $bundle) {
            $response = wp_safe_remote_head($url, ['timeout' => 8, 'redirection' => 3]);
            if (is_wp_error($response)) { throw new \RuntimeException('Falha temporária ao verificar mídia: ' . $response->get_error_message()); }
            $status = wp_remote_retrieve_response_code($response);
            if ($status === 429 || $status >= 500) { throw new \RuntimeException('Falha temporária de mídia: HTTP ' . $status); }
            if ($status !== 200) { continue; }
            $mime = strtolower(trim(explode(';', wp_remote_retrieve_header($response, 'content-type'))[0]));
            if (!preg_match('~^(video/[a-z0-9.+-]+|image/(jpeg|png|gif|webp|avif)|application/pdf)$~', $mime)) { continue; }
            $files[] = ['url' => $url, 'mime' => $mime, 'bundle' => $bundle];
        }
        return $files;
    }
    private static function json(string $url): array {
        $r = wp_safe_remote_get($url, ['timeout' => 15, 'limit_response_size' => 4 * 1024 * 1024, 'headers' => ['Accept' => 'application/json']]);
        if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200) { return []; }
        $decoded = json_decode(wp_remote_retrieve_body($r), true);
        return is_array($decoded) ? $decoded : [];
    }
    private static function rest(string $base, string $source): array {
        $handle = preg_replace('~^https?://[^/]+/(?:handle/)?~', '', $source);
        if (!preg_match('~^[0-9.]+/[^/?#]+$~', $handle)) { return []; }
        $base = rtrim($base, '/');
        $files = [];
        // DSpace 7+: follow public HAL links supplied by the server.
        $item = self::json($base . '/pid/find?id=' . rawurlencode($handle));
        $bundlesUrl = $item['_links']['bundles']['href'] ?? '';
        foreach (self::pages($bundlesUrl, 'bundles') as $bundle) {
            foreach (self::pages($bundle['_links']['bitstreams']['href'] ?? '', 'bitstreams') as $bitstream) {
                $url = $bitstream['_links']['content']['href'] ?? '';
                if ($url) { $files[$url] = $bundle['name'] ?? ''; }
            }
        }
        if ($item) { return $files; }
        // DSpace 5/6 legacy REST: retrieveLink is provided by the API.
        $item = self::json($base . '/handle/' . implode('/', array_map('rawurlencode', explode('/', $handle))) . '?expand=bitstreams');
        foreach ($item['bitstreams'] ?? [] as $bitstream) {
            $url = $bitstream['retrieveLink'] ?? '';
            if (str_starts_with($url, '/bitstreams/')) { $url = $base . $url; }
            elseif (str_starts_with($url, '/')) {
                $parts = wp_parse_url($base);
                $url = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . $url;
            }
            if ($url) { $files[$url] = $bitstream['bundleName'] ?? ''; }
        }
        return $files;
    }
    private static function pages(string $url, string $relation): array {
        $seen = []; $result = [];
        while ($url && !isset($seen[$url])) {
            $seen[$url] = true;
            $page = self::json($url);
            $result = array_merge($result, $page['_embedded'][$relation] ?? []);
            $url = $page['_links']['next']['href'] ?? '';
        }
        return $result;
    }
    public static function copy(string $url, string $mime): int {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $temp = wp_tempnam('midas');
        if (!$temp) { return 0; }
        $limit = min(wp_max_upload_size(), 100 * 1024 * 1024);
        $response = wp_safe_remote_get($url, ['timeout' => 60, 'stream' => true, 'filename' => $temp, 'limit_response_size' => $limit + 1]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200 || filesize($temp) > $limit) { wp_delete_file($temp); return 0; }
        $name = sanitize_file_name(basename(wp_parse_url($url, PHP_URL_PATH) ?: ''));
        if (!pathinfo($name, PATHINFO_EXTENSION)) {
            $ext = ['video/mp4' => 'mp4', 'video/webm' => 'webm', 'video/ogg' => 'ogv', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/avif' => 'avif', 'application/pdf' => 'pdf'][$mime] ?? '';
            if (!$ext) { wp_delete_file($temp); return 0; }
            $name = 'midas-' . substr(hash('sha256', $url), 0, 20) . '.' . $ext;
        }
        $id = media_handle_sideload(['name' => $name, 'tmp_name' => $temp], 0);
        if (is_wp_error($id)) { wp_delete_file($temp); return 0; }
        update_post_meta($id, '_midas_owned', 1);
        return (int)$id;
    }
}
