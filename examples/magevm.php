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

require_once 'app.php';

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

    /** @var  app */
    protected $app;

    /**
     * Constructor
     *
     * @param resource $connectionResource The connection resource
     */
    public function __construct($connectionResource)
    {
        $this->connectionResource = $connectionResource;
        $this->start(PTHREADS_INHERIT_ALL | PTHREADS_ALLOW_HEADERS);
        $this->setApp(new app());
        //$this->run();
    }

    /**
     * @param app $app
     */
    public function setApp(app $app)
    {
        $this->app = $app;
    }

    /**
     * @return app
     */
    public function getApp()
    {
        return $this->app;
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


        /*
        $ctx = new ZMQContext();
        //  First allow 0MQ to set the identity
        $com = new ZMQSocket($ctx, ZMQ::SOCKET_REQ);
        $com->connect("ipc://com");

        $com->send('Running Thread "' . __CLASS__ .'" #' . $this->getThreadId());
        */

        echo __METHOD__ . ':' . __LINE__ . PHP_EOL;

        // set server var for magento request handling
        $_SERVER                    = array();
        $_SERVER["REQUEST_URI"]     = "/index.php";
        $_SERVER["SCRIPT_NAME"]     = "/index.php";
        $_SERVER["SCRIPT_FILENAME"] = WEBROOT . "index.php";
        $_SERVER["HTTP_HOST"]       = "magento.local:9080";

        echo __METHOD__ . ':' . __LINE__ . PHP_EOL;

        require AUTOLOADER;
        require WEBROOT . 'app/Mage.php';

        echo __METHOD__ . ':' . __LINE__ . PHP_EOL;

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

        appserver_get_headers(true);

        echo __METHOD__ . ':' . __LINE__ . PHP_EOL;

        $connection = StreamSocket::getInstance($this->connectionResource);

        echo __METHOD__ . ':' . __LINE__ . PHP_EOL;

        $shutdownHandlerRegistered = false;

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

            foreach(array('application_params', 'current_category', '_singleton/core/layout', 'current_entity_key', 'current_product', 'category', 'product') as $key) {
              //  \Mage::unregister($key);
            }

            try {
                // accept client connection
                if ($client = $connection->accept()) {
                    $this->getApp()->handle($client, $magentoApp);
                }
            } catch (\Exception $e) {
                $client->write($e);
                $client->close();
            }

            echo "Served by " . $this->getThreadId() . "\n\n";

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
for ($i = 1; $i <= 4; $i++) {
    echo "Starting MageWorker #$i" . PHP_EOL;
    $mageWorker[$i] = new MageWorker($serverConnection->getConnectionResource());
}

/*
$msg = new Zmsg($com);
while ($msg->recv()) {
    error_log($msg->body());
}
*/
