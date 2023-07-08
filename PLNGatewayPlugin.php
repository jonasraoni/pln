<?php

/**
 * @file PLNGatewayPlugin.php
 *
 * Copyright (c) 2013-2023 Simon Fraser University
 * Copyright (c) 2003-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PLNGatewayPlugin
 *
 * @brief Gateway component of web PLN plugin
 */

namespace APP\plugins\generic\pln;

use APP\core\Application;
use APP\template\TemplateManager;
use PKP\config\Config;
use PKP\core\ArrayItemIterator;
use PKP\db\DAORegistry;
use PKP\plugins\GatewayPlugin;
use PKP\plugins\PluginRegistry;
use PKP\site\VersionCheck;
use PKP\site\VersionDAO;
use PKP\submission\PKPSubmission;

class PLNGatewayPlugin extends GatewayPlugin
{
    private const PING_ARTICLE_COUNT = 12;

    /**
     * Constructor.
     */
    public function __construct(private string $parentPluginName)
    {
        parent::__construct();
    }

    /**
     * @copydoc Plugin::getHideManagement()
     */
    public function getHideManagement(): bool
    {
        return true;
    }

    /**
     * @copydoc Plugin::getName()
     */
    public function getName(): string
    {
        return 'PLNGatewayPlugin';
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.plngateway.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription(): string
    {
        return __('plugins.generic.plngateway.description');
    }

    /**
     * Get the plugin
     */
    public function getPlugin(): PLNPlugin
    {
        /** @var PLNPlugin */
        $plugin = PluginRegistry::getPlugin('generic', $this->parentPluginName);
        return $plugin;
    }

    /**
     * Override the builtin to get the correct plugin path.
     */
    public function getPluginPath(): string
    {
        $plugin = $this->getPlugin();
        return $plugin->getPluginPath();
    }

    /**
     * Override the builtin to get the correct template path.
     */
    public function getTemplatePath($inCore = false): string
    {
        $plugin = $this->getPlugin();
        return $plugin->getTemplatePath($inCore);
    }

    /**
     * @copydoc Plugin::getEnabled()
     */
    public function getEnabled()
    {
        return $this->getPlugin()->getEnabled(); // Should always be true anyway if this is loaded
    }

    /**
     * @copydoc GatewayPlugin::fetch()
     */
    public function fetch($args, $request): void
    {
        $plugin = $this->getPlugin();
        $templateMgr = TemplateManager::getManager($request);
        $journal = $request->getJournal();

        $pluginVersionFile = $this->getPluginPath() . '/version.xml';
        $pluginVersion = VersionCheck::parseVersionXml($pluginVersionFile);
        $templateMgr->assign('pluginVersion', $pluginVersion);

        $terms = [];
        $termsAccepted = $plugin->termsAgreed($journal->getId());
        if ($termsAccepted) {
            $templateMgr->assign('termsAccepted', 'yes');
            $terms = unserialize($plugin->getSetting($journal->getId(), 'terms_of_use'));
            $termsAgreement = unserialize($plugin->getSetting($journal->getId(), 'terms_of_use_agreement'));
        } else {
            $templateMgr->assign('termsAccepted', 'no');
        }

        $application = Application::get();
        $products = $application->getEnabledProducts('plugins.generic');
        $prerequisites = [
            'phpVersion' => PHP_VERSION,
            'zipInstalled' => class_exists('ZipArchive') ? 'yes' : 'no',
            'acron' => isset($products['acron']) ? 'yes' : 'no',
        ];
        $templateMgr->assign('prerequisites', $prerequisites);

        $termKeys = array_keys($terms);
        $termsDisplay = [];
        foreach ($termKeys as $key) {
            $termsDisplay[] = [
                'key' => $key,
                'term' => $terms[$key]['term'],
                'updated' => $terms[$key]['updated'],
                'accepted' => $termsAgreement[$key]
            ];
        }
        $templateMgr->assign('termsDisplay', new ArrayItemIterator($termsDisplay));
        /** @var VersionDAO */
        $versionDao = DAORegistry::getDAO('VersionDAO');
        $ojsVersion = $versionDao->getCurrentVersion();
        $templateMgr->assign('ojsVersion', $ojsVersion->getVersionString());
        /** @var SubmissionDAO */
        $submissionDao = DAORegistry::getDAO('SubmissionDAO');
        $publications = [];
        $submissions = $submissionDao->getByContextId($journal->getId());
        while ($submission = $submissions->next()) {
            $publication = $submission->getCurrentPublication();
            if (!$publication || $publication->getData('status') != PKPSubmission::STATUS_PUBLISHED) {
                continue;
            }
            $publications[] = $publication;
            if (count($publications) === static::PING_ARTICLE_COUNT) {
                break;
            }
        }
        $templateMgr->assign('publications', $publications);
        $templateMgr->assign('pln_network', $plugin->getSetting($journal->getId(), 'pln_network'));

        header('content-type: text/xml; charset=UTF-8');
        $templateMgr->display($plugin->getTemplateResource('ping.tpl'));
    }
}
