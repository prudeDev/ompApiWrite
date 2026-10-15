<?php

/**
 * @file plugins/generic/ompApiWrite/classes/BookApiHelper.inc.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class BookApiHelper
 * @ingroup plugins_generic_ompApiWrite
 *
 * @brief Lookups, input normalisation, file storage and JSON payloads shared
 *        by the book, author, chapter and publication-format controllers.
 */

import('classes.core.Services');
import('lib.pkp.classes.submission.SubmissionFile'); // SUBMISSION_FILE_* constants
import('plugins.generic.ompApiWrite.classes.DoiHelper');

/** OMP sales types for a file inside a publication format. */
define('OMPAPI_SALES_OPEN_ACCESS', 'openAccess');
define('OMPAPI_SALES_DIRECT', 'directSales');
define('OMPAPI_SALES_NOT_AVAILABLE', 'notAvailable');

class BookApiHelper {

	//
	// Lookups scoped to the current press
	//

	/**
	 * Load a submission and confirm it belongs to the current press.
	 * Prevents touching another press's books by guessing ids.
	 *
	 * @return Submission|null
	 */
	public static function contextSubmission($submissionId, $context) {
		if (!$context) {
			return null;
		}
		$submission = Services::get('submission')->get((int) $submissionId);
		if (!$submission || (int) $submission->getData('contextId') !== (int) $context->getId()) {
			return null;
		}
		return $submission;
	}

	/**
	 * Resolve {submissionId}/{publicationId} from the route, both scoped to the
	 * current press.
	 *
	 * @return array|null [Submission, Publication]
	 */
	public static function contextPublication($args, $context) {
		$submission = self::contextSubmission($args['submissionId'], $context);
		if (!$submission) {
			return null;
		}
		$publication = Services::get('publication')->get((int) $args['publicationId']);
		if (!$publication || (int) $publication->getData('submissionId') !== (int) $submission->getId()) {
			return null;
		}
		return array($submission, $publication);
	}

	/**
	 * A temporary upload (POST /temporaryFiles) owned by the requesting user.
	 *
	 * @return TemporaryFile|null
	 */
	public static function temporaryFile($temporaryFileId, $user) {
		if (empty($temporaryFileId) || !$user) {
			return null;
		}
		$temporaryFileDao = DAORegistry::getDAO('TemporaryFileDAO'); /* @var $temporaryFileDao TemporaryFileDAO */
		return $temporaryFileDao->getTemporaryFile((int) $temporaryFileId, $user->getId()) ?: null;
	}

	/**
	 * Whether a publication is already public. The core locks the metadata of
	 * a published publication; the plugin follows the same rule.
	 */
	public static function isPublished($publication) {
		return (int) $publication->getData('status') === STATUS_PUBLISHED;
	}

	/**
	 * The standard 409 for writes against a published publication.
	 */
	public static function publishedConflict($response) {
		return $response->withStatus(409)->withJson(array(
			'error' => 'api.publication.403.cantEditPublished',
			'errorMessage' => __('plugins.generic.ompApiWrite.published.locked'),
		));
	}

	//
	// Locales
	//

	/**
	 * Is $locale one of the press's submission locales?
	 */
	public static function isSubmissionLocale($locale, $context) {
		return is_string($locale) && in_array($locale, (array) $context->getSupportedSubmissionLocales(), true);
	}

	/**
	 * Normalise a multilingual input: a plain string is stored under $locale,
	 * an object { locale: value } is kept as is. Null/'' give an empty array.
	 *
	 * @return array
	 */
	public static function toLocalized($value, $locale) {
		if (is_array($value)) {
			return $value;
		}
		if ($value === null || $value === '') {
			return array();
		}
		return array($locale => (string) $value);
	}

	/**
	 * Locale keys of a multilingual value that the press does not support.
	 *
	 * @return array
	 */
	public static function unsupportedLocales($localized, $context) {
		$bad = array();
		foreach (array_keys((array) $localized) as $locale) {
			if (!self::isSubmissionLocale($locale, $context)) {
				$bad[] = $locale;
			}
		}
		return $bad;
	}

	/**
	 * True when no locale carries a non-blank string.
	 */
	public static function isBlankLocalized($localized) {
		foreach ((array) $localized as $value) {
			if (is_string($value) && trim($value) !== '') {
				return false;
			}
		}
		return true;
	}

	//
	// Defaults
	//

	/**
	 * First enabled genre in the "document" category (e.g. "Book Manuscript").
	 */
	public static function defaultGenreId($contextId) {
		$genreDao = DAORegistry::getDAO('GenreDAO'); /* @var $genreDao GenreDAO */
		$genres = $genreDao->getEnabledByContextId($contextId);
		while ($genre = $genres->next()) {
			if ($genre->getCategory() == GENRE_CATEGORY_DOCUMENT) {
				return $genre->getId();
			}
		}
		return null;
	}

	/**
	 * Author-role user group to file a contributor under. OMP ships
	 * "Volume editor" (VE), "Chapter Author" (CA) and "Author" (AU), all with
	 * ROLE_ID_AUTHOR; pick the one matching the contributor, falling back to the
	 * first author-role group.
	 */
	public static function authorUserGroupId($contextId, $isVolumeEditor = false) {
		$wanted = $isVolumeEditor ? 'VE' : 'AU';
		$userGroupDao = DAORegistry::getDAO('UserGroupDAO'); /* @var $userGroupDao UserGroupDAO */
		$groups = $userGroupDao->getByRoleId($contextId, ROLE_ID_AUTHOR);
		$fallback = null;
		while ($group = $groups->next()) {
			if ($fallback === null) {
				$fallback = $group->getId();
			}
			if (in_array($wanted, (array) $group->getData('abbrev'), true)) {
				return $group->getId();
			}
		}
		return $fallback;
	}

	//
	// Files inside a publication format
	//

	/**
	 * Read `salesType` / `price` from the request.
	 *
	 *   salesType "openAccess"   -> free download (price "0")   [default]
	 *   salesType "directSales"  -> sold; `price` > 0 required
	 *   salesType "notAvailable" -> listed but not downloadable
	 *
	 * A bare `price` also works: 0 means open access, > 0 direct sales.
	 *
	 * @return array [salesType, directSalesPrice, error|null]
	 */
	public static function salesFromParams($params) {
		$type = isset($params['salesType']) ? (string) $params['salesType'] : null;
		$price = array_key_exists('price', $params) ? $params['price'] : null;

		if ($type === null) {
			if ($price === null || (is_numeric($price) && (float) $price == 0)) {
				$type = OMPAPI_SALES_OPEN_ACCESS;
			} elseif (is_numeric($price) && (float) $price > 0) {
				$type = OMPAPI_SALES_DIRECT;
			} else {
				return array(null, null, __('plugins.generic.ompApiWrite.sales.invalidPrice'));
			}
		}

		switch ($type) {
			case OMPAPI_SALES_OPEN_ACCESS:
				return array($type, '0', null);
			case OMPAPI_SALES_NOT_AVAILABLE:
				return array($type, null, null);
			case OMPAPI_SALES_DIRECT:
				if (!is_numeric($price) || (float) $price <= 0) {
					return array(null, null, __('plugins.generic.ompApiWrite.sales.invalidPrice'));
				}
				return array($type, (string) $price, null);
		}
		return array(null, null, __('plugins.generic.ompApiWrite.sales.invalidType'));
	}

	/**
	 * Copy a temporary upload into the submission's files as a PROOF file of a
	 * publication format (assoc REPRESENTATION). That is exactly how OMP's
	 * catalog finds the downloadable files of a format.
	 *
	 * @param array $extra extra submission-file data (chapterId, salesType,
	 *   directSalesPrice)
	 * @return SubmissionFile
	 */
	public static function storeFormatFile($temporaryFile, $submission, $format, $locale, $context, $user, $extra) {
		import('lib.pkp.classes.file.FileManager');
		$fileManager = new FileManager();
		// Keep only a plain alphanumeric extension from the client's file name.
		$extension = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $fileManager->parseFileExtension($temporaryFile->getOriginalFileName())));
		$submissionDir = Services::get('submissionFile')->getSubmissionDir($context->getId(), $submission->getId());
		$fileId = Services::get('file')->add(
			$temporaryFile->getFilePath(),
			$submissionDir . '/' . uniqid() . ($extension !== '' ? '.' . $extension : '')
		);

		$submissionFile = DAORegistry::getDAO('SubmissionFileDAO')->newDataObject();
		$submissionFile->setAllData(array_merge(array(
			'fileId' => $fileId,
			'submissionId' => $submission->getId(),
			'uploaderUserId' => $user->getId(),
			'fileStage' => SUBMISSION_FILE_PROOF,
			'genreId' => self::defaultGenreId($context->getId()),
			'assocType' => ASSOC_TYPE_REPRESENTATION,
			'assocId' => $format->getId(),
			'name' => array($locale => $temporaryFile->getOriginalFileName()),
		), $extra));

		return Services::get('submissionFile')->add($submissionFile, Application::get()->getRequest());
	}

	/**
	 * Submission files stored inside a publication format.
	 *
	 * @return array SubmissionFile[]
	 */
	public static function formatFiles($submission, $format) {
		$files = array();
		$iterator = Services::get('submissionFile')->getMany(array(
			'submissionIds' => array($submission->getId()),
			'assocTypes' => array(ASSOC_TYPE_REPRESENTATION),
			'assocIds' => array($format->getId()),
		));
		foreach ($iterator as $submissionFile) {
			$files[] = $submissionFile;
		}
		return $files;
	}

	/**
	 * Does $chapterId name a chapter of this publication?
	 */
	public static function isPublicationChapter($chapterId, $publication) {
		$chapterDao = DAORegistry::getDAO('ChapterDAO'); /* @var $chapterDao ChapterDAO */
		return (bool) $chapterDao->getChapter((int) $chapterId, $publication->getId());
	}

	//
	// JSON payloads
	//

	/**
	 * A chapter, with the ids of its chapter authors.
	 */
	public static function chapterProps($chapter) {
		$chapterAuthorDao = DAORegistry::getDAO('ChapterAuthorDAO'); /* @var $chapterAuthorDao ChapterAuthorDAO */
		return array(
			'id' => (int) $chapter->getId(),
			'publicationId' => (int) $chapter->getData('publicationId'),
			'seq' => (int) $chapter->getSequence(),
			'title' => (array) $chapter->getData('title'),
			'subtitle' => (array) $chapter->getData('subtitle'),
			'abstract' => (array) $chapter->getData('abstract'),
			'pages' => $chapter->getPages(),
			'datePublished' => $chapter->getDatePublished(),
			'doi' => DoiHelper::get($chapter),
			'authorIds' => array_map('intval', (array) $chapterAuthorDao->getAuthorIdsByChapterId($chapter->getId())),
		);
	}

	/**
	 * A publication format, including the files it offers.
	 */
	public static function formatProps($format, $submission, $request) {
		$documents = array();
		foreach (self::formatFiles($submission, $format) as $submissionFile) {
			$documents[] = self::documentProps($submissionFile, $format, $submission, $request);
		}
		return array(
			'id' => (int) $format->getId(),
			'publicationId' => (int) $format->getData('publicationId'),
			'name' => (array) $format->getData('name'),
			'entryKey' => $format->getEntryKey(),
			'physicalFormat' => (bool) $format->getPhysicalFormat(),
			'isApproved' => (bool) $format->getIsApproved(),
			'isAvailable' => (bool) $format->getIsAvailable(),
			'remoteUrl' => $format->getData('remoteUrl'),
			'urlPath' => $format->getData('urlPath'),
			'seq' => (int) $format->getSequence(),
			'doi' => DoiHelper::get($format),
			'documents' => $documents,
		);
	}

	/**
	 * A file inside a publication format, with its public download URL.
	 */
	public static function documentProps($submissionFile, $format, $submission, $request) {
		return array(
			'id' => (int) $submissionFile->getId(),
			'formatId' => (int) $format->getId(),
			'name' => (array) $submissionFile->getData('name'),
			'mimetype' => $submissionFile->getData('mimetype'),
			'chapterId' => $submissionFile->getData('chapterId') ? (int) $submissionFile->getData('chapterId') : null,
			'salesType' => $submissionFile->getData('salesType'),
			'directSalesPrice' => $submissionFile->getData('directSalesPrice'),
			'doi' => DoiHelper::get($submissionFile),
			'urlDownload' => $request->getDispatcher()->url(
				$request, ROUTE_PAGE, null, 'catalog', 'download',
				array($submission->getId(), $format->getBestId(), $submissionFile->getId())
			),
		);
	}
}
