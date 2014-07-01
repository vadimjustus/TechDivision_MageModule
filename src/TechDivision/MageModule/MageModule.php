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

use TechDivision\Http\HttpRequestInterface;
use TechDivision\Http\HttpResponseInterface;
use TechDivision\Http\HttpResponseStates;
use TechDivision\PhpModule\PhpModule;
use TechDivision\Server\Dictionaries\ModuleHooks;
use TechDivision\Server\Dictionaries\ServerVars;
use TechDivision\Server\Exceptions\ModuleException;
use TechDivision\Server\Interfaces\ModuleInterface;
use TechDivision\Server\Interfaces\ServerContextInterface;

/**
 * Class MageModule
 *
 * @category  Webserver
 * @package   TechDivision_MageModule
 * @author    Johann Zelger <jz@techdivision.com>
 * @copyright 2014 TechDivision GmbH <info@techdivision.com>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/techdivision/TechDivision_MageModule
 */
class MageModule extends PhpModule implements ModuleInterface
{
    /**
     * Defines the module name
     *
     * @var string
     */
    const MODULE_NAME = 'mage';

    /**
     * Hold's the server's context
     *
     * @var \TechDivision\Server\Interfaces\ServerContextInterface
     */
    protected $serverContext;

    /**
     * Hold's the request instance
     *
     * @var \TechDivision\Http\HttpRequestInterface
     */
    protected $request;

    /**
     * Hold's the response instance
     *
     * @var \TechDivision\Http\HttpResponseInterface
     */
    protected $response;

    protected $mageWorker = array();

    /**
     * Initiates the module
     *
     * @param \TechDivision\Server\Interfaces\ServerContextInterface $serverContext The server's context instance
     *
     * @return bool
     * @throws \TechDivision\Server\Exceptions\ModuleException
     */
    public function init(ServerContextInterface $serverContext)
    {
        $this->serverContext = $serverContext;

        // start magento worker
        for ($i=1; $i<=4; $i++) {
            echo "Starting AppWorker #$i" . PHP_EOL;
            $this->mageWorker[$i] = new AppWorker('\TechDivision\MageModule\App');
        }
    }

    /**
     * Return's the server's context
     *
     * @return \TechDivision\Server\Interfaces\ServerContextInterface
     */
    public function getServerContext()
    {
        return $this->serverContext;
    }

    /**
     * Return's the request instance
     *
     * @return \TechDivision\Http\HttpRequestInterface
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * Return's the response instance
     *
     * @return \TechDivision\Http\HttpResponseInterface
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * Prepares the module for upcoming request in specific context
     *
     * @return bool
     * @throws \TechDivision\Server\Exceptions\ModuleException
     */
    public function prepare()
    {

    }

    /**
     * Implement's module logic for given hook
     *
     * @param \TechDivision\Http\HttpRequestInterface  $request  The request object
     * @param \TechDivision\Http\HttpResponseInterface $response The response object
     * @param int                                      $hook     The current hook to process logic for
     *
     * @throws \TechDivision\Server\Exceptions\ModuleException
     * @return bool
     */
    public function process(HttpRequestInterface $request, HttpResponseInterface $response, $hook)
    {
        // check if shutdown hook is comming
        if (ModuleHooks::SHUTDOWN === $hook) {
            return; //$this->shutdown($request, $response);
        }

        // if wrong hook is comming do nothing
        if (ModuleHooks::REQUEST_POST !== $hook) {
            return;
        }

        // set req and res internally
        $this->request = $request;
        $this->response = $response;
        // get server context to local var
        $serverContext = $this->getServerContext();


        // check if server handler sais php modules should react on this request as file handler
        if ($serverContext->getServerVar(ServerVars::SERVER_HANDLER) === self::MODULE_NAME) {

            // create new response stackable
            $responseStack = new ResponseStack();

            foreach ($this->mageWorker as $mageWorker) {
                if ($mageWorker->handleRequest === false) {
                    $worker = $mageWorker;
                    $worker->handle($responseStack);
                    break;
                }
            }

            while (strlen($responseStack->body) === 0) {
                usleep(1);
            }

            foreach (appserver_get_headers(true) as $resHeader) {
                list($resHeaderKey, $resHeaderValue) = explode(': ', $resHeader);
                $response->addHeader($resHeaderKey, $resHeaderValue);
            }

            $response->setStatusCode(appserver_get_http_response_code());

            $response->appendBodyStream($responseStack->body);

            // set response state to be dispatched after this without calling other modules process
            $response->setState(HttpResponseStates::DISPATCH);
        }
    }

    /**
     * Prepare's the server vars for php usage
     *
     * @return void
     */
    protected function prepareServerVars()
    {
        $serverContext = $this->getServerContext();
        // init php self server var
        $phpSelf = $serverContext->getServerVar(ServerVars::SCRIPT_NAME);
        if ($serverContext->hasServerVar(ServerVars::PATH_INFO)) {
            $phpSelf .= $serverContext->getServerVar(ServerVars::PATH_INFO);
        }
        $serverContext->setServerVar(PhpModule::SERVER_VAR_PHP_SELF, $phpSelf);
    }

    /**
     * Return's an array of module names which should be executed first
     *
     * @return array The array of module names
     */
    public function getDependencies()
    {
        return array();
    }

    /**
     * Returns the module name
     *
     * @return string The module name
     */
    public function getModuleName()
    {
        return self::MODULE_NAME;
    }

    /**
     * Implement's module shutdown logic
     *
     * @param \TechDivision\Http\HttpRequestInterface  $request  The request object
     * @param \TechDivision\Http\HttpResponseInterface $response The response object
     *
     * @return bool
     * @throws \TechDivision\Server\Exceptions\ModuleException
     */
    public function shutdown(HttpRequestInterface $request, HttpResponseInterface $response)
    {

    }
}
