<?php
/**
 * Plugin Name: MIDAS — Mídias Digitais de Acervos e Sistemas
 * Description: Catálogo de acervos DSpace por OAI-PMH.
 * Update URI: midas-dspace
 * Version: 0.1.0
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * Text Domain: midas-dspace
 */
defined('ABSPATH') || exit;
define('MIDAS_VERSION', '0.1.0');
define('MIDAS_FILE', __FILE__);
spl_autoload_register(static function ($class) {
    if (str_starts_with($class, 'Midas\\')) {
        $file = __DIR__ . '/includes/' . str_replace('\\', '/', substr($class, 6)) . '.php';
        if (is_file($file)) { require_once $file; }
    }
});
register_activation_hook(__FILE__, ['Midas\\Storage\\Database', 'install']);
register_deactivation_hook(__FILE__, static function () {
    wp_clear_scheduled_hook('midas_tick');
    if (function_exists('as_unschedule_all_actions')) { as_unschedule_all_actions('midas_tick', [], 'midas'); }
});
add_action('plugins_loaded', static function () {
    if (get_option('midas_schema') !== Midas\Storage\Database::VERSION) { Midas\Storage\Database::install(); }
    (new Midas\Admin\Pages())->register();
    (new Midas\Frontend\Shortcodes())->register();
    (new Midas\Sync\Queue())->register();
    (new Midas\Updates\Github())->register();
    if (defined('WP_CLI') && WP_CLI) { WP_CLI::add_command('midas', Midas\Sync\Cli::class); }
});
