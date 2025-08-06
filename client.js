const API_URL = "broker.php";

async function rpc(method, params = {}) {
  const res = await fetch(API_URL, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      jsonrpc: "2.0",
      method,
      params,
      id: Date.now()
    })
  });
  const json = await res.json();
  if (json.result !== undefined) return json.result;
  else alert("Erro: " + json.error?.message);
}

async function enviarLocalizacao() {
  const device = document.getElementById("device").value;
  const lat = document.getElementById("latitude").value;
  const lng = document.getElementById("longitude").value;

  if (!device || !lat || !lng) {
    alert("Preencha todos os campos.");
    return;
  }

  await rpc("sendLocation", {
    device,
    latitude: parseFloat(lat),
    longitude: parseFloat(lng),
    timestamp: new Date().toISOString()
  });

  alert("Localização enviada com sucesso!");
  document.getElementById("latitude").value = "";
  document.getElementById("longitude").value = "";
}

async function atualizarMapa() {
  const locais = await rpc("getLocations");
  const output = document.getElementById("map-output");
  output.innerHTML = "";

  locais.forEach(loc => {
    const div = document.createElement("div");
    div.textContent = `📍 ${loc.device}: (${loc.latitude}, ${loc.longitude})`;
    output.appendChild(div);
  });
}

async function atualizarContatos() {
  const contatos = await rpc("listContacts");

  const div = document.getElementById("contact-list");
  div.innerHTML = "";

  contatos.forEach(c => {
    const span = document.createElement("div");
    span.textContent = `${c.online ? "🟢" : "🔴"} ${c.name}`;
    div.appendChild(span);
  });
}

setInterval(atualizarContatos, 3000);
setInterval(atualizarMapa, 3000);
