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
 *                  with params, exec time, stack trace and connection name
 *                  for HTTP request into Tracy's debug panel.
 */
class DbPanel implements \Tracy\IBarPanel {

	/**
	 * MvcCore Extension - Debug - Tracy - Session - version:
	 * Comparison by PHP function version_compare();
	 * @see http://php.net/manual/en/function.version-compare.php
	 */
	const VERSION = '5.3.2';
	
	/**
	 * Query type keywords to match.
	 * @var array
	 */
	protected static $queryTypesKeywords = [
		1	=> ' select ',
		2	=> ' insert ',
		4	=> ' update ',
		8	=> ' delete ',
		16	=> ' truncate ',
		32	=> ' create ',
		64	=> ' alter ',
		128	=> ' drop ',
	];

	/**
	 * Query type background colors.
	 * @var array
	 */
	protected static $queryTypesColors = [
		1	=> 'transparent',	// select
		2	=> '#cbffcb',		// insert
		4	=> '#ffe7a3',		// update
		8	=> '#ffcbd3',		// delete
		16	=> '#ffcbd3',		// truncate
		32	=> '#e6bfff',		// create
		64	=> '#f16183',		// alter
		128	=> '#bcceff',		// drop
	];

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
	protected $debugCode = '';
	
	/**
	 * Assets CSP nonce attribute for web debugging.
	 * @var string
	 */
	protected $nonceAttr = '';

	
	/**
	 * Get unique `Tracy` debug bar panel id.
	 * @return string
	 */
	public function getId () {
		return 'db-panel';
	}

	/**
	 * Return rendered debug panel heading HTML code displayed all time in `Tracy` debug  bar.
	 * @return string
	 */
	public function getTab () {
		$this->prepareQueriesData();
		ob_start();
		include(__DIR__ . '/db.tab.phtml');
		return ob_get_clean();
	}

	/**
	 * Return rendered debug panel content window HTML code.
	 * @return string
	 */
	public function getPanel () {
		$this->prepareQueriesData();
		if ($this->queriesCount === 0) return $this->debugCode;
		$tracyVersion = defined("\\Tracy\\Debugger::Version")
			? \Tracy\Debugger::Version
			: \Tracy\Debugger::VERSION;
		$nonce = version_compare($tracyVersion, '2.10.8', '>=')
			? \Tracy\Helpers::getNonceAttr()
			: \Tracy\Helpers::getNonce();
		$this->nonceAttr = $nonce ? ' nonce="' . \Tracy\Helpers::escapeHtml($nonce) . '"' : '';
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
		$dbDebugger = $this->prepareGetAttachedDebugger();
		if ($dbDebugger === NULL) return;
		$sysConfProps = \MvcCore\Model::GetSysConfigProperties();
		$store = & $dbDebugger->GetStore();
		$appRoot = \MvcCore\Application::GetInstance()->GetPathAppRoot();
		$appRootLen = mb_strlen($appRoot);
		$datetimeFormat = 'H:i:s.';
		foreach ($store as $item) {
			$connection = $item->connection;
			$connConfig = $connection->GetConfig();
			list(
				$dumpSuccess, $queryWithValues
			) = \MvcCore\Ext\Models\Db\Connection::DumpQueryWithParams(
				$connection->GetProvider(), $item->query, $item->params
			);
			$query = $dumpSuccess ? $queryWithValues : $item->query;
			$preparedStack = $this->prepareStackData($item->stack, $appRoot, $appRootLen);
			$execMsTimestamp = $item->resTime - $item->reqTime;
			$reqTimestampInt = intval(floor($item->reqTime));
			$resTimestampInt = intval(floor($item->resTime));
			$reqDateTimeStr = date($datetimeFormat, $reqTimestampInt);
			$resDateTimeStr = date($datetimeFormat, $resTimestampInt);
			$reqDatetimeMs = intval(round(($item->reqTime - floatval($reqTimestampInt)) * 1000000));
			$resDatetimeMs = intval(round(($item->resTime - floatval($resTimestampInt)) * 1000000));
			$this->queries[] = (object) [
				'query'		=> $this->prepareFormatedQuery($query),
				'type'		=> $this->prepareQueryType($query),
				'params'	=> $dumpSuccess ? NULL : $item->params,
				'reqTime'	=> $reqDateTimeStr . $reqDatetimeMs,
				'resTime'	=> $resDateTimeStr . $resDatetimeMs,
				'exec'		=> $execMsTimestamp,
				'execMili'	=> $execMsTimestamp * 1000,
				'stack'		=> $preparedStack,
				'connection'=> $connConfig->{$sysConfProps->name},
				'hash'		=> $this->hashQuery($item, $preparedStack),
			];
			$this->queriesTime += $execMsTimestamp;
		}
		$this->queriesCount = count($this->queries);
		$this->queriesTime = $this->queriesTime;
		$dbDebugger->Dispose();
	}

	/**
	 * Trim query and cut minimum whitespaces in each line.
	 * @param  string $rawQuery 
	 * @return string
	 */
	protected function prepareFormatedQuery ($rawQuery) {
		$queryLines = explode("\n", str_replace(["\r", "\n\n"], ["\n", "\n"], $rawQuery));
		$indents = [];
		$minIndent = PHP_INT_MAX;
		foreach ($queryLines as $index => $queryLine) {
			$queryLineTrimmed = trim($queryLine);
			if (mb_strlen($queryLineTrimmed) === 0) {
				unset($queryLines[$index]);
				continue;
			}
			$indent = preg_replace("#^([\t ]*).*#u", "$1", $queryLine);
			$tabIndent = str_replace("    ", "\t", $indent);
			$tabIndentLen = mb_strlen($tabIndent);
			$indents[$index] = [$tabIndentLen, mb_strlen($indent)];
			if ($tabIndentLen < $minIndent) $minIndent = $tabIndentLen;
		}
		if ($minIndent > 0) {
			foreach ($queryLines as $index => $queryLine) {
				list($tabIndent, $realIndent) = $indents[$index];
				if ($tabIndent === $realIndent) {
					$queryLines[$index] = mb_substr($queryLine, $minIndent);
				} else {
					$indentValue = str_replace("    ", "\t", mb_substr($queryLine, 0, $realIndent));
					$queryLines[$index] = mb_substr($indentValue, $minIndent) . mb_substr($queryLine, $realIndent);
				}
			}
		}
		return implode("\n", array_values($queryLines));
	}

	/**
	 * Return attached debugger singleton on any existing and opened connection.
	 * @return \MvcCore\Ext\Models\Db\Debugger|NULL
	 */
	protected function prepareGetAttachedDebugger () {
		$dbConfigs = \MvcCore\Model::GetConfigs();
		$extendedConnectionFound = FALSE;
		$dbDebugger = NULL;
		foreach ($dbConfigs as $connectionName => $dbConfig) {
			if (\MvcCore\Model::HasConnection($connectionName)) {
				$conn = \MvcCore\Model::GetConnection($connectionName);
				if ($conn instanceof \MvcCore\Ext\Models\Db\IConnection) {
					$extendedConnectionFound = TRUE;
					$debuggerLocal = $conn->GetDebugger();
					if ($debuggerLocal !== NULL) {
						$dbDebugger = $debuggerLocal;
						if (count($debuggerLocal->GetStore()) > 0)
							break;
					}
				}
			}
		}
		if ($dbDebugger === NULL) {
			$this->debugCode = !$extendedConnectionFound
				? "No database connection found, which implements interface `\MvcCore\Ext\Models\Db\IConnection`."
				: "No configured debugger found on any database connection.";
			return NULL;
		}
		return $dbDebugger;
	}

	/**
	 * Prepare query type by matched keywords.
	 * @param  string $query 
	 * @return int
	 */
	protected function prepareQueryType ($query) {
		$queryType = 0;
		$queryWithSingleSpace = ' '.preg_replace("#\s+#", ' ', str_replace(';', ' ; ', $query)).' ';
		foreach (static::$queryTypesKeywords as $queryTypeFlag => $queryTypeKeyword) 
			if (stripos($queryWithSingleSpace, $queryTypeKeyword) !== FALSE) 
				$queryType |= $queryTypeFlag;
		return $queryType;
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
	protected function debug ($var) {
		$this->debugCode .= \Tracy\Dumper::toHtml($var, [
			\Tracy\Dumper::LIVE		=> FALSE,
			//\Tracy\Dumper::DEPTH	=> 5,
		]);
	}
}
