// Recherche d'horaires SNCF : pré-remplit un formulaire de trajet (voir app/sncf.py).
(() => {
  const ids = new Map(); // libellé de gare -> identifiant Navitia
  const list = document.getElementById('sncf-places');
  const fetchJson = async (url) => {
    const r = await fetch(url, { credentials: 'same-origin' });
    const data = await r.json().catch(() => ({}));
    if (!r.ok) throw new Error(data.error || 'Erreur');
    return data;
  };
  const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };

  document.querySelectorAll('.sncf').forEach((box) => {
    const form = box.closest('form');
    const base = box.dataset.base;
    const panel = box.querySelector('.sncf-panel');
    const from = box.querySelector('.sncf-from'), to = box.querySelector('.sncf-to');
    const results = box.querySelector('.sncf-results');
    const connect = box.querySelector('.sncf-connect');
    const field = (n) => form.elements[n];

    const syncConnect = () => {
      const q = new URLSearchParams({ origin: from.value || field('origin').value, dest: to.value || field('destination').value, day: field('day').value });
      connect.href = `${base}/connect?${q}`;
    };
    form.addEventListener('input', syncConnect);

    box.querySelector('.sncf-open')?.addEventListener('click', () => {
      panel.hidden = !panel.hidden;
      if (!from.value) from.value = field('origin').value;
      if (!to.value) to.value = field('destination').value;
    });

    const suggest = debounce(async (input) => {
      if (input.value.length < 2) return;
      try {
        const places = await fetchJson(`${base}/places?q=${encodeURIComponent(input.value)}`);
        list.replaceChildren(...places.map((p) => { ids.set(p.name, p.id); const o = document.createElement('option'); o.value = p.name; return o; }));
      } catch { /* suggestions facultatives */ }
    }, 250);
    [from, to].forEach((i) => i.addEventListener('input', () => suggest(i)));

    box.querySelector('.sncf-go')?.addEventListener('click', async () => {
      results.textContent = '';
      if (!ids.has(from.value) || !ids.has(to.value) || !field('day').value) {
        results.textContent = 'Choisissez les deux gares dans la liste proposée et renseignez la date du trajet.';
        return;
      }
      results.textContent = 'Recherche…';
      try {
        const q = new URLSearchParams({ origin: ids.get(from.value), dest: ids.get(to.value), day: field('day').value, time: box.querySelector('.sncf-time').value });
        const js = await fetchJson(`${base}/journeys?${q}`);
        if (!js.length) { results.textContent = 'Aucun itinéraire trouvé.'; return; }
        results.replaceChildren(...js.map((j) => {
          const b = document.createElement('button');
          b.type = 'button'; b.className = 'sec'; b.style.cssText = 'display:block;margin:4px 0;text-align:left';
          const via = j.transfers ? ` · ${j.transfers} corresp.` : '';
          b.textContent = `${j.dep_time} → ${j.arr_time} · ${j.mode}${j.numbers ? ' ' + j.numbers : ''}${via} · ${Math.floor(j.duration_min / 60)}h${String(j.duration_min % 60).padStart(2, '0')}`;
          b.addEventListener('click', () => {
            field('origin').value = from.value; field('destination').value = to.value;
            field('dep_time').value = j.dep_time; field('arr_time').value = j.arr_time;
            field('mode').value = j.mode;
            if (j.numbers && !field('note').value) field('note').value = `Train ${j.numbers}`;
            field('price').focus();
          });
          return b;
        }));
      } catch (e) { results.textContent = e.message; }
    });
    syncConnect();
  });
})();
