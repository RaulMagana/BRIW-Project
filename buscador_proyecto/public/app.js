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
        const val = e.target.value;
        if (val.length < 3) return; // No buscar si es muy corto

        try {
            // Llamamos a search.php en segundo plano
            const response = await fetch(`search.php?q=${encodeURIComponent(val)}`);
            const data = await response.json();
            
            const dataList = document.getElementById('suggestions');
            dataList.innerHTML = ''; // Limpiar anteriores

            // Agregar títulos encontrados como sugerencias
            data.results.slice(0, 5).forEach(r => {
                const opt = document.createElement('option');
                opt.value = r.titulo; // Sugerir el título completo
                dataList.appendChild(opt);
            });
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
    if (Object.keys(data.facets).length > 0) {
        for (const [cat, count] of Object.entries(data.facets)) {
            // Si la categoría está activa, la mostramos en negrita/diferente
            const estilo = (cat === currentCategory) ? 'font-weight:bold; color:black;' : 'color:blue; cursor:pointer;';
            const marcador = (cat === currentCategory) ? ' [x]' : '';
            
            // Al hacer clic, llamamos a buscar pasando la categoría
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
    // SOLUCIÓN SNIPPETS
    // ----------------------------------------------------
    if (data.results.length === 0) {
        resultsDiv.innerHTML = '<p>No se encontraron resultados.</p>';
        return;
    }

    data.results.forEach(doc => {
        const item = document.createElement('div');
        item.className = 'result-item';
        item.style.marginBottom = "20px";
        
        // Renderizamos el snippet HTML tal cual viene (con <strong>)
        item.innerHTML = `
            <h3><a href="${doc.url}" target="_blank">${doc.titulo}</a></h3>
            <p style="color:green; font-size: 0.9em;">${doc.url} - <span style="background:#eee; padding:2px;">${doc.categoria}</span></p>
            <p class="snippet" style="font-size: 0.95em; color: #444;">... ${doc.snippet} ...</p>
            <hr style="border: 0; border-top: 1px solid #eee;">
        `;
        resultsDiv.appendChild(item);
    });
}

function usarSugerencia(texto) {
    document.getElementById('query').value = texto;
    currentCategory = null; // Resetear filtros al aceptar sugerencia
    buscar();
}