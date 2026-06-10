<?php

/**
 * Implements hook_drush_command() to allow command line operations
 */
function jibc_api_migration_drush_command() {
  $items['unpublish-missing-course'] = [
    'description' => 'Unpublish JIBC course when it is missing from JSON source.',
    'aliases' => ['jibc-umc'],
  ];

  return $items;
}

/**
 * Unpublish JIBC course when it is missing from JSON source.
 *
 * @param string $migration_name
 *   The migration names.
 */
function drush_jibc_api_migration_unpublish_missing_course($migration_name = '') {
  $service = Drupal::service('jibc_api_migration.refresh');
  $service->unpublishCourse($migration_name);
}