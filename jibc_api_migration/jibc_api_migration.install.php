<?php
/**
 * @file
 * jibc_api_migration install file.
 */

/**
 * Implements hook_uninstall().
 */
function jibc_api_migration_uninstall() {
  // Delete this module's migrations.
  $migrations = [
    'new_courses'
  ];
  foreach ($migrations as $migration) {
    Drupal::configFactory()->getEditable('migrate_plus.migration.' . $migration)->delete();
  }
  drupal_flush_all_caches();
}

/**
 * Attach orphaned course offerings to their parent courses.
 */
function jibc_api_migration_update_9001() {
  $db = \Drupal::database();
  $messages = [];
  
  // Get all course offering paragraphs with their course IDs
  $offerings = $db->query("
    SELECT p.id, p.revision_id, pf.field_course_id_value 
    FROM {paragraphs_item} p 
    JOIN {paragraph__field_course_id} pf ON p.id = pf.entity_id
    WHERE p.type = 'course_offering'
    AND p.id NOT IN (SELECT field_course_offerings_target_id FROM {node__field_course_offerings})
  ")->fetchAll();
  
  $messages[] = 'Found ' . count($offerings) . ' orphaned course offerings';
  
  // Group offerings by course
  $grouped = [];
  foreach ($offerings as $offering) {
    // Extract base course ID (remove section number)
    // e.g., BLAW-1000-001 -> BLAW-1000
    $course_id = $offering->field_course_id_value;
    if (preg_match('/^([A-Z]+-\d+)/', $course_id, $matches)) {
      $base_course_id = $matches[1];
    } else {
      // Try alternate pattern for different ID formats
      $parts = explode('-', $course_id);
      if (count($parts) >= 2) {
        $base_course_id = $parts[0] . '-' . $parts[1];
      } else {
        $base_course_id = $course_id;
      }
    }
    
    if (!isset($grouped[$base_course_id])) {
      $grouped[$base_course_id] = [];
    }
    $grouped[$base_course_id][] = [
      'target_id' => $offering->id,
      'target_revision_id' => $offering->revision_id,
    ];
  }
  
  $messages[] = 'Grouped into ' . count($grouped) . ' courses';
  
  // Attach to course nodes
  $attached = 0;
  $failed = 0;
  
  foreach ($grouped as $course_id => $offering_refs) {
    // Find the course node
    $nid = $db->query("
      SELECT entity_id FROM {node__field_course_id} 
      WHERE field_course_id_value = :cid
      LIMIT 1
    ", [':cid' => $course_id])->fetchField();
    
    if ($nid) {
      try {
        $node = \Drupal\node\Entity\Node::load($nid);
        if ($node && $node->bundle() === 'course') {
          // Get existing offerings (if any)
          $existing = $node->get('field_course_offerings')->getValue();
          
          // Add new offerings
          foreach ($offering_refs as $ref) {
            $existing[] = $ref;
          }
          
          // Save with all offerings
          $node->set('field_course_offerings', $existing);
          $node->save();
          $attached++;
        } else {
          $failed++;
        }
      } catch (\Exception $e) {
        $failed++;
        \Drupal::logger('jibc_api_migration')->error('Error attaching to course @id: @error', [
          '@id' => $course_id,
          '@error' => $e->getMessage(),
        ]);
      }
    } else {
      $failed++;
    }
  }
  
  $messages[] = "Successfully attached offerings to $attached courses";
  if ($failed > 0) {
    $messages[] = "Failed to attach offerings for $failed courses";
  }
  
  // Clear cache
  drupal_flush_all_caches();
  
  return implode('. ', $messages);
}

/**
 * Fix course offerings attachment using direct database update for performance.
 */
function jibc_api_migration_update_9002() {
  $db = \Drupal::database();
  
  // First, clear any existing attachments to start fresh
  $db->truncate('node__field_course_offerings')->execute();
  $db->truncate('node_revision__field_course_offerings')->execute();
  
  // Get all offerings grouped by course
  $offerings = $db->query("
    SELECT 
      p.id as paragraph_id,
      p.revision_id,
      pf.field_course_id_value as section_id,
      SUBSTRING_INDEX(pf.field_course_id_value, '-', 2) as course_id
    FROM {paragraphs_item} p 
    JOIN {paragraph__field_course_id} pf ON p.id = pf.entity_id
    WHERE p.type = 'course_offering'
    ORDER BY pf.field_course_id_value
  ")->fetchAll();
  
  // Group by course and insert directly
  $course_offerings = [];
  foreach ($offerings as $offering) {
    if (!isset($course_offerings[$offering->course_id])) {
      $course_offerings[$offering->course_id] = [];
    }
    $course_offerings[$offering->course_id][] = $offering;
  }
  
  $attached = 0;
  foreach ($course_offerings as $course_id => $offerings) {
    // Find the node
    $node_data = $db->query("
      SELECT n.entity_id, n.revision_id, nd.langcode
      FROM {node__field_course_id} n
      JOIN {node_field_data} nd ON n.entity_id = nd.nid
      WHERE n.field_course_id_value = :cid
      LIMIT 1
    ", [':cid' => $course_id])->fetchObject();
    
    if ($node_data) {
      $delta = 0;
      foreach ($offerings as $offering) {
        // Insert into field tables
        $db->insert('node__field_course_offerings')
          ->fields([
            'bundle' => 'course',
            'deleted' => 0,
            'entity_id' => $node_data->entity_id,
            'revision_id' => $node_data->revision_id,
            'langcode' => $node_data->langcode,
            'delta' => $delta,
            'field_course_offerings_target_id' => $offering->paragraph_id,
            'field_course_offerings_target_revision_id' => $offering->revision_id,
          ])
          ->execute();
        
        $db->insert('node_revision__field_course_offerings')
          ->fields([
            'bundle' => 'course',
            'deleted' => 0,
            'entity_id' => $node_data->entity_id,
            'revision_id' => $node_data->revision_id,
            'langcode' => $node_data->langcode,
            'delta' => $delta,
            'field_course_offerings_target_id' => $offering->paragraph_id,
            'field_course_offerings_target_revision_id' => $offering->revision_id,
          ])
          ->execute();
        
        $delta++;
      }
      $attached++;
    }
  }
  
  // Clear all caches
  drupal_flush_all_caches();
  
  return "Attached offerings to $attached courses using direct database update.";
}

/**
 * Clean up duplicate course offerings and ensure consistent IDs.
 */
function jibc_api_migration_update_9003() {
  $db = \Drupal::database();
  $messages = [];
  
  // Find duplicate offerings (same CourseSec_ID or CourseSec_Name)
  $duplicates = $db->query("
    SELECT 
      field_course_id_value,
      COUNT(*) as count,
      GROUP_CONCAT(entity_id) as entity_ids
    FROM {paragraph__field_course_id}
    WHERE bundle = 'course_offering'
    GROUP BY field_course_id_value
    HAVING COUNT(*) > 1
  ")->fetchAll();
  
  $removed = 0;
  foreach ($duplicates as $dup) {
    $ids = explode(',', $dup->entity_ids);
    // Keep the first one, delete the rest
    array_shift($ids);
    
    foreach ($ids as $id) {
      try {
        $paragraph = \Drupal\paragraphs\Entity\Paragraph::load($id);
        if ($paragraph) {
          $paragraph->delete();
          $removed++;
        }
      } catch (\Exception $e) {
        \Drupal::logger('jibc_api_migration')->error('Error deleting duplicate offering @id: @error', [
          '@id' => $id,
          '@error' => $e->getMessage(),
        ]);
      }
    }
  }
  
  $messages[] = "Removed $removed duplicate course offerings";
  
  // Verify all offerings are properly attached
  $orphaned = $db->query("
    SELECT COUNT(*) FROM {paragraphs_item} p 
    WHERE p.type = 'course_offering' 
    AND p.id NOT IN (SELECT field_course_offerings_target_id FROM {node__field_course_offerings})
  ")->fetchField();
  
  if ($orphaned > 0) {
    $messages[] = "Warning: Still have $orphaned orphaned offerings. Run update 9001 again.";
  } else {
    $messages[] = "All course offerings are properly attached";
  }
  
  // Clear caches
  drupal_flush_all_caches();
  
  return implode('. ', $messages);
}