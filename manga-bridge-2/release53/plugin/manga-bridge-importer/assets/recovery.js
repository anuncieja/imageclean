(function () {
	'use strict';
	var selectAll = document.querySelector('[data-mbi-select-all]');
	if (selectAll) {
		selectAll.addEventListener('change', function () {
			var form = selectAll.closest('form');
			form.querySelectorAll('input[name="manga_ids[]"]:not(:disabled)').forEach(function (box) {
				box.checked = selectAll.checked;
			});
		});
	}
	var root = document.querySelector('[data-mbi-catalog-restore][data-active="1"]');
	if (!root || !window.MBIRecovery) { return; }
	var busy = false;
	function tick() {
		if (busy) { return; }
		busy = true;
		var body = new URLSearchParams();
		body.set('action', 'mbi_catalog_restore_tick');
		body.set('nonce', MBIRecovery.nonce);
		fetch(MBIRecovery.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (response) {
			if (!response.ok) { throw new Error('HTTP ' + response.status); }
			return response.json();
		}).then(function (payload) {
			if (!payload.success || !payload.data) { throw new Error('Resposta inválida do servidor'); }
			var state = payload.data;
			var total = (state.ids || []).length || 1;
			var percent = Number(state.percent || 0);
			var status = root.querySelector('[data-restore-status]');
			var bar = root.querySelector('[data-restore-bar]');
			if (status) {
				status.textContent = percent + '% · ' + String(state.index || 0) + ' de ' + total + ' obras verificadas · ' +
					String(state.current_draft_done || 0) + '/' + String(state.current_draft_total || 0) + ' capítulos da obra atual · ' +
					String(state.mangas_restored || 0) + ' mangás e ' + String(state.chapters_restored || 0) +
					' capítulos publicados. ' + String(state.mangas_skipped || 0) + ' obras e ' +
					String(state.chapters_skipped || 0) + ' capítulos ignorados.';
			}
			if (bar) { bar.style.width = percent + '%'; }
			if (!state.active) { window.location.reload(); return; }
			window.setTimeout(tick, 350);
		}).catch(function (error) {
			var status = root.querySelector('[data-restore-status]');
			if (status) { status.textContent = 'Conexão interrompida (' + error.message + '). Recarregue a página ou use “Processar próximo lote manualmente”.'; }
		}).finally(function () { busy = false; });
	}
	window.setTimeout(tick, 200);
}());
