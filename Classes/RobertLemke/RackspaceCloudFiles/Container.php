<?php
namespace RobertLemke\RackspaceCloudFiles;

/*                                                                        *
 * This script belongs to the package "RobertLemke.RackspaceCloudFiles".  *
 *                                                                        *
 *                                                                        */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Http\Uri;
use TYPO3\Flow\Http\Request;

/**
 * Representation of a container
 *
 * The actual actions are delegated to the service.
 */
class Container {

	/**
	 * @Flow\Inject
	 * @var \RobertLemke\RackspaceCloudFiles\Service
	 */
	protected $service;

	/**
	 * @var string
	 */
	protected $name;

	/**
	 * Constructor
	 *
	 * @param string $name Name of this container
	 */
	public function __construct($name) {
		$this->name = $name;
	}

	/**
	 * Returns the name of this container
	 *
	 * @return string
	 * @api
	 */
	public function getName() {
		return $this->name;
	}

	/**
	 * Enables or disables CDN for this container
	 *
	 * @param boolean $state On or off (TRUE or FALSE)
	 * @param integer $ttl Time to live (seconds) for CDN stored assets – minimum: 900
	 * @return void
	 */
	public function setContentDeliveryNetwork($state, $ttl) {
		$this->service->setContentDeliveryNetwork($this->name, $state, $ttl);
	}

	/**
	 * Creates a new (content) object in this container
	 *
	 * @param string $name Name of the content object (will be urlencoded)
	 * @param string $content The actual content to store
	 * @return void
	 * @api
	 */
	public function createObject($name, $content) {
		$this->service->createObject($this->name, $name, $content);
	}

	/**
	 * Deletes a (content) object from this container
	 *
	 * @param string $name Name fo the content object (will be urlencoded)
	 * @return void
	 * @api
	 */
	public function deleteObject($name) {
		$this->service->deleteObject($this->name, $name);
	}

	/**
	 * Removes all objects from this container
	 *
	 * @return void
	 * @api
	 */
	public function flush() {
		$this->service->flushContainer($this->name);
	}
}

?>