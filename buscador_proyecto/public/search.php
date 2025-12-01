<?php
// search.php - VERSIÓN FINAL CORREGIDA
// Ocultamos advertencias (Deprecated) para que no ensucien el JSON
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', 0);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/config_query_expansion.php';
header('Content-Type: application/json');

use Solarium\Client;
use Solarium\Core\Client\Adapter\Curl;
use Symfony\Component\EventDispatcher\EventDispatcher;

// ==========================================
// 1. FUNCIÓN DE EXPANSIÓN SEMÁNTICA (GEMINI)
// ==========================================
function obtenerSinonimosGemini($termino) {
    
    // -----------------------------------------------------------
    // 🔑 PEGA TU API KEY AQUÍ ABAJO (Dentro de las comillas)
    // -----------------------------------------------------------
    $apiKey = 'AIzaSyB8yv4yT17DNe1FAHf9t2xlYuncsELR1vA'; 
    // -----------------------------------------------------------

    // Validación simple
    if ($apiKey === 'TU_API_KEY_DE_GOOGLE' || strlen($termino) < 3) {
        return [];
    }

    // USAREMOS EL MODELO QUE SÍ TIENES: gemini-2.0-flash
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . $apiKey;
    
    // Instrucción precisa para obligar a Gemini a pensar en español
    // Prompt con contexto de TECNOLOGÍA para evitar confusiones
    $prompt = "Contexto: Eres el motor de expansión semántica de una Enciclopedia de TECNOLOGÍA e INFORMÁTICA en español. " .
              "Tu tarea es generar sinónimos técnicos para mejorar la búsqueda. " .
              "REGLAS OBLIGATORIAS: " .
              "1. Idioma: Todo debe ser en ESPAÑOL. " .
              "2. Desambiguación: Si una palabra es un 'falso amigo' (como 'red'), asume SIEMPRE el significado informático (ej: 'red' = 'conexión/network', NUNCA 'color rojo'). " .
              "3. Devuelve SOLAMENTE un array JSON crudo con 3 sinónimos para: '$termino'.";
    // Configuración mínima para evitar errores 400
    $data = [
        'contents' => [
            [ 'parts' => [ ['text' => $prompt] ] ]
        ]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    // FIX SSL PARA LOCALHOST (Mac/XAMPP)
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 4); // 4 segundos máximo

    $response = curl_exec($ch);
    
    // En PHP 8.x curl_close ya no es obligatorio y da warning, lo quitamos o ignoramos.
    // curl_close($ch); 

    if ($response) {
        $json = json_decode($response, true);
        
        // Buscamos el texto en la estructura de Gemini
        if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
            $rawText = $json['candidates'][0]['content']['parts'][0]['text'];
            
            // Limpiamos marcas de Markdown (```json ... ```)
            $rawText = str_replace(['```json', '```'], '', $rawText);
            $sinonimos = json_decode($rawText, true);

            if (is_array($sinonimos)) {
                return $sinonimos;
            }
        }
    }

    return [];
}

// ==========================================
// 2. CONFIGURACIÓN SOLR
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

// 3. PROCESAR CONSULTA
$queryTerm = $_GET['q'] ?? '*:*';
$query = $client->createSelect();
$dismax = $query->getEDisMax();
// Ajusta estos pesos según lo que prefieras
$dismax->setQueryFields('titulo^3.0 contenido^1.0 categoria^0.5'); 

// 4. PREPARACIÓN BOOLEANA
$terminoOriginal = $queryTerm;
$reemplazos = [' and ' => ' AND ', ' or ' => ' OR ', ' not ' => ' NOT '];
$queryFinal = str_ireplace(array_keys($reemplazos), array_values($reemplazos), $terminoOriginal);

// 5. APLICAR EXPANSIÓN SEMÁNTICA
// Solo si es una búsqueda simple (sin operadores complejos)
if (strpos($queryFinal, ' AND ') === false && strpos($queryFinal, ' OR ') === false && $queryFinal !== '*:*') {
    
    // CACHÉ SIMPLE EN ARCHIVO (Para no gastar API ni tiempo)
    $archivoCache = __DIR__ . '/cache_sinonimos_v2.json';
    $terminoLower = mb_strtolower(trim($queryFinal));
    $sinonimos = [];

    // A) Intentar leer de caché
    if (file_exists($archivoCache)) {
        $cacheData = json_decode(file_get_contents($archivoCache), true) ?? [];
        if (isset($cacheData[$terminoLower])) {
            $sinonimos = $cacheData[$terminoLower];
        }
    }

    // B) Si no hay caché, preguntar a Gemini
    if (empty($sinonimos)) {
        $sinonimos = obtenerSinonimosGemini($queryFinal);
        
        // Guardar en caché si hubo éxito
        if (!empty($sinonimos)) {
            $cacheData = file_exists($archivoCache) ? json_decode(file_get_contents($archivoCache), true) : [];
            $cacheData[$terminoLower] = $sinonimos;
            file_put_contents($archivoCache, json_encode($cacheData));
        }
    }
    
    // C) Modificar la query
    if (!empty($sinonimos)) {
        // Ponemos comillas a cada sinónimo
        $sinonimosQuotes = array_map(function($s) { return '"' . trim($s) . '"'; }, $sinonimos);
        $expansion = implode(' OR ', $sinonimosQuotes);
        // Query final: (original OR sinonimo1 OR sinonimo2)
        $queryFinal = "($queryFinal OR $expansion)";
    }
}

$query->setQuery($queryFinal);

// 6. FACETAS (FILTROS LATERALES)
$facetSet = $query->getFacetSet();
$facetSet->createFacetField('categorias')->setField('categoria_str');
$facetSet->createFacetField('niveles_lectura')->setField('lectura_str');
$facetSet->createFacetField('anios')->setField('anio_str')->setSort('index');

// 7. APLICAR FILTROS DE URL
$mapaFiltros = ['cat' => 'categoria_str', 'lectura' => 'lectura_str', 'anio' => 'anio_str'];
foreach ($mapaFiltros as $paramUrl => $campoSolr) {
    if ($val = $_GET[$paramUrl] ?? null) {
        $query->createFilterQuery('filtro_'.$paramUrl)->setQuery(sprintf('%s:"%s"', $campoSolr, $val));
    }
}

// 8. EXTRAS (Snippets, Corrector)
$query->getHighlighting()->setFields('contenido')->setSimplePrefix('<b>')->setSimplePostfix('</b>');
$query->getSpellcheck()->setQuery($queryTerm)->setCount(1);

// 9. EJECUTAR EN SOLR
try {
    $resultset = $client->select($query);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// 10. PREPARAR RESPUESTA JSON
$docs = [];
$hl = $resultset->getHighlighting();

foreach ($resultset as $doc) {
    // Snippet
    $snip = $hl->getResult($doc->id)->getField('contenido');
    $snippetText = count($snip) > 0 ? implode(' ... ', $snip) : substr($doc->contenido[0] ?? '', 0, 100);

    $docs[] = [
        'titulo' => $doc->titulo,
        'url' => $doc->url,
        'categoria' => $doc->categoria,
        'anio_str' => $doc->anio_str ?? null,
        'lectura_str' => $doc->lectura_str ?? null,
        'snippet' => $snippetText,
        'score' => $doc->score
    ];
}

// Facetas
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

// Sugerencia
$suggestion = null;
if ($spell = $resultset->getSpellcheck()) {
    foreach ($spell->getCollations() as $c) { $suggestion = $c->getQuery(); break; }
}

echo json_encode([
    'results' => $docs,
    'facets' => $facetData,
    'suggestion' => $suggestion,
    'total' => $resultset->getNumFound(),
    'debug_query' => $queryFinal // Para que veas la magia en la consola
]);
?>