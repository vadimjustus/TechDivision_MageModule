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
 * PHP version 5
 *
 * @category  Webserver
 * @package   TechDivision_MageModule
 * @author    Johann Zelger <jz@techdivision.com>
 * @author    Markus Stockbauer <ms@techdivision.com>
 * @copyright 2014 TechDivision GmbH <info@techdivision.com>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/techdivision/TechDivision_MageModule
 */

namespace magevm;

define('BASEDIR', __DIR__ . DIRECTORY_SEPARATOR);
define('AUTOLOADER', '/opt/appserver/app/code' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php');
define('WEBROOT', '/var/www/magevm/');

use \TechDivision\Server\Sockets\StreamSocket;

/**
 * Class MageWorker
 *
 * @category  Webserver
 * @package   TechDivision_MageModule
 * @author    Johann Zelger <jz@techdivision.com>
 * @copyright 2014 TechDivision GmbH <info@techdivision.com>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/techdivision/TechDivision_MageModule
 */
class MageWorker extends \Thread
{

    protected $connectionResource;

    /**
     * Constructor
     *
     * @param resource $connectionResource The connection resource
     */
    public function __construct($connectionResource)
    {
        $this->connectionResource = $connectionResource;
        $this->start(PTHREADS_INHERIT_ALL | PTHREADS_ALLOW_HEADERS);
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
     * @param array  $a_data Empty array to fill with data
     * @param string $input
     *
     * @param array  $headers
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
     * Runs the vm
     *
     * @return void
     */
    public function run()
    {
        // set server var for magento request handling
        $_SERVER                    = array();
        $_SERVER["REQUEST_URI"]     = "/index.php";
        $_SERVER["SCRIPT_NAME"]     = "/index.php";
        $_SERVER["SCRIPT_FILENAME"] = WEBROOT . "index.php";
        $_SERVER["HTTP_HOST"]       = "magento.local:9080";

        require AUTOLOADER;
        require WEBROOT . 'app/Mage.php';

        appserver_set_headers_sent(false);
        $magentoApp = \Mage::app();
        ob_start();
        $magentoApp->run(
            array(
                 'scope_code' => 'default',
                 'scope_type' => 'store',
                 'options'    => array(),
            )
        );
        ob_end_clean();

        $connection = StreamSocket::getInstance($this->connectionResource);


        // go for a loop while accepting clients
        do {

            // set server var for magento request handling
            $_SERVER                    = array();
            $_SERVER["REQUEST_URI"]     = "/index.php";
            $_SERVER["SCRIPT_NAME"]     = "/index.php";
            $_SERVER["SCRIPT_FILENAME"] = WEBROOT . "index.php";
            $_SERVER["HTTP_HOST"]       = "magento.local:9080";

            // make sure the superglobals are clean before we start
            $_COOKIE = array();
            unset($_SESSION);

            // the registry keys to preserve from cleaning up after every magento app request
            $registryPreserveKeys = array();

            $reflect = new \ReflectionClass('\Mage');
            $props   = $reflect->getStaticProperties();

            $registryCleanKeys = array_keys($props['_registry']);

            // cleanup mage registry
            foreach ($registryCleanKeys as $registryCleanKey) {
                if (!in_array($registryCleanKey, $registryPreserveKeys)) {
                    \Mage::unregister($registryCleanKey);
                }
            }

            try {
                // accept client connection
                if ($client = $connection->accept()) {

                    // read socket for dummy
                    list($httpMethod, $httpUri, $httpProtocol) = explode(' ', $client->readLine());

                    // update the request method in the global $_SERVER var
                    $_SERVER['REQUEST_METHOD'] = $httpMethod;

                    // readin headers
                    $headers = array();
                    while (($line = $client->readLine()) !== "\r\n") {
                        list($headerKey, $headerValue) = explode(': ', trim($line));
                        $headers[$headerKey] = $headerValue;
                    }
                    // it// iterate all cookies and set them in globals if exists
                    if (isset($headers['Cookie'])) {
                        $cookieHeaderValue = $headers['Cookie'];
                        foreach (explode(';', $cookieHeaderValue) as $cookieLine) {
                            list ($key, $value) = explode('=', $cookieLine);
                            $_COOKIE[trim($key)] = trim($value);
                        }
                    }

                    if (isset($_COOKIE['frontend']) && strlen($_COOKIE['frontend'])) {
                        session_id($_COOKIE['frontend']);
                    }

                    if (strpos($httpUri, '/index.php') === false) {
                        // output serving static content
                        $client->copyStream(fopen(WEBROOT . $httpUri, 'r'));
                    } else {
                        if ($httpMethod === 'POST') {
                            if (isset($headers['Content-Length'])) {
                                $bodyContent = $client->read($headers['Content-Length']);

                                if (strpos($headers['Content-Type'], 'multipart/form-data') !== false) {
                                    $this->parse_raw_http_request($_POST, $bodyContent, $headers);
                                } else {
                                    parse_str(urldecode($bodyContent), $_POST);
                                }
                            }
                        }

                        $appRequest  = new \Mage_Core_Controller_Request_Http();
                        $appResponse = new \Mage_Core_Controller_Response_Http();
                        $appRequest->setRequestUri($httpUri);

                        $magentoApp->setRequest($appRequest);
                        $magentoApp->setResponse($appResponse);

                        ob_start();

                        appserver_set_headers_sent(false);

                        $magentoApp->run(
                            array(
                                 'scope_code' => 'default',
                                 'scope_type' => 'store',
                                 'options'    => array(),
                            )
                        );

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

                        $client->write("HTTP/1.1 " . appserver_get_http_response_code() . "\r\n");
                        $client->write($headerStr . $headerSetCookieStr);
                        $client->write("\r\n" . ob_get_contents());

                        ob_end_clean();
                    }

                    $client->close();

                    session_write_close();
                    appserver_session_init();
                }
            } catch (\Exception $e) {
                $client->write($e);
                $client->close();
            }
        } while (1);
    }
}

require AUTOLOADER;
//require 'zmsg.php';

// open server connection to www
$serverConnection = StreamSocket::getServerInstance(
    'tcp://0.0.0.0:9080'
);

// start mage workers
$mageWorker = array();
for ($i = 1; $i <= 12; $i++) {
    echo "Starting MageWorker #$i" . PHP_EOL;
    $mageWorker[$i] = new MageWorker($serverConnection->getConnectionResource());
}
