<?php


namespace haenchen\entity_change_tracking\Form;


use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use haenchen\entity_change_tracking\Controller\EntityChangeTrackingController;

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
    $classes = EntityChangeTrackingController::getClasses();
    $data = \Drupal::config(self::CONFIG_NAME)->get('data');
    foreach ($classes as $fullClassName) {
      $parts = explode('\\', $fullClassName);
      $className = end($parts);
      $form[$className] = [
        '#type' => 'details',
        '#title' => $className,
        $className . '-track_new' => [
          '#type' => 'checkbox',
          '#title' => t('Track new entities'),
          '#description' => t('An email will be sent when a new entity of this type is created.'),
          '#default_value' => $data[$className]['track_new'] ?? FALSE,
        ],
      ];
  
      /** @var BaseFieldDefinition $field */
      foreach ($fullClassName::create()->getFieldDefinitions() as $field) {
        $sName = $field->getName();
        $form[$className][$className . '-' . $sName] = [
          '#type' => 'checkbox',
          '#title' => $sName . ' (' . $field->getLabel() . ')',
          '#description' => $field->getDescription(),
          '#default_value' => $data[$className][$sName] ?? FALSE,
        ];
      }
    }
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $data = [];
    foreach ($form_state->getValues() as $key => $value) {
      if (strpos($key, '-') === FALSE)
        continue;
      $parts = explode('-', $key);
      $data[$parts[0]][$parts[1]] = $value;
    }

    $config = \Drupal::configFactory()
      ->getEditable(self::CONFIG_NAME);
    $config->set('data', $data);
    $config->save();
  }


}
