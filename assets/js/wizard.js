document.addEventListener("DOMContentLoaded", function () {
  const form = document.getElementById("dc-form");
  const resultBox = document.getElementById("dc-result");
  const addBtn = document.getElementById("dc-add-to-cart");

  const typeSelect = document.getElementById("dc-type");
  const colorRow = document.getElementById("dc-color-row");
  const colorSelect = document.getElementById("dc-color");
  const heightSelect = document.getElementById("dc-height");

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

      // ✅ Safari fix: forceer reset (anders onthoudt hij oude waarde)
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

      // filter ook op subtype (bij hout én bamboe)
      if (subtype && map.subtype && map.subtype !== subtype) return;

      if (map.thick_mm && map.thick_mm > 0) heights.add(map.thick_mm);
      if (map.color) colors.add(map.color);
    });

    return {
      heights: Array.from(heights).sort((a, b) => a - b),
      colors: Array.from(colors)
    };
  }

  /**
   * Vul hoogte- en kleurvelden op basis van selectie
   */
  function updateFields() {
    const type = typeSelect.value;
    const subtype = subtypeSelect.value;

    const { heights, colors } = getOptions(type, subtype);

    // Hoogte vullen
    heightSelect.innerHTML = '<option value="">-- Kies… --</option>';
    heights.forEach((h) => {
      const opt = document.createElement("option");
      opt.value = h;
      opt.textContent = `${h} mm`;
      heightSelect.appendChild(opt);
    });

    // Kleur vullen
    colorSelect.innerHTML = '<option value="">-- Kies kleur… --</option>';
    if (colors.length > 0) {
      colors.forEach((c) => {
        const opt = document.createElement("option");
        opt.value = c;
        opt.textContent =
          c.charAt(0).toUpperCase() + c.slice(1).replace("_", " ");
        colorSelect.appendChild(opt);
      });
      colorRow.style.display = "block";
    } else {
      colorRow.style.display = "none";
    }
  }

  /**
   * 🧠 Type verandert → rebuild subtypes, bind event opnieuw (Safari fix)
   */
  typeSelect.addEventListener("change", () => {
    setSubtypeOptions(typeSelect.value);
    updateFields();

    // Safari fix: herbind het change-event na reset van de options
    subtypeSelect.onchange = updateFields;
  });

  // Subtype verandert → update alleen velden
  subtypeSelect.addEventListener("change", updateFields);

  // Initieel vullen bij page load
  setSubtypeOptions(typeSelect.value);
  updateFields();

  // ✅ Nieuw: piketpalen-keuze tonen/verbergen
  const poleSelect = document.getElementById("dc-poles");
  const poleSizeRow = document.getElementById("dc-pole-size-row");
  const poleSizeSelect = document.getElementById("dc-pole-size");

  if (poleSelect) {
    poleSelect.addEventListener("change", (e) => {
      poleSizeRow.style.display = e.target.value === "with" ? "block" : "none";
    });
  }

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
      height: parseInt(heightSelect.value, 10),
      color: colorSelect.value || "",
      poles: poleSelect?.value || "none",
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
      let html = `<h4>Resultaat</h4>
                  <p>Oppervlakte totaal: ${data.data.surface_m2} m²</p>
                  <ul>`;
      lines.forEach((line) => {
        html += `<li>${line.meta._hh_dc_summary}</li>`;
      });
      html += "</ul>";
      resultBox.innerHTML = html;

      addBtn.dataset.lines = JSON.stringify(lines);
      addBtn.style.display = "inline-block";
    } catch (err) {
      console.error(err);
      resultBox.innerHTML = "<p style='color:red;'>Fout bij berekening.</p>";
    }
  });

  /**
   * Winkelmand-knop
   */
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
