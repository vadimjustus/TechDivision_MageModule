<?php
/**
 * \TechDivision\MageModule\MageModule
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

namespace TechDivision\MageModule;

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
     * Hold's the worker instance
     *
     * @var \TechDivision\MageModule\AppWorker
     */
    protected $worker;

    /**
     * Construct and start thread automatically
     */
    public function __construct()
    {
        // auto start thread
        $this->start(PTHREADS_INHERIT_FUNCTIONS | PTHREADS_ALLOW_HEADERS);
    }

    /**
     * Handles the request
     *
     * @param \TechDivision\MageModule\AppWorker     $worker   The worker instance
     * @param \TechDivision\MageModule\ResponseStack $response The response stack data object
     *
     * @return void
     */
    protected function handle($worker, $response)
    {
        $this->response = $response;
        $this->worker = $worker;

        $this->notify();
    }

    /**
     * Runs the app logic
     *
     * @return void
     */
    public function run()
    {
        require __DIR__ . DIRECTORY_SEPARATOR . 'ResponseStack.php';
        require __DIR__ . DIRECTORY_SEPARATOR . 'AppWorker.php';

        // prepare the app for being ready to accept requests

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

            // run the app
            $app->run(array(
                'scope_code' => 'default',
                'scope_type' => 'store',
                'options'    => array(),
            ));


            $content = ob_get_contents();

            $this->response->body = $content;

            $this->worker->notify();

            // end and clean output buffering
            ob_end_clean();

        } while (true);
    }
}
