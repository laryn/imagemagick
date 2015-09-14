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
 * Here we are using an algorithm that produces the same results as PHP 5.4.
 *
 * @todo drop this if the class gets in Drupal core, see #1551686-23
 */
class Rectangle {

  /**
   * An array of point coordinates, keyed by an id.
   *
   * Canonical points are:
   * 'c_a' - bottom left corner of the rectangle
   * 'c_b' - bottom right corner of the rectangle
   * 'c_c' - top right corner of the rectangle
   * 'c_d' - top left corner of the rectangle
   * 'o_a' - bottom left corner of the bounding rectangle, once the rectangle
   *         is rotated
   * 'o_c' - top right corner of the bounding rectangle, once the rectangle
   *         is rotated
   *
   *   c_d +-----------------+ c_c
   *       |                 |
   *       |                 |
   *   c_a +-----------------+ c_b
   *
   *
   *       +-----------------+--+ o_c
   *       |               c_c  |
   *       + c_d                |
   *       |                c_b +
   *       |  c_a               |
   *   o_a +--+-----------------+
   *
   * @var array
   */
  protected $points = [];

  /**
   * Constructs a new Rectangle object.
   *
   * @param int $width
   *   The width of the rectangle.
   * @param int $height
   *   The height of the rectangle.
   */
  public function __construct($width, $height) {
    if ($width !== 0 && $height !== 0) {
      $this
        ->setPoint('c_a', [0, 0])
        ->setPoint('c_b', [$width, 0])
        ->setPoint('c_c', [$width, $height])
        ->setPoint('c_d', [0, $height])
        ->setPoint('o_a', [0, 0])
        ->setPoint('o_c', [$width, $height]);
    }
  }

  /**
   * Rotates the rectangle and any additional point.
   *
   * @param float $angle
   *   Rotation angle.
   */
  public function rotate($angle) {
    if ($angle) {
      foreach ($this->points as &$point) {
        $this->rotatePoint($point, $angle);
      }
      $this->determineBoundingCorners();
    }
    return $this;
  }

  /**
   * Gets the bounding width of the rectangle.
   *
   * @return int
   *   The bounding width of the rotated rectangle.
   */
  public function getBoundingWidth() {
    return (int) ceil($this->points['o_c'][0] - $this->points['o_a'][0] - 0.000001);
  }

  /**
   * Gets the bounding height of the rectangle.
   *
   * @return int
   *   The bounding height of the rotated rectangle.
   */
  public function getBoundingHeight() {
    return (int) ceil($this->points['o_c'][1] - $this->points['o_a'][1] - 0.000001);
  }

  /**
   * Sets a point and its coordinates.
   *
   * @param string $id
   *   The point ID.
   * @param array $coords
   *   An array of x, y coordinates.
   *
   * @return $this
   */
  protected function setPoint($id, array $coords = [0, 0]) {
    $this->points[$id] = $coords;
    return $this;
  }

  /**
   * Rotates a point, by an offset and a rotation angle.
   *
   * @param array $point
   *   An array of x, y coordinates.
   * @param float $angle
   *   Rotation angle.
   *
   * @return $this
   */
  protected function rotatePoint(array &$point, $angle) {
    $rad = deg2rad($angle);
    $sin = sin($rad);
    $cos = cos($rad);
    list($x, $y) = $point;
    $point[0] = $x * $cos + $y * -$sin;
    $point[1] = $y * $cos - $x * -$sin;
    return $this;
  }

  /**
   * Calculates the corners of the bounding rectangle.
   *
   * The bottom left ('o_a') and top right ('o_c') corners of the bounding
   * rectangle of a rotated rectangle are needed to determine the bounding
   * width and height.
   *
   * @return $this
   */
  protected function determineBoundingCorners() {
    $this
      ->setPoint('o_a', [
          min($this->points['c_a'][0], $this->points['c_b'][0], $this->points['c_c'][0], $this->points['c_d'][0]),
          min($this->points['c_a'][1], $this->points['c_b'][1], $this->points['c_c'][1], $this->points['c_d'][1])
        ]
      )
      ->setPoint('o_c', [
          max($this->points['c_a'][0], $this->points['c_b'][0], $this->points['c_c'][0], $this->points['c_d'][0]),
          max($this->points['c_a'][1], $this->points['c_b'][1], $this->points['c_c'][1], $this->points['c_d'][1])
        ]
      );
    return $this;
  }

}
