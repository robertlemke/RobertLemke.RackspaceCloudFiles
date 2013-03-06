<?php
namespace RobertLemke\RackspaceCloudFiles;

/*                                                                        *
 * This script belongs to the package "RobertLemke.RackspaceCloudFiles".  *
 *                                                                        *
 *                                                                        */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Resource\Collection;
use TYPO3\Flow\Resource\Exception;
use TYPO3\Flow\Resource\Resource;
use TYPO3\Flow\Resource\Storage\StorageInterface;
use TYPO3\Flow\Resource\Target\TargetInterface;
use TYPO3\Flow\Utility\Files;

/**
 * A resource publishing target based on Rackspace Cloudfiles
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
	 * Name of the Cloudfiles container which should be used for publication
	 *
	 * @var string
	 */
	protected $containerName;

	/**
	 * Internal cache for known storages, indexed by storage name
	 *
	 * @var array<\TYPO3\Flow\Resource\Storage\StorageInterface>
	 */
	protected $storages = array();

	/**
	 * @Flow\Inject
	 * @var \RobertLemke\RackspaceCloudFiles\Service
	 */
	protected $cloudFilesService;

	/**
	 * @Flow\Inject
	 * @var \TYPO3\Flow\Resource\ResourceManager
	 */
	protected $resourceManager;

	/**
	 * Constructor
	 *
	 * @param string $name Name of this target instance, according to the resource settings
	 * @param array $options Options for this target
	 */
	public function __construct($name, array $options = array()) {
		$this->name = $name;
		foreach ($options as $key => $value) {
			switch ($key) {
				case 'container':
					$this->containerName = $value;
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
					throw new Exception(sprintf('An unknown option "%s" was specified in the configuration of the "%s" resource RackspaceTarget. Please check your settings.', $key, $name), 1362500688);
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
	 */
	public function publish(Collection $collection) {
		foreach ($collection->getDirectories() as $directoryStorageUri) {
			list($storageName, $sourcePath) = explode('://', $directoryStorageUri);
			if (!isset($this->storages[$storageName])) {
				$this->storages[$storageName] = $this->resourceManager->getStorage($storageName);
			}
			$this->publishDirectory($this->storages[$storageName]->getPrivateUriByResourcePath($sourcePath), $sourcePath);
		}
	}

	/**
	 * Returns the web accessible URI pointing to the given static resource
	 *
	 * @param string $relativePathAndFilename Relative path and filename of the static resource
	 * @return string The URI
	 * TODO: ADJUST
	 */
	public function getPublicStaticResourceUri($relativePathAndFilename) {
		return $this->baseUri . $relativePathAndFilename;
	}

	/**
	 * Publishes the given persistent resource from the given storage
	 *
	 * @param \TYPO3\Flow\Resource\Resource $resource The resource to publish
	 * @param \TYPO3\Flow\Resource\Storage\StorageInterface $storage The storage the given resource is stored in
	 * @return boolean
	 */
	public function publishResource(Resource $resource, StorageInterface $storage) {
		if ($storage instanceof Storage && $storage->getContainerName() === $this->containerName) {
			return TRUE;
		}
		$sourcePathAndFilename = $storage->getPrivateUriByResource($resource);
		if ($sourcePathAndFilename === FALSE) {
			return FALSE;
		}

		// TODO: IMPLEMENT
	}

	/**
	 * Returns the web accessible URI pointing to the specified persistent resource
	 *
	 * @param string $resource Resource object or the resource hash of the resource
	 * @return string The URI
	 */
	public function getPublicPersistentResourceUri($resource) {
		if ($resource instanceof Resource) {
			$hash = $resource->getHash();
		}
		if (!isset($hash)) {
			if (!is_string($resource) || strlen($resource) !== 40) {
				throw new \InvalidArgumentException('Specified an invalid resource to getPublishedPersistentResourceUri()', 1362501006);
			}
			$hash = $resource;
		}
		if ($this->cdn !== array()) {
			return $this->cdn['http'] . $hash;
		} else {
			return $this->cloudFilesService->getTemporaryUri($this->containerName, $hash);
		}
	}

	/**
	 * Publishes the specified source directory to this target, with the given
	 * relative path.
	 *
	 * @param string $sourcePath Path of the source directory
	 * @param string $relativeTargetPath relative path in the target directory
	 * @return boolean TRUE if publishing succeeded
	 */
	protected function publishDirectory($sourcePath, $relativeTargetPath) {
		$normalizedSourcePath = rtrim(Files::getUnixStylePath($this->realpath($sourcePath)), '/');
		$targetPath = rtrim(Files::concatenatePaths(array($this->path, $relativeTargetPath)), '/');

		if ($this->mirrorMode === 'link') {
			if (Files::is_link($targetPath) && (rtrim(Files::getUnixStylePath($this->realpath($targetPath)), '/') === $normalizedSourcePath)) {
				return TRUE;
			} elseif (is_dir($targetPath)) {
				Files::removeDirectoryRecursively($targetPath);
			} elseif (is_link($targetPath)) {
				unlink($targetPath);
			} else {
				Files::createDirectoryRecursively(dirname($targetPath));
			}
			symlink($sourcePath, $targetPath);
		} else {
			Files::copyDirectoryRecursively($sourcePath, $targetPath, TRUE);
			return TRUE;
		}
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

}

?>