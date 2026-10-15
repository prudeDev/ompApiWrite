<?php

/**
 * @file plugins/generic/ompApiWrite/tools/importPresses.php
 *
 * Carga masiva de editoriales (presses) en OMP 3.3 desde un archivo CSV.
 *
 * Uso (desde la raíz de OMP):
 *   php plugins/generic/ompApiWrite/tools/importPresses.php <adminUsername> <archivo.csv>
 *
 * Ejemplo:
 *   php plugins/generic/ompApiWrite/tools/importPresses.php admin presses.csv
 *
 * Formato del CSV (con cabecera, separado por comas, UTF-8):
 *   urlPath,name,acronym,description,primaryLocale,supportedLocales,supportedFormLocales,supportedSubmissionLocales,enabled
 *
 *   - urlPath        (obligatorio) ruta de la editorial, ej. "ecorfan-libros". Solo [a-z0-9-_].
 *   - name           (obligatorio) nombre de la editorial.
 *   - acronym        (opcional) siglas / acrónimo.
 *   - description    (opcional) descripción breve.
 *   - primaryLocale  (opcional) ej. es_ES, en_US. Por defecto el del sitio.
 *   - supportedLocales           (opcional) idiomas de la UI. Locales separados por "|" (ej. "en_US|es_ES").
 *   - supportedFormLocales       (opcional) idiomas de Formularios. Subconjunto de supportedLocales.
 *   - supportedSubmissionLocales (opcional) idiomas de Envíos. Subconjunto de supportedLocales.
 *   - enabled        (opcional) 1 = habilitado (por defecto), 0 = deshabilitado.
 */

require(dirname(__FILE__, 5) . '/tools/bootstrap.inc.php');

class ImportPressesTool extends CommandLineTool {

	/** @var string */
	protected $username;

	/** @var string */
	protected $csvFile;

	public function __construct($argv = array()) {
		parent::__construct($argv);

		if (count($this->argv) < 2) {
			$this->usage();
			exit(1);
		}

		$this->username = array_shift($this->argv);
		$this->csvFile = array_shift($this->argv);
	}

	public function usage() {
		echo "Carga masiva de editoriales en OMP 3.3 desde un CSV.\n\n"
			. "Uso: {$this->scriptName} <adminUsername> <archivo.csv>\n\n"
			. "  adminUsername : usuario administrador del sitio que quedara como gestor.\n"
			. "  archivo.csv   : CSV con cabecera. Columnas:\n"
			. "                  urlPath,name,acronym,description,primaryLocale,supportedLocales,supportedFormLocales,supportedSubmissionLocales,enabled\n";
	}

	public function execute() {
		// 1) Validar que el usuario administrador existe y fijarlo como "usuario actual".
		//    Services::get('context')->add() usa $request->getUser() (que lee de Registry)
		//    para asignar el rol de Gestor (Press Manager) de la nueva editorial.
		$userDao = DAORegistry::getDAO('UserDAO'); /** @var UserDAO $userDao */
		$user = $userDao->getByUsername($this->username);
		if (!$user) {
			die("ERROR: no existe el usuario \"{$this->username}\".\n");
		}
		Registry::set('user', $user);

		if (!is_file($this->csvFile) || !is_readable($this->csvFile)) {
			die("ERROR: no se puede leer el archivo \"{$this->csvFile}\".\n");
		}

		$request = Application::get()->getRequest();
		$contextDao = Application::getContextDAO(); /** @var PressDAO $contextDao */
		$site = $request->getSite();
		$siteLocale = $site->getPrimaryLocale();

		// 2) Leer el CSV.
		$handle = fopen($this->csvFile, 'r');
		if ($handle === false) {
			die("ERROR: no se pudo abrir el CSV.\n");
		}

		$header = fgetcsv($handle);
		if ($header === false) {
			die("ERROR: el CSV esta vacio.\n");
		}
		// Normalizar cabeceras (quitar BOM y espacios).
		$header = array_map(function ($h) {
			return strtolower(trim(str_replace("\xEF\xBB\xBF", '', $h)));
		}, $header);

		if (!in_array('urlpath', $header) || !in_array('name', $header)) {
			die("ERROR: el CSV debe tener al menos las columnas 'urlPath' y 'name'.\n");
		}

		$created = 0;
		$skipped = 0;
		$line = 1;

		while (($row = fgetcsv($handle)) !== false) {
			$line++;
			if (count(array_filter($row, 'strlen')) === 0) {
				continue; // linea en blanco
			}

			$data = array_combine($header, array_pad($row, count($header), ''));

			$urlPath = trim($data['urlpath']);
			$name = trim($data['name']);
			$acronym = isset($data['acronym']) ? trim($data['acronym']) : '';
			$description = isset($data['description']) ? trim($data['description']) : '';
			$locale = !empty($data['primarylocale']) ? trim($data['primarylocale']) : $siteLocale;
			$enabled = !isset($data['enabled']) || $data['enabled'] === '' ? true : (bool) intval($data['enabled']);

			// Configuracion de idiomas (columnas opcionales; locales separados por
			// "|", "," o espacio). UI = supportedLocales, Forms/Submissions análogos.
			$supported = $this->parseLocaleList(isset($data['supportedlocales']) ? $data['supportedlocales'] : '');
			$formLocales = $this->parseLocaleList(isset($data['supportedformlocales']) ? $data['supportedformlocales'] : '');
			$submissionLocales = $this->parseLocaleList(isset($data['supportedsubmissionlocales']) ? $data['supportedsubmissionlocales'] : '');

			// Validaciones minimas.
			if ($urlPath === '' || $name === '') {
				echo "  [linea {$line}] OMITIDO: urlPath o name vacios.\n";
				$skipped++;
				continue;
			}
			if (!preg_match('/^[a-z0-9]+([\-_][a-z0-9]+)*$/i', $urlPath)) {
				echo "  [linea {$line}] OMITIDO: urlPath invalido \"{$urlPath}\" (solo letras, numeros, - y _).\n";
				$skipped++;
				continue;
			}
			if ($contextDao->getByPath($urlPath)) {
				echo "  [linea {$line}] OMITIDO: ya existe una editorial con path \"{$urlPath}\".\n";
				$skipped++;
				continue;
			}

			// 3) Construir la editorial y crearla con toda su inicializacion por defecto.
			$context = $contextDao->newDataObject();
			$context->setData('primaryLocale', $locale);
			$context->setData('supportedLocales', !empty($supported) ? $supported : $site->getSupportedLocales());
			if (!empty($formLocales)) {
				$context->setData('supportedFormLocales', $formLocales);
			}
			if (!empty($submissionLocales)) {
				$context->setData('supportedSubmissionLocales', $submissionLocales);
			}
			$context->setPath($urlPath);
			$context->setData('name', $name, $locale);
			$context->setData('urlPath', $urlPath);
			if ($acronym !== '') {
				$context->setData('acronym', $acronym, $locale);
			}
			if ($description !== '') {
				$context->setData('description', $description, $locale);
			}
			$context->setEnabled($enabled);

			try {
				$context = Services::get('context')->add($context, $request);
				echo "  [linea {$line}] CREADO: \"{$name}\" (path: {$urlPath}, id: {$context->getId()}).\n";
				$created++;
			} catch (Exception $e) {
				echo "  [linea {$line}] ERROR al crear \"{$name}\": " . $e->getMessage() . "\n";
				$skipped++;
			}
		}

		fclose($handle);

		echo "\nListo. Editoriales creadas: {$created}. Omitidos/errores: {$skipped}.\n";
	}

	/**
	 * Convierte una celda con locales separados por "|", "," o espacio en un
	 * arreglo de codigos unicos. Devuelve arreglo vacio si la celda esta vacia.
	 *
	 * @param string $value
	 * @return array
	 */
	protected function parseLocaleList($value) {
		$value = trim((string) $value);
		if ($value === '') {
			return array();
		}
		$parts = preg_split('/[|,\s]+/', $value, -1, PREG_SPLIT_NO_EMPTY);
		$out = array();
		foreach ($parts as $locale) {
			$locale = trim($locale);
			if ($locale !== '' && !in_array($locale, $out, true)) {
				$out[] = $locale;
			}
		}
		return $out;
	}
}

$tool = new ImportPressesTool(isset($argv) ? $argv : array());
$tool->execute();
