<?php
require __DIR__ . '/../config/config.php'; 
header('Content-Type: application/json');

$client = getSolrClient($config);
$queryTerm = $_GET['q'] ?? '*:*';
$catFilter = $_GET['cat'] ?? null;
$doSpell   = isset($_GET['spellcheck']) && $_GET['spellcheck'] === 'true';

$query = $client->createSelect();
$dismax = $query->getEDisMax();

// 1. IMPORTANTE: Buscamos en los campos que TU CRAWLER llenó
$dismax->setQueryFields('titulo_texto^3.0 desc_texto^1.0');

if ($catFilter) {
    $query->createFilterQuery('cat')->setQuery('categoria:"' . $catFilter . '"');
}

$query->setQuery($queryTerm);

$facetSet = $query->getFacetSet();
$facetSet->createFacetField('categorias')->setField('categoria');

$hl = $query->getHighlighting();
$hl->setFields('desc_texto'); // Resaltamos en la descripción
$hl->setSimplePrefix('<strong>')->setSimplePostfix('</strong>');
$hl->setSnippets(2);

if ($doSpell) {
    $spell = $query->getSpellcheck();
    $spell->setQuery($queryTerm);
    $spell->setCount(1);
    $spell->setCollate(true);
}

try {
    $resultset = $client->select($query);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

$docs = [];
$highlighting = $resultset->getHighlighting();

foreach ($resultset as $document) {
    $snip = $highlighting->getResult($document->id)->getField('desc_texto');
    $snippetText = count($snip) > 0 ? implode(' ... ', $snip) : substr($document->desc_texto[0] ?? '', 0, 150) . '...';

    // 2. TRADUCCIÓN: De nombres del Crawler -> a nombres del Frontend
    $docs[] = [
        'titulo'    => $document->titulo_texto, // Crawler usa titulo_texto
        'url'       => $document->url_str,      // Crawler usa url_str
        'categoria' => $document->categoria ?? 'General',
        'snippet'   => $snippetText
    ];
}

$facets = $resultset->getFacetSet()->getFacet('categorias');
$facetData = [];
foreach ($facets as $value => $count) {
    if ($count > 0) $facetData[$value] = $count;
}

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