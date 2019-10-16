(function ($, Drupal, drupalSettings) {

  var jsonrequest;
  Drupal.leafletBBox = {
    
    map: null,
    markerGroup: null,
    overlays: {},

    onMapLoad: function(map) {
      Drupal.leafletBBox.map = map;
      Drupal.leafletBBox.markerGroup = new Array();

      // Intialize empty layers and associated controls.
      var layer_count = 0;
      $.each(drupalSettings.leafletBBox, function(key, value) {
        if (typeof value.url !== 'undefined') {
          // Add empty layers.
          Drupal.leafletBBox.markerGroup[key] = new L.LayerGroup();
          Drupal.leafletBBox.markerGroup[key].addTo(map);

          // Connect layer controls to layer data.
          Drupal.leafletBBox.overlays[value.layer_title]
            = Drupal.leafletBBox.markerGroup[key];

          layer_count++;
        }
      });

      // If we have more than one data layer, add the control.
      // @TODO: figure out how to interact with base map selection.
      if (layer_count > 1) {
        L.control.layers(null, Drupal.leafletBBox.overlays).addTo(map);
      }
      
      // settings maxbound so that the map remain alwxays centered
      var southWest = new L.LatLng(90, -18000),
      northEast = new L.LatLng(-90, 180000),
      bounds = new L.LatLngBounds(southWest, northEast);
    
      map.setMaxBounds(bounds);
       
      // Loading a map is the same as moving/zooming.
      map.on('moveend', Drupal.leafletBBox.moveEnd);

    },

    moveEnd: function(e) {
      var map = Drupal.leafletBBox.map;
	  
      // Rebuild the bounded GeoJSON layers.
      $.each(drupalSettings.leafletBBox, function(layer_key, layer_info) {
        if (typeof layer_info.url !== 'undefined') {
          Drupal.leafletBBox.makeGeoJSONLayer(map, layer_info, layer_key);
        }
      });
	  
    },

    makeGeoJSONLayer: function(map, info, layer_key) {
    
      if (jsonrequest) {
          jsonrequest.abort();
      }
    
      var url = typeof info.url !== 'undefined' ? info.url : drupalSettings.leafletBBox.url;

      
      var bbox_arg_id = ('bbox_arg_id' in drupalSettings.leafletBBox) ?
        drupalSettings.leafletBBox.bbox_arg_id : 'bbox';

      
      
      // we want to use a bigger bbox into account so that numbers are not changing for the user
      // adding 1 screensize of bbox in all directions
      
      var bounds = map.getBounds();
      
      // case initialisation: not yet any height
      if (bounds.getSouthWest().lat == bounds.getNorthEast().lat) return;
      
      // longitude calculation if we want to add more to the west & east
      var west = bounds.getSouthWest().lng - 0.4*(bounds.getNorthEast().lng - bounds.getSouthWest().lng);
      var east = bounds.getNorthEast().lng + 0.4*(bounds.getNorthEast().lng - bounds.getSouthWest().lng);
      
      // latitude calculation if we want to add more to the south & north
      var south = bounds.getSouthWest().lat; - 0.8*(bounds.getNorthEast().lat - bounds.getSouthWest().lat);
      var north = bounds.getNorthEast().lat + 0.8*(bounds.getNorthEast().lat - bounds.getSouthWest().lat);
      
      // rounding for cache purpose // flooring / ceiling so that zooms are not impacted
      var bbox = Math.floor(west) + ',' + Math.floor(south) + ',' + Math.ceil(east) + ',' + Math.ceil(north);
      
      
      url += "?" + bbox_arg_id +"=" + bbox;
      
     
      url += "&zoom=" + map.getZoom();
      
      // to remove, this is used to disable bounding box strategy for testing purpose
      //url += "&cluster_distance=0";
      
      // Append any existing query string (respect exposed filters).
      if (window.location.search.substring(1) != '') {
        url += "&" + window.location.search.substring(1);
      }

      // Make a new GeoJSON layer.    
      jsonrequest = $.getJSON(url, function(data) {
        var layerGroup = Drupal.leafletBBox.markerGroup[layer_key];
       
        
        // duplicating the data point if needed
        var myFeatures = [];
        for (var key in data.features) {
 
          // if spanning on multiple maps, then display the marker multiple times
          lng_init = data.features[key].geometry.coordinates[0];
          
          if (lng_init >= west && lng_init <= east)
          {
            myFeatures.push(data.features[key]);
          }
          
          // multiply towards west
          lng = lng_init - 360;
          while (lng >= west)
          {
            if (lng <= east)
            {
              var newObject = jQuery.extend(true, {}, data.features[key]);
              newObject.geometry.coordinates[0] = lng;
              myFeatures.push(newObject);
            }
             lng -= 360;
          }
          
          // multiply towards east
          lng = lng_init + 360;
          while (lng <= east)
          {
            if (lng >= west)
            {
              var newObject = jQuery.extend(true, {}, data.features[key]);
              newObject.geometry.coordinates[0] = lng;
              myFeatures.push(newObject);
            }
             lng += 360;
          } 
        }
        
        layerGroup.eachLayer(function (layer) { 
        
          var markers = layer._layers;
          
          for (var key_markers in markers) {
            var not_found = true;
            
            for (var key_data in myFeatures) {
            
              // define if a marker is already there (to avoid blinking effect)
              if ( markers[key_markers].feature.geometry.coordinates[0] == myFeatures[key_data].geometry.coordinates[0] 
              && markers[key_markers].feature.geometry.coordinates[1] == myFeatures[key_data].geometry.coordinates[1] 
              && markers[key_markers].feature.properties.geocluster_ids == myFeatures[key_data].properties.geocluster_ids) {
                not_found = false;
                myFeatures.splice(key_data, 1); 
                break;
              }
            }
            if (not_found) { // these features should not be displayed anymore
              layerGroup.removeLayer(layer); 
            }
          }
        });
        
        // adding remaining features
        for (var key in myFeatures) {
          var geojsonLayer = new L.GeoJSON(myFeatures[key], Drupal.leafletBBox.geoJSONOptions);
          layerGroup.addLayer(geojsonLayer);
        }
      
      });
	
    }
  };

  Drupal.leafletBBox.geoJSONOptions = { 
  
    pointToLayer: function(featureData, latlng) {   
      title = "";
      if (featureData.properties.label) {
        title = featureData.properties.label;
      }
      lMarker = new L.Marker(latlng, {title: title});
    },

    onEachFeature: function(feature, layer) {
      if (feature.properties && feature.properties.popup) {
        layer.bindPopup(feature.properties.popup);
      }
    }

  };

  // Insert map.
  $(document).bind('leaflet.map', function(e, map, lMap) { 
    Drupal.leafletBBox.onMapLoad(lMap);
  });

})(jQuery, Drupal, drupalSettings);
