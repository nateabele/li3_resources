<?php
/**
 * Lithium: the most rad php framework
 *
 * @copyright     Copyright 2013, Union of RAD (http://union-of-rad.org)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace li3_resources\tests\mocks\net\http;

class MockMedia extends \lithium\net\http\Media {

	public static function render($response, $data = null, array $options = array()) {
		return compact('response', 'data', 'options');
	}
}

?>