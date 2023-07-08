<?php

/**
 * @file controllers/grid/PLNStatusGridRow.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2003-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PLNStatusGridRow
 *
 * @brief Handle PLNStatus deposit grid row requests.
 */

namespace APP\plugins\generic\pln\controllers\grid;

use APP\plugins\generic\pln\form\Deposit;
use PKP\controllers\grid\GridRow;

class PLNStatusGridRow extends GridRow
{
    /**
     * @copydoc GridRow::initialize()
     */
    public function initialize($request, $template = null): void
    {
        parent::initialize($request, PLNStatusGridHandler::$plugin->getTemplateResource('gridRow.tpl'));
    }

    /**
     * Retrieves the deposit
     */
    public function getDeposit(): Deposit
    {
        return $this->getData();
    }
}
