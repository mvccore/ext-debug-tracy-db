# MvcCore - Extension - Debug - Nette Tracy - Panel Database

[![Latest Stable Version](https://img.shields.io/badge/Stable-v5.3.4-brightgreen.svg?style=plastic)](https://github.com/mvccore/ext-debug-tracy-db/releases)
[![License](https://img.shields.io/badge/License-BSD%203-brightgreen.svg?style=plastic)](https://mvccore.github.io/docs/mvccore/5.0.0/LICENSE.md)
![PHP Version](https://img.shields.io/badge/PHP->=5.4-brightgreen.svg?style=plastic)

MvcCore Debug Tracy Extension to render queries with params and execution times.

## Installation
```shell
composer require mvccore/ext-debug-tracy-db
```

## Configuration

You have to configure installed debugger class into your database connection like this:
```ini
[db]
defaultName		= main

main.driver		= mysql
main.host		= 127.0.0.1
main.database	= cdcol
main.user		= cdcol
main.password	= "********"
; database debugger for connection main:
main.debugger	= \MvcCore\Ext\Models\Db\Debugger
```

Or you can configure debugger class for all database connections like this:
```ini
[db]
defaultName		= main

; database debugger for all connctions:
defaultDebugger	= \MvcCore\Ext\Models\Db\Debugger

main.driver		= mysql
main.host		= 127.0.0.1
main.database	= cdcol
main.user		= cdcol
main.password	= "********"
```