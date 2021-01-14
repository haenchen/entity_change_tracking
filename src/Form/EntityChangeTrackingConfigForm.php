<?php


namespace Drupal\haenchen\entity_change_tracking\Form;


use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\haenchen\entity_change_tracking\Controller\EntityChangeTrackingController;

class EntityChangeTrackingConfigForm extends ConfigFormBase {

  public const CONFIG_NAME = 'entity_change_tracking.config';

  protected function getEditableConfigNames() {
    return [self::CONFIG_NAME];
  }

  public function getFormId() {
    return 'entity_change_tracking_config_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    $entityTypes = (new EntityChangeTrackingController)->getTypes();
    $data = \Drupal::config(self::CONFIG_NAME)->get('data');
    $fieldManager = \Drupal::service('entity_field.manager');

    foreach ($entityTypes as $entityTypeId) {
      $form[$entityTypeId] = [
        '#type' => 'details',
        '#title' => $entityTypeId,
        $entityTypeId . '-track_new' => [
          '#type' => 'checkbox',
          '#title' => t('Track new entities'),
          '#description' => t('An email will be sent when a new entity of this type is created.'),
          '#default_value' => $data[$entityTypeId]['track_new'] ?? FALSE,
        ],
      ];

      $fields = $fieldManager->getBaseFieldDefinitions($entityTypeId);
      $fields = array_map(static function (BaseFieldDefinition $field) {
        return $field->getName();
      }, $fields);
      $form[$entityTypeId][$entityTypeId . '-fields'] = [
        '#type' => 'checkboxes',
        '#title' => t('Select which fields to track'),
        '#options' => $fields,
        '#default_value' => $data[$entityTypeId]['fields'] ?? [],
      ];
    }
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = \Drupal::configFactory()
      ->getEditable(self::CONFIG_NAME);

    $data = [];
    foreach ($form_state->getValues() as $key => $value) {
      if (strpos($key, '-') === FALSE)
        continue;
      $parts = explode('-', $key);
      if ($parts[1] === 'track_new')
        $aData[$parts[0]]['track_new'] = $value;
      if (is_array($value))
        $this->setTrackedFields($aData, $parts[0], $value);
    }

    $config->set('data', $data);
    $config->save();
  }

  private function setTrackedFields(array &$data, string $entityType, array $fields) {
    $fieldsToTrack = [];
    foreach ($fields as $key => $value)
      if ($value)
        $fieldsToTrack[] = $key;

    $data[$entityType]['fields'] = $fieldsToTrack;
  }
}
