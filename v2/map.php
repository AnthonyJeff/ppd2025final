<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Painel de Rastreamento</title>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <style>
    #map { height: 600px; }
  </style>
</head>
<body>
  <h1>Painel de Rastreamento - Leaflet</h1>
  <div id="map"></div>

  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script>
    const map = L.map("map").setView([-14.2, -51.9], 4); // Brasil

    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
      maxZoom: 18
    }).addTo(map);

    const markers = {};

    async function fetchLocations() {
      const res = await fetch("broker.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ jsonrpc: "2.0", method: "getLocations", id: Date.now() })
      });

      const data = await res.json();
      const locations = data.result;

      for (const loc of locations) {
        const id = loc.device;
        const latlng = [loc.lat, loc.lng];

        if (markers[id]) {
          markers[id].setLatLng(latlng);
        } else {
          markers[id] = L.marker(latlng).addTo(map).bindPopup(`Dispositivo: ${id}`);
        }
      }
    }

    setInterval(fetchLocations, 3000); // Atualiza a cada 3s
  </script>
</body>
</html>
