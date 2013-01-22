<?php
namespace RobertLemke\RackspaceCloudFiles;

/*                                                                        *
 * This script belongs to the package "RobertLemke.RackspaceCloudFiles".  *
 *                                                                        *
 *                                                                        */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Resource\Publishing\AbstractResourcePublishingTarget;
use TYPO3\Flow\Utility\Files;

/**
 * Publishing target for Rackspace CloudFiles
 *
 * @Flow\Scope("singleton")
 */
class PublishingTarget extends AbstractResourcePublishingTarget {

	/**
	 * @Flow\Inject
	 * @var \RobertLemke\RackspaceCloudFiles\Service
	 */
	protected $cloudFilesService;

	/**
	 * Recursively publishes static resources located in the specified directory.
	 * These resources are typically public package resources provided by the active packages.
	 *
	 * @param string $sourcePath The full path to the source directory which should be published (includes sub directories)
	 * @param string $relativeTargetPath Path relative to the target's root where resources should be published to.
	 * @return boolean TRUE if publication succeeded or FALSE if the resources could not be published
	 */
	public function publishStaticResources($sourcePath, $relativeTargetPath) {
		if (!is_dir($sourcePath)) {
			return FALSE;
		}
		$sourcePath = rtrim(Files::getUnixStylePath($this->realpath($sourcePath)), '/');
		$container = $this->cloudFilesService->getContainer('gurumanage-resources');

		foreach (Files::readDirectoryRecursively($sourcePath) as $sourcePathAndFilename) {
			if (substr(strtolower($sourcePathAndFilename), -4, 4) === '.php') {
				continue;
			}
			$targetPathAndFilename = str_replace($sourcePath, '', $sourcePathAndFilename);
			$container->createObject($targetPathAndFilename, 'file://' . $sourcePathAndFilename);
		}

		return TRUE;
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
	}

	/**
	 * Returns the base URI pointing to the published static resources
	 *
	 * @return string The base URI pointing to web accessible static resources
	 */
	public function getStaticResourcesWebBaseUri() {
	}

	/**
	 * Returns the web URI pointing to the published persistent resource
	 *
	 * @param \TYPO3\Flow\Resource\Resource $resource The resource to publish
	 * @return mixed Either the web URI of the published resource or FALSE if the resource source file doesn't exist or the resource could not be published for other reasons
	 */
	public function getPersistentResourceWebUri(\TYPO3\Flow\Resource\Resource $resource) {
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