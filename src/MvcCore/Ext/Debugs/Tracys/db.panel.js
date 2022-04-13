var Tracy = window.Tracy || {};
Tracy.DebugDbPanel = (function () {
	var DebugDbPanel = function (contentPanelId) {
		var content = document.getElementById(contentPanelId);
		this.tbody = content.querySelector('table tbody');
		this.orderAnchors = [].slice.apply(document.querySelectorAll('.tracy-panel-db .content table thead a'));
		this.sessionStorageKeyBase = 'tracy-toggles-tracy-debug-panel-db-panel-r';
		this.rowsData = null;
		this.codeTextAreas = {};
		this.orderMaps = {
			order: [],
			exec: [],
			conn: []
		};
		this.lastClickedElm = null;
		this.timeoutId = 0;
		this.init();
	}
	DebugDbPanel.prototype = {
		init: function () {
			this.tbody.addEventListener('click', this.handleTbodyClick.bind(this));
			this.orderAnchors.forEach(function (orderAnchor) {
				orderAnchor.addEventListener('click', this.handleOrderClick.bind(this));
			}.bind(this));
			var sessionStorageKeys = Object.keys(window.sessionStorage),
				sessionStorageKeyBaseLen = this.sessionStorageKeyBase.length,
				sessionStorageKey = '',
				rowHash = '';
			for (var i = 0, l = sessionStorageKeys.length; i < l; i++) {
				sessionStorageKey = sessionStorageKeys[i];
				if (sessionStorageKey.indexOf(this.sessionStorageKeyBase) !== 0)
					continue;
				if (window.sessionStorage[sessionStorageKey] !== '1')
					continue;
				rowHash = sessionStorageKey.substr(sessionStorageKeyBaseLen).trim();
				if (rowHash.length !== 32) continue;
				var rowToOpen = this.tbody.querySelector("tr[data-hash='" + rowHash + "']");
				if (rowToOpen) {
					rowToOpen.className = rowToOpen.className + ' opened';
					this.initializeRow(rowToOpen, rowHash);
				}
			}
		},
		handleTbodyClick: function (e) {
			var rowElm = this.getQueryRowFromTarget(e);
			if (rowElm == null) return;
			if (e.target === this.lastClickedElm) {
				clearTimeout(this.timeoutId);
				this.lastClickedElm = null;
				this.handleTbodyDoubleClick(rowElm);
			} else {
				this.lastClickedElm = e.target;
				this.timeoutId = setTimeout(function () {
					clearTimeout(this.timeoutId);
					this.lastClickedElm = null;
				}.bind(this), 500);
			}
		},
		getQueryRowFromTarget: function (e) {
			var currentElm = e.target,
				rowElm = null;
			while (currentElm.parentNode != null) {
				if (this.isQueryRow(currentElm)) {
					rowElm = currentElm;
					break;
				}
				currentElm = currentElm.parentNode;
			}
			return rowElm;
		},
		isQueryRow: function (elm) {
			return elm.nodeName.toLowerCase() == 'tr' && elm.className.indexOf('query-row') != -1;
		},
		handleTbodyDoubleClick: function (rowElm) {
			var rowElmCls = rowElm.className,
				rowHash = rowElm.dataset.hash,
				codeArea = this.codeTextAreas[rowHash] == null
					? this.codeTextAreas[rowHash] = this.initializeRow(rowElm, rowHash)
					: this.codeTextAreas[rowHash];
			if (rowElmCls.indexOf('opened') == -1) {
				rowElm.className = rowElmCls + ' opened';
				this.storeRowState(rowHash, 1);
			} else {
				rowElm.className = rowElmCls.replace(/^(.*)(\s*opened\s*)(.*)/g, '$1$3');
				this.storeRowState(rowHash, 0);
			}
			setTimeout(function () {
				codeArea.focus();
				codeArea.setSelectionRange(0, codeArea.innerHTML.length, 'forward');
			}.bind(this), 1);
		},
		initializeRow: function (rowElm, rowHash) {
			var codeElm = rowElm.querySelector('td.query code'),
				textArea = document.createElement('textarea'),
				rawLines = codeElm.innerHTML.replace(/\r\n/g, "\n").split("\n");
			for (var i = 0, l = rawLines.length; i < l; i++)
				rawLines[i] = rawLines[i].trimEnd();
			textArea.innerHTML = rawLines.join("\n").trim();
			return codeElm.parentNode.insertBefore(textArea, codeElm);
		},
		storeRowState: function (rowHash, state) {
			window.sessionStorage[this.sessionStorageKeyBase + rowHash] = state;
		},
		handleOrderClick: function (e) {
			if (this.rowsData == null) this.completeRowsData();
			var anchor = e.target,
				orderMapKey = anchor.dataset.map,
				dir = parseInt(anchor.dataset.dir, 10),
				orderMap = this.orderMaps[orderMapKey];
			this.orderAnchors.forEach(function (orderAnchor) {
				if (orderAnchor === anchor) return;
				orderAnchor.className = '';
				orderAnchor.setAttribute('data-dir', '0');
			}.bind(this));
			if (dir === 0) {
				dir = 1;
				anchor.className = 'asc';
			} else if (dir === 1) {
				dir = -1;
				anchor.className = 'desc';
			} else {
				dir = 0;
				anchor.className = '';
			}
			anchor.setAttribute('data-dir', dir);
			if (dir === 0) {
				orderMap = this.orderMaps.order;
				dir = 1;
			}
			var rowsCodes = [],
				rowHash = '',
				row = null,
				index = 0;
			if (dir === 1) {
				for (var i = 0, l = orderMap.length; i < l; i++) {
					index = orderMap[i].index;
					rowHash = this.rowsData[index].rowElm.dataset.hash;
					row = this.tbody.querySelector("tr[data-hash='" + rowHash + "']");
					rowsCodes.push(row.outerHTML);
				}
			} else {
				for (var i = orderMap.length - 1; i >= 0; i--) {
					index = orderMap[i].index;
					rowHash = this.rowsData[index].rowElm.dataset.hash;
					row = this.tbody.querySelector("tr[data-hash='" + rowHash + "']");
					rowsCodes.push(row.outerHTML);
				}
			}
			this.tbody.innerHTML = rowsCodes.join('');
		},
		completeRowsData: function () {
			var tbodyRows = [].slice.apply(this.tbody.querySelectorAll('tr.query-row'));
			this.rowsData = [];
			tbodyRows.forEach(function (tbodyRow, index) {
				var row = JSON.parse(tbodyRow.dataset.row);
				row.index = index;
				row.rowElm = tbodyRow;
				this.rowsData.push(row);
				this.orderMaps.order.push({ index: index })
				this.orderMaps.exec.push({ exec: row.exec, index: index });
				this.orderMaps.conn.push({ conn: row.conn, index: index });
			}.bind(this));
			this.orderMaps.exec.sort(function (a, b) {
				if (a.exec > b.exec) return 1;
				if (a.exec < b.exec) return -1;
				return a.index > b.index ? 1 : -1;
			});
			this.orderMaps.conn.sort(function (a, b) {
				var compared = b.conn.localeCompare(a.conn);
				if (compared !== 0) return compared;
				return a.index > b.index ? 1 : -1;
			});
		}
	};
	return DebugDbPanel;
})();