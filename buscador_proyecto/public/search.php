<?php
// search.php - VERSIÓN DE DIAGNÓSTICO
error_reporting(E_ALL); // Reportar todos los errores
ini_set('display_errors', 0); // Pero no imprimirlos en el HTML para no romper el JSON
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config_query_expansion.php';
header('Content-Type: application/json');

use Solarium\Client;
use Solarium\Core\Client\Adapter\Curl;
use Symfony\Component\EventDispatcher\EventDispatcher;

// --- VARIABLE GLOBAL PARA GUARDAR ERRORES DE GEMINI ---
$geminiDebug = ["status" => "No se intentó conectar"];

// ==========================================
// 1. FUNCIÓN GEMINI CON DIAGNÓSTICO
// ==========================================
function obtenerSinonimosGemini($termino) {
    global $geminiDebug;
    
    
    // -----------------------------------------------------------
    $apiKey = 'AIzaSyD1FdqwD_KhGzaJUNbwbH-pim65_0l7hl0'; 
    // -----------------------------------------------------------

    if ($apiKey === 'TU_API_KEY_DE_GOOGLE') {
        $geminiDebug = ["error" => "FALTA LA API KEY. Reemplaza el texto en el código."];
        return [];
    }

   // Cambiamos 'v1beta' por 'v1' y usamos el modelo clásico
$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . $apiKey;
    
    $prompt = "Eres un buscador experto. Devuelve SOLAMENTE un array JSON crudo con 3 sinónimos para: '$termino'. Ejemplo: [\"sinonimo1\", \"sinonimo2\"]";


  // CONFIGURACIÓN SIMPLE (A prueba de fallos)
    $data = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ]
        // Eliminamos 'generationConfig' por completo para evitar errores
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    // --- FIX PARA MAC/LOCALHOST (SSL) ---
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 segundos de espera máximo

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);


    // GUARDAR DIAGNÓSTICO
    if ($curlError) {
        $geminiDebug = ["error_conexion" => $curlError];
        return [];
    }
    
    $geminiDebug = [
        "status" => "Conectado",
        "codigo_http" => $httpCode,
        "respuesta_raw" => substr($response, 0, 200) . "..." // Mostramos los primeros 200 caracteres
    ];

    // Procesar JSON
    if ($response) {
        $json = json_decode($response, true);
        if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
            $rawText = $json['candidates'][0]['content']['parts'][0]['text'];
            $rawText = str_replace(['```json', '```'], '', $rawText);
            $sinonimos = json_decode($rawText, true);

            if (is_array($sinonimos)) {
                return $sinonimos;
            } else {
                $geminiDebug["error_parsing"] = "Google respondió, pero no era un array JSON válido.";
            }
        } else {
            $geminiDebug["error_api"] = "La estructura del JSON de Google no es la esperada (Posible error de cuota o modelo).";
        }
    }

    return [];
}

// ==========================================
// 2. LÓGICA DE SOLR
// ==========================================

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
$client = new Client(new Curl(), new EventDispatcher(), $config);
$queryTerm = $_GET['q'] ?? '*:*';
$query = $client->createSelect();
$dismax = $query->getEDisMax();
$dismax->setQueryFields('titulo^2.0 contenido^1.0');

// Lógica Booleana
$terminoOriginal = $queryTerm;
$reemplazos = [' and ' => ' AND ', ' or ' => ' OR ', ' not ' => ' NOT '];
$queryFinal = str_ireplace(array_keys($reemplazos), array_values($reemplazos), $terminoOriginal);

// --- EXPANSIÓN SEMÁNTICA ---
// Solo si no es una búsqueda compleja
if (strpos($queryFinal, ' AND ') === false && strpos($queryFinal, ' OR ') === false) {
    // LLAMAMOS A LA FUNCIÓN (Sin caché por ahora, para probar conexión)
    $sinonimos = obtenerSinonimosGemini($queryFinal);
    
    if (!empty($sinonimos)) {
        $sinonimosQuotes = array_map(function($s) { return '"' . trim($s) . '"'; }, $sinonimos);
        $expansion = implode(' OR ', $sinonimosQuotes);
        $queryFinal = "($queryFinal OR $expansion)";
    }
}

$query->setQuery($queryFinal);

// Facetas y Filtros
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

// Highlighting & Spellcheck
$query->getHighlighting()->setFields('contenido')->setSimplePrefix('<b>')->setSimplePostfix('</b>');
$query->getSpellcheck()->setQuery($queryTerm)->setCount(1);

// Ejecutar
try {
    $resultset = $client->select($query);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage(), 'gemini_debug' => $geminiDebug]);
    exit;
}

// Preparar respuesta
$docs = [];
foreach ($resultset as $doc) {
    $docs[] = [
        'titulo' => $doc->titulo,
        'url' => $doc->url,
        'categoria' => $doc->categoria,
        'anio_str' => $doc->anio_str ?? null,
        'lectura_str' => $doc->lectura_str ?? null,
        'score' => $doc->score
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

// --- RESPUESTA FINAL CON DIAGNÓSTICO ---
echo json_encode([
    'results' => $docs,
    'facets' => $facetData,
    'suggestion' => $suggestion,
    'total' => $resultset->getNumFound(),
    
    // AQUÍ ESTÁ EL CHIVATO
    'debug_query' => $queryFinal,
    'gemini_status' => $geminiDebug 
]);
?>
