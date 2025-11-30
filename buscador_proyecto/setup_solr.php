<?php
require 'config/config.php';

echo "--- Configurando Esquema de Solr ---\n";

$client = getSolrClient($config);

// Definimos los campos coincidiendo con tu CRAWLER ACTUAL
$campos = [
    // El crawler usa 'titulo_texto', así que definimos ese
    ['name' => 'titulo_texto', 'type' => 'text_es', 'stored' => true, 'indexed' => true],
    
    // El crawler usa 'url_str', así que definimos ese
    ['name' => 'url_str',      'type' => 'string',  'stored' => true, 'indexed' => true],
    
    // El crawler usa 'desc_texto', así que definimos ese
    ['name' => 'desc_texto',   'type' => 'text_general', 'stored' => true, 'indexed' => true], //cambie text_es a text_general
    
    // Agregamos categoría por si decides usarlo en el futuro (opcional)
    ['name' => 'categoria',    'type' => 'string',  'stored' => true, 'indexed' => true],
];

foreach ($campos as $campo) {
    $guzzle = new \GuzzleHttp\Client();
    
    try {
        // Usamos la configuración correcta del config.php para la URL
        $baseUrl = 'http://' . $config['endpoint']['localhost']['host'] . ':' . $config['endpoint']['localhost']['port'] . $config['endpoint']['localhost']['path'] . $config['endpoint']['localhost']['core'];
        
        $response = $guzzle->post($baseUrl . '/schema', [
            'json' => [
                'add-field' => $campo
            ]
        ]);
        echo "Campo '{$campo['name']}' creado/verificado.\n";
    } catch (\Exception $e) {
        echo "Nota: El campo '{$campo['name']}' ya existía o hubo un error menor.\n";
    }
}

echo "\n¡Esquema configurado correctamente!\n";