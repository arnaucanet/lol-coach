// --- State ---
let CHAMPIONS = [];
let RUNE_TREES = [];   // full runesReforged data
let RUNE_SHARDS = {};  // { offense: [], flex: [], defense: [] }
const selection = {
    allies:  { top: null, jng: null, mid: null, adc: null, supp: null },
    enemies: { top: null, jng: null, mid: null, adc: null, supp: null },
};

const ROLE_LABEL = { top: 'Top', jng: 'Jungla', mid: 'Mid', adc: 'ADC', supp: 'Support' };

// --- Bootstrap ---
async function boot() {
    try {
        const [champRes, runesRes] = await Promise.all([fetch('champions.php'), fetch('runes.php')]);
        const champData = await champRes.json();
        const runesData = await runesRes.json();
        CHAMPIONS = champData.champions || [];
        RUNE_TREES = runesData.trees || [];
        RUNE_SHARDS = runesData.shards || {};
        document.getElementById('patch').textContent = `Parche ${champData.version}`;
        initPickers();
        initRoleMarker();
        initTabs();
        initSearch();
        initBuilds();
    } catch (e) {
        document.getElementById('patch').textContent = 'Error cargando datos';
        console.error(e);
    }
}

// --- Tabs ---
function initTabs() {
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => switchTab(btn.dataset.tab));
    });
}

function switchTab(tabId) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === tabId));
    document.querySelectorAll('.tab-view').forEach(v => v.hidden = v.dataset.tab !== tabId);
}

function initPickers() {
    document.querySelectorAll('.picker').forEach(el => new ChampionPicker(el));
    document.getElementById('analyze').addEventListener('click', runAnalysis);
}

function initRoleMarker() {
    const roleSel = document.getElementById('playerRole');
    const apply = () => {
        const role = roleSel.value; // top | jng | mid | adc | supp
        document.querySelectorAll('.lane.is-me, .lane.is-rival').forEach(el => {
            el.classList.remove('is-me', 'is-rival');
        });
        const meRow    = document.querySelector(`.lane[data-team="allies"][data-lane="${role}"]`);
        const rivalRow = document.querySelector(`.lane[data-team="enemies"][data-lane="${role}"]`);
        if (meRow)    meRow.classList.add('is-me');
        if (rivalRow) rivalRow.classList.add('is-rival');
    };
    roleSel.addEventListener('change', apply);
    apply();
}

// --- Picker component ---
class ChampionPicker {
    constructor(root) {
        this.root = root;
        this.target = root.dataset.target;
        this.value = null;
        this.render();
    }

    render() {
        this.root.innerHTML = `
            <div class="picker-input" tabindex="0">
                <span class="placeholder">Elegir...</span>
            </div>
            <div class="picker-dropdown">
                <input type="text" class="picker-search" placeholder="Buscar campeón...">
                <div class="picker-options"></div>
            </div>
        `;
        this.input   = this.root.querySelector('.picker-input');
        this.drop    = this.root.querySelector('.picker-dropdown');
        this.search  = this.root.querySelector('.picker-search');
        this.options = this.root.querySelector('.picker-options');

        this.input.addEventListener('click', (e) => {
            if (e.target.classList.contains('clear')) return;
            this.toggle();
        });
        this.search.addEventListener('input', () => this.renderOptions(this.search.value));
        this.search.addEventListener('keydown', (e) => this.handleKey(e));
        document.addEventListener('click', (e) => {
            if (!this.root.contains(e.target)) this.close();
        });
    }

    toggle() { this.drop.classList.contains('open') ? this.close() : this.open(); }

    open() {
        document.querySelectorAll('.picker-dropdown.open').forEach(d => d.classList.remove('open'));
        document.querySelectorAll('.picker-input.open').forEach(d => d.classList.remove('open'));
        this.drop.classList.add('open');
        this.input.classList.add('open');
        this.renderOptions('');
        setTimeout(() => this.search.focus(), 30);
    }

    close() {
        this.drop.classList.remove('open');
        this.input.classList.remove('open');
        this.search.value = '';
    }

    renderOptions(q) {
        const needle = q.toLowerCase().trim();
        const filtered = needle
            ? CHAMPIONS.filter(c =>
                c.name.toLowerCase().includes(needle) ||
                c.id.toLowerCase().includes(needle))
            : CHAMPIONS;

        this.options.innerHTML = filtered.map((c, i) => `
            <div class="picker-option${i === 0 ? ' active' : ''}" data-id="${c.id}">
                <img src="${c.icon}" alt="" loading="lazy">
                <div class="opt-info">
                    <div class="opt-name">${c.name}</div>
                    <div class="opt-title">${c.title}</div>
                </div>
            </div>
        `).join('');

        this.options.querySelectorAll('.picker-option').forEach(opt => {
            opt.addEventListener('click', () => this.select(opt.dataset.id));
        });
    }

    handleKey(e) {
        const opts = [...this.options.querySelectorAll('.picker-option')];
        const activeIdx = opts.findIndex(o => o.classList.contains('active'));

        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            const next = e.key === 'ArrowDown' ? activeIdx + 1 : activeIdx - 1;
            if (next >= 0 && next < opts.length) {
                opts.forEach(o => o.classList.remove('active'));
                opts[next].classList.add('active');
                opts[next].scrollIntoView({ block: 'nearest' });
            }
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (activeIdx >= 0) this.select(opts[activeIdx].dataset.id);
        } else if (e.key === 'Escape') {
            this.close();
        }
    }

    select(id) {
        const champ = CHAMPIONS.find(c => c.id === id);
        if (!champ) return;
        this.value = champ;
        setSelection(this.target, champ.id);
        this.input.innerHTML = `
            <img src="${champ.icon}" alt="">
            <span class="name">${champ.name}</span>
            <span class="clear" title="Quitar">×</span>
        `;
        this.input.querySelector('.clear').addEventListener('click', (e) => {
            e.stopPropagation();
            this.clear();
        });
        this.close();
    }

    clear() {
        this.value = null;
        setSelection(this.target, null);
        this.input.innerHTML = '<span class="placeholder">Elegir...</span>';
    }
}

function setSelection(target, value) {
    if (target.includes('.')) {
        const [team, lane] = target.split('.');
        selection[team][lane] = value;
    } else if (target === 'builderChampion') {
        builderChampionValue = value;
    }
}

// --- Analysis flow ---
async function runAnalysis() {
    const btn = document.getElementById('analyze');
    const status = document.getElementById('status');
    const result = document.getElementById('result');

    const role = document.getElementById('playerRole').value;
    const playerChampion = selection.allies[role];
    const laneOpponent   = selection.enemies[role];

    if (!playerChampion) {
        status.className = 'status error';
        status.textContent = `Selecciona tu campeón en la casilla ${ROLE_LABEL[role]} (aliados).`;
        return;
    }
    if (!laneOpponent) {
        status.className = 'status error';
        status.textContent = `Selecciona tu rival en la casilla ${ROLE_LABEL[role]} (enemigos).`;
        return;
    }

    btn.disabled = true;
    status.className = 'status';
    status.innerHTML = '<span class="loader"></span>Analizando partida con GPT...';
    result.innerHTML = '';

    const payload = {
        playerChampion,
        playerRole: ROLE_LABEL[role],
        laneOpponent,
        allies:  selection.allies,
        enemies: selection.enemies,
    };

    try {
        const res = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();

        if (!res.ok) {
            throw new Error(data.error || `HTTP ${res.status}`);
        }

        result.innerHTML = renderCoachResponse(data.analysis);
        const usage = data.usage
            ? `${data.usage.total_tokens} tokens · ${data.model} · parche ${data.version}`
            : `${data.model} · parche ${data.version}`;
        result.innerHTML += `<div class="meta">${usage}</div>`;
        status.className = 'status success';
        status.textContent = '✓ Análisis completado';
    } catch (e) {
        status.className = 'status error';
        status.textContent = 'Error: ' + e.message;
        console.error(e);
    } finally {
        btn.disabled = false;
    }
}

// ---------------------------------------------------------------
// COACH RESPONSE RENDERER (JSON estructurado con iconos)
// ---------------------------------------------------------------
function renderCoachResponse(a) {
    if (!a) return '<p style="color:var(--error)">Respuesta vacía.</p>';

    const sections = [];

    // 1. Análisis de líneas
    if (a.analysis) {
        sections.push(`
            <div class="coach-section">
                <h3>Análisis de líneas</h3>
                <div class="analysis-grid">
                    ${a.analysis.matchup ? `<div><strong>Matchup:</strong> ${escapeHtml(a.analysis.matchup)}</div>` : ''}
                    ${a.analysis.gank_risk ? `<div><strong>Ganks:</strong> ${escapeHtml(a.analysis.gank_risk)}</div>` : ''}
                    ${a.analysis.power_spikes ? `<div><strong>Power spikes:</strong> ${escapeHtml(a.analysis.power_spikes)}</div>` : ''}
                </div>
            </div>
        `);
    }

    // 2. Runas + hechizos (columnas)
    if (a.runes || a.summoner_spells_data) {
        sections.push(`
            <div class="coach-section runes-block">
                <h3>Runas y hechizos</h3>
                <div class="runes-layout">
                    ${a.runes ? renderRunes(a.runes) : ''}
                    ${a.summoner_spells_data ? renderSummoners(a.summoner_spells_data) : ''}
                </div>
            </div>
        `);
    }

    // 3. Orden de habilidades
    if (a.skill_order) {
        sections.push(`
            <div class="coach-section">
                <h3>Orden de habilidades</h3>
                ${renderSkillOrder(a.skill_order)}
            </div>
        `);
    }

    // 4. Build
    if (a.build) sections.push(renderBuild(a.build));

    // 5. Macro
    if (a.macro) {
        sections.push(`
            <div class="coach-section">
                <h3>Plan de juego</h3>
                ${a.macro.teamfight_role ? `<p><strong>Rol en teamfights:</strong> ${escapeHtml(a.macro.teamfight_role)}</p>` : ''}
                ${a.macro.win_condition  ? `<p><strong>Condición de victoria:</strong> ${escapeHtml(a.macro.win_condition)}</p>`  : ''}
            </div>
        `);
    }

    // 6. Consejo crítico
    if (a.critical_tip) {
        sections.push(`
            <div class="coach-section critical">
                <div class="critical-icon">💡</div>
                <div><strong>${escapeHtml(a.critical_tip)}</strong></div>
            </div>
        `);
    }

    return sections.join('');
}

// Normaliza nombres (tildes, símbolos, casing) para matchear runas seleccionadas
function normRune(s) {
    if (!s) return '';
    return String(s).toLowerCase()
        .normalize('NFD').replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9]/g, '');
}

function findTree(name) {
    const key = normRune(name);
    return RUNE_TREES.find(t => normRune(t.name) === key || normRune(t.key) === key) || null;
}

function renderRunes(r) {
    if (!RUNE_TREES.length) {
        // Fallback: si aún no cargó el árbol, mostramos versión compacta antigua
        return renderRunesCompact(r);
    }
    const primaryTree   = findTree(r.primary_tree)   || findTree(r.primary_tree_data?.name);
    const secondaryTree = findTree(r.secondary_tree) || findTree(r.secondary_tree_data?.name);

    const selectedKeystone = normRune(r.keystone);
    const selectedPrimary  = (r.primary_runes || []).map(normRune);
    const selectedSecondary = (r.secondary_runes || []).map(normRune);

    // Slot 0 = keystones; slots 1-3 = runas
    const renderPrimaryTree = (tree) => {
        if (!tree) return '<div style="color:var(--muted);font-size:0.85rem">Árbol no reconocido</div>';
        return `
            <div class="rune-tree primary">
                <div class="rune-tree-header">
                    <img src="${tree.icon}" alt="" class="rt-icon">
                    <span class="rt-name">${escapeHtml(tree.name)}</span>
                </div>
                ${tree.slots.map((slot, slotIdx) => {
                    const isKeystoneRow = slotIdx === 0;
                    return `
                        <div class="rune-slot ${isKeystoneRow ? 'keystones' : ''}">
                            ${slot.map(rune => {
                                const active = isKeystoneRow
                                    ? normRune(rune.name) === selectedKeystone
                                    : selectedPrimary.includes(normRune(rune.name));
                                return `
                                    <div class="rune-node ${active ? 'active' : ''}" title="${escapeHtml(rune.name)}">
                                        <img src="${rune.icon}" alt="${escapeHtml(rune.name)}">
                                    </div>
                                `;
                            }).join('')}
                        </div>
                    `;
                }).join('')}
            </div>
        `;
    };

    const renderSecondaryTree = (tree) => {
        if (!tree) return '<div style="color:var(--muted);font-size:0.85rem">Árbol no reconocido</div>';
        // Secondary: solo slots 1-3 (no keystone)
        return `
            <div class="rune-tree secondary">
                <div class="rune-tree-header">
                    <img src="${tree.icon}" alt="" class="rt-icon">
                    <span class="rt-name">${escapeHtml(tree.name)}</span>
                </div>
                ${tree.slots.slice(1).map(slot => `
                    <div class="rune-slot">
                        ${slot.map(rune => {
                            const active = selectedSecondary.includes(normRune(rune.name));
                            return `
                                <div class="rune-node ${active ? 'active' : ''}" title="${escapeHtml(rune.name)}">
                                    <img src="${rune.icon}" alt="${escapeHtml(rune.name)}">
                                </div>
                            `;
                        }).join('')}
                    </div>
                `).join('')}
            </div>
        `;
    };

    // Fragmentos (shards) — mapeamos el label seleccionado a un índice por fila
    const shardMap = {
        offense: normRune(r.shards?.[0] || ''),
        flex:    normRune(r.shards?.[1] || ''),
        defense: normRune(r.shards?.[2] || ''),
    };
    // Alias genéricos → índice por defecto (por fila)
    const genericToIdx = { ofensivo: 0, ofensiva: 0, offense: 0, flexible: 0, flex: 0, defensivo: 0, defensiva: 0, defense: 0 };
    const renderShardRow = (rowKey, rowIdx) => {
        const options = RUNE_SHARDS[rowKey] || [];
        const selectedNorm = shardMap[rowKey];
        // Determinar índice activo
        let activeIdx = options.findIndex(sh => normRune(sh.name) === selectedNorm);
        if (activeIdx < 0 && selectedNorm in genericToIdx) activeIdx = genericToIdx[selectedNorm];
        if (activeIdx < 0 && selectedNorm) {
            // matching parcial por palabras clave
            activeIdx = options.findIndex(sh => normRune(sh.name).includes(selectedNorm) || selectedNorm.includes(normRune(sh.name)));
        }
        if (activeIdx < 0) activeIdx = 0; // por defecto activar el primero si no matcheó

        return options.map((sh, i) => `
            <div class="rune-node shard ${i === activeIdx ? 'active' : ''}" title="${escapeHtml(sh.name)}">
                <img src="${sh.icon}" alt="${escapeHtml(sh.name)}">
            </div>
        `).join('');
    };

    return `
        <div class="rune-grid">
            ${renderPrimaryTree(primaryTree)}
            ${renderSecondaryTree(secondaryTree)}
            <div class="rune-tree shards-tree">
                <div class="rune-tree-header"><span class="rt-name">Fragmentos</span></div>
                <div class="rune-slot">${renderShardRow('offense', 0)}</div>
                <div class="rune-slot">${renderShardRow('flex', 1)}</div>
                <div class="rune-slot">${renderShardRow('defense', 2)}</div>
            </div>
        </div>
    `;
}

// Versión compacta antigua (fallback si el árbol aún no cargó)
function renderRunesCompact(r) {
    return `
        <div class="runes-col">
            <div class="rune-primary">
                ${r.primary_tree_data?.icon ? `<img class="tree-icon" src="${r.primary_tree_data.icon}" alt="">` : ''}
                <div class="tree-name">${escapeHtml(r.primary_tree || '')}</div>
                <div class="keystone">
                    ${r.keystone_data?.icon ? `<img src="${r.keystone_data.icon}" alt="" class="keystone-icon">` : '<div class="keystone-icon missing"></div>'}
                    <span>${escapeHtml(r.keystone || '')}</span>
                </div>
            </div>
        </div>
    `;
}

function renderSummoners(list) {
    return `
        <div class="summoners-col">
            <div class="col-title">Hechizos</div>
            <div class="summoner-icons">
                ${list.map(s => `
                    <div class="summoner-slot">
                        ${s.icon ? `<img src="${s.icon}" alt="">` : '<div class="missing-icon"></div>'}
                        <span>${escapeHtml(s.name || '')}</span>
                    </div>
                `).join('')}
            </div>
        </div>
    `;
}

function renderSkillOrder(sk) {
    const priority = sk.priority || [];
    const seq = sk.sequence || [];

    // grid 4×18
    const skills = ['Q','W','E','R'];
    const grid = skills.map(row => {
        const cells = [];
        for (let lvl = 1; lvl <= 18; lvl++) {
            const active = seq[lvl - 1] === row;
            cells.push(`<div class="skill-cell${active ? ' active ' + row.toLowerCase() : ''}">${active ? lvl : ''}</div>`);
        }
        return `<div class="skill-row"><span class="skill-label">${row}</span>${cells.join('')}</div>`;
    }).join('');

    const priorityDisplay = priority.length
        ? priority.map((k, i) => `<span class="prio-cell ${k.toLowerCase()}">${k}</span>${i < priority.length - 1 ? '<span class="prio-arrow">›</span>' : ''}`).join('')
        : '';

    return `
        ${priorityDisplay ? `<div class="skill-priority"><span class="col-title">Prioridad de maxeo</span><div class="prio-row">${priorityDisplay}</div></div>` : ''}
        <div class="skill-grid">
            <div class="skill-row header"><span class="skill-label"></span>${Array.from({length:18},(_,i)=>`<div class="skill-cell-header">${i+1}</div>`).join('')}</div>
            ${grid}
        </div>
    `;
}

function renderBuild(b) {
    const itemRow = (list) => (list || []).map(it => `
        <div class="build-item" title="${escapeHtml(it.name)}${it.gold ? ' · ' + it.gold + 'g' : ''}">
            ${it.icon ? `<img src="${it.icon}" alt="">` : '<div class="missing-icon"></div>'}
            <span class="build-item-name">${escapeHtml(it.name)}</span>
            ${it.gold ? `<span class="build-item-gold">${it.gold}g</span>` : ''}
        </div>
    `).join('');

    const arrowRow = (list) => (list || []).map((it, i) => `
        ${i > 0 ? '<span class="build-arrow">›</span>' : ''}
        <div class="build-item compact" title="${escapeHtml(it.name)}">
            ${it.icon ? `<img src="${it.icon}" alt="">` : '<div class="missing-icon"></div>'}
        </div>
    `).join('');

    return `
        <div class="coach-section">
            <h3>Build</h3>

            ${b.starting_items_data?.length ? `
                <div class="build-sub">
                    <div class="col-title">Starting items</div>
                    <div class="build-row">${itemRow(b.starting_items_data)}</div>
                </div>` : ''}

            ${b.first_back_data?.length ? `
                <div class="build-sub">
                    <div class="col-title">Primer back</div>
                    <div class="build-row">${itemRow(b.first_back_data)}</div>
                </div>` : ''}

            ${b.core_items_data?.length ? `
                <div class="build-sub">
                    <div class="col-title">Build path (core)</div>
                    <div class="build-row arrows">${arrowRow(b.core_items_data)}</div>
                    <div class="build-row wrap">${itemRow(b.core_items_data)}</div>
                </div>` : ''}

            ${b.boots_data?.name ? `
                <div class="build-sub">
                    <div class="col-title">Botas</div>
                    <div class="build-row">${itemRow([b.boots_data])}</div>
                </div>` : ''}

            ${b.situational_data?.length ? `
                <div class="build-sub">
                    <div class="col-title">Situacionales</div>
                    <div class="build-row wrap">${itemRow(b.situational_data)}</div>
                </div>` : ''}

            ${b.counter_items?.length ? `
                <div class="build-sub">
                    <div class="col-title">Contra picks concretos</div>
                    ${b.counter_items.map(ci => `
                        <div class="counter-row">
                            ${ci.icon ? `<img src="${ci.icon}" alt="">` : '<div class="missing-icon"></div>'}
                            <div>
                                <div><strong>${escapeHtml(ci.name)}</strong> <span class="counter-target">→ ${escapeHtml(ci.target || '')}</span></div>
                                <div class="counter-reason">${escapeHtml(ci.reason || '')}</div>
                            </div>
                        </div>
                    `).join('')}
                </div>` : ''}
        </div>
    `;
}

// --- Old markdown renderer (por si acaso lo usamos en otro sitio) ---
function renderMarkdown(md) {
    if (!md) return '';
    const esc = (s) => s.replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));

    const lines = md.split('\n');
    const out = [];
    let inList = false;

    const flushList = () => { if (inList) { out.push('</ul>'); inList = false; } };
    const inline = (s) => esc(s)
        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
        .replace(/\*(.+?)\*/g, '<em>$1</em>')
        .replace(/`(.+?)`/g, '<code>$1</code>');

    for (const raw of lines) {
        const line = raw.trimEnd();
        if (!line.trim()) { flushList(); continue; }

        const h = line.match(/^(#{1,6})\s+(.+)$/);
        if (h) { flushList(); out.push(`<h${h[1].length}>${inline(h[2])}</h${h[1].length}>`); continue; }

        const bq = line.match(/^>\s*(.+)$/);
        if (bq) { flushList(); out.push(`<blockquote>${inline(bq[1])}</blockquote>`); continue; }

        const li = line.match(/^\s*[-*]\s+(.+)$/);
        if (li) {
            if (!inList) { out.push('<ul>'); inList = true; }
            out.push(`<li>${inline(li[1])}</li>`);
            continue;
        }

        flushList();
        out.push(`<p>${inline(line)}</p>`);
    }
    flushList();
    return out.join('\n');
}

// ---------------------------------------------------------------
// BUILDS TAB (Champion Builds — meta stats)
// ---------------------------------------------------------------
let builderChampionValue = null;

function initBuilds() {
    document.getElementById('loadBuildBtn').addEventListener('click', loadChampionBuild);

    // Poblar el select de región del builder: Global primero, luego regiones individuales
    setTimeout(async () => {
        try {
            const r = await fetch('regions.php').then(r => r.json());
            const sel = document.getElementById('builderRegion');
            if (sel && r.regions?.length) {
                const globalOpt = '<option value="global" selected>🌍 Global</option>';
                const regionOpts = r.regions.map(reg =>
                    `<option value="${reg.code}" title="${reg.label}">${reg.code.toUpperCase()}</option>`
                ).join('');
                sel.innerHTML = globalOpt + regionOpts;
            }
        } catch (e) {}
    }, 300);
}

async function loadChampionBuild() {
    const status = document.getElementById('buildsStatus');
    const result = document.getElementById('buildsResult');
    const role   = document.getElementById('builderRole').value;
    const region = document.getElementById('builderRegion')?.value || 'global';
    const rank   = document.getElementById('builderRank')?.value   || 'challenger';

    if (!builderChampionValue) {
        status.className = 'status error';
        status.textContent = 'Selecciona un campeón.';
        return;
    }

    const rankLabels = {
        all: 'All Tiers', challenger: 'Challenger', grandmaster: 'Grandmaster',
        master: 'Master', masterplus: 'Master+',
        diamond: 'Diamond', diamondplus: 'Diamond+',
        emerald: 'Emerald', emeraldplus: 'Emerald+',
        platinum: 'Platinum', platinumplus: 'Platinum+',
        gold: 'Gold', goldplus: 'Gold+',
        silver: 'Silver', bronze: 'Bronze', iron: 'Iron',
    };
    const regLabel = region === 'global' ? 'Global (EUW+NA+KR)' : region.toUpperCase();
    status.className = 'status';
    status.innerHTML = `<span class="loader"></span>Analizando hasta 250 partidas ${rankLabels[rank]} de ${regLabel}... (primera vez: 4–6 min por rate limit de Riot dev key; después instantáneo por 12h)`;
    result.innerHTML = '';

    try {
        const url = `champion_stats.php?champion=${encodeURIComponent(builderChampionValue)}&role=${encodeURIComponent(role)}&region=${encodeURIComponent(region)}&rank=${encodeURIComponent(rank)}`;
        const res = await fetch(url);
        const data = await res.json();

        if (data.error && !data.stats) {
            throw new Error(data.error);
        }

        result.innerHTML = renderChampionStats(data.stats);
        wireVariantTabs();
        const src = data.stats?.data_source;
        const cache = res.headers.get('X-Cache') === 'HIT' ? ' · cache' : '';
        status.className = 'status success';
        status.textContent = src
            ? `✓ ${data.stats.games} partidas del campeón analizadas (${src.matches_inspected} inspeccionadas) · parche ${data.stats.patch}${cache}`
            : `✓ Build cargada · parche ${data.stats.patch}${cache}`;
    } catch (e) {
        status.className = 'status error';
        status.textContent = 'Error: ' + e.message;
        console.error(e);
    }
}

function renderChampionStats(s) {
    if (!s) return '<p style="color:var(--error)">Sin datos.</p>';
    const tierClass = 'tier-' + (s.tier || 'A').replace('+', '\\+');

    const variants = s.build_variants || [];
    const variantsHtml = variants.length ? `
        <div class="variant-tabs">
            ${variants.map((v, i) => `
                <button class="variant-tab${i === 0 ? ' active' : ''}" data-variant="${i}">
                    ${v.main_item_data?.icon ? `<img src="${v.main_item_data.icon}" alt="">` : '<div class="missing-icon" style="width:32px;height:32px"></div>'}
                    <span class="vt-pct">${v.pick_rate ?? '?'}%</span>
                    ${v.label ? `<span class="vt-label">${escapeHtml(v.label)}</span>` : ''}
                </button>
            `).join('')}
        </div>
        <div id="variantHost">${renderBuildVariant(variants[0], s.skill_order)}</div>
    ` : '<p style="color:var(--muted)">Sin variantes de build disponibles.</p>';

    const ds = s.data_source || {};
    const rankLabels = { challenger: 'Challenger', grandmaster: 'Grandmaster', master: 'Master', masterplus: 'Master+' };
    const rankLabel  = rankLabels[ds.rank_filter] || 'Challenger';
    const regLabel   = ds.is_global
        ? `Global (${(ds.regions_sampled || []).map(r => r.toUpperCase()).join(' + ')})`
        : (s.region || '').toUpperCase();
    const dataSourceBanner = ds.type ? `
        <div class="data-source-banner">
            <span class="ds-icon">📊</span>
            <div class="ds-text">
                <strong>Datos reales</strong> · <strong>${ds.matches_inspected}</strong> partidas SoloQ Ranked de <strong>${rankLabel}</strong> · <strong>${regLabel}</strong>.
                <span class="ds-count">${s.games} partidas del campeón analizadas (top ${ds.top_players_sampled} jugadores muestreados).</span>
            </div>
        </div>
    ` : '';

    const html = `
        <div class="build-header">
            <div class="champ-avatar"><img src="${s.champion_icon}" alt=""></div>
            <div class="champ-info">
                <h2>${escapeHtml(s.champion)}</h2>
                <div class="role">${escapeHtml((s.role || '').toUpperCase())} · ${(s.region || '').toUpperCase()}</div>
            </div>
            <div class="tier-badges">
                <div class="tier-badge ${tierClass}"><div class="tb-label">Tier</div><div class="tb-value">${escapeHtml(s.tier || '?')}</div></div>
                <div class="tier-badge"><div class="tb-label">Win rate</div><div class="tb-value">${s.win_rate ?? '?'}%</div></div>
                <div class="tier-badge"><div class="tb-label">Pick rate</div><div class="tb-value">${s.pick_rate ?? '?'}%</div></div>
                <div class="tier-badge"><div class="tb-label">Ban rate</div><div class="tb-value">${s.ban_rate ?? '?'}%</div></div>
            </div>
        </div>

        ${dataSourceBanner}

        ${variantsHtml}

        ${s.situational_items?.length ? `<div class="coach-section"><h3>Objetos situacionales frecuentes (globales)</h3>
            <div class="situationals-grid">
                ${s.situational_items.map(sit => `
                    <div class="situational-card" title="${escapeHtml(sit.item_data?.name || '')}">
                        ${sit.item_data?.icon ? `<img src="${sit.item_data.icon}" alt="">` : '<div class="missing-icon"></div>'}
                        <div class="sit-info">
                            <div class="sit-name">${escapeHtml(sit.item_data?.name || '')}</div>
                            ${sit.pick_rate ? `<div class="sit-pr">${sit.pick_rate}% pick</div>` : ''}
                        </div>
                    </div>
                `).join('')}
            </div>
        </div>` : ''}

        ${s.counters?.length ? `<div class="coach-section"><h3>Counters</h3><div class="counters-list">${s.counters.map(c => `
            <div class="counter-champ">
                ${c.champion_icon ? `<img src="${c.champion_icon}" alt="">` : '<div class="missing-icon"></div>'}
                <div class="info">
                    <div class="name">${escapeHtml(c.champion)}</div>
                    <div class="reason">${escapeHtml(c.reason || '')}</div>
                </div>
                ${c.difficulty ? `<span class="diff ${escapeHtml(c.difficulty)}">${escapeHtml(c.difficulty)}</span>` : ''}
            </div>
        `).join('')}</div></div>` : ''}

        ${s.synergies?.length ? `<div class="coach-section"><h3>Sinergias</h3><div class="synergies-list">${s.synergies.map(c => `
            <div class="synergy-champ">
                ${c.champion_icon ? `<img src="${c.champion_icon}" alt="">` : '<div class="missing-icon"></div>'}
                <div class="info">
                    <div class="name">${escapeHtml(c.champion)}</div>
                    <div class="reason">${escapeHtml(c.reason || '')}</div>
                </div>
            </div>
        `).join('')}</div></div>` : ''}

        ${s.tips?.length ? `<div class="coach-section"><h3>Tips avanzados</h3><ul class="tips-list">${s.tips.map(t => `<li>${escapeHtml(t)}</li>`).join('')}</ul></div>` : ''}
    `;

    // Guardamos datos para que switchVariant pueda usarlos
    window._buildsData = { variants, skill_order: s.skill_order };
    return html;
}

function renderBuildVariant(v, defaultSkillOrder) {
    if (!v) return '';

    return `
        <div class="variant-content">
            <div class="build-grid">
                <div>
                    ${v.runes ? `<div class="coach-section"><h3>Runas</h3>${renderRunes(v.runes)}</div>` : ''}
                    ${v.summoner_spells ? `<div class="coach-section"><h3>Hechizos de invocador</h3>
                        <div class="rune-option">
                            <div class="option-header">
                                ${v.summoner_spells.pick_rate != null ? `<span class="pill pr">${v.summoner_spells.pick_rate}% pick</span>` : ''}
                                ${v.summoner_spells.win_rate  != null ? `<span class="pill wr">${v.summoner_spells.win_rate}% WR</span>` : ''}
                            </div>
                            <div class="summoner-icons">
                                ${(v.summoner_spells.spells_data || []).map(x => `
                                    <div class="summoner-slot">
                                        ${x.icon ? `<img src="${x.icon}" alt="">` : '<div class="missing-icon"></div>'}
                                        <span>${escapeHtml(x.name || '')}</span>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    </div>` : ''}
                    ${defaultSkillOrder ? `<div class="coach-section"><h3>Orden de habilidades</h3>${renderSkillOrder(defaultSkillOrder)}</div>` : ''}
                </div>

                <div>
                    ${v.starting_sets?.length ? `<div class="coach-section"><h3>Starting items</h3>
                        ${v.starting_sets.map(si => `
                            <div class="build-option">
                                <div class="option-header">
                                    ${si.pick_rate != null ? `<span class="pill pr">${si.pick_rate}% pick</span>` : ''}
                                    ${si.win_rate  != null ? `<span class="pill wr">${si.win_rate}% WR</span>` : ''}
                                </div>
                                <div class="build-row wrap">${(si.items_data || []).map(renderItemMini).join('')}</div>
                            </div>
                        `).join('')}
                    </div>` : ''}

                    ${v.core_paths?.length ? `<div class="coach-section"><h3>Build path (core)</h3>
                        ${v.core_paths.map(cb => `
                            <div class="build-option">
                                <div class="option-header">
                                    ${cb.pick_rate != null ? `<span class="pill pr">${cb.pick_rate}% pick</span>` : ''}
                                    ${cb.win_rate  != null ? `<span class="pill wr">${cb.win_rate}% WR</span>` : ''}
                                </div>
                                <div class="build-row arrows">${(cb.items_data || []).map((it, i) => `
                                    ${i > 0 ? '<span class="build-arrow">›</span>' : ''}
                                    <div class="build-item compact" title="${escapeHtml(it.name)}">
                                        ${it.icon ? `<img src="${it.icon}" alt="">` : '<div class="missing-icon"></div>'}
                                    </div>
                                `).join('')}</div>
                                <div class="build-row wrap" style="margin-top:8px">${(cb.items_data || []).map(renderItemMini).join('')}</div>
                            </div>
                        `).join('')}
                    </div>` : ''}

                    ${v.boots?.length ? `<div class="coach-section"><h3>Botas</h3>
                        ${v.boots.map(b => `
                            <div class="build-option">
                                <div class="option-header">
                                    ${b.pick_rate != null ? `<span class="pill pr">${b.pick_rate}% pick</span>` : ''}
                                    ${b.win_rate  != null ? `<span class="pill wr">${b.win_rate}% WR</span>` : ''}
                                </div>
                                <div class="build-row">${b.item_data ? renderItemMini(b.item_data) : ''}</div>
                            </div>
                        `).join('')}
                    </div>` : ''}

                    ${v.situational_items?.length ? `<div class="coach-section"><h3>Situacionales</h3>
                        ${v.situational_items.map(sit => `
                            <div class="build-option">
                                <div class="build-row">
                                    ${sit.item_data ? renderItemMini(sit.item_data) : ''}
                                    ${sit.reason ? `<span style="color:var(--muted);font-size:0.82rem;margin-left:8px">${escapeHtml(sit.reason)}</span>` : ''}
                                </div>
                            </div>
                        `).join('')}
                    </div>` : ''}
                </div>
            </div>
        </div>
    `;
}

function wireVariantTabs() {
    const host = document.getElementById('variantHost');
    document.querySelectorAll('.variant-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            const idx = parseInt(btn.dataset.variant, 10);
            const data = window._buildsData;
            if (!data || !data.variants[idx]) return;
            document.querySelectorAll('.variant-tab').forEach(b => b.classList.toggle('active', b === btn));
            host.innerHTML = renderBuildVariant(data.variants[idx], data.skill_order);
        });
    });
}

function renderItemMini(it) {
    return `
        <div class="build-item" title="${escapeHtml(it.name)}${it.gold?' · '+it.gold+'g':''}">
            ${it.icon ? `<img src="${it.icon}" alt="">` : '<div class="missing-icon"></div>'}
            <span class="build-item-name">${escapeHtml(it.name)}</span>
            ${it.gold ? `<span class="build-item-gold">${it.gold}g</span>` : ''}
        </div>
    `;
}

// ---------------------------------------------------------------
// SEARCH TAB (buscador de jugador EUW)
// ---------------------------------------------------------------
let LAST_PLAYER = null; // { puuid, gameName, tagLine, region }
let LAST_LIVE   = null; // datos de live game

async function initSearch() {
    const input = document.getElementById('riotIdInput');
    document.getElementById('searchBtn').addEventListener('click', runSearch);
    input.addEventListener('keydown', (e) => { if (e.key === 'Enter') runSearch(); });

    // Cargar lista de regiones
    try {
        const r = await fetch('regions.php').then(r => r.json());
        const sel = document.getElementById('regionSelect');
        sel.innerHTML = r.regions.map(reg =>
            `<option value="${reg.code}"${reg.code === 'euw' ? ' selected' : ''} title="${reg.label}">${reg.code.toUpperCase()}</option>`
        ).join('');
    } catch (e) {
        console.warn('No se pudieron cargar regiones', e);
    }
}

async function runSearch() {
    const raw = document.getElementById('riotIdInput').value.trim();
    const region = document.getElementById('regionSelect').value || 'euw';
    const status = document.getElementById('searchStatus');
    const card   = document.getElementById('playerCard');

    if (!raw.includes('#')) {
        status.className = 'status error';
        status.textContent = 'Formato: Nombre#TAG (ej: Faker#KR1)';
        return;
    }

    status.className = 'status';
    status.innerHTML = '<span class="loader"></span>Consultando Riot API (' + region.toUpperCase() + ')...';
    card.innerHTML = '';

    try {
        const url = `player.php?riotId=${encodeURIComponent(raw)}&region=${region}`;
        const res = await fetch(url);
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);

        LAST_PLAYER = { puuid: data.account.puuid, gameName: data.account.gameName, tagLine: data.account.tagLine, region };
        renderPlayer(data);

        status.className = 'status success';
        status.textContent = '✓ Datos cargados desde ' + region.toUpperCase();

        // Segunda llamada: comprobar si está en partida
        checkLiveGame(LAST_PLAYER.puuid, region);
    } catch (e) {
        status.className = 'status error';
        status.textContent = 'Error: ' + e.message;
        console.error(e);
    }
}

function renderPlayer(data) {
    const card = document.getElementById('playerCard');
    card.innerHTML = '';

    // Header
    const hdr = document.createElement('div');
    hdr.className = 'player-header';
    hdr.innerHTML = `
        <div class="avatar"><img src="${data.summoner?.profileIcon || ''}" alt=""></div>
        <div class="who">
            <h2>${escapeHtml(data.account.gameName)}<span class="tag">#${escapeHtml(data.account.tagLine)}</span></h2>
            <div class="level">Nivel ${data.summoner?.level ?? '?'} · ${(data.region || '').toUpperCase()}</div>
        </div>
    `;
    card.appendChild(hdr);

    // Live game placeholder
    const liveHost = document.createElement('div');
    liveHost.id = 'liveHost';
    card.appendChild(liveHost);

    // Ranks con emblema
    if (data.ranks?.length) {
        const s = document.createElement('div');
        s.className = 'section-block';
        s.innerHTML = '<h3>Rango</h3><div class="ranks">' + data.ranks.map(r => `
            <div class="rank-card">
                ${r.tier ? `<img src="${r.emblem}" alt="" class="emblem" onerror="this.classList.add('unknown');this.src=''">` : '<div class="emblem unknown"></div>'}
                <div>
                    <div class="queue">${queueLabel(r.queue)}</div>
                    <div class="tier">${escapeHtml(r.tier || 'Unranked')} ${escapeHtml(r.rank || '')}</div>
                    <div class="lp">${r.lp} LP</div>
                </div>
                <div class="wr">
                    <strong>${r.winrate}%</strong>
                    ${r.wins}W · ${r.losses}L
                </div>
                ${r.hotStreak ? '<span class="streak">🔥 Racha</span>' : ''}
            </div>
        `).join('') + '</div>';
        card.appendChild(s);
    }

    // Champion Pool (WR por campeón últimas 20 partidas)
    if (data.champPool?.length) {
        const s = document.createElement('div');
        s.className = 'section-block';
        s.innerHTML = '<h3>Champion pool (últimas 20)</h3><div class="champpool-grid">' + data.champPool.slice(0, 8).map(c => {
            const wrClass = c.winrate >= 60 ? 'high' : c.winrate >= 45 ? 'mid' : 'low';
            const wrColor = c.winrate >= 60 ? '#4ade80' : c.winrate >= 45 ? '#facc15' : '#f87171';
            return `
                <div class="champpool-card" style="--wr-width:${c.winrate}%;--wr-color:${wrColor}">
                    <img src="${c.icon}" alt="">
                    <div class="cp-info">
                        <div class="cp-name">${escapeHtml(c.name)}</div>
                        <div class="cp-stats">${c.games} juegos · ${c.avg_kills}/${c.avg_deaths}/${c.avg_assists} · KDA ${c.kda}</div>
                    </div>
                    <div class="cp-wr ${wrClass}">${c.winrate}%</div>
                </div>`;
        }).join('') + '</div>';
        card.appendChild(s);
    }

    // Mastery (top 10 visualmente)
    if (data.mastery?.length) {
        const s = document.createElement('div');
        s.className = 'section-block';
        s.innerHTML = '<h3>Maestría (top 10)</h3><div class="mastery-grid">' + data.mastery.slice(0, 10).map(m => `
            <div class="mastery-card">
                <img src="${m.icon || ''}" alt="">
                <div class="info">
                    <div class="cname">${escapeHtml(m.name)}</div>
                    <div class="cmeta">M${m.level} · ${formatPoints(m.points)}</div>
                </div>
            </div>
        `).join('') + '</div>';
        card.appendChild(s);
    }

    // Duos (aliados frecuentes)
    if (data.duos?.length) {
        const s = document.createElement('div');
        s.className = 'section-block';
        s.innerHTML = '<h3>Aliados frecuentes (duos)</h3><div class="duos-list">' + data.duos.map(d => {
            const initial = (d.riotId || '?').charAt(0).toUpperCase();
            const wrClass = d.winrate >= 55 ? 'high' : d.winrate <= 45 ? 'low' : '';
            return `
                <div class="duo-card">
                    <div class="duo-icon">${initial}</div>
                    <div class="duo-info">
                        <div class="duo-name">${escapeHtml(d.riotId)}</div>
                        <div class="duo-stats">${d.games} juegos juntos</div>
                    </div>
                    <div class="duo-wr ${wrClass}">${d.winrate}%</div>
                </div>`;
        }).join('') + '</div>';
        card.appendChild(s);
    }

    // Matches enriquecidas
    if (data.matches?.length) {
        const s = document.createElement('div');
        s.className = 'section-block';
        s.innerHTML = '<h3>Últimas partidas</h3>' + data.matches.slice(0, 15).map(m => `
            <div class="match-row ${m.win ? 'win' : 'loss'}">
                <div class="result-bar"></div>
                <img src="${m.championIcon || ''}" alt="" class="champ">
                <div>
                    <div class="mchamp">${escapeHtml(m.champion)}${m.role ? ' · ' + m.role : ''}</div>
                    <div class="mmode">${queueLabel(m.gameMode)} · ${Math.round(m.durationS/60)}m · ${timeAgo(m.endedAt)}</div>
                    <div class="m-extras">
                        <span><span class="lbl">CS/m</span> ${m.csPerMin}</span>
                        <span><span class="lbl">KP</span> ${m.kp}%</span>
                        <span><span class="lbl">DMG</span> ${m.damageShare}%</span>
                    </div>
                </div>
                <div class="m-summs">
                    ${m.summoners?.[0]?.icon ? `<img src="${m.summoners[0].icon}" title="${escapeHtml(m.summoners[0].name)}">` : '<div style="width:18px;height:18px"></div>'}
                    ${m.summoners?.[1]?.icon ? `<img src="${m.summoners[1].icon}" title="${escapeHtml(m.summoners[1].name)}">` : '<div style="width:18px;height:18px"></div>'}
                </div>
                <div class="m-items">
                    ${(m.items || []).map(it => it && it.icon
                        ? `<div class="slot"><img src="${it.icon}" title="${escapeHtml(it.name)}"></div>`
                        : '<div class="slot empty"></div>').join('')}
                </div>
                <div class="mkda">
                    ${m.kills}/${m.deaths}/${m.assists}
                    <div class="kdaval">${m.kda} KDA</div>
                </div>
                <div class="mresult">${m.win ? 'Victoria' : 'Derrota'}</div>
            </div>
        `).join('');
        card.appendChild(s);
    }
}

function timeAgo(ts) {
    if (!ts) return '';
    const diff = Date.now() - ts;
    const mins = Math.floor(diff / 60000);
    if (mins < 60) return `hace ${mins}m`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return `hace ${hrs}h`;
    const days = Math.floor(hrs / 24);
    return `hace ${days}d`;
}

async function checkLiveGame(puuid, region) {
    const host = document.getElementById('liveHost');
    if (!host) return;
    host.innerHTML = '<div class="status"><span class="loader"></span>Comprobando partida en vivo (esto tarda un poco: 20+ llamadas a Riot API para los 10 jugadores)...</div>';

    try {
        const res = await fetch(`live_game.php?puuid=${encodeURIComponent(puuid)}&region=${encodeURIComponent(region)}`);
        if (res.status === 404) {
            host.innerHTML = '<div class="status" style="text-align:center;margin-top:12px;">📴 No está en partida ahora mismo</div>';
            return;
        }
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);

        LAST_LIVE = data;
        renderLiveGame(host, data);
    } catch (e) {
        host.innerHTML = `<div class="status error">Live game: ${escapeHtml(e.message)}</div>`;
    }
}

function renderLiveGame(host, data) {
    const mySide = data.mySide;
    const myLane = findLaneOf(data.teams[mySide], LAST_PLAYER.puuid);

    host.innerHTML = `
        <div class="live-banner">
            <span class="pulse"></span>
            <div class="info">
                <strong>En partida ahora</strong>
                <small>${data.gameMode} · llevan ${Math.floor(data.lengthS/60)}m · lado ${mySide === 'blue' ? 'azul' : 'rojo'} · carril ${myLane.toUpperCase()}</small>
            </div>
            <div class="live-header-actions">
                <button id="coachLiveBtn" class="btn-coach">🎯 Coach IA de esta partida</button>
                <button id="loadIntoCoachBtn" class="btn-simple">Solo cargar picks</button>
            </div>
        </div>
        <div class="live-teams">
            <div class="live-team blue">
                <h4>Lado azul${mySide === 'blue' ? ' (tú)' : ''}</h4>
                ${renderLiveTeam(data.teams.blue, LAST_PLAYER.puuid)}
            </div>
            <div class="live-team red">
                <h4>Lado rojo${mySide === 'red' ? ' (tú)' : ''}</h4>
                ${renderLiveTeam(data.teams.red, LAST_PLAYER.puuid)}
            </div>
        </div>
        <p class="status" style="text-align:left;font-size:0.75rem;margin-top:6px;">${escapeHtml(data.note || '')}</p>
        <div id="liveCoachResult"></div>
    `;

    document.getElementById('coachLiveBtn').addEventListener('click', () => runCoachFromLive(myLane));
    document.getElementById('loadIntoCoachBtn').addEventListener('click', () => loadLiveIntoCoach(data, myLane));
}

function findLaneOf(teamMap, puuid) {
    for (const [lane, p] of Object.entries(teamMap)) {
        if (p && p.puuid === puuid) return lane;
    }
    return 'mid';
}

function renderLiveTeam(team, myPuuid) {
    const lanes = ['top','jng','mid','adc','supp'];
    return lanes.map(lane => {
        const p = team[lane];
        if (!p) return `<div class="live-player"><span class="role-tag">${lane.toUpperCase()}</span><div></div><div class="lp-name"><em style="color:var(--dim)">—</em></div><div></div><div></div></div>`;
        const mine = p.puuid === myPuuid;
        const kIcon = p.keystone?.icon;
        const s1 = p.summonerSpells?.[0];
        const s2 = p.summonerSpells?.[1];
        const rank = p.rank ? `${p.rank.tier} ${p.rank.rank}` : 'Unranked';
        const rankEmblem = p.rank?.emblem;
        const mastery = p.mastery ? `M${p.mastery.level} · ${formatPoints(p.mastery.points)}` : '';
        return `
            <div class="live-player${mine ? ' mine' : ''}">
                <span class="role-tag">${lane.toUpperCase()}</span>
                <img class="champ" src="${p.icon || ''}" alt="">
                <div class="lp-name">
                    <div class="cn">${escapeHtml(p.name)}${mastery ? ` <span class="lp-mastery">${mastery}</span>` : ''}</div>
                    <div class="rn">${escapeHtml(p.riotId || '')}</div>
                </div>
                <div class="lp-runes-summs">
                    ${kIcon ? `<img src="${kIcon}" class="keystone-mini" title="${escapeHtml(p.keystone?.name || '')}">` : '<div class="keystone-mini"></div>'}
                    ${s1?.icon ? `<img src="${s1.icon}" title="${escapeHtml(s1.name)}">` : '<div style="width:20px;height:20px"></div>'}
                    <div></div>
                    ${s2?.icon ? `<img src="${s2.icon}" title="${escapeHtml(s2.name)}">` : '<div style="width:20px;height:20px"></div>'}
                </div>
                <div class="lp-rank">
                    ${rankEmblem ? `<img src="${rankEmblem}" alt="" onerror="this.style.display='none'">` : ''}
                    <span>${escapeHtml(rank)}</span>
                </div>
            </div>
        `;
    }).join('');
}

async function runCoachFromLive(myLane) {
    if (!LAST_PLAYER || !LAST_LIVE) return;
    const btn = document.getElementById('coachLiveBtn');
    const resultHost = document.getElementById('liveCoachResult');
    btn.disabled = true;
    btn.textContent = '⏳ Analizando con IA (con datos reales de la partida)...';
    resultHost.innerHTML = '<div class="status"><span class="loader"></span>El coach está razonando con las runas, hechizos, rangos y mastery reales de los 10 jugadores...</div>';

    try {
        const res = await fetch('coach_from_live.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                region: LAST_PLAYER.region,
                puuid:  LAST_PLAYER.puuid,
                myLane: myLane,
            }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || `HTTP ${res.status}`);

        resultHost.innerHTML = `
            <div class="coach-result-panel">
                <h3 class="result-title">🎯 Coach IA · Análisis con datos reales de tu partida</h3>
                ${renderCoachResponse(data.analysis)}
                ${data.usage ? `<div class="meta">${data.usage.total_tokens} tokens · ${data.model} · parche ${data.version}</div>` : ''}
            </div>
        `;
        btn.textContent = '✓ Analizado';
    } catch (e) {
        resultHost.innerHTML = `<div class="status error">Error: ${escapeHtml(e.message)}</div>`;
        btn.disabled = false;
        btn.textContent = '🎯 Coach IA de esta partida';
    }
}

function loadLiveIntoCoach(liveData, myLane) {
    // 1. Determinar aliados/enemigos según lado
    const mySide  = liveData.mySide;
    const oppSide = mySide === 'blue' ? 'red' : 'blue';

    // 2. Poblar los pickers usando la instancia global de ChampionPicker
    fillTeam('allies',  liveData.teams[mySide]);
    fillTeam('enemies', liveData.teams[oppSide]);

    // 3. Fijar el rol
    const roleSel = document.getElementById('playerRole');
    roleSel.value = myLane;
    roleSel.dispatchEvent(new Event('change'));

    // 4. Cambiar a la pestaña de coach
    switchTab('coach');

    // 5. Feedback
    const status = document.getElementById('status');
    status.className = 'status success';
    status.textContent = '✓ Composición cargada desde la partida en vivo. Ajusta carriles si es necesario y pulsa Analizar.';
}

function fillTeam(teamKey, laneMap) {
    for (const [lane, p] of Object.entries(laneMap)) {
        if (!p || !p.championKey) continue;
        const pickerEl = document.querySelector(`.picker[data-target="${teamKey}.${lane}"]`);
        if (!pickerEl) continue;
        // Buscamos la instancia via query DOM y la re-inicializamos vía select programático
        const champ = CHAMPIONS.find(c => c.id === p.championKey);
        if (!champ) continue;
        // Renderizamos el estado seleccionado directamente
        const inp = pickerEl.querySelector('.picker-input');
        inp.innerHTML = `
            <img src="${champ.icon}" alt="">
            <span class="name">${champ.name}</span>
            <span class="clear" title="Quitar">×</span>
        `;
        inp.querySelector('.clear').addEventListener('click', (e) => {
            e.stopPropagation();
            inp.innerHTML = '<span class="placeholder">Elegir...</span>';
            selection[teamKey][lane] = null;
        });
        selection[teamKey][lane] = champ.id;
    }
}

// --- Helpers ---
function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
}

function queueLabel(q) {
    return ({
        RANKED_SOLO_5x5: 'Solo/Duo',
        RANKED_FLEX_SR:  'Flex',
        CLASSIC:         'Normal',
        ARAM:            'ARAM',
    })[q] || q;
}

function formatPoints(n) {
    if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M pts';
    if (n >= 1000)    return (n / 1000).toFixed(0) + 'K pts';
    return n + ' pts';
}

boot();
