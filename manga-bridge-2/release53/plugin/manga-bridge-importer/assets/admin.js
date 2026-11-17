(function () {
	'use strict';

	var root = document.querySelector('[data-mbi-progress]');
	var updateRoot = document.querySelector('[data-mbi-update-progress]');
	var scanRoot = document.querySelector('[data-mbi-update-scan]');
	var updatesRoot = document.querySelector('[data-mbi-updates-list]');
	var scanStartButton = document.querySelector('[data-mbi-scan-start]');
	if ((!root && !updateRoot && !scanRoot) || !window.MBIProgress) {
		return;
	}

	var timer = null;
	var interval = 10000;
	var workerBusy = false;
	var workerNotBefore = 0;
	var shouldAccelerate = !!window.MBIProgress.autoAccelerate;
	var manualWasActive = !!window.MBIProgress.manualActive && !!updateRoot;
	var updatesRevision = 0;
	var syncedUpdatesRevision = 0;
	var manualPreparing = !!window.MBIProgress.manualPreparing && !!updateRoot;
	var manualPrepareBusy = false;
	var cleanupBusy = false;
	var cleanupActive = !!window.MBIProgress.cleanupActive;
	var scanBusy = false;
	var scanActive = !!window.MBIProgress.scanActive && !!scanRoot;
	var watchProgress = true;

	function setText(selector, value) {
		var element = document.querySelector(selector);
		if (element) {
			element.textContent = String(value);
		}
	}

	function render(data) {
		if (!data) { return; }
		watchProgress = !!data.watch;
		if (!root) { return; }
		shouldAccelerate = !!data.accelerate;
		workerNotBefore = Date.now() + Math.max(0, Number(data.worker_wait) || 0) * 1000;
		var bar = root.querySelector('[data-progress-bar]');
		var track = root.querySelector('[role="progressbar"]');
		root.className = root.className.replace(/\bmbi-state-\S+/g, '').trim() + ' mbi-state-' + data.state;
		if (bar) {
			bar.style.width = data.percent + '%';
		}
		if (track) {
			track.setAttribute('aria-valuenow', data.percent);
		}
		setText('[data-progress-status]', data.status);
		setText('[data-progress-title]', data.title);
		setText('[data-progress-percent]', data.percent + '%');
		setText('[data-progress-message]', data.message);
		setText('[data-progress-order]', data.order);
		setText('[data-progress-order-label]', data.order_label);
		setText('[data-progress-skipped]', data.skipped);
		setText('[data-progress-catalog]', data.catalog_total);
		setText('[data-progress-chapters]', data.chapters);
		setText('[data-progress-images]', data.images);
		setText('[data-progress-queue]', data.queue);
		setText('[data-progress-activity]', data.activity || 'Aguardando');
		setText('[data-progress-heartbeat]', data.heartbeat || '—');
		setText('[data-progress-load]', data.load || '');
		var pipeline = root.querySelector('[data-pipeline]');
		if (pipeline) {
			pipeline.hidden = !data.pipeline || !data.pipeline.total;
			if (!pipeline.hidden) {
				setText('[data-progress-pending-label]', 'Candidatas recusadas / em revisão');
				var resume = pipeline.querySelector('[data-resume-quantity52]');
				if (resume) { resume.hidden = !data.pipeline.can_resume; }
				Object.keys(data.pipeline.bars).forEach(function (key) {
					pipeline.querySelector('[data-pipeline-bar="' + key + '"]').value = data.pipeline.bars[key];
					setText('[data-pipeline-count="' + key + '"]', data.pipeline.counts[key]);
				});
				var rows = pipeline.querySelector('[data-pipeline-rows]');
				rows.textContent = '';
				data.pipeline.rows.forEach(function (item) {
					var row = document.createElement('tr');
					[item.title, item.status, item.detail].forEach(function (value) { var cell = document.createElement('td'); cell.textContent = value; row.appendChild(cell); });
					rows.appendChild(row);
				});
			}
		}
		Object.keys(data.counts || {}).forEach(function (key) {
			setText('[data-queue-' + key + ']', data.counts[key]);
		});
	}

	function renderManual(data) {
		if (!updateRoot || !data) {
			return;
		}
		shouldAccelerate = (root && shouldAccelerate) || (!!data.active && !data.paused);
		manualPreparing = !!data.preparing && !data.paused;
		workerNotBefore = Date.now() + Math.max(0, Number(data.worker_wait) || 0) * 1000;
		var bar = updateRoot.querySelector('[data-update-bar]');
		var track = updateRoot.querySelector('[role="progressbar"]');
		updateRoot.className = updateRoot.className.replace(/\bmbi-state-\S+/g, '').trim() + ' mbi-state-' + data.state;
		if (bar) { bar.style.width = data.percent + '%'; }
		if (track) { track.setAttribute('aria-valuenow', data.percent); }
		setText('[data-update-status]', data.status);
		setText('[data-update-title]', data.title);
		setText('[data-update-percent]', data.percent + '%');
		setText('[data-update-message]', data.message);
		setText('[data-update-total]', data.total);
		setText('[data-update-completed]', data.completed);
		setText('[data-update-failed]', data.failed);
		setText('[data-update-pending]', data.pending);
		setText('[data-update-manga]', data.manga || '—');
		setText('[data-update-chapter]', data.chapter || '—');
		if (manualWasActive && !data.active) {
			// Atualiza a lista no próximo poll sem navegar ou reiniciar a página.
			updatesRevision++;
		}
		manualWasActive = !!data.active;
	}

	function appendHidden(form, name, value) {
		var input = document.createElement('input');
		input.type = 'hidden';
		input.name = name;
		input.value = String(value);
		form.appendChild(input);
	}

	function actionForm(label, fields, buttonClass) {
		var form = document.createElement('form');
		form.method = 'post';
		form.action = window.MBIProgress.adminPostUrl;
		appendHidden(form, '_wpnonce', window.MBIProgress.actionNonce);
		Object.keys(fields).forEach(function (key) { appendHidden(form, key, fields[key]); });
		var button = document.createElement('button');
		button.type = 'submit';
		button.className = buttonClass || 'button button-secondary';
		button.textContent = label;
		form.appendChild(button);
		return form;
	}

	function scanStatus(item) {
		var count = Number(item.new_count || 0);
		var labels = {
			pending: 'Aguardando',
			processing: 'Verificando agora',
			clean: 'Sem novos capítulos',
			has_updates: count + ' novo(s) encontrado(s)',
			partial: 'LISTA PARCIAL — conferência limitada',
			partial_updates: count + ' novo(s) em lista parcial',
			error: 'Falha na verificação'
		};
		return labels[item.status] || 'Aguardando';
	}

	function chapterNumbers(item) {
		var numbers = Array.isArray(item.chapter_numbers) ? item.chapter_numbers : [];
		if (!numbers.length) { return '—'; }
		var visible = numbers.slice(0, 12);
		return 'Cap. ' + visible.join(', ') + (numbers.length > visible.length ? ' e mais ' + (numbers.length - visible.length) : '');
	}

	function renderScanRows(items) {
		if (!scanRoot) { return; }
		var body = scanRoot.querySelector('[data-scan-results]');
		if (!body) { return; }
		body.textContent = '';
		if (!items || !items.length) {
			var emptyRow = document.createElement('tr');
			var emptyCell = document.createElement('td');
			emptyCell.colSpan = 5;
			emptyCell.textContent = 'A lista aparecerá aqui quando a verificação começar.';
			emptyRow.appendChild(emptyCell);
			body.appendChild(emptyRow);
			return;
		}
		items.forEach(function (item, index) {
			var row = document.createElement('tr');
			row.setAttribute('data-scan-manga-id', String(item.manga_id || 0));
			var order = document.createElement('td');
			order.textContent = String(index + 1);
			var manga = document.createElement('td');
			var strong = document.createElement('strong');
			strong.textContent = item.manga_name || 'Mangá sem título';
			manga.appendChild(strong);
			var status = document.createElement('td');
			var badge = document.createElement('span');
			badge.className = 'mbi-badge mbi-scan-' + (item.status || 'pending');
			badge.textContent = scanStatus(item);
			status.appendChild(badge);
			if (item.note) {
				var note = document.createElement('small');
				note.className = 'mbi-scan-note';
				note.textContent = item.note;
				status.appendChild(note);
			}
			if (item.error) {
				var error = document.createElement('small');
				error.className = 'mbi-scan-error';
				error.textContent = item.error;
				status.appendChild(error);
			}
			var chapters = document.createElement('td');
			chapters.textContent = chapterNumbers(item);
			var time = document.createElement('td');
			time.textContent = item.checked_at || '—';
			row.appendChild(order);
			row.appendChild(manga);
			row.appendChild(status);
			row.appendChild(chapters);
			row.appendChild(time);
			body.appendChild(row);
		});
		var processing = body.querySelector('.mbi-scan-processing');
		if (processing) {
			processing.closest('tr').scrollIntoView({block: 'nearest', behavior: 'smooth'});
		}
	}

	function renderDetectedUpdates(rows) {
		if (!updatesRoot) { return; }
		updatesRoot.textContent = '';
		var applyAll = document.querySelector('[data-mbi-apply-all]');
		if (!rows || !rows.length) {
			var empty = document.createElement('p');
			empty.textContent = 'Nenhum capítulo novo detectado até agora.';
			updatesRoot.appendChild(empty);
			if (applyAll) { applyAll.hidden = true; }
			return;
		}
		if (applyAll) { applyAll.hidden = false; }
		var groups = {};
		var order = [];
		rows.forEach(function (item) {
			var key = String(item.manga_id || 0);
			if (!groups[key]) { groups[key] = []; order.push(key); }
			groups[key].push(item);
		});
		order.forEach(function (mangaId) {
			var items = groups[mangaId];
			var section = document.createElement('section');
			section.className = 'mbi-update-group';
			var header = document.createElement('header');
			var info = document.createElement('div');
			var name = document.createElement('strong');
			name.textContent = items[0].manga_name || 'Mangá';
			var count = document.createElement('span');
			count.textContent = items.length + ' capítulo(s) novo(s)';
			info.appendChild(name);
			info.appendChild(count);
			header.appendChild(info);
			header.appendChild(actionForm('Atualizar este mangá', {action: 'mbi_apply_updates', manga_id: mangaId}, 'button button-primary'));
			section.appendChild(header);
			var scroll = document.createElement('div');
			scroll.className = 'mbi-table-scroll';
			var table = document.createElement('table');
			table.className = 'widefat striped';
			var thead = document.createElement('thead');
			var headRow = document.createElement('tr');
			['Ordem', 'Capítulo', 'Detectado em', 'Ação'].forEach(function (label) {
				var th = document.createElement('th'); th.textContent = label; headRow.appendChild(th);
			});
			thead.appendChild(headRow);
			table.appendChild(thead);
			var tbody = document.createElement('tbody');
			items.forEach(function (item, index) {
				var row = document.createElement('tr');
				var position = document.createElement('td'); position.textContent = String(index + 1);
				var chapter = document.createElement('td');
				var chapterName = document.createElement('strong'); chapterName.textContent = 'Capítulo ' + (item.chapter_number || 'novo');
				var source = document.createElement('small'); source.className = 'mbi-url'; source.textContent = item.chapter_url || '';
				chapter.appendChild(chapterName); chapter.appendChild(document.createElement('br')); chapter.appendChild(source);
				var detected = document.createElement('td'); detected.textContent = item.detected_at || '';
				var action = document.createElement('td');
				action.appendChild(actionForm('Atualizar novo capítulo', {action: 'mbi_apply_updates', update_key: item.key || ''}, 'button button-secondary'));
				row.appendChild(position); row.appendChild(chapter); row.appendChild(detected); row.appendChild(action);
				tbody.appendChild(row);
			});
			table.appendChild(tbody); scroll.appendChild(table); section.appendChild(scroll); updatesRoot.appendChild(section);
		});
	}

	function renderScan(data) {
		if (!scanRoot || !data) { return; }
		scanActive = !!data.active;
		scanRoot.className = scanRoot.className.replace(/\bmbi-state-\S+/g, '').trim() + ' mbi-state-' + (data.visual || 'idle');
		var bar = scanRoot.querySelector('[data-scan-bar]');
		var track = scanRoot.querySelector('[role="progressbar"]');
		if (bar) { bar.style.width = Number(data.percent || 0) + '%'; }
		if (track) { track.setAttribute('aria-valuenow', Number(data.percent || 0)); }
		setText('[data-scan-status]', data.status || 'AGUARDANDO');
		setText('[data-scan-title]', data.title || 'Nenhuma verificação em andamento');
		setText('[data-scan-percent]', Number(data.percent || 0) + '%');
		setText('[data-scan-message]', data.message || '');
		setText('[data-scan-total]', data.total || 0);
		setText('[data-scan-checked]', data.checked || 0);
		setText('[data-scan-with]', data.with_updates || 0);
		setText('[data-scan-without]', data.without_updates || 0);
		setText('[data-scan-chapters]', data.found_chapters || 0);
		setText('[data-scan-partial]', data.partial || 0);
		setText('[data-scan-failed]', data.failed || 0);
		setText('[data-scan-current]', data.current_manga || '—');
		renderScanRows(data.items || []);
		if (scanStartButton) {
			scanStartButton.disabled = scanActive;
			scanStartButton.textContent = scanActive ? 'Verificação em andamento…' : (Number(data.total || 0) > 0 ? 'Verificar novamente' : 'Detectar novos capítulos agora');
		}
	}

	function scanPost(action, nonce) {
		var body = new window.URLSearchParams();
		body.set('action', action);
		body.set('nonce', nonce);
		// v1.6.19: admin-post.php é o transporte principal do detector.
		// Em alguns hosts o admin-ajax.php é interceptado pelo WAF e devolve uma página HTML.
		var endpoint = window.MBIProgress.adminPostUrl || window.MBIProgress.ajaxUrl;
		return window.fetch(endpoint, {
			method: 'POST', credentials: 'same-origin', cache: 'no-store',
			headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'Accept': 'application/json'}, body: body.toString()
		}).then(function (response) {
			return response.text().then(function (text) {
				var data;
				try {
					data = JSON.parse(text);
				} catch (error) {
					throw new Error('O servidor devolveu HTML em vez de JSON (HTTP ' + response.status + '). A verificação foi interrompida com segurança; nenhum capítulo foi alterado.');
				}
				if (!response.ok && (!data || !data.success)) {
					throw new Error(data && data.data && data.data.message ? data.data.message : ('Falha HTTP ' + response.status + '.'));
				}
				return data;
			});
		});
	}

	function markNextPending() {
		if (!scanRoot) { return; }
		var badge = scanRoot.querySelector('.mbi-scan-pending');
		if (!badge) { return; }
		badge.className = 'mbi-badge mbi-scan-processing';
		badge.textContent = 'Verificando agora';
		var row = badge.closest('tr');
		var name = row ? row.querySelector('td:nth-child(2) strong') : null;
		setText('[data-scan-current]', name ? name.textContent : '—');
		if (row) { row.scrollIntoView({block: 'nearest', behavior: 'smooth'}); }
	}

	function scanTick() {
		if (!scanActive || scanBusy) { return; }
		scanBusy = true;
		markNextPending();
		scanPost('mbi_update_scan_tick', window.MBIProgress.scanTickNonce).then(function (response) {
			if (!response || !response.success) { throw new Error(response && response.data && response.data.message ? response.data.message : 'A verificação não respondeu.'); }
			renderScan(response.data.scan);
			renderDetectedUpdates(response.data.updates || []);
		}).catch(function (error) {
			setText('[data-scan-message]', (error && error.message ? error.message : 'Falha de comunicação.') + ' Uma nova tentativa será feita automaticamente.');
		}).finally(function () {
			scanBusy = false;
			if (scanActive) { window.setTimeout(scanTick, 650); }
		});
	}

	function startScan() {
		if (scanBusy || scanActive) { return; }
		scanBusy = true;
		if (scanStartButton) { scanStartButton.disabled = true; scanStartButton.textContent = 'Preparando lista…'; }
		scanPost('mbi_start_update_scan', window.MBIProgress.scanStartNonce).then(function (response) {
			if (!response || !response.success) { throw new Error(response && response.data && response.data.message ? response.data.message : 'Não foi possível iniciar a verificação.'); }
			renderScan(response.data.scan);
			renderDetectedUpdates(response.data.updates || []);
		}).catch(function (error) {
			scanActive = false;
			if (scanStartButton) { scanStartButton.disabled = false; scanStartButton.textContent = 'Detectar novos capítulos agora'; }
			setText('[data-scan-message]', error && error.message ? error.message : 'Não foi possível iniciar a verificação.');
		}).finally(function () {
			scanBusy = false;
			if (scanActive) { window.setTimeout(scanTick, 250); }
		});
	}


	function manualPrepareTick() {
		if (!manualPreparing || manualPrepareBusy || document.hidden) { return; }
		if (Date.now() < workerNotBefore) {
			window.setTimeout(manualPrepareTick, Math.max(2000, Math.min(30000, workerNotBefore - Date.now())));
			return;
		}
		manualPrepareBusy = true;
		var body = new window.URLSearchParams();
		body.set('action', 'mbi_prepare_manual_update_tick');
		body.set('nonce', window.MBIProgress.manualPrepareNonce);
		window.fetch(window.MBIProgress.adminPostUrl || window.MBIProgress.ajaxUrl, {
			method: 'POST', credentials: 'same-origin', cache: 'no-store',
			headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'Accept': 'application/json'},
			body: body.toString()
		}).then(function (response) {
			return response.text().then(function (text) {
				var json;
				try { json = JSON.parse(text); } catch (e) { throw new Error('A hospedagem interrompeu um lote de preparação. O próximo lote será tentado automaticamente.'); }
				return json;
			});
		}).then(function (response) {
			if (response && response.success && response.data && response.data.manual) {
				renderManual(response.data.manual);
			}
		}).catch(function () {
			// WP-Cron continua a preparação mesmo se o navegador perder uma rodada.
		}).finally(function () {
			manualPrepareBusy = false;
			if (manualPreparing) { window.setTimeout(manualPrepareTick, Math.max(2000, Math.min(30000, workerNotBefore - Date.now()))); }
		});
	}

	function accelerate() {
		if (!shouldAccelerate || workerBusy || Date.now() < workerNotBefore) {
			return;
		}
		workerBusy = true;
		var body = new window.URLSearchParams();
		body.set('action', 'mbi_accelerate');
		body.set('nonce', window.MBIProgress.workerNonce);
		var controller = typeof window.AbortController !== 'undefined' ? new window.AbortController() : null;
		var watchdog = window.setTimeout(function () {
			if (controller) { controller.abort(); }
		}, 70000);
		window.fetch(window.MBIProgress.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
			body: body.toString(),
			cache: 'no-store',
			signal: controller ? controller.signal : undefined
		}).then(function (response) {
			return response.json();
		}).then(function (response) {
			if (response && response.success && response.data && response.data.progress) {
				render(response.data.progress);
				renderManual(response.data.progress.manual);
			}
		}).catch(function () {
			// O cron de segurança continua disponível se a requisição do navegador falhar.
		}).finally(function () {
			window.clearTimeout(watchdog);
			workerBusy = false;
			if (shouldAccelerate) {
				window.setTimeout(accelerate, Math.max(2000, Math.min(60000, workerNotBefore - Date.now())));
			}
		});
	}


	function renderCleanup(data) {
		if (!data) {
			return;
		}
		cleanupActive = !!data.active;
		setText('[data-cleanup-state]', data.active ? 'EM ANDAMENTO' : (data.done ? 'CONCLUÍDA' : 'AGUARDANDO'));
		setText('[data-cleanup-deleted]', data.deleted || 0);
		setText('[data-cleanup-remaining]', data.remaining || 0);
		setText('[data-cleanup-protected]', data.protected || 0);
	}

	function cleanupTick() {
		if (!cleanupActive || cleanupBusy || document.hidden) {
			return;
		}
		cleanupBusy = true;
		var body = new window.URLSearchParams();
		body.set('action', 'mbi_cleanup_media_tick');
		body.set('nonce', window.MBIProgress.cleanupNonce);
		window.fetch(window.MBIProgress.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
			body: body.toString(),
			cache: 'no-store'
		}).then(function (response) {
			return response.json();
		}).then(function (response) {
			if (response && response.success && response.data && response.data.cleanup) {
				renderCleanup(response.data.cleanup);
			}
		}).catch(function () {
			// O WP-Cron continua a limpeza se o navegador falhar.
		}).finally(function () {
			cleanupBusy = false;
			if (cleanupActive) {
				window.setTimeout(cleanupTick, 2500);
			}
		});
	}

	var proxyTestButton = document.querySelector('[data-mbi-proxy-test]');
	var proxyTestStatus = document.querySelector('[data-proxy-test-status]');
	var proxyAuditButton = document.querySelector('[data-mbi-proxy-audit]');
	var proxyAuditStatus = document.querySelector('[data-proxy-audit-status]');
	var proxyMigrationBusy = false;
	var proxyMigrationActive = !!window.MBIProgress.proxyMigrationActive;
	var imageSamples51 = new Map();

	// Diagnostics run only on clicks. Repeated clicks reuse a bounded recent sample.
	function cachedImage51(url, task) {
		var cached = imageSamples51.get(url);
		if (cached && cached.until > Date.now()) { return cached.promise; }
		if (imageSamples51.size >= 32) { imageSamples51.delete(imageSamples51.keys().next().value); }
		var promise = task();
		imageSamples51.set(url, {until: Date.now() + 300000, promise: promise});
		promise.catch(function () { imageSamples51.delete(url); });
		return promise;
	}

	function setProxyStatus(message, ok) {
		if (!proxyTestStatus) { return; }
		proxyTestStatus.textContent = message;
		proxyTestStatus.style.fontWeight = '600';
		proxyTestStatus.style.color = ok === true ? '#008a20' : (ok === false ? '#b32d2e' : '');
	}

	function runPreparedProxyUrl(prepared) {
		if (!prepared || !prepared.ok || !prepared.proxy_url) { return Promise.reject(new Error('Não foi possível preparar a URL de teste.')); }
		setProxyStatus('Validando uma amostra pelo navegador; resultado reutilizado por 5 minutos…', null);
		return loadAuditImage(prepared.proxy_url, 20000).then(function (result) {
			if (!result.ok) { throw new Error('A imagem não foi entregue pelo Worker: ' + result.reason + '. Confira chave, hosts permitidos e acesso à origem.'); }
		});
	}

	function runProxyTest() {
		if (!proxyTestButton || proxyTestButton.disabled) { return; }
		proxyTestButton.disabled = true;
		setProxyStatus('Preparando diagnóstico…', null);

		// v1.6.10: usa primeiro os dados preparados no próprio painel. Isso evita
		// depender do admin-ajax.php em hosts/WAFs que devolvem HTML nessa ação.
		var prepared = window.MBIProgress && window.MBIProgress.proxyTestPrepared ? window.MBIProgress.proxyTestPrepared : null;
		var task;
		if (prepared && prepared.ok && prepared.proxy_url) {
			task = runPreparedProxyUrl(prepared);
		} else {
			var body = new window.URLSearchParams();
			body.set('action', 'mbi_prepare_proxy_test');
			body.set('nonce', window.MBIProgress.proxyTestNonce);
			task = window.fetch(window.MBIProgress.ajaxUrl, {
				method: 'POST', credentials: 'same-origin',
				headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
				body: body.toString(), cache: 'no-store'
			}).then(function (response) {
				return response.text().then(function (text) {
					var json;
					try { json = JSON.parse(text); } catch (e) {
						throw new Error('A hospedagem/WAF devolveu HTML no lugar do JSON do diagnóstico (HTTP ' + response.status + '). Atualize o painel; a v1.6.10 usa fallback sem AJAX.');
					}
					return json;
				});
			}).then(function (response) {
				if (!response || !response.success || !response.data || !response.data.proxy_url) {
					throw new Error(response && response.data && response.data.message ? response.data.message : 'Não foi possível preparar a URL de teste.');
				}
				return runPreparedProxyUrl({ok: true, proxy_url: response.data.proxy_url});
			});
		}

		task.then(function () {
			setProxyStatus('Proxy/CDN validado com sucesso. A imagem foi entregue pelo Worker sem ocupar o PHP da hospedagem.', true);
		}).catch(function (error) {
			setProxyStatus(error && error.message ? error.message : 'Falha no teste seguro do Proxy/CDN.', false);
		}).finally(function () {
			proxyTestButton.disabled = false;
		});
	}

	function loadAuditImage(url, timeoutMs) {
		return cachedImage51(url, function () { return new Promise(function (resolve) {
			var image = new Image();
			var finished = false;
			var started = Date.now();
			var timer = window.setTimeout(function () {
				if (finished) { return; }
				finished = true;
				image.src = '';
				resolve({ok: false, ms: Date.now() - started, reason: 'timeout'});
			}, timeoutMs || 12000);
			image.onload = function () {
				if (finished) { return; }
				finished = true;
				window.clearTimeout(timer);
				resolve({ok: true, ms: Date.now() - started, reason: ''});
			};
			image.onerror = function () {
				if (finished) { return; }
				finished = true;
				window.clearTimeout(timer);
				resolve({ok: false, ms: Date.now() - started, reason: 'erro'});
			};
			image.referrerPolicy = 'no-referrer';
			image.src = url;
		}); });
	}

	function renderAuditRows(rows) {
		if (!proxyAuditStatus) { return; }
		proxyAuditStatus.innerHTML = '';
		rows.forEach(function (row) {
			var line = document.createElement('div');
			line.style.margin = '5px 0';
			var sourceLabel = row.source.ok ? 'origem OK' : 'origem FALHA';
			var proxyLabel = row.proxy.ok ? 'Worker OK' : 'Worker FALHA';
			line.textContent = row.host + ' · ' + row.role + ' · ' + sourceLabel + ' (' + row.source.ms + 'ms) · ' + proxyLabel + ' (' + row.proxy.ms + 'ms)';
			line.style.color = row.proxy.ok ? '#008a20' : '#b32d2e';
			proxyAuditStatus.appendChild(line);
		});
	}

	function runAuditSamples(samples) {
		var seen = Object.create(null);
		samples = (samples || []).filter(function (sample) { if (seen[sample.proxy_url]) { return false; } seen[sample.proxy_url] = true; return true; }).slice(0, 6);
		var chain = Promise.resolve([]);
		(samples || []).forEach(function (sample) {
			chain = chain.then(function (rows) {
				if (proxyAuditStatus) {
					proxyAuditStatus.textContent = 'Testando ' + sample.host + ' (' + (rows.length + 1) + '/' + samples.length + ')…';
				}
				return Promise.all([
					loadAuditImage(sample.source_url, 12000),
					loadAuditImage(sample.proxy_url, 12000)
				]).then(function (results) {
					rows.push({host: sample.host, role: sample.role, source: results[0], proxy: results[1]});
					return rows;
				});
			});
		});
		return chain;
	}

	function runProxyAudit() {
		if (!proxyAuditButton || proxyAuditButton.disabled) { return; }
		proxyAuditButton.disabled = true;
		if (proxyAuditStatus) { proxyAuditStatus.textContent = 'Preparando uma amostra por host de origem…'; }

		var prepared = window.MBIProgress && window.MBIProgress.proxyAuditPrepared ? window.MBIProgress.proxyAuditPrepared : null;
		var task;
		if (prepared && prepared.ok && prepared.samples && prepared.samples.length) {
			task = runAuditSamples(prepared.samples);
		} else {
			var body = new window.URLSearchParams();
			body.set('action', 'mbi_prepare_proxy_origin_audit');
			body.set('nonce', window.MBIProgress.proxyAuditNonce);
			task = window.fetch(window.MBIProgress.ajaxUrl, {
				method: 'POST', credentials: 'same-origin',
				headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
				body: body.toString(), cache: 'no-store'
			}).then(function (response) {
				return response.text().then(function (text) {
					var json;
					try { json = JSON.parse(text); } catch (e) {
						throw new Error('A hospedagem/WAF devolveu HTML no lugar do JSON da auditoria (HTTP ' + response.status + ').');
					}
					return json;
				});
			}).then(function (response) {
				if (!response || !response.success || !response.data || !response.data.samples) {
					throw new Error(response && response.data && response.data.message ? response.data.message : 'Não foi possível preparar a auditoria.');
				}
				return runAuditSamples(response.data.samples);
			});
		}

		task.then(function (rows) {
			renderAuditRows(rows);
		}).catch(function (error) {
			if (proxyAuditStatus) {
				proxyAuditStatus.textContent = error && error.message ? error.message : 'Falha ao auditar as origens.';
				proxyAuditStatus.style.color = '#b32d2e';
			}
		}).finally(function () {
			proxyAuditButton.disabled = false;
		});
	}

	function renderProxyMigration(data) {
		if (!data) { return; }
		proxyMigrationActive = !!data.active;
		setText('[data-proxy-migration-state]', data.active ? 'EM ANDAMENTO' : (data.done ? 'CONCLUÍDA' : 'AGUARDANDO'));
		setText('[data-proxy-migration-scanned]', data.posts_scanned || 0);
		setText('[data-proxy-migration-mangas]', data.mangas_migrated || 0);
		setText('[data-proxy-migration-chapters]', data.chapters_migrated || 0);
		setText('[data-proxy-migration-urls]', data.urls_migrated || 0);
		setText('[data-proxy-migration-skipped]', data.skipped || 0);
	}

	function proxyMigrationTick() {
		if (!proxyMigrationActive || proxyMigrationBusy || document.hidden) { return; }
		proxyMigrationBusy = true;
		var body = new window.URLSearchParams();
		body.set('action', 'mbi_proxy_migration_tick');
		body.set('nonce', window.MBIProgress.proxyMigrationNonce);
		window.fetch(window.MBIProgress.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
			body: body.toString(),
			cache: 'no-store'
		}).then(function (response) {
			return response.json();
		}).then(function (response) {
			if (response && response.success && response.data && response.data.migration) {
				renderProxyMigration(response.data.migration);
			}
		}).catch(function () {
			// WP-Cron continua a migração como fallback.
		}).finally(function () {
			proxyMigrationBusy = false;
			if (proxyMigrationActive) {
				window.setTimeout(proxyMigrationTick, 3000);
			}
		});
	}

	if (proxyTestButton) {
		proxyTestButton.addEventListener('click', runProxyTest);
	}
	if (proxyAuditButton) {
		proxyAuditButton.addEventListener('click', runProxyAudit);
	}
	if (scanStartButton) {
		scanStartButton.addEventListener('click', startScan);
	}

	function schedule() {
		window.clearTimeout(timer);
		if (!watchProgress && !scanActive && !manualPreparing && !cleanupActive && !proxyMigrationActive && updatesRevision <= syncedUpdatesRevision) { return; }
		timer = window.setTimeout(poll, interval);
	}
	document.addEventListener('visibilitychange', function () { if (!document.hidden) { watchProgress = true; schedule(); } });

	function poll() {
		if (document.hidden || workerBusy) {
			schedule();
			return;
		}
		var body = new window.URLSearchParams();
		body.set('action', 'mbi_progress');
		body.set('nonce', window.MBIProgress.nonce);
		var requestedUpdatesRevision = updatesRoot && updatesRevision > syncedUpdatesRevision ? updatesRevision : 0;
		if (requestedUpdatesRevision) { body.set('include_updates', '1'); }
		window.fetch(window.MBIProgress.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
			body: body.toString(),
			cache: 'no-store'
		}).then(function (response) {
			return response.json();
		}).then(function (response) {
			if (response && response.success && response.data) {
				render(response.data);
				renderManual(response.data.manual);
				if (requestedUpdatesRevision && Array.isArray(response.data.updates) && requestedUpdatesRevision === updatesRevision) {
					renderDetectedUpdates(response.data.updates);
					syncedUpdatesRevision = requestedUpdatesRevision;
				}
				manualPrepareTick();
				accelerate();
				cleanupTick();
			}
		}).catch(function () {
			// Mantém os últimos dados válidos e tenta novamente no próximo ciclo.
		}).finally(schedule);
	}

	if (manualPreparing) {
		window.setTimeout(manualPrepareTick, 350);
	}
	if (shouldAccelerate) {
		window.setTimeout(accelerate, 700);
	}
	if (cleanupActive) {
		window.setTimeout(cleanupTick, 500);
	}
	if (proxyMigrationActive) {
		window.setTimeout(proxyMigrationTick, 800);
	}
	if (scanActive) {
		window.setTimeout(scanTick, 300);
	}
	schedule();
}());
