<?php

namespace Drupal\imagemagick\Plugin\ImageToolkit\Operation\imagemagick;

use Drupal\Core\ImageToolkit\ImageToolkitOperationBase;

/**
 * Base image toolkit operation class for Imagemagick.
 */
abstract class ImagemagickImageToolkitOperationBase extends ImageToolkitOperationBase {

  /**
   * The correctly typed image toolkit for imagemagick operations.
   *
   * @return \Drupal\imagemagick\Plugin\ImageToolkit\ImagemagickToolkit
   *   The correctly typed image toolkit for imagemagick operations.
   */
  // @codingStandardsIgnoreStart
  protected function getToolkit() {
    return parent::getToolkit();
  }
  // @codingStandardsIgnoreEnd

}
