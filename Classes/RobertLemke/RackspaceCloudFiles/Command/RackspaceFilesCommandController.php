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

		$container->createObject($file, fopen('file://' . realpath($file), 'rb'));
		$this->outputLine('Sucessfully uploaded the file.');
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