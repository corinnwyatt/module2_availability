<?php

namespace Drupal\jibc_api_migration\Plugin\migrate\source;

use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate_plus\Plugin\migrate\source\Url;
use Drupal\migrate_tools\Plugin\migrate\source\SyncableSourceTrait;
use Drupal\migrate_tools\SyncableSourceInterface;
use Drupal\Core\Site\Settings;
use Drupal\migrate_plus\DataParserPluginManager;

/**
 * A syncable url source using SyncableSourceTrait with dynamic URL support.
 *
 * @MigrateSource(
 *   id = "syncable_url",
 *   source_module = "jibc_api_migration"
 * )
 */
class SyncableUrl extends Url implements SyncableSourceInterface {

  use SyncableSourceTrait;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration, DataParserPluginManager $parser_plugin_manager) {
    // Set dynamic URL based on environment.
    $settings_config = Settings::get('jibc_api', []);

    if (!empty($settings_config['api_base_url'])) {
      $configuration['urls'] = rtrim($settings_config['api_base_url'], '/') . '/courses';
    }

    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $parser_plugin_manager);

    // THIS IS CRITICAL - must call this after parent constructor.
    $this->setAllRowsFromConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public static function create($container, array $configuration, $plugin_id, $plugin_definition, ?MigrationInterface $migration = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $migration,
      $container->get('plugin.manager.migrate_plus.data_parser')
    );
  }

  // DO NOT implement sourceIds() - let the trait handle it
  // DO NOT implement markChanged() - let the trait handle it
}