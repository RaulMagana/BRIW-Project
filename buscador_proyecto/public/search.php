<?php
// =========================================================
// search.php - VERSIÓN FINAL ESTABLE (SIN FILTRO AÑO)
// =========================================================

// 1. ACTIVAR REPORTE DE ERRORES (Para que no salga pantalla blanca 500)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE); // Ignora avisos leves

// Cabecera JSON
header('Content-Type: application/json; charset=utf-8');

// 2. CARGA DE LIBRERÍAS
require __DIR__ . '/../vendor/autoload.php';
// require __DIR__ . '/../config/config_query_expansion.php'; // (Opcional si no lo usas)

use Solarium\Client;
use Solarium\Core\Client\Adapter\Curl;
use Symfony\Component\EventDispatcher\EventDispatcher;

// Variable global para depurar Gemini
$geminiDebug = null;

// ==========================================
// 3. FUNCIÓN GEMINI (BLINDADA)
// ==========================================
function obtenerSinonimosGemini($termino) {
    global $geminiDebug;
    
    // --- PON TU API KEY AQUÍ ---
    $apiKey = 'TU_LLAVE_DE_API_DE_GOOGLE_AQUI'; 
    // ---------------------------

    // Validación previa
    if (strpos($apiKey, 'TU_LLAVE') !== false || strlen($termino) < 3) {
        $geminiDebug = ["info" => "No se consultó API (Falta Key o término corto)"];
        return [];
    }
 
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . $apiKey;
    $prompt = "Contexto: Buscador Técnico. Dame un array JSON puro con 3 sinónimos para: '$termino'. Ejemplo: [\"red\", \"malla\"]. Sin markdown.";
    $data = ['contents' => [[ 'parts' => [ ['text' => $prompt] ] ]]];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    // SEGURIDAD: Evitar que falle si el internet es lento
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2); // Si tarda más de 2s, se cancela

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Diagnóstico
    if ($curlError) {
        $geminiDebug = ["error_conexion" => $curlError, "code" => $httpCode];
        return [];
    }

    if ($response) {
        $json = json_decode($response, true);
        
        // Verificar errores de Google
        if (isset($json['error'])) {
            $geminiDebug = ["error_google" => $json['error']['message']];
            return [];
        }

        if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
            $texto = $json['candidates'][0]['content']['parts'][0]['text'];
            $texto = str_replace(['```json', '```', "\n"], '', $texto);
            $array = json_decode($texto, true);
            
            if (is_array($array)) {
                $geminiDebug = ["status" => "OK", "sinonimos" => $array];
                return $array;
            }
        }
    }
    
    return [];
}

// ==========================================
// 4. CONFIGURACIÓN SOLR
// ==========================================
try {
    $config = [
        'endpoint' => [
            'localhost' => ['host' => '127.0.0.1', 'port' => 8983, 'path' => '/', 'core' => 'buscador_proyecto']
        ]
    ];
    $client = new Client(new Curl(), new EventDispatcher(), $config);
    
    // Obtener término
    $queryTerm = isset($_GET['q']) && trim($_GET['q']) !== '' ? $_GET['q'] : '*:*';
    $query = $client->createSelect();
    
    // Configuración de pesos (DisMax)
    $dismax = $query->getEDisMax();
    $dismax->setQueryFields('titulo^2.0 contenido^1.0');

    // ==========================================
    // 5. LÓGICA DE EXPANSIÓN (GEMINI)
    // ==========================================
    $queryFinal = $queryTerm;
    
    // Solo expandir si es una búsqueda simple
    if ($queryTerm !== '*:*' && strpos($queryTerm, ' AND ') === false && strpos($queryTerm, ' OR ') === false) {
        $sinonimos = obtenerSinonimosGemini($queryTerm);
        if (!empty($sinonimos)) {
            $sinonimosQuotes = array_map(function($s) { return '"' . trim($s) . '"'; }, $sinonimos);
            $expansion = implode(' OR ', $sinonimosQuotes);
            $queryFinal = "($queryTerm OR $expansion)";
        }
    }
    $query->setQuery($queryFinal);

    // ==========================================
    // 6. FILTROS (SIN AÑO)
    // ==========================================
    $helper = $query->getHelper();
    
    // Mapa reducido (Quitamos 'anio')
    $mapaFiltros = [
        'cat'     => 'categoria_str', 
        'lectura' => 'lectura_str'
    ];

    $filtrosActivos = [];

    foreach ($mapaFiltros as $paramUrl => $campoSolr) {
        $val = $_GET[$paramUrl] ?? null;
        if ($val && !is_array($val) && trim($val) !== '') {
            $valSeguro = $helper->escapePhrase($val);
            $query->createFilterQuery('fq_' . $paramUrl)->setQuery($campoSolr . ':' . $valSeguro);
            $filtrosActivos[$paramUrl] = $val;
        }
    }

    // ==========================================
    // 7. FACETAS (MENÚ LATERAL - SIN AÑO)
    // ==========================================
    $facetSet = $query->getFacetSet();
    $facetSet->createFacetField('categorias')->setField('categoria_str')->setMinCount(1);
    $facetSet->createFacetField('niveles_lectura')->setField('lectura_str')->setMinCount(1);
    // Nota: Hemos eliminado createFacetField('anios')

    // ==========================================
    // 8. HIGHLIGHT Y SPELLCHECK
    // ==========================================
    $hl = $query->getHighlighting();
    $hl->setFields('contenido');
    $hl->setSimplePrefix('<b>')->setSimplePostfix('</b>');
    $hl->setSnippets(1);
    $hl->setFragSize(150);
    $hl->setQuery($queryFinal);

    $spell = $query->getSpellcheck();
    $spell->setQuery($queryTerm);
    $spell->setCount(1);
    $spell->setCollate(true);

    // ==========================================
    // 9. EJECUCIÓN
    // ==========================================
    $resultset = $client->select($query);
    
    // Procesar documentos
    $docs = [];
    $highlighting = $resultset->getHighlighting();

    foreach ($resultset as $document) {
        // Snippet seguro
        $snip = $highlighting->getResult($document->id)->getField('contenido');
        if (count($snip) > 0) {
            $rawSnippet = $snip[0];
        } else {
            $contenidoOriginal = is_array($document->contenido) ? ($document->contenido[0] ?? '') : ($document->contenido ?? '');
            $textoLimpio = strip_tags($contenidoOriginal);
            $rawSnippet = mb_substr($textoLimpio, 0, 150) . '...';
        }

        $docs[] = [
            'titulo'       => $document->titulo, 
            'url'          => $document->url, 
            'categoria'    => $document->categoria,
            'lectura_str'  => $document->lectura_str ?? null,
            // Quitamos anio_str para limpiar la respuesta
            'snippet'      => $rawSnippet, 
            'score'        => $document->score
        ];
    }

    // Procesar Facetas limpias
    $allFacets = $resultset->getFacetSet()->getFacets();
    $facetData = [];
    foreach ($allFacets as $name => $res) {
        $ops = [];
        foreach ($res as $v => $c) {
            if($c > 0) $ops[$v] = $c;
        }
        if(!empty($ops)) $facetData[$name] = $ops;
    }

    // Sugerencia
    $suggestion = null;
    if ($spell = $resultset->getSpellcheck()) {
        foreach ($spell->getCollations() as $c) { $suggestion = $c->getQuery(); break; }
    }

    // RESPUESTA FINAL
    echo json_encode([
        'results'        => $docs,
        'facets'         => $facetData,
        'suggestion'     => $suggestion,
        'total'          => $resultset->getNumFound(),
        'filtros_activos'=> $filtrosActivos,
        'gemini_status'  => $geminiDebug 
    ]);

} catch (Exception $e) {
    // Si algo falla catastróficamente, mostramos JSON con el error
    http_response_code(500);
    echo json_encode([
        'error' => 'Error fatal en el servidor',
        'detalles' => $e->getMessage(),
        'archivo' => $e->getFile(),
        'linea' => $e->getLine()
    ]);
}
?>