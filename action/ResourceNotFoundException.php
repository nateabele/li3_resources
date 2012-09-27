<?php
/**
 * li3_resources: Friendly resource definitions for Lithium.
 *
 * @copyright     Copyright 2012, Union of RAD, LLC (http://union-of-rad.com)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace li3_resources\action;

/**
 * The `ResourceNotFoundException` is thrown when a well-formed request for a resource executes a
 * query for which no resource(s) is/are found.
 */
class ResourceNotFoundException extends \lithium\action\DispatchException {
}

?>