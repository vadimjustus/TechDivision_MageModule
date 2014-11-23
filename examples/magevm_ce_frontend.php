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

require __DIR__ . '/lib/SessionContainer.php';

define('BASEDIR', __DIR__ . DIRECTORY_SEPARATOR);
define('AUTOLOADER', '/opt/appserver' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php');
define('WEBROOT', '/var/www/magevm/');
define('DOMAIN', 'magento.local');
define('PORT', '80');
define('LOGFILE', __DIR__ . '/../tmp/server.log');

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
    private $sessionContainer;

    /**
     * @var bool
     */
    private $sessionContainerMode = true;

    /**
     * @var string
     */
    private $logFile;

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
     * @param string $msg
     */
    public function log($msg)
    {
        $message =  date('Y-m-d H:i:s') . ' - ' . str_pad($this->getName() . ':', 25, ' ') . $msg . PHP_EOL;
        error_log($message, 3, $this->getLogFile());
    }

    /**
     * Runs the vm
     *
     * @return void
     */
    public function run()
    {
        $_SERVER                    = array();
        $_SERVER["REQUEST_URI"]     = "/index.php";
        $_SERVER["SCRIPT_NAME"]     = "/index.php";
        $_SERVER["SCRIPT_FILENAME"] = WEBROOT . "index.php";
        $_SERVER["HTTP_HOST"]       = DOMAIN . ":" . PORT;

        require AUTOLOADER;
        require WEBROOT . 'app/Mage.php';

        $this->log('Warm up Magento instance.');

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

        session_write_close();
        appserver_session_init();

        $connection = StreamSocket::getInstance($this->connectionResource);

        // go for a loop while accepting clients
        do {

            $_SERVER                    = array();
            $_SERVER["REQUEST_URI"]     = "/index.php";
            $_SERVER["SCRIPT_NAME"]     = "/index.php";
            $_SERVER["SCRIPT_FILENAME"] = WEBROOT . "index.php";
            $_SERVER["HTTP_HOST"]       = DOMAIN . ":" . PORT;

            // make sure the superglobals are clean before we start
            $_COOKIE = array();
            unset($_SESSION);

            $this->log('Clean Magento registry');

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

                        if ($this->isSessionContainerMode()
                            && $sessionData = $this->sessionContainer->getData(session_id())
                        ) {
                            $_SESSION = $sessionData;
                        }
                    }

                    $this->log(sprintf('Execute request: %s', $httpUri));

                    if (strpos($httpUri, '/index.php') === false) {
                        // output serving static content
                        $client->copyStream(fopen(WEBROOT . $httpUri, 'r'));
                    } else {
                        if ($httpMethod === 'POST') {
                            if (isset($headers['Content-Length']) && $headers['Content-Length'] > 0) {
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
                        $this->log(sprintf('Finish Magento app run in %.6f', $duration));

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

                        $this->log("Wrote response body");

                        if ($this->isSessionContainerMode() && isset($_SESSION)) {
                            $this->log(sprintf('Write session data to session handler with id %s.', session_id()));
                            $this->sessionContainer->setData(session_id(), $_SESSION);
                        }

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
    'tcp://0.0.0.0:' . PORT
);

$workerNames = array(
    1 => 'Hans', 'Tim', 'Stocki', 'Rene', 'Vadim',
    'Lars', 'Flo', 'Witte', 'Stefan', 'Sepp',
    'Berwanger', 'Faihu', 'Luna', 'Lili', 'Tulpe',
    'Samba', 'Bert', 'Dodo', 'Philipp', 'Datterich',
    'Matze', 'Fredi', 'Marvin', 'Peter', 'Jean'
);

shuffle($workerNames);

$session = new SessionContainer();

$threads = $argv[1];

// start mage workers
$mageWorker = array();
for ($i = 1; $i <= $threads; $i++) {
    $worker = new MageWorker($serverConnection->getConnectionResource());

    $name = "[" . str_pad($i, 3, '0', STR_PAD_LEFT) . "] " . (isset($workerNames[$i]) ? $workerNames[$i] : 'no-name');

    $worker->setName($name);
    $worker->setSessionContainer($session);
    $worker->setSessionContainerMode(false);
    $worker->setLogFile(LOGFILE);
    $worker->start(PTHREADS_INHERIT_ALL | PTHREADS_ALLOW_HEADERS);

    $worker->log('Started');

    $mageWorker[$i] = $worker;
    usleep(100000);
}
