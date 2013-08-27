<?php
namespace RobertLemke\RackspaceCloudFiles\Command;

/*                                                                        *
 * This script belongs to the package "RobertLemke.RackspaceCloudFiles".  *
 *                                                                        *
 *                                                                        */

use TYPO3\Flow\Annotations as Flow;
use TYPO3\Flow\Cli\CommandController;
use RobertLemke\RackspaceCloudFiles\Exception;

/**
 * RackspaceFiles command controller for the RobertLemke.RackspaceCloudFiles package
 *
 * @Flow\Scope("singleton")
 */
class RackspaceFilesCommandController extends CommandController {

	/**
	 * @Flow\Inject
	 * @var \RobertLemke\RackspaceCloudFiles\Service
	 */
	protected $cloudFilesService;

	/**
	 * Checks the connection
	 *
	 * This command checks if the configured credentials and connectivity allows for
	 * connecting with the Cloudfiles REST service.
	 *
	 * @return void
	 */
	public function connectCommand() {
		try {
			$this->cloudFilesService->authenticate();
		} catch(Exception $e) {
			$this->outputLine($e->getMessage());
			$this->quit(1);
		}
		$this->outputLine('OK');
	}

	/**
	 * Displays a list of containers
	 *
	 * This command outputs a list of all containers from the currently configured
	 * Cloudfiles account.
	 *
	 * @return void
	 */
	public function listContainersCommand() {
		try {
			$containers = $this->cloudFilesService->getContainers();
		} catch(Exception $e) {
			$this->outputLine($e->getMessage());
			$this->quit(1);
		}

		if (count($containers) === 0) {
			$this->outputLine('The account currently does not have any containers.');
		}

		foreach ($containers as $container) {
			$this->outputLine($container->getName());
		}
	}


	/**
	 * Removes all objects from a container
	 *
	 * This command deletes all objects (files) of the given container.
	 *
	 * @param string $container Name of the container
	 * @return void
	 */
	public function flushContainerCommand($container) {
		try {
			$container = $this->cloudFilesService->getContainer($container);
			$container->flush();
		} catch(Exception $e) {
			$this->outputLine($e->getMessage());
			$this->quit(1);
		}
		$this->outputLine('Successfully flushed container %s.', array($container->getName()));
	}

	/**
	 * Sets CDN for a container
	 *
	 * This command enables or disables the usage of the Content Delivery Network for
	 * the specified container.
	 *
	 * @param string $container Name of the container
	 * @param boolean $state State of CDN - either true or false
	 * @param integer $ttl The time to live in seconds, minimum: 900
	 * @return void
	 */
	public function containerCdnCommand($container, $state, $ttl = 900) {
		try {
			$container = $this->cloudFilesService->getContainer($container);
			$container->setContentDeliveryNetwork($state, $ttl);
		} catch(Exception $e) {
			$this->outputLine($e->getMessage());
			$this->quit(1);
		}
		$this->outputLine('Successfully %s CDN for container %s.', array(($state ? 'enabled' : 'disabled'), $container->getName()));
	}

	/**
	 * Upload a file to a container
	 *
	 * This command uploads the file specified by <b>file</b> to the container
	 * specified by <container>. The container must exist already in order to upload
	 * the file.
	 *
	 * @param string $container Name of the container
	 * @param string $file Full path leading to the file to upload
	 * @return void
	 */
	public function uploadCommand($container, $file) {
		if (!file_exists($file)) {
			$this->outputLine('The specified file does not exist.');
			$this->quit(1);
		}

		try {
			$container = $this->cloudFilesService->getContainer($container);
		} catch(Exception $e) {
			$this->outputLine($e->getMessage());
			$this->quit(1);
		}

		$md5Hash = md5_file($file);
		$container->createObject($file, fopen('file://' . realpath($file), 'rb'), array(), $md5Hash);
		$this->outputLine('Sucessfully uploaded %s to %s.', array($file, $container->getName()));
	}

	/**
	 * Delete a file from the container
	 *
	 * This command removes the a file specified by the given <b>object name</b> from
	 * the specified container.
	 *
	 * @param string $container Name of the container the object is contained in
	 * @param string $object Name of the object (file) to be deleted
	 * @return void
	 */
	public function deleteCommand($container, $object) {
		try {
			$container = $this->cloudFilesService->getContainer($container);
			$container->deleteObject($object);
		} catch(Exception $e) {
			$this->outputLine($e->getMessage());
			$this->quit(1);
		}
		$this->outputLine('Successfully removed %s from container %s.', array($object, $container->getName()));
	}

	/**
	 * Copy an object
	 *
	 * This command copies an object which already exists in the source container to the given target container.
	 *
	 * @param string $sourceContainer Name of the container the original object is contained in
	 * @param string $sourceObject Name of the object (file) to copy
	 * @param string $targetContainer Name of the container the object should be copied to
	 * @param string $targetObject Name of the newly created target object
	 * @return void
	 */
	public function copyCommand($sourceContainer, $sourceObject, $targetContainer, $targetObject) {
		try {
			$this->cloudFilesService->copyObject($sourceContainer, $sourceObject, $targetContainer, $targetObject);
		} catch(Exception $e) {
			$this->outputLine($e->getMessage());
			$this->quit(1);
		}
		$this->outputLine('Successfully copied %s from container %s as %s to container %s.', array($sourceObject, $sourceContainer, $targetObject, $targetContainer));
	}

	/**
	 * Lists of objects of a container
	 *
	 * This command displays a list of all objects stored in the specified container.
	 *
	 * @param string $container Name of the container
	 * @param boolean $verbose If additional metadata should be shown
	 * @return void
	 */
	public function listObjectsCommand($container, $verbose = FALSE) {
		try {
			$objects = $this->cloudFilesService->listObjects($container, ($verbose ? 'json' : NULL));
		} catch(Exception $e) {
			$this->outputLine($e->getMessage());
			$this->quit(1);
		}
		foreach ($objects as $name => $objectInfo) {
			if ($verbose) {
				$this->outputLine($name . ' ' . $objectInfo['hash'] . ' ' . $objectInfo['last_modified']);
			} else {
				$this->outputLine($objectInfo);
			}
		}
	}

	/**
	 * Generate a temporary download link
	 *
	 * This command generates a temporary URI which expires after the optionally
	 * specified number of seconds (ttl).
	 *
	 * @param string $container Name of the container
	 * @param string $object Name of the object within the container
	 * @param integer $ttl Number of seconds until the link should expire
	 * @return void
	 */
	public function generateLinkCommand($container, $object, $ttl = 60) {
		try {
			$uri = $this->cloudFilesService->getTemporaryUri($container, $object, $ttl);
		} catch(Exception $e) {
			$this->outputLine($e->getMessage());
			$this->quit(1);
		}

		$this->outputLine((string)$uri);
	}
}

?>