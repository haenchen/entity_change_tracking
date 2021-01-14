<?php

namespace Drupal\entity_change_tracking\Controller;

use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityType;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\entity_change_tracking\Form\EntityChangeTrackingConfigForm;
use Drupal\user\Entity\User;

class EntityChangeTrackingController {

  public function getTypes(): array {
    \Drupal::cache()->delete('qd_data_tracking.classes');
    $ids = [];
    foreach (\Drupal::entityTypeManager()->getDefinitions() as $definition) {
      $ids[] = $definition->id();
    }
    return $ids;
  }

  public function handleNewEntity(EntityInterface $entity): void {
    if ($this->userMakesAuthorizedChanges($entity)) {
      return;
    }

    $entityType = $entity->getEntityTypeId();
    if (!in_array($entityType, $this->getTypes(), TRUE)) {
      return;
    }
    $aData = \Drupal::config(EntityChangeTrackingConfigForm::CONFIG_NAME)
      ->get('data');
    if (empty($aData[$entityType]['track_new'])) {
      return;
    }
    $this->handleCreationLog($entity);
  }

  private function handleCreationLog(EntityInterface $entity): void {
    $message = 'A new %entity has been created with the ID @id';
    $params = [
      '%entity' =>  $entity->getEntityTypeId(),
      '@id' => $entity->toLink($entity->id())->toString(),
    ];
   
    \Drupal::logger('entity_changes')->alert($message, $params);
  }

  public function handleChangedEntity(EntityInterface $entity): void {
    if ($this->userMakesAuthorizedChanges($entity)) {
      return;
    }

    $entityTypeId = $entity->getEntityTypeId();
    if (!in_array($entityTypeId, $this->getTypes(), TRUE)) {
      return;
    }

    $changedFields = [];
    $data = \Drupal::config(EntityChangeTrackingConfigForm::CONFIG_NAME)
      ->get('data');
    foreach ($data[$entityTypeId]['fields'] as $field) {
      if ($entity->$field->getValue() !== $entity->original->$field->getValue()) {
        $changedFields[] = $field;
      }
    }

    $this->handleChangeLog($entity, $changedFields);
  }

  private function handleChangeLog(EntityInterface $entity, array $changedFields): void {
    if (!$changedFields) {
      return;
    }

    $entityType = $entity->getEntityTypeId();
    
    $message = t('The %entity entity with the id @id has been changed. <br>'
      . 'The following values have been changed: <br><br>', [
      '%entity' => $entityType,
      '@id' => $entity->toLink($entity->id())->toString(),
    ]);
    foreach ($changedFields as $field) {
      $oldValue = $entity->original->$field->getValue();
      $newValue = $entity->$field->getValue();
      if (!$entity->getFieldDefinition($field)->isMultiple()) {
        $message .= t('%field has been changed from %original to %new. <br>', [
          '%field' => $field,
          '%original' => $oldValue[0]['value'],
          '%new' => $newValue[0]['value'],
        ]);
      }
      else {
        $difference = count($oldValue) < count($newValue) ? t('increased') : t('reduced');
        $message .= t('The amount of items in %field has been :difference. <br>', [
          '%field' => $field,
          ':difference' => $difference,
        ]);
      }
    }

    \Drupal::logger('entity_changes')->alert($message);
  }

  private function userMakesAuthorizedChanges(EntityInterface $entity): bool {
    $user = \Drupal::currentUser() ?: User::load($entity->user_id->value);
    if (!$user) {
      return FALSE;
    }
    if ($user->hasPermission('can make authorized changes')) {
      return TRUE;
    }

    return FALSE;
  }

}
