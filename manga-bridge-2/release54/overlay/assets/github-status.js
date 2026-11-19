(() => {
    const card = document.getElementById('mbi-github-status');
    if (!card || card.dataset.active !== '1') return;
    let failures = 0;
    async function poll() {
        if (document.hidden) { setTimeout(poll, 15000); return; }
        let keep = true;
        try {
            const response = await fetch(card.dataset.url, {credentials: 'same-origin', cache: 'no-store'});
            if (!response.ok) throw new Error('status');
            const result = await response.json();
            if (!result.success) throw new Error('status');
            const data = result.data;
            for (const field of ['label', 'chapters', 'total', 'published', 'reason']) {
                card.querySelector('[data-field="' + field + '"]').textContent = field === 'total' && !data[field] ? 'a identificar' : String(data[field]);
            }
            failures = 0;
            keep = data.active;
            if (!keep) {
                // Refresh only the card's final controls/links, without reissuing the POST.
                const link = document.createElement('a');
                link.href = location.href;
                link.textContent = 'Ver resultado e ações disponíveis';
                card.append(link);
            }
        } catch (_) { failures += 1; }
        if (keep && failures < 3) setTimeout(poll, 15000 * (failures + 1));
    }
    setTimeout(poll, 15000);
})();
