<?php
// search.php - VERSIÓN FINAL LIMPIA
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', 0);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config_query_expansion.php';
header('Content-Type: application/json');

use Solarium\Client;
use Solarium\Core\Client\Adapter\Curl;
use Symfony\Component\EventDispatcher\EventDispatcher;


function obtenerSinonimosGemini($termino) {
    global $geminiDebug;
    
  
    $apiKey = 'llave'; 
 

    if (strpos($apiKey, 'TU_LLAVE_REAL') !== false || strlen($termino) < 3) {
        $geminiDebug = ["error" => "FALTA LA API KEY."];
        return [];
    }

 
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . $apiKey;
    
    $prompt = "Contexto: Buscador de Tecnología. Genera un array JSON con 3 sinónimos técnicos en español para: '$termino'. IGNORA otros idiomas. Ejemplo: [\"sinonimo1\"]";

    $data = ['contents' => [[ 'parts' => [ ['text' => $prompt] ] ]]];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    // Fix SSL
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($curlError) {
        $geminiDebug = ["error_conexion" => $curlError];
        return [];
    }
    
    $geminiDebug = ["status" => "Conectado", "codigo_http" => $httpCode];

    if ($response) {
        $json = json_decode($response, true);
        
       
        if (isset($json['error'])) {
             $geminiDebug = ["error_fatal" => $json['error']['message']];
             return [];
        }

      
        if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
            $rawText = $json['candidates'][0]['content']['parts'][0]['text'];
            $rawText = str_replace(['```json', '```'], '', $rawText);
            $sinonimos = json_decode($rawText, true);

            if (is_array($sinonimos)) return $sinonimos;
        }
    }

    return [];
}

// ==========================================
// 2. LÓGICA DE SOLR
// ==========================================

$config = [
    'endpoint' => [
        'localhost' => ['host' => '127.0.0.1', 'port' => 8983, 'path' => '/', 'core' => 'buscador_proyecto']
    ]
];
$client = new Client(new Curl(), new EventDispatcher(), $config);
$queryTerm = $_GET['q'] ?? '*:*';
$query = $client->createSelect();
$dismax = $query->getEDisMax();
$dismax->setQueryFields('titulo^2.0 contenido^1.0');


$queryFinal = $queryTerm;


if (strpos($queryTerm, ' AND ') === false && strpos($queryTerm, ' OR ') === false) {
    $sinonimos = obtenerSinonimosGemini($queryTerm);
    
    if (!empty($sinonimos)) {
        $sinonimosQuotes = array_map(function($s) { return '"' . trim($s) . '"'; }, $sinonimos);
        $expansion = implode(' OR ', $sinonimosQuotes);
        $queryFinal = "($queryTerm OR $expansion)";
    }
}

$query->setQuery($queryFinal);

// Facetas y Filtros (Completo)
$facetSet = $query->getFacetSet();
$facetSet->createFacetField('categorias')->setField('categoria_str');
$facetSet->createFacetField('niveles_lectura')->setField('lectura_str');
$facetSet->createFacetField('anios')->setField('anio_str')->setSort('index');

$mapaFiltros = ['cat' => 'categoria_str', 'lectura' => 'lectura_str', 'anio' => 'anio_str'];
foreach ($mapaFiltros as $paramUrl => $campoSolr) {
    if ($val = $_GET[$paramUrl] ?? null) {
        $query->createFilterQuery('filtro_'.$paramUrl)->setQuery(sprintf('%s:"%s"', $campoSolr, $val));
    }
}

// Highlighting y Snippets (AQUÍ ESTÁ LA LÍNEA PARA EL ÚLTIMO FIX)
$hl = $query->getHighlighting();
$hl->setFields('contenido');
$hl->setSimplePrefix('<b>')->setSimplePostfix('</b>');
$hl->setSnippets(1); 
$hl->setFragSize(150); // Cortar snippet en Solr

// Usar la query expandida para encontrar highlights
$hl->setQuery($queryFinal); 

$spell = $query->getSpellcheck();
$spell->setQuery($queryTerm);
$spell->setCount(1);
$spell->setCollate(true);

// Ejecutar
try {
    $resultset = $client->select($query);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage(), 'gemini_debug' => $geminiDebug]);
    exit;
}

// Preparar respuesta
$docs = [];
$highlighting = $resultset->getHighlighting();

foreach ($resultset as $document) {
    // LÓGICA CORREGIDA DEL SNIPPET
    $snip = $highlighting->getResult($document->id)->getField('contenido');
    
    if (count($snip) > 0) {
        // Si hay highlight (éxito), usamos el primer fragmento (con <b> tags)
        $rawSnippet = $snip[0]; 
    } else {
        // Fallback: Tomamos el texto original, lo limpiamos y cortamos
        $contenidoOriginal = is_array($document->contenido) ? ($document->contenido[0] ?? '') : ($document->contenido ?? '');
        $textoLimpio = strip_tags($contenidoOriginal);
        $rawSnippet = mb_substr($textoLimpio, 0, 150) . '...';
    }

    $docs[] = [
        'titulo' => $document->titulo, 'url' => $document->url, 'categoria' => $document->categoria,
        'anio_str' => $document->anio_str ?? null, 'lectura_str' => $document->lectura_str ?? null,
        'snippet' => $rawSnippet, 'score' => $document->score
    ];
}

$allFacets = $resultset->getFacetSet()->getFacets();
$facetData = [];
foreach ($allFacets as $name => $res) {
    $ops = [];
    foreach ($res as $v => $c) if($c>0) $ops[$v]=$c;
    if(!empty($ops)) {
        if($name==='anios') krsort($ops);
        $facetData[$name] = $ops;
    }
}

$suggestion = null;
if ($spell = $resultset->getSpellcheck()) {
    foreach ($spell->getCollations() as $c) { $suggestion = $c->getQuery(); break; }
}

echo json_encode([
    'results' => $docs,
    'facets' => $facetData,
    'suggestion' => $suggestion,
    'total' => $resultset->getNumFound(),
    'debug_query' => $queryFinal,
    'gemini_status' => $geminiDebug 
]);
?>