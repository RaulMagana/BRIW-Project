<?php
// Fíjate en el "/../" para salir de la carpeta config y buscar vendor
require __DIR__ . '/../vendor/autoload.php'; 

$config = [
    'endpoint' => [
        'localhost' => [
            'host' => '127.0.0.1',
            'port' => 8983,
            'path' => '/',
            'core' => 'buscador_empresarial',
        ]
    ]
];

function getSolrClient($config) {
    // Usamos el EventDispatcher y Curl correctamente
    return new \Solarium\Client(
        new \Solarium\Core\Client\Adapter\Curl(), 
        new \Symfony\Component\EventDispatcher\EventDispatcher(), 
        $config
    );
}