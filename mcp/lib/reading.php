<?php
/**
 * Read-only queries against the published reading index.
 *
 * This is a PHP port of the site's `src/lib/agent-reading.mjs` helpers.
 * It only returns text and figures that are already in the index. It does
 * not invent prices, context windows, or model cards.
 *
 * @package Meirinho\Mcp
 */

const MCP_TABLE_BENCHES = ['gpqa-diamond', 'swe-bench-verified', 'tau-bench-airline', 'simpleqa-verified'];

/**
 * Stop words ignored when tokenizing a search query (PT, EN, and ES).
 *
 * @return array<string, true>
 */
function mcp_stop_words(): array
{
    static $stop = null;
    if ($stop === null) {
        $stop = array_fill_keys([
            'a', 'an', 'the', 'of', 'on', 'in', 'and', 'or', 'to', 'for', 'by', 'with', 'at', 'from',
            'open', 'find', 'finding', 'found', 'latest', 'recent', 'abrir', 'abre', 'encontrar', 'encontra', 'encontre', 'encontrando', 'encuentra', 'encuentre', 'achar', 'ver',
            'compare', 'comparar', 'comparacion', 'comparison', 'filter', 'filtrar', 'filtro',
            'use', 'uso', 'por', 'de', 'do', 'da', 'dos', 'das', 'em', 'no', 'na', 'nos', 'nas',
            'um', 'uma', 'two', 'dois', 'models', 'modelos', 'modelo', 'model', 'notes',
            'notas', 'nota', 'card', 'ficha', 'page', 'pagina', 'dentro',
        ], true);
    }
    return $stop;
}

/**
 * Fold a string for search: lowercase and strip common Latin diacritics.
 *
 * @param mixed $value Raw value.
 * @return string
 */
function mcp_fold($value): string
{
    $text = (string) ($value ?? '');
    $text = strtr($text, [
        'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
        'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
        'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
        'Ç' => 'C', 'ç' => 'c', 'Ñ' => 'N', 'ñ' => 'n',
        'Ý' => 'Y', 'ý' => 'y', 'ÿ' => 'y',
    ]);
    return strtolower($text);
}

/**
 * Normalize a path or URL path for lookups.
 *
 * @param mixed $value Raw path or URL.
 * @return string
 */
function mcp_normalize_path($value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $path = trim((string) $value);
    if ($path === '') {
        return '';
    }
    $parts = parse_url($path);
    if (is_array($parts) && isset($parts['path']) && (isset($parts['scheme']) || str_starts_with($path, '/'))) {
        $path = $parts['path'];
    }
    if (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }
    if (!str_ends_with($path, '/')) {
        $path .= '/';
    }
    return $path;
}

/**
 * Normalize a language code to `pt`, `en`, or `es`.
 *
 * @param mixed $value Requested language.
 * @param string $fallback Fallback code, usually `pt`.
 * @return string
 */
function mcp_lang_of($value, string $fallback = 'pt'): string
{
    $raw = mcp_fold($value);
    if ($raw === 'en' || $raw === 'english' || $raw === 'ingles') {
        return 'en';
    }
    if ($raw === 'es' || $raw === 'spanish' || $raw === 'espanhol' || $raw === 'espanol') {
        return 'es';
    }
    if ($raw === 'pt' || $raw === 'pt-br' || $raw === 'portuguese' || $raw === 'portugues') {
        return 'pt';
    }
    return $fallback === 'en' || $fallback === 'es' ? $fallback : 'pt';
}

/**
 * Static UI strings used in tool answers, in PT, EN, and ES.
 *
 * @return array<string, array<string, string>>
 */
function mcp_copy(): array
{
    return [
        'missing' => [
            'pt' => 'não está na tabela',
            'en' => 'not in the table',
            'es' => 'no está en la tabla',
        ],
        'fallback' => [
            'es' => 'No hay texto publicado en español. El texto publicado está en portugués.',
            'en' => 'There is no published English text. The published text is in Portuguese.',
            'pt' => 'Não há texto publicado nesse idioma. O texto publicado está em português.',
        ],
        'ranking' => [
            'pt' => 'Linhas já publicadas, sem ranking novo.',
            'en' => 'Rows already published, with no new ranking.',
            'es' => 'Líneas ya publicadas, sin ranking nuevo.',
        ],
        'gpt6' => [
            'pt' => 'GPT-6 não é uma ficha só. Estas são as fichas publicadas.',
            'en' => 'GPT-6 is not a single card. These are the published cards.',
            'es' => 'GPT-6 no es una sola ficha. Estas son las fichas publicadas.',
        ],
        'notFound' => [
            'pt' => 'Não está publicado.',
            'en' => 'It is not published.',
            'es' => 'No está publicado.',
        ],
    ];
}

/**
 * Phrase used when a requested item is not published.
 *
 * @param string $lang `pt`, `en`, or `es`.
 * @return string
 */
function mcp_missing(string $lang): string
{
    $copy = mcp_copy();
    return $copy['missing'][$lang] ?? $copy['missing']['pt'];
}

/**
 * Swap decimal/thousands separators in a numeric string.
 *
 * @param string $value Number as text.
 * @return string
 */
function mcp_swap_separators(string $value): string
{
    $value = str_replace(',', "\0", $value);
    $value = str_replace('.', ',', $value);
    return str_replace("\0", '.', $value);
}

/**
 * Format a number with the thousands separator of a language.
 *
 * @param string $value Digits and optional fraction.
 * @param string $lang `pt`, `en`, or `es`.
 * @return string
 */
function mcp_group_number(string $value, string $lang): string
{
    $parts = explode('.', $value, 2);
    $grouped = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $parts[0]);
    $value = isset($parts[1]) && $parts[1] !== '' ? $grouped . '.' . $parts[1] : $grouped;
    return $lang === 'en' ? $value : mcp_swap_separators($value);
}

/**
 * Format a numeric value already present in the index.
 *
 * @param string $lang `pt`, `en`, or `es`.
 * @param mixed $value Number or numeric string.
 * @param int $digits Fraction digits.
 * @return string|null Null when the value is not a number in the index.
 */
function mcp_format_num(string $lang, $value, int $digits = 0): ?string
{
    if (!is_numeric($value)) {
        return null;
    }
    $n = (float) $value;
    if (!is_finite($n)) {
        return null;
    }
    $truncated = (float) ($n < 0 ? ceil($n) : floor($n));
    if ($digits > 0 && $n !== $truncated) {
        $s = rtrim(rtrim(number_format($n, $digits, '.', ''), '0'), '.');
    } else {
        $s = (string) (int) $truncated;
    }
    return mcp_group_number($s, $lang);
}

/**
 * Render a float with a fixed fraction length, without scientific notation.
 *
 * @param float $n Number.
 * @param int $precision Fraction digits.
 * @return string
 */
function mcp_precision_string(float $n, int $precision): string
{
    if ($n == 0.0) {
        return '0';
    }
    $negative = $n < 0;
    $n = abs($n);
    $exp = (int) floor(log10($n));
    $scale = $precision - 1 - $exp;
    $rounded = round($n, $scale);
    if ($scale > 0) {
        $s = number_format($rounded, $scale, '.', '');
    } else {
        $s = sprintf('%.0f', $rounded);
    }
    if ($negative) {
        $s = '-' . $s;
    }
    $asNumber = (float) $s;
    $out = rtrim(rtrim(sprintf('%.12F', abs($asNumber)), '0'), '.');
    if ($out === '' || $out === '-0') {
        $out = '0';
    }
    return $asNumber < 0 ? '-' . $out : $out;
}

/**
 * Format a price that is already in the index.
 *
 * @param string $lang `pt`, `en`, or `es`.
 * @param mixed $value Price value.
 * @return string|null
 */
function mcp_format_price(string $lang, $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    $n = (float) $value;
    if (!is_finite($n)) {
        return null;
    }
    if ($n == 0.0) {
        $s = '0';
    } elseif ($n >= 0.1) {
        $s = number_format($n, 2, '.', ',');
    } else {
        $s = rtrim(rtrim(mcp_precision_string($n, 2), '0'), '.');
    }
    if ($lang !== 'en') {
        $s = mcp_swap_separators($s);
    }
    return 'US$ ' . $s;
}

/**
 * Localized display name of a benchmark, from the index.
 *
 * @param array<string, mixed> $index Reading index.
 * @param string $id Benchmark id.
 * @param string $lang `pt`, `en`, or `es`.
 * @return string
 */
function mcp_bench_name(array $index, string $id, string $lang): string
{
    $name = $id;
    foreach ($index['benchmarks'] ?? [] as $row) {
        if (($row['id'] ?? '') === $id) {
            $name = $row['name'] ?? $id;
            break;
        }
    }
    if ($lang === 'pt' || !str_contains($name, '(')) {
        return $name;
    }
    $tiers = $lang === 'en' ? 'tiers 1 to 3' : 'niveles 1 a 3';
    $airline = $lang === 'en' ? 'airline' : 'aerolínea';
    return str_replace(['níveis 1 a 3', 'companhia aérea'], [$tiers, $airline], $name);
}

/**
 * Localized label for a published weighting scheme.
 *
 * @param array<string, mixed> $index Reading index.
 * @param string $lang `pt`, `en`, or `es`.
 * @param mixed $weights Weight id or structure.
 * @return string
 */
function mcp_weight_label(array $index, string $lang, $weights): string
{
    $table = $index['labels']['weights'][$lang] ?? [];
    if (!$weights) {
        return $table['nao_informado'] ?? mcp_missing($lang);
    }
    return $table[$weights] ?? $weights;
}

/**
 * Localized label for a published comparison column.
 *
 * @param array<string, mixed> $index Reading index.
 * @param string $lang `pt`, `en`, or `es`.
 * @param string $key Column key.
 * @return string
 */
function mcp_column_label(array $index, string $lang, string $key): string
{
    return $index['labels']['columns'][$lang][$key] ?? $key;
}

/**
 * Published comparison cells for one model, in the requested language.
 *
 * @param array<string, mixed> $index Reading index.
 * @param array<string, mixed> $model Model record.
 * @param string $lang `pt`, `en`, or `es`.
 * @return array<string, mixed>
 */
function mcp_comparison_cells(array $index, array $model, string $lang): array
{
    $blank = mcp_missing($lang);
    $priceIn = mcp_format_price($lang, $model['priceIn'] ?? null);
    $priceOut = mcp_format_price($lang, $model['priceOut'] ?? null);
    $context = !empty($model['context'])
        ? mcp_format_num($lang, $model['context']) . ' ' . ($index['labels']['tokens'][$lang] ?? 'tokens')
        : null;
    $usage = ($model['usage'] ?? null) === null ? null : mcp_format_num($lang, $model['usage'], 2) . '%';
    $downloads = ($model['downloads'] ?? null) === null ? null : mcp_format_num($lang, $model['downloads']);
    $cells = [
        mcp_column_label($index, $lang, 'model') => $model['name'] ?? '',
        mcp_column_label($index, $lang, 'lab') => ($model['lab'] ?? '') !== '' ? $model['lab'] : $blank,
        mcp_column_label($index, $lang, 'weights') => mcp_weight_label($index, $lang, $model['weights'] ?? null),
        mcp_column_label($index, $lang, 'price_in') => $priceIn ?? $blank,
        mcp_column_label($index, $lang, 'price_out') => $priceOut ?? $blank,
        mcp_column_label($index, $lang, 'context') => $context ?? $blank,
    ];
    foreach (MCP_TABLE_BENCHES as $id) {
        $score = $model['scores'][$id] ?? null;
        $label = mcp_bench_name($index, $id, $lang);
        $cells[$label] = $score ? mcp_format_num($lang, $score['value'] ?? null, 1) . '%' : $blank;
    }
    $cells[mcp_column_label($index, $lang, 'usage')] = $usage ?? $blank;
    $cells[mcp_column_label($index, $lang, 'downloads')] = $downloads ?? $blank;
    return $cells;
}

/**
 * Plain-text card for a model, built only from published fields.
 *
 * @param array<string, mixed> $index Reading index.
 * @param array<string, mixed> $model Model record.
 * @param string $lang `pt`, `en`, or `es`.
 * @return string
 */
function mcp_card_text(array $index, array $model, string $lang): string
{
    $cells = mcp_comparison_cells($index, $model, $lang);
    $lines = [];
    foreach ($cells as $key => $value) {
        $lines[] = $key . ': ' . $value;
    }
    $sources = array_values(array_filter($model['sources'] ?? [], static function ($source) {
        return is_array($source) && !empty($source['url']);
    }));
    if ($sources) {
        $lines[] = $index['labels']['columns'][$lang]['sources'] ?? 'Fontes';
        foreach ($sources as $source) {
            $lines[] = '- ' . (($source['title'] ?? '') !== '' ? $source['title'] : $source['url']) . ': ' . $source['url'];
        }
    }
    if (!empty($model['priceFlags'])) {
        $lines[] = 'price_flags: ' . implode(', ', $model['priceFlags']);
    }
    return implode("\n", $lines);
}

/**
 * Short summary fields for an index item.
 *
 * @param array<string, mixed> $item Index item.
 * @return array<string, mixed>
 */
function mcp_summary(array $item): array
{
    return [
        'collection' => $item['collection'] ?? '',
        'kind' => $item['kind'] ?? '',
        'lang' => $item['lang'] ?? '',
        'slug' => $item['slug'] ?? '',
        'title' => $item['title'] ?? '',
        'url' => $item['url'] ?? '',
        'date' => ($item['date'] ?? '') !== '' ? $item['date'] : null,
    ];
}

/**
 * Find a model record in the index by id or slug.
 *
 * @param array<string, mixed> $index Reading index.
 * @param string $id Model id or slug.
 * @return array<string, mixed>|null
 */
function mcp_find_model(array $index, string $id): ?array
{
    foreach ($index['models'] ?? [] as $model) {
        if (($model['id'] ?? '') === $id) {
            return $model;
        }
    }
    return null;
}

/**
 * Published body text of an item, if present in the index.
 *
 * @param array<string, mixed> $index Reading index.
 * @param array<string, mixed> $item Index item.
 * @return string
 */
function mcp_item_body(array $index, array $item): string
{
    if (!empty($item['modelId'])) {
        $model = mcp_find_model($index, $item['modelId']);
        if ($model) {
            return mcp_card_text($index, $model, $item['lang'] ?? 'pt');
        }
    }
    return $item['body'] ?? '';
}

/**
 * Return an item in the requested language, or Portuguese with a notice.
 *
 * @param array<string, mixed> $index Reading index.
 * @param array<string, mixed>|null $item Item in any language.
 * @param string|null $requested Requested language.
 * @return array<string, mixed>|null Packed item plus language metadata.
 */
function mcp_with_fallback(array $index, ?array $item, ?string $requested): ?array
{
    if (!$item) {
        return null;
    }
    if (!$requested || ($item['lang'] ?? '') === $requested) {
        return ['item' => $item, 'lang' => $item['lang'] ?? 'pt', 'fallback' => null];
    }
    foreach ($index['items'] ?? [] as $entry) {
        if (($entry['group'] ?? null) === ($item['group'] ?? null) && ($entry['lang'] ?? '') === $requested) {
            return ['item' => $entry, 'lang' => $requested, 'fallback' => null];
        }
    }
    $pt = $item;
    foreach ($index['items'] ?? [] as $entry) {
        if (($entry['group'] ?? null) === ($item['group'] ?? null) && ($entry['lang'] ?? '') === 'pt') {
            $pt = $entry;
            break;
        }
    }
    $copy = mcp_copy();
    return [
        'item' => $pt,
        'lang' => 'pt',
        'fallback' => $copy['fallback'][$requested] ?? $copy['fallback']['pt'],
    ];
}

/**
 * Shape an item for a tool response, including fallback notices.
 *
 * @param array<string, mixed> $index Reading index.
 * @param array<string, mixed>|null $packed Result of mcp_with_fallback().
 * @return array<string, mixed>
 */
function mcp_present_item(array $index, ?array $packed): array
{
    $copy = mcp_copy();
    if (!$packed) {
        return ['found' => false, 'lang' => 'pt', 'note' => $copy['notFound']['pt']];
    }
    $item = $packed['item'];
    $lang = $packed['lang'];
    $fallback = $packed['fallback'];
    $bodyItem = $item;
    $bodyItem['lang'] = $lang;
    $result = [
        'found' => true,
        'lang' => $lang,
        'fallback' => $fallback,
        'item' => array_merge(mcp_summary($item), [
            'lang' => $lang,
            'description' => $item['description'] ?? '',
            'body' => mcp_item_body($index, $bodyItem),
            'sources' => array_values($item['sources'] ?? []),
        ]),
    ];
    if ($fallback) {
        $result['note'] = $fallback;
        $ordered = [
            'found' => true,
            'lang' => $lang,
            'fallback' => $fallback,
            'note' => $fallback,
            'item' => $result['item'],
        ];
        return $ordered;
    }
    return $result;
}

/**
 * Tokenize a search query, dropping short tokens and stop words.
 *
 * @param string $query Raw query.
 * @return list<string>
 */
function mcp_tokens(string $query): array
{
    $stop = mcp_stop_words();
    $parts = preg_split('/[^a-z0-9.]+/', mcp_fold($query), -1, PREG_SPLIT_NO_EMPTY);
    $tokens = [];
    foreach ($parts as $token) {
        if (strlen($token) >= 2 && !isset($stop[$token])) {
            $tokens[] = $token;
        }
    }
    return $tokens;
}

/**
 * Score how well an item matches a tokenized query.
 *
 * @param array<string, mixed> $item Index item.
 * @param string $query Raw query.
 * @param list<string> $tokens Tokenized query.
 * @return int
 */
function mcp_score_item(array $item, string $query, array $tokens): int
{
    $title = mcp_fold($item['title'] ?? '');
    $q = trim(mcp_fold($query));
    $hay = mcp_fold(($item['title'] ?? '') . "\n" . ($item['description'] ?? '') . "\n" . ($item['slug'] ?? '') . "\n" . ($item['hay'] ?? ''));
    if ($q === '') {
        return 0;
    }
    if ($title === $q || str_replace('-', ' ', mcp_fold($item['slug'] ?? '')) === $q) {
        return 100;
    }
    if (str_starts_with($title, $q)) {
        return 88;
    }
    if (strlen($q) >= 4 && str_contains($title, $q)) {
        return 76;
    }
    if (strlen($q) >= 4 && str_contains($hay, $q)) {
        return 64;
    }
    if (!$tokens) {
        return 0;
    }
    $hits = 0;
    foreach ($tokens as $token) {
        if (str_contains($hay, $token)) {
            $hits++;
        }
    }
    if ($hits === count($tokens)) {
        return 58;
    }
    if ($hits >= (int) ceil(count($tokens) * 0.7) && $hits >= 2) {
        return 40;
    }
    return 0;
}

/**
 * Whether a query is asking for the latest Mercado notes.
 *
 * @param string $query Raw query.
 * @return bool
 */
function mcp_is_latest_market(string $query): bool
{
    $q = mcp_fold($query);
    return preg_match('/(ultima|latest|recent|notas recentes)/', $q) === 1
        && preg_match('/(mercado|market|nota)/', $q) === 1;
}

/**
 * Detect a collection name implied by the query text, if any.
 *
 * @param string $query Raw query.
 * @return string|null `mercado`, `artigos`, `modelos`, or null.
 */
function mcp_forced_collection(string $query): ?string
{
    $q = mcp_fold($query);
    if (str_contains($q, 'por dentro')) {
        return 'artigos';
    }
    if (mcp_is_latest_market($query)) {
        return 'mercado';
    }
    if (preg_match('/\bvs\b/', $q) === 1 || str_contains($q, 'compar')) {
        return 'modelos';
    }
    if (preg_match('/(agents|agentes|transcription|transcricao|transcripcion)/', $q) === 1
        && preg_match('/(model|modelo|ficha|card|uso|use|filter|filtr)/', $q) === 1) {
        return 'modelos';
    }
    return null;
}

/**
 * Normalize a model-name query for lookups.
 *
 * @param mixed $raw Raw query.
 * @return string
 */
function mcp_normalize_model_query($raw): string
{
    $text = mcp_fold($raw);
    $text = preg_replace('/sonet(?!t)/', 'sonnet', $text);
    $text = preg_replace('/\b(?:november|novembro|noviembre)\b/', 'nov', $text);
    $text = preg_replace('/\b(?:de|do|da|dos|das|of|the)\b/', ' ', $text);
    $text = preg_replace('/[^a-z0-9.]+/', ' ', $text);
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text);
}

/**
 * Whether the query is the bare published GPT-6 label, not a single card.
 *
 * @param string $q Normalized query.
 * @return bool
 */
function mcp_is_bare_gpt6(string $q): bool
{
    return $q === 'gpt 6' || $q === 'gpt6';
}

/**
 * Resolve a query to published model records.
 *
 * @param array<string, mixed> $index Reading index.
 * @param mixed $raw Raw query or id.
 * @return array<string, mixed>
 */
function mcp_resolve_models(array $index, $raw): array
{
    $q = mcp_normalize_model_query($raw);
    if ($q === '') {
        return [];
    }
    if (mcp_is_bare_gpt6($q)) {
        $found = [];
        foreach ($index['models'] ?? [] as $model) {
            $id = $model['id'] ?? '';
            $name = mcp_fold($model['name'] ?? '');
            if (str_starts_with($id, 'gpt-6') || str_starts_with($name, 'gpt 6')) {
                $found[] = $model;
            }
        }
        return $found;
    }
    $scored = [];
    foreach ($index['models'] ?? [] as $model) {
        $id = str_replace('-', ' ', $model['id'] ?? '');
        $name = mcp_fold($model['name'] ?? '');
        $aliases = [];
        foreach ($model['aliases'] ?? [] as $alias) {
            $aliases[] = mcp_fold($alias);
        }
        $blobs = array_merge([$id, $name], $aliases);
        $score = 0;
        foreach ($blobs as $blob) {
            if ($blob === $q) {
                $score = 100;
                break;
            }
        }
        if ($score === 0) {
            foreach ($blobs as $blob) {
                if (str_starts_with($blob, $q . ' ') || str_starts_with($blob, $q)) {
                    $score = 90;
                    break;
                }
            }
        }
        if ($score === 0) {
            $parts = array_values(array_filter(explode(' ', $q), static function ($part) {
                return $part !== '';
            }));
            $hay = implode(' ', $blobs);
            $all = $parts !== [];
            foreach ($parts as $part) {
                if (!str_contains($hay, $part)) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                $score = 80;
            }
        }
        if ($score) {
            $scored[] = ['model' => $model, 'score' => $score];
        }
    }
    if (!$scored) {
        return [];
    }
    $top = 0;
    foreach ($scored as $entry) {
        $top = max($top, $entry['score']);
    }
    $models = [];
    foreach ($scored as $entry) {
        if ($entry['score'] === $top) {
            $models[] = $entry['model'];
        }
    }
    return $models;
}

/**
 * Normalize a published "use" filter id.
 *
 * @param mixed $raw Raw value.
 * @return string
 */
function mcp_use_id($raw): string
{
    $q = str_replace('ç', 'c', preg_replace('/transcripcion|transcription/', 'transcricao', mcp_fold($raw)));
    if ($q === '') {
        return '';
    }
    if (str_contains($q, 'transcri')) {
        return 'transcricao';
    }
    if ($q === 'agents' || str_contains($q, 'agente')) {
        return 'agentes';
    }
    return preg_replace('/[^a-z0-9-]+/', '', $q);
}

/**
 * Published path of a two-model comparison, if it exists in the index.
 *
 * @param array<string, mixed> $index Reading index.
 * @param string $a First model id.
 * @param string $b Second model id.
 * @return string|null
 */
function mcp_pair_path(array $index, string $a, string $b): ?string
{
    foreach ($index['pairs'] ?? [] as $pair) {
        if (!is_array($pair) || count($pair) < 2) {
            continue;
        }
        if (($pair[0] === $a && $pair[1] === $b) || ($pair[0] === $b && $pair[1] === $a)) {
            return '/ia/modelos/comparar/' . $pair[0] . '-vs-' . $pair[1] . '/';
        }
    }
    return null;
}

/**
 * One published comparison row for a model.
 *
 * @param array<string, mixed> $index Reading index.
 * @param array<string, mixed> $model Model record.
 * @param string $lang `pt`, `en`, or `es`.
 * @return array<string, mixed>
 */
function mcp_row_for(array $index, array $model, string $lang): array
{
    $prefix = $lang === 'en' ? '/en' : ($lang === 'es' ? '/es' : '');
    $scores = [];
    foreach ($model['scores'] ?? [] as $id => $score) {
        if (!is_array($score)) {
            continue;
        }
        $scores[mcp_bench_name($index, (string) $id, $lang)] = [
            'value' => mcp_format_num($lang, $score['value'] ?? null, 1) . '%',
            'source' => ($score['sourceName'] ?? '') !== '' ? $score['sourceName'] : ($score['source'] ?? ''),
            'sourceUrl' => $score['sourceUrl'] ?? '',
        ];
    }
    return [
        'id' => $model['id'] ?? '',
        'name' => $model['name'] ?? '',
        'url' => $prefix . '/ia/modelos/' . ($model['id'] ?? '') . '/',
        'lab' => ($model['lab'] ?? '') !== '' ? $model['lab'] : mcp_missing($lang),
        'usecases' => array_values($model['usecases'] ?? []),
        'cells' => mcp_comparison_cells($index, $model, $lang),
        'publishedScores' => $scores,
        'sources' => array_values($model['sources'] ?? []),
        'card' => mcp_card_text($index, $model, $lang),
    ];
}

/**
 * Split a "versus" query into two model names.
 *
 * @param string $raw Raw query.
 * @return list<string>
 */
function mcp_split_versus(string $raw): array
{
    $parts = preg_split('/\s+vs\.?\s+/i', $raw);
    if (is_array($parts) && count($parts) === 2 && trim($parts[0]) !== '' && trim($parts[1]) !== '') {
        return ['model' => trim($parts[0]), 'versus' => trim($parts[1])];
    }
    return ['model' => $raw, 'versus' => ''];
}

/**
 * Phrase listing model names that are not in the published table.
 *
 * @param string $lang `pt`, `en`, or `es`.
 * @param list<string> $names Missing names.
 * @return string
 */
function mcp_missing_phrase(string $lang, array $names): string
{
    $list = implode(', ', $names);
    if ($lang === 'en') {
        return $list . ' is not in the table.';
    }
    if ($lang === 'es') {
        return $list . ' no está en el cuadro.';
    }
    return $list . ' não está no quadro.';
}

/**
 * Split a comparison query into model names or slugs.
 *
 * @param mixed $raw Raw query.
 * @return array<string, mixed>
 */
function mcp_split_comparison_query($raw): array
{
    $text = trim((string) ($raw ?? ''));
    $text = preg_replace('/[?.!]+$/u', '', $text);
    if ($text === '') {
        return [];
    }
    $colon = explode(':', $text, 2);
    $text = trim($colon[0]);
    $text = preg_replace('/^(?:compare|compara(?:r|ção|cion)?|comparacion|comparación)\s+/iu', '', $text);
    $parts = preg_split('/\s*,\s*|\s+e\s+|\s+y\s+|\s+and\s+/iu', $text);
    $names = [];
    foreach ($parts ?: [] as $part) {
        $part = preg_replace('/^(?:os|as|los|las|el|la|the|o|a)\s+/iu', '', trim($part));
        $part = trim($part);
        if ($part !== '') {
            $names[] = $part;
        }
    }
    return count($names) >= 2 ? $names : [];
}

/**
 * Build comparison requests from tool arguments.
 *
 * @param array<string, mixed> $index Reading index.
 * @param array<string, mixed> $args Tool arguments.
 * @return array<string, mixed>
 */
function mcp_comparison_requests(array $index, array $args): array
{
    $names = [];
    $push = static function ($value) use (&$names): void {
        $text = trim((string) ($value ?? ''));
        if ($text !== '') {
            $names[] = $text;
        }
    };
    if (!empty($args['slugs']) && is_array($args['slugs'])) {
        foreach ($args['slugs'] as $slug) {
            $push($slug);
        }
    } elseif (!empty($args['models']) && is_array($args['models'])) {
        foreach ($args['models'] as $model) {
            $push($model);
        }
    } elseif (!empty($args['model'])) {
        $split = mcp_split_versus((string) $args['model']);
        $push($split['model']);
        $push($args['versus'] ?? $split['versus']);
    } elseif (!empty($args['versus'])) {
        $push($args['versus']);
    } elseif (!empty($args['query'])) {
        foreach (mcp_split_comparison_query($args['query']) as $name) {
            $push($name);
        }
    }
    if (count($names) === 1 && !mcp_resolve_models($index, $names[0])) {
        $pieces = mcp_split_comparison_query($names[0]);
        if (count($pieces) >= 2) {
            return $pieces;
        }
    }
    return $names;
}

/**
 * Return a published comparison row or model card, without a new ranking.
 *
 * @param array<string, mixed> $index Reading index.
 * @param array<string, mixed> $args Tool arguments.
 * @param string $pageLang Default language from the page or request.
 * @return array<string, mixed>
 */
function mcp_get_comparison_row(array $index, array $args = [], string $pageLang = 'pt'): array
{
    $copy = mcp_copy();
    $lang = mcp_lang_of($args['lang'] ?? ($args['language'] ?? null), $pageLang);
    $use = mcp_use_id($args['use'] ?? ($args['uso'] ?? ''));
    $note = [$copy['ranking'][$lang]];
    $requested = mcp_comparison_requests($index, $args);
    $models = [];
    $missing = [];
    if ($requested) {
        foreach ($requested as $name) {
            if (mcp_is_bare_gpt6(mcp_normalize_model_query($name))) {
                $note[] = $copy['gpt6'][$lang];
                break;
            }
        }
        foreach ($requested as $name) {
            $found = mcp_resolve_models($index, $name);
            if (!$found) {
                $missing[] = trim((string) $name);
            } else {
                foreach ($found as $model) {
                    $models[] = $model;
                }
            }
        }
    } elseif ($use !== '') {
        foreach ($index['models'] ?? [] as $model) {
            if (in_array($use, $model['usecases'] ?? [], true)) {
                $models[] = $model;
            }
        }
        $note[] = $lang === 'en' ? 'Published use filter.' : ($lang === 'es' ? 'Filtro de uso publicado.' : 'Filtro de uso publicado.');
    }
    $seen = [];
    $unique = [];
    foreach ($models as $model) {
        $id = $model['id'] ?? '';
        if (isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $unique[] = $model;
    }
    $models = $unique;
    if (!$models) {
        return [
            'found' => false,
            'lang' => $lang,
            'tool' => 'get_comparison_row',
            'note' => $copy['notFound'][$lang],
            'rows' => [],
        ];
    }
    if ($missing) {
        $note[] = mcp_missing_phrase($lang, $missing);
    }
    $limit = !empty($args['limit']) ? (int) $args['limit'] : ($use !== '' && !$requested ? 12 : 24);
    $rows = [];
    foreach (array_slice($models, 0, $limit) as $model) {
        $rows[] = mcp_row_for($index, $model, $lang);
    }
    $pair = count($models) === 2 ? mcp_pair_path($index, $models[0]['id'], $models[1]['id']) : null;
    $prefix = $lang === 'en' ? '/en' : ($lang === 'es' ? '/es' : '');
    $explicitMany = count($requested) >= 2;
    $bare = false;
    foreach ($requested as $name) {
        if (mcp_is_bare_gpt6(mcp_normalize_model_query($name))) {
            $bare = true;
            break;
        }
    }
    $result = [
        'found' => true,
        'lang' => $lang,
        'tool' => 'get_comparison_row',
        'ambiguous' => $bare || (count($models) > 1 && !$explicitMany && $use === ''),
        'note' => implode(' ', $note),
        'pairPage' => $pair ? $prefix . $pair : null,
        'total' => count($models),
        'rows' => $rows,
    ];
    if ($use !== '') {
        $result = [
            'found' => true,
            'lang' => $lang,
            'tool' => 'get_comparison_row',
            'ambiguous' => $bare || (count($models) > 1 && !$explicitMany && $use === ''),
            'note' => implode(' ', $note),
            'use' => $use,
            'usePage' => $prefix . '/ia/modelos/uso/' . $use . '/',
            'pairPage' => $pair ? $prefix . $pair : null,
            'total' => count($models),
            'rows' => $rows,
        ];
    }
    return $result;
}

/**
 * Search published items in the index.
 *
 * @param array<string, mixed> $index Reading index.
 * @param array<string, mixed> $args Tool arguments.
 * @param string $pageLang Default language from the page or request.
 * @return array<string, mixed>
 */
function mcp_search(array $index, array $args, string $pageLang): array
{
    $copy = mcp_copy();
    $lang = mcp_lang_of($args['lang'] ?? null, $pageLang);
    $query = trim((string) ($args['query'] ?? ($args['q'] ?? '')));
    if ($query === '') {
        return ['found' => false, 'lang' => $lang, 'tool' => 'search', 'note' => $copy['notFound'][$lang], 'items' => []];
    }
    $explicit = $args['collection'] ?? null;
    $forced = $explicit ?: mcp_forced_collection($query);
    if (mcp_is_latest_market($query) && (!$forced || $forced === 'mercado')) {
        $items = [];
        foreach ($index['items'] ?? [] as $item) {
            if (($item['collection'] ?? '') === 'mercado' && ($item['kind'] ?? '') === 'nota' && ($item['lang'] ?? '') === 'pt') {
                $items[] = $item;
            }
        }
        usort($items, static function ($a, $b) {
            $date = strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''));
            return $date !== 0 ? $date : strcmp((string) ($a['slug'] ?? ''), (string) ($b['slug'] ?? ''));
        });
        $limit = !empty($args['limit']) ? (int) $args['limit'] : 8;
        $packed = [];
        foreach (array_slice($items, 0, $limit) as $item) {
            $packed[] = mcp_with_fallback($index, $item, $lang);
        }
        $out = [];
        foreach ($packed as $entry) {
            $out[] = array_merge(mcp_summary($entry['item']), ['fallback' => $entry['fallback']]);
        }
        return [
            'found' => $packed !== [],
            'lang' => $packed[0]['lang'] ?? $lang,
            'tool' => 'search',
            'collection' => 'mercado',
            'crossed' => false,
            'items' => $out,
        ];
    }
    $tokens = mcp_tokens($query);
    $ranked = [];
    foreach (['mercado', 'artigos', 'modelos'] as $collection) {
        if ($forced && $collection !== $forced) {
            continue;
        }
        $best = 0;
        $hits = [];
        foreach ($index['items'] ?? [] as $item) {
            if (($item['collection'] ?? '') !== $collection || ($item['lang'] ?? '') !== 'pt') {
                continue;
            }
            $score = mcp_score_item($item, $query, $tokens);
            if ($score < 40) {
                continue;
            }
            $best = max($best, $score);
            $hits[] = ['item' => $item, 'score' => $score];
        }
        if ($collection === 'modelos') {
            foreach (mcp_resolve_models($index, $query) as $model) {
                $item = null;
                foreach ($index['items'] ?? [] as $entry) {
                    if (($entry['modelId'] ?? '') === ($model['id'] ?? '') && ($entry['lang'] ?? '') === 'pt' && ($entry['kind'] ?? '') === 'ficha') {
                        $item = $entry;
                        break;
                    }
                }
                if (!$item) {
                    continue;
                }
                $score = mcp_is_bare_gpt6(mcp_normalize_model_query($query)) ? 100 : 96;
                $best = max($best, $score);
                $already = false;
                foreach ($hits as $hit) {
                    if (($hit['item']['modelId'] ?? null) === ($model['id'] ?? null)) {
                        $already = true;
                        break;
                    }
                }
                if (!$already) {
                    $hits[] = ['item' => $item, 'score' => $score];
                }
            }
        }
        if ($hits) {
            $ranked[] = ['collection' => $collection, 'best' => $best, 'hits' => $hits];
        }
    }
    if (!$ranked) {
        return ['found' => false, 'lang' => $lang, 'tool' => 'search', 'note' => $copy['notFound'][$lang], 'items' => [], 'crossed' => false];
    }
    usort($ranked, static function ($a, $b) {
        return $b['best'] <=> $a['best'];
    });
    $floor = max(80, $ranked[0]['best'] - 15);
    $keep = $forced ? $ranked : array_values(array_filter($ranked, static function ($group) use ($ranked, $floor) {
        return $group['best'] === $ranked[0]['best'] || $group['best'] >= $floor;
    }));
    $crossed = !$forced && count($keep) > 1;
    $limit = !empty($args['limit']) ? (int) $args['limit'] : 8;
    $groups = [];
    foreach ($keep as $group) {
        $hits = $group['hits'];
        usort($hits, static function ($a, $b) {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }
            return strcmp((string) ($b['item']['date'] ?? ''), (string) ($a['item']['date'] ?? ''));
        });
        $items = [];
        foreach (array_slice($hits, 0, $limit) as $hit) {
            $packed = mcp_with_fallback($index, $hit['item'], $lang);
            $items[] = array_merge(mcp_summary($packed['item']), [
                'fallback' => $packed['fallback'],
                'score' => $hit['score'],
            ]);
        }
        $groups[] = ['collection' => $group['collection'], 'items' => $items];
    }
    $flat = [];
    foreach ($groups as $group) {
        foreach ($group['items'] as $item) {
            $flat[] = $item;
        }
    }
    $note = null;
    if ($crossed) {
        $note = $lang === 'en'
            ? 'The query matches more than one published area.'
            : ($lang === 'es' ? 'La consulta coincide con más de un área publicada.' : 'A consulta encontra mais de uma área publicada.');
    } elseif (mcp_is_bare_gpt6(mcp_normalize_model_query($query))) {
        $note = $copy['gpt6'][$lang];
    }
    $result = [
        'found' => true,
        'lang' => $lang,
        'tool' => 'search',
        'collection' => $crossed ? null : $groups[0]['collection'],
        'crossed' => $crossed,
        'groups' => $groups,
        'items' => $flat,
    ];
    if ($note !== null) {
        $result = [
            'found' => true,
            'lang' => $lang,
            'tool' => 'search',
            'collection' => $crossed ? null : $groups[0]['collection'],
            'crossed' => $crossed,
            'note' => $note,
            'groups' => $groups,
            'items' => $flat,
        ];
    }
    return $result;
}

/**
 * List published items in a collection.
 *
 * @param array<string, mixed> $index Reading index.
 * @param array<string, mixed> $args Tool arguments.
 * @param string $pageLang Default language from the page or request.
 * @param string|null $section Optional collection override.
 * @return array<string, mixed>
 */
function mcp_list(array $index, array $args, string $pageLang, ?string $section): array
{
    $copy = mcp_copy();
    $lang = mcp_lang_of($args['lang'] ?? null, $pageLang);
    $collection = $args['collection'] ?? $section;
    if (!$collection) {
        return ['found' => false, 'lang' => $lang, 'tool' => 'list', 'note' => $copy['notFound'][$lang], 'items' => []];
    }
    $use = mcp_use_id($args['use'] ?? '');
    $items = [];
    foreach ($index['items'] ?? [] as $item) {
        if (($item['collection'] ?? '') === $collection && ($item['lang'] ?? '') === 'pt') {
            $items[] = $item;
        }
    }
    if ($collection === 'mercado') {
        $items = array_values(array_filter($items, static function ($item) {
            return ($item['kind'] ?? '') === 'nota';
        }));
    }
    if ($collection === 'artigos') {
        $items = array_values(array_filter($items, static function ($item) {
            return ($item['kind'] ?? '') === 'artigo';
        }));
    }
    if ($collection === 'modelos') {
        $items = array_values(array_filter($items, static function ($item) {
            return in_array($item['kind'] ?? '', ['ficha', 'uso', 'pagina'], true);
        }));
        if ($use !== '') {
            $items = array_values(array_filter($items, static function ($item) use ($use) {
                return (($item['kind'] ?? '') === 'uso' && ($item['use'] ?? '') === $use)
                    || (($item['kind'] ?? '') === 'ficha' && in_array($use, $item['usecases'] ?? [], true));
            }));
        }
    }
    usort($items, static function ($a, $b) {
        $date = strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? ''));
        if ($date !== 0) {
            return $date;
        }
        return strcmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
    });
    $total = count($items);
    $slice = [];
    $listLimit = !empty($args['limit']) ? (int) $args['limit'] : 20;
    foreach (array_slice($items, 0, $listLimit) as $item) {
        $entry = mcp_with_fallback($index, $item, $lang);
        $slice[] = array_merge(mcp_summary($entry['item']), ['fallback' => $entry['fallback']]);
    }
    $result = [
        'found' => $total > 0,
        'lang' => $lang,
        'tool' => 'list',
        'collection' => $collection,
        'total' => $total,
        'items' => $slice,
    ];
    if ($use !== '') {
        $result = [
            'found' => $total > 0,
            'lang' => $lang,
            'tool' => 'list',
            'collection' => $collection,
            'use' => $use,
            'total' => $total,
            'items' => $slice,
        ];
    }
    return $result;
}

/**
 * Open a published item by URL, slug, or path.
 *
 * @param array<string, mixed> $index Reading index.
 * @param array<string, mixed> $args Tool arguments.
 * @param string $pageLang Default language from the page or request.
 * @param string|null $section Optional collection hint.
 * @return array<string, mixed>
 */
function mcp_get(array $index, array $args, string $pageLang, ?string $section): array
{
    $copy = mcp_copy();
    $requested = mcp_lang_of($args['lang'] ?? null, $pageLang);
    $url = mcp_normalize_path($args['url'] ?? null);
    if ($url !== '') {
        $exact = null;
        foreach ($index['items'] ?? [] as $item) {
            if (($item['url'] ?? '') === $url) {
                $exact = $item;
                break;
            }
        }
        if (!$exact) {
            return ['found' => false, 'lang' => $requested, 'tool' => 'get', 'note' => $copy['notFound'][$requested]];
        }
        if (!array_key_exists('lang', $args) || $args['lang'] === null || $args['lang'] === '') {
            $presented = mcp_present_item($index, ['item' => $exact, 'lang' => $exact['lang'], 'fallback' => null]);
            $presented['tool'] = 'get';
            return $presented;
        }
        $sibling = null;
        foreach ($index['items'] ?? [] as $item) {
            if (($item['group'] ?? null) === ($exact['group'] ?? null) && ($item['lang'] ?? '') === $requested) {
                $sibling = $item;
                break;
            }
        }
        if ($sibling) {
            $presented = mcp_present_item($index, ['item' => $sibling, 'lang' => $requested, 'fallback' => null]);
            $presented['tool'] = 'get';
            return $presented;
        }
        $presented = mcp_present_item($index, mcp_with_fallback($index, $exact, $requested));
        $presented['tool'] = 'get';
        return $presented;
    }
    $slug = trim(mcp_fold($args['slug'] ?? ''), '/');
    if ($slug === '') {
        return ['found' => false, 'lang' => $requested, 'tool' => 'get', 'note' => $copy['notFound'][$requested]];
    }
    $collection = $args['collection'] ?? $section;
    $hits = [];
    foreach ($index['items'] ?? [] as $item) {
        if (($item['lang'] ?? '') !== 'pt') {
            continue;
        }
        $itemSlug = mcp_fold($item['slug'] ?? '');
        if ($itemSlug === $slug || str_ends_with((string) ($item['url'] ?? ''), '/' . $slug . '/')) {
            $hits[] = $item;
        }
    }
    if ($collection) {
        $scoped = array_values(array_filter($hits, static function ($item) use ($collection) {
            return ($item['collection'] ?? '') === $collection;
        }));
        if ($scoped) {
            $hits = $scoped;
        }
    }
    if (!$hits) {
        $models = mcp_resolve_models($index, $slug);
        $hits = [];
        foreach ($models as $model) {
            foreach ($index['items'] ?? [] as $item) {
                if (($item['modelId'] ?? '') === ($model['id'] ?? '') && ($item['lang'] ?? '') === 'pt' && ($item['kind'] ?? '') === 'ficha') {
                    $hits[] = $item;
                    break;
                }
            }
        }
        if (mcp_is_bare_gpt6(mcp_normalize_model_query($slug)) && count($hits) > 1) {
            $items = [];
            foreach ($hits as $item) {
                $items[] = mcp_summary(mcp_with_fallback($index, $item, $requested)['item']);
            }
            return [
                'found' => true,
                'lang' => $requested,
                'tool' => 'get',
                'ambiguous' => true,
                'note' => $copy['gpt6'][$requested],
                'items' => $items,
            ];
        }
    }
    if (!$hits) {
        return ['found' => false, 'lang' => $requested, 'tool' => 'get', 'note' => $copy['notFound'][$requested]];
    }
    if (count($hits) > 1) {
        $items = [];
        foreach ($hits as $item) {
            $items[] = mcp_summary(mcp_with_fallback($index, $item, $requested)['item']);
        }
        $note = $requested === 'en'
            ? 'More than one published item matches.'
            : ($requested === 'es' ? 'Hay más de un elemento publicado.' : 'Há mais de um item publicado.');
        return [
            'found' => true,
            'lang' => $requested,
            'tool' => 'get',
            'ambiguous' => true,
            'note' => $note,
            'items' => $items,
        ];
    }
    $presented = mcp_present_item($index, mcp_with_fallback($index, $hits[0], $requested));
    $presented['tool'] = 'get';
    return $presented;
}

/**
 * Normalize tool arguments (language aliases, types).
 *
 * @param array<string, mixed> $args Raw arguments.
 * @return array<string, mixed>
 */
function mcp_normalize_args(array $args): array
{
    if (empty($args['lang']) && !empty($args['language'])) {
        $args['lang'] = $args['language'];
    }
    if (empty($args['use']) && !empty($args['uso'])) {
        $args['use'] = $args['uso'];
    }
    if (empty($args['url']) && !empty($args['path'])) {
        $args['url'] = $args['path'];
    }
    if (empty($args['query']) && !empty($args['q'])) {
        $args['query'] = $args['q'];
    }
    return $args;
}

/**
 * Dispatch a published tool by name.
 *
 * @param array<string, mixed> $index Reading index.
 * @param string $name Tool name.
 * @param array<string, mixed> $args Tool arguments.
 * @param array<string, mixed> $page Optional page context (language, section).
 * @return array<string, mixed>
 */
function mcp_run_tool(array $index, string $name, array $args = [], array $page = []): array
{
    $args = mcp_normalize_args($args);
    $pageLang = $page['lang'] ?? 'pt';
    $section = $page['section'] ?? ($args['section'] ?? null);
    if ($name === 'search') {
        return mcp_search($index, $args, $pageLang);
    }
    if ($name === 'list' || $name === 'list_items') {
        $listArgs = $args;
        if ($name === 'list_items') {
            $listArgs['collection'] = $section;
        }
        $result = mcp_list($index, $listArgs, $pageLang, $section);
        $result['tool'] = $name;
        return $result;
    }
    if ($name === 'get' || $name === 'get_item') {
        $result = mcp_get($index, $args, $pageLang, $section);
        $result['tool'] = $name;
        return $result;
    }
    if ($name === 'get_comparison_row') {
        return mcp_get_comparison_row($index, $args, $pageLang);
    }
    return [
        'found' => false,
        'lang' => mcp_lang_of($args['lang'] ?? null, $pageLang),
        'tool' => $name,
        'note' => mcp_copy()['notFound']['pt'],
    ];
}
