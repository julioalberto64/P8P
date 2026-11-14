<?php

namespace P8P\Nodes\Core;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use P8P\Core\ExpressionEvaluator;
use P8P\Interfaces\NodeInterface;
use RuntimeException;

/** Bounded, in-memory input with stream-based CSV processing; no server paths. */
class XmlCsvParserNode implements NodeInterface
{
    private const MAX_BYTES = 5242880;
    private const MAX_ITEMS = 50000;
    private const MAX_DEPTH = 64;

    public function getSchema(): array
    {
        return [
            'type' => 'xml_csv_parser', 'name' => 'XML & CSV Parser', 'category' => 'Core', 'evaluates_expressions' => true,
            'description' => 'Convierte XML/estructura JSON y CSV/filas sin acceso a archivos ni red.',
            'icon' => 'FileCode', 'is_start' => false,
            'help_text' => 'Operaciones xml_to_json, json_to_xml, csv_to_rows, rows_to_csv. input acepta texto, JSON/array o archivo {content_base64,mime_type?,filename?} de otros nodos; nunca rutas. Máximo 5 MiB, 50000 elementos/filas y profundidad XML 64. XML usa {name,namespace,attributes:[{name,namespace,value}],namespaces:{prefijo:URI},children:[elemento|texto]}; conserva orden/texto/elementos vacíos, omite comentarios/PI/CDATA como distinción léxica. No reversible para todo XML; DTD/entidades prohibidos. CSV conserva strings y ceros, escape vacío por defecto, cabeceras duplicate_headers=error|suffix y filas irregulares error|pad (pad rechaza exceso). headers=false usa filas posicionales. Codificación UTF-8, ISO-8859-1 o Windows-1252; BOM UTF-8 se elimina. Exportar protect_formulas=true antepone apóstrofo explícitamente a campos con =,+,-,@,tab/CR/LF al inicio. Se usa stream temporal, sin carga de rutas; entradas mayores se rechazan.',
            'parameters' => [
                'operation' => ['type' => 'select', 'required' => true, 'label' => 'Operación', 'default' => 'csv_to_rows', 'options' => array_map(static fn ($v) => ['value' => $v, 'label' => $v], ['xml_to_json', 'json_to_xml', 'csv_to_rows', 'rows_to_csv'])],
                'input' => ['type' => 'textarea', 'required' => true, 'label' => 'Texto, JSON o {{archivo}} / {{filas}}'],
                'delimiter' => ['type' => 'string', 'label' => 'Delimitador (un byte)', 'default' => ','],
                'enclosure' => ['type' => 'string', 'label' => 'Comillas (un byte)', 'default' => '"'],
                'escape' => ['type' => 'string', 'label' => 'Escape (vacío o un byte)', 'default' => ''],
                'headers' => ['type' => 'select', 'label' => 'Encabezados', 'default' => 'true', 'options' => [['value' => 'true', 'label' => 'Sí'], ['value' => 'false', 'label' => 'No']]],
                'columns' => ['type' => 'textarea', 'label' => 'Columnas JSON explícitas al exportar (opcional)'],
                'encoding' => ['type' => 'select', 'label' => 'Codificación', 'default' => 'UTF-8', 'options' => array_map(static fn ($v) => ['value' => $v, 'label' => $v], ['UTF-8', 'ISO-8859-1', 'Windows-1252'])],
                'duplicate_headers' => ['type' => 'select', 'label' => 'Encabezados duplicados', 'default' => 'error', 'options' => [['value' => 'error', 'label' => 'Error'], ['value' => 'suffix', 'label' => 'Sufijo _2, _3…']]],
                'irregular_rows' => ['type' => 'select', 'label' => 'Filas irregulares', 'default' => 'error', 'options' => [['value' => 'error', 'label' => 'Error'], ['value' => 'pad', 'label' => 'Completar faltantes con vacío']]],
                'protect_formulas' => ['type' => 'select', 'label' => 'Proteger fórmulas al exportar', 'default' => 'false', 'options' => [['value' => 'false', 'label' => 'No alterar valores'], ['value' => 'true', 'label' => 'Anteponer apóstrofo']]]
            ]
        ];
    }

    public function validate(array $parameters): bool
    {
        try { $this->prepare($parameters); return true; } catch (InvalidArgumentException $e) { return false; }
    }

    public function execute(array $parameters, array $context): array
    {
        $p = $this->prepare(ExpressionEvaluator::evaluate($parameters, $context));
        $operation = $p['operation'];
        if ($operation === 'xml_to_json') {
            return ['success' => true, 'data' => $this->parseXml($this->textInput($p['input'], $p['encoding']))];
        }
        if ($operation === 'json_to_xml') {
            $root = $this->structure($p['input']);
            $doc = new DOMDocument('1.0', 'UTF-8');
            $count = 0;
            $doc->appendChild($this->writeElement($doc, $root, [], 1, $count));
            $text = $doc->saveXML();
            if (!is_string($text) || strlen($text) > self::MAX_BYTES) { throw new InvalidArgumentException('Parser: salida XML mayor de 5 MiB.'); }
            return $this->fileOutput($text, 'application/xml', 'output.xml');
        }
        if ($operation === 'csv_to_rows') {
            return ['success' => true, 'data' => $this->parseCsv($this->textInput($p['input'], $p['encoding']), $p)];
        }
        return $this->exportCsv($this->structure($p['input']), $p);
    }

    private function prepare(array $p): array
    {
        if (!in_array($p['operation'] ?? null, ['xml_to_json', 'json_to_xml', 'csv_to_rows', 'rows_to_csv'], true) || !array_key_exists('input', $p)) { throw new InvalidArgumentException('Parser: operación o entrada ausente.'); }
        $p += ['delimiter' => ',', 'enclosure' => '"', 'escape' => '', 'headers' => 'true', 'encoding' => 'UTF-8', 'duplicate_headers' => 'error', 'irregular_rows' => 'error', 'protect_formulas' => 'false'];
        foreach (['delimiter', 'enclosure', 'escape'] as $key) {
            if (!is_string($p[$key]) || strlen($p[$key]) > 1 || ($key !== 'escape' && strlen($p[$key]) !== 1) || strpbrk($p[$key], "\r\n\0") !== false || ($p[$key] !== '' && ord($p[$key]) > 127)) { throw new InvalidArgumentException('Parser: delimitador/comillas/escape deben ser caracteres ASCII distintos de salto de línea.'); }
        }
        if ($p['delimiter'] === $p['enclosure'] || !in_array($p['encoding'], ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true) || !in_array($p['duplicate_headers'], ['error', 'suffix'], true) || !in_array($p['irregular_rows'], ['error', 'pad'], true)) { throw new InvalidArgumentException('Parser: opciones CSV inválidas.'); }
        foreach (['headers', 'protect_formulas'] as $key) {
            if (!in_array($p[$key], [true, false, 'true', 'false'], true)) { throw new InvalidArgumentException('Parser: opción booleana inválida.'); }
            $p[$key] = $p[$key] === true || $p[$key] === 'true';
        }
        return $p;
    }

    private function textInput(mixed $input, string $encoding): string
    {
        if (is_array($input)) {
            $encoded = $input['content_base64'] ?? null;
            if (!is_string($encoded) || strlen($encoded) > 6990508 || ($input = base64_decode($encoded, true)) === false || base64_encode($input) !== $encoded) { throw new InvalidArgumentException('Parser: archivo content_base64 inválido.'); }
        }
        if (!is_string($input) || strlen($input) > self::MAX_BYTES) { throw new InvalidArgumentException('Parser: se requiere texto/archivo de máximo 5 MiB.'); }
        if (str_starts_with($input, "\xEF\xBB\xBF")) { $input = substr($input, 3); }
        if (!mb_check_encoding($input, $encoding)) { throw new InvalidArgumentException('Parser: codificación de entrada inválida.'); }
        $text = mb_convert_encoding($input, 'UTF-8', $encoding);
        if (strlen($text) > self::MAX_BYTES) { throw new InvalidArgumentException('Parser: texto convertido mayor de 5 MiB.'); }
        return $text;
    }

    private function structure(mixed $value): array
    {
        if (is_string($value)) {
            if (strlen($value) > self::MAX_BYTES) { throw new InvalidArgumentException('Parser: JSON mayor de 5 MiB.'); }
            try { $value = json_decode($value, true, 256, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); } catch (\JsonException $e) { throw new InvalidArgumentException('Parser: JSON inválido.'); }
        }
        if (!is_array($value)) { throw new InvalidArgumentException('Parser: se requiere una estructura JSON.'); }
        try { $size = strlen(json_encode($value, JSON_THROW_ON_ERROR)); } catch (\JsonException $e) { throw new InvalidArgumentException('Parser: estructura no representable como JSON.'); }
        if ($size > self::MAX_BYTES) { throw new InvalidArgumentException('Parser: estructura mayor de 5 MiB.'); }
        return $value;
    }

    private function parseXml(string $text): array
    {
        if (!class_exists(\XMLReader::class) || !class_exists(DOMDocument::class)) { throw new RuntimeException('Parser: requiere extensiones dom y xmlreader.'); }
        if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $text)) { throw new InvalidArgumentException('Parser: DTD y entidades no permitidos.'); }
        // Read first to enforce depth/count before constructing a DOM. No entity expansion or DTD loading.
        $reader = new \XMLReader();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$reader->XML($text, 'UTF-8', LIBXML_NONET)) { throw new InvalidArgumentException('Parser: XML inválido.'); }
            $reader->setParserProperty(\XMLReader::LOADDTD, false);
            $reader->setParserProperty(\XMLReader::SUBST_ENTITIES, false);
            $count = 0;
            while ($reader->read()) {
                if ($reader->nodeType === \XMLReader::DOC_TYPE || $reader->nodeType === \XMLReader::ENTITY_REF || $reader->depth >= self::MAX_DEPTH || ($reader->nodeType === \XMLReader::ELEMENT && ++$count > self::MAX_ITEMS)) { throw new InvalidArgumentException('Parser: XML excede límites o contiene entidades.'); }
            }
            if (libxml_get_errors() !== []) { throw new InvalidArgumentException('Parser: XML inválido.'); }
            $doc = new DOMDocument();
            $doc->resolveExternals = false;
            $doc->substituteEntities = false;
            if (!$doc->loadXML($text, LIBXML_NONET) || !$doc->documentElement) { throw new InvalidArgumentException('Parser: XML inválido.'); }
            return $this->readElement($doc->documentElement);
        } finally {
            $reader->close(); libxml_clear_errors(); libxml_use_internal_errors($previous);
        }
    }

    private function readElement(DOMElement $element): array
    {
        $attributes = [];
        foreach ($element->attributes as $attribute) {
            $attributes[] = ['name' => $attribute->nodeName, 'namespace' => $attribute->namespaceURI ?? '', 'value' => $attribute->nodeValue];
        }
        $namespaces = [];
        $xpath = new \DOMXPath($element->ownerDocument);
        foreach ($xpath->query('namespace::*', $element) as $ns) {
            if ($ns->nodeName !== 'xmlns:xml') { $namespaces[$ns->nodeName === 'xmlns' ? '' : substr($ns->nodeName, 6)] = $ns->nodeValue ?? ''; }
        }
        $children = [];
        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) { $children[] = $this->readElement($child); }
            elseif ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) { $children[] = $child->nodeValue; }
        }
        return ['name' => $element->nodeName, 'namespace' => $element->namespaceURI ?? '', 'namespaces' => (object) $namespaces, 'attributes' => $attributes, 'children' => $children];
    }

    private function writeElement(DOMDocument $doc, array $node, array $inherited, int $depth, int &$count): DOMElement
    {
        if ($depth > self::MAX_DEPTH || ++$count > self::MAX_ITEMS || array_diff(array_keys($node), ['name', 'namespace', 'namespaces', 'attributes', 'children'])) { throw new InvalidArgumentException('Parser: estructura XML inválida o excede límites.'); }
        $name = $this->xmlName($node['name'] ?? null);
        $namespace = $node['namespace'] ?? '';
        $namespaces = $node['namespaces'] ?? [];
        if ($namespaces instanceof \stdClass) { $namespaces = (array) $namespaces; }
        if (!is_string($namespace) || !is_array($namespaces) || !is_array($node['attributes'] ?? []) || !array_is_list($node['attributes'] ?? []) || !is_array($node['children'] ?? []) || !array_is_list($node['children'] ?? [])) { throw new InvalidArgumentException('Parser: namespaces, attributes o children inválidos.'); }
        foreach ($namespaces as $prefix => $uri) {
            if (!is_string($uri) || ($prefix !== '' && (!is_string($prefix) || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $prefix) !== 1)) || in_array($prefix, ['xml', 'xmlns'], true) || $uri === 'http://www.w3.org/2000/xmlns/' || $uri === 'http://www.w3.org/XML/1998/namespace') { throw new InvalidArgumentException('Parser: declaración namespace inválida.'); }
        }
        $this->xmlText($namespace);
        foreach ($namespaces as $uri) { $this->xmlText($uri); }
        $bindings = array_replace($inherited, $namespaces);
        $bindings['xml'] = 'http://www.w3.org/XML/1998/namespace';
        $parts = explode(':', $name);
        if (count($parts) === 2 && (($bindings[$parts[0]] ?? null) !== $namespace || $namespace === '')) { throw new InvalidArgumentException('Parser: prefijo sin namespace coincidente.'); }
        if (count($parts) === 1 && ($bindings[''] ?? '') !== $namespace) { $namespaces[''] = $namespace; $bindings[''] = $namespace; }
        try { $element = $namespace === '' ? $doc->createElement($name) : $doc->createElementNS($namespace, $name); } catch (\Throwable $e) { throw new InvalidArgumentException('Parser: nombre XML inválido.'); }
        foreach ($namespaces as $prefix => $uri) { $element->setAttributeNS('http://www.w3.org/2000/xmlns/', $prefix === '' ? 'xmlns' : 'xmlns:' . $prefix, $uri); }
        $seen = [];
        foreach ($node['attributes'] ?? [] as $attribute) {
            if (!is_array($attribute) || array_diff(array_keys($attribute), ['name', 'namespace', 'value']) || !is_string($attribute['value'] ?? null)) { throw new InvalidArgumentException('Parser: atributo inválido.'); }
            $attrName = $this->xmlName($attribute['name'] ?? null);
            $uri = $attribute['namespace'] ?? '';
            $parts = explode(':', $attrName);
            if (!is_string($uri) || $attrName === 'xmlns' || $parts[0] === 'xmlns' || (count($parts) === 1 && $uri !== '') || (count($parts) === 2 && (($bindings[$parts[0]] ?? null) !== $uri || $uri === ''))) { throw new InvalidArgumentException('Parser: namespace de atributo inválido.'); }
            $this->xmlText($uri);
            $key = $uri . '|' . end($parts);
            if (isset($seen[$key])) { throw new InvalidArgumentException('Parser: atributo duplicado.'); }
            $seen[$key] = true;
            $this->xmlText($attribute['value']);
            $uri === '' ? $element->setAttribute($attrName, $attribute['value']) : $element->setAttributeNS($uri, $attrName, $attribute['value']);
        }
        foreach ($node['children'] ?? [] as $child) {
            if (is_string($child)) { $this->xmlText($child); $element->appendChild($doc->createTextNode($child)); }
            elseif (is_array($child)) { $element->appendChild($this->writeElement($doc, $child, $bindings, $depth + 1, $count)); }
            else { throw new InvalidArgumentException('Parser: hijos deben ser texto o elementos.'); }
        }
        return $element;
    }

    private function xmlName(mixed $name): string
    {
        if (!is_string($name) || strlen($name) > 255 || preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*(?::[A-Za-z_][A-Za-z0-9_.-]*)?$/D', $name) !== 1 || $name === 'xmlns') { throw new InvalidArgumentException('Parser: nombres XML ASCII válidos requeridos.'); }
        return $name;
    }

    private function xmlText(string $value): void
    {
        if (!mb_check_encoding($value, 'UTF-8') || preg_match('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', $value)) { throw new InvalidArgumentException('Parser: caracteres XML inválidos.'); }
    }

    private function parseCsv(string $text, array $p): array
    {
        $stream = fopen('php://temp/maxmemory:1048576', 'w+');
        if ($stream === false) { throw new RuntimeException('Parser: no se pudo abrir stream temporal.'); }
        try {
            fwrite($stream, $text); rewind($stream);
            $rows = []; $columns = null; $width = null;
            while (($row = fgetcsv($stream, 0, $p['delimiter'], $p['enclosure'], $p['escape'])) !== false) {
                $row = array_map(static fn ($v) => $v ?? '', $row);
                if ($width === null) {
                    $width = count($row);
                    if ($p['headers']) { $columns = $this->headers($row, $p['duplicate_headers']); continue; }
                }
                if (count($row) !== $width) {
                    if ($p['irregular_rows'] === 'pad' && count($row) < $width) { $row = array_pad($row, $width, ''); }
                    else { throw new InvalidArgumentException('Parser: fila CSV con longitud irregular.'); }
                }
                $rows[] = $columns !== null ? (object) array_combine($columns, $row) : $row;
                if (count($rows) > self::MAX_ITEMS) { throw new InvalidArgumentException('Parser: CSV supera 50000 filas.'); }
            }
            return ['rows' => $rows, 'columns' => $columns ?? [], 'row_count' => count($rows)];
        } finally { fclose($stream); }
    }

    private function headers(array $row, string $policy): array
    {
        $used = []; $result = [];
        foreach ($row as $value) {
            if ((!is_string($value) && !is_int($value)) || (string) $value === '' || strlen((string) $value) > 1024) { throw new InvalidArgumentException('Parser: encabezado vacío o inválido.'); }
            $value = (string) $value; $candidate = $value; $i = 2;
            if ($policy === 'error' && isset($used[$candidate])) { throw new InvalidArgumentException('Parser: encabezados duplicados.'); }
            while (isset($used[$candidate])) { $candidate = $value . '_' . $i++; }
            $used[$candidate] = true; $result[] = $candidate;
        }
        return $result;
    }

    private function exportCsv(array $rows, array $p): array
    {
        if (!array_is_list($rows) || count($rows) > self::MAX_ITEMS) { throw new InvalidArgumentException('Parser: se requiere una lista de hasta 50000 filas.'); }
        $columns = isset($p['columns']) && $p['columns'] !== '' ? $this->structure($p['columns']) : null;
        if ($columns !== null && !array_is_list($columns)) { throw new InvalidArgumentException('Parser: columns debe ser una lista.'); }
        if ($p['headers'] && $columns === null && $rows !== []) { $columns = is_array($rows[0]) || $rows[0] instanceof \stdClass ? array_keys((array) $rows[0]) : null; }
        if ($columns !== null) { $columns = $this->headers($columns, 'error'); }
        $stream = fopen('php://temp/maxmemory:1048576', 'w+');
        if ($stream === false) { throw new RuntimeException('Parser: no se pudo abrir stream temporal.'); }
        try {
            if ($p['headers'] && $columns !== null) { $this->csvLine($stream, $columns, $p); }
            $width = $columns !== null ? count($columns) : null;
            foreach ($rows as $row) {
                if ($row instanceof \stdClass) { $row = (array) $row; }
                if (!is_array($row)) { throw new InvalidArgumentException('Parser: fila inválida.'); }
                if ($p['headers']) {
                    if (array_diff(array_map('strval', array_keys($row)), $columns ?? [])) { throw new InvalidArgumentException('Parser: fila contiene columnas desconocidas.'); }
                    $values = [];
                    foreach ($columns ?? [] as $column) {
                        if (!array_key_exists($column, $row) && $p['irregular_rows'] !== 'pad') { throw new InvalidArgumentException('Parser: falta columna.'); }
                        $values[] = array_key_exists($column, $row) ? $row[$column] : '';
                    }
                } else {
                    if (!array_is_list($row)) { throw new InvalidArgumentException('Parser: headers=false requiere filas posicionales.'); }
                    $width ??= count($row);
                    if (count($row) !== $width) {
                        if ($p['irregular_rows'] !== 'pad' || count($row) > $width) { throw new InvalidArgumentException('Parser: fila irregular.'); }
                        $row = array_pad($row, $width, '');
                    }
                    $values = $row;
                }
                foreach ($values as $value) { if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) { throw new InvalidArgumentException('Parser: CSV exporta strings UTF-8; no convierte tipos automáticamente.'); } }
                $this->csvLine($stream, $values, $p);
            }
            rewind($stream); $text = stream_get_contents($stream);
            if (!is_string($text)) { throw new RuntimeException('Parser: lectura temporal fallida.'); }
            $encoded = iconv('UTF-8', $p['encoding'], $text);
            if ($encoded === false || strlen($encoded) > self::MAX_BYTES) { throw new InvalidArgumentException('Parser: salida no representable o mayor de 5 MiB.'); }
            return $this->fileOutput($encoded, 'text/csv', 'output.csv') + ['row_count' => count($rows), 'encoding' => $p['encoding'], 'formula_protection' => $p['protect_formulas']];
        } finally { fclose($stream); }
    }

    private function csvLine($stream, array $values, array $p): void
    {
        if ($p['protect_formulas']) { $values = array_map(static fn ($v) => preg_match('/^[=+\-@\t\r\n]/', $v) ? "'" . $v : $v, $values); }
        if (fputcsv($stream, $values, $p['delimiter'], $p['enclosure'], $p['escape'], "\r\n") === false || ftell($stream) > self::MAX_BYTES) { throw new InvalidArgumentException('Parser: escritura CSV fallida o salida mayor de 5 MiB.'); }
    }

    private function fileOutput(string $text, string $mime, string $filename): array
    {
        return ['success' => true, 'data' => $text, 'content_base64' => base64_encode($text), 'mime_type' => $mime, 'filename' => $filename, 'size' => strlen($text)];
    }
}
