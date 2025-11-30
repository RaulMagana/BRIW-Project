<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config_query_expansion.php';
header('Content-Type: application/json');

use Solarium\Client;
use Solarium\Core\Client\Adapter\Curl;
use Symfony\Component\EventDispatcher\EventDispatcher;

$config = [
    'endpoint' => [
        'localhost' => [
            'host' => '127.0.0.1', 
            'port' => 8983, 'path' => '/', 
            'core' => 'buscador_proyecto'
        ]
    ]
];
$adapter = new Curl();
$eventDispatcher = new EventDispatcher();
$client = new Client($adapter, $eventDispatcher, $config);

$queryTerm = $_GET['q'] ?? '*:*';

// Expansion de consulta con Datamuse
$finalQueryString = $queryTerm;

if ($queryTerm !== '*:*' && trim($queryTerm) !== '') {
    $qTrim = trim($queryTerm);
    $expanded_terms = [];
    
    // Consultar Datamuse para obtener términos relacionados
    $datamuse_url = DATAMUSE_API_URL . '?ml=' . urlencode($qTrim) . '&max=3';
    $datamuse_json = @file_get_contents($datamuse_url);
    
    if ($datamuse_json) {
        $words = json_decode($datamuse_json, true);
        if (is_array($words)) {
            foreach ($words as $word) {
                if (!empty($word['word'])) {
                    $expanded_terms[] = $word['word'];
                }
            }
        }
    }
    
    // Construir query: (término1 AND término2 ...) AND ("original" OR sinónimo1 OR sinónimo2 ...)
    $base_query_terms = preg_split('/\s+/', $qTrim, -1, PREG_SPLIT_NO_EMPTY);
    
    if (!empty($base_query_terms)) {
        $base_query_lucene = '(' . implode(' AND ', $base_query_terms) . ')';
        $expansion_terms = array_merge([$qTrim], $expanded_terms);
        $expansion_query = '(' . implode(' OR ', $expansion_terms) . ')';
        $finalQueryString = $base_query_lucene . ' AND ' . $expansion_query;
    }
}

// Crear consulta
$query = $client->createSelect();

// 1. Búsqueda Booleana (2 pts): Solr lo soporta nativamente (ej: "auto AND rojo")
// 2. Relevancia Ponderada (4 pts): Título vale x2 más que el contenido
$dismax = $query->getEDisMax();
$dismax->setQueryFields('titulo^2.0 contenido^1.0');

$query->setQuery($finalQueryString);

// 3. Búsqueda Facetada (4 pts) - categoría se obtiene automáticamente del documento
$facetSet = $query->getFacetSet();
$facetSet->createFacetField('categorias')->setField('categoria');

// 4. Resultados con Snippets / Highlighting (2 pts)
$hl = $query->getHighlighting();
$hl->setFields('contenido');
$hl->setSimplePrefix('<strong>')->setSimplePostfix('</strong>');
$hl->setSnippets(2);

// 5. Sugerencias de corrección "Did you mean" (3 pts)
$spell = $query->getSpellcheck();
$spell->setQuery($queryTerm);
$spell->setCount(1);
$spell->setCollate(true);

// Ejecutar
try {
    $resultset = $client->select($query);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// Preparar respuesta JSON
$docs = [];
$highlighting = $resultset->getHighlighting();

foreach ($resultset as $document) {
    // Obtener snippet
    $snip = $highlighting->getResult($document->id)->getField('contenido');
    $snippetText = count($snip) > 0 ? implode(' ... ', $snip) : substr($document->contenido[0] ?? '', 0, 100);

    $docs[] = [
        'titulo' => $document->titulo,
        'url' => $document->url,
        'categoria' => $document->categoria,
        'snippet' => $snippetText
    ];
}

// Obtener facetas (categorías del índice)
$facets = $resultset->getFacetSet()->getFacet('categorias');
$facetData = [];
foreach ($facets as $value => $count) {
    if ($count > 0) $facetData[$value] = $count;
}

// PROCESAR CORRECCIÓN ORTOGRÁFICA
$suggestion = null;
$spellChk = $resultset->getSpellcheck();
if ($spellChk && !$spellChk->getCorrectlySpelled()) {
    $collations = $spellChk->getCollations();
    foreach ($collations as $collation) {
        $suggestion = $collation->getQuery();
        break;
    }
}

echo json_encode([
    'results' => $docs,
    'facets' => $facetData,
    'suggestion' => $suggestion,
    'total' => $resultset->getNumFound()
]);

