<?php
/**
 * View Class for XML
 *
 * @author Jonathan Dalrymple
 * @author kvz
 */
App::uses('BluntXml', 'Rest.Lib');

class XmlView extends View {
	public $bluntXml;

	public function __construct($controller) {
		$this->bluntXml = new BluntXml();

		parent::__construct($controller);
	}

	public function render ($view = null, $layout = null) {
		if (!array_key_exists('response', $this->viewVars)) {
			trigger_error('viewVar "response" should have been set by Rest component already', E_USER_ERROR);

			return false;
		}

		return $this->encode($this->viewVars['response']);
	}

	public function headers ($Controller, $settings) {
		if ($settings['debug'] > 2) {
			return null;
		}

		header('Content-Type: application/xml');

		$Controller->RequestHandler->respondAs('xml');

		return true;
	}

	public function encode ($response) {
		return $this->bluntXml->encode($response, Inflector::tableize($this->request->params['controller']) . '_response');
	}
}