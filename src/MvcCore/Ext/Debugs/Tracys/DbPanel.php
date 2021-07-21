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
 *                  with params, exec time, stack trace and connection name.
 */
class DbPanel implements \Tracy\IBarPanel {

	/**
	 * MvcCore Extension - Debug - Tracy - Session - version:
	 * Comparison by PHP function version_compare();
	 * @see http://php.net/manual/en/function.version-compare.php
	 */
	const VERSION = '5.0.0';

	/**
	 * Unique panel id.
	 * @var string|NULL
	 */
	protected $panelId = NULL;

	/**
	 * Rendered queires for template.
	 * @var array|NULL
	 */
	protected $queries = NULL;

	/**
	 * Executed queries count.
	 * @var int
	 */
	protected $queriesCount = 0;

	/**
	 * Executed queries total time.
	 * @var float
	 */
	protected $queriesTime = 0.0;
	
	/**
	 * Debug code for this panel, printed at panel bottom.
	 * @var string
	 */
	private $_debugCode = '';

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
		if ($this->queriesCount === 0) return '';
		ob_start();
		include(__DIR__ . '/db.panel.phtml');
		return ob_get_clean();
	}

	/**
	 * Prepare view data for rendering.
	 * @return void
	 */
	protected function prepareQueriesData () {
		if ($this->queries !== NULL) return;
		$this->queries = [];
		$this->panelId = number_format(microtime(TRUE), 6, '', '');
		$sysConfProps = \MvcCore\Model::GetSysConfigProperties();
		$dbDebugger = \MvcCore\Ext\Models\Db\Debugger::GetInstance();
		$store = & $dbDebugger->GetStore();
		$appRoot = \MvcCore\Application::GetInstance()->GetRequest()->GetAppRoot();
		$appRootLen = mb_strlen($appRoot);
		foreach ($store as $item) {
			$connection = $item->connection;
			$connConfig = $connection->GetConfig();
			list(
				$dumpSuccess, $queryWithValues
			) = \MvcCore\Ext\Models\Db\Connection::DumpQueryWithParams(
				$connection->GetProvider(), $item->query, $item->params
			);
			$preparedStack = $this->prepareStackData($item->stack, $appRoot, $appRootLen);
			$this->queries[] = (object) [
				'query'		=> $dumpSuccess ? $queryWithValues : $item->query,
				'params'	=> $dumpSuccess ? $item->params : NULL,
				'exec'		=> $item->exec,
				'execMili'	=> $item->exec * 1000,
				'stack'		=> $preparedStack,
				'connection'=> $connConfig->{$sysConfProps->name},
				'hash'		=> $this->hashQuery($item, $preparedStack),
			];
			$this->queriesTime += $item->exec;
		}
		$this->queriesCount = count($this->queries);
		$this->queriesTime = $this->queriesTime;
		$dbDebugger->Dispose();
	}

	/**
	 * Prepare code for stack trace rendering.
	 * @param  array  $stack 
	 * @param  string $appRoot 
	 * @param  int    $appRootLen 
	 * @return \string[][]
	 */
	protected function prepareStackData (array $stack, $appRoot, $appRootLen) {
		$result = [];
		foreach ($stack as $stackItem) {
			$file = NULL;
			$line = NULL;
			$class = NULL;
			$func = NULL;
			$callType = '';
			if (isset($stackItem['file']))
				$file = str_replace('\\', '/', $stackItem['file']);
			if (isset($stackItem['line']))
				$line = $stackItem['line'];
			if (isset($stackItem['class']))
				$class = $stackItem['class'];
			if (isset($stackItem['function']))
				$func = $stackItem['function'];
			if (isset($stackItem['type']))
				$callType = str_replace('-', '&#8209;', $stackItem['type']);
			$callType = '::';
			if ($func !== NULL && $file !== NULL && $line !== NULL) {
				$visibleFilePath = $this->getVisibleFilePath($file, $appRoot, $appRootLen);
				$phpCode = $class !== NULL
					? $class . $callType . $func . '();'
					: $func . '();';
				$link = \Tracy\Helpers::editorUri($file, $line);
				$result[] = [
					'<a title="'.$file.':'.$line.'" href="'.$link.'">'.$visibleFilePath.':'.$line.'</a>',
					$phpCode
				];
			} else {
				$result[] = [
					NULL,
					$class !== NULL
						? $class . $callType . $func . '();'
						: $func . '();'
				];
			}
		}
		return $result;
	}

	/**
	 * Return file path to render in link text.
	 * If there is found application root in path, 
	 * return only path after it, if not, return 
	 * three dots, two parent folders and filename.
	 * @param  string $file 
	 * @param  string $appRoot 
	 * @param  int    $appRootLen 
	 * @return string
	 */
	protected function getVisibleFilePath ($file, $appRoot, $appRootLen) {
		$result = $file;
		if (mb_strpos($file, $appRoot) === 0) {
			$result = mb_substr($file, $appRootLen);
		} else {
			$i = 0;
			$pos = mb_strlen($file) + 1;
			while ($i < 3) {
				$pos = mb_strrpos(mb_substr($file, 0, $pos - 1), '/');
				if ($pos === FALSE) break; 
				$i++;
			}
			if ($pos === FALSE) {
				$result = $file;
			} else {
				$result = '&hellip;'.mb_substr($file, $pos);
			}
		}
		return $result;
	}

	/**
	 * Create unique query MD5 hash.
	 * @param  \stdClass   $item 
	 * @param  \string[][] $preparedStack 
	 * @return string
	 */
	protected function hashQuery ($item, $preparedStack) {
		return md5(implode('', [
			$item->query,
			serialize($item->params),
			serialize($preparedStack)
		]));
	}

	/**
	 * Print any variable in panel body under database queries.
	 * @param  mixed $var
	 * @return void
	 */
	private function _debug ($var) {
		$this->_debugCode .= \Tracy\Dumper::toHtml($var, [
			\Tracy\Dumper::LIVE		=> TRUE,
			//\Tracy\Dumper::DEPTH	=> 5,
		]);
	}
}
