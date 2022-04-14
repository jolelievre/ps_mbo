<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace PrestaShop\Module\Mbo\Module\Filters;

class Origin
{
    public const FLAG = 'origin';

    /* Bitwise operators */
    public const DISK = 1;
    public const ADDONS_MUST_HAVE = 2;
    public const ADDONS_SERVICE = 4;
    public const ADDONS_NATIVE = 8;
    public const ADDONS_NATIVE_ALL = 16;
    public const ADDONS_CUSTOMER = 32;
    public const ADDONS_ALL = 62;

    public const ALL = -1;
}
