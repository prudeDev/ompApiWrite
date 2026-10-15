<?php

/**
 * @file plugins/generic/ompApiWrite/OmpApiWritePlugin.inc.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class OmpApiWritePlugin
 * @ingroup plugins_generic_ompApiWrite
 *
 * @brief Adds write endpoints for books (monographs) to the existing OMP 3.3
 *        REST API, without modifying the core: books, authors, chapters,
 *        publication formats (with their files) and bulk press creation.
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class OmpApiWritePlugin extends GenericPlugin {

	/**
	 * @copydoc Plugin::register()
	 */
	public function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);
		if ($success) {
			// Register the hook unconditionally. Generic plugins are loaded by
			// the Dispatcher before the request context is resolved, so
			// getEnabled() is unreliable here. The enabled-check is deferred to
			// the callback, which runs at route-setup time once the context is
			// known. See APIHandler::setupEndpoints().
			HookRegistry::register('APIHandler::endpoints', array($this, 'addEndpoints'));
			// Every press created from now on starts with this plugin (and DOI)
			// enabled. See enableForNewContext().
			HookRegistry::register('Context::add', array($this, 'enableForNewContext'));
		}
		return $success;
	}

	/**
	 * Plugins enabled automatically in every new press: this one, so the API
	 * can write to the press right away, and DOI, which the `doi` fields of
	 * the API need.
	 */
	protected $newContextPlugins = array('doipubidplugin');

	/**
	 * Hook callback for 'Context::add'.
	 *
	 * $args = array(&$context, $request)
	 *
	 * OMP loads every plugin (enabled or not) right before firing this hook
	 * when a press is created, whether from this API (contexts/bulk) or from
	 * the OMP UI. The setting is idempotent: if the hook runs more than once
	 * for the same press, nothing changes.
	 *
	 * @param string $hookName
	 * @param array $args
	 * @return boolean false, to let other hooks run
	 */
	public function enableForNewContext($hookName, $args) {
		$context = $args[0];
		$pluginSettingsDao = DAORegistry::getDAO('PluginSettingsDAO'); /* @var $pluginSettingsDao PluginSettingsDAO */
		foreach (array_merge(array($this->getName()), $this->newContextPlugins) as $pluginName) {
			$pluginSettingsDao->updateSetting($context->getId(), $pluginName, 'enabled', true, 'bool');
		}
		return false;
	}

	/**
	 * Hook callback for 'APIHandler::endpoints'.
	 *
	 * $args = array(&$endpoints, $apiHandler)
	 *
	 * Only the submissions and contexts resources are augmented. OMP has no
	 * "issues": every book route hangs off /submissions.
	 *
	 * NOTE: api/v1/submissions/index.php hands any path containing "/files" to
	 * PKPSubmissionFileHandler, so none of our routes may contain "/files".
	 * Files inside a publication format live under ".../documents".
	 *
	 * NOTE: APIHandler keys role assignments by handler *method name*, shared
	 * with the core routes of the same handler. Every method name used here is
	 * therefore distinct from PKPSubmissionHandler's own (add, edit, delete,
	 * get, getMany, getPublication, ...).
	 *
	 * @param string $hookName
	 * @param array $args
	 * @return boolean false, to let other hooks run
	 */
	public function addEndpoints($hookName, $args) {
		$endpoints =& $args[0];
		$handler = $args[1];

		// Bulk-creating and exporting presses is a site-wide, site-admin
		// operation served from the site API (no press in the path), where the
		// per-press getEnabled() check below would be false. Wired before that
		// check, guarded only by ROLE_ID_SITE_ADMIN.
		if (is_a($handler, 'PKPContextHandler')) {
			$this->addContextEndpoints($endpoints, $handler->getEndpointPattern());
			return false;
		}

		if (!is_a($handler, 'PKPSubmissionHandler')) {
			return false;
		}

		// The request context is resolved by now, so this check is reliable.
		$context = Application::get()->getRequest()->getContext();
		$contextId = $context ? $context->getId() : CONTEXT_SITE;
		if (!$this->getEnabled($contextId)) {
			return false;
		}

		// Only press managers may use these endpoints.
		$roles = array(ROLE_ID_MANAGER);
		$base = $handler->getEndpointPattern();

		$this->addSeriesEndpoints($endpoints, $base, $roles);
		$this->addBookEndpoints($endpoints, $base, $roles);
		$this->addAuthorEndpoints($endpoints, $base, $roles);
		$this->addChapterEndpoints($endpoints, $base, $roles);
		$this->addFormatEndpoints($endpoints, $base, $roles);

		return false;
	}

	/**
	 * Books: a submission + its current publication, managed as one unit.
	 *
	 * The static "/books" segment never collides with the core
	 * "/{submissionId:\d+}" placeholder.
	 */
	protected function addBookEndpoints(&$endpoints, $base, $roles) {
		$this->import('api.v1.submissions.BookWriteController');
		$controller = new BookWriteController();
		$books = $base . '/books';

		$this->route($endpoints, 'POST', $books, $controller, 'createBook', $roles);
		$this->route($endpoints, 'GET', $books . '/{submissionId:\d+}', $controller, 'getBook', $roles);
		$this->route($endpoints, 'PUT', $books . '/{submissionId:\d+}', $controller, 'updateBook', $roles);
		$this->route($endpoints, 'DELETE', $books . '/{submissionId:\d+}', $controller, 'removeBook', $roles);
		$this->route($endpoints, 'PUT', $books . '/{submissionId:\d+}/publish', $controller, 'publishBook', $roles);
		$this->route($endpoints, 'PUT', $books . '/{submissionId:\d+}/unpublish', $controller, 'unpublishBook', $roles);
	}

	/**
	 * Series of the press: multi-volume works a book joins via `seriesId`.
	 *
	 * The static "/books/series" segment never collides with
	 * "/books/{submissionId:\d+}".
	 */
	protected function addSeriesEndpoints(&$endpoints, $base, $roles) {
		$this->import('api.v1.submissions.SeriesWriteController');
		$controller = new SeriesWriteController();
		$series = $base . '/books/series';

		$this->route($endpoints, 'GET', $series, $controller, 'listSeries', $roles);
		$this->route($endpoints, 'POST', $series, $controller, 'createSeries', $roles);
		$this->route($endpoints, 'GET', $series . '/{seriesId:\d+}', $controller, 'getSeries', $roles);
		$this->route($endpoints, 'PUT', $series . '/{seriesId:\d+}', $controller, 'editSeries', $roles);
		$this->route($endpoints, 'DELETE', $series . '/{seriesId:\d+}', $controller, 'removeSeries', $roles);
	}

	/**
	 * Authors (contributors and volume editors) of a publication.
	 */
	protected function addAuthorEndpoints(&$endpoints, $base, $roles) {
		$this->import('api.v1.submissions.AuthorWriteController');
		$controller = new AuthorWriteController();
		$authors = $base . '/{submissionId:\d+}/publications/{publicationId:\d+}/authors';

		$this->route($endpoints, 'GET', $authors, $controller, 'getAuthors', $roles);
		$this->route($endpoints, 'POST', $authors, $controller, 'addAuthor', $roles);
		$this->route($endpoints, 'PUT', $authors . '/{authorId:\d+}', $controller, 'editAuthor', $roles);
		$this->route($endpoints, 'DELETE', $authors . '/{authorId:\d+}', $controller, 'deleteAuthor', $roles);
	}

	/**
	 * Chapters of a publication, with their chapter authors.
	 */
	protected function addChapterEndpoints(&$endpoints, $base, $roles) {
		$this->import('api.v1.submissions.ChapterWriteController');
		$controller = new ChapterWriteController();
		$chapters = $base . '/{submissionId:\d+}/publications/{publicationId:\d+}/chapters';

		$this->route($endpoints, 'GET', $chapters, $controller, 'listChapters', $roles);
		$this->route($endpoints, 'POST', $chapters, $controller, 'addChapter', $roles);
		$this->route($endpoints, 'PUT', $chapters . '/{chapterId:\d+}', $controller, 'editChapter', $roles);
		$this->route($endpoints, 'DELETE', $chapters . '/{chapterId:\d+}', $controller, 'deleteChapter', $roles);
	}

	/**
	 * Publication formats (PDF, EPUB, print...) and the files inside them.
	 */
	protected function addFormatEndpoints(&$endpoints, $base, $roles) {
		$this->import('api.v1.submissions.PublicationFormatController');
		$controller = new PublicationFormatController();
		$formats = $base . '/{submissionId:\d+}/publications/{publicationId:\d+}/formats';
		$documents = $formats . '/{formatId:\d+}/documents';

		$this->route($endpoints, 'GET', $formats, $controller, 'listFormats', $roles);
		$this->route($endpoints, 'POST', $formats, $controller, 'addFormat', $roles);
		$this->route($endpoints, 'PUT', $formats . '/{formatId:\d+}', $controller, 'editFormat', $roles);
		$this->route($endpoints, 'DELETE', $formats . '/{formatId:\d+}', $controller, 'deleteFormat', $roles);
		$this->route($endpoints, 'POST', $documents, $controller, 'addFormatDocument', $roles);
		$this->route($endpoints, 'PUT', $documents . '/{submissionFileId:\d+}', $controller, 'editFormatDocument', $roles);
		$this->route($endpoints, 'DELETE', $documents . '/{submissionFileId:\d+}', $controller, 'deleteFormatDocument', $roles);
	}

	/**
	 * Bulk-import and export routes on the core contexts resource.
	 *
	 * The static "/bulk" and "/export" segments take precedence over the core
	 * "/{contextId}" placeholder in the router, so there is no collision.
	 */
	protected function addContextEndpoints(&$endpoints, $base) {
		$this->import('api.v1.contexts.ContextWriteController');
		$controller = new ContextWriteController();
		$roles = array(ROLE_ID_SITE_ADMIN);

		$this->route($endpoints, 'POST', $base . '/bulk', $controller, 'bulkAdd', $roles);
		$this->route($endpoints, 'GET', $base . '/export', $controller, 'export', $roles);
	}

	/**
	 * Append one route definition.
	 */
	protected function route(&$endpoints, $method, $pattern, $controller, $action, $roles) {
		$endpoints[$method][] = array(
			'pattern' => $pattern,
			'handler' => array($controller, $action),
			'roles' => $roles,
		);
	}

	/**
	 * @copydoc Plugin::getDisplayName()
	 */
	public function getDisplayName() {
		return __('plugins.generic.ompApiWrite.displayName');
	}

	/**
	 * @copydoc Plugin::getDescription()
	 */
	public function getDescription() {
		return __('plugins.generic.ompApiWrite.description');
	}
}
