<?php
/**
 * 2007-2017 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2017 PrestaShop SA
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */
namespace PrestaShop\PrestaShop\Adapter\Module;

use Doctrine\Common\Cache\CacheProvider;
use PrestaShop\PrestaShop\Core\Addon\AddonListFilterOrigin;
use PrestaShopBundle\Service\DataProvider\Admin\AddonsInterface;
use PrestaShopBundle\Service\DataProvider\Admin\CategoriesProvider;
use PrestaShopBundle\Service\DataProvider\Admin\ModuleInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Router;
use Symfony\Component\Translation\TranslatorInterface;

/**
 * Data provider for new Architecture, about Module object model.
 *
 * This class will provide data from DB / ORM about Modules for the Admin interface.
 * This is an Adapter that works with the Legacy code and persistence behaviors.
 */
class AdminModuleDataProvider implements ModuleInterface
{
    const _CACHEKEY_MODULES_ = '_addons_modules';

    const _DAY_IN_SECONDS_ = 86400; /* Cache for One Day */

    private $languageISO;
    private $logger;
    private $router = null;
    private $addonsDataProvider;
    private $categoriesProvider;
    private $cacheProvider;

    protected $catalog_modules = array();
    protected $catalog_modules_names;
    public $failed = false;

    public function __construct(
        TranslatorInterface $translator,
        LoggerInterface $logger,
        AddonsInterface $addonsDataProvider,
        CategoriesProvider $categoriesProvider,
        CacheProvider $cacheProvider = null
    ) {
        list($this->languageISO) = explode('-', $translator->getLocale());

        $this->logger = $logger;
        $this->addonsDataProvider = $addonsDataProvider;
        $this->categoriesProvider = $categoriesProvider;
        $this->cacheProvider = $cacheProvider;
    }

    public function setRouter(Router $router)
    {
        $this->router = $router;
    }

    public function clearCatalogCache()
    {
        if ($this->cacheProvider) {
            $this->cacheProvider->delete($this->languageISO.self::_CACHEKEY_MODULES_);
        }
        $this->catalog_modules = array();
    }

    public function getAllModules()
    {
        return \Module::getModulesOnDisk(true,
            $this->addonsDataProvider->isAddonsAuthenticated(),
            (int) \Context::getContext()->employee->id
        );
    }

    public function getCatalogModules(array $filters = array())
    {
        if (count($this->catalog_modules) === 0 && !$this->failed) {
            $this->loadCatalogData();
        }

        return $this->applyModuleFilters(
                $this->catalog_modules, $filters
        );
    }

    public function getCatalogModulesNames(array $filter = array())
    {
        return array_keys($this->getCatalogModules($filter));
    }

    public function generateAddonsUrls(array $addons, $specific_action = null)
    {
        foreach ($addons as &$addon) {
            $urls = array();
            foreach (array('install', 'uninstall', 'enable', 'disable', 'enable_mobile', 'disable_mobile', 'reset', 'upgrade') as $action) {
                $urls[$action] = $this->router->generate('admin_module_manage_action', array(
                    'action' => $action,
                    'module_name' => $addon->attributes->get('name'),
                ));
            }
            $urls['configure'] = $this->router->generate('admin_module_configure_action', array(
                'module_name' => $addon->attributes->get('name'),
            ));

            if ($addon->database->has('installed') && $addon->database->get('installed') == 1) {
                if ($addon->database->get('active') == 0) {
                    $url_active = 'enable';
                    unset(
                        $urls['install'],
                        $urls['disable']
                    );
                } elseif ($addon->attributes->get('is_configurable') == 1) {
                    $url_active = 'configure';
                    unset(
                        $urls['enable'],
                        $urls['install']
                    );
                } else {
                    $url_active = 'disable';
                    unset(
                        $urls['install'],
                        $urls['enable'],
                        $urls['configure']
                    );
                }

                if ($addon->attributes->get('is_configurable') == 0) {
                    unset($urls['configure']);
                }

                if ($addon->canBeUpgraded()) {
                    $url_active = 'upgrade';
                } else {
                    unset(
                        $urls['upgrade']
                    );
                }
                if ($addon->database->get('active_on_mobile') == 0) {
                    unset($urls['disable_mobile']);
                } else {
                    unset($urls['enable_mobile']);
                }
                if (!$addon->canBeUpgraded()) {
                    unset(
                        $urls['upgrade']
                    );
                }
            } elseif (
                !$addon->attributes->has('origin') ||
                $addon->disk->get('is_present') == true ||
                in_array($addon->attributes->get('origin'), array('native', 'native_all', 'partner', 'customer'))
            ) {
                $url_active = 'install';
                unset(
                    $urls['uninstall'],
                    $urls['enable'],
                    $urls['disable'],
                    $urls['enable_mobile'],
                    $urls['disable_mobile'],
                    $urls['reset'],
                    $urls['upgrade'],
                    $urls['configure']
                );
            } else {
                $url_active = 'buy';
            }
            if (count($urls)) {
                $addon->attributes->set('urls', $urls);
            }
            if ($specific_action && array_key_exists($specific_action, $urls)) {
                $addon->attributes->set('url_active', $specific_action);
            } else {
                $addon->attributes->set('url_active', $url_active);
            }

            $categoryParent = $this->categoriesProvider->getParentCategory($addon->attributes->get('categoryName'));
            $addon->attributes->set('categoryParent', $categoryParent);
        }

        return $addons;
    }

    public function getModuleAttributesById($moduleId)
    {
        return (array) $this->addonsDataProvider->request('module', array('id_module' => $moduleId));
    }

    protected function applyModuleFilters(array $modules, array $filters)
    {
        if (!count($filters)) {
            return $modules;
        }

        // We get our module IDs to keep
        foreach ($filters as $filter_name => $value) {
            $search_result = array();

            switch ($filter_name) {
                case 'search':
                    // We build our results array.
                    // We could remove directly the non-matching modules, but we will give that for the final loop of this function

                    foreach (explode(' ', $value) as $keyword) {
                        if (empty($keyword)) {
                            continue;
                        }

                        // Instead of looping on the whole module list, we use $module_ids which can already be reduced
                        // thanks to the previous array_intersect(...)
                        foreach ($modules as $key => $module) {
                            if (strpos($module->displayName, $keyword) !== false
                                || strpos($module->name, $keyword) !== false
                                || strpos($module->description, $keyword) !== false) {
                                $search_result[] = $key;
                            }
                        }
                    }
                    break;
                case 'name':
                    // exact given name (should return 0 or 1 result)
                    $search_result[] = $value;
                    break;
                default:
                    // "the switch statement is considered a looping structure for the purposes of continue."
                    continue 2;
            }

            $modules = array_intersect_key($modules, array_flip($search_result));
        }

        return $modules;
    }

    protected function loadCatalogData()
    {
        if ($this->cacheProvider && $this->cacheProvider->contains($this->languageISO.self::_CACHEKEY_MODULES_)) {
            $this->catalog_modules = $this->cacheProvider->fetch($this->languageISO.self::_CACHEKEY_MODULES_);
        }

        if (!$this->catalog_modules) {
            $params = array('format' => 'json');
            $requests = array(
                AddonListFilterOrigin::ADDONS_MUST_HAVE => 'must-have',
                AddonListFilterOrigin::ADDONS_SERVICE => 'service',
                AddonListFilterOrigin::ADDONS_NATIVE => 'native',
                AddonListFilterOrigin::ADDONS_NATIVE_ALL => 'native_all',
            );
            if ($this->addonsDataProvider->isAddonsAuthenticated()) {
                $requests[AddonListFilterOrigin::ADDONS_CUSTOMER] = 'customer';
            }

            try {
                $listAddons = array();
                // We execute each addons request
                foreach ($requests as $action_filter_value => $action) {
                    if (!$this->addonsDataProvider->isAddonsUp()) {
                        continue;
                    }
                    // We add the request name in each product returned by Addons,
                    // so we know whether is bought

                    $addons = $this->addonsDataProvider->request($action, $params);
                    foreach ($addons as $addonsType => $addon) {
                        $addon->origin = $action;
                        $addon->origin_filter_value = $action_filter_value;
                        $addon->categoryParent = $this->categoriesProvider
                            ->getParentCategory($addon->categoryName)
                        ;
                        if (! isset($addon->product_type)) {
                            $addon->productType = isset($addonsType)?rtrim($addonsType, 's'):'module';
                        } else {
                            $addon->productType = $addon->product_type;
                        }
                        $listAddons[$addon->name] = $addon;
                    }
                }

                $this->catalog_modules = $listAddons;
                if ($this->cacheProvider) {
                    $this->cacheProvider->save($this->languageISO.self::_CACHEKEY_MODULES_, $this->catalog_modules, self::_DAY_IN_SECONDS_);
                }
            } catch (\Exception $e) {
                if (!$this->fallbackOnCatalogCache()) {
                    $this->catalog_modules = array();
                    $this->failed = true;
                    $this->logger->error('Data from PrestaShop Addons is invalid, and cannot fallback on cache. ', array('exception' => $e->getMessage()));
                }
            }
        }
    }

    protected function fallbackOnCatalogCache()
    {
        // Fallback on data from cache if exists
        if ($this->cacheProvider) {
            $this->catalog_modules = $this->cacheProvider->fetch($this->languageISO.self::_CACHEKEY_MODULES_);
        }

        return $this->catalog_modules;
    }
}
