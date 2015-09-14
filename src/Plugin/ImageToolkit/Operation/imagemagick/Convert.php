<?php

/**
 * @file
 * Contains \Drupal\imagemagick\Plugin\ImageToolkit\Operation\imagemagick\Convert.
 */

namespace Drupal\imagemagick\Plugin\ImageToolkit\Operation\imagemagick;

/**
 * Defines imagemagick Convert operation.
 *
 * @ImageToolkitOperation(
 *   id = "imagemagick_convert",
 *   toolkit = "imagemagick",
 *   operation = "convert",
 *   label = @Translation("Convert"),
 *   description = @Translation("Instructs the toolkit to save the image with a specified format.")
 * )
 */
class Convert extends ImagemagickImageToolkitOperationBase {

  /**
   * {@inheritdoc}
   */
  protected function arguments() {
    return array(
      'extension' => array(
        'description' => 'The new extension of the converted image',
      ),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function validateArguments(array $arguments) {
    if (!in_array($arguments['extension'], $this->getToolkit()->getSupportedExtensions())) {
      throw new \InvalidArgumentException(String::format("Invalid extension (@value) specified for the image 'convert' operation", array('@value' => $arguments['extension'])));
    }
    return $arguments;
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(array $arguments) {
    $this->getToolkit()->setDestinationFormat($arguments['extension']);
    return TRUE;
  }

}
