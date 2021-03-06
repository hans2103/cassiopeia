<?php
/**
 * Joomla! Content Management System
 *
 * @copyright  Copyright (C) 2005 - 2020 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\CMS\Extension;

\defined('JPATH_PLATFORM') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Dispatcher\ComponentDispatcherFactoryInterface;
use Joomla\CMS\Dispatcher\DispatcherInterface;

/**
 * Access to component specific services.
 *
 * @since  4.0.0
 */
class Component implements ComponentInterface
{
	/**
	 * The dispatcher factory.
	 *
	 * @var ComponentDispatcherFactoryInterface
	 *
	 * @since  4.0.0
	 */
	private $dispatcherFactory;

	/**
	 * Component constructor.
	 *
	 * @param   ComponentDispatcherFactoryInterface  $dispatcherFactory  The dispatcher factory
	 *
	 * @since   4.0.0
	 */
	public function __construct(ComponentDispatcherFactoryInterface $dispatcherFactory)
	{
		$this->dispatcherFactory = $dispatcherFactory;
	}

	/**
	 * Returns the dispatcher for the given application.
	 *
	 * @param   CMSApplicationInterface  $application  The application
	 *
	 * @return  DispatcherInterface
	 *
	 * @since   4.0.0
	 */
	public function getDispatcher(CMSApplicationInterface $application): DispatcherInterface
	{
		return $this->dispatcherFactory->createDispatcher($application);
	}
}
