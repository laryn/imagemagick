<?php

namespace Drupal\imagemagick;

/**
 * Provides an interface for ImageMagick execution managers.
 * )
 */
interface ImagemagickExecManagerInterface {

  /**
   * Verifies file path of the executable binary by checking its version.
   *
   * @param string $path
   *   The user-submitted file path to the convert binary.
   * @param string $package
   *   (optional) The graphics package to use.
   *
   * @return array
   *   An associative array containing:
   *   - output: The shell output of 'convert -version', if any.
   *   - errors: A list of error messages indicating if the executable could
   *     not be found or executed.
   */
  public function checkPath($path, $package = NULL);

  /**
   * Executes the convert executable as shell command.
   *
   * @param string $command
   *   The executable to run.
   * @param \Drupal\imagemagick\ImagemagickExecArgments $arguments
   *   An ImageMagick execution arguments object.
   * @param string &$output
   *   (optional) A variable to assign the shell stdout to, passed by
   *   reference.
   * @param string &$error
   *   (optional) A variable to assign the shell stderr to, passed by
   *   reference.
   * @param string $path
   *   (optional) A custom file path to the executable binary.
   *
   * @return bool
   *   TRUE if the command succeeded, FALSE otherwise. The error exit status
   *   code integer returned by the executable is logged.
   */
  public function execute($command, ImagemagickExecArguments $arguments, &$output = NULL, &$error = NULL, $path = NULL);

}
