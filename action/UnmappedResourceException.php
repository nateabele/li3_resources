<?php
/**
 * li3_resources: Friendly resource definitions for Lithium.
 *
 * @copyright     Copyright 2012, Union of RAD, LLC (http://union-of-rad.com)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace li3_resources\action;

/**
 * The `UnmappedResourceException` is thrown when a model class used to map a parameterized resource
 * can't be found.
 */
class UnmappedResourceException extends \lithium\core\ClassNotFoundException {
}

?>