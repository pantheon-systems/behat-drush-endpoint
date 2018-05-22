<?php

namespace Drush\Commands;

use Drush\Commands\DrushCommands;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\TermInterface;

/**
 * A Drush commandfile.
 *
 * Contains Behat Drush commands, for use by the Behat Drush Extension.
 * These commands are specifically for Drush 9
 *
 * See these files for an example of injecting Drupal services:
 *   - http://cgit.drupalcode.org/devel/tree/src/Commands/DevelCommands.php
 *   - http://cgit.drupalcode.org/devel/tree/drush.services.yml
 */
class BehatDrushEndpointCommands extends DrushCommands {

  /**
   * Behat Drush endpoint. Serves as an entrypoint for Behat to make remote calls into the Drupal site being tested.
   *
   * @param $operation
   *   Behat operation, e.g. create-node.
   * @param $data
   *   Operation data in json format.
   * @usage drush behat create-node '{"title":"Example page","type":"page"}'
   *   Create a page with the title "Example page".
   *
   * @command behat:create
   * @aliases behat
   */
  public function behat($operation, $data) {
    $obj = json_decode($data);

    // Dispatch if the operation exists.
    $fn = 'drush_behat_op_' . strtr($operation, '-', '_');
    if (method_exists($this, $fn)) {
      return $this->{$fn}($obj);
    }
    else {
      throw new \Exception(dt("Operation '!op' unknown", array('!op' => $operation)));
    }
  }

  /**
   * Create a node.
   */
  public function drush_behat_op_create_node($node) {
    // Default status to 1 if not set.
    if (!isset($node->status)) {
      $node->status = 1;
    }
    // If 'author' is set, remap it to 'uid'.
    if (isset($node->author)) {
      $user = user_load_by_name($node->author);
      if ($user) {
        $node->uid = $user->id();
      }
    }

    // Attempt to decipher any fields that may be specified.
    $this->drush_behat_expand_entity_fields('node', $node);

    $entity = entity_create('node', (array) $node);
    $entity->save();

    $node->nid = $entity->id();

    return (array) $node;
  }

  /**
   * Delete a node.
   */
  public function drush_behat_op_delete_node($node) {
    $node = $node instanceof NodeInterface ? $node : Node::load($node->nid);
    if ($node instanceof NodeInterface) {
      $node->delete();
    }
  }

  /**
   * Create a taxonomy term.
   */
  public function drush_behat_op_create_taxonomy_term($term) {
    $term->vid = $term->vocabulary_machine_name;

    // Attempt to decipher any fields that may be specified.
    _drush_behat_expand_entity_fields('taxonomy_term', $term);

    $entity = entity_create('taxonomy_term', (array)$term);
    $entity->save();

    $term->tid = $entity->id();
    return $term;
  }

  /**
   * Delete a taxonomy term.
   */
  public function drush_behat_op_delete_taxonomy_term(\stdClass $term) {
    $term = $term instanceof TermInterface ? $term : Term::load($term->tid);
    if ($term instanceof TermInterface) {
      $term->delete();
    }
  }

  /**
   * Check if this is a field.
   */
  public function drush_behat_op_is_field($is_field_info) {
    list($entity_type, $field_name) = $is_field_info;
    return $this->drush_behat_is_field($entity_type, $field_name);
  }

  /**
   * Get all of the field attached to the specified entity type.
   *
   * @see Drupal\Driver\Cores\Drupal8\getEntityFieldTypes in Behat
   */
  protected function drush_behat_get_entity_field_types($entity_type) {
    $return = array();
    $fields = \Drupal::entityManager()->getFieldStorageDefinitions($entity_type);
    foreach ($fields as $field_name => $field) {
      if ($this->drush_behat_is_field($entity_type, $field_name)) {
        $return[$field_name] = $field->getType();
      }
    }
    return $return;
  }

  protected function drush_behat_is_field($entity_type, $field_name) {
    $fields = \Drupal::entityManager()->getFieldStorageDefinitions($entity_type);
    return (isset($fields[$field_name]) && $fields[$field_name] instanceof FieldStorageConfig);
  }

  protected function drush_behat_get_field_handler($entity, $entity_type, $field_name) {
    $core_namespace = "Drupal8";
    return $this->drush_behat_get_field_handler_common($entity, $entity_type, $field_name, $core_namespace);
  }

  /**
   * Expands properties on the given entity object to the expected structure.
   *
   * @param \stdClass $entity
   *   Entity object.
   *
   * @see Drupal\Driver\Cores\AbstractCore\expandEntityFields
   */
  protected function drush_behat_expand_entity_fields($entity_type, \stdClass $entity) {
    $field_types = $this->drush_behat_get_entity_field_types($entity_type);
    foreach ($field_types as $field_name => $type) {
      if (isset($entity->$field_name)) {
        $entity->$field_name = $this->drush_behat_get_field_handler($entity, $entity_type, $field_name)
          ->expand($entity->$field_name);
      }
    }
  }

  /**
   * Get the field handler for the specified field of the specified entity.
   *
   * Note that this function instantiates a field handler class that is
   * provided by the Behat Drush Driver.  In order for this to work, an
   * appropriate autoload.inc file must be included.  This will be done
   * automatically if the Drupal site is managed by Composer, and requires
   * the Behat Drush Driver in its composer.json file.
   *
   * @see Drupal\Driver\Cores\AbstractCore\getFieldHandler
   */
  protected function drush_behat_get_field_handler_common($entity, $entity_type, $field_name, $core_namespace) {
    $field_types = $this->drush_behat_get_entity_field_types($entity_type);
    $camelized_type = $this->drush_behat_camelize($field_types[$field_name]);
    $default_class = sprintf('\Drupal\Driver\Fields\%s\DefaultHandler', $core_namespace);
    $class_name = sprintf('\Drupal\Driver\Fields\%s\%sHandler', $core_namespace, $camelized_type);
    if (class_exists($class_name)) {
      return new $class_name($entity, $entity_type, $field_name);
    }
    return new $default_class($entity, $entity_type, $field_name);
  }

}
