<?php
/**
 * li3_resources: Friendly resource definitions for Lithium.
 *
 * @copyright     Copyright 2012, Union of RAD, LLC (http://union-of-rad.com)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace li3_resources\action;

/**
 * The `BadRequestException` is thrown when a request is made to a `Resource` object that
 * the `Resource` does not understand. 
 */
class BadRequestException extends \RuntimeException {

	protected $code = 400;
}

?>