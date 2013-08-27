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
	 * @var \TYPO3\Flow\Http\Client\CurlEngine
	 */
	protected $browserRequestEngine;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\Flow\Log\SystemLoggerInterface
	 */
	protected $systemLogger;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\Flow\Cache\Frontend\StringFrontend
	 */
	protected $authenticationCache;

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
	 * @var \TYPO3\Flow\Http\Uri
	 */
	protected $cdnManagementUri;

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
	 * Base URIs of the Content Delivery Network for each container
	 *
	 * @var array
	 */
	protected $cdnUris = array();

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
		$this->browserRequestEngine->setOption(CURLOPT_TIMEOUT, 120);
		$this->browser->setRequestEngine($this->browserRequestEngine);
	}

	/**
	 * Explicitly authenticates with the service.
	 *
	 * This method is called automatically by all other API methods accessing the
	 * service.
	 *
	 * @return void
	 * @throws Exception
	 * @api
	 */
	public function authenticate() {
		$authenticationInfo = $this->authenticationCache->get('authenticationInfo');
		if ($authenticationInfo !== FALSE) {
			$this->systemLogger->log(sprintf('Authentication token still valid for account %s', $this->username), LOG_DEBUG);
			$this->authenticationToken = $authenticationInfo['authenticationToken'];
			$this->storageToken = $authenticationInfo['storageToken'];
			$this->storageUri = $authenticationInfo['storageUri'];
			$this->cdnManagementUri = $authenticationInfo['cdnManagementUri'];
		} else {
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
			$this->cdnManagementUri = new Uri($response->getHeader('X-CDN-Management-Url'));

			$authenticationInfo = array();
			$authenticationInfo['authenticationToken'] = $this->authenticationToken;
			$authenticationInfo['storageToken'] = $this->storageToken;
			$authenticationInfo['storageUri'] = $this->storageUri;
			$authenticationInfo['cdnManagementUri'] = $this->cdnManagementUri;
			$this->authenticationCache->set('authenticationInfo', $authenticationInfo, array(), 1380);

			$this->setMetaDataKey();
		}
	}

	/**
	 * Returns an array of container objects
	 *
	 * @return array<\RobertLemke\RackspaceCloudFiles\Container>
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
			throw new Exception($message, 1362516313);
		}

		$this->systemLogger->log(sprintf('Retrieved container "%s"', $name), LOG_DEBUG);

		$this->containers[$name] = new Container($name, $this->storageUri);
		return $this->containers[$name];
	}

	/**
	 * Enables mirroring into the Content Delivery Network for the specified container
	 *
	 * @param string $containerName Name of the container to make public
	 * @param boolean $state On or off (TRUE or FALSE)
	 * @param integer $ttl Time to live (seconds) for CDN stored assets – minimum: 900
	 * @return void
	 * @api
	 */
	public function setContentDeliveryNetwork($containerName, $state, $ttl = 900) {
		if ($this->authenticationToken === NULL) {
			$this->authenticate();
		}

		$request = Request::create(new Uri($this->cdnManagementUri . '/' . urlencode($containerName) . '?format=json'), 'PUT');
		$request->setHeader('X-CDN-Enabled', ($state ? 'True' : 'False'));
		$request->setHeader('X-TTL', $ttl);
		$response = $this->sendRequest($request);

		if ($response->getStatusCode() !== 201 && $response->getStatusCode() !== 202) {
			$message = sprintf('Setting the CDN flag of container "%s" failed: %s', $containerName, $response->getStatus());
			$this->systemLogger->log($message, LOG_ERR);
			throw new Exception($message);
		}

		$this->cdnUris[$containerName] = array(
			'http' => $response->getHeader('X-Cdn-Uri'),
			'https' => $response->getHeader('X-Cdn-Ssl-Uri'),
			'ios' => $response->getHeader('X-Cdn-Ios-Uri'),
			'streaming' => $response->getHeader('X-Cdn-Streaming-Uri')
		);
	}

	/**
	 * Removes all objects from the specified container
	 *
	 * @param string $containerName Name of the container
	 * @return void
	 * @throws Exception
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
		foreach (json_decode($response->getContent()) as $object) {
			$counter ++;

			$request = Request::create(new Uri($this->storageUri . '/' . urlencode($containerName) . '/' . $this->encodeObjectName($object->name)), 'DELETE');
			$response = $this->sendRequest($request);
			if ($response->getStatusCode() !== 204) {
				$message = sprintf('Flushing container "%s" failed. Could not delete object "%s": %s', $containerName, $object->name, $response->getStatus());
				$this->systemLogger->log($message, LOG_ERR);
				throw new Exception($message);
			}
			$this->systemLogger->log(sprintf('   Deleted "%s"', $object->name, $response->getStatus()), LOG_DEBUG);
		}

		$this->systemLogger->log(sprintf('Flushed container "%s" which contained %s object(s).', $containerName, $counter), LOG_DEBUG);
	}

	/**
	 * Returns a list of objects of the given container.
	 *
	 * @param string $containerName Name of the container
	 * @param string $format Either NULL (means "filename"), "xml", or "json"
	 * @return array Either an array of filenames (default), or an array of JSON / XML data with more information
	 * @api
	 */
	public function listObjects($containerName, $format = NULL) {
		if ($this->authenticationToken === NULL) {
			$this->authenticate();
		}

		$request = Request::create(new Uri($this->storageUri . '/' . urlencode($containerName) . ($format !== NULL ? '?format=' . $format : '')));
		$response = $this->sendRequest($request);

		if ($response->getStatusCode() !== 200) {
			$message = sprintf('Could not list objects of container "%s": %s', $containerName, $response->getStatus());
			$this->systemLogger->log($message, LOG_ERR);
			throw new Exception($message);
		}

		switch ($format) {
			case 'json':
				$objects = array();
				foreach (json_decode($response->getContent(), TRUE) as $objectInfo) {
					$objects[$objectInfo['name']] = $objectInfo;
				}
			break;
			case 'xml':

			default:
				$objects = explode(chr(10), $response->getContent());
		}

		$this->systemLogger->log(sprintf('Retrieved list of objects in container "%s" (contains %s objects).', $containerName, count($objects)), LOG_DEBUG);
		return $objects;
	}

	/**
	 * Checks if a (content) object with the given name in the specified container exists.
	 *
	 * If an md5 hash is specified, this function only returns TRUE if the object exists and has the same md5 checksum.
	 *
	 * @param string $containerName Name of the container
	 * @param string $objectName Name of the content object
	 * @param string $md5Hash Optional md5 checksum of the expected content
	 * @return boolean TRUE if the object exists, otherwise FALSE
	 * @api
	 */
	public function objectExists($containerName, $objectName, $md5Hash = NULL) {
		if ($this->authenticationToken === NULL) {
			$this->authenticate();
		}

		$request = Request::create(new Uri($this->storageUri . '/' . urlencode($containerName) . '/' . $this->encodeObjectName($objectName)), 'HEAD');
		$response = $this->sendRequest($request);

		if ($response->getStatusCode() === 200) {
			return ($md5Hash === NULL) ? TRUE : ($response->getHeader('Etag') === $md5Hash);
		}

		return FALSE;
	}

	/**
	 * Creates a new (content) object in the specified container
	 *
	 * @param string $containerName Name of the container
	 * @param string $objectName Name of the content object
	 * @param string|resource $content The actual content to store or a stream resource
	 * @param array $additionalHeaders Additional headers to set, for example array('Content-Disposition' => 'attachment; filename=littlekitten.jpg', ...)
	 * @param string $md5Hash An MD5 hash of the content. If none is specified and $content is a string, an MD5 hash will be calculated automatically
	 * @throws Exception
	 * @return void
	 * @api
	 */
	public function createObject($containerName, $objectName, $content, $additionalHeaders = array(), $md5Hash = NULL) {
		if ($this->authenticationToken === NULL) {
			$this->authenticate();
		}

		if ($md5Hash === NULL && is_string($content)) {
			$md5Hash = md5($content);
		}

		$request = Request::create(new Uri($this->storageUri . '/' . urlencode($containerName) . '/' . $this->encodeObjectName($objectName)), 'PUT');
		$request->setContent($content);
		foreach ($additionalHeaders as $fieldName => $value) {
			$request->setHeader($fieldName, $value);
		}
		if ($md5Hash !== NULL) {
			$request->setHeader('Etag', $md5Hash);
		}
		$response = $this->sendRequest($request);

		switch ($response->getStatusCode()) {
			case 201:
				$this->systemLogger->log(sprintf('Created object "%s" in container "%s" with MD5 hash "%s"', $objectName, $containerName, $md5Hash ?: 'unknown'), LOG_DEBUG);
			break;
			case 422:
				$message = sprintf('Creating object "%s" in container "%s" failed: the data was corrupted during upload (MD5 hashes did not match)', $objectName, $containerName, $response->getStatus());
				$this->systemLogger->log($message, LOG_ERR);
				throw new Exception($message);
			break;
			default:
				$message = sprintf('Creating object "%s" in container "%s" failed: %s', $objectName, $containerName, $response->getStatus());
				$this->systemLogger->log($message, LOG_ERR);
				throw new Exception($message);
		}
	}

	/**
	 * Deletes an existing (content) object in the specified container
	 *
	 * @param string $containerName Name of the container
	 * @param string $objectName Name of the content object
	 * @return void
	 * @throws Exception
	 * @api
	 */
	public function deleteObject($containerName, $objectName) {
		if ($this->authenticationToken === NULL) {
			$this->authenticate();
		}
		$request = Request::create(new Uri($this->storageUri . '/' . urlencode($containerName) . '/' . $this->encodeObjectName($objectName)), 'DELETE');
		$response = $this->sendRequest($request);

		if ($response->getStatusCode() !== 204) {
			$message = sprintf('Deleting object "%s" in container "%s" failed: %s', $objectName, $containerName, $response->getStatus());
			$this->systemLogger->log($message, LOG_ERR);
			throw new Exception($message);
		}
		$this->systemLogger->log(sprintf('Deleted object "%s" in container "%s"', $objectName, $containerName), LOG_DEBUG);
	}

	/**
	 * Creates a new (content) object in the specified container
	 *
	 * @param string $sourceContainerName Name of the source container
	 * @param string $sourceObjectName Name of the source content object
	 * @param string $targetContainerName Name of the target container
	 * @param string $targetObjectName Name of the target content object
	 * @param array $additionalHeaders Additional headers to set for the target object, for example array('Content-Disposition' => 'attachment; filename=littlekitten.jpg', ...)
	 * @throws Exception
	 * @return void
	 * @api
	 */
	public function copyObject($sourceContainerName, $sourceObjectName, $targetContainerName, $targetObjectName, $additionalHeaders = array()) {
		if ($this->authenticationToken === NULL) {
			$this->authenticate();
		}

		$request = Request::create(new Uri($this->storageUri . '/' . urlencode($sourceContainerName) . '/' . $this->encodeObjectName($sourceObjectName)), 'COPY');
		$request->setHeader('Destination', '/' . urlencode($targetContainerName) . '/' . $this->encodeObjectName($targetObjectName));
		foreach ($additionalHeaders as $fieldName => $value) {
			$request->setHeader($fieldName, $value);
		}
		$response = $this->sendRequest($request);

		if ($response->getStatusCode() !== 201) {
			$message = sprintf('Copying object "%s" in container "%s" to container "%s" failed: %s', $sourceObjectName, $sourceContainerName, $targetContainerName, $response->getStatus());
			$this->systemLogger->log($message, LOG_ERR);
			throw new Exception($message);
		}
		$this->systemLogger->log(sprintf('Copied object "%s" from container "%s" to container "%s" as object "%s"', $sourceObjectName, $sourceContainerName, $targetContainerName, $targetObjectName), LOG_DEBUG);
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
		$objectUri = new Uri($this->storageUri . '/' . urlencode($containerName) . '/' . $this->encodeObjectName($objectName));
		$objectPath = $objectUri->getPath();
		$hmacBody = "GET\n$expirationTime\n$objectPath";
		$hmacSignature = hash_hmac('sha1', $hmacBody, $this->metaDataKey);
		return new Uri($objectUri . '?temp_url_sig=' . $hmacSignature . '&temp_url_expires=' . $expirationTime);
	}

	/**
	 * Generates and returns a public CDN URI for the given object
	 *
	 * Note that this method does not check if the specified container or object
	 * exists. If the this method has been called before, no further HTTP request
	 * needs to / will be sent in order to render the URI.
	 *
	 * @param string $containerName Name of the container
	 * @param string $objectName Name of the content object
	 * @param string $scheme Either "http", "https", "ios" or "streaming"
	 * @return \TYPO3\Flow\Http\Uri
	 * @api
	 */
	public function getPublicUri($containerName, $objectName, $scheme = 'http') {
		if ($this->authenticationToken === NULL) {
			$this->authenticate();
		}
		if (!isset($this->cdnUris[$containerName])) {
			$this->setContentDeliveryNetwork($containerName, TRUE);
		}
		return new Uri($this->cdnUris[$containerName][$scheme] . '/' . $this->encodeObjectName($objectName));
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

	/**
	 * Will urlencode() the given object name, but keep foward slashes intact.
	 *
	 * @param string $objectName
	 * @return string
	 */
	protected function encodeObjectName($objectName) {
		$objectName = urlencode($objectName);
		$objectName = str_replace('%2F', '/', $objectName);
		$objectName = str_replace('%20', '+', $objectName);
		return $objectName;
	}

}

?>