<?php
namespace RobertLemke\RackspaceCloudFiles;

/*                                                                        *
 * This script belongs to the package "RobertLemke.RackspaceCloudFiles".  *
 *                                                                        *
 *                                                                        */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Resource\Publishing\AbstractResourcePublishingTarget;
use TYPO3\Flow\Resource\Resource;
use TYPO3\Flow\Utility\Files;

/**
 * Publishing target for Rackspace CloudFiles
 *
 * NOTE: In its current state this publishing target does not publish static
 *       resources and defers that task to the FileSystemPublishingTarget
 */
class PublishingTarget extends AbstractResourcePublishingTarget {

	/**
	 * @Flow\Inject
	 * @var \RobertLemke\RackspaceCloudFiles\Service
	 */
	protected $cloudFilesService;

	/**
	 * This is just a preliminary solution: once publishStaticResources() etc. is
	 * implemented, the FileSystemPublishingTarget should not be used anymore and
	 * it should be modified to be prototype instead of singleton.
	 *
	 * @Flow\Inject
	 * @var \TYPO3\Flow\Resource\Publishing\FileSystemPublishingTarget
	 */
	protected $fileSystemPublishingTarget;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\Flow\Persistence\PersistenceManagerInterface
	 */
	protected $persistenceManager;

	/**
	 * Name of the CloudFiles container where static resource will be stored.
	 *
	 * @var string
	 */
	protected $staticResourcesContainerName = 'typo3-flow-static-resources';

	/**
	 * Name of the CloudFiles container where persistent resource will be stored.
	 * Note that this container should be non-public!
	 *
	 * @var string
	 */
	protected $persistentResourcesContainerName = 'typo3-flow-persistent-resources';

	/**
	 * Recursively publishes static resources located in the specified directory.
	 * These resources are typically public package resources provided by the active packages.
	 *
	 * @param string $sourcePath The full path to the source directory which should be published (includes sub directories)
	 * @param string $relativeTargetPath Path relative to the target's root where resources should be published to.
	 * @return boolean TRUE if publication succeeded or FALSE if the resources could not be published
	 */
	public function publishStaticResources($sourcePath, $relativeTargetPath) {
		return $this->fileSystemPublishingTarget->publishStaticResources($sourcePath, $relativeTargetPath);

			// TODO: Finish implementation
		if (!is_dir($sourcePath)) {
			return FALSE;
		}
		$sourcePath = rtrim(Files::getUnixStylePath($this->realpath($sourcePath)), '/');
		$container = $this->cloudFilesService->getContainer($this->staticResourcesContainerName);

		foreach (Files::readDirectoryRecursively($sourcePath) as $sourcePathAndFilename) {
			if (substr(strtolower($sourcePathAndFilename), -4, 4) === '.php') {
				continue;
			}
			$targetPathAndFilename = str_replace($sourcePath, '', $sourcePathAndFilename);
			$headers = array('Content-Disposition' => 'attachment; filename=' . urlencode(basename($sourcePathAndFilename)));
			$container->createObject($targetPathAndFilename, 'file://' . $sourcePathAndFilename, $headers);
		}
		return TRUE;
	}

	/**
	 * Imports a persistent resource from the specified source
	 *
	 * TODO: Also support stream resources as source
	 *
	 * @param string $source The URI of the source
	 * @param string $originalFilename The original filename, for example "Butterfly.jpg"
	 * @param integer $size The size of the upload - if it is not known, this parameter can be omitted
	 * @return \TYPO3\Flow\Resource\Resource The generated Resource object
	 */
	public function importPersistentResource($source, $originalFilename, $size = NULL) {
		$hash = sha1_file($source);
		$resource = $this->createResourceFromHashAndFilename($hash, $originalFilename);

		$headers = array('Content-Disposition' => 'attachment; filename=' . urlencode($originalFilename));
		$this->cloudFilesService->createObject($this->persistentResourcesContainerName, $hash, fopen($source, 'rb'), $headers);

		return $resource;
	}

	/**
	 * Publishes a persistent resource to the web accessible resources directory.
	 *
	 * @param \TYPO3\Flow\Resource\Resource $resource The resource to publish
	 * @return mixed Either the web URI of the published resource or FALSE if the resource source file doesn't exist or the resource could not be published for other reasons
	 */
	public function publishPersistentResource(\TYPO3\Flow\Resource\Resource $resource) {
	}

	/**
	 * Unpublishes a persistent resource in the web accessible resources directory.
	 *
	 * @param \TYPO3\Flow\Resource\Resource $resource The resource to unpublish
	 * @return boolean TRUE if at least one file was removed, FALSE otherwise
	 */
	public function unpublishPersistentResource(\TYPO3\Flow\Resource\Resource $resource) {
	}

	/**
	 * Returns the base URI where persistent resources are published an accessible from the outside.
	 *
	 * @return \TYPO3\Flow\Http\Uri The base URI
	 */
	public function getResourcesBaseUri() {
	}

	/**
	 * Returns the publishing path where resources are published in the local filesystem
	 * @return string The resources publishing path
	 */
	public function getResourcesPublishingPath() {
		return $this->fileSystemPublishingTarget->getResourcesPublishingPath();
	}

	/**
	 * Returns the base URI pointing to the published static resources
	 *
	 * @return string The base URI pointing to web accessible static resources
	 */
	public function getStaticResourcesWebBaseUri() {
		return $this->fileSystemPublishingTarget->getStaticResourcesWebBaseUri();
	}

	/**
	 * Returns the web URI pointing to the published persistent resource
	 *
	 * @param \TYPO3\Flow\Resource\Resource $resource The resource to publish
	 * @return mixed Either the web URI of the published resource or FALSE if the resource source file doesn't exist or the resource could not be published for other reasons
	 */
	public function getPersistentResourceWebUri(\TYPO3\Flow\Resource\Resource $resource) {
		return $this->cloudFilesService->getTemporaryUri($this->persistentResourcesContainerName, $resource->getResourcePointer()->getHash());
	}

	/**
	 * Wrapper around realpath(). Needed for testing, as realpath() cannot be mocked
	 * by vfsStream.
	 *
	 * @param string $path
	 * @return string
	 */
	protected function realpath($path) {
		return realpath($path);
	}

	/**
	 * Creates a resource object from a given hash and filename. The according
	 * resource pointer is fetched automatically.
	 *
	 * @param string $resourceHash The SHA1 hash of the content of the resource
	 * @param string $originalFilename The original filename, for example "foo.jpg"
	 * @return \TYPO3\Flow\Resource\Resource The created resource
	 * @api
	 * FIXME Put into abstract resource storage
	 */
	protected function createResourceFromHashAndFilename($resourceHash, $originalFilename) {
		$resource = new Resource();
		$resource->setFilename($originalFilename);

		$resourcePointer = $this->getResourcePointerForHash($resourceHash);
		$resource->setResourcePointer($resourcePointer);

		return $resource;
	}

	/**
	 * Helper function which creates or fetches a resource pointer object for a given hash.
	 *
	 * If a ResourcePointer with the given hash exists, this one is used. Else, a new one
	 * is created. This is a workaround for missing ValueObject support in Doctrine.
	 *
	 * @param string $hash
	 * @return \TYPO3\Flow\Resource\ResourcePointer
	 * FIXME Put into abstract resource storage
	 */
	protected function getResourcePointerForHash($hash) {
		$resourcePointer = $this->persistenceManager->getObjectByIdentifier($hash, 'TYPO3\Flow\Resource\ResourcePointer');
		if (!$resourcePointer) {
			$resourcePointer = new \TYPO3\Flow\Resource\ResourcePointer($hash);
			$this->persistenceManager->add($resourcePointer);
		}

		return $resourcePointer;
	}


}


?>