<?php
require __DIR__ . '/../vendor/autoload.php';
// Asegúrate de que esta ruta sea correcta según tu estructura
require __DIR__ . '/../utils/preprocess.php';

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
    private $maxDepth = 2; // Aumentado a 2 para probar mejor la navegación profunda

    // Lista negra de palabras en la URL (Namespaces de MediaWiki y otros)
    private $urlBlacklist = [
        'Especial:', 'Special:', 
        'Wikipedia:', 'Project:',
        'Portal:', 
        'Ayuda:', 'Help:',
        'Usuario:', 'User:',
        'Discusión:', 'Talk:',
        'Archivo:', 'File:', 'Image:',
        'MediaWiki:',
        'Plantilla:', 'Template:',
        'Categoría:', 'Category:',
        'action=edit', 'action=history',
        'printable=yes', 'oldid='
    ];

    public function __construct()
    {
        // 1. Configuración Solr
        $config = [
            'endpoint' => [
                'localhost' => [
                    'host' => '127.0.0.1',
                    'port' => 8983,
                    'path' => '/solr/',
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
                'User-Agent' => 'Mozilla/5.0 (compatible; BuscadorProyectoBot/1.0)'
            ]
        ]);
    }

    public function run($seedUrls)
    {
        echo "--- INICIANDO CRAWLER CON FILTROS INTELIGENTES ---\n";
        foreach ($seedUrls as $url) {
            $this->processUrl($url, 0);
        }
    }

    private function processUrl($url, $currentDepth)
    {
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
                
                // Obtener dominio base de la URL actual para restringir la navegación
                $currentHost = parse_url($url, PHP_URL_HOST);

                // Selector: buscamos enlaces dentro del contenido principal para evitar menús
                // En Wikipedia, el contenido está en #bodyContent. Si no existe, usa 'body'
                $selectorContexto = $crawler->filter('#bodyContent')->count() > 0 ? '#bodyContent a[href]' : 'body a[href]';
                
                $links = $crawler->filter($selectorContexto)->each(function (Crawler $node) {
                    return $node->attr('href');
                });

                $links = array_unique($links);
                $count = 0; 

                $baseUrl = parse_url($url, PHP_URL_SCHEME) . '://' . $currentHost;

                foreach ($links as $link) {
                    $link = trim($link);

                    // Reconstrucción básica de URL absoluta
                    if (strpos($link, '/') === 0) {
                        $fullUrl = $baseUrl . $link;
                    } elseif (strpos($link, 'http') === 0) {
                        $fullUrl = $link;
                    } else {
                        // Ignorar enlaces "raros" o relativos complejos por ahora
                        continue;
                    }

                    // --- FILTRO MAESTRO ---
                    if (!$this->isValidLink($fullUrl, $currentHost)) {
                        continue;
                    }

                    // Límite por página para no saturar
                    if ($count >= 10) break;

                    $count++;
                    $this->processUrl($fullUrl, $currentDepth + 1);
                }
            }

        } catch (Exception $e) {
            echo "$indent [ERROR] $url: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Verifica si un enlace es válido para ser visitado
     */
    private function isValidLink($url, $allowedHost)
    {
        // 1. Validar que sea del mismo dominio (evita ir a facebook, ace.wikipedia, etc.)
        $linkHost = parse_url($url, PHP_URL_HOST);
        if ($linkHost !== $allowedHost) {
            return false;
        }

        // 2. Validar extensiones de archivos estáticos (evita descargar imágenes o pdfs grandes)
        if (preg_match('/\.(jpg|jpeg|png|gif|pdf|doc|docx|zip|rar|css|js)$/i', $url)) {
            return false;
        }

        // 3. Validar contra la Lista Negra (evita Especial:, Portal:, Login, etc.)
        foreach ($this->urlBlacklist as $blacklisted) {
            if (stripos($url, $blacklisted) !== false) {
                return false;
            }
        }

        return true;
    }

    private function indexToSolr($url, Crawler $crawler)
    {
        try {
            $update = $this->solr->createUpdate();
            $doc = $update->createDocument();

            // Título
            if ($crawler->filter('h1')->count() > 0) {
                $titulo = $crawler->filter('h1')->text();
            } elseif ($crawler->filter('title')->count() > 0) {
                $titulo = $crawler->filter('title')->text();
            } else {
                $titulo = 'Sin titulo';
            }
            
            // Extracción de contenido
            $contenidoHtml = '';
            $selectorTexto = $crawler->filter('#bodyContent p')->count() > 0 ? '#bodyContent p' : 'body p';
            
            $crawler->filter($selectorTexto)->each(function (Crawler $node) use (&$contenidoHtml) {
                $contenidoHtml .= $node->html() . ' ';
            });

            if (strlen($contenidoHtml) < 50) {
                $contenidoHtml = $crawler->filter('body')->html();
            }

            // --- PROCESAMIENTO ---
            $tokens = Preprocessor::process($contenidoHtml);
            $contenidoLimpio = implode(' ', $tokens);

            if (strlen($contenidoLimpio) < 100) {
                echo "    -> [SKIP] Contenido insuficiente ($titulo)\n";
                return;
            }

            $contenidoSeguro = mb_substr($contenidoLimpio, 0, 30000, "UTF-8");

            // --- AQUÍ ESTÁ LA CORRECCIÓN DE CAMPOS PARA SOLR ---
            $doc->id = md5($url);
            
            // Nombre definido en setup_solr.php -> Variable de tu crawler
            $doc->titulo_texto = $titulo;          // Antes tenías $doc->titulo
            $doc->desc_texto   = $contenidoSeguro; // Antes tenías $doc->contenido
            $doc->url_str      = $url;             // Antes tenías $doc->url
            
            // Omitimos categoria porque no lo definimos en el schema inicial
            // $doc->categoria = ... 

            $update->addDocument($doc);
            $update->addCommit();
            $this->solr->update($update);
            
            echo "    -> [SOLR] OK: " . substr($titulo, 0, 30) . "\n";

        } catch (Exception $e) {
            echo "    -> [ERROR SOLR] " . $e->getMessage() . "\n";
        }
    }
}

// --- EJECUCIÓN ---
$bot = new SolrDeepCrawler();
$urls_semilla = ['https://es.wikipedia.org/wiki/Internet'];
$bot->run($urls_semilla);