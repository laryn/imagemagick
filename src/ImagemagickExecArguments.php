<?php

namespace Drupal\imagemagick;

/**
 * Stores arguments for execution of ImageMagick/GraphicsMagick commands.
 */
class ImagemagickExecArguments {

  /**
   * An identifier to be used for arguments internal to the toolkit.
   */
  const INTERNAL_ARGUMENT_IDENTIFIER = '>!>';

  /**
   * The ImageMagick execution manager service.
   *
   * @var \Drupal\imagemagick\ImagemagickExecManagerInterface
   */
  protected $execManager;

  /**
   * The array of command line arguments to be used by 'convert'.
   *
   * @var string[]
   */
  protected $arguments = [];

  /**
   * Path of the image file.
   *
   * @var string
   */
  protected $source = '';

  /**
   * The local filesystem path to the source image file.
   *
   * @var string
   */
  protected $sourceLocalPath = '';

  /**
   * The source image format.
   *
   * @var string
   */
  protected $sourceFormat = '';

  /**
   * The source image frames to access.
   *
   * @var string
   */
  protected $sourceFrames;

  /**
   * The image destination URI/path on saving.
   *
   * @var string
   */
  protected $destination = NULL;

  /**
   * The local filesystem path to the image destination.
   *
   * @var string
   */
  protected $destinationLocalPath = '';

  /**
   * The image destination format on saving.
   *
   * @var string
   */
  protected $destinationFormat = '';

  /**
   * Constructs an ImagemagickExecArguments object.
   *
   * @param \Drupal\imagemagick\ImagemagickExecManagerInterface $exec_manager
   *   The ImageMagick execution manager service.
   */
  public function __construct(ImagemagickExecManagerInterface $exec_manager) {
    $this->execManager = $exec_manager;
  }

  /**
   * Gets the command line arguments for the binary.
   *
   * @return string[]
   *   The array of command line arguments.
   */
  public function getArguments() {
    return $this->arguments ?: [];
  }

  /**
   * Gets the command line arguments string for the binary.
   *
   * Removes any argument used internally within the toolkit.
   *
   * @return string
   *   The sring of command line arguments.
   */
  public function getStringForBinary() {
    if (!$this->arguments) {
      return '';
    }
    $arguments_for_binary = array_filter($this->arguments, function ($argument) {
      return strpos($argument, self::INTERNAL_ARGUMENT_IDENTIFIER) !== 0;
    });
    return implode(' ', $arguments_for_binary);
  }

  /**
   * Adds a command line argument.
   *
   * @param string $arg
   *   The command line argument to be added.
   *
   * @return $this
   */
  public function addArgument($arg) {
    $this->arguments[] = $arg;
    return $this;
  }

  /**
   * Prepends a command line argument.
   *
   * @param string $arg
   *   The command line argument to be prepended.
   *
   * @return $this
   */
  public function prependArgument($arg) {
    array_unshift($this->arguments, $arg);
    return $this;
  }

  /**
   * Finds if a command line argument exists.
   *
   * @param string $arg
   *   The command line argument to be found.
   *
   * @return bool
   *   Returns the array key for the argument if it is found in the array,
   *   FALSE otherwise.
   */
  public function findArgument($arg) {
    foreach ($this->getArguments() as $i => $a) {
      if (strpos($a, $arg) === 0) {
        return $i;
      }
    }
    return FALSE;
  }

  /**
   * Removes a command line argument.
   *
   * @param int $index
   *   The index of the command line argument to be removed.
   *
   * @return $this
   */
  public function removeArgument($index) {
    if (isset($this->arguments[$index])) {
      unset($this->arguments[$index]);
    }
    return $this;
  }

  /**
   * Resets the command line arguments.
   *
   * @return $this
   */
  public function resetArguments() {
    $this->arguments = [];
    return $this;
  }

  /**
   * Returns the count of command line arguments.
   *
   * @return $this
   */
  public function countArguments() {
    return count($this->arguments);
  }

  /**
   * Sets the path of the source image file.
   *
   * @param string $source
   *   The source path of the image file.
   *
   * @return $this
   */
  public function setSource($source) {
    $this->source = $source;
    return $this;
  }

  /**
   * Gets the path of the source image file.
   *
   * @return string
   *   The source path of the image file, or an empty string if the source is
   *   not set.
   */
  public function getSource() {
    return $this->source;
  }

  /**
   * Sets the local filesystem path to the image file.
   *
   * @param string $path
   *   A filesystem path.
   *
   * @return $this
   */
  public function setSourceLocalPath($path) {
    $this->sourceLocalPath = $path;
    return $this;
  }

  /**
   * Gets the local filesystem path to the image file.
   *
   * @return string
   *   A filesystem path.
   */
  public function getSourceLocalPath() {
    return $this->sourceLocalPath;
  }

  /**
   * Sets the source image format.
   *
   * @param string $format
   *   The image format.
   *
   * @return $this
   */
  public function setSourceFormat($format) {
    $this->sourceFormat = $format;
    return $this;
  }

  /**
   * Gets the source image format.
   *
   * @return string
   *   The source image format.
   */
  public function getSourceFormat() {
    return $this->sourceFormat;
  }

  /**
   * Sets the source image frames to access.
   *
   * @param string $frames
   *   The frames in '[n]' string format.
   *
   * @return $this
   *
   * @see http://www.imagemagick.org/script/command-line-processing.php
   */
  public function setSourceFrames($frames) {
    $this->sourceFrames = $frames;
    return $this;
  }

  /**
   * Gets the source image frames to access.
   *
   * @return string
   *   The frames in '[n]' string format.
   *
   * @see http://www.imagemagick.org/script/command-line-processing.php
   */
  public function getSourceFrames() {
    return $this->sourceFrames;
  }

  /**
   * Sets the image destination URI/path on saving.
   *
   * @param string $destination
   *   The image destination URI/path.
   *
   * @return $this
   */
  public function setDestination($destination) {
    $this->destination = $destination;
    return $this;
  }

  /**
   * Gets the image destination URI/path on saving.
   *
   * @return string
   *   The image destination URI/path.
   */
  public function getDestination() {
    return $this->destination;
  }

  /**
   * Sets the local filesystem path to the destination image file.
   *
   * @param string $path
   *   A filesystem path.
   *
   * @return $this
   */
  public function setDestinationLocalPath($path) {
    $this->destinationLocalPath = $path;
    return $this;
  }

  /**
   * Gets the local filesystem path to the destination image file.
   *
   * @return string
   *   A filesystem path.
   */
  public function getDestinationLocalPath() {
    return $this->destinationLocalPath;
  }

  /**
   * Sets the image destination format.
   *
   * When set, it is passed to the convert binary in the syntax
   * "[format]:[destination]", where [format] is a string denoting an
   * ImageMagick's image format.
   *
   * @param string $format
   *   The image destination format.
   *
   * @return $this
   */
  public function setDestinationFormat($format) {
    $this->destinationFormat = $format;
    return $this;
  }

  /**
   * Gets the image destination format.
   *
   * When set, it is passed to the convert binary in the syntax
   * "[format]:[destination]", where [format] is a string denoting an
   * ImageMagick's image format.
   *
   * @return string
   *   The image destination format.
   */
  public function getDestinationFormat() {
    return $this->destinationFormat;
  }

  /**
   * Escapes a string.
   *
   * @param string $arg
   *   The string to escape.
   *
   * @return string
   *   An escaped string for use in the
   *   ImagemagickExecManagerInterface::execute method.
   */
  public function escapeShellArg($arg) {
    return $this->execManager->escapeShellArg($arg);
  }

}
