<?php
/**
 * li3_resources: Friendly resource definitions for Lithium.
 *
 * @copyright     Copyright 2012, Union of RAD, LLC (http://union-of-rad.com)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace li3_resources\tests\cases\net\http;

use Mockery;
use lithium\data\Entity;
use lithium\core\Libraries;
use lithium\action\Request;
use lithium\util\Collection;
use li3_resources\net\http\Resources;

class ResourcesTest extends \lithium\test\Unit {

	/**
	 * Tests querying a single entity.
	 */
	public function testResourceQuerying() {
		$model = Mockery::mock("alias:li3_resources\models\Posts");
		$conditions = array('id' => 6);
		$data = $conditions + array('title' => 'New Post');
		$request = $this->_request('GET', $conditions);

		$model->shouldReceive('first')->once()->with(compact('conditions'))->andReturn(
			new Entity(compact('data'))
		);
		$model->shouldReceive('key')->once()->andReturn('id');

		$result = Resources::get($request, compact('model') + array(
			'name' => 'post',
			'call' => array('first')
		));
		$this->assertEqual($data, $result->data());

		$model->shouldReceive('first')->once()->with(compact('conditions'))->andReturn(null);
		$model->shouldReceive('key')->once()->andReturn('id');

		$result = Resources::get($request, compact('model') + array(
			'name' => 'post',
			'call' => array('first'),
			'required' => false
		));
		$this->assertNull($result);

		$conditions += array('foo' => 'bar');
		$model->shouldReceive('first')->once()->with(compact('conditions'))->andReturn(null);
		$model->shouldReceive('key')->once()->andReturn('id');

		$result = Resources::get($request, compact('model') + array(
			'name' => 'post',
			'call' => array('first', 'conditions' => array('foo' => 'bar')),
			'required' => false
		));
		$this->assertNull($result);

		$model->shouldReceive('first')->once()->with(compact('conditions'))->andReturn(null);
		$model->shouldReceive('key')->once()->andReturn('id');
		$this->expectException("/^Resource `post` not found in model/");

		Resources::get($request, compact('model') + array(
			'name' => 'post', 'call' => array('first') + compact('conditions')
		));
	}

	/**
	 * Tests that queries returning empty sets trigger an exception if the `'required'` option is
	 * set to `true`.
	 */
	public function testQueryingEmptySet() {
		$model = Mockery::mock("alias:li3_resources\models\Foos");
		$conditions = array('foo' => 'bar');
		$request = $this->_request('GET');

		$model->shouldReceive('all')->once()->with(compact('conditions'))->andReturn(
			new Collection()
		);

		$result = Resources::get($request, compact('model') + array(
			'name' => 'foo',
			'required' => false,
			'call' => array('all') + compact('conditions')
		));
		$this->assertIdentical(array(), $result->to('array'));

		$model->shouldReceive('all')->once()->andReturn(new Collection());
		$this->expectException("/^Resource `post` not found in model/");

		$result = Resources::get($this->_request('GET'), compact('model') + array(
			'name' => 'post',
			'call' => array('all')
		));
	}

	/**
	 * Tests that resource parameters with no matching model generate an exception.
	 */
	public function testResourceQueryingWithInvalidModel() {
		$this->expectException('Could not find resource-mapped model class for resource `fzbszl`.');

		$result = Resources::get($this->_request('GET'), array(
			'name' => 'fzbszl',
			'call' => array('first') + compact('conditions')
		));
	}

	/**
	 * Tests that sets of resource configurations can be mapped to resource results.
	 */
	public function testResourceSetMapping() {
		$model = Mockery::mock("alias:li3_resources\models\Bars");
		$conditions = array('foo' => 'bar');

		// $result = Resources::all(array(
		// 	
		// ));
	}

	public function tearDown() {
		Mockery::close();
	}

	protected function _request($method, array $params = array(), array $headers = array()) {
		return new Request(compact('params') + array(
			'env' => array('REQUEST_METHOD' => $method) + $headers
		));
	}
}

?>