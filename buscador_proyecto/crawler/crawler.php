<?php
require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;
use Solarium\Client as SolrClient;
use Solarium\Core\Client\Adapter\Curl;
use Symfony\Component\EventDispatcher\EventDispatcher;

class SolrDeepCrawler
{
    private $solr;
    private $httpClient;
    private $visitedUrls = []; 
    private $maxDepth = 1; 

    public function __construct()
    {
        // 1. Configuración Solr
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
        $this->solr = new SolrClient(new Curl(), new EventDispatcher(), $config);

        // 2. Configuración Guzzle
        $this->httpClient = new Client([
            'verify' => false,
            'timeout' => 10, 
            'headers' => [
                // Es bueno simular un navegador real para evitar bloqueos en algunos sitios
                'User-Agent' => 'Mozilla/5.0 (compatible; MiBotEstudiantil/1.0)'
            ]
        ]);
    }

    public function run($seedUrls)
    {
        echo "--- INICIANDO CRAWLER GENÉRICO ---\n";
        foreach ($seedUrls as $url) {
            $this->processUrl($url, 0);
        }
    }

    private function processUrl($url, $currentDepth)
    {
        // Normalizar URL (quitar / al final para evitar duplicados tipo .com y .com/)
        $url = rtrim($url, '/');

        if (in_array($url, $this->visitedUrls)) {
            return;
        }
        $this->visitedUrls[] = $url;

        $indent = str_repeat("    ", $currentDepth);
        $etiqueta = ($currentDepth === 0) ? "[SEMILLA]" : "[NIVEL $currentDepth]";
        echo "$indent $etiqueta Procesando: $url \n";

        try {
            $response = $this->httpClient->get($url);
            $html = (string) $response->getBody();
            $crawler = new Crawler($html);

            // 1. Indexar el contenido actual
            $this->indexToSolr($url, $crawler);

            // 2. Buscar nuevos enlaces si no hemos llegado al límite
            if ($currentDepth < $this->maxDepth) {
                
                echo "$indent ... Buscando enlaces ...\n";

                // SELECTOR GENÉRICO: Cualquier enlace con href
                $links = $crawler->filter('a[href]')->each(function (Crawler $node) {
                    return $node->attr('href');
                });

                $links = array_unique($links);
                $count = 0; 

                // Detectar el dominio base para arreglar enlaces relativos
                // Ej: si estamos en https://www.bbc.com/news, el host es www.bbc.com
                $parsedUrl = parse_url($url);
                $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];

                foreach ($links as $link) {
                    $link = trim($link);

                    // --- FILTROS DE SEGURIDAD ---
                    if (empty($link)) continue;
                    if ($link[0] === '#') continue; // Anclas internas
                    if (strpos($link, 'javascript:') === 0) continue;
                    if (strpos($link, 'mailto:') === 0) continue;
                    if (strpos($link, 'tel:') === 0) continue;
                    
                    // Límite por página
                    if ($count >= 15) break;

                    // --- RECONSTRUCCIÓN DE URL ---
                    $fullUrl = $link;

                    // Caso 1: Enlace relativo a la raíz (ej: "/deportes")
                    if (strpos($link, '/') === 0) {
                        $fullUrl = $baseUrl . $link;
                    }
                    // Caso 2: Enlace relativo simple (ej: "articulo.html") - asume raíz para simplificar
                    elseif (strpos($link, 'http') === false) {
                        $fullUrl = $baseUrl . '/' . $link;
                    }
                    // Caso 3: URL Absoluta (ya tiene http), se deja igual.

                    $count++;
                    // Llamada recursiva
                    $this->processUrl($fullUrl, $currentDepth + 1);
                }
            }

        } catch (Exception $e) {
            echo "$indent Error procesando $url: " . $e->getMessage() . "\n";
        }
    }

    private function indexToSolr($url, Crawler $crawler)
    {
        try {
            $update = $this->solr->createUpdate();
            $doc = $update->createDocument();

            // Título: intenta h1, si no hay, usa title
            if ($crawler->filter('h1')->count() > 0) {
                $titulo = $crawler->filter('h1')->text();
            } elseif ($crawler->filter('title')->count() > 0) {
                $titulo = $crawler->filter('title')->text();
            } else {
                $titulo = 'Sin titulo';
            }
            
            $contenido = '';
            // SELECTOR GENÉRICO: Toma todos los párrafos del body
            // Esto funciona en Wikipedia, periódicos, blogs, etc.
            $crawler->filter('body p')->each(function (Crawler $node) use (&$contenido) {
                $contenido .= $node->text() . ' ';
            });

            // Si no encontró párrafos, intentar con divs de texto (fallback básico)
            if (strlen($contenido) < 50) {
                $contenido = substr($crawler->filter('body')->text(), 0, 1000);
            }

            // --- LIMPIEZA Y CODIFICACIÓN ---
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'UTF-8');
            $contenido = preg_replace('/[\x00-\x1F\x7F]/u', '', $contenido);
            $contenidoSeguro = mb_substr($contenido, 0, 30000, "UTF-8");

            $doc->id = md5($url);
            $doc->titulo = $titulo;
            $doc->contenido = $contenidoSeguro;
            $doc->url = $url;
            
            // Categorización simple
            $doc->categoria = (strpos(strtolower($contenido), 'tecnología') !== false) ? 'Tecnología' : 'General';

            $update->addDocument($doc);
            $update->addCommit();
            $this->solr->update($update);
            
            echo "    -> [SOLR] Indexado correctamente: " . substr($titulo, 0, 30) . "...\n";

        } catch (Exception $e) {
            echo "    -> [ERROR SOLR] No se pudo indexar $url: " . $e->getMessage() . "\n";
        }
    }
}

// --- EJECUCIÓN DE PRUEBA ---

$bot = new SolrDeepCrawler();

// Puedes probar mezclando sitios ahora
$urls_semilla = [
    'https://es.wikipedia.org/wiki/Internet', // Wikipedia
    // 'https://www.bbc.com/mundo',           // Noticias (descomentar para probar)
    // 'https://www.php.net/manual/es/intro-whatis.php' // Documentación técnica
];

$bot->run($urls_semilla);