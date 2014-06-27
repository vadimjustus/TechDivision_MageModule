<?php
/**
 * \TechDivision\MageModule\AppProcessThread
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
 * Class AppProcessThread
 *
 * @category  Webserver
 * @package   TechDivision_MageModule
 * @author    Johann Zelger <jz@techdivision.com>
 * @copyright 2014 TechDivision GmbH <info@techdivision.com>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/techdivision/TechDivision_MageModule
 */
class AppProcessThread extends \Thread
{

    /**
     * Hold's the response stack object
     *
     * @var \TechDivision\MageModule\ResponseStack
     */
    protected $response;

    /**
     * Hold's the worker instance
     *
     * @var \TechDivision\MageModule\AppWorker
     */
    protected $worker;

    /**
     * Constructs the process by worker and response
     *
     * @param \TechDivision\MageModule\AppWorker     $worker   The worker instance
     * @param \TechDivision\MageModule\ResponseStack $response The response stack object
     */
    public function __construct($worker, $response)
    {
        $this->response = $response;
        $this->worker = $worker;
        // auto start thread
        $this->start(PTHREADS_INHERIT_FUNCTIONS | PTHREADS_ALLOW_HEADERS);
    }

    /**
     * Runs the process logic
     *
     * @return void
     */
    public function run()
    {
        require __DIR__ . DIRECTORY_SEPARATOR . 'AppWorker.php';
        require __DIR__ . DIRECTORY_SEPARATOR . 'App.php';
        require __DIR__ . DIRECTORY_SEPARATOR . 'ResponseStack.php';

        $this->worker->handle($this, $this->response);

        $this->wait();
    }
}
