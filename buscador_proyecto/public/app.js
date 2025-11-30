// Variable global para mantener el estado de la categoría seleccionada
let currentCategory = null;

// Inicializar eventos al cargar
document.addEventListener('DOMContentLoaded', () => {
    // Evento para el botón buscar
    document.getElementById('btn-search').addEventListener('click', () => {
        currentCategory = null; // Nueva búsqueda reinicia filtros
        buscar(); 
    });

    // Evento para detectar "Enter" en la caja de texto
    document.getElementById('query').addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            currentCategory = null;
            buscar();
        }
    });

    // ----------------------------------------------------
    // SOLUCIÓN SUGERENCIAS DE LLENADO (AUTOCOMPLETE)
    // ----------------------------------------------------
    document.getElementById('query').addEventListener('input', async (e) => {
        const val = e.target.value.trim();
        if (val.length < 3) return; // No buscar si es muy corto

        try {
            // CORRECCIÓN IMPORTANTE: 
            // Agregamos '*' al final para que Solr haga búsqueda parcial (prefix search)
            // Ejemplo: si escribes "com", busca "com*" y halla "computadora"
            const term = val + '*'; 
            
            // Llamamos a search.php pasando el término con asterisco
            const response = await fetch(`search.php?q=${encodeURIComponent(term)}`);
            const data = await response.json();
            
            const dataList = document.getElementById('suggestions');
            dataList.innerHTML = ''; // Limpiar anteriores

            // Agregar títulos encontrados como sugerencias
            if (data.results && data.results.length > 0) {
                data.results.slice(0, 5).forEach(r => {
                    const opt = document.createElement('option');
                    opt.value = r.titulo; // Sugerir el título completo
                    dataList.appendChild(opt);
                });
            }
        } catch (error) {
            console.error("Error obteniendo sugerencias:", error);
        }
    });
});

async function buscar(filtroCategoria = null) {
    const queryInput = document.getElementById('query');
    const query = queryInput.value;

    // Si nos pasan una categoría, actualizamos la global
    if (filtroCategoria) {
        currentCategory = filtroCategoria;
    }

    let url = `search.php?q=${encodeURIComponent(query)}`;
    
    // Añadimos la categoría si existe
    if (currentCategory) {
        url += `&cat=${encodeURIComponent(currentCategory)}`;
    }

    // Para la búsqueda principal, pedimos explícitamente el spellcheck
    url += '&spellcheck=true';

    try {
        const response = await fetch(url);
        const data = await response.json();
        renderizar(data);
    } catch (error) {
        console.error("Error en búsqueda:", error);
    }
}

function renderizar(data) {
    const resultsDiv = document.getElementById('results-area');
    const facetsDiv = document.getElementById('facets-area');
    const correctionDiv = document.getElementById('correction-area');
    
    resultsDiv.innerHTML = '';
    facetsDiv.innerHTML = '<h3>Filtros</h3>';
    correctionDiv.innerHTML = '';
    
    // --- NUEVA LÍNEA: Mostrar el total de resultados ---
    if (data.total !== undefined && data.total > 0) {
        resultsDiv.innerHTML = `<h2>${data.total} resultados encontrados</h2>`;
    }

    // ----------------------------------------------------
    // SOLUCIÓN CORRECCIÓN (DID YOU MEAN)
    // ----------------------------------------------------
    if (data.suggestion) {
        correctionDiv.innerHTML = `
            <div style="background-color: #ffe6e6; padding: 10px; border: 1px solid red; margin-bottom: 15px;">
                Quizás quisiste decir: 
                <a href="#" style="font-weight:bold; color:red;" onclick="usarSugerencia('${data.suggestion}')">
                    ${data.suggestion}
                </a>
            </div>
        `;
    }

    // ----------------------------------------------------
    // SOLUCIÓN FACETAS
    // ----------------------------------------------------
    if (data.facets && Object.keys(data.facets).length > 0) {
        for (const [cat, count] of Object.entries(data.facets)) {
            const estilo = (cat === currentCategory) ? 'font-weight:bold; color:black;' : 'color:blue; cursor:pointer;';
            const marcador = (cat === currentCategory) ? ' [x]' : '';
            
            facetsDiv.innerHTML += `
                <div style="margin-bottom: 5px;">
                    <span style="${estilo}" onclick="buscar('${cat}')">
                        ${cat} (${count})${marcador}
                    </span>
                </div>`;
        }
    } else {
        facetsDiv.innerHTML += '<p>No hay filtros disponibles</p>';
    }

    // ----------------------------------------------------
    // SOLUCIÓN SNIPPETS Y NIVELES 1 & 2
    // ----------------------------------------------------
    if (!data.results || data.results.length === 0) {
        resultsDiv.innerHTML = '<p>No se encontraron resultados.</p>';
        return;
    }

    data.results.forEach(doc => {
        const item = document.createElement('div');
        item.className = 'result-item';
        item.style.marginBottom = "20px";
        
        // --- 1. PREPARACIÓN DE VARIABLES ---
        const queryClean = document.getElementById('query').value.trim().toLowerCase();
        const tituloClean = (doc.titulo || '').toLowerCase();
        
        let badge = '';
        
        // NIVEL 1: Score Numérico
        const scoreNum = doc.score ? parseFloat(doc.score).toFixed(4) : 'N/A';

        // --- 2. LÓGICA DE BADGES (NIVEL 2: Relevancia Ponderada) ---
        
        // Si la búsqueda tiene más de 2 letras y aparece en el título (demostrando el boost x2)...
        if (queryClean.length > 2 && tituloClean.includes(queryClean)) {
            badge = `<span style="background: #ffd700; color: #000; padding: 2px 6px; font-size: 0.7em; border-radius: 4px; margin-left: 10px; border: 1px solid #d4af37; vertical-align: middle;">
                        ★ Relevancia Alta (x2 Título)
                     </span>`;
        }
        
        // --- 3. CONSTRUCCIÓN DEL HTML ---
        item.innerHTML = `
            <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                <h3 style="margin-top:0;">
                    <a href="${doc.url || '#'}" target="_blank">${doc.titulo}</a>
                    ${badge} 
                </h3>
                
                <span style="background: #e1ecf4; color: #00529b; padding: 4px 8px; border-radius: 4px; font-size: 0.8em; font-weight: bold; white-space: nowrap; margin-left: 10px;">
                    Relevancia: ${scoreNum}
                </span>
            </div>

            <p style="color:green; font-size: 0.9em; margin-top: 5px;">
                ${doc.url || ''} - <span style="background:#eee; padding:2px;">${doc.categoria || 'General'}</span>
            </p>
            
            <p class="snippet" style="font-size: 0.95em; color: #444;">
                ... ${doc.snippet || ''} ...
            </p>
            <hr style="border: 0; border-top: 1px solid #eee;">
        `;
        
        resultsDiv.appendChild(item);
    });
}

function usarSugerencia(texto) {
    document.getElementById('query').value = texto;
    currentCategory = null; 
    buscar();
}