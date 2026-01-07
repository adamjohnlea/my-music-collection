(function(){
  const state = { data: [], q: '', sort: 'added_desc', page: 1, perPage: 24 };
  const $ = sel => document.querySelector(sel);
  const $$ = sel => Array.from(document.querySelectorAll(sel));
  const params = new URLSearchParams(location.search);
  state.q = params.get('q') || '';
  state.sort = params.get('sort') || 'added_desc';
  state.page = Math.max(1, parseInt(params.get('page')||'1',10));
  state.perPage = Math.max(1, Math.min(60, parseInt(params.get('per_page')||'24',10)));

  function cmp(a,b){
    const dir = (state.sort.endsWith('_desc') ? -1 : 1);
    const key = state.sort.replace(/_(asc|desc)$/,'');
    const kmap = {
      added: r => r.added_at || '',
      artist: r => (r.artist||'').toLowerCase(),
      title: r => (r.title||'').toLowerCase(),
      year: r => (r.year||0),
      rating: r => (r.rating==null ? -1 : r.rating),
      imported: r => r.id,
    };
    let ka = kmap[key]?kmap[key](a):a.id, kb = kmap[key]?kmap[key](b):b.id;
    if (ka<kb) return -1*dir; if (ka>kb) return 1*dir; return a.id-b.id;
  }

  function parseQuery(q){
    q = (q||'').trim().replace(/(\b\w+):\s+/g,'$1:');
    const tokens = q? q.match(/(")([^\"]*)(")|(\S+)/g) : [];
    const fields = [];
    const terms = [];
    let yearFrom=null, yearTo=null;
    const map = { artist:'artist', title:'title', label:'label_text', format:'format_text', genre:'genre_style_text', style:'genre_style_text', country:'country', credit:'credit_text', company:'company_text', identifier:'identifier_text', barcode:'identifier_text', notes:'notes' };
    (tokens||[]).forEach(tok=>{
      if (/^year:\d{4}(\.\.|$)/i.test(tok)){
        const v = tok.split(':')[1];
        if (/^\d{4}\.\.\d{4}$/.test(v)){ const [a,b]=v.split('..'); yearFrom=+a; yearTo=+b; return; }
        if (/^\d{4}$/.test(v)){ yearFrom=+v; yearTo=+v; return; }
      }
      const m = tok.match(/^(\w+):(.*)$/);
      if (m){
        const key=m[1].toLowerCase(); let val=m[2]; if (val.startsWith('"')&&val.endsWith('"')) val=val.slice(1,-1);
        if (map[key]){ fields.push({field: map[key], value: val.toLowerCase()}); return; }
      }
      terms.push(tok.replace(/\*/g,'').toLowerCase());
    });
    return {fields, terms, yearFrom, yearTo};
  }

  function applyFilters(items){
    const {fields, terms, yearFrom, yearTo} = parseQuery(state.q);
    let out = items;
    if (terms.length){
      out = out.filter(r=>{
        const hay = (r.artist+' '+r.title+' '+(r.genre_style_text||'')+' '+(r.label_text||'')+' '+(r.format_text||'')+' '+(r.country||'')).toLowerCase();
        return terms.every(t=>hay.includes(t));
      });
    }
    fields.forEach(f=>{
      out = out.filter(r=>String(r[f.field]||'').toLowerCase().includes(f.value));
    });
    if (yearFrom!=null && yearTo!=null){ out = out.filter(r=> (r.year||0)>=yearFrom && (r.year||0)<=yearTo ); }
    return out;
  }

  function paginate(items){
    const total = items.length;
    const pages = Math.max(1, Math.ceil(total/state.perPage));
    const page = Math.max(1, Math.min(state.page, pages));
    const slice = items.slice((page-1)*state.perPage, page*state.perPage);
    return {total, pages, page, slice};
  }

  function render(items){
    items.sort(cmp);
    const filtered = applyFilters(items);
    const {total, pages, page, slice} = paginate(filtered);
    $('#stats').textContent = `${total} items` + (state.q? ` • Search: “${state.q}”`:'' );
    const grid = $('#grid'); grid.innerHTML='';
    slice.forEach(r=>{
      const a = document.createElement('a'); a.className='card'; a.href = `releases/${r.id}.html`;
      a.innerHTML = `<img class="cover ready" src="${r.image||''}" alt="${(r.title||'').replaceAll('"','&quot;')}"/>\n<div class="meta"><div class="title">${r.title||''}</div><div class="artist">${r.artist||''}${r.year? ' • '+r.year:''}</div></div>`;
      grid.appendChild(a);
    });
    renderPager(pages, page);
    updateQueryString();
  }

  function renderPager(pages, page){
    const nav = $('#pager'); nav.innerHTML='';
    function btn(label, p, disabled, current){
      const a = document.createElement('a'); a.className='page-btn'+(current?' is-current':'')+(disabled?' is-disabled':'');
      if (!disabled) a.href = `?q=${encodeURIComponent(state.q)}&sort=${state.sort}&page=${p}&per_page=${state.perPage}`;
      a.textContent = label; nav.appendChild(a);
    }
    btn('Prev', Math.max(1, page-1), page<=1, false);
    const span = 2; const start=Math.max(1, page-span), end=Math.min(pages, page+span);
    for (let p=start;p<=end;p++){ btn(String(p), p, false, p===page); }
    btn('Next', Math.min(pages, page+1), page>=pages, false);
  }

  function updateQueryString(){
    const params = new URLSearchParams({ q: state.q, sort: state.sort, page: String(state.page), per_page: String(state.perPage) });
    history.replaceState(null, '', '?' + params.toString());
  }

  function wire(){
    const q = $('#search-input'); const sort = $('#sort');
    q.value = state.q; sort.value = state.sort;
    q.addEventListener('input', ()=>{ state.q=q.value; state.page=1; render(state.data.slice()); });
    sort.addEventListener('change', ()=>{ state.sort=sort.value; render(state.data.slice()); });
  }

  async function load(){
    const isWantlist = location.pathname.endsWith('wantlist.html');
    const dataFile = isWantlist ? 'data/wantlist.json' : 'data/releases.json';
    const manifestFile = isWantlist ? null : 'data/releases.manifest.json';

    if (manifestFile) {
      try {
        const manRes = await fetch(manifestFile);
        if (manRes.ok){
          const manifest = await manRes.json();
          const files = manifest.map(m=> 'data/'+m.file);
          const arrays = await Promise.all(files.map(f=> fetch(f).then(r=>r.json())));
          state.data = arrays.flat();
          return;
        }
      } catch(e) {}
    }

    try {
      const res = await fetch(dataFile);
      if (res.ok){ state.data = await res.json(); return; }
    } catch(e) {}

    // Inline fallback
    const el = document.getElementById('releases-data');
    if (el) {
      try { state.data = JSON.parse(el.textContent||'[]'); } catch(e) { state.data = []; }
    } else {
      state.data = [];
    }
  }

  function surprise() {
    if (!state.data.length) return;
    const items = applyFilters(state.data);
    if (!items.length) return;
    const r = items[Math.floor(Math.random() * items.length)];
    const isDetail = location.pathname.includes('/releases/');
    location.href = (isDetail ? '' : 'releases/') + r.id + '.html';
  }

  window.surpriseMe = surprise;

  wire();
  load().then(()=> render(state.data.slice()));
})();