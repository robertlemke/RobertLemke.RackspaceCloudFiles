<?php
namespace RobertLemke\RackspaceCloudFiles;

/*                                                                        *
 * This script belongs to the package "RobertLemke.RackspaceCloudFiles".  *
 *                                                                        *
 *                                                                        */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Resource\Collection;
use TYPO3\Flow\Resource\CollectionInterface;
use TYPO3\Flow\Resource\Exception;
use TYPO3\Flow\Resource\Resource;
use TYPO3\Flow\Resource\ResourceManager;
use TYPO3\Flow\Resource\ResourceMetaDataInterface;
use TYPO3\Flow\Resource\Storage\Object;
use TYPO3\Flow\Resource\Target\TargetInterface;

/**
 * A resource publishing target based on Rackspace CloudFiles
 */
class RackspaceTarget implements TargetInterface {

	/**
	 * Name which identifies this resource target
	 *
	 * @var string
	 */
	protected $name;

	/**
	 * If Content Delivery Network should be enabled this array contains the respective
	 * base URIs
	 *
	 * @var array
	 */
	protected $cdn = array();

	/**
	 * Name of the CloudFiles container which should be used for publication
	 *
	 * @var string
	 */
	protected $containerName;

	/**
	 * CORS (Cross-Origin Resource Sharing) allowed origins for published content
	 *
	 * @var string
	 */
	protected $corsAllowOrigin = '*';

	/**
	 * Internal cache for known storages, indexed by storage name
	 *
	 * @var array<\TYPO3\Flow\Resource\Storage\StorageInterface>
	 */
	protected $storages = array();

	/**
	 * @Flow\Inject
	 * @var Service
	 */
	protected $cloudFilesService;

	/**
	 * @Flow\Inject
	 * @var ResourceManager
	 */
	protected $resourceManager;

	/**
	 * @var array
	 */
	protected $existingObjectsInfo;

	/**
	 * Constructor
	 *
	 * @param string $name Name of this target instance, according to the resource settings
	 * @param array $options Options for this target
	 * @throws Exception
	 */
	public function __construct($name, array $options = array()) {
		$this->name = $name;
		foreach ($options as $key => $value) {
			switch ($key) {
				case 'container':
					$this->containerName = $value;
				break;
				case 'corsAllowOrigin':
					$this->corsAllowOrigin = $value;
				break;
				case 'cdn':
					if (!is_array($value)) {
						throw new Exception(sprintf('The option "%s" was specified in the configuration of the "%s" resource RackspaceTarget was not a valid array. Please check your settings.', $key, $name), 1362517645);
					}
					if ($value !== array() && (!isset($value['http']) || !isset($value['https']))) {
						throw new Exception(sprintf('The option "%s" was specified in the configuration of the "%s" resource RackspaceTarget either must be an empty array or contain base URIs for http and https.', $key, $name), 1362517647);
					}
					$this->cdn['http'] = rtrim($value['http'], '/') . '/';
					$this->cdn['https'] = rtrim($value['https'], '/') . '/';
				break;
				default:
					if ($value !== NULL) {
						throw new Exception(sprintf('An unknown option "%s" was specified in the configuration of the "%s" resource RackspaceTarget. Please check your settings.', $key, $name), 1362500688);
					}
			}
		}
	}

	/**
	 * Returns the name of this target instance
	 *
	 * @return string The target instance name
	 */
	public function getName() {
		return $this->name;
	}

	/**
	 * Publishes the whole collection to this target
	 *
	 * @param \TYPO3\Flow\Resource\Collection $collection The collection to publish
	 * @return void
	 * @throws Exception
	 */
	public function publishCollection(Collection $collection) {
		if (!isset($this->existingObjectsInfo)) {
			$this->existingObjectsInfo = $this->cloudFilesService->listObjects($this->containerName, 'json');
		}
		$obsoleteObjects = array_fill_keys(array_keys($this->existingObjectsInfo), TRUE);

		$storage = $collection->getStorage();
		if ($storage instanceof RackspaceStorage) {
			$storageContainerName = $storage->getContainerName();
			if ($storageContainerName === $this->containerName) {
				throw new Exception(sprintf('Could not publish collection %s because the source and target Rackspace Cloudfiles container is the same.', $collection->getName()), 1375348241);
			}
			foreach ($collection->getObjects() as $object) {
				/** @var \TYPO3\Flow\Resource\Storage\Object $object */
				$this->cloudFilesService->copyObject($storageContainerName, $object->getSha1(), $this->containerName, $this->getRelativePublicationPathAndFilename($object));
				unset($obsoleteObjects[$this->getRelativePublicationPathAndFilename($object)]);
			}
		} else {
			foreach ($collection->getObjects() as $object) {
				/** @var \TYPO3\Flow\Resource\Storage\Object $object */
				$this->publishFile($object->getStream(), $this->getRelativePublicationPathAndFilename($object), $object);
				unset($obsoleteObjects[$this->getRelativePublicationPathAndFilename($object)]);
			}
		}

		foreach (array_keys($obsoleteObjects) as $relativePathAndFilename) {
			$this->cloudFilesService->deleteObject($this->containerName, $relativePathAndFilename);
		}
	}

	/**
	 * Returns the web accessible URI pointing to the given static resource
	 *
	 * @param string $relativePathAndFilename Relative path and filename of the static resource
	 * @return string The URI
	 */
	public function getPublicStaticResourceUri($relativePathAndFilename) {
		return $this->cloudFilesService->getPublicUri($this->containerName, $relativePathAndFilename);
	}

	/**
	 * Publishes the given persistent resource from the given storage
	 *
	 * @param \TYPO3\Flow\Resource\Resource $resource The resource to publish
	 * @param CollectionInterface $collection The collection the given resource belongs to
	 * @return void
	 * @throws Exception
	 */
	public function publishResource(Resource $resource, CollectionInterface $collection) {
		$storage = $collection->getStorage();
		if ($storage instanceof RackspaceStorage) {
			if ($storage->getContainerName() === $this->containerName) {
				throw new Exception(sprintf('Could not publish resource with SHA1 hash %s of collection %s because the source and target Rackspace Cloudfiles container is the same.', $resource->getSha1(), $collection->getName()), 1375348223);
			}
			$this->cloudFilesService->copyObject($storage->getContainerName(), $resource->getSha1(), $this->containerName, $this->getRelativePublicationPathAndFilename($resource));
		} else {
			$sourceStream = $collection->getStreamByResource($resource);
			if ($sourceStream === FALSE) {
				throw new Exception(sprintf('Could not publish resource with SHA1 hash %s of collection %s because there seems to be no corresponding data in the storage.', $resource->getSha1(), $collection->getName()), 1375342304);
			}
			$this->publishFile($sourceStream, $this->getRelativePublicationPathAndFilename($resource), $resource);
		}
	}

	/**
	 * Unpublishes the given persistent resource
	 *
	 * @param \TYPO3\Flow\Resource\Resource $resource The resource to unpublish
	 * @return void
	 */
	public function unpublishResource(Resource $resource) {
		try {
			$this->cloudFilesService->deleteObject($this->containerName, $this->getRelativePublicationPathAndFilename($resource));
		} catch (\Exception $e) {
		}
	}

	/**
	 * Returns the web accessible URI pointing to the specified persistent resource
	 *
	 * @param \TYPO3\Flow\Resource\Resource $resource Resource object or the resource hash of the resource
	 * @return string The URI
	 * @throws Exception
	 */
	public function getPublicPersistentResourceUri(Resource $resource) {
		if ($this->cdn !== array()) {
			return $this->cloudFilesService->getPublicUri($this->containerName, $this->getRelativePublicationPathAndFilename($resource));
		} else {
			return $this->cloudFilesService->getTemporaryUri($this->containerName, $this->getRelativePublicationPathAndFilename($resource));
		}
	}

	/**
	 * Publishes the specified source file to this target, with the given relative path.
	 *
	 * @param resource $sourceStream
	 * @param string $relativeTargetPathAndFilename
	 * @param ResourceMetaDataInterface $metaData
	 * @throws Exception
	 * @return void
	 */
	protected function publishFile($sourceStream, $relativeTargetPathAndFilename, ResourceMetaDataInterface $metaData) {
		if (!isset($this->existingObjectsInfo)) {
			$this->existingObjectsInfo = $this->cloudFilesService->listObjects($this->containerName, 'json');
		}

		if (!isset($this->existingObjectsInfo[$relativeTargetPathAndFilename]) || $this->existingObjectsInfo[$relativeTargetPathAndFilename]['hash'] !== $metaData->getMd5()) {
			$additionalHeaders = array('Access-Control-Allow-Origin' => $this->corsAllowOrigin);
			$this->cloudFilesService->createObject($this->containerName, $relativeTargetPathAndFilename, $sourceStream, $additionalHeaders, $metaData->getMd5());
			fclose($sourceStream);
		}
	}

	/**
	 * Determines and returns the relative path and filename for the given Storage Object or Resource. If the given
	 * object represents a persistent resource, its own relative publication path will be empty. If the given object
	 * represents a static resources, it will contain a relative path.
	 *
	 * @param ResourceMetaDataInterface $object Resource or Storage Object
	 * @return string The relative path and filename, for example "c828d0f88ce197be1aff7cc2e5e86b1244241ac6/MyPicture.jpg"
	 */
	protected function getRelativePublicationPathAndFilename(ResourceMetaDataInterface $object) {
		if ($object->getRelativePublicationPath() !== '') {
			$pathAndFilename = $object->getRelativePublicationPath() . $object->getFilename();
		} else {
			$pathAndFilename = $object->getSha1() . '/' . $object->getFilename();
		}
		return $pathAndFilename;
	}

}

?>