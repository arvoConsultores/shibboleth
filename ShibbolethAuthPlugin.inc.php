<?php

/**
 * @file plugins/generic/shibboleth/ShibbolethAuthPlugin.inc.php
 *
 * Copyright (c) 2014-2017 Simon Fraser University
 * Copyright (c) 2003-2017 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 * Code modified (2021) by Josué Cimadevilla at Arvo Consultores. Project funded by Fundación Española de Ciencia y Tecnologia (FECYT)  
 *
 * @class ShibbolethAuthPlugin
 * @ingroup plugins_generic_shibboleth
 *
 * @brief Shibboleth authentication plugin.
 *
 * Assumes Apache mod_shib and appropriate configuration.
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class ShibbolethAuthPlugin extends GenericPlugin {
	// @@@ TODO: Is there a way to disable delete and upgrade actions
	// when the user does not have permission to disable?

	// @@@ TODO: The profile password tab should just be hidden
	// completely when the plugin is enabled.

	/** @var int */
	var $_contextId;

	/** @var bool */
	var $_globallyEnabled;

	var $_singleContext;

	/**
	 * @copydoc Plugin::__construct()
	 */
	function __construct() {
		parent::__construct();
		$this->_contextId = $this->getCurrentContextId();
		$this->_globallyEnabled = $this->getSetting(0, 'enabled');
		$this->_singleContext = true;
		$contextDao = Application::getContextDAO();
		$workingContexts = $contextDao->getAvailable();
		if ($workingContexts && $workingContexts->getCount() > 1) {
			$this->_singleContext = false;
		}
	}

	/**
	 * @copydoc Plugin::register()
	 */
	function register($category, $path) {
		$success = parent::register($category, $path);
		$this->addLocaleData();
		if ($success && $this->getEnabled()) {
			// Register pages to handle login.
			HookRegistry::register(
				'LoadHandler',
				array($this, 'handleRequest')
			);
		}
		return $success;
	}

	/**
	 * @copydoc LazyLoadPlugin::getName()
	 */
	function getName() {
		return 'ShibbolethAuthPlugin';
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	function getDisplayName() {
		return __('plugins.generic.shibboleth.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	function getDescription() {
		return __('plugins.generic.shibboleth.description');
	}

	/**
	 * @copydoc Plugin::isSitePlugin()
	 */
	function isSitePlugin() {
		return true;
	}

	/**
	 * @copydoc Plugin::manage()
	 */
	function manage($args, $request) {
		switch ($request->getUserVar('verb')) {
			case 'settings':
				AppLocale::requireComponents(
					LOCALE_COMPONENT_APP_COMMON,
					LOCALE_COMPONENT_PKP_MANAGER
				);
				$templateMgr = TemplateManager::getManager($request);
				$templateMgr->register_function(
					'plugin_url',
					array($this, 'smartyPluginUrl')
				);

				$this->import('ShibbolethSettingsForm');
				$form = new ShibbolethSettingsForm(
					$this,
					$this->_contextId
				);

				if ($request->getUserVar('save')) {
					$form->readInputData();
					if ($form->validate()) {
						$form->execute();
						return new JSONMessage(true);
					}
				} else {
					$form->initData();
				}
				return new JSONMessage(true, $form->fetch($request));
		}
		return parent::manage($args, $request);
	}

	/**
	 * @copydoc Plugin::getTemplatePath()
	 */
	function getTemplatePath($inCore = false) {
		return parent::getTemplatePath($inCore) . 'templates/';
	}

	/**
	 * @copydoc Plugin::getSetting()
	 */
	function getSetting($contextId, $name) {
		if ($this->_globallyEnabled) {
			return parent::getSetting(0, $name);
		} else {
			return parent::getSetting($contextId, $name);
		}
	}

	/**
	 * @copydoc Plugin::getActions()
	 */
	function getActions($request, $verb) {
		// Don’t allow settings unless enabled in this context.
		/* if (!$this->getEnabled() || !$this->getCanDisable()) {
			return parent::getActions($request, $verb);
		} */

		$router = $request->getRouter();
		import('lib.pkp.classes.linkAction.request.AjaxModal');
		return array_merge(
			array(
				new LinkAction(
					'settings',
					new AjaxModal(
						$router->url(
							$request,
							null,
							null,
							'manage',
							null,
							array(
								'verb' => 'settings',
								'plugin' => $this->getName(),
								'category' => 'generic'
							)
						),
						$this->getDisplayName()
					),
					__('manager.plugins.settings'),
					null
				),
			),
			parent::getActions($request, $verb)
		);
	}


	//
	// Public methods required to support lazy load.
	//
	/**
	 * @copydoc LazyLoadPlugin::getCanEnable()
	 */
	function getCanEnable() {
		// return !$this->_globallyEnabled || $this->_contextId == CONTEXT_SITE || $this->_singleContext;
		if($this->_contextId == 0) {
			return false;
		} else {
			return !$this->_globallyEnabled || $this->_contextId == CONTEXT_SITE || $this->_singleContext;
		}
	}

	/**
	 * @copydoc LazyLoadPlugin::getCanDisable()
	 */
	function getCanDisable() {
		return !$this->_globallyEnabled || $this->_contextId == CONTEXT_SITE || $this->_singleContext;
	}

	/**
	 * @copydoc LazyLoadPlugin::setEnabled()
	 */
	function setEnabled($enabled) {
		if ($this->_singleContext) {
			// LazyLoadPlugin::setEnabled will force the context to CONTEXT_SITE when isSitePlugin returns true
			parent::setEnabled($enabled);
		} else {
			// if there are multiple contexts, allow enabling/disabling at the context level
			$this->updateSetting($this->_contextId, 'enabled', $enabled, 'bool');
		}
	}

/**
	 * Override LazyLoadPlugin::getEnabled() to allow for enabling of
	 * this sitewide plugin within an individual context
	 * @param $contextId integer
	 * @return boolean
	 */
	function getEnabled($contextId = null) {
		if ($contextId == null) {
			$contextId = $this->getCurrentContextId();
			// LazyLoadPlugin::getEnabled just asks here if this is a site plugin
			if ($this->_globallyEnabled || $this->_singleContext) {
				$contextId = CONTEXT_SITE;
			}
		}
		return $this->getSetting($contextId, 'enabled');
	}

	//
	// Callback handler
	// 
	/**
	 * Hook callback: register pages for each login method.
	 * This URL is of the form: shibboleth/{$shibrequest}
	 * @see PKPPageRouter::route()
	 */
	function handleRequest($hookName, $params) {
		$page = $params[0];
		$op = $params[1];
		if ($this->getEnabled()
			&& ($page == 'shibboleth'
				|| ($page == 'login'
					&& array_search(
						$op,
						array(
							'changePassword',
							'index',
							'lostPassword',
							'requestResetPassword',
							'savePassword',
							'signIn',
							'signOut',
						)
					))
				|| ($page == 'user'
					&& array_search(
						$op,
						array(
							'activateUser',
							'register',
							'registerUser',
							'validate',
						)
					)
				)
			)
		) {
			$this->import('pages/ShibbolethHandler');
			define('HANDLER_CLASS', 'ShibbolethHandler');
			define('SHIBBOLETH_PLUGIN_NAME', $this->getName());
			return true;
		}
		return false;
	}
}
