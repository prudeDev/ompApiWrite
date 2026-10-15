<?php

/**
 * @defgroup plugins_generic_ompApiWrite Books API Write Plugin
 */

/**
 * @file plugins/generic/ompApiWrite/index.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @ingroup plugins_generic_ompApiWrite
 * @brief Wrapper for the Books API Write plugin.
 */
require_once('OmpApiWritePlugin.inc.php');

return new OmpApiWritePlugin();
