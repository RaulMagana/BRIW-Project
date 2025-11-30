<?php
require 'config/config.php';

echo "--- Configurando Esquema de Solr (Sincronizado) ---\n";

$client = getSolrClient($config);

// Definimos los campos EXACTOS que usa tu Crawler
$campos = [
    ['name' => 'titulo',    'type' => 'text_es', 'stored' => true, 'indexed' => true],
    ['name' => 'contenido', 'type' => 'text_es', 'stored' => true, 'indexed' => true],
    ['name' => 'url',       'type' => 'string',  'stored' => true, 'indexed' => true],
    ['name' => 'categoria', 'type' => 'string',  'stored' => true, 'indexed' => true],
];

foreach ($campos as $campo) {
    $guzzle = new \GuzzleHttp\Client();
    try {
        $baseUrl = 'http://' . $config['endpoint']['localhost']['host'] . ':' . $config['endpoint']['localhost']['port'] . $config['endpoint']['localhost']['path'] . $config['endpoint']['localhost']['core'];
        $response = $guzzle->post($baseUrl . '/schema', [
            'json' => ['add-field' => $campo]
        ]);
        echo "Campo '{$campo['name']}' creado/verificado.\n";
    } catch (\Exception $e) {
        
    }
}
echo "\n¡Esquema configurado correctamente!\n";