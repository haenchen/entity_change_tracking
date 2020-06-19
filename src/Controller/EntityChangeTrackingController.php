<?php

namespace haenchen\entity_change_tracking\Controller;

use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityType;
use Drupal\Core\Field\BaseFieldDefinition;
use haenchen\entity_change_tracking\Form\EntityChangeTrackingConfigForm;
use Drupal\user\Entity\User;

class EntityChangeTrackingController {

  public function getTypes(): array {
    \Drupal::cache()->delete('qd_data_tracking.classes');
    $classes = [];
    foreach (\Drupal::entityTypeManager()->getDefinitions() as $definition) {
      $classes[] = $definition->id();
    }
    return $classes;
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
    $this->createCreationMailing($entity);
  }

  private function createCreationMailing(EntityInterface $entity): void {
    $sSubject = t('A new :entity entity has been created.', [
      ':entity' => $entity->getEntityTypeId(),
    ]);

    $sBody = t('%entity has been created with the following values: <br><br>', [
      '%entity' => $entity->toLink()->toString(),
    ]);
    /** @var EntityFieldManager $manager */
    $manager = \Drupal::service('entity_field.manager');
    /** @var BaseFieldDefinition $baseField */
    foreach ($manager->getBaseFieldDefinitions($entity->getEntityTypeId()) as $baseField) {
      $field = $baseField->getName();
      $sBody .= t(':field = :value <br>', [
        ':field' => $field,
        ':value' => $entity->$field->value,
      ]);
    }

    /** TODO: rework mailing */
    $this->handleMailing([
      'is_admin_mail' => TRUE,
      'subject' => $sSubject,
      'body' => $sBody,
    ]);
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

    $this->createChangedMailingIfNecessary($entity, $changedFields);
  }

  private function createChangedMailingIfNecessary(EntityInterface $entity, array $changedFields): void {
    if (!$changedFields) {
      return;
    }

    $entityType = $entity->getEntityTypeId();

    $subject = t('A(n) %entity entity has been changed', [
      '%entity' => $entityType,
    ]);
    $body = t('The %entity entity with the id :id has been changed. <br>'
      . 'The following values have been changed: <br><br>', [
      '%entity' => $entityType,
      ':id' => $entity->toLink()->toString(),
    ]);
    foreach ($changedFields as $field) {
      $oldValue = $entity->original->$field->getValue();
      $newValue = $entity->$field->getValue();
      if (!$entity->getFieldDefinition($field)->isMultiple()) {
        $body .= t('%field has been changed from %original to %new. <br>', [
          '%field' => $field,
          '%original' => $oldValue[0]['value'],
          '%new' => $newValue[0]['value'],
        ]);
      }
      else {
        $difference = count($oldValue) < count($newValue) ? t('increased') : t('reduced');
        $body .= t('The amount of items in %field has been :difference. <br>', [
          '%field' => $field,
          ':difference' => $difference,
        ]);
      }
    }
    $body .= t('<br>Please check what these changed may effect.');

    /** TODO: rework mail handling */
    $this->handleMailing([
      'subject' => $subject,
      'body' => $body,
    ]);
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
