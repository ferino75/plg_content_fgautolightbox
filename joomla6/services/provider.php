<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Content.fgautolightbox
 * @copyright   Copyright (C) 2026 FG. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use FG\Plugin\Content\Fgautolightbox\Extension\Fgautolightbox;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            static function (Container $container) {
                $dispatcher = $container->get(DispatcherInterface::class);
                $plugin = new Fgautolightbox(
                    $dispatcher,
                    (array) PluginHelper::getPlugin('content', 'fgautolightbox')
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
