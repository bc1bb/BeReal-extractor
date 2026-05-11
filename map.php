<?php
require __DIR__ . '/_lib.php';

$points = [];
foreach (unified_stream() as $p) {
    if (empty($p['location'])) continue;
    $lat = $p['location']['latitude']  ?? null;
    $lng = $p['location']['longitude'] ?? null;
    if (!is_numeric($lat) || !is_numeric($lng)) continue;
    $points[] = [
        'lat'   => (float)$lat,
        'lng'   => (float)$lng,
        'at'    => fmt_date($p['takenAt'] ?? ''),
        'thumb' => photo_url($p['primary']['path'] ?? null),
    ];
}

render_header('/map.php', 'Map');
?>
<h1>Map</h1>
<p class="muted"><?= nice_num(count($points)) ?> geo-tagged posts</p>

<?php if (!$points): ?>
  <div class="card"><span class="muted">No location data found in this export.</span></div>
<?php else: ?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">
<style>
  .leaflet-popup-content { color: #111; }
  .leaflet-popup-content img { max-width: 160px; display: block; margin-top: 4px; border-radius: 4px; }
</style>
<div id="map"></div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
<script>
(function () {
  const points = <?= json_encode($points, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) ?>;
  const map = L.map('map');
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '© OpenStreetMap',
  }).addTo(map);
  const cluster = L.markerClusterGroup();
  const bounds = [];
  points.forEach(p => {
    const m = L.marker([p.lat, p.lng]);
    const popup = '<b>' + (p.at || '') + '</b>'
      + (p.thumb ? '<br><a href="' + p.thumb + '" target="_blank" rel="noopener"><img src="' + p.thumb + '" alt=""></a>' : '');
    m.bindPopup(popup);
    cluster.addLayer(m);
    bounds.push([p.lat, p.lng]);
  });
  map.addLayer(cluster);
  if (bounds.length) map.fitBounds(bounds, {padding: [30, 30]});
  else                map.setView([0, 0], 2);
})();
</script>
<?php endif; ?>

<?php render_footer(); ?>
