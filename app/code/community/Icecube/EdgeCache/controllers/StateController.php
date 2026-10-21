<?php

/**
 * The per-visitor state a cached page leaves out, fetched by edgecache.js.
 *
 * SPDX-FileCopyrightText: 2026 Icecube
 * SPDX-License-Identifier: OSL-3.0
 * @package Icecube_EdgeCache
 */

declare(strict_types=1);

class Icecube_EdgeCache_StateController extends Mage_Core_Controller_Front_Action
{
    public const VERSION = 1;

    /**
     * Not on the cacheable list, so preDispatch starts the session and the key returned is the
     * one a POST from this visitor will be checked against.
     */
    #[Maho\Config\Route('/edgecache/state', name: 'edgecache.state', methods: ['GET'])]
    public function indexAction(): void
    {
        $customerSession = Mage::getSingleton('customer/session');

        $this->getResponse()
            ->setHeader('Cache-Control', Icecube_EdgeCache_Model_Policy::PRIVATE_CACHE_CONTROL, true)
            ->setBodyJson([
                'v' => self::VERSION,
                'formKey' => Mage::getSingleton('core/session')->getFormKey(),
                'customer' => [
                    'loggedIn' => $customerSession->isLoggedIn(),
                    'group' => (int) $customerSession->getCustomerGroupId(),
                ],
                'cart' => [
                    'qty' => (float) (Mage::getSingleton('checkout/cart')->getSummaryQty() ?? 0),
                ],
            ]);
    }
}
