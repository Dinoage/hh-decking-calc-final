document.addEventListener("DOMContentLoaded", function () {
  const form = document.getElementById("dc-form");
  const resultBox = document.getElementById("dc-result");
  const addBtn = document.getElementById("dc-add-to-cart");

  const typeSelect = document.getElementById("dc-type");
  const colorRow = document.getElementById("dc-color-row");
  const colorSelect = document.getElementById("dc-color");
  
  // Containers (rows) voor verbergen/tonen
  const heightRow = document.getElementById("dc-height-row");
  const heightSelect = document.getElementById("dc-height");
  const polesRow = document.getElementById("dc-poles-row");
  const polesSelect = document.getElementById("dc-poles");

  const subtypeRow = document.getElementById("dc-subtype-row");
  const subtypeSelect = document.getElementById("dc-subtype");

  // ✅ Geldige subtypes per materiaal
  const SUBTYPE_OPTIONS = {
    hout: [
      { value: "bangkirai", label: "Bangkirai" },
      { value: "angelim", label: "Angelim Vermelho" },
      { value: "douglas", label: "Douglas" }
    ],
    bamboe: [
      { value: "plank", label: "Vlonderplank" },
      { value: "tegel", label: "Vlondertegel" },
      { value: "visgraat", label: "Visgraat" }
    ]
  };

  /**
   * Bouw subtype-lijst op basis van type
   */
  function setSubtypeOptions(type) {
    subtypeSelect.innerHTML = '<option value="">-- Kies soort… --</option>';

    if (type === "hout" || type === "bamboe") {
      const opts = SUBTYPE_OPTIONS[type] || [];
      opts.forEach(({ value, label }) => {
        const opt = document.createElement("option");
        opt.value = value;
        opt.textContent = label;
        subtypeSelect.appendChild(opt);
      });
      subtypeRow.style.display = "block";
      subtypeSelect.selectedIndex = 0;
    } else {
      subtypeRow.style.display = "none";
      subtypeSelect.value = "";
    }
  }

  /**
   * Haal beschikbare hoogtes en kleuren uit config
   */
  function getOptions(type, subtype = "") {
    const heights = new Set();
    const colors = new Set();

    Object.values(HHDC.config.mappings).forEach((map) => {
      if (map.type !== type) return;

      // filter ook op subtype
      if (subtype && map.subtype && map.subtype !== subtype) return;

      // Hoogtes: alleen toevoegen als > 0, tenzij het een tegel is (dan boeit het niet, wordt toch verborgen)
      if (map.thick_mm && map.thick_mm > 0) heights.add(map.thick_mm);
      
      if (map.color) colors.add(map.color);
    });

    return {
      heights: Array.from(heights).sort((a, b) => a - b),
      colors: Array.from(colors)
    };
  }

  /**
   * Vul velden & Verberg overbodige stappen (Tegels)
   */
  function updateFields() {
    const type = typeSelect.value;
    const subtype = subtypeSelect.value;

    const { heights, colors } = getOptions(type, subtype);

    // 1. Specifiek gedrag voor TEGELS: 
    // - Geen piketpalen (Balkon)
    // - Vaak geen hoogtekeuze (indien maar 1 soort of 0mm in config)
    const isTile = (subtype === 'tegel');

    // --- PLAATSING (Piketpalen) ---
    if (isTile) {
      polesRow.style.display = "none";
      polesSelect.value = "none"; // Forceer 'zonder piketpalen'
      // Verberg ook de paalmaat (die hangt af van polesSelect change, dus trigger die eventueel)
      document.getElementById("dc-pole-size-row").style.display = "none";
    } else {
      polesRow.style.display = "block";
    }

    // --- HOOGTE ---
    // Als er geen hoogtes zijn gevonden (bv bij tegels met dikte 0) of het is een tegel, verberg hoogte.
    if (heights.length === 0 || isTile) {
       heightRow.style.display = "none";
       heightSelect.innerHTML = '<option value="0" selected>Standaard</option>';
    } else {
       heightRow.style.display = "block";
       heightSelect.innerHTML = '<option value="">-- Kies… --</option>';
       heights.forEach((h) => {
         const opt = document.createElement("option");
         opt.value = h;
         opt.textContent = `${h} mm`;
         heightSelect.appendChild(opt);
       });
    }

    // --- KLEUR ---
    colorSelect.innerHTML = '<option value="">-- Kies kleur… --</option>';
    if (colors.length > 0) {
      colors.forEach((c) => {
        const opt = document.createElement("option");
        opt.value = c;
        opt.textContent = c.charAt(0).toUpperCase() + c.slice(1).replace("_", " ");
        colorSelect.appendChild(opt);
      });
      colorRow.style.display = "block";
    } else {
      colorRow.style.display = "none";
    }
  }

  // Event Listeners
  typeSelect.addEventListener("change", () => {
    setSubtypeOptions(typeSelect.value);
    updateFields();
    subtypeSelect.onchange = updateFields; // Re-bind voor Safari fix
  });

  subtypeSelect.addEventListener("change", updateFields);

  // Piketpalen-keuze tonen/verbergen logica (voor als de row wel zichtbaar is)
  const poleSizeRow = document.getElementById("dc-pole-size-row");
  const poleSizeSelect = document.getElementById("dc-pole-size");

  if (polesSelect) {
    polesSelect.addEventListener("change", (e) => {
      poleSizeRow.style.display = e.target.value === "with" ? "block" : "none";
    });
  }

  // Init
  setSubtypeOptions(typeSelect.value);
  updateFields();


  /**
   * Berekening verzenden
   */
  form.addEventListener("submit", async function (e) {
    e.preventDefault();

    const payload = {
      type: typeSelect.value,
      subtype: subtypeSelect.value || "",
      length: parseFloat(document.getElementById("dc-length").value),
      width: parseFloat(document.getElementById("dc-width").value),
      height: parseInt(heightSelect.value, 10) || 0, // Fallback naar 0 voor tegels
      color: colorSelect.value || "",
      poles: polesSelect?.value || "none",
      pole_size: poleSizeSelect?.value || ""
    };

    console.log("▶️ Verzonden payload:", payload);
    resultBox.innerHTML = "<p>Berekenen...</p>";

    try {
      const resp = await fetch(HHDC.rest.base + "/calc", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-WP-Nonce": HHDC.nonce
        },
        body: JSON.stringify(payload)
      });

      const data = await resp.json();

      if (!data.success) {
        resultBox.innerHTML = `<p style="color:red;">${data.message}</p>`;
        return;
      }

      const lines = data.data.lines;
      
      // Header met totaal oppervlakte en nieuwe grid layout
      let html = `<div class="hh-dc-summary-header">
                    <h4>Resultaat & Materiaallijst</h4>
                    <p>Berekende oppervlakte: <strong>${data.data.surface_m2} m²</strong></p>
                  </div>
                  <div class="hh-dc-results-grid">`;

      lines.forEach((line) => {
        // Fallbacks voor als er geen afbeelding is
        const imgUrl = line.image || 'https://via.placeholder.com/100?text=Geen+foto'; 
        const title = line.title || line.meta._hh_dc_summary;
        // Alleen de note tonen als die gevuld is
        const note = line.cutting_note ? `<div class="hh-dc-cutting-note">${line.cutting_note}</div>` : '';

        html += `
          <div class="hh-dc-item-card">
            <div class="hh-dc-item-img">
              <img src="${imgUrl}" alt="${title}" />
            </div>
            <div class="hh-dc-item-content">
                <div class="hh-dc-item-header">
                    <span class="hh-dc-qty">${line.qty}x</span>
                    <span class="hh-dc-title">${title}</span>
                </div>
                ${note}
            </div>
          </div>
        `;
      });

      html += "</div>"; // sluit grid
      resultBox.innerHTML = html;

      addBtn.dataset.lines = JSON.stringify(lines);
      addBtn.style.display = "inline-block";
    } catch (err) {
      console.error(err);
      resultBox.innerHTML = "<p style='color:red;'>Fout bij berekening.</p>";
    }
  });

  addBtn.addEventListener("click", async function () {
    const lines = JSON.parse(addBtn.dataset.lines || "[]");
    if (!lines.length) return;

    try {
      const resp = await fetch(HHDC.rest.base + "/add-to-cart", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          "X-WP-Nonce": HHDC.nonce
        },
        body: JSON.stringify({ lines })
      });

      const data = await resp.json();
      if (!data.success) {
        alert("Fout: " + data.message);
        return;
      }
      window.location.href = data.cart_url;
    } catch (err) {
      console.error(err);
      alert("Fout bij toevoegen aan winkelmand.");
    }
  });
});