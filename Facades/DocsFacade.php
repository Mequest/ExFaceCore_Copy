<?php
namespace exface\Core\Facades;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use kabachello\FileRoute\FileRouteMiddleware;
use Psr\Http\Message\UriInterface;
use kabachello\FileRoute\Templates\PlaceholderFileTemplate;
use exface\Core\Facades\AbstractHttpFacade\NotFoundHandler;
use exface\Core\DataTypes\StringDataType;
use exface\Core\CommonLogic\Filemanager;
use function GuzzleHttp\Psr7\stream_for;
use exface\Core\Facades\DocsFacade\MarkdownDocsReader;
use exface\Core\Facades\DocsFacade\Middleware\AppUrlRewriterMiddleware;
use exface\Core\Facades\AbstractHttpFacade\HttpRequestHandler;
use exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade;
use DOMDocument;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use axenox\PDFPrinter\Interfaces\Actions\iCreatePdf;
use axenox\PDFPrinter\Actions\Traits\iCreatePdfTrait;

/**
 *
 * @author Andrej Kabachnik
 *
 */
class DocsFacade extends AbstractHttpFacade implements iCreatePdf
{
    use iCreatePdfTrait;
    
    private $processedLinks = [];
    private $processedLinksKey = 0;
    private $dompdf = null;
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade::createResponse()
     */
    protected function createResponse(ServerRequestInterface $request) : ResponseInterface
    {
        $handler = new HttpRequestHandler(new NotFoundHandler());
        
        // Add URL rewriter: it will take care of URLs after the content had been generated by the router
        $handler->add(new AppUrlRewriterMiddleware($this));
        
        $requestUri = $request->getUri();
        $baseUrl = StringDataType::substringBefore($requestUri->getPath(), '/' . $this->buildUrlToFacade(true), '');
        $baseUrl = $requestUri->getScheme() . '://' . $requestUri->getAuthority() . $baseUrl;
        
        $baseRewriteRules = $this->getWorkbench()->getConfig()->getOption('FACADES.DOCSFACADE.BASE_URL_REWRITE');
        if (! $baseRewriteRules->isEmpty()) {
            foreach ($baseRewriteRules->getPropertiesAll() as $pattern => $replace) {
                $baseUrl = preg_replace($pattern, $replace, $baseUrl);
            }
        }
        
        // Add router middleware
        $matcher = function(UriInterface $uri) {
            $path = $uri->getPath();
            $url = StringDataType::substringAfter($path, '/' . $this->buildUrlToFacade(true), '');
            $url = ltrim($url, "/");
            $url = urldecode($url);
            if ($q = $uri->getQuery()) {
                $url .= '?' . $q;
            }
            return $url;
        };
        
        $reader = new MarkdownDocsReader($this->getWorkbench());
        
        switch (true) {
            case ($request->getQueryParams()['render'] === 'pdf'):
                $templatePath = Filemanager::pathJoin([$this->getApp()->getDirectoryAbsolutePath(), 'Facades/DocsFacade/templatePDF.html']);
                $template = new PlaceholderFileTemplate($templatePath, $baseUrl . '/' . $this->buildUrlToFacade(true));
                $handler->add(new FileRouteMiddleware($matcher, $this->getWorkbench()->filemanager()->getPathToVendorFolder(), $reader, $template));
                
                $response = $handler->handle($request);
                $htmlString = $response->getBody()->__toString();
                // Generate filename from html title of first docu page
                preg_match('/<title>(.*?)<\/title>/is', $htmlString, $matches);
                $filename = $matches[1] . '.pdf';
                // Find all links in first docu page
                $linksArray = $this->findLinksInHtml($htmlString);
                // Create temp file for saving entire html content
                $tempFilePath = tempnam(sys_get_temp_dir(), 'combined_content_');
                
                $this->processLinks($tempFilePath, $linksArray);
                
                // attach print function to end of html to show print window when accessing the HTML
                $printString = 
                '<script type="text/javascript">
                    window.onload = function() {
                    window.print();
                    };
                </script>';
                file_put_contents($tempFilePath, $printString, FILE_APPEND | LOCK_EX);
                
                $combinedBodyContent = file_get_contents($tempFilePath);
                // Clean up the temporary file
                unlink($tempFilePath);
                
                // Parse the body content of all links at the end of the body html tag of the first html doc page
                $bodyCloseTagPosition = stripos($htmlString, '</body>');
                $htmlString = substr_replace($htmlString, $combinedBodyContent, $bodyCloseTagPosition, 0);

                $response = new Response(200, [], $htmlString);
                $response = $response->withHeader('Content-Type', 'text/html');
                break;
                
            case ($request->getQueryParams()['markdown'] === 'true'):
                $templatePath = Filemanager::pathJoin([$this->getApp()->getDirectoryAbsolutePath(), 'Facades/DocsFacade/templatePDF.html']);
                $template = new PlaceholderFileTemplate($templatePath, $baseUrl . '/' . $this->buildUrlToFacade(true));
                $template->setBreadcrumbsRootName('Documentation');
                $handler->add(new FileRouteMiddleware($matcher, $this->getWorkbench()->filemanager()->getPathToVendorFolder(), $reader, $template));
                $response = $handler->handle($request);
                break;
                
            default:
                $templatePath = Filemanager::pathJoin([$this->getApp()->getDirectoryAbsolutePath(), 'Facades/DocsFacade/template.html']);
                $template = new PlaceholderFileTemplate($templatePath, $baseUrl . '/' . $this->buildUrlToFacade(true));
                $template->setBreadcrumbsRootName('Documentation');
                $handler->add(new FileRouteMiddleware($matcher, $this->getWorkbench()->filemanager()->getPathToVendorFolder(), $reader, $template));
                $response = $handler->handle($request);
                break;
        }
        
        foreach ($this->buildHeadersCommon() as $header => $val) {
            $response = $response->withHeader($header, $val);
        }
        return $response;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade::buildHeadersCommon()
     */
    protected function buildHeadersCommon() : array
    {
        $facadeHeaders = array_filter($this->getConfig()->getOption('FACADES.DOCSFACADE.HEADERS.COMMON')->toArray());
        $commonHeaders = parent::buildHeadersCommon();
        return array_merge($commonHeaders, $facadeHeaders);
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Facades\AbstractHttpFacade\AbstractHttpFacade::getUrlRouteDefault()
     */
    public function getUrlRouteDefault(): string
    {
        return 'api/docs';
    }
    
    /**
     * Recursivlely add all html of all docu links to a tempFile
     * @param string $tempFilePath
     * @param array $linksArray
     */
    protected function processLinks(string $tempFilePath, array $linksArray) {
        foreach ($linksArray as $link) {
            // Only process links that are markdown files and have not been processed before
            if (str_ends_with($link, '.md') && !in_array($link, $this->processedLinks)) {
                $this->processedLinks[$this->processedLinksKey] = $link;
                $this->processedLinksKey = $this->processedLinksKey +1;

                $linkRequest = new ServerRequest('GET', $link);
                $linkRequest = $linkRequest->withQueryParams(['markdown' => 'true']);
                $linkResponse = $this->createResponse($linkRequest);
                $htmlString = $linkResponse->getBody()->__toString();

                // Write the body content to the temporary file
                file_put_contents($tempFilePath, $htmlString, FILE_APPEND | LOCK_EX);
                
                $linksArrayRecursive = $this->findLinksInHTML($htmlString);
                if (!empty($linksArrayRecursive)) {
                    $this->processLinks($tempFilePath, $linksArrayRecursive);
                }
            }
        }
    }
    
    /**
     * Returns bodyContent as string from a html string
     * @param string $html
     * @return string
     */
    protected function getBodyContent(string $html) : string {
        $bodyContent = '';
        if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $html, $matches)) {
            $bodyContent = $matches[1];
        }
        return $bodyContent;
    }
    
    /**
     * Returns all links inside of a html string as an array
     * @param string $html
     * @return array
     */
    protected function findLinksInHtml(string $html) : array {
        $dom = new DOMDocument();
        // Suppress errors due to malformed HTML
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        // Clear the errors
        libxml_clear_errors();
        
        $links = $dom->getElementsByTagName('a');
        $extractedLinks = [];
        
        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            if ($href) {
                $extractedLinks[] = $href;
            }
        } 
        return $extractedLinks;
    }
    
}