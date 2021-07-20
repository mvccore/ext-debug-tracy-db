<?php

/**
 * MvcCore
 *
 * This source file is subject to the BSD 3 License
 * For the full copyright and license information, please view
 * the LICENSE.md file that are distributed with this source code.
 *
 * @copyright	Copyright (c) 2016 Tom Flidr (https://github.com/mvccore)
 * @license		https://mvccore.github.io/docs/mvccore/5.0.0/LICENSE.md
 */

namespace MvcCore\Ext\Debugs\Tracys;

/**
 * Responsibility - dump all executed SQL queries on configured connection(s) 
 *                  with params, exec times and stack traces.
 */
class DbPanel implements \Tracy\IBarPanel {

	/**
	 * MvcCore Extension - Debug - Tracy - Session - version:
	 * Comparison by PHP function version_compare();
	 * @see http://php.net/manual/en/function.version-compare.php
	 */
	const VERSION = '5.0.0';

	/**
	 * Rendered queires for template.
	 * @var array|NULL
	 */
	protected $queries = NULL;

	/**
	 * Get unique `Tracy` debug bar panel id.
	 * @return string
	 */
	public function getId() {
		return 'db-panel';
	}

	/**
	 * Return rendered debug panel heading HTML code displayed all time in `Tracy` debug  bar.
	 * @return string
	 */
	public function getTab() {
		$this->prepareQueriesData();
		ob_start();
		include(__DIR__ . '/db.tab.phtml');
		return ob_get_clean();
	}

	/**
	 * Return rendered debug panel content window HTML code.
	 * @return string
	 */
	public function getPanel() {
		$this->prepareQueriesData();
		if (count($this->queries) === 0) return '';
		ob_start();
		include(__DIR__ . '/db.panel.phtml');
		return ob_get_clean();
	}

	/**
	 * 
	 * @return void
	 */
	protected function prepareQueriesData () {
		if ($this->queries !== NULL) return;
		$this->queries = [];
		$sysConfProps = \MvcCore\Model::GetSysConfigProperties();
		$dbDebugger = \MvcCore\Ext\Models\Db\Debugger::GetInstance();
		$store = & $dbDebugger->GetStore();
		foreach ($store as $item) {
			$connection = $item->connection;
			$connConfig = $connection->GetConfig();
			list(
				$dumpSuccess, $queryWithValues
			) = \MvcCore\Ext\Models\Db\Connection::DumpQueryWithParams($connection->GetProvider(), $item->query, $item->params);
			$this->queries[] = (object) [
				'query'		=> $dumpSuccess ? $queryWithValues : $item->query,
				'params'	=> $dumpSuccess ? $item->params : NULL,
				'exec'		=> $item->exec,
				'stack'		=> $item->stack,
				'connection'=> $connConfig->{$sysConfProps->name},
			];
		}
		$dbDebugger->Dispose();
	}
}
