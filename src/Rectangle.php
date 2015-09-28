<?php

/**
 * @file
 * Contains \Drupal\imagemagick\Rectangle.
 */

namespace Drupal\imagemagick;

/**
 * Rectangle rotation algebra class.
 *
 * This class is used by the image system to abstract, from toolkit
 * implementations, the calculation of the expected dimensions resulting
 * from an image rotate operation.
 *
 * Different versions of PHP for the GD toolkit, and alternative toolkits,
 * use different algorithms to perform the rotation of an image and result
 * in different dimensions of the output image.
 * This prevents predictability of the final image size for instance by the
 * image rotate effect, and by the image toolkit rotate operation.
 *
 * Here we are using an algorithm that produces the same results as PHP 5.5+
 *
 * @todo drop this if the class gets in Drupal core, see #1551686
 */
class Rectangle {
  /**
   * The width of the rectangle.
   *
   * @var int
   */
  protected $width;

  /**
   * The height of the rectangle.
   *
   * @var int
   */
  protected $height;

  /**
   * The width of the rotated rectangle.
   *
   * @var int
   */
  protected $boundingWidth;

  /**
   * The height of the rotated rectangle.
   *
   * @var int
   */
  protected $boundingHeight;

  /**
   * The imprecision factor to use for calculations.
   *
   * @var float
   */
  protected $imprecision = -0.00001;

  /**
   * Constructs a new Rectangle object.
   *
   * @param int $width
   *   The width of the rectangle.
   * @param int $height
   *   The height of the rectangle.
   */
  public function __construct($width, $height) {
    if ($width >= 0 && $height >= 0) {
      $this->width = $width;
      $this->height = $height;
      $this->boundingWidth = $width;
      $this->boundingHeight = $height;
    }
  }

  /**
   * Rotates the rectangle.
   *
   * @param float $angle
   *   Rotation angle.
   *
   * @return $this
   */
  public function rotate($angle) {
    // For rotations that are not multiple of 90 degrees, we need to match
    // GD that uses C floats internally, whereas we at PHP level use C
    // doubles. We correct that using an imprecision. Also, we need to
    // introduce a correction factor of 0.5 to match the GD algorithm used
    // in PHP 5.5+ to calculate the new width and height of the rotated
    // image.
    if ($angle % 90 === 0) {
      $imprecision = 0;
      $correction = 0;
    }
    else {
      $imprecision = $this->imprecision;
      $correction = 0.5;
    }

    // Do the necessary trigonometry.
    $rad = deg2rad($angle);
    $cos = cos($rad);
    $sin = sin($rad);
    $sinImprecision = $sin < 0 ? -$imprecision : $imprecision;
    $cosImprecision = $cos < 0 ? -$imprecision : $imprecision;
    $a = $this->fixImprecision($this->width * $cos + $cosImprecision, $cosImprecision);
    $b = $this->fixImprecision($this->height * $sin + $correction + $sinImprecision, $sinImprecision);
    $c = $this->fixImprecision($this->width * $sin + $sinImprecision, $sinImprecision);
    $d = $this->fixImprecision($this->height * $cos + $correction + $cosImprecision, $cosImprecision);

    // This is how GD on PHP5.5 calculates the new dimensions.
    $this->boundingWidth = abs((int) $a) + abs((int) $b);
    $this->boundingHeight = abs((int) $c) + abs((int) $d);

    return $this;
  }

  /**
   * Performs an imprecision check on the input value and fixes it.
   *
   * GD that uses C floats internally, whereas we at PHP level use C doubles.
   * We correct that using an imprecision.
   *
   * @param float $input
   *   The input value resulting from an expression.
   * @param float $imprecision
   *   The imprecision factor.
   *
   * @return float
   *   A fixed value, where input is substracted from input if the fraction
   *   part of the value is lower than the imprecision.
   */
  protected function fixImprecision($input, $imprecision) {
    $fraction = abs((1 - ((((int) $input) + 1) - ($input + 1))));
    return $fraction < abs($imprecision) ? $input - $imprecision : $input;
  }

  /**
   * Gets the bounding width of the rectangle.
   *
   * @return int
   *   The bounding width of the rotated rectangle.
   */
  public function getBoundingWidth() {
    return $this->boundingWidth;
  }

  /**
   * Gets the bounding height of the rectangle.
   *
   * @return int
   *   The bounding height of the rotated rectangle.
   */
  public function getBoundingHeight() {
    return $this->boundingHeight;
  }

  /**
   * Sets the imprecision to be used for calculations.
   *
   * @param float $imprecision
   *   The imprecision to be used for calculations.
   *
   * @return $this
   */
  public function setImprecision($imprecision) {
    $this->imprecision = $imprecision;
    return $this;
  }

}
