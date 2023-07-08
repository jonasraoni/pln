<?php

/**
 * @file classes/DepositPackage.php
 *
 * Copyright (c) 2013-2023 Simon Fraser University
 * Copyright (c) 2003-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class DepositPackage
 *
 * @brief Represent a PLN deposit package.
 */

namespace APP\plugins\generic\pln\classes;

use APP\core\Application;
use APP\journal\Journal;
use APP\journal\JournalDAO;
use APP\plugins\generic\pln\classes\tasks\Depositor;
use APP\plugins\generic\pln\form\Deposit;
use APP\plugins\generic\pln\PLNPlugin;
use APP\plugins\importexport\native\NativeImportExportPlugin;
use Core;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Exception;
use PKP\config\Config;
use PKP\db\DAORegistry;
use PKP\file\ContextFileManager;
use PKP\file\FileManager;
use PKP\plugins\PluginRegistry;
use PKP\scheduledTask\ScheduledTaskHelper;
use PKP\site\VersionDAO;
use PKP\submission\PKPSubmission;
use Throwable;
use whikloj\BagItTools\Bag;

class DepositPackage
{
    private const PKP_NAMESPACE = 'http://pkp.sfu.ca/SWORD';

    /**
     * Constructor
     */
    public function __construct(private Deposit $deposit, private ?Depositor $task = null)
    {
    }

    /**
     * Send a message to a log.
     * If the deposit package is aware of a a scheduled task, the message will be sent to the task's log, otherwise it will be sent to error_log().
     */
    protected function logMessage(string $message): void
    {
        if ($this->task) {
            $this->task->addExecutionLogEntry($message, ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
        } else {
            error_log($message);
        }
    }

    /**
     * Get the directory used to store deposit data.
     */
    public function getDepositDir(): string
    {
        $fileManager = new ContextFileManager($this->deposit->getJournalId());
        return $fileManager->getBasePath() . PLN_PLUGIN_ARCHIVE_FOLDER . "/{$this->deposit->getUUID()}";
    }

    /**
     * Get the filename used to store the deposit's atom document.
     */
    public function getAtomDocumentPath(): string
    {
        return "{$this->getDepositDir()}/{$this->deposit->getUUID()}.xml";
    }

    /**
     * Get the filename used to store the deposit's bag.
     */
    public function getPackageFilePath(): string
    {
        return "{$this->getDepositDir()}/{$this->deposit->getUUID()}.zip";
    }

    /**
     * Create a DOMElement in the $dom, and set the element name, namespace, and
     * content. Any invalid UTF-8 characters will be dropped. The
     * content will be placed inside a CDATA section.
     */
    protected function createElement(DOMDocument $dom, string $elementName, ?string $content, ?string $namespace = null): DOMElement
    {
        // remove any invalid UTF-8.
        $original = mb_substitute_character();
        mb_substitute_character(0xFFFD);
        $filtered = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        mb_substitute_character($original);

        // put the filtered content in a CDATA, as it may contain markup that isn't valid XML.
        $node = $dom->createCDATASection($filtered);
        $element = $dom->createElementNS($namespace, $elementName);
        $element->appendChild($node);
        return $element;
    }

    /**
     * Create an atom document for this deposit.
     */
    public function generateAtomDocument(): string
    {
        $plnPlugin = PluginRegistry::getPlugin('generic', PLN_PLUGIN_NAME);
        /** @var JournalDAO */
        $journalDao = DAORegistry::getDAO('JournalDAO');
        /** @var Journal */
        $journal = $journalDao->getById($this->deposit->getJournalId());
        $fileManager = new ContextFileManager($this->deposit->getJournalId());

        // set up folder and file locations
        $atomFile = $this->getAtomDocumentPath();
        $packageFile = $this->getPackageFilePath();

        // make sure our bag is present
        if (!$fileManager->fileExists($packageFile)) {
            $this->logMessage(__('plugins.generic.pln.error.depositor.missingpackage', ['file' => $packageFile]));
            return false;
        }

        $atom = new DOMDocument('1.0', 'utf-8');
        $entry = $atom->createElementNS('http://www.w3.org/2005/Atom', 'entry');
        $entry->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:dcterms', 'http://purl.org/dc/terms/');
        $entry->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:pkp', static::PKP_NAMESPACE);

        $entry->appendChild($this->createElement($atom, 'email', $journal->getData('contactEmail')));
        $entry->appendChild($this->createElement($atom, 'title', $journal->getLocalizedName()));

        $request = Application::get()->getRequest();
        $dispatcher = Application::get()->getDispatcher();

        $entry->appendChild($this->createElement($atom, 'pkp:journal_url', $dispatcher->url($request, Application::ROUTE_PAGE, $journal->getPath()), static::PKP_NAMESPACE));
        $entry->appendChild($this->createElement($atom, 'pkp:publisherName', $journal->getData('publisherInstitution'), static::PKP_NAMESPACE));
        $entry->appendChild($this->createElement($atom, 'pkp:publisherUrl', $journal->getData('publisherUrl'), static::PKP_NAMESPACE));
        $entry->appendChild($this->createElement($atom, 'pkp:issn', $journal->getData('onlineIssn') ?: $journal->getData('printIssn'), static::PKP_NAMESPACE));
        $entry->appendChild($this->createElement($atom, 'id', 'urn:uuid:' . $this->deposit->getUUID()));
        $entry->appendChild($this->createElement($atom, 'updated', date('Y-m-d H:i:s', strtotime($this->deposit->getDateModified()))));

        $url = $dispatcher->url($request, Application::ROUTE_PAGE, $journal->getPath()) . '/' . PLN_PLUGIN_ARCHIVE_FOLDER . '/deposits/' . $this->deposit->getUUID();
        $pkpDetails = $this->createElement($atom, 'pkp:content', $url, static::PKP_NAMESPACE);
        $pkpDetails->setAttribute('size', ceil(filesize($packageFile) / 1000));

        $objectVolume = '';
        $objectIssue = '';
        $objectPublicationDate = 0;

        $depositObjects = $this->deposit->getDepositObjects()->toIterator();
        switch ($this->deposit->getObjectType()) {
            case 'PublishedArticle': // Legacy (OJS pre-3.2)
            case PLN_PLUGIN_DEPOSIT_OBJECT_SUBMISSION:
                /** @var SubmissionDAO */
                $submissionDao = DAORegistry::getDAO('SubmissionDAO');
                foreach ($depositObjects as $depositObject) {
                    $submission = $submissionDao->getById($depositObject->getObjectId());
                    $publication = $submission->getCurrentPublication();
                    $publicationDate = $publication ? $publication->getData('publicationDate') : null;
                    if ($publicationDate && strtotime($publicationDate) > $objectPublicationDate) {
                        $objectPublicationDate = strtotime($publicationDate);
                    }
                }
                break;
            case PLN_PLUGIN_DEPOSIT_OBJECT_ISSUE:
                foreach ($depositObjects as $depositObject) {
                    /** @var IssueDAO */
                    $issueDao = DAORegistry::getDAO('IssueDAO');
                    $issue = $issueDao->getById($depositObject->getObjectId());
                    $objectVolume = $issue->getVolume();
                    $objectIssue = $issue->getNumber();
                    if ($issue->getDatePublished() > $objectPublicationDate) {
                        $objectPublicationDate = $issue->getDatePublished();
                    }
                }
                break;
        }

        $pkpDetails->setAttribute('volume', $objectVolume);
        $pkpDetails->setAttribute('issue', $objectIssue);
        $pkpDetails->setAttribute('pubdate', date('Y-m-d', strtotime($objectPublicationDate)));

        // Add OJS Version
        /** @var VersionDAO */
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
        $license = $atom->createElementNS(static::PKP_NAMESPACE, 'license');
        $license->appendChild($this->createElement($atom, 'openAccessPolicy', $journal->getLocalizedData('openAccessPolicy', $locale), static::PKP_NAMESPACE));
        $license->appendChild($this->createElement($atom, 'licenseURL', $journal->getLocalizedData('licenseURL', $locale), static::PKP_NAMESPACE));

        $mode = $atom->createElementNS(static::PKP_NAMESPACE, 'publishingMode');
        $mode->nodeValue = match ($journal->getData('publishingMode')) {
            Journal::PUBLISHING_MODE_OPEN => 'Open',
            Journal::PUBLISHING_MODE_SUBSCRIPTION => 'Subscription',
            Journal::PUBLISHING_MODE_NONE => 'None',
            default => ''
        };
        $license->appendChild($mode);
        $license->appendChild($this->createElement($atom, 'copyrightNotice', $journal->getLocalizedData('copyrightNotice', $locale), static::PKP_NAMESPACE));
        $license->appendChild($this->createElement($atom, 'copyrightBasis', $journal->getLocalizedData('copyrightBasis'), static::PKP_NAMESPACE));
        $license->appendChild($this->createElement($atom, 'copyrightHolder', $journal->getLocalizedData('copyrightHolder'), static::PKP_NAMESPACE));

        $entry->appendChild($license);
        $atom->save($atomFile);

        return $atomFile;
    }

    /**
     * Create a package containing the serialized deposit objects. If the BagIt library fails to load, null will be returned.
     *
     * @return string The full path of the created zip archive
     */
    public function generatePackage(): string
    {
        require_once __DIR__ . '/../vendor/autoload.php';

        // get DAOs, plugins and settings
        /** @var JournalDAO */
        $journalDao = DAORegistry::getDAO('JournalDAO');
        /** @var IssueDAO */
        $issueDao = DAORegistry::getDAO('IssueDAO');
        /** @var SubmissionDAO */
        $submissionDao = DAORegistry::getDAO('SubmissionDAO');
        /** @var NativeImportExportPlugin */
        $exportPlugin = PluginRegistry::loadPlugin('importexport', 'native');
        @ini_set('memory_limit', -1);
        $plnPlugin = PluginRegistry::getPlugin('generic', PLN_PLUGIN_NAME);

        // set up folder and file locations
        $bagDir = "{$this->getDepositDir()}/{$this->deposit->getUUID()}";
        $packageFile = $this->getPackageFilePath();
        $exportFile = tempnam(sys_get_temp_dir(), 'ojs-pln-export-');
        $termsFile = tempnam(sys_get_temp_dir(), 'ojs-pln-terms-');

        $bag = Bag::create($bagDir);

        $fileList = [];
        $fileManager = new FileManager();

        $journal = $journalDao->getById($this->deposit->getJournalId());
        $depositObjects = $this->deposit->getDepositObjects();
        switch ($this->deposit->getObjectType()) {
            case 'PublishedArticle': // Legacy (OJS pre-3.2)
            case PLN_PLUGIN_DEPOSIT_OBJECT_SUBMISSION:
                $submissionIds = [];

                // we need to add all of the relevant submissions to an array to export as a batch
                while ($depositObject = $depositObjects->next()) {
                    $submission = $submissionDao->getById($this->deposit->getObjectId());
                    if ($submission->getContextId() !== $journal->getId()) {
                        continue;
                    }
                    if ($submission->getCurrentPublication()?->getData('status') !== PKPSubmission::STATUS_PUBLISHED) {
                        continue;
                    }

                    $submissionIds[] = $submission->getId();
                }

                // export all of the submissions together
                $exportXml = $exportPlugin->exportSubmissions($submissionIds, $journal, null, ['no-embed' => 1]);
                if (!$exportXml) {
                    throw new Exception(__('plugins.generic.pln.error.depositor.export.articles.error'));
                }
                $exportXml = $this->cleanFileList($exportXml, $fileList);
                $fileManager->writeFile($exportFile, $exportXml);
                break;
            case PLN_PLUGIN_DEPOSIT_OBJECT_ISSUE:
                // we only ever do one issue at a time, so get that issue
                $request = Application::get()->getRequest();
                $depositObject = $depositObjects->next();
                $issue = $issueDao->getByBestId($depositObject->getObjectId(), $journal->getId());

                $exportXml = $exportPlugin->exportIssues(
                    (array) $issue->getId(),
                    $journal,
                    $request->getUser(),
                    ['no-embed' => 1]
                );

                if (!$exportXml) {
                    throw new $exception($this->logMessage(__('plugins.generic.pln.error.depositor.export.issue.error')));
                }
                $exportXml = $this->cleanFileList($exportXml, $fileList);
                $fileManager->writeFile($exportFile, $exportXml);
                break;
            default:
                throw new Exception('Unknown deposit type!');
        }

        // add the current terms to the bag
        $termsXml = new DOMDocument('1.0', 'utf-8');
        $entry = $termsXml->createElementNS('http://www.w3.org/2005/Atom', 'entry');
        $entry->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:dcterms', 'http://purl.org/dc/terms/');
        $entry->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:pkp', PLN_PLUGIN_NAME);

        $terms = unserialize($plnPlugin->getSetting($this->deposit->getJournalId(), 'terms_of_use'));
        $agreement = unserialize($plnPlugin->getSetting($this->deposit->getJournalId(), 'terms_of_use_agreement'));

        $pkpTermsOfUse = $termsXml->createElementNS(PLN_PLUGIN_NAME, 'pkp:terms_of_use');
        foreach ($terms as $termName => $termData) {
            $element = $termsXml->createElementNS(PLN_PLUGIN_NAME, $termName, $termData['term']);
            $element->setAttribute('updated', $termData['updated']);
            $element->setAttribute('agreed', $agreement[$termName]);
            $pkpTermsOfUse->appendChild($element);
        }

        $entry->appendChild($pkpTermsOfUse);
        $termsXml->appendChild($entry);
        $termsXml->save($termsFile);

        // add the exported content to the bag
        $bag->addFile($exportFile, $this->deposit->getObjectType() . $this->deposit->getUUID() . '.xml');
        foreach ($fileList as $sourcePath => $targetPath) {
            // $sourcePath is a relative path to the files directory; add the files directory to the front
            $sourcePath = rtrim(Config::getVar('files', 'files_dir'), '/') . '/' . $sourcePath;
            $bag->addFile($sourcePath, $targetPath);
        }

        // Add the schema files to the bag (adjusting the XSD references to flatten them)
        $bag->createFile(
            preg_replace(
                '/schemaLocation="[^"]+pkp-native.xsd"/',
                'schemaLocation="pkp-native.xsd"',
                file_get_contents('plugins/importexport/native/native.xsd')
            ),
            'native.xsd'
        );
        $bag->createFile(
            preg_replace(
                '/schemaLocation="[^"]+importexport.xsd"/',
                'schemaLocation="importexport.xsd"',
                file_get_contents('lib/pkp/plugins/importexport/native/pkp-native.xsd')
            ),
            'pkp-native.xsd'
        );
        $bag->createFile(file_get_contents('lib/pkp/xml/importexport.xsd'), 'importexport.xsd');

        // add the exported content to the bag
        $bag->addFile($termsFile, 'terms' . $this->deposit->getUUID() . '.xml');

        // Add OJS Version
        /** @var VersionDAO */
        $versionDao = DAORegistry::getDAO('VersionDAO');
        $currentVersion = $versionDao->getCurrentVersion();
        $bag->setExtended(true);
        $bag->addBagInfoTag('PKP-PLN-OJS-Version', $currentVersion->getVersionString());

        $bag->update();

        // create the bag
        $bag->package($packageFile);

        // remove the temporary bag directory and temp files
        $fileManager->rmtree($bagDir);
        $fileManager->deleteByPath($exportFile);
        $fileManager->deleteByPath($termsFile);
        return $packageFile;
    }

    /**
     * Read a list of file paths from the specified native XML string and clean up the XML's pathnames.
     *
     * @param array $fileList Reference to array to receive file list
     */
    public function cleanFileList(string $xml, array &$fileList): string
    {
        $doc = new DOMDocument();
        $doc->loadXML($xml);
        $xpath = new DOMXPath($doc);
        $xpath->registerNameSpace('pkp', 'http://pkp.sfu.ca');
        foreach ($xpath->query('//pkp:submission_file//pkp:href') as $hrefNode) {
            $filePath = $hrefNode->getAttribute('src');
            $targetPath = 'files/' . basename($filePath);
            $fileList[$filePath] = $targetPath;
            $hrefNode->setAttribute('src', $targetPath);
        }
        return $doc->saveXML();
    }

    /**
     * Transfer the atom document to the PN.
     */
    public function transferDeposit(): void
    {
        $journalId = $this->deposit->getJournalId();
        /** @var DepositDAO */
        $depositDao = DAORegistry::getDAO('DepositDAO');
        /** @var PLNPlugin */
        $plnPlugin = PluginRegistry::getPlugin('generic', PLN_PLUGIN_NAME);

        // post the atom document
        $baseUrl = $plnPlugin->getSetting($journalId, 'pln_network');
        $atomPath = $this->getAtomDocumentPath();

        // Reset deposit if the package doesn't exist
        if (!file_exists($atomPath)) {
            $this->deposit->setNewStatus();
            $depositDao->updateObject($this->deposit);
            return;
        }

        $journalUuid = $plnPlugin->getSetting($journalId, 'journal_uuid');
        $baseContUrl = $baseUrl . PLN_PLUGIN_CONT_IRI . "/{$journalUuid}/{$this->deposit->getUUID()}";

        $result = $plnPlugin->curlGet("{$baseContUrl}/state");
        $status = intdiv((int) $result['status'], 100);
        // Abort if status not 2XX or 4XX
        if ($status !== 2 && $status !== 4) {
            $this->task->addExecutionLogEntry(
                __(
                    'plugins.generic.pln.depositor.transferringdeposits.processing.resultFailed',
                    ['depositId' => $this->deposit->getId(),
                        'error' => $result['status'],
                        'result' => $result['error']]
                ),
                ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE
            );
            $this->logMessage(__('plugins.generic.pln.error.http.deposit', ['error' => $result['status'], 'message' => $result['error']]));
            $this->deposit->setExportDepositError(__('plugins.generic.pln.error.http.deposit', ['error' => $result['status'], 'message' => $result['error']]));
            $this->deposit->setLastStatusDate(Core::getCurrentDate());
            $depositDao->updateObject($this->deposit);
            return;
        }
        // Status 2XX at this URL means the content has been deposited before
        $isNewDeposit = $status !== 2;
        $url = $isNewDeposit ? $baseUrl . PLN_PLUGIN_COL_IRI . "/{$journalUuid}" : "{$baseContUrl}/edit";

        $this->task->addExecutionLogEntry(
            __(
                'plugins.generic.pln.depositor.transferringdeposits.processing.postAtom',
                [
                    'depositId' => $this->deposit->getId(),
                    'statusLocal' => $this->deposit->getLocalStatus(),
                    'statusProcessing' => $this->deposit->getProcessingStatus(),
                    'statusLockss' => $this->deposit->getLockssStatus(),
                    'atomPath' => $atomPath,
                    'url' => $url,
                    'method' => $isNewDeposit ? 'PostFile' : 'PutFile'
                ]
            ),
            ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE
        );

        $result = $isNewDeposit
            ? $plnPlugin->curlPostFile($url, $atomPath)
            : $plnPlugin->curlPutFile($url, $atomPath);

        // If we get a 2XX, set the status as transferred
        if (intdiv((int) $result['status'], 100) === 2) {
            $this->task->addExecutionLogEntry(
                __(
                    'plugins.generic.pln.depositor.transferringdeposits.processing.resultSucceeded',
                    ['depositId' => $this->deposit->getId()]
                ),
                ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE
            );

            $this->deposit->setTransferredStatus();
            $this->deposit->setExportDepositError(null);
        } else {
            $this->task->addExecutionLogEntry(
                __(
                    'plugins.generic.pln.depositor.transferringdeposits.processing.resultFailed',
                    ['depositId' => $this->deposit->getId(), 'error' => $result['status'], 'result' => $result['error']]
                ),
                ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE
            );
            if ($result['status']) {
                $this->logMessage(__('plugins.generic.pln.error.http.deposit', ['error' => $result['status'], 'message' => $result['error']]));
                $this->deposit->setExportDepositError(__('plugins.generic.pln.error.http.deposit', ['error' => $result['status'], 'message' => $result['error']]));
            } else {
                $this->logMessage(__('plugins.generic.pln.error.network.deposit', ['error' => $result['error']]));
                $this->deposit->setExportDepositError(__('plugins.generic.pln.error.network.deposit', ['error' => $result['error']]));
            }
        }

        $this->deposit->setLastStatusDate(Core::getCurrentDate());
        $depositDao->updateObject($this->deposit);
    }

    /**
     * Package a deposit for transfer to and retrieval by the PN.
     */
    public function packageDeposit(): void
    {
        /** @var DepositDAO */
        $depositDao = DAORegistry::getDAO('DepositDAO');
        $fileManager = new ContextFileManager($this->deposit->getJournalId());
        $plnDir = $fileManager->getBasePath() . PLN_PLUGIN_ARCHIVE_FOLDER;

        // make sure the pln work directory exists
        if (!$fileManager->fileExists($plnDir, 'dir')) {
            $fileManager->mkdir($plnDir);
        }

        // make a location for our work and clear it out if it already exists
        $this->remove();
        $fileManager->mkdir($this->getDepositDir());

        try {
            $packagePath = $this->generatePackage();
            if (!$fileManager->fileExists($packagePath)) {
                throw new Exception(__(
                    'plugins.generic.pln.depositor.packagingdeposits.processing.packageFailed',
                    ['depositId' => $this->deposit->getId()]
                ));
            }

            if (!$fileManager->fileExists($this->generateAtomDocument())) {
                throw new Exception(__(
                    'plugins.generic.pln.depositor.packagingdeposits.processing.packageFailed',
                    ['depositId' => $this->deposit->getId()]
                ));
            }
        } catch (Throwable $exception) {
            $this->logMessage(__('plugins.generic.pln.error.depositor.export.issue.error') . $exception->getMessage());
            $this->deposit->setExportDepositError($exception->getMessage());
            $this->deposit->setLastStatusDate(Core::getCurrentDate());
            $depositDao->updateObject($this->deposit);
            return;
        }

        $this->task->addExecutionLogEntry(
            __(
                'plugins.generic.pln.depositor.packagingdeposits.processing.packageSucceeded',
                ['depositId' => $this->deposit->getId()]
            ),
            ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE
        );

        // update the deposit's status
        $this->deposit->setPackagedStatus();
        $this->deposit->setExportDepositError(null);
        $this->deposit->setLastStatusDate(Core::getCurrentDate());
        $depositDao->updateObject($this->deposit);
    }

    /**
     * Update the deposit's status by checking with the PLN.
     */
    public function updateDepositStatus(): void
    {
        $journalId = $this->deposit->getJournalId();
        /** @var DepositDAO */
        $depositDao = DAORegistry::getDAO('DepositDAO');
        /** @var PLNPlugin */
        $plnPlugin = PluginRegistry::getPlugin('generic', 'plnplugin');

        $url = $plnPlugin->getSetting($journalId, 'pln_network') . PLN_PLUGIN_CONT_IRI;
        $url .= '/' . $plnPlugin->getSetting($journalId, 'journal_uuid');
        $url .= '/' . $this->deposit->getUUID() . '/state';

        // retrieve the content document
        $result = $plnPlugin->curlGet($url);
        if (intdiv((int) $result['status'], 100) !== 2) {
            if ($result['status']) {
                error_log(__('plugins.generic.pln.error.http.swordstatement', ['error' => $result['status'], 'message' => $result['error']]));

                // Status 4XX means the deposit doesn't exist or isn't related to the given journal, so we restart the deposit
                if (intdiv($result['status'], 100) === 4) {
                    $this->deposit->setNewStatus();
                    $depositDao->updateObject($this->deposit);
                }

                return;
            }

            error_log(__('plugins.generic.pln.error.network.swordstatement', ['error' => $result['error'] ?: 'Unexpected error']));
            return;
        }

        $contentDOM = new DOMDocument();
        $contentDOM->preserveWhiteSpace = false;
        $contentDOM->loadXML($result['result']);

        // get the remote deposit state
        $processingState = $contentDOM->getElementsByTagName('category')->item(0)->getAttribute('term');
        $this->task->addExecutionLogEntry(
            __(
                'plugins.generic.pln.depositor.statusupdates.processing.processingState',
                ['depositId' => $this->deposit->getId(),
                    'processingState' => $processingState]
            ),
            ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE
        );

        // Clear previous error messages
        $this->deposit->setExportDepositError(null);
        $this->deposit->setStagingState($processingState ?: null);
        // Handle the local state
        $newStatus = match ($processingState) {
            'depositedByJournal', 'harvest-error'
                => PLN_PLUGIN_DEPOSIT_STATUS_PACKAGED | PLN_PLUGIN_DEPOSIT_STATUS_TRANSFERRED,
            'harvested', 'payload-validated', 'bag-validated', 'xml-validated', 'virus-checked', 'payload-error', 'bag-error', 'xml-error', 'virus-error'
                => PLN_PLUGIN_DEPOSIT_STATUS_PACKAGED | PLN_PLUGIN_DEPOSIT_STATUS_TRANSFERRED | PLN_PLUGIN_DEPOSIT_STATUS_RECEIVED,
            'reserialized', 'hold', 'reserialize-error', 'deposit-error'
                => PLN_PLUGIN_DEPOSIT_STATUS_PACKAGED | PLN_PLUGIN_DEPOSIT_STATUS_TRANSFERRED | PLN_PLUGIN_DEPOSIT_STATUS_RECEIVED | PLN_PLUGIN_DEPOSIT_STATUS_VALIDATED,
            'deposited', 'status-error'
                => PLN_PLUGIN_DEPOSIT_STATUS_PACKAGED | PLN_PLUGIN_DEPOSIT_STATUS_TRANSFERRED | PLN_PLUGIN_DEPOSIT_STATUS_RECEIVED | PLN_PLUGIN_DEPOSIT_STATUS_VALIDATED | PLN_PLUGIN_DEPOSIT_STATUS_SENT,
            default => null
        };

        if ($newStatus) {
            $this->deposit->setStatus($newStatus);
        } else {
            $this->deposit->setExportDepositError('Unknown processing state ' . $processingState);
            $this->logMessage('Deposit ' . $this->deposit->getId() . ' has unknown processing state ' . $processingState);
        }

        // The deposit file can be dropped once it's received by the PKP PN
        if ($this->deposit->getReceivedStatus()) {
            $this->remove();
        } elseif (!file_exists($this->getAtomDocumentPath())) {
            // Otherwise the package must still exist at this point, if it doesn't, we restart the deposit
            $this->deposit->setNewStatus();
            $depositDao->updateObject($this->deposit);
            return;
        }

        // Handle error messages
        if (in_array($processingState, ['hold', 'harvest-error', 'deposit-error', 'reserialize-error', 'virus-error', 'xml-error', 'payload-error', 'bag-error', 'status-error'])) {
            $this->deposit->setExportDepositError(__('plugins.generic.pln.status.error.' . $processingState));
        }

        $lockssState = $contentDOM->getElementsByTagName('category')->item(1)->getAttribute('term');
        $this->deposit->setLockssState($lockssState ?: null);
        switch ($lockssState) {
            case '':
                // do nothing.
                break;
            case 'inProgress':
                $this->deposit->setStatus(PLN_PLUGIN_DEPOSIT_STATUS_PACKAGED | PLN_PLUGIN_DEPOSIT_STATUS_TRANSFERRED | PLN_PLUGIN_DEPOSIT_STATUS_RECEIVED | PLN_PLUGIN_DEPOSIT_STATUS_VALIDATED | PLN_PLUGIN_DEPOSIT_STATUS_SENT | PLN_PLUGIN_DEPOSIT_STATUS_LOCKSS_RECEIVED);
                break;
            case 'agreement':
                $this->deposit->setStatus(PLN_PLUGIN_DEPOSIT_STATUS_PACKAGED | PLN_PLUGIN_DEPOSIT_STATUS_TRANSFERRED | PLN_PLUGIN_DEPOSIT_STATUS_RECEIVED | PLN_PLUGIN_DEPOSIT_STATUS_VALIDATED | PLN_PLUGIN_DEPOSIT_STATUS_SENT | PLN_PLUGIN_DEPOSIT_STATUS_LOCKSS_RECEIVED | PLN_PLUGIN_DEPOSIT_STATUS_LOCKSS_AGREEMENT);
                $this->deposit->setPreservedDate(Core::getCurrentDate());
                break;
            default:
                $this->deposit->setExportDepositError('Unknown LOCKSS state ' . $lockssState);
                $this->logMessage('Deposit ' . $this->deposit->getId() . ' has unknown LOCKSS state ' . $lockssState);
                break;
        }

        $this->deposit->setLastStatusDate(Core::getCurrentDate());
        $depositDao->updateObject($this->deposit);
    }

    /**
     * Delete a deposit package from the disk
     */
    public function remove(): bool
    {
        return (new ContextFileManager($this->deposit->getJournalId()))
            ->rmtree($this->getDepositDir());
    }
}
