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
 * Class PhpModule
 *
 * @category  Webserver
 * @package   TechDivision_MageModule
 * @author    Johann Zelger <jz@techdivision.com>
 * @copyright 2014 TechDivision GmbH <info@techdivision.com>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/techdivision/TechDivision_PhpModule
 */
class MageModule implements ModuleInterface
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
        error_log(__METHOD__);
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

            if (!class_exists('\Mage')) {

                error_log(__METHOD__ . ':' . __LINE__);

                // set server var for magento request handling
                $_SERVER = array();
                $_SERVER["REQUEST_URI"] = "/index.php";
                $_SERVER["SCRIPT_NAME"] = "/index.php";
                $_SERVER["SCRIPT_FILENAME"] = "/var/www/magento/index.php";
                $_SERVER["HTTP_HOST"] = "magento.local:9080";

                require '/var/www/magento/app/Mage.php';

                appserver_set_headers_sent(false);
                $magentoApp = \Mage::app();
                ob_start();
                $magentoApp->run(array(
                    'scope_code' => 'default',
                    'scope_type' => 'store',
                    'options'    => array(),
                ));
                ob_end_clean();

            } else {
                $startTime = microtime(true);
                $magentoApp = \Mage::app();
                error_log('\Mage:app() took: ' . microtime(true) - $startTime);
            }

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

            $appRequest = new \Mage_Core_Controller_Request_Http();
            $appResponse = new \Mage_Core_Controller_Response_Http();
            $appRequest->setRequestUri($request->getUri());

            $magentoApp->setRequest($appRequest);
            $magentoApp->setResponse($appResponse);

            ob_start();

            appserver_set_headers_sent(false);

            $magentoApp->run(array(
                'scope_code' => 'default',
                'scope_type' => 'store',
                'options'    => array(),
            ));

            foreach (appserver_get_headers(true) as $resHeader) {
                list($resHeaderKey, $resHeaderValue) = explode(': ', $resHeader);
                $response->addHeader($resHeaderKey, $resHeaderValue);
            }

            $response->setStatusCode(appserver_get_http_response_code());

            $response->appendBodyStream(ob_get_contents());

            ob_end_clean();

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
        error_log(__METHOD__);
    }

    public function clearRegistry()
    {
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
    }
}
