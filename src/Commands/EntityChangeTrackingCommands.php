<?php


namespace Drupal\haenchen\entity_change_tracking\Commands;

use Drupal\Core\Config\Config;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\haenchen\entity_change_tracking\Controller\EntityChangeTrackingController;
use Drupal\haenchen\entity_change_tracking\Form\EntityChangeTrackingConfigForm;
use Drush\Commands\DrushCommands;

/** @noinspection ContractViolationInspection */

class EntityChangeTrackingCommands extends DrushCommands {

  /**
   * @usage drush entity_change_tracking:track-field-changes
   *   Enables change tracking for the specified field of the specified entity
   *   type
   *
   * @param string $entityType
   *   The entity type that contains the field that shall be tracked
   *
   * @param string $field
   *   The name of the field that shall be tracked
   *
   * @command entity_change_tracking:track-field-changes
   * @aliases tfc
   */
  public function trackFieldChanges(string $entityType, string $field): void {
    if (!$this->validateInputs($entityType, $field)) {
      return;
    }

    $this->setTracking($entityType, $field, TRUE);
    $this->logger()->success("Tracking enabled for $field in $entityType");
  }

  /**
   * @usage drush entity_change_tracking:untrack-field-changes
   *   Disables change tracking for the specified field of the specified entity
   *   type
   *
   * @param string $identifier
   *   The entity type that contains the field that shall no longer be tracked
   *
   * @param string $field
   *   The name of the field that shall no longer be tracked
   *
   * @option entity typename Use the entity type name instead of EntityTypeId
   *
   * @command entity_change_tracking:untrack-field-changes
   * @aliases ufc
   */
  public function untrackFieldChanges(string $identifier, string $field): void {
    if (!$this->validateInputs($identifier, $field)) {
      return;
    }

    $this->setTracking($identifier, $field, FALSE);
    $this->logger()->success("Tracking disabled for $field in $identifier");
  }

  /**
   * @usage drush entity_change_tracking:track-field
   *   Enables the tracking of new entities
   *
   * @param string $identifier
   *   The entity type that shall be tracked
   *
   * @command entity_change_tracking:track-field
   * @aliases tne
   */
  public function trackNewEntities(string $identifier): void {
    if (!$this->validateType($identifier)) {
      return;
    }

    $this->setNewEntityTracking($identifier, TRUE);
    $this->logger()->success("Tracking enabled for new $identifier entities");
  }

  /**
   * @usage drush entity_change_tracking:untrack-field
   *   Disables the tracking of new entities
   *
   * @param string $identifier
   *   The entity type that shall no longer be tracked
   *
   * @command entity_change_tracking:untrack-field
   * @aliases une
   */
  public function untrackNewEntities(string $identifier): void {
    if (!$this->validateType($identifier)) {
      return;
    }

    $this->setNewEntityTracking($identifier, FALSE);
    $this->logger()->success("Tracking enabled for new $identifier entities");
  }

  /**
   * @usage
   *
   * @param string $address The email address that is supposed to receive
   *   tracking notifications
   *
   * @command entity_change_tracking:add-tracking-recipient
   * @alias atr
   */
  public function addTrackingRecipient(string $address) {
    if (!\Drupal::service('email.validator')->isValid($address)) {
      $this->logger()->error("$address is not a valid email address.");
      return;
    }

    $config = $this->getEditableConfig();
    $recipients = explode(';', $config->get('recipients'));
    $recipients[] = $address;
    $config->set('recipients', implode(';', $recipients));
    $config->save();
  }

  /**
   * @usage
   *
   * @param string $address The email address that is supposed to no longer
   *   receive tracking notifications
   *
   * @command entity_change_tracking:remove-tracking-recipient
   * @alias rtr
   */
  public function removeTrackingRecipient(string $address) {
    $config = $this->getEditableConfig();
    $recipients = explode(';', $config->get('recipients'));
    $key = array_search($address, $recipients, TRUE);
    if ($key !== FALSE) {
      unset($recipients[$key]);
    }
    $config->set('recipients', implode(';', $recipients));
    $config->save();
  }

  /**
   * Checks whether a field exists within a entity type
   *
   * @param string $entityType The entity type that is supposed to have the
   *   field
   * @param string $field The field that is supposed to exist in the entity
   *   type
   *
   * @return bool TRUE if the field exists withing the entity type
   */
  private function fieldIsValid(string $entityType, string $field): bool {
    $fields = \Drupal::service('entity_field.manager')
      ->getBaseFieldDefinitions($entityType);
    $fields = array_map(static function (BaseFieldDefinition $field) {
      return $field->getName();
    }, $fields);
    return in_array($field, $fields, TRUE);
  }

  /**
   * Checks if the class is defined, trackable and whether it has the specified
   * field
   *
   * @param string $entityType The entity type that shall be validated
   * @param string $field The field that the entity type is supposed to have
   *
   * @return bool TRUE if the entity type exists and has the specified field
   */
  private function validateInputs(string $entityType, string $field): bool {
    if (!$this->validateType($entityType)) {
      return FALSE;
    }

    if (!$this->fieldIsValid($entityType, $field)) {
      $this->logger()
        ->error("$entityType does not have a field called '$field'.");
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Checks if a class is defined and trackable
   *
   * @param string $entityType The entity type that shall be validated
   *
   * @return bool TRUE if the entity type exists and is trackable
   */
  private function validateType(string $entityType): bool {
    if (!\Drupal::entityTypeManager()->hasDefinition($entityType)) {
      $this->logger()->error("$entityType does not exist.");
      return FALSE;
    }

    $classes = (new EntityChangeTrackingController)->getTypes();
    if (!in_array($entityType, $classes, TRUE)) {
      $this->logger->error($entityType . ' is not a trackable entity type.');
      return FALSE;
    }

    return TRUE;
  }

  private function setTracking(string $entityType, string $field, bool $track): void {
    $config = $this->getEditableConfig();

    $data = $config->get('data') ?? [];
    $trackedFields = $data[$entityType]['fields'] ?? [];

    if ($track && !in_array($field, $trackedFields, TRUE)) {
      $trackedFields[] = $field;
    }
    if (!$track && in_array($field, $trackedFields, TRUE)) {
      $position = array_search($field, $trackedFields, TRUE);
      unset($trackedFields[$position]);
    }

    $data[$entityType]['fields'] = $trackedFields;
    $config->set('data', $data);
    $config->save();
  }

  private function setNewEntityTracking(string $entityType, bool $track): void {
    $config = $this->getEditableConfig();
    $data[$entityType]['track_new'] = $track;
    $config->set('data', $data);
    $config->save();
  }

  /**
   * @return Config Editable config object
   */
  private function getEditableConfig(): Config {
    $config = \Drupal::configFactory()
      ->getEditable(EntityChangeTrackingConfigForm::CONFIG_NAME);
    return $config;
  }

}
