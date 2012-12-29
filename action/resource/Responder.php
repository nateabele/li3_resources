<?php

namespace li3_resources\action\resource;

use Exception;
use Countable;
use lithium\util\Set;
use lithium\util\Inflector;
use lithium\core\ConfigException;
use lithium\net\http\MediaException;

class Responder extends \lithium\core\Object {

	protected $_autoConfig = array(
		'classes' => 'merge',
		'generatedResponses' => 'merge',
		'statusTransitions' => 'merge',
		'responders'
	);

	protected $_classes = array(
		'media' => 'lithium\net\http\Media',
		'router' => 'lithium\net\http\Router',
		'response' => 'lithium\action\Response',
		'entity' => 'lithium\data\Entity'
	);

	protected $_generatedResponses = array(
		'body' => array(200, 201),
		'errorBody' => array(422)
	);

	protected $_responders = array();

	protected $_statusTransitions = array(
		'index' => array(
			array(array(), array(), 200)
		),
		'view' => array(
			array(array(), array(), 200)
		),
		'add' => array(
			array(array(), array('success' => true), 201),
			array(array('exists' => false), array('exists' => true), 201),
			array(array('exists' => false), array('exists' => false), 422)
		),
		'edit' => array(
			array(
				array('exists' => true),
				array('exists' => true, 'validates' => true, 'success' => true),
				200
			),
			array(array(), array('validates' => false), 422)
		),
		'delete' => array(
			array(array('exists' => true), array('exists' => false), 204),
			array(array(), array('exists' => true), 424),
			array(array(), array('success' => false), 424)
		)
	);

	protected function _init() {
		parent::_init();
		$self = $this;
		$url = $this->_url();
		$transitions = $this->_statusTransitions;

		$this->_responders += array(
			'exception' => function($request, array $resources, array $options) {
				if (!$options['data'] instanceof Exception) {
					return $options;
				}
				$result = array(
					'status' => $options['status'] ?: $options['data']->getCode(),
					'data' => array(
						'type' => basename(str_replace('\\', '/', get_class($options['data']))),
						'message' => $options['data']->getMessage()
					)
				);
				return $result + $options;
			},
			'status' => function($request, array $resources, array $options) use ($transitions) {
				if ($options['status'] && is_int($options['status'])) {
					return $options;
				}
				if (!isset($transitions[$options['method']])) {
					throw new ConfigException();
				}
				foreach ($transitions[$options['method']] as $transition) {
					foreach ($options['state'] as $i => $state) {
						$state = (array) $state + $options;

						if (array_intersect_assoc($transition[$i], $state) != $transition[$i]) {
							continue 2;
						}
					}
					return array('status' => $transition[2]) + $options;
				}
				return $options;
			},
			'browser' => function($request, array $resources, array $options) use ($self) {
				if (!$options['requiresView']) {
					return $options;
				}
				$key = lcfirst($options['controller']);
				$id = is_object($options['data']) ? spl_object_hash($options['data']) : null;

				foreach ($resources as $name => $value) {
					if (is_object($value) && spl_object_hash($value) == $id) {
						$key = $name;
						break;
					}
				}

				$options += array('template' => $options['method'], 'layout' => 'default');
				$options['status'] = $options['status'] ?: 200;
				$options['controller'] = Inflector::underscore($options['controller']);
				$options['data'] = array($key => $options['data']);

				if (isset($options['viewData']) && is_callable($options['viewData'])) {
					$options['data'] += $options['viewData']();
				}
				return $options;
			},
			'next' => function($request, array $resources, array $options) use ($url) {
				if (!$options['next'] || $options['location'] || !$options['requiresView']) {
					return $options;
				}
				$location = $url($request, $options['next']);
				return compact('location') + array('status' => 302) + $options;
			},
			'location' => function($request, array $resources, array $options) use ($url) {
				$validStatus = in_array($options['status'], array(201, 301, 302, 303));

				if (!$options['location'] || $options['next'] || !$validStatus) {
					return $options;
				}
				return array('location' => $url($request, $options['location'])) + $options;
			},
			'error' => function($request, array $resources, array $options) {
				$failed = ($options['success'] === false || $options['status'] === 422);
				$isObj = (is_object($options['data']) && method_exists($options['data'], 'errors'));

				if ($isObj && $failed) {
					$options = array('data' => $options['data']->errors()) + $options;
				}
				return $options;
			}
		);
	}

	public function handle($request, array $resources, array $options = array()) {
		$defaults = array(
			'data' => null,
			'status' => null,
			'method' => null,
			'location' => null,
			'next' => null,
			'type' => $request->accepts(),
			'headers' => array(),
			'success' => null,
			'export' => null,
			'requiresView' => $this->_requiresView($request)
		);
		$options += $defaults;
		$classes = $this->_classes;

		foreach ($this->_responders as $name => $responder) {
			$options = $responder($request, $resources, $options);
		}
		$keys = array('status', 'location', 'headers', 'type');
		$config = array_intersect_key($options, array_fill_keys($keys, true));
		$response = $this->_instance('response', compact('request') + $config);

		if (!$response->headers('Vary')) {
			$response->headers('Vary', array('Accept', 'Accept-Encoding'));
		}
		$doExport = ($options['export'] && !$options['requiresView']);
		$data = $doExport ? $this->_export($options['data'], $options['export']) : $options['data'];

		unset($defaults['type'], $defaults['status']);
		$options = array_diff_key($options, $defaults);

		if ($config['location'] && $config['status'] != 201) {
			return $response;
		}
		return $classes['media']::render($response, $data, $options + compact('request'));
	}

	protected function _export($data, $exporter) {
		if ($data instanceof Countable) {
			$result = array();

			foreach ($data as $key => $val) {
				$result[] = $this->_export($val, $exporter);
			}
			return $result;
		}
		if (!$data instanceof $this->_classes['entity']) {
			return $data;
		}
		$result = $exporter($data);
		$fields = is_object($data) && method_exists($data, 'schema') ? $data->schema() : null;

		foreach ($result as $key => $val) {
			if (!is_int($key)) {
				continue;
			}
			if (isset($fields[$val]) || $fields === null) {
				unset($result[$key]);
				$result[$val] = $data->{$val};
			}
		}
		return Set::expand($result);
	}

	/**
	 * Determines whether the response to be generated is of a content type that requires rendering
	 * a template.
	 *
	 * @param object $request A `Request` object instance.
	 * @return boolean Returns `true` if the current request renders a template, otherwise `false`.
	 */
	protected function _requiresView($request) {
		$classes = $this->_classes;
		$accepts = $request->accepts();
		$config = $classes['media']::handlers($accepts);

		if (!$classes['media']::type($accepts) || $config === null) {
			$message = "The application does not understand the requested content type.";
			throw new MediaException($message);
		}
		if ($config === array()) {
			$config = $classes['media']::handlers('default');
		}
		$config += array('view' => null, 'paths' => null);
		return ($config['view'] && $config['paths']);
	}

	protected function _url() {
		$classes = $this->_classes;

		// @todo: Rewrite this to map parameters from resource configuration.
		return function($request, $url) use ($classes) {
			if (is_object($url)) {
				$url = isset($url->_id) ? array('id' => $url->_id) : array();
			}
			return $classes['router']::match($url + array('action' => null), $request, array(
				'absolute' => true
			));
		};
	}

	public function state($object) {
		if (!is_object($object)) {
			return null;
		}
		$handlers = array(
			'lithium\data\Collection' => function($collection) {
				return array('collection' => true, 'exists' => true, 'validates'  => true);
			},
			'lithium\data\Entity' => function($entity) {
				return array(
					'collection' => false,
					'exists' => $entity->exists(),
					'validates' => $entity->validates()
				);
			}
		);

		foreach ($handlers as $class => $handler) {
			if ($object instanceof $class) {
				return $handler($object);
			}
		}
	}

	/**
	 * Handles exceptions raised during the resource action's processing. If `$_handleExceptions` is
	 * set to `true`, this method is used to convert `Exception` objects to responses, using the
	 * exception's code as the HTTP status of the response, and returns an encoded message equal to
	 * the exception's error message.
	 *
	 * @param object $request The current `Request` object.
	 * @param object $e An `Exception` object thrown in the course of executing a `Resource`
	 *        action.
	 * @return object Returns a `Response` object with the encoded error information.
	 */
	// public function _responseFromException($request, $e) {}
}

?>