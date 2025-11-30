<?php
require __DIR__ . '/../config/config.php'; // Usamos el config centralizado
header('Content-Type: application/json');

// Inicializar cliente Solr
$client = getSolrClient($config);

$queryTerm = $_GET['q'] ?? '*:*';
$catFilter = $_GET['cat'] ?? null;
$doSpell   = isset($_GET['spellcheck']) && $_GET['spellcheck'] === 'true';

// Crear consulta
$query = $client->createSelect();

// 1. Usar eDisMax para búsqueda inteligente
$dismax = $query->getEDisMax();
// IMPORTANTE: Aquí le decimos que busque en los campos que TU CRAWLER llenó
$dismax->setQueryFields('titulo_texto^3.0 desc_texto^1.0');

// 2. Filtro por Categoría (Facetas)
if ($catFilter) {
    $query->createFilterQuery('cat')->setQuery('categoria:"' . $catFilter . '"');
}

// Configurar Query Principal
$query->setQuery($queryTerm);

// 3. Configurar Facetas (para la barra lateral)
$facetSet = $query->getFacetSet();
$facetSet->createFacetField('categorias')->setField('categoria');

// 4. Configurar Highlighting (Resaltado)
$hl = $query->getHighlighting();
$hl->setFields('desc_texto'); // Resaltar en el cuerpo del texto
$hl->setSimplePrefix('<strong>')->setSimplePostfix('</strong>');
$hl->setSnippets(2);

// 5. Configurar "Did you mean" (Corrector)
if ($doSpell) {
    $spell = $query->getSpellcheck();
    $spell->setQuery($queryTerm);
    $spell->setCount(1);
    $spell->setCollate(true);
}

// Ejecutar consulta
try {
    $resultset = $client->select($query);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// Preparar respuesta JSON para el Frontend (app.js)
$docs = [];
$highlighting = $resultset->getHighlighting();

foreach ($resultset as $document) {
    // Obtenemos el snippet resaltado
    $snip = $highlighting->getResult($document->id)->getField('desc_texto');
    $snippetText = count($snip) > 0 ? implode(' ... ', $snip) : substr($document->desc_texto[0] ?? '', 0, 150) . '...';

    // MAPEO CRÍTICO: Convertimos los nombres de tu Crawler a los que espera el JS
    $docs[] = [
        'titulo'    => $document->titulo_texto, // Crawler: titulo_texto -> JS: titulo
        'url'       => $document->url_str,      // Crawler: url_str      -> JS: url
        'categoria' => $document->categoria ?? 'General',
        'snippet'   => $snippetText
    ];
}

// Procesar Facetas
$facets = $resultset->getFacetSet()->getFacet('categorias');
$facetData = [];
foreach ($facets as $value => $count) {
    if ($count > 0) $facetData[$value] = $count;
}

// Procesar Sugerencia
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
    'results'    => $docs,
    'facets'     => $facetData,
    'suggestion' => $suggestion,
    'total'      => $resultset->getNumFound()
]);