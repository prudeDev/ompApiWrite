<?php

/**
 * @file plugins/generic/ompApiWrite/api/v1/submissions/AuthorWriteController.inc.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AuthorWriteController
 * @ingroup plugins_generic_ompApiWrite
 *
 * @brief CRUD for the contributors of a book's publication (authors, volume
 *        editors). Routes are injected into the core PKPSubmissionHandler.
 *
 *        Authorization: manager role + press is enforced by the core handler;
 *        each method additionally checks that the publication belongs to a
 *        book of the current press.
 */

import('classes.core.Services');
import('plugins.generic.ompApiWrite.classes.BookApiHelper');

// ORCID public API — readable without OAuth, unlike the member API.
define('OMPAPI_ORCID_PUBLIC_API', 'https://pub.orcid.org/');
define('OMPAPI_ORCID_PUBLIC_API_SANDBOX', 'https://pub.sandbox.orcid.org/');
define('OMPAPI_ORCID_URL', 'https://orcid.org/');
define('OMPAPI_ORCID_URL_SANDBOX', 'https://sandbox.orcid.org/');

class AuthorWriteController {

	/**
	 * GET /submissions/{submissionId}/publications/{publicationId}/authors
	 */
	public function getAuthors($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$found = BookApiHelper::contextPublication($args, $request->getContext());
		if (!$found) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}
		list(, $publication) = $found;

		$items = array();
		$iterator = Services::get('author')->getMany(array('publicationIds' => array($publication->getId())));
		foreach ($iterator as $author) {
			$items[] = $this->props($author, $request, $slimRequest);
		}

		return $response->withJson(array('items' => $items, 'itemsMax' => count($items)), 200);
	}

	/**
	 * POST /submissions/{submissionId}/publications/{publicationId}/authors
	 *
	 * Extra (non-schema) body fields:
	 *   primaryContact     bool  make this author the publication's contact
	 *   fetchOrcidProfile  bool  fill blanks from the public ORCID record (default true)
	 *   lookupOrcidByName  bool  search ORCID by name when no `orcid` is sent (default false)
	 */
	public function addAuthor($slimRequest, $response, $args) {
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

		$raw = (array) $slimRequest->getParsedBody();
		$makePrimary = !empty($raw['primaryContact']);
		$params = $this->prepareAuthorParams($raw, $context, $this->bookLocale($submission, $context), VALIDATE_ACTION_ADD, $errors);
		if (!empty($errors)) {
			return $response->withStatus(400)->withJson($errors);
		}

		$params['publicationId'] = $publication->getId();
		$author = $this->insertAuthor($params, $request);

		if ($makePrimary || !$publication->getData('primaryContactId')) {
			Services::get('publication')->edit($publication, array('primaryContactId' => $author->getId()), $request);
		}

		return $response->withJson($this->props($author, $request, $slimRequest), 201);
	}

	/**
	 * PUT /submissions/{submissionId}/publications/{publicationId}/authors/{authorId}
	 */
	public function editAuthor($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		$found = BookApiHelper::contextPublication($args, $context);
		if (!$found) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}
		list($submission, $publication) = $found;

		$author = Services::get('author')->get((int) $args['authorId']);
		if (!$author || (int) $author->getData('publicationId') !== (int) $publication->getId()) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}
		if (BookApiHelper::isPublished($publication)) {
			return BookApiHelper::publishedConflict($response);
		}

		$raw = (array) $slimRequest->getParsedBody();
		$makePrimary = !empty($raw['primaryContact']);
		$params = $this->prepareAuthorParams($raw, $context, $this->bookLocale($submission, $context), VALIDATE_ACTION_EDIT, $errors);
		if (!empty($errors)) {
			return $response->withStatus(400)->withJson($errors);
		}
		// Fixed by the route; never let the body move an author.
		unset($params['publicationId'], $params['id']);

		$author = Services::get('author')->edit($author, $params, $request);

		if ($makePrimary) {
			Services::get('publication')->edit($publication, array('primaryContactId' => $author->getId()), $request);
		}

		return $response->withJson($this->props($author, $request, $slimRequest), 200);
	}

	/**
	 * DELETE /submissions/{submissionId}/publications/{publicationId}/authors/{authorId}
	 *
	 * Also unlinks the author from every chapter, and hands the primary-contact
	 * role to the next remaining author.
	 */
	public function deleteAuthor($slimRequest, $response, $args) {
		$request = Application::get()->getRequest();
		$found = BookApiHelper::contextPublication($args, $request->getContext());
		if (!$found) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}
		list(, $publication) = $found;

		$author = Services::get('author')->get((int) $args['authorId']);
		if (!$author || (int) $author->getData('publicationId') !== (int) $publication->getId()) {
			return $response->withStatus(404)->withJsonError('api.404.resourceNotFound');
		}
		if (BookApiHelper::isPublished($publication)) {
			return BookApiHelper::publishedConflict($response);
		}

		$data = $this->props($author, $request, $slimRequest);

		DAORegistry::getDAO('ChapterAuthorDAO')->deleteChapterAuthorById($author->getId());
		Services::get('author')->delete($author);

		if ((int) $publication->getData('primaryContactId') === (int) $author->getId()) {
			$nextId = null;
			$remaining = Services::get('author')->getMany(array('publicationIds' => array($publication->getId())));
			foreach ($remaining as $other) {
				$nextId = $other->getId();
				break;
			}
			Services::get('publication')->edit($publication, array('primaryContactId' => $nextId), $request);
		}

		return $response->withJson($data, 200);
	}

	//
	// Shared with BookWriteController (authors sent inline when creating a book)
	//

	/**
	 * Normalise, enrich (ORCID) and validate one author's input.
	 *
	 * @param array $raw request body for one author
	 * @param Context $context
	 * @param string $locale the book's locale; plain strings are stored under it
	 * @param string $action VALIDATE_ACTION_ADD | VALIDATE_ACTION_EDIT
	 * @param array $errors (out) field => messages; empty on success
	 * @return array params ready for Services::get('author')
	 */
	public function prepareAuthorParams($raw, $context, $locale, $action, &$errors) {
		$errors = array();
		$params = $this->readParams($raw, $locale);

		if ($action === VALIDATE_ACTION_ADD && empty($params['userGroupId'])) {
			$params['userGroupId'] = BookApiHelper::authorUserGroupId(
				$context->getId(), !empty($params['isVolumeEditor'])
			);
		}

		foreach (array('givenName', 'familyName', 'preferredPublicName', 'affiliation', 'biography') as $field) {
			if (isset($params[$field])) {
				$bad = BookApiHelper::unsupportedLocales($params[$field], $context);
				if (!empty($bad)) {
					$errors[$field] = array(__('plugins.generic.ompApiWrite.locale.unsupported', array('locales' => implode(', ', $bad))));
				}
			}
		}
		if (!empty($errors)) {
			return $params;
		}

		$orcidError = $this->applyOrcidProfile($params, $context, $locale);
		if ($orcidError) {
			$errors['orcid'] = array($orcidError);
			return $params;
		}

		$validation = Services::get('author')->validate(
			$action, $params, $context->getSupportedSubmissionLocales(), $locale
		);
		// The publication id is fixed by the route (or created later, for
		// authors sent inline with a new book), never by the caller.
		unset($validation['publicationId']);
		$errors = (array) $validation;

		return $params;
	}

	/**
	 * Store a prepared author.
	 *
	 * @return Author
	 */
	public function insertAuthor($params, $request) {
		$authorDao = DAORegistry::getDAO('AuthorDAO'); /* @var $authorDao AuthorDAO */
		$author = $authorDao->newDataObject();
		$author->setAllData($params);
		return Services::get('author')->add($author, $request);
	}

	//
	// ORCID enrichment
	//

	/**
	 * Fill the fields the caller left out from the author's public ORCID
	 * record. Values sent in the request always win.
	 *
	 * The record is the `orcid` sent in the request. Searching ORCID by name is
	 * only done when the caller asks for it (`lookupOrcidByName: true`): a name
	 * is not an identity, and a wrong iD credits the book to someone else.
	 *
	 * @param array $params (by ref) author params, enriched in place
	 * @return string|null  an error message, or null on success
	 */
	protected function applyOrcidProfile(&$params, $context, $locale) {
		// Not schema fields; consume them before they reach validation.
		$fetch = !array_key_exists('fetchOrcidProfile', $params) || (bool) $params['fetchOrcidProfile'];
		$byName = !empty($params['lookupOrcidByName']);
		unset($params['fetchOrcidProfile'], $params['lookupOrcidByName']);

		$sandbox = $this->usesOrcidSandbox($context->getId());
		$derived = false;

		if (!empty($params['orcid'])) {
			$orcidId = $this->normalizeOrcid($params['orcid']);
			if (!$orcidId) {
				return __('plugins.generic.ompApiWrite.orcid.invalid');
			}
		} else {
			if (!$byName) {
				return null;
			}
			$orcidId = $this->findOrcidByName($params, $sandbox);
			if (!$orcidId) {
				return null; // unknown or ambiguous name: keep what was sent
			}
			$derived = true;
		}

		// Always store the canonical ORCID URL, as the orcidProfile plugin does.
		$params['orcid'] = ($sandbox ? OMPAPI_ORCID_URL_SANDBOX : OMPAPI_ORCID_URL) . $orcidId;

		if (!$fetch) {
			return null;
		}

		$profile = $this->fetchOrcidProfile($orcidId, $sandbox);
		if ($profile === null) {
			if ($derived) {
				// Don't keep an iD we couldn't confirm, and don't fail the
				// request over a lookup that was only a convenience.
				unset($params['orcid']);
				return null;
			}
			return __('plugins.generic.ompApiWrite.orcid.fetchFailed');
		}

		foreach (array('givenName', 'familyName', 'biography', 'affiliation') as $field) {
			if (BookApiHelper::isBlankLocalized(isset($params[$field]) ? $params[$field] : null) && !empty($profile[$field])) {
				$params[$field] = array($locale => $profile[$field]);
			}
		}
		if (empty($params['country']) && !empty($profile['country'])) {
			$params['country'] = $profile['country'];
		}

		return null;
	}

	/**
	 * Find an ORCID iD from the author's name. Conservative: both name parts
	 * are required and only a single exact hit is accepted.
	 *
	 * @return string|null  a bare ORCID iD
	 */
	protected function findOrcidByName($params, $sandbox) {
		$givenName = $this->firstLocalized(isset($params['givenName']) ? $params['givenName'] : null);
		$familyName = $this->firstLocalized(isset($params['familyName']) ? $params['familyName'] : null);
		if (!$givenName || !$familyName) {
			return null;
		}

		$query = 'given-names:"' . $this->escapeOrcidQuery($givenName) . '"'
			. ' AND family-name:"' . $this->escapeOrcidQuery($familyName) . '"';
		$base = $sandbox ? OMPAPI_ORCID_PUBLIC_API_SANDBOX : OMPAPI_ORCID_PUBLIC_API;

		try {
			$res = Application::get()->getHttpClient()->request('GET', $base . 'v3.0/expanded-search/', array(
				'headers' => array('Accept' => 'application/json'),
				'query' => array('q' => $query, 'rows' => 2),
				'http_errors' => false,
				'timeout' => 10,
			));
			if ($res->getStatusCode() != 200) {
				return null;
			}
			$results = json_decode($res->getBody(), true);
		} catch (Exception $e) {
			error_log('[ompApiWrite] ORCID name search failed: ' . $e->getMessage());
			return null;
		}

		if (!isset($results['num-found']) || (int) $results['num-found'] !== 1) {
			return null;
		}
		$orcidId = $this->orcidValue($results, array('expanded-result', 0, 'orcid-id'));

		return $orcidId ? $this->normalizeOrcid($orcidId) : null;
	}

	/**
	 * Read the public ORCID record: name and biography from /person, current
	 * affiliation from /employments.
	 *
	 * @return array|null  null when the record can't be read
	 */
	protected function fetchOrcidProfile($orcidId, $sandbox) {
		$base = $sandbox ? OMPAPI_ORCID_PUBLIC_API_SANDBOX : OMPAPI_ORCID_PUBLIC_API;
		$httpClient = Application::get()->getHttpClient();
		$options = array(
			'headers' => array('Accept' => 'application/json'),
			'http_errors' => false,
			'timeout' => 10,
		);

		try {
			$res = $httpClient->request('GET', $base . 'v3.0/' . urlencode($orcidId) . '/person', $options);
			if ($res->getStatusCode() != 200) {
				return null;
			}
			$person = json_decode($res->getBody(), true);
		} catch (Exception $e) {
			error_log('[ompApiWrite] ORCID person lookup failed: ' . $e->getMessage());
			return null;
		}

		$profile = array(
			'givenName' => $this->orcidValue($person, array('name', 'given-names', 'value')),
			'familyName' => $this->orcidValue($person, array('name', 'family-name', 'value')),
			'biography' => $this->orcidValue($person, array('biography', 'content')),
			'country' => $this->orcidValue($person, array('addresses', 'address', 0, 'country', 'value')),
			'affiliation' => null,
		);

		// Affiliation lives in the employments record; a failure here is not fatal.
		try {
			$res = $httpClient->request('GET', $base . 'v3.0/' . urlencode($orcidId) . '/employments', $options);
			if ($res->getStatusCode() == 200) {
				$employments = json_decode($res->getBody(), true);
				$profile['affiliation'] = $this->orcidValue($employments, array(
					'affiliation-group', 0, 'summaries', 0, 'employment-summary', 'organization', 'name'
				));
			}
		} catch (Exception $e) {
			error_log('[ompApiWrite] ORCID employments lookup failed: ' . $e->getMessage());
		}

		return $profile;
	}

	/**
	 * Whether the press's orcidProfile plugin points at the ORCID sandbox.
	 */
	protected function usesOrcidSandbox($contextId) {
		$plugin = PluginRegistry::getPlugin('generic', 'orcidprofileplugin');
		if (!$plugin || !$plugin->getEnabled($contextId)) {
			return false;
		}
		$apiPath = (string) $plugin->getSetting($contextId, 'orcidProfileAPIPath');
		return strpos($apiPath, 'sandbox') !== false;
	}

	/**
	 * Extract the bare 16-digit ORCID iD from either the raw id or a full URL.
	 */
	protected function normalizeOrcid($value) {
		if (preg_match('/(\d{4}-\d{4}-\d{4}-\d{3}[\dX])/i', (string) $value, $m)) {
			return strtoupper($m[1]);
		}
		return null;
	}

	/**
	 * Escape a value for use inside a quoted ORCID (Solr) query term.
	 */
	protected function escapeOrcidQuery($value) {
		return str_replace(array('\\', '"'), array('\\\\', '\\"'), $value);
	}

	/**
	 * Safely walk a nested ORCID JSON structure.
	 */
	protected function orcidValue($data, $path) {
		foreach ($path as $key) {
			if (!is_array($data) || !isset($data[$key])) {
				return null;
			}
			$data = $data[$key];
		}
		return is_string($data) || is_numeric($data) ? $data : null;
	}

	/**
	 * First non-blank value of a string or { locale: value } map.
	 */
	protected function firstLocalized($value) {
		foreach ((array) $value as $localized) {
			if (is_string($localized) && trim($localized) !== '') {
				return trim($localized);
			}
		}
		return null;
	}

	//
	// Helpers
	//

	/**
	 * Normalise multilingual fields (a plain string goes under the book's
	 * locale) and cast the scalar fields the schema expects.
	 */
	protected function readParams($params, $locale) {
		unset($params['primaryContact']);

		foreach (array('givenName', 'familyName', 'preferredPublicName', 'affiliation', 'biography') as $field) {
			if (isset($params[$field]) && is_string($params[$field])) {
				$params[$field] = array($locale => $params[$field]);
			}
		}

		if (isset($params['seq'])) $params['seq'] = (int) $params['seq'];
		if (isset($params['userGroupId'])) $params['userGroupId'] = (int) $params['userGroupId'];
		if (isset($params['includeInBrowse'])) $params['includeInBrowse'] = (bool) $params['includeInBrowse'];
		if (isset($params['isVolumeEditor'])) $params['isVolumeEditor'] = (bool) $params['isVolumeEditor'];

		return $params;
	}

	/**
	 * The book's own locale: what OMP's forms treat as the author's primary.
	 */
	protected function bookLocale($submission, $context) {
		$locale = $submission->getData('locale');
		return $locale ? $locale : $context->getPrimaryLocale();
	}

	/**
	 * Full JSON representation of an author.
	 */
	protected function props($author, $request, $slimRequest) {
		return Services::get('author')->getFullProperties($author, array(
			'request' => $request,
			'slimRequest' => $slimRequest,
		));
	}
}
