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
 * Hook into \TYPO3\CMS\Recordlist\RecordList\DatabaseRecordList and
 * \TYPO3\CMS\Backend\Utility\IconUtility to visually change
 * the icon associated to a FE/BE user/group record based on whether
 * the record is linked to LDAP.
 *
 * @author     Xavier Perseguers <xavier@typo3.org>
 * @package    TYPO3
 * @subpackage ig_ldap_sso_auth
 */
class Tx_IgLdapSsoAuth_Hooks_DatabaseRecordListIconUtility implements t3lib_localRecordListGetTableHook {

	/**
	 * Modifies the DB list query.
	 *
	 * @param string $table The current database table
	 * @param integer $pageId The record's page ID
	 * @param string $additionalWhereClause An additional WHERE clause
	 * @param string $selectedFieldsList Comma separated list of selected fields
	 * @param localRecordList $parentObject Parent localRecordList object
	 * @return void
	 * @see \TYPO3\CMS\Recordlist\RecordList\DatabaseRecordList::getTable()
	 */
	public function getDBlistQuery($table, $pageId, &$additionalWhereClause, &$selectedFieldsList, &$parentObject) {
		if (t3lib_div::inList('be_groups,be_users,fe_groups,fe_users', $table)) {
			$selectedFieldsList .= ',tx_igldapssoauth_dn';
		}
	}

	/**
	 * Overrides the icon overlay with a LDAP symbol, if needed.
	 *
	 * @param string $table The current database table
	 * @param array $row The current record
	 * @param array &$status The array of associated statuses
	 * @return void
	 * @see \TYPO3\CMS\Backend\Utility\IconUtility::mapRecordOverlayToSpriteIconName()
	 */
	public function overrideIconOverlay($table, array $row, array &$status) {
		if (t3lib_div::inList('be_groups,be_users,fe_groups,fe_users', $table)) {
			$status['is_ldap_record'] = !empty($row['tx_igldapssoauth_dn']);
		}
	}

}
