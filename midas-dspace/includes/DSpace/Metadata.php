<?php
namespace Midas\DSpace;
defined('ABSPATH') || exit;

final class Metadata {
    public static function parse(\SimpleXMLElement $record): array {
        $header = $record->xpath('./*[local-name()="header"]')[0] ?? null;
        if (!$header) { throw new \RuntimeException('Registro sem header.'); }
        $id = \Midas\Oai\Client::value($header, 'identifier');
        if ($id === '') { throw new \RuntimeException('Registro sem identificador.'); }
        $data = ['identifier' => $id, 'datestamp' => \Midas\Oai\Client::value($header, 'datestamp'), 'deleted' => (string)$header['status'] === 'deleted' ? 1 : 0];
        $data['sets'] = array_map('strval', $header->xpath('./*[local-name()="setSpec"]'));
        $data['raw_metadata'] = $record->asXML();
        $map = ['title' => 'title', 'author' => 'creator', 'item_date' => 'date', 'abstract' => 'description', 'subjects' => 'subject', 'language' => 'language', 'license' => 'rights'];
        foreach ($map as $column => $term) {
            $nodes = $record->xpath('.//*[local-name()="metadata"]//*[local-name()="' . $term . '" and (namespace-uri()="http://purl.org/dc/elements/1.1/" or namespace-uri()="http://purl.org/dc/terms/")]');
            // XOAI stores Dublin Core under named element/field nodes.
            if (!$nodes) { $nodes = $record->xpath('.//*[local-name()="element" and @name="dc"]/*[local-name()="element" and @name="' . $term . '"]//*[local-name()="field" and @name="value"]'); }
            $data[$column] = implode('; ', array_unique(array_filter(array_map(static fn($node) => trim((string)$node), $nodes ?: []))));
        }
        $data['title'] = $data['title'] ?: $id;
        $data['item_date'] = substr($data['item_date'], 0, 255);
        $data['source_url'] = '';
        $urls = [];
        foreach ($record->xpath('.//*[local-name()="metadata"]//*') as $node) {
            $value = trim((string)$node);
            if (filter_var($value, FILTER_VALIDATE_URL) && preg_match('~^https?://~i', $value)) { $urls[$value] = ''; }
            foreach (['http://www.w3.org/1999/xlink' => 'href', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#' => 'resource'] as $ns => $attribute) {
                $url = (string)$node->attributes($ns)->{$attribute};
                if (filter_var($url, FILTER_VALIDATE_URL) && preg_match('~^https?://~i', $url)) { $urls[$url] = ''; }
            }
        }
        foreach ($urls as $url => $_) {
            if (str_contains($url, '/handle/') || str_contains($url, 'hdl.handle.net/')) { $data['source_url'] = $url; break; }
        }
        $data['candidates'] = array_keys($urls);
        return $data;
    }
}
