<?php
/**
 * magevm
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * PHP version 5.5
 */

namespace magevm;

use \TechDivision\Server\Sockets\StreamSocket;

/**
 * Class MageWorker
 *
 * @category  Webserver
 * @package   TechDivision_MageModule
 * @copyright 2014 TechDivision GmbH <info@techdivision.com>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/techdivision/TechDivision_MageModule
 * @author    Johann Zelger <jz@techdivision.com>
 * @author    Markus Stockbauer <ms@techdivision.com>
 * @author    Vadim Justus <v.justus@techdivision.com>
 */
class MageWorker extends \Thread
{

    protected $connectionResource;

    /**
     * @var string
     */
    private $name = '';

    /**
     * @var \magevm\SessionContainer
     */
    public $sessionContainer;

    /**
     * @var bool
     */
    private $sessionContainerMode = true;

    /**
     * @var string
     */
    private $logFile;

    /**
     * @var array
     */
    private $requestData = array();

    /**
     * @var string
     */
    private $webroot = '';

    /**
     * @var string
     */
    private $domain = '';

    /**
     * @var string
     */
    private $port = '';

    /**
     * Constructor
     *
     * @param resource $connectionResource The connection resource
     */
    public function __construct($connectionResource)
    {
        $this->connectionResource = $connectionResource;
    }

    /**
     * @param string $name
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param bool $bool
     */
    public function setSessionContainerMode($bool)
    {
        $this->sessionContainerMode = (bool)$bool;
    }

    /**
     * @return bool
     */
    public function isSessionContainerMode()
    {
        return (bool)$this->sessionContainerMode;
    }

    /**
     * @param SessionContainer $sessionContainer
     */
    public function setSessionContainer(SessionContainer $sessionContainer)
    {
        $this->sessionContainer = $sessionContainer;
    }

    /**
     * @param string $filepath
     */
    public function setLogFile($filepath)
    {
        $this->logFile = $filepath;
    }

    /**
     * @return string
     */
    public function getLogFile()
    {
        return $this->logFile;
    }

    /**
     * @param string $path
     */
    public function setWebroot($path)
    {
        $this->webroot = $path;
    }

    /**
     * @return string
     */
    protected function getWebroot()
    {
        return $this->webroot;
    }


    /**
     * @param string $string
     */
    public function setDomain($string)
    {
        $this->domain = $string;
    }

    /**
     * @return string
     */
    protected function getDomain()
    {
        return $this->domain;
    }


    /**
     * @param string $port
     */
    public function setPort($port)
    {
        $this->port = $port;
    }

    /**
     * @return string
     */
    protected function getPort()
    {
        return $this->port;
    }

    /**
     * Parse raw HTTP request data
     *
     * Pass in $a_data as an array. This is done by reference to avoid copying
     * the data around too much.
     *
     * Any files found in the request will be added by their field name to the
     * $data['files'] array.
     *
     * @param array $a_data Empty array to fill with data
     * @param string $input
     *
     * @param array $headers
     *
     * @return  array  Associative array of request data
     *
     * @link http://www.chlab.ch/blog/archives/php/manually-parse-raw-http-data-php
     */
    protected function parse_raw_http_request(array &$a_data, $input, $headers)
    {
        // grab multipart boundary from content type header
        preg_match('/boundary=(.*)$/', $headers['Content-Type'], $matches);

        // content type is probably regular form-encoded
        if (!count($matches)) {
            // we expect regular puts to containt a query string containing data
            parse_str(urldecode($input), $a_data);
            return $a_data;
        }

        $boundary = $matches[1];

        // split content by boundary and get rid of last -- element
        $a_blocks = preg_split("/-+$boundary/", $input);
        array_pop($a_blocks);

        // loop data blocks
        foreach ($a_blocks as $id => $block) {
            if (empty($block)) {
                continue;
            }

            // you'll have to var_dump $block to understand this and maybe replace \n or \r with a visibile char

            // parse uploaded files
            if (strpos($block, 'application/octet-stream') !== false) {
                // match "name", then everything after "stream" (optional) except for prepending newlines
                preg_match("/name=\"([^\"]*)\".*stream[\n|\r]+([^\n\r].*)?$/s", $block, $matches);
                $a_data['files'][$matches[1]] = $matches[2];
            } // parse all other fields
            else {
                // match "name" and optional value in between newline sequences
                preg_match('/name=\"([^\"]*)\"[\n|\r]+([^\n\r].*)?\r$/s', $block, $matches);
                $a_data[$matches[1]] = $matches[2];
            }
        }
    }

    /**
     * @param string|array $msg
     */
    public function log($msg)
    {
        if (is_array($msg)) {
            $this->log('Array: ' . count($msg));
            foreach ($msg as $key => $value) {
                $this->log(sprintf(' - %s: %s', $key, $value));
            }
            return;
        }

        $message =  date('Y-m-d H:i:s') . ' - ' . str_pad($this->getName() . ':', 25, ' ') . $msg . PHP_EOL;
        error_log($message, 3, $this->getLogFile());
    }

    /**
     * @return void
     */
    protected function setGlobalServerVars()
    {
        $_SERVER                    = array();
        $_SERVER["REQUEST_URI"]     = "/index.php";
        $_SERVER["SCRIPT_NAME"]     = "/index.php";
        $_SERVER["SCRIPT_FILENAME"] = $this->getWebroot() . "/index.php";
        $_SERVER["HTTP_HOST"]       = $this->getDomain() . ":" . $this->getPort();
    }

    /**
     * @return void
     */
    protected function warmUpMagentoInstance()
    {
        $this->log('Warm up Magento instance.');

        appserver_set_headers_sent(false);
        $magentoApp = $this->getMagentoApp();

        ob_start();
        $magentoApp->run(
            array(
                'scope_code' => 'default',
                'scope_type' => 'store',
                'options'    => array(),
            )
        );
        ob_end_clean();

        session_write_close();
        appserver_session_init();
    }

    /**
     * @return \Mage_Core_Model_App
     */
    protected function getMagentoApp()
    {
        return \Mage::app();
    }

    /**
     * @return void
     */
    protected function cleanMagentoInstanceRegistry()
    {
        // the registry keys to preserve from cleaning up after every magento app request
        $registryPreserveKeys = array(
            'original_include_path',
            '_singleton/core/resource',
            '_resource_singleton/core/website',
            '_resource_singleton/core/store_group',
            '_resource_singleton/core/store',
            '_resource_helper/core',
            //'_singleton/core/cookie',
            //'application_params',
            '_resource_singleton/core/resource',
            '_helper/core',
            '_singleton/core/resource_setup_query_modifier',
            'controller',
            '_singleton/Mage_Cms_Controller_Router',
            '_singleton/core/factory',
            '_resource_singleton/core/url_rewrite',
            //'_singleton/core/layout',
            '_helper/core/http',
            //'_singleton/core/session',
            '_singleton/core/design_config',
            '_singleton/core/design_fallback',
            '_singleton/core/design_package',
            '_singleton/core/design',
            '_resource_singleton/core/design',
            '_singleton/core/translate',
            '_singleton/core/locale',
            '_singleton/core/translate_inline',
            '_resource_singleton/core/translate',
            '_singleton/Mage_Core_Model_Domainpolicy',
            '_singleton/xmlconnect/observer',
            '_helper/core/string',
            '_singleton/log/visitor',
            '_resource_singleton/log/visitor',
            '_singleton/pagecache/observer',
            '_helper/pagecache',
            '_singleton/persistent/observer',
            '_helper/persistent',
            //'_helper/persistent/session',
            //'_resource_singleton/persistent/session',
            //'_singleton/persistent/observer_session',
            '_singleton/customer/config_share',
            //'_singleton/customer/session',
            '_helper/cms/page',
            '_singleton/cms/page',
            '_resource_singleton/cms/page',
            '_helper/page/layout',
            '_singleton/page/config',
            '_helper/page',
            '_singleton/customer/observer',
            '_helper/customer',
            '_helper/catalog',
            '_helper/catalog/map',
            '_helper/catalogsearch',

            '_helper/checkout/cart',
            '_singleton/checkout/cart',
            //'_singleton/checkout/session',
            '_helper/checkout',
            //'_singleton/catalog/session',
            '_helper/core/file_storage_database',
            '_helper/core/js',
            '_helper/directory',
            '_helper/googleanalytics',
            '_helper/adminhtml',
            '_helper/widget',
            '_singleton/core/url',
            '_singleton/catalog/observer',
            '_helper/catalog/category',
            '_helper/catalog/category_flat',
            '_singleton/eav/config',
            '_resource_singleton/eav/entity_type',
            //'_resource_singleton/catalog/category',
            '_singleton/catalog/factory',
            //'_resource_singleton/catalog/attribute',

            '_helper/catalog/category_url_rewrite',
            '_resource_helper/eav',
            // '_singleton/catalog/layer',
            '_resource_helper/catalog',
            '_helper/wishlist',
            '_singleton/paypal/config',
            '_helper/cms',
            '_resource_singleton/tag/tag',

            //'_singleton/reports/session',
            '_resource_singleton/reports/product_index_compared',
            '_helper/catalog/product_flat',
            '_resource_singleton/catalog/product',
            '_singleton/catalog/product_visibility',
            '_resource_singleton/reports/product_index_viewed',
            '_helper/catalog/product_compare',
            '_resource_singleton/cms/block',
            '_helper/core/cookie',
            '_singleton/core/date'
        );

        $reflect = new \ReflectionClass('\Mage');
        $props   = $reflect->getStaticProperties();

        $registryCleanKeys = array_keys($props['_registry']);

        // cleanup mage registry
        foreach ($registryCleanKeys as $registryCleanKey) {
            if (!in_array($registryCleanKey, $registryPreserveKeys)) {
                \Mage::unregister($registryCleanKey);
            }
        }
    }

    protected function cleanSuperglobals()
    {
        // make sure the superglobals are clean before we start
        $_COOKIE = array();
        unset($_SESSION);
    }

    /**
     * @param StreamSocket $request
     */
    protected function parseRequest(\TechDivision\Server\Sockets\StreamSocket $request)
    {
        $firstLine = explode(' ', $request->readLine());

        $this->requestData = array(
            'method' => $firstLine[0],
            'uri' => $firstLine[1],
            'protocol' => $firstLine[2],
            'headers' => $this->parseRequestHeaders($request),
        );

        // update the request method in the global $_SERVER var
        $_SERVER['REQUEST_METHOD'] = $this->getRequestData('method');

        $this->prepareGlobalCookies();
        $this->prepareGlobalSession();
    }

    /**
     * iterate all cookies and set them in globals if exists
     * @return void
     */
    private function prepareGlobalCookies()
    {
        foreach (explode(';', $this->getRequestHeader('Cookie')) as $cookieLine) {
            list ($key, $value) = explode('=', $cookieLine);
            $_COOKIE[trim($key)] = trim($value);
        }
    }

    /**
     * prepare session id and session data
     * @return void
     */
    private function prepareGlobalSession()
    {
        if (isset($_COOKIE['frontend']) && strlen($_COOKIE['frontend'])) {
            session_id($_COOKIE['frontend']);

            if ($this->isSessionContainerMode()
                && $sessionData = $this->sessionContainer->getData(session_id())
            ) {
                $_SESSION = $sessionData;
            }
        }
    }

    /**
     * @param StreamSocket $request
     * @return array
     * @throws \TechDivision\Server\Sockets\SocketReadTimeoutException
     */
    private function parseRequestHeaders(\TechDivision\Server\Sockets\StreamSocket $request)
    {
        // readin headers
        $headers = array();
        while (($line = $request->readLine()) !== "\r\n") {
            list($headerKey, $headerValue) = explode(': ', trim($line));
            $headers[$headerKey] = $headerValue;
        }
        return $headers;
    }

    /**
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    protected function getRequestData($key, $default = null)
    {
        if (isset($this->requestData[$key])) {
            return $this->requestData[$key];
        }
        return $default;
    }

    /**
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    protected function getRequestHeader($key, $default = null)
    {
        $header = $this->getRequestData('headers');

        if (isset($header[$key])) {
            return $header[$key];
        }
        return $default;
    }

    /**
     * @param StreamSocket $request
     * @throws \TechDivision\Server\Sockets\SocketReadException
     * @throws \TechDivision\Server\Sockets\SocketReadTimeoutException
     */
    protected function processPost(\TechDivision\Server\Sockets\StreamSocket $request)
    {
        $httpMethod = $this->getRequestData('method');

        if ($httpMethod === 'POST') {
            $contentLength = $this->getRequestHeader('Content-Length', 0);
            if ($contentLength > 0) {
                $bodyContent = $request->read($contentLength);

                if (strpos($this->getRequestHeader('Content-Type'), 'multipart/form-data') !== false) {
                    $this->parse_raw_http_request($_POST, $bodyContent, $this->getRequestData('headers'));
                } else {
                    parse_str(urldecode($bodyContent), $_POST);
                }
            }
        }
    }

    protected function executeRequest(\TechDivision\Server\Sockets\StreamSocket $request)
    {
        $httpUri = $this->getRequestData('uri');

        if (strpos($httpUri, '/index.php') === false) {
            // output serving static content
            $request->copyStream(fopen(WEBROOT . $httpUri, 'r'));
        }
        else {
            $this->processPost($request);

            $appRequest  = new \Mage_Core_Controller_Request_Http();
            $appResponse = new \Mage_Core_Controller_Response_Http();
            $appRequest->setRequestUri($httpUri);

            $magentoApp = $this->getMagentoApp();
            $magentoApp->setRequest($appRequest);
            $magentoApp->setResponse($appResponse);

            ob_start();
            appserver_set_headers_sent(false);

            $this->log("Run Magento app");
            $start = microtime(true);
            $magentoApp->run(
                array(
                    'scope_code' => 'default',
                    'scope_type' => 'store',
                    'options'    => array(),
                )
            );
            $duration = microtime(true) - $start;
            $this->log(sprintf('Finish Magento app run in %.6f s', $duration));

            // build up res headers
            $resHeaders = array(
                "Server"         => "MageServer/0.1.0 (PHP 5.5.10)",
                "Connection"     => "Close",
                "Content-Length" => ob_get_length(),
                "X-Powered-By"   => "MageWorker",
                "Expires"        => "Thu, 19 Nov 1981 08:52:00 GMT",
                "Cache-Control"  => "no-store, no-cache, must-revalidate, post-check=0, pre-check=0",
                "Pragma"         => "no-cache",
                "Content-Type"   => "text/html; charset=UTF-8",
                "Date"           => "Sat, 17 May 14 16:44:40 +0000"
            );

            $headerStr          = '';
            $headerSetCookieStr = '';

            foreach (appserver_get_headers(true) as $resHeader) {
                list($resHeaderKey, $resHeaderValue) = explode(': ', $resHeader);
                // set cookie stuff
                if (strtolower($resHeaderKey) === 'set-cookie') {
                    $headerSetCookieStr .= $resHeaderKey . ': ' . $resHeaderValue . "\r\n";
                } else {
                    $resHeaders[$resHeaderKey] = $resHeaderValue;
                }
            }

            // generate header string
            foreach ($resHeaders as $resHeaderKey => $resHeaderValue) {
                $headerStr .= $resHeaderKey . ': ' . $resHeaderValue . "\r\n";
            }

            $request->write("HTTP/1.1 " . appserver_get_http_response_code() . "\r\n");
            $request->write($headerStr . $headerSetCookieStr);
            $request->write("\r\n" . ob_get_contents());

            if ($this->isSessionContainerMode() && isset($_SESSION)) {
                $this->log(sprintf('Write session data to session handler with id %s.', session_id()));
                $this->sessionContainer->setData(session_id(), $_SESSION);
            }

            ob_end_clean();
        }

    }

    /**
     * Runs the vm
     *
     * @return void
     */
    public function run()
    {
        $this->setGlobalServerVars();
        $this->warmUpMagentoInstance();

        $connection = StreamSocket::getInstance($this->connectionResource);

        // go for a loop while accepting clients
        do {
            $this->setGlobalServerVars();
            $this->cleanSuperglobals();
            $this->cleanMagentoInstanceRegistry();

            if ($client = $connection->accept()) {
                try {
                    // accept client connection

                    $this->parseRequest($client);
                    $this->executeRequest($client);

                    $client->close();
                    session_write_close();
                    appserver_session_init();
                } catch (\Exception $e) {
                    $client->write($e);
                    $client->close();
                }
            }
        } while (1);
    }
}