<?php

namespace Drupal\jibc_api_migration\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * @MigrateProcessPlugin(
 *   id = "set_text_format"
 * )
 */
class SetTextFormat extends ProcessPluginBase {

  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    // Return array with both value and format
    return [
      'value' => $value,
      'format' => 'full_html',  // or 'basic_html'
    ];
  }
}