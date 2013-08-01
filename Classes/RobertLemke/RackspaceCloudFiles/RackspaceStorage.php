<?php
namespace RobertLemke\RackspaceCloudFiles;

/*                                                                        *
 * This script belongs to the package "RobertLemke.RackspaceCloudFiles".  *
 *                                                                        *
 *                                                                        */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Resource\Resource;
use TYPO3\Flow\Resource\Storage\Exception as StorageException;
use TYPO3\Flow\Resource\Storage\Object;
use TYPO3\Flow\Resource\Storage\WritableStorageInterface;
use TYPO3\Flow\Utility\Files;

/**
 * A resource storage based on Rackspace Cloudfiles
 */
class RackspaceStorage implements WritableStorageInterface {

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
	 * @Flow\Inject
	 * @var \TYPO3\Flow\Utility\Environment
	 */
	protected $environment;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\Flow\Resource\ResourceRepository
	 */
	protected $resourceRepository;

	/**
	 * Constructor
	 *
	 * @param string $name Name of this storage instance, according to the resource settings
	 * @param array $options Options for this storage
	 * @throws Exception
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
					if ($value !== NULL) {
						throw new Exception(sprintf('An unknown option "%s" was specified in the configuration of the "%s" resource RackspaceStorage. Please check your settings.', $key, $name), 1362500689);
					}
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
	 * Imports a resource (file) from the given URI or PHP resource stream into this storage.
	 *
	 * On a successful import this method returns a Resource object representing the newly
	 * imported persistent resource.
	 *
	 * @param string | resource $source The URI (or local path and filename) or the PHP resource stream to import the resource from
	 * @param string $collectionName Name of the collection the new Resource belongs to
	 * @return Resource A resource object representing the imported resource
	 * @throws \TYPO3\Flow\Resource\Storage\Exception
	 * TODO: Dont upload file again if it already exists
	 */
	public function importResource($source, $collectionName) {
		if (is_resource($source)) {
			throw new StorageException('Could not import resource because stream resources are not yet implemented for RackspaceStorage.', 1375266667);
		}

		$pathInfo = pathinfo($source);
		$originalFilename = $pathInfo['basename'];
		$temporaryTargetPathAndFilename = $this->environment->getPathToTemporaryDirectory() . uniqid('RobertLemke_RackspaceCloudFiles_');

		if (copy($source, $temporaryTargetPathAndFilename) === FALSE) {
			throw new StorageException(sprintf('Could not copy the file from "%s" to temporary file "%s".', $source, $temporaryTargetPathAndFilename), 1375266771);
		}

		$sha1Hash = sha1_file($temporaryTargetPathAndFilename);

		$resource = new Resource();
		$resource->setFilename($originalFilename);
		$resource->setCollectionName($collectionName);
		$resource->setSha1($sha1Hash);

		$headers = array('Content-Disposition' => 'attachment; filename=' . urlencode($originalFilename));
		$this->cloudFilesService->createObject($this->containerName, $sha1Hash, fopen($temporaryTargetPathAndFilename, 'rb'), $headers);

		return $resource;
	}

	/**
	 * Imports a resource (file) as specified in the given upload info array as a
	 * persistent resource.
	 *
	 * On a successful import this method returns a Resource object representing
	 * the newly imported persistent resource.
	 *
	 * @param array $uploadInfo An array detailing the resource to import (expected keys: name, tmp_name)
	 * @param string $collectionName Name of the collection this uploaded resource should be part of
	 * @return string A resource object representing the imported resource
	 * @throws Exception
	 */
	public function importUploadedResource(array $uploadInfo, $collectionName) {
		$pathInfo = pathinfo($uploadInfo['name']);
		$originalFilename = $pathInfo['basename'];
		$sourcePathAndFilename = $uploadInfo['tmp_name'];

		if (!file_exists($sourcePathAndFilename)) {
			throw new Exception(sprintf('The temporary file "%s" of the file upload does not exist (anymore).', $sourcePathAndFilename), 1375267007);
		}

		$newSourcePathAndFilename = $this->environment->getPathToTemporaryDirectory() . 'RobertLemke_RackspaceCloudFiles_' . uniqid() . '.tmp';
		if (move_uploaded_file($sourcePathAndFilename, $newSourcePathAndFilename) === FALSE) {
			throw new Exception(sprintf('The uploaded file "%s" could not be moved to the temporary location "%s".', $sourcePathAndFilename, $newSourcePathAndFilename), 1375267045);
		}
		$sha1Hash = sha1_file($newSourcePathAndFilename);

		$resource = new Resource();
		$resource->setFilename($originalFilename);
		$resource->setCollectionName($collectionName);
		$resource->setSha1($sha1Hash);

		$headers = array('Content-Disposition' => 'attachment; filename=' . urlencode($originalFilename));
		$this->cloudFilesService->createObject($this->containerName, $sha1Hash, fopen($newSourcePathAndFilename, 'rb'), $headers);

		return $resource;
	}

	/**
	 * Deletes the storage data related to the given Resource object
	 *
	 * @param \TYPO3\Flow\Resource\Resource $resource The Resource to delete the storage data of
	 * @return boolean TRUE if removal was successful
	 */
	public function deleteResource(Resource $resource) {
		$this->cloudFilesService->deleteObject($this->containerName, $resource->getSha1());
		return TRUE;
	}

	/**
	 * Returns a URI which can be used internally to open / copy the given resource
	 * stored in this storage.
	 *
	 * @param \TYPO3\Flow\Resource\Resource $resource The resource stored in this storage
	 * @return string A temporary URI leading to the resource file
	 */
	public function getPrivateUriByResource(Resource $resource) {
		return $this->cloudFilesService->getTemporaryUri($this->containerName, $resource->getSha1());
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

	/**
	 * Retrieve all Objects stored in this storage, filtered by the given collection name
	 *
	 * @param string $collectionName
	 * @return array<\TYPO3\Flow\Resource\Storage\Object>
	 * @api
	 */
	public function getObjectsByCollectionName($collectionName) {
		$objects = array();
		foreach ($this->resourceRepository->findByCollectionName($collectionName) as $resource) {
			/** @var \TYPO3\Flow\Resource\Resource $resource */
			$object = new Object();
			$object->setFilename($resource->getFilename());
			$object->setSha1($resource->getSha1());
			$object->setDataUri($this->cloudFilesService->getTemporaryUri($this->containerName, $resource->getSha1()));
			$objects[] = $object;
		}
		return $objects;
	}

}

?>