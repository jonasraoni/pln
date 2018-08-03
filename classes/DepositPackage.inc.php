<?php

/**
 * @file plugins/generic/pln/classes/DepositPackage.inc.php
 *
 * Copyright (c) 2013-2017 Simon Fraser University Library
 * Copyright (c) 2003-2017 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class DepositPackage
 * @ingroup plugins_generic_pln
 *
 * @brief Handle PLN requests
 */

import('lib.pkp.classes.file.ContextFileManager');
import('lib.pkp.classes.scheduledTask.ScheduledTask');

class DepositPackage {

	/**
	 * @var $deposit Deposit
	 */
	var $_deposit;

	/**
	 * If the DepositPackage object was created as part of a scheduled task
	 * run, then save the task so error messages can be logged there.
	 * @var ScheduledTask $_task;
	 */
	var $_task;


	/**
	 * Constructor.
	 *
	 * @param $deposit Deposit
	 * @param $task ScheduledTask
	 *
	 * @return DepositPackage
	 */
	function __construct($deposit, $task = null) {
		$this->_deposit = $deposit;
		$this->_task = $task;
	}

	/**
	 * Send a message to a log. If the deposit package is aware of a
	 * a scheduled task, the message will be sent to the task's
	 * log. Otherwise it will be sent to error_log().
	 *
	 * @param $message string Locale-specific message to be logged
	 */
	function _logMessage($message) {
		if($this->_task) {
			$this->_task->addExecutionLogEntry($message, SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
		} else {
			error_log($message);
		}
	}

	/**
	 * Get the directory used to store deposit data.
	 * @return string
	 */
	function getDepositDir() {
		$journalDao = DAORegistry::getDAO('JournalDAO');
		$fileManager = new ContextFileManager($this->_deposit->getJournalId());
		return $fileManager->filesDir . PLN_PLUGIN_ARCHIVE_FOLDER . DIRECTORY_SEPARATOR . $this->_deposit->getUUID();
	}

	/**
	 * Get the filename used to store the deposit's atom document.
	 * @return string
	 */
	function getAtomDocumentPath() {
		return $this->getDepositDir() . DIRECTORY_SEPARATOR . $this->_deposit->getUUID() . ".xml";
	}

	/**
	 * Get the filename used to store the deposit's bag.
	 * @return string
	 */
	 function getPackageFilePath() {
		return $this->getDepositDir() . DIRECTORY_SEPARATOR . $this->_deposit->getUUID() . ".zip";
	}

	/**
	 * Create a DOMElement in the $dom, and set the element name, namespace, and
	 * content. Any invalid UTF-8 characters will be dropped. The
	 * content will be placed inside a CDATA section.
	 *
	 * @param DOMDocument $dom
	 * @param string $elementName
	 * @param string $content
	 * @param string $namespace
	 * @return DOMElement
	 */
	function _generateElement($dom, $elementName, $content, $namespace = null){
		// remove any invalid UTF-8.
		$original = mb_substitute_character();
		mb_substitute_character(0xFFFD);
		$filtered = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
		mb_substitute_character($original);

		// put the filtered content in a CDATA, as it may contain markup that
		// isn't valid XML.
		$node = $dom->createCDATASection($filtered);
		$element = $dom->createElementNS($namespace, $elementName);
		$element->appendChild($node);
		return $element;
	}

	/**
	 * Create an atom document for this deposit.
	 * @return string
	 */
	function generateAtomDocument() {

		$plnPlugin = PluginRegistry::getPlugin('generic',PLN_PLUGIN_NAME);
		$journalDao = DAORegistry::getDAO('JournalDAO');
		$journal = $journalDao->getById($this->_deposit->getJournalId());
		$fileManager = new ContextFileManager($this->_deposit->getJournalId());

		// set up folder and file locations
		$atomFile = $this->getAtomDocumentPath();
		$packageFile = $this->getPackageFilePath();

		// make sure our bag is present
		if (!$fileManager->fileExists($packageFile)) {
			$this->_logMessage(__("plugins.generic.pln.error.depositor.missingpackage", array('file' => $packageFile)));
			return false;
		}

		$atom = new DOMDocument('1.0', 'utf-8');
		$entry = $atom->createElementNS('http://www.w3.org/2005/Atom', 'entry');
		$entry->setAttributeNS('http://www.w3.org/2000/xmlns/' ,'xmlns:dcterms', 'http://purl.org/dc/terms/');
		$entry->setAttributeNS('http://www.w3.org/2000/xmlns/' ,'xmlns:pkp', 'http://pkp.sfu.ca/SWORD');

		$email = $this->_generateElement($atom, 'email', $journal->getSetting('contactEmail'));
		$entry->appendChild($email);

		$title = $this->_generateElement($atom, 'title', $journal->getLocalizedName());
		$entry->appendChild($title);

		$request = PKPApplication::getRequest();
		$dispatcher = $request->getDispatcher();

		$pkpJournalUrl = $this->_generateElement($atom, 'pkp:journal_url', $dispatcher->url($request, ROUTE_PAGE, $journal->getPath()), 'http://pkp.sfu.ca/SWORD');
		$entry->appendChild($pkpJournalUrl);

		$pkpPublisher = $this->_generateElement($atom, 'pkp:publisherName', $journal->getSetting('publisherInstitution'), 'http://pkp.sfu.ca/SWORD');
		$entry->appendChild($pkpPublisher);

		$pkpPublisherUrl = $this->_generateElement($atom, 'pkp:publisherUrl', $journal->getSetting('publisherUrl'), 'http://pkp.sfu.ca/SWORD');
		$entry->appendChild($pkpPublisherUrl);

		$issn = '';

		if ($journal->getSetting('onlineIssn')) {
			$issn = $journal->getSetting('onlineIssn');
		} else if ($journal->getSetting('printIssn')) {
			$issn = $journal->getSetting('printIssn');
		}

		$pkpIssn = $this->_generateElement($atom, 'pkp:issn', $issn, 'http://pkp.sfu.ca/SWORD');
		$entry->appendChild($pkpIssn);

		$id = $this->_generateElement($atom, 'id', 'urn:uuid:'.$this->_deposit->getUUID());
		$entry->appendChild($id);

		$updated = $this->_generateElement($atom, 'updated', strftime("%Y-%m-%d %H:%M:%S",strtotime($this->_deposit->getDateModified())));
		$entry->appendChild($updated);

		$url = $dispatcher->url($request, ROUTE_PAGE, $journal->getPath()) . '/' . PLN_PLUGIN_ARCHIVE_FOLDER . '/deposits/' . $this->_deposit->getUUID();
		$pkpDetails = $this->_generateElement($atom, 'pkp:content', $url, 'http://pkp.sfu.ca/SWORD');
		$pkpDetails->setAttribute('size', ceil(filesize($packageFile)/1000));

		$objectVolume = "";
		$objectIssue = "";
		$objectPublicationDate = 0;

		switch ($this->_deposit->getObjectType()) {
			case PLN_PLUGIN_DEPOSIT_OBJECT_ARTICLE:
				$depositObjects = $this->_deposit->getDepositObjects();
				while ($depositObject = $depositObjects->next()) {
					$publishedArticleDao = DAORegistry::getDAO('PublishedArticleDAO');
					$article = $publishedArticleDao->getPublishedArticleByArticleId($depositObject->getObjectId());
					if ($article->getDatePublished() > $objectPublicationDate)
						$objectPublicationDate = $article->getDatePublished();
					unset($depositObject);
				}
				break;
			case PLN_PLUGIN_DEPOSIT_OBJECT_ISSUE:
				$depositObjects = $this->_deposit->getDepositObjects();
				while ($depositObject = $depositObjects->next()) {
					$issueDao = DAORegistry::getDAO('IssueDAO');
					$issue = $issueDao->getById($depositObject->getObjectId());
					$objectVolume = $issue->getVolume();
					$objectIssue = $issue->getNumber();
					if ($issue->getDatePublished() > $objectPublicationDate)
						$objectPublicationDate = $issue->getDatePublished();
					unset($depositObject);
				}
				break;
		}

		$pkpDetails->setAttribute('volume', $objectVolume);
		$pkpDetails->setAttribute('issue', $objectIssue);
		$pkpDetails->setAttribute('pubdate', strftime("%Y-%m-%d",strtotime($objectPublicationDate)));

		// Add OJS Version
		$versionDao = DAORegistry::getDAO('VersionDAO');
		$currentVersion = $versionDao->getCurrentVersion();
		$pkpDetails->setAttribute('ojsVersion', $currentVersion->getVersionString());

		switch ($plnPlugin->getSetting($journal->getId(), 'checksum_type')) {
			case 'SHA-1':
				$pkpDetails->setAttribute('checksumType', 'SHA-1');
				$pkpDetails->setAttribute('checksumValue', sha1_file($packageFile));
				break;
			case 'MD5':
				$pkpDetails->setAttribute('checksumType', 'MD5');
				$pkpDetails->setAttribute('checksumValue', md5_file($packageFile));
				break;
		}

		$entry->appendChild($pkpDetails);
		$atom->appendChild($entry);

		$locale = $journal->getPrimaryLocale();
		$license = $atom->createElementNS('http://pkp.sfu.ca/SWORD', 'license');
		$license->appendChild($this->_generateElement($atom, 'openAccessPolicy', $journal->getLocalizedSetting('openAccessPolicy', $locale), 'http://pkp.sfu.ca/SWORD'));
		$license->appendChild($this->_generateElement($atom, 'licenseURL', $journal->getLocalizedSetting('licenseURL', $locale), 'http://pkp.sfu.ca/SWORD'));

		$mode = $atom->createElementNS('http://pkp.sfu.ca/SWORD', 'publishingMode');
		switch($journal->getSetting('publishingMode')) {
			case PUBLISHING_MODE_OPEN:
				$mode->nodeValue = 'Open';
				break;
			case PUBLISHING_MODE_SUBSCRIPTION:
				$mode->nodeValue = 'Subscription';
				break;
			case PUBLISHING_MODE_NONE:
				$mode->nodeValue = 'None';
				break;
		}
		$license->appendChild($mode);
		$license->appendChild($this->_generateElement($atom, 'copyrightNotice', $journal->getLocalizedSetting('copyrightNotice', $locale), 'http://pkp.sfu.ca/SWORD'));
		$license->appendChild($this->_generateElement($atom, 'copyrightBasis', $journal->getLocalizedSetting('copyrightBasis'), 'http://pkp.sfu.ca/SWORD'));
		$license->appendChild($this->_generateElement($atom, 'copyrightHolder', $journal->getLocalizedSetting('copyrightHolder'), 'http://pkp.sfu.ca/SWORD'));

		$entry->appendChild($license);
		$atom->save($atomFile);

		return $atomFile;

	}

	/**
	 * Create a package containing the serialized deposit objects. If the
	 * bagit library fails to load, null will be returned.
	 *
	 * @return string The full path of the created zip archive
	 */
	function generatePackage() {

		if( ! @include_once(dirname(__FILE__).'/../vendor/scholarslab/bagit/lib/bagit.php')) {
			$this->_logMessage(__("plugins.generic.pln.error.include.bagit"));
			return;
		}

		try {
			$this->_task->addExecutionLogEntry("IN generatePackage - Bagit Found:: ". $this->_deposit->getId(), SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
			// get DAOs, plugins and settings
			$journalDao = DAORegistry::getDAO('JournalDAO');
			$issueDao = DAORegistry::getDAO('IssueDAO');
			$sectionDao = DAORegistry::getDAO('SectionDAO');
			$publishedArticleDao = DAORegistry::getDAO('PublishedArticleDAO');
			PluginRegistry::loadCategory('importexport');
			$exportPlugin = PluginRegistry::getPlugin('importexport','NativeImportExportPlugin');
			$plnPlugin = PluginRegistry::getPlugin('generic',PLN_PLUGIN_NAME);
			$fileManager = new ContextFileManager($this->_deposit->getJournalId());

			$journal = $journalDao->getById($this->_deposit->getJournalId());
			$depositObjects = $this->_deposit->getDepositObjects();

			$this->_task->addExecutionLogEntry("IN generatePackage - Before setup folders:: ". $this->_deposit->getId(), SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
			// set up folder and file locations
			$bagDir = $this->getDepositDir() . DIRECTORY_SEPARATOR . $this->_deposit->getUUID();
			$packageFile = $this->getPackageFilePath();
			$exportFile =  tempnam(sys_get_temp_dir(), 'ojs-pln-export-');
			$termsFile =  tempnam(sys_get_temp_dir(), 'ojs-pln-terms-');

			$this->_task->addExecutionLogEntry("IN generatePackage - Bagit Found:: ". $bagDir . "-" . $packageFile . "-" . $exportFile . "-" . $termsFile, SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);

			$bag = new BagIt($bagDir);

			$this->_task->addExecutionLogEntry("IN generatePackage - Bagit Found:: Bag Made", SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
			switch ($this->_deposit->getObjectType()) {
				case PLN_PLUGIN_DEPOSIT_OBJECT_ARTICLE:
					$articles = array();

					// we need to add all of the relevant articles to an array to export as a batch
					while ($depositObject = $depositObjects->next()) {
						$article = $publishedArticleDao->getPublishedArticleByArticleId($this->_deposit->getObjectId(), $journal->getId());
						$issue = $issueDao->getIssueById($article->getIssueId(), $journal->getId());
						$section = $sectionDao->getSection($article->getSectionId());

						// add the article to the array we'll pass for export
						$articles[] = array(
							'publishedArticle' => $article,
							'section' => $section,
							'issue' => $issue,
							'journal' => $journal
						);
						unset($depositObject);
					}

					// export all of the articles together
					if ($exportPlugin->exportArticles($articles, $exportFile) !== true) {
						$this->_logMessage(__("plugins.generic.pln.error.depositor.export.articles.error"));
						return false;
					}
					break;
				case PLN_PLUGIN_DEPOSIT_OBJECT_ISSUE:

					$this->_task->addExecutionLogEntry("IN generatePackage:: Make issue process", SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
					// we only ever do one issue at a time, so get that issue
					$depositObject = $depositObjects->next();
					$issue = $issueDao->getByBestId($depositObject->getObjectId(),$journal->getId());

					$this->_task->addExecutionLogEntry("IN generatePackage:: Issue" . $issue->getId(), SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);

					$application = PKPApplication::getApplication();
					$request = $application->getRequest();
					$user = $request->getUser();

					$exportXml = $exportPlugin->exportIssues(
						(array) $issue->getId(),
						$journal,
						$request->getUser()
					);

					$this->_task->addExecutionLogEntry("IN generatePackage:: ExportedXML", SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
					if (!$exportXml) {
						$this->_logMessage(__("plugins.generic.pln.error.depositor.export.issue.error"));
						return false;
					}

					import('lib.pkp.classes.file.FileManager');
					$fileManager = new FileManager();
					$fileManager->writeFile($exportFile, $exportXml);

					break;
				default:
			}

			$this->_task->addExecutionLogEntry("IN generatePackage:: Breaked from switch", SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
			// add the current terms to the bag
			$termsXml = new DOMDocument('1.0', 'utf-8');
			$entry = $termsXml->createElementNS('http://www.w3.org/2005/Atom', 'entry');
			$entry->setAttributeNS('http://www.w3.org/2000/xmlns/' ,'xmlns:dcterms', 'http://purl.org/dc/terms/');
			$entry->setAttributeNS('http://www.w3.org/2000/xmlns/' ,'xmlns:pkp', PLN_PLUGIN_NAME);

			$terms = unserialize($plnPlugin->getSetting($this->_deposit->getJournalId(), 'terms_of_use'));
			$agreement = unserialize($plnPlugin->getSetting($this->_deposit->getJournalId(), 'terms_of_use_agreement'));

			$pkpTermsOfUse = $termsXml->createElementNS(PLN_PLUGIN_NAME, 'pkp:terms_of_use');
			foreach ($terms as $termName => $termData) {
				$element = $termsXml->createElementNS(PLN_PLUGIN_NAME, $termName, $termData['term']);
				$element->setAttribute('updated',$termData['updated']);
				$element->setAttribute('agreed', $agreement[$termName]);
				$pkpTermsOfUse->appendChild($element);
			}

			$entry->appendChild($pkpTermsOfUse);
			$termsXml->appendChild($entry);
			$termsXml->save($termsFile);

			$this->_task->addExecutionLogEntry("IN generatePackage:: Before Bag Add File XML", SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);

			// add the exported content to the bag
			$bag->addFile($exportFile, $this->_deposit->getObjectType() . $this->_deposit->getUUID() . '.xml');

			$this->_task->addExecutionLogEntry("IN generatePackage:: Before Bag Add terms", SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
			// add the exported content to the bag
			$bag->addFile($termsFile, 'terms' . $this->_deposit->getUUID() . '.xml');

			// Add OJS Version
			$versionDao = DAORegistry::getDAO('VersionDAO');
			$currentVersion = $versionDao->getCurrentVersion();

			$this->_task->addExecutionLogEntry("IN generatePackage:: Before setBagInfoData", SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
			$bag->setBagInfoData('PKP-PLN-OJS-Version', $currentVersion->getVersionString());

			$this->_task->addExecutionLogEntry("IN generatePackage:: Before bag->update()", SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
			$bag->update();

			$this->_task->addExecutionLogEntry("IN generatePackage:: Before bag->package()", SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
			// create the bag
			$bag->package($packageFile,'zip');

			$this->_task->addExecutionLogEntry("IN generatePackage:: Finalising", SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
			// remove the temporary bag directory and temp files
			$fileManager->rmtree($bagDir);
			$fileManager->deleteFile($exportFile);
			$fileManager->deleteFile($termsFile);

			return $packageFile;
		}
		catch (Exception $e) {
			$this->_task->addExecutionLogEntry("IN generatePackage:: Caught exception:" . $e->getMessage(), SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
			return false;
		}

	}

	/**
	 * Transfer the atom document to the PLN.
	 */
	function transferDeposit() {
		$journalId = $this->_deposit->getJournalId();
		$depositDao = DAORegistry::getDAO('DepositDAO');
		$journalDao = DAORegistry::getDAO('JournalDAO');
		$plnPlugin = PluginRegistry::getPlugin('generic',PLN_PLUGIN_NAME);
		$fileManager = new ContextFileManager($journalId);
		$plnDir = $fileManager->filesDir . PLN_PLUGIN_ARCHIVE_FOLDER;

		// post the atom document
		$url = $plnPlugin->getSetting($journalId, 'pln_network');
		if ($this->_deposit->getLockssAgreementStatus()) {
			$url .= PLN_PLUGIN_CONT_IRI . '/' . $plnPlugin->getSetting($journalId, 'journal_uuid');
			$url .= '/' . $this->_deposit->getUUID() . '/edit';
			$result = $plnPlugin->_curlPutFile(
				$url,
				$this->getAtomDocumentPath()
			);
		} else {
			$url .= PLN_PLUGIN_COL_IRI . '/' . $plnPlugin->getSetting($journalId, 'journal_uuid');
			$result = $plnPlugin->_curlPostFile(
				$url,
				$this->getAtomDocumentPath()
			);
		}

		// if we get the OK, set the status as transferred
		if (($result['status'] == PLN_PLUGIN_HTTP_STATUS_OK) || ($result['status'] == PLN_PLUGIN_HTTP_STATUS_CREATED)) {
			$this->_deposit->setTransferredStatus();
			// unset a remote error if this worked
			$this->_deposit->setLockssReceivedStatus(false);
			// if this was an update, unset the update flag
			$this->_deposit->setLockssAgreementStatus(false);
			$this->_deposit->setLastStatusDate(time());
			$depositDao->updateObject($this->_deposit);
		} else {
			// we got an error back from the staging server
			if($result['status'] == FALSE) {
				$this->_logMessage(__("plugins.generic.pln.error.network.deposit", array('error' => $result['error'])));
			} else {
				$this->_logMessage(__("plugins.generic.pln.error.http.deposit", array('error' => $result['status'])));
			}
			$this->_deposit->setLockssReceivedStatus();
			$this->_deposit->setLastStatusDate(time());
			$depositDao->updateObject($this->_deposit);
		}

	}

	/**
	 * Package a deposit for transfer to and retrieval by the PLN.
	 */
	function packageDeposit() {
		$this->_task->addExecutionLogEntry("Start packageDeposit:: ". $this->_deposit->getId(), SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
		$depositDao = DAORegistry::getDAO('DepositDAO');
		$journalDao = DAORegistry::getDAO('JournalDAO');
		$fileManager = new ContextFileManager($this->_deposit->getJournalId());
		$plnDir = $fileManager->filesDir . PLN_PLUGIN_ARCHIVE_FOLDER;

		// make sure the pln work directory exists
		if ($fileManager->fileExists($plnDir,'dir') !== true) { $fileManager->mkdir($plnDir); }

		// make a location for our work and clear it out if it's there
		$depositDir = $plnDir . DIRECTORY_SEPARATOR . $this->_deposit->getUUID();
		if ($fileManager->fileExists($depositDir,'dir')) $fileManager->rmtree($depositDir);
		$fileManager->mkdir($depositDir);

		$this->_task->addExecutionLogEntry("Before generatePackage:: ". $this->_deposit->getId(), SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
		$packagePath = $this->generatePackage();

		$this->_task->addExecutionLogEntry("packagePath:: ". $packagePath, SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
		if( ! $packagePath) {
			return;
		}
		if (!$fileManager->fileExists($packagePath)) {
			$this->_deposit->setPackagedStatus(false);
			$depositDao->updateObject($this->_deposit);
			return;
		}

		if (!$fileManager->fileExists($this->generateAtomDocument())) {
			$this->_deposit->setPackagedStatus(false);
			$depositDao->updateObject($this->_deposit);
			return;
		}

		// update the deposit's status
		$this->_deposit->setPackagedStatus();
		$depositDao->updateObject($this->_deposit);
		$this->_task->addExecutionLogEntry("END packageDeposit:: ". $this->_deposit->getId(), SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
	}

	/**
	 * Update the deposit's status by checking with the PLN.
	 */
	function updateDepositStatus() {
		$journalId = $this->_deposit->getJournalID();
		$depositDao = DAORegistry::getDAO('DepositDAO');
		$plnPlugin = PluginRegistry::getPlugin('generic', 'plnplugin');

		$url = $plnPlugin->getSetting($journalId, 'pln_network') . PLN_PLUGIN_CONT_IRI;
		$url .= '/' . $plnPlugin->getSetting($journalId, 'journal_uuid');
		$url .= '/' . $this->_deposit->getUUID() . '/state';

		// retrieve the content document
		$result = $plnPlugin->_curlGet($url);

		if ($result['status'] != PLN_PLUGIN_HTTP_STATUS_OK) {
			// stop here if we didn't get an OK
			if($result['status'] === FALSE) {
				error_log(__('plugins.generic.pln.error.network.swordstatement', array('error' => $result['error'])));
			} else {
				error_log(__('plugins.generic.pln.error.http.swordstatement', array('error' => $result['status'])));
			}
			return;
		}

		$contentDOM = new DOMDocument();
		$contentDOM->preserveWhiteSpace = false;
		$contentDOM->loadXML($result['result']);

		// get the remote deposit state
		$processingState = $contentDOM->getElementsByTagName('category')->item(0)->getAttribute('term');
		switch ($processingState) {
			case 'depositedByJournal':
				$this->_deposit->setTransferredStatus(true);
				break;
			case 'harvested':
			case 'xml-validated':
			case 'payload-validated':
			case 'virus-checked':
				$this->_deposit->setReceivedStatus(true);
				break;
			case 'bag-validated':
			case 'reserialized':
				$this->_deposit->setValidatedStatus(true);
				break;
			case 'deposited':
				$this->_deposit->setSentStatus(true);
				break;
			default:
				$this->_logMessage('Deposit ' . $this->_deposit->getId() . ' has unknown processing state ' . $processingState);
		}

		$lockssState = $contentDOM->getElementsByTagName('category')->item(1)->getAttribute('term');
		switch($lockssState) {
			case '':
				// do nothing.
				break;
			case 'received':
				$this->_deposit->setLockssReceivedStatus();
				break;
			case 'syncing':
				$this->_deposit->setLockssSyncingStatus();
				break;
			case 'agreement':
				if( ! $this->_deposit->getLockssAgreementStatus()) {
					$journalDao = DAORegistry::getDAO('JournalDAO');
					$fileManager = new ContextFileManager($this->_deposit->getJournalId());
					$depositDir = $this->getDepositDir();
					$fileManager->rmtree($depositDir);
				}
				$this->_deposit->setLockssAgreementStatus(true);
				break;
			default:
				$this->_logMessage('Deposit ' . $this->_deposit->getId() . ' has unknown LOCKSS state ' . $processingState);
		}

		$this->_deposit->setLastStatusDate(time());
		$depositDao->updateObject($this->_deposit);
	}
}
?>
