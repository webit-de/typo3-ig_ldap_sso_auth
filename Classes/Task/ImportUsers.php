<?php
/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

/**
 * Synchronizes users for selected context and configuration.
 *
 * Context may be FE, BE or both. A single configuration may be chosen or all of them.
 *
 * @author     Francois Suter <typo3@cobweb.ch>
 * @package    TYPO3
 * @subpackage ig_ldap_sso_auth
 */
class Tx_IgLdapSsoAuth_Task_ImportUsers extends tx_scheduler_Task {

	/**
	 * Synchronization context (may be FE, BE or both).
	 *
	 * @var string
	 */
	protected $context = 'both';

	/**
	 * Selected LDAP configuration (may be 0 (for all) or a configuration uid).
	 *
	 * @var integer
	 */
	protected $configuration = 0;

	/**
	 * Defines how missing users (i.e. TYPO3 users which are no longer found on the LDAP server)
	 * should be handled. Can be "disable", "delete" or "nothing".
	 *
	 * @var string
	 */
	protected $missingUsersHandling = 'nothing';

	/**
	 * Defines how restored users (i.e. TYPO3 users which were deleted or disabled on the TYPO3 side,
	 * but still exist on the LDAP server) should be handled. Can be "enable", "undelete", "both" or "nothing".
	 *
	 * @var string
	 */
	protected $restoredUsersHandling = 'nothing';

	/**
	 * Performs the synchronization of LDAP users according to selected parameters.
	 *
	 * @throws Exception
	 * @return boolean Returns TRUE on successful execution, FALSE on error
	 */
	public function execute() {

		// Assemble a list of configuration and contexts for import
		/** @var Tx_IgLdapSsoAuth_Domain_Repository_ConfigurationRepository $configurationRepository */
		$configurationRepository = t3lib_div::makeInstance('Tx_IgLdapSsoAuth_Domain_Repository_ConfigurationRepository');
		if (empty($this->configuration)) {
			$ldapConfigurations = $configurationRepository->fetchAll();
		} else {
			$configuration = $configurationRepository->fetchByUid($this->configuration);
			$ldapConfigurations = array();
			if ($configuration !== NULL) {
				$ldapConfigurations[] = $configuration;
			}
		}
		if ($this->context == 'both') {
			$executionContexts = array('fe', 'be');
		} else {
			$executionContexts = array($this->context);
		}

		// Start a database transaction with all our changes
		// Syntax is compatible with MySQL, Oracle, MSSQL and PostgreSQL
		$this->getDatabaseConnection()->sql_query('START TRANSACTION');

		// Loop on each configuration and context and import the related users
		$failures = 0;
		foreach ($ldapConfigurations as $aConfiguration) {
			foreach ($executionContexts as $aContext) {
				/** @var Tx_IgLdapSsoAuth_Utility_UserImport $importUtility */
				$importUtility = t3lib_div::makeInstance(
					'Tx_IgLdapSsoAuth_Utility_UserImport',
					$aConfiguration['uid'],
					$aContext
				);
				// Start by connecting to the designated LDAP/AD server
				$success = tx_igldapssoauth_ldap::connect(tx_igldapssoauth_config::getLdapConfiguration());
				// Proceed with import if successful
				if ($success) {

					$ldapUsers = $importUtility->fetchLdapUsers();
					// Consider that fetching no users from LDAP is an error
					if (count($ldapUsers) == 0) {
						$failures++;

					// Otherwise proceed with import
					} else {
						// Disable or delete users, according to settings
						if ($this->missingUsersHandling == 'disable') {
							$importUtility->disableUsers();
						} elseif ($this->missingUsersHandling == 'delete') {
							$importUtility->deleteUsers();
						}
						$typo3Users = $importUtility->fetchTypo3Users($ldapUsers);
						$config = $importUtility->getConfiguration();

						// Loop on all users and import them
						foreach ($ldapUsers as $index => $aUser) {
							// Merge LDAP and TYPO3 information
							$user = tx_igldapssoauth_auth::merge($aUser, $typo3Users[$index], $config['users']['mapping']);

							// Import the user using information from LDAP
							$importUtility->import($user, $aUser, $this->restoredUsersHandling);
						}

					}
					// Clean up
					unset($importUtility);
					tx_igldapssoauth_ldap::disconnect();
				} else {
					$failures++;
				}
			}
		}

		// If some failures were registered, rollback the whole transaction and report error
		if ($failures > 0) {
			$this->getDatabaseConnection()->sql_query('ROLLBACK');
			throw new Exception(
				'Some or all imports failed. Synchronisation was aborted. Check your settings or your network connections',
				1410774015
			);

		} else {
			// Everything went fine, commit the changes
			$this->getDatabaseConnection()->sql_query('COMMIT');
		}
		return TRUE;
	}

	/**
	 * This method returns the context and configuration as additional information.
	 *
	 * @return	string	Information to display
	 */
	public function getAdditionalInformation() {
		if (empty($this->configuration)) {
			$configurationName = $GLOBALS['LANG']->sL('LLL:EXT:ig_ldap_sso_auth/Resources/Private/Language/locallang.xml:task.import_users.field.configuration.all');
		} else {
			$configurationName = $this->getConfigurationName();
		}
		$info = sprintf(
			$GLOBALS['LANG']->sL('LLL:EXT:ig_ldap_sso_auth/Resources/Private/Language/locallang.xml:task.import_users.additional_information'),
			$this->getContext(),
			$configurationName
		);
		return $info;
	}

	/**
	 * Sets the context parameter.
	 *
	 * @param $context
	 */
	public function setContext($context) {
		$this->context = $context;
	}

	/**
	 * Returns the context parameter.
	 *
	 * @return mixed
	 */
	public function getContext() {
		return $this->context;
	}

	/**
	 * Sets the configuration.
	 *
	 * @param $configuration
	 */
	public function setConfiguration($configuration) {
		$this->configuration = (int)$configuration;
	}

	/**
	 * Returns the current configuration.
	 *
	 * @return int
	 */
	public function getConfiguration() {
		return $this->configuration;
	}

	/**
	 * Sets the missing users handling flag.
	 *
	 * NOTE: behavior defaults to "nothing".
	 *
	 * @param string $missingUsersHandling Can be "disable", "delete" or "nothing".
	 */
	public function setMissingUsersHandling($missingUsersHandling) {
		$this->missingUsersHandling = $missingUsersHandling;
	}

	/**
	 * Returns the missing users handling flag.
	 *
	 * @return string
	 */
	public function getMissingUsersHandling() {
		return $this->missingUsersHandling;
	}

	/**
	 * Sets the restored users handling flag.
	 *
	 * NOTE: behavior defaults to "nothing".
	 *
	 * @param string $restoredUsersHandling Can be "enable", "undelete", "both" or "nothing".
	 */
	public function setRestoredUsersHandling($restoredUsersHandling) {
		$this->restoredUsersHandling = $restoredUsersHandling;
	}

	/**
	 * Returns the restored users handling flag.
	 *
	 * @return string
	 */
	public function getRestoredUsersHandling() {
		return $this->restoredUsersHandling;
	}

	/**
	 * Returns the name of the current configuration.
	 *
	 * @return string
	 */
	public function getConfigurationName() {
		/** @var Tx_IgLdapSsoAuth_Domain_Repository_ConfigurationRepository $configurationRepository */
		$configurationRepository = t3lib_div::makeInstance('Tx_IgLdapSsoAuth_Domain_Repository_ConfigurationRepository');
		$ldapConfiguration = $configurationRepository->fetchByUid($this->configuration);
		if ($ldapConfiguration === NULL) {
			return '';
		} else {
			return $ldapConfiguration['name'];
		}
	}

	/**
	 * Returns the database connection.
	 *
	 * @return t3lib_DB
	 */
	protected function getDatabaseConnection() {
		return $GLOBALS['TYPO3_DB'];
	}
}
