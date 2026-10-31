<?php

/**
 * The form key for a page rendered without a session, which edgecache.js asks for before the
 * first POST.
 *
 * SPDX-FileCopyrightText: 2026 Icecube
 * SPDX-License-Identifier: OSL-3.0
 * @package Icecube_EdgeCache
 */

declare(strict_types=1);

class Icecube_EdgeCache_FormkeyController extends Mage_Core_Controller_Front_Action
{
    /**
     * Never renders without a session (Icecube_EdgeCache_Helper_Data::isCacheableAction()), so
     * preDispatch starts the session and the key is the one the POST is checked against.
     */
    #[Maho\Config\Route('/edgecache/formkey', name: 'edgecache.formkey', methods: ['GET'])]
    public function indexAction(): void
    {
        $this->getResponse()
            ->setHeader('Cache-Control', Icecube_EdgeCache_Model_Policy::PRIVATE_CACHE_CONTROL, true)
            ->setBodyJson(['form_key' => Mage::getSingleton('core/session')->getFormKey()]);
    }
}
