<?php

namespace Drupal\jibc_api_migration\EventSubscriber;

use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigratePostRowSaveEvent;
use Drupal\migrate\Event\MigratePreRowSaveEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\node\Entity\Node;

class MigrationSubscriber implements EventSubscriberInterface {

  public static function getSubscribedEvents() {
    return [
      MigrateEvents::PRE_ROW_SAVE => ['onPreRowSave'],
      MigrateEvents::POST_ROW_SAVE => ['onPostRowSave'],
    ];
  }

  public function onPreRowSave(MigratePreRowSaveEvent $event) {
    $migration = $event->getMigration();
    $row = $event->getRow();
    
    if ($migration->id() == 'new_courses') {
      // Handle Course_Desc field
      $course_desc = $row->getSourceProperty('Course_Desc');
      if (!empty($course_desc)) {
        // Ensure the description is properly set with format
        $row->setDestinationProperty('field_course_details', [
          'value' => $course_desc,
          'format' => 'full_html',
        ]);
      }
      
      // Check if this is an update to an existing archived course
      $id_map = $row->getIdMap();
      if (!empty($id_map['destid1'])) {
        $nid = $id_map['destid1'];
        $existing_node = Node::load($nid);
        
        if ($existing_node && !$existing_node->isPublished()) {
          // Force republish since it's back in the API
          $row->setDestinationProperty('status', 1);
          $row->setDestinationProperty('moderation_state', 'published');
          
          \Drupal::logger('jibc_api_migration')->notice('Re-publishing archived course @id', [
            '@id' => $row->getSourceProperty('Course_ID'),
          ]);
        }
      }
    }
  }

  public function onPostRowSave(MigratePostRowSaveEvent $event) {
    $migration = $event->getMigration();
    
    if ($migration->id() == 'new_courses') {
      $destination_ids = $event->getDestinationIdValues();
      if (!empty($destination_ids[0])) {
        $node = \Drupal::entityTypeManager()->getStorage('node')->load($destination_ids[0]);
        
        if ($node) {
          // Check if node should be published but isn't
          if (!$node->isPublished()) {
            $node->setPublished(TRUE);
            $node->set('moderation_state', 'published');
            $node->save();
            
            \Drupal::logger('jibc_api_migration')->warning('Fixed unpublished course @id that exists in API', [
              '@id' => $node->get('field_course_id')->value
            ]);
          }
        }
      }
    }
  }
}
