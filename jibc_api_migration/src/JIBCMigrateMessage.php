<?php

namespace Drupal\jibc_api_migration;

use Drupal\migrate\MigrateMessage as MigrateMessageBase;
use Drupal\migrate\MigrateMessageInterface;

/**
 * Class JIBCMigrateMessage.
 *
 * 
 */
class JIBCMigrateMessage extends MigrateMessageBase {

  /**
   * {@inheritdoc}
   */
  public function display($message, $type = 'status') {
    \Drupal::logger($message, $type);
  }

}