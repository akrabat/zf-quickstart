<?php

namespace Zf2\Http;

use Zf2\Stdlib\Request as BaseRequest,
    Zf2\Stdlib\Parameters as HttpParameters;

class Request extends BaseRequest implements HttpRequest
{
    protected $queryParams;
    protected $postParams;
    protected $cookieParams;
    protected $fileParams;
    protected $serverParams;
    protected $envParams;
    protected $headers;
    protected $rawBody;

    /* mutators for various superglobals */
    public function setQuery(HttpParameters $query)
    {
        $this->queryParams = $query;
        return $this;
    }

    public function setPost(HttpParameters $post)
    {
        $this->postParams = $post;
        return $this;
    }

    public function setCookies(HttpParameters $cookies)
    {
        $this->cookieParams = $cookies;
        return $this;
    }

    /**
     * Set files parameters
     * 
     * @todo   Maybe separate this into its own component?
     * @param  HttpParameters $files 
     * @return Request
     */
    public function setFiles(HttpParameters $files)
    {
        $this->fileParams = $files;
        return $this;
    }

    public function setServer(HttpParameters $server)
    {
        $this->serverParams = $server;
        return $this;
    }

    public function setEnv(HttpParameters $env)
    {
        $this->envParams = $env;
        return $this;
    }

    public function setHeaders(HttpRequestHeaders $headers)
    {
        $this->headers = $headers;
        return $this;
    }

    public function setRawBody($string)
    {
        $this->rawBody = $string;
        return $this;
    }

    /* accessors for various superglobals */
    public function query($name = null, $default = null)
    {
        if (null === $this->queryParams) {
            $this->setQuery(new Parameters($_GET));
        }

        if (null !== $name) {
            return $this->queryParams[$name] ?: $default;
        }

        return $this->queryParams;
    }

    public function post($name = null, $default = null)
    {
        if (null === $this->postParams) {
            $this->setPost(new Parameters($_POST));
        }

        if (null !== $name) {
            return $this->postParams[$name] ?: $default;
        }

        return $this->postParams;
    }

    public function cookie($name = null, $default = null)
    {
        if (null === $this->cookieParams) {
            $this->setCookies(new Parameters($_COOKIE));
        }

        if (null !== $name) {
            return $this->cookieParams[$name] ?: $default;
        }

        return $this->cookieParams;
    }

    public function file($name = null)
    {
        if (null === $this->fileParams) {
            $this->setFiles(new Parameters($_FILES));
        }

        if (null !== $name) {
            return $this->fileParams[$name];
        }

        return $this->fileParams;
    }

    public function server($name = null, $default = null)
    {
        if (null === $this->serverParams) {
            $this->setServer(new Parameters($_SERVER));
        }

        if (null !== $name) {
            return $this->serverParams[strtoupper($name)] ?: $default;
        }

        return $this->serverParams;
    }

    public function env($name = null, $default = null)
    {
        if (null === $this->envParams) {
            $this->setEnv(new Parameters($_ENV));
        }

        if (null !== $name) {
            return $this->envParams[$name] ?: $default;
        }

        return $this->envParams;
    }

    public function headers($name = null)
    {
        if (null === $this->headers) {
            $this->setHeaders($this->getServerHeaders());
        }

        if (null !== $name) {
            return $this->headers->get($name);
        }

        return $this->headers;
    }

    protected function getServerHeaders()
    {
        $headers = new RequestHeaders();
        $server  = $this->server();
        foreach ($server as $key => $value) {
            switch (strtoupper($key)) {
                case 'REQUEST_URI':
                    $headers->setUri($value);
                    break;
                case 'REQUEST_METHOD':
                    $headers->setMethod($value);
                    break;
                case 'SERVER_PROTOCOL':
                    if (preg_match('#^HTTP/(?P<version>.*)$#', $value, $matches)) {
                        $headers->setProtocolVersion($matches['version']);
                    }
                    break;
                case 'CONTENT_TYPE':
                case 'CONTENT_LENGTH':
                    $headers->addHeader($key, $value);
                    break;
                default:
                    // Test if we have a "HTTP_" key, indicating a generic header
                    if (preg_match('/^http_(?P<type>.*)$/i', $key, $matches)) {
                        $type = $matches['type'];
                        $headers->addHeader($type, $value);
                    }
                    break;
            }
        }
        return $headers;
    }

    public function getContent()
    {
        if (null === $this->rawBody) {
            if ($this->isPost() || $this->isPut()) {
                $this->setRawBody(file_get_contents('php://input'));
            }
        }
        return $this->rawBody;
    }

    /* URI decomposition */
    public function getRequestUri()
    {
        return $this->headers()->getUri();
    }

    public function getScheme()
    {
        $https = $this->server('HTTPS', false);
        $scheme = empty($https) ? 'http' : 'https';
        return $scheme;
    }

    public function getHttpHost()
    {
        return $this->server('HTTP_HOST');
    }

    public function getPort()
    {
        return $this->server('SERVER_PORT');
    }

    public function getPathInfo()
    {
        $requestUri = $this->getRequestUri();
        $pathInfo = $requestUri;

        $baseUrl = $this->getBaseUrl();
        if (!empty($baseUrl)) {
            if (strpos($requestUri, $baseUrl) === 0) {
                $pathInfo = substr($requestUri, strlen($baseUrl));
            }
        }

        return parse_url($pathInfo, PHP_URL_PATH);
    }


    /* base path/url/script name info */
    public function getBasePath()
    {
    }

    public function getBaseUrl()
    {
        $filename = (isset($_SERVER['SCRIPT_FILENAME'])) ? basename($_SERVER['SCRIPT_FILENAME']) : '';

        if (isset($_SERVER['SCRIPT_NAME']) && basename($_SERVER['SCRIPT_NAME']) === $filename) {
            $baseUrl = $_SERVER['SCRIPT_NAME'];
        } elseif (isset($_SERVER['PHP_SELF']) && basename($_SERVER['PHP_SELF']) === $filename) {
            $baseUrl = $_SERVER['PHP_SELF'];
        } elseif (isset($_SERVER['ORIG_SCRIPT_NAME']) && basename($_SERVER['ORIG_SCRIPT_NAME']) === $filename) {
            $baseUrl = $_SERVER['ORIG_SCRIPT_NAME']; // 1and1 shared hosting compatibility
        } else {
            // Backtrack up the script_filename to find the portion matching
            // php_self
            $path    = isset($_SERVER['PHP_SELF']) ? $_SERVER['PHP_SELF'] : '';
            $file    = isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : '';
            $segs    = explode('/', trim($file, '/'));
            $segs    = array_reverse($segs);
            $index   = 0;
            $last    = count($segs);
            $baseUrl = '';
            do {
                $seg     = $segs[$index];
                $baseUrl = '/' . $seg . $baseUrl;
                ++$index;
            } while (($last > $index) && (false !== ($pos = strpos($path, $baseUrl))) && (0 != $pos));
        }

        // Does the baseUrl have anything in common with the request_uri?
        $requestUri = $this->getRequestUri();

        if (0 === strpos($requestUri, $baseUrl)) {
            // full $baseUrl matches
            return $baseUrl;
        }

        if (0 === strpos($requestUri, dirname($baseUrl))) {
            // directory portion of $baseUrl matches
            $baseUrl = rtrim(dirname($baseUrl), '/');
            return $baseUrl;
        }

        $truncatedRequestUri = $requestUri;
        if (($pos = strpos($requestUri, '?')) !== false) {
            $truncatedRequestUri = substr($requestUri, 0, $pos);
        }

        $basename = basename($baseUrl);
        if (empty($basename) || !strpos($truncatedRequestUri, $basename)) {
            // no match whatsoever; set it blank
            $baseUrl = '';
            return $baseUrl;
        }

        // If using mod_rewrite or ISAPI_Rewrite strip the script filename
        // out of baseUrl. $pos !== 0 makes sure it is not matching a value
        // from PATH_INFO or QUERY_STRING
        if ((strlen($requestUri) >= strlen($baseUrl))
            && ((false !== ($pos = strpos($requestUri, $baseUrl))) && ($pos !== 0)))
        {
            $baseUrl = substr($requestUri, 0, $pos + strlen($baseUrl));
        }

        return $baseUrl;
    }

    public function getScriptName()
    {
    }


    /* capabilities */
    public function getMethod()
    {
        return $this->headers()->getMethod();
    }

    public function setMethod($method)
    {
        $this->headers()->setMethod($method);
        return $this;
    }

    public function getETags()
    {
    }

    public function getPreferredLanguage(array $locales = null)
    {
    }

    public function getLanguages()
    {
    }

    public function getCharsets()
    {
    }

    public function getAcceptableContentTypes()
    {
    }

    public function isNoCache()
    {
    }

    public function isFlashRequest()
    {
        $headers = $this->headers();
        if (!$headers->has('User-Agent')) {
            return false;
        }
        foreach ($headers->get('User-Agent') as $header) {
            if (strstr(strtolower($header->getValue()), ' flash')) {
                return true;
            }
        }
        return false;
    }

    public function isSecure()
    {
        return (bool) $this->server('https', false);
    }

    public function isXmlHttpRequest()
    {
        $headers = $this->headers();
        if ($headers->has('X-Requested-With') && 'XMLHttpRequest' == $headers->get('X-Requested-With')->top()->getValue()) {
            return true;
        }
        return false;
    }


    /* potential method tests */
    public function isDelete()
    {
        return ('DELETE' === $this->headers()->getMethod());
    }

    public function isGet()
    {
        return ('GET' === $this->headers()->getMethod());
    }

    public function isHead()
    {
        return ('HEAD' === $this->headers()->getMethod());
    }

    public function isOptions()
    {
        return ('OPTIONS' === $this->headers()->getMethod());
    }

    public function isPost()
    {
        return ('POST' === $this->headers()->getMethod());
    }

    public function isPut()
    {
        return ('PUT' === $this->headers()->getMethod());
    }


    /* creational capabilities */
    // returns full URI string: scheme, host, port, base URL, path info, and query string
    public function getUri()
    {
    }

    public static function create($uri, $method = 'get' /** .. more args */)
    {
    }

    // not sure if this needs to be in interface
    public function __clone()
    {
    }

    /* Create HTTP request */
    public function __toString()
    {
        return $this->headers() . "\r\n" . $this->getContent();
    }

    /* Create object from "document" */
    public function fromString($string)
    {
        $segments = preg_split("/\r\n\r\n/", $string, 2);

        // Populate headers
        $this->headers()->fromString($segments[0]);

        // Populate raw body, if content found
        if (2 === count($segments)) {
            $this->setRawBody($segments[1]);
        } else {
            $this->setRawBody('');
        }

        return $this;
    }
}
