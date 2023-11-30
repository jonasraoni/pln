<?php

/**
 * @file classes/form/PLNStatusForm.inc.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2000-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PLNStatusForm
 * @brief Form for journal managers to check PLN plugin status
 */

import('lib.pkp.classes.form.Form');

class PLNStatusForm extends Form {
	/** @var int */
	var $_contextId;

	/** @var PLNPlugin Plugin */
	var $_plugin;

	/**
	 * Constructor
	 * @param PLNPlugin $plugin
	 * @param int $contextId
	 */
	public function __construct($plugin, $contextId) {
		$this->_contextId = $contextId;
		$this->_plugin = $plugin;

		parent::__construct($plugin->getTemplateResource('status.tpl'));
	}

	/**
	 * @copydoc Form::fetch()
	 */
	public function fetch($request, $template = null, $display = false) {
		$context = $request->getContext();
		/** @var DepositDAO */
		$depositDao = DAORegistry::getDAO('DepositDAO');
		$networkStatus = $this->_plugin->getSetting($context->getId(), 'pln_accepting');
		$networkStatusMessage = $this->_plugin->getSetting($context->getId(), 'pln_accepting_message');
		$rangeInfo = PKPHandler::getRangeInfo($request, 'deposits');

		if (!$networkStatusMessage) {
			if ($networkStatus === true) {
				$networkStatusMessage = __('plugins.generic.pln.notifications.pln_accepting');
			} else {
				$networkStatusMessage = __('plugins.generic.pln.notifications.pln_not_accepting');
			}
		}
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign(array(
			'deposits' => $depositDao->getByJournalId($context->getId(), $rangeInfo),
			'networkStatus' => $networkStatus,
			'networkStatusMessage' => $networkStatusMessage
		));

		return parent::fetch($request, $template, $display);
	}
}
