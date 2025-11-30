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



// Crear consulta
$query = $client->createSelect();

// Configuración del parser eDisMax  para que funcione la sintaxis booleana nativa
$dismax = $query->getEDisMax();

// Aquí definimos dónde buscar. 
// Título vale x2 (Requisito 2 cumplido).
$dismax->setQueryFields('titulo^2.0 contenido^1.0');

// Lógica de Expansión de Consulta
$finalQueryString = $queryTerm;


// Establecer la query final suponiendo que se ha expandido correctamente sino solo se remplaza el parametro por "queryTerm"
$query->setQuery($finalQueryString);

// 3. Búsqueda Facetada (4 pts) - categoría se obtiene automáticamente del documento pero es muy simple MEJORAR LA BUSQUEDA FACETADA esta relacionada con el crawler, desde el crawler determina la categoria. MODIFICAR!!!!
$facetSet = $query->getFacetSet();
$facetSet->createFacetField('categorias')->setField('categoria');

// 4. Resultados con Snippets / Highlighting (2 pts) LISTO, ya resalta en el contenido las palabras buscadas
$hl = $query->getHighlighting();
$hl->setFields('contenido');
$hl->setSimplePrefix('<strong>')->setSimplePostfix('</strong>');
$hl->setSnippets(2);

// 5. Sugerencias de corrección "Did you mean" (3 pts) NO FUNCIONA
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
