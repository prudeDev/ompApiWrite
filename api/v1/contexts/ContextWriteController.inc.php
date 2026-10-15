<?php

/**
 * @file plugins/generic/ompApiWrite/api/v1/contexts/ContextWriteController.inc.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ContextWriteController
 * @ingroup plugins_generic_ompApiWrite
 *
 * @brief Site-wide endpoints to bulk-create presses (contexts) and to export
 *        the current list of presses. Both require a Site Administrator.
 *
 * Creating/listing every press is a site-level operation, so both endpoints
 * are only served from the site-wide API (no press in the request path):
 *
 *   POST /index.php/index/api/v1/contexts/bulk
 *   GET  /index.php/index/api/v1/contexts/export[?format=csv]
 */

class ContextWriteController {

	/**
	 * Maximum presses accepted in a single bulk request.
	 *
	 * Creating a press runs the full context initialization (several seconds
	 * each), and every add() re-runs PluginRegistry::loadAllPlugins(), which
	 * re-registers hooks that accumulate within the PHP process: each further
	 * press in the SAME request gets slower. Keep batches small and let the
	 * caller loop (each HTTP request is a fresh process).
	 */
	const MAX_BULK = 25;

	/** Columns used for CSV import/export, in order. */
	private $csvColumns = array('urlPath', 'name', 'acronym', 'description', 'primaryLocale', 'supportedLocales', 'supportedFormLocales', 'supportedSubmissionLocales', 'enabled');

	/**
	 * Bulk-create presses.
	 *
	 * Body (JSON): { "presses": [ { "urlPath": "...", "name": "...",
	 *   "acronym": "...", "description": "...", "primaryLocale": "en_US",
	 *   "enabled": true }, ... ] }
	 *
	 * `name`, `acronym` and `description` accept a plain string (stored in the
	 * press's primary locale) or an object keyed by locale. Every item is
	 * processed independently: a bad row is reported in `errors`/`skipped` and
	 * does not stop the rest.
	 */
	public function bulkAdd($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();

		// Site-wide only: creating presses has no meaning inside a press.
		if ($request->getContext()) {
			return $response->withStatus(404)->withJsonError('api.submissions.404.siteWideEndpoint');
		}

		$body = $slimRequest->getParsedBody();
		$presses = isset($body['presses']) ? $body['presses'] : null;
		if (!is_array($presses) || empty($presses)) {
			return $response->withStatus(400)->withJson(array(
				'error' => 'api.400.invalidBody',
				'errorMessage' => __('plugins.generic.ompApiWrite.bulk.required'),
			));
		}

		if (count($presses) > self::MAX_BULK) {
			return $response->withStatus(400)->withJson(array(
				'error' => 'api.400.tooManyPresses',
				'errorMessage' => __('plugins.generic.ompApiWrite.bulk.tooMany', array(
					'count' => count($presses), 'max' => self::MAX_BULK,
				)),
			));
		}

		// A full batch can exceed the default PHP time limit (the web server
		// may still enforce its own timeout).
		if (function_exists('set_time_limit')) {
			@set_time_limit(0);
		}

		$site = $request->getSite();
		$sitePrimaryLocale = $site->getPrimaryLocale();
		$allowedLocales = $site->getSupportedLocales();
		$contextService = Services::get('context');
		$contextDao = Application::getContextDAO();

		$created = array();
		$skipped = array();
		$errors = array();

		foreach ($presses as $index => $press) {
			if (!is_array($press)) {
				$errors[] = array('index' => $index, 'errors' => array('body' => __('plugins.generic.ompApiWrite.bulk.notObject')));
				continue;
			}

			$urlPath = isset($press['urlPath']) ? trim((string) $press['urlPath']) : '';
			$locale = !empty($press['primaryLocale']) ? trim((string) $press['primaryLocale']) : $sitePrimaryLocale;

			$name = $this->toLocalizedValue(isset($press['name']) ? $press['name'] : null, $locale);
			if ($urlPath === '' || $this->isBlankLocalized($name)) {
				$errors[] = array('index' => $index, 'urlPath' => $urlPath, 'errors' => array('name' => __('plugins.generic.ompApiWrite.bulk.pathAndName')));
				continue;
			}

			// Cheap duplicate check first, so a repeated file is idempotent and
			// reported as skipped rather than as a validation error.
			if ($contextDao->getByPath($urlPath)) {
				$skipped[] = array('index' => $index, 'urlPath' => $urlPath, 'reason' => __('plugins.generic.ompApiWrite.bulk.exists'));
				continue;
			}

			$params = array(
				'primaryLocale' => $locale,
				'name' => $name,
				'urlPath' => $urlPath,
				'enabled' => !array_key_exists('enabled', $press) ? true : (bool) $press['enabled'],
			);
			foreach (array('acronym', 'description') as $field) {
				$value = $this->toLocalizedValue(isset($press[$field]) ? $press[$field] : null, $locale);
				if (!$this->isBlankLocalized($value)) {
					$params[$field] = $value;
				}
			}

			// Language settings: UI, Forms and Submissions locales.
			foreach (array('supportedLocales', 'supportedFormLocales', 'supportedSubmissionLocales') as $localeProp) {
				if (array_key_exists($localeProp, $press)) {
					$list = $this->toLocaleList($press[$localeProp]);
					if (!empty($list)) {
						$params[$localeProp] = $list;
					}
				}
			}

			// Forms/Submissions locales must be a subset of the UI locales, as
			// the settings form enforces.
			$uiLocales = isset($params['supportedLocales']) ? $params['supportedLocales'] : $allowedLocales;
			$subsetError = null;
			foreach (array('supportedFormLocales' => 'Forms', 'supportedSubmissionLocales' => 'Submissions') as $prop => $label) {
				if (isset($params[$prop])) {
					$outside = array_diff($params[$prop], $uiLocales);
					if (!empty($outside)) {
						$subsetError = __('plugins.generic.ompApiWrite.bulk.localeSubset', array('label' => $label, 'locales' => implode(', ', $outside)));
						break;
					}
				}
			}
			if ($subsetError !== null) {
				$errors[] = array('index' => $index, 'urlPath' => $urlPath, 'errors' => array('supportedLocales' => $subsetError));
				continue;
			}

			// The core validator enforces the urlPath format, uniqueness, and
			// that every locale is installed on the site.
			$validationErrors = $contextService->validate(VALIDATE_ACTION_ADD, $params, $allowedLocales, $locale);
			if (!empty($validationErrors)) {
				$errors[] = array('index' => $index, 'urlPath' => $urlPath, 'errors' => $validationErrors);
				continue;
			}

			try {
				$context = $contextDao->newDataObject();
				$context->setAllData($params);
				$context = $contextService->add($context, $request);
				$created[] = array(
					'id' => (int) $context->getId(),
					'urlPath' => $context->getPath(),
					'name' => $context->getLocalizedName($locale),
				);
			} catch (Exception $e) {
				$errors[] = array('index' => $index, 'urlPath' => $urlPath, 'errors' => array('exception' => $e->getMessage()));
			}
		}

		return $response->withJson(array(
			'summary' => array(
				'received' => count($presses),
				'created' => count($created),
				'skipped' => count($skipped),
				'errors' => count($errors),
			),
			'created' => $created,
			'skipped' => $skipped,
			'errors' => $errors,
		), empty($created) ? 200 : 201);
	}

	/**
	 * Export every press. `?format=csv` returns the same columns the bulk
	 * import and tools/importPresses.php accept; anything else returns JSON.
	 */
	public function export($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();

		if ($request->getContext()) {
			return $response->withStatus(404)->withJsonError('api.submissions.404.siteWideEndpoint');
		}

		$params = $slimRequest->getQueryParams();
		$format = isset($params['format']) ? strtolower(trim($params['format'])) : 'json';

		$rows = array();
		$contextsIterator = Services::get('context')->getMany(array('count' => 10000, 'offset' => 0));
		foreach ($contextsIterator as $context) {
			$locale = $context->getPrimaryLocale();
			$rows[] = array(
				'id' => (int) $context->getId(),
				'urlPath' => $context->getPath(),
				'name' => $context->getLocalizedName($locale),
				'acronym' => (string) $context->getLocalizedAcronym(),
				'description' => (string) $context->getLocalizedData('description', $locale),
				'primaryLocale' => $locale,
				'supportedLocales' => (array) $context->getData('supportedLocales'),
				'supportedFormLocales' => (array) $context->getData('supportedFormLocales'),
				'supportedSubmissionLocales' => (array) $context->getData('supportedSubmissionLocales'),
				'enabled' => (bool) $context->getEnabled(),
			);
		}

		if ($format === 'csv') {
			$response->getBody()->write($this->toCsv($rows));
			return $response
				->withHeader('Content-Type', 'text/csv; charset=utf-8')
				->withHeader('Content-Disposition', 'attachment; filename="presses.csv"');
		}

		return $response->withJson(array(
			'itemsMax' => count($rows),
			'items' => $rows,
		), 200);
	}

	/**
	 * A plain string or a {locale: value} object, as the multilingual array
	 * OMP expects.
	 */
	private function toLocalizedValue($value, $locale) {
		if (is_array($value)) {
			return $value;
		}
		if ($value === null || $value === '') {
			return array();
		}
		return array($locale => trim((string) $value));
	}

	/**
	 * Locale list from an array or a "en_US|es_ES" style string.
	 */
	private function toLocaleList($value) {
		if (is_array($value)) {
			$list = $value;
		} elseif (is_string($value) && trim($value) !== '') {
			$list = preg_split('/[|,\s]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
		} else {
			return array();
		}
		$out = array();
		foreach ($list as $locale) {
			$locale = trim((string) $locale);
			if ($locale !== '' && !in_array($locale, $out, true)) {
				$out[] = $locale;
			}
		}
		return $out;
	}

	/**
	 * True when no locale carries a non-blank string.
	 */
	private function isBlankLocalized($localized) {
		foreach ((array) $localized as $value) {
			if (is_string($value) && trim($value) !== '') {
				return false;
			}
		}
		return true;
	}

	/**
	 * CSV (with header and UTF-8 BOM, so Excel opens accents correctly).
	 */
	private function toCsv($rows) {
		$handle = fopen('php://temp', 'r+');
		fputcsv($handle, $this->csvColumns);
		foreach ($rows as $row) {
			fputcsv($handle, array(
				$this->csvSafe($row['urlPath']),
				$this->csvSafe($row['name']),
				$this->csvSafe($row['acronym']),
				$this->csvSafe($row['description']),
				$row['primaryLocale'],
				implode('|', (array) $row['supportedLocales']),
				implode('|', (array) $row['supportedFormLocales']),
				implode('|', (array) $row['supportedSubmissionLocales']),
				$row['enabled'] ? '1' : '0',
			));
		}
		rewind($handle);
		$csv = stream_get_contents($handle);
		fclose($handle);
		return "\xEF\xBB\xBF" . $csv;
	}

	/**
	 * Neutralise spreadsheet formulas: a cell starting with = + - @ would be
	 * evaluated when the file is opened in Excel.
	 */
	private function csvSafe($value) {
		$value = (string) $value;
		return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
	}
}
