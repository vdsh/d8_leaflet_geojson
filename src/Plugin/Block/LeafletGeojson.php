<?php

namespace Drupal\leaflet_geojson\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;

/**
 * Provides a 'Leaflet Geojson' Block.
 *
 * @Block(
 *   id = "leaflet_geojson",
 *   admin_label = @Translation("Leaflet Geojson Map Block"),
 *   category = @Translation("Mapping"),
 * )
 */
class LeafletGeojson extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    return [
      '#markup' => $this->leaflet_geojson_map_pane_render_layered_map(),
      '#attached' => array(
        'library' => $this->leaflet_geojson_map_pane_render_javascript_library(),
        'drupalSettings' => array(
            'leafletBBox' => $this->leaflet_geojson_map_pane_render_javascript_settings(),
        ),
      ),
    ];
  }
  
  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    
    $conf = $this->configuration;
    // Build base layer selector.
    $base_options = array();
    foreach (leaflet_map_get_info() as $key => $map) {
      $base_options[$key] = $this->t($map['label']);
    }
    // The default selection is the first one, or the previously selected one.
    $default_base = key($base_options);
    if (isset($conf['map_settings']['base'])) {
      $default_base = $conf['map_settings']['base'];
    }
    $form['map_settings']['base'] = array(
      '#title' => $this->t('Leaflet base layer'),
      '#type' => 'select',
      '#options' => $base_options,
      '#default_value' => $default_base,
      '#required' => TRUE,
      '#description' => $this->t(
          'Select the Leaflet base layer (map style) that will display the data.'
      ),
    );

    // Provide some UI help for setting up multi-layer maps.
    $data_layers_description
      = $this->t('Choose one or more GeoJSON sources that will provide the map data. ');
    $data_layers_description
      .= $this->t('If more than one source  is selected, a layer control will appear on the map. ');
    $data_layers_description
      .= $this->t('Views GeoJSON page displays are automatically exposed here.');

    // Build the data layers selector.
    $form['map_settings']['info'] = array(
      '#type' => 'fieldset',
      '#tree' => TRUE,
      '#title' => 'Views GeoJSON Data Layers',
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
      '#description' => $data_layers_description,
    );

    // Grab the available layers.
    $sources = leaflet_geojson_source_get_info(NULL, TRUE);
    $source_options = array();
    foreach ($sources as $id => $source) {
      $source_options[$id] = $source['title'];
    }

    // Figure out if we have any data layers yet, and set the layer count.
    if (empty($form_state->get('layer_count'))) {
      // During creation, we wont have any data yet, so only one layer.
      if (!isset($conf['map_settings']['info']['data'])) {
        $form_state->set('layer_count', 1);
      }
      else {
        // During edit, we'll have one or more layers, so count.
        $form_state->set('layer_count',  count($conf['map_settings']['info']['data']));
      }
    }

    // Build the number of layer selections indicated by layer_count.
    for ($layer_index = 1; $layer_index <= $form_state->get('layer_count'); $layer_index++) {
      $default_layer_source = key($source_options);
      if (isset($conf['map_settings']['info']['data']['leaflet_' . $layer_index])) {
        $default_layer_source
          = $conf['map_settings']['info']['data']['leaflet_' . $layer_index];
      }
      $form['map_settings']['info']['data']['leaflet_' . $layer_index] = array(
        '#type' => 'select',
        '#title' => $this->t('GeoJSON layer source'),
        '#options' => $source_options,
        '#default_value' => $default_layer_source,
        '#required' => ($layer_index == 1),
      );
    }

    // Provide an "Add another layer" button.
    
    $form['map_settings']['info']['add_layer'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Add another layer'),
      '#submit' => array('leaflet_geojson_map_pane_add_layer'),
    );

    // Provide a "Remove" button for latest selected layer.
    if ($form_state->get('layer_count') > 1) {
      $form['map_settings']['info']['remove_layer'] = array(
        '#type' => 'submit',
        '#value' => $this->t('Remove last layer'),
        '#submit' => array('leaflet_geojson_map_pane_remove_last_layer'),
      );
    }


    // Leaflet wants a height in the call to the render function.
    $default_height = isset($conf['map_settings']['height']) ? $conf['map_settings']['height'] : 400;
    $form['map_settings']['height'] = array(
      '#title' => $this->t('Map height'),
      '#type' => 'textfield',
      //'#field_suffix' => $this->t('px'),
      '#size' => 4,
      '#default_value' => $default_height,
      '#required' => FALSE,
      '#description' => $this->t("Set the map height"),
    );
    
    // added to have 100% in height
    $form['map_settings']['height_unit'] = array(
        '#title' => $this->t('Map height unit'),
        '#type' => 'select',
        '#options' => array(
          'px' => $this->t('px'),
          '%' => $this->t('%'),
        ),
        '#default_value' => isset($conf['map_settings']['height_unit']) ? $conf['map_settings']['height_unit'] : 'px',
        '#description' => $this->t('Wether height is absolute (pixels) or relative (percent).'),
      );

    // Optionally override natural center and zoom.
    $default_override_zoom_center = isset($conf['map_settings']['override_zoom_center']) ? $conf['map_settings']['override_zoom_center'] : FALSE;
    $form['map_settings']['override_zoom_center'] = array(
      '#type' => 'checkbox',
      '#title' => 'Override natural center and zoom placement',
      '#default_value' => $default_override_zoom_center,
      '#description' => $this->t("Map will auto zoom and center based on the data. Check this box to customize the zooom and center"),
    );
    $form['map_settings']['custom_zoom_center'] = array(
      '#type' => 'fieldset',
      '#title' => 'Zoom and Center',
      '#tree' => TRUE,
      '#states' => array(
        'visible' => array(
          ':input[name="override_zoom_center"]' => array('checked' => TRUE),
        ),
      ),
    );
    $default_zoom = isset($conf['map_settings']['custom_zoom_center']['zoom']) ? $conf['map_settings']['custom_zoom_center']['zoom'] : 1;
    $form['map_settings']['custom_zoom_center']['zoom'] = array(
      '#title' => $this->t('Zoom'),
      '#type' => 'textfield',
      '#size' => 20,
      '#default_value' => $default_zoom,
      '#required' => FALSE,
    );
    $form['map_settings']['custom_zoom_center']['center'] = array(
      '#type' => 'fieldset',
      '#title' => 'Map center',
      '#tree' => TRUE,
      '#description' => $this->t("Provide a default map center especially when using the bounding box strategy."),
    );
    $default_center = isset($conf['map_settings']['custom_zoom_center']['center']) ? $conf['map_settings']['custom_zoom_center']['center'] : array('lon' => 0, 'lat' => 0);
    $form['map_settings']['custom_zoom_center']['center']['lon'] = array(
      '#title' => $this->t('Center longitude'),
      '#type' => 'textfield',
      '#size' => 20,
      '#default_value' => $default_center['lon'],
      '#required' => FALSE,
    );
    $form['map_settings']['custom_zoom_center']['center']['lat'] = array(
      '#title' => $this->t('Center latitude'),
      '#type' => 'textfield',
      '#size' => 20,
      '#default_value' => $default_center['lat'],
      '#required' => FALSE,
    );

    return $form;
  }

  /**
   * Submit handler just puts non-empty values into configuration.
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    foreach ($form['settings']['map_settings'] as $key => $content) {
      if (!empty($form_state->getValue(array('map_settings',$key)))) {
        $this->configuration['map_settings'][$key] = $form_state->getValue(array('map_settings',$key));
      }
    }
  }
  
  
   /**
   * Helper function to generate the javascript settings
   */
  private function leaflet_geojson_map_pane_render_javascript_library() {
    $libraries = array('leaflet_geojson/leaflet_geojson_bbox');
    
     // Allow other modules to alter the map data.
    \Drupal::moduleHandler()->alter('leaflet_geojson_map_pane_render_javascript_library', $libraries);
    
    return $libraries;
  }
  
  /**
   * Helper function to generate the javascript settings
   */
  private function leaflet_geojson_map_pane_render_javascript_settings() {
  
    $conf = $this->configuration;

    // Gather information about the leaflet base and data layers.
    $map_base_info = leaflet_map_get_info($conf['map_settings']['base']);
    $data_layers_info = array();
    foreach ($conf['map_settings']['info']['data'] as $layer_idx => $layer_machine_name) {
      $data_layers_info[$layer_idx]
        = leaflet_geojson_source_get_info($layer_machine_name);
    }
    
    return $data_layers_info;
  }
   
  /**
   * Helper function to generate the map markup based on the pane config.
   */
  private function leaflet_geojson_map_pane_render_layered_map() {
  
    $conf = $this->configuration;

    // Gather information about the leaflet base and data layers.
    $map_base_info = leaflet_map_get_info($conf['map_settings']['base']);
    $data_layers_info = array();
    foreach ($conf['map_settings']['info']['data'] as $layer_idx => $layer_machine_name) {
      $data_layers_info[$layer_idx]
        = leaflet_geojson_source_get_info($layer_machine_name);
    }

    // We are not currently supporting mixed bounding, i.e. if any one layer
    // is not bounded, then all layers will be fetched non-bounded. This will
    // have serious performance impact at large scale.
    $all_bounded = TRUE;
    foreach ($data_layers_info as $layer_idx => $layer_info) {
      if (!isset($layer_info['bbox'])) {
        $all_bounded = FALSE;
        break;
      }
    }

    $feature_layers = array();
    if ($all_bounded) {

      // Ensure the map center is non-empty for bbox.
      if (empty($map_base_info['center'])) {
        $map_base_info['center'] = array('lon' => 0,'lat' => 0);
      }    
    }
    else {
      /* @TODO: non-bounded data fetch */
    }

    // Apply any overrides of natural center and zoom.
    if (!empty($conf['map_settings']['override_zoom_center'])) {
      if (!empty($conf['map_settings']['custom_zoom_center']['zoom'])) {
        $map_base_info['settings']['zoom']
          = $conf['map_settings']['custom_zoom_center']['zoom'];
        $map_base_info['settings']['map_position_force'] = true;
      }
      if (!empty($conf['map_settings']['custom_zoom_center']['center']['lat']) && !empty($conf['map_settings']['custom_zoom_center']['center']['lon'])) {
        $map_base_info['settings']['center'] = array(
          'lat' => $conf['map_settings']['custom_zoom_center']['center']['lat'],
          'lon' => $conf['map_settings']['custom_zoom_center']['center']['lon'],
        );
      }
    }

    // Allow other modules to alter the map data.
    \Drupal::moduleHandler()->alter('leaflet_geojson_map_pane', $map_base_info, $feature_layers);
    

    $map = \Drupal::service('leaflet.service')->leafletRenderMap(
      $map_base_info, $feature_layers, $conf['map_settings']['height'] . $conf['map_settings']['height_unit']
    );
    
    
    return render($map);
  }

}