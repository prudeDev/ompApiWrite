<?php

/**
 * @file plugins/generic/ompApiWrite/api/v1/submissions/SeriesWriteController.inc.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SeriesWriteController
 * @ingroup plugins_generic_ompApiWrite
 *
 * @brief Manage the series of the current press: the multi-volume works a
 *        book joins through its `seriesId` (one book per volume, ordered by
 *        `seriesPosition`).
 *
 *        Authorization (manager role + press) is enforced by the core
 *        PKPSubmissionHandler before these methods run.
 */

import('classes.core.Services');
import('plugins.generic.ompApiWrite.classes.BookApiHelper');

class SeriesWriteController {

	/** Multilingual string fields of a series the API accepts. */
	protected $localizedFields = array('title', 'prefix', 'subtitle', 'description');

	/**
	 * GET /submissions/books/series  -- the press's series, to pick `seriesId`.
	 */
	public function listSeries($slimRequest, $response, $args) {
		$context = Application::get()->getRequest()->getContext();
		if (!$context) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$items = array();
		$result = Application::get()->getSectionDAO()->getByContextId($context->getId());
		while ($series = $result->next()) {
			$items[] = $this->seriesProps($series, $context);
		}

		return $response->withJson(array('items' => $items, 'itemsMax' => count($items)), 200);
	}

	/**
	 * GET /submissions/books/series/{seriesId}
	 */
	public function getSeries($slimRequest, $response, $args) {
		$context = Application::get()->getRequest()->getContext();
		$series = $this->contextSeries($args['seriesId'], $context);
		if (!$series) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}
		return $response->withJson($this->seriesProps($series, $context), 200);
	}

	/**
	 * POST /submissions/books/series  -- create a series.
	 *
	 * Body (JSON), all optional except `title`:
	 *   title, prefix, subtitle, description   string | { locale: string }
	 *   locale      language of plain-string fields (default: press primary locale)
	 *   path        URL path; derived from the title when not sent
	 *   seq         position among the press's series (default: last)
	 *   isInactive  hide it from new submissions
	 *
	 * Books are listed by `seriesPosition` ascending, as volumes.
	 */
	public function createSeries($slimRequest, $response, $args) {
		$context = Application::get()->getRequest()->getContext();
		if (!$context) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$params = (array) $slimRequest->getParsedBody();
		$errors = array();
		$locale = $this->readLocale($params, $context, $errors);
		$props = $this->readSeriesProps($params, $locale, $context, $errors);
		if (BookApiHelper::isBlankLocalized(isset($props['title']) ? $props['title'] : null)) {
			$errors['title'] = array(__('form.dataRequired'));
		}

		$seriesDao = Application::get()->getSectionDAO(); /* @var $seriesDao SeriesDAO */
		$path = isset($params['path']) && trim((string) $params['path']) !== ''
			? $this->readPath($params['path'], $context, null, $errors)
			: $this->uniquePath($this->slug($this->firstValue(isset($props['title']) ? $props['title'] : array())), $context);

		if (!empty($errors)) {
			return $response->withStatus(400)->withJson($errors);
		}

		$series = $seriesDao->newDataObject();
		$series->setPressId($context->getId());
		$series->setPath($path);
		$series->setSequence(isset($params['seq']) ? (float) $params['seq'] : $this->nextSequence($context));
		$series->setFeatured(0);
		$series->setEditorRestricted(0);
		$series->setIsInactive(!empty($params['isInactive']) ? 1 : 0);
		$series->setSortOption(DAORegistry::getDAO('SubmissionDAO')->getSortOption(ORDERBY_SERIES_POSITION, SORT_DIRECTION_ASC));
		$this->applyProps($series, $props);
		$seriesDao->insertObject($series);

		$series = $seriesDao->getById($series->getId(), $context->getId());
		return $response->withJson($this->seriesProps($series, $context), 201);
	}

	/**
	 * PUT /submissions/books/series/{seriesId}  -- edit a series. Only fields
	 * sent are changed.
	 */
	public function editSeries($slimRequest, $response, $args) {
		$context = Application::get()->getRequest()->getContext();
		$series = $this->contextSeries($args['seriesId'], $context);
		if (!$series) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}

		$params = (array) $slimRequest->getParsedBody();
		$errors = array();
		$locale = $this->readLocale($params, $context, $errors);
		$props = $this->readSeriesProps($params, $locale, $context, $errors);
		if (array_key_exists('title', $props) && BookApiHelper::isBlankLocalized($props['title'])) {
			$errors['title'] = array(__('form.dataRequired'));
		}
		$path = array_key_exists('path', $params)
			? $this->readPath($params['path'], $context, $series->getId(), $errors)
			: null;

		if (!empty($errors)) {
			return $response->withStatus(400)->withJson($errors);
		}

		if ($path !== null) $series->setPath($path);
		if (isset($params['seq'])) $series->setSequence((float) $params['seq']);
		if (array_key_exists('isInactive', $params)) $series->setIsInactive(!empty($params['isInactive']) ? 1 : 0);
		$this->applyProps($series, $props);

		$seriesDao = Application::get()->getSectionDAO(); /* @var $seriesDao SeriesDAO */
		$seriesDao->updateObject($series);

		$series = $seriesDao->getById($series->getId(), $context->getId());
		return $response->withJson($this->seriesProps($series, $context), 200);
	}

	/**
	 * DELETE /submissions/books/series/{seriesId}  -- delete an empty series.
	 *
	 * The core would silently detach the series' books; the API refuses
	 * instead, so no volume loses its series by accident.
	 */
	public function removeSeries($slimRequest, $response, $args) {
		$context = Application::get()->getRequest()->getContext();
		$series = $this->contextSeries($args['seriesId'], $context);
		if (!$series) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}
		if ($this->bookCount($series, $context) > 0) {
			return $response->withStatus(409)->withJson(array(
				'error' => 'api.series.403.notEmpty',
				'errorMessage' => __('plugins.generic.ompApiWrite.series.notEmpty'),
			));
		}

		$data = $this->seriesProps($series, $context);
		Application::get()->getSectionDAO()->deleteById($series->getId(), $context->getId());

		return $response->withJson($data, 200);
	}

	//
	// Input
	//

	/**
	 * The series in the current press, or null.
	 *
	 * @return Series|null
	 */
	protected function contextSeries($seriesId, $context) {
		if (!$context) {
			return null;
		}
		return Application::get()->getSectionDAO()->getById((int) $seriesId, $context->getId()) ?: null;
	}

	/**
	 * `locale`: the language of plain-string fields.
	 */
	protected function readLocale($params, $context, &$errors) {
		$locale = !empty($params['locale']) ? (string) $params['locale'] : $context->getPrimaryLocale();
		if (!in_array($locale, (array) $context->getSupportedFormLocales(), true)) {
			$errors['locale'] = array(__('plugins.generic.ompApiWrite.locale.unsupported', array('locales' => $locale)));
		}
		return $locale;
	}

	/**
	 * The multilingual fields sent, normalised to { locale: value }.
	 *
	 * @return array
	 */
	protected function readSeriesProps($params, $locale, $context, &$errors) {
		$props = array();
		$supported = (array) $context->getSupportedFormLocales();
		foreach ($this->localizedFields as $field) {
			if (!array_key_exists($field, $params)) continue;
			$value = BookApiHelper::toLocalized($params[$field], $locale);
			$bad = array_diff(array_keys($value), $supported);
			if (!empty($bad)) {
				$errors[$field] = array(__('plugins.generic.ompApiWrite.locale.unsupported', array('locales' => join(', ', $bad))));
				continue;
			}
			$props[$field] = $value;
		}
		return $props;
	}

	/**
	 * `path`: same rule as the OMP series form, unique in the press.
	 */
	protected function readPath($value, $context, $exceptSeriesId, &$errors) {
		$path = trim((string) $value);
		if (!preg_match('/^[a-zA-Z0-9\/._-]+$/', $path)) {
			$errors['path'] = array(__('plugins.generic.ompApiWrite.series.invalidPath'));
			return null;
		}
		$existing = Application::get()->getSectionDAO()->getByPath($path, $context->getId());
		if ($existing && (int) $existing->getId() !== (int) $exceptSeriesId) {
			$errors['path'] = array(__('plugins.generic.ompApiWrite.series.duplicatePath'));
			return null;
		}
		return $path;
	}

	/**
	 * Copy the multilingual fields onto the series, keeping other locales.
	 */
	protected function applyProps($series, $props) {
		foreach ($props as $field => $values) {
			$current = (array) $series->getData($field);
			$series->setData($field, array_merge($current, $values));
		}
	}

	//
	// Helpers
	//

	protected function firstValue($localized) {
		foreach ((array) $localized as $value) {
			if (is_string($value) && trim($value) !== '') return $value;
		}
		return '';
	}

	/**
	 * A URL path from a title: lowercase ASCII words joined by "-".
	 */
	protected function slug($text) {
		$text = strtr((string) $text, array(
			'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
			'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
			'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'ç' => 'c', 'Ç' => 'c',
		));
		$slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($text)), '-');
		return $slug !== '' ? substr($slug, 0, 200) : 'series';
	}

	/**
	 * $base, or $base-2, $base-3... the first one free in the press.
	 */
	protected function uniquePath($base, $context) {
		$seriesDao = Application::get()->getSectionDAO(); /* @var $seriesDao SeriesDAO */
		$path = $base;
		for ($i = 2; $seriesDao->getByPath($path, $context->getId()); $i++) {
			$path = $base . '-' . $i;
		}
		return $path;
	}

	/**
	 * One past the highest sequence in use.
	 */
	protected function nextSequence($context) {
		$max = 0;
		$result = Application::get()->getSectionDAO()->getByContextId($context->getId());
		while ($series = $result->next()) {
			$max = max($max, (float) $series->getSequence());
		}
		return $max + 1;
	}

	/**
	 * Books whose current publication is in the series.
	 */
	protected function bookCount($series, $context) {
		return (int) Services::get('submission')->getMax(array(
			'contextId' => $context->getId(),
			'seriesIds' => array($series->getId()),
		));
	}

	//
	// Output
	//

	protected function seriesProps($series, $context) {
		return array(
			'id' => (int) $series->getId(),
			'path' => $series->getPath(),
			'title' => (array) $series->getData('title'),
			'prefix' => (array) $series->getData('prefix'),
			'subtitle' => (array) $series->getData('subtitle'),
			'description' => (array) $series->getData('description'),
			'seq' => (float) $series->getSequence(),
			'isInactive' => (bool) $series->getIsInactive(),
			'bookCount' => $this->bookCount($series, $context),
		);
	}
}
