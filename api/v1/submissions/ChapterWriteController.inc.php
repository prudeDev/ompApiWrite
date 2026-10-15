<?php

/**
 * @file plugins/generic/ompApiWrite/api/v1/submissions/ChapterWriteController.inc.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ChapterWriteController
 * @ingroup plugins_generic_ompApiWrite
 *
 * @brief CRUD for the chapters of a book's publication, including which of
 *        the publication's contributors are the chapter's authors.
 *
 *        Authorization: manager role + press is enforced by the core handler;
 *        each method additionally checks that the publication belongs to a
 *        book of the current press.
 */

import('classes.core.Services');
import('plugins.generic.ompApiWrite.classes.BookApiHelper');

class ChapterWriteController {

	/**
	 * GET /submissions/{submissionId}/publications/{publicationId}/chapters
	 */
	public function listChapters($slimRequest, $response, $args) {
		$found = BookApiHelper::contextPublication($args, Application::get()->getRequest()->getContext());
		if (!$found) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}
		list(, $publication) = $found;

		$items = array();
		$result = DAORegistry::getDAO('ChapterDAO')->getByPublicationId($publication->getId());
		while ($chapter = $result->next()) {
			$items[] = BookApiHelper::chapterProps($chapter);
		}

		return $response->withJson(array('items' => $items, 'itemsMax' => count($items)), 200);
	}

	/**
	 * POST /submissions/{submissionId}/publications/{publicationId}/chapters
	 *
	 * Body (JSON):
	 *   title          string | { locale: string }   (required)
	 *   subtitle, abstract   string | { locale: string }
	 *   pages          string, e.g. "15-42"
	 *   datePublished  YYYY-MM-DD
	 *   authorIds      [int]  contributors of this publication, in order
	 *   seq            int    position (default: last)
	 *   doi            string
	 */
	public function addChapter($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$found = BookApiHelper::contextPublication($args, $context);
		if (!$found) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}
		list($submission, $publication) = $found;
		if (BookApiHelper::isPublished($publication)) {
			return BookApiHelper::publishedConflict($response);
		}

		$params = (array) $slimRequest->getParsedBody();
		$locale = $submission->getData('locale') ?: $context->getPrimaryLocale();

		$errors = $this->validate($params, $locale, $context, $publication, null);
		if (!array_key_exists('title', $params) || BookApiHelper::isBlankLocalized(BookApiHelper::toLocalized($params['title'], $locale))) {
			$errors['title'] = array(__('form.dataRequired'));
		}
		if (!empty($errors)) {
			return $response->withStatus(400)->withJson($errors);
		}

		$chapterDao = DAORegistry::getDAO('ChapterDAO'); /* @var $chapterDao ChapterDAO */
		$chapter = $chapterDao->newDataObject();
		$chapter->setData('publicationId', $publication->getId());
		$chapter->setSequence(isset($params['seq']) ? (int) $params['seq'] : $this->nextSequence($publication));
		$this->applyParams($chapter, $params, $locale);
		$chapterId = $chapterDao->insertChapter($chapter);

		if (isset($params['authorIds'])) {
			$this->setChapterAuthors($chapterId, $params['authorIds']);
		}
		if (DoiHelper::requested($params)) {
			DoiHelper::store($chapterDao, $chapterId, $params['doi']);
		}

		$chapter = $chapterDao->getChapter($chapterId, $publication->getId());
		return $response->withJson(BookApiHelper::chapterProps($chapter), 201);
	}

	/**
	 * PUT /submissions/{submissionId}/publications/{publicationId}/chapters/{chapterId}
	 *
	 * Only fields sent are changed. `authorIds` replaces the whole list.
	 */
	public function editChapter($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$found = BookApiHelper::contextPublication($args, $context);
		if (!$found) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}
		list($submission, $publication) = $found;

		$chapterDao = DAORegistry::getDAO('ChapterDAO'); /* @var $chapterDao ChapterDAO */
		$chapter = $chapterDao->getChapter((int) $args['chapterId'], $publication->getId());
		if (!$chapter) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}
		if (BookApiHelper::isPublished($publication)) {
			return BookApiHelper::publishedConflict($response);
		}

		$params = (array) $slimRequest->getParsedBody();
		$locale = $submission->getData('locale') ?: $context->getPrimaryLocale();

		$errors = $this->validate($params, $locale, $context, $publication, $chapter);
		if (array_key_exists('title', $params) && BookApiHelper::isBlankLocalized(BookApiHelper::toLocalized($params['title'], $locale))) {
			$errors['title'] = array(__('form.dataRequired'));
		}
		if (!empty($errors)) {
			return $response->withStatus(400)->withJson($errors);
		}

		if (isset($params['seq'])) {
			$chapter->setSequence((int) $params['seq']);
		}
		$this->applyParams($chapter, $params, $locale);
		$chapterDao->updateObject($chapter);

		if (array_key_exists('authorIds', $params)) {
			$this->setChapterAuthors($chapter->getId(), (array) $params['authorIds']);
		}
		if (DoiHelper::requested($params)) {
			DoiHelper::store($chapterDao, $chapter->getId(), $params['doi']);
		}

		$chapter = $chapterDao->getChapter($chapter->getId(), $publication->getId());
		return $response->withJson(BookApiHelper::chapterProps($chapter), 200);
	}

	/**
	 * DELETE /submissions/{submissionId}/publications/{publicationId}/chapters/{chapterId}
	 *
	 * Removes the chapter and its author links. Files that were assigned to
	 * the chapter are kept, just no longer tied to it.
	 */
	public function deleteChapter($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$found = BookApiHelper::contextPublication($args, $request->getContext());
		if (!$found) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}
		list($submission, $publication) = $found;

		$chapterDao = DAORegistry::getDAO('ChapterDAO'); /* @var $chapterDao ChapterDAO */
		$chapter = $chapterDao->getChapter((int) $args['chapterId'], $publication->getId());
		if (!$chapter) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}
		if (BookApiHelper::isPublished($publication)) {
			return BookApiHelper::publishedConflict($response);
		}

		$data = BookApiHelper::chapterProps($chapter);

		// Untie files assigned to this chapter.
		$files = Services::get('submissionFile')->getMany(array('submissionIds' => array($submission->getId())));
		foreach ($files as $submissionFile) {
			if ((int) $submissionFile->getData('chapterId') === (int) $chapter->getId()) {
				Services::get('submissionFile')->edit($submissionFile, array('chapterId' => null), $request);
			}
		}

		DAORegistry::getDAO('ChapterAuthorDAO')->deleteChapterAuthorsByChapterId($chapter->getId());
		$chapterDao->deleteObject($chapter);
		$chapterDao->resequenceChapters($publication->getId());

		return $response->withJson($data, 200);
	}

	//
	// Helpers
	//

	/**
	 * @return array field => messages
	 */
	protected function validate($params, $locale, $context, $publication, $chapter) {
		$errors = array();

		foreach (array('title', 'subtitle', 'abstract') as $field) {
			if (!array_key_exists($field, $params)) continue;
			$bad = BookApiHelper::unsupportedLocales(BookApiHelper::toLocalized($params[$field], $locale), $context);
			if (!empty($bad)) {
				$errors[$field] = array(__('plugins.generic.ompApiWrite.locale.unsupported', array('locales' => implode(', ', $bad))));
			}
		}

		if (!empty($params['datePublished'])) {
			$date = DateTime::createFromFormat('Y-m-d', (string) $params['datePublished']);
			if (!$date || $date->format('Y-m-d') !== $params['datePublished']) {
				$errors['datePublished'] = array(__('plugins.generic.ompApiWrite.date.invalid'));
			}
		}

		if (isset($params['authorIds'])) {
			if (!is_array($params['authorIds'])) {
				$errors['authorIds'] = array(__('plugins.generic.ompApiWrite.chapter.invalidAuthors'));
			} else {
				$valid = array();
				foreach (Services::get('author')->getMany(array('publicationIds' => array($publication->getId()))) as $author) {
					$valid[] = (int) $author->getId();
				}
				foreach ($params['authorIds'] as $authorId) {
					if (!in_array((int) $authorId, $valid, true)) {
						$errors['authorIds'] = array(__('plugins.generic.ompApiWrite.chapter.invalidAuthors'));
						break;
					}
				}
			}
		}

		if (DoiHelper::requested($params)) {
			$doiError = DoiHelper::validate($params['doi'], $context->getId(), $chapter ? DoiHelper::get($chapter) : null);
			if ($doiError) {
				$errors['doi'] = array($doiError);
			}
		}

		return $errors;
	}

	/**
	 * Copy the sent fields onto the chapter (partial update friendly).
	 */
	protected function applyParams($chapter, $params, $locale) {
		foreach (array('title' => 'setTitle', 'subtitle' => 'setSubtitle', 'abstract' => 'setAbstract') as $field => $setter) {
			if (!array_key_exists($field, $params)) continue;
			foreach (BookApiHelper::toLocalized($params[$field], $locale) as $valueLocale => $value) {
				$chapter->$setter($value, $valueLocale);
			}
		}
		if (array_key_exists('pages', $params)) {
			$chapter->setPages($params['pages'] === '' ? null : (string) $params['pages']);
		}
		if (array_key_exists('datePublished', $params)) {
			$chapter->setDatePublished($params['datePublished'] ?: null);
		}
	}

	/**
	 * Replace the chapter's author list. The first one is the chapter's
	 * primary contact.
	 */
	protected function setChapterAuthors($chapterId, $authorIds) {
		$chapterAuthorDao = DAORegistry::getDAO('ChapterAuthorDAO'); /* @var $chapterAuthorDao ChapterAuthorDAO */
		$chapterAuthorDao->deleteChapterAuthorsByChapterId($chapterId);
		$seq = 0;
		foreach (array_unique(array_map('intval', $authorIds)) as $authorId) {
			$chapterAuthorDao->insertChapterAuthor($authorId, $chapterId, $seq === 0, $seq);
			$seq++;
		}
	}

	/**
	 * One past the highest sequence in use.
	 */
	protected function nextSequence($publication) {
		$max = -1;
		$result = DAORegistry::getDAO('ChapterDAO')->getByPublicationId($publication->getId());
		while ($chapter = $result->next()) {
			$max = max($max, (int) $chapter->getSequence());
		}
		return $max + 1;
	}
}
