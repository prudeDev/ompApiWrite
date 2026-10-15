<?php

/**
 * @file plugins/generic/ompApiWrite/classes/DoiHelper.inc.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class DoiHelper
 * @ingroup plugins_generic_ompApiWrite
 *
 * @brief Store and validate DOIs on the objects OMP assigns them to:
 *        publications (the book), chapters, publication formats and
 *        submission files.
 *
 *        In OMP a DOI is a "pub-id" (`pub-id::doi`) written through the DAO's
 *        changePubId()/deletePubId().
 */

class DoiHelper {

	/**
	 * Was a `doi` key sent at all? (Distinguishes "not sent" from "clear it".)
	 */
	public static function requested($params) {
		return array_key_exists('doi', $params);
	}

	/**
	 * Validate a DOI before writing it.
	 *
	 * @param string|null $doi
	 * @param int $contextId
	 * @param string|null $currentDoi the object's existing DOI, so re-sending it
	 *   unchanged on an edit is not flagged as a duplicate
	 * @return string|null  error message, or null when valid
	 */
	public static function validate($doi, $contextId, $currentDoi = null) {
		$doi = self::normalize($doi);
		if ($doi === null) {
			return null; // clearing the DOI is always allowed
		}
		// A DOI always starts with "10." followed by the registrant prefix.
		if (!preg_match('#^10\.\d{4,9}/\S+$#', $doi)) {
			return __('plugins.generic.ompApiWrite.doi.invalid');
		}
		if ($doi === self::normalize($currentDoi)) {
			return null; // unchanged
		}
		// Every object type OMP can hold a DOI on, queried on its own DAO.
		$daos = array(
			DAORegistry::getDAO('PublicationDAO'),
			DAORegistry::getDAO('ChapterDAO'),
			DAORegistry::getDAO('PublicationFormatDAO'),
			DAORegistry::getDAO('SubmissionFileDAO'),
		);
		foreach ($daos as $dao) {
			if ($dao && method_exists($dao, 'pubIdExists') && $dao->pubIdExists('doi', $doi, null, $contextId)) {
				return __('plugins.generic.ompApiWrite.doi.duplicate');
			}
		}
		return null;
	}

	/**
	 * Write (or clear) the DOI of an object.
	 *
	 * @param DAO $dao a DAO implementing changePubId()/deletePubId()
	 * @param int $objectId
	 * @param string|null $doi  empty/null clears the DOI
	 */
	public static function store($dao, $objectId, $doi) {
		$doi = self::normalize($doi);
		if ($doi === null) {
			$dao->deletePubId((int) $objectId, 'doi');
		} else {
			$dao->changePubId((int) $objectId, 'doi', $doi);
		}
	}

	/**
	 * Read the stored DOI of an object, or null.
	 */
	public static function get($object) {
		$doi = $object->getData('pub-id::doi');
		return is_string($doi) && $doi !== '' ? $doi : null;
	}

	/**
	 * Trim, and treat empty string as "no DOI".
	 */
	protected static function normalize($doi) {
		if ($doi === null) {
			return null;
		}
		$doi = trim((string) $doi);
		return $doi === '' ? null : $doi;
	}
}
