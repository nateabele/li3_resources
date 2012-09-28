<?php

namespace li3_resources\action\resource;

use Exception;
use lithium\net\ConfigException;
use lithium\net\http\MediaException;

class Responder extends \lithium\core\StaticObject {

	protected static $_classes = array(
		'media' => 'lithium\net\http\Media',
		'router' => 'lithium\net\http\Router'
	);

	protected static $_generatedResponses = array(
		'location' => array(201, 301, 302, 303),
		'body' => array(200, 201),
		'errorBody' => array(422)
	);

	protected static $_statusCodes = array(
		'index' => array(
			array(array(), array(), 200)
		),
		'view' => array(
			array(array(), array(), 200)
		),
		'add' => array(
			array(array('exists' => false), array('exists' => true), 201),
			array(array('exists' => false), array('exists' => false), 422)
		),
		'edit' => array(
			array(array(), array('exists' => true, 'validates' => true, 'status' => true), 200)
		),
		'delete' => array(
			array(array('exists' => true), array('exists' => false), 204)
		)
	);

	public static function handle($request, $response, array $options = array()) {
		$classes = static::$_classes;
		$defaults = array('data' => null, 'status' => null);
		$options += $defaults;
		$result = null;

		if (is_bool($options['status']) && $options['data']) {
			$options['status'] = static::_status($options);
		}

		foreach (static::handlers() as $name => $handler) {
			if ($result = call_user_func($handler, $request, $response, $options)) {
				break;
			}
		}

		if (!$result) {
			return $response;
		}
		$response->status($result['status']);
		$response->type($request->accepts());
		$options = array_diff_key($result, $defaults) + $options;

		foreach (static::_generators() as $key => $func) {
			if (!in_array($response->status['code'], static::$_generatedResponses[$key])) {
				continue;
			}
			$response = $func($request, $response, $result['data'], $options);
		}
		return $response;
	}

	protected static function _status(array $options = array()) {
		if (!isset(static::$_statusCodes[$options['method']])) {
			throw new ConfigException();
		}
		foreach (static::$_statusCodes[$options['method']] as $transition) {
			$transition = array_combine(array('before', 'after', 'status'), $transition);

			foreach (array('before', 'after') as $key) {
				$options[$key] += compact('status');

				if (array_intersect_assoc($transition[$key], $options[$key]) != $transition[$key]) {
					continue 2;
				}
			}
			return $transition['status'];
		}
	}

	public static function requiresView($request) {
		$classes = static::$_classes;
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

	public static function handlers() {
		$class = get_called_class();

		return array(
			'exception' => array($class, '_responseFromException'),
			'eventFromTransition' => array($class, '_responseEventFromTransition'),
			'browserView' => array($class, '_responseForBrowser')
		);
	}

	protected static function _generators() {
		$classes = static::$_classes;

		return array(
			'location' => function($request, $response, $data, $options) use ($classes) {
				$url = array('id' => $data->_id) + $options['params'];
				$location = $classes['router']::match($url, $request, array('absolute' => true));
				$response->headers("location", $location);
				return $response;
			},
			'body' => function($request, $response, $data, $options) use ($classes) {
				// @hack: Replace this with handlers():
				if ($data instanceof Exception) {
					$data = Responder::_responseFromException($request, $data);
				}
				return $classes['media']::render($response, $data, $options + compact('request'));
			},
			'errorBody' => function($request, $response, $data, $options) use ($classes) {
				$data = $data->errors();
				return $classes['media']::render($response, $data, $options + compact('request'));
			}
		);
	}

	public static function state($object) {
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
	public static function _responseFromException($request, $e) {
		if (!$e instanceof Exception) {
			return false;
		}
		return array('status' => $e->getCode(), 'data' => array('message' => $e->getMessage()));
	}

	protected static function _responseEventFromTransition($request, $response, array $options) {
		return false;
	}

	protected static function _responseForBrowser($request, $response, array $options) {
		return array(
			'status' => $options['status'] ?: 200,
			'data' => $options['data']
		);
	}
}

?>