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
     * Hold's the app class type definition
     *
     * @var string
     */
    protected $appType;

    /**
     * Constructs the app worker
     *
     * @param string $appType The app class type definition
     */
    public function __construct($appType)
    {
        $this->appType = $appType;
        // auto start thread
        $this->start(PTHREADS_INHERIT_FUNCTIONS | PTHREADS_ALLOW_HEADERS);
    }

    /**
     * Handles a request
     *
     * @param \TechDivision\Http\HttpResponseInterface $response The response object
     *
     * @return void
     */
    protected function handle($response)
    {
        $this->response = $response;
        $this->notify();
    }

    /**
     * Runs the workers logic
     *
     * @return void
     */
    public function run()
    {
        require __DIR__ . DIRECTORY_SEPARATOR . 'App.php';
        require __DIR__ . DIRECTORY_SEPARATOR . 'ResponseStack.php';
        require __DIR__ . DIRECTORY_SEPARATOR . 'AppProcessThread.php';

        // instantiate new app
        $app = new $this->appType();

        $this->handleRequest = false;

        do {
            if ($this->handleRequest === false) {
                // wait for request to work on
                $this->wait();
            }

            $this->handleRequest = true;

            // notify the app to do something
            $app->handle($this, $this->response);

            // let itself wait for the app to be ready
            $this->wait();

            $this->handleRequest = false;


        } while (true);
    }
}
