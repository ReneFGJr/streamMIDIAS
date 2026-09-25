<?php
namespace Midas\Updates;
defined('ABSPATH') || exit;

final class Github {
    public function register(): void {
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check']);
    }
    public function check($transient) {
        if (!is_object($transient)) { return $transient; }
        $repository = get_option('midas_github', '');
        if (!$repository || $repository === 'OWNER/REPO' || !preg_match('~^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$~', $repository)) { return $transient; }
        $release = get_transient('midas_release');
        if ($release === false) {
            $response = wp_safe_remote_get('https://api.github.com/repos/' . $repository . '/releases/latest', ['timeout' => 15, 'headers' => ['Accept' => 'application/vnd.github+json'], 'limit_response_size' => 1024 * 1024]);
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { set_transient('midas_release', [], 900); return $transient; }
            $release = json_decode(wp_remote_retrieve_body($response), true) ?: [];
            set_transient('midas_release', $release, 3600);
        }
        $version = ltrim($release['tag_name'] ?? '', 'v');
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version) || !empty($release['draft']) || !empty($release['prerelease']) || !version_compare($version, MIDAS_VERSION, '>')) { return $transient; }
        foreach ($release['assets'] ?? [] as $asset) {
            $url = $asset['browser_download_url'] ?? '';
            if (($asset['name'] ?? '') === 'midas-dspace.zip' && str_starts_with($url, 'https://github.com/' . $repository . '/releases/download/')) {
                $plugin = plugin_basename(MIDAS_FILE);
                $transient->response[$plugin] = (object)['slug' => 'midas-dspace', 'plugin' => $plugin, 'new_version' => $version, 'url' => 'https://github.com/' . $repository, 'package' => $url, 'requires' => '6.4', 'requires_php' => '8.1'];
                break;
            }
        }
        return $transient;
    }
}
