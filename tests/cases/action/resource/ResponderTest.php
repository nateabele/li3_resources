<?php
/**
 * li3_resources: Friendly resource definitions for Lithium.
 *
 * @copyright     Copyright 2012, Union of RAD, LLC (http://union-of-rad.com)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace li3_resources\tests\cases\action\resource;

use lithium\action\Request;
use lithium\action\Response;
use li3_resources\action\resource\Responder;
use li3_resources\tests\mocks\net\http\MockMedia;

class ResponderTest extends \lithium\test\Unit {

	public function setUp() {
		$this->_responder = new Responder(array(
			'classes' => array('media' => 'li3_resources\tests\mocks\net\http\MockMedia')
		));
	}

	public function testDataForTypeWithView() {
		$request = new Request(array('env' => array('HTTP_ACCEPT' => 'text/html')));
		$resources = array('file' => (object) array('field' => 'value'));
		$options = array(
			'controller' => 'File', 'status' => 200, 'data' => $resources['file']
		);

		$result = $this->_responder->handle($request, $resources, $options);
		$this->assertEqual($resources, $result['data']);
	}

	public function testDataForTypeWithNoView() {
		$request = new Request(array('env' => array('HTTP_ACCEPT' => 'application/json')));
		$resources = array('file' => (object) array('field' => 'value'));
		$options = array(
			'status' => 200, 'data' => $resources['file']
		);

		$result = $this->_responder->handle($request, $resources, $options);
		$this->assertEqual($resources['file'], $result['data']);
	}

	public function testDataForOverridedTypeWithNoView() {
		$request = new Request(array('env' => array('HTTP_ACCEPT' => 'text/html')));
		$resources = array('file' => (object) array('field' => 'value'));
		$options = array(
			'status' => 200, 'data' => $resources['file'], 'type' => 'json'
		);

		$result = $this->_responder->handle($request, $resources, $options);
		$this->assertEqual($resources['file'], $result['data']);
	}
}

?>