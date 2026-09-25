(() => {
    const status = document.getElementById('midas-discovery-status');
    const button = document.getElementById('midas-discovery-resume');
    if (!status || !button) return;
    let timer;
    let busy = false;
    async function advance() {
        if (busy) return;
        clearTimeout(timer);
        busy = true;
        button.disabled = true;
        try {
            const body = new URLSearchParams({action: 'midas_discovery', nonce: midasDiscovery.nonce});
            const response = await fetch(midasDiscovery.url, {method: 'POST', credentials: 'same-origin', body});
            const result = await response.json();
            if (!result.success) throw new Error(result.data?.message || 'Não foi possível continuar. Atualize a página e consulte os logs.');
            status.textContent = result.data.message;
            if (result.data.pending) timer = setTimeout(advance, result.data.delay * 1000);
        } catch (error) {
            status.textContent = error.message + ' Use Continuar descoberta para tentar novamente.';
        } finally {
            busy = false;
            button.disabled = false;
        }
    }
    button.addEventListener('click', advance);
    advance();
})();
