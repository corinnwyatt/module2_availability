<?php

namespace Drupal\jibc_api_migration\Plugin\migrate\process;

use Drupal\migrate\MigrateException;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Drupal\Core\Database\Database;
use Drupal\taxonomy\Entity\Term;

/**
 * Perform custom value transformations.
 *
 * @MigrateProcessPlugin(
 *   id = "course_taxonomy"
 * )
 *
 * To do custom value transformations use the following:
 *
 * @code
 * field_text:
 *   plugin: course_taxonomy
 *   source: text
 * @endcode
 *
 */
class CourseTaxonomy extends ProcessPluginBase {
  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    if(!empty($value) && isset($this->configuration['vocabulary'])){
      $vid = $this->configuration['vocabulary'];
      $query = Database::getConnection()
      ->select('taxonomy_term_field_data', 'tx')
      ->fields('tx', array('tid'))
      ->condition('vid', $vid, '=')
      ->condition('name', $value, '=');
      if($result = $query->execute()->fetchObject()) {
        $term = Term::load($result->tid);
        return $term;
      } else {
        $term = Term::create([
          'name' => $value, 
          'vid' => $vid,
        ]);
        $term->save();
        return $term;
      }
      
    } else {
      return;
    }
  }
}