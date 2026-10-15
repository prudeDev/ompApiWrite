<?php

/**
 * @file plugins/generic/ompApiWrite/api/v1/submissions/PublicationFormatController.inc.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PublicationFormatController
 * @ingroup plugins_generic_ompApiWrite
 *
 * @brief CRUD for a book's publication formats (PDF, EPUB, paperback...) and
 *        for the files ("documents") each format offers.
 *
 *        A file is public in the catalog when: the book is published, its
 *        format is approved and available, and the file's sales type is
 *        open access (or direct sales, once paid).
 *
 *        Formats can be managed on a published book too: adding a format or
 *        swapping a file does not change the book's metadata.
 *
 *        The file sub-resource is ".../documents", not ".../files": the core
 *        routes any submissions path containing "/files" elsewhere.
 */

import('classes.core.Services');
import('plugins.generic.ompApiWrite.classes.BookApiHelper');

class PublicationFormatController {

	/**
	 * GET /submissions/{submissionId}/publications/{publicationId}/formats
	 */
	public function listFormats($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$found = BookApiHelper::contextPublication($args, $request->getContext());
		if (!$found) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}
		list($submission, $publication) = $found;

		$items = array();
		foreach (DAORegistry::getDAO('PublicationFormatDAO')->getByPublicationId($publication->getId())->toArray() as $format) {
			$items[] = BookApiHelper::formatProps($format, $submission, $request);
		}

		return $response->withJson(array('items' => $items, 'itemsMax' => count($items)), 200);
	}

	/**
	 * POST /submissions/{submissionId}/publications/{publicationId}/formats
	 *
	 * Body (JSON):
	 *   name            string | { locale: string }  (required), e.g. "PDF"
	 *   physicalFormat  bool    (default false: digital)
	 *   entryKey        ONIX product form (default "DA" digital, "BC" paperback if physical)
	 *   remoteUrl       sell/host the format elsewhere (no files then)
	 *   urlPath, seq, doi
	 *   isApproved      bool    (default true)
	 *   isAvailable     bool    (default true)
	 *   temporaryFileId optional: a first file, with chapterId / salesType / price
	 */
	public function addFormat($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$user = $request->getUser();
		$found = BookApiHelper::contextPublication($args, $context);
		if (!$found) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}
		list($submission, $publication) = $found;

		$params = (array) $slimRequest->getParsedBody();
		$locale = $submission->getData('locale') ?: $context->getPrimaryLocale();

		$errors = $this->validate($params, $locale, $context, $publication, null);
		if (!array_key_exists('name', $params) || BookApiHelper::isBlankLocalized(BookApiHelper::toLocalized($params['name'], $locale))) {
			$errors['name'] = array(__('form.dataRequired'));
		}

		$temporaryFile = null;
		$sales = null;
		if (!empty($params['temporaryFileId'])) {
			list($temporaryFile, $sales) = $this->readDocument($params, $user, $publication, $errors);
		}

		if (!empty($errors)) {
			return $response->withStatus(400)->withJson($errors);
		}

		$physical = !empty($params['physicalFormat']);
		$formatDao = DAORegistry::getDAO('PublicationFormatDAO'); /* @var $formatDao PublicationFormatDAO */
		$format = $formatDao->newDataObject();
		$format->setData('publicationId', $publication->getId());
		$format->setPhysicalFormat($physical);
		$format->setEntryKey($physical ? 'BC' : 'DA');
		$format->setIsApproved(true);
		$format->setIsAvailable(true);
		$format->setSequence($this->nextSequence($publication));
		$this->applyParams($format, $params, $locale);
		$formatId = $formatDao->insertObject($format);

		if (DoiHelper::requested($params)) {
			DoiHelper::store($formatDao, $formatId, $params['doi']);
		}

		$format = $formatDao->getById($formatId, $publication->getId());
		if ($temporaryFile) {
			BookApiHelper::storeFormatFile($temporaryFile, $submission, $format, $locale, $context, $user, $sales);
		}

		return $response->withJson(BookApiHelper::formatProps($format, $submission, $request), 201);
	}

	/**
	 * PUT /submissions/{submissionId}/publications/{publicationId}/formats/{formatId}
	 *
	 * Only fields sent are changed.
	 */
	public function editFormat($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		list($submission, $publication, $format) = $this->resolveFormat($args, $context);
		if (!$format) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$params = (array) $slimRequest->getParsedBody();
		$locale = $submission->getData('locale') ?: $context->getPrimaryLocale();

		$errors = $this->validate($params, $locale, $context, $publication, $format);
		if (array_key_exists('name', $params) && BookApiHelper::isBlankLocalized(BookApiHelper::toLocalized($params['name'], $locale))) {
			$errors['name'] = array(__('form.dataRequired'));
		}
		if (!empty($errors)) {
			return $response->withStatus(400)->withJson($errors);
		}

		if (array_key_exists('physicalFormat', $params)) {
			$format->setPhysicalFormat((bool) $params['physicalFormat']);
		}
		$this->applyParams($format, $params, $locale);

		$formatDao = DAORegistry::getDAO('PublicationFormatDAO'); /* @var $formatDao PublicationFormatDAO */
		$formatDao->updateObject($format);
		if (DoiHelper::requested($params)) {
			DoiHelper::store($formatDao, $format->getId(), $params['doi']);
		}

		$format = $formatDao->getById($format->getId(), $publication->getId());
		return $response->withJson(BookApiHelper::formatProps($format, $submission, $request), 200);
	}

	/**
	 * DELETE /submissions/{submissionId}/publications/{publicationId}/formats/{formatId}
	 *
	 * Uses OMP's own routine: removes the format, its ONIX metadata (codes,
	 * markets, dates, sales rights) and its files, and logs the event.
	 */
	public function deleteFormat($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		list($submission, , $format) = $this->resolveFormat($args, $context);
		if (!$format) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$data = BookApiHelper::formatProps($format, $submission, $request);
		Services::get('publicationFormat')->deleteFormat($format, $submission, $context);

		return $response->withJson($data, 200);
	}

	/**
	 * POST .../formats/{formatId}/documents  -- add a file to the format.
	 *
	 * Body: temporaryFileId (required, uploaded first to /temporaryFiles),
	 *       chapterId (the file is that chapter only), salesType / price, doi.
	 */
	public function addFormatDocument($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$user = $request->getUser();
		list($submission, $publication, $format) = $this->resolveFormat($args, $context);
		if (!$format) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$params = (array) $slimRequest->getParsedBody();
		$errors = array();
		if (empty($params['temporaryFileId'])) {
			$errors['temporaryFileId'] = array(__('form.fileRequired'));
		} else {
			list($temporaryFile, $sales) = $this->readDocument($params, $user, $publication, $errors);
		}
		if (DoiHelper::requested($params)) {
			$doiError = DoiHelper::validate($params['doi'], $context->getId());
			if ($doiError) $errors['doi'] = array($doiError);
		}
		if (!empty($errors)) {
			return $response->withStatus(400)->withJson($errors);
		}

		$locale = $submission->getData('locale') ?: $context->getPrimaryLocale();
		$submissionFile = BookApiHelper::storeFormatFile($temporaryFile, $submission, $format, $locale, $context, $user, $sales);

		if (DoiHelper::requested($params)) {
			DoiHelper::store(DAORegistry::getDAO('SubmissionFileDAO'), $submissionFile->getId(), $params['doi']);
			$submissionFile = Services::get('submissionFile')->get($submissionFile->getId());
		}

		return $response->withJson(BookApiHelper::documentProps($submissionFile, $format, $submission, $request), 201);
	}

	/**
	 * PUT .../formats/{formatId}/documents/{submissionFileId}
	 *
	 * Change how a file is offered (salesType / price), its chapter or DOI.
	 * To replace the file itself, delete it and add the new one.
	 */
	public function editFormatDocument($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		list($submission, $publication, $format) = $this->resolveFormat($args, $context);
		$submissionFile = $format ? $this->resolveDocument($args, $submission, $format) : null;
		if (!$submissionFile) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$params = (array) $slimRequest->getParsedBody();
		$errors = array();
		$edit = array();

		if (array_key_exists('salesType', $params) || array_key_exists('price', $params)) {
			list($salesType, $price, $salesError) = BookApiHelper::salesFromParams($params);
			if ($salesError) {
				$errors['salesType'] = array($salesError);
			} else {
				$edit['salesType'] = $salesType;
				$edit['directSalesPrice'] = $price;
			}
		}
		if (array_key_exists('chapterId', $params)) {
			if (empty($params['chapterId'])) {
				$edit['chapterId'] = null;
			} elseif (BookApiHelper::isPublicationChapter($params['chapterId'], $publication)) {
				$edit['chapterId'] = (int) $params['chapterId'];
			} else {
				$errors['chapterId'] = array(__('plugins.generic.ompApiWrite.chapter.notFound'));
			}
		}
		if (DoiHelper::requested($params)) {
			$doiError = DoiHelper::validate($params['doi'], $context->getId(), DoiHelper::get($submissionFile));
			if ($doiError) $errors['doi'] = array($doiError);
		}
		if (!empty($errors)) {
			return $response->withStatus(400)->withJson($errors);
		}

		if (!empty($edit)) {
			$submissionFile = Services::get('submissionFile')->edit($submissionFile, $edit, $request);
		}
		if (DoiHelper::requested($params)) {
			DoiHelper::store(DAORegistry::getDAO('SubmissionFileDAO'), $submissionFile->getId(), $params['doi']);
			$submissionFile = Services::get('submissionFile')->get($submissionFile->getId());
		}

		return $response->withJson(BookApiHelper::documentProps($submissionFile, $format, $submission, $request), 200);
	}

	/**
	 * DELETE .../formats/{formatId}/documents/{submissionFileId}
	 */
	public function deleteFormatDocument($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		list($submission, , $format) = $this->resolveFormat($args, $request->getContext());
		$submissionFile = $format ? $this->resolveDocument($args, $submission, $format) : null;
		if (!$submissionFile) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$data = BookApiHelper::documentProps($submissionFile, $format, $submission, $request);
		Services::get('submissionFile')->delete($submissionFile);

		return $response->withJson($data, 200);
	}

	//
	// Helpers
	//

	/**
	 * @return array [Submission|null, Publication|null, PublicationFormat|null]
	 */
	protected function resolveFormat($args, $context) {
		$found = BookApiHelper::contextPublication($args, $context);
		if (!$found) {
			return array(null, null, null);
		}
		list($submission, $publication) = $found;
		$format = DAORegistry::getDAO('PublicationFormatDAO')->getById((int) $args['formatId'], $publication->getId());
		return array($submission, $publication, $format ?: null);
	}

	/**
	 * A file of this format, by id.
	 */
	protected function resolveDocument($args, $submission, $format) {
		foreach (BookApiHelper::formatFiles($submission, $format) as $submissionFile) {
			if ((int) $submissionFile->getId() === (int) $args['submissionFileId']) {
				return $submissionFile;
			}
		}
		return null;
	}

	/**
	 * Validate the upload, chapter and sales fields of a new file.
	 *
	 * @return array [TemporaryFile|null, array submission-file extras]
	 */
	protected function readDocument($params, $user, $publication, &$errors) {
		$temporaryFile = BookApiHelper::temporaryFile($params['temporaryFileId'], $user);
		if (!$temporaryFile) {
			$errors['temporaryFileId'] = array(__('common.uploadFailed'));
		}

		$extra = array();
		list($salesType, $price, $salesError) = BookApiHelper::salesFromParams($params);
		if ($salesError) {
			$errors['salesType'] = array($salesError);
		} else {
			$extra['salesType'] = $salesType;
			$extra['directSalesPrice'] = $price;
		}

		if (!empty($params['chapterId'])) {
			if (BookApiHelper::isPublicationChapter($params['chapterId'], $publication)) {
				$extra['chapterId'] = (int) $params['chapterId'];
			} else {
				$errors['chapterId'] = array(__('plugins.generic.ompApiWrite.chapter.notFound'));
			}
		}

		return array($temporaryFile, $extra);
	}

	/**
	 * @return array field => messages
	 */
	protected function validate($params, $locale, $context, $publication, $format) {
		$errors = array();

		if (array_key_exists('name', $params)) {
			$bad = BookApiHelper::unsupportedLocales(BookApiHelper::toLocalized($params['name'], $locale), $context);
			if (!empty($bad)) {
				$errors['name'] = array(__('plugins.generic.ompApiWrite.locale.unsupported', array('locales' => implode(', ', $bad))));
			}
		}
		if (!empty($params['entryKey']) && !preg_match('/^[A-Z0-9]{2}$/', (string) $params['entryKey'])) {
			$errors['entryKey'] = array(__('plugins.generic.ompApiWrite.format.invalidEntryKey'));
		}
		if (!empty($params['remoteUrl']) && !filter_var($params['remoteUrl'], FILTER_VALIDATE_URL)) {
			$errors['remoteUrl'] = array(__('plugins.generic.ompApiWrite.format.invalidUrl'));
		}
		if (!empty($params['urlPath'])) {
			if (!preg_match('/^[a-zA-Z0-9]+([\.\-_][a-zA-Z0-9]+)*$/', (string) $params['urlPath']) || ctype_digit((string) $params['urlPath'])) {
				$errors['urlPath'] = array(__('plugins.generic.ompApiWrite.format.invalidUrlPath'));
			} else {
				foreach (DAORegistry::getDAO('PublicationFormatDAO')->getByPublicationId($publication->getId())->toArray() as $other) {
					if ($other->getData('urlPath') === $params['urlPath'] && (!$format || $other->getId() != $format->getId())) {
						$errors['urlPath'] = array(__('plugins.generic.ompApiWrite.format.duplicateUrlPath'));
						break;
					}
				}
			}
		}
		if (DoiHelper::requested($params)) {
			$doiError = DoiHelper::validate($params['doi'], $context->getId(), $format ? DoiHelper::get($format) : null);
			if ($doiError) {
				$errors['doi'] = array($doiError);
			}
		}

		return $errors;
	}

	/**
	 * Copy the sent fields onto the format (partial update friendly).
	 */
	protected function applyParams($format, $params, $locale) {
		if (array_key_exists('name', $params)) {
			$name = (array) $format->getData('name');
			foreach (BookApiHelper::toLocalized($params['name'], $locale) as $nameLocale => $value) {
				$name[$nameLocale] = $value;
			}
			$format->setData('name', $name);
		}
		if (!empty($params['entryKey'])) $format->setEntryKey((string) $params['entryKey']);
		if (array_key_exists('remoteUrl', $params)) $format->setData('remoteUrl', $params['remoteUrl'] ?: null);
		if (array_key_exists('urlPath', $params)) $format->setData('urlPath', $params['urlPath'] ?: null);
		if (isset($params['seq'])) $format->setSequence((int) $params['seq']);
		if (array_key_exists('isApproved', $params)) $format->setIsApproved((bool) $params['isApproved']);
		if (array_key_exists('isAvailable', $params)) $format->setIsAvailable((bool) $params['isAvailable']);
	}

	/**
	 * One past the highest sequence in use.
	 */
	protected function nextSequence($publication) {
		$max = -1;
		foreach (DAORegistry::getDAO('PublicationFormatDAO')->getByPublicationId($publication->getId())->toArray() as $format) {
			$max = max($max, (int) $format->getSequence());
		}
		return $max + 1;
	}
}
