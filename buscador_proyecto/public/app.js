// ==========================================
// CONFIGURACIÓN Y MAPEOS
// ==========================================

// Diccionario para títulos bonitos en la barra lateral
const FACET_TITLES = {
    'categorias': 'Categoría',
    'niveles_lectura': 'Extensión del documento',
};

// Diccionario para mapear nombres de Solr a parámetros URL (search.php)
const URL_PARAM_MAP = {
    'categorias': 'cat',
    'niveles_lectura': 'lectura',
};

// ==========================================
// EVENTOS INICIALES
// ==========================================
document.addEventListener('DOMContentLoaded', () => {
    
    // 1. Detectar si hay parámetros en la URL al cargar (para que no se pierda la búsqueda al refrescar)
    const params = new URLSearchParams(window.location.search);
    if (params.has('q')) {
        document.getElementById('query').value = params.get('q');
        // Si hay búsqueda en URL, ejecutamos
        ejecutarBusqueda(window.location.search); 
    }

    // 2. Botón Buscar
    document.getElementById('btn-search').addEventListener('click', () => {
        iniciarNuevaBusqueda();
    });

    // 3. Enter en la caja de texto
    document.getElementById('query').addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            iniciarNuevaBusqueda();
        }
    });

    // 4. Autocomplete (Tu lógica original mejorada)
    document.getElementById('query').addEventListener('input', async (e) => {
        const val = e.target.value.trim();
        if (val.length < 3) return;

        try {
            const term = val + '*'; // Wildcard para Solr
            const response = await fetch(`search.php?q=${encodeURIComponent(term)}`);
            const data = await response.json();
            
            const dataList = document.getElementById('suggestions');
            dataList.innerHTML = ''; 

            if (data.results && data.results.length > 0) {
                data.results.slice(0, 5).forEach(r => {
                    const opt = document.createElement('option');
                    opt.value = r.titulo;
                    dataList.appendChild(opt);
                });
            }
        } catch (error) {
            console.error("Error sugiriendo:", error);
        }
    });
});

// ==========================================
// LÓGICA DE BÚSQUEDA
// ==========================================

// Esta función se llama cuando el usuario escribe y da Enter/Click (limpia filtros)
function iniciarNuevaBusqueda() {
    const query = document.getElementById('query').value;
    
    // Creamos una URL limpia, solo con 'q'
    const params = new URLSearchParams();
    params.set('q', query);
    params.set('spellcheck', 'true');

    // Actualizamos la URL del navegador sin recargar
    const nuevaUrlQuery = `?${params.toString()}`;
    window.history.pushState({}, '', nuevaUrlQuery);

    ejecutarBusqueda(nuevaUrlQuery);
}

// Esta función aplica un filtro sin borrar los otros
// Función para aplicar O QUITAR un filtro (Toggle)
function aplicarFiltro(facetGroup, valor) {
    const params = new URLSearchParams(window.location.search);
    const paramName = URL_PARAM_MAP[facetGroup]; // ej: 'anio'
    
    // LÓGICA DE TOGGLE (Prender/Apagar)
    // Si el filtro que clicamos YA es igual al que está en la URL...
    if (params.get(paramName) === valor) {
        params.delete(paramName); // ...¡Lo borramos!
    } else {
        params.set(paramName, valor); // Si no, lo aplicamos.
    }

    // Actualizamos URL y buscamos
    const nuevaUrlQuery = `?${params.toString()}`;
    window.history.pushState({}, '', nuevaUrlQuery);
    ejecutarBusqueda(nuevaUrlQuery);
}

// Función central que hace el FETCH
async function ejecutarBusqueda(queryString) {
    // Quitamos el '?' si existe para evitar dobles
    if (queryString.startsWith('?')) {
        queryString = queryString.substring(1);
    }
    
    // Construimos la URL para search.php
    const urlFetch = `search.php?${queryString}`;

    try {
        const response = await fetch(urlFetch);
        const data = await response.json();
        renderizarTodo(data);
    } catch (error) {
        console.error("Error en búsqueda:", error);
    }
}

// ==========================================
// RENDERIZADO (VISTA)
// ==========================================

function renderizarTodo(data) {
    renderizarResultados(data);
    renderizarFiltros(data.facets);
    renderizarCorreccion(data.suggestion);
}

function renderizarResultados(data) {
    const resultsDiv = document.getElementById('results-area');
    resultsDiv.innerHTML = '';

    // Mostrar total
    if (data.total !== undefined) {
        resultsDiv.innerHTML = `<h2 style="font-size: 1.2rem; color: #555; margin-bottom: 20px;">${data.total} resultados encontrados</h2>`;
    }

    if (!data.results || data.results.length === 0) {
        resultsDiv.innerHTML += '<p>No se encontraron resultados.</p>';
        return;
    }

    data.results.forEach(doc => {
        const item = document.createElement('div');
        item.className = 'result-item';
        item.style.marginBottom = "25px";
        item.style.padding = "15px";
        item.style.border = "1px solid #eee";
        item.style.borderRadius = "8px";
        item.style.backgroundColor = "#fff";
        
        // Validamos que exista URL, si no ponemos '#'
        const linkUrl = doc.url || '#';

        // HTML del Item
       item.innerHTML = `
            <div style="display:flex; justify-content:space-between;">
                <h3 style="margin:0 0 5px 0;">
                    <a href="${linkUrl}" target="_blank" style="text-decoration:none; color:#1a0dab; font-size:1.2rem;">
                        ${doc.titulo}
                    </a>
                </h3>
                <span style="font-size:0.8em; color:#777;">Score: ${parseFloat(doc.score).toFixed(2)}</span>
            </div>

            <div style="margin-bottom:5px;">
                <a href="${linkUrl}" target="_blank" style="font-size:0.85em; color:#006621; text-decoration:none;">
                    ${doc.url || ''}
                </a>
            </div>

            <div style="margin-bottom:8px;">
                <span style="background:#f1f3f4; padding:2px 6px; border-radius:4px; font-size:0.8em; border:1px solid #dadce0;">${doc.categoria || 'General'}</span>
                ${doc.lectura_str ? `<span style="background:#e3f2fd; color:#0d47a1; padding:2px 6px; border-radius:4px; font-size:0.8em;">⏱ ${doc.lectura_str}</span>` : ''}
            </div>
            
            <p style="
                color: #444; 
                font-size: 0.95em; 
                margin-top: 8px;
                line-height: 1.5;
                display: -webkit-box;
                -webkit-line-clamp: 2;
                -webkit-box-orient: vertical;
                overflow: hidden;
                text-overflow: ellipsis;
            ">
                ${doc.snippet || 'No se encontró fragmento relevante.'}
            </p>
        `;
        
        resultsDiv.appendChild(item);
    });
}


function renderizarFiltros(facets) {
    const contenedor = document.getElementById('facets-content'); 
    contenedor.innerHTML = ''; 

    // 1. BOTÓN DE LIMPIAR (Solo aparece si hay filtros activos en la URL)
    const params = new URLSearchParams(window.location.search);
    // Verificamos si hay algún parámetro de filtro activo
    const hayFiltros = Object.values(URL_PARAM_MAP).some(p => params.has(p));

    if (hayFiltros) {
        const btnLimpiar = document.createElement('button');
        btnLimpiar.innerHTML = 'Limpiar filtros';
        // Estilos para que parezca un botón de acción
        btnLimpiar.style.width = '100%';
        btnLimpiar.style.padding = '8px';
        btnLimpiar.style.marginBottom = '15px';
        btnLimpiar.style.backgroundColor = '#ffebee'; // Rojo clarito
        btnLimpiar.style.color = '#c62828';
        btnLimpiar.style.border = '1px solid #ef9a9a';
        btnLimpiar.style.borderRadius = '4px';
        btnLimpiar.style.cursor = 'pointer';
        btnLimpiar.style.fontWeight = 'bold';

        btnLimpiar.onclick = () => {
            // Borramos los filtros pero MANTENEMOS la búsqueda ('q')
            const query = params.get('q') || '';
            const newParams = new URLSearchParams();
            if(query) newParams.set('q', query);
            
            // Recargamos sin filtros
            const urlLimpia = `?${newParams.toString()}`;
            window.history.pushState({}, '', urlLimpia);
            ejecutarBusqueda(urlLimpia);
        };

        contenedor.appendChild(btnLimpiar);
    }

    // Si no hay facetas, terminamos aquí
    if (!facets || Object.keys(facets).length === 0) {
        if (!hayFiltros) contenedor.innerHTML = '<p style="color:#999; font-size:0.9em;">Sin filtros</p>';
        return;
    }

    // 2. RENDERIZADO DE LOS GRUPOS (Igual que antes)
    for (const [key, opciones] of Object.entries(facets)) {
        if (Object.keys(opciones).length === 0) continue;

        const titulo = document.createElement('h4');
        titulo.textContent = FACET_TITLES[key] || key;
        titulo.style.marginTop = '15px';
        titulo.style.marginBottom = '5px';
        titulo.style.fontSize = '0.9rem';
        titulo.style.textTransform = 'uppercase';
        titulo.style.color = '#5f6368';
        titulo.style.borderBottom = '1px solid #ddd';
        contenedor.appendChild(titulo);

        const ul = document.createElement('ul');
        ul.style.listStyle = 'none';
        ul.style.paddingLeft = '0';

        for (const [nombreOpcion, cantidad] of Object.entries(opciones)) {
            const li = document.createElement('li');
            li.style.marginBottom = '4px';

            const enlace = document.createElement('a');
            enlace.href = '#';
            enlace.style.fontSize = '0.9rem';
            enlace.style.textDecoration = 'none';
            enlace.style.color = '#1a0dab';
            enlace.innerHTML = `${nombreOpcion} <span style="color:#70757a; font-size:0.85em;">(${cantidad})</span>`;

            // ESTADO ACTIVO: Si está seleccionado, poner negrita y una "X" para indicar que se puede quitar
            const paramName = URL_PARAM_MAP[key];
            if (params.get(paramName) === nombreOpcion) {
                enlace.style.fontWeight = 'bold';
                enlace.style.color = '#d93025'; // Rojo para indicar "activo/quitar"
                enlace.innerHTML = ` ${nombreOpcion}`;
                li.style.backgroundColor = '#fce8e6'; // Fondo suave
                li.style.borderRadius = '4px';
                li.style.paddingLeft = '5px';
            }

            enlace.onclick = (e) => {
                e.preventDefault();
                aplicarFiltro(key, nombreOpcion);
            };

            li.appendChild(enlace);
            ul.appendChild(li);
        }
        contenedor.appendChild(ul);
    }
}

function renderizarCorreccion(sugerencia) {
    const div = document.getElementById('correction-area');
    div.innerHTML = '';
    if (sugerencia) {
        div.innerHTML = `
            <div style="color:#d93025; margin-bottom: 15px; font-style:italic;">
                Quizás quisiste decir: 
                <a href="#" style="font-weight:bold; color:#1a0dab; text-decoration:underline;" 
                   onclick="usarSugerencia('${sugerencia}')">
                    ${sugerencia}
                </a>
            </div>
        `;
    }
}

function usarSugerencia(texto) {
    document.getElementById('query').value = texto;
    iniciarNuevaBusqueda();
}