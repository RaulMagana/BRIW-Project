<?php
error_reporting(0);
ini_set('display_errors', 0);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config_query_expansion.php';
header('Content-Type: application/json');

use Solarium\Client;
use Solarium\Core\Client\Adapter\Curl;
use Symfony\Component\EventDispatcher\EventDispatcher;

// 1. Configuración del Cliente
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

// 2. Obtener término
$queryTerm = $_GET['q'] ?? '*:*';

// 3. Crear consulta
$query = $client->createSelect();

// 4. Configuración eDisMax (Relevancia)
$dismax = $query->getEDisMax();
$dismax->setQueryFields('titulo^2.0 contenido^1.0'); 

// 5. Lógica Booleana (AND/OR/NOT)
$terminoOriginal = $queryTerm;
$reemplazos = [
    ' and ' => ' AND ', ' or '  => ' OR ', ' not ' => ' NOT ',
    ' y '   => ' AND ', ' o '   => ' OR ', ' ni '  => ' NOT '
];
$queryFinal = str_ireplace(array_keys($reemplazos), array_values($reemplazos), $terminoOriginal);
$query->setQuery($queryFinal);

// ---------------------------------------------------------
// 6. CONFIGURACIÓN DE FACETAS (FILTROS)
// ---------------------------------------------------------
$facetSet = $query->getFacetSet();

// A. Categoría
$facetSet->createFacetField('categorias')->setField('categoria_str');

// B. Tiempo de Lectura
$facetSet->createFacetField('niveles_lectura')->setField('lectura_str');

// C. Año (Corregido: Quitamos setDirection para evitar el error)
$facetSet->createFacetField('anios')
         ->setField('anio_str')
         ->setSort('index'); // Ordena por número (2020, 2021...)

// ---------------------------------------------------------
// 7. APLICAR FILTROS ACTIVOS
// ---------------------------------------------------------
$mapaFiltros = [
    'cat'     => 'categoria_str',
    'lectura' => 'lectura_str',
    'anio'    => 'anio_str'
];

foreach ($mapaFiltros as $paramUrl => $campoSolr) {
    $valor = $_GET[$paramUrl] ?? null;

    if ($valor) {
        $filterName = 'filtro_' . $paramUrl;
        // Aplicamos el filtro con comillas para seguridad
        $query->createFilterQuery($filterName)
              ->setQuery(sprintf('%s:"%s"', $campoSolr, $valor));
    }
}

// 8. Highlighting
$hl = $query->getHighlighting();
$hl->setFields('contenido');
$hl->setSimplePrefix('<strong>')->setSimplePostfix('</strong>');
$hl->setSnippets(2);

// 9. Spellcheck
$spell = $query->getSpellcheck();
$spell->setQuery($queryTerm);
$spell->setCount(1);
$spell->setCollate(true);

// 10. Ejecutar Consulta
try {
    $resultset = $client->select($query);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// 11. Procesar Resultados
$docs = [];
$highlighting = $resultset->getHighlighting();

foreach ($resultset as $document) {
    $snip = $highlighting->getResult($document->id)->getField('contenido');
    $snippetText = count($snip) > 0 ? implode(' ... ', $snip) : substr($document->contenido[0] ?? '', 0, 100);

    $docs[] = [
        'titulo' => $document->titulo,
        'url' => $document->url,
        'categoria' => $document->categoria,
        // Pasamos los datos nuevos al frontend para los badges
        'anio_str' => $document->anio_str ?? null,
        'lectura_str' => $document->lectura_str ?? null,
        'snippet' => $snippetText,
        'score' => $document->score
    ];
}

// 12. Procesar Facetas (Aquí ordenamos los años)
$allFacets = $resultset->getFacetSet()->getFacets();
$facetData = [];

foreach ($allFacets as $facetName => $facetResult) {
    $opciones = [];
    foreach ($facetResult as $value => $count) {
        if ($count > 0) {
            $opciones[$value] = $count;
        }
    }

    if (!empty($opciones)) {
        // TRUCO: Si es la lista de años, la ordenamos descendente (krsort)
        // para que salga 2024, 2023, 2022...
        if ($facetName === 'anios') {
            krsort($opciones);
        }

        $facetData[$facetName] = $opciones;
    }
}

// 13. Sugerencias
$suggestion = null;
$spellChk = $resultset->getSpellcheck();
if ($spellChk && !$spellChk->getCorrectlySpelled()) {
    $collations = $spellChk->getCollations();
    foreach ($collations as $collation) {
        $suggestion = $collation->getQuery();
        break;
    }
}

// 14. Respuesta Final
echo json_encode([
    'results' => $docs,
    'facets' => $facetData,
    'suggestion' => $suggestion,
    'total' => $resultset->getNumFound()
]);
?>