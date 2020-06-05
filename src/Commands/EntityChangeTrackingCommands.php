<?php


namespace haenchen\entity_change_tracking\Commands;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use haenchen\entity_change_tracking\Controller\EntityChangeTrackingController;
use haenchen\entity_change_tracking\Form\EntityChangeTrackingConfigForm;
use Drush\Commands\DrushCommands;

/** @noinspection ContractViolationInspection */
class EntityChangeTrackingCommands extends DrushCommands {

  /**
   * @usage drush entity_change_tracking:track-field-changes
   *   Enables change tracking for the specified field of the specified class
   *
   * @param string $identifier
   *   The class that contains the field that shall be tracked
   *
   * @param string $field
   *   The name of the field that shall be tracked
   *
   * @option classname Use the class name instead of EntityTypeId
   *
   * @command entity_change_tracking:track-field-changes
   * @aliases tfc
   */
  public function trackFieldChanges(string $identifier, string $field, array $options = [
    'classname' => FALSE,
  ]) {

    if ($options['classname'] !== TRUE) {
      $identifier = $this->getClassByEntityTypeId($identifier);
      if (!$identifier)
        return;
    }

    $identifier = $this->validateInputs($identifier, $field);
    if (!$identifier)
      return;

    $this->setTracking($identifier, $field, TRUE);
    $this->logger()->success("Tracking enabled for $field in $identifier");
  }

  /**
   * @usage drush entity_change_tracking:untrack-field-changes
   *   Disables change tracking for the specified field of the specified class
   *
   * @param string $identifier
   *   The class that contains the field that shall no longer be tracked
   *
   * @param string $field
   *   The name of the field that shall no longer be tracked
   *
   * @option classname Use the class name instead of EntityTypeId
   *
   * @command entity_change_tracking:untrack-field-changes
   * @aliases ufc
   */
  public function untrackFieldChanges(string $identifier, string $field, array $options = [
    'classname' => FALSE,
  ]) {

    if ($options['classname'] !== TRUE) {
      $identifier = $this->getClassByEntityTypeId($identifier);
      if (!$identifier)
        return;
    }

    $identifier = $this->validateInputs($identifier, $field);
    if (!$identifier)
      return;

    $this->setTracking($identifier, $field, FALSE);
    $this->logger()->success("Tracking disabled for $field in $identifier");
  }

  /**
   * @usage drush entity_change_tracking:track-field
   *   Enables the tracking of new entities
   *
   * @param string $identifier
   *   The Entityclass that shall be tracked
   *
   * @option classname Use the class name instead of EntityTypeId
   *
   * @command entity_change_tracking:track-field
   * @aliases tne
   */
  public function trackNewEntities(string $identifier, array $options = [
    'classname' => FALSE,
  ]) {

    if ($options['classname'] !== TRUE) {
      $identifier = $this->getClassByEntityTypeId($identifier);
      if (!$identifier)
        return;
    }

    $identifier = $this->validateClass($identifier);
    if (!$identifier)
      return;

    $identifier = $this->getClassName($identifier);
    $this->setTracking($identifier, 'track_new', TRUE);
    $this->logger()->success("Tracking enabled for new $identifier entities");
  }

  /**
   * @usage drush entity_change_tracking:untrack-field
   *   Disables the tracking of new entities
   *
   * @param string $identifier
   *   The Entityclass that shall no longer be tracked
   *
   * @option classname Use the class name instead of EntityTypeId
   *
   * @command entity_change_tracking:untrack-field
   * @aliases une
   */
  public function untrackNewEntities(string $identifier, array $options = [
    'classname' => FALSE,
  ]) {

    if ($options['classname'] !== TRUE) {
      $identifier = $this->getClassByEntityTypeId($identifier);
      if (!$identifier)
        return;
    }

    $identifier = $this->validateClass($identifier);
    if (!$identifier)
      return;

    $identifier = $this->getClassName($identifier);
    $this->setTracking($identifier, 'track_new', FALSE);
    $this->logger()->success("Tracking disabled for new $identifier entities");
  }

  private function getValidClass(string $className): string {
    $entityDefinitions = \Drupal::entityTypeManager()->getDefinitions();
    $allClasses = array_map(static function (EntityTypeInterface $oDefinition) {
      return $oDefinition->getClass();
    }, $entityDefinitions);
    $matches = [];
    $lowerClassName = strtolower($className);
    foreach ($allClasses as $declaredClass) {
      $lowerDeclaredClassName = strtolower($declaredClass);
      if ($lowerClassName === $lowerDeclaredClassName)
        return $className;

      $currentClass = $this->getClassName($lowerDeclaredClassName);
      if ($lowerClassName === $currentClass)
        $matches[] = $declaredClass;
    }
    $matchCount = count($matches);
    if ($matchCount < 1) {
      $this->logger()->error($className . ' does not exist.');
      return '';
    }
    if ($matchCount > 1) {
      $this->logger()
        ->error('Multiple classes found, please provide the fully qualified class name.');
      return '';
    }
    return reset($matches);
  }

  /**
   * Checks whether a field exists within a class
   *
   * @param string $className The class that is supposed to have the field
   * @param string $field The field that is supposed to exist in the class
   *
   * @return bool TRUE if the field exists withing the class
   */
  private function fieldIsValid(string $className, string $field) {
    /** @noinspection PhpUndefinedMethodInspection */
    $fields = $className::create()->getFieldDefinitions();
    $fieldNames = array_map(static function (BaseFieldDefinition $field) {
      return $field->getName();
    }, $fields);
    return in_array($field, $fieldNames, TRUE);
  }

  /**
   * Checks if the class is defined, trackable and whether it has the specified field
   *
   * @param string $className The class that shall be validated
   * @param string $field The field that the class is supposed to have
   *
   * @return string The classname in the correct case if, empty if it does not exist
   */
  private function validateInputs(string $className, string $field): string {
    $fullClassName = $this->validateClass($className);
    if (!$fullClassName)
      return '';

    if (!$this->fieldIsValid($fullClassName, $field)) {
      $this->logger()->error("$className does not have a field called '$field'.");
      return '';
    }

    return $this->getClassName($fullClassName);
  }

  /**
   * Checks if a class is defined and trackable
   *
   * @param string $className The class that shall be validated
   *
   * @return string The fully qualified class name or empty if it does not exist
   */
  private function validateClass(string $className): string {
    $fullClassName = $this->getValidClass($className);
    if (!$fullClassName)
      return '';

    $classes = EntityChangeTrackingController::getClasses();
    if (!in_array($fullClassName, $classes)) {
      $this->logger->error($className . ' is not a trackable class.');
      return '';
    }

    return $fullClassName;
  }

  private function setTracking(string $className, string $field, bool $track) {
    $config = \Drupal::configFactory()
      ->getEditable(EntityChangeTrackingConfigForm::CONFIG_NAME);

    $data = $config->get('data') ?? [];
    $data[$className][$field] = $track ? 1 : 0;

    $config->set('data', $data);
    $config->save();
  }

  private function getClassName(string $fullClassName) {
    $parts = explode('\\', $fullClassName);
    return end($parts);
  }

  private function getClassByEntityTypeId(string $sTypeId): string {
    $manager = \Drupal::entityTypeManager();
    if (!$manager->hasDefinition($sTypeId)) {
      $this->logger()->error("Entity type $sTypeId does not exist");
      return '';
    }
    return $manager->getDefinition($sTypeId)->getClass();
  }
}
