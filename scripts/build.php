<?php
/** Build the WordPress upload package: php scripts/build.php */
if (PHP_SAPI !== 'cli') { exit; }
if (!class_exists('ZipArchive')) { throw new RuntimeException('Ative a extensão PHP zip.'); }
$root = dirname(__DIR__);
$source = $root . '/midas-dspace';
$output = $root . '/dist/midas-dspace.zip';
$required = ['includes/Admin/Discovery.php', 'assets/js/discovery.js', 'midas-dspace.php', 'uninstall.php', 'includes/Admin/Pages.php', 'includes/Oai/Client.php', 'includes/DSpace/Media.php', 'includes/DSpace/Metadata.php', 'includes/Storage/Database.php', 'includes/Storage/Items.php', 'includes/Sync/Queue.php', 'includes/Sync/Cli.php', 'includes/Frontend/Shortcodes.php', 'includes/Updates/Github.php', 'assets/css/catalog.css'];
foreach ($required as $file) {
    if (!is_file($source . '/' . $file) || filesize($source . '/' . $file) === 0) { throw new RuntimeException('Arquivo ausente ou vazio: ' . $file); }
}
if (!preg_match('/^\s*\*\s*Plugin Name:\s*\S+/m', file_get_contents($source . '/midas-dspace.php'))) {
    throw new RuntimeException('Cabeçalho WordPress ausente.');
}
if (!is_dir(dirname($output)) && !mkdir(dirname($output), 0775, true)) { throw new RuntimeException('Não foi possível criar dist.'); }
$zip = new ZipArchive();
if ($zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { throw new RuntimeException('Não foi possível abrir o ZIP.'); }
$files = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
    if (!$file->isFile() || $file->isLink()) { continue; }
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));
    if (str_starts_with($relative, 'tests/') || str_starts_with($relative, 'fixtures/')) { continue; }
    $files[$relative] = $file->getPathname();
}
ksort($files);
foreach ($files as $relative => $path) {
    if (!$zip->addFile($path, 'midas-dspace/' . $relative)) { throw new RuntimeException('Falha ao adicionar: ' . $relative); }
}
if (!$zip->close()) { throw new RuntimeException('Falha ao gravar o ZIP.'); }
if ($zip->open($output, ZipArchive::CHECKCONS) !== true) { throw new RuntimeException('ZIP inválido.'); }
foreach ($required as $file) {
    $entry = $zip->statName('midas-dspace/' . $file);
    if (!$entry || !$entry['size']) { throw new RuntimeException('Arquivo ausente no pacote: ' . $file); }
}
$count = $zip->numFiles;
$zip->close();
echo "Pacote validado: $output\n$count arquivos; " . filesize($output) . " bytes\nSHA-256: " . hash_file('sha256', $output) . "\n";
