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

namespace PrestaShop\Module\Mbo\Addons\Module;

use Exception;
use PrestaShop\Module\Mbo\Addons\AddonsCollection;
use PrestaShop\Module\Mbo\Addons\ManagerInterface;
use PrestaShop\PrestaShop\Adapter\Module\Module;
use PrestaShop\PrestaShop\Adapter\Module\ModuleDataUpdater;
use PrestaShop\PrestaShop\Adapter\Module\ModuleZipManager;
use PrestaShop\PrestaShop\Core\Cache\Clearer\CacheClearerInterface;
use PrestaShop\PrestaShop\Core\Domain\Theme\Exception\FailedToEnableThemeModuleException;
use PrestaShopBundle\Event\ModuleManagementEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\Translation\TranslatorInterface;

class ModuleManager implements ManagerInterface
{
    /**
     * Module Data Provider.
     *
     * @var ModuleDataProvider
     */
    private $modulesProvider;

    /**
     * Module Data Provider.
     *
     * @var AdminModuleDataProvider
     */
    private $adminModuleProvider;

    /**
     * Module Data Provider.
     *
     * @var ModuleDataUpdater
     */
    private $moduleUpdater;

    /**
     * Module Repository.
     *
     * @var ModuleRepository
     */
    private $moduleRepository;

    /**
     * Module Zip Manager.
     *
     * @var ModuleZipManager
     */
    private $moduleZipManager;

    /**
     * Translator.
     *
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * Additionnal data used for module actions.
     *
     * @var ParameterBag
     */
    private $actionParams;

    /**
     * @var CacheClearerInterface
     */
    private $symfonyCacheClearer;

    /**
     * Used to check if the cache has already been cleaned.
     *
     * @var bool
     */
    private $cacheCleared = false;

    /**
     * @param ModuleDataProvider $modulesProvider
     * @param ModuleDataUpdater $modulesUpdater
     * @param ModuleRepository $moduleRepository
     * @param ModuleZipManager $moduleZipManager
     * @param TranslatorInterface $translator
     * @param EventDispatcherInterface $eventDispatcher
     * @param CacheClearerInterface $symfonyCacheClearer
     */
    public function __construct(
        AdminModuleDataProvider $adminModuleProvider,
        ModuleDataProvider $modulesProvider,
        ModuleDataUpdater $modulesUpdater,
        ModuleRepository $moduleRepository,
        ModuleZipManager $moduleZipManager,
        TranslatorInterface $translator,
        EventDispatcherInterface $eventDispatcher,
        CacheClearerInterface $symfonyCacheClearer
    ) {
        $this->adminModuleProvider = $adminModuleProvider;
        $this->modulesProvider = $modulesProvider;
        $this->moduleUpdater = $modulesUpdater;
        $this->moduleRepository = $moduleRepository;
        $this->moduleZipManager = $moduleZipManager;
        $this->translator = $translator;
        $this->eventDispatcher = $eventDispatcher;
        $this->symfonyCacheClearer = $symfonyCacheClearer;
        $this->actionParams = new ParameterBag();
    }

    /**
     * For some actions, you may need to add params like confirmation details.
     * This setter is the way to register them in the manager.
     *
     * @param array $actionParams
     *
     * @return $this
     */
    public function setActionParams(array $actionParams)
    {
        $this->actionParams->replace($actionParams);

        return $this;
    }

    /**
     * @param callable $modulesPresenter
     *
     * @return object
     */
    public function getModulesWithNotifications(callable $modulesPresenter)
    {
        $modules = $this->groupModulesByInstallationProgress();

        foreach ($modules as $moduleLabel => $modulesPart) {
            $collection = AddonsCollection::createFrom($modulesPart);
            $this->adminModuleProvider->generateAddonsUrls($collection, str_replace('to_', '', $moduleLabel));
            $modules->{$moduleLabel} = $modulesPresenter($collection);
        }

        return $modules;
    }

    /**
     * Detailed array of number of modules per notification type.
     *
     * @return array
     */
    public function countModulesWithNotificationsDetailed()
    {
        $notificationCounts = [
            'count' => 0,
        ];

        foreach ((array) $this->groupModulesByInstallationProgress() as $key => $modules) {
            $count = count($modules);
            $notificationCounts[$key] = $count;
            $notificationCounts['count'] += $count;
        }

        return $notificationCounts;
    }

    /**
     * @return object
     */
    protected function groupModulesByInstallationProgress()
    {
        $installedProducts = $this->moduleRepository->getInstalledModules();

        $modules = (object) [
            'to_configure' => [],
            'to_update' => [],
        ];

        /*
         * @var Module
         */
        foreach ($installedProducts as $installedProduct) {
            if ($this->shouldRecommendConfigurationForModule($installedProduct)) {
                $modules->to_configure[] = (object) $installedProduct;
            }

            if ($installedProduct->canBeUpgraded()) {
                $modules->to_update[] = (object) $installedProduct;
            }
        }

        return $modules;
    }

    /**
     * @param Module $installedProduct
     *
     * @return bool
     */
    protected function shouldRecommendConfigurationForModule(Module $installedProduct)
    {
        $warnings = $this->getModuleInstallationWarnings($installedProduct);

        return !empty($warnings);
    }

    /**
     * @param Module $installedProduct
     *
     * @return string|array
     */
    protected function getModuleInstallationWarnings(Module $installedProduct)
    {
        if ($installedProduct->hasValidInstance()) {
            return $installedProduct->getInstance()->warning;
        }

        return [];
    }

    /**
     * Add new module from zipball. This will unzip the file and move the content
     * to the right locations.
     * A theme can bundle modules, resources, documentation, email templates and so on.
     *
     * @param string $source The source can be a module name (installed from either local disk or addons.prestashop.com).
     *                       or a location (url or path to the zip file)
     *
     * @return bool true for success
     */
    public function install($source)
    {
        // in CLI mode, there is no employee set up
        if (!$this->adminModuleProvider->isAllowedAccess(__FUNCTION__)) {
            throw new Exception($this->translator->trans('You are not allowed to install modules.', [], 'Admin.Modules.Notification'));
        }

        if (is_file($source)) {
            $name = $this->moduleZipManager->getName($source);
        } else {
            $name = $source;
            $source = null;
        }

        if ($this->modulesProvider->isInstalled($name)) {
            return $this->upgrade($name, 'latest', $source);
        }

        if (!empty($source)) {
            $this->moduleZipManager->storeInModulesFolder($source);
        } elseif (!$this->modulesProvider->isOnDisk($name)) {
            if (!$this->moduleUpdater->setModuleOnDiskFromAddons($name)) {
                throw new FailedToEnableThemeModuleException($name, $this->translator->trans('The module %name% could not be found on Addons.', ['%name%' => $name], 'Admin.Modules.Notification'));
            }
        }

        $module = $this->moduleRepository->getModule($name);
        $result = $module->onInstall();

        $this->checkAndClearCache($result);
        $this->dispatch(ModuleManagementEvent::INSTALL, $module);

        return $result;
    }

    /**
     * Execute post install
     *
     * @param string $moduleName
     *
     * @return bool true for success
     */
    public function postInstall(string $moduleName): bool
    {
        if (!$this->modulesProvider->isInstalled($moduleName)) {
            return false;
        }

        if (!$this->modulesProvider->isOnDisk($moduleName)) {
            return false;
        }

        $module = $this->moduleRepository->getModule($moduleName);
        /** @var Module $module */
        $result = $module->onPostInstall();

        $this->checkAndClearCache($result);
        $this->dispatch(ModuleManagementEvent::POST_INSTALL, $module);

        return $result;
    }

    /**
     * Remove all theme files, resources, documentation and specific modules.
     *
     * @param string $name The source can be a module name (installed from either local disk or addons.prestashop.com).
     *                     or a location (url or path to the zip file)
     *
     * @return bool true for success
     */
    public function uninstall($name)
    {
        // Check permissions:
        // * Employee can delete
        // * Employee can delete this specific module
        if (!$this->adminModuleProvider->isAllowedAccess(__FUNCTION__, $name)) {
            throw new Exception($this->translator->trans('You are not allowed to uninstall the module %module%.', ['%module%' => $name], 'Admin.Modules.Notification'));
        }

        $this->checkIsInstalled($name);

        // Get module instance and uninstall it
        $module = $this->moduleRepository->getModule($name);
        $result = $module->onUninstall();

        if ($result && $this->actionParams->get('deletion', false)) {
            $result = $this->removeModuleFromDisk($name);
        }

        $this->checkAndClearCache($result);
        $this->dispatch(ModuleManagementEvent::UNINSTALL, $module);

        return $result;
    }

    /**
     * Download new files from source, backup old files, replace files with new ones
     * and execute all necessary migration scripts form current version to the new one.
     *
     * @param string $name the theme you want to upgrade
     * @param string $version the version you want to up upgrade to
     * @param string $source if the upgrade is not coming from addons, you need to specify the path to the zipball
     *
     * @return bool true for success
     */
    public function upgrade($name, $version = 'latest', $source = null)
    {
        if (!$this->adminModuleProvider->isAllowedAccess(__FUNCTION__, $name)) {
            throw new Exception($this->translator->trans('You are not allowed to upgrade the module %module%.', ['%module%' => $name], 'Admin.Modules.Notification'));
        }

        $this->checkIsInstalled($name);
        $module = $this->moduleRepository->getModule($name);

        // Get new module
        // 1- From source
        if ($source != null) {
            $this->moduleZipManager->storeInModulesFolder($source);
        } elseif ($module->canBeUpgradedFromAddons()) {
            // 2- From Addons
            // This step is not mandatory (in case of local module),
            // we do not check the result
            $this->moduleUpdater->setModuleOnDiskFromAddons($name);
        }

        // Load and execute upgrade files
        $result = $this->moduleUpdater->upgrade($name) && $module->onUpgrade($version);

        $this->checkAndClearCache($result);
        $this->dispatch(ModuleManagementEvent::UPGRADE, $module);

        return $result;
    }

    /**
     * Disable a module without uninstalling it.
     * Allows the merchant to temporarly remove a module without uninstalling it.
     *
     * @param string $name The module name to disable
     *
     * @return bool True for success
     */
    public function disable($name)
    {
        if (!$this->adminModuleProvider->isAllowedAccess(__FUNCTION__, $name)) {
            throw new Exception($this->translator->trans('You are not allowed to disable the module %module%.', ['%module%' => $name], 'Admin.Modules.Notification'));
        }

        $this->checkIsInstalled($name);

        $module = $this->moduleRepository->getModule($name);

        try {
            $result = $module->onDisable();
        } catch (Exception $e) {
            throw new Exception($this->translator->trans('Error when disabling module %module%. %error_details%.', ['%module%' => $name, '%error_details%' => $e->getMessage()], 'Admin.Modules.Notification'), 0, $e);
        }

        $this->checkAndClearCache($result);
        $this->dispatch(ModuleManagementEvent::DISABLE, $module);

        return $result;
    }

    /**
     * Enable a module previously disabled.
     *
     * @param string $name The module name to enable
     *
     * @return bool True for success
     */
    public function enable($name)
    {
        if (!$this->adminModuleProvider->isAllowedAccess(__FUNCTION__, $name)) {
            throw new Exception($this->translator->trans('You are not allowed to enable the module %module%.', ['%module%' => $name], 'Admin.Modules.Notification'));
        }

        $this->checkIsInstalled($name);

        $module = $this->moduleRepository->getModule($name);

        try {
            $result = $module->onEnable();
        } catch (Exception $e) {
            throw new Exception($this->translator->trans('Error when enabling module %module%. %error_details%.', ['%module%' => $name, '%error_details%' => $e->getMessage()], 'Admin.Modules.Notification'), 0, $e);
        }

        $this->checkAndClearCache($result);
        $this->dispatch(ModuleManagementEvent::ENABLE, $module);

        return $result;
    }

    /**
     * Disable a module specifically on mobile.
     *
     * @param string $name The module name to disable
     *
     * @return bool True for success
     */
    public function disableMobile($name)
    {
        if (!$this->adminModuleProvider->isAllowedAccess(__FUNCTION__, $name)) {
            throw new Exception($this->translator->trans('You are not allowed to disable the module %module% on mobile.', ['%module%' => $name], 'Admin.Modules.Notification'));
        }

        $this->checkIsInstalled($name);

        $module = $this->moduleRepository->getModule($name);

        try {
            $result = $module->onMobileDisable();
        } catch (Exception $e) {
            throw new Exception($this->translator->trans('Error when disabling module %module% on mobile. %error_details%', ['%module%' => $name, '%error_details%' => $e->getMessage()], 'Admin.Modules.Notification'), 0, $e);
        }

        $this->checkAndClearCache($result);

        return $result;
    }

    /**
     * Enable a module previously disabled on mobile.
     *
     * @param string $name The module name to enable
     *
     * @return bool True for success
     */
    public function enableMobile($name)
    {
        if (!$this->adminModuleProvider->isAllowedAccess(__FUNCTION__, $name)) {
            throw new Exception($this->translator->trans('You are not allowed to enable the module %module% on mobile.', ['%module%' => $name], 'Admin.Modules.Notification'));
        }

        $this->checkIsInstalled($name);

        $module = $this->moduleRepository->getModule($name);

        try {
            $result = $module->onMobileEnable();
        } catch (Exception $e) {
            throw new Exception($this->translator->trans('Error when enabling module %module% on mobile. %error_details%', ['%module%' => $name, '%error_details%' => $e->getMessage()], 'Admin.Modules.Notification'), 0, $e);
        }

        $this->checkAndClearCache($result);

        return $result;
    }

    /**
     * Actions to perform to restaure default settings.
     *
     * @param string $name The theme name to reset
     *
     * @return bool True for success
     */
    public function reset($name, $keep_data = false)
    {
        if (!$this->adminModuleProvider->isAllowedAccess('install') || !$this->adminModuleProvider->isAllowedAccess('uninstall', $name)) {
            throw new Exception($this->translator->trans('You are not allowed to reset the module %module%.', ['%module%' => $name], 'Admin.Modules.Notification'));
        }

        $this->checkIsInstalled($name);

        $module = $this->moduleRepository->getModule($name);

        try {
            if ((bool) $keep_data && method_exists($module->getInstance(), 'reset')) {
                $this->dispatch(ModuleManagementEvent::UNINSTALL, $module);
                $status = $module->onReset();
                $this->dispatch(ModuleManagementEvent::INSTALL, $module);
            } else {
                $status = ($this->uninstall($name) && $this->install($name));
            }
        } catch (Exception $e) {
            throw new Exception($this->translator->trans('Error when resetting module %module%. %error_details%', ['%module%' => $name, '%error_details%' => $e->getMessage()], 'Admin.Modules.Notification'), 0, $e);
        }

        return $status;
    }

    /**
     * Shortcut to the module data provider in order to know if a module is enabled.
     *
     * @param string $name The technical module name
     *
     * @return bool
     */
    public function isEnabled($name)
    {
        return $this->modulesProvider->isEnabled($name);
    }

    /**
     * Shortcut to the module data provider in order to know if a module is installed.
     *
     * @param string $name The technical module name
     *
     * @return bool True is installed
     */
    public function isInstalled($name)
    {
        return $this->modulesProvider->isInstalled($name);
    }

    /**
     * Shortcut to the module data provider in order to know the module id depends
     * on its name.
     *
     * @param string $name The technical module name
     *
     * @return int the Module Id, or 0 if not found
     */
    public function getModuleIdByName($name)
    {
        return $this->modulesProvider->getModuleIdByName($name);
    }

    /**
     * Shortcut to the module data updater to remove the module from the disk.
     *
     * @param string $name The technical module name
     *
     * @return bool True if files were properly removed
     */
    public function removeModuleFromDisk($name)
    {
        return $this->moduleUpdater->removeModuleFromDisk($name);
    }

    /**
     * Returns the last error, if found.
     *
     * @param string $name The technical module name
     *
     * @return string|null The last error added to the module if found
     */
    public function getError($name)
    {
        $message = null;
        $module = $this->moduleRepository->getModule($name);
        if ($module->hasValidInstance()) {
            $errors = $module->getInstance()->getErrors();
            $message = array_pop($errors);
        } else {
            // Invalid instance: Missing or with syntax error
            $message = $this->translator->trans(
                'The module is invalid and cannot be loaded.',
                [],
                'Admin.Modules.Notification'
            );
        }

        if (empty($message)) {
            $message = $this->translator->trans(
                'Unfortunately, the module did not return additional details.',
                [],
                'Admin.Modules.Notification'
            );
        }

        return $message;
    }

    /**
     * This function is a refacto of the event dispatching.
     *
     * @param string $event
     * @param Module $module
     */
    private function dispatch(string $event, $module)
    {
        $this->eventDispatcher->dispatch(new ModuleManagementEvent($module), $event);
    }

    private function checkIsInstalled($name)
    {
        if (!$this->modulesProvider->isInstalled($name)) {
            throw new Exception($this->translator->trans('The module %module% must be installed first', ['%module%' => $name], 'Admin.Modules.Notification'));
        }
    }

    /**
     * @param bool $result
     */
    private function checkAndClearCache($result)
    {
        if ($result && $this->actionParams->get('cacheClearEnabled', true)) {
            $this->clearCache();
        }
    }

    /**
     * Clear smarty and Symfony cache (the sf2 cache is remove on the process shutdown).
     */
    private function clearCache()
    {
        if ($this->cacheCleared) {
            return;
        }

        $this->symfonyCacheClearer->clear();
        $this->cacheCleared = true;
    }
}
