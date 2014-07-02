<?php
/**
 * Created by JetBrains PhpStorm.
 * User: stockbauerm
 * Date: 02/07/14
 * Time: 17:25
 * To change this template use File | Settings | File Templates.
 */

namespace magevm;


class app
{

    protected $client;

    protected $shutdownFunctionRegistered = false;

    /**
     * @param mixed $client
     */
    public function setClient($client)
    {
        $this->client = $client;
    }

    /**
     * @return mixed
     */
    public function getClient()
    {
        return $this->client;
    }

    public function shutdown($shutdown = true)
    {
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

        foreach ($h = appserver_get_headers(true) as $resHeader) {
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

        $this->getClient()->write("HTTP/1.1 " . appserver_get_http_response_code() . "\r\n");
        $this->getClient()->write($headerStr . $headerSetCookieStr);
        $this->getClient()->write("\r\n" . $c = ob_get_contents());


        ob_end_clean();

        $this->getClient()->close();

        session_write_close();
        appserver_session_init();

        if($shutdown) {
            var_dump($this->getClient());
            var_dump($headerStr);
            var_dump($h);
        }
    }

    public function registerShutdownFunction()
    {
        if(!$this->shutdownFunctionRegistered) {
            register_shutdown_function(
                array(&$this, 'shutdown')
            );

            $this->shutdownFunctionRegistered = true;
        }
    }

    public function handle($client, $magentoApp)
    {

        $this->setClient($client);

        $this->registerShutdownFunction();

        echo __METHOD__ . ':' . __LINE__ . PHP_EOL;

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
        echo __METHOD__ . ':' . __LINE__ . PHP_EOL;

        if (strpos($httpUri, '/index.php') === false) {
            // output serving static content
            $client->copyStream(fopen(WEBROOT . $httpUri, 'r'));
            $client->close();
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

            echo __METHOD__ . ':' . __LINE__ . PHP_EOL;

            $appRequest  = new \Mage_Core_Controller_Request_Http();
            $appResponse = new \Mage_Core_Controller_Response_Http();
            $appRequest->setRequestUri($httpUri);

            echo __METHOD__ . ':' . __LINE__ . PHP_EOL;

            $magentoApp->setRequest($appRequest);
            $magentoApp->setResponse($appResponse);

            ob_start();

            appserver_set_headers_sent(false);

            //echo __METHOD__ . ':' . __LINE__ . PHP_EOL;

            $magentoApp->run(
                array(
                     'scope_code' => 'default',
                     'scope_type' => 'store',
                     'options'    => array(),
                )
            );

            $this->shutdown(false);
        }
    }

}