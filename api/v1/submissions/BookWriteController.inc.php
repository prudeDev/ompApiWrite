<?php

/**
 * @file plugins/generic/ompApiWrite/api/v1/submissions/BookWriteController.inc.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class BookWriteController
 * @ingroup plugins_generic_ompApiWrite
 *
 * @brief Manage a book (monograph) as a single unit: its submission plus its
 *        current publication. A book is created UNPUBLISHED, straight into the
 *        production stage; publishing it puts it in the press catalog.
 *
 *        Authorization (manager role + press) is enforced by the core
 *        PKPSubmissionHandler before these methods run.
 */

import('classes.core.Services');
import('classes.submission.Submission'); // WORK_TYPE_* constants
import('lib.pkp.classes.services.interfaces.EntityWriteInterface'); // VALIDATE_ACTION_* constants
import('plugins.generic.ompApiWrite.classes.BookApiHelper');

class BookWriteController {

	/** Multilingual string fields of a publication the API accepts. */
	protected $localizedFields = array('title', 'subtitle', 'prefix', 'abstract', 'coverage', 'rights', 'source', 'type', 'copyrightHolder');

	/** Controlled-vocabulary fields: { locale: [terms] }. */
	protected $vocabFields = array('keywords', 'subjects', 'disciplines', 'supportingAgencies', 'languages');

	/** Plain publication fields the API accepts. */
	protected $scalarFields = array('seriesId', 'seriesPosition', 'datePublished', 'licenseUrl', 'copyrightYear', 'citationsRaw');

	/** Allowed cover image types => extension. */
	protected $coverTypes = array('image/gif' => 'gif', 'image/jpeg' => 'jpg', 'image/pjpeg' => 'jpg', 'image/png' => 'png', 'image/x-png' => 'png', 'image/webp' => 'webp');

	/**
	 * POST /submissions/books  -- create a book, unpublished.
	 *
	 * Body (JSON), all optional except `title`:
	 *   title, subtitle, prefix, abstract ...   string | { locale: string }
	 *   keywords, subjects, ...                  [terms] | { locale: [terms] }
	 *   locale          the book's language (default: press primary locale)
	 *   workType        "authored" (default) | "edited"
	 *   seriesId, seriesPosition, datePublished, licenseUrl, copyrightYear, doi
	 *   authors         [ { givenName, familyName, email, isVolumeEditor, orcid, ... } ]
	 *   coverImageTemporaryFileId, coverImageAltText
	 *   temporaryFileId  a PDF: creates a "PDF" digital format holding it
	 *   formatName       name of that format (default "PDF")
	 *   salesType/price  how that file is offered (default open access)
	 *
	 * Everything is validated before anything is written.
	 */
	public function createBook($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$user = $request->getUser();
		if (!$context) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$params = (array) $slimRequest->getParsedBody();

		$locale = !empty($params['locale']) ? (string) $params['locale'] : $context->getPrimaryLocale();
		if (!BookApiHelper::isSubmissionLocale($locale, $context)) {
			return $response->withStatus(400)->withJson(array('locale' => array(
				__('plugins.generic.ompApiWrite.locale.unsupported', array('locales' => $locale))
			)));
		}

		// --- validate everything up front: nothing can be rolled back later ---
		$errors = array();

		$workType = $this->readWorkType($params, WORK_TYPE_AUTHORED_WORK, $errors);

		$props = $this->readPublicationProps($params, $locale, $context, $errors);
		$props['locale'] = $locale;
		if (BookApiHelper::isBlankLocalized(isset($props['title']) ? $props['title'] : null)) {
			$errors['title'] = array(__('form.dataRequired'));
		}
		$this->validatePublicationProps(VALIDATE_ACTION_ADD, $props, $locale, $context, $errors);

		if (DoiHelper::requested($params)) {
			$doiError = DoiHelper::validate($params['doi'], $context->getId());
			if ($doiError) $errors['doi'] = array($doiError);
		}

		$cover = $this->readCover($params, $locale, $context, $user, $errors);

		$authors = array();
		$authorController = $this->authorController();
		if (isset($params['authors'])) {
			if (!is_array($params['authors'])) {
				$errors['authors'] = array(__('plugins.generic.ompApiWrite.authors.notList'));
			} else {
				foreach (array_values($params['authors']) as $i => $rawAuthor) {
					$authorErrors = array();
					$authors[$i] = $authorController->prepareAuthorParams(
						(array) $rawAuthor, $context, $locale, VALIDATE_ACTION_ADD, $authorErrors
					);
					$authors[$i]['seq'] = isset($authors[$i]['seq']) ? $authors[$i]['seq'] : $i;
					if (!empty($authorErrors)) {
						$errors['authors'][$i] = $authorErrors;
					}
				}
			}
		}

		$temporaryFile = null;
		if (!empty($params['temporaryFileId'])) {
			$temporaryFile = BookApiHelper::temporaryFile($params['temporaryFileId'], $user);
			if (!$temporaryFile) {
				$errors['temporaryFileId'] = array(__('common.uploadFailed'));
			}
		}
		list($salesType, $price, $salesError) = BookApiHelper::salesFromParams($params);
		if ($temporaryFile && $salesError) {
			$errors['salesType'] = array($salesError);
		}

		// ISBN: OMP keeps it as an ONIX identification code of a publication
		// format (15 = ISBN-13, 02 = ISBN-10), so it needs the digital format.
		$isbn = null;
		if (isset($params['isbn']) && trim((string) $params['isbn']) !== '') {
			$isbn = trim((string) $params['isbn']);
			$digits = strtoupper(preg_replace('/[^0-9Xx]/', '', $isbn));
			if (!preg_match('/^(\d{13}|\d{9}[\dX])$/', $digits)) {
				$errors['isbn'] = array('ISBN must have 10 or 13 digits.');
			}
		}

		if (!empty($errors)) {
			return $response->withStatus(400)->withJson($errors);
		}

		// --- 1. Submission, straight into production ---
		$submissionDao = DAORegistry::getDAO('SubmissionDAO'); /* @var $submissionDao SubmissionDAO */
		$submission = $submissionDao->newDataObject();
		$submission->setData('contextId', $context->getId());
		$submission->setData('locale', $locale);
		$submission->setData('workType', $workType);
		$submission->setData('stageId', WORKFLOW_STAGE_ID_PRODUCTION);
		$submission->setData('status', STATUS_QUEUED);
		$submission->setData('submissionProgress', 0);
		$submission = Services::get('submission')->add($submission, $request);

		// --- 2. Publication (unpublished) ---
		$publicationDao = DAORegistry::getDAO('PublicationDAO'); /* @var $publicationDao PublicationDAO */
		$publication = $publicationDao->newDataObject();
		$publication->setAllData($props);
		$publication->setData('submissionId', $submission->getId());
		$publication->setData('status', STATUS_QUEUED);
		$publication->setData('version', 1);
		$publication = Services::get('publication')->add($publication, $request);

		if (DoiHelper::requested($params)) {
			DoiHelper::store($publicationDao, $publication->getId(), $params['doi']);
		}

		$submission = Services::get('submission')->edit(
			$submission, array('currentPublicationId' => $publication->getId()), $request
		);

		// --- 3. Contributors; the first one is the primary contact ---
		$primaryContactId = null;
		foreach ($authors as $authorParams) {
			$authorParams['publicationId'] = $publication->getId();
			$author = $authorController->insertAuthor($authorParams, $request);
			if ($primaryContactId === null) {
				$primaryContactId = $author->getId();
			}
		}

		$edit = array();
		if ($primaryContactId) {
			$edit['primaryContactId'] = $primaryContactId;
		}
		if ($cover) {
			$edit['coverImage'] = $cover;
		}
		if (!empty($edit)) {
			// The cover is moved into place (and OMP builds its thumbnail) by
			// the publication service on edit, not on add.
			$publication = Services::get('publication')->get($publication->getId());
			Services::get('publication')->edit($publication, $edit, $request);
		}

		// --- 4. Optional digital format with the PDF (and the ISBN) ---
		if ($temporaryFile || $isbn) {
			$format = $this->newDigitalFormat(
				$publication, !empty($params['formatName']) ? (string) $params['formatName'] : 'PDF', $locale
			);
			if ($temporaryFile) {
				BookApiHelper::storeFormatFile($temporaryFile, $submission, $format, $locale, $context, $user, array(
					'salesType' => $salesType,
					'directSalesPrice' => $price,
				));
			}
			if ($isbn) {
				$identificationCodeDao = DAORegistry::getDAO('IdentificationCodeDAO'); /* @var $identificationCodeDao IdentificationCodeDAO */
				$code = $identificationCodeDao->newDataObject();
				$code->setPublicationFormatId($format->getId());
				$code->setCode(strlen(preg_replace('/[^0-9Xx]/', '', $isbn)) === 13 ? '15' : '02');
				$code->setValue($isbn);
				$identificationCodeDao->insertObject($code);
			}
		}

		$submission = Services::get('submission')->get($submission->getId());
		return $response->withJson($this->bookProps($submission, $request, $slimRequest), 201);
	}

	/**
	 * GET /submissions/books/{submissionId}  -- the book, its contributors,
	 * chapters and formats in one payload.
	 */
	public function getBook($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$submission = BookApiHelper::contextSubmission($args['submissionId'], $request->getContext());
		if (!$submission) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}
		return $response->withJson($this->bookProps($submission, $request, $slimRequest), 200);
	}

	/**
	 * PUT /submissions/books/{submissionId}  -- edit the current publication's
	 * metadata (and the book's work type). Only fields sent are changed.
	 *
	 * A published book is locked, as in the editorial UI: unpublish, edit,
	 * publish again.
	 */
	public function updateBook($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$user = $request->getUser();

		$submission = BookApiHelper::contextSubmission($args['submissionId'], $context);
		if (!$submission) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}
		$publication = $submission->getCurrentPublication();
		if (BookApiHelper::isPublished($publication)) {
			return BookApiHelper::publishedConflict($response);
		}

		$params = (array) $slimRequest->getParsedBody();
		$locale = $submission->getData('locale') ?: $context->getPrimaryLocale();

		$errors = array();
		$workType = $this->readWorkType($params, null, $errors);
		$props = $this->readPublicationProps($params, $locale, $context, $errors);
		if (array_key_exists('title', $props) && BookApiHelper::isBlankLocalized($props['title'])) {
			$errors['title'] = array(__('form.dataRequired'));
		}
		$this->validatePublicationProps(VALIDATE_ACTION_EDIT, $props, $locale, $context, $errors);

		if (DoiHelper::requested($params)) {
			$doiError = DoiHelper::validate($params['doi'], $context->getId(), DoiHelper::get($publication));
			if ($doiError) $errors['doi'] = array($doiError);
		}

		$cover = $this->readCover($params, $locale, $context, $user, $errors, $publication);

		if (!empty($errors)) {
			return $response->withStatus(400)->withJson($errors);
		}

		if ($cover) {
			$props['coverImage'] = $cover;
		}
		if (!empty($props)) {
			Services::get('publication')->edit($publication, $props, $request);
		}
		if (DoiHelper::requested($params)) {
			DoiHelper::store(DAORegistry::getDAO('PublicationDAO'), $publication->getId(), $params['doi']);
		}
		if ($workType !== null && (int) $workType !== (int) $submission->getData('workType')) {
			Services::get('submission')->edit($submission, array('workType' => $workType), $request);
		}

		$submission = Services::get('submission')->get($submission->getId());
		return $response->withJson($this->bookProps($submission, $request, $slimRequest), 200);
	}

	/**
	 * DELETE /submissions/books/{submissionId}  -- delete the book entirely
	 * (every version, contributor, chapter, format and file).
	 *
	 * A published book must be unpublished first, so it can't vanish from the
	 * catalog by accident.
	 */
	public function removeBook($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$submission = BookApiHelper::contextSubmission($args['submissionId'], $request->getContext());
		if (!$submission) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}
		if ((int) $submission->getData('status') === STATUS_PUBLISHED) {
			return $response->withStatus(409)->withJson(array(
				'error' => 'api.submissions.403.deletePublished',
				'errorMessage' => __('plugins.generic.ompApiWrite.book.deletePublished'),
			));
		}

		$data = $this->bookProps($submission, $request, $slimRequest);
		// Chapters and formats: OMP 3.3 skips this hook on submission delete
		foreach (Services::get('publication')->getMany(array('submissionIds' => $submission->getId())) as $publication) {
			Services::get('publication')->deletePublicationBefore('Publication::delete::before', array($publication));
		}
		Services::get('submission')->delete($submission);

		return $response->withJson($data, 200);
	}

	/**
	 * PUT /submissions/books/{submissionId}/publish  -- put the book in the
	 * catalog.
	 *
	 * Goes through the core's validatePublish(), the same gate as the
	 * editorial UI. With a future `datePublished` OMP schedules it instead.
	 */
	public function publishBook($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$submission = BookApiHelper::contextSubmission($args['submissionId'], $context);
		if (!$submission) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}
		$publication = $submission->getCurrentPublication();
		if (BookApiHelper::isPublished($publication)) {
			return $response->withStatus(409)->withJson(array(
				'error' => 'api.publication.403.alreadyPublished',
				'errorMessage' => __('plugins.generic.ompApiWrite.book.alreadyPublished'),
			));
		}

		$errors = Services::get('publication')->validatePublish(
			$publication, $submission,
			$context->getSupportedSubmissionLocales(),
			$submission->getData('locale') ?: $context->getPrimaryLocale()
		);
		if (!empty($errors)) {
			return $response->withStatus(400)->withJson($errors);
		}

		Services::get('publication')->publish($publication);

		$submission = Services::get('submission')->get($submission->getId());
		return $response->withJson($this->bookProps($submission, $request, $slimRequest), 200);
	}

	/**
	 * PUT /submissions/books/{submissionId}/unpublish  -- take the book out of
	 * the catalog (OMP leaves a tombstone, as in the UI).
	 */
	public function unpublishBook($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$submission = BookApiHelper::contextSubmission($args['submissionId'], $request->getContext());
		if (!$submission) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}
		$publication = $submission->getCurrentPublication();
		$status = (int) $publication->getData('status');
		if ($status !== STATUS_PUBLISHED && $status !== STATUS_SCHEDULED) {
			return $response->withStatus(409)->withJson(array(
				'error' => 'api.publication.403.cantUnpublish',
				'errorMessage' => __('plugins.generic.ompApiWrite.book.notPublished'),
			));
		}

		Services::get('publication')->unpublish($publication);

		$submission = Services::get('submission')->get($submission->getId());
		return $response->withJson($this->bookProps($submission, $request, $slimRequest), 200);
	}

	//
	// Input
	//

	/**
	 * `workType`: "authored" | "edited", or the WORK_TYPE_* integers.
	 *
	 * @return int|null $default when not sent
	 */
	protected function readWorkType($params, $default, &$errors) {
		if (!array_key_exists('workType', $params)) {
			return $default;
		}
		$map = array(
			'authored' => WORK_TYPE_AUTHORED_WORK,
			'edited' => WORK_TYPE_EDITED_VOLUME,
			(string) WORK_TYPE_AUTHORED_WORK => WORK_TYPE_AUTHORED_WORK,
			(string) WORK_TYPE_EDITED_VOLUME => WORK_TYPE_EDITED_VOLUME,
		);
		$key = strtolower(trim((string) $params['workType']));
		if (!isset($map[$key])) {
			$errors['workType'] = array(__('plugins.generic.ompApiWrite.book.invalidWorkType'));
			return $default;
		}
		return $map[$key];
	}

	/**
	 * Pick the publication fields out of the body, normalising multilingual
	 * input to { locale: value } and rejecting locales the press doesn't use.
	 *
	 * @return array props for the publication service
	 */
	protected function readPublicationProps($params, $locale, $context, &$errors) {
		$props = array();

		foreach ($this->localizedFields as $field) {
			if (!array_key_exists($field, $params)) continue;
			$props[$field] = BookApiHelper::toLocalized($params[$field], $locale);
		}

		foreach ($this->vocabFields as $field) {
			if (!array_key_exists($field, $params)) continue;
			$value = $params[$field];
			// A flat list belongs to the book's locale.
			if (is_array($value) && (empty($value) || array_key_exists(0, $value))) {
				$value = array($locale => array_values($value));
			}
			$props[$field] = (array) $value;
		}

		foreach (array_merge($this->localizedFields, $this->vocabFields) as $field) {
			if (!isset($props[$field])) continue;
			$bad = BookApiHelper::unsupportedLocales($props[$field], $context);
			if (!empty($bad)) {
				$errors[$field] = array(__('plugins.generic.ompApiWrite.locale.unsupported', array('locales' => implode(', ', $bad))));
			}
		}

		foreach ($this->scalarFields as $field) {
			if (array_key_exists($field, $params)) {
				$props[$field] = $params[$field] === '' ? null : $params[$field];
			}
		}

		if (isset($props['seriesId'])) {
			$props['seriesId'] = (int) $props['seriesId'];
			if (!Application::get()->getSectionDAO()->getById($props['seriesId'], $context->getId())) {
				$errors['seriesId'] = array(__('publication.invalidSeries'));
			}
		}
		if (isset($props['copyrightYear'])) {
			$props['copyrightYear'] = (int) $props['copyrightYear'];
		}
		if (isset($props['datePublished'])) {
			$date = DateTime::createFromFormat('Y-m-d', (string) $props['datePublished']);
			if (!$date || $date->format('Y-m-d') !== $props['datePublished']) {
				$errors['datePublished'] = array(__('plugins.generic.ompApiWrite.date.invalid'));
			}
		}

		return $props;
	}

	/**
	 * Run the core publication validator over the props we are about to write.
	 * Fields the controller sets itself (submission, status, version) are not
	 * the caller's concern and are left out of the report.
	 */
	protected function validatePublicationProps($action, $props, $locale, $context, &$errors) {
		$coreErrors = (array) Services::get('publication')->validate(
			$action, $props, $context->getSupportedSubmissionLocales(), $locale
		);
		foreach (array('submissionId', 'status', 'version') as $ignored) {
			unset($coreErrors[$ignored]);
		}
		foreach ($coreErrors as $field => $messages) {
			if (!isset($errors[$field])) {
				$errors[$field] = $messages;
			}
		}
	}

	/**
	 * Read the cover image fields into the `coverImage` value the publication
	 * service expects: { locale: { temporaryFileId, altText } }.
	 *
	 *   coverImageTemporaryFileId  an image uploaded to /temporaryFiles
	 *   coverImageAltText          alt text
	 *   coverImageLocale           locale the cover is for (default: book locale)
	 *
	 * The existing covers of other locales are carried over untouched.
	 *
	 * @return array|null null when no cover field was sent
	 */
	protected function readCover($params, $locale, $context, $user, &$errors, $publication = null) {
		$hasFile = !empty($params['coverImageTemporaryFileId']);
		$hasAlt = array_key_exists('coverImageAltText', $params);
		if (!$hasFile && !$hasAlt) {
			return null;
		}

		$coverLocale = !empty($params['coverImageLocale']) ? (string) $params['coverImageLocale'] : $locale;
		// The locale ends up in the stored file name: only known locales pass.
		if (!BookApiHelper::isSubmissionLocale($coverLocale, $context)) {
			$errors['coverImageLocale'] = array(__('plugins.generic.ompApiWrite.locale.unsupported', array('locales' => $coverLocale)));
			return null;
		}

		$cover = $publication ? (array) $publication->getData('coverImage') : array();
		$entry = isset($cover[$coverLocale]) ? (array) $cover[$coverLocale] : array();

		if ($hasFile) {
			$temporaryFile = BookApiHelper::temporaryFile($params['coverImageTemporaryFileId'], $user);
			if (!$temporaryFile) {
				$errors['coverImageTemporaryFileId'] = array(__('common.uploadFailed'));
				return null;
			}
			if (!isset($this->coverTypes[$temporaryFile->getFileType()])) {
				$errors['coverImageTemporaryFileId'] = array(__('plugins.generic.ompApiWrite.cover.invalidType'));
				return null;
			}
			$entry['temporaryFileId'] = $temporaryFile->getId();
		} elseif (empty($entry['uploadName'])) {
			// Alt text with no cover to describe.
			$errors['coverImageAltText'] = array(__('plugins.generic.ompApiWrite.cover.missing'));
			return null;
		}
		if ($hasAlt) {
			$entry['altText'] = (string) $params['coverImageAltText'];
		}

		$cover[$coverLocale] = $entry;
		return $cover;
	}

	//
	// Helpers
	//

	/**
	 * A digital publication format, approved and available, so its files go
	 * public as soon as the book is published.
	 */
	protected function newDigitalFormat($publication, $name, $locale) {
		$formatDao = DAORegistry::getDAO('PublicationFormatDAO'); /* @var $formatDao PublicationFormatDAO */
		$format = $formatDao->newDataObject();
		$format->setData('publicationId', $publication->getId());
		$format->setData('name', array($locale => $name));
		$format->setEntryKey('DA'); // ONIX: digital
		$format->setPhysicalFormat(false);
		$format->setIsApproved(true);
		$format->setIsAvailable(true);
		$format->setSequence(0);
		$formatId = $formatDao->insertObject($format);
		return $formatDao->getById($formatId, $publication->getId());
	}

	protected function authorController() {
		import('plugins.generic.ompApiWrite.api.v1.submissions.AuthorWriteController');
		return new AuthorWriteController();
	}

	/**
	 * The book as one payload: submission, current publication, contributors,
	 * chapters and formats.
	 */
	protected function bookProps($submission, $request, $slimRequest) {
		$publication = $submission->getCurrentPublication();
		$publicationId = $publication->getId();

		$authors = array();
		foreach (Services::get('author')->getMany(array('publicationIds' => array($publicationId))) as $author) {
			$authors[] = Services::get('author')->getFullProperties($author, array(
				'request' => $request,
				'slimRequest' => $slimRequest,
			));
		}

		$chapters = array();
		$result = DAORegistry::getDAO('ChapterDAO')->getByPublicationId($publicationId);
		while ($chapter = $result->next()) {
			$chapters[] = BookApiHelper::chapterProps($chapter);
		}

		$formats = array();
		foreach (DAORegistry::getDAO('PublicationFormatDAO')->getByPublicationId($publicationId)->toArray() as $format) {
			$formats[] = BookApiHelper::formatProps($format, $submission, $request);
		}

		$statusNames = array(
			STATUS_QUEUED => 'unpublished',
			STATUS_PUBLISHED => 'published',
			STATUS_DECLINED => 'declined',
			STATUS_SCHEDULED => 'scheduled',
		);
		$status = (int) $publication->getData('status');

		$data = array(
			'submissionId' => (int) $submission->getId(),
			'publicationId' => (int) $publicationId,
			'status' => isset($statusNames[$status]) ? $statusNames[$status] : $status,
			'workType' => (int) $submission->getData('workType') === WORK_TYPE_EDITED_VOLUME ? 'edited' : 'authored',
			'locale' => $submission->getData('locale'),
			'seriesId' => $publication->getData('seriesId') ? (int) $publication->getData('seriesId') : null,
			'seriesPosition' => $publication->getData('seriesPosition'),
			'primaryContactId' => $publication->getData('primaryContactId') ? (int) $publication->getData('primaryContactId') : null,
			'doi' => DoiHelper::get($publication),
			'coverImage' => $publication->getData('coverImage'),
			'urlPublished' => $request->getDispatcher()->url(
				$request, ROUTE_PAGE, null, 'catalog', 'book', array($submission->getBestId())
			),
		);
		foreach (array_merge($this->localizedFields, $this->vocabFields, $this->scalarFields) as $field) {
			if (!array_key_exists($field, $data)) {
				$data[$field] = $publication->getData($field);
			}
		}
		$data['authors'] = $authors;
		$data['chapters'] = $chapters;
		$data['publicationFormats'] = $formats;

		return $data;
	}
}
