<?php

namespace Drupal\jibc_api_migration\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\migrate\MigrateMessage;
use Drupal\jibc_api_migration\JIBCMigrateExecutable;
use Drupal\migrate\Plugin\MigrationInterface;

class APIMigrationRefreshForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormID() {
    return 'jibc_api_migration_refresh_courses';
  }
  
  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'jibc_api_migration.settings',
    ];
  }
  
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, Request $request = NULL) {
    $frequency = ($this->config('jibc_api_migration.settings')->get('jibc_api_migration_refresh_frequency'))/3600;
    
    $db = \Drupal::database();

    // Check current status
    $stats = [
      'courses' => $db->query("SELECT COUNT(*) FROM {node} WHERE type = 'course'")->fetchField(),
      'published_courses' => $db->query("
        SELECT COUNT(*) FROM {content_moderation_state_field_data} cms
        INNER JOIN {node} n ON cms.content_entity_id = n.nid
        WHERE n.type = 'course' 
        AND cms.content_entity_type_id = 'node'
        AND cms.moderation_state = 'published'
      ")->fetchField(),
      'archived_courses' => $db->query("
        SELECT COUNT(*) FROM {content_moderation_state_field_data} cms
        INNER JOIN {node} n ON cms.content_entity_id = n.nid
        WHERE n.type = 'course' 
        AND cms.content_entity_type_id = 'node'
        AND cms.moderation_state = 'archived'
      ")->fetchField(),
      'offerings' => $db->query("SELECT COUNT(*) FROM {paragraphs_item} WHERE type = 'course_offering'")->fetchField(),
      'attached' => $db->query("SELECT COUNT(DISTINCT entity_id) FROM {node__field_course_offerings}")->fetchField(),
      'orphaned' => $db->query("
        SELECT COUNT(*) FROM {paragraphs_item} p 
        WHERE p.type = 'course_offering' 
        AND p.id NOT IN (SELECT field_course_offerings_target_id FROM {node__field_course_offerings})
      ")->fetchField(),
    ];
    
    // Get migration map stats
    $map_table = 'migrate_map_new_courses';
    $map_stats = [
      'total_mapped' => 0,
      'imported' => 0,
      'needs_update' => 0,
      'ignored' => 0,
      'failed' => 0,
    ];
    
    if ($db->schema()->tableExists($map_table)) {
      $map_stats['total_mapped'] = $db->query("SELECT COUNT(*) FROM {{$map_table}}")->fetchField();
      $map_stats['imported'] = $db->query("SELECT COUNT(*) FROM {{$map_table}} WHERE source_row_status = 0")->fetchField();
      $map_stats['needs_update'] = $db->query("SELECT COUNT(*) FROM {{$map_table}} WHERE source_row_status = 1")->fetchField();
      $map_stats['ignored'] = $db->query("SELECT COUNT(*) FROM {{$map_table}} WHERE source_row_status = 2")->fetchField();
      $map_stats['failed'] = $db->query("SELECT COUNT(*) FROM {{$map_table}} WHERE source_row_status = 3")->fetchField();
    }
    
    // Get last import time
    $last_imported_store = \Drupal::keyValue('migrate_last_imported');
    $last_imported = $last_imported_store->get('new_courses');
    $last_imported_formatted = $last_imported 
      ? date('Y-m-d H:i:s', (int) ($last_imported / 1000)) 
      : 'Never';
    
    // Get last cron run time
    $last_cron = \Drupal::state()->get('jibc_api_migration.last_cron_run', 0);
    $last_cron_formatted = $last_cron 
      ? date('Y-m-d H:i:s', $last_cron) 
      : 'Never';
    
    // Check migration status
    $migration = \Drupal::service('plugin.manager.migration')->createInstance('new_courses');
    $migration_status = $migration ? $migration->getStatusLabel() : 'Unknown';
    
    $form['status'] = [
      '#type' => 'details',
      '#title' => t('Current Status'),
      '#open' => TRUE,
    ];
    
    $form['status']['course_stats'] = [
      '#type' => 'item',
      '#title' => t('Course Statistics'),
      '#markup' => t('
        <table class="admin-status-table">
          <tr><td>Total Courses in Drupal:</td><td><strong>@total</strong></td></tr>
          <tr><td>Published Courses:</td><td><strong style="color:green;">@published</strong></td></tr>
          <tr><td>Archived Courses:</td><td><strong style="color:orange;">@archived</strong></td></tr>
          <tr><td>Total Course Offerings:</td><td><strong>@offerings</strong></td></tr>
          <tr><td>Courses with Offerings Attached:</td><td><strong>@attached</strong></td></tr>
          <tr><td>Orphaned Offerings:</td><td><strong style="color:@orphan_color;">@orphaned</strong></td></tr>
        </table>
      ', [
        '@total' => $stats['courses'],
        '@published' => $stats['published_courses'],
        '@archived' => $stats['archived_courses'],
        '@offerings' => $stats['offerings'],
        '@attached' => $stats['attached'],
        '@orphaned' => $stats['orphaned'],
        '@orphan_color' => $stats['orphaned'] > 0 ? 'red' : 'green',
      ]),
    ];
    
    $form['status']['migration_stats'] = [
      '#type' => 'item',
      '#title' => t('Migration Map Statistics'),
      '#markup' => t('
        <table class="admin-status-table">
          <tr><td>Items in Migration Map:</td><td><strong>@total</strong></td></tr>
          <tr><td>Status: Imported:</td><td><strong>@imported</strong></td></tr>
          <tr><td>Status: Needs Update:</td><td><strong>@needs_update</strong></td></tr>
          <tr><td>Status: Ignored:</td><td><strong>@ignored</strong></td></tr>
          <tr><td>Status: Failed:</td><td><strong style="color:@failed_color;">@failed</strong></td></tr>
        </table>
        <p><em>Note: "Imported" count in map may exceed API total if courses were archived. This is expected behavior.</em></p>
      ', [
        '@total' => $map_stats['total_mapped'],
        '@imported' => $map_stats['imported'],
        '@needs_update' => $map_stats['needs_update'],
        '@ignored' => $map_stats['ignored'],
        '@failed' => $map_stats['failed'],
        '@failed_color' => $map_stats['failed'] > 0 ? 'red' : 'green',
      ]),
    ];
    
    $form['status']['timing_stats'] = [
      '#type' => 'item',
      '#title' => t('Timing Information'),
      '#markup' => t('
        <table class="admin-status-table">
          <tr><td>Last Import Completed:</td><td><strong>@last_import</strong></td></tr>
          <tr><td>Last Cron Run:</td><td><strong>@last_cron</strong></td></tr>
          <tr><td>Migration Status:</td><td><strong>@status</strong></td></tr>
          <tr><td>Cron Frequency:</td><td><strong>Every @freq hours</strong></td></tr>
        </table>
      ', [
        '@last_import' => $last_imported_formatted,
        '@last_cron' => $last_cron_formatted,
        '@status' => $migration_status,
        '@freq' => $frequency,
      ]),
    ];
    
    // Warning if migration is stuck
    if ($migration && $migration->getStatus() !== MigrationInterface::STATUS_IDLE) {
      $form['status']['warning'] = [
        '#markup' => '<div class="messages messages--warning">' . 
          t('Warning: Migration is currently in "@status" status. You may need to reset it.', [
            '@status' => $migration_status,
          ]) . '</div>',
      ];
      
      $form['status']['reset_action'] = [
        '#type' => 'submit',
        '#value' => t('Reset Migration Status to Idle'),
        '#submit' => ['::resetMigrationStatus'],
        '#attributes' => [
          'class' => ['button--danger'],
        ],
      ];
    }
    
    $form['jibc_api_migration_refresh_all'] = [
      '#type' => 'details',
      '#title' => t('Refresh Course Synchronization'),
      '#description' => t('The course synchronization operation runs automatically every @freq hours via cron. You can also run it manually here.<br /><br />', ['@freq' => $frequency]),
      '#open' => TRUE,
    ];
    
    $form['jibc_api_migration_refresh_all']['jibc_api_migration_refresh_action'] = [
      '#type' => 'submit',
      '#value' => t('Refresh All Courses'),
      '#submit' => ['::refreshCourses'],
    ];
    
    // Add attach orphaned offerings button if needed
    if ($stats['orphaned'] > 0) {
      $form['jibc_api_migration_refresh_all']['jibc_api_migration_attach_action'] = [
        '#type' => 'submit',
        '#value' => t('Attach @count Orphaned Offerings', ['@count' => $stats['orphaned']]),
        '#submit' => ['::attachOrphaned'],
        '#attributes' => [
          'class' => ['button--primary'],
        ],
      ];
    }
    
    // Debug section
    $form['debug'] = [
      '#type' => 'details',
      '#title' => t('Debug & Maintenance'),
      '#open' => FALSE,
    ];
    
    $form['debug']['force_cron'] = [
      '#type' => 'submit',
      '#value' => t('Force Cron Run (Reset Timer)'),
      '#submit' => ['::forceCronRun'],
      '#description' => t('Resets the last run timer to force the next cron to run the migration.'),
    ];
    
    // Show cleanup button if map has more entries than API
    $api_total = $db->query("SELECT COUNT(*) FROM {node_field_data} WHERE type = 'course' AND status = 1")->fetchField();
    if ($map_stats['total_mapped'] > $api_total) {
      $extra_entries = $map_stats['total_mapped'] - $api_total;
      $form['debug']['cleanup_map'] = [
        '#type' => 'submit',
        '#value' => t('Clean Up Migration Map (@count extra entries)', ['@count' => $extra_entries]),
        '#submit' => ['::cleanupMigrationMap'],
        '#attributes' => [
          'class' => ['button--danger'],
        ],
        '#description' => t('Removes map entries for courses that no longer exist in the API.'),
      ];
    }
    
    $form['debug']['view_recent_logs'] = [
      '#markup' => '<p>' . t('View recent migration logs: <a href="@url">Watchdog Logs</a>', [
        '@url' => '/admin/reports/dblog?type%5B%5D=jibc_api_migration&type%5B%5D=Course+Refresh',
      ]) . '</p>',
    ];
    
    return $form;
  }
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // This is now handled by specific submit handlers
  }
  
  /**
   * Submit handler for refresh courses
   */
  public function refreshCourses(array &$form, FormStateInterface $form_state) {
    $tempstore = \Drupal::service('tempstore.private')->get('jibc_api_migration');
    $tempstore->set('trigger', 'user');
    
    \Drupal::logger('jibc_api_migration')->notice('Manual course refresh triggered by user @user', [
      '@user' => \Drupal::currentUser()->getAccountName(),
    ]);
    
    $service = \Drupal::service('jibc_api_migration.refresh');
    $service->refreshAllCourses();
  }
  
  /**
   * Submit handler for attach orphaned offerings
   */
  public function attachOrphaned(array &$form, FormStateInterface $form_state) {
    $service = \Drupal::service('jibc_api_migration.refresh');
    $service->attachOrphanedOfferings();
    
    // Rebuild the form to update the counts
    $form_state->setRebuild();
  }
  
  /**
   * Submit handler to reset migration status
   */
  public function resetMigrationStatus(array &$form, FormStateInterface $form_state) {
    $migration = \Drupal::service('plugin.manager.migration')->createInstance('new_courses');
    if ($migration) {
      $migration->setStatus(MigrationInterface::STATUS_IDLE);
      \Drupal::messenger()->addStatus(t('Migration status reset to Idle.'));
      \Drupal::logger('jibc_api_migration')->notice('Migration status manually reset to IDLE by user @user', [
        '@user' => \Drupal::currentUser()->getAccountName(),
      ]);
    }
    $form_state->setRebuild();
  }
  
  /**
   * Submit handler to force cron run
   */
  public function forceCronRun(array &$form, FormStateInterface $form_state) {
    \Drupal::state()->delete('jibc_api_migration.last_cron_run');
    \Drupal::messenger()->addStatus(t('Cron timer reset. The migration will run on the next cron execution.'));
    \Drupal::logger('jibc_api_migration')->notice('Cron timer manually reset by user @user', [
      '@user' => \Drupal::currentUser()->getAccountName(),
    ]);
    $form_state->setRebuild();
  }
  
  /**
   * Submit handler to clean up migration map entries for archived/missing courses.
   */
  public function cleanupMigrationMap(array &$form, FormStateInterface $form_state) {
    $db = \Drupal::database();
    $logger = \Drupal::logger('jibc_api_migration');
    
    $map_table = 'migrate_map_new_courses';
    if (!$db->schema()->tableExists($map_table)) {
      \Drupal::messenger()->addError(t('Migration map table does not exist.'));
      return;
    }
    
    // Get all source IDs (Course_ID) for PUBLISHED courses in Drupal
    $published_course_ids = $db->query("
      SELECT nfci.field_course_id_value 
      FROM {node__field_course_id} nfci
      INNER JOIN {node_field_data} nfd ON nfci.entity_id = nfd.nid
      WHERE nfd.type = 'course' AND nfd.status = 1
    ")->fetchCol();
    
    $logger->info('Found @count published courses in Drupal', ['@count' => count($published_course_ids)]);
    
    // Get all entries in the migrate map
    $map_entries = $db->query("SELECT sourceid1, destid1, source_row_status FROM {{$map_table}}")->fetchAll();
    $logger->info('Found @count entries in migrate map', ['@count' => count($map_entries)]);
    
    $removed = 0;
    $removed_details = [];
    
    foreach ($map_entries as $entry) {
      $source_id = $entry->sourceid1;
      $dest_id = $entry->destid1;
      $status = $entry->source_row_status;
      
      // Remove map entry if:
      // 1. The course_id is not in published courses, OR
      // 2. The destination node doesn't exist anymore
      $should_remove = FALSE;
      $reason = '';
      
      if (!in_array($source_id, $published_course_ids)) {
        $should_remove = TRUE;
        $reason = 'course not published';
      }
      
      // Also check if the node even exists
      if (!$should_remove && $dest_id) {
        $node_exists = $db->query("SELECT 1 FROM {node} WHERE nid = :nid", [':nid' => $dest_id])->fetchField();
        if (!$node_exists) {
          $should_remove = TRUE;
          $reason = 'node deleted';
        }
      }
      
      if ($should_remove) {
        $db->delete($map_table)
          ->condition('sourceid1', $source_id)
          ->execute();
        $removed++;
        $removed_details[] = "$source_id ($reason)";
        $logger->info('Removed map entry: @id (node @nid) - @reason', [
          '@id' => $source_id,
          '@nid' => $dest_id,
          '@reason' => $reason,
        ]);
      }
    }
    
    if ($removed > 0) {
      \Drupal::messenger()->addStatus(t('Cleaned up @count entries from migration map.', ['@count' => $removed]));
      $logger->notice('Migration map cleanup: removed @count entries by user @user', [
        '@count' => $removed,
        '@user' => \Drupal::currentUser()->getAccountName(),
      ]);
    } else {
      \Drupal::messenger()->addStatus(t('Migration map is already clean. No entries to remove.'));
    }
    
    $form_state->setRebuild();
  }

  public function rollBackCourses(){
    $migration_id = 'new_courses';
    $migration = \Drupal::service('plugin.manager.migration')->createInstance($migration_id);
    $executable = new JIBCMigrateExecutable(
      $migration, 
      new MigrateMessage()
    );
    return $executable->rollbackMissingItems();
  }
}
