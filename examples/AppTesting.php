<?php
/**
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

namespace AppserverIo\AppProcessor;

require '/opt/appserver/app/code/vendor/autoload.php';
require '/opt/appserver/var/scripts/core_functions.php';


use \TechDivision\Server\Sockets\StreamSocket;

/**
 * Class App
 *
 * @category  Webserver
 * @package   TechDivision_MageModule
 * @author    Johann Zelger <jz@techdivision.com>
 * @copyright 2014 TechDivision GmbH <info@techdivision.com>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/techdivision/TechDivision_MageModule
 */
class App extends \Thread
{

    /**
     * Construct
     */
    public function __construct()
    {
        // auto start thread
        $this->start(PTHREADS_INHERIT_FUNCTIONS | PTHREADS_ALLOW_HEADERS);
    }

    /**
     * Runs the app logic
     *
     * @return void
     */
    public function run()
    {
        // prepare the app for being ready to accept requests
        $this->prepare();

        // set server var for magento request handling
        $_SERVER = array(
            "REQUEST_URI" => "/index.php",
            "SCRIPT_NAME" => "/index.php",
            "SCRIPT_FILENAME" => "/var/www/magento/index.php",
            "HTTP_HOST" => "magento.local:9080",
        );

        // require Mage class
        require '/var/www/magento/app/Mage.php';

        // set headers to be not sent yet
        appserver_set_headers_sent(false);

        // pre init the magento app
        $app = \Mage::app();
        ob_start();
        $app->run(array(
            'scope_code' => 'default',
            'scope_type' => 'store',
            'options'    => array(),
        ));
        ob_end_clean();


        do {
            // wait for new app request
            $this->wait();

            // start output buffering to grap generated content afterwards
            ob_start();
            // run the app itself
            $this->process();


            // the registry keys to clean after every magento app request
            $registryCleanKeys = array('application_params','current_category','current_product','_singleton/core/layout');

            // cleanup mage registry
            foreach ($registryCleanKeys as $registryCleanKey) {
                \Mage::unregister($registryCleanKey);
            }

            // setup app request and response
            $appRequest = new \Mage_Core_Controller_Request_Http();
            $appResponse = new \Mage_Core_Controller_Response_Http();
            $appRequest->setRequestUri('/index.php');
            $app->setRequest($appRequest);
            $app->setResponse($appResponse);

            // set the headers to be not sent yet
            appserver_set_headers_sent(false);

            $startTime = microtime(true);

            // run the app
            $app->run(array(
                'scope_code' => 'default',
                'scope_type' => 'store',
                'options'    => array(),
            ));

            $deltaTime = microtime(true) - $startTime;
            error_log("Magento app->run() took: $deltaTime");

            // log generated content
            //error_log(ob_get_contents());
            // end and clean output buffering
            ob_end_clean();

        } while (true);
    }
}

/**
 * Class AppWorker
 *
 * @category  Webserver
 * @package   TechDivision_MageModule
 * @author    Johann Zelger <jz@techdivision.com>
 * @copyright 2014 TechDivision GmbH <info@techdivision.com>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/techdivision/TechDivision_MageModule
 */
class AppWorker extends \Thread
{
    /**
     * Hold's the connection socket resource
     *
     * @var resource
     */
    protected $connectionResource;

    /**
     * Hold's the app class type definition
     *
     * @var string
     */
    protected $appType;

    /**
     * Constructs the worker
     *
     * @param string   $appType            The app class type definition
     * @param resource $connectionResource The connection socket resource
     */
    public function __construct($appType, $connectionResource)
    {
        // set refs to object properties
        $this->connectionResource = $connectionResource;
        $this->appType = $appType;

        // auto start thread
        $this->start(PTHREADS_INHERIT_ALL | PTHREADS_ALLOW_HEADERS);
    }

    /**
     * Runs the worker logic
     *
     * @return void
     */
    public function run()
    {
        // instantiate new app
        $app = new $this->appType();

        // build up connection object by resource
        $connection = StreamSocket::getInstance($this->connectionResource);

        do {
            // accept client connection
            if ($client = $connection->accept()) {
                $app->notify();
                // close connection to client
                $client->close();
            }

        } while (true);
    }
}

// open server connection to the world wide web
$serverConnection = StreamSocket::getServerInstance(
    'tcp://0.0.0.0:9080'
);

// start mage workers
$mageWorker = array();
for ($i=1; $i<=8; $i++) {
    echo "Starting AppWorker #$i" . PHP_EOL;
    $mageWorker[$i] = new AppWorker('\AppserverIo\AppProcessor\App', $serverConnection->getConnectionResource());
}
