<?php
namespace RobertLemke\RackspaceCloudFiles;

/*                                                                        *
 * This script belongs to the package "RobertLemke.RackspaceCloudFiles".  *
 *                                                                        *
 *                                                                        */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Resource\Resource;
use TYPO3\Flow\Resource\Exception;
use TYPO3\Flow\Resource\Storage\StorageInterface;
use TYPO3\Flow\Utility\Files;

/**
 * A resource storage based on Rackspace Cloudfiles
 */
class Storage implements StorageInterface {

	/**
	 * Name which identifies this resource storage
	 *
	 * @var string
	 */
	protected $name;

	/**
	 * Name of the Cloudfiles container which should be used as a storage
	 *
	 * @var string
	 */
	protected $containerName;

	/**
	 * @Flow\Inject
	 * @var \RobertLemke\RackspaceCloudFiles\Service
	 */
	protected $cloudFilesService;

	/**
	 * Constructor
	 *
	 * @param string $name Name of this storage instance, according to the resource settings
	 * @param array $options Options for this storage
	 */
	public function __construct($name, array $options = array()) {
		$this->name = $name;
		$this->containerName = $name;
		foreach ($options as $key => $value) {
			switch ($key) {
				case 'container':
					$this->containerName = $value;
				break;
				default:
					throw new Exception(sprintf('An unknown option "%s" was specified in the configuration of the "%s" resource RackspaceStorage. Please check your settings.', $key, $name), 1362500689);
			}
		}
	}

	/**
	 * Returns the instance name of this storage
	 *
	 * @return string
	 */
	public function getName() {
		return $this->name;
	}

	/**
	 * Returns the Rackspace Cloudfiles container name used as a storage
	 *
	 * @return string
	 */
	public function getContainerName() {
		return $this->containerName;
	}

	/**
	 * Imports a resource (file) as specified in the given upload info array as a
	 * persistent resource.
	 *
	 * On a successful import this method returns a Resource object representing
	 * the newly imported persistent resource.
	 *
	 * @param array $uploadInfo An array detailing the resource to import (expected keys: name, tmp_name)
	 * @return mixed A resource object representing the imported resource or an error message if an error occurred
	 */
	public function importUploadedResource(array $uploadInfo) {
		$pathInfo = pathinfo($uploadInfo['name']);
		$sourcePathAndFilename = $uploadInfo['tmp_name'];
		$originalFilename = $pathInfo['basename'];

		if (!file_exists($sourcePathAndFilename)) {
			return 'The temporary file of the file upload does not exist (anymore).';
		}
		if (!is_uploaded_file($sourcePathAndFilename)) {
			return 'The file specified in the upload info array for being imported as a resource has not been uploaded via HTTP POST.';
		}

		$hash = sha1_file($sourcePathAndFilename);

		$resource = new Resource();
		$resource->setFilename($originalFilename);
		$resource->setHash($hash);

		$headers = array('Content-Disposition' => 'attachment; filename=' . urlencode($originalFilename));
		$this->cloudFilesService->createObject($this->containerName, $hash, fopen($sourcePathAndFilename, 'rb'), $headers);

		return $resource;
	}

	/**
	 * Returns a URI which can be used internally to open / copy the given resource
	 * stored in this storage.
	 *
	 * @param \TYPO3\Flow\Resource\Resource $resource The resource stored in this storage
	 * @return string A temporary URI leading to the resource file
	 */
	public function getPrivateUriByResource(Resource $resource) {
		return $this->cloudFilesService->getTemporaryUri($this->containerName, $resource->getHash());
	}

	/**
	 * Returns a URI which can be used internally to open / copy the given resource
	 * stored in this storage.
	 *
	 * @param string $relativePath A path relative to the storage root.
	 * @return string A temporary URI leading to the resource file
	 */
	public function getPrivateUriByResourcePath($relativePath) {
		return $this->cloudFilesService->getTemporaryUri($this->containerName, ltrim('/', $relativePath));
	}

}

?>