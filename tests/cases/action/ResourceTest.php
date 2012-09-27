<?php
/**
 * li3_resources: Friendly resource definitions for Lithium.
 *
 * @copyright     Copyright 2012, Union of RAD, LLC (http://union-of-rad.com)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace li3_resources\tests\cases\action;

use Mockery;
use lithium\data\Entity;
use lithium\action\Request;
use lithium\action\Response;
use lithium\util\Collection;
use li3_resources\tests\mocks\MockResource;

class ResourceTest extends \lithium\test\Unit {

	protected $_mapper;

	protected $_resourceConfig = array(
		'classes' => array(
			'entity' => 'lithium\data\Entity',
			'response' => 'lithium\action\Response',
			'responder' => 'li3_resources\action\resource\Responder'
		),
		'methods' => array(
			'GET' => array('view' => 'id', 'index' => null),
			'POST' => array('edit' => 'id', 'add' => null),
			'PUT' => array('edit' => 'id'),
			'PATCH' => array('edit' => 'id'),
			'DELETE' => array ('delete' => 'id'),
		)
	);

	public function testHttpMethodMapping() {
		$resource = new MockResource(array('mockMethod' => true));

		$map = array(
			array('action' => 'view',   'method' => 'GET',    'params' => array('id' => 1)),
			array('action' => 'index',  'method' => 'GET',    'params' => array()),
			array('action' => 'add',    'method' => 'POST',   'params' => array()),
			array('action' => 'edit',   'method' => 'POST',   'params' => array('id' => 1)),
			array('action' => 'delete', 'method' => 'DELETE', 'params' => array('id' => 1)),
			array('action' => 'edit',   'method' => 'PUT',    'params' => array('id' => 1))
		);

		foreach ($map as $request) {
			$result = $resource($this->_request($request['method'], $request['params']));
			$this->assertEqual($request['action'], $result);
		}

		$result = $resource($this->_request('GET', array('action' => 'arbitrary')));
		$this->assertEqual('arbitrary', $result);
	}

	public function testDefaultIndexMethodDispatch() {
		$binding = Mockery::mock('resourceModel');
		$this->_mapper = Mockery::mock('overload:li3_resources\net\http\ResourcesTest');
		$mapper = $this->_mapper;

		$config = $this->_resourceConfig;
		$resources = array('mockResources' => array('call' => 'first'));
		$request = $this->_request('GET');
		$json = $this->_request('GET', array(), array('HTTP_ACCEPT' => 'application/json'));

		$data = array(
			array('id' => 1, 'title' => 'first'),
			array('id' => 2, 'title' => 'second'),
			array('id' => 3, 'title' => 'third')
		);
		$map = array(
			Mockery::on(function($data) use ($resources) {
				$success = isset($data['mockResources'][0]) && is_object($data['mockResources'][0]);
				unset($data['mockResources'][0]);
				return $success && $data == $resources;
			}),
			Mockery::on(function($data) use ($config) {
				$success = is_object($data['binding']);
				unset($data['binding'], $data['classes']['resources']);
				return $success && $data == $config;
			})
		);
		$return = array('mockResources' => new Collection(compact('data')));
		$mapper->shouldReceive('all')->once()->with($map[0], $map[1], $json)->andReturn($return);
		$mapper->shouldReceive('all')->once()->with($map[0], $map[1], $request)->andReturn($return);

		$resource = new MockResource(compact('binding') + array(
			'classes' => array('resources' => $mapper),
			'handleExceptions' => false
		));

		$result = $resource($json);
		$this->assertTrue($result instanceof Response);
		$this->assertEqual("application/json; charset=UTF-8", $result->headers['Content-Type']);
		$this->assertEqual(json_encode($data), $result->body());
		$this->assertEqual('HTTP/1.1 200 OK', $result->status());

		$regex = '/^Template not found at path `.*views\/mock_resource\/index.html.php`\.$/';
		$this->expectException($regex);
		$result = $resource($request);
	}

	public function testDefaultCreateMethodDispatch() {
		$mapper = $this->_mapper;
		$binding = Mockery::mock('lithium\data\Model');

		$data = array('id' => 1, 'title' => 'first');
		$entity = Mockery::mock(new Entity(compact('data') + array('model' => $binding)));

		$entity->shouldReceive('validates')->times(4)->andReturn(true, true, true, false);
		$entity->shouldReceive('exists')->times(4)->andReturn(false, true, false, false);
		$entity->shouldReceive('save')->twice()->andReturn(true, false);
		$entity->shouldReceive('errors')->once()->andReturn(array(
			'title' => 'Title not cool enough'
		));

		$config = $this->_resourceConfig;
		$resources = array('mockResource' => array('call' => 'first'));

		$request = $this->_request('POST', array(), array('HTTP_ACCEPT' => 'application/json'));
		$request->data = $data;

		$map = array(Mockery::any(), Mockery::any());
		$return = array('mockResource' => $entity);
		$mapper->shouldReceive('all')->once()->with($map[0], $map[1], $request)->andReturn($return);

		$resource = new MockResource(compact('binding') + array(
			'classes' => array('resources' => $mapper),
			'handleExceptions' => false
		));

		$result = $resource($request);
		$this->assertTrue($result instanceof Response);
		$this->assertEqual(json_encode($data), $result->body());
		$this->assertEqual('HTTP/1.1 201 Created', $result->status());
		$this->assertEqual("application/json; charset=UTF-8", $result->headers['Content-Type']);

		$result = $resource($request);
		$this->assertTrue($result instanceof Response);
		$this->assertEqual(json_encode(array('title' => 'Title not cool enough')), $result->body());
		$this->assertEqual('HTTP/1.1 422 Unprocessable Entity', $result->status());
		$this->assertEqual("application/json; charset=UTF-8", $result->headers['Content-Type']);
	}

	protected function _request($method, array $params = array(), array $headers = array()) {
		return new Request(compact('params') + array(
			'env' => array('REQUEST_METHOD' => $method) + $headers
		));
	}
}

?>