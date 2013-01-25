<?php
namespace RobertLemke\RackspaceCloudFiles;

/*                                                                        *
 * This script belongs to the package "RobertLemke.RackspaceCloudFiles".  *
 *                                                                        *
 *                                                                        */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Http\Request;
use TYPO3\Flow\Http\Uri;

/**
 * Bridge for the CloudFiles REST service
 *
 * @Flow\Scope("singleton")
 */
class Service {

	/**
	 * @Flow\Inject
	 * @var \TYPO3\Flow\Http\Client\Browser
	 */
	protected $browser;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\Flow\Http\Client\RequestEngineInterface
	 */
	protected $browserRequestEngine;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\Flow\Log\SystemLoggerInterface
	 */
	protected $systemLogger;

	/**
	 * @var string
	 */
	protected $username;

	/**
	 * @var string
	 */
	protected $apiKey;

	/**
	 * @var \TYPO3\Flow\Http\Uri
	 */
	protected $authenticationServiceUri;

	/**
	 * @var string
	 */
	protected $authenticationToken;

	/**
	 * @var string
	 */
	protected $storageToken;

	/**
	 * @var \TYPO3\Flow\Http\Uri
	 */
	protected $storageUri;

	/**
	 * @var string
	 */
	protected $metaDataKey;

	/**
	 * If the meta data key has already set for the remote service
	 *
	 * @var boolean
	 */
	protected $metaDataKeySet = FALSE;

	/**
	 * A cache for recently retrieved containers
	 *
	 * @var array
	 */
	protected $containers = array();

	/**
	 * @param array $settings
	 * @return void
	 */
	public function injectSettings(array $settings) {
		$this->username = $settings['username'];
		$this->apiKey = $settings['apiKey'];
		$this->metaDataKey = $settings['metaDataKey'];
		$this->authenticationServiceUri = $settings['authenticationServiceUri'] . '/v1.0/';
	}

	/**
	 * Initialize this service
	 *
	 * @return void
	 */
	public function initializeObject() {
		$this->browser->setRequestEngine($this->browserRequestEngine);
	}

	/**
	 * Explicitly authenticates with the service.
	 *
	 * This method is called automatically by all other API methods accessing the
	 * service.
	 *
	 * @return void
	 * @api
	 */
	public function authenticate() {
		$request = Request::create(new Uri($this->authenticationServiceUri));
		$request->setHeader('X-Auth-User', $this->username);
		$request->setHeader('X-Auth-Key', $this->apiKey);

		$response = $this->browser->sendRequest($request);

		if ($response->getStatusCode() !== 204) {
			$message = sprintf('Authentication with account "%s" failed: %s', $this->username, $response->getStatus());
			$this->systemLogger->log($message, LOG_ERR);
			throw new Exception($message);
		}
		$this->systemLogger->log(sprintf('Authentication successful with account %s', $this->username), LOG_DEBUG);

		$this->authenticationToken = $response->getHeader('X-Auth-Token');
		$this->storageToken = $response->getHeader('X-Storage-Token');
		$this->storageUri = new Uri($response->getHeader('X-Storage-Url'));

		$this->setMetaDataKey();
	}

	/**
	 * Returns an array of container objects
	 *
	 * @return array
	 * @api
	 */
	public function getContainers() {
		if ($this->authenticationToken === NULL) {
			$this->authenticate();
		}

		$containers = array();
		$request = Request::create(new Uri($this->storageUri . '?format=json'));
		$response = $this->sendRequest($request);

		if ($response->getStatusCode() !== 200) {
			$message = sprintf('Getting containers for account "%s" failed: %s', $this->username, $response->getStatus());
			$this->systemLogger->log($message, LOG_ERR);
			throw new Exception($message);
		}

		foreach (json_decode($response->getContent()) as $details) {
			$containers[] = new Container($details->name, $this->storageUri);
		}

		$this->systemLogger->log(sprintf('Retrieved %s container(s) from account %s', count($containers), $this->username), LOG_DEBUG);

		return $containers;
	}

	/**
	 * Returns the specified container
	 *
	 * @param string $name Name of the container
	 * @return \RobertLemke\RackspaceCloudFiles\Container
	 * @api
	 */
	public function getContainer($name) {
		if ($this->authenticationToken === NULL) {
			$this->authenticate();
		}

		if (isset($this->containers[$name])) {
			return $this->containers[$name];
		}

		$request = Request::create(new Uri($this->storageUri . '/' . urlencode($name) . '?format=json'), 'HEAD');
		$response = $this->sendRequest($request);

		if ($response->getStatusCode() !== 204) {
			$message = sprintf('Getting container "%s" from account "%s" failed: %s', $name, $this->username, $response->getStatus());
			$this->systemLogger->log($message, LOG_ERR);
		}

		$this->systemLogger->log(sprintf('Retrieved container "%s"', $name), LOG_DEBUG);

		$this->containers[$name] = new Container($name, $this->storageUri);
		return $this->containers[$name];
	}

	/**
	 * Removes all objects from the specified container
	 *
	 * @param string $containerName Name of the container
	 * @return void
	 * @api
	 */
	public function flushContainer($containerName) {
		if ($this->authenticationToken === NULL) {
			$this->authenticate();
		}

		$request = Request::create(new Uri($this->storageUri . '/' . urlencode($containerName) . '?format=json'));
		$response = $this->sendRequest($request);

		if ($response->getStatusCode() !== 200) {
			$message = sprintf('Flushing container "%s" failed: %s', $containerName, $response->getStatus());
			$this->systemLogger->log($message, LOG_ERR);
			throw new Exception($message);
		}

		$this->systemLogger->log(sprintf('Flushing container "%s" ...', $containerName), LOG_DEBUG);

		$counter = 0;
		foreach (json_decode($response->getContent()) as $objectName) {
			$counter ++;

			$request = Request::create(new Uri($this->storageUri . '/' . urlencode($containerName) . '/' . urlencode($objectName->name)), 'DELETE');
			$response = $this->sendRequest($request);
			if ($response->getStatusCode() !== 204) {
				$message = sprintf('Flushing container "%s" failed. Could not delete object "%s": %s', $containerName, $objectName, $response->getStatus());
				$this->systemLogger->log($message, LOG_ERR);
				throw new Exception($message);
			}
			$this->systemLogger->log(sprintf('   Deleted "%s"', $objectName->name, $response->getStatus()), LOG_DEBUG);
		}

		$this->systemLogger->log(sprintf('Flushed container "%s" which contained %s object(s).', $containerName, $counter), LOG_DEBUG);
	}

	/**
	 * Creates a new (content) object in the specified container
	 *
	 * @param string $containerName Name of the container
	 * @param string $objectName Name of the content object
	 * @param string|resource $content The actual content to store or a stream resource
	 * @param array $additionalHeaders Additional headers to set, for example array('Content-Disposition' => 'attachment; filename=littlekitten.jpg', ...)
	 * @return void
	 * @api
	 */
	public function createObject($containerName, $objectName, $content, $additionalHeaders = array()) {
		if ($this->authenticationToken === NULL) {
			$this->authenticate();
		}

		$request = Request::create(new Uri($this->storageUri . '/' . urlencode($containerName) . '/' . urlencode($objectName)), 'PUT');
		$request->setContent($content);
		foreach ($additionalHeaders as $fieldName => $value) {
			$request->setHeader($fieldName, $value);
		}
		$response = $this->sendRequest($request);

		if ($response->getStatusCode() !== 201) {
			$message = sprintf('Creating object "%s" in container "%s" failed: %s', $objectName, $containerName, $response->getStatus());
			$this->systemLogger->log($message, LOG_ERR);
			throw new Exception($message);
		}
		$this->systemLogger->log(sprintf('Created object "%s" in container "%s"', $objectName, $containerName), LOG_DEBUG);
	}

	/**
	 * Generates and returns a temporary URI for the given object
	 *
	 * Note that this method does not check if the specified container or object
	 * exists. If the this method has been called before, no further HTTP request
	 * needs to / will be sent in order to render the URI.
	 *
	 * @param string $containerName Name of the container
	 * @param string $objectName Name of the content object
	 * @param integer $ttl Number of seconds until the link should expire
	 * @return \TYPO3\Flow\Http\Uri
	 * @api
	 */
	public function getTemporaryUri($containerName, $objectName, $ttl = 60) {
		if ($this->authenticationToken === NULL) {
			$this->authenticate();
		}
		$expirationTime = time() + $ttl;
		$objectUri = new Uri($this->storageUri . '/' . urlencode($containerName) . '/' . urlencode($objectName));
		$objectPath = $objectUri->getPath();
		$hmacBody = "GET\n$expirationTime\n$objectPath";
		$hmacSignature = hash_hmac('sha1', $hmacBody, $this->metaDataKey);

		return new Uri($objectUri . '?temp_url_sig=' . $hmacSignature . '&temp_url_expires=' . $expirationTime);
	}

	/**
	 * Sends a request and automatically adds the X-Auth-Token
	 *
	 * @param \TYPO3\Flow\Http\Request $request
	 * @return \TYPO3\Flow\Http\Response
	 */
	protected function sendRequest(Request $request) {
		if ($this->authenticationToken === NULL) {
			$this->authenticate();
		}
		$request->setHeader('X-Auth-Token', $this->authenticationToken);
		return $this->browser->sendRequest($request);
	}

	/**
	 * Sets the Account Meta Data Key
	 *
	 * @return void
	 */
	protected function setMetaDataKey() {
		$request = Request::create($this->storageUri, 'POST');
		$request->setHeader('X-Account-Meta-Temp-Url-Key', $this->metaDataKey);
		$response = $this->sendRequest($request);
		if (substr($response->getStatusCode(), 0, 1) !== '2') {
			$message = sprintf('Failed setting the account meta data key for account %s: ', $this->username, $response->getStatus());
			$this->systemLogger->log($message, LOG_ERR);
			throw new Exception($message);
		}
		$this->systemLogger->log(sprintf('Successfully set the meta data key for account %s', $this->username), LOG_DEBUG);
	}

}

?>