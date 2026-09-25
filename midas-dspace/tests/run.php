<?php
// Pure protocol tests, no WordPress installation required.
define('ABSPATH', __DIR__);
require __DIR__ . '/../includes/Oai/Client.php';
require __DIR__ . '/../includes/DSpace/Metadata.php';
require __DIR__ . '/../includes/Frontend/Shortcodes.php';
use Midas\Oai\Client;
use Midas\DSpace\Metadata;
use Midas\Frontend\Shortcodes;
$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
function rejects(callable $call, string $message): void {
    try { $call(); } catch (RuntimeException $e) { check(true, $message); return; }
    check(false, $message);
}
$xml = Client::xml(file_get_contents(__DIR__ . '/../fixtures/records.xml'));
$record = Metadata::parse($xml->xpath('//*[local-name()="record"]')[0]);
check($record['identifier'] === 'oai:example:123/1', 'OAI identifier');
check($record['title'] === 'Memória audiovisual & patrimônio', 'UTF-8 and XML entities');
check($record['author'] === 'Maria Silva; João Souza', 'Multiple authors');
check($record['sets'] === ['col_1'], 'Header membership');
check($record['source_url'] === 'https://repository.example/handle/123/1', 'Handle URL');
check(count($record['candidates']) === 5, 'Preserve multiple media candidates');
check(Client::token($xml) === 'page:2+/=', 'Opaque token must survive unchanged');
check(Shortcodes::ids('[1,2,3]') === [1,2,3], 'Bracket shortcode form');
check(Shortcodes::ids('1, 2,3') === [1,2,3], 'Comma shortcode form');
check(Shortcodes::ids('1,1,2') === [1,2], 'Deduplicate IDs');
check(Shortcodes::ids('1 OR 1=1') === [0], 'Invalid filter fails closed');
check(Shortcodes::ids('0') === [0], 'Zero filter fails closed');
check(Shortcodes::ids('') === [], 'Empty filter');
rejects(fn() => Client::xml('<!DOCTYPE x [<!ENTITY secret SYSTEM "file:///secret">]><OAI-PMH/>'), 'Reject DTD');
rejects(fn() => Client::xml('<broken'), 'Reject malformed XML');
rejects(fn() => Client::xml('<html/>'), 'Reject HTML');
rejects(fn() => Client::xml('<OAI-PMH xmlns="http://www.openarchives.org/OAI/2.0/"><error code="badResumptionToken">expired</error></OAI-PMH>'), 'Token error');
check(Client::xml('<OAI-PMH xmlns="http://www.openarchives.org/OAI/2.0/"><error code="noRecordsMatch"/></OAI-PMH>') instanceof SimpleXMLElement, 'Empty incremental window');
$deleted = new SimpleXMLElement('<record><header status="deleted"><identifier>oai:deleted</identifier><datestamp>2026-09-25</datestamp></header></record>');
check(Metadata::parse($deleted)['deleted'] === 1, 'Tombstone');
$xoai = new SimpleXMLElement('<record><header><identifier>oai:xoai</identifier></header><metadata><metadata xmlns="http://www.lyncode.com/xoai"><element name="dc"><element name="title"><element name="none"><field name="value">XOAI title</field></element></element></element></metadata></metadata></record>');
check(Metadata::parse($xoai)['title'] === 'XOAI title', 'XOAI named fields');
echo "OK: $checks protocol/parser/filter checks\n";
