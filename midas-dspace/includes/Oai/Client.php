<?php
namespace Midas\Oai;
defined('ABSPATH') || exit;

final class ProtocolException extends \RuntimeException {}

final class Client {
    public function __construct(private string $endpoint) {}
    public static function xml(string $body): \SimpleXMLElement {
        if (!function_exists('simplexml_load_string')) { throw new \RuntimeException('A extensão PHP SimpleXML é necessária.'); }
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $body)) { throw new \RuntimeException('XML com DTD/entidades não é permitido.'); }
        $previous = libxml_use_internal_errors(true);
        try { $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        if (!$xml || $xml->getName() !== 'OAI-PMH') { throw new \RuntimeException('Resposta OAI-PMH inválida.'); }
        $namespaces = $xml->getDocNamespaces();
        if (!in_array('http://www.openarchives.org/OAI/2.0/', $namespaces, true)) { throw new \RuntimeException('Namespace OAI inválido.'); }
        foreach ($xml->xpath('/*/*[local-name()="error"]') as $error) {
            if ((string)$error['code'] !== 'noRecordsMatch') { throw new ProtocolException((string)$error['code']); }
        }
        return $xml;
    }
    public function request(string $verb, array $args = []): \SimpleXMLElement {
        $response = wp_safe_remote_get(add_query_arg(['verb' => $verb] + $args, $this->endpoint), ['timeout' => 30, 'redirection' => 3, 'limit_response_size' => 12 * 1024 * 1024]);
        if (is_wp_error($response)) { throw new \RuntimeException($response->get_error_message()); }
        if (wp_remote_retrieve_response_code($response) !== 200) { throw new \RuntimeException('HTTP ' . wp_remote_retrieve_response_code($response)); }
        $xml = self::xml(wp_remote_retrieve_body($response));
        $section = $xml->xpath('/*/*[local-name()="' . $verb . '"]');
        $empty = $xml->xpath('/*/*[local-name()="error" and @code="noRecordsMatch"]');
        if (!$section && !(in_array($verb, ['ListRecords', 'ListIdentifiers'], true) && $empty)) { throw new \RuntimeException('Resposta incompleta para ' . $verb); }
        return $xml;
    }
    public function identify(): array {
        $xml = $this->request('Identify');
        $granularity = self::value($xml, 'granularity');
        if (!in_array($granularity, ['YYYY-MM-DD', 'YYYY-MM-DDThh:mm:ssZ'], true)) { throw new \RuntimeException('Granularidade OAI não suportada.'); }
        $formats = $this->request('ListMetadataFormats');
        $prefixes = array_map('strval', $formats->xpath('//*[local-name()="metadataPrefix"]'));
        $prefix = '';
        foreach (['mets', 'xoai', 'ore', 'oai_dc'] as $supported) {
            if (in_array($supported, $prefixes, true)) { $prefix = $supported; break; }
        }
        if (!$prefix) { throw new \RuntimeException('Nenhum formato compatível anunciado.'); }
        return ['metadata_prefix' => $prefix, 'granularity' => $granularity, 'formats' => wp_json_encode($prefixes)];
    }
    public static function value(\SimpleXMLElement $xml, string $name): string {
        $nodes = $xml->xpath('.//*[local-name()="' . $name . '"]');
        return trim((string)($nodes[0] ?? ''));
    }
    public static function token(\SimpleXMLElement $xml): string { return self::value($xml, 'resumptionToken'); }
}
