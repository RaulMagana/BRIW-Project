<?php
// 1. Cargamos la configuración centralizada
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../utils/preprocess.php';

use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;
use Solarium\Client as SolrClient;

class SolrDeepCrawler
{
    private $solr;
    private $httpClient;
    private $visitedUrls = []; 
    private $maxDepth = 2;
    private $urlBlacklist = [
        'Especial:', 'Special:', 'Wikipedia:', 'Project:', 'Portal:', 
        'Ayuda:', 'Help:', 'Usuario:', 'User:', 'Discusión:', 'Talk:',
        'Archivo:', 'File:', 'Image:', 'MediaWiki:', 'Plantilla:', 'Template:',
        'Categoría:', 'Category:', 'action=edit', 'action=history',
        'printable=yes', 'oldid='
    ];


    public function __construct($config)
    {
 
        $this->solr = getSolrClient($config);

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
        echo "--- INICIANDO CRAWLER (USANDO CONFIG.PHP) ---\n";
        foreach ($seedUrls as $url) {
            $this->processUrl($url, 0);
        }
    }

    private function processUrl($url, $currentDepth)
    {
        $url = rtrim($url, '/');
        if (in_array($url, $this->visitedUrls)) return;
        $this->visitedUrls[] = $url;

        $indent = str_repeat("    ", $currentDepth);
        $etiqueta = ($currentDepth === 0) ? "[SEMILLA]" : "[NIVEL $currentDepth]";
        echo "$indent $etiqueta Procesando: $url \n";

        try {
            $response = $this->httpClient->get($url);
            $html = (string) $response->getBody();
            $crawler = new Crawler($html);

            $this->indexToSolr($url, $crawler);

            if ($currentDepth < $this->maxDepth) {
                $currentHost = parse_url($url, PHP_URL_HOST);
                $selector = $crawler->filter('#bodyContent')->count() > 0 ? '#bodyContent a[href]' : 'body a[href]';
                
                $links = $crawler->filter($selector)->each(fn(Crawler $node) => $node->attr('href'));
                $links = array_unique($links);
                $count = 0; 
                $baseUrl = parse_url($url, PHP_URL_SCHEME) . '://' . $currentHost;

                foreach ($links as $link) {
                    $link = trim($link);
                    if (strpos($link, '/') === 0) $fullUrl = $baseUrl . $link;
                    elseif (strpos($link, 'http') === 0) $fullUrl = $link;
                    else continue;

                    if (!$this->isValidLink($fullUrl, $currentHost)) continue;
                    if ($count >= 6) break;
                    $count++;
                    $this->processUrl($fullUrl, $currentDepth + 1);
                }
            }
        } catch (Exception $e) {
            echo "$indent [ERROR WEB] $url: " . $e->getMessage() . "\n";
        }
    }

    private function isValidLink($url, $allowedHost)
    {
        $linkHost = parse_url($url, PHP_URL_HOST);
        if ($linkHost !== $allowedHost) return false;
        if (preg_match('/\.(jpg|jpeg|png|gif|pdf|doc|docx|zip|rar|css|js)$/i', $url)) return false;
        foreach ($this->urlBlacklist as $bl) {
            if (stripos($url, $bl) !== false) return false;
        }
        return true;
    }

    private function indexToSolr($url, Crawler $crawler)
    {
        try {
            $update = $this->solr->createUpdate();
            $doc = $update->createDocument();

            // 1. TÍTULO
            $titulo = 'Sin titulo';
            if ($crawler->filter('h1')->count() > 0) $titulo = $crawler->filter('h1')->text();
            elseif ($crawler->filter('title')->count() > 0) $titulo = $crawler->filter('title')->text();

            // 2. CONTENIDO (Protegido contra nulos)
            $contenidoHtml = '';
            $selector = $crawler->filter('#bodyContent p')->count() > 0 ? '#bodyContent p' : 'body p';
            
            $crawler->filter($selector)->each(function (Crawler $node) use (&$contenidoHtml) {
                $contenidoHtml .= ($node->html() ?? '') . ' ';
            });

            if (strlen($contenidoHtml) < 50) {
                $contenidoHtml = $crawler->filter('body')->html() ?? '';
            }

            // 3. LIMPIEZA
            $tokens = Preprocessor::process($contenidoHtml);
            $contenidoLimpio = implode(' ', $tokens);

            if (strlen($contenidoLimpio) < 100) {
                echo "    -> [SKIP] Contenido insuficiente\n";
                return;
            }

            $contenidoSeguro = mb_substr($contenidoLimpio, 0, 30000, "UTF-8");

            // =========================================================
            // LÓGICA DE AUTOMATIZACIÓN (El cambio importante)
            // =========================================================

            // A. CATEGORÍA AUTOMÁTICA (Desde Wikipedia)
            $categoriaDetectada = 'General'; // Valor por defecto

            // Buscamos el bloque de categorías al final de la página de Wikipedia
            if ($crawler->filter('#mw-normal-catlinks li a')->count() > 0) {
                // Obtenemos todas las categorías
                $cats = $crawler->filter('#mw-normal-catlinks li a')->each(function ($node) {
                    return $node->text();
                });

                // Buscamos la primera que sea útil (que no diga "Wikipedia" o "Anexo")
                foreach ($cats as $cat) {
                    if (stripos($cat, 'Wikipedia') === false && stripos($cat, 'Anexo') === false && stripos($cat, 'Artículos') === false) {
                        $categoriaDetectada = $cat; // ¡La encontramos!
                        break; 
                    }
                }
            }

            // B. NIVEL DE LECTURA (Cálculo matemático)
            $numPalabras = str_word_count($contenidoSeguro);
            $nivelLectura = 'Estándar';
            if ($numPalabras < 500) $nivelLectura = 'Breve';
            elseif ($numPalabras > 3000) $nivelLectura = 'Detallado';

            // C. AÑO (Detección en texto)
            $anioDetectado = date('Y'); 
            if (preg_match('/\b(19|20)\d{2}\b/', $contenidoSeguro, $matches)) {
                $anioDetectado = $matches[0];
            }

            // =========================================================
            // ASIGNACIÓN A SOLR
            // =========================================================
            
            $doc->id = md5($url);
            $doc->titulo    = $titulo;          
            $doc->contenido = $contenidoSeguro; 
            $doc->url       = $url;             
            
            // Campos para mostrar
            $doc->categoria = $categoriaDetectada;

            // Campos para filtros (Facetas)
            $doc->categoria_str = $categoriaDetectada; 
            $doc->lectura_str   = $nivelLectura;
            $doc->anio_str      = $anioDetectado;

            // Enviar
            $update->addDocument($doc);
            $update->addCommit();
            $this->solr->update($update);

            echo "    -> [SOLR] OK: " . substr($titulo, 0, 20) . "... | Cat: $categoriaDetectada | $anioDetectado\n";

        } catch (Exception $e) {
            echo "    -> [ERROR SOLR] " . $e->getMessage() . "\n";
        }
    }
}

// --- EJECUCIÓN ---
$bot = new SolrDeepCrawler($config); 
$urls_semilla = ['https://es.wikipedia.org/wiki/Internet'];
$bot->run($urls_semilla);