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
 * @copyright 2014 TechDivision GmbH <info@techdivision.com>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/techdivision/TechDivision_MageModule
 */

namespace magevm;

define('BASEDIR', __DIR__ . DIRECTORY_SEPARATOR);
define('AUTOLOADER', '/opt/appserver/app/code' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php');

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
     * Runs the vm
     *
     * @return void
     */
    public function run()
    {
        /*
        $ctx = new ZMQContext();
        //  First allow 0MQ to set the identity
        $com = new ZMQSocket($ctx, ZMQ::SOCKET_REQ);
        $com->connect("ipc://com");

        $com->send('Running Thread "' . __CLASS__ .'" #' . $this->getThreadId());
        */

        echo __METHOD__ . ':' . __LINE__ . PHP_EOL;

        // set server var for magento request handling
        $_SERVER = array();
        $_SERVER["REQUEST_URI"] = "/index.php";
        $_SERVER["SCRIPT_NAME"] = "/index.php";
        $_SERVER["SCRIPT_FILENAME"] = "/var/www/magento/index.php";
        $_SERVER["HTTP_HOST"] = "magento.local:9080";

        echo __METHOD__ . ':' . __LINE__ . PHP_EOL;

        require AUTOLOADER;
        require '/var/www/magento/app/Mage.php';

        echo __METHOD__ . ':' . __LINE__ . PHP_EOL;

        appserver_set_headers_sent(false);
        $magentoApp = \Mage::app();
        ob_start();
        $magentoApp->run(array(
            'scope_code' => 'default',
            'scope_type' => 'store',
            'options'    => array(),
        ));
        ob_end_clean();

        echo __METHOD__ . ':' . __LINE__ . PHP_EOL;

        $connection = StreamSocket::getInstance($this->connectionResource);

        echo __METHOD__ . ':' . __LINE__ . PHP_EOL;

        // go for a loop while accepting clients
        do {
            // the registry keys to clean after every magento app request
            $registryCleanKeys = array(
                'application_params',
                'current_category',
                'current_product',
                '_singleton/core/layout',
                'current_entity_key',
                '_singleton/core/resource',
                '_resource_singleton/core/website',
                '_resource_singleton/core/store_group',
                '_resource_singleton/core/store',
                '_resource_helper/core',
                '_singleton/core/cookie',
                'controller',
                '_singleton/Mage_Cms_Controller_Router',
                '_singleton/core/factory',
                '_resource_singleton/core/url_rewrite',
                '_helper/core/http',
                '_singleton/core/session',
                '_singleton/core/design_package',
                '_singleton/core/design',
                '_resource_singleton/core/design',
                '_singleton/core/translate',
                '_singleton/core/locale',
                '_singleton/core/translate_inline',
                '_singleton/xmlconnect/observer',
                '_helper/core/string',
                '_singleton/log/visitor',
                '_resource_singleton/log/visitor',
                '_singleton/pagecache/observer',
                '_helper/pagecache',
                '_singleton/persistent/observer',
                '_helper/persistent',
                '_helper/persistent/session',
                '_resource_singleton/persistent/session',
                '_singleton/persistent/observer_session',
                '_singleton/customer/session',
                '_helper/cms/page',
                '_singleton/cms/page',
                '_resource_singleton/cms/page',
                '_helper/page/layout',
                '_helper/page',
                '_singleton/customer/observer',
                '_helper/customer',
                '_helper/catalog',
                '_helper/catalog/map',
                '_helper/catalogsearch',
                '_helper/core',
                '_helper/checkout/cart',
                '_singleton/checkout/cart',
                '_singleton/checkout/session',
                '_helper/checkout',
                '_helper/contacts',
                '_singleton/catalog/session',
                '_helper/core/file_storage_database',
                '_helper/core/js',
                '_helper/directory',
                '_helper/googleanalytics',
                '_helper/adminhtml',
                '_helper/widget',
                '_helper/wishlist',
                '_helper/cms',
                '_helper/catalog/product_compare',
                '_singleton/reports/session',
                '_resource_singleton/reports/product_index_viewed',
                '_helper/catalog/product_flat',
                '_resource_singleton/eav/entity_type',
                '_resource_singleton/catalog/product',
                '_singleton/catalog/factory',
                '_singleton/catalog/product_visibility',
                '_resource_singleton/reports/product_index_compared',
                '_resource_singleton/poll/poll',
                '_resource_singleton/poll/poll_answer',
                '_helper/paypal',
                '_helper/core/cookie',
                '_singleton/core/url',
                '_singleton/core/date'
            );

            // cleanup mage registry
            foreach ($registryCleanKeys as $registryCleanKey) {
                \Mage::unregister($registryCleanKey);
            }

            try {
                // accept client connection
                if ($client = $connection->accept()) {

                    echo __METHOD__ . ':' . __LINE__ . PHP_EOL;

                    // read socket for dummy
                    list($httpMethod, $httpUri, $httpProtocol) = explode(' ', $client->readLine());
                    // readin headers
                    $headers = array();
                    while (($line = $client->readLine()) !== "\r\n") {
                        list($headerKey, $headerValue) = explode(': ', trim($line));
                        $headers[$headerKey] = $headerValue;
                    }
                    // iterate all cookies and set them in globals if exists
                    if (isset($headers['Cookie'])) {
                        $cookieHeaderValue = $headers['Cookie'];
                        foreach (explode('; ', $cookieHeaderValue) as $cookieLine) {
                            list ($key, $value) = explode('=', $cookieLine);
                            $_COOKIE[$key] = $value;
                        }
                    }

                    if (strpos($httpUri, '/index.php') === false) {
                        // output serving static content
                        $client->copyStream(fopen('/var/www/magento' . $httpUri, 'r'));
                    } else {
                        if ($httpMethod === 'POST') {
                            if (isset($headers['Content-Length'])) {
                                $bodyContent = $client->read($headers['Content-Length']);
                                parse_str(urldecode($bodyContent), $_POST);
                            }
                        }

                        echo __METHOD__ . ':' . __LINE__ . PHP_EOL;

                        $appRequest = new \Mage_Core_Controller_Request_Http();
                        $appResponse = new \Mage_Core_Controller_Response_Http();
                        $appRequest->setRequestUri($httpUri);

                        echo __METHOD__ . ':' . __LINE__ . PHP_EOL;

                        $magentoApp->setRequest($appRequest);
                        $magentoApp->setResponse($appResponse);

                        ob_start();

                        appserver_set_headers_sent(false);

                        echo __METHOD__ . ':' . __LINE__ . PHP_EOL;

                        $magentoApp->run(array(
                            'scope_code' => 'default',
                            'scope_type' => 'store',
                            'options'    => array(),
                        ));

                        echo __METHOD__ . ':' . __LINE__ . PHP_EOL;

                        // build up res headers
                        $resHeaders = array(
                            "Server" => "MageServer/0.1.0 (PHP 5.5.10)",
                            "Connection" => "Close",
                            "Content-Length" => ob_get_length(),
                            "X-Powered-By" => "MageWorker",
                            "Expires" => "Thu, 19 Nov 1981 08:52:00 GMT",
                            "Cache-Control" => "no-store, no-cache, must-revalidate, post-check=0, pre-check=0",
                            "Pragma" => "no-cache",
                            "Content-Type" => "text/html; charset=UTF-8",
                            "Date" => "Sat, 17 May 14 16:44:40 +0000"
                        );
                        $headerStr = '';
                        foreach (appserver_get_headers(true) as $resHeader) {
                            list($resHeaderKey, $resHeaderValue) = explode(': ', $resHeader);
                            $resHeaders[$resHeaderKey] = $resHeaderValue;
                        }
                        // generate header string
                        foreach ($resHeaders as $resHeaderKey => $resHeaderValue) {
                            $headerStr .= $resHeaderKey . ': ' . $resHeaderValue . "\r\n";
                        }

                        $client->write("HTTP/1.1 " . appserver_get_http_response_code() . "\r\n");
                        $client->write($headerStr);
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

/*
$ctx = new ZMQContext();
$com = new ZMQSocket($ctx, ZMQ::SOCKET_ROUTER);
$com->bind("ipc://com");
*/

// start mage workers
$mageWorker = array();
for ($i=1; $i<=4; $i++) {
    echo "Starting MageWorker #$i" . PHP_EOL;
    $mageWorker[$i] = new MageWorker($serverConnection->getConnectionResource());
}

/*
$msg = new Zmsg($com);
while ($msg->recv()) {
    error_log($msg->body());
}
*/
