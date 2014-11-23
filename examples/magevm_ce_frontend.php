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

use \TechDivision\Server\Sockets\StreamSocket;

define('WEBROOT', '/var/www/magevm');
define('DOMAIN', 'magento.local');
define('PORT', '80');
define('LOGFILE', __DIR__ . '/../tmp/server.log');

require '/opt/appserver' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require WEBROOT . '/app/Mage.php';
require __DIR__ . '/lib/MageWorker.php';
require __DIR__ . '/lib/SessionContainer.php';


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

$threads = isset($argv[1]) ? $argv[1] : 4;

// start mage workers
$mageWorker = array();
for ($i = 1; $i <= $threads; $i++) {
    $worker = new MageWorker($serverConnection->getConnectionResource());

    $name = "[" . str_pad($i, 3, '0', STR_PAD_LEFT) . "] " . (isset($workerNames[$i]) ? $workerNames[$i] : 'no-name');

    $worker->setWebroot(WEBROOT);
    $worker->setDomain(DOMAIN);
    $worker->setPort(PORT);
    $worker->setName($name);
    $worker->setSessionContainer($session);
    $worker->setSessionContainerMode(true);
    $worker->setLogFile(LOGFILE);
    $worker->start(PTHREADS_INHERIT_ALL | PTHREADS_ALLOW_HEADERS);

    $worker->log('Started');

    $mageWorker[$i] = $worker;
    usleep(100000);
}
