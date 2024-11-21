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

namespace MvcCore\Ext\Debugs\Tracys\DbDebugger;

/**
 * Responsibility - dump all executed SQL queries on configured connection(s) 
 *                  with params, exec time, stack trace and connection name
 *                  into separate file for each HTTP or CLI request.
 */
class		FileWriter
extends		\MvcCore\Ext\Debugs\Tracys\DbPanel
implements	\MvcCore\Ext\Models\Db\IDebugger {
	
	use \MvcCore\Ext\Models\Db\Debugger\Props;
	use \MvcCore\Ext\Models\Db\Debugger\Methods {
		\MvcCore\Ext\Models\Db\Debugger\Methods::GetInstance as GetInstanceParent;
	}

	const LOG_FILES_BASE_NAME = 'db.queries.';

	/**
	 * @inheritDoc
	 * @return \MvcCore\Ext\Debugs\Tracys\DbDebugger\FileWriter
	 */
	public static function GetInstance () {
		$initialization = self::$instance === NULL;
		/** @var \MvcCore\Ext\Debugs\Tracys\DbDebugger\FileWriter $instance */
		$instance = self::GetInstanceParent();
		if ($initialization) {
			$instance::$baseNamespaces[] = 'MvcCore\\Ext\\Models\\Db';
			$app = \MvcCore\Application::GetInstance();
			$app->AddPostTerminateHandler(function($req)use($instance, $app){
				$instance->ShutdownHandler($app, $req);
			});
		}
		return $instance;
	}

	/**
	 * Complete debugger queries and write it into logs directory.
	 * @param \MvcCore\IApplication $app 
	 * @param \MvcCore\IRequest $req 
	 */
	public function ShutdownHandler (\MvcCore\IApplication $app, \MvcCore\IRequest $req) {
		$this->prepareQueriesData();
		if ($this->queriesCount === 0) return;
		$this->debug($req);
		
		$debugClass = $app->GetDebugClass();
		$appRoot = $app->GetPathAppRoot();
		$logsDirAbsPath = $app->GetPathLogs(TRUE);
		$tracySrcPath = $this->prepareTracySrcPath($logsDirAbsPath, $appRoot);

		list($htmlBegin, $htmlEnd) = $this->prepareHtmlCode($tracySrcPath);

		ob_start();
		include(__DIR__ . '/../db.panel.phtml');
		$code = $htmlBegin . ob_get_clean() . $htmlEnd;
		
		$reqStart = $req->GetStartTime();
		$fileName = self::LOG_FILES_BASE_NAME . number_format($reqStart, 6, '.', '') . '.html';
		$fullPath = $logsDirAbsPath . '/' . $fileName;
		if (file_exists($fullPath)) unlink($fullPath);

		$toolClass = $app->GetToolClass();
		$toolClass::AtomicWrite($fullPath, $code, 'w+');
	}

	/**
	 * Prepare result file html head and foot.
	 * @param  string $tracySrcPath 
	 * @return \string[]
	 */
	protected function prepareHtmlCode ($tracySrcPath) {
		$assetsCode = [];
		$cssFiles = [
			'/assets/BlueScreen/bluescreen.css',
			'/assets/Bar/bar.css',
			'/assets/Toggle/toggle.css',
			'/assets/Dumper/dumper.css',
		];
		$jsFiles = [
			'/assets/Toggle/toggle.js',
			'/assets/Dumper/dumper.js',
		];

		foreach ($cssFiles as $cssFile)
			$assetsCode[] = '<link rel="stylesheet" type="text/css" href="' . $tracySrcPath . $cssFile . '" />';
		$assetsCode[] = '<style type="text/css">#tracy-debug{display:block !important;}</style>';
		
		foreach ($jsFiles as $jsFile)
			$assetsCode[] = '<script type="text/javascript" src="' . $tracySrcPath . $jsFile . '"></script>';
		$assetsCode[] = '<script type="text/javascript">Tracy && Tracy.Dumper.init();</script>';
		
		$htmlBegin = '<html id="tracy-bs"><head>'
			.implode("\n", $assetsCode)
			.'<script>document.documentElement.className+=" tracy-js";</script>'
			.'</head><body id="tracy-debug"><div class="tracy-mode-window">';
		$htmlEnd = '</div></body></html>';

		return [$htmlBegin, $htmlEnd];
	}

	/**
	 * Try to complete relative tracy library sources path from logs directory.
	 * @param  string $logsDirAbsPath 
	 * @param  string $appRoot 
	 * @return string
	 */
	protected function prepareTracySrcPath ($logsDirAbsPath, $appRoot) {
		$tracyCollapsePaths = \Tracy\Debugger::getBlueScreen()->collapsePaths;
		$tracySrcAbsPath = isset($tracyCollapsePaths[0]) 
			? str_replace('\\', '/', $tracyCollapsePaths[0]) 
			: $appRoot . '/vendor/tracy/tracy/src/Tracy';
		$logsDirAbsPathLen = mb_strlen($logsDirAbsPath);
		$offset = $logsDirAbsPathLen;
		$parentDirsCount = 0;
		$tracySrcPath = $tracySrcAbsPath;
		while (TRUE) {
			$lastSlashPos = mb_strrpos(mb_substr($logsDirAbsPath, 0, $offset), '/');
			if ($lastSlashPos === FALSE) break;
			$logsDirPartPath = mb_substr($logsDirAbsPath, 0, $lastSlashPos);
			if ($logsDirPartPath === '') break;
			$parentDirsCount++;
			if (mb_strrpos($tracySrcAbsPath, $logsDirPartPath) !== FALSE) {
				$tracySrcPath = str_pad('', $parentDirsCount * 3, '../')
					. mb_substr($tracySrcAbsPath, mb_strlen($logsDirPartPath) + 1);
				break;
			} else {
				$offset = $lastSlashPos;
			}
		}
		return $tracySrcPath;
	}
}