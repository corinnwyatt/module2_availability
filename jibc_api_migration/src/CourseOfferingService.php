<?php
/**
 * @file
 * Contains \Drupal\jibc_api_migration\CourseOfferingService
 */

namespace Drupal\jibc_api_migration;

use Drupal\Core\Database\Database;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Class CourseOfferingService.
 * Defines a service to check existing course offerings
 */
class CourseOfferingService {

  /**
   * Checks if individual course offerings already exist. This is to prevent duplicity and enable update of existing course offerings
   *  @return string
   */
  public function getCourseOffering_entity_id($coursec_id){
    if (!$coursec_id){
      return FALSE;
    }
    
    $query = Database::getConnection()->select('paragraph__field_course_id', 'p');
    $query->fields('p', ['entity_id']);
    $query->condition('field_course_id_value', $coursec_id, '=');
    $query->range(0, 1);
    
    $result = $query->execute()->fetchObject();
    return $result;
  }

  public function getCourse_node_id($course_id){
    if (!$course_id){
      return FALSE;
    }
    $query = Database::getConnection()->select('node__field_course_id', 'c')
    ->fields('c', ['entity_id',])
    ->condition('field_course_id_value', $course_id, '=')
    ->range(0, 1)
    ->execute();
    $result = $query->fetchObject();
    return $result;
  }

  /**
   * This service should allow easy deletion of missing course offerings how we need to completely delete from database.
   * Right now, deleted course offerings will stay hidden
   */
  public function getMissingCourseOfferings($course_id) {
    // Get entity ids for courses
    $course_id = $this->getCourse_node_id($course_id);
    $tempstore = \Drupal::service('tempstore.private')->get('jibc_api_migration');
    $course_offering = $tempstore->get('existing-Ids');
    $query = Database::getConnection()->select('node__field_course_offerings', 'c');
    $query->fields('c', ['field_course_offerings_target_id',]);
    $query->condition('entity_id', $course_id->entity_id, '=');
    //Check if there are new incoming course offerings and append additional conditions 
    if($course_offering){
      foreach($course_offering as $c_o){
        $query->condition('field_course_offerings_target_id', $c_o, '!=');
      }
    }
    // Reset $tempstore
    //$tempstore->delete('existing-Ids');
    $result = $query->execute();
    return $result->fetchObject();
    
    
  }

  public function getCampus($title){
    $query = Database::getConnection()->select('node_field_data', 'c')
      ->fields('c', ['nid',])
      ->condition('title', $title, '=')
      ->range(0, 1)
      ->execute();
      return $query->fetchObject();
  }
}