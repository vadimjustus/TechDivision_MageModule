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
 * @copyright 2014 TechDivision GmbH <info@techdivision.com>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/techdivision/TechDivision_MageModule
 */

namespace magevm;

/**
 * Class App
 *
 * @category  Webserver
 * @package   TechDivision_MageModule
 * @copyright 2014 TechDivision GmbH <info@techdivision.com>
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @link      https://github.com/techdivision/TechDivision_MageModule
 * @author    Vadim Justus <v.justus@techdivision.com>
 */

class SessionContainer extends \Stackable
{
    /**
     * Interface compatibility
     */
    public function run() {}

    /**
     * @param string $id
     * @return null|array
     */
    public function getData($id)
    {
        if (isset($this->data[$id])) {
            return $this->data[$id];
        }
        return null;
    }

    /**
     * @param string $id
     * @param array $data
     */
    public function setData($id, $data = array())
    {
        $this->data[$id] = $data;
    }
}