<?php

namespace haenchen\entity_change_tracking\Controller;

use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityType;
use Drupal\Core\Field\BaseFieldDefinition;
use haenchen\entity_change_tracking\Form\EntityChangeTrackingConfigForm;
use Drupal\user\Entity\User;

class EntityChangeTrackingController {
  
  public static function rebuildEntityCache(): void {
    \Drupal::cache()->delete('qd_data_tracking.classes');
    $classes = [];
    foreach (\Drupal::entityTypeManager()->getDefinitions() as $definition)
      $classes[] = $definition->getClass();
    
    \Drupal::cache()->set('entity_change_tracking.classes', $classes);
  }
  
  public static function getClasses(): array {
    $cache = \Drupal::cache()->get('entity_change_tracking.classes');
    if (!$cache || !$cache->data) {
      self::rebuildEntityCache();
    }
    return \Drupal::cache()->get('entity_change_tracking.classes')->data;
  }
  
  public static function handleNewEntity(EntityInterface $oEntity) {
    if (self::userMakesAuthorizedChanges($oEntity))
      return;
    
    $simpleClassName = self::getSimpleClassName($oEntity);
    if (empty($simpleClassName))
      return;
    $data = \Drupal::config(EntityChangeTrackingConfigForm::CONFIG_NAME)
      ->get('data');
    if (!$data[$simpleClassName]['track_new'])
      return;
    self::createCreationMailing($oEntity);
  }
  
  private static function createCreationMailing(EntityInterface $entity) {
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
    self::handleMailing([
      'is_admin_mail' => TRUE,
      'subject' => $sSubject,
      'body' => $sBody,
    ]);
  }
  
  public static function handleChangedEntity(EntityInterface $entity) {
    if (self::userMakesAuthorizedChanges($entity))
      return;
    
    $simpleClassName = self::getSimpleClassName($entity);
    if (empty($simpleClassName))
      return;
    
    $changedFields = [];
    $data = \Drupal::config(EntityChangeTrackingConfigForm::CONFIG_NAME)
      ->get('data');
    foreach ($data[$simpleClassName] as $field => $track) {
      if ($track && $entity->$field->value != $entity->original->$field->value)
        $changedFields[] = $field;
    }
    
    self::createChangedMailingIfNecessary($entity, $changedFields);
  }
  
  private static function createChangedMailingIfNecessary(EntityInterface $entity, array $changedFields) {
    if (!$changedFields)
      return;
    
    $entityType = $entity->getEntityTypeId();
    
    $subjec = t('A(n) %entity entity has been changed', [
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
    self::handleMailing([
      'is_admin_mail' => TRUE,
      'subject' => $subjec,
      'body' => $body,
    ]);
  }
  
  private static function getSimpleClassName(EntityInterface $oEntity): string {
    $classes = self::getClasses();
    $fullClassName = $oEntity->getEntityType()->getClass();
    $parts = explode('\\', $fullClassName);
    $simpleClassName = end($parts);
    if (in_array($fullClassName, $classes, TRUE))
      return $simpleClassName;
    return '';
  }
  
  private static function userMakesAuthorizedChanges(EntityInterface $oEntity): bool {
    $user = \Drupal::currentUser();
    if ($user->hasPermission('can make authorized changes'))
      return TRUE;
    
    if (\Drupal::currentUser()->isAuthenticated())
      return FALSE;
    
    $authorId = $oEntity->user_id->value ?? 0;
    $user = User::load($authorId);
    if ($user)
      return FALSE;
    
    if ($user->hasPermission('can make authorized changes'))
      return TRUE;
    return FALSE;
  }
}
