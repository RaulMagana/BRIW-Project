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
            'port' => 8983, 
            'path' => '/', 
            'core' => 'buscador_proyecto'
        ]
    ]
];
$adapter = new Curl();
$eventDispatcher = new EventDispatcher();
$client = new Client($adapter, $eventDispatcher, $config);

// 1. Obtener término original
$queryTerm = $_GET['q'] ?? '*:*';

// 2. Crear consulta
$query = $client->createSelect();

// 3. Configuración del parser eDisMax
$dismax = $query->getEDisMax();
$dismax->setQueryFields('titulo^2.0 contenido^1.0'); // Relevancia Ponderada

// 4. LÓGICA DE CORRECCIÓN BOOLEANA (AND/OR/NOT)
// ---------------------------------------------------------
$terminoOriginal = $queryTerm;

// Diccionario de reemplazo (espacios importantes para no romper palabras)
$reemplazos = [
    ' and ' => ' AND ',
    ' or '  => ' OR ',
    ' not ' => ' NOT ',
    ' y '   => ' AND ',  // Español
    ' o '   => ' OR ',   // Español
    ' ni '  => ' NOT '   // Español
];

// Reemplazo insensible a mayúsculas/minúsculas
$queryFinal = str_ireplace(array_keys($reemplazos), array_values($reemplazos), $terminoOriginal);

// --- IMPORTANTE: ASIGNAMOS LA QUERY CORREGIDA UNA SOLA VEZ ---
$query->setQuery($queryFinal);
// ---------------------------------------------------------

// 5. Búsqueda Facetada
$facetSet = $query->getFacetSet();
$facetSet->createFacetField('categorias')->setField('categoria');

// 6. Highlighting (Snippets)
$hl = $query->getHighlighting();
$hl->setFields('contenido');
$hl->setSimplePrefix('<strong>')->setSimplePostfix('</strong>');
$hl->setSnippets(2);

// 7. Spellcheck
$spell = $query->getSpellcheck();
$spell->setQuery($queryTerm);
$spell->setCount(1);
$spell->setCollate(true);

// 8. Ejecutar
try {
    $resultset = $client->select($query);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// 9. Preparar respuesta
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
        'snippet' => $snippetText,
        'score' => $document->score
    ];
}

// Obtener facetas
$facets = $resultset->getFacetSet()->getFacet('categorias');
$facetData = [];
foreach ($facets as $value => $count) {
    if ($count > 0) $facetData[$value] = $count;
}

// Obtener sugerencia ortográfica
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