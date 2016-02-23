var mapzen = mapzen || {};
mapzen.whosonfirst = mapzen.whosonfirst || {};
mapzen.whosonfirst.boundaryissues = mapzen.whosonfirst.boundaryissues || {};

// for the time being this assumes jQuery is present
// that decision may change some day but not today
// (20160121/thisisaaronland)

mapzen.whosonfirst.boundaryissues.venue = (function() {

	var map,
	    $result;

	var self = {

		setup_map: function() {
			var swlat = 37.70120736474139;
			var swlon = -122.68707275390624;
			var nelat = 37.80924146650164;
			var nelon = -122.21912384033203;

			mapzen.whosonfirst.leaflet.tangram.scenefile('/tangram/refill.yaml');
			map = mapzen.whosonfirst.leaflet.tangram.map_with_bbox(
				'map',
				swlat, swlon, nelat, nelon
			);
			
			self.map = map;
		},

		setup_drawing: function() {
			var drawnItems = new L.FeatureGroup();
			var markerLayer;
			map.addLayer(drawnItems);

			var VenueMarker = L.Icon.extend({
				options: {
					iconUrl: '/images/marker-icon.png',
					iconRetinaUrl: '/images/marker-icon-2x.png',
					shadowUrl: null,
					iconAnchor: new L.Point(13, 42),
					iconSize: new L.Point(25, 42),
					popupAnchor: new L.Point(0, -42)
				}
			});
			var drawControl = new L.Control.Draw({
				draw: {
					polyline: false,
					polygon: false,
					rectangle: false,
					circle: false,
					marker: {
						icon: new VenueMarker()
					}
				},
				edit: {
					featureGroup: drawnItems,
					edit: false,
					remove: false
				}
			});
			map.addControl(drawControl);

			map.on('draw:drawstart', function(e){
				if (markerLayer){
					drawnItems.removeLayer(markerLayer);
					markerLayer = null;
				}
				self.reset_coordinates();
			});

			map.on('draw:created', function(e){
				markerLayer = e.layer;
				drawnItems.addLayer(markerLayer);

				var ll = markerLayer.getLatLng();
				
				if ($('input[name="geojson.properties.geom:latitude"]').length == 0) {
					var $rel = $('#json-schema-object-geojson-properties');
					self.add_row($rel, 'geom:latitude', ll.lat);
				} else {
					$('input[name="geojson.properties.geom:latitude"]').val(ll.lat);
				}
				
				if ($('input[name="geojson.properties.geom:longitude"]').length == 0) {
					var $rel = $('#json-schema-object-geojson-properties');
					self.add_row($rel, 'geom:longitude', ll.lng);
				} else {
					$('input[name="geojson.properties.geom:longitude"]').val(ll.lng);
				}

				setTimeout(function() {
					// Clicking on the marker lets you reset the location
					markerLayer.on('click', function(e){
						drawnItems.removeLayer(markerLayer);
						markerLayer = null;
						self.reset_coordinates();
					});
				}, 0);
				
				var html = 'Coordinates: <strong>' + ll.lat + ', ' + ll.lng + '</strong>' +
				           '<span id="where-parent"></span>';
				$('#where').html(html);
				
				self.lookup_parent_id(ll.lat, ll.lng, function(results) {
					try {
						var parent_id = results[0].Id;
						var html = ' in <strong>' + results[0].Name + '</strong> (' + results[0].Placetype + ')';
						$('input[name="geojson.properties.wof:parent_id"]').val(parent_id);
						$('#where-parent').html(html);
					} catch(e) {
						mapzen.whosonfirst.log.error('Error looking up parent_id.');
					}
				});
			});
		},

		setup_properties: function() {
			// Add new properties to the object by changing the 'Value' field
			$('input.add-value').change(function(e) {
				if ($(e.target).val()) {
					var $rel = $(e.target).closest('.json-schema-object');
					var $row = $rel.find('> table > tbody > .add-row');
					var $key = $row.find('.add-key');
					var $value = $row.find('.add-value');
					var key = $key.val();
					var value = $value.val();
					self.add_row($rel, key, value);
					$key.val('');
					$value.val('');
					
					// Focus the 'Key' field to make multiple additions easier
					$key.focus();
				}
			});
		},

		setup_form: function() {
			$('#venue-form').submit(function(e) {
				e.preventDefault();

				var onsuccess = function(rsp){
					self.show_result(rsp);
				};
				var onerror = function(rsp){
					self.show_result(rsp);
				};

				var venue = self.encode_venue();
				var data = {
					crumb: $(this).data("crumb-venue"),
					venue: JSON.stringify(venue)
				};
				mapzen.whosonfirst.boundaryissues.api.api_call("wof.venue.create", data, onsuccess, onerror);

				$result.html('Loading...');
			});
		},
		
		add_row: function($rel, key, value) {
			console.log('add_row:', $rel, key, value);
			var $row = $rel.find('> table > tbody > .add-row');
			var context = $rel.data('context');
			var remove = '<span class="remove-row">&times;</span>';
			var $newRow = $(
				'<tr>' +
					'<th>' + key + remove + '</th>' +
					'<td><input name="' + context + '.' + key + '" class="venue-property"></td>' +
				'</tr>'
			).insertBefore($row);
			$newRow.find('.remove-row').click(function(e) {
				$newRow.remove();
			});

			$rel.find('input[name="' + context + '.' + key + '"]').val(value);
		},

		encode_venue: function() {
			var lat = parseFloat($('input[name="geom:latitude"]').val());
			var lng = parseFloat($('input[name="geom:longitude"]').val());
			var venue = {
				type: 'Feature',
				bbox: [lng, lat, lng, lat],
				geometry: {
					type: 'Point',
					coordinates: [lng, lat]
				},
				properties: {
					"geom:latitude": lat,
					"geom:longitude": lng
				}
			};
			$('#venue-form').find('input.venue-property').each(function(i, input) {
				var name = $(input).attr('name');
				var value = $(input).val();
				// Ignore initial 'geojson.' in name (e.g., "geojson.properties.wof:concordances.id")
				name = name.replace(/^geojson\./, '');
				self.assign_property(venue, name, value);
			});
			return venue;
		},

		assign_property: function(context, name, value) {
			// Check if there are '.' chars in the name
			var next_step = name.match(/^([^.]+)\.(.+)/);
			if (next_step) {
				// If so, recurse into the properties context
				if (!context[next_step[1]]) {
					context[next_step[1]] = {};
				}
				self.assign_property(context[next_step[1]], next_step[2], value);
			} else {
				// If not, then we've reached the correct context
				context[name] = value;
			}
		},

		show_result: function(rsp) {
			if (rsp.ok && rsp.stat == 'ok') {
				var edit_link = '<a href="/id/' + rsp.id + '/">' + rsp.id + '</a>';
				var geojson_link = '(<a href="' + rsp.geojson_url + '">raw GeoJSON</a>)';
				$result.html('Success! Created ' + edit_link + ' ' + geojson_link);
				mapzen.whosonfirst.log.debug(rsp);
			} else if (rsp.error_msg) {
				$result.html('Error: ' + rsp.error_msg);
				mapzen.whosonfirst.log.error(rsp);
			} else {
				$result.html('Oh noes, an error! Check the JavaScript console?');
				mapzen.whosonfirst.log.error(rsp);
			}
		},
		
		lookup_parent_id: function(lat, lng, callback) {
			var data = {
				latitude: lat,
				longitude: lng
			};
			
			var onsuccess = function(rsp) {
				callback(rsp.results);
			};
			var onerror = function(rsp) {
				mapzen.whosonfirst.log.error('Error looking up parent_id.');
			};
			mapzen.whosonfirst.boundaryissues.api.api_call("wof.pip", data, onsuccess, onerror);
		},
		
		reset_coordinates: function() {
			$('#where').html('');
			$('input[name="geom:latitude"]').val('');
			$('input[name="geom:longitude"]').val('');
		}

	};

	$(document).ready(function() {
		$result = $('#venue-result');
		self.setup_map();
		self.setup_drawing();
		self.setup_properties();
		self.setup_form();
	});

	return self;
})();
