<?php
Class RestComponent extends Component {
	public $codes = array(
		200 => 'OK',
		400 => 'Bad Request',
		401 => 'Unauthorized',
		402 => 'Payment Required',
		403 => 'Forbidden',
		404 => 'Not Found',
		405 => 'Method Not Allowed',
		406 => 'Not Acceptable',
		407 => 'Proxy Authentication Required',
		408 => 'Request Time-out',
		500 => 'Internal Server Error',
		501 => 'Not Implemented',
		502 => 'Bad Gateway',
		503 => 'Service Unavailable',
		504 => 'Gateway Time-out',
	);

	public $controller;
	public $request;
	public $postData;

	protected $_RestLog;
	protected $_View;
	protected $_logData     = array();
	protected $_feedback    = array();
	protected $_credentials = array();
	protected $_aborting    = false;

	public $settings = array(
		// Component options
		'callbacks' => array(
			'cbRestlogBeforeSave' => 'restlogBeforeSave',
			'cbRestlogAfterSave' => 'restlogAfterSave',
			'cbRestlogBeforeFind' => 'restlogBeforeFind',
			'cbRestlogAfterFind' => 'restlogAfterFind',
			'cbRestlogFilter' => 'restlogFilter',
			'cbRestRatelimitMax' => 'restRatelimitMax',
		),
		'extensions' => array('xml', 'json'),
		'viewsFromPlugin' => true,
		'skipControllers' => array( // Don't show these as actual rest controllers even though they have the component attached
			'App',
			'Defaults',
		),
		'auth' => array(
			'requireSecure' => false,
			'keyword' => 'TRUEREST',
			'fields' => array(
				'class' => 'class',
				'apikey' => 'apikey',
				'username' => 'username',
			),
		),
		'exposeVars' => array(
			'*' => array(
				'method' => 'get|post|put|delete',
				'id' => 'true|false',
			),
			'index' => array(
				'scopeVar' => 'scope|rack_name|any_other_varname_to_specify_scope',
			),
		),
		'defaultVars' => array(
			'index' => array(
				'scopeVar' => 'scope',
				'method' => 'get',
				'id' => false,
			),
			'view' => array(
				'scopeVar' => 'scope',
				'method' => 'get',
				'id' => true,
			),
			'edit' => array(
				'scopeVar' => 'scope',
				'method' => 'put',
				'id' => true,
			),
			'add' => array(
				'scopeVar' => 'scope',
				'method' => 'put',
				'id' => false,
			),
			'delete' => array(
				'scopeVar' => 'scope',
				'method' => 'delete',
				'id' => true,
			),
		),
		'log' => array(
			'model' => 'Rest.RestLog',
			'pretty' => true,
			// Optionally, choose to store some log fields on disk, instead of in the database
			'fields' => array(
				'data_in' => '{LOGS}rest-{date_Y}_{date_m}/{username}_{id}_1_{field}.log',
				'meta' => '{LOGS}rest-{date_Y}_{date_m}/{username}_{id}_2_{field}.log',
				'data_out' => '{LOGS}rest-{date_Y}_{date_m}/{username}_{id}_3_{field}.log',
			),
		),
		'meta' => array(
			'enable' => true,
			'requestKeys' => array(
				'HTTP_HOST',
				'HTTP_USER_AGENT',
				'REMOTE_ADDR',
				'REQUEST_METHOD',
				'REQUEST_TIME',
				'REQUEST_URI',
				'SERVER_ADDR',
				'SERVER_PROTOCOL',
			),
		),
		'ratelimit' => array(
			'enable' => true,
			'default' => 'Customer',
			'classlimits' => array(
				'Employee' => array('-1 hour', 1000),
				'Customer' => array('-1 hour', 100),
			),
			'identfield' => 'apikey',
			'ip_limit' => array('-1 hour', 60),  // For those not logged in
		),
		'version' => '0.3',
		'actions' => array(
			'view' => array(
				'extract' => array(),
			),
		),
		'debug' => 2,
		'onlyActiveWithAuth' => false,
		'catchredir' => false,
	);

	/**
	 * Should the rest plugin be active?
	 *
	 * @var string
	 */
	public $isActive = null;

	public function __construct(ComponentCollection $collection, $settings = array()) {
		if (is_array($config = Configure::read('Rest.settings'))) {
			$settings = Set::merge($this->settings, $config);
		}

		$settings = array_merge($this->settings, (array)$settings);

		$this->controller = $collection->getController();

		$this->request = $this->controller->request;

		parent::__construct($collection, $settings);
	}

	public function initialize(&$controller) {
		if (!$this->isActive()) {
			return;
		}

		// Control Debug
		$this->settings['debug'] = (int)$this->settings['debug'];
		Configure::write('debug', $this->settings['debug']);

		// Set credentials
		$this->credentials(true);

		// Prepare log
		$this->log(array(
			'controller' => $this->controller->name,
			'action' => $this->controller->action,
			'model_id' => @$this->request->passedArgs[0]
				? @$this->request->passedArgs[0]
				: 0,
			'ratelimited' => 0,
			'requested' => date('Y-m-d H:i:s'),
			'ip' => $_SERVER['REMOTE_ADDR'],
			'httpcode' => 200,
		));

		// Validate & Modify Post
		$this->postData = $this->_modelizePost($this->request->data);

		if ($this->postData === false) {
			return $this->abort('Invalid post data');
		}

		// SSL
		if (!empty($this->settings['auth']['requireSecure'])) {
			if (!isset($this->controller->Security) || !is_object($this->controller->Security)) {
				return $this->abort('You need to enable the Security component first');
			}

			$this->controller->Security->requireSecure($this->settings['auth']['requireSecure']);
		}

		// Set content-headers
		$this->headers();
	}

	/**
	 * Catch & fire callbacks. You can map callbacks to different places
	 * using the value parts in $this->settings['callbacks'].
	 * If the resolved callback is a string we assume it's in
	 * the controller.
	 *
	 * @param string $name
	 * @param array  $arguments
	 */
	public function  __call($name, $arguments) {
		if (!isset($this->settings['callbacks'][$name])) {
			return $this->abort('Function does not exist: '. $name);
		}

		$cb = $this->settings['callbacks'][$name];
		if (is_string($cb)) {
			$cb = array($this->controller, $cb);
		}

		if (is_callable($cb)) {
			array_unshift($arguments, $this);

			return call_user_func_array($cb, $arguments);
		}
	}


	/**
	 * Write the accumulated logentry
	 *
	 * @param <type> $controller
	 */
	public function shutdown(&$controller) {
		if (!$this->isActive()) {
			return;
		}

		$this->log(array(
			'responded' => date('Y-m-d H:i:s'),
		));

		$this->log(true);
	}

	/**
	 * Controls layout & view files
	 *
	 * @param <type> $controller
	 * @return <type>
	 */
	public function startup(&$controller) {
		if (!$this->isActive()) {
			return;
		}

		// Rate Limit
		if (@$this->settings['ratelimit']['enable']) {
			$credentials = $this->credentials();

			if ($class = @$credentials['class']) {
				list($time, $max) = $this->settings['ratelimit']['classlimits'][$class];

				$cbMax = $this->cbRestRatelimitMax($credentials);
				if ($cbMax) {
					$max = $cbMax;
				}

				if (true !== ($count = $this->ratelimit($time, $max))) {
					$msg = sprintf('You have reached your ratelimit (%s is more than the allowed %s requests in %s)', $count, $max,	str_replace('-', '', $time));

					$this->log('ratelimited', 1);

					return $this->abort($msg);
				}
			} else $this->warning('Unable to establish class');
		}
		if ($this->settings['viewsFromPlugin']) {
			// Setup the controller so it can use
			// the view inside this plugin
			$this->controller->view = 'Rest.' . $this->View(false);
		}

		// Dryrun
		if (($this->controller->_restMeta = @$_POST['meta'])) {
			if (@$this->controller->_restMeta['dryrun']) {
				$this->warning('Dryrun active, not really executing your command');
				$this->abort();
			}
		}
	}

	/**
	 * Collects viewVars, reformats, and makes them available as
	 * viewVar: response for use in REST serialization
	 *
	 * @param <type> $controller
	 *
	 * @return <type>
	 */
	public function beforeRender(&$controller) {
		if (!$this->isActive()) {
			return;
		}

		$extract = @$this->settings['actions'][$this->controller->action]['extract'];

		$data = ($extract) ? $this->inject((array)$extract, $this->controller->viewVars) : $this->controller->viewVars;

		$this->controller->set('response', $this->response($data));

		// if a callback function is requested, pass the callback name to the controller
		// responds if following query parameters present: jsoncallback, callback
		$callback = false;

		$json_callback_keys = array('jsoncallback', 'callback');

		foreach ($json_callback_keys as $key) {
			if (array_key_exists($key, $this->request->params['url'])) {
				$callback = $this->request->params['url'][$key];
			}
		}

		if ($callback) {
			if (preg_match('/\W/', $callback)) {
				return $this->abort('Prevented request. Your callback is vulnerable to XSS attacks. ');
			}

			$this->controller->set('callbackFunc', $callback);
		}
	}

	/**
	 * Determines is an array is numerically indexed
	 *
	 * @param array $array
	 *
	 * @return boolean
	 */
	public function numeric($array = array()) {
		if (empty($array)) {
			return null;
		}

		$keys = array_keys($array);

		foreach ($keys as $key) {
			if (!is_numeric($key)) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Prepares REST data for cake interaction
	 *
	 * @param <type> $data
	 * @return <type>
	 */
	protected function _modelizePost(&$data) {
		if (!is_array($data)) {
			return $data;
		}

		// Don't throw errors if data is already modelized
		// f.e. sending a serialized FormHelper form via ajax
		if (isset($data[$this->controller->modelClass])) {
			$data = $data[$this->controller->modelClass];
		}

		// Protected against Saving multiple models in one post
		// while still allowing mass-updates in the form of:
		// $this->request->data[1][field] = value;
		if (Set::countDim($data) === 2) {
			if (!$this->numeric($data)) {
				return $this->error('2 dimensional can only begin with numeric index');
			}
		} else if (Set::countDim($data) !== 1) {
			return $this->error('You may only send 1 dimensional posts');
		}

		// Return encapsulated in controller model
		return array($this->controller->modelClass => $data);
	}

	/**
	 * Works together with Logging to ratelimit incomming requests by
	 * identfield
	 *
	 * @return <type>
	 */
	public function ratelimit($time, $max) {

		// No rate limit active
		if (empty($this->settings['ratelimit'])) {
			return true;
		}

		// Need logging
		if (empty($this->settings['log']['model'])) {
			return $this->abort('Logging is required for any ratelimiting to work');
		}

		// Need identfield
		if (empty($this->settings['ratelimit']['identfield'])) {
			return $this->abort('Need a identfield or I will not know what to ratelimit on');
		}

		$userField = $this->settings['ratelimit']['identfield'];

		$userId	= $this->credentials($userField);

		$this->cbRestlogBeforeFind();

		if ($userId) { // If you're logged in
			$logs = $this->RestLog()->find('list', array(
				'fields' => array('id', $userField),
				'conditions' => array(
					$this->RestLog()->alias . '.requested >' => date('Y-m-d H:i:s', strtotime($time)),
					$this->RestLog()->alias . '.' . $userField => $userId,
				),
			));
		} else { // IP based rate limiting
			$max  = $this->settings['ratelimit']['ip_limit'];
			$logs = $this->RestLog()->find('list', array(
				'fields' => array('id', $userField),
				'conditions' => array(
					$this->RestLog()->alias . '.requested >' => date('Y-m-d H:i:s', strtotime($time)),
					$this->RestLog()->alias . '.ip' => $this->_logData['ip'],
				),
			));
		}

		$this->cbRestlogAfterFind();

		return (count($logs) >= $max) ? $count : true;
	}

	/**
	 * Return an instance of the log model
	 *
	 * @return object
	 */
	public function RestLog() {
		if (!$this->_RestLog) {
			$this->_RestLog = ClassRegistry::init($this->settings['log']['model']);

			$this->_RestLog->restLogSettings = $this->settings['log'];

			$this->_RestLog->restLogSettings['controller'] = $this->controller->name;

			$this->_RestLog->Encoder = $this->View(true);
		}

		return $this->_RestLog;
	}

	/**
	 * log(true) writes log to disk. otherwise stores key-value
	 * pairs in memory for later saving. Can also work recursively
	 * by giving an array as the key
	 *
	 * @param mixed $key
	 * @param mixed $val
	 *
	 * @return boolean
	 */
	public function log($key, $val = null) {
		if ($key === true && func_num_args() === 1) { // Write log
			if (!@$this->settings['log']['model']) {
				return true;
			}

			$this->RestLog()->create();

			$this->cbRestlogBeforeSave();

			$log = array($this->RestLog()->alias => $this->_logData);

			$log = $this->cbRestlogFilter($log);

			$res = (is_array($log)) ? $this->RestLog()->save($log) : null;

			$this->cbRestlogAfterSave();

			return $res;
		}

		// Multiple values: recurse
		if (is_array($key)) {
			foreach ($key as $k=>$v) {
				$this->log($k, $v);
			}

			return true;
		}

		// Single value, save
		$this->_logData[$key] = $val;

		return true;
	}

	/**
	 * Sets or returns credentials as found in the 'Authorization' header
	 * sent by the client.
	 *
	 * Have your client set a header like:
	 * Authorization: TRUEREST username=john&password=xxx&apikey=247b5a2f72df375279573f2746686daa<
	 * http://docs.amazonwebservices.com/AmazonS3/2006-03-01/index.html?RESTAuthentication.html
	 *
	 * credentials(true) sets credentials
	 * credentials() returns full array
	 * credentials('username') returns username
	 *
	 * @param mixed boolean or string $set
	 *
	 * @return <type>
	 */
	public function credentials($set = false) {
		if ($set === false) {// Return full credentials
			return $this->_credentials;
		}

		if ($set === true) {// Set credentials
			if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
				$parts = explode(' ', $_SERVER['HTTP_AUTHORIZATION']);

				$match = array_shift($parts);

				if ($match !== $this->settings['auth']['keyword']) {
					return false;
				}

				$str = join(' ', $parts);

				parse_str($str, $this->_credentials);

				if (!isset($this->_credentials[$this->settings['auth']['fields']['class']])) {
					$this->_credentials[$this->settings['auth']['fields']['class']] = $this->settings['ratelimit']['default'];
				}

				$this->log(array(
					'username' => $this->_credentials[$this->settings['auth']['fields']['username']],
					'apikey' => $this->_credentials[$this->settings['auth']['fields']['apikey']],
					'class' => $this->_credentials[$this->settings['auth']['fields']['class']],
				));
			}

			return $this->_credentials;
		}

		if (is_string($set)) { // Return 1 field
			if (null !== ($val = @$this->_credentials[$set])) { // First try key as is
				return $val;
			}

			// Fallback to the mapped key according to authfield settings
			if (null !== ($val = @$this->_credentials[$this->settings['auth']['fields'][$set]])) {
				return $val;
			}

			return null;
		}

		return $this->abort('credential argument not supported');
	}

	/**
	 * Returns a list of controllers where Rest component has been activated
	 * uses Cache::read & Cache::write by default to tackle performance
	 * issues.
	 *
	 * @param boolean $cached
	 *
	 * @return array
	 */
	public function controllers($cached = true) {
		$ckey = sprintf('%s.%s', __CLASS__, __FUNCTION__);

		if (!$cached || !($restControllers = Cache::read($ckey))) {
			$restControllers = array();

			$controllers = App::objects('controller', null, false);

			// Unlist some controllers by default
			foreach ($this->settings['skipControllers'] as $skipController) {
				if (false !== ($key = array_search($skipController, $controllers))) {
					unset($controllers[$key]);
				}
			}

			// Instantiate all remaining controllers and check components
			foreach ($controllers as $controller) {
				$className = $controller.'Controller';

				$debug = false;
				if (!class_exists($className)) {
					App::uses($className, 'Controller');
					if (!App::load($className)) {
						continue;
					}
				}

				$controller = new $className();

				if (isset($controller->components['Rest.Rest']['actions']) && is_array($controller->components['Rest.Rest']['actions'])) {
					$exposeActions = array();

					foreach ($controller->components['Rest.Rest']['actions'] as $action => $vars) {
						if (!in_array($action, $controller->methods)) {
							$this->debug(sprintf(
								'Rest component is expecting a "%s" action but got "%s" instead. ' .
								'You probably upgraded your component without reading the backward compatiblity ' .
								'warnings in the readme file, or just did not implement the "%s" action in the "%s" controller yet',
								$controller->name, $action, $action, $controller->name
							));

							continue;
						}

						$saveVars = array();

						$exposeVars = array_merge(
							$this->settings['exposeVars']['*'],
							isset($this->settings['exposeVars'][$action]) ? $this->settings['exposeVars'][$action] : array()
						);

						foreach ($exposeVars as $exposeVar => $example) {
							if (isset($vars[$exposeVar])) {
								$saveVars[$exposeVar] = $vars[$exposeVar];
							} else {
								if (isset($this->settings['defaultVars'][$action][$exposeVar])) {
									$saveVars[$exposeVar] = $this->settings['defaultVars'][$action][$exposeVar];
								} else {
									return $this->abort(sprintf(
										'Rest maintainer needs to set "%s" for %s using ' .
										'%s->components->Rest.Rest->actions[\'%s\'][\'%s\'] = %s',
										$exposeVar, $action, $className, $action, $exposeVar, $example
									));
								}
							}
						}

						$exposeActions[$action] = $saveVars;
					}

					$restControllers[$controller] = $exposeActions;
				}

				unset($controller);
			}

			ksort($restControllers);

			if ($cached) {
				Cache::write($ckey, $restControllers);
			}
		}

		return $restControllers;
	}

	/**
	 * Set content-type headers based on extension
	 *
	 * @param <type> $ext
	 *
	 * @return <type>
	 */
	public function headers($ext = null) {
		return $this->View(true, $ext)->headers($this->controller, $this->settings);
	}

	public function isActive() {
		if ($this->isActive === null) {
			if (!isset($this->controller) || !is_object($this->controller)) {
				return false;
			}

			if ($this->settings['onlyActiveWithAuth'] === true) {
				$keyword = $this->settings['auth']['keyword'];

				return $this->isActive = ($keyword && strpos(@$_SERVER['HTTP_AUTHORIZATION'], $keyword) === 0);
			}

			if (!isset($this->request->params['url']['ext']) || empty($this->request->params['url']['ext'])) {
				return false;
			}

			return $this->isActive = in_array($this->request->params['url']['ext'], $this->settings['extensions']);
		}

		return $this->isActive;
	}

	public function validate($format, $arg1 = null, $arg2 = null) {
		$args = func_get_args();

		if (count($args) > 1) $format = vsprintf($format, $args);

		$this->_feedback['error'][] = 'validation: ' . $format;

		return false;
	}

	public function error($format, $arg1 = null, $arg2 = null) {
		$args = func_get_args();

		if (count($args) > 1) $format = vsprintf($format, $args);

		$this->_feedback[__FUNCTION__][] = $format;

		return false;
	}

	public function debug($format, $arg1 = null, $arg2 = null) {
		$args = func_get_args();

		if (count($args) > 1) $format = vsprintf($format, $args);

		$this->_feedback[__FUNCTION__][] = $format;

		return true;
	}

	public function info($format, $arg1 = null, $arg2 = null) {
		$args = func_get_args();

		if (count($args) > 1) $format = vsprintf($format, $args);

		$this->_feedback[__FUNCTION__][] = $format;

		return true;
	}

	public function warning($format, $arg1 = null, $arg2 = null) {
		$args = func_get_args();

		if (count($args) > 1) $format = vsprintf($format, $args);

		$this->_feedback[__FUNCTION__][] = $format;

		return false;
	}

	/**
	 * Returns (optionally) formatted feedback.
	 *
	 * @param boolean $format
	 *
	 * @return array
	 */
	public function getFeedBack($format = false) {
		if (!$format) {
			return $this->_feedback;
		}

		$feedback = array();

		foreach ($this->_feedback as $level => $messages) {
			foreach ($messages as $i => $message) {
				$feedback[] = array('message' => $message, 'level' => $level);
			}
		}

		return $feedback;
	}

	/**
	 * Reformats data according to Xpaths in $take
	 *
	 * @param array $take
	 * @param array $viewVars
	 *
	 * @return array
	 */
	public function inject($take, $viewVars) {
		$data = array();

		foreach ($take as $path => $dest) {
			if (is_numeric($path)) {
				$path = $dest;
			}

			$data = Set::insert($data, $dest, Set::extract($path, $viewVars));
		}

		return $data;
	}

	/**
	 * Get an array of everything that needs to go into the Xml / Json
	 *
	 * @param array $data optional. Data collected by cake
	 *
	 * @return array
	 */
	public function response($data = array()) {
		// In case of edit, return what post data was received
		if (empty($data) && !empty($this->postData)) {
			$data = $this->postData;

			// In case of add, enrich the postdata with the primary key of the
			// added record. Nice if you e.g. first create a parent, and then
			// immediately need the ID to add it's children
			if (!empty($this->controller->modelClass)) {
				$modelClass = $this->controller->modelClass;

				if (!empty($data[$modelClass]) && ($model = @$this->controller->{$modelClass})) {
					if (empty($data[$modelClass][$model->primaryKey]) && $model->id) {
						$data[$modelClass][$model->primaryKey] = $model->id;
					}
				}

				// import validation errors
				if (($modelErrors = @$this->controller->{$modelClass}->validationErrors)) {
					if (is_array($modelErrors)) {
						$modelErrors = join('; ', $modelErrors);
					}

					$this->validate($modelErrors);
				}
			}
		}

		$feedback = $this->getFeedBack(true);

		$hasErrors = count(@$this->_feedback['error']);
		$hasValidationErrors = count(@$this->_feedback['validate']);

		$time = time();
		$status = ($hasErrors || $hasValidationErrors) ? 'error' : 'ok';

		$response = (false === ($embed = @$this->settings['actions'][$this->controller->action]['embed'])) ? $data : compact('data');

		if ($this->settings['meta']['enable']) {
			$serverKeys = array_flip($this->settings['meta']['requestKeys']);

			$server = array_intersect_key($_SERVER, $serverKeys);

			foreach ($server as $k=>$v) {
				if ($k === ($lc = strtolower($k))) {
					continue;
				}

				$server[$lc] = $v;

				unset($server[$k]);
			}

			$response['meta'] = array(
				'status' => $status,
				'feedback' => $feedback,
				'request' => $server,
				'credentials' => array(),
				'time_epoch' => gmdate('U', $time),
				'time_local' => date('r', $time),
			);

			if (!empty($this->settings['version'])) {
				$response['meta']['version'] = $this->settings['version'];
			}

			foreach ($this->_settings['auth']['fields'] as $field) {
				$response['meta']['credentials'][$field] = $this->credentials($field);
			}
		}

		$dump = array(
			'data_in' => $this->postData,
			'data_out' => $data,
		);

		if ($this->settings['meta']['enable']) {
			$dump['meta'] = $response['meta'];
		}

		$this->log($dump);

		return $response;
	}

	/**
	 * Returns either string or reference to active View object
	 *
	 * @param boolean $object
	 * @param string  $ext
	 *
	 * @return mixed object or string
	 */
	public function View($object = true, $ext = null) {
		if (!$this->isActive()) {
			return $this->abort('Rest not activated. Maybe try correct extension.');
		}

		if ($ext === null) {
			$ext = $this->request->params['url']['ext'];
		}

		$base = Inflector::camelize($ext);

		if (!$object) {
			return $base;
		}

		// Keep 1 instance of the active View in ->_View
		if (!$this->_View) {
			$className = $base . 'View';

			App::uses($className, 'Rest.View');

			$this->_View = new $className($this->controller);

			$this->controller->viewClass = $base;

			if (empty($this->_View->params)) {
				$this->_View->params = $this->request->params;
			}
		}

		return $this->_View;
	}

	public function beforeRedirect(&$controller, $url, $status = null, $exit = true) {
		if (@$this->settings['catchredir'] === false) {
			return;
		}

		if (!$this->isActive()) {
			return true;
		}

		$redirect = true; // Where is this even used?

		$this->abort(compact('url', 'status', 'exit', 'redirect'));

		return false;
	}

	/**
	 * Could be called by e.g. ->redirect to dump
	 * an error & stop further execution.
	 *
	 * @param <type> $params
	 * @param <type> $data
	 */
	public function abort($params = array(), $data = array()) {
		if ($this->_aborting) {
			return;
		}

		$this->_aborting = true;

		if (is_string($params)) {
			$code = '403';
			$error = $params;
		} else {
			$code = '200';
			$error = '';

			if (is_object($this->controller->Session) && @$this->controller->Session->read('Message.auth')) {
				// Automatically fetch Auth Component Errors
				$code = '403';
				$error = $this->controller->Session->read('Message.auth.message');
				$this->controller->Session->delete('Message.auth');
			}

			if (!empty($params['status'])) {
				$code = $params['status'];
			}

			if (!empty($params['error'])) {
				$error = $params['error'];
			}

			if (empty($error) && !empty($params['redirect'])) {
				$this->debug('Redirect prevented by rest component. ');
			}
		}

		if ($error) {
			$this->error($error);
		}

		$message = (!$error && isset($this->codes[$code])) ? $this->codes[$code] : $error;

		$this->controller->header(sprintf('HTTP/1.1 %s %s', $code, $message));

		$this->headers();

		$encoded = $this->View()->encode($this->response($data));

		// Die.. ugly. but very safe. which is what we need
		// or all Auth & Acl work could be circumvented
		$this->log(array(
			'httpcode' => $code,
			'error' => $error,
		));

		$this->shutdown($this->controller);

		die($encoded);
	}
}